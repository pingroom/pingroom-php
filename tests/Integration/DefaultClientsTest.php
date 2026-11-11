<?php

declare(strict_types=1);

namespace PingRoom\Tests\Integration;

use PingRoom\Client;
use PingRoom\Exception\NetworkException;
use PingRoom\Exception\PingRoomException;
use PingRoom\Exception\TimeoutException;
use PingRoom\Http\DefaultHttpClient;
use PingRoom\IncomingWebhook;
use Psr\Http\Client\ClientInterface;

/**
 * The clients the SDK builds when you pass none, over a real socket: a redirect is
 * refused and never followed (so a 307/308 cannot replay a body or a token to another
 * origin), a slow server is a timeout, and a closed port is a network error.
 */
final class DefaultClientsTest extends LocalServerTestCase
{
    private const TOKEN = 'agt_live_fixture_credential_5f2c9e';

    /**
     * @return array<string, array{string}>
     */
    public static function clients(): array
    {
        return [
            'default (whatever the SDK picks)' => ['default'],
            'guzzle' => ['guzzle'],
            'symfony' => ['symfony'],
        ];
    }

    private function httpClient(string $which, float $timeout = 30.0): ?ClientInterface
    {
        return match ($which) {
            'guzzle' => class_exists(\GuzzleHttp\Client::class) ? DefaultHttpClient::guzzle($timeout) : self::skip('Guzzle is not installed.'),
            'symfony' => class_exists(\Symfony\Component\HttpClient\Psr18Client::class) ? (DefaultHttpClient::symfony($timeout) ?? self::skip('Symfony HttpClient has no PSR-7 factory.')) : self::skip('symfony/http-client is not installed.'),
            default => null,
        };
    }

    private static function skip(string $reason): never
    {
        self::markTestSkipped($reason);
    }

    /**
     * @dataProvider clients
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('clients')]
    public function testARedirectIsRefusedAndNeverReachesTheOtherServer(string $which): void
    {
        $log = $this->tempFile();
        $collector = $this->startServer('collector', ['COLLECT_FILE' => $log]);

        foreach ([307, 308, 301, 302] as $status) {
            $redirector = $this->startServer('redirector', ['REDIRECT_TO' => $collector . '/collect', 'REDIRECT_STATUS' => (string) $status]);
            $client = new Client(token: self::TOKEN, baseUrl: $redirector, httpClient: $this->httpClient($which));

            $e = $this->thrownBy(fn() => $client->broadcast('AB12', 'fixture-private-message', data: ['k' => 'v']));
            self::assertSame(['redirect_refused', $status], [$e->errorCode, $e->status], "{$which} {$status}");

            $e = $this->thrownBy(fn() => $client->attachments->upload('fixture-private-attachment', 'notes.txt'));
            self::assertSame('redirect_refused', $e->errorCode);

            $e = $this->thrownBy(fn() => $client->sendIncomingWebhook($redirector . '/hook/fixture-secret', ['message' => 'fixture-private-message']));
            self::assertSame('redirect_refused', $e->errorCode);

            $e = $this->thrownBy(fn() => (new IncomingWebhook(httpClient: $this->httpClient($which)))->send($redirector . '/hook/fixture-secret', ['message' => 'x']));
            self::assertSame('redirect_refused', $e->errorCode);
        }

        self::assertSame([], $this->collected($log), 'nothing may reach the redirect target: no body and no token');
    }

    public function testTheSdkBuildsItsOwnRedirectSafeClientWhenNoneIsGiven(): void
    {
        $log = $this->tempFile();
        $collector = $this->startServer('collector', ['COLLECT_FILE' => $log]);
        $redirector = $this->startServer('redirector', ['REDIRECT_TO' => $collector . '/collect', 'REDIRECT_STATUS' => '307']);

        // No `httpClient`: the SDK builds one (Guzzle here) configured not to follow redirects.
        $client = new Client(token: self::TOKEN, baseUrl: $redirector);
        $e = $this->thrownBy(fn() => $client->broadcast('AB12', 'hi'));
        self::assertSame('redirect_refused', $e->errorCode);
        self::assertSame([], $this->collected($log));
    }

    /**
     * @dataProvider clients
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('clients')]
    public function testARequestReachesAServerWithTheBearerTokenAndTheJsonBody(string $which): void
    {
        $log = $this->tempFile();
        $server = $this->startServer('collector', ['COLLECT_FILE' => $log]);
        $client = new Client(token: self::TOKEN, baseUrl: $server, httpClient: $this->httpClient($which));

        $result = $client->broadcast('AB12', 'hello é', data: ['k' => 'v'], idempotencyKey: 'k1');
        self::assertSame('x', $result['id']);

        $rows = $this->collected($log);
        self::assertCount(1, $rows);
        self::assertSame('POST', $rows[0]['method']);
        self::assertSame('/api/agent/rooms/AB12/notifications', $rows[0]['uri']);
        self::assertSame('Bearer ' . self::TOKEN, $rows[0]['authorization']);
        self::assertSame('{"message":"hello é","data":{"k":"v"}}', $rows[0]['body']);

        // A multipart upload reaches the wire intact too.
        $client->attachments->upload("bytes\x00\xFF", 'notes.txt', 'text/plain');
        $upload = $this->collected($log)[1];
        self::assertSame('Bearer ' . self::TOKEN, $upload['authorization']);
        self::assertSame(['name' => 'notes.txt', 'type' => 'text/plain', 'content' => base64_encode("bytes\x00\xFF")], $upload['file']);
    }

    /**
     * @dataProvider clients
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('clients')]
    public function testASlowServerIsATimeoutAndAClosedPortIsANetworkError(string $which): void
    {
        $slow = $this->startServer('slow');
        $client = new Client(token: self::TOKEN, baseUrl: $slow, timeout: 0.4, httpClient: $this->httpClient($which, 0.4));
        $started = microtime(true);
        $e = $this->thrownBy(fn() => $client->metrics->list('ab12'));
        self::assertInstanceOf(TimeoutException::class, $e, "{$which}: " . $e->getMessage());
        self::assertLessThan(3.0, microtime(true) - $started);

        $closed = new Client(token: self::TOKEN, baseUrl: 'http://127.0.0.1:1', timeout: 1.0, httpClient: $this->httpClient($which, 1.0));
        $e = $this->thrownBy(fn() => $closed->metrics->list('ab12'));
        self::assertInstanceOf(NetworkException::class, $e);
        self::assertStringNotContainsString(self::TOKEN, $e->getMessage());
        self::assertNull($e->getPrevious());
    }

    public function testTheClientTimeoutConfiguresTheClientTheSdkBuilds(): void
    {
        $slow = $this->startServer('slow');
        $client = new Client(token: self::TOKEN, baseUrl: $slow, timeout: 0.4);
        $e = $this->thrownBy(fn() => $client->metrics->list('ab12'));
        self::assertInstanceOf(TimeoutException::class, $e);
    }

    private function thrownBy(callable $fn): PingRoomException
    {
        try {
            $fn();
        } catch (PingRoomException $e) {
            return $e;
        }

        self::fail('Expected a PingRoomException.');
    }
}
