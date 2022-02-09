<?php declare(strict_types=1);

namespace Eboubaker\Scrapper\Scrappers;

use Eboubaker\Scrapper\Contracts\Scrapper;

class FacebookScrapper extends Scrapper
{
    public static function can_scrap($url): bool
    {
        return !!preg_match("/https?:\/\/((web|m|www)\.)?facebook\.com\//", $url);
    }

    /**
     */
    function download_media_from_html_document(string $html_document): string
    {
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
        foreach ($videos as $video) {
            notice("VIDEO URL: {}", data_get($video, 'media.playable_url_quality_hd',
                fn() => data_get($video, 'media.playable_url')));
        }
        if (isset($image['uri'])) {
            notice("IMAGE URL: {}", $image['uri']);
        } else if (count($videos) == 0) {
            error("No media elements were found in this post");
        }
        return '';
    }
}

