<?php

namespace Eboubaker\Scraper\Contracts;

/**
 * a cache store
 */
interface Cache
{
    public static function cache_has(string $key): bool;

    public static function cache_get(string $key, $default = null);

    public static function cache_set(string $key, $value);

    public static function cache_forget(string $key): void;

    public static function cache_pull(string $key);

    public static function remember(string $key, callable $action);
}
