<?php

namespace Eboubaker\Scrapper\Contracts;

use Throwable;

interface Downloader
{
    function __construct(string $resource_url);

    /**
     * @throws Throwable
     */
    function saveto(string $file_name): string;

    function get_resource_url(): string;
}