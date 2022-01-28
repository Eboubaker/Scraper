<?php

namespace Eboubaker\Scrapper\Contracts;

use Eboubaker\Scrapper\Facebook\FacebookScrapper;
use Eboubaker\Scrapper\Reddit\RedditScrapper;
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
     * download the media in post
     */
    abstract function download_media_from_post_url($post_url);

    /**
     * close any resources used by the scrapper such as the Webdriver connection
     */
    public function close()
    {
        if (!$this->wasClosed) {
            info("sending close signal");
            try {
                $this->driver->close();
                $this->wasClosed = true;
            } catch (Exception $e) {
                error("Failed to close window");
            }
        } else {
            debug("driver was already closed");
        }
    }

    /**
     * @throws Exception if failed to determine required scrapper
     */
    public
    static function getRequiredScrapper(string $url, RemoteWebDriver $driver): Scrapper
    {
        if (preg_match("/https?:\/\/(m|www)\.reddit\.com\/r\/.*\/comments\/.*/", $url))
            return new RedditScrapper($driver);
        else if (preg_match("/https?:\/\/(web|m|www)\.facebook\.com\//", $url))
            return new FacebookScrapper($driver);
        // TODO: add pr request link for new scrapper
        warn("{} is probably not supported", $url);
        // TODO: add how to do login when it is implemented
        notice("if the post url is private you might need to login first");
        throw new Exception("Could not determine which extractor to use");
    }
}