<?php declare(strict_types=1);

namespace Eboubaker\Scrapper\Scrappers;

use Eboubaker\Scrapper\App;
use Eboubaker\Scrapper\Concerns\ScrapperUtils;
use Eboubaker\Scrapper\Contracts\Scrapper;
use Eboubaker\Scrapper\Exception\DownloadFailedException;
use Eboubaker\Scrapper\Exception\ExpectationFailedException;
use Eboubaker\Scrapper\Exception\WebPageNotLoadedException;
use Eboubaker\Scrapper\Tools\Http\Document;
use Eboubaker\Scrapper\Tools\Http\ThreadedDownloader;
use Throwable;

/**
 * @author Eboubaker Bekkouche <eboubakkar@gmail.com>
 */
final class FacebookScrapper implements Scrapper
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
    function scrap(Document $document): string
    {
        if ($document->getContentLength() < bytes('300kb')) {
            notice("Response size is too small: {}", style(human_readable_size($document->getContentLength()), 'red'));
        }
        if (preg_match("/\/login\/\?next=/", $document->getFinalUrl())) {
            throw new WebPageNotLoadedException("Facebook redirected me to the login page, This post might be private, try adding required header cookies");
        }

        $data_bag = $document->getDataBag();
        $base = "https?:\/\/.{1,3}\.facebook\.com";
        if (preg_match("/$base\/(?<poster>[^\/]+)\/videos\/(?<video_id>\d+)/", $document->getFinalUrl(), $matches)) {
            download_by_video_id:
            $owner = data_get($data_bag, array_search_match($data_bag, [
                    "id" => "/$matches[video_id]/",
                    "owner.name"
                ]) . ".owner.name", fn() => data_get($matches, 'poster'));
            info("Downloading Video $matches[video_id]" . putif($owner, " from $owner"));
            $url = data_get($data_bag, array_search_match($data_bag, [
                    "id" => "/$matches[video_id]/",
                    "playable_url_quality_hd"
                ]) . ".playable_url_quality_hd",
                fn() => data_get($data_bag, array_search_match($data_bag, [
                        "id" => "/$matches[video_id]/",
                        "playable_url"
                    ]) . ".playable_url"));
            $downloader = new ThreadedDownloader($url, 32);
            $fname = normalize(App::cache_get('output_dir') . "/" . putif($owner, "$owner ") . $matches['video_id'] . ".mp4");
            return $downloader->saveto($fname);
        } else if (preg_match("/$base\/watch(\?.*?)v=(?<video_id>\d+)/", $document->getFinalUrl(), $matches)) {
            goto download_by_video_id;
        } else if (preg_match("/$base\/photo\/(\?.*?)fbid=(?<image_id>\d+)/", $document->getFinalUrl(), $matches)) {
            download_by_image_id:
            $owner = data_get($data_bag, array_search_match($data_bag, [
                    "id" => "/$matches[image_id]/",
                    "owner.name"
                ]) . ".owner.name", fn() => data_get($matches, 'poster'));
            info("Downloading Image $matches[image_id]" . putif($owner, " from $owner"));
            $url = data_get($data_bag, array_search_match($data_bag, [
                    "id" => "/$matches[image_id]/",
                    "image.uri"
                ]) . ".image.uri");
            $downloader = new ThreadedDownloader($url, 8);
            $fname = normalize(App::cache_get('output_dir') . "/" . putif($owner, "$owner ") . $matches['image_id'] . ".jpg");
            return $downloader->saveto($fname);
        } else if (preg_match("/$base\/(?<poster>[^\/]+)\/photos\/[^\/]+\/(?<image_id>\d+)/", $document->getFinalUrl(), $matches)) {
            goto download_by_image_id;
        } else if (preg_match("/$base\/(?<poster>[^\/]+)\/videos\/([^\/]+?\/)?(?<video_id>\d+)/", $document->getFinalUrl(), $matches)) {
            goto download_by_video_id;
        } else {
            throw new ExpectationFailedException("No media elements were found in this post");
        }
    }
}

