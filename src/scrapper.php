<?php

namespace Eboubaker\Scrapper;

require 'vendor/autoload.php';

use Eboubaker\Scrapper\Reddit\RedditScrapper;
use Facebook\WebDriver\Chrome\ChromeOptions;
use Facebook\WebDriver\Exception\NoSuchElementException;
use Facebook\WebDriver\Exception\TimeoutException;
use Facebook\WebDriver\Remote\DesiredCapabilities;
use Facebook\WebDriver\Remote\RemoteWebDriver;
use Facebook\WebDriver\WebDriverBy;
use RuntimeException;
use Throwable;

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);


function main($argc, $argv)
{
    $driverUrl = "https://eboubaker.xyz:8800";
    echo "establishing connection with driver $driverUrl\n";
    try {
        $capabilities = DesiredCapabilities::firefox();
//        $capabilities->setCapability("disable-notifications", true);
        $driver = RemoteWebDriver::create($driverUrl, $capabilities, 60000, 60000);
    }catch (Throwable $e){
        throw new RuntimeException("Failed to connect to the driver, maybe the queue size is full?", 1, $e);
    }
    $scrapper = new RedditScrapper($driver);
    echo "connected to driver $driverUrl\n";
    $url = $argv[1];
    $scrapper->download_media_from_post_url($url);
    $scrapper->close();
}

main($argc, $argv);