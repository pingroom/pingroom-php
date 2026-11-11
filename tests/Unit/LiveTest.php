<?php

declare(strict_types=1);

namespace PingRoom\Tests\Unit;

use PingRoom\ErrorCodes;
use PingRoom\LiveStatus;
use PingRoom\Tests\Support\TestCase;

final class LiveTest extends TestCase
{
    private const NOTIFICATION_ID = '0198f3c2-6f0e-7d3a-9a55-3b1c2d4e5f60';

    public function testPushPostsTheBuilderBodyToTheRoomLivePath(): void
    {
        [$pr, $http] = $this->clientAnswering(['notification_id' => 'n1', 'correlation_id' => 'c', 'state' => 'started', 'idempotent' => false], 201);
        $result = $pr->live->push('AB12CD', LiveStatus::timer('c', ['state' => 'running', 'started_at' => 1794146700]));

        self::assertSame('started', $result['state']);
        $request = $http->last();
        self::assertSame(['POST', '/api/agent/rooms/AB12CD/live'], [$request->getMethod(), $request->getUri()->getPath()]);
        self::assertSame('{"correlation_id":"c","live_status":{"template":"timer","state":"running","started_at":1794146700}}', $http->rawBody());
    }

    public function testPushValidatesDataTagsAndTheCorrelationIdAndForwardsTags(): void
    {
        [$pr, $http] = $this->clientAnswering(['notification_id' => 'n1', 'state' => 'started'], 201);
        $pr->live->push('ab12', LiveStatus::progress('c1', ['state' => 'running', 'progress' => 0.1], ['tags' => ['nightly', 'prod']]));
        self::assertSame(['nightly', 'prod'], $http->jsonBody()['tags']);

        self::assertSame('invalid_request', $this->thrown(fn() => $pr->live->push('ab12', LiveStatus::done('c1', null, ['tags' => ['bad tag']])))->errorCode);
        self::assertSame('invalid_request', $this->thrown(fn() => $pr->live->push('ab12', ['live_status' => ['state' => 'running']]))->errorCode);
        self::assertSame('invalid_request', $this->thrown(fn() => $pr->live->push(' ', LiveStatus::done('c1')))->errorCode);
        self::assertSame('invalid_data', $this->thrown(fn() => $pr->live->push('ab12', LiveStatus::status('c1', ['state' => 'running'], ['data' => [1, 2]])))->errorCode);
        $e = $this->thrown(fn() => $pr->live->push('ab12', LiveStatus::status('c1', ['state' => 'running'], ['data' => ['actions' => [['label' => 'Open', 'type' => 'open_url', 'url' => 'https://example.com/']]]])));
        self::assertStringStartsWith('A live-progress stream cannot carry buttons.', $e->getMessage());
        self::assertCount(1, $http->requests);

        $pr->live->push('ab12', LiveStatus::status('c1', ['state' => 'running'], ['data' => []]));
        self::assertSame('{"correlation_id":"c1","live_status":{"template":"status","state":"running"},"data":{}}', $http->rawBody(1));
    }

    public function testGetReadsBackAStreamAndEncodesTheCorrelationId(): void
    {
        [$pr, $http] = $this->clientAnswering(['notification_id' => 'n1', 'correlation_id' => 'deploy/1', 'state' => 'running', 'progress' => 0.4, 'tags' => []]);
        $snapshot = $pr->live->get('AB12CD', 'deploy/1');
        self::assertNotNull($snapshot);
        self::assertSame(0.4, $snapshot['progress']);
        self::assertSame(['GET', '/api/agent/rooms/AB12CD/live/deploy%2F1'], [$http->last()->getMethod(), $http->last()->getUri()->getPath()]);
    }

    public function testGetResolvesNullOnlyForAGenuinelyAbsentStream(): void
    {
        [$pr] = $this->clientAnswering(['code' => 'stream_not_found', 'message' => 'No live stream for that correlation id in the last 24 hours.'], 404);
        self::assertNull($pr->live->get('AB12CD', 'corr-1'));

        // A mistyped or deleted room code is the route-binding 404. Reading it as "no
        // stream" would make a restarted producer open a new stream instead of failing.
        [$pr] = $this->clientAnswering(['message' => 'Resource not found.'], 404);
        $e = $this->thrown(fn() => $pr->live->get('NOPE99', 'corr-1'));
        self::assertSame(404, $e->status);
        self::assertNull($e->errorCode);

        // A permission failure must never be reported as an absent stream.
        [$pr] = $this->clientAnswering(['code' => 'room_not_granted', 'message' => 'not granted'], 403);
        $e = $this->thrown(fn() => $pr->live->get('AB12CD', 'corr-1'));
        self::assertSame([403, 'room_not_granted'], [$e->status, $e->errorCode]);
        self::assertContains('room_not_granted', ErrorCodes::ROOM_SCOPED_ERROR_CODES);
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function receipt(array $overrides = []): array
    {
        return [...[
            'notification_id' => self::NOTIFICATION_ID, 'correlation_id' => 'milk-1', 'state' => 'running', 'idempotent' => false,
            'claim' => ['status' => 'claimed', 'by' => ['id' => 'member-1', 'name' => 'Alex', 'image_url' => null], 'at' => 1794146700, 'late' => false],
        ], ...$overrides];
    }

    public function testClaimPostsTheActionToTheAgentTransitionRouteAndReturnsTheClaim(): void
    {
        [$pr, $http] = $this->clientAnswering($this->receipt());
        $result = $pr->live->claim('AB12CD', self::NOTIFICATION_ID, 'claim');

        self::assertSame(['POST', '/api/agent/rooms/AB12CD/live/' . self::NOTIFICATION_ID . '/claim'], [$http->last()->getMethod(), $http->last()->getUri()->getPath()]);
        self::assertSame('{"action":"claim"}', $http->rawBody());
        self::assertSame('Alex', $result['claim']['by']['name']);
        self::assertFalse($result['idempotent']);
    }

    public function testClaimAcceptsEachDocumentedTransitionAndIdempotentReceiptWithoutDroppingAdditiveFields(): void
    {
        foreach ([['claim', 'claimed', 'running'], ['release', 'open', 'running'], ['done', 'done', 'done'], ['cancel', 'cancelled', 'failed']] as [$action, $status, $state]) {
            foreach ([false, true] as $idempotent) {
                $receipt = $this->receipt(['state' => $state, 'idempotent' => $idempotent, 'extra' => 'future-field']);
                $receipt['claim']['status'] = $status;
                if ($action === 'release' || $action === 'cancel') {
                    $receipt['claim']['by'] = null;
                    $receipt['claim']['at'] = null;
                }
                [$pr, $http] = $this->clientAnswering($receipt);
                self::assertSame($receipt, $pr->live->claim('AB12CD', strtoupper(self::NOTIFICATION_ID), $action));
                self::assertCount(1, $http->requests);
            }
        }
    }

    public function testReleasingAfterTheDeadlineResolvesWithTheExpiredReceiptOnceAndKeepsAdditiveFields(): void
    {
        foreach ([false, true] as $idempotent) {
            $receipt = [
                'notification_id' => self::NOTIFICATION_ID, 'correlation_id' => 'milk-1', 'state' => 'failed', 'idempotent' => $idempotent, 'extra' => 'future-field',
                'claim' => ['status' => 'expired', 'by' => null, 'at' => null, 'late' => false, 'realerted_at' => 1794146000, 'ended_by' => 'member-1'],
            ];
            [$pr, $http] = $this->clientAnswering($receipt);
            $result = $pr->live->claim('AB12CD', self::NOTIFICATION_ID, 'release');
            self::assertSame($receipt, $result);
            self::assertSame('expired', $result['claim']['status']);
            self::assertSame('failed', $result['state']);
            self::assertSame('{"action":"release"}', $http->rawBody());
            self::assertCount(1, $http->requests);
        }
    }

    public function testTheExpiredReceiptIsReleasesAloneAndOnlyAsExpiredWithStateFailed(): void
    {
        $expired = [
            'notification_id' => self::NOTIFICATION_ID, 'correlation_id' => 'milk-1', 'state' => 'failed', 'idempotent' => false,
            'claim' => ['status' => 'expired', 'by' => null, 'at' => null, 'late' => false],
        ];
        $wrongPair = static fn(string $status, string $state): array => [...$expired, 'state' => $state, 'claim' => [...$expired['claim'], 'status' => $status]];
        $cases = [
            ['claim', $expired], ['done', $expired], ['cancel', $expired],
            ['release', $wrongPair('expired', 'running')], ['release', $wrongPair('expired', 'done')], ['release', $wrongPair('open', 'failed')],
            ['release', $wrongPair('unfinished', 'failed')], ['release', $wrongPair('cancelled', 'failed')],
            ['release', [...$expired, 'notification_id' => 'another-notification']],
            ['release', [...$expired, 'claim' => [...$expired['claim'], 'late' => 'false']]],
            ['release', [...$expired, 'claim' => [...$expired['claim'], 'at' => '1794146700']]],
        ];
        foreach ($cases as [$action, $body]) {
            [$pr, $http] = $this->clientAnswering($body);
            $e = $this->thrown(fn() => $pr->live->claim('AB12CD', self::NOTIFICATION_ID, $action));
            self::assertSame('invalid_response', $e->errorCode, $action . ' ' . json_encode($body));
            self::assertSame(0, $e->status);
            self::assertCount(1, $http->requests);
        }
    }

    public function testClaimRejectsMalformedOrMisattributedSuccessReceiptsWithoutReplayingThePost(): void
    {
        $withClaim = fn(array $change): array => $this->receipt(['claim' => [...$this->receipt()['claim'], ...$change]]);
        $withoutKey = function (string $key): array {
            $receipt = $this->receipt();
            unset($receipt[$key]);

            return $receipt;
        };
        $withoutClaimKey = function (string $key): array {
            $receipt = $this->receipt();
            unset($receipt['claim'][$key]);

            return $receipt;
        };
        $invalid = [
            'null', '[]', '{}', 'true', '1',
            $this->receipt(['notification_id' => 'another-notification']),
            $withoutKey('notification_id'),
            $this->receipt(['correlation_id' => '']),
            $this->receipt(['idempotent' => 'true']),
            $withoutKey('idempotent'),
            $this->receipt(['state' => 'done']),
            $this->receipt(['claim' => null]),
            $withClaim(['status' => 'open']),
            $withClaim(['status' => 'other']),
            $withClaim(['by' => ['name' => 'Alex']]),
            $withClaim(['by' => ['id' => 'member-1']]),
            $withClaim(['by' => 'Alex']),
            $withClaim(['at' => '1794146700']),
            $withClaim(['late' => 0]),
            $withoutClaimKey('at'),
            $withoutClaimKey('by'),
        ];
        foreach ($invalid as $body) {
            [$pr, $http] = $this->clientWith(fn() => self::raw(200, is_string($body) ? $body : json_encode($body, JSON_THROW_ON_ERROR)));
            $e = $this->thrown(fn() => $pr->live->claim('AB12CD', self::NOTIFICATION_ID, 'claim'));
            self::assertSame('invalid_response', $e->errorCode, is_string($body) ? $body : json_encode($body));
            self::assertSame(0, $e->status);
            self::assertCount(1, $http->requests, 'the SDK never repeats the transition POST');
        }
    }

    public function testClaimPreservesRefusalStatusCodeAndBodyWithoutReplayingThePost(): void
    {
        foreach ([[409, 'claim_conflict'], [422, 'claim_own_request'], [403, 'forbidden'], [404, 'claim_not_found']] as [$status, $code]) {
            $body = ['code' => $code, 'message' => 'Refused', ...(($status === 409 || $status === 422) ? ['claim' => $this->receipt()['claim'], 'state' => 'running'] : [])];
            [$pr, $http] = $this->clientAnswering($body, $status);
            $e = $this->thrown(fn() => $pr->live->claim('AB12CD', self::NOTIFICATION_ID, 'claim'));
            self::assertSame([$status, $code, $body], [$e->status, $e->errorCode, $e->body]);
            self::assertCount(1, $http->requests);
        }
    }

    public function testAConflictBodyNamesWhoGotItFirst(): void
    {
        $claim = ['status' => 'claimed', 'by' => ['id' => 'u2', 'name' => 'Sam'], 'at' => 1794146700, 'late' => false];
        [$pr] = $this->clientAnswering(['code' => 'claim_conflict', 'message' => 'Sam is already on it.', 'claim' => $claim, 'state' => 'running'], 409);
        $e = $this->thrown(fn() => $pr->live->claim('AB12CD', self::NOTIFICATION_ID, 'claim'));
        self::assertIsArray($e->body);
        self::assertSame('Sam', $e->body['claim']['by']['name']);
    }

    public function testClaimRefusesABlankIdAndAnUnsupportedActionBeforeAnyRequest(): void
    {
        [$pr, $http] = $this->clientAnswering([]);
        self::assertSame('invalid_request', $this->thrown(fn() => $pr->live->claim('AB12CD', '', 'done'))->errorCode);
        foreach (['', 'take', 'constructor', '__proto__', 'CLAIM'] as $action) {
            self::assertSame('invalid_request', $this->thrown(fn() => $pr->live->claim('AB12CD', self::NOTIFICATION_ID, $action))->errorCode, $action);
        }
        self::assertSame([], $http->requests);
    }

    public function testTheClaimErrorCodesAreExportedAsARuntimeList(): void
    {
        self::assertSame([
            'claim_conflict', 'claim_own_request', 'claim_private_rooms_only', 'claim_not_found',
            'claim_request_invalid', 'claim_must_start_running', 'claim_deadline_out_of_range',
        ], ErrorCodes::LIVE_CLAIM_ERROR_CODES);
    }
}
