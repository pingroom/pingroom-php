<?php

declare(strict_types=1);

namespace PingRoom\Tests\Unit;

use PingRoom\PingAction;
use PingRoom\Tests\Support\TestCase;

final class BroadcastTest extends TestCase
{
    private const PING = ['id' => 'n1', 'message' => 'hi', 'created_at' => 'now', 'action_number' => null, 'action_icon' => null, 'trigger_source' => null];

    public function testBroadcastPostsThePingBodyToTheRoomNotificationsPath(): void
    {
        [$pr, $http] = $this->clientAnswering(self::PING, 201);
        $result = $pr->broadcast(
            'ab12',
            'hi',
            data: ['commit' => 'abc'],
            correlationId: 'c1',
            requiresAck: true,
            ackTimeoutSeconds: 300,
        );

        self::assertSame('n1', $result['id']);
        $request = $http->last();
        self::assertSame('POST', $request->getMethod());
        self::assertSame('https://api.pingroom.io/api/agent/rooms/ab12/notifications', (string) $request->getUri());
        self::assertSame('application/json', $request->getHeaderLine('Content-Type'));
        self::assertSame('Bearer ' . self::TOKEN, $request->getHeaderLine('Authorization'));
        // The exact bytes JSON.stringify writes for the same input, in the same key order.
        self::assertSame(
            '{"message":"hi","data":{"commit":"abc"},"correlation_id":"c1","requires_ack":true,"ack_timeout_seconds":300}',
            $http->rawBody(),
        );
        self::assertFalse($request->hasHeader('Idempotency-Key'));
    }

    public function testEveryFieldGoesOutUnderItsWireName(): void
    {
        [$pr, $http] = $this->clientAnswering(self::PING, 201);
        $pr->broadcast(
            'ab12',
            'Deploy shipped',
            title: 'CI',
            actionNumber: 3,
            actionIcon: 'rocket',
            data: ['v' => 1],
            correlationId: 'c-1',
            replyTo: 'r-1',
            tags: ['deploy', 'prod'],
            replaceKey: 'build:41',
            attachmentIds: ['a1', 'a2'],
            requiresAck: true,
            ackMode: 'all',
            isUrgent: true,
            ackTimeoutSeconds: 600,
            idempotencyKey: 'k-1',
        );

        self::assertSame([
            'message' => 'Deploy shipped',
            'title' => 'CI',
            'action_number' => 3,
            'action_icon' => 'rocket',
            'data' => ['v' => 1],
            'correlation_id' => 'c-1',
            'reply_to' => 'r-1',
            'tags' => ['deploy', 'prod'],
            'attachment_ids' => ['a1', 'a2'],
            'requires_ack' => true,
            'ack_mode' => 'all',
            'is_urgent' => true,
            'ack_timeout_seconds' => 600,
            'replace_key' => 'build:41',
        ], $http->jsonBody());
        // The camelCase names never reach the wire.
        self::assertStringNotContainsString('replaceKey', $http->rawBody());
        self::assertStringNotContainsString('idempotencyKey', $http->rawBody());
        self::assertSame('k-1', $http->last()->getHeaderLine('Idempotency-Key'));
    }

    public function testAnOmittedFieldIsNotSentAndAnExplicitFalseIs(): void
    {
        [$pr, $http] = $this->clientAnswering(self::PING, 201);
        $pr->broadcast('ab12', 'hi');
        self::assertSame('{"message":"hi"}', $http->rawBody());

        $pr->broadcast('ab12', 'hi', requiresAck: false, isUrgent: false);
        self::assertSame('{"message":"hi","requires_ack":false,"is_urgent":false}', $http->rawBody(1));
    }

    public function testUrgentIsIndependentOfAcknowledgement(): void
    {
        [$pr, $http] = $this->clientAnswering(self::PING, 201);
        $pr->broadcast('ab12', 'prod is down', isUrgent: true);
        self::assertSame(['message' => 'prod is down', 'is_urgent' => true], $http->jsonBody());
    }

    public function testAnEmptyDataIsAnObjectAndNestedStructuresKeepTheirShape(): void
    {
        [$pr, $http] = $this->clientAnswering(self::PING, 201);
        $pr->broadcast('ab12', 'hi', data: []);
        self::assertSame('{"message":"hi","data":{}}', $http->rawBody(0));

        $pr->broadcast('ab12', 'hi', data: new \stdClass());
        self::assertSame('{"message":"hi","data":{}}', $http->rawBody(1));

        $pr->broadcast('ab12', 'hi', data: ['list' => [1, 2], 'obj' => ['a' => null], 'empty_obj' => new \stdClass(), 'f' => 0.5, 'i' => 12.0]);
        self::assertSame('{"message":"hi","data":{"list":[1,2],"obj":{"a":null},"empty_obj":{},"f":0.5,"i":12}}', $http->rawBody(2));
    }

    public function testTheBodyIsWrittenLikeJsonStringify(): void
    {
        [$pr, $http] = $this->clientAnswering(self::PING, 201);
        $pr->broadcast('ab12', 'Deploy ✅ <b>&', data: ['url' => 'https://a.example/b/c', 'fa' => 'سلام', "ls" => "a\u{2028}b", 'q' => "x\"y\\z\n"]);
        self::assertSame(
            "{\"message\":\"Deploy ✅ <b>&\",\"data\":{\"url\":\"https://a.example/b/c\",\"fa\":\"سلام\",\"ls\":\"a\u{2028}b\",\"q\":\"x\\\"y\\\\z\\n\"}}",
            $http->rawBody(),
        );
    }

    public function testDataIsMeasuredTheWayTheServerMeasuresItBeforeAnyRequest(): void
    {
        [$pr, $http] = $this->clientAnswering(self::PING, 201);
        // 2,000 Persian characters are 4 KB of UTF-8 but 12 KB of json_encode output.
        $e = $this->thrown(fn() => $pr->broadcast('ab12', 'hi', data: ['note' => str_repeat('س', 2000)]));
        self::assertSame('invalid_data', $e->errorCode);
        self::assertSame('invalid_data', $this->thrown(fn() => $pr->broadcast('ab12', 'hi', data: ['path' => str_repeat('/', 4100)]))->errorCode);
        self::assertSame([], $http->requests);

        $pr->broadcast('ab12', 'hi', data: ['note' => str_repeat('a', 8000)]);
        self::assertCount(1, $http->requests);
    }

    public function testDataMustBeAnObjectWithAtMost25Keys(): void
    {
        [$pr, $http] = $this->clientAnswering(self::PING, 201);
        foreach ([[1, 2, 3], ['a', 'b']] as $list) {
            $e = $this->thrown(fn() => $pr->broadcast('ab12', 'hi', data: $list));
            self::assertSame('invalid_data', $e->errorCode);
            self::assertStringContainsString('JSON object', $e->getMessage());
        }
        $e = $this->thrown(fn() => $pr->broadcast('ab12', 'hi', data: array_combine(array_map(fn($i) => "k{$i}", range(1, 26)), range(1, 26))));
        self::assertStringContainsString('at most 25 keys', $e->getMessage());
        self::assertSame([], $http->requests);

        $pr->broadcast('ab12', 'hi', data: array_combine(array_map(fn($i) => "k{$i}", range(1, 25)), range(1, 25)));
        self::assertCount(1, $http->requests);
    }

    public function testDataThatCannotBeEncodedIsRefused(): void
    {
        [$pr, $http] = $this->clientAnswering(self::PING, 201);
        self::assertSame('invalid_data', $this->thrown(fn() => $pr->broadcast('ab12', 'hi', data: ['bad' => "\xB1\x31"]))->errorCode);
        self::assertSame('invalid_data', $this->thrown(fn() => $pr->broadcast('ab12', 'hi', data: ['bad' => NAN]))->errorCode);
        self::assertSame([], $http->requests);
    }

    public function testMessageAndTitleLimitsArePublicRoomCeilingsCountedInCodePoints(): void
    {
        [$pr, $http] = $this->clientAnswering(self::PING, 201);
        $message = str_repeat('m', 160);
        $title = str_repeat('t', 40);
        $pr->broadcast('public1', $message, title: $title);
        self::assertSame(['message' => $message, 'title' => $title], $http->jsonBody());

        // 160 emoji are 320 UTF-16 units but 160 characters.
        $pr->broadcast('public1', str_repeat('😀', 160));
        self::assertCount(2, $http->requests);

        $e = $this->thrown(fn() => $pr->broadcast('ab12', str_repeat('m', 161)));
        self::assertSame('invalid_request', $e->errorCode);
        self::assertMatchesRegularExpression('/message.*160.*161/', $e->getMessage());
        $e = $this->thrown(fn() => $pr->broadcast('ab12', 'ok', title: str_repeat('t', 41)));
        self::assertMatchesRegularExpression('/title.*40.*41/', $e->getMessage());
        self::assertCount(2, $http->requests);
    }

    public function testAnEmptyOrBlankMessageIsRefused(): void
    {
        [$pr, $http] = $this->clientAnswering(self::PING, 201);
        foreach (['', '   ', "\u{00A0}\u{FEFF}"] as $message) {
            self::assertSame('invalid_request', $this->thrown(fn() => $pr->broadcast('ab12', $message))->errorCode);
        }
        self::assertSame([], $http->requests);
    }

    public function testTheActionNumberIsAnIntegerFromOneToSixteen(): void
    {
        [$pr, $http] = $this->clientAnswering(self::PING, 201);
        foreach ([0, 17, -1] as $n) {
            self::assertSame('invalid_request', $this->thrown(fn() => $pr->broadcast('ab12', 'hi', actionNumber: $n))->errorCode);
        }
        self::assertSame([], $http->requests);
        $pr->broadcast('ab12', 'hi', actionNumber: 16);
        self::assertSame(16, $http->jsonBody()['action_number']);
    }

    public function testTagsAreSentAsGivenAndCheckedTheWayTheServerChecksThem(): void
    {
        [$pr, $http] = $this->clientAnswering(self::PING + ['tags' => ['deploy']], 201);
        $result = $pr->broadcast('ab12', 'hi', tags: ['Deploy ', 'deploy', 'prod_1']);
        self::assertSame(['deploy'], $result['tags']);
        // The server normalizes; the SDK does not rewrite the payload.
        self::assertSame(['Deploy ', 'deploy', 'prod_1'], $http->jsonBody()['tags']);

        $bad = [['bad tag'], ['a.b'], ['café'], ['-lead'], ['_lead'], [str_repeat('a', 33)], array_map(fn($i) => "t{$i}", range(0, 10)), ['deploy' => 'x'], [5], [null], [['deploy']]];
        foreach ($bad as $tags) {
            self::assertSame('invalid_request', $this->thrown(fn() => $pr->broadcast('ab12', 'hi', tags: $tags))->errorCode, json_encode($tags) ?: '');
        }
        self::assertCount(1, $http->requests);
    }

    public function testTagsAcceptTheLimitsAndWhatTheServerNormalizesAway(): void
    {
        [$pr, $http] = $this->clientAnswering(self::PING, 201);
        $pr->broadcast('ab12', 'hi', tags: array_map(fn($i) => str_repeat((string) $i, 32), range(0, 9)));
        // Eleven that collapse to ten once trimmed, lowercased and de-duplicated.
        $pr->broadcast('ab12', 'hi', tags: [...array_map(fn($i) => "t{$i}", range(0, 9)), ' T0 ']);
        // Blanks are dropped, as on the server.
        $pr->broadcast('ab12', 'hi', tags: ['', '  ', 'ok']);
        $pr->broadcast('ab12', 'hi', tags: []);
        $pr->broadcast('ab12', 'hi', tags: null);
        self::assertCount(5, $http->requests);
        self::assertSame([], $http->jsonBody(3)['tags']);
        self::assertArrayNotHasKey('tags', $http->jsonBody(4));
    }

    public function testAReplaceKeyIsSentAsReplaceKeyAndCheckedBeforeAnyRequest(): void
    {
        [$pr, $http] = $this->clientAnswering(self::PING + ['replaced' => true, 'edited_at' => '2026-11-11T12:00:00.000000Z'], 200);
        $result = $pr->broadcast('ab12', 'Build 41: green', replaceKey: 'build:41', idempotencyKey: 'k-1');
        self::assertTrue($result['replaced']);
        self::assertSame('{"message":"Build 41: green","replace_key":"build:41"}', $http->rawBody());

        foreach (['', 'has space', 'ünï', 'a/b', str_repeat('x', 65), "ok\n"] as $key) {
            self::assertSame('invalid_request', $this->thrown(fn() => $pr->broadcast('ab12', 'hi', replaceKey: $key))->errorCode, json_encode($key) ?: '');
        }
        self::assertCount(1, $http->requests);
        foreach (['a', str_repeat('x', 64), 'build.41_prod:eu-1', 'A9'] as $key) {
            $pr->broadcast('ab12', 'hi', replaceKey: $key);
        }
        self::assertCount(5, $http->requests);
    }

    public function testTheIdempotencyKeyIsAHeaderNeverBodyTrimmedAsPhpTrims(): void
    {
        [$pr, $http] = $this->clientAnswering(self::PING, 201);
        $pr->broadcast('AB12', 'Deploy shipped', idempotencyKey: 'deploy-1.4.0');
        $pr->broadcast('AB12', 'Deploy shipped');
        self::assertSame('deploy-1.4.0', $http->requests[0]->getHeaderLine('Idempotency-Key'));
        self::assertFalse($http->requests[1]->hasHeader('Idempotency-Key'));
        self::assertSame(['message' => 'Deploy shipped'], $http->jsonBody(0));

        $pr->broadcast('AB12', 'x', idempotencyKey: 'deploy 1.4.0 abc123');
        $pr->broadcast('AB12', 'x', idempotencyKey: " \t deploy-2\n");
        $pr->broadcast('AB12', 'x', idempotencyKey: ' ' . str_repeat('k', 255) . ' ');
        self::assertSame('deploy 1.4.0 abc123', $http->requests[2]->getHeaderLine('Idempotency-Key'));
        self::assertSame('deploy-2', $http->requests[3]->getHeaderLine('Idempotency-Key'));
        self::assertSame(str_repeat('k', 255), $http->requests[4]->getHeaderLine('Idempotency-Key'));

        // Every printable ASCII character is accepted.
        $printable = implode('', array_map('chr', range(0x21, 0x7E)));
        $pr->broadcast('AB12', 'x', idempotencyKey: "a {$printable}");
        self::assertSame("a {$printable}", $http->last()->getHeaderLine('Idempotency-Key'));
    }

    public function testAnIdempotencyKeyThatIsEmptyTooLongOrNotHeaderSafeIsRefusedAsInvalidRequest(): void
    {
        [$pr, $http] = $this->clientAnswering(self::PING, 201);
        foreach (['', '   ', str_repeat('k', 256), 'deploy-✅', 'ключ', 'café', "line\nbreak", "tab\there", "nul\0inside", "del\x7F"] as $key) {
            $e = $this->thrown(fn() => $pr->broadcast('AB12', 'x', idempotencyKey: $key));
            self::assertSame('invalid_request', $e->errorCode, json_encode($key) ?: '');
            self::assertStringContainsString('idempotencyKey', $e->getMessage());
        }
        self::assertSame([], $http->requests);
    }

    public function testButtonsInDataAreCheckedAndTheSigningSecretIsReturned(): void
    {
        $response = self::PING + ['action_signing_secret' => str_repeat('ab', 32), 'actions' => [['id' => 'a1', 'label' => 'Open dashboard', 'type' => 'open_url', 'style' => 'default', 'host' => 'grafana.example.com']]];
        [$pr, $http] = $this->clientAnswering($response, 201);
        $result = $pr->broadcast('AB12', 'Disk 91%', data: ['actions' => [
            PingAction::openUrl('Open dashboard', 'https://grafana.example.com/d/disk'),
            PingAction::webhook('Clean up now', 'https://ops.example.com/cleanup', id: 'clean', style: 'danger', body: ['host' => 'db-2']),
        ]]);

        self::assertSame(str_repeat('ab', 32), $result['action_signing_secret']);
        self::assertSame([
            ['label' => 'Open dashboard', 'type' => 'open_url', 'url' => 'https://grafana.example.com/d/disk'],
            ['label' => 'Clean up now', 'type' => 'webhook', 'url' => 'https://ops.example.com/cleanup', 'id' => 'clean', 'style' => 'danger', 'body' => ['host' => 'db-2']],
        ], $http->jsonBody()['data']['actions']);
    }

    public function testInvalidButtonsAreRefusedLocallyNamingEveryField(): void
    {
        [$pr, $http] = $this->clientAnswering(self::PING, 201);
        $open = ['label' => 'Open dashboard', 'type' => 'open_url', 'url' => 'https://grafana.example.com/d/disk'];

        $e = $this->thrown(fn() => $pr->broadcast('AB12', 'x', data: ['actions' => [['label' => 'paypal.com', 'type' => 'open_url', 'url' => 'http://x']]]));
        self::assertSame('invalid_data', $e->errorCode);
        self::assertMatchesRegularExpression('/data\.actions\.0\.label: .*data\.actions\.0\.url:/', $e->getMessage());

        self::assertStringContainsString('1 to 3 buttons', $this->thrown(fn() => $pr->broadcast('AB12', 'x', data: ['actions' => [$open, $open, $open, $open]]))->getMessage());
        self::assertStringContainsString('data.actions.1.id: Each button on a Ping needs its own id', $this->thrown(fn() => $pr->broadcast('AB12', 'x', data: ['actions' => [$open + ['id' => 'x'], ['id' => 'x'] + $open]]))->getMessage());
        self::assertStringContainsString('Unknown button field: method', $this->thrown(fn() => $pr->broadcast('AB12', 'x', data: ['actions' => [$open + ['method' => 'POST']]]))->getMessage());
        self::assertStringContainsString('must be a list', $this->thrown(fn() => $pr->broadcast('AB12', 'x', data: ['actions' => ['a' => $open]]))->getMessage());
        self::assertSame([], $http->requests);

        // `null` is absent, as on the server.
        $pr->broadcast('AB12', 'x', data: ['actions' => null]);
        self::assertCount(1, $http->requests);
    }

    public function testEveryButtonRefusalSurfacesItsStatusAndCode(): void
    {
        foreach ([[422, 'ping_buttons_agents_only'], [422, 'ping_buttons_unsupported_room'], [422, 'ping_buttons_with_ack'], [403, 'ping_buttons_require_pro']] as [$status, $code]) {
            [$pr] = $this->clientAnswering(['code' => $code, 'message' => $code], $status);
            $e = $this->thrown(fn() => $pr->broadcast('AB12', 'x', data: ['actions' => [['label' => 'Open', 'type' => 'open_url', 'url' => 'https://example.com/']]]));
            self::assertSame($status, $e->status);
            self::assertSame($code, $e->errorCode);
        }
    }

    public function testARateLimitIsRetryableAfterItsRetryAfter(): void
    {
        [$pr] = $this->clientWith(fn() => self::json(429, ['code' => 'public_room_rate_limited', 'message' => 'slow down'], ['Retry-After' => '3']));
        $e = $this->thrown(fn() => $pr->broadcast('AB12', 'x'));
        self::assertSame(429, $e->status);
        self::assertSame('public_room_rate_limited', $e->errorCode);
        self::assertSame(3, $e->retryAfter);
    }

    public function testAnInviteCodeIsPercentEncodedAsOneSegment(): void
    {
        [$pr, $http] = $this->clientAnswering(self::PING, 201);
        $pr->broadcast('ab 12/x', 'hi');
        self::assertSame('/api/agent/rooms/ab%2012%2Fx/notifications', $http->last()->getUri()->getPath());
    }
}
