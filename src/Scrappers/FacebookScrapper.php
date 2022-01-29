<?php

namespace Eboubaker\Scrapper\Scrappers;

use Eboubaker\Scrapper\Contracts\Scrapper;
use Eboubaker\Scrapper\Exception\DriverConnectionException;
use Exception;
use Facebook\WebDriver\Exception\NoSuchElementException;
use Facebook\WebDriver\Exception\TimeoutException;
use Facebook\WebDriver\Remote\RemoteWebElement;
use Facebook\WebDriver\WebDriverBy;
use Facebook\WebDriver\WebDriverExpectedCondition;
use FFMpeg\FFProbe\DataMapping\StreamCollection;
use Tightenco\Collect\Support\Collection;

class FacebookScrapper extends Scrapper
{
    public static function can_scrap($url): bool
    {
        return preg_match("/https?:\/\/(web|m|www)\.facebook\.com\//", $url);
    }

    /**
     * @throws NoSuchElementException
     * @throws DriverConnectionException
     * @throws \Facebook\WebDriver\Exception\TimeoutException
     */
    function download_media_from_post_url($post_url)
    {
        try {
            $type = $this->getPostType($post_url, $postNode);
            if ($type === TYPE_VIDEO) {
                /**
                 * @var StreamCollection[] $found_streams
                 */
                $found_streams = $this->extract_static_video_sources($post_url, $postNode);
            } else if ($type === TYPE_IMAGE) {
                error("Image Downloads for Facebook are not implemented");
                exit;
            } else {
                throw new DriverConnectionException("No Media elements were found on this post url: $post_url");
            }
        } catch (Exception $e) {
            error("Failed to locate media element in url $post_url");
            $this->close();
            throw $e;
        }
    }

    /**
     * @throws NoSuchElementException
     * @throws \Facebook\WebDriver\Exception\TimeoutException
     * @throws DriverConnectionException
     */
    function extract_static_video_sources($post_url, RemoteWebElement $postNode): Collection
    {
        $clickPlay = function () {
            try {
                debug("Clicking play button");
                $play = $this->driver->findElement(WebDriverBy::cssSelector('[aria-label="Play video"],[aria-label="Play"]'));
                $this->driver->executeScript("arguments[0].click();", [$play]);
                debug("play button was clicked");
            } catch (NoSuchElementException $e) {
                debug("Play button not found, Skipped clicking the play button");
            }
        };
        $clickUnmute = function () use ($postNode, $clickPlay) {
            try {
                debug("Clicking Unmute button");
                $control = $postNode->findElement(WebDriverBy::cssSelector('[aria-label="Unmute"]'));
                $this->driver->executeScript("arguments[0].click();", [$control]);
                debug("Unmute button was clicked");
                debug("Refreshing page");
                $this->driver->navigate()->refresh();
                $this->driver->executeScript("(window.performance || window.mozPerformance || window.msPerformance || window.webkitPerformance).clearResourceTimings()");
                $clickPlay();
            } catch (NoSuchElementException $e) {
                debug("Unmute button not found, Skipped clicking the Unmute button");
            }
        };
//        $clickPlay();
//        $clickUnmute();
        sleep(3);
        $script = <<<JS
        var performance = window.performance || window.mozPerformance || window.msPerformance || window.webkitPerformance || {}
        var traffic = performance.getEntries() || []
        var streamFetches = traffic.filter(t => t.initiatorType === 'fetch').map(t => t.name)
        var possibleUrls = streamFetches.sort((u1, u2) => {
            let score = url => {
                let p = 0
                if(url.contains('scontent')) p += 12
                if(url.contains('bytestart=')) p += 5
                if(url.contains('byteend=')) p += 5
                if(url.contains('mp4?')) p += 4
                if(url.contains('video')) p += 3
                return p
            }
            return score(u2) - score(u1) 
        })
        urls = possibleUrls.map(e => e.split('&').filter(s => !s.contains('bytestart=') && !s.contains('byteend=')).join('&')).map(e => encodeURI(e))
        urls = [...new Set(urls)]
        //urls = url.slice(0, 5)
        return urls
        JS;
        debug("pulling video sources");
        file_put_contents("found_urls.txt", '');
        $found_urls = collect($this->driver->executeScript($script))
            ->map(fn($u) => str_replace([' ', "\n", "\r"], '', $u));
        $found_urls->each(fn($u) => file_put_contents("found_urls.txt", $u . PHP_EOL, FILE_APPEND | LOCK_EX));
        if (is_numeric($found_urls)) {
            throw new DriverConnectionException("The video was not played on the webpage, video url not found");
        }
        debug("Found {} possible urls", style(count($found_urls), "blue"));
        $ffprobe = \FFMpeg\FFProbe::create();
        $found_streams = collect($found_urls)->map(function ($u) use ($ffprobe) {
            $streams = $ffprobe->streams($u);
            collect($streams->all())->each(fn($s) => $s->set('url', $u));
            if ($streams->videos()->first() == null && $streams->audios()->first() == null) return null;
            return $streams;
        })->filter();
        if ($found_streams->count() > 0)
            info("The following media sources where found:");
        else
            error("No video sources were found");
        $found_streams->each(function (StreamCollection $streams) {
            $video = $streams->videos()->first();
            $audio = $streams->audios()->first();
            $d = $video ? $video->getDimensions() : null;
            if ($video && $audio) {
                echo format("{} {} {}",
                        style("VIDEO", "magenta", "bold"),
                        style(format("({}x{})", $d->getWidth(), $d->getHeight()), "blue"),
                        $video->get('url'))
                    . "\n";
            } else if ($video) {
                echo format("{} {} {}",
                        style("VIDEO(NO AUDIO)", "magenta", "bold"),
                        style(format("({}x{})", $d->getWidth(), $d->getHeight()), "blue"),
                        $video->get('url'))
                    . "\n";
            } else {
                echo format("{} {}",
                        style("AUDIO", "magenta", "bold"),
                        $audio->get('url'))
                    . "\n";
            }
        });
        return $found_urls;
    }

    /**
     * @throws Exception
     */
    function getPostType($post_url, &$postNode): int
    {
        info("Getting media type");
        try {
            /**
             * @var RemoteWebElement $postNode
             */
            $postNode = $this->driver->wait()->until(WebDriverExpectedCondition::visibilityOfElementLocated(WebDriverBy::cssSelector('[data-pagelet="MediaViewerPhoto"],[data-pagelet="WatchPermalinkVideo"]')));
            switch ($postNode->getAttribute("data-pagelet")) {
                case "MediaViewerPhoto":
                    return TYPE_IMAGE;
                case "WatchPermalinkVideo":
                    return TYPE_VIDEO;
            }
        } catch (NoSuchElementException | TimeoutException $e) {
            debug($e->getMessage(), $e->getFile(), $e->getLine());
        }
        debug("post container did not match any type");
        return -1;
    }


}

