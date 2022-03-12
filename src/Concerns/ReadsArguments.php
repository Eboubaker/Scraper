<?php

namespace Eboubaker\Scrapper\Concerns;

use Eboubaker\Scrapper\Exception\InvalidArgumentException;
use Garden\Cli\Args;
use Garden\Cli\Cli;

trait ReadsArguments
{
    private static Args $arguments;


    public static function args(): Args
    {
        return self::$arguments;
    }

    /**
     * @throws InvalidArgumentException
     */
    public static function parse_arguments($argv = null): Args
    {
        $cli = Cli::create()
            ->description("Download media from a post url")
            ->opt("out:o", "set output path, default is current working directory(\$pwd)")
            ->opt("verbose:v", "display more useful information", false, 'bool')
            ->opt("version", "show version", false, 'bool')
            ->opt('header:h', "Add a header to the request (like Cookie), can be repeated", false, "string[]")
            ->arg("url", "Post Url");
        try {
            if (count($argv) === 1)
                array_push($argv, "--help");
            return $cli->parse($argv, in_array("--help", $argv));
        } catch (\Throwable $e) {
            throw new InvalidArgumentException("Invalid arguments", $e);
        }
    }
}