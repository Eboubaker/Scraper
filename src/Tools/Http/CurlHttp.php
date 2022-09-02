<?php declare(strict_types=1);

namespace Eboubaker\Scraper\Tools\Http;

use Eboubaker\Scraper\App;
use Eboubaker\Scraper\Exception\InvalidArgumentException;
use Eboubaker\Scraper\Tools\Cache\Memory;

/**
 * @author Eboubaker Bekkouche <eboubakkar@gmail.com>
 */
class CurlHttp
{
    /**
     * @throws InvalidArgumentException
     */
    public static function make_curl_headers(): array
    {
        return Memory::remember('app_curl_headers', function () {
            $headers = [
                "accept-encoding" => "gzip, deflate",
                "accept" => "text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.9",
                "accept-language" => "en-US,en;q=0.9",
                "sec-ch-ua" => "\" Not A;Brand\";v=\"99\", \"Chromium\";v=\"99\", \"Microsoft Edge\";v=\"99\"",
                "sec-ch-ua-mobile" => "?0",
                "sec-ch-ua-platform" => "\"Windows\"",
                "sec-fetch-dest" => "document",
                "sec-fetch-mode" => "navigate",
                "sec-fetch-site" => "none",
                "sec-fetch-user" => "?1",
                "upgrade-insecure-requests" => "1"
            ];
            $cli_headers = App::bootstrapped() ? App::args()->getOpt('header', []) : [];
            if (!($user_agent = data_get($cli_headers, array_search_match($cli_headers, [
                    null => "/User-Agent\s*?:\s*?.+/i"
                ]) ?? ""))) {
                $headers["user-agent"] = "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/99.0.4844.51 Safari/537.36 Edg/99.0.1150.36";
            } else {
                $headers['user-agent'] = $user_agent;
            }
            foreach ($cli_headers as $cli_header) {
                if (preg_match("/^(.+?):\s*?(.+)$/", $cli_header, $matches)) {
                    $key = $matches[1];
                    $value = $matches[2];
                    $headers[$key] = $value;
                }
            }
            return $headers;
        });
    }
}
