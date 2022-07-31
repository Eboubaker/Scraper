<?php

namespace Eboubaker\Scrapper\Exception;

/**
 * last code: 20 {@link TargetMediaNotFoundException}
 */
abstract class UserException extends \Exception
{
    public function __construct($message = "", \Throwable $previous = null)
    {
        parent::__construct($message, $this->code, $previous);
    }
}
