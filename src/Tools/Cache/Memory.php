<?php

namespace Eboubaker\Scrapper\Tools\Cache;

use Eboubaker\Scrapper\Contracts\Cache;

/**
 * static memory cache.
 * can store anything.
 * keeps reference of values
 */
final class Memory implements Cache
{
    private static array $store = [];

    public static function cache_pull(string $key)
    {
        $value = Memory::cache_get($key);
        Memory::cache_forget($key);
        return $value;
    }

    public static function cache_get(string $key, $default = null)
    {
        if (Memory::cache_has($key)) {
            return Memory::$store[$key];
        }
        if (is_callable($default)) {
            return call_user_func($default);
        }
        return $default;
    }

    public static function cache_has(string $key): bool
    {
        return array_key_exists($key, Memory::$store);
    }

    public static function cache_forget(string $key): void
    {
        if (isset(Memory::$store[$key])) {
            unset(Memory::$store[$key]);
        }
    }

    public static function do_once(string $key, callable $action): void
    {
        if (!Memory::cache_has($key)) {
            call_user_func($action);
            Memory::cache_set($key, null);
        }
    }

    public static function cache_set(string $key, $value)
    {
        return Memory::$store[$key] = $value;
    }

    public static function increment(string $key, int $step = 1): void
    {
        if (!Memory::cache_has($key)) {
            Memory::cache_set($key, 0);
        }
        Memory::cache_set($key, Memory::cache_get($key) + $step);
    }

    public static function remember(string $key, callable $action)
    {
        if (Memory::cache_has($key)) {
            return Memory::cache_get($key);
        }

        $value = call_user_func($action);
        Memory::cache_set($key, $value);
        return $value;
    }
}
