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
        $log = make_monolog('UnitTest::testProxies');
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
        $j = file_get_contents("https://www.proxy-list.download/api/v1/get?type=https");
        $proxy_list = preg_split("/\r?\n/", $j);
        $log->info("Proxies count: " . count($proxy_list));
        foreach (["167.172.109.12:39452", ...$proxy_list] as $p_url) {
            $ch = curl_init("https://www.google.com/");
            curl_setopt($ch, CURLOPT_PROXY, $p_url);
            curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_HTTPS);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_TIMEOUT, 3);
            curl_exec($ch);
            if (!curl_errno($ch)) {
                $log->info("result", [
                    'proxy' => $p_url,
                    "response_size" => curl_getinfo($ch, CURLINFO_SIZE_DOWNLOAD_T),
                    "final_url" => curl_getinfo($ch, CURLINFO_EFFECTIVE_URL),
                    "response_time" => curl_getinfo($ch, CURLINFO_TOTAL_TIME_T) / 1000.0
                ]);
            } else {
                $log->error(curl_error($ch));
            }
            curl_close($ch);
        }
        self::assertTrue(true);
    }
}
