<?php

namespace Eboubaker\Scrapper\Exception;

class UrlNotSupportedException extends \Exception
{
    public function __construct($message = "", \Throwable $previous = null)
    {
        parent::__construct($message, 15, $previous);
    }
}