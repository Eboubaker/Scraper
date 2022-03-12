<?php declare(strict_types=1);

use Eboubaker\Scrapper\App;
use PHPUnit\Framework\TestCase;

final class FacebookScrapperTest extends TestCase
{
    public function testCanScrapVideoSourcesInPost(): void
    {
        $fname = getcwd() . DIRECTORY_SEPARATOR . "test_" . time() . "mp4";
        App::run([
            "", "-o", $fname, "https://www.facebook.com/zuck/videos/4884691704896320"
        ]);
        $this->assertGreaterThan(bytes('1MB'), @filesize($fname));
    }

    public function testCanScrapImageSource(): void
    {
        $this->fail("TODO");
    }
}

