<?php declare(strict_types=1);

namespace Eboubaker\Scraper;

use Closure;
use Eboubaker\Scraper\Concerns\ParsesAppArguments;
use Eboubaker\Scraper\Contracts\Scraper;
use Eboubaker\Scraper\Exception\InvalidArgumentException;
use Eboubaker\Scraper\Exception\RequirementFailedException;
use Eboubaker\Scraper\Exception\UrlNotSupportedException;
use Eboubaker\Scraper\Exception\UserException;
use Eboubaker\Scraper\Scrapers\FacebookScraper;
use Eboubaker\Scraper\Scrapers\RedditScraper;
use Eboubaker\Scraper\Scrapers\TiktokScraper;
use Eboubaker\Scraper\Scrapers\YoutubeScraper;
use Eboubaker\Scraper\Tools\Cache\FS;
use Eboubaker\Scraper\Tools\Cache\Memory;
use Eboubaker\Scraper\Tools\Http\Document;
use Exception;
use ReflectionClass;

/**
 * @author Eboubaker Bekkouche <eboubakkar@gmail.com>
 */
final class App
{
    use ParsesAppArguments;

    private static bool $bootstrapped = false;
    /**
     * @var Closure[]
     */
    private static array $before_shutdown_tasks = [];

    private static bool $successfulTermination = false;

    public static function terminating(Closure $task, string $key = null): void
    {
        if (!empty($key)) {
            self::$before_shutdown_tasks[$key] = $task;
        } else {
            self::$before_shutdown_tasks[] = $task;
        }
    }

    public static function terminatingUnregister(Closure ...$tasks): void
    {
        foreach ($tasks as $task) {
            $index = array_search($task, self::$before_shutdown_tasks, true);
            if ($index !== false) {
                unset(self::$before_shutdown_tasks[$index]);
            }
        }
    }

    public static function onSuccessfulTermination(Closure $task): void
    {
        self::$before_shutdown_tasks[] = fn() => self::$successfulTermination && call_user_func($task);
    }

    public static function registerShutDownHandler()
    {
        register_shutdown_function(function () {
            foreach (self::$before_shutdown_tasks as $task) {
                try {
                    $task();
                } catch (Exception $e) {
                    make_monolog("App::registerShutDownHandler")->error($e->getMessage());
                    if (debug_enabled()) {
                        error("App::registerShutDownHandler " . $e->getMessage());
                    }
                }
            }
        });
    }

    /**
     * @throws InvalidArgumentException on invalid cli arguments
     */
    private static function bootstrap(array $args)
    {
        // parse arguments
        App::$arguments = self::parse_arguments($args);
        // show version and exit if requested version option.
        if (App::args()->getOpt('version')) die("v0.1.1" . PHP_EOL);

        // make logs directory if not exists
        if (!file_exists(dirname(logfile()))) mkdir(dirname(logfile()));

        if (App::is_dockerized()) {
            if (!is_dir("/downloads")) {
                notice("The app is running in docker, You need to mount a volume so downloads can be saved: \ndocker run -it -v /your/output/directory:/downloads eboubaker/scraper ...");
                throw new InvalidArgumentException("Please mount a volume for the directory /downloads");
            } else {
                Memory::cache_set('output_dir', "/downloads");
            }
        } else {
            $dir = App::args()->getOpt('output', getcwd());
            if (!is_dir($dir)) {
                throw new InvalidArgumentException("No such directory: $dir");
            } else {
                Memory::cache_set('output_dir', realpath(normalize($dir)));
            }
        }


        // disable pcre jit because we are dealing with big chunks of text
        ini_set("pcre.jit", '0');
        ini_set("pcre.backtrack_limit", '20000000');
        ini_set("pcre.recursion_limit", '20000000');

        if (rand(0, 100) > 90) FS::gc();// clear unused cache
        self::$bootstrapped = true;
    }

    /**
     * is it running inside the docker image?
     */
    public static function is_dockerized(): bool
    {
        return !!getenv("SCRAPER_DOCKERIZED");
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
//        info("attempting to determine which extractor to use");
        /** @var $scraper Scraper */
        $scraper = null;
        /** @var $available_scrapers Scraper[]|string[] */
        $available_scrapers = [
            FacebookScraper::class,
            YoutubeScraper::class,
            RedditScraper::class,
            TiktokScraper::class,
        ];
        foreach ($available_scrapers as $class) {
            if ($class::can_scrap($document)) {
                $scraper = new $class;
                $cname = explode("\\", $class);
                info("using " . end($cname));
                break;
            }
        }
        if (!$scraper) {
            // TODO: add pr request link for new scraper
            warn("{} is probably not supported", $document->getFinalUrl());
            // TODO: add how to do login when it is implemented
            notice("if the post url is private you might need to login first");
            throw new UrlNotSupportedException("Could not determine which extractor to use");
        } else {
            $files = $scraper->scrap($document);
//            if (stream_isatty(STDOUT)) {
//                $linesCount = Memory::cache_get('stdout_written_lines_count');
//                // clear all previous written lines
//                // TODO: maybe we should keep the lines as log for the user
//                fwrite(STDOUT, "\33[{$linesCount}A\33[J");// https://www.vt100.net/docs/vt100-ug/chapter3.html#S3.3.6
//            }
            $log = make_monolog("App::run_main");
            foreach ($files as $file) {
                $log->info("full path: $file");
                $file = App::is_dockerized() ? basename($file) : $file;
                info("SAVED: $file");
            }
            // linux wont add newline by default.
            if (!host_is_windows_machine() && !App::is_dockerized()) echo PHP_EOL;
        }
        return 0;
    }

    public static function run(array $args): int
    {
        try {
            self::check_platform();
            self::bootstrap($args);
            self::registerShutDownHandler();
            $exit_code = self::run_main();
            self::$successfulTermination = true;
            return $exit_code;
        } catch (InvalidArgumentException $e) {// this is not \InvalidArgumentException, this extends Exceptions\UserException
            echo TTY_FLUSH;
            error($e->getMessage());
            if ($e->getPrevious())
                error("Cause: [{}]: {}", (new ReflectionClass($e))->getShortName(), $e->getPrevious()->getMessage());
            tell("run with --help to see usage");
            return $e->getCode();
        } catch (UserException $e) {
            echo TTY_FLUSH;
            if ($e->getPrevious()) {
                $className = function ($object): ?string {
                    try {
                        /** @noinspection PhpUnhandledExceptionInspection */
                        return (new ReflectionClass($object))->getShortName();
                    } catch (\Throwable $e) {
                        return null;
                    }
                };
                $str_cause = '';
                $current = $e->getPrevious();
                while ($current) {
                    $str_cause .= "\n" . style("      * Caused by", 'red,bold') . ": " . $className($current) . ": " . $current->getMessage();
                    $current = $current->getPrevious();
                }
                error($className($e) . ": " . $e->getMessage() . $str_cause);
            } else {
                error($e->getMessage());
            }
            return $e->getCode();
        } catch (\Exception $e) {
            echo TTY_FLUSH;
            // display nice error message to console, or maybe bad??
            if (debug_enabled()) dump_exception($e);
            notice("Report issues to https://github.com/Eboubaker/Scraper/issues");
            error($e->getMessage());
            return $e->getCode() !== 0 ? $e->getCode() : 100;
        }
    }
}
