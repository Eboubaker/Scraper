<?php declare(strict_types=1);

namespace Eboubaker\Scrapper\Scrappers;

use Eboubaker\Scrapper\Contracts\Scrapper;
use Eboubaker\Scrapper\Exception\NotImplementedException;

class YoutubeScrapper extends Scrapper
{
    public static function can_scrap($url): bool
    {
        return !!preg_match("/https?:\/\/((m|www)\.)?youtu(be)?(-nocookie)?\.(com|be)\//", $url);
    }

    /**
     * @throws NotImplementedException
     */
    function download_media_from_html_document(string $html_document): string
    {
        throw new NotImplementedException("Youtube scrapper will be implemented very soon.");
    }
}

