<?php declare(strict_types=1);

namespace Eboubaker\Scrapper\Concerns;

use Eboubaker\Scrapper\App;
use Exception;
use FFMpeg\Media\Video;
use Psr\Log\LoggerInterface;
use ReflectionClass;

/**
 * @author Eboubaker Bekkouche <eboubakkar@gmail.com>
 */
trait ScrapperUtils
{
    private LoggerInterface $log;

    public function __construct()
    {
        $channel = (new ReflectionClass($this))->getShortName();
        $this->log = make_monolog($channel);
    }

    /**
     * returns the path to the temporary merged video,
     * the file should be cleaned after copying or on errors.
     * @throws Exception|\FFMpeg\Exception\InvalidArgumentException|\FFMpeg\Exception\RuntimeException
     * @noinspection PhpFullyQualifiedNameUsageInspection
     */
    function merge_video_with_audio(string $video_source, string $audio_source, string $output, \Closure $on_progress = null): void
    {
        $ffmpeg = make_ffmpeg();
        /** @var $vid Video */
        $vid = $ffmpeg->open($video_source);
        $vid->addFilter(new \FFMpeg\Filters\Audio\SimpleFilter(array('-i', $audio_source, '-shortest')));
        $format = new \FFMpeg\Format\Video\X264();
        $format->setPasses(1);
        if ($on_progress) {
            $format->on('progress', fn($video, $format, $percentage) => $on_progress($percentage, $video, $format));
        }
        $vid->save($format, $output);
    }

    public static function make_curl_headers(): array
    {
        $headers = [
            "accept" => "text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.9",
            "accept-language" => "ar",
            "sec-ch-ua" => "\" Not A;Brand\";v=\"99\", \"Chromium\";v=\"99\", \"Microsoft Edge\";v=\"99\"",
            "sec-ch-ua-mobile" => "?0",
            "sec-ch-ua-platform" => "\"Windows\"",
            "sec-fetch-dest" => "document",
            "sec-fetch-mode" => "navigate",
            "sec-fetch-site" => "none",
            "sec-fetch-user" => "?1",
            "upgrade-insecure-requests" => "1"
        ];
        $cli_headers = App::bootstrapped() ? App::args()->getOpt('header', []) : [];
        if (!($user_agent = data_get($cli_headers, array_search_match($cli_headers, [
                null => "/User-Agent\s*?:\s*?.+/i"
            ]) ?? ""))) {
            $headers["user-agent"] = "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/99.0.4844.51 Safari/537.36 Edg/99.0.1150.36";
        }
        return $headers + collect($cli_headers)->map('headers_array_to_associative')->all();
    }
}
