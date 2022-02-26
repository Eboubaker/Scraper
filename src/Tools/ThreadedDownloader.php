<?php

namespace Eboubaker\Scrapper\Tools;

use ArrayAccess;
use Closure;
use Eboubaker\Scrapper\Concerns\ScrapperUtils;
use Eboubaker\Scrapper\Contracts\Downloader;
use Eboubaker\Scrapper\Exception\ExpectationFailedException;
use Exception;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Exception\GuzzleException as HttpClientException;
use GuzzleHttp\RequestOptions as ReqOpt;
use parallel\Channel;
use parallel\Events;
use parallel\Events\Event\Type as EventType;
use parallel\Future;
use parallel\Runtime;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * fast download a resource url by chunking it to multiple parts for multiple threads
 * @author Eboubaker Bekkouche <eboubakkar@gmail.com>
 */
final class ThreadedDownloader implements Downloader
{
    private string $resource_url;
    private int $workers_count;
    private int $resource_size;
    private LoggerInterface $log;

    /**
     * @throws ExpectationFailedException
     * @throws Throwable
     * @throws HttpClientException
     */
    public function __construct(string $resource_url, int $workers_count = 8)
    {
        $this->resource_url = $resource_url;
        $this->workers_count = $workers_count;
        $this->resource_size = $this->get_resource_size();
        $this->log = make_monolog('ThreadedDownloader');
    }

    /**
     * @throws ExpectationFailedException
     * @throws Throwable
     * @throws HttpClientException
     */
    private function get_resource_size(): int
    {
        try {
            $client = new HttpClient([
                'timeout' => 8,
                'allow_redirects' => true,
                'verify' => false, // TODO: SSL
            ]);
            $response = $client->get($this->resource_url, [
                ReqOpt::HEADERS => ScrapperUtils::make_curl_headers() + [
                        "Range" => "bytes=10-20"
                    ],
                ReqOpt::STREAM => true,
            ]);
            if ((int)data_get($response->getHeader('Content-Length'), 0) !== 11) {
                throw new ExpectationFailedException("resource url does not support chunking");
            } else {
                $response = $client->get($this->resource_url, [
                    ReqOpt::HEADERS => ScrapperUtils::make_curl_headers(),
                    ReqOpt::STREAM => true,
                ]);
                $total = (int)data_get($response->getHeader('Content-Length'), 0);
                if (!$total) {
                    throw new ExpectationFailedException("could not determine resource size");
                } else {
                    return $total;
                }
            }
        } catch (Throwable $e) {
            $this->log->error($e->getMessage(), ["function" => __FUNCTION__]);
            throw $e;
        }
    }

    /**
     * @throws Throwable
     */
    public function saveto(string $file_name): string
    {
        $totalDownloaded = 0;
        $chunkSize = (int)($this->resource_size / $this->workers_count);
        $workers_link = Channel::make('workers_link', Channel::Infinite);
        $tracker_link = Channel::make('health_link', Channel::Infinite);
        $tasks = collect([]);
        $makeRuntime = fn() => new Runtime(rootpath('/vendor/autoload.php'));
        info("using $this->workers_count workers");
        $tracker = $makeRuntime()->run($this->make_tracker_func(), [
            "Tracker",
            $this->workers_count,
            $this->resource_size
        ]);
        for ($i = 0; $i < $this->workers_count; $i++) {
            $downloaded = 0;// TODO: put downloaded parts hash in some directory and read them later to allow resume option
            $totalDownloaded += $downloaded;
            $start = $i * $chunkSize;
            $end = $i == $this->workers_count - 1 ? $this->resource_size - 1 : $start + $chunkSize - 1;
            $tasks[] = $makeRuntime()->run($this->make_worker_func(), [
                $i,
                $this->resource_url,
                $start,
                $end,
                ScrapperUtils::make_curl_headers(),
                'workers_link'
            ]);
        }
        do {
            usleep(500_000);
        } while ($tasks->contains(fn(Future $future) => !$future->done()));
        if (!$tracker->done())
            usleep(2_000_000);
        $tracker_link->send(["message" => "STOP"]);
        usleep(300_000);
        $workers_link->close();
        $parts = collect($tracker->value())->filter()->sort();
        if ($parts->count() !== $tasks->count()) {
            throw new ExpectationFailedException("not all file parts finished downloading");
        }
        $this->files_merge($parts, $file_name);
        foreach ($parts as $part) {
            if (!@unlink($part)) {
                $this->log->warning("temporary file not removed: $part", ['function' => __FUNCTION__]);
            }
        }
        return $file_name;
    }

    private function make_tracker_func(): Closure
    {
        return function ($worker_id, int $workersCount, int $total) {
            $log = make_monolog("tracker_$worker_id");
            $workers_link = Channel::open('workers_link');
            $tracker_link = Channel::open('health_link');
            $events = new Events();
            $events->addChannel($workers_link);
            $events->addChannel($tracker_link);
            $events->setBlocking(true);
            $events->setTimeout(500_000);
            $downloaded = 0;
            $max_snake_len = 50;
            $last_downloaded = 0;
            $last_show = 0;
            $flick_timeout = 2;//seconds
            $running = 0;
            $delayed_progress = function ($downloaded, $running) use (&$last_show, &$last_downloaded, $flick_timeout, $max_snake_len, $total, $log) {
                if (microtime(true) > $last_show + $flick_timeout || $total === $downloaded) {
                    $log->debug("downloaded: $downloaded, total: $total");
                    $perc_100 = (int)(100.0 * $downloaded / $total);
                    $snake_len = (int)(1.0 * $max_snake_len * $downloaded / $total);
                    fwrite(STDOUT, sprintf(TTY_FLUSH . "[%s] %d%% %s/%s (%s/s) (%s workers)" . (!stream_isatty(STDOUT) ? PHP_EOL : '')
                        , str_pad(style(str_repeat('=', $snake_len), 'green,bold') . ">", $max_snake_len, "-", STR_PAD_RIGHT)
                        , $perc_100
                        , human_readable_size($downloaded)
                        , human_readable_size($total)
                        , human_readable_size(($downloaded - $last_downloaded) / (microtime(true) - $last_show))
                        , $running));
                    $last_downloaded = $downloaded;
                    $last_show = microtime(true);
                }
            };
            $parts = [];
            while ($workersCount > 0) {
                try {
                    $event = $events->poll();
                    $events->addChannel($workers_link);
                    if (EventType::Read === $event->type) {
                        $message = (array)$event->value;// force fail if not array
                        if ($message['event'] === 'PROGRESSING') {
                            $downloaded += $message['data']['downloaded'];
                            $delayed_progress($downloaded, $running);
                        } else if ($message['event'] === 'STRUGGLING') $running--;
                        else if ($message['event'] === 'MAKING_NEW_ATTEMPT' || $message['event'] === 'STARTING') $running++;
                        else if ($message['event'] === 'DONE') {
                            $log->debug("got event done: " . json_encode($message));
                            $workersCount--;
                            $running--;
                            $parts[$message['worker_id']] = $message['data']['part_path'];
                        } else if ($message['event'] === 'STOP') break;// message from master thread
                    } elseif (EventType::Close === $event->type) {
                        $log->debug("got close event type");
                        return null;
                    }
                } catch (Events\Error\Timeout $e) {
                    // nothing
                } catch (Throwable $e) {
                    $log->error($e->getMessage());
                }
            }
            $log->debug("found parts: " . json_encode($parts));
            return $parts;
        };
    }

    private function make_worker_func(): Closure
    {
        return function (int $worker_id, string $url, int $start, int $end, $append_headers, $report_channel) {
            $log = make_monolog("worker_$worker_id");
            $workers_link = Channel::open($report_channel);
            $name = tempnam(sys_get_temp_dir(), time() . ".scrapper");
            $out = fopen($name, 'ab+');
            $attempt = 1;
            $total = $end - $start + 1;
            $log->debug("total=$total");
            $old_downloaded = 0;
            $on_progress = function () use ($total, $out, $workers_link, $worker_id, &$old_downloaded) {
                $downloaded = fstat($out)['size'];
                $workers_link->send([
                    "worker_id" => $worker_id,
                    "event" => "PROGRESSING",
                    "data" => [
                        "downloaded" => $downloaded - $old_downloaded,
                        "total" => $total
                    ],
                ]);
                $old_downloaded = $downloaded;
            };
            $last_show = 0;
            $flick_timeout = 2;//seconds
            $delayed_progress = function () use (&$last_show, $flick_timeout, $on_progress) {
                if (microtime(true) > $last_show + $flick_timeout) {
                    $on_progress();
                    $last_show = microtime(true);
                }
            };
            $client = new HttpClient([
                'timeout' => 10,
                'allow_redirects' => true,
                'verify' => false, // TODO: SSL
            ]);
            $buffer_size = bytes('64kb');
            start_over:
            try {
                $response = $client->get($url, [
                    ReqOpt::HEADERS => [
                            "Range" => "bytes=" . ($start + fstat($out)['size']) . "-$end"
                        ] + $append_headers,
                    ReqOpt::STREAM => true,
                    'curl' => [
                        CURLOPT_BUFFERSIZE => $buffer_size
                    ]
                ]);
                if ($attempt === 1) {
                    $workers_link->send([
                        "worker_id" => $worker_id,
                        "event" => "STARTING",
                        "data" => [],
                    ]);
                }
                $log->debug("response code:" . $response->getStatusCode());
                $log->debug("content-length:" . data_get($response->getHeader('Content-Length'), 0, 0));
                $stream = $response->getBody();
                while (!$stream->eof()) {
                    $read = $stream->read($buffer_size);
                    fwrite($out, $read);
                    $delayed_progress();
                }
                $log->debug("done downloading with " . fstat($out)['size'] . " downloaded out of " . $total);
                if (fstat($out)['size'] !== $total)
                    throw new Exception("unexpected end of input");
            } catch (Throwable $e) {
                $log->error($e->getMessage());
                $workers_link->send([
                    "worker_id" => $worker_id,
                    "event" => "STRUGGLING",
                    "data" => [
                        "error" => $e->getMessage()
                    ]
                ]);
                usleep(random_int(1_500_000, 3_000_000));
                $workers_link->send([
                    "worker_id" => $worker_id,
                    "event" => "MAKING_NEW_ATTEMPT",
                    "data" => [
                        "number" => ++$attempt
                    ],
                ]);
                goto start_over;
            }
            fclose($out);
            $log->info("saved part to $name");
            $workers_link->send([
                "worker_id" => $worker_id,
                "event" => "DONE",
                "data" => [
                    "part_path" => $name
                ],
            ]);
        };
    }

    private function files_merge(ArrayAccess $files, string $file_name)
    {
        $dest = fopen($file_name, "w+b");
        $buffer = bytes('64kb');
        foreach ($files as $f) {
            $FH = fopen($f, "r+");
            $size = fstat($FH)['size'];
            while ($size > 0) {
                $read = fread($FH, $buffer);
                fwrite($dest, $read);
                $size -= strlen($read);
            }
            fclose($FH);
        }
        fclose($dest);
    }

    function get_resource_url(): string
    {
        return $this->resource_url;
    }
}