<?php

declare(strict_types=1);

namespace PingRoom\Tests\Unit;

use Nyholm\Psr7\Factory\Psr17Factory;
use PingRoom\Client;
use PingRoom\Exception\PingRoomException;
use PingRoom\Internal\Json;
use PingRoom\LinkPing;
use PingRoom\Tests\Support\Fixture;
use PingRoom\Tests\Support\MockHttpClient;
use PingRoom\Tests\Support\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Replays tests/contracts/structured-ping-vectors.json, the server's verdict on the
 * three structured-Ping rules every client re-implements by hand, through every public
 * method that takes `data` or an idempotency key.
 *
 * The PHP SDK measures `data` the server's own way (it decodes, trims and
 * empty-to-nulls as the server's middleware do, then counts PHP's escaped
 * `json_encode()`), so unlike the TypeScript SDK it has no drift on `data`.
 */
final class StructuredPingVectorsTest extends TestCase
{
    /** The SDK narrows a key to printable ASCII on purpose: it travels as an HTTP header. The server would accept each of these. */
    private const KEY_NARROWED = [
        'form-feed-edges', 'nbsp-edges', 'non-ascii-persian', 'non-ascii-latin1', 'persian-130-chars',
        'emoji-255-chars', 'inner-control-char', 'inner-tab', 'inner-del',
    ];

    /** @var array<string, mixed> */
    private array $fixture;

    protected function setUp(): void
    {
        $this->fixture = Fixture::load('structured-ping-vectors');
    }

    public function testTheFixtureIsTheShapeThisTestReads(): void
    {
        self::assertSame('structured-ping-vectors', $this->fixture['contract']);
        self::assertSame(1, $this->fixture['version']);
        self::assertSame(
            ['dataMaxBytes' => 8192, 'dataMaxKeys' => 25, 'idempotencyKeyMaxCodePoints' => 255, 'buttonLabelMaxCodePoints' => 26],
            $this->fixture['limits'],
        );
        foreach (['data', 'idempotencyKey', 'buttonLabel'] as $section) {
            self::assertGreaterThan(5, count($this->vectors($section)), "{$section} vectors are missing");
        }
        self::assertSame(\PingRoom\Limits::MAX_DATA_BYTES, 8192);
        self::assertSame(\PingRoom\Limits::MAX_DATA_KEYS, 25);
        self::assertSame(\PingRoom\Limits::MAX_IDEMPOTENCY_KEY_LENGTH, 255);
        self::assertSame(\PingRoom\Limits::MAX_LINK_BUTTON_LABEL_LENGTH, 26);
    }

    public function testTheSdkMeasuresEveryDataVectorTheWayTheServerDoes(): void
    {
        $measured = 0;
        foreach ($this->vectors('data') as $vector) {
            if ($vector['serverBytes'] === null) {
                continue;
            }
            self::assertIsArray($vector['data']);
            self::assertSame($vector['serverBytes'], Json::serverBytes($vector['data']), $vector['id']);
            ++$measured;
        }
        self::assertGreaterThan(20, $measured);
    }

    public function testEveryPublicMethodThatTakesDataAgreesWithTheServerOnEveryVector(): void
    {
        $senders = [
            'broadcast' => fn(Client $pr, mixed $data) => $pr->broadcast('AB12', 'hi', data: $data),
            'handoffs.requestAck' => fn(Client $pr, mixed $data) => $pr->handoffs->requestAck('hi', data: $data),
            'handoffs.ask' => fn(Client $pr, mixed $data) => $pr->handoffs->ask('hi', ['a', 'b'], data: $data),
            'approvals.request' => fn(Client $pr, mixed $data) => $pr->approvals->request('AB12', 'Ship?', data: $data),
            'questions.ask' => fn(Client $pr, mixed $data) => $pr->questions->ask('AB12', 'Ship?', data: $data),
            'live.push' => fn(Client $pr, mixed $data) => $pr->live->push('AB12', ['correlation_id' => 'c1', 'live_status' => ['state' => 'running', 'message' => 'x'], 'data' => $data]),
            'notifications.replace' => fn(Client $pr, mixed $data) => $pr->notifications->replace('AB12', 'n1', 'hi', data: $data),
            'incomingWebhook' => fn(Client $pr, mixed $data) => $pr->sendIncomingWebhook('https://example.test/webhooks/room/secret', ['message' => 'hi', 'data' => $data]),
        ];

        $disagreements = [];
        foreach ($senders as $name => $send) {
            foreach ($this->vectors('data') as $vector) {
                [$pr, $http] = $this->sendingClient();
                $refused = null;
                try {
                    $send($pr, $vector['data']);
                } catch (PingRoomException $e) {
                    $refused = $e;
                }
                $sent = $refused === null;
                if ($sent !== $vector['accepted']) {
                    $disagreements[] = ['sender' => $name, 'id' => $vector['id'], 'client' => $sent, 'server' => $vector['accepted']];
                    continue;
                }
                if ($sent) {
                    self::assertCount(1, $http->requests, "{$name} {$vector['id']}");
                    $body = $http->jsonBody();
                    self::assertSame($vector['data'], $body['data'], "{$name} {$vector['id']}: data changed on the wire");
                } else {
                    self::assertCount(0, $http->requests, "{$name} {$vector['id']}: refused locally but a request was made anyway");
                    self::assertContains($this->dataReason($refused), $vector['reasons'], "{$name} {$vector['id']}: the SDK refused as {$this->dataReason($refused)}");
                }
            }
        }
        self::assertSame([], $disagreements);
    }

    public function testEveryPublicMethodThatTakesAnIdempotencyKeyAgreesWithTheServerAndSendsTheTrimmedKey(): void
    {
        $senders = [
            'broadcast' => fn(Client $pr, string $key) => $pr->broadcast('AB12', 'hi', idempotencyKey: $key),
            'handoffs.requestAck' => fn(Client $pr, string $key) => $pr->handoffs->requestAck('hi', idempotencyKey: $key),
            'handoffs.ask' => fn(Client $pr, string $key) => $pr->handoffs->ask('hi', ['a', 'b'], idempotencyKey: $key),
            'approvals.request' => fn(Client $pr, string $key) => $pr->approvals->request('AB12', 'Ship?', idempotencyKey: $key),
            'questions.ask' => fn(Client $pr, string $key) => $pr->questions->ask('AB12', 'Ship?', idempotencyKey: $key),
            'incomingWebhook' => fn(Client $pr, string $key) => $pr->sendIncomingWebhook('https://example.test/webhooks/room/secret', ['message' => 'hi'], $key),
        ];
        // Only these two senders treat an empty string as "no key", as the TypeScript SDK does.
        $emptyMeansNoKey = ['handoffs.requestAck', 'handoffs.ask', 'incomingWebhook'];

        $disagreements = [];
        foreach ($senders as $name => $send) {
            foreach ($this->vectors('idempotencyKey') as $vector) {
                [$pr, $http] = $this->sendingClient();
                $refused = null;
                try {
                    $send($pr, $vector['key']);
                } catch (PingRoomException $e) {
                    $refused = $e;
                }
                if ($refused !== null) {
                    // A key the SDK refuses is a typed error, never a network failure.
                    self::assertSame('invalid_request', $refused->errorCode, "{$name} {$vector['id']}");
                    self::assertStringContainsString('idempotencyKey', $refused->getMessage());
                    self::assertSame([], $http->requests);
                }
                $header = $refused === null && $http->requests !== [] && $http->last()->hasHeader('Idempotency-Key')
                    ? $http->last()->getHeaderLine('Idempotency-Key')
                    : null;
                $client = $refused !== null ? false : ($header === null ? 'sent without the key' : true);
                if ($client !== $vector['accepted']) {
                    $disagreements[] = [$name, $vector['id'], $client, $vector['accepted']];
                } elseif ($client === true) {
                    self::assertSame($vector['trimmed'], $header, "{$name} {$vector['id']}");
                }
            }
        }

        $expected = [];
        foreach (array_keys($senders) as $name) {
            foreach ($this->vectors('idempotencyKey') as $vector) {
                if (in_array($vector['id'], self::KEY_NARROWED, true)) {
                    $expected[] = [$name, $vector['id'], false, true];
                }
                if ($vector['id'] === 'empty' && in_array($name, $emptyMeansNoKey, true)) {
                    $expected[] = [$name, 'empty', 'sent without the key', false];
                }
            }
        }
        usort($disagreements, fn(array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
        usort($expected, fn(array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
        self::assertSame($expected, $disagreements);
    }

    public function testLinkPingCountsCodePointsLikeTheServerForTheButtonLabel(): void
    {
        foreach ($this->vectors('buttonLabel') as $vector) {
            $accepted = true;
            $built = null;
            try {
                $built = LinkPing::data('https://example.com/build/512', $vector['label']);
            } catch (PingRoomException) {
                $accepted = false;
            }
            self::assertSame($vector['accepted'], $accepted, $vector['id']);
            if ($accepted) {
                self::assertSame($vector['label'], $built['button_label'], "{$vector['id']}: label changed");
            }
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function vectors(string $section): array
    {
        $vectors = $this->fixture[$section];
        self::assertIsArray($vectors);

        /** @var list<array<string, mixed>> $vectors */
        return $vectors;
    }

    /**
     * A client that answers every request with a success every sender accepts.
     *
     * @return array{Client, MockHttpClient}
     */
    private function sendingClient(): array
    {
        $http = new MockHttpClient(fn(RequestInterface $request): ResponseInterface => self::json(201, ['id' => 'x', 'state' => 'pending', 'success' => true]));
        $factory = new Psr17Factory();

        return [new Client(token: 't', baseUrl: 'https://api.pingroom.io', httpClient: $http, requestFactory: $factory, streamFactory: $factory), $http];
    }

    private function dataReason(PingRoomException $error): string
    {
        if ($error->errorCode !== 'invalid_data') {
            return "unknown: {$error->errorCode} {$error->getMessage()}";
        }
        if (str_contains($error->getMessage(), 'at most 25 keys')) {
            return 'too_many_keys';
        }
        if (str_contains($error->getMessage(), 'too large')) {
            return 'too_large';
        }
        if (str_contains($error->getMessage(), 'JSON object')) {
            return 'not_object';
        }

        return "unknown: {$error->getMessage()}";
    }
}
