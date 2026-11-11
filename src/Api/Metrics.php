<?php

declare(strict_types=1);

namespace PingRoom\Api;

use PingRoom\Http\Transport;
use PingRoom\Internal\MetricRules;
use PingRoom\Internal\Path;
use PingRoom\Internal\Validate;
use PingRoom\Unchanged;

/**
 * `$client->metrics`: room metrics, named values a script keeps in a room for a
 * phone widget to show (key `mrr`, label "MRR", format `currency`, unit `USD`,
 * value `12400`). Setting one sends no Ping and no push, and a room holds at most
 * {@see self::MAX_METRICS_PER_ROOM}.
 */
final class Metrics
{
    /** Metrics one room can hold. */
    public const MAX_METRICS_PER_ROOM = 8;

    /** Every format, in the server's order. */
    public const FORMATS = ['number', 'currency', 'percent', 'unit', 'text'];

    public const MAX_KEY_LENGTH = 40;

    /** A key as the server stores it: a lowercase letter or digit first, then lowercase letters, digits, `-` and `_`. */
    public const KEY_PATTERN = '/^[a-z0-9][a-z0-9_-]{0,39}\z/';

    public const MAX_LABEL_LENGTH = 24;

    public const MAX_UNIT_LENGTH = 8;

    public const MAX_TEXT_VALUE_LENGTH = 24;

    /** Largest magnitude a numeric value may have. */
    public const MAX_ABSOLUTE_VALUE = 1e15;

    public function __construct(
        private readonly Transport $http,
    ) {}

    /**
     * The key the server addresses a metric by: trimmed and lowercased (ASCII
     * only), then checked against the stored shape.
     */
    public static function normalizeKey(string $key): string
    {
        return MetricRules::normalizeKey($key);
    }

    /**
     * The room's metrics, oldest first, with the per-room limit. Needs the
     * `pingroom:metrics:read` scope; the agent's human only has to be a member.
     *
     * @return array<string, mixed> `metrics` (oldest first; each `key`, `label`, `format`, `unit`, `value`, `value_updated_at`) and `limit`
     */
    public function list(string $inviteCode): array
    {
        return Validate::record($this->http->request('GET', '/api/agent/rooms/' . Path::segment($inviteCode) . '/metrics'));
    }

    /**
     * Set a metric's value, creating the metric when the key is new (an upsert).
     * Needs the `pingroom:metrics:write` scope and a human who owns or administers
     * the room. A new metric's label defaults to its key and its format to `number`
     * or `text` by the value's type.
     *
     * Writing the value the metric already has changes nothing and keeps
     * `value_updated_at`, so a script may repeat itself freely. Pass the value raw,
     * never pre-formatted (`12400`, not `"$12,400"`).
     *
     * Rules, checked before any request and the same as the server's: the key is 1
     * to 40 characters of letters, digits, `-` and `_`; the label at most 24
     * characters; `$format` one of {@see self::FORMATS}; `$unit` an ISO 4217 code
     * for `currency` (its shape is checked here, the code list by the server), 1 to
     * 8 characters for `unit`, and not allowed for the other formats; the value a
     * finite number of at most 1e15 in absolute value (or a plain decimal string),
     * or for `text` a string of at most 24 characters.
     *
     * `$unit` keeps the stored unit when you leave it out, and `null` clears it.
     *
     * @return array<string, mixed> the stored metric: `key`, `label`, `format`, `unit`, `value`, `value_updated_at`
     */
    public function set(
        string $inviteCode,
        string $key,
        int|float|string $value,
        ?string $label = null,
        ?string $format = null,
        string|Unchanged|null $unit = Unchanged::Value,
    ): array {
        $normalizedKey = MetricRules::normalizeKey($key);
        $fields = ['value' => $value];
        if ($label !== null) {
            $fields['label'] = $label;
        }
        if ($format !== null) {
            $fields['format'] = $format;
        }
        if (!$unit instanceof Unchanged) {
            $fields['unit'] = $unit;
        }
        MetricRules::assertFields($fields, true);
        $fields['value'] = MetricRules::wireValue($value);

        return Validate::record($this->http->request(
            'PUT',
            '/api/agent/rooms/' . Path::segment($inviteCode) . '/metrics/' . Path::segment($normalizedKey),
            ['json' => $fields],
        ));
    }

    /**
     * Delete a metric. Throws `metric_not_found` when the room has no such key.
     */
    public function delete(string $inviteCode, string $key): void
    {
        $normalizedKey = MetricRules::normalizeKey($key);
        $this->http->request('DELETE', '/api/agent/rooms/' . Path::segment($inviteCode) . '/metrics/' . Path::segment($normalizedKey));
    }
}
