<?php declare(strict_types=1);

namespace Eboubaker\Scraper\Scrapers;

use Ahc\Cli\IO\Interactor;
use Ahc\Cli\Output\Writer;
use Eboubaker\JSON\Contracts\JSONEntry;
use Eboubaker\JSON\JSONObject;
use Eboubaker\JSON\JSONValue;
use Eboubaker\Scraper\Concerns\WritesLogs;
use Eboubaker\Scraper\Contracts\Scraper;
use Eboubaker\Scraper\Exception\DownloadFailedException;
use Eboubaker\Scraper\Exception\ExpectationFailedException;
use Eboubaker\Scraper\Exception\TargetMediaNotFoundException;
use Eboubaker\Scraper\Exception\WebPageNotLoadedException;
use Eboubaker\Scraper\Tools\Cache\Memory;
use Eboubaker\Scraper\Tools\Http\Document;
use Eboubaker\Scraper\Tools\Http\ThreadedDownloader;
use Eboubaker\Scraper\Tools\Optional;
use Throwable;

/**
 * @author Eboubaker Bekkouche <eboubakkar@gmail.com>
 */
final class FacebookScraper implements Scraper
{
    use WritesLogs;

    private const PATTERN_VIDEO_URL1 =
        /** @lang RegExp */
        "/https?:\/\/.{1,3}\.facebook\.com\/(?<poster>[^\/]+)\/videos\/(?<video_id>\d+)/";
    private const PATTERN_VIDEO_URL2 =
        /** @lang RegExp */
        "/https?:\/\/.{1,3}\.facebook\.com\/watch\/?(\?.*?)v=(?<video_id>\d+)/";
    private const PATTERN_VIDEO_URL3 =
        /** @lang RegExp */
        "/https?:\/\/.{1,3}\.facebook\.com\/(?<poster>[^\/]+)\/videos\/([^\/]+?\/)?(?<video_id>\d+)/";
    private const PATTERN_IMAGE_URL1 =
        /** @lang RegExp */
        "/https?:\/\/.{1,3}\.facebook\.com\/(?<poster>[^\/]+)\/photos\/[^\/]+\/(?<image_id>\d+)/";
    private const PATTERN_IMAGE_URL2 =
        /** @lang RegExp */
        "/https?:\/\/.{1,3}\.facebook\.com\/photo\/(\?.*?)fbid=(?<image_id>\d+)/";
    private const PATTERN_GROUP_POST1 =
        /** @lang RegExp */
        "/https?:\/\/.{1,3}\.facebook\.com\/groups\/(?<group_id>[^\/]+)\/permalink\/(?<post_id>\d+)/";
    private const PATTERN_GROUP_POST2 =
        /** @lang RegExp */
        "/https?:\/\/.{1,3}\.facebook\.com\/groups\/(?<group_id>[^\/]+)\/posts\/(?<post_id>\d+)/";
    private const PATTERN_PROFILE_STORY1 =
        /** @lang RegExp */
        "#https?://.{1,3}\.facebook\.com/stories/(?<bucket_id>[^/]+)/(?<encoded>[^?&/]+)#";

    public static function can_scrap(Document $document): bool
    {
        return preg_match(self::PATTERN_VIDEO_URL1, $document->getFinalUrl())
            || preg_match(self::PATTERN_VIDEO_URL2, $document->getFinalUrl())
            || preg_match(self::PATTERN_VIDEO_URL3, $document->getFinalUrl())
            || preg_match(self::PATTERN_IMAGE_URL1, $document->getFinalUrl())
            || preg_match(self::PATTERN_IMAGE_URL2, $document->getFinalUrl())
            || preg_match(self::PATTERN_GROUP_POST1, $document->getFinalUrl())
            || preg_match(self::PATTERN_GROUP_POST2, $document->getFinalUrl())
            || preg_match(self::PATTERN_PROFILE_STORY1, $document->getFinalUrl());
    }

    /**
     * @return string[]
     * @throws WebPageNotLoadedException
     * @throws DownloadFailedException
     * @throws ExpectationFailedException
     * @throws Throwable
     */
    function scrap(Document $document): iterable
    {
        if ($document->getContentLength() < bytes('300kb')) {
            warn("Response size is too small: {}, this probably indicates facebook redirected you to the login page, please read docs/login.md", style(human_readable_size($document->getContentLength()), 'red'));
        }
        if (preg_match("/\/login\/\?next=/", $document->getFinalUrl())) {
            throw new WebPageNotLoadedException("Facebook redirected me to the login page, This post might be private, try adding required header cookies");
        }
        if (preg_match(self::PATTERN_VIDEO_URL1, $document->getFinalUrl(), $matches)
            || preg_match(self::PATTERN_VIDEO_URL2, $document->getFinalUrl(), $matches)
            || preg_match(self::PATTERN_VIDEO_URL3, $document->getFinalUrl(), $matches)) {
            return wrapIterable($this->download_video($document, $matches['video_id']));
        } else if (preg_match(self::PATTERN_IMAGE_URL1, $document->getFinalUrl(), $matches)
            || preg_match(self::PATTERN_IMAGE_URL2, $document->getFinalUrl(), $matches)) {
            return wrapIterable($this->download_image($document, $matches['image_id']));
        } else if (preg_match(self::PATTERN_PROFILE_STORY1, $document->getFinalUrl(), $matches)) {
            return wrapIterable($this->download_story($document, $matches['bucket_id']));
        } else if (preg_match(self::PATTERN_GROUP_POST1, $document->getFinalUrl(), $matches)
            || preg_match(self::PATTERN_GROUP_POST2, $document->getFinalUrl(), $matches)) {
            $result = $document->getObjects()->getAll(
                "**.comet_sections.content.story.attachments.**.styles.attachment.media"
            );
            if (isset($result[0]) && $result[0] instanceof JSONObject && $result[0]->has('id')) {
                $id = $result[0]->get('id')->value();
                $type = $result[0]->get('__typename');
                if ($type && strtolower($type->value()) === 'video') {
                    return wrapIterable($this->download_video($document, $id));
                } else if ($type && strtolower($type->value()) === 'photo') {
                    return wrapIterable($this->download_image($document, $id));
                } else {
                    throw new ExpectationFailedException("Unknown media type: $type");
                }
            } else {
                throw new TargetMediaNotFoundException("Could not find the media in the post $matches[post_id]");
            }
        }
        fail:
        throw new TargetMediaNotFoundException("No media elements were found");
    }

    /**
     * @throws TargetMediaNotFoundException
     * @throws ExpectationFailedException
     * @throws Throwable
     */
    private function download_image(Document $document, string $id): ?string
    {
        $data = $document->getObjects();
        $title = Optional::ofNullable($data->search([
            "result.data.id" => fn(JSONEntry $e) => $e instanceof JSONValue && $e->equals($id),
            "result.data.title.text"
        ]))->map(function ($object) {
            return $object->get("result.data.title.text")->value();
        })->orElse("");
        $owner = Optional::ofNullable($data->search([
            "id" => fn(JSONEntry $e) => $e instanceof JSONValue && $e->equals($id),
            "owner.name"
        ]))->map(function ($object) {
            return $object->get("owner.name")->value();
        })->orElse("");
        $url = Optional::ofNullable($data->search([
            "id" => fn(JSONEntry $e) => $e instanceof JSONValue && $e->equals($id),
            "image.uri"
        ]))->mapOnce(function ($object) {
            return $object->get("image.uri")->value();
        })->orElseNew(function () use ($id, $data) {
            return $data->search([
                "id" => fn(JSONEntry $e) => $e instanceof JSONValue && $e->equals($id),
                "photo_image.uri"
            ]);
        })->mapOnce(function ($object) {
            return $object->get("photo_image.uri")->value();
        })->orElseThrow(fn() => new TargetMediaNotFoundException("Image \"$id\" not found"));
        $fname = normalize(
            Memory::cache_get('output_dir')
            . "/"
            . filter_filename("fb_$id" . putif($title, " " . $title) . ".jpg")
        );
        info("Will download Image " . style($id, 'cyan,bold') . putif($title, ": $title") . putif($owner, " " . style("FROM", 'cyan,bold') . " $owner"));
        return ThreadedDownloader::for($url, $document->getFinalUrl())
            ->setWorkers(8)
            ->validate()
            ->saveto($fname);
    }

    /**
     * @throws TargetMediaNotFoundException
     * @throws ExpectationFailedException
     * @throws Throwable
     */
    private function download_story(Document $document, string $id): array
    {
        $bucket = Optional::ofNullable(
            $document->getObjects()->getAll(
                '**.bucket',
                fn($v) => $v instanceof JSONObject && $v->get('id') !== null && $v->get('id')->matches("/$id/")
            )[0] ?? null
        )->map(fn($v) => $v->assoc());
        $attachments = $bucket
            ->map(fn($v) => data_get($v, 'unified_stories.edges'))
            ->map(fn($v) => array_map(fn($vv) => data_get($vv, 'node.attachments'), $v))
            ->map(fn($v) => array_merge(...array_values($v)))// flatten
            ->map(fn($v) => array_map(fn($vv) => data_get($vv, 'media'), $v))
            ->map(fn($v) => array_filter($v)) // not null
            ->map(fn($v) => array_filter($v, fn($v) => data_get($v, '__typename') === "Video" || data_get($v, '__typename') === "Photo"))
            ->map(fn($v) => count($v) === 0 ? null : $v)
            ->orElseThrow(fn() => new TargetMediaNotFoundException("No media elements found in this story bucket, story might be no longer available"));
        if (count($attachments) > 1) {
            $i = new Interactor();
            (new Writer())->table(array_map(function ($v, $k) {
                if ($this->is_image($v)) {
                    $description = data_get($v, 'accessibility_caption', '');
                } else {
                    $description = data_get($v, 'width') . "X" . data_get($v, 'height');
                }
                return [
                    'Number' => $k + 1,
                    'Type' => data_get($v, '__typename', 'Unknown'),
                    'Description' => $description,
                ];
            }, $attachments, array_keys($attachments)));
            info("More than 1 media was found in the story, specify which attachments to download, separate input numbers by comma ','");
            $attachments = $i->prompt("Which attachments to download?(All)", null, function ($input) use ($attachments) {
                if (!is_string($input) || empty($input)) return $attachments;
                return array_map(function ($v) use ($attachments) {
                    $i = intval($v);
                    if ($i > count($attachments) || $i < 1) throw new \RuntimeException("invalid number " . $v);
                    return $attachments[$i - 1];
                }, explode(',', $input));
            }, 3);
        }

        $files = [];
        foreach ($attachments as $attachment) {
            $is_image = $this->is_image($attachment);
            $fname = normalize(Memory::cache_get('output_dir') . "/" . filter_filename("[Story] $attachment[id]." . ($is_image ? "jpg" : "mp4")));
            if ($is_image) {
                info("Downloading attachment $attachment[id] (image)");
                $url = Optional::ofNullable(data_get($attachment, 'image.uri'))
                    ->orElseThrow(fn() => new TargetMediaNotFoundException("image($attachment[id]) url not found"));
            } else {
                info("Downloading attachment $attachment[id] (video)");
                $url = Optional::ofNullable(data_get($attachment, 'playable_url_quality_hd'))
                    ->orElseNew(data_get($attachment, 'playable_url'))
                    ->orElseThrow(fn() => new TargetMediaNotFoundException("video($attachment[id]) url not found"));
            }
            $files[] = ThreadedDownloader::for($url, "[Story]$attachment[id]")
                ->validate()
                ->saveto($fname);
        }
        return $files;
    }

    private function is_image($attachment)
    {
        if ($attachment instanceof JSONObject) {
            return $attachment->get('__typename') !== null && $attachment->get('__typename')->value() === "Photo";
        } else {
            return data_get($attachment, "__typename") === "Photo";
        }
    }

    /**
     * @throws ExpectationFailedException
     * @throws Throwable
     * @throws TargetMediaNotFoundException
     */
    private function download_video(Document $doc, string $id): ?string
    {
        $data = $doc->getObjects();
        $url = Optional::ofNullable($data->search([
            "id" => fn($v) => $v->equals($id),
            "playable_url_quality_hd"
        ]))->mapOnce(function ($video) {
            return $video->get("playable_url_quality_hd")->value();
        })->orElseNew(function () use ($id, $data) {
            return $data->search([
                "id" => fn($v) => $v->equals($id),
                "playable_url"
            ]);
        })->mapOnce(function ($video) {
            return $video->get("playable_url")->value();
        })->orElseThrow(fn() => new TargetMediaNotFoundException("Video \"$id\" not found"));
        $title = Optional::ofNullable($data->search([
            "result.data.id" => fn($e) => $e->equals($id),
            "result.data.title.text"
        ]))->map(function ($object) {
            return $object->get("result.data.title.text")->value();
        })->orElse("");
        $owner = Optional::ofNullable($data->search([
            "id" => fn($e) => $e->equals($id),
            "owner.name"
        ]))->map(function ($object) {
            return $object->get("owner.name")->value();
        })->orElse("");
        $fname = normalize(Memory::cache_get('output_dir') . "/" . filter_filename("fb_$id" . putif($title, " " . $title) . ".mp4"));
        info("Will download Video " . style($id, 'cyan,bold') . putif($title, ": $title") . putif($owner, " " . style("FROM", 'cyan,bold') . " $owner"));
        return ThreadedDownloader::for($url, $doc->getFinalUrl())
            ->validate()
            ->saveto($fname);
    }
}

