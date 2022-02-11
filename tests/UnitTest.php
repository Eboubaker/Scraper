<?php declare(strict_types=1);
ini_set('display_errors', "1");
ini_set('display_startup_errors', "1");
error_reporting(E_ALL);

require_once "../vendor/autoload.php";

use PHPUnit\Framework\TestCase;

final class UnitTest extends TestCase
{
    public function testProxies()
    {
        // https://api.proxyscrape.com/?request=displayproxies&proxytype=socks4&country=all
        // https://www.proxy-list.download/api/v1/get?type=socks4
        // https://www.proxyscan.io/download?type=socks4
        // https://raw.githubusercontent.com/TheSpeedX/PROXY-List/master/socks4.txt
        // (should parse html table) https://www.socks-proxy.net

        // https://api.proxyscrape.com/v2/?request=getproxies&protocol=socks5&timeout=10000&country=all&simplified=true
        // https://www.proxy-list.download/api/v1/get?type=socks5
        // https://www.proxyscan.io/download?type=socks5
        // https://raw.githubusercontent.com/TheSpeedX/PROXY-List/master/socks5
        // https://raw.githubusercontent.com/hookzof/socks5_list/master/proxy.txt
        $j = file_get_contents("https://api.proxyscrape.com/v2/?request=getproxies&protocol=socks5&timeout=10000&country=all&simplified=true");
        $proxy_list = preg_split("/\r?\n/", $j);
        $proxy_list = array_slice($proxy_list, 0, 20);
        $result = ["worked" => [], "Total" => count($proxy_list)];
        foreach ($proxy_list as $url) {
            $ch = curl_init("https://www.google.com/");
            curl_setopt($ch, CURLOPT_PROXY, $url);
            curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS5);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_exec($ch);
            if (!curl_errno($ch)) {
                $result["worked"][$url] = [
                    "response_size" => curl_getinfo($ch, CURLINFO_SIZE_DOWNLOAD_T),
                    "final_url" => curl_getinfo($ch, CURLINFO_EFFECTIVE_URL),
                    "response_time" => curl_getinfo($ch, CURLINFO_TOTAL_TIME_T) / 1000.0
                ];
            }
            curl_close($ch);
        }
        $result["worked_count"] = count($result["worked"]);
        flog(json_encode($result));
        self::assertTrue(true);
    }
}
