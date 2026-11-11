<?php

declare(strict_types=1);

namespace PingRoom\Internal;

use PingRoom\Exception\PingRoomException;

/**
 * Shape checks for server responses the wait loops and the claim call trust.
 *
 * Decoded JSON has no distinct object type: `{}` and `[]` are both `[]`. A
 * "record" here is an array that is not a non-empty list, so an empty object
 * counts as one.
 *
 * @internal
 */
final class Validate
{
    private function __construct() {}

    public static function isRecord(mixed $value): bool
    {
        return is_array($value) && ($value === [] || !array_is_list($value));
    }

    public static function isNonEmptyString(mixed $value): bool
    {
        return is_string($value) && !Str::isBlank($value);
    }

    /** UUIDs are case-insensitive; a caller may hold an id in either case. */
    public static function sameId(mixed $received, string $expected): bool
    {
        return is_string($received) && strtolower($received) === strtolower($expected);
    }

    /**
     * A decoded 2xx body the call expects to be a JSON object.
     *
     * @return array<string, mixed>
     */
    public static function record(mixed $value): array
    {
        if (!self::isRecord($value)) {
            throw PingRoomException::invalidResponse(200, 'a JSON object', $value);
        }
        /** @var array<string, mixed> $value */

        return $value;
    }

    /**
     * What an unusable wait response says about itself, for the exception body.
     */
    public static function invalidWaitResponse(string $subject, string $expectedId, mixed $value, string $stateField): PingRoomException
    {
        return new PingRoomException(
            "PingRoom returned an invalid {$subject} wait response.",
            'invalid_response',
            0,
            null,
            [
                'expected_id' => $expectedId,
                'received_id' => self::isRecord($value) && is_array($value) && isset($value['id']) && is_string($value['id']) ? $value['id'] : null,
                $stateField => self::isRecord($value) && is_array($value) && isset($value[$stateField]) && is_string($value[$stateField]) ? $value[$stateField] : null,
            ],
        );
    }
}
