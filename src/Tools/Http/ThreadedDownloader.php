<?php

namespace Eboubaker\Scrapper\Tools\Http;

use ArrayAccess;
use Closure;
use Eboubaker\Scrapper\Concerns\ScrapperUtils;
use Eboubaker\Scrapper\Contracts\Downloader;
use Eboubaker\Scrapper\Exception\ExpectationFailedException;
use Eboubaker\Scrapper\Tools\CLI\DownloadIndicator;
use Exception;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Exception\GuzzleException as HttpClientException;
use GuzzleHttp\RequestOptions as ReqOpt;
use parallel\Channel;
use parallel\Events;
use parallel\Events\Event\Type as EventType;
use parallel\Future;
use parallel\Runtime;
use Phar;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use Throwable;
use Tightenco\Collect\Support\Collection;

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
    private array $append_headers = [];

    /**
     * @throws ExpectationFailedException
     * @throws Throwable
     * @throws HttpClientException
     */
    public function __construct(string $resource_url, int $workers_count = 32)
    {
        $this->resource_url = $resource_url;
        $this->workers_count = $workers_count;
        $this->log = make_monolog('ThreadedDownloader');
        $this->resource_size = $this->get_resource_size();
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
                    ] + $this->append_headers,
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
        $chunkSize = (int)($this->resource_size / $this->workers_count);
        $workers_link = Channel::make('workers_link', Channel::Infinite);
        $tracker_link = Channel::make('health_link', Channel::Infinite);
        $workers = new Collection();
        if (running_as_phar()) {
            $vendor_dir = Phar::running(true) . "/vendor/autoload.php";
        } else {
            $vendor_dir = rootpath('/vendor/autoload.php');
        }
        $makeRuntime = fn() => new Runtime($vendor_dir);
        $tracker = $makeRuntime()->run($this->make_tracker_task(), [
            "Tracker",
            $this->workers_count,
            $this->resource_size
        ]);
        for ($i = 0; $i < $this->workers_count; $i++) {
            $downloaded = 0;// TODO: put downloaded parts hash in some directory and read them later to allow resume option
            $start = $i * $chunkSize + $downloaded;
            $end = $i == $this->workers_count - 1 ? $this->resource_size - 1 : $start + $chunkSize - 1;
            $workers[] = $makeRuntime()->run($this->make_worker_task(), [
                $i,
                $this->resource_url,
                $start,
                $end,
                ScrapperUtils::make_curl_headers() + $this->append_headers,
                'workers_link'
            ]);
        }
        do usleep(500_000);
        while ($workers->contains(fn(Future $task) => !$task->done()));
        if (!$tracker->done())
            usleep(2_000_000);
        $tracker_link->send(["message" => "STOP"]);
        usleep(300_000);
        echo "\n";
        if (!$tracker->done()) $this->log->warning("sent stop signal to tracker but it did not stop, this will probably cause an error or cause the main thread to hang");
        $workers_link->close();
        $tracker_link->close();
        $parts = collect($tracker->value())->filter()->sort();
        if ($parts->count() !== $workers->count()) {
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

    private function make_tracker_task(): Closure
    {
        return function ($worker_id, int $workersCount, int $total) {
            $log = make_monolog("Tracker");
            $workers_link = Channel::open('workers_link');
            $tracker_link = Channel::open('health_link');
            $events = new Events();
            $events->addChannel($workers_link);
            $events->addChannel($tracker_link);
            $events->setBlocking(true);
            $events->setTimeout(500_000);
            $running = 0;
            $indicator = new DownloadIndicator($total);
            $parts = [];
            while ($workersCount > 0) {
                try {
                    $event = $events->poll();
                    $events->addChannel($workers_link);
                    if (EventType::Read === $event->type) {
                        $message = (array)$event->value;// force fail if not array
                        if ($message['event'] === 'PROGRESSING') {
                            $indicator->progress($message['data']['downloaded']);
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
                        break;
                    }
                    $indicator->display("($running workers)");
                } catch (Events\Error\Timeout $e) {
                    // nothing
                } catch (Throwable $e) {
                    $log->error($e->getMessage());
                }
            }
            $indicator->clear();
            $log->debug("collected parts: " . json_encode($parts));
            return $parts;
        };
    }

    private function make_worker_task(): Closure
    {
        return function (int $worker_id, string $url, int $start, int $end, $append_headers, $report_channel) {
            $log = make_monolog("worker_$worker_id");
            $workers_link = Channel::open($report_channel);
            $part_path = tempnam(sys_get_temp_dir(), "scr");
            $log->debug("will save to: $part_path");
            $out = fopen($part_path, 'wb+');
            $attempt = 1;
            $total = $end - $start + 1;
            $log->debug("total=$total");
            $client = new HttpClient([
                'timeout' => 10,
                'allow_redirects' => true,
                'verify' => false, // TODO: SSL
            ]);
            $buffer_size = bytes('64kb');
            $total_write = 0;
            start_over:
            try {
                $log->debug("Requesting range " . ($start + $total_write) . "-$end");
                if ($start + $total_write >= $end) {
                    $log->error("Range start is bigger than end", ["start" => $start, "fstat" => $total_write, "end" => $end]);
                    return;
                }
                $response = $client->get($url, [
                    ReqOpt::HEADERS => [
                            "Range" => "bytes=" . ($start + $total_write) . "-$end"
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
                while ($total_write !== $total && !$stream->eof()) {
                    $read = $stream->read($buffer_size);
                    $wrote = fwrite($out, $read);
                    $total_write += $wrote;
                    $workers_link->send([
                        "worker_id" => $worker_id,
                        "event" => "PROGRESSING",
                        "data" => [
                            "downloaded" => $wrote,
                            "total" => $total
                        ],
                    ]);
                }
                if ($total_write !== $total) throw new Exception("unexpected end of input");
                $log->debug("done downloading with $total_write downloaded out of " . $total);
            } catch (Throwable $e) {
                $log->error((new ReflectionClass($e))->getName() . ": " . $e->getMessage(), ["fstat" => fstat($out)['size'], "total" => $total, "total_write" => $total_write]);
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
            $log->info("saved part to $part_path");
            $workers_link->send([
                "worker_id" => $worker_id,
                "event" => "DONE",
                "data" => [
                    "part_path" => $part_path
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

    public function with_headers(array $headers)
    {
        array_push($this->append_headers, $headers);
    }
}
