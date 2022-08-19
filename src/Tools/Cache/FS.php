<?php

namespace Eboubaker\Scrapper\Tools\Cache;

use Eboubaker\Scrapper\Contracts\Cache;
use RuntimeException;

/**
 * File-System cache.
 * stores serializable values.
 * does not store reference of cached values
 */
class FS implements Cache
{
    private static string $__cache_dir;// use get_cache_dir()

    public static function cache_pull(string $key)
    {
        $value = self::cache_get($key);
        self::cache_forget($key);
        return $value;
    }

    /**
     * @param mixed|callable|null $default will be returned if the key does not exist, if it is callback it will be called first
     * @throws RuntimeException if the keyfile exists but somehow it is corrupt and deserialization fails
     */
    public static function cache_get(string $key, $default = null)
    {
        $kf = self::key_file($key);
        $exists = @file_exists($kf);
        if ($exists) $content = @file_get_contents($kf);
        if (!$exists) {
            if (is_callable($default)) return call_user_func($default);
            return $default;
        }
        // TODO: does this error catcher work?
        $throw = null;
        $previous = set_error_handler(function ($errno, $errstr) use ($key, &$throw) {
            $throw = new RuntimeException("Failed to unserialize cached value for key: $key. caused by: $errstr", 0);
        });
        $value = @unserialize($content);
        set_error_handler($previous);
        if ($throw == null) {
            return $value;
        } else {
            throw $throw;
        }
    }

    // ~~~~~~~ IMPLEMENTATION ~~~~~~~ //

    /**
     * convert the key to a unique file path
     */
    private static function key_file(string $key): string
    {
        return self::get_cache_dir() . DIRECTORY_SEPARATOR . hash('sha256', $key);
    }

    /**
     * make the cache directory or just return it if it exists
     */
    private static function get_cache_dir(): string
    {
        if (isset(self::$__cache_dir)) return self::$__cache_dir;
        self::$__cache_dir = rootpath('cache');
        if (!file_exists(self::$__cache_dir)) {
            if (!mkdir(self::$__cache_dir, 0600, true)) {
                debug("Failed to create cache directory: " . self::$__cache_dir);
            }
            @file_put_contents(self::$__cache_dir . '/README.txt', 'This is a cache directory for the app. unused caches are cleaned by the app automatically.');
        }
        return self::$__cache_dir;
    }

    public static function cache_forget(string $key): void
    {
        $kf = self::key_file($key);
        if (@file_exists($kf)) @unlink($kf);
    }

    public static function remember(string $key, callable $action)
    {
        if (self::cache_has($key)) {
            return self::cache_get($key);
        }

        $value = call_user_func($action);
        self::cache_set($key, $value);
        return $value;
    }

    public static function do_once(string $key, callable $action): void
    {
        if (!FS::cache_has($key)) {
            call_user_func($action);
            FS::cache_set($key, null);
            register_shutdown_function(function () use ($key) {
                if (FS::cache_has($key)) FS::cache_forget($key);
            });
        }
    }

    public static function cache_has(string $key): bool
    {
        return @file_exists(self::key_file($key));
    }

    public static function cache_set(string $key, $value)
    {
        $serialized = serialize($value);
        @file_put_contents(self::key_file($key), $serialized);
    }
    // ~~~~~~~ IMPLEMENTATION END ~~~~~~~ //

    /**
     * removes cache files that are older than 24 hours
     */
    public static function gc()
    {
        foreach (array_diff(scandir(self::get_cache_dir()), array('.', '..')) as $cache_file) {
            $file_path = self::get_cache_dir() . DIRECTORY_SEPARATOR . $cache_file;
            if (basename($file_path) == 'README.txt') continue;
            if (time() - filemtime($file_path) > 24 * 3600) @unlink($file_path);
        }
    }
}
