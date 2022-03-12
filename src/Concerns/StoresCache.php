<?php

namespace Eboubaker\Scrapper\Concerns;

use Tightenco\Collect\Support\Arr;

trait StoresCache
{
    private static array $store = [];

    public static function cache_set(string $key, $value)
    {
        Arr::set(self::$store, $key, $value);
        return $value;
    }

    public static function cache_has(string $key): bool
    {
        return Arr::has(self::$store, $key);
    }

    public static function cache_pull(string $key)
    {
        return self::cache_forget($key);
    }

    public static function cache_forget(string $key)
    {
        $v = self::cache_get($key);
        Arr::forget(self::$store, $key);
        return $v;
    }

    public static function cache_get(string $key)
    {
        return data_get(self::$store, $key);
    }
}
