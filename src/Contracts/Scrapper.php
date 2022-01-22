<?php

namespace Eboubaker\Scrapper\Contracts;

use Facebook\WebDriver\Remote\RemoteWebDriver;

abstract class Scrapper
{
    protected RemoteWebDriver $driver;

    public function __construct(RemoteWebDriver $driver)
    {
        $this->driver = $driver;
    }

    /**
     * guess the filename from the media url
     * @ForOverride
     */
    function probe_file_name(string $url): string{
        return explode('/', explode('?', $url)[0])[1];
    }

    /**
     * save the url as file
     * @return string the saved file's relative path
     */
    function saveUrl(string $url, $filename = null): string{
        if($filename == null){
            $filename = $this->probe_file_name($url);
        }
        if(!is_dir("downloads")){
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
     * download the post
     */
    abstract function download_media_from_post_url($post_url);

    /**
     * close any resources used by the scrapper such as the Webdriver connection
     */
    public function close($exceptionRaised = false){
        if($exceptionRaised){
            echo "An Exception occurred, Closing driver connection\n";
        }else {
            echo "Closing driver connection\n";
        }
        $this->driver->quit();
    }
}