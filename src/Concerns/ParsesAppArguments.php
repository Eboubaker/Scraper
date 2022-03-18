<?php

namespace Eboubaker\Scrapper\Concerns;

use Eboubaker\Scrapper\Exception\InvalidArgumentException;
use Garden\Cli\Args;
use Garden\Cli\Cli;

trait ParsesAppArguments
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
            ->opt("output:o", "set output directory, default is current working directory")
            ->opt("verbose:v", "display more useful information", false, 'bool')
            //->opt("quality:q", "default: best, change quality selection behavior allowed values: optimal,best,low", false)
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
