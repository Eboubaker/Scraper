<?php

use FFMpeg\Exception\ExecutableNotFoundException;
use FFMpeg\FFMpeg;
use FFMpeg\FFProbe\DataMapping\Stream;
use FFMpeg\Format\FormatInterface;

if (!function_exists("str_starts_with")) {// for php < 8.0
    function str_starts_with($haystack, $needle): bool
    {
        return $needle === substr($haystack, 0, strlen($needle));
    }
}
function style(string $text, ...$styles): string
{
    $codes = [
        'bold' => 1,
        'italic' => 3, 'underline' => 4, 'strikethrough' => 9,
        'black' => 30, 'red' => 31, 'green' => 32, 'yellow' => 33, 'blue' => 34, 'magenta' => 35, 'cyan' => 36, 'white' => 37,
        'blackbg' => 40, 'redbg' => 41, 'greenbg' => 42, 'yellowbg' => 44, 'bluebg' => 44, 'magentabg' => 45, 'cyanbg' => 46, 'lightgreybg' => 47
    ];
    $formatMap = array_map(fn($v) => $codes[$v], $styles);
    return "\e[" . implode(';', $formatMap) . 'm' . $text . "\e[0m";
}

function format(string $text, ...$args): string
{
    return sprintf(str_replace("{}", "%s", $text), ...$args);
}

function info(string $msg, ...$args)
{
    echo style("[INFO] ", "cyan", "bold") . format($msg, ...$args) . "\n";
}

function notice(string $msg, ...$args)
{
    echo style("[NOTICE] ", "red", "yellow", "bold") . format($msg, ...$args) . "\n";
}

function error(string $msg, ...$args)
{
    echo style("[ERROR] ", "white", "redbg", "bold") . format($msg, ...$args) . "\n";
}

function warn(string $msg, ...$args)
{
    echo style("[WARN] ", "yellow", "bold") . format($msg, ...$args) . "\n";
}

function debug(string $msg, ...$args)
{
    if (!debug_enabled()) return;
    echo style("[DEBUG] ", "blue", "bold") . format($msg, ...$args) . "\n";
}

function host_is_windows_machine(): bool
{
    return DIRECTORY_SEPARATOR === '\\';
}

/**
 * Show the exception in the console
 * @param Exception $e
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

/**
 * return value if condition is true otherwise empty string
 * @param callable|bool $condition
 */
function putif($condition, $value)
{
    if (is_callable($condition))
        return $condition() ? $value : '';
    return $condition ? $value : '';
}

function debug_enabled(): bool
{
    return boolval(\Eboubaker\Scrapper\App::get('debug'));
}

/**
 * get the directory of the vendor folder (the project path)
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
 */
function consolepath(string $append = ''): string
{
    return getcwd() . putif($append !== '', DIRECTORY_SEPARATOR . $append);
}

function usage_error($msg)
{
    echo <<<EOF
    Error: $msg
    Usage: php scrap.php [--driver-url <URL>] [--debug] [--out <output_file>] [--] <PAGE_URL>
    EOF;
    echo "\n\n";
}

/**
 * @param Stream $video
 * @param Stream $audio
 * @param string $output_file
 * @param FormatInterface $format
 * @return string
 * @throws ExecutableNotFoundException
 */
function merge_video_with_audio(Stream          $video,
                                Stream          $audio,
                                string          $filename,
                                FormatInterface $format): string
{
    $ffmpeg = FFMpeg::create();
    $w = $h = 0;
    try {
        list($w, $h) = [$video->getDimensions()->getWidth(), $video->getDimensions()->getHeight()];
    } catch (Exception $e) {
        debug("getDimensions() failed {}:{}", __FILE__, __LINE__);
    }
    info("Will merge Audio with Video ({}x{})", $w, $h);
    $vid = $ffmpeg->open($video->get('url'));
    $vid->addFilter(new \FFMpeg\Filters\Audio\SimpleFilter(array('-i', $audio->get('url'), '-shortest')));
    $vid->save($format, $filename);
    return $filename;
}

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

function logfile(string $name = 'scrapper.log')
{
    return rootpath('logs/' . $name);
}