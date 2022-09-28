<?php declare(strict_types=1);

namespace Eboubaker\Scraper\Contracts;

use Eboubaker\Scraper\Tools\Http\Document;
use Eboubaker\Scraper\Tools\Optional;

/**
 * all scrapers shall implement this contract
 * @author Eboubaker Bekkouche <eboubakkar@gmail.com>
 */
interface Scraper
{

    /**
     * download the media(s) in the document, should return the list of filenames
     * @return string[]|iterable
     */
    public function scrap(Document $document);


    /**
     * returns true if the url can be handled by the scraper.
     * The function should return a boolean and should not raise any exceptions and should not produce any side effects.
     */
    // #[\JetBrains\PhpStorm\Pure]
    public static function can_scrap(Document $document): bool;

    /**
     * returns true if the url is a redirect url which can be obtained using this scraper
     */
    // #[\JetBrains\PhpStorm\Pure]
    public static function is_redirect(string $url): bool;

    /**
     * returns the redirect target of the given redirect url
     * @param string $url the supposedly url which contains a redirect
     * @return Optional<string> the real string url of the redirect inside an optional, if the url is not a redirect url or is invalid empty optional is returned
     */
    public static function get_redirect_target(string $url): Optional;
}
