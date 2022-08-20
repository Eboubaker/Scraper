<?php

namespace Eboubaker\Scraper\Tools\Http;

use Closure;
use Eboubaker\Scraper\App;
use Eboubaker\Scraper\Concerns\WritesLogs;
use Eboubaker\Scraper\Exception\ExpectationFailedException;
use Eboubaker\Scraper\Exception\FileSystemException;
use Eboubaker\Scraper\Scrapers\Shared\ScraperUtils;
use Eboubaker\Scraper\Tools\Cache\FS;
use Eboubaker\Scraper\Tools\CLI\DownloadIndicator;
use Exception;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\RequestOptions as ReqOpt;
use parallel\Channel;
use parallel\Events;
use parallel\Events\Event\Type as EventType;
use parallel\Future;
use parallel\Runtime;
use Phar;
use ReflectionClass;
use Throwable;
use Tightenco\Collect\Support\Collection;

/**
 * fast download a resource url by chunking it to multiple parts for multiple threads.
 * This will allow us to avoid the rate limiting that many sites force.
 * each thread has an independent connection it's connection will be limited but the sum of all the other threads will make the download process faster
 * @author Eboubaker Bekkouche <eboubakkar@gmail.com>
 */
final class ThreadedDownloader
{
    use WritesLogs;

    private string $resource_url;
    private string $resourceId;
    private int $workers_count;
    private int $resource_size;
    private array $append_headers = [];
    private bool $validated = false;


    /**
     * @param string $resource_url resource to download
     * @param string $resourceId the unique identifier of the resource, will be used as a key to resume the download if previously interrupted
     * @return ThreadedDownloader a new instance with 32 workers
     */
    public static function for(string $resource_url, string $resourceId): ThreadedDownloader
    {
        $instance = new ThreadedDownloader();
        $instance->workers_count = 32;
        $instance->resource_url = $resource_url;
        $instance->resourceId = $resourceId;
        return $instance;
    }

    /**
     * @throws Exception if count is equal or less than 0
     */
    public function setWorkers(int $count): self
    {
        if ($count <= 0) throw new Exception("workers count cannot be less than 0");
        $this->workers_count = $count;
        return $this;
    }

    /**
     * will probe the resource by sending a head request
     * @throws ExpectationFailedException if the response content_length was 0 or if the response does not support resume
     * @throws Throwable if an http error occurred during the validation request
     */
    public function validate(): self
    {
        try {
            $client = new HttpClient([
                'timeout' => 60,
                'allow_redirects' => true,
                'verify' => false, // TODO: SSL
            ]);
            $response = $client->get($this->resource_url, [
                ReqOpt::HEADERS => ScraperUtils::make_curl_headers() + [
                        "Range" => "bytes=10-20"
                    ] + $this->append_headers,
                ReqOpt::STREAM => true,
            ]);
            if ((int)data_get($response->getHeader('Content-Length'), 0) !== 11) {
                throw new ExpectationFailedException("resource url does not support chunking");
            } else {
                $response = $client->get($this->resource_url, [
                    ReqOpt::HEADERS => ScraperUtils::make_curl_headers(),
                    ReqOpt::STREAM => true,
                ]);
                $total = (int)data_get($response->getHeader('Content-Length'), 0);
                if (!$total) {
                    throw new ExpectationFailedException("could not determine resource size");
                } else {
                    $this->resource_size = $total;
                }
            }
        } catch (Throwable $e) {
            $this->log->error($e->getMessage(), ["function" => __FUNCTION__]);
            throw $e;
        }
        $this->validated = true;
        return $this;
    }

    /**
     * saves the resource to the provided path
     * @return string file_name parameter
     * @throws Throwable
     */
    public function saveto(string $file_name): string
    {
        /**
         * TODO: at the end of the download there is always 1 or 2 workers fall behind and they will make the download too slow at the end...
         * possible fix: if a worker completes the part task, then it should help other workers by splitting the remaining range between them
         * another option is to limit the downloaded size say 50MB, and each time all workers complete a chunk of 50MB, go to the next chunk
         *
         * TODO: if the resource size is too big (say 10GB) the tokens for the resource will probably expire at some point and the download will stuck in the middle, the workers will keep trying but they will always fail because the tokens have expired,
         * possible fix: add a "revalidator" parameter which should be a closure that will get a new fresh url and if the master thread detects that the url has expired it will notify the workers with a fresh url by calling the closure to get a new url
         * helpful note: many sites will put the "expires" timestamp in the url itself we can read that value and check if it is in the past, the master thread will do that check everytime a worker fails
         */
        if (!$this->validated) throw new Exception("validate the resource before saving");
        $chunkSize = (int)($this->resource_size / $this->workers_count);
        $workers_link = Channel::make('workers_link', Channel::Infinite);
        $tracker_link = Channel::make('health_link', Channel::Infinite);
        /**
         * @var Future[]|Collection $workers
         */
        $workers = new Collection();
        if (running_as_phar()) {
            $vendor_dir = Phar::running(true) . "/vendor/autoload.php";
        } else {
            $vendor_dir = rootpath('/vendor/autoload.php');
        }
        $makeRuntime = fn() => new Runtime($vendor_dir);
        {// allocate the required space for the output or fail
            if (!wrap_warnings(function () use ($file_name) {
                $res = fopen($file_name, 'w+b');
                $success = true;
                $success &= 0 === fseek($res, $this->resource_size + $this->resource_size / $this->workers_count - 1, SEEK_CUR);
                $success &= fwrite($res, 'e', 1);
                $success &= fclose($res);
                return $success;
            })) {
                @unlink($file_name);
                throw new FileSystemException("could not create temporary output file $file_name");
            }
        }
        @unlink($file_name);
        $tracker = $makeRuntime()->run($this->make_tracker_task(), [
            "Tracker",
            $this->workers_count,
            $this->resource_size
        ]);
        App::terminating($trackerShutdown = function () use ($tracker) {
            if (isset($tracker) && !$tracker->done()) {
                $this->log->debug("post-termination: forcefully terminating tracker");
                $tracker->cancel();
            }
        });
        $part_locations_cache_key = implode('.', array(__CLASS__, $this->resourceId, $this->workers_count, $this->resource_size));
        $parts = FS::cache_get($part_locations_cache_key, []);
        if (empty($parts)) {
            for ($i = 0; $i < $this->workers_count; $i++) {
                $parts[] = tempnam(sys_get_temp_dir(), 'scr');
            }
            FS::cache_set($part_locations_cache_key, $parts);
        } else {
            notice("Resuming download from previous attempt found in cache.");
        }
        App::terminating($workersShutdown = function () use ($workers) {
            if (isset($workers) && is_array($workers)) {
                foreach ($workers as $worker) {
                    if (!$worker->done()) {
                        $this->log->debug("post-termination: forcefully terminating worker");
                        $worker->cancel();
                    }
                }
            }
        });
        for ($i = 0; $i < $this->workers_count; $i++) {
            $downloaded = 0;
            $start = $i * $chunkSize + $downloaded;
            $end = $i == $this->workers_count - 1 ? $this->resource_size - 1 : $start + $chunkSize - 1;
            $workers[] = $makeRuntime()->run($this->make_worker_task(), [
                $i,
                $this->resource_url,
                $start,
                $end,
                ScraperUtils::make_curl_headers() + $this->append_headers,
                'workers_link',
                $parts[$i],
            ]);

        }
        // TODO: remove this busy waiting
        do usleep(500_000);
        while ($workers->contains(fn(Future $task) => !$task->done()));
        if (!$tracker->done())
            usleep(2_000_000);
        $tracker_link->send(["message" => "STOPURSELF"]);
        usleep(300_000);
        echo "\n";
        if (!$tracker->done()) $this->log->warning("sent stop signal to tracker but it did not stop, this will probably cause an error or cause the main thread to hang");
        $workers_link->close();
        $tracker_link->close();
        if ($tracker->value() !== $workers->count()) {
            if (file_exists($file_name)) @unlink($file_name);
            throw new ExpectationFailedException("not all file parts finished downloading");
        }
        $this->files_merge($parts, $file_name);
        App::onSuccessfulTermination(function () use ($part_locations_cache_key, $parts) {
            $this->log->debug("post-termination: removing cache entry for $part_locations_cache_key");
            FS::cache_forget($part_locations_cache_key);
            foreach ($parts as $part) {
                $this->log->debug("post-termination: removing part $part");
                if (!@unlink($part)) {
                    $this->log->warning(
                        "temporary file not removed: $part",
                        ['function' => __FUNCTION__]
                    );
                }
            }
        });
        App::terminatingUnregister($workersShutdown, $trackerShutdown);
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
            $doneCount = 0;
            while ($workersCount > 0) {
                try {
                    $event = $events->poll();
                    $events->addChannel($workers_link);
                    if (EventType::Read === $event->type) {
                        $message = (array)$event->value;// force fail if not array
                        if ($message['event'] !== 'PROGRESSING') $log->debug(json_encode($message));
                        if ($message['event'] === 'PROGRESSING') $indicator->progress($message['data']['downloaded']);
                        else if ($message['event'] === 'STRUGGLING') $running--;
                        else if ($message['event'] === 'STARTING') $running++;
                        else if ($message['event'] === 'DONE') {
                            $doneCount++;
                            $workersCount--;
                            $running--;
                        } else if ($message['event'] === 'FAILED') {
                            $workersCount--;
                            $running--;
                        } else if ($message['event'] === 'STOPURSELF') break;// message from master thread
                    } elseif (EventType::Close === $event->type) {
                        $log->debug("got close event type");
                        break;
                    }
                } catch (Events\Error\Timeout $e) {
                    // just poll timeout
                } catch (Throwable $e) {
                    $log->error($e->getMessage());
                }
                $indicator->display("($running workers)");
            }
            $indicator->clear();
            $log->debug("collected parts: " . json_encode($doneCount));
            return $doneCount;
        };
    }

    private function make_worker_task(): Closure
    {
        return function (int $worker_id, string $url, int $start, int $end, $append_headers, $report_channel, $part_path) {
            $log = make_monolog("worker_$worker_id");
            $workers_link = Channel::open($report_channel);
            if (!($out = fopen($part_path, 'a+b'))) {
                $log->error("could not open $part_path for writing");
                goto fail;
            }
            $total = $end - $start + 1;
            $log->debug("total=$total");
            $client = new HttpClient([
                'timeout' => 10,
                'allow_redirects' => true,
                'verify' => false, // TODO: SSL
            ]);
            $buffer_size = bytes('64kb');
            $total_wrote = fstat($out)['size'];// get downloaded size if previously interrupted, otherwise 0
            if ($total_wrote != 0) {// resume the download, update the progress
                $workers_link->send([
                    "worker_id" => $worker_id,
                    "event" => "PROGRESSING",
                    "data" => [
                        "downloaded" => $total_wrote,
                    ],
                ]);
            }
            start_over:
            try {
                $workers_link->send([
                    "worker_id" => $worker_id,
                    "event" => "STARTING",
                    "data" => [],
                ]);
                if ($total_wrote === $total) {
                    $log->debug("already downloaded $total_wrote bytes of $total bytes");
                    goto done;
                }
                if ($start + $total_wrote >= $end) {
                    $log->error("Range start is bigger than end", ["start" => $start, "total_write" => $total_wrote, "end" => $end]);
                    goto fail;
                }
                $log->debug("Requesting range " . ($start + $total_wrote) . "-$end");
                $response = $client->get($url, [
                    ReqOpt::HEADERS => [
                            "Range" => "bytes=" . ($start + $total_wrote) . "-$end"
                        ] + $append_headers,
                    ReqOpt::STREAM => true,
                    'curl' => [
                        CURLOPT_BUFFERSIZE => $buffer_size
                    ]
                ]);
                $log->debug("response code:" . $response->getStatusCode());
                $log->debug("content-length:" . data_get($response->getHeader('Content-Length'), 0, 0));
                $stream = $response->getBody();
                while ($total_wrote !== $total && !$stream->eof()) {
                    $wrote = fwrite($out, $stream->read($buffer_size));
                    $total_wrote += $wrote;
                    $workers_link->send([
                        "worker_id" => $worker_id,
                        "event" => "PROGRESSING",
                        "data" => [
                            "downloaded" => $wrote,
                        ],
                    ]);
                }
                if ($total_wrote !== $total) throw new Exception("unexpected end of input");
                $log->debug("done downloading with $total_wrote downloaded out of " . $total);
            } catch (Throwable $e) {
                $log->error((new ReflectionClass($e))->getName() . ": " . $e->getMessage(), ["total" => $total, "total_write" => $total_wrote]);
                $workers_link->send([
                    "worker_id" => $worker_id,
                    "event" => "STRUGGLING",
                    "data" => [
                        "error" => $e->getMessage()
                    ]
                ]);
                usleep(random_int(1_500_000, 3_000_000));
                goto start_over;
            }
            done:
            fclose($out);
            $workers_link->send([
                "worker_id" => $worker_id,
                "event" => "DONE",
            ]);
            return;
            fail:
            $workers_link->send([
                "worker_id" => $worker_id,
                "event" => "FAILED",
            ]);
            if (file_exists($part_path)) @unlink($part_path);
        };
    }

    private function files_merge(array $files, string $file_name)
    {
        $dest = fopen($file_name, "w+b");
        $buffer_size = bytes('8kb');
        foreach ($files as $f) {
            $FH = fopen($f, "r+");
            $size = fstat($FH)['size'];
            while ($size > 0) {
                $read = fread($FH, $buffer_size);
                fwrite($dest, $read);
                $size -= strlen($read);
            }
            fclose($FH);
        }
        fclose($dest);
    }

    public function with_headers(array $headers): self
    {
        array_push($this->append_headers, $headers);
        return $this;
    }
}
