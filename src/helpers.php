<?php
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

function info(string $msg, ...$args)
{
    printf(style("[INFO]", "cyan", "bold") . " " . str_replace("{}", "%s", $msg) . "\n", ...$args);
}

function notice(string $msg, ...$args)
{
    printf(style("[NOTICE]", "red", "yellow", "bold") . " " . str_replace("{}", "%s", $msg) . "\n", ...$args);
}

function error(string $msg, ...$args)
{
    printf(style("[ERROR]", "white", "redbg", "bold") . " " . str_replace("{}", "%s", $msg) . "\n", ...$args);
}

function warn(string $msg, ...$args)
{
    printf(style("[WARN]", "yellow", "bold") . " " . str_replace("{}", "%s", $msg) . "\n", ...$args);
}

function debug(string $msg, ...$args)
{
    printf(style("[DEBUG]", "blue", "bold") . " " . str_replace("{}", "%s", $msg) . "\n", ...$args);
}

function host_is_windows_machine(): bool
{
    return DIRECTORY_SEPARATOR === '\\';
}

function dump_exception(Exception $e)
{
    dump((object)["exception" => $e, "cause" => $e->getPrevious()]);
}

function endc(array $a)
{
    return end($a);
}

function encodeURI($url): string
{
    // http://php.net/manual/en/function.rawurlencode.php
    // https://developer.mozilla.org/en/JavaScript/Reference/Global_Objects/encodeURI
    $unescaped = array(
        '%2D' => '-', '%5F' => '_', '%2E' => '.', '%21' => '!', '%7E' => '~',
        '%2A' => '*', '%27' => "'", '%28' => '(', '%29' => ')'
    );
    $reserved = array(
        '%3B' => ';', '%2C' => ',', '%2F' => '/', '%3F' => '?', '%3A' => ':',
        '%40' => '@', '%26' => '&', '%3D' => '=', '%2B' => '+', '%24' => '$'
    );
    $score = array(
        '%23' => '#'
    );
    return strtr(rawurlencode($url), array_merge($reserved, $unescaped, $score));

}