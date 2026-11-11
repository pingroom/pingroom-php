<?php

declare(strict_types=1);

namespace PingRoom;

/**
 * Live-status streams: the wire contract for driving a Live Activity.
 *
 * A stream is keyed by `correlation_id`: the first ping starts a live card on every
 * room member's lock screen (one alert), further `running` pings move it silently,
 * and the first `done` or `failed` sends one completion alert and ends it.
 *
 * Each builder returns the array body that `$client->live->push()` and an incoming
 * webhook (`IncomingWebhook::send()`, with `live_status` and `correlation_id` at the
 * top level) take. `$live` is the `live_status` object: only `state` (`running`,
 * `done` or `failed`) is required, and every other field is read by the template
 * you chose. `$rest` carries the rest of the ping body (`title`, `action`, `data`,
 * `tags`, `requires_ack`, `ack_timeout_seconds`).
 *
 * `template` and `steps` are fixed at stream creation: a later ping cannot
 * re-template a running card, and a `running` ping whose `steps` list differs from
 * the stored one is rejected with 422 `steps_immutable`. `segments` (`progress`) and
 * `started_at` (`timer`) are fixed the same way (`segments_immutable`,
 * `started_at_immutable`). Content set at creation is sticky, so a sparse update
 * carrying just `progress` keeps rendering the full template, and `{state: 'done'}`
 * alone keeps the scoreboard, metrics grid, deadline and accent on the final frame.
 *
 * The builders check nothing on `$live`: the server answers 422 with the rule it
 * broke. {@see self::LIMITS} holds the numbers for a caller who wants to check first.
 *
 * @phpstan-type LiveFields array<string, mixed>
 * @phpstan-type PingFields array<string, mixed>
 */
final class LiveStatus
{
    /**
     * Every `live_status.template` the server accepts, in the frozen wire order
     * (pinned to `tests/contracts/live-templates.json`). An app that does not know
     * one shows the `status` layout (title and message), so a template added later
     * never leaves a card blank.
     */
    public const TEMPLATES = [
        'status',
        'steps',
        'progress',
        'metrics',
        'countdown',
        'question',
        'matchup',
        'claim',
        'timer',
        'value',
        'stats',
    ];

    /**
     * The server's limits on the fields the `timer`, `value`, `stats` and segmented
     * `progress` layouts added. The SDK does not enforce them; they are pinned to
     * the server by the contract fixture.
     */
    public const LIMITS = [
        // `value`: the `value` string.
        'valueLength' => 24,
        // `value`: the `value_label` string.
        'valueLabelLength' => 24,
        // `value`: the `delta` string.
        'deltaLength' => 12,
        // `stats`: fewest and most `{label, value}` pairs.
        'statsMin' => 4,
        'statsMax' => 8,
        // `stats`: each pair's `label` and `value`.
        'statLabelLength' => 24,
        'statValueLength' => 24,
        // `progress`: `segments` is 2 to 20; `current_segment` is 0 to `segments`.
        'segmentsMin' => 2,
        'segmentsMax' => 20,
    ];

    public const STATES = ['running', 'done', 'failed'];

    private function __construct() {}

    /**
     * Default template: a title line plus a status dot, with optional progress.
     *
     * @param LiveFields $live
     * @param PingFields $rest
     *
     * @return array<string, mixed>
     */
    public static function status(string $correlationId, array $live, array $rest = []): array
    {
        return self::ping($correlationId, ['template' => 'status', ...$live], $rest);
    }

    /**
     * A segmented step tracker. `steps` (2 to 8 labels) is required on the first
     * ping; `current_step` advances it.
     *
     * @param LiveFields $live
     * @param PingFields $rest
     *
     * @return array<string, mixed>
     */
    public static function steps(string $correlationId, array $live, array $rest = []): array
    {
        return self::ping($correlationId, ['template' => 'steps', ...$live], $rest);
    }

    /**
     * A determinate progress bar (`progress`, 0 to 1) with an optional live ETA
     * (`eta_at`). Set `segments` (2 to 20) and `current_segment` to draw it in equal
     * segments; `progress` is then derived as `current_segment / segments` when you
     * omit it.
     *
     * @param LiveFields $live
     * @param PingFields $rest
     *
     * @return array<string, mixed>
     */
    public static function progress(string $correlationId, array $live, array $rest = []): array
    {
        return self::ping($correlationId, ['template' => 'progress', ...$live], $rest);
    }

    /**
     * Up to 3 labelled counters (`metrics`: a list of `{label, value}`).
     *
     * @param LiveFields $live
     * @param PingFields $rest
     *
     * @return array<string, mixed>
     */
    public static function metrics(string $correlationId, array $live, array $rest = []): array
    {
        return self::ping($correlationId, ['template' => 'metrics', ...$live], $rest);
    }

    /**
     * A large live timer counting down to `deadline_at` (epoch seconds).
     *
     * @param LiveFields $live
     * @param PingFields $rest
     *
     * @return array<string, mixed>
     */
    public static function countdown(string $correlationId, array $live, array $rest = []): array
    {
        return self::ping($correlationId, ['template' => 'countdown', ...$live], $rest);
    }

    /**
     * A prompt (`prompt`) with up to 4 choices (`options`: a list of `{value, label}`)
     * shown on the lock screen.
     *
     * @param LiveFields $live
     * @param PingFields $rest
     *
     * @return array<string, mixed>
     */
    public static function question(string $correlationId, array $live, array $rest = []): array
    {
        return self::ping($correlationId, ['template' => 'question', ...$live], $rest);
    }

    /**
     * Two sides (`left`, `right`: `{label, value}`) plus a centre score or clock
     * (`center`).
     *
     * @param LiveFields $live
     * @param PingFields $rest
     *
     * @return array<string, mixed>
     */
    public static function matchup(string $correlationId, array $live, array $rest = []): array
    {
        return self::ping($correlationId, ['template' => 'matchup', ...$live], $rest);
    }

    /**
     * "Who's on it?": a request one room member takes on. Private standard rooms
     * only (`422 claim_private_rooms_only` elsewhere).
     *
     * Pass the request text as `$rest['title']` (1 to 120 characters; `$live['message']`
     * is used when there is no title). Send `state: 'running'` on the first ping.
     * `$live['deadline_at']` is optional, in the future and at most 8 hours away.
     * Anything else in `$live` is ignored, including a `claim` block.
     *
     * After creation the request moves with `$client->live->claim()`. A producer
     * without a member identity (an incoming webhook) ends its own request with
     * {@see self::done()} (done) or {@see self::failed()} (cancelled).
     *
     * @param LiveFields $live
     * @param PingFields $rest
     *
     * @return array<string, mixed>
     */
    public static function claim(string $correlationId, array $live, array $rest = []): array
    {
        return self::ping($correlationId, ['template' => 'claim', ...$live], $rest);
    }

    /**
     * A timer counting up from `started_at` (epoch seconds), kept by the device so
     * it needs no per-second pushes. `started_at` defaults to the first ping's time.
     * A finished timer freezes at its final elapsed time. As with every stream, send
     * an update at least every 15 minutes or it is ended as stale.
     *
     * @param LiveFields $live
     * @param PingFields $rest
     *
     * @return array<string, mixed>
     */
    public static function timer(string $correlationId, array $live, array $rest = []): array
    {
        return self::ping($correlationId, ['template' => 'timer', ...$live], $rest);
    }

    /**
     * One prominent value. `value` (at most 24 characters) is required on the first
     * ping; `value_label`, `delta` (at most 12) and `trend` (`up`, `down`, `flat`)
     * are optional. When you send no `message` the server writes one so an older
     * app, which shows the status layout, still reads well.
     *
     * @param LiveFields $live
     * @param PingFields $rest
     *
     * @return array<string, mixed>
     */
    public static function value(string $correlationId, array $live, array $rest = []): array
    {
        return self::ping($correlationId, ['template' => 'value', ...$live], $rest);
    }

    /**
     * Four to eight labelled values (`stats`: a list of `{label, value}`), required
     * on the first ping. Use `metrics` for up to 3. When you send no `message` the
     * server writes one for older apps.
     *
     * @param LiveFields $live
     * @param PingFields $rest
     *
     * @return array<string, mixed>
     */
    public static function stats(string $correlationId, array $live, array $rest = []): array
    {
        return self::ping($correlationId, ['template' => 'stats', ...$live], $rest);
    }

    /**
     * An urgent stream, delivered time-sensitive from the start so it breaks through
     * Focus. This is a `category`, not a `template`: `alert` has no template
     * equivalent, and it is the only way to start a stream time-sensitive. Fixed at
     * creation, like `template`.
     *
     * @param LiveFields $live
     * @param PingFields $rest
     *
     * @return array<string, mixed>
     */
    public static function alert(string $correlationId, array $live, array $rest = []): array
    {
        return self::ping($correlationId, ['category' => 'alert', ...$live], $rest);
    }

    /**
     * Finish a stream. The one alert your stream earns.
     *
     * @param PingFields $rest
     *
     * @return array<string, mixed>
     */
    public static function done(string $correlationId, ?string $message = null, array $rest = []): array
    {
        return self::ping($correlationId, ['state' => 'done', ...self::messageField($message)], $rest);
    }

    /**
     * Finish a stream unsuccessfully.
     *
     * @param PingFields $rest
     *
     * @return array<string, mixed>
     */
    public static function failed(string $correlationId, ?string $message = null, array $rest = []): array
    {
        return self::ping($correlationId, ['state' => 'failed', ...self::messageField($message)], $rest);
    }

    /**
     * @param LiveFields $live
     * @param PingFields $rest
     *
     * @return array<string, mixed>
     */
    private static function ping(string $correlationId, array $live, array $rest): array
    {
        return ['correlation_id' => $correlationId, 'live_status' => $live, ...$rest];
    }

    /**
     * @return array<string, string>
     */
    private static function messageField(?string $message): array
    {
        return $message !== null && $message !== '' ? ['message' => $message] : [];
    }
}
