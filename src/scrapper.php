<?php

namespace Eboubaker\Scrapper;
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require 'vendor/autoload.php';

use Eboubaker\Scrapper\Contracts\Scrapper;
use Eboubaker\Scrapper\Exceptions\ExpectationFailedException;
use Exception;
use Facebook\WebDriver\Exception\InvalidSessionIdException;
use Facebook\WebDriver\Exception\WebDriverCurlException;
use Facebook\WebDriver\Remote\DesiredCapabilities;
use Facebook\WebDriver\Remote\RemoteWebDriver;
use Facebook\WebDriver\WebDriverDimension;

/**
 * @throws Exception
 */
function main(int $argc, array $argv)
{
    $url = $argv[1] ?? '';
    $driverUrl = "http://localhost:4444";
    $sessionId = '';
    info("establishing connection with driver {}", $driverUrl);
    try {
        $capabilities = DesiredCapabilities::chrome();
        if (empty($sessionId)) {
//            warn("Connecting without a sessionId, creating a new session will take some time");
            $driver = RemoteWebDriver::create($driverUrl, $capabilities, 60000, 60000);
            info("Connected to driver {} with a new session {}", $driverUrl, style($driver->getSessionID(), "green"));
        } else {
            $driver = RemoteWebDriver::createBySessionID($sessionId, $driverUrl, 60000, 60000);
            info("Connected to driver {} with session {}", $driverUrl, style($driver->getSessionID(), "green"));
        }
        $driver->manage()->window()->setSize(new WebDriverDimension(1366, 768));
    } catch (InvalidSessionIdException $e) {
        error("Invalid sessionId {}", $sessionId);
        exit;
    } catch (Exception $e) {
        error("Failed to connect to the driver, maybe the queue size is full?", 1, $e);
        throw $e;
    }
    try {
        info("Navigating to {}", $url);
        $driver->navigate()->to($url);
    } catch (WebDriverCurlException $e) {
        notice("If the webpage is not responding, make sure the webdriver has enough memory");
        $driver->close();
        throw $e;
    }
    $scrapper = null;
    try {
        $currentUrl = $driver->getCurrentURL();
        info("current url is {}", $currentUrl);
        info("attempting to determine which extractor to use");
        $scrapper = Scrapper::getRequiredScrapper($currentUrl, $driver);
        info("using {}", (new \ReflectionClass($scrapper))->getShortName());
        $scrapper->download_media_from_post_url($url);
    } catch (Exception $e) {
        if ($e instanceof ExpectationFailedException)
            error($e->getMessage());
        else
            dump_exception($e);
        if ($scrapper) $scrapper->close();
        try {
            info("an error occurred, attempting to take a screenshot of the webpage...");
            $driver->takeScreenshot("screenshot.png");
            if (filesize("screenshot.png") > 4096) throw new Exception();
            notice("a screenshot of the webpage was saved as ./screenshot.png, do you want to open it now?");
            printf("Open screenshot?(Y/N):");
            $line = rtrim(fgets(STDIN));
            if ($line === 'y' || $line === 'Y') {
                // TODO: avoid system()
                if (host_is_windows_machine()) system("explorer.exe screenshot.png");
                else system("open screenshot.png");
            }
        } catch (Exception $e) {
            error("could not save screenshot");
        }
    }
}


try {
    main($argc, $argv);
} catch (Exception $e) {
    dump_exception($e);
    exit;
}

