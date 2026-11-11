<?php

declare(strict_types=1);

namespace PingRoom\Api;

use PingRoom\Exception\PingRoomException;
use PingRoom\Http\Transport;
use PingRoom\Internal\Data;
use PingRoom\Internal\Guards;
use PingRoom\Internal\Path;
use PingRoom\Internal\PingActionRules;
use PingRoom\Internal\Validate;

/**
 * `$client->live`: live-status streams, a self-updating card on the room members'
 * lock screens. Build the body with {@see \PingRoom\LiveStatus}.
 *
 * Requires the `pingroom:live:write` scope. Free accounts get a small daily budget
 * of new streams; updates and the terminal ping of an open stream are never
 * charged, so a card can never be quota-blocked into hanging open. A first ping
 * that is already `done` or `failed` is a new stream and is charged.
 */
final class Live
{
    /**
     * What each action's 200 receipt may say, as `[claim.status, stream state]`
     * pairs. `release` has two: letting go reopens a request that can still be
     * taken, but a request past its deadline (or four hours old with none) ends as
     * `expired` with stream state `failed`, exactly as time ends an open one. That
     * is still a 200, and a retry replays it. Only `release` can end that way.
     */
    private const CLAIM_OUTCOMES = [
        'claim' => [['claimed', 'running']],
        'release' => [['open', 'running'], ['expired', 'failed']],
        'done' => [['done', 'done']],
        'cancel' => [['cancelled', 'failed']],
    ];

    public function __construct(
        private readonly Transport $http,
    ) {}

    /**
     * Send one stream ping: start, update, or end, chosen by `live_status.state`
     * and whether the correlation already exists.
     *
     * @param array<string, mixed> $ping a body from a {@see \PingRoom\LiveStatus} builder: `correlation_id`, `live_status`, and optionally `title`, `action`, `data`, `tags`, `requires_ack`, `ack_timeout_seconds`
     *
     * @return array<string, mixed> `notification_id`, `correlation_id`, `state` (`started` on creation, then `running`, `done`, `failed`), `idempotent`
     */
    public function push(string $inviteCode, array $ping): array
    {
        Guards::requireNonEmpty($inviteCode, 'inviteCode');
        Guards::requireNonEmpty($ping['correlation_id'] ?? null, 'correlation_id');
        $data = $ping['data'] ?? null;
        $view = Data::check($data);
        PingActionRules::refuse($view, 'A live-progress stream');
        Guards::assertTags($ping['tags'] ?? null);

        // An empty `data` is an empty object on the wire, never `[]`.
        if (array_key_exists('data', $ping) && $ping['data'] === []) {
            $ping['data'] = new \stdClass();
        }

        return Validate::record($this->http->request(
            'POST',
            '/api/agent/rooms/' . Path::segment($inviteCode) . '/live',
            ['json' => $ping],
        ));
    }

    /**
     * Read back a stream this credential started, so a restarted producer can
     * reconcile instead of opening a duplicate.
     *
     * Returns null only for a 404 `stream_not_found`: no stream with that
     * correlation id in the last 24 hours. Every other failure throws: a 404 for an
     * unknown or deleted room code, and a 403 `room_not_granted` when the room is
     * outside the grant the human gave this agent, both propagate rather than being
     * reported as "no stream".
     *
     * Every stored `live_status` field is returned (fields you never set come back as
     * `null`), plus `notification_id`, `correlation_id`, `tags` and `updated_at`; a
     * `claim` stream also carries `title` and `claim`.
     *
     * @return array<string, mixed>|null
     */
    public function get(string $inviteCode, string $correlationId): ?array
    {
        Guards::requireNonEmpty($inviteCode, 'inviteCode');
        Guards::requireNonEmpty($correlationId, 'correlationId');
        try {
            return Validate::record($this->http->request(
                'GET',
                '/api/agent/rooms/' . Path::segment($inviteCode) . '/live/' . Path::segment($correlationId),
            ));
        } catch (PingRoomException $error) {
            if ($error->status === 404 && $error->errorCode === 'stream_not_found') {
                return null;
            }
            throw $error;
        }
    }

    /**
     * Act on a "Who's on it?" request as the human this credential acts for: `claim`
     * takes an open request (anyone but its asker), `release` lets go of one you
     * hold, `done` finishes it (its claimer, or its asker at any time) and `cancel`
     * withdraws it (its asker, or a room owner or admin).
     *
     * `release` returns the request to `open` only while it can still be taken. Past
     * its deadline (or four hours after it was made, when it has none) it ends the
     * request as `expired` instead: a 200 whose `claim.status` is `expired` and whose
     * `state` is `failed`. It is not an error. No other action resolves that way.
     *
     * `$notificationId` is the request's `notification_id`, from the `push()` result
     * or `notifications->list()`. It needs only room membership, not room ownership,
     * and the `pingroom:live:write` scope.
     *
     * Repeating your own last action resolves with `idempotent: true`. A refusal
     * throws a PingRoomException (`claim_conflict` 409, `claim_own_request` 422,
     * `claim_private_rooms_only` 422, `claim_not_found` 404, or `forbidden` 403); on
     * 409 and 422 its `body` carries the request as it now stands, so you can say who
     * got it first. A malformed or mismatched success throws `invalid_response`; the
     * SDK never repeats the transition POST automatically.
     *
     * @param string $action `claim`, `release`, `done` or `cancel`
     *
     * @return array<string, mixed> `notification_id`, `correlation_id`, `claim`, `state`, `idempotent`
     */
    public function claim(string $inviteCode, string $notificationId, string $action): array
    {
        Guards::requireNonEmpty($inviteCode, 'inviteCode');
        Guards::requireNonEmpty($notificationId, 'notificationId');
        if (!array_key_exists($action, self::CLAIM_OUTCOMES)) {
            throw PingRoomException::invalidRequest('`action` must be claim, release, done or cancel.');
        }
        $value = $this->http->request(
            'POST',
            '/api/agent/rooms/' . Path::segment($inviteCode) . '/live/' . Path::segment($notificationId) . '/claim',
            ['json' => ['action' => $action]],
        );

        return self::validateClaimResult($value, $notificationId, $action);
    }

    /**
     * @return array<string, mixed>
     */
    private static function validateClaimResult(mixed $value, string $notificationId, string $action): array
    {
        $expected = self::CLAIM_OUTCOMES[$action];
        $valid = Validate::isRecord($value) && is_array($value)
            && Validate::sameId($value['notification_id'] ?? null, $notificationId)
            && Validate::isNonEmptyString($value['correlation_id'] ?? null)
            && is_bool($value['idempotent'] ?? null);

        $claim = $valid && is_array($value) ? ($value['claim'] ?? null) : null;
        $valid = $valid && Validate::isRecord($claim) && is_array($claim);

        if ($valid && is_array($value) && is_array($claim)) {
            $matches = false;
            foreach ($expected as [$status, $state]) {
                if (($claim['status'] ?? null) === $status && ($value['state'] ?? null) === $state) {
                    $matches = true;
                }
            }
            $valid = $matches
                && is_bool($claim['late'] ?? null)
                && array_key_exists('by', $claim)
                && ($claim['by'] === null
                    || (Validate::isRecord($claim['by']) && is_array($claim['by'])
                        && Validate::isNonEmptyString($claim['by']['id'] ?? null)
                        && is_string($claim['by']['name'] ?? null)))
                && array_key_exists('at', $claim)
                && ($claim['at'] === null
                    || (is_int($claim['at']) || (is_float($claim['at']) && is_finite($claim['at']))));
        }

        if (!$valid || !is_array($value)) {
            throw new PingRoomException(
                'PingRoom returned an invalid claim transition receipt.',
                'invalid_response',
                0,
                null,
                ['expected_notification_id' => $notificationId, 'action' => $action],
            );
        }

        /** @var array<string, mixed> $value */
        return $value;
    }
}
