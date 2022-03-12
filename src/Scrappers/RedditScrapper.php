<?php declare(strict_types=1);

namespace Eboubaker\Scrapper\Scrappers;

use Eboubaker\Scrapper\Concerns\ScrapperUtils;
use Eboubaker\Scrapper\Contracts\Scrapper;
use Eboubaker\Scrapper\Exception\NotImplementedException;
use Eboubaker\Scrapper\Tools\Http\Document;

/**
 * @author Eboubaker Bekkouche <eboubakkar@gmail.com>
 */
final class RedditScrapper implements Scrapper
{
    use ScrapperUtils;

    public static function can_scrap(Document $document): bool
    {
        return !!preg_match("/https?:\/\/(www\.)?reddit\.com\//", $document->getFinalUrl());
    }

    /**
     * @throws NotImplementedException
     */
    function download_media_from_html_document(Document $document): string
    {
        throw new NotImplementedException("Reddit scrapper will be implemented soon.");
    }
}

