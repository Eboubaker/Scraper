<?php

namespace Eboubaker\Scrapper\Exception;

abstract class UserException extends \Exception
{
    public function __construct($message = "", \Throwable $previous = null)
    {
        parent::__construct($message, $this->code, $previous);
    }
}