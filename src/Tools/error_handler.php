<?php declare(strict_types=1);

// TODO: add only when not in debug mode
// convert errors to exceptions.
set_error_handler(function (int    $errno,
                            string $errstr,
                            string $errfile,
                            int    $errline) {
    make_monolog('error_handler')->warning(
        format("Unhandled error: {} at {}:{} error_no {}", $errstr, $errfile, $errline, $errno)
    );
    /** @noinspection PhpUnhandledExceptionInspection */
    throw new ErrorException($errstr, $errno, 1, $errfile, $errline);
});

