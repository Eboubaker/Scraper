<?php

namespace Eboubaker\Scraper\Concerns;

use Eboubaker\Scraper\Exception\InvalidArgumentException;
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
            ->opt("verbose:v", "display more useful information while running", false, 'bool')
            ->opt("quality:q", "default: prompt, for videos only, changes quality selection behavior, allowed values: prompt,highest,high,saver where highest chooses the highest quality regardless of size, high will pick the best quality and will consider video size, saver will try to pick a lower size video", false)
            ->opt("version", "show version", false, 'bool')
            ->opt('header:H', "Add a header to the request (like Cookie), can be repeated", false, "string[]")
            ->arg("url", "Post Url");
        try {
            if (count($argv) === 1) array_push($argv, "--help");
            $args = $cli->parse($argv, in_array("--help", $argv));
        } catch (\Throwable $e) {
            throw new InvalidArgumentException("Invalid arguments", $e);
        }
        if (!$args->hasOpt('quality')) $args->setOpt('quality', 'prompt');
        if (!in_array($args->getOpt('quality'), ['prompt', 'highest', 'high', 'saver'])) {
            throw new InvalidArgumentException("Validation failed for option 'quality': option must be one of prompt, highest, high, saver");
        }
        return $args;
    }
}
