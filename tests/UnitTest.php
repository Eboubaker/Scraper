<?php declare(strict_types=1);
ini_set('display_errors', "1");
ini_set('display_startup_errors', "1");
error_reporting(E_ALL);

require_once "../vendor/autoload.php";

use PHPUnit\Framework\TestCase;

final class UnitTest extends TestCase
{
    public function testCanRun()
    {

        self::assertTrue(true);
    }
}
