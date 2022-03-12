<?php

namespace Eboubaker\Scrapper\Tools\Http;

use Eboubaker\Scrapper\Concerns\ScrapperUtils;
use Eboubaker\Scrapper\Exception\ExpectationFailedException;
use Eboubaker\Scrapper\Exception\WebPageNotLoadedException;
use Eboubaker\Scrapper\Extensions\Guzzle\EffectiveUrlMiddleware;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\RequestOptions as ReqOpt;
use Symfony\Component\DomCrawler\Crawler;

class Document
{
    private string $original_url;
    private string $final_url;
    private string $content;
    private array $data_bag;

    private function __construct(string $url, string $final_url, string $content)
    {
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
                ReqOpt::PROGRESS => fn($downloadTotal, $downloadedBytes) => printf(TTY_FLUSH . "       " . human_readable_size($downloadedBytes) . "/" . ($downloadTotal ? human_readable_size($downloadTotal) : " ?B"))
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
            $log = logfile('cached_responses/' . md5($url) . ".html", true);
            if (@file_put_contents($log, $html_document)) {
                debug("saved response as: {}", $log);
            } else {
                debug("failed to save response as: {}", $log);
            }
        }

        return new Document($url, $final_url, $html_document);
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


    public function getDataBag(): array
    {
        if (isset($this->data_bag)) return $this->data_bag;
        $this->data_bag = $this->collect_all_json($this->content);
        return $this->data_bag;
    }

    /**
     * find all json objects/arrays in an html document's scripts.
     *
     * @param string $html
     * @return array an associative array of all found objects(nested objects ar also associative arrays)
     * @author Eboubaker Bekkouche <eboubakkar@gmail.com>
     */
    private function collect_all_json(string $html): array
    {
        $regex_valid_json = <<<'EOF'
        /
        (?(DEFINE)
         (?<number>   -? (?= [1-9]|0(?!\d) ) \d+ (\.\d+)? ([eE] [+-]? \d+)? )
         (?<boolean>   true | false | null )
         (?<string>    " ([^"\n\r\t\\\\]* | \\ ["\\\\bfnrt\/] | \\ u [0-9a-f]{4} )* " )
         (?<array>     \[  (?:  (?&json)  (?: , (?&json)  )*  )?  \s* \] )
         (?<pair>      \s* (?&string) \s* : (?&json)  )
         (?<object>    \{  (?:  (?&pair)  (?: , (?&pair)  )*  )?  \s* \} )
         (?<json>   \s* (?: (?&number) | (?&boolean) | (?&string) | (?&array) | (?&object) ) \s* )
         (?<realobject>    \{  (?:  (?&pair)  (?: , (?&pair)  )*  )  \s* \} )
         (?<realarray>     \[  (?:  (?&json)  (?: , (?&json)  )*  )  \s* \] )
         (?<realjson>   \s* (?: (?&realarray) | (?&realobject) ) \s* )
        )
        (?&realjson)
        /six
        EOF;

        return collect((new Crawler($html))
            // find script tags
            ->filter("script")
            // find all json inside the scripts
            ->each(function (Crawler $node) use ($regex_valid_json) {
                if (isset($F)) unset($F);
                preg_match_all($regex_valid_json, $node->text(null, false), $F, PREG_UNMATCHED_AS_NULL | PREG_SPLIT_NO_EMPTY);
                if (preg_last_error() !== PREG_NO_ERROR) error(preg_last_error_msg());
                return $F;
            }))
            ->flatten()
            // remove preg_match empty groups garbage
            ->filter(fn($j) => $j && strlen($j))
            ->map(fn($obj) => json_decode($obj, true))
            ->filter(function (array $arr) {
                // keep arrays that contains at least one string key
                return collect($arr)->filter(fn($v, $k) => is_string($k))
                    ->count();
            })
            ->values()
            ->all();
    }
}
