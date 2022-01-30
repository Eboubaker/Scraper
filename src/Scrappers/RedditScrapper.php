<?php

namespace Eboubaker\Scrapper\Scrappers;

use Eboubaker\Scrapper\Contracts\Scrapper;
use Exception;
use Facebook\WebDriver\WebDriverBy;

class RedditScrapper extends Scrapper
{
    public static function can_scrap($url): bool
    {
        return preg_match("/https?:\/\/(m|www)\.reddit\.com\/r\/.*\/comments\/.*/", $url);
    }

    /**
     * @throws Exception
     */
    public function probe_file_name(string $url): string
    {
        if (str_starts_with($url, "https://preview.redd.it/")) { // probably a gif
            $seg1 = explode('?', $url)[0];
            if (strpos($url, "format=mp4") && strpos($url, ".gif?")) {
                $ext = "mp4";
            } else {
                $ext = (fn($arr) => end($arr))(explode('.', $seg1));
            }
            $l = strrpos($url, '/') + 1;
            $name = substr($url, $l, strrpos($seg1, '.') - $l);
            return $name . "." . $ext;
        } else if (str_starts_with($url, "https://v.redd.it/")) {// probably a video
            throw new Exception("Video pulling not implemented");
        }
        return parent::probe_file_name($url);
    }

    /**
     * @throws Exception
     */
    function download_media_from_post_url($post_url): string
    {
        try {
            echo "Loading url $post_url\n";
            $this->driver->get($post_url);
            echo "Finding media element\n";
            $img = $this->driver->findElement(WebDriverBy::cssSelector('div>div>div>a>img'));
            $src = $img->getAttribute('src');
            $this->close();
            echo "element tag is " . $img->getTagName() . "\n";
            echo "img src is: " . $src . "\n";
            echo "Attempting to download image $src\n";
            $filename = $this->saveUrl($src);
            echo "Downloaded image $src as $filename\n";
            return $filename;
        } catch (Exception $e) {
            echo "Failed to locate media element in url $post_url\n";
            echo "Attempting to make a screenshot of the browser\n";
            $this->driver->takeScreenshot("screenshot.png");
            echo "Screenshot saved as screenshot.png\n";
            $this->close();
            throw $e;
        }
    }


}

