<?php declare(strict_types=1);

namespace Eboubaker\Scrapper\Concerns;

use Eboubaker\Scrapper\App;
use Eboubaker\Scrapper\Contracts\Scrapper;
use Eboubaker\Scrapper\Exception\UrlNotSupportedException;
use Eboubaker\Scrapper\Exception\WebPageNotLoadedException;
use Eboubaker\Scrapper\Scrappers\FacebookScrapper;
use Eboubaker\Scrapper\Scrappers\YoutubeScrapper;
use Exception;

/**
 * @author Eboubaker Bekkouche <eboubakkar@gmail.com>
 */
trait ScrapperUtils
{
    /**
     * @throws WebPageNotLoadedException
     */
    public static function load_webpage(string $url): array
    {
        $ch = curl_init($url);
        // cainfo.pem can be obtained from here: https://curl.se/ca/cacert.pem
        // curl_setopt($ch, CURLOPT_CAINFO, rootpath("extras/cacert.pem"));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, TRUE);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 10);
        $headers = [
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.9',
            'cache-control: max-age=0',
        ];
        $cli_headers = App::args()->getOpt('header', []);
        if (!($user_agent = data_get($cli_headers, array_search_match($cli_headers, [
                null => "/User-Agent\s*?:\s*?.+/i"
            ]) ?? "@@"))) {
            $headers[] = "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/98.0.4758.80 Safari/537.36 Edg/98.0.1108.43";
        }
        foreach ($cli_headers as $header) {
            $headers[] = $header;
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        info("Downloading webpage: {}", $url);
        $html_document = curl_exec($ch);

        if (curl_errno($ch)) {
            error(curl_error($ch));
            throw new WebPageNotLoadedException(format("Could not load webpage: {}", $url));
        }

        if (debug_enabled()) {
            $log = rootpath('logs/cached_responses/' . md5($url));
            if (@file_put_contents(rootpath('logs/cached_responses/' . md5($url)), $html_document)) {
                debug("saved response as: {}", $log);
            } else {
                debug("failed to save response as: {}", $log);
            }
        }

        $response_size = curl_getinfo($ch, CURLINFO_SIZE_DOWNLOAD_T);
        $final_url = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        info("Final url was: {}", $final_url ?? style("NULL", 'red'));
        info("Response size: {}", style(human_readable_size($response_size), $response_size < bytes('300kb') ? 'red' : ''));
        curl_close($ch);

        return [
            $html_document,
            $final_url,
        ];
    }


    /**
     * returns the proper scrapper that can handle the given url.
     * The url must be a final url meaning it should not do a redirect to a different url.
     *
     * @throws UrlNotSupportedException if failed to determine required scrapper
     */
    public static function getRequiredScrapper(string $final_url, ?string $html_content): Scrapper
    {
        if (FacebookScrapper::can_scrap($final_url, $html_content))
            return new FacebookScrapper();
        else if (YoutubeScrapper::can_scrap($final_url, $html_content))
            return new YoutubeScrapper();
        // TODO: add pr request link for new scrapper
        warn("{} is probably not supported", $final_url);
        // TODO: add how to do login when it is implemented
        notice("if the post url is private you might need to login first");
        throw new UrlNotSupportedException("Could not determine which extractor to use");
    }

    /**
     * returns the path to the temporary merged video,
     * the file should be cleaned after copying or on errors.
     * @throws Exception|\FFMpeg\Exception\InvalidArgumentException|\FFMpeg\Exception\RuntimeException
     * @noinspection PhpFullyQualifiedNameUsageInspection
     */
    protected function merge_video_with_audio(string $video_url, string $audio_url): ?string
    {
        $ffmpeg = \FFMpeg\FFMpeg::create([
            "ffprobe.binaries" => get_ffmpeg_path()
        ]);
        $vid = $ffmpeg->open($video_url);
        $vid->addFilter(new \FFMpeg\Filters\Audio\SimpleFilter(array('-i', $audio_url, '-shortest')));
        $name = tempnam(sys_get_temp_dir(), time() . ".scrapper");
        $vid->save(new \FFMpeg\Format\Video\X264(), $name);
        return $name;
    }
}