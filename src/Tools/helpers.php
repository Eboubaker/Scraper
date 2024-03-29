<?php /** @noinspection PhpUnnecessaryFullyQualifiedNameInspection,PhpFullyQualifiedNameUsageInspection */

use Eboubaker\Scraper\App;
use Eboubaker\Scraper\Tools\Cache\Memory;

const TTY_UP = "\33[A";// https://www.vt100.net/docs/vt100-ug/chapter3.html#CUU
const TTY_FLUSH = "\33[2K\r";

/**
 * Local Write Line, register the wrote line as stdout_written_lines_count which will be used later to
 * know how many lines to clear when the app is done
 */
function lwritel(string $line)
{
    fwrite(STDOUT, $line . PHP_EOL);
    App::bootstrapped() && Memory::increment('stdout_written_lines_count');
}

/**
 * @author Eboubakkar Bekkouche <eboubakkar@gmail.com>
 */
function format(string $text, ...$args): string
{
    return sprintf(str_replace("{}", "%s", str_replace("%", "%%", $text)), ...$args);
}

function info(string $msg, ...$args)
{
    lwritel(style("[INFO] ", "cyan,bold") . format($msg, ...$args));
}

function tell(string $msg, ...$args)
{
    lwritel(format($msg, ...$args));
}

function notice(string $msg, ...$args)
{
    lwritel(style("[NOTICE] ", "red,yellow,bold") . format($msg, ...$args));
}

function error(string $msg, ...$args)
{
    lwritel(style("[ERROR] ", "red,bold") . format($msg, ...$args));
}

function warn(string $msg, ...$args)
{
    lwritel(style("[WARN] ", "yellow,bold") . format($msg, ...$args));
}

function debug(string $msg, ...$args)
{
    if (!debug_enabled()) return;
    lwritel(style("[DEBUG] ", "blue,bold") . format($msg, ...$args));
}


/**
 * if a php Error/Warning happens while running the action then print it to the console.
 * restores the previous error_handler afterwards.
 * @param Closure<bool> $closure
 * @return bool
 */
function wrap_warnings(\Closure $closure): bool
{
    set_error_handler(
        function (int $errno, string $errstr) {
            error("$errstr");
        }
    );
    $success = $closure();
    restore_error_handler();
    return $success;
}

function style(string $text, ...$styles): string
{
    if (!stream_isatty(STDOUT)) {
        // TODO: the parallel runtime doesn't know about this, the tracker thread will show the warning again.
        \Eboubaker\Scraper\Tools\Cache\FS::do_once('tty_colors_warned', function () {
            fputs(STDOUT, "[WARN] STDOUT is not a TTY device, colors and styles are not supported.\n");
        });
        return $text;
    }
    $codes = [
        'bold' => 1,
        'italic' => 3, 'underline' => 4, 'strikethrough' => 9,
        'black' => 30, 'red' => 31, 'green' => 32, 'yellow' => 33, 'blue' => 34, 'magenta' => 35, 'cyan' => 36, 'white' => 37,
        'blackbg' => 40, 'redbg' => 41, 'greenbg' => 42, 'yellowbg' => 44, 'bluebg' => 44, 'magentabg' => 45, 'cyanbg' => 46, 'lightgreybg' => 47
    ];

    $styles = collect($styles)
        ->flatten()
        ->filter(fn($v) => is_string($v))
        ->map(fn($s) => explode(',', $s))
        ->flatten()
        ->map(fn($c) => trim($c))
        ->filter(fn($c) => !!strlen($c))
        ->filter(fn($c) => !in_array($c, $codes, true));
    if ($styles->count() === 0) return $text;
    $formatMap = $styles->map(fn($v) => $codes[$v])->all();
    return "\e[" . implode(';', $formatMap) . 'm' . $text . "\e[0m";
}

function host_is_windows_machine(): bool
{
    return DIRECTORY_SEPARATOR === '\\';
}


/**
 * Show the exception in the console
 * @param Exception $e
 * @author Eboubakkar Bekkouche <eboubakkar@gmail.com>
 */
function dump_exception(Exception $e)
{
    dump((object)["error" => $e->getMessage(), "exception" => $e, "cause" => $e->getPrevious()]);
}

/**
 * convert a number with size unit to bytes count.
 * ex:
 *     12kb returns (12 * 1024).
 *     8M   returns (8 * 1024 * 1024)
 *     4096 returns 4096 (no modifier applied)
 *
 * Available modifiers: m k g.
 * any other non number character will be stripped,
 * returns 0 if string is empty
 *
 * @author Eboubaker Bekkouche <eboubakkar@gmail.com>
 */
function bytes(string $format)
{
    $units['k'] = 1024;
    $units['m'] = $units['k'] * 1024;
    $units['g'] = $units['m'] * 1024;
    $n = intval(preg_replace('/\D/', '', $format));
    preg_match('/\D/', $format, $s);
    if (count($s) < 1) return $n;
    if (stristr($s[0], 'k')) {
        return $units['k'] * $n;
    } else if (stristr($s[0], 'm')) {
        return $units['m'] * $n;
    } else if (stristr($s[0], 'g')) {
        return $units['g'] * $n;
    } else {
        return $n;
    }
}

const H_SIZE_UNITS = array("B", "KB", "MB", "GB", "TB", "PB", "EB", "ZB", "YB"); //units of measurement
function human_readable_size($bytes, $decimals = 2, $max = 0): string
{
    if ($bytes >= 1) {
        $base = floor(log($bytes) / log(1024));
        if ($max && $base > $max) $base = $max;
        $n = $bytes / pow(1024, $base);
        return rtrim(rtrim(number_format($n, $decimals === -1 ? (intval($n) === $n ? 0 : $decimals) : $decimals) . " " . H_SIZE_UNITS[$base], '0'), '.');
    } else return "0 B";
}

/**
 * return value if condition is true otherwise empty string (by default)
 * @param callable|bool $condition
 * @author Eboubakkar Bekkouche <eboubakkar@gmail.com>
 */
function putif($condition, $value, $else = '')
{
    if (is_callable($condition))
        return $condition() ? $value : $else;
    return $condition ? $value : $else;
}

/**
 * @author Eboubakkar Bekkouche <eboubakkar@gmail.com>
 */
function debug_enabled(): bool
{
    // TODO: optimize
    if (!App::bootstrapped()) return false;
    return boolval(App::args()->getOpt('verbose'));
}

/**
 * @author Eboubakkar Bekkouche <eboubakkar@gmail.com>
 */
function running_as_phar(): bool
{
    return !empty(Phar::running(false));
}


/**
 * get path to the project root,
 * if running as phar then return the directory of the phar file.
 * if running on docker return the mounted volume.
 * @throws Error
 * @author Eboubakkar Bekkouche <eboubakkar@gmail.com>
 */
function rootpath(string $append = '', bool $check_docker = true): string
{
    $dir = Memory::remember('internals.root-path.' . $check_docker, function () use ($check_docker) {
        if ($check_docker && App::is_dockerized()) {
            $p = realpath(Memory::cache_get('output_dir'));
            if (!$p) throw new Error("running on docker and could not resolve root path, maybe did not mount /downloads volume?");
            $p = normalize($p . '/docker-scraper-data');
            if (!file_exists($p)) {
                try {
                    if (!mkdir($p, 0600)) {
                        throw new RuntimeException("mkdir($p, 0600) did not succeed");
                    }
                    if (!file_exists($p . DIRECTORY_SEPARATOR . "README.txt")) {
                        file_put_contents($p . DIRECTORY_SEPARATOR . "README.txt", "This directory contains the scrapper data, such as logs/caches when running the docker image eboubaker/scraper" . PHP_EOL);
                    }
                } catch (\Throwable $original) {
                    throw new Error("running on docker and could not create data path, maybe did not mount /downloads volume?", $original->getCode(), $original);
                }
            }
            return $p;
        }
        $dir = dirname(Phar::running(false));
        if (!$dir) {
            $dir = realpath(__DIR__);
            $tries = 5;
            // the root is where vendor sits
            while (!file_exists(normalize($dir . '/vendor')) && --$tries != 0)// TODO: not a good idea, they may have /vendor for other purposes
                $dir = dirname($dir);
            if ($tries == 0) throw new Error("root path not resolved /vendor directory not found");
        }
        return $dir;
    });

    return normalize($dir . putif($append !== '', DIRECTORY_SEPARATOR . $append));
}


/**
 * make path cross platform
 * @author Eboubaker Bekkouche <eboubakkar@gmail.com>
 */
function normalize(string $path): string
{
    $path = str_replace("\\\\", "\\", $path);
    $path = str_replace("//", "/", $path);
    return str_replace(["\\", "/"], DIRECTORY_SEPARATOR, $path);
}

/**
 * @author Eboubakkar Bekkouche <eboubakkar@gmail.com>
 */
function logfile(?string $name = 'scraper.log', bool $create_paths = false): string
{
    if ($name == null) $name = 'scraper.log';
    $p = rootpath('logs/' . $name);
    $parts = explode(DIRECTORY_SEPARATOR, $p);
    if ($create_paths && count($parts) > 1) {
        $parts = implode(DIRECTORY_SEPARATOR, array_slice($parts, 0, -1));
        if (!file_exists($parts)) {
            // TODO: is this right? (600) will it cause permission issues?
            mkdir($parts, 0600, true);
        }
    }
    return $p;
}

/**
 * recursively find in a multi-dimensional array an item which contains all the given keys.
 * @author Eboubakkar Bekkouche <eboubakkar@gmail.com>
 */
function array_search_match(array $array, $keys): ?string
{
    return __array_search_match($array, $keys, '');
}

function __array_search_match(array $array, $keys, $path): ?string
{
    $keys = (array)$keys;// allow string or array
    // array must have the given key and also all adjacent keys
    $found_count = 0;
    foreach ($keys as $key => $xvar) {
        if (is_string($key)) {// $xvar is real regex
            $v = data_get($array, $key, fn() => false);
            if (is_callable($v))
                break; // not found(default value)
            else if (empty($key)) {// regex searching without nesting
                foreach ($array as $k => $i) {
                    if (preg_match($xvar, $i)) {
                        return strval($k);
                    }
                }
                break;
            } else if (!is_numeric($v) && !is_string($v))
                break;// we can't run regex on array
            else if (!preg_match($xvar, $v))
                break;// regex didnt match

        } else {// $xvar is not a regex
            if (!\Tightenco\Collect\Support\Arr::has($array, $xvar))
                break;// key not found
        }
        ++$found_count;
    }
    if ($found_count === count($keys)) {
        return ltrim($path, '.');
    }
    foreach ($array as $key => $item) {
        if (is_array($item)) {
            $found = __array_search_match($item, $keys, $path . '.' . $key);
            if ($found) return $found;
        }
    }
    return null;
}

/**
 * @author Eboubakkar Bekkouche <eboubakkar@gmail.com>
 */
function array_preg_find_key_paths(array $haystack, string $pattern, &$accumulator, array $current_path = []): bool
{
    $found_something = false;
    foreach ($haystack as $key => $item) {
        if ((is_string($key) || is_numeric($key)) && preg_match($pattern, strval($key))) {
            $accumulator[] = [...$current_path, $key];
            $found_something = true;
        }
    }
    foreach ($haystack as $key => $item) {
        if (is_array($item)) {
            $found_something = array_preg_find_key_paths($item, $pattern, $accumulator, [...$current_path, $key]) || $found_something;
        }
    }
    return $found_something;
}

/**
 * note: might return empty string if all characters are filtered
 */
function filter_filename($name): string
{
    // remove illegal file system characters https://en.wikipedia.org/wiki/Filename#Reserved_characters_and_words
    $name = str_replace(array_merge(
        array_map('chr', range(0, 31)),
        array('<', '>', ':', '"', '/', '\\', '|', '?', '*')
    ), '', $name);
    // maximise filename length to 255 bytes http://serverfault.com/a/9548/44086
    $ext = pathinfo($name, PATHINFO_EXTENSION);
    return mb_strcut(
            pathinfo($name, PATHINFO_FILENAME),
            0,
            255 - ($ext ? strlen($ext) + 1 : 0),
            mb_detect_encoding($name)
        ) . ($ext ? '.' . $ext : '');
}

function make_ffmpeg(array $config = []): ?\FFMpeg\FFMpeg
{
    return Memory::remember('internals.ffmpeg-instance', function () use ($config) {
        try {
            if (!host_is_windows_machine()) {
                $ffmpeg = \FFMpeg\FFMpeg::create($config);
            } else {
                try {
                    $ffmpeg = \FFMpeg\FFMpeg::create($config);
                } catch (Throwable $e) {
                    // try get from release
                    $ffmpeg = \FFMpeg\FFMpeg::create([
                            "ffmpeg.binaries" => rootpath("bin/ffmpeg/ffmpeg.exe"),
                            "ffprobe.binaries" => rootpath("bin/ffmpeg/ffprobe.exe"),
                        ] + $config);
                }
            }
            return $ffmpeg;
        } catch (\Throwable $e) {
            warn("could not get FFMpeg instance, make sure it is installed and available in \$PATH: https://www.ffmpeg.org/download.html");
            make_monolog(__FILE__ . '@' . __FUNCTION__)->error("Exception:" . (new ReflectionClass($e))->getName() . ":" . $e->getMessage());
            return null;
        }
    });
}


function headers_associative_to_array(array $headers): array
{
    return collect($headers)->map(fn($value, $header) => "$header: $value")->all();
}

function headers_array_to_associative(string $header_raw): array
{
    $parts = explode(':', $header_raw);
    return [$parts[0] => implode('', array_slice($parts, 1))];
}

/**
 * returns $str with length of max_len+3, if it overflows
 */
function strip_str($str, $max_len)
{
    if (strlen($str) > $max_len) {
        return substr($str, 0, $max_len) . "...";
    }
    return $str;
}

function make_monolog($name = 'default', $level = \Monolog\Logger::DEBUG): \Psr\Log\LoggerInterface
{
    $log = new \Monolog\Logger($name);
    $handler = new \Monolog\Handler\StreamHandler(logfile(), $level, true, null, true);
    $handler->setFormatter(new \Monolog\Formatter\LineFormatter("%datetime% [%level_name%] %channel% %message% %context% %extra%\n"));
    $log->pushHandler($handler);
    return $log;
}


/**
 * @throws Exception if an appropriate source of randomness cannot be found.
 */
function random_name($directory, $prefix = '', $ext = null): string
{
    $fname = $prefix . substr(hash('md5', random_bytes(256)), 0, 4) . ".tmp" . putif($ext, ".$ext");
    return normalize("$directory/$fname");
}

/**
 * wrap the value in an array if it is not iterable.
 * @param $value
 * @return iterable
 */
function wrapIterable($value): iterable
{
    if (is_array($value) || is_iterable($value)) {
        return $value;
    }
    return [$value];
}

/**
 * on docker return the local tmp path
 * @return string|void
 */
function get_temp_dir()
{
    if (App::is_dockerized()) {
        $p = rootpath('tmp');
        if (!file_exists($p)) {
            try {
                if (!mkdir($p, 0600)) throw new Error("failed to create tmp dir");
            } catch (\Throwable $original) {
                throw new Error("failed to create tmp dir", $original->getCode(), $original);
            }
        }
        return $p;
    } else {
        return sys_get_temp_dir();
    }
}

/**
 * returns the path to the temporary merged video,
 * the file should be cleaned after copying or on errors.
 * @throws Exception|\FFMpeg\Exception\InvalidArgumentException|\FFMpeg\Exception\RuntimeException
 * @noinspection PhpFullyQualifiedNameUsageInspection
 */
function merge_video_with_audio(string $video_source, string $audio_source, string $output, \Closure $on_progress = null): void
{
    $ffmpeg = make_ffmpeg();
    /** @var $vid \FFMpeg\Media\Video */
    $vid = $ffmpeg->open($video_source);
    $vid->addFilter(new \FFMpeg\Filters\Audio\SimpleFilter(array('-i', $audio_source, '-shortest')));
    $format = new \Eboubaker\Scraper\Extensions\FFMpeg\X264();
    $format->setVideoCodec('copy');
    $format->setKiloBitrate(0);
    if ($on_progress) {
        $format->on('progress', fn($video, $format, $percentage) => $on_progress($percentage, $video, $format));
    }
    $vid->save($format, $output);
}
