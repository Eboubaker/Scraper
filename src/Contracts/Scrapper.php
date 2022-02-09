<?php declare(strict_types=1);

namespace Eboubaker\Scrapper\Contracts;

use Eboubaker\Scrapper\Exception\UrlNotSupportedException;
use Eboubaker\Scrapper\Exception\WebPageNotLoadedException;
use Eboubaker\Scrapper\Scrappers\FacebookScrapper;
use Eboubaker\Scrapper\Scrappers\YoutubeScrapper;

define("TYPE_VIDEO", 1);
define("TYPE_IMAGE", 2);

abstract class Scrapper
{
    /**
     * download the media in the document, should return the filename
     */
    abstract function download_media_from_html_document(string $html_document): string;

    /**
     * returns the proper scrapper that can handle the given url.
     * The url must be a final url meaning it should not do a redirect to a different url.
     *
     * @throws UrlNotSupportedException if failed to determine required scrapper
     */
    public static function getRequiredScrapper(string $url): Scrapper
    {
        if (FacebookScrapper::can_scrap($url))
            return new FacebookScrapper();
        else if (YoutubeScrapper::can_scrap($url))
            return new YoutubeScrapper();
        // TODO: add pr request link for new scrapper
        warn("{} is probably not supported", $url);
        // TODO: add how to do login when it is implemented
        notice("if the post url is private you might need to login first");
        throw new UrlNotSupportedException("Could not determine which extractor to use");
    }

    /**
     * @throws WebPageNotLoadedException
     */
    public static function load_webpage(string $url, $add_headers = []): array
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, TRUE);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 10);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'User-Agent: User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/97.0.4692.99 Safari/537.36 Edg/97.0.1072.76',
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.9',
            'cache-control: max-age=0',
            ...$add_headers
        ]);
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
     * returns true if the url can be handled by this scrapper.
     * The function should return a boolean and should not raise any exceptions.
     */
    public static abstract function can_scrap($url): bool;
}