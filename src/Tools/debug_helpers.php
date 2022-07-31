<?php
/**
 * these helpers are for debugging only (to use with ide debugger)
 * @author Eboubakkar Bekkouche <eboubakkar@gmail.com>
 */

use Tightenco\Collect\Support\Arr;


function array_dot_find_value(array $array, string $dot_path, &$accumulator, $current_path = ""): bool
{
    $found_something = false;
    if (Arr::has($array, $dot_path)) {
        $accumulator[][$current_path] = data_get($array, $dot_path);
        $found_something = true;
    }
    foreach ($array as $key => $item) {
        if (is_array($item)) {
            $found_something = array_dot_find_value($item, $dot_path, $accumulator, $current_path . "." . $key) || $found_something;
        }
    }
    return $found_something;
}

function __array_preg_find_value_paths(array $haystack, string $pattern, &$accumulator, array $current_path = []): bool
{
    $found_something = false;
    foreach ($haystack as $key => $item) {
        if ((is_string($item) || is_numeric($item)) && preg_match($pattern, strval($item))) {
            $accumulator[] = [...$current_path, $key];
            $found_something = true;
        }
    }
    foreach ($haystack as $key => $item) {
        if (is_array($item)) {
            $found_something = __array_preg_find_value_paths($item, $pattern, $accumulator, [...$current_path, $key]) || $found_something;
        }
    }
    return $found_something;
}

function array_preg_find_value_paths_mapped(array $haystack, string $pattern): array
{
    __array_preg_find_value_paths($haystack, $pattern, $acc);
    return map_key_path_expressive($haystack, $acc ?? []);
}

function array_preg_find_value_paths(array $haystack, string $pattern): array
{
    __array_preg_find_value_paths($haystack, $pattern, $acc);
    return $acc;
}

function map_key_path(array $source, array $paths, $level = 0): array
{
    return collect($paths)->mapWithKeys(function ($p) use ($source, $level) {
        $vp = implode('.', array_slice($p, 0, $level ? -1 * $level : null));
        return [$vp => data_get($source, $vp)];
    })->all();
}

function map_key_path_expressive(array $source, array $paths): array
{
    $ret = [];
    foreach ($paths as $path) {
        $p = [];
        $current_path = 0;
        foreach ($path as $si => $key_segment) {
            $kp = implode('.', array_slice($path, 0, $si + 1));
            Arr::set($p, $current_path, [
                "value" => data_get($source, $kp),
                "path" => $kp,
                "full_target" => implode('.', $path)
            ]);
            $current_path .= '.next';
        }
        $current_path = preg_replace("/(\.next$)/", '', $current_path);
        $rp = [];
        $rcp = 0;
        while (strlen($current_path) != 0) {
            $n = data_get($p, $current_path);
            unset($n['next']);
            Arr::set($rp, $rcp, $n);
            $current_path = preg_replace("/(\.next$)|(^0$)/", '', $current_path);
            $rcp .= '.previous';
        }
        $ret[] = ['full_target' => $p[0]['full_target'], 'head' => $p[0], 'tail' => $rp[0]];
    }
    return $ret;
}
