<?php

namespace Eboubaker\Scrapper\Tools\Http;

use Eboubaker\Scrapper\Concerns\WritesLogs;
use Eboubaker\Scrapper\Exception\ExpectationFailedException;
use Eboubaker\Scrapper\Exception\WebPageNotLoadedException;
use Eboubaker\Scrapper\Extensions\Guzzle\EffectiveUrlMiddleware;
use Eboubaker\Scrapper\Scrappers\Shared\ScrapperUtils;
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
    private array $data_bag;

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
        $cached = logfile('cached_responses/' . md5($url) . ".html", true);
        if (debug_enabled() && file_exists($cached) && 0) {
            debug("loading cached response");
            try {
                list($final_url, $html_document) = explode(PHP_EOL, file_get_contents($cached), 2);
            } catch (\Throwable $e) {
                debug("ERROR: loading cached response failed");
                goto never_mind_request_it;
            }
        } else {
            never_mind_request_it:
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
                if (@file_put_contents($cached, $final_url . PHP_EOL . $html_document)) {
                    debug("saved response as: {}", $cached);
                } else {
                    debug("failed to save response as: {}", $cached);
                }
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
        info("Parsing response");
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
        // TODO: this fails on the array part sometimes: https://regex101.com/r/Jj0bRX
        // i probably have to parse without regex
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
            ->filter("script")
            ->each(fn(Crawler $node) => $node->text(null, false)))
            ->map(function ($js) use ($regex_valid_json) {
                preg_match_all($regex_valid_json, $js, $matches, PREG_UNMATCHED_AS_NULL | PREG_SPLIT_NO_EMPTY);
                if (preg_last_error() !== PREG_NO_ERROR) warn("PCRE: {}", preg_last_error_msg());
                return $matches;
            })
            ->flatten()
            // remove preg_match empty groups garbage
            ->filter(fn($j) => $j && strlen($j))
            ->map(fn($obj) => json_decode($obj, true))
            ->filter(function (array $arr) {// TODO: is this required
                // keep arrays that contains at least one string key
                return collect($arr)->filter(fn($v, $k) => is_string($k))
                    ->count();
            })
            ->values()
            ->all();
    }
}
