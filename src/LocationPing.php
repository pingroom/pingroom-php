<?php

declare(strict_types=1);

namespace PingRoom;

use PingRoom\Exception\PingRoomException;
use PingRoom\Internal\Str;

/**
 * Location pings: attach a map-ready point to a ping. The reserved wire shape lives
 * at `data.location`:
 *
 *     $pr->broadcast('ab12cd', message: 'Meet me here', data: [
 *         'event' => 'lunch',
 *         ...LocationPing::data(25.2048, 55.2708, 'Dubai Mall'),
 *     ]);
 */
final class LocationPing
{
    public const MAX_LABEL_LENGTH = 100;

    public const MAX_ADDRESS_LENGTH = 255;

    private function __construct() {}

    /**
     * Build and validate the reserved `data.location` fragment for a location ping.
     * Coordinates are decimal degrees, inclusive: latitude -90 to 90, longitude -180
     * to 180. Throws a PingRoomException (`invalid_data`) for anything else.
     *
     * @param string|null $label   display label, at most 100 characters
     * @param string|null $address human-readable address, at most 255 characters
     *
     * @return array{location: array{latitude: int|float, longitude: int|float, label?: string, address?: string}}
     */
    public static function data(int|float $latitude, int|float $longitude, ?string $label = null, ?string $address = null): array
    {
        $location = [
            'latitude' => self::coordinate($latitude, 'latitude', -90, 90),
            'longitude' => self::coordinate($longitude, 'longitude', -180, 180),
        ];
        if ($label !== null) {
            $location['label'] = self::text($label, 'label', self::MAX_LABEL_LENGTH);
        }
        if ($address !== null) {
            $location['address'] = self::text($address, 'address', self::MAX_ADDRESS_LENGTH);
        }

        return ['location' => $location];
    }

    /**
     * Read a valid reserved location from an arbitrary received `data` value. Invalid
     * or absent location metadata is ignored (null) instead of throwing while a
     * notification feed is being processed.
     *
     * @return array{latitude: int|float, longitude: int|float, label?: string, address?: string}|null
     */
    public static function extract(mixed $data): ?array
    {
        if (!is_array($data) || !isset($data['location']) || !is_array($data['location'])) {
            return null;
        }
        $candidate = $data['location'];
        $latitude = $candidate['latitude'] ?? null;
        $longitude = $candidate['longitude'] ?? null;
        if (!(is_int($latitude) || is_float($latitude)) || !(is_int($longitude) || is_float($longitude))) {
            return null;
        }
        foreach (['label' => self::MAX_LABEL_LENGTH, 'address' => self::MAX_ADDRESS_LENGTH] as $key => $max) {
            if (array_key_exists($key, $candidate) && (!is_string($candidate[$key]) || !Str::isUtf8($candidate[$key]) || Str::length($candidate[$key]) > $max)) {
                return null;
            }
        }
        try {
            $location = self::data(
                $latitude,
                $longitude,
                isset($candidate['label']) && is_string($candidate['label']) ? $candidate['label'] : null,
                isset($candidate['address']) && is_string($candidate['address']) ? $candidate['address'] : null,
            );
        } catch (PingRoomException) {
            return null;
        }

        return $location['location'];
    }

    private static function coordinate(int|float $value, string $name, int $min, int $max): int|float
    {
        if (is_float($value) && !is_finite($value)) {
            throw PingRoomException::invalidData("locationPing: {$name} must be a finite JSON number");
        }
        if ($value < $min || $value > $max) {
            throw PingRoomException::invalidData("locationPing: {$name} must be between {$min} and {$max}");
        }

        return $value;
    }

    private static function text(string $value, string $name, int $max): string
    {
        if (!Str::isUtf8($value) || Str::length($value) > $max) {
            throw PingRoomException::invalidData("locationPing: {$name} must be at most {$max} characters");
        }

        return $value;
    }
}
