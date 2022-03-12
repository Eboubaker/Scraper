<?php declare(strict_types=1);

namespace Eboubaker\Scrapper\Scrappers;

use Eboubaker\Scrapper\Concerns\ScrapperUtils;
use Eboubaker\Scrapper\Contracts\Scrapper;
use Eboubaker\Scrapper\Exception\WebPageNotLoadedException;

/**
 * @author Eboubaker Bekkouche <eboubakkar@gmail.com>
 */
class FacebookScrapper implements Scrapper
{
    use ScrapperUtils;

    public static function can_scrap(Document $document): bool
    {
        return !!preg_match("/https?:\/\/(.+\.)?facebook\.com\//", $document->getFinalUrl());
    }

    /**
     * @throws WebPageNotLoadedException
     * @throws DownloadFailedException
     * @throws ExpectationFailedException
     * @throws Throwable
     */
    function download_media_from_html_document(Document $document): string
    {
        if (preg_match("/\/login\/\?next=/", $final_url)) {
            throw new WebPageNotLoadedException("Facebook redirected you to the login page, This post might be private, try logging in first");
        }
        $data_bag = collect_all_json($html_document);
        $image = data_get($data_bag, array_search_match($data_bag, [
                "currMedia.image.uri",
                "currMedia.__isMedia" => "/Photo/",
            ]) . '.currMedia.image');
        $route = data_get($data_bag, array_search_match($data_bag, [
                "url",
                "routePath",
            ]) ?? '@@');
        $videos = data_get($data_bag, array_search_match($data_bag, [
                "data.video.story.attachments",
            ]) . ".data.video.story.attachments");
        if ($videos) {
            foreach ($videos as $video) {
                notice("VIDEO URL: {}", data_get($video, 'media.playable_url_quality_hd',
                    fn() => data_get($video, 'media.playable_url')));
            }
        }
        if (isset($image['uri'])) {
            notice("IMAGE URL: {}", $image['uri']);
        } else if (!$videos || count($videos) == 0) {
            error("No media elements were found in this post");
        }
        return '';
    }
}

