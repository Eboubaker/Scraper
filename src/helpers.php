<?php
if(!function_exists("str_starts_with")){
    function str_starts_with($haystack, $needle): bool
    {
        return $needle === substr($haystack, 0, strlen($needle));
    }
}