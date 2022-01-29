<?php

namespace Eboubaker\Scrapper\Exception;

class DriverConnectionException extends \Exception
{
    public function __construct($message = "", \Throwable $previous = null)
    {
        parent::__construct($message, 11, $previous);
    }
}