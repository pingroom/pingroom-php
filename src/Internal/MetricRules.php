<?php

declare(strict_types=1);

namespace PingRoom\Internal;

use PingRoom\Api\Metrics;
use PingRoom\Exception\PingRoomException;

/**
 * Room metric checks, mirroring the server's `RoomMetricRules`, so a typo fails
 * here, before a request, instead of as a 422. The server stays authoritative: it
 * also knows the stored metric (a partial update is judged by the stored format)
 * and the ISO 4217 code list, so nothing here is stricter than it. Only the
 * `[A-Z]{3}` shape of a currency code is checked, so a code the server accepts is
 * never refused.
 *
 * @internal
 */
final class MetricRules
{
    private const CURRENCY_SHAPE = '/^[A-Z]{3}\z/';

    /** A plain decimal, the only text the server reads as a number: no exponent, hex, padding, NaN or Infinity. */
    private const DECIMAL = '/^-?\d+(?:\.\d+)?\z/';

    private const CONTROL = '/[\x{0000}-\x{001F}\x{007F}-\x{009F}]/u';

    private function __construct() {}

    /**
     * The key the server addresses a metric by: trimmed and lowercased (ASCII
     * only, like PHP's `strtolower`), then checked against the stored shape.
     */
    public static function normalizeKey(mixed $key, string $field = 'key'): string
    {
        if (!is_string($key)) {
            throw self::invalid("`{$field}` must be a string.");
        }
        $normalized = Str::lowerAscii(Str::jsTrim($key));
        if (preg_match(Metrics::KEY_PATTERN, $normalized) !== 1) {
            throw self::invalid(
                "`{$field}` must be 1–" . Metrics::MAX_KEY_LENGTH
                . ' characters: letters, digits, "-" and "_", starting with a letter or digit.',
            );
        }

        return $normalized;
    }

    /**
     * Check the fields of one metric write: the value (required when
     * `$requireValue`), and the optional label, format and unit. Used by
     * `Metrics::set()` and by the `metric` of an incoming webhook, which share the
     * server rule. A key that is absent from `$fields` is not sent.
     *
     * A value with no `format` is a number or a string: the server makes a new
     * metric `number` or `text` by its type and judges an existing one by its
     * stored format, so only what holds for either is checked.
     *
     * @param array<array-key, mixed> $fields `value`, `label`, `format`, `unit`
     */
    public static function assertFields(array $fields, bool $requireValue, string $prefix = ''): void
    {
        $name = static fn(string $field): string => $prefix . $field;
        $hasFormat = array_key_exists('format', $fields);
        $format = $fields['format'] ?? null;

        if ($hasFormat && (!is_string($format) || !in_array($format, Metrics::FORMATS, true))) {
            throw self::invalid('`' . $name('format') . '` must be one of: ' . implode(', ', Metrics::FORMATS) . '.');
        }
        $format = $hasFormat ? (string) $format : null;

        if (array_key_exists('label', $fields)) {
            self::assertShortText($fields['label'], Metrics::MAX_LABEL_LENGTH, $name('label'));
        }

        $unit = $fields['unit'] ?? null;
        if ($unit !== null) {
            if ($format !== null && $format !== 'currency' && $format !== 'unit') {
                throw self::invalid('`' . $name('unit') . '` is only allowed with the currency and unit formats.');
            }
            $text = self::assertShortText($unit, Metrics::MAX_UNIT_LENGTH, $name('unit'));
            if ($format === 'currency' && preg_match(self::CURRENCY_SHAPE, mb_strtoupper($text, 'UTF-8')) !== 1) {
                throw self::invalid('`' . $name('unit') . '` for the currency format must be an ISO 4217 code such as USD.');
            }
        }

        $value = $fields['value'] ?? null;
        if ($value === null) {
            if ($requireValue) {
                throw self::invalid('`' . $name('value') . '` is required.');
            }

            return;
        }
        if (!is_int($value) && !is_float($value) && !is_string($value)) {
            throw self::invalid('`' . $name('value') . '` must be a number or a string.');
        }
        if ($format === 'text') {
            self::assertTextValue($value, $name('value'));
        } elseif ($format !== null || !is_string($value)) {
            self::assertNumericValue($value, $format, $name('value'));
        } else {
            // No format and a string: text for a new metric, a number for an
            // existing numeric one. Hold it to the limits both would.
            if (!Str::isUtf8($value)
                || Str::length($value) > Metrics::MAX_TEXT_VALUE_LENGTH
                || Str::isBlank($value)
                || preg_match(self::CONTROL, $value) === 1) {
                throw self::invalid(
                    '`' . $name('value') . '` must be a number, or a string of 1–' . Metrics::MAX_TEXT_VALUE_LENGTH
                    . ' characters without control characters.',
                );
            }
        }
    }

    /**
     * The value as it goes on the wire: an integral float is written as an
     * integer, as JavaScript writes it (`12`, never `12.0` or `1.0e+15`).
     */
    public static function wireValue(int|float|string $value): int|float|string
    {
        if (is_float($value) && is_finite($value) && floor($value) === $value && abs($value) <= Metrics::MAX_ABSOLUTE_VALUE) {
            return (int) $value;
        }

        return $value;
    }

    private static function assertShortText(mixed $value, int $max, string $field): string
    {
        if (!is_string($value)) {
            throw self::invalid("`{$field}` must be a string.");
        }
        if (!Str::isUtf8($value)) {
            throw self::invalid("`{$field}` must be valid UTF-8.");
        }
        $text = Str::jsTrim($value);
        if ($text === '' || Str::length($text) > $max) {
            throw self::invalid("`{$field}` must be 1–{$max} characters.");
        }
        if (preg_match(self::CONTROL, $text) === 1) {
            throw self::invalid("`{$field}` must not contain control characters.");
        }

        return $text;
    }

    /** A numeric value must be a finite number (or a plain decimal string) within ±1e15. */
    private static function assertNumericValue(int|float|string $value, ?string $format, string $field): void
    {
        $label = $format === null ? 'a number' : "a number for the {$format} format";
        if (is_string($value)) {
            $trimmed = Str::jsTrim($value);
            if (preg_match(self::DECIMAL, $trimmed) !== 1) {
                throw self::invalid("`{$field}` must be {$label}: a finite number, or a plain decimal string such as \"12.5\".");
            }
            $number = (float) $trimmed;
        } else {
            $number = (float) $value;
        }
        if (!is_finite($number)) {
            throw self::invalid("`{$field}` must be a finite number (not NaN or Infinity).");
        }
        if (abs($number) > Metrics::MAX_ABSOLUTE_VALUE) {
            throw self::invalid("`{$field}` must have an absolute value of at most " . sprintf('%.0f', Metrics::MAX_ABSOLUTE_VALUE) . '.');
        }
    }

    private static function assertTextValue(int|float|string $value, string $field): void
    {
        if (!is_string($value)) {
            throw self::invalid("`{$field}` must be a string for the text format.");
        }
        if (!Str::isUtf8($value)
            || Str::length($value) > Metrics::MAX_TEXT_VALUE_LENGTH
            || Str::isBlank($value)) {
            throw self::invalid("`{$field}` must be 1–" . Metrics::MAX_TEXT_VALUE_LENGTH . ' characters for the text format.');
        }
        if (preg_match(self::CONTROL, $value) === 1) {
            throw self::invalid("`{$field}` must not contain control characters.");
        }
    }

    private static function invalid(string $message): PingRoomException
    {
        return PingRoomException::invalidRequest($message);
    }
}
