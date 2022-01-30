<?php

namespace Eboubaker\Scrapper\Contracts;

use Eboubaker\Scrapper\Exception\UrlNotSupportedException;
use Eboubaker\Scrapper\Scrappers\FacebookScrapper;
use Eboubaker\Scrapper\Scrappers\RedditScrapper;
use Exception;
use Facebook\WebDriver\Remote\RemoteWebDriver;

define("TYPE_VIDEO", 1);
define("TYPE_IMAGE", 2);

abstract class Scrapper
{
    protected RemoteWebDriver $driver;
    protected bool $wasClosed;

    public function __construct(RemoteWebDriver $driver)
    {
        $this->driver = $driver;
        $this->wasClosed = false;
    }

    /**
     * guess the filename from the media url
     * @ForOverride
     */
    function probe_file_name(string $url): string
    {
        return explode('/', explode('?', $url)[0])[1];
    }

    /**
     * save the url as file
     * @return string the saved file's relative path
     */
    function saveUrl(string $url, $filename = null): string
    {
        if ($filename == null) {
            $filename = $this->probe_file_name($url);
        }
        if (!is_dir("downloads")) {
            mkdir("downloads");
        }
        $filename = "downloads" . DIRECTORY_SEPARATOR . $filename;
        echo "filename: $filename\n";
        $ch = curl_init($url);
        $fp = fopen($filename, 'wb');
        curl_setopt($ch, CURLOPT_FILE, $fp);
        curl_setopt($ch, CURLOPT_HEADER, 0);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        echo "starting download\n";
        curl_exec($ch);
        curl_close($ch);
        fclose($fp);
        return $filename;
    }

    /**
     * download the media in post should return the filename
     */
    abstract function download_media_from_post_url($post_url): string;

    /**
     * close any resources used by the scrapper such as the Webdriver connection
     */
    public function close(): bool
    {
        if (!$this->wasClosed) {
            debug("sending close signal to webdriver");
            try {
                $this->driver->close();
                $this->wasClosed = true;
                return true;
            } catch (Exception $e) {
                error("Failed to close webdriver");
            }
        } else {
            debug("driver was already closed");
        }
        return false;
    }

    /**
     * returns the proper scrapper that can handle the given url.
     * The url must be a final url meaning it should not do a redirect to a different url.
     *
     * @throws UrlNotSupportedException if failed to determine required scrapper
     */
    public static function getRequiredScrapper(string $url, RemoteWebDriver $driver): Scrapper
    {
        if (RedditScrapper::can_scrap($url))
            return new RedditScrapper($driver);
        else if (FacebookScrapper::can_scrap($url))
            return new FacebookScrapper($driver);
        // TODO: add pr request link for new scrapper
        warn("{} is probably not supported", $url);
        // TODO: add how to do login when it is implemented
        notice("if the post url is private you might need to login first");
        throw new UrlNotSupportedException("Could not determine which extractor to use");
    }

    /**
     * returns true if the url can be handled by this scrapper.
     * The function should return a boolean and should not raise any exceptions.
     */
    public static abstract function can_scrap($url): bool;
}