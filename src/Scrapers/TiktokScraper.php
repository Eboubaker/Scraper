<?php declare(strict_types=1);

namespace Eboubaker\Scraper\Scrapers;

use Eboubaker\JSON\Contracts\JSONEntry;
use Eboubaker\JSON\JSONObject;
use Eboubaker\Scraper\Concerns\WritesLogs;
use Eboubaker\Scraper\Contracts\Scraper;
use Eboubaker\Scraper\Exception\ExpectationFailedException;
use Eboubaker\Scraper\Exception\TargetMediaNotFoundException;
use Eboubaker\Scraper\Scrapers\Shared\ScraperUtils;
use Eboubaker\Scraper\Tools\Cache\Memory;
use Eboubaker\Scraper\Tools\Http\Document;
use Eboubaker\Scraper\Tools\Http\ThreadedDownloader;
use Eboubaker\Scraper\Tools\Optional;
use Throwable;

/**
 * @author Eboubaker Bekkouche <eboubakkar@gmail.com>
 */
final class TiktokScraper implements Scraper
{
    use ScraperUtils, WritesLogs;

    public static function can_scrap(Document $document): bool
    {
        return !!preg_match("#https://(www|vm)\.tiktok\.com/#", $document->getFinalUrl());
    }

    /**
     * @param Document $document
     * @return string[]
     * @throws ExpectationFailedException
     * @throws TargetMediaNotFoundException
     * @throws Throwable
     */
    function scrap(Document $document): iterable
    {
        $data_bag = $document->getObjects();
        preg_match("|https://www\.tiktok\.com/[^/]+/video/(?<video_id>\d+)|", $document->getFinalUrl(), $matches);
        if (empty($matches['video_id'])) {
            throw new TargetMediaNotFoundException("Tiktok video id not found in this url: " . $document->getFinalUrl());
        }
        $id = $matches['video_id'];
        $module = Optional::ofNullable($data_bag->get("**.ItemModule.$id"))
            ->map(fn(JSONEntry $v) => $v instanceof JSONObject ? $v->assoc() : null);
        $download_url = $module->mapOnce(fn(array $v) => data_get($v, 'video.downloadAddr'))
            ->mapOnce(fn(array $v) => data_get($v, 'video.playAddr'))
            ->orElseThrow(fn() => new TargetMediaNotFoundException("Tiktok video not found in this page"));
        $author_uname = $module->mapOnce(fn(array $v) => is_string(data_get($v, 'author')) ? data_get($v, 'author') : null)
            ->orElse('Unknown');
        $author_name = $module->mapOnce(fn(array $v) => is_string(data_get($v, 'nickname')) ? "(" . data_get($v, 'nickname') . ")" : null)
            ->orElse('');
        $fname = "[Tiktok] @$author_uname$author_name Video [$id]";
        $fname = normalize(Memory::cache_get('output_dir') . "/" . filter_filename($fname) . ".mp4");
        info("Downloading @$author_uname$author_name Video [$id]");
        return wrapIterable(ThreadedDownloader::for($download_url, 'tiktok' . $id)
            ->validate()
            ->saveto($fname));
    }
}

