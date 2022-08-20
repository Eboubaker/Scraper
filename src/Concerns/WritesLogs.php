<?php declare(strict_types=1);

namespace Eboubaker\Scraper\Concerns;

use Psr\Log\LoggerInterface;
use ReflectionClass;

/**
 * used by anything that wants to write logs to the log file
 * @author Eboubaker Bekkouche <eboubakkar@gmail.com>
 */
trait WritesLogs
{
    private LoggerInterface $log;

    /**
     * must be called with the parent's constructor if overridden
     */
    public function __construct()
    {
        $channel = (new ReflectionClass($this))->getShortName();
        $this->log = make_monolog($channel);
    }
}
