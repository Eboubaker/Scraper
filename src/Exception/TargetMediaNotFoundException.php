<?php

namespace Eboubaker\Scraper\Exception;


class TargetMediaNotFoundException extends UserException
{
    protected $code = 20;

    public function __construct($message = "media not found", \Throwable $previous = null)
    {
        parent::__construct($message . ", if the post is not public read https://github.com/Eboubaker/Scraper/blob/master/docs/LOGIN.md", $previous);
    }
}
