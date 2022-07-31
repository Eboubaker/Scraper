<?php /** @noinspection PhpMissingReturnTypeInspection */

namespace Eboubaker\Scrapper\Extensions\Guzzle;

use Closure;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * will add a X-GUZZLE-EFFECTIVE-URL header to the response which will represent
 * the final URL of the request if it was redirected
 *
 * @author Thinkscape
 * @see https://gist.github.com/Thinkscape/43499cfafda1af8f606d
 */
class EffectiveUrlMiddleware
{
    /**
     * @var Callable
     */
    protected $nextHandler;

    /**
     * @var string
     */
    protected string $headerName;

    /**
     * @param callable $nextHandler
     * @param string $headerName The header name to use for storing effective url
     */
    public function __construct(
        callable $nextHandler,
        string   $headerName = 'X-GUZZLE-EFFECTIVE-URL'
    )
    {
        $this->nextHandler = $nextHandler;
        $this->headerName = $headerName;
    }

    /**
     * Prepare a middleware closure to be used with HandlerStack
     *
     * @param string $headerName The header name to use for storing effective url
     *
     * @return Closure
     */
    public static function middleware(string $headerName = 'X-GUZZLE-EFFECTIVE-URL')
    {
        return function (callable $handler) use (&$headerName) {
            return new static($handler, $headerName);
        };
    }

    /**
     * Inject effective-url header into response.
     *
     * @param RequestInterface $request
     * @param array $options
     *
     * @return RequestInterface
     */
    public function __invoke(RequestInterface $request, array $options)
    {
        $fn = $this->nextHandler;

        return $fn($request, $options)->then(function (ResponseInterface $response) use ($request, $options) {
            return $response->withAddedHeader($this->headerName, $request->getUri()->__toString());
        });
    }
}
