<?php

namespace Eboubaker\Scrapper\Exception;


class ExpectationFailedException extends \Exception
{
    public function __construct($message = "", \Throwable $previous = null)
    {
        parent::__construct($message, 12, $previous);
    }
}