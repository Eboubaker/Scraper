<?php declare(strict_types=1);

namespace Eboubaker\Scrapper\Scrappers;

use Eboubaker\Scrapper\Concerns\ScrapperUtils;
use Eboubaker\Scrapper\Contracts\Scrapper;
use Eboubaker\Scrapper\Exception\NotImplementedException;

/**
 * @author Eboubaker Bekkouche <eboubakkar@gmail.com>
 */
class RedditScrapper implements Scrapper
{
    use ScrapperUtils;

    public static function can_scrap(string $final_url, ?string $html_document): bool
    {
        return !!preg_match("/https?:\/\/(www\.)?reddit\.com\//", $final_url);
    }

    /**
     * @throws NotImplementedException
     */
    function download_media_from_html_document(string $final_url, string $html_document): string
    {
        throw new NotImplementedException("Reddit scrapper will be implemented soon.");
    }
}

