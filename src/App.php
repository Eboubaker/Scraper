<?php

namespace Eboubaker\Scrapper;

use Eboubaker\Scrapper\Contracts\Scrapper;
use Eboubaker\Scrapper\Exception\DriverConnectionException;
use Eboubaker\Scrapper\Exception\UrlNavigationException;
use Eboubaker\Scrapper\Exception\UrlNotSupportedException;
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
            dump(self::$arguments);
            return self::run_main();
        } catch (Exception $e) {
            dump_exception($e);
            exit($e->getCode() !== 0 ? $e->getCode() : 100);
        }
    }

    private static function bootstrap(array $args)
    {
        // convert warning to exception.
        set_error_handler(function (int    $errno,
                                    string $errstr,
                                    string $errfile,
                                    int    $errline) {
            /** @noinspection PhpUnhandledExceptionInspection */
            throw new ErrorException($errstr, $errno, 1, $errfile, $errline);
        });
        self::$arguments = self::parse_arguments($args);
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

    /**
     * @throws DriverConnectionException
     * @throws UrlNavigationException
     * @throws UrlNotSupportedException
     */
    private static function run_main(): int
    {
        $driver = self::connect_to_driver();
        // first argument or argument after -- (end of options)
        $url = self::get("0", fn() => self::get(""));
        try {
            info("Navigating to {}", $url);
            $driver->navigate()->to($url);
        } catch (Exception $e) {
            notice("If the webpage is not responding, make sure the webdriver has enough memory");
            $driver->close();
            throw new UrlNavigationException(format("Failed to navigate to the provided url: {}", $url ?? "NULL"));
        }
        info("attempting to determine which extractor to use");
        try {
            $currentUrl = $driver->getCurrentURL();
            info("current url is {}", $currentUrl);
        } catch (Exception $e) {
            throw new DriverConnectionException("Could not get current URL", 0, $e);
        }
        $scrapper = Scrapper::getRequiredScrapper($currentUrl, $driver);
        info("using {}", (new \ReflectionClass($scrapper))->getShortName());
        try {
            $scrapper->download_media_from_post_url($url);
        } catch (Exception $e) {
            dump_exception($e);
            $scrapper->close();
            try {
                info("an error occurred, attempting to take a screenshot of the webpage...");
                $driver->takeScreenshot(getcwd() . DIRECTORY_SEPARATOR . "screenshot.png");
                if (filesize("screenshot.png") > 4096) throw new Exception();
                notice("a screenshot of the webpage was saved as ./screenshot.png, do you want to open it now?");
                printf("Open screenshot?(Y/N):");
                $line = rtrim(fgets(STDIN));
                if ($line === 'y' || $line === 'Y') {
                    // TODO: avoid system()
                    if (host_is_windows_machine()) system("explorer.exe \"" . getcwd() . DIRECTORY_SEPARATOR . "screenshot.png\"");
                    else system("open \"" . getcwd() . DIRECTORY_SEPARATOR . "screenshot.png\"");
                }
            } catch (Exception $e) {
                error("could not save screenshot");
            }
        }
    }

    /**
     * @throws DriverConnectionException
     */
    private static function connect_to_driver(): RemoteWebDriver
    {
        $sessionId = self::get("driver-session");
        $driverUrl = self::get("driver-url", "http://localhost:4444");

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
            throw new DriverConnectionException("Failed to connect to the driver", 0, $e);
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

}