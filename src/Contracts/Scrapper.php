<?php declare(strict_types=1);

namespace Eboubaker\Scrapper\Contracts;

/**
 * @author Eboubaker Bekkouche <eboubakkar@gmail.com>
 */
interface Scrapper
{
    /**
     * download the media in the document, should return the filename
     */
    public function download_media_from_html_document(string $final_url, string $html_document): string;


    /**
     * returns true if the url can be handled by the scrapper.
     * The function should return a boolean and should not raise any exceptions and should not produce any side effects.
     */
    // #[\JetBrains\PhpStorm\Pure]
    public static function can_scrap(string $final_url, ?string $html_document): bool;
}