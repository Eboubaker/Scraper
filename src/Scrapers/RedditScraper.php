<?php declare(strict_types=1);

namespace Eboubaker\Scraper\Scrapers;

use Eboubaker\Scraper\Concerns\WritesLogs;
use Eboubaker\Scraper\Contracts\Scraper;
use Eboubaker\Scraper\Exception\NotImplementedException;
use Eboubaker\Scraper\Tools\Http\Document;
use Eboubaker\Scraper\Tools\Optional;

/**
 * @author Eboubaker Bekkouche <eboubakkar@gmail.com>
 */
final class RedditScraper implements Scraper
{
    use WritesLogs;

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

        throw new NotImplementedException("Reddit scraper will be implemented soon.");
    }

    public static function is_redirect(string $url): bool
    {
        return false;
    }

    public static function get_redirect_target(string $url): Optional
    {
        return Optional::empty();
    }
}

