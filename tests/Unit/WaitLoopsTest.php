<?php

declare(strict_types=1);

namespace PingRoom\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PingRoom\Client;
use PingRoom\Exception\NetworkException;
use PingRoom\Exception\PingRoomException;
use PingRoom\Exception\TimeoutException;
use PingRoom\Internal\Poller;
use PingRoom\Tests\Support\FakeClock;
use PingRoom\Tests\Support\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * The three blocking waits (`approvals->waitForDecision()`, `questions->waitForAnswer()`,
 * `handoffs->waitForResult()`) share one loop: the same backoff, spacing, Retry-After
 * handling and give-up rule as the TypeScript SDK. A fake clock stands in for time, so the
 * 15-minute bounds cost nothing.
 */
final class WaitLoopsTest extends TestCase
{
    private const MINUTE = 60_000;

    /**
     * @return array<string, array{array{name: string, what: string, path: string}}>
     */
    public static function loops(): array
    {
        return [
            'approvals.waitForDecision' => [['name' => 'approval', 'what' => 'approval appr-1', 'path' => '/api/agent/approvals/appr-1/wait']],
            'questions.waitForAnswer' => [['name' => 'question', 'what' => 'question q-1', 'path' => '/api/agent/questions/q-1/wait']],
            'handoffs.waitForResult' => [['name' => 'handoff', 'what' => 'handoff h-1', 'path' => '/api/agent/handoffs/h-1/wait']],
        ];
    }

    /**
     * @param array{name: string, what: string, path: string} $loop
     *
     * @return array<string, mixed>
     */
    private function pending(array $loop, ?string $expiresAt = null): array
    {
        return match ($loop['name']) {
            'approval' => ['id' => 'appr-1', 'status' => 'pending', 'decision' => null, 'decided_at' => null, 'expires_at' => $expiresAt],
            'question' => ['id' => 'q-1', 'kind' => 'question', 'state' => 'pending', 'answer' => null, 'expires_at' => $expiresAt],
            default => ['id' => 'h-1', 'kind' => 'ack', 'state' => 'open', 'expires_at' => $expiresAt],
        };
    }

    /**
     * @param array{name: string, what: string, path: string} $loop
     *
     * @return array<string, mixed>
     */
    private function done(array $loop): array
    {
        return match ($loop['name']) {
            'approval' => ['id' => 'appr-1', 'status' => 'decided', 'decision' => 'approve', 'decided_at' => '2026-10-27T10:00:00+00:00', 'expires_at' => null],
            'question' => ['id' => 'q-1', 'kind' => 'question', 'state' => 'answered', 'answer' => ['value' => 'ship', 'label' => 'Ship'], 'expires_at' => null],
            default => ['id' => 'h-1', 'kind' => 'ack', 'state' => 'acked', 'acked_at' => '2026-10-27T10:00:00+00:00', 'expires_at' => null],
        };
    }

    /**
     * @param array{name: string, what: string, path: string} $loop
     * @param (callable(): bool)|null                          $isCancelled
     * @param (callable(PingRoomException, int): void)|null    $onRetry
     *
     * @return array<string, mixed>
     */
    private function waitFor(Client $pr, array $loop, ?int $timeout = null, ?callable $onRetry = null, ?callable $isCancelled = null): array
    {
        return match ($loop['name']) {
            'approval' => $pr->approvals->waitForDecision('appr-1', timeout: $timeout, onRetry: $onRetry, isCancelled: $isCancelled),
            'question' => $pr->questions->waitForAnswer('q-1', timeout: $timeout, onRetry: $onRetry, isCancelled: $isCancelled),
            default => $pr->handoffs->waitForResult('h-1', timeout: $timeout, onRetry: $onRetry, isCancelled: $isCancelled),
        };
    }

    /**
     * A transport whose answer is chosen by `$respond($n, $nowMs)` for the n-th request
     * (1-based); a Throwable it returns is thrown, simulating a dropped connection.
     *
     * @param callable(int, int): (ResponseInterface|\Throwable) $respond
     *
     * @return array{Client, FakeClock, \ArrayObject<int, array{at: int, path: string}>}
     */
    private function transport(callable $respond): array
    {
        $clock = new FakeClock();
        /** @var \ArrayObject<int, array{at: int, path: string}> $requests */
        $requests = new \ArrayObject();
        [$pr] = $this->clientWith(function (RequestInterface $request, int $n) use ($respond, $clock, $requests): ResponseInterface {
            $requests[] = ['at' => $clock->nowMs(), 'path' => $request->getUri()->getPath()];
            $answer = $respond($n, $clock->nowMs());
            if ($answer instanceof \Throwable) {
                throw $answer;
            }

            return $answer;
        }, $clock);

        return [$pr, $clock, $requests];
    }

    /** A cancellable wait looks at the flag every quarter second, so it stops within one slice of the caller's moment. */
    private function assertStoppedPromptlyAt(int $stopAt, FakeClock $clock): void
    {
        self::assertGreaterThanOrEqual($stopAt, $clock->nowMs());
        self::assertLessThan($stopAt + 250, $clock->nowMs());
    }

    private function unavailable503(): ResponseInterface
    {
        return self::raw(503, '<html>Service Unavailable</html>');
    }

    private function iso(int $ms): string
    {
        return gmdate('Y-m-d\TH:i:s', intdiv($ms, 1000)) . sprintf('.%03dZ', $ms % 1000);
    }

    public function testTheOutageBoundsMatchTheCliExpiryPlusFiveMinutesOrFifteenMinutesOfFailures(): void
    {
        self::assertSame(5 * self::MINUTE, Poller::WAIT_EXPIRY_GRACE_MS);
        self::assertSame(15 * self::MINUTE, Poller::WAIT_UNKNOWN_EXPIRY_OUTAGE_MS);
        self::assertSame(2100, Poller::MIN_POLL_INTERVAL_MS);
    }

    #[DataProvider('loops')]
    public function testItDoesTheRequestTheTypeScriptSdkDoes(array $loop): void
    {
        [$pr, , $requests] = $this->transport(fn(): ResponseInterface => self::json(200, $this->done($loop)));
        $result = $this->waitFor($pr, $loop);
        self::assertSame($this->done($loop), $result);
        self::assertSame([['at' => 1_794_146_700_000, 'path' => $loop['path']]], $requests->getArrayCopy());
    }

    #[DataProvider('loops')]
    public function testItSendsTheHoldTimeoutWhenGivenAndExtendsNothingElse(array $loop): void
    {
        $http = null;
        [$pr, $http] = $this->clientAnswering($this->done($loop));
        $this->waitFor($pr, $loop, timeout: 5);
        self::assertSame(['timeout' => '5'], $http->query());

        [$pr, $http] = $this->clientAnswering($this->done($loop));
        $this->waitFor($pr, $loop);
        self::assertSame('', $http->last()->getUri()->getQuery());
    }

    #[DataProvider('loops')]
    public function testWithoutACancelFlagItGivesUpAfterFifteenMinutesOfUnbrokenFailuresWhenNoExpiryWasSeen(array $loop): void
    {
        [$pr, $clock, $requests] = $this->transport(fn() => $this->unavailable503());
        $start = $clock->nowMs();
        $e = $this->thrown(fn() => $this->waitFor($pr, $loop));

        self::assertSame('unavailable', $e->errorCode);
        self::assertSame(0, $e->status);
        $cause = $e->getPrevious();
        self::assertInstanceOf(PingRoomException::class, $cause);
        self::assertSame(503, $cause->status);
        self::assertStringContainsString("stopped waiting for {$loop['what']}", $e->getMessage());
        self::assertStringContainsString('PingRoom unavailable since ' . $this->iso($start) . ' (HTTP 503)', $e->getMessage());
        // Exactly the bound: the last wait is cut short to land on it, with no early retry after.
        self::assertSame(15 * self::MINUTE, $clock->nowMs() - $start);
        self::assertLessThanOrEqual(30_000, $clock->nowMs() - $requests[count($requests) - 1]['at']);
    }

    #[DataProvider('loops')]
    public function testItGivesUpAtTheLastSeenExpiresAtPlusFiveMinutesNotBefore(array $loop): void
    {
        $clock = new FakeClock();
        $expiresAt = $clock->nowMs() + 30 * self::MINUTE;
        [$pr, $clock] = $this->transport(fn(int $n): ResponseInterface => $n === 1
            ? self::json(200, $this->pending($loop, $this->iso($expiresAt)))
            : $this->unavailable503());
        $e = $this->thrown(fn() => $this->waitFor($pr, $loop));

        self::assertSame('unavailable', $e->errorCode);
        // The first response was valid and 5 min past the 30-minute expiry is well past the
        // 15-minute unknown-expiry cap, so this proves the expiry is what is used.
        self::assertSame($expiresAt + 5 * self::MINUTE, $clock->nowMs());
    }

    #[DataProvider('loops')]
    public function testWithAnExpirySoonItGivesUpBeforeTheFifteenMinuteCap(array $loop): void
    {
        $expiresAt = (new FakeClock())->nowMs() + self::MINUTE;
        [$pr, $clock] = $this->transport(fn(int $n) => $n === 1
            ? self::json(200, $this->pending($loop, $this->iso($expiresAt)))
            : new \RuntimeException('fetch failed'));
        $e = $this->thrown(fn() => $this->waitFor($pr, $loop));

        self::assertSame('unavailable', $e->errorCode);
        self::assertInstanceOf(NetworkException::class, $e->getPrevious());
        self::assertSame('network_error', $e->getPrevious()->errorCode);
        self::assertStringContainsString('(network_error)', $e->getMessage());
        self::assertSame($expiresAt + 5 * self::MINUTE, $clock->nowMs());
    }

    #[DataProvider('loops')]
    public function testAnyValidResponseRestartsTheOutageClock(array $loop): void
    {
        // 10 min of 503, one pending read with no expiry, 10 more min of 503, then the
        // outcome. No unbroken 15-minute outage, so the wait never gives up.
        $recovered = false;
        $start = (new FakeClock())->nowMs();
        [$pr, $clock] = $this->transport(function (int $n, int $now) use (&$recovered, $loop, $start): ResponseInterface {
            $elapsed = $now - $start;
            if ($elapsed < 10 * self::MINUTE) {
                return $this->unavailable503();
            }
            if (!$recovered) {
                $recovered = true;

                return self::json(200, $this->pending($loop));
            }

            return $elapsed < 21 * self::MINUTE ? $this->unavailable503() : self::json(200, $this->done($loop));
        });
        self::assertSame($this->done($loop), $this->waitFor($pr, $loop));
        self::assertGreaterThanOrEqual(21 * self::MINUTE, $clock->nowMs() - $start);
    }

    #[DataProvider('loops')]
    public function testItReportsEachRetryToOnRetryWithTheConsecutiveAttemptNumber(array $loop): void
    {
        $script = [$this->unavailable503(), $this->unavailable503(), self::json(200, $this->pending($loop)), $this->unavailable503(), self::json(200, $this->done($loop))];
        [$pr] = $this->transport(fn(int $n): ResponseInterface => $script[min($n, count($script)) - 1]);
        $seen = [];
        $result = $this->waitFor($pr, $loop, onRetry: function (PingRoomException $e, int $attempt) use (&$seen): void {
            $seen[] = [$e->status, $attempt];
        });
        self::assertSame($this->done($loop), $result);
        // The count restarts after the pending read in the middle.
        self::assertSame([[503, 1], [503, 2], [503, 1]], $seen);
    }

    #[DataProvider('loops')]
    public function testItEndsWithTheErrorOnRetryThrows(array $loop): void
    {
        [$pr, , $requests] = $this->transport(fn() => $this->unavailable503());
        $stop = new \DomainException('three strikes');
        try {
            $this->waitFor($pr, $loop, onRetry: function (PingRoomException $e, int $attempt) use ($stop): void {
                if ($attempt === 3) {
                    throw $stop;
                }
            });
            self::fail('expected the onRetry error');
        } catch (\DomainException $e) {
            self::assertSame($stop, $e);
        }
        self::assertCount(3, $requests);
    }

    #[DataProvider('loops')]
    public function testItStaysBelow30PollsPerMinuteAcrossAlternatingFailuresAndPendingResponses(array $loop): void
    {
        [$pr, $clock, $requests] = $this->transport(fn(int $n): ResponseInterface => $n % 2 === 1 ? $this->unavailable503() : self::json(200, $this->pending($loop)));
        $stopAt = $clock->nowMs() + self::MINUTE;
        $e = $this->thrown(fn() => $this->waitFor($pr, $loop, timeout: 0, isCancelled: fn(): bool => $clock->nowMs() >= $stopAt));

        self::assertSame('cancelled', $e->errorCode);
        $this->assertStoppedPromptlyAt($stopAt, $clock);
        self::assertCount(29, $requests, 'one loop must not exhaust the wait route\'s 30 a minute');
        $gaps = [];
        foreach ($requests as $i => $request) {
            if ($i > 0) {
                $gaps[] = $request['at'] - $requests[$i - 1]['at'];
            }
        }
        self::assertSame(array_fill(0, 28, 2100), $gaps);
    }

    #[DataProvider('loops')]
    public function testItAbortsDuringTheRetryIntervalWithoutAnotherRequest(array $loop): void
    {
        [$pr, $clock, $requests] = $this->transport(fn() => $this->unavailable503());
        $stopAt = $clock->nowMs() + 1500;
        $e = $this->thrown(fn() => $this->waitFor($pr, $loop, isCancelled: fn(): bool => $clock->nowMs() >= $stopAt));

        self::assertSame('cancelled', $e->errorCode);
        $this->assertStoppedPromptlyAt($stopAt, $clock);
        self::assertCount(1, $requests);
    }

    #[DataProvider('loops')]
    public function testItStopsAtTheOutageDeadlineWithoutAnEarlyRetry(array $loop): void
    {
        $start = (new FakeClock())->nowMs();
        $deadline = $start + 2500;
        $expiry = $this->iso($deadline - Poller::WAIT_EXPIRY_GRACE_MS);
        [$pr, $clock, $requests] = $this->transport(fn(int $n): ResponseInterface => $n === 1 ? self::json(200, $this->pending($loop, $expiry)) : $this->unavailable503());
        $e = $this->thrown(fn() => $this->waitFor($pr, $loop));

        self::assertSame('unavailable', $e->errorCode);
        self::assertSame($deadline, $clock->nowMs());
        self::assertSame([0, 2100], array_map(fn(array $r): int => $r['at'] - $start, $requests->getArrayCopy()));
    }

    #[DataProvider('loops')]
    public function testItRetriesAtTheOutageDeadlineWhenTheFullIntervalFits(array $loop): void
    {
        $start = (new FakeClock())->nowMs();
        $deadline = $start + 4200;
        $expiry = $this->iso($deadline - Poller::WAIT_EXPIRY_GRACE_MS);
        [$pr, $clock, $requests] = $this->transport(fn(int $n): ResponseInterface => $n === 1 ? self::json(200, $this->pending($loop, $expiry)) : $this->unavailable503());
        $e = $this->thrown(fn() => $this->waitFor($pr, $loop));

        self::assertSame('unavailable', $e->errorCode);
        self::assertSame($deadline, $clock->nowMs());
        self::assertSame([0, 2100, 4200], array_map(fn(array $r): int => $r['at'] - $start, $requests->getArrayCopy()));
    }

    #[DataProvider('loops')]
    public function testItPreservesCancellationWhileWaitingOutTheOutageBudget(array $loop): void
    {
        $start = (new FakeClock())->nowMs();
        $expiry = $this->iso($start + 2500 - Poller::WAIT_EXPIRY_GRACE_MS);
        [$pr, $clock, $requests] = $this->transport(fn(int $n): ResponseInterface => $n === 1 ? self::json(200, $this->pending($loop, $expiry)) : $this->unavailable503());
        $stopAt = $start + 2300;
        $e = $this->thrown(fn() => $this->waitFor($pr, $loop, isCancelled: fn(): bool => $clock->nowMs() >= $stopAt));

        self::assertSame('cancelled', $e->errorCode);
        $this->assertStoppedPromptlyAt($stopAt, $clock);
        self::assertSame([0, 2100], array_map(fn(array $r): int => $r['at'] - $start, $requests->getArrayCopy()));
    }

    #[DataProvider('loops')]
    public function testItRefusesAMalformed2xxInsteadOfReturningItAsAnOutcome(array $loop): void
    {
        $stateField = $loop['name'] === 'approval' ? 'status' : 'state';
        $done = $this->done($loop);
        $withoutState = $done;
        unset($withoutState[$stateField]);
        $bodies = [
            '<html>captive portal</html>',
            '{}',
            '[]',
            json_encode([...$done, 'id' => 'someone-else']),
            json_encode([...$done, $stateField => 'approved']),
            json_encode($withoutState),
        ];
        foreach ($bodies as $body) {
            [$pr, , $requests] = $this->transport(fn(): ResponseInterface => self::raw(200, (string) $body));
            $e = $this->thrown(fn() => $this->waitFor($pr, $loop));
            self::assertSame('invalid_response', $e->errorCode, (string) $body);
            self::assertCount(1, $requests, 'a malformed response must not be polled again');
        }
    }

    #[DataProvider('loops')]
    public function testATerminalResponseForTheRequestedIdIsAcceptedInEitherCase(array $loop): void
    {
        $done = $this->done($loop);
        $done['id'] = strtoupper((string) $done['id']);
        [$pr] = $this->transport(fn(): ResponseInterface => self::json(200, $done));
        self::assertSame($done['id'], $this->waitFor($pr, $loop)['id']);
    }

    #[DataProvider('loops')]
    public function testItRetriesA429AfterItsRetryAfterThenReturnsTheOutcome(array $loop): void
    {
        [$pr, $clock, $requests] = $this->transport(fn(int $n): ResponseInterface => $n === 1
            ? self::json(429, ['code' => 'rate_limited', 'message' => 'Too Many Attempts.'], ['Retry-After' => '1'])
            : self::json(200, $this->done($loop)));
        self::assertSame($this->done($loop), $this->waitFor($pr, $loop));
        self::assertCount(2, $requests);
        // A Retry-After below the poll floor still waits the 2.1 s floor.
        self::assertSame(2100, $requests[1]['at'] - $requests[0]['at']);
    }

    #[DataProvider('loops')]
    public function testARetryAfterLongerThanThePollFloorIsWaitedOutInFullFromTheHeaderOrTheBody(array $loop): void
    {
        $answers = [
            self::json(429, ['code' => 'rate_limited', 'message' => 'Too Many Attempts.'], ['Retry-After' => '3']),
            self::json(429, ['code' => 'rate_limited', 'message' => 'Too Many Attempts.', 'retry_after' => 3]),
        ];
        foreach ($answers as $answer) {
            [$pr, , $requests] = $this->transport(fn(int $n): ResponseInterface => $n === 1 ? $answer : self::json(200, $this->done($loop)));
            self::assertSame($this->done($loop), $this->waitFor($pr, $loop));
            self::assertSame(3000, $requests[1]['at'] - $requests[0]['at']);
        }
    }

    #[DataProvider('loops')]
    public function testItRetriesA5xxAndADroppedConnectionWithBackoff(array $loop): void
    {
        foreach ([$this->unavailable503(), new \RuntimeException('fetch failed'), new \RuntimeException('cURL error 28: Operation timed out')] as $failure) {
            [$pr, , $requests] = $this->transport(fn(int $n) => $n === 1 ? $failure : self::json(200, $this->done($loop)));
            self::assertSame($this->done($loop), $this->waitFor($pr, $loop));
            self::assertCount(2, $requests);
            self::assertGreaterThanOrEqual(1000, $requests[1]['at'] - $requests[0]['at']);
        }
    }

    #[DataProvider('loops')]
    public function testItThrowsAPermanentFailureAtOnce(array $loop): void
    {
        foreach ([401, 403, 404, 422] as $status) {
            [$pr, , $requests] = $this->transport(fn(): ResponseInterface => self::json($status, ['message' => "HTTP {$status}"]));
            $e = $this->thrown(fn() => $this->waitFor($pr, $loop));
            self::assertSame($status, $e->status);
            self::assertCount(1, $requests, "{$loop['name']} {$status}");
        }
    }

    #[DataProvider('loops')]
    public function testItSpacesImmediatelyPendingPollsAtLeast2100MsApart(array $loop): void
    {
        [$pr, , $requests] = $this->transport(fn(int $n): ResponseInterface => self::json(200, $n < 4 ? $this->pending($loop) : $this->done($loop)));
        self::assertSame($this->done($loop), $this->waitFor($pr, $loop, timeout: 0));
        self::assertCount(4, $requests);
        $gaps = [];
        foreach ($requests as $i => $request) {
            if ($i > 0) {
                $gaps[] = $request['at'] - $requests[$i - 1]['at'];
            }
        }
        self::assertSame([2100, 2100, 2100], $gaps);
    }

    #[DataProvider('loops')]
    public function testATerminalAnswerReturnsAtOnceWithoutApplyingThePollInterval(array $loop): void
    {
        [$pr, $clock, $requests] = $this->transport(fn(): ResponseInterface => self::json(200, $this->done($loop)));
        $start = $clock->nowMs();
        self::assertSame($this->done($loop), $this->waitFor($pr, $loop));
        self::assertSame($start, $clock->nowMs());
        self::assertCount(1, $requests);
    }

    public function testAnApprovalNeedsADecisionWhenDecidedAndNoDecidedAtWhenPending(): void
    {
        $loop = ['name' => 'approval', 'what' => 'approval appr-1', 'path' => ''];
        $decided = $this->done($loop);
        $pending = $this->pending($loop);
        $bad = [
            [...$decided, 'decision' => null],
            [...$decided, 'decision' => ''],
            [...$pending, 'decided_at' => '2026-10-27T10:00:00+00:00'],
            array_diff_key($decided, ['decided_at' => 1]),
            [...$decided, 'decided_at' => 5],
        ];
        foreach ($bad as $body) {
            [$pr] = $this->clientAnswering($body);
            self::assertSame('invalid_response', $this->thrown(fn() => $pr->approvals->waitForDecision('appr-1'))->errorCode, json_encode($body) ?: '');
        }
    }

    public function testTheLoopsRefuseABlankIdBeforeSending(): void
    {
        [$pr, $http] = $this->clientAnswering([]);
        foreach ([fn() => $pr->approvals->waitForDecision(' '), fn() => $pr->questions->waitForAnswer(''), fn() => $pr->handoffs->waitForResult(' ')] as $attempt) {
            self::assertSame('invalid_request', $this->thrown($attempt)->errorCode);
        }
        self::assertSame([], $http->requests);
    }

    public function testTransientClassificationMatchesThePairingAndCliRetrySet(): void
    {
        self::assertTrue(Poller::isTransient(new NetworkException('x')));
        self::assertTrue(Poller::isTransient(new TimeoutException()));
        self::assertTrue(Poller::isTransient(new PingRoomException('x', 'public_room_rate_limited', 429)));
        self::assertTrue(Poller::isTransient(new PingRoomException('x', null, 502)));
        foreach ([400, 401, 403, 404, 409, 422] as $status) {
            self::assertFalse(Poller::isTransient(new PingRoomException('x', null, $status)), (string) $status);
        }
        self::assertFalse(Poller::isTransient(new PingRoomException('x', 'invalid_response')));
        self::assertFalse(Poller::isTransient(new PingRoomException('x', 'redirect_refused', 307)));
        self::assertFalse(Poller::isTransient(new \RuntimeException('plain')));
    }

    public function testTransientBackoffDoublesToA30SecondCapAndYieldsToAServerRetryAfter(): void
    {
        $error = new PingRoomException('x', null, 503);
        self::assertSame([1000, 2000, 4000, 8000, 16000, 30000, 30000, 30000], array_map(fn(int $n): int => Poller::transientDelayMs($error, $n), [1, 2, 3, 4, 5, 6, 7, 50]));
        self::assertSame([400, 800, 1600], array_map(fn(int $n): int => Poller::transientDelayMs($error, $n, 400), [1, 2, 3]));
        $throttled = new PingRoomException('x', null, 429, 42);
        self::assertSame(42_000, Poller::transientDelayMs($throttled, 1));
        self::assertSame(42_000, Poller::transientDelayMs($throttled, 9));
        // Retry-After: 0 must not turn into a tight loop.
        self::assertSame(1000, Poller::transientDelayMs(new PingRoomException('x', null, 429, 0), 1));
        self::assertSame(1500, Poller::transientDelayMs(new PingRoomException('x', null, 429, 1.5), 1));
    }

    public function testEveryBackoffBaseIsFlooredAt250MsAndDoublesUntilTheCap(): void
    {
        $error = new PingRoomException('x', null, 503);
        $doubling = [250, 500, 1000, 2000, 4000, 8000, 16000, 30000, 30000, 30000];
        foreach ([0, 1, 10, 249, 250] as $base) {
            self::assertSame($doubling, array_map(fn(int $n): int => Poller::transientDelayMs($error, $n, $base), [1, 2, 3, 4, 5, 6, 7, 8, 9, 100]), "base {$base}");
            self::assertSame(250, Poller::transientDelayMs(new PingRoomException('x', null, 429, 0), 1, $base), "base {$base}");
        }
        self::assertSame(3000, Poller::transientDelayMs(new PingRoomException('x', null, 429, 3), 1, 0));
        // A base above the floor is kept and doubles; a base past the cap stays put.
        self::assertSame([300, 600, 1200, 2400, 4800, 9600, 19200], array_map(fn(int $n): int => Poller::transientDelayMs($error, $n, 300), [1, 2, 3, 4, 5, 6, 7]));
        self::assertSame([60_000, 60_000, 60_000], array_map(fn(int $n): int => Poller::transientDelayMs($error, $n, 60_000), [1, 2, 3]));
        // A nonsense base falls back to the default rather than to the floor.
        foreach ([-5, NAN, INF] as $base) {
            self::assertSame(1000, Poller::transientDelayMs($error, 1, $base), var_export($base, true));
        }
    }

    public function testTheLongPollCallsGiveTheHttpClientTheirHoldPlusABufferNotTheDefault(): void
    {
        self::assertSame(30.0, Poller::waitTimeoutSeconds(null));
        self::assertSame(15.0, Poller::waitTimeoutSeconds(5));
        self::assertSame(10.0, Poller::waitTimeoutSeconds(0));
        self::assertSame(40.0, Poller::waitTimeoutSeconds(30));
    }
}
