<?php

namespace Eboubaker\Scrapper\Exception;


class TargetMediaNotFoundException extends UserException
{
    protected $code = 20;

    public function __construct($message = "media not found", \Throwable $previous = null)
    {
        parent::__construct($message . ", if the post is not public read https://github.com/Eboubaker/Scrapper/blob/master/docs/LOGIN.md", $previous);
    }
}
