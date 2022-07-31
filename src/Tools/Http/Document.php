<?php

namespace Eboubaker\Scrapper\Tools\Http;

use Eboubaker\JSON\JSONArray;
use Eboubaker\JSON\JSONFinder;
use Eboubaker\Scrapper\Concerns\WritesLogs;
use Eboubaker\Scrapper\Exception\ExpectationFailedException;
use Eboubaker\Scrapper\Exception\WebPageNotLoadedException;
use Eboubaker\Scrapper\Extensions\Guzzle\EffectiveUrlMiddleware;
use Eboubaker\Scrapper\Scrappers\Shared\ScrapperUtils;
use Eboubaker\Scrapper\Tools\Cache\FS;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\RequestOptions as ReqOpt;
use Symfony\Component\DomCrawler\Crawler;

class Document
{
    use WritesLogs {
        __construct as private bootTrait;
    }

    private string $original_url;
    private string $final_url;
    private string $content;
    private JSONArray $json;

    private function __construct(string $url, string $final_url, string $content)
    {
        $this->bootTrait();
        $this->original_url = $url;
        $this->final_url = $final_url;
        $this->content = $content;
    }

    /**
     * @throws WebPageNotLoadedException
     */
    public static function fromUrl(string $url): Document
    {
        $stack = HandlerStack::create();
        $stack->push(EffectiveUrlMiddleware::middleware());
        $client = new HttpClient([
            'timeout' => 60,
            'allow_redirects' => [
                'max' => 5
            ],
            'verify' => false, // TODO: SSL
            'handler' => $stack
        ]);
        try {
            $response = $client->get($url, [
                ReqOpt::HEADERS => ScrapperUtils::make_curl_headers(),
                ReqOpt::PROGRESS => fn($downloadTotal, $downloadedBytes) => printf(TTY_FLUSH . "       " . human_readable_size($downloadedBytes))
            ]);
            printf(TTY_FLUSH);
            $final_url = $response->getHeaderLine('X-GUZZLE-EFFECTIVE-URL');
            $html_document = $response->getBody()->getContents();
            $response_size = strlen($html_document);
            make_monolog('ScrapperUtils')->debug("Response size: " . $response_size . "(" . human_readable_size($response_size) . ")");
            if ($response_size === 0) {
                throw new ExpectationFailedException("response size was 0");
            }
        } catch (\Throwable $e) {
            throw new WebPageNotLoadedException(format("Could not load webpage: {}", $url), $e);
        }

        if (debug_enabled()) {
            $fname = logfile('html/' . md5($url . microtime(true)) . ".html", true);
            if (@file_put_contents($fname, $html_document)) {
                debug("saved response as: {}", $fname);
            }
        }
        return new Document($url, $final_url, $html_document);
    }

    public static function fromCache($id): ?Document
    {
        if (!FS::cache_has($id)) {
            return null;
        }
        list($final_url, $html_document) = explode(PHP_EOL, FS::cache_get($id), 2);
        return new Document($final_url, $final_url, $html_document);
    }

    /**
     * @return string
     */
    public function getOriginalUrl(): string
    {
        return $this->original_url;
    }

    /**
     * @return string
     */
    public function getFinalUrl(): string
    {
        return $this->final_url;
    }

    /**
     * @return string
     */
    public function getContent(): string
    {
        return $this->content;
    }

    /**
     * @return int
     */
    public function getContentLength(): int
    {
        return strlen($this->content);
    }

    /**
     * returns all json/javascript objects/arrays in the document's scripts inside a {@link \Eboubaker\JSON\JSONArray}
     */
    public function getObjects(): JSONArray
    {
        if (isset($this->json)) return $this->json;
        $scripts = (new Crawler($this->content))->filter("script")->each(function (Crawler $node) {
            return $node->text(null, false);
        });
        $result = [];
        $finder = JSONFinder::make(JSONFinder::T_OBJECT | JSONFinder::T_ARRAY | JSONFinder::T_JS);
        foreach ($scripts as $script) {
            foreach ($finder->findEntries($script) as $entry) {
                $result[] = $entry;
            }
        }
        $this->json = new JSONArray($result);
        return $this->json;
    }
}
