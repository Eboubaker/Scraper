<?php declare(strict_types=1);

namespace Eboubaker\Scrapper;

use Eboubaker\Scrapper\Concerns\ParsesAppArguments;
use Eboubaker\Scrapper\Concerns\StoresCache;
use Eboubaker\Scrapper\Contracts\Scrapper;
use Eboubaker\Scrapper\Exception\InvalidArgumentException;
use Eboubaker\Scrapper\Exception\RequirementFailedException;
use Eboubaker\Scrapper\Exception\UrlNotSupportedException;
use Eboubaker\Scrapper\Exception\UserException;
use Eboubaker\Scrapper\Scrappers\FacebookScrapper;
use Eboubaker\Scrapper\Scrappers\RedditScrapper;
use Eboubaker\Scrapper\Scrappers\YoutubeScrapper;
use Eboubaker\Scrapper\Tools\Http\Document;
use Exception;

/**
 * @author Eboubaker Bekkouche <eboubakkar@gmail.com>
 */
final class App
{
    use StoresCache, ParsesAppArguments;

    private static bool $bootstrapped = false;

    public static function run(array $args): int
    {
        try {
            self::check_platform();
            self::bootstrap($args);
            return self::run_main();
        } catch (InvalidArgumentException $e) {
            echo TTY_FLUSH;
            error($e->getMessage());
            if ($e->getPrevious())
                error($e->getPrevious()->getMessage());
            tell("run with --help to see usage");
            return $e->getCode();
        } catch (UserException $e) {
            echo TTY_FLUSH;
            if ($e->getPrevious()) {
                $str_cause = '';
                $current = $e->getPrevious();
                while ($current) {
                    $str_cause .= "\n" . style("      * Caused by", 'red,bold') . ": " . className($current) . ": " . $current->getMessage();
                    $current = $current->getPrevious();
                }
                error(className($e) . ": " . $e->getMessage() . $str_cause);
            } else {
                error($e->getMessage());
            }
            return $e->getCode();
        } catch (Exception $e) {
            echo TTY_FLUSH;
            // display nice error message to console, or maybe bad??
            if (debug_enabled()) dump_exception($e);
            error($e->getMessage());
            return $e->getCode() !== 0 ? $e->getCode() : 100;
        }
    }

    /**
     * @throws InvalidArgumentException on invalid cli arguments
     */
    private static function bootstrap(array $args)
    {
        // parse arguments
        App::$arguments = self::parse_arguments($args);
        // show version and exit if requested version option.
        if (App::args()->getOpt('version')) die("v0.1.0" . PHP_EOL);

        // make logs directory if not exists
        if (!file_exists(dirname(logfile()))) mkdir(dirname(logfile()));

        if (getenv("SCRAPPER_DOCKERIZED")) {
            if (!is_dir(rootpath('downloads'))) {
                notice("The app is running in docker, You need to mount a volume so downloads can be saved: \ndocker run -it -v /your/output/directory:/app/downloads eboubaker/scrapper ...");
                throw new InvalidArgumentException("Please mount a volume for the directory /app/downloads");
            } else {
                App::cache_set('output_dir', rootpath('downloads'));
            }
        } else {
            $dir = App::args()->getOpt('output', getcwd());
            if (!is_dir($dir)) {
                throw new InvalidArgumentException("No such directory: $dir");
            } else {
                App::cache_set('output_dir', realpath(normalize($dir)));
            }
        }


        // disable pcre jit because we are dealing with big chunks of text
        ini_set("pcre.jit", '0');
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
        $url = trim($url);

        if (empty($url)) {
            throw new InvalidArgumentException("url was not provided");
        }
        info("Downloading webpage: {}", $url);
        $document = Document::fromUrl($url);
        if ($url !== $document->getFinalUrl())
            notice("Final url was changed: {}", $document->getFinalUrl() ?? style("NULL", 'red'));
        info("attempting to determine which extractor to use");
        /** @var $scrapper Scrapper */
        $scrapper = null;
        /** @var $availableScrappers array<int, Scrapper> */
        $availableScrappers = [
            FacebookScrapper::class,
            YoutubeScrapper::class,
            RedditScrapper::class
        ];
        foreach ($availableScrappers as $klass) {
            if ($klass::can_scrap($document)) {
                $scrapper = new $klass;
                $cname = explode("\\", $klass);
                info("using " . end($cname));
                break;
            }
        }
        if (!$scrapper) {
            // TODO: add pr request link for new scrapper
            warn("{} is probably not supported", $document->getFinalUrl());
            // TODO: add how to do login when it is implemented
            notice("if the post url is private you might need to login first");
            throw new UrlNotSupportedException("Could not determine which extractor to use");
        } else {
            $out = $scrapper->scrap($document);
            if (stream_isatty(STDOUT)) {
                fwrite(STDOUT, "\33[" . App::cache_get('stdout_wrote_lines') . "A\33[J");
            }
            if (getenv("SCRAPPER_DOCKERIZED")) {
                tell("Saved as : " . basename($out));
            } else {
                tell("Saved as : $out");
            }
            if (!host_is_windows_machine()) echo PHP_EOL;
        }
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

    /**
     * @return bool was bootstrap() called?
     */
    public static function bootstrapped(): bool
    {
        return !!self::$bootstrapped;
    }
}
