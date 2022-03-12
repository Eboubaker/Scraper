<?php declare(strict_types=1);

namespace Eboubaker\Scrapper\Concerns;

use Eboubaker\Scrapper\App;
use Exception;
use FFMpeg\Media\Video;
use Psr\Log\LoggerInterface;
use ReflectionClass;

/**
 * @author Eboubaker Bekkouche <eboubakkar@gmail.com>
 */
trait ScrapperUtils
{
    private LoggerInterface $log;

    public function __construct()
    {
        $channel = (new ReflectionClass($this))->getShortName();
        $this->log = make_monolog($channel);
    }

    /**
     * returns the path to the temporary merged video,
     * the file should be cleaned after copying or on errors.
     * @throws Exception|\FFMpeg\Exception\InvalidArgumentException|\FFMpeg\Exception\RuntimeException
     * @noinspection PhpFullyQualifiedNameUsageInspection
     */
    protected function merge_video_with_audio(string $video_url, string $audio_url): ?string
    {
        $ffmpeg = \FFMpeg\FFMpeg::create([
            "ffprobe.binaries" => get_ffmpeg_path()
        ]);
        $vid = $ffmpeg->open($video_url);
        $vid->addFilter(new \FFMpeg\Filters\Audio\SimpleFilter(array('-i', $audio_url, '-shortest')));
        $name = tempnam(sys_get_temp_dir(), time() . ".scrapper");
        $vid->save(new \FFMpeg\Format\Video\X264(), $name);
        return $name;
    }
}