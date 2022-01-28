<?php

namespace Eboubaker\Scrapper;
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require 'vendor/autoload.php';

use Eboubaker\Scrapper\Contracts\Scrapper;
use Exception;
use Facebook\WebDriver\Remote\DesiredCapabilities;
use Facebook\WebDriver\Remote\RemoteWebDriver;

/**
 * @throws Exception
 */
function main(int $argc, array $argv)
{
    $url = $argv[1];
    $driverUrl = "https://eboubaker.xyz:8800";
    $sessionId = '';
    info("establishing connection with driver {}", $driverUrl);
    try {
        $capabilities = DesiredCapabilities::chrome();
        if (empty($sessionId)) {
            warn("Connecting without a sessionId, creating a new session will take some time");
            $driver = RemoteWebDriver::create($driverUrl, $capabilities, 60000, 60000);
            info("Connected to driver {} with a new session {}", $driverUrl, style($driver->getSessionID(), "green"));
        } else {
            $driver = RemoteWebDriver::createBySessionID($sessionId, $driverUrl, 60000, 60000);
            info("Connected to driver {} with session {}", $driverUrl, style($driver->getSessionID(), "green"));
        }
    } catch (Exception $e) {
        error("Failed to connect to the driver, maybe the queue size is full?", 1, $e);
        throw $e;
    }
    try {
        info("Navigating to {}", $url);
        $driver->navigate()->to($url);
    } catch (Exception $e) {
        notice("If the webpage is not responding, make sure the webdriver has enough memory");
        $driver->close();
        throw $e;
    }
    try {
        $currentUrl = $driver->getCurrentURL();
        info("current url is {}", $currentUrl);
        info("attempting to determine which extractor to use");
        $scrapper = Scrapper::getRequiredScrapper($currentUrl, $driver);
        info("using {}", get_class($scrapper));
        $scrapper->download_media_from_post_url($url);
    } catch (Exception $e) {
        try {
            $driver->takeScreenshot("screenshot.png");
            notice("a screenshot of the webpage was saved as ./screenshot.png, do you want to open it now?");
            printf("Open screenshot?(Y/N):");
            $line = rtrim(fgets(STDIN));
            if($line === 'y' || $line === 'Y'){
                // TODO: avoid system()
                system("open screenshot.png");
            }
        } finally {
            throw $e;
        }
    }
    $scrapper->close();
}


try {
    main($argc, $argv);
} catch (Exception $e) {
    dd($e);
}