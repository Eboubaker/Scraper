<?php

namespace Eboubaker\Scrapper\Tools\CLI;

class DownloadIndicator
{
    use HasProgressBar {
        HasProgressBar::__construct as private bootTrait;
    }

    private float $total;
    private float $downloaded = 0;
    private float $current_speed = 0;
    private float $last_downloaded = 0;
    private float $show_delay = .2;
    private float $speed_delay = 2;
    private float $last_speed_update = 0;
    private float $last_show = 0;

    public function __construct(float $total)
    {
        $this->bootTrait();
        $this->total = $total;
    }

    public function progress(float $downloaded)
    {
        $this->downloaded += $downloaded;
    }

    public function display($append_caption = '')
    {
        // refresh the calculations when it is time
        if (microtime(true) > $this->last_show + $this->show_delay) {
            // refresh the average speed when it is time.
            if (microtime(true) > $this->last_speed_update + $this->speed_delay) {
                $this->current_speed = ($this->downloaded - $this->last_downloaded) / (microtime(true) - $this->last_speed_update);
                $this->last_downloaded = $this->downloaded;
                $this->last_speed_update = microtime(true);
            }

            $this->show_bar($this->downloaded / $this->total, "%s/%s %s/s $append_caption"
                , human_readable_size($this->downloaded)
                , human_readable_size($this->total)
                , human_readable_size($this->current_speed));
            $this->last_show = microtime(true);
        }
    }
}
