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
    echo style("[DEBUG] ", "blue", "bold") . format($msg, ...$args) . "\n";
}

function host_is_windows_machine(): bool
{
    return DIRECTORY_SEPARATOR === '\\';
}

function dump_exception(Exception $e)
{
    dump((object)["error" => $e->getMessage(), "exception" => $e, "cause" => $e->getPrevious()]);
}
