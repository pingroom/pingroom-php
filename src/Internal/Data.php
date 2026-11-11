<?php

declare(strict_types=1);

namespace PingRoom\Internal;

use PingRoom\Exception\PingRoomException;
use PingRoom\Limits;

/**
 * Validation of a ping's structured `data` object, measured the way the server
 * measures it.
 *
 * The check runs on the data as the server will see it: the value is encoded
 * the way it goes on the wire and decoded again (so an object with numeric keys
 * `{"0": ...}` is the list the server takes it for), then trimmed and
 * empty-to-null converted as the server's middleware does, then re-encoded with
 * PHP's default `json_encode()` flags and counted in bytes. The 25-key cap counts
 * the top-level keys.
 *
 * @internal
 */
final class Data
{
    private function __construct() {}

    /**
     * Refuse a `data` value the server would refuse.
     *
     * @return array<array-key, mixed>|null the data as the server decodes it, or null when `$data` is null
     */
    public static function check(mixed $data, string $field = 'data'): ?array
    {
        if ($data === null) {
            return null;
        }
        if (!is_array($data) && !is_object($data)) {
            throw PingRoomException::invalidData("`{$field}` must be a JSON object (not an array or scalar).");
        }

        try {
            $encoded = Json::encode($data);
        } catch (\JsonException) {
            throw PingRoomException::invalidData("`{$field}` must be JSON-serializable.");
        }
        $valid = false;
        $decoded = Json::decode($encoded, $valid);
        // A JsonSerializable object may encode to a scalar or a list; the server
        // refuses a non-empty list as `data`.
        if (!is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
            throw PingRoomException::invalidData("`{$field}` must be a JSON object (not an array or scalar).");
        }
        if (count($decoded) > Limits::MAX_DATA_KEYS) {
            throw PingRoomException::invalidData(
                "`{$field}` may have at most " . Limits::MAX_DATA_KEYS . ' keys (got ' . count($decoded) . ').',
            );
        }
        $bytes = Json::serverBytes($decoded);
        if ($bytes > Limits::MAX_DATA_BYTES) {
            throw PingRoomException::invalidData(
                "`{$field}` is too large ({$bytes} bytes, max " . Limits::MAX_DATA_BYTES . ').',
            );
        }

        return $decoded;
    }

    /**
     * The value to put on the wire for a validated `data`: an empty one becomes
     * `{}`, never `[]`.
     */
    public static function toWire(mixed $data): mixed
    {
        return Json::asObject($data);
    }
}
