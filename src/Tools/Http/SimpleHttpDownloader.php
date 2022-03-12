<?php

namespace Eboubaker\Scrapper\Tools\Http;

use Eboubaker\Scrapper\Concerns\ScrapperUtils;
use Eboubaker\Scrapper\Contracts\Downloader;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\RequestOptions as ReqOpt;

/**
 * Downloads a resource with a single http request
 * @author Eboubaker Bekkouche <eboubakkar@gmail.com>
 */
final class SimpleHttpDownloader implements Downloader
{
    private string $resource_url;

    public function __construct(string $resource_url)
    {
        $this->resource_url = $resource_url;
    }

    /**
     * @inheritDoc
     */
    public function saveto(string $file_name): string
    {
        $client = new HttpClient([
            'timeout' => 10,
            'allow_redirects' => true,
            'verify' => false, // TODO: SSL
        ]);
        $buffer_size = bytes('64kb');
        $response = $client->get($this->get_resource_url(), [
            ReqOpt::HEADERS => ScrapperUtils::make_curl_headers(),
            ReqOpt::STREAM => true,
            'curl' => [
                CURLOPT_BUFFERSIZE => $buffer_size
            ]
        ]);
        $name = tempnam(sys_get_temp_dir(), "scr");
        $out = fopen($name, 'ab+');
        $stream = $response->getBody();
        while (!$stream->eof()) {
            $read = $stream->read($buffer_size);
            fwrite($out, $read);
        }
        fclose($out);
        rename($name, $file_name);
        return $file_name;
    }

    function get_resource_url(): string
    {
        return $this->resource_url;
    }
}
