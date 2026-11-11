<?php

declare(strict_types=1);

namespace PingRoom\Internal;

use PingRoom\Exception\PingRoomException;

/**
 * JSON in the shape the PingRoom API reads, and the server's own way of
 * measuring it.
 *
 * Wire encoding matches `JSON.stringify()` of the TypeScript SDK byte for byte:
 * slashes and non-ASCII characters are written as themselves, an integral float
 * is written without a fraction (`12`, not `12.0`), and floats use the shortest
 * round-trip form whatever `serialize_precision` the host sets.
 *
 * @internal
 */
final class Json
{
    private const WIRE_FLAGS = JSON_THROW_ON_ERROR
        | JSON_UNESCAPED_SLASHES
        | JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_LINE_TERMINATORS;

    private function __construct() {}

    /**
     * Encode a request body.
     *
     * @throws \JsonException when the value cannot be encoded (invalid UTF-8, NaN, INF, depth)
     */
    public static function encode(mixed $value): string
    {
        return self::withShortestFloats(static fn(): string => json_encode($value, self::WIRE_FLAGS));
    }

    /**
     * Encode a request body, mapping a failure to a PingRoomException.
     */
    public static function encodeBody(mixed $value): string
    {
        try {
            return self::encode($value);
        } catch (\JsonException $e) {
            throw PingRoomException::invalidRequest(
                'The request body cannot be encoded as JSON (' . $e->getMessage() . ').',
            );
        }
    }

    /**
     * Decode a JSON text into arrays (`{}` and `[]` both become `[]`), or null
     * when it is not valid JSON. `$ok` tells a decoded `null` from a failure.
     */
    public static function decode(string $text, bool &$ok): mixed
    {
        try {
            $value = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $ok = false;

            return null;
        }
        $ok = true;

        return $value;
    }

    /**
     * Turn a PHP array that stands for a JSON object into something that always
     * encodes as one: an empty `[]` becomes `{}` (an empty PHP array is
     * otherwise written as `[]`, a list).
     */
    public static function asObject(mixed $value): mixed
    {
        return $value === [] ? new \stdClass() : $value;
    }

    /**
     * The bytes the server's 8 KB rule measures: the data as the server holds it
     * after decoding the request (`json_decode(..., true)`), after Laravel's
     * `TrimStrings` and `ConvertEmptyStringsToNull` middleware have run, written
     * back with `json_encode()` and its default flags. Every non-ASCII UTF-16
     * unit is a six-byte `\uXXXX` escape there, and `/` is `\/`.
     *
     * @param array<array-key, mixed> $decoded the value as the server decodes it
     */
    public static function serverBytes(array $decoded): int
    {
        $normalized = self::asServerSees($decoded);

        return strlen(self::withShortestFloats(static fn(): string => json_encode($normalized, JSON_THROW_ON_ERROR)));
    }

    private static function asServerSees(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map(self::asServerSees(...), $value);
        }
        if (is_string($value)) {
            $trimmed = Str::trimLikeLaravel($value);

            return $trimmed === '' ? null : $trimmed;
        }

        return $value;
    }

    /**
     * @param callable(): string $encode
     */
    private static function withShortestFloats(callable $encode): string
    {
        $previous = ini_get('serialize_precision');
        $changed = false;
        // A host may have disabled ini_set(); the encoding is then the host's own.
        if ($previous !== '-1' && function_exists('ini_set')) {
            $changed = @ini_set('serialize_precision', '-1') !== false;
        }
        try {
            return $encode();
        } finally {
            if ($changed && $previous !== false) {
                @ini_set('serialize_precision', $previous);
            }
        }
    }
}
