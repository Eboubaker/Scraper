<?php

namespace Eboubaker\Scrapper\Facebook;

use Eboubaker\Scrapper\Contracts\Scrapper;
use Eboubaker\Scrapper\Exceptions\ExpectationFailedException;
use Exception;
use Facebook\WebDriver\Exception\NoSuchElementException;
use Facebook\WebDriver\Exception\TimeoutException;
use Facebook\WebDriver\Remote\RemoteWebElement;
use Facebook\WebDriver\WebDriverBy;
use Facebook\WebDriver\WebDriverExpectedCondition;

class FacebookScrapper extends Scrapper
{
    public function probe_file_name(string $url): string
    {
        return parent::probe_file_name($url);
    }

    /**
     * @throws NoSuchElementException
     * @throws ExpectationFailedException
     * @throws \Facebook\WebDriver\Exception\TimeoutException
     */
    function download_media_from_post_url($post_url)
    {
        try {
            $type = $this->getPostType($post_url, $postNode);
            if ($type === TYPE_VIDEO) {
                $url = $this->extract_static_video_url($post_url, $postNode);
                debug("static video url is {}", $url);
            } else if ($type === TYPE_IMAGE) {
                error("Image Downloads for Facebook are not implemented");
                exit;
            } else {
                throw new ExpectationFailedException("No Media elements were found on this post url: $post_url");
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
     * @throws ExpectationFailedException
     */
    function extract_static_video_url($post_url, RemoteWebElement $postNode): string
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
                $clickPlay();
            } catch (NoSuchElementException $e) {
                debug("Unmute button not found, Skipped clicking the Unmute button");
            }
        };
        $clickPlay();
        $clickUnmute();
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
        urls = [...new Set(urls)].slice(0, 5)
        if(urls.length === 0) return 0 
        return urls
        JS;
        debug("pulling video url");
        $result = $this->driver->executeScript($script);
        $result = array_map(fn($u) => str_replace([' ', "\n", "\r"], '', $u), $result);

        dump("result is", $result);
        if (is_numeric($result)) {
            throw new ExpectationFailedException("The video was not played on the webpage, video url not found");
        }
        debug("Found {} possible urls", style(count($result), "blue"));
        $ffprobe = \FFMpeg\FFProbe::create();
        $result = array_map(function ($u) use ($ffprobe) {
            $video = $ffprobe
                ->streams($u)
                ->videos()
                ->first();
            $audio = $ffprobe
                ->streams($u)
                ->audios()
                ->first();
            return $video ?? $audio;
        }, $result);
        dump($result);
        return $result[0];
    }

    function getPostType($post_url, &$postNode): int
    {
        try {
            /**
             * @var RemoteWebElement $postNode
             */
            $postNode = $this->driver->wait()->until(WebDriverExpectedCondition::visibilityOfElementLocated(WebDriverBy::cssSelector('[data-pagelet="MediaViewerPhoto"],[data-pagelet="WatchPermalinkVideo"]')));
            dump($postNode);
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

