<?php declare(strict_types=1);

namespace Eboubaker\Scrapper\Scrappers;

use Eboubaker\Scrapper\App;
use Eboubaker\Scrapper\Concerns\WritesLogs;
use Eboubaker\Scrapper\Contracts\Scrapper;
use Eboubaker\Scrapper\Exception\ExpectationFailedException;
use Eboubaker\Scrapper\Exception\NotImplementedException;
use Eboubaker\Scrapper\Scrappers\Shared\ScrapperUtils;
use Eboubaker\Scrapper\Tools\CLI\ProgressIndicator;
use Eboubaker\Scrapper\Tools\Http\Document;
use Eboubaker\Scrapper\Tools\Http\ThreadedDownloader;
use Exception;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\RequestOptions as ReqOpt;
use Throwable;

/**
 * @author Eboubaker Bekkouche <eboubakkar@gmail.com>
 */
final class YoutubeScrapper implements Scrapper
{
    use ScrapperUtils, WritesLogs;

    public static function can_scrap(Document $document): bool
    {
        return !!preg_match("/https?:\/\/((m|www)\.)?youtu(be)?(-nocookie)?\.(com|be)\//", $document->getFinalUrl());
    }

    /**
     * @throws NotImplementedException
     * @throws Exception
     * @throws Throwable
     */
    function scrap(Document $document): string
    {
        $data_bag = $document->getDataBag();
        $video_manifest = data_get($data_bag, array_search_match($data_bag, [
                "streamingData.formats",
                "streamingData.adaptiveFormats"
            ]) ?? "");
        $formats = collect(data_get($video_manifest, 'streamingData.formats'))->sort(fn($v2, $v1) => $this->compare_streams($v1, $v2));
        $adaptive_videos = collect(data_get($video_manifest, 'streamingData.adaptiveFormats'))
            ->filter(fn($v) => stripos(data_get($v, 'mimeType'), 'video') !== false)
            ->sort(fn($v2, $v1) => $this->compare_streams($v1, $v2));
        $adaptive_audios = collect(data_get($video_manifest, 'streamingData.adaptiveFormats'))
            ->filter(fn($v) => stripos(data_get($v, 'mimeType'), 'audio') !== false)
            ->sort(fn($v2, $v1) => $this->compare_streams($v1, $v2));
        $fname = normalize(App::args()->getOpt('output', getcwd()) . "/" . filter_filename(data_get($video_manifest, 'videoDetails.title', "download_" . $document->getFinalUrl())) . ".mp4");
        $useFormats = function () use ($fname, $formats, $video_manifest, $document, $data_bag) {
            $video = $formats->first();
            preg_match("/(?<authority>https?:\/\/.*?\.com)\//", data_get($video, 'url'), $matches);
            info("Downloading Video {}", style($this->str_video_quality($video), 'blue'));
            return ThreadedDownloader::for($this->get_stream_url($video, $data_bag))
                ->validate()
                ->saveto($fname);
        };
        $useAdaptive = function () use ($data_bag, $document, $video_manifest, $useFormats, $adaptive_videos, $adaptive_audios, $formats, $fname) {
            $ffmpeg = make_ffmpeg();
            $video = $adaptive_videos->first();
            $audio = $adaptive_audios->first();
            if (!$ffmpeg) {
                warn("This video has better sources ({}) but it has no sound and the video must be merged with the audio source, but ffmpeg is not installed", $this->str_video_quality($video));
                if (!host_is_windows_machine()) {
                    warn("FFmpeg is not installed please install it to have better output, (apt-get install ffmpeg)");
                } else {
                    warn("FFmpeg must be installed and put into PATH, https://www.ffmpeg.org/download.html");
                }
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
                info("Downloading Video {}", style($this->str_video_quality($video), 'blue'));
                $video_file = ThreadedDownloader::for($this->get_stream_url($video, $data_bag))
                    ->with_headers($headers)
                    ->validate()
                    ->saveto(random_name(App::cache_get('output_dir'), 'scr', 'mp4'));//mp4 is ~not~ correct
                info("Downloading Audio");
                $audio_file = ThreadedDownloader::for($this->get_stream_url($audio, $data_bag))
                    ->with_headers($headers)
                    ->validate()
                    ->saveto(random_name(App::cache_get('output_dir'), 'scr', 'mp3'));//mp3 is ~not~ correct
                info("Merging Video with Audio");
                $indicator = new ProgressIndicator("FFmpeg");
                try {
                    $this->merge_video_with_audio($video_file, $audio_file, $fname, fn($percentage) => $indicator->update($percentage / 100.0));
                    $indicator->clear();
                    echo PHP_EOL;
                    return $fname;
                } finally {
                    @unlink($video_file);
                    @unlink($audio_file);
                }
            }
        };
        if ($formats->count() > 0 && $adaptive_videos->count() > 0 && $adaptive_audios->count() > 0 && $this->compare_streams($adaptive_videos->first(), $formats->first()) > 0) {
            // adaptive_video is better
            return $useAdaptive();
        } else {
            // video in formats is better, no merge required
            return $useFormats();
        }
//        throw new NotImplementedException("Youtube scrapper will be implemented very soon.");
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
    private function get_stream_url(array $stream_manifest, array $data_bag): string
    {

        if (isset($stream_manifest['url'])) {
            $this->log->debug("found direct url: $stream_manifest[url]");
            return $stream_manifest['url'];
        } else if (isset($stream_manifest['signatureCipher'])) {
            try {
                $this->log->debug("Extracting url from video signature", ["signatureCipher" => $stream_manifest["signatureCipher"]]);
                array_preg_find_key_paths($data_bag, "/^PLAYER_JS_URL$/", $matches);
                $player_src_url = "https://www.youtube.com" . data_get($data_bag, implode('.', $matches[0]));
                $client = new HttpClient([
                    'timeout' => 60,
                    'allow_redirects' => true,
                    'verify' => false, // TODO: SSL
                ]);
                $player_src = $client->get($player_src_url, [
                    ReqOpt::HEADERS => ScrapperUtils::make_curl_headers(),
                ])->getBody()->getContents();
                preg_match("/^.+?=(?<cipher>.+?)&.+?=(?<sig_query>.+?)&url=(?<url>.+)/", $stream_manifest['signatureCipher'], $matches);
                $url = urldecode($matches['url']) . "&$matches[sig_query]=" . $this->decode_cipher($player_src, $matches['cipher']);
                $this->log->debug("extracted url: $url");
                return $url;
            } catch (Throwable $e) {
                $this->log->error($e->getMessage());
                $throw = $e;
            }
        }
        throw new ExpectationFailedException("stream url not found in stream manifest", $throw ?? null);
    }

    /**
     * @throws Exception
     */
    private function decode_cipher(string $player_src, string $cipher): string
    {
        // TODO: The function does not always succeed in deciphering the code, needs investigation....
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
                }
            }
            return $cipher;
        }
    }
}
