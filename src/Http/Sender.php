<?php

declare(strict_types=1);

namespace PingRoom\Http;

use Http\Discovery\Exception\NotFoundException;
use Http\Discovery\Psr17FactoryDiscovery;
use PingRoom\Exception\NetworkException;
use PingRoom\Exception\PingRoomException;
use PingRoom\Exception\TimeoutException;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Sends one PSR-7 request through a PSR-18 client and applies the rules every
 * PingRoom call shares: a redirect is refused, never followed (a 307/308 would
 * replay pairing secrets, uploaded bytes or a webhook body to whatever origin
 * `Location` names), and a client failure becomes a PingRoom exception that does
 * not carry the request (and so not the Authorization header).
 *
 * @internal
 */
final class Sender
{
    /** @var array<string, ClientInterface> */
    private array $clients = [];

    /**
     * @param ClientInterface|null $httpClient a client you built; its own timeout and redirect settings apply. Null: the SDK builds one per timeout.
     */
    public function __construct(
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly ?ClientInterface $httpClient,
        private readonly float $defaultTimeout,
    ) {
        // Fail at construction, not on the first call, when no client can be found.
        $this->clientFor($this->defaultTimeout);
    }

    /**
     * Build a sender, finding the PSR-17 factories (and, without `$httpClient`, a
     * PSR-18 client) that are installed when you pass none.
     */
    public static function create(
        ?ClientInterface $httpClient,
        ?RequestFactoryInterface $requestFactory,
        ?StreamFactoryInterface $streamFactory,
        float $timeout,
    ): self {
        try {
            $requestFactory ??= Psr17FactoryDiscovery::findRequestFactory();
            $streamFactory ??= Psr17FactoryDiscovery::findStreamFactory();
        } catch (NotFoundException) {
            throw new PingRoomException(
                'No PSR-17 HTTP factories found. Install an implementation (for example `composer require nyholm/psr7`) or pass `requestFactory` and `streamFactory`.',
                'no_http_client',
            );
        }

        return new self($requestFactory, $streamFactory, $httpClient, $timeout);
    }

    public function defaultTimeout(): float
    {
        return $this->defaultTimeout;
    }

    /**
     * A request with a body; the PSR-17 factories throw `InvalidArgumentException`
     * for a URL they cannot parse, which is reported without echoing the URL.
     *
     * @param array<string, string> $headers
     */
    public function build(string $method, string $url, #[\SensitiveParameter] array $headers, ?string $body): RequestInterface
    {
        try {
            $request = $this->requestFactory->createRequest($method, $url);
            foreach ($headers as $name => $value) {
                $request = $request->withHeader($name, $value);
            }
            if ($body !== null) {
                $request = $request->withBody($this->streamFactory->createStream($body));
            }

            return $request;
        } catch (\InvalidArgumentException) {
            // Not the PSR-7 message: some implementations quote the offending header value.
            throw PingRoomException::invalidRequest('The request could not be built: the URL or a header is not valid.');
        }
    }

    /**
     * Send, and refuse a redirect. `$scrub` removes secrets from a client's error
     * message before it is kept.
     *
     * @param (callable(string): string)|null $scrub
     * @param string                          $failurePrefix what a network failure's message starts with
     */
    public function send(
        #[\SensitiveParameter]
        RequestInterface $request,
        float $timeout,
        ?callable $scrub = null,
        string $failurePrefix = 'Network request failed: ',
    ): ResponseInterface {
        try {
            $response = $this->clientFor($timeout)->sendRequest($request);
        } catch (PingRoomException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw $this->clientFailure($e, $scrub, $failurePrefix);
        }

        self::refuseRedirect($response);

        return $response;
    }

    /**
     * The whole response body. Reading it can fail on a dropped connection, like
     * sending can.
     *
     * @param (callable(string): string)|null $scrub
     */
    public function read(ResponseInterface $response, ?callable $scrub = null, string $failurePrefix = 'Network request failed: '): string
    {
        try {
            return (string) $response->getBody();
        } catch (\Exception $e) {
            throw $this->clientFailure($e, $scrub, $failurePrefix);
        }
    }

    /**
     * A 3xx is never followed: the SDK sends credentials and bodies only to the
     * origin it was configured for.
     */
    public static function refuseRedirect(ResponseInterface $response): void
    {
        $status = $response->getStatusCode();
        if ($status < 300 || $status >= 400) {
            return;
        }
        // Release the body, best effort.
        try {
            $response->getBody()->close();
        } catch (\Throwable) {
            // The refusal below is what matters.
        }

        throw new PingRoomException(
            'Refusing to follow a redirect; credentials and request bodies are never forwarded.',
            'redirect_refused',
            $status,
        );
    }

    private function clientFor(float $timeout): ClientInterface
    {
        if ($this->httpClient !== null) {
            return $this->httpClient;
        }
        $key = 'timeout-' . sprintf('%.3f', $timeout);

        return $this->clients[$key] ??= DefaultHttpClient::create($timeout);
    }

    /**
     * @param (callable(string): string)|null $scrub
     */
    private function clientFailure(
        // The client's exception carries the request, and the request the token:
        // keep it out of the trace of the exception thrown here.
        #[\SensitiveParameter]
        \Exception $e,
        ?callable $scrub,
        string $failurePrefix,
    ): PingRoomException {
        if (self::isTimeout($e)) {
            return new TimeoutException();
        }
        $message = $e->getMessage();
        if ($scrub !== null) {
            $message = $scrub($message);
        }

        return new NetworkException($failurePrefix . $message);
    }

    /**
     * PSR-18 has no timeout exception, so recognise the ones the common clients
     * raise: Symfony's `TimeoutExceptionInterface`, a cURL "operation timed out"
     * (error 28) from Guzzle or the cURL handler, or a message that says so.
     */
    private static function isTimeout(\Throwable $e): bool
    {
        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof \Symfony\Contracts\HttpClient\Exception\TimeoutExceptionInterface) {
                return true;
            }
            if (preg_match('/cURL error 28\b|timed out|timeout was reached/i', $current->getMessage()) === 1) {
                return true;
            }
        }

        return false;
    }
}
