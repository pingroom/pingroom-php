<?php

declare(strict_types=1);

namespace PingRoom\Internal;

/**
 * String helpers that agree with how the server counts and trims text: code
 * points (`mb_strlen`), not bytes or UTF-16 units.
 *
 * @internal
 */
final class Str
{
    /**
     * What JavaScript's `String.prototype.trim()` strips, so a value the
     * TypeScript SDK calls blank is blank here too.
     */
    private const JS_WHITESPACE = '\t\n\x{000B}\f\r \x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}';

    private function __construct() {}

    /** Length in Unicode code points, as the server's `mb_strlen` counts it. */
    public static function length(string $value): int
    {
        return mb_strlen($value, 'UTF-8');
    }

    /** Whether `$value` is well-formed UTF-8 (JSON cannot carry anything else). */
    public static function isUtf8(string $value): bool
    {
        return mb_check_encoding($value, 'UTF-8');
    }

    /** Trim like JavaScript's `trim()`. */
    public static function jsTrim(string $value): string
    {
        return self::trimWith($value, self::JS_WHITESPACE);
    }

    /** Whether `$value` is empty once trimmed like JavaScript would. */
    public static function isBlank(string $value): bool
    {
        return self::jsTrim($value) === '';
    }

    /**
     * Trim a ping tag as the server does: whitespace plus the byte-order mark
     * and the zero-width space, a superset of the server's trim set so the SDK is
     * never stricter than the route behind it.
     */
    public static function trimTag(string $value): string
    {
        return self::trimWith($value, self::JS_WHITESPACE . '\x{200B}');
    }

    /**
     * Trim a string the way Laravel's `TrimStrings` middleware does, so a value
     * is measured as the server measures it.
     */
    public static function trimLikeLaravel(string $value): string
    {
        // Copied from Illuminate\Support\Str::INVISIBLE_CHARACTERS and trim().
        $invisible = '\x{0009}\x{0020}\x{00A0}\x{00AD}\x{034F}\x{061C}\x{115F}\x{1160}\x{17B4}\x{17B5}\x{180E}\x{2000}\x{2001}\x{2002}\x{2003}\x{2004}\x{2005}\x{2006}\x{2007}\x{2008}\x{2009}\x{200A}\x{200B}\x{200C}\x{200D}\x{200E}\x{200F}\x{202F}\x{205F}\x{2060}\x{2061}\x{2062}\x{2063}\x{2064}\x{2065}\x{206A}\x{206B}\x{206C}\x{206D}\x{206E}\x{206F}\x{3000}\x{2800}\x{3164}\x{FEFF}\x{FFA0}\x{1D159}\x{1D173}\x{1D174}\x{1D175}\x{1D176}\x{1D177}\x{1D178}\x{1D179}\x{1D17A}\x{E0020}';
        $class = '[\s' . $invisible . " \n\r\t\v\0" . ']+';

        return preg_replace('~^' . $class . '|' . $class . '$~u', '', $value) ?? trim($value);
    }

    /** ASCII-only lowercase, like PHP 8's `strtolower`: a non-ASCII letter never folds into an ASCII one. */
    public static function lowerAscii(string $value): string
    {
        return strtr($value, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz');
    }

    private static function trimWith(string $value, string $characters): string
    {
        return preg_replace('/^[' . $characters . ']+|[' . $characters . ']+\z/u', '', $value) ?? $value;
    }
}
