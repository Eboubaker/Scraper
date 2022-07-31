<?php

namespace Eboubaker\Scrapper\Tools\CLI;

trait HasProgressBar
{
    private int $length;

    public function __construct(int $length = 50)
    {
        $this->length = $length;
    }

    function clear()
    {
        if (stream_isatty(STDOUT)) {
            fwrite(STDOUT, TTY_FLUSH . TTY_UP);
        } else {
            echo PHP_EOL;
        }
    }

    protected function show_bar(float $percentage, $add_format, ...$arguments)
    {
        if (stream_isatty(STDOUT)) {
            $format = "\33[2K\r[%s] %d%% $add_format";
            $plus_pad = 11;
        } else {
            $format = "[%s] %d%% $add_format\n";
            $plus_pad = 0;
        }
        $snake_len = (int)($this->length * $percentage);
        fwrite(STDOUT, sprintf($format
            , str_pad(style(str_repeat('=', $snake_len), 'green,bold') . ">", $this->length + $plus_pad, "-", STR_PAD_RIGHT)
            , (int)($percentage * 100)
            , ...$arguments));
    }
}
