<?php

declare(strict_types=1);

namespace PingRoom;

/**
 * The machine `errorCode` vocabulary, one list per surface, the same lists the
 * TypeScript SDK exports. Branch on `$e->errorCode` and `$e->status`, not on the
 * message. Retry any 429 after `$e->retryAfter` rather than matching one code:
 * route throttles answer `rate_limited`, but public rooms and quick actions
 * answer their own (`public_room_rate_limited`, `cooldown_active`).
 */
final class ErrorCodes
{
    /**
     * The code the `agent.room` gate emits on every room-scoped route (HTTP 403):
     * the room is outside the grant the human gave this agent.
     */
    public const ROOM_SCOPED_ERROR_CODES = [
        'room_not_granted',
    ];

    /** What a handoff create or read can answer. Server: `AgentHandoffController`, `HandoffService`, `RequestIdempotency`. */
    public const HANDOFF_ERROR_CODES = [
        'feature_temporarily_unavailable',
        'handoff_room_unsupported',
        'invalid_target',
        'no_room_configured',
        'target_not_permitted',
        'recipient_not_ready',
        'idempotency_conflict',
        'invalid_idempotency_key',
        'capability_check_unavailable',
        'insufficient_scope',
        'free_limit_reached',
    ];

    /** The coded failures of the Agent Inbox activation path (an endpoint this SDK does not wrap yet; the codes are exported for parity). */
    public const AGENT_INBOX_ERROR_CODES = [
        'activation_evidence_unavailable',
        'no_room_configured',
        'activation_room_muted',
        'activation_delivery_unavailable',
        'feature_temporarily_unavailable',
    ];

    /** The coded failures of the quick-action surface, and of a broadcast that names an `actionNumber`. */
    public const QUICK_ACTION_ERROR_CODES = [
        'quick_action_input_required',
        'quick_action_input_type',
        'action_not_configured',
        'pro_required',
        'quick_action_layout_changed',
        'quick_action_page_in_use',
        'location_trigger_owner_only',
        'location_trigger_inactive',
    ];

    /** The coded refusals of "Who's on it?": `Live::claim()` and a `Live::push()` that starts a `claim` stream. */
    public const LIVE_CLAIM_ERROR_CODES = [
        'claim_conflict',
        'claim_own_request',
        'claim_private_rooms_only',
        'claim_not_found',
        'claim_request_invalid',
        'claim_must_start_running',
        'claim_deadline_out_of_range',
    ];

    /**
     * The coded refusals of `Notifications::replace()`. A bare 404 with no code
     * means the server has replacing a Ping switched off. A `broadcast()` with a
     * `replaceKey` never answers these: when the earlier Ping cannot be replaced
     * it sends a new one.
     */
    public const REPLACE_ERROR_CODES = [
        'notification_not_found',
        'not_ping_owner',
        'unsupported_room',
        'unsupported_question',
        'unsupported_handoff',
        'unsupported_live_status',
        'unsupported_claim',
        'unsupported_ack',
        'unsupported_checklist',
        'unsupported_source',
        'unsupported_actions',
        'replace_window_closed',
        'replace_limit_reached',
        'ping_withdrawn',
    ];

    /** The coded refusals of `Notifications::withdraw()`. A bare 404 with no code means withdrawing is switched off. */
    public const WITHDRAW_ERROR_CODES = [
        'notification_not_found',
        'not_ping_owner',
        'unsupported_room',
        'unsupported_question',
        'unsupported_handoff',
        'unsupported_live_status',
        'unsupported_claim',
        'unsupported_ack',
        'unsupported_checklist',
        'unsupported_source',
        'withdraw_window_closed',
    ];

    /** The coded refusals of room metrics (`Metrics::set()`, `Metrics::delete()`, and an incoming webhook's `metric`). */
    public const METRIC_ERROR_CODES = [
        'metrics_limit_reached',
        'metric_not_found',
        'metric_exists',
    ];

    /**
     * Codes any route can answer: `rate_limited` (429, a route throttle) and
     * `feature_disabled` (403, the operator switched the capability off;
     * `$e->body['feature']` names the switch, and retrying cannot succeed).
     */
    public const COMMON_ERROR_CODES = ['rate_limited', 'feature_disabled'];

    /** Request-size failures: the server's 413, and the SDK's local refusal of an attachment over 5 MiB. */
    public const REQUEST_LIMIT_ERROR_CODES = ['payload_too_large', 'attachment_too_large'];

    /** The coded refusals of a send that carries buttons (`data.actions`). */
    public const PING_ACTION_ERROR_CODES = [
        'ping_buttons_agents_only',
        'ping_buttons_unsupported_room',
        'ping_buttons_with_ack',
        'ping_buttons_require_pro',
    ];

    /**
     * Codes this SDK raises itself, with status 0 (`attachment_too_large` also
     * carries status 413).
     */
    public const CLIENT_ERROR_CODES = [
        'no_token',
        'no_http_client',
        'invalid_request',
        'invalid_data',
        'invalid_url',
        'insecure_url',
        'invalid_response',
        'redirect_refused',
        'network_error',
        'timeout',
        'unavailable',
        'cancelled',
        'attachment_too_large',
    ];

    private function __construct() {}

    /**
     * Every server code this SDK's lists cover, for a caller that branches once
     * across surfaces instead of per endpoint.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return array_values(array_unique([
            ...self::HANDOFF_ERROR_CODES,
            ...self::ROOM_SCOPED_ERROR_CODES,
            ...self::AGENT_INBOX_ERROR_CODES,
            ...self::QUICK_ACTION_ERROR_CODES,
            ...self::LIVE_CLAIM_ERROR_CODES,
            ...self::REPLACE_ERROR_CODES,
            ...self::WITHDRAW_ERROR_CODES,
            ...self::METRIC_ERROR_CODES,
            ...self::PING_ACTION_ERROR_CODES,
            ...self::REQUEST_LIMIT_ERROR_CODES,
            ...self::COMMON_ERROR_CODES,
        ]));
    }
}
