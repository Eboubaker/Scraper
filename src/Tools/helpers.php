<?php

use Symfony\Component\DomCrawler\Crawler;
use Tightenco\Collect\Support\Arr;


/**
 * @author Eboubakkar Bekkouche <eboubakkar@gmail.com>
 */
function format(string $text, ...$args): string
{

    return sprintf(str_replace("{}", "%s", $text), ...$args);
}

function info(string $msg, ...$args)
{
    echo style("[INFO] ", "cyan,bold") . format($msg, ...$args) . "\n";
}

function tell(string $msg, ...$args)
{
    fwrite(STDOUT, format($msg, ...$args) . "\n");
}

function notice(string $msg, ...$args)
{
    echo style("[NOTICE] ", "red,yellow,bold") . format($msg, ...$args) . "\n";
}

function error(string $msg, ...$args)
{
    echo style("[ERROR] ", "red,bold") . format($msg, ...$args) . "\n";
}

function warn(string $msg, ...$args)
{
    echo style("[WARN] ", "yellow,bold") . format($msg, ...$args) . "\n";
}

function debug(string $msg, ...$args)
{
    if (!debug_enabled()) return;
    echo style("[DEBUG] ", "blue,bold") . format($msg, ...$args) . "\n";
}


function style(string $text, ...$styles): string
{
    // stolen: https://stackoverflow.com/a/69580828/10387008
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
        ->filter(fn($c) => strlen($c))
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

// stolen: https://stackoverflow.com/a/66573396/10387008
function human_readable_size($bytes): string
{
    if ($bytes > 0) {
        $base = floor(log($bytes) / log(1024));
        $units = array("B", "KB", "MB", "GB", "TB", "PB", "EB", "ZB", "YB"); //units of measurement
        return number_format(($bytes / pow(1024, floor($base))), 3) . " $units[$base]";
    } else return "0 bytes";
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
    return boolval(\Eboubaker\Scrapper\App::args()->getOpt('verbose'));
}

/**
 * get the directory of the vendor folder (the project root).
 *
 * if failed to find vendor directory then return the relative (./DIRECTORY_SEPARATOR)
 * @author Eboubakkar Bekkouche <eboubakkar@gmail.com>
 */
function rootpath(string $append = ''): string
{
    $append = str_replace(["\\", "/"], DIRECTORY_SEPARATOR, $append);
    $curr = dirname(__FILE__);
    $tries = 5;
    while (!file_exists($curr . DIRECTORY_SEPARATOR . 'vendor') && --$tries != 0)
        $curr = dirname($curr);
    return $curr . putif($append !== '', DIRECTORY_SEPARATOR . $append);
}

/**
 * get the current working directory path
 * @author Eboubakkar Bekkouche <eboubakkar@gmail.com>
 */
function consolepath(string $append = ''): string
{
    return getcwd() . putif($append !== '', DIRECTORY_SEPARATOR . $append);
}

/**
 * @author Eboubakkar Bekkouche <eboubakkar@gmail.com>
 */
function download_static_media_url(string $url, string $filename): string
{
    $ch = curl_init($url);
    $fp = fopen($filename, 'wb');
    curl_setopt($ch, CURLOPT_FILE, $fp);
    curl_setopt($ch, CURLOPT_HEADER, 0);
    if (!curl_exec($ch)) {
        fclose($fp);
        unlink($filename);
        return false;
    }
    curl_close($ch);
    fclose($fp);
    return $filename;
}

/**
 * @author Eboubakkar Bekkouche <eboubakkar@gmail.com>
 */
function logfile(string $name = 'scrapper.log')
{
    return rootpath('logs/' . $name);
}

// stolen: https://stackoverflow.com/a/52927815/10387008
function setClipboard(string $new): bool
{
    if (PHP_OS_FAMILY === "Windows") {
        // works on windows 7 +
        $clip = popen("clip", "wb");
    } elseif (PHP_OS_FAMILY === "Linux") {
        // tested, works on ArchLinux
        $clip = popen('xclip -selection clipboard', 'wb');
    } elseif (PHP_OS_FAMILY === "Darwin") {
        // untested!
        $clip = popen('pbcopy', 'wb');
    } else {
        throw new \Error("running on unsupported OS: " . PHP_OS_FAMILY . " - only Windows, Linux, and MacOS supported.");
    }
    $written = fwrite($clip, $new);
    return (pclose($clip) === 0 && strlen($new) === $written);
}

/**
 * @author Eboubakkar Bekkouche <eboubakkar@gmail.com>
 */
function decode_json_url(string $url): string
{
    return json_decode("\"$url\"");
}

/**
 * @author Eboubakkar Bekkouche <eboubakkar@gmail.com>
 */
function array_preg_find_value_paths(array $haystack, string $pattern, &$accumulator, array $current_path = []): bool
{
    $found_something = false;
    foreach ($haystack as $key => $item) {
        if ((is_string($item) || is_numeric($item)) && preg_match($pattern, strval($item))) {
            $accumulator[] = [...$current_path, $key];
            $found_something = true;
        }
    }
    foreach ($haystack as $key => $item) {
        if (is_array($item)) {
            $found_something = array_preg_find_value_paths($item, $pattern, $accumulator, [...$current_path, $key]) || $found_something;
        }
    }
    return $found_something;
}

/**
 * @author Eboubakkar Bekkouche <eboubakkar@gmail.com>
 */
function array_dot_find_value(array $array, string $dot_path, &$accumulator, $current_path = ""): bool
{
    $found_something = false;
    if (Arr::has($array, $dot_path)) {
        $accumulator[][$current_path] = Arr::get($array, $dot_path);
        $found_something = true;
    }
    foreach ($array as $key => $item) {
        if (is_array($item)) {
            $found_something = array_dot_find_value($item, $dot_path, $accumulator, $current_path . "." . $key) || $found_something;
        }
    }
    return $found_something;
}

/**
 * recursively find in a multi-dimensional array an item which contains all the given keys.
 * @author Eboubakkar Bekkouche <eboubakkar@gmail.com>
 */
function array_search_match(array $array, $keys, $path = ''): ?string
{
    $keys = (array)$keys;// allow string or array
    // array must have the given key and also all adjacent keys
    $found_count = 0;
    foreach ($keys as $key => $regex) {
        if (is_string($key)) {// $regex is real regex
            $v = Arr::get($array, $key, fn() => false);
            if (is_callable($v))
                break; // not found(default value)
            else if (empty($key)) {// regex searching without nesting
                foreach ($array as $k => $i) {
                    if (preg_match($regex, $i)) {
                        return strval($k);
                    }
                }
                break;
            } else if (!is_numeric($v) && !is_string($v))
                break;// we can't run regex on array
            else if (!preg_match($regex, $v))
                break;// regex didnt match

        } else {// $regex is not a regex
            if (!Arr::has($array, $regex))
                break;// key not found
        }
        ++$found_count;
    }
    if ($found_count === count($keys)) {
        return ltrim($path, '.');
    }
    foreach ($array as $key => $item) {
        if (is_array($item)) {
            $found = array_search_match($item, $keys, $path . '.' . $key);
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

// stolen: https://stackoverflow.com/a/42058764/10387008
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

/**
 * find all json objects/arrays in an html document.
 *
 * @param string $html
 * @return array an associative array of all found objects(nested objects ar also associative arrays)
 * @author Eboubaker Bekkouche <eboubakkar@gmail.com>
 */
function collect_all_json(string $html): array
{
    $regex_valid_json = <<<'EOF'
    /

    (?(DEFINE)
     (?<number>   -? (?= [1-9]|0(?!\d) ) \d+ (\.\d+)? ([eE] [+-]? \d+)? )
     (?<boolean>   true | false | null )
     (?<string>    " ([^"\n\r\t\\\\]* | \\ ["\\\\bfnrt\/] | \\ u [0-9a-f]{4} )* " )
     (?<array>     \[  (?:  (?&json)  (?: , (?&json)  )*  )?  \s* \] )
     (?<pair>      \s* (?&string) \s* : (?&json)  )
     (?<object>    \{  (?:  (?&pair)  (?: , (?&pair)  )*  )?  \s* \} )
     (?<json>   \s* (?: (?&number) | (?&boolean) | (?&string) | (?&array) | (?&object) ) \s* )
     (?<realobject>    \{  (?:  (?&pair)  (?: , (?&pair)  )*  )  \s* \} )
     (?<realarray>     \[  (?:  (?&json)  (?: , (?&json)  )*  )  \s* \] )
     (?<realjson>   \s* (?: (?&realarray) | (?&realobject) ) \s* )
    )
    (?&realjson)
    /six
    EOF;

    $data_bag = collect((new Crawler($html))
        // find script tags
        ->filter("script")
        // find all json inside the scripts
        ->each(function (Crawler $node) use ($regex_valid_json) {
            if (isset($F)) unset($F);
            preg_match_all($regex_valid_json, $node->text(null, false), $F, PREG_UNMATCHED_AS_NULL);
            if (preg_last_error() !== PREG_NO_ERROR) error(preg_last_error_msg());
            return $F;
        }))
        ->flatten()
        // remove preg_match empty groups garbage
        ->filter(fn($j) => $j && strlen($j))
        ->map(fn($obj) => json_decode($obj, true))
        ->filter(function (array $arr) {
            // keep arrays that contains at least one string key
            return collect($arr)->filter(fn($v, $k) => is_string($k))
                ->count();
        })
        ->values()
        ->all();
    return $data_bag;
}

/**
 * Append something to a log file
 * @param string $str
 * @param string|null $fname which logfile to use
 * @author Eboubaker Bekkouche <eboubakkar@gmail.com>
 */
function flog(string $str, ?string $fname = null)
{
    if ($fname)
        file_put_contents(logfile($fname), $str . PHP_EOL, FILE_APPEND);
    else
        file_put_contents(logfile(), $str . PHP_EOL, FILE_APPEND);
}

/**
 * @return string
 */
function get_ffmpeg_path(): ?string
{
    $local_path = rootpath("bin/ffmpeg" . putif(host_is_windows_machine(), ".exe"));
    if (file_exists($local_path)) return $local_path;
    exec(putif(host_is_windows_machine(), "where", "which") . " ffmpeg", $r);
    $path = trim(explode("\n", $r)[0]);
    if (empty($path)) {
        // TODO: Download ffmpeg binaries and return the local_path
        return null;
    } else {
        return $path;
    }
}