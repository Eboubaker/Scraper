<?php declare(strict_types=1);

namespace Eboubaker\Scrapper\Scrappers;

use Eboubaker\Scrapper\Contracts\Scrapper;
use Eboubaker\Scrapper\Exception\NotImplementedException;

class RedditScrapper extends Scrapper
{
    public static function can_scrap($url): bool
    {
        return !!preg_match("/https?:\/\/(www\.)?reddit\.com\//", $url);
    }

    /**
     * @throws NotImplementedException
     */
    function download_media_from_html_document(string $html_document): string
    {
        throw new NotImplementedException("Reddit scrapper will be implemented soon.");
    }
}

