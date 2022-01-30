<?php

namespace Eboubaker\Scrapper;

use Eboubaker\Scrapper\Contracts\Scrapper;
use Eboubaker\Scrapper\Exception\DriverConnectionException;
use Eboubaker\Scrapper\Exception\InvalidArgumentException;
use Eboubaker\Scrapper\Exception\UrlNavigationException;
use Eboubaker\Scrapper\Exception\UrlNotSupportedException;
use Eboubaker\Scrapper\Exception\UserException;
use ErrorException;
use Exception;
use Facebook\WebDriver\Exception\InvalidSessionIdException;
use Facebook\WebDriver\Remote\DesiredCapabilities;
use Facebook\WebDriver\Remote\RemoteWebDriver;
use Facebook\WebDriver\WebDriverDimension;
use Tightenco\Collect\Support\Arr;

final class App
{
    private static array $arguments;

    public static function run(array $args): int
    {
        try {
            self::bootstrap($args);
            return self::run_main();
        } catch (InvalidArgumentException $e) {
            usage_error($e->getMessage());
            return $e->getCode();
        } catch (UserException $e) {
            error($e->getMessage());
            if (debug_enabled())
                dump_exception($e);
            return $e->getCode();
        } catch (Exception $e) {
            // display nice error message to console, or maybe bad??
            dump_exception($e);
            return $e->getCode() !== 0 ? $e->getCode() : 100;
        }
    }

    private static function bootstrap(array $args)
    {
        // convert warnings to exceptions.
        set_error_handler(function (int    $errno,
                                    string $errstr,
                                    string $errfile,
                                    int    $errline) {
            /** @noinspection PhpUnhandledExceptionInspection */
            throw new ErrorException($errstr, $errno, 1, $errfile, $errline);
        });
        // parse arguments
        self::$arguments = self::parse_arguments($args);
    }

    /**
     * @throws DriverConnectionException
     * @throws UrlNavigationException
     * @throws UrlNotSupportedException
     * @throws InvalidArgumentException
     */
    private static function run_main(): int
    {
        // first argument or argument after -- (end of options)
        $url = str_replace('\\', '', App::get(0, fn() => App::get("")));
        if (empty($url)) {
            throw new InvalidArgumentException("url was not provided");
        }
        $driver = self::connect_to_driver();

        try {
            info("Navigating to  {}", $url);
            $driver->navigate()->to($url);
        } catch (Exception $e) {
            notice("If the webpage is not responding, make sure the webdriver has enough memory");
            $driver->close();
            throw new UrlNavigationException(format("Failed to navigate to the provided url: {}", $url ?? "NULL"));
        }
        try {
            $currentUrl = $driver->getCurrentURL();
            info("current url is {}", $currentUrl);
        } catch (Exception $e) {
            throw new DriverConnectionException("Could not get current URL", $e);
        }
        info("attempting to determine which extractor to use");
        $scrapper = Scrapper::getRequiredScrapper($currentUrl, $driver);
        info("using {}", (new \ReflectionClass($scrapper))->getShortName());
        try {
            info("Media file was downloaded as {}", realpath($scrapper->download_media_from_post_url($url)));
        } catch (Exception $e) {
            if (!$scrapper->close()) {// if driver was still open(not done using webdriver)
                try {
                    notice("an error occurred, attempting to take a screenshot of the webpage...");
                    $picpath = consolepath("screenshot.png");
                    $driver->takeScreenshot($picpath);
                    if (filesize($picpath) > 4096) throw new Exception();
                    notice("a screenshot of the webpage was saved as ./screenshot.png, check it out");
                } catch (Exception $e) {
                    error("could not save screenshot");
                }
            }
            throw $e;
        }
        return 0;
    }

    /**
     * @throws DriverConnectionException
     */
    private static function connect_to_driver(): RemoteWebDriver
    {
        $sessionId = App::get("driver-session");
        $driverUrl = App::get("driver-url", "http://localhost:4444");

        info("establishing connection with driver {}", $driverUrl ?? "NULL");
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
            return $driver;
        } catch (InvalidSessionIdException $e) {
            throw new DriverConnectionException("Invalid sessionId {}", $sessionId);
        } catch (Exception $e) {
            notice("Make sure the driver is running and the queue is not full or specify a different driver");
            throw new DriverConnectionException(format("Failed to connect to the driver {}", $driverUrl), $e);
        }
    }

    /**
     * get the option value in the arguments
     * @param string $option the option name
     * @return mixed
     */
    public static function get(string $option, $default = null)
    {
        return Arr::get(self::$arguments, $option, $default);
    }

    /**
     * parseArgs Command Line Interface (CLI) utility function.
     * @author              Patrick Fisher <patrick@pwfisher.com>
     * @see                 https://github.com/pwfisher/CommandLine.php
     */
    public static function parse_arguments($argv = null): array
    {
        $argv = $argv ? $argv : $_SERVER['argv'];
        array_shift($argv);
        $o = array();
        for ($i = 0, $j = count($argv); $i < $j; $i++) {
            $a = $argv[$i];
            if (substr($a, 0, 2) == '--') {
                $eq = strpos($a, '=');
                if ($eq !== false) {
                    $o[substr($a, 2, $eq - 2)] = substr($a, $eq + 1);
                } else {
                    $k = substr($a, 2);
                    if ($i + 1 < $j && $argv[$i + 1][0] !== '-') {
                        $o[$k] = $argv[$i + 1];
                        $i++;
                    } else if (!isset($o[$k])) {
                        $o[$k] = true;
                    }
                }
            } else if (substr($a, 0, 1) == '-') {
                if (substr($a, 2, 1) == '=') {
                    $o[substr($a, 1, 1)] = substr($a, 3);
                } else {
                    foreach (str_split(substr($a, 1)) as $k) {
                        if (!isset($o[$k])) {
                            $o[$k] = true;
                        }
                    }
                    if ($i + 1 < $j && $argv[$i + 1][0] !== '-') {
                        $o[$k] = $argv[$i + 1];
                        $i++;
                    }
                }
            } else {
                $o[] = $a;
            }
        }
        return $o;
    }

}