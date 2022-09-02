<?php declare(strict_types=1);

namespace Eboubaker\Scraper\Scrapers;

use Eboubaker\Scraper\App;
use Eboubaker\Scraper\Concerns\WritesLogs;
use Eboubaker\Scraper\Contracts\Scraper;
use Eboubaker\Scraper\Exception\ExpectationFailedException;
use Eboubaker\Scraper\Exception\NotImplementedException;
use Eboubaker\Scraper\Tools\Cache\FS;
use Eboubaker\Scraper\Tools\Cache\Memory;
use Eboubaker\Scraper\Tools\CLI\ProgressIndicator;
use Eboubaker\Scraper\Tools\Http\CurlHttp;
use Eboubaker\Scraper\Tools\Http\Document;
use Eboubaker\Scraper\Tools\Http\ThreadedDownloader;
use Eboubaker\Scraper\Tools\Optional;
use Exception;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\RequestOptions as ReqOpt;
use Throwable;
use Tightenco\Collect\Support\Arr;

/**
 * @author Eboubaker Bekkouche <eboubakkar@gmail.com>
 */
final class YoutubeScraper implements Scraper
{
    use WritesLogs;

    public static function can_scrap(Document $document): bool
    {
        return !!preg_match("/https?:\/\/((m|www)\.)?youtu(be)?(-nocookie)?\.(com|be)\//", $document->getFinalUrl());
    }

    private function get_video_id(Document $document): string
    {
        $url = $document->getFinalUrl();
        preg_match("/watch\\?v=(?<id>[^&]*)/", $url, $matches);
        return $matches['id'] ?? '<unkown-id>';
    }

    /**
     * @return string[]
     * @throws NotImplementedException
     * @throws Exception
     * @throws Throwable
     */
    function scrap(Document $document): iterable
    {
        $manifest = Optional::ofNullable($document->getObjects()->search([
            "streamingData.formats",
            "streamingData.adaptiveFormats"
        ]))->map(function ($obj) {
            return $obj->get('streamingData');
        })->orElseThrow(fn() => new ExpectationFailedException("No video manifest found"))->assoc();
        $formats = collect(data_get($manifest, 'formats'))->sort(fn($v2, $v1) => $this->compare_streams($v1, $v2));
        $adaptive_videos = collect(data_get($manifest, 'adaptiveFormats'))
            ->filter(fn($v) => stripos(data_get($v, 'mimeType'), 'video') !== false)
            ->sort(fn($v2, $v1) => $this->compare_streams($v1, $v2));
        $adaptive_audios = collect(data_get($manifest, 'adaptiveFormats'))
            ->filter(fn($v) => stripos(data_get($v, 'mimeType'), 'audio') !== false)
            ->sort(fn($v2, $v1) => $this->compare_streams($v1, $v2));
        //0 = "23.videoDetails.title"
        //1 = "23.microformat.playerMicroformatRenderer.title.simpleText"
        //2 = "43.contents.twoColumnWatchNextResults.results.results.contents.0.videoPrimaryInfoRenderer.title.runs.0.text"
        //3 = "43.playerOverlays.playerOverlayRenderer.videoDetails.playerOverlayVideoDetailsRenderer.title.simpleText"
        $fname = Optional::ofNullable($document->getObjects()->getAll("**.videoDetails.title")[0] ?? null)
            ->orElseNew(fn() => $document->getObjects()->getAll("**.playerMicroformatRenderer.title.simpleText")[0] ?? null)
            ->orElseNew(fn() => $document->getObjects()->getAll("**.playerOverlayVideoDetailsRenderer.title.simpleText")[0] ?? null)
            ->map(fn($v) => $v->value())
            ->orElse(fn() => "yt_");
        $fname = $fname . " [" . $this->get_video_id($document) . "]";
        $fname = normalize(Memory::cache_get('output_dir') . "/" . filter_filename($fname) . ".mp4");
        $useFormats = function () use ($fname, $formats, $manifest, $document) {
            $video = $formats->first();
            if (Arr::has($video, 'signatureCipher')) {
                notice("This video is from a verified channel and is protected, will attempt to decipher the url");
            }
            $url = $this->get_stream_url($video, $document);
            info("Downloading Video {}", style($this->str_video_quality($video), 'blue'));
            return ThreadedDownloader::for($url, $document->getFinalUrl() . "-formats-" . data_get($video, 'itag'))
                ->validate()
                ->saveto($fname);
        };
        $useAdaptive = function () use ($document, $manifest, $useFormats, $adaptive_videos, $adaptive_audios, $formats, $fname) {
            $quality = App::args()->getOpt('quality');
            if ($quality === 'highest') {
                $video = $adaptive_videos->first();
                $audio = $adaptive_audios->first();
            } else if ($quality === "high") {
                $video = Optional::ofNullable($adaptive_videos->filter(fn($v) => str_starts_with(data_get($v, 'quality', 'hd'), 'hd'))->reverse()->first())->orElse($adaptive_videos->first());
                $audio = Optional::ofNullable($adaptive_audios->filter(fn($v) => data_get('bitrate', 0) <= 320_000)->first())->orElse($adaptive_audios->first());
            } else if ($quality === "saver") {
                $video = Optional::ofNullable($adaptive_videos->filter(fn($v) => str_starts_with(data_get($v, 'quality', 'hd'), 'hd'))->first())->orElse($adaptive_videos->first());
                $audio = Optional::ofNullable($adaptive_audios->filter(fn($v) => strstr(data_get($v, 'quality', 'medium'), 'medium') !== false)->first())->orElse($adaptive_audios->first());
            } else {// prompt
                notice("--quality option not specified, manual selection required.");
                $writer = new \Ahc\Cli\Output\Writer;
                $writer->write("Available video streams:\n");
                $arr = $adaptive_videos->values();
                $writer->table($arr->map(fn($v, $k) => [
                    'Number' => $k,
                    'Label' => $this->str_video_quality($v),
                    'BitRate' => Optional::ofNullable(data_get($v, 'bitrate'))->map(fn($v) => strtolower(human_readable_size($v, 0)) . "ps")->orElse('unknown'),
                    'Size' => Optional::ofNullable(data_get($v, 'contentLength'))->map(fn($v) => human_readable_size($v))->orElse('unknown')
                ])->all());
                $interactor = new \Ahc\Cli\IO\Interactor();
                $quality = $interactor->choice("Select video stream", $arr->keys()->all());
                if ($quality === null) {
                    throw new ExpectationFailedException("No video stream selected");
                }
                $video = $arr->get($quality);
                $arr = $adaptive_audios->values();
                $writer->write("Available audio streams:\n");
                $writer->table($arr->map(fn($v, $k) => [
                    'Number' => $k,
                    'BitRate' => Optional::ofNullable(data_get($v, 'bitrate'))->map(fn($v) => strtolower(human_readable_size($v, 0)) . "ps")->orElse('unknown'),
                    'Size' => Optional::ofNullable(data_get($v, 'contentLength'))->map(fn($v) => human_readable_size($v))->orElse('unknown')
                ])->all());
                $quality = $interactor->choice("Select audio stream", $arr->keys()->all());
                if ($quality === null) {
                    throw new ExpectationFailedException("No audio stream selected");
                }
                $audio = $arr->get($quality);
            }
            $ffmpeg = make_ffmpeg();
            if (!$ffmpeg) {
                warn("This video has better sources ({}) but it has no sound and the video must be merged with the audio source, but ffmpeg is not installed", $this->str_video_quality($video));
                warn("Will download lower quality video ({})", $this->str_video_quality($formats->first()));
                return $useFormats();
            } else {
//                preg_match("/(?<authority>https?:\/\/.*?\.com)\//", data_get($video, 'url'), $matches);
                $headers = [
//                    "authority" => data_get($matches, 'authority', "rr4---sn-5hneknes.googlevideo.com"),
                    "sec-ch-ua" => "\" Not A;Brand\";v=\"99\", \"Chromium\";v=\"98\", \"Microsoft Edge\";v=\"98\"",
                    "sec-ch-ua-mobile" => "?0",
                    "user-agent" => "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/98.0.4758.80 Safari/537.36 Edg/98.0.1108.50",
                    "sec-ch-ua-platform" => "\"Windows\"",
                    "accept" => "*/*",
                    "origin" => "https://www.youtube.com",
                    "sec-fetch-site" => "cross-site",
                    "sec-fetch-mode" => "cors",
                    "sec-fetch-dest" => "empty",
                    "referer" => "https://www.google.com/",
                    "accept-language" => "en"
                ];
                if (Arr::has($video, 'signatureCipher')) {
                    notice("This video is from a verified channel and is protected, will attempt to decipher the url");
                }
                $video_url = $this->get_stream_url($video, $document);
                $audio_url = $this->get_stream_url($audio, $document);
                try {
                    info("Downloading Video {}", style($this->str_video_quality($video), 'blue'));
                    $vfile = random_name(Memory::cache_get('output_dir'), 'stream-', 'mp4');// might not be mp4, but anyways..
                    $vdownloader = ThreadedDownloader::for($video_url, $document->getFinalUrl() . "-adaptive-video" . data_get($video, 'itag'))
                        ->with_headers($headers)
                        ->validate();
                    App::terminating(fn() => file_exists($vfile) && @unlink($vfile));
                    $afile = random_name(Memory::cache_get('output_dir'), 'stream-', 'mp3');
                    $adownloader = ThreadedDownloader::for($audio_url, $document->getFinalUrl() . "-adaptive-audio-" . data_get($video, 'itag'))
                        ->with_headers($headers)
                        ->validate();
                    App::terminating(fn() => file_exists($afile) && @unlink($afile));
                    $video_file = $vdownloader->saveto($vfile);
                    info("Downloading Audio");
                    $audio_file = $adownloader->saveto($afile);
                    info("Merging Video with Audio");
                    $indicator = new ProgressIndicator("FFmpeg");
                    merge_video_with_audio($video_file, $audio_file, $fname, fn($percentage) => $indicator->update($percentage / 100.0));
                    $indicator->clear();
                    echo PHP_EOL;
                    return $fname;
                } finally {
                    if (isset($video_file)) @unlink($video_file);
                    if (isset($audio_file)) @unlink($audio_file);
                }
            }
        };
        if ($formats->count() > 0 && $adaptive_videos->count() > 0 && $adaptive_audios->count() > 0 && $this->compare_streams($adaptive_videos->first(), $formats->first()) > 0) {
            // adaptive_video is better
            return wrapIterable($useAdaptive());
        } else {
            // video in formats is better, no merge required
            return wrapIterable($useFormats());
        }
//        throw new NotImplementedException("Youtube scraper will be implemented very soon.");
    }

    private function str_video_quality(array $v): string
    {
        return format("{}x{}[{}]", data_get($v, 'width'), data_get($v, 'height'), data_get($v, 'qualityLabel'));
    }

    private function compare_videos_quality_label(array $v1, array $v2): int
    {
        // qualityLabel examples: "1080p60 HDR"
        //                        "hd720"
        //                        "240p"
        preg_match("/(?<hd>hd)?(?<quality>\d+)p?(?<fps>\d+)?\s*(?<type>.+?)?\s*/", data_get($v1, "qualityLabel"), $matches1);
        preg_match("/(?<hd>hd)?(?<quality>\d+)p?(?<fps>\d+)?\s*(?<type>.+?)?\s*/", data_get($v2, "qualityLabel"), $matches2);

        // quality
        $diff = intval(data_get($matches1, 'quality', 0)) - intval(data_get($matches2, 'quality', 0));
        if ($diff !== 0) return $diff;

        // HDR?
        $t1 = strtoupper(data_get($matches1, 'type'));
        $t2 = strtoupper(data_get($matches2, 'type'));
        if ($t1 === "HDR" && $t2 !== "HDR") return 1; else if ($t2 === "HDR" && $t1 !== "HDR") return -1;

        // fps
        $diff = intval(data_get($matches1, 'fps', 0)) - intval(data_get($matches2, 'fps', 0));
        if ($diff !== 0) return $diff;

        // HD?
        $h1 = strtoupper(data_get($matches1, 'hd'));
        $h2 = strtoupper(data_get($matches2, 'hd'));
        if ($h1 === "HD" && $h2 !== "HD") return 1; else if ($h2 === "HD" && $h1 !== "HD") return -1;

        return intval(data_get($v1, 'contentLength', 0)) - intval(data_get($v2, 'contentLength', 0));
    }

    /**
     * returns $v1 - $v2 value
     */
    private function compare_streams(?array $v1, ?array $v2): int
    {
        // TODO: this always picks the biggest file size, maybe i can add a quality option and set it to
        // default as "best", where best considers file size and quality, another option may be "highest" which
        // will pick the largest and highest quality, another option might be "prompt" which will let the user
        // select from the quality list

        if (empty($v1)) return -1;
        if (empty($v2)) return 1;
        preg_match("/video/"
            , data_get($v1, 'mimeType', '') . data_get($v2, 'mimeType', '')
            , $matches);
        if (count($matches) > 2) return $this->compare_videos_quality_label($v1, $v2);
        // probably audio stream
        $score1 = 0.001 * intval(data_get($v1, 'bitrate'))
            + 1 * intval(data_get($v1, 'width'))
            + 1 * intval(data_get($v1, 'height'))
            + 50 * intval(data_get($v1, 'fps'))
            + 3.845e-6 * intval(data_get($v1, 'contentLength'));
        $score2 = 0.001 * intval(data_get($v2, 'bitrate'))
            + 1 * intval(data_get($v2, 'width'))
            + 1 * intval(data_get($v2, 'height'))
            + 50 * intval(data_get($v2, 'fps'))
            + 3.845e-6 * intval(data_get($v2, 'contentLength'));
        return (int)($score1 - $score2);
    }

    /**
     * @throws ExpectationFailedException if could not detect the stream url
     */
    private function get_stream_url(array $stream_manifest, Document $document): string
    {
        $this->log->debug("get_stream_url: $stream_manifest[itag]");
        if (isset($stream_manifest['url'])) {
            $this->log->debug("found direct url: $stream_manifest[url]");
            return $stream_manifest['url'];
        } else if (isset($stream_manifest['signatureCipher'])) {
            try {
                $this->log->debug("deciphering video signature: $stream_manifest[signatureCipher]");
                foreach ($document->getObjects()->values() as $key => $value) {
                    if (preg_match("/\.PLAYER_JS_URL$/", $key, $matches)) {
                        $player_src_url = "https://www.youtube.com" . $value->value();
                        break;
                    }
                }
                if (!isset($player_src_url)) {
                    throw new ExpectationFailedException("Could not decode video signature, cause: player_src_url not found");
                }
                $this->log->debug("loading player source script: $player_src_url");
                $player_src = FS::remember('player_src_' . $player_src_url, function () use ($player_src_url) {
                    $this->log->debug("player source script is not in cache, downloading from: $player_src_url");
                    $client = new HttpClient([
                        'timeout' => 60,
                        'allow_redirects' => true,
                        'verify' => false, // TODO: SSL
                    ]);
                    return $client->get($player_src_url, [
                        ReqOpt::HEADERS => CurlHttp::make_curl_headers(),
                    ])->getBody()->getContents();
                });
                preg_match("/^.+?=(?<cipher>.+?)&.+?=(?<sig_query>.+?)&url=(?<url>.+)/", $stream_manifest['signatureCipher'], $matches);
                $url = urldecode($matches['url']) . "&$matches[sig_query]=" . $this->decode_cipher($player_src, $matches['cipher']);
                $this->log->debug("extracted url: $url");
                return $url;
            } catch (Throwable $e) {
                $this->log->error($e->getMessage());
                throw new ExpectationFailedException("Could not decode video signature, cause: " . $e->getMessage(), $e);
            }
        }
        throw new ExpectationFailedException("stream url not found in stream manifest", $throw ?? null);
    }

    /**
     * @throws Exception
     */
    private function decode_cipher(string $player_src, string $cipher): string
    {
        if (strpos($cipher, '%') !== -1) {
            $this->log->debug("need to urldecode for cipher: $cipher");
            $cipher = urldecode($cipher);
        }
        // get object and function definitions from player src
        {
            $decipher_func_pattern = "/{[a-zA-Z]+=[a-zA-Z]+\\.split\\(\"\"\\);[a-zA-Z0-9$]{2}\\.[a-zA-Z0-9$]{2}.*?[a-zA-Z]+\\.join\\(\"\"\\)};/s";
            $obfuscator_object_name_pattern = "/[a-zA-Z0-9$]{2}\\.[a-zA-Z0-9$]{2}\\([a-zA-Z],(\\d\\d|\\d)\\)/s";

            preg_match($decipher_func_pattern, $player_src, $matches);
            $decipher_func_definition = $matches[0];

            preg_match($obfuscator_object_name_pattern, $decipher_func_definition, $matches);
            $obfuscator_object_name = explode(".", $matches[0])[0];
            $obfuscator_object_name_p = str_replace("$", "\\$", $obfuscator_object_name);// for patterns
            $obfuscator_object_definition_pattern = "/var\s$obfuscator_object_name_p={(?<props>.+?)};/s";

            preg_match($obfuscator_object_definition_pattern, $player_src, $matches);
            $obfuscator_object_definition = str_replace(",\n", ",", $matches['props']);
        }
        // make operations from function and object definitions
        {
            preg_match_all("/(?<name>[a-zA-Z0-9$]{2}):function/", $obfuscator_object_definition, $matches);
            $names = $matches['name'];
            $pfuncs = [];
            foreach (preg_split("/[a-zA-Z0-9$]{2}:function/", $obfuscator_object_definition, -1, PREG_SPLIT_NO_EMPTY) as $i => $def) {
                if (str_contains($def, "%")) {
                    $pfuncs[$names[$i]] = "swap";
                } else if (str_contains($def, "splice")) {
                    $pfuncs[$names[$i]] = "splice";
                } else if (str_contains($def, "reverse")) {
                    $pfuncs[$names[$i]] = "reverse";
                } else {
                    throw new Exception("could not map js instruction");
                }
            }
            preg_match("/(?<ops>$obfuscator_object_name_p\.(?<func>\([a-zA-Z0-9$],(?<arg>\d+)\));)+/", $decipher_func_definition, $matches);
            // js ops
            $jops = array_filter(explode(';', $decipher_func_definition));
            array_shift($jops);// split op
            array_pop($jops);// chars join op
        }
        // finally process js ops in php
        {
            foreach ($jops as $jop) {
                preg_match("/$obfuscator_object_name_p\.(?<func>[a-zA-Z0-9$]{2})\([a-zA-Z0-9$],(?<arg>\d+)\)/", $jop, $matches);
                $op = $pfuncs[$matches['func']];
                $arg = (int)$matches['arg'];
                if ($op === "swap") {
                    $c = $cipher[0];
                    $cipher[0] = $cipher[$arg % strlen($cipher)];
                    $cipher[$arg % strlen($cipher)] = $c;
                } else if ($op === "reverse") {
                    $cipher = strrev($cipher);
                } else if ($op === "splice") {
                    $cipher = substr($cipher, $arg);
                } else {
                    throw new Exception("unknown op");
                }
            }
            return $cipher;
        }
    }
}
