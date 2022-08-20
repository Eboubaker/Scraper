<?php

namespace Eboubaker\Scraper\Tools\CLI;

class ProgressIndicator
{
    use HasProgressBar {
        HasProgressBar::__construct as private bootTrait;
    }

    private string $caption_format;

    public function __construct(string $append_format = '', int $length = 50)
    {
        $this->bootTrait($length);
        $this->caption_format = $append_format;
    }

    /**
     * @param float $percentage value from 0 to 1.0
     * @param ...$arguments
     * @return true always true, this allows to call the function using inline boolean checking
     */
    function update(float $percentage, ...$arguments): bool
    {
        $this->show_bar($percentage, $this->caption_format, ...$arguments);
        return true;
    }
}
