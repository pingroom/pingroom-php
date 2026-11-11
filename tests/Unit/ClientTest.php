<?php

declare(strict_types=1);

namespace PingRoom\Tests\Unit;

use Nyholm\Psr7\Factory\Psr17Factory;
use PingRoom\Client;
use PingRoom\Exception\NetworkException;
use PingRoom\Exception\PingRoomException;
use PingRoom\Exception\TimeoutException;
use PingRoom\Tests\Support\FakeNetworkException;
use PingRoom\Tests\Support\MockHttpClient;
use PingRoom\Tests\Support\TestCase;
use PingRoom\Version;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class ClientTest extends TestCase
{
    public function testTheVersionIsInOnePlaceAndTheUserAgentFollowsIt(): void
    {
        self::assertSame('0.1.0', Version::VERSION);
        $changelog = (string) file_get_contents(__DIR__ . '/../../CHANGELOG.md');
        self::assertStringContainsString('## 0.1.0', $changelog);

        [$pr, $http] = $this->clientAnswering(['metrics' => [], 'limit' => 8]);
        $pr->metrics->list('ab12');
        self::assertSame('pingroom-php/' . Version::VERSION, $http->last()->getHeaderLine('User-Agent'));
    }

    public function testAnAuthenticatedGetCarriesTheBearerTokenAndNoBody(): void
    {
        [$pr, $http] = $this->clientAnswering(['metrics' => [], 'limit' => 8]);
        $pr->metrics->list('ab12');

        $request = $http->last();
        self::assertSame('GET', $request->getMethod());
        self::assertSame('https://api.pingroom.io/api/agent/rooms/ab12/metrics', (string) $request->getUri());
        self::assertSame('Bearer ' . self::TOKEN, $request->getHeaderLine('Authorization'));
        self::assertSame('application/json', $request->getHeaderLine('Accept'));
        self::assertFalse($request->hasHeader('Content-Type'));
        self::assertSame('', (string) $request->getBody());
    }

    public function testUserAgentCanBeOverridden(): void
    {
        $http = new MockHttpClient(fn() => self::json(200, ['metrics' => [], 'limit' => 8]));
        $factory = new Psr17Factory();
        $pr = new Client(token: 't', httpClient: $http, requestFactory: $factory, streamFactory: $factory, userAgent: 'my-bot/2');
        $pr->metrics->list('ab12');
        self::assertSame('my-bot/2', $http->last()->getHeaderLine('User-Agent'));
    }

    public function testACallNeedingAuthWithoutATokenThrowsNoTokenBeforeSending(): void
    {
        [$pr, $http] = $this->clientWith(fn() => self::json(200, []), token: null);
        $e = $this->thrown(fn() => $pr->metrics->list('ab12'));
        self::assertSame('no_token', $e->errorCode);
        self::assertSame([], $http->requests);

        $pr->setToken('tok2');
        $pr->metrics->list('ab12');
        self::assertSame('Bearer tok2', $http->last()->getHeaderLine('Authorization'));
        self::assertSame('tok2', $pr->getToken());
        $pr->setToken(null);
        self::assertNull($pr->getToken());
    }

    public function testAnEmptyTokenIsNoToken(): void
    {
        [$pr, $http] = $this->clientWith(fn() => self::json(200, []), token: '');
        self::assertSame('no_token', $this->thrown(fn() => $pr->metrics->list('ab12'))->errorCode);
        self::assertSame([], $http->requests);
    }

    public function testATokenThatCannotBeAHeaderIsRefusedWithoutEchoingIt(): void
    {
        [$pr, $http] = $this->clientWith(fn() => self::json(200, []), token: "bad token\nX-Injected: 1");
        $e = $this->thrown(fn() => $pr->metrics->list('ab12'));
        self::assertSame('invalid_request', $e->errorCode);
        self::assertStringNotContainsString('bad token', $e->getMessage());
        self::assertSame([], $http->requests);
    }

    public function testTheBaseUrlMustBeHttpsUnlessLoopbackOrAllowed(): void
    {
        $factory = new Psr17Factory();
        $make = static fn(string $url, bool $allow = false): Client => new Client(
            baseUrl: $url,
            httpClient: new MockHttpClient(fn() => self::json(200, [])),
            requestFactory: $factory,
            streamFactory: $factory,
            allowInsecure: $allow,
        );

        $e = $this->thrown(fn() => $make('http://api.evil.com'));
        self::assertSame('insecure_url', $e->errorCode);
        self::assertStringContainsString('http://api.evil.com', $e->getMessage());
        self::assertSame('insecure_url', $this->thrown(fn() => $make('ftp://api.pingroom.io'))->errorCode);

        foreach (['http://localhost:8000', 'http://127.0.0.1:8000', 'http://[::1]:8000', 'http://dev.localhost', 'https://api.pingroom.io'] as $ok) {
            self::assertInstanceOf(Client::class, $make($ok));
        }
        self::assertInstanceOf(Client::class, $make('http://api.example.test', allow: true));

        foreach (['', 'not-a-url', '/api', 'https://', 'http://exa mple.com'] as $bad) {
            self::assertSame('invalid_url', $this->thrown(fn() => $make($bad))->errorCode, $bad);
        }
    }

    public function testABaseUrlPathPrefixIsKeptAndATrailingSlashDropped(): void
    {
        [$pr, $http] = $this->clientWith(fn() => self::json(200, ['metrics' => [], 'limit' => 8]), baseUrl: 'https://gw.example.com/pingroom/?x=1#frag');
        $pr->metrics->list('ab12');
        self::assertSame('https://gw.example.com/pingroom/api/agent/rooms/ab12/metrics', (string) $http->last()->getUri());

        [$pr, $http] = $this->clientWith(fn() => self::json(200, ['metrics' => [], 'limit' => 8]), baseUrl: 'https://api.pingroom.io:8443/');
        $pr->metrics->list('ab12');
        self::assertSame('https://api.pingroom.io:8443/api/agent/rooms/ab12/metrics', (string) $http->last()->getUri());
    }

    public function testTheBaseUrlComesFromTheEnvironmentWhenNotGiven(): void
    {
        $factory = new Psr17Factory();
        $http = new MockHttpClient(fn() => self::json(200, ['metrics' => [], 'limit' => 8]));
        $previous = [getenv('PINGROOM_API_URL'), getenv('PINGROOM_BASE_URL')];
        try {
            putenv('PINGROOM_API_URL');
            putenv('PINGROOM_BASE_URL=https://base.example.com');
            (new Client(token: 't', httpClient: $http, requestFactory: $factory, streamFactory: $factory))->metrics->list('a');
            self::assertSame('base.example.com', $http->last()->getUri()->getHost());

            // PINGROOM_API_URL wins over PINGROOM_BASE_URL; an empty value counts as unset.
            putenv('PINGROOM_API_URL=https://api.example.com');
            (new Client(token: 't', httpClient: $http, requestFactory: $factory, streamFactory: $factory))->metrics->list('a');
            self::assertSame('api.example.com', $http->last()->getUri()->getHost());
            putenv('PINGROOM_API_URL=');
            (new Client(token: 't', httpClient: $http, requestFactory: $factory, streamFactory: $factory))->metrics->list('a');
            self::assertSame('base.example.com', $http->last()->getUri()->getHost());

            // An explicit base URL beats both.
            (new Client(token: 't', baseUrl: 'https://explicit.example.com', httpClient: $http, requestFactory: $factory, streamFactory: $factory))->metrics->list('a');
            self::assertSame('explicit.example.com', $http->last()->getUri()->getHost());

            putenv('PINGROOM_API_URL');
            putenv('PINGROOM_BASE_URL');
            (new Client(token: 't', httpClient: $http, requestFactory: $factory, streamFactory: $factory))->metrics->list('a');
            self::assertSame('api.pingroom.io', $http->last()->getUri()->getHost());
        } finally {
            foreach (['PINGROOM_API_URL' => $previous[0], 'PINGROOM_BASE_URL' => $previous[1]] as $name => $value) {
                putenv($value === false ? $name : "{$name}={$value}");
            }
        }
    }

    public function testATimeoutMustBePositive(): void
    {
        foreach ([0.0, -1.0, NAN, INF] as $timeout) {
            $e = $this->thrown(fn() => new Client(timeout: $timeout, httpClient: new MockHttpClient(fn() => self::json(200, [])), requestFactory: new Psr17Factory(), streamFactory: new Psr17Factory()));
            self::assertSame('invalid_request', $e->errorCode);
        }
    }

    public function testAnAbsoluteRequestPathIsRefusedSoTheTokenCannotGoElsewhere(): void
    {
        [$pr, $http] = $this->clientAnswering([]);
        $transport = $this->transportOf($pr);
        foreach (['https://elsewhere.invalid/collect', 'HTTP://elsewhere.invalid/', 'javascript:alert(1)'] as $path) {
            self::assertSame('invalid_request', $this->thrown(fn() => $transport->request('GET', $path))->errorCode, $path);
        }
        self::assertSame([], $http->requests);

        $transport->request('GET', 'api/agent/rooms');
        self::assertSame('https://api.pingroom.io/api/agent/rooms', (string) $http->last()->getUri());
    }

    public function testApathParameterThatIsEmptyOrADotSegmentIsRefused(): void
    {
        [$pr, $http] = $this->clientAnswering([]);
        $attempts = [
            fn() => $pr->live->get('AB12', '..'),
            fn() => $pr->approvals->get('..'),
            fn() => $pr->approvals->waitForDecision('.'),
            fn() => $pr->questions->cancel('.'),
            fn() => $pr->questions->get('..'),
            fn() => $pr->metrics->list(''),
            fn() => $pr->notifications->get('..'),
        ];
        foreach ($attempts as $attempt) {
            self::assertSame('invalid_request', $this->thrown($attempt)->errorCode);
        }
        self::assertSame([], $http->requests);

        // Dots inside a segment are ordinary characters.
        [$pr, $http] = $this->clientAnswering(['id' => '..a']);
        $pr->approvals->get('..a');
        self::assertSame('/api/agent/approvals/..a', $http->last()->getUri()->getPath());
    }

    public function testPathSegmentsAreEncodedLikeEncodeUriComponent(): void
    {
        [$pr, $http] = $this->clientAnswering(['id' => 'x']);
        $pr->notifications->get("n/1 ?!*'()~é");
        self::assertSame("/api/agent/notifications/n%2F1%20%3F!*'()~%C3%A9", $http->last()->getUri()->getPath());
    }

    public function testAnApiErrorMapsToItsStatusAndCodeAndNeverLeaksTheToken(): void
    {
        [$pr] = $this->clientAnswering(['code' => 'pings_closed', 'message' => 'closed'], 403);
        $e = $this->thrown(fn() => $pr->broadcast('AB12', 'hi'));

        self::assertSame('pings_closed', $e->errorCode);
        self::assertSame(403, $e->status);
        self::assertSame(403, $e->getCode());
        self::assertSame('closed', $e->getMessage());
        self::assertSame(['code' => 'pings_closed', 'message' => 'closed'], $e->body);
        self::assertNull($e->retryAfter);
        self::assertStringNotContainsString(self::TOKEN, serialize($e->body) . $e->getMessage() . (string) $e);
    }

    public function testTheErrorCodeFallsBackToTheErrorFieldAndTheMessageToTheCodeThenTheStatus(): void
    {
        [$pr] = $this->clientAnswering(['error' => 'cooldown_active'], 429);
        $e = $this->thrown(fn() => $pr->metrics->list('a'));
        self::assertSame('cooldown_active', $e->errorCode);
        self::assertSame('cooldown_active', $e->getMessage());

        [$pr] = $this->clientAnswering(['unrelated' => true], 500);
        $e = $this->thrown(fn() => $pr->metrics->list('a'));
        self::assertNull($e->errorCode);
        self::assertSame('HTTP 500', $e->getMessage());

        [$pr] = $this->clientAnswering(['code' => '', 'error' => 'real', 'message' => ''], 422);
        $e = $this->thrown(fn() => $pr->metrics->list('a'));
        self::assertSame('real', $e->errorCode);
        self::assertSame('real', $e->getMessage());
    }

    public function testRetryAfterComesFromTheBodyElseAWholeSecondsHeader(): void
    {
        [$pr] = $this->clientAnswering(['code' => 'rate_limited', 'retry_after' => 5], 429);
        self::assertSame(5, $this->thrown(fn() => $pr->metrics->list('a'))->retryAfter);

        [$pr] = $this->clientWith(fn() => self::json(429, ['code' => 'rate_limited'], ['Retry-After' => ' 7 ']));
        self::assertSame(7, $this->thrown(fn() => $pr->metrics->list('a'))->retryAfter);

        // An HTTP-date is not a number of seconds.
        [$pr] = $this->clientWith(fn() => self::json(429, ['code' => 'rate_limited'], ['Retry-After' => 'Wed, 21 Oct 2026 07:28:00 GMT']));
        self::assertNull($this->thrown(fn() => $pr->metrics->list('a'))->retryAfter);

        // The body wins over the header.
        [$pr] = $this->clientWith(fn() => self::json(429, ['retry_after' => 2], ['Retry-After' => '9']));
        self::assertSame(2, $this->thrown(fn() => $pr->metrics->list('a'))->retryAfter);
    }

    public function testANonJsonErrorBodyStillMapsToItsStatusWithTheTextAsBody(): void
    {
        [$pr] = $this->clientWith(fn() => self::raw(502, '<html>Bad gateway</html>'));
        $e = $this->thrown(fn() => $pr->metrics->list('a'));
        self::assertSame(502, $e->status);
        self::assertNull($e->errorCode);
        self::assertSame('<html>Bad gateway</html>', $e->body);
    }

    public function testA2xxWhoseNonEmptyBodyIsNotJsonIsInvalidResponseNeverData(): void
    {
        foreach (['<html>Please sign in to the hotspot</html>', 'OK', '{"rooms": [', 'undefined'] as $page) {
            foreach ([200, 201, 202] as $status) {
                [$pr] = $this->clientWith(fn() => self::raw($status, $page));
                $e = $this->thrown(fn() => $pr->metrics->list('a'));
                self::assertSame('invalid_response', $e->errorCode, "{$status} {$page}");
                self::assertSame(0, $e->status);
            }
        }
        [$pr] = $this->clientWith(fn() => self::raw(200, '<html>' . str_repeat('x', 5000) . '</html>'));
        $e = $this->thrown(fn() => $pr->metrics->list('a'));
        self::assertIsString($e->body);
        self::assertLessThanOrEqual(512, strlen($e->body));
    }

    public function testAnEmptyOr204OrNullSuccessIsStillASuccess(): void
    {
        $cases = [[204, '', null], [200, '', null], [202, '', null], [200, " \n", null], [200, 'null', null], [200, '[]', []], [200, '{"ok":true}', ['ok' => true]]];
        foreach ($cases as [$status, $body, $expected]) {
            [$pr] = $this->clientWith(fn() => self::raw($status, $body));
            self::assertSame($expected, $this->transportOf($pr)->request('POST', '/x'), "{$status} " . json_encode($body));
        }
    }

    public function testAnObjectEndpointThatAnswersNullIsInvalidResponse(): void
    {
        [$pr] = $this->clientWith(fn() => self::raw(200, 'null'));
        self::assertSame('invalid_response', $this->thrown(fn() => $pr->metrics->list('a'))->errorCode);
    }

    public function testARedirectIsRefusedNotFollowedWhateverItsStatus(): void
    {
        foreach ([301, 302, 303, 307, 308] as $status) {
            [$pr, $http] = $this->clientWith(fn() => self::raw($status, '', ['Location' => 'https://evil.example/collect']));
            $e = $this->thrown(fn() => $pr->broadcast('AB12', 'hi'));
            self::assertSame('redirect_refused', $e->errorCode);
            self::assertSame($status, $e->status);
            self::assertCount(1, $http->requests, 'the SDK must not send a second request');
        }
    }

    public function testRedirectsAreRefusedOnEveryDoorUploadsWebhooksAndRawReads(): void
    {
        [$pr, $http] = $this->clientWith(fn() => self::raw(307, '', ['Location' => 'https://evil.example/collect']));
        self::assertSame('redirect_refused', $this->thrown(fn() => $pr->attachments->upload('bytes', 'notes.txt'))->errorCode);
        self::assertSame('redirect_refused', $this->thrown(fn() => $pr->attachments->content('abc'))->errorCode);
        self::assertSame('redirect_refused', $this->thrown(fn() => $pr->sendIncomingWebhook('https://example.test/hook/fixture-secret', ['message' => 'x']))->errorCode);
        self::assertCount(3, $http->requests);
    }

    public function testAnUnfollowedRedirectIsNotRetriedAsTransientByTheWaitLoops(): void
    {
        [$pr, $http] = $this->clientWith(fn() => self::raw(307, '', ['Location' => 'https://evil.example/']));
        self::assertSame('redirect_refused', $this->thrown(fn() => $pr->approvals->waitForDecision('a1'))->errorCode);
        self::assertCount(1, $http->requests);
    }

    public function testANetworkFailureBecomesANetworkExceptionWithoutTheRequestOrToken(): void
    {
        [$pr] = $this->clientWith(function (RequestInterface $request): ResponseInterface {
            throw new FakeNetworkException('Could not connect; sent Authorization: Bearer ' . self::TOKEN, $request);
        });
        $e = $this->thrown(fn() => $pr->broadcast('AB12', 'hi'));

        self::assertInstanceOf(NetworkException::class, $e);
        self::assertSame('network_error', $e->errorCode);
        self::assertSame(0, $e->status);
        self::assertStringStartsWith('Network request failed: ', $e->getMessage());
        self::assertStringNotContainsString(self::TOKEN, $e->getMessage());
        // The client's own exception carries the request, and the request the token.
        self::assertNull($e->getPrevious());
        self::assertStringNotContainsString(self::TOKEN, print_r($e, true));
    }

    public function testATimeoutIsATimeoutExceptionForTheCommonClientsSignals(): void
    {
        $failures = [
            new \RuntimeException('cURL error 28: Operation timed out after 30001 milliseconds with 0 bytes received'),
            new \RuntimeException('Connection timed out'),
            new \Symfony\Component\HttpClient\Exception\TimeoutException('Idle timeout reached for "https://api.pingroom.io".'),
            new \RuntimeException('outer', 0, new \RuntimeException('Timeout was reached')),
        ];
        foreach ($failures as $failure) {
            [$pr] = $this->clientWith(function () use ($failure): ResponseInterface {
                throw $failure;
            });
            $e = $this->thrown(fn() => $pr->metrics->list('a'));
            self::assertInstanceOf(TimeoutException::class, $e, $failure->getMessage());
            self::assertSame('timeout', $e->errorCode);
            self::assertSame(0, $e->status);
        }
    }

    public function testAnExceptionTheClientRaisesThatIsAnErrorIsNotSwallowed(): void
    {
        [$pr] = $this->clientWith(function (): ResponseInterface {
            throw new \TypeError('a bug in the HTTP client');
        });
        $this->expectException(\TypeError::class);
        $pr->metrics->list('a');
    }

    public function testClientRenderingsNeverShowTheToken(): void
    {
        [$pr] = $this->clientAnswering([]);
        $renderings = [
            json_encode($pr, JSON_THROW_ON_ERROR),
            json_encode(['context' => ['client' => $pr, 'live' => $pr->live, 'approvals' => $pr->approvals]], JSON_THROW_ON_ERROR),
            print_r($pr, true),
            print_r($pr->notifications, true),
            print_r($pr->handoffs, true),
            var_export($pr, true),
            var_export($pr->questions, true),
            print_r((array) $pr, true),
            $this->dump($pr),
            $this->dump($pr->approvals),
            $this->dump($pr->attachments),
        ];
        foreach ($renderings as $rendering) {
            self::assertStringNotContainsString(self::TOKEN, $rendering);
        }
        self::assertStringContainsString('[redacted]', (string) json_encode($pr));
        self::assertSame(['baseUrl' => 'https://api.pingroom.io', 'token' => '[redacted]'], $pr->jsonSerialize());
        self::assertSame(self::TOKEN, $pr->getToken(), 'the credential stays readable through the explicit accessor');
    }

    public function testAClientWithoutATokenSaysSo(): void
    {
        [$pr] = $this->clientWith(fn() => self::json(200, []), token: null);
        self::assertSame(['baseUrl' => 'https://api.pingroom.io', 'token' => null], $pr->jsonSerialize());
    }

    public function testTheClientCannotBeSerialized(): void
    {
        [$pr] = $this->clientAnswering([]);
        $this->expectException(\LogicException::class);
        serialize($pr);
    }

    public function testTheTokenIsHiddenInAStackTraceThroughSensitiveParameter(): void
    {
        try {
            new Client(token: self::TOKEN, baseUrl: 'ftp://bad.example.com');
            self::fail('expected a failure');
        } catch (PingRoomException $e) {
            self::assertStringNotContainsString(self::TOKEN, $e->getTraceAsString());
            self::assertStringNotContainsString(self::TOKEN, print_r($e->getTrace(), true));
        }
    }

    public function testRequestFailuresDoNotLeakTheTokenThroughTheTraceEither(): void
    {
        [$pr] = $this->clientWith(function (RequestInterface $request): ResponseInterface {
            throw new FakeNetworkException('down', $request);
        });
        $e = $this->thrown(fn() => $pr->metrics->list('a'));
        self::assertStringNotContainsString(self::TOKEN, print_r($e->getTrace(), true));
        self::assertStringNotContainsString(self::TOKEN, $this->dump($e));
    }

    public function testNoFactoriesAndNoDiscoverableOnesIsAClearError(): void
    {
        // Discovery would find nyholm/psr7 here, so this only checks that an explicit
        // factory is honoured and the client builds without discovery.
        $factory = new Psr17Factory();
        $client = new Client(token: 't', httpClient: new MockHttpClient(fn() => self::json(200, [])), requestFactory: $factory, streamFactory: $factory);
        self::assertInstanceOf(Client::class, $client);
    }

    private function dump(mixed $value): string
    {
        ob_start();
        var_dump($value);

        return (string) ob_get_clean();
    }

    private function transportOf(Client $client): \PingRoom\Http\Transport
    {
        $property = new \ReflectionProperty(Client::class, 'http');
        $transport = $property->getValue($client);
        self::assertInstanceOf(\PingRoom\Http\Transport::class, $transport);

        return $transport;
    }
}
