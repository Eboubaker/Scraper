<?php

namespace Eboubaker\Scrapper\Facebook;

use Eboubaker\Scrapper\Contracts\Scrapper;
use Eboubaker\Scrapper\Exceptions\ExpectationFailedException;
use Exception;
use Facebook\WebDriver\Exception\NoSuchElementException;
use Facebook\WebDriver\WebDriverBy;
use Facebook\WebDriver\WebDriverExpectedCondition;

class FacebookScrapper extends Scrapper
{
    public function probe_file_name(string $url): string
    {
        return parent::probe_file_name($url);
    }

    function download_media_from_post_url($post_url)
    {
        try {
            $type = $this->getPostType($post_url);
            if ($type === TYPE_VIDEO) {
                $url = $this->extract_static_video_url($post_url);
                debug("static video url is {}", $url);
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
    function extract_static_video_url($post_url): string
    {
        $script = <<<JS
        var respond = arguments[0];
        var container = document.querySelector('div[role="presentation"]');
        var soundControls = () => document.querySelector('[aria-label="Unmute"],[aria-label="Mute"]');
        if(!soundControls()){
            container.click();
            var checkExist = setInterval(function() {
               if (soundControls()) {
                  clearInterval(checkExist);
                  var sound = document.querySelector('[aria-label="Unmute"]');
                  if(sound) sound.click()
                  respond(true)
               }
            }, 100);
        }
        JS;
        debug("taking screenshot");
        $this->driver->takeScreenshot("screenshot1.png");
        debug("Clicking unmute button");
        $this->driver->executeAsyncScript($script);
        debug("Refreshing webpage");
        $this->driver->navigate()->refresh();
        debug("waiting for video to appear");
        $this->driver->wait()->until(WebDriverExpectedCondition::visibilityOfAnyElementLocated(WebDriverBy::cssSelector('[aria-label="Play"], [aria-label="Pause"]')));
        try {
            debug("Clicking play button");
            $this->driver->findElement(WebDriverBy::cssSelector('[aria-label="Play"]'))->click();
        } /** @noinspection PhpRedundantCatchClauseInspection */
        catch (NoSuchElementException $e) {
            debug("Play button not found, Skipped clicking the play button");
        }
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
        urls = possibleUrls.map(e => e.split('&').filter(s => !s.contains('bytestart=') && !s.contains('byteend=')).join('&'))
        urls = [...new Set(urls)].slice(0, 5)
        if(urls.length === 0) return 0 
        return urls
        JS;
        debug("pulling video url");
        $result = $this->driver->executeScript($script)[0];
        if(is_numeric($result)){
            throw new ExpectationFailedException("Static video url not found");
        }
        dump($result);
        debug("Found {} possible urls, picking first one {}", count($result), $result[0]);
        return $result[0];
    }

    function getPostType($post_url): int
    {
        try {
            $elems = $this->driver->findElements(WebDriverBy::cssSelector('[aria-label="Mute"], [aria-label="Unmute"]'));
            if (count($elems) > 0) return TYPE_VIDEO;
        } catch (NoSuchElementException $e) {
            // url is not a video
        }
        return -1;
    }
}

