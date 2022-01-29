<?php

namespace Eboubaker\Scrapper\Exception;


class UrlNavigationException extends \Exception
{
    public function __construct($message = "", \Throwable $previous = null)
    {
        parent::__construct($message, 14, $previous);
    }
}