<?php

namespace Eboubaker\Scrapper\Scrappers;

use Eboubaker\Scrapper\App;
use Eboubaker\Scrapper\Contracts\Scrapper;
use Eboubaker\Scrapper\Exception\DriverConnectionException;
use Eboubaker\Scrapper\Exception\ExpectationFailedException;
use Eboubaker\Scrapper\Exception\NotImplementedException;
use Exception;
use Facebook\WebDriver\Exception\NoSuchElementException;
use Facebook\WebDriver\Exception\TimeoutException;
use Facebook\WebDriver\Remote\RemoteWebElement;
use Facebook\WebDriver\WebDriverBy;
use Facebook\WebDriver\WebDriverExpectedCondition;
use FFMpeg\Coordinate\Dimension;
use FFMpeg\Exception\RuntimeException;
use FFMpeg\FFProbe;
use FFMpeg\FFProbe\DataMapping\Stream;
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
     * @throws TimeoutException
     * @throws ExpectationFailedException
     * @throws NotImplementedException
     */
    function download_media_from_post_url($post_url): string
    {
        try {
            $type = $this->getPostType($post_url, $postNode);
        } catch (Exception $e) {
            throw new ExpectationFailedException(format("Failed to determine Media type in url: {}", $post_url), $e);
        }
        if ($type === TYPE_VIDEO) {
            info("Detected video element");
            /**
             * @var StreamCollection[]|Collection $found_streams
             */
            $found_streams = $this->extract_static_video_sources($post_url, $postNode);
            info("Picking best video");
            $this->close();
            $best_quality_picker = function (/** @var null|Stream $best */ $best, Stream $video) {
                if ($best === null) return $video;
                // TODO: just comparing resolution is a weak approach (https://superuser.com/a/338734/1072749)
                $d1 = new Dimension(1, 1);
                $d2 = new Dimension(1, 1);
                try {
                    $d1 = $best->getDimensions();
                } catch (RuntimeException $e) {
                    debug("getDimensions() failed {}:{}", __FILE__, __LINE__);
                }
                try {
                    $d2 = $video->getDimensions();
                } catch (RuntimeException $e) {
                    debug("getDimensions() failed {}:{}", __FILE__, __LINE__);
                }
                $best->set('qscore', $d1->getWidth() * $d1->getHeight());
                $video->set('qscore', $d2->getWidth() * $d2->getHeight());
                if ($video->get('qscore') > $best->get('qscore'))
                    return $video;
                else
                    return $best;
            };
            // TODO: also check other videos in the list not only the first one
            /**
             * @var Stream $video
             */
            $video = $found_streams->filter(fn(StreamCollection $streams) => $streams->first()->get('video-only'))
                ->map(fn(StreamCollection $streams) => $streams->videos()->first())
                ->reduce($best_quality_picker);
            /**
             * @var Stream $audio
             */
            $audio = $found_streams->filter(fn(StreamCollection $streams) => $streams->first()->get('audio-only'))->first()->first();
            /**
             * @var Stream $full_video
             */
            $full_video = $found_streams->filter(fn(StreamCollection $streams) => $streams->first()->get('full-stream'))
                ->map(fn(StreamCollection $streams) => $streams->videos()->first())
                ->reduce($best_quality_picker);

            if ($video !== null && $audio !== null && ($full_video === null || $video->get('qscore') > $full_video->get('qscore'))) {
                // TODO: generate default video name (possibly infer from url)
                warn("Will merge video and audio steams");
                return merge_video_with_audio($video, $audio, App::get('output', time() . '.mp4'), new \FFMpeg\Format\Video\X264());
            } else if ($full_video !== null) {
                warn("Downloading from static url {}", $full_video->get('url') ?? "NULL");
                $file = download_static_media_url($full_video->get('url'), App::get('output', time() . '.mp4'));
                if (!$file) {
                    throw new ExpectationFailedException("Could not download url: {}", $full_video->get('url') ?? "NULL");
                }
                return $file;
            } else {
                throw new ExpectationFailedException("No video source was found");
            }
        } else if ($type === TYPE_IMAGE) {
            info("Detected image element");
            throw new NotImplementedException("Image Downloads for Facebook are not implemented");
        } else {
            throw new ExpectationFailedException("No Media elements were found on this post url: $post_url");
        }
    }

    /**
     * @throws NoSuchElementException
     * @throws TimeoutException
     * @throws DriverConnectionException
     * @throws ExpectationFailedException
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
        // TODO: detect in javascript when to stop reading
        sleep(5);
        $script = <<<JS
        var performance = window.performance || window.mozPerformance || window.msPerformance || window.webkitPerformance || {}
        var traffic = performance.getEntries() || []
        var possibleUrls = traffic.filter(t => t.initiatorType === 'fetch').map(t => t.name)
        urls = possibleUrls.map(e => e.split('&').filter(s => !s.contains('bytestart=') && !s.contains('byteend=')).join('&')).map(e => encodeURI(e))
        // TODO: Look in &oe= query string to define uniqueness instead of using Set
        urls = [...new Set(urls)]
        //urls = url.slice(0, 5)
        return urls
        JS;
        info("pulling video sources");
        file_put_contents(logfile(), time() . ': Found sources:' . PHP_EOL, FILE_APPEND | LOCK_EX);
        $found_urls = collect($this->driver->executeScript($script))
            ->map(fn($u) => str_replace([' ', "\n", "\r"], '', $u));
        $found_urls->each(fn($u) => file_put_contents(logfile(), $u . PHP_EOL, FILE_APPEND | LOCK_EX));
        if (count($found_urls) === 0) {
            throw new ExpectationFailedException("The video was not played on the webpage, video url not found");
        }
        info("Found {} possible urls", style(count($found_urls), "blue"));
        $ffprobe = FFProbe::create();
        $found_streams = collect($found_urls)->map(function ($u) use ($ffprobe) {
            $streams = $ffprobe->streams($u);
            collect($streams->all())->each(fn($s) => $s->set('url', $u));
            if ($streams->videos()->first() == null && $streams->audios()->first() == null) return null;
            return $streams;
        })->filter();
        unset ($found_urls);
        if ($found_streams->count() > 0)
            info("The following media sources where found:");
        else
            error("No video sources were found");
        $found_streams->each(function (StreamCollection $streams, $i) {
            $video = $streams->videos()->first();
            $audio = $streams->audios()->first();
            $d = $video ? $video->getDimensions() : null;
            // TODO: Not valid because we sorted the streams in javascript
            $is_source = $i === 0;
            if ($video && $audio) {
                $video->set('full-stream', true);
                $audio->set('full-stream', true);
                echo format("{} {} {}",
                        style("VIDEO" . putif($is_source, "(SOURCE)"), "magenta", "bold"),
                        style(format("({}x{})", $d->getWidth(), $d->getHeight()), "blue"),
                        $video->get('url'))
                    . "\n";
            } else if ($video) {
                $video->set('video-only', true);
                echo format("{} {} {}",
                        style("VIDEO(NO AUDIO)" . putif($is_source, "(SOURCE)"), "magenta", "bold"),
                        style(format("({}x{})", $d->getWidth(), $d->getHeight()), "blue"),
                        $video->get('url'))
                    . "\n";
            } else {
                $audio->set('audio-only', true);
                echo format("{} {}",
                        style("AUDIO", "magenta", "bold"),
                        $audio->get('url'))
                    . "\n";
            }
        });
        return $found_streams;
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

