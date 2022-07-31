<?php declare(strict_types=1);

namespace Eboubaker\Scrapper\Contracts;

use Eboubaker\Scrapper\Tools\Http\Document;

/**
 * all scrappers shall implement this contract
 * @author Eboubaker Bekkouche <eboubakkar@gmail.com>
 */
interface Scrapper
{
    /**
     * download the media(s) in the document, should return the list of filenames
     * @return string[]|iterable
     */
    public function scrap(Document $document);


    /**
     * returns true if the url can be handled by the scrapper.
     * The function should return a boolean and should not raise any exceptions and should not produce any side effects.
     */
    // #[\JetBrains\PhpStorm\Pure]
    public static function can_scrap(Document $document): bool;
}
