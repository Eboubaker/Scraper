<?php

namespace Eboubaker\Scrapper\Reddit;

use Eboubaker\Scrapper\Contracts\Scrapper;
use Exception;
use Facebook\WebDriver\WebDriverBy;

class RedditScrapper extends Scrapper
{
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
            $script = <<<'EOD'
                var uri = arguments[0];
                var callback = arguments[1];
                var toBase64 = function(buffer){for(var r,n=new Uint8Array(buffer),t=n.length,a=new Uint8Array(4*Math.ceil(t/3)),i=new Uint8Array(64),o=0,c=0;64>c;++c)i[c]="ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/".charCodeAt(c);for(c=0;t-t%3>c;c+=3,o+=4)r=n[c]<<16|n[c+1]<<8|n[c+2],a[o]=i[r>>18],a[o+1]=i[r>>12&63],a[o+2]=i[r>>6&63],a[o+3]=i[63&r];return t%3===1?(r=n[t-1],a[o]=i[r>>2],a[o+1]=i[r<<4&63],a[o+2]=61,a[o+3]=61):t%3===2&&(r=(n[t-2]<<8)+n[t-1],a[o]=i[r>>10],a[o+1]=i[r>>4&63],a[o+2]=i[r<<2&63],a[o+3]=61),new TextDecoder("ascii").decode(a)};
                var xhr = new XMLHttpRequest();
                xhr.responseType = 'arraybuffer';
                xhr.onload = function(){ callback(toBase64(xhr.response)) };
                xhr.onerror = function(){ callback(xhr.status) };
                xhr.open('GET', uri);
                xhr.send();
            EOD;
            $result = $this->driver->executeAsyncScript($script, [$url]);
            if (is_numeric($result)) {
                throw new Exception("Request failed with status $result");
            }
            return base64_decode($result);
        }
        return parent::probe_file_name($url);
    }

    function download_media_from_post_url($post_url)
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
            $path = str_split($src);
            $filename = $this->saveUrl($src);
            echo "Downloaded image $src as $filename\n";
        } catch (Exception $e) {
            echo "Failed to locate media element in url $post_url\n";
            echo "Attempting to make a screenshot of the browser\n";
            $this->driver->takeScreenshot("screenshot.png");
            echo "Screenshot saved as screenshot.png\n";
            $this->close(true);
            throw $e;
        }
    }
}

