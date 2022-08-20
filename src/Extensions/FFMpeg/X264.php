<?php /** @noinspection PhpMissingReturnTypeInspection */

namespace Eboubaker\Scraper\Extensions\FFMpeg;

/**
 * Added 'copy' as available video codec
 */
class X264 extends \FFMpeg\Format\Video\X264
{
    /**
     * {@inheritDoc}
     */
    public function getAvailableVideoCodecs()
    {
        return array('libx264', 'copy');
    }
}
