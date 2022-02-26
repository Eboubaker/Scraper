<?php declare(strict_types=1);

namespace Eboubaker\Scrapper;

use Eboubaker\Scrapper\Concerns\ReadsArguments;
use Eboubaker\Scrapper\Concerns\ScrapperUtils;
use Eboubaker\Scrapper\Concerns\StoresCache;
use Eboubaker\Scrapper\Exception\InvalidArgumentException;
use Eboubaker\Scrapper\Exception\RequirementFailedException;
use Eboubaker\Scrapper\Exception\UrlNotSupportedException;
use Eboubaker\Scrapper\Exception\UserException;
use ErrorException;
use Exception;

/**
 * @author Eboubaker Bekkouche <eboubakkar@gmail.com>
 */
final class App
{
    use StoresCache, ReadsArguments;

    private static bool $bootstrapped = false;

    public static function run(array $args): int
    {
        try {
            self::check_platform();
            self::bootstrap($args);
            return self::run_main();
        } catch (InvalidArgumentException $e) {
            error($e->getMessage());
            if ($e->getPrevious())
                error($e->getPrevious()->getMessage());
            tell("run with --help to see usage");
            return $e->getCode();
        } catch (UserException $e) {
            error($e->getMessage());
            return $e->getCode();
        } catch (Exception $e) {
            // display nice error message to console, or maybe bad??
            dump_exception($e);
            return $e->getCode() !== 0 ? $e->getCode() : 100;
        }
    }

    /**
     * @throws InvalidArgumentException
     * @throws ErrorException
     */
    private static function bootstrap(array $args)
    {
        // convert errors to exceptions.
        set_error_handler(function (int    $errno,
                                    string $errstr,
                                    string $errfile,
                                    int    $errline) {
            /** @noinspection PhpUnhandledExceptionInspection */
            throw new ErrorException($errstr, $errno, 1, $errfile, $errline);
        });

        // parse arguments
        App::$arguments = self::parse_arguments($args);
        // show version and exit if requested version option.
        if (App::args()->getOpt('version')) die("v0.0.1" . PHP_EOL);

        // make logs directory if not exists
        if (!file_exists(dirname(logfile()))) mkdir(dirname(logfile()));

        // disable pcre jit because we are dealing with big chunks of text
        ini_set("pcre.jit", '0');// TODO: check if required (test big response)
        ini_set("pcre.backtrack_limit", '20000000');
        ini_set("pcre.recursion_limit", '20000000');
        self::$bootstrapped = true;
    }

    /**
     * @throws UrlNotSupportedException
     * @throws InvalidArgumentException
     * @throws Exception
     */
    private static function run_main(): int
    {
        // escape terminal escape char '\'
        $url = str_replace('\\', '', App::args()->getArg('url', ''));

        if (empty($url)) {
            throw new InvalidArgumentException("url was not provided");
        }

        list($html_document, $final_url) = ScrapperUtils::load_webpage($url);

        info("attempting to determine which extractor to use");
        $scrapper = ScrapperUtils::getRequiredScrapper($final_url, $html_document);
        info("using {}", (new \ReflectionClass($scrapper))->getShortName());

        $scrapper->download_media_from_html_document($final_url, $html_document);
        return 0;
    }

    /**
     * make sure all required extensions are enabled
     * @throws RequirementFailedException
     */
    private static function check_platform()
    {
//        if (!function_exists('opcache_get_status') || !opcache_get_status()) {
//            throw new RequirementFailedException("zend opcache is not enabled, edit file " . php_ini_loaded_file() . " and enable both opcache.enable_cli and opcache.enable");
//        }
//        if (!extension_loaded('parallel')) {
//            throw new RequirementFailedException("required php extension parallel is not installed: https://www.php.net/manual/en/book.parallel.php");
//        }
//        if (!extension_loaded('curl')) {
//            throw new RequirementFailedException("required php extension curl is not enabled: modify php.ini and enable it: " . php_ini_loaded_file());
//        }
    }

    public static function bootstrapped(): bool
    {
        return self::$bootstrapped;
    }
}