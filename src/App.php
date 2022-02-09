<?php declare(strict_types=1);

namespace Eboubaker\Scrapper;

use Eboubaker\Scrapper\Contracts\Scrapper;
use Eboubaker\Scrapper\Exception\InvalidArgumentException;
use Eboubaker\Scrapper\Exception\UrlNotSupportedException;
use Eboubaker\Scrapper\Exception\UserException;
use ErrorException;
use Exception;
use Tightenco\Collect\Support\Arr;

final class App
{
    private static array $arguments = [];

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

        // disable pcre jit because we are dealing with big chunks of text
        ini_set("pcre.jit", '0');
    }

    /**
     * @throws UrlNotSupportedException
     * @throws InvalidArgumentException
     * @throws Exception
     */
    private static function run_main(): int
    {
        // first argument
        $url = str_replace('\\', '', App::get(0, ''));
        if (empty($url)) {
            throw new InvalidArgumentException("url was not provided, url must be the first argument");
        }
        list($html_document, $final_url) = Scrapper::load_webpage($url);

        info("attempting to determine which extractor to use");
        $scrapper = Scrapper::getRequiredScrapper($final_url);
        info("using {}", (new \ReflectionClass($scrapper))->getShortName());

        $scrapper->download_media_from_html_document($html_document);
        return 0;
    }

    /**
     * get the option value in the arguments
     * @param string|int $option the option name/key
     * @return mixed
     */
    public static function get($option, $default = null)
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
        $argv = $argv ?: $_SERVER['argv'];
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