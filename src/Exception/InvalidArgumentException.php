<?php

namespace Eboubaker\Scrapper\Exception;

class InvalidArgumentException extends \Exception
{
    public function __construct($message = "", \Throwable $previous = null)
    {
        parent::__construct($message, 13, $previous);
    }
}