<?php

declare(strict_types=1);

namespace PingRoom\Tests\Unit;

use PingRoom\ErrorCodes;
use PingRoom\Exception\PingRoomException;
use PingRoom\Tests\Support\FakeClock;
use PingRoom\Tests\Support\FakeNetworkException;
use PingRoom\Tests\Support\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class NotificationsTest extends TestCase
{
    private const PING = [
        'id' => 'n1',
        'message' => 'Build 41: green',
        'action_number' => null,
        'action_icon' => null,
        'trigger_source' => 'manual',
        'created_at' => 'now',
        'edited_at' => '2026-11-11T12:00:00.000000Z',
    ];

    public function testListSendsOnlyTheFiltersGivenAndAddsTheRoomCode(): void
    {
        [$pr, $http] = $this->clientAnswering([
            'current_page' => 1, 'per_page' => 5, 'total' => 2, 'last_page' => 1, 'next_page_url' => null, 'prev_page_url' => null,
            'data' => [
                ['id' => 'n1', 'room' => ['id' => 'r1', 'name' => 'A', 'invite_code' => 'ab12']],
                ['id' => 'n2'],
            ],
        ]);
        $page = $pr->notifications->list(type: 'received', roomId: '0197c3a0-0000-7000-8000-000000000001', tag: 'deploy', date: '2026-11-11', limit: 5, page: 2);

        $request = $http->last();
        self::assertSame('GET', $request->getMethod());
        self::assertSame('/api/agent/notifications', $request->getUri()->getPath());
        self::assertSame(
            ['type' => 'received', 'room_id' => '0197c3a0-0000-7000-8000-000000000001', 'tag' => 'deploy', 'date' => '2026-11-11', 'limit' => '5', 'page' => '2'],
            $http->query(),
        );
        // History keeps the full room and adds the listener-compatible `room.code`.
        self::assertSame('ab12', $page['data'][0]['room']['code']);
        self::assertSame('ab12', $page['data'][0]['room']['invite_code']);
        self::assertArrayNotHasKey('room', $page['data'][1]);
        self::assertSame(2, $page['total']);
    }

    public function testListLeavesTheQueryAloneWithoutFilters(): void
    {
        [$pr, $http] = $this->clientAnswering(['data' => []]);
        $pr->notifications->list();
        self::assertSame('', $http->last()->getUri()->getQuery());
        $pr->notifications->list(type: 'sent');
        self::assertSame('type=sent', $http->last()->getUri()->getQuery());
    }

    public function testTheQueryIsFormEncodedLikeUrlSearchParams(): void
    {
        [$pr, $http] = $this->clientAnswering(['data' => []]);
        // The server lowercases the tag filter, so the SDK accepts a padded, mixed-case one.
        $pr->notifications->list(tag: ' Deploy ', date: 'a b*c~d&é');
        self::assertSame('tag=+Deploy+&date=a+b*c%7Ed%26%C3%A9', $http->last()->getUri()->getQuery());
    }

    public function testListRefusesATagThatCannotExistBeforeAnyRequest(): void
    {
        [$pr, $http] = $this->clientAnswering(['data' => []]);
        foreach (['bad tag', ''] as $tag) {
            self::assertSame('invalid_request', $this->thrown(fn() => $pr->notifications->list(tag: $tag))->errorCode, $tag);
        }
        self::assertSame([], $http->requests);
    }

    public function testListThatGetsAnythingButAPageIsInvalidResponse(): void
    {
        [$pr] = $this->clientAnswering(['no' => 'data']);
        self::assertSame('invalid_response', $this->thrown(fn() => $pr->notifications->list())->errorCode);
    }

    public function testGetFetchesOneNotificationAndEncodesItsId(): void
    {
        [$pr, $http] = $this->clientAnswering(['id' => 'n/1 ?', 'action_state' => ['status' => 'open', 'requires_ack' => true]]);
        $notification = $pr->notifications->get('n/1 ?');
        self::assertSame('open', $notification['action_state']['status']);
        self::assertSame('/api/agent/notifications/n%2F1%20%3F', $http->last()->getUri()->getPath());
        self::assertSame('GET', $http->last()->getMethod());

        self::assertSame('invalid_request', $this->thrown(fn() => $pr->notifications->get(' '))->errorCode);
    }

    public function testWaitSendsCursorTimeoutLimitAndTagAndExtendsTheHttpTimeout(): void
    {
        [$pr, $http] = $this->clientAnswering(['notifications' => [['id' => 'n2', 'message' => 'yo']], 'cursor' => 'cur2']);
        $result = $pr->notifications->wait(after: 'cur1', timeout: 5, limit: 10, tag: 'billing');

        self::assertSame('cur2', $result['cursor']);
        self::assertSame('/api/agent/notifications/wait', $http->last()->getUri()->getPath());
        self::assertSame(['after' => 'cur1', 'timeout' => '5', 'limit' => '10', 'tag' => 'billing'], $http->query());

        self::assertSame('invalid_request', $this->thrown(fn() => $pr->notifications->wait(tag: 'bad tag'))->errorCode);
        self::assertCount(1, $http->requests);
    }

    public function testWaitForAcknowledgementSendsTimeoutAndReturnsTheState(): void
    {
        [$pr, $http] = $this->clientAnswering(['id' => 'n/1', 'action_state' => ['status' => 'acked', 'requires_ack' => true, 'acked_by' => ['id' => 'u1', 'name' => 'Sam', 'profile_image' => null], 'acked_at' => 'now', 'expires_at' => null]]);
        $result = $pr->notifications->waitForAcknowledgement('n/1', timeout: 7);

        self::assertSame('acked', $result['action_state']['status']);
        self::assertSame('Sam', $result['action_state']['acked_by']['name']);
        self::assertSame('/api/agent/notifications/n%2F1/ack/wait', $http->last()->getUri()->getPath());
        self::assertSame(['timeout' => '7'], $http->query());
    }

    public function testReplacePutsTheNewTextAndOmitsTitleAndDataItWasNotGiven(): void
    {
        [$pr, $http] = $this->clientAnswering(self::PING);
        $result = $pr->notifications->replace('ab12', 'n1', 'Build 41: green');

        self::assertSame('2026-11-11T12:00:00.000000Z', $result['edited_at']);
        $request = $http->last();
        self::assertSame('PUT', $request->getMethod());
        self::assertSame('/api/agent/rooms/ab12/notifications/n1', $request->getUri()->getPath());
        self::assertSame('{"message":"Build 41: green"}', $http->rawBody());
        self::assertFalse($request->hasHeader('Idempotency-Key'), 'a replacement has no idempotency key');
    }

    public function testReplaceSendsTitleAndDataAsGivenAndNullClearsThem(): void
    {
        [$pr, $http] = $this->clientAnswering(self::PING);
        $pr->notifications->replace('ab12', 'n1', 'm', title: 'Deploy', data: ['build' => 41]);
        $pr->notifications->replace('ab12', 'n1', 'm', title: null, data: null);
        $pr->notifications->replace('ab12', 'n1', 'm', title: '');
        $pr->notifications->replace('ab12', 'n1', 'm', data: []);

        self::assertSame('{"message":"m","title":"Deploy","data":{"build":41}}', $http->rawBody(0));
        self::assertSame('{"message":"m","title":null,"data":null}', $http->rawBody(1));
        self::assertSame('{"message":"m","title":""}', $http->rawBody(2));
        self::assertSame('{"message":"m","data":{}}', $http->rawBody(3));
    }

    public function testReplaceEncodesBothSegmentsAndRefusesEmptyOnesBeforeAnyRequest(): void
    {
        [$pr, $http] = $this->clientAnswering(self::PING);
        $pr->notifications->replace('ab 12', 'n/1', 'm');
        self::assertSame('/api/agent/rooms/ab%2012/notifications/n%2F1', $http->last()->getUri()->getPath());

        foreach ([fn() => $pr->notifications->replace('', 'n1', 'm'), fn() => $pr->notifications->replace('ab12', ' ', 'm'), fn() => $pr->notifications->replace('ab12', 'n1', '')] as $attempt) {
            self::assertSame('invalid_request', $this->thrown($attempt)->errorCode);
        }
        self::assertCount(1, $http->requests);
    }

    public function testReplaceChecksLengthDataAndButtonsTheWayASendDoes(): void
    {
        [$pr, $http] = $this->clientAnswering(self::PING);
        self::assertSame('invalid_request', $this->thrown(fn() => $pr->notifications->replace('ab12', 'n1', str_repeat('m', 161)))->errorCode);
        self::assertSame('invalid_request', $this->thrown(fn() => $pr->notifications->replace('ab12', 'n1', 'm', title: str_repeat('t', 41)))->errorCode);
        self::assertSame('invalid_data', $this->thrown(fn() => $pr->notifications->replace('ab12', 'n1', 'm', data: [1]))->errorCode);
        $e = $this->thrown(fn() => $pr->notifications->replace('ab12', 'n1', 'm', data: ['actions' => [['label' => 'Open', 'type' => 'open_url', 'url' => 'https://example.com/']]]));
        self::assertSame('invalid_data', $e->errorCode);
        self::assertStringStartsWith('A replacement cannot carry buttons.', $e->getMessage());
        self::assertSame([], $http->requests);
    }

    public function testEveryReplaceRefusalSurfacesItsStatusAndCode(): void
    {
        $refusals = [
            [404, 'notification_not_found'], [403, 'not_ping_owner'], [422, 'unsupported_room'], [422, 'unsupported_question'],
            [422, 'unsupported_handoff'], [422, 'unsupported_live_status'], [422, 'unsupported_claim'], [422, 'unsupported_ack'],
            [422, 'unsupported_checklist'], [422, 'unsupported_source'], [422, 'unsupported_actions'], [409, 'replace_window_closed'],
            [409, 'replace_limit_reached'], [409, 'ping_withdrawn'],
        ];
        self::assertSame(array_column($refusals, 1), ErrorCodes::REPLACE_ERROR_CODES);
        foreach ($refusals as [$status, $code]) {
            [$pr] = $this->clientAnswering(['code' => $code, 'message' => "refused: {$code}"], $status);
            $e = $this->thrown(fn() => $pr->notifications->replace('ab12', 'n1', 'm'));
            self::assertSame($status, $e->status);
            self::assertSame($code, $e->errorCode);
        }
    }

    public function testACodelessReplace404MeansTheServerHasReplacingOff(): void
    {
        [$pr] = $this->clientAnswering(['message' => 'The route could not be found.'], 404);
        $e = $this->thrown(fn() => $pr->notifications->replace('ab12', 'n1', 'm'));
        self::assertSame(404, $e->status);
        self::assertNull($e->errorCode);
    }

    public function testWithdrawPostsAnEmptyObjectToTheWithdrawRoute(): void
    {
        [$pr, $http] = $this->clientAnswering(['id' => 'n1', 'message' => 'Deploy started', 'withdrawn_at' => '2026-11-11T12:00:00.000000Z']);
        $result = $pr->notifications->withdraw('ab12', 'n1');

        self::assertSame('2026-11-11T12:00:00.000000Z', $result['withdrawn_at']);
        $request = $http->last();
        self::assertSame('POST', $request->getMethod());
        self::assertSame('/api/agent/rooms/ab12/notifications/n1/withdraw', $request->getUri()->getPath());
        self::assertSame('{}', $http->rawBody());
        self::assertFalse($request->hasHeader('Idempotency-Key'));

        $pr->notifications->withdraw('ab 12', 'n/1');
        self::assertSame('/api/agent/rooms/ab%2012/notifications/n%2F1/withdraw', $http->last()->getUri()->getPath());
        self::assertSame('invalid_request', $this->thrown(fn() => $pr->notifications->withdraw('', 'n1'))->errorCode);
        self::assertSame('invalid_request', $this->thrown(fn() => $pr->notifications->withdraw('ab12', ' '))->errorCode);
        self::assertCount(2, $http->requests);
    }

    public function testEveryWithdrawRefusalSurfacesItsStatusAndCode(): void
    {
        $refusals = [
            [404, 'notification_not_found'], [403, 'not_ping_owner'], [422, 'unsupported_room'], [422, 'unsupported_question'],
            [422, 'unsupported_handoff'], [422, 'unsupported_live_status'], [422, 'unsupported_claim'], [422, 'unsupported_ack'],
            [422, 'unsupported_checklist'], [422, 'unsupported_source'], [409, 'withdraw_window_closed'],
        ];
        self::assertSame(array_column($refusals, 1), ErrorCodes::WITHDRAW_ERROR_CODES);
        foreach ($refusals as [$status, $code]) {
            [$pr] = $this->clientAnswering(['code' => $code, 'message' => $code], $status);
            $e = $this->thrown(fn() => $pr->notifications->withdraw('ab12', 'n1'));
            self::assertSame([$status, $code], [$e->status, $e->errorCode]);
        }
    }

    public function testListenReadsTheHeadThenYieldsAcrossPollsAndStopsWhenTheCallerBreaks(): void
    {
        $n = 0;
        [$pr, $http] = $this->clientWith(function () use (&$n): ResponseInterface {
            ++$n;
            if ($n === 1) {
                return self::json(200, ['notifications' => [], 'cursor' => 'c0']);
            }

            return self::json(200, ['notifications' => [['id' => $n === 2 ? 'a' : 'b', 'message' => (string) $n]], 'cursor' => $n === 2 ? 'c1' : 'c2']);
        });

        $got = [];
        foreach ($pr->notifications->listen() as $ping) {
            $got[] = $ping['id'];
            if (count($got) >= 2) {
                break;
            }
        }
        self::assertSame(['a', 'b'], $got);
        // The head read has no cursor and a zero hold; each later poll carries the cursor.
        self::assertSame(['timeout' => '0'], $http->query(0));
        self::assertSame(['after' => 'c0'], $http->query(1));
        self::assertSame(['after' => 'c1'], $http->query(2));
    }

    public function testListenPollsWithTheGivenCursorTagLimitAndTimeout(): void
    {
        $clock = new FakeClock();
        [$pr, $http] = $this->clientWith(fn() => self::json(200, ['notifications' => [], 'cursor' => 'cur1']), $clock);
        $stop = false;
        $count = 0;
        foreach ($pr->notifications->listen(after: 'cur1', timeout: 3, limit: 7, tag: 'billing', isCancelled: function () use (&$stop, &$count): bool {
            return $stop = ++$count > 2;
        }) as $ignored) {
            self::fail('no ping expected');
        }
        self::assertTrue($stop);
        self::assertSame(['after' => 'cur1', 'timeout' => '3', 'limit' => '7', 'tag' => 'billing'], $http->query(0));
    }

    public function testListenRejectsABadTagAtTheCall(): void
    {
        [$pr, $http] = $this->clientAnswering(['notifications' => [], 'cursor' => 'c']);
        $e = $this->thrown(function () use ($pr): void {
            $pr->notifications->listen(tag: 'bad tag');
        });
        self::assertSame('invalid_request', $e->errorCode);
        self::assertSame([], $http->requests);
    }

    public function testListenEndsByThrowingA401EvenWithOnErrorInsteadOfRetryingARevokedCredential(): void
    {
        [$pr, $http] = $this->clientAnswering(['message' => 'Unauthenticated.'], 401);
        $handled = [];
        $e = $this->thrown(function () use ($pr, &$handled): void {
            foreach ($pr->notifications->listen(after: 'c0', backoffMs: 1, onError: function (PingRoomException $error) use (&$handled): void {
                $handled[] = $error;
            }) as $ignored) {
                // none
            }
        });
        self::assertSame(401, $e->status);
        self::assertCount(1, $http->requests);
        self::assertSame([], $handled);
    }

    public function testListenPassesOnlyTransientFailuresToOnErrorBacksOffAndKeepsDelivering(): void
    {
        $clock = new FakeClock();
        $script = [
            fn(): ResponseInterface => self::json(503, []),
            fn(): ResponseInterface => throw new \RuntimeException('fetch failed'),
            fn(): ResponseInterface => self::json(200, ['notifications' => [['id' => 'a']], 'cursor' => 'c1']),
        ];
        [$pr, $http] = $this->clientWith(function (RequestInterface $request, int $n) use ($script, $clock): ResponseInterface {
            $times[] = $clock->nowMs();

            return $script[min($n, count($script)) - 1]();
        }, $clock);

        $handled = [];
        $got = [];
        foreach ($pr->notifications->listen(after: 'c0', backoffMs: 400, onError: function (PingRoomException $e) use (&$handled): void {
            $handled[] = [$e->status, $e->errorCode];
        }) as $ping) {
            $got[] = $ping['id'];
            break;
        }

        self::assertSame(['a'], $got);
        self::assertSame([[503, null], [0, 'network_error']], $handled);
        // The 400 and 800 ms backoff steps must also respect the 2.1 s poll interval.
        self::assertSame([2100, 2100], $clock->sleeps);
        self::assertCount(3, $http->requests);
    }

    public function testListenWaitsOutARetryAfterLongerThanThePollInterval(): void
    {
        $clock = new FakeClock();
        [$pr] = $this->clientWith(function (RequestInterface $request, int $n): ResponseInterface {
            return $n === 1
                ? self::json(429, ['code' => 'rate_limited', 'message' => 'Too Many Attempts.', 'retry_after' => 3])
                : self::json(200, ['notifications' => [['id' => 'b']], 'cursor' => 'c2']);
        }, $clock);

        $got = [];
        foreach ($pr->notifications->listen(after: 'c0', backoffMs: 1) as $ping) {
            $got[] = $ping['id'];
            break;
        }
        self::assertSame(['b'], $got);
        self::assertSame([3000], $clock->sleeps);
    }

    public function testListenRetriesATransientFailureOfItsFirstHeadRead(): void
    {
        $clock = new FakeClock();
        [$pr, $http] = $this->clientWith(function (RequestInterface $request, int $n): ResponseInterface {
            return match ($n) {
                1 => self::json(502, []),
                2 => self::json(200, ['notifications' => [], 'cursor' => 'head']),
                default => self::json(200, ['notifications' => [['id' => 'c']], 'cursor' => 'c3']),
            };
        }, $clock);

        $got = [];
        foreach ($pr->notifications->listen() as $ping) {
            $got[] = $ping['id'];
            break;
        }
        self::assertSame(['c'], $got);
        self::assertSame(['timeout' => '0'], $http->query(1));
        self::assertSame(['after' => 'head'], $http->query(2));
    }

    public function testListenCombinesThePollIntervalWithAGrowingBackoffForSmallBackoffValues(): void
    {
        // A base of 0 never grew, and 1 reached only 32 ms: about 31 requests a second
        // against a route limited to 30 a minute. Every base below the floor behaves alike.
        foreach ([0, 1, 40, 250] as $backoffMs) {
            $gaps = $this->gapsWhileFailing($backoffMs, 100_000);
            self::assertSame([2100, 2100, 2100, 2100, 4000, 8000, 16000, 30000, 30000], $gaps, "backoffMs {$backoffMs}");
        }
    }

    public function testListenKeepsABackoffAbovePollIntervalAndStillCapsAt30Seconds(): void
    {
        self::assertSame([5000, 10000, 20000, 30000, 30000], $this->gapsWhileFailing(5000, 100_000));
    }

    public function testListenStaysBelow30PollsPerMinuteAcrossAlternatingFailuresAndEmptyResponses(): void
    {
        $clock = new FakeClock();
        $times = [];
        [$pr, $http] = $this->clientWith(function (RequestInterface $request, int $n) use ($clock, &$times): ResponseInterface {
            $times[] = $clock->nowMs();

            return $n % 2 === 1 ? self::json(503, []) : self::json(200, ['notifications' => [], 'cursor' => 'c0']);
        }, $clock);
        $stopAt = $clock->nowMs() + 60_000;
        foreach ($pr->notifications->listen(after: 'c0', timeout: 0, isCancelled: fn(): bool => $clock->nowMs() >= $stopAt) as $ignored) {
            // none
        }
        self::assertCount(29, $http->requests);
        $gaps = [];
        foreach (array_slice($times, 1) as $i => $time) {
            $gaps[] = $time - $times[$i];
        }
        self::assertSame(array_fill(0, 28, 2100), $gaps);
    }

    public function testListenDoesNotSpinWhenTheServerAnswersAtOnceWithAnEmptyBatch(): void
    {
        $clock = new FakeClock();
        [$pr, $http] = $this->clientWith(fn() => self::json(200, ['notifications' => [], 'cursor' => 'c0']), $clock);
        $stopAt = $clock->nowMs() + 10_000;
        foreach ($pr->notifications->listen(after: 'c0', timeout: 0, isCancelled: fn(): bool => $clock->nowMs() >= $stopAt) as $ignored) {
            // none
        }
        // The route allows 30 a minute per human, shared with their other listeners:
        // 10 s of 2.1 s spacing is 5 polls, not hundreds.
        self::assertCount(5, $http->requests);
    }

    public function testListenStartsEmptyPollsAtLeast2100MsApartAndRepollsANonEmptyBatchAtOnce(): void
    {
        $clock = new FakeClock();
        $times = [];
        [$pr, $http] = $this->clientWith(function (RequestInterface $request, int $n) use ($clock, &$times): ResponseInterface {
            $times[] = $clock->nowMs();

            return $n === 1
                ? self::json(200, ['notifications' => [['id' => 'a']], 'cursor' => 'c1'])
                : self::json(200, ['notifications' => [], 'cursor' => 'c1']);
        }, $clock);
        $got = [];
        foreach ($pr->notifications->listen(after: 'c0', timeout: 0, isCancelled: function () use (&$times): bool {
            return count($times) >= 3;
        }) as $ping) {
            $got[] = $ping['id'];
        }
        self::assertSame(['a'], $got);
        self::assertCount(3, $times);
        self::assertSame(0, $times[1] - $times[0], 'a non-empty batch is followed by an immediate poll');
        self::assertSame(2100, $times[2] - $times[1]);
    }

    public function testListenTurnsA2xxThatIsNotAWaitAnswerIntoInvalidResponse(): void
    {
        $bodies = [
            '<html>Please sign in to the hotspot</html>',
            '{}',
            '{"notifications":"none","cursor":"c1"}',
            '{"notifications":[null],"cursor":"c1"}',
            '{"notifications":[],"cursor":7}',
            '{"notifications":[],"cursor":null}',
            '{"notifications":{"a":1},"cursor":"c1"}',
        ];
        foreach ($bodies as $body) {
            [$pr, $http] = $this->clientWith(fn() => self::raw(200, $body));
            $e = $this->thrown(function () use ($pr): void {
                foreach ($pr->notifications->listen(after: 'c0') as $ignored) {
                    // none
                }
            });
            self::assertSame('invalid_response', $e->errorCode, $body);
            self::assertSame(0, $e->status);
            self::assertCount(1, $http->requests);
        }

        // The initial head read exists to obtain a cursor: one without it is invalid.
        [$pr] = $this->clientAnswering(['notifications' => []]);
        $e = $this->thrown(function () use ($pr): void {
            foreach ($pr->notifications->listen() as $ignored) {
                // none
            }
        });
        self::assertSame('invalid_response', $e->errorCode);
    }

    public function testListenAbortingDuringTheRetryIntervalEndsItQuietly(): void
    {
        $clock = new FakeClock();
        [$pr, $http] = $this->clientWith(fn() => self::json(503, []), $clock);
        $stopAt = $clock->nowMs() + 1500;
        $result = [];
        foreach ($pr->notifications->listen(after: 'c0', backoffMs: 0, isCancelled: fn(): bool => $clock->nowMs() >= $stopAt) as $ping) {
            $result[] = $ping;
        }
        self::assertSame([], $result);
        self::assertCount(1, $http->requests);
        self::assertSame(1500, array_sum($clock->sleeps), 'the sleep stops at the caller\'s deadline, in slices');
    }

    public function testAnErrorAFailedConnectionRaisesIsRetriedByListenAsTransient(): void
    {
        $clock = new FakeClock();
        [$pr] = $this->clientWith(function (RequestInterface $request, int $n): ResponseInterface {
            if ($n === 1) {
                throw new FakeNetworkException('connection reset', $request);
            }

            return self::json(200, ['notifications' => [['id' => 'z']], 'cursor' => 'c9']);
        }, $clock);
        $got = [];
        foreach ($pr->notifications->listen(after: 'c0') as $ping) {
            $got[] = $ping['id'];
            break;
        }
        self::assertSame(['z'], $got);
    }

    /**
     * The gaps between the requests of a listener whose every poll fails with a 503,
     * until `$forMs` of fake time have passed.
     *
     * @return list<int>
     */
    private function gapsWhileFailing(int $backoffMs, int $forMs): array
    {
        $clock = new FakeClock();
        $times = [];
        [$pr] = $this->clientWith(function () use ($clock, &$times): ResponseInterface {
            $times[] = $clock->nowMs();

            return self::json(503, []);
        }, $clock);
        $stopAt = $clock->nowMs() + $forMs;
        foreach ($pr->notifications->listen(after: 'c0', backoffMs: $backoffMs, isCancelled: fn(): bool => $clock->nowMs() >= $stopAt) as $ignored) {
            // none
        }
        $gaps = [];
        foreach (array_slice($times, 1) as $i => $time) {
            $gaps[] = $time - $times[$i];
        }

        return $gaps;
    }
}
