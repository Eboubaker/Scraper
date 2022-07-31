<?php declare(strict_types=1);

namespace Eboubaker\Scrapper\Scrappers;

use Eboubaker\Scrapper\Concerns\WritesLogs;
use Eboubaker\Scrapper\Contracts\Scrapper;
use Eboubaker\Scrapper\Exception\NotImplementedException;
use Eboubaker\Scrapper\Scrappers\Shared\ScrapperUtils;
use Eboubaker\Scrapper\Tools\Http\Document;

/**
 * @author Eboubaker Bekkouche <eboubakkar@gmail.com>
 */
final class RedditScrapper implements Scrapper
{
    use ScrapperUtils, WritesLogs;

    public static function can_scrap(Document $document): bool
    {
        return !!preg_match("/https?:\/\/(www\.)?reddit\.com\//", $document->getFinalUrl());
    }

    /**
     * @return iterable
     * @throws NotImplementedException
     */
    function scrap(Document $document)
    {
        $data_bag = $document->getObjects();

        throw new NotImplementedException("Reddit scrapper will be implemented soon.");
    }
}

