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