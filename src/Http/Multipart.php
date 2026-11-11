<?php

declare(strict_types=1);

namespace PingRoom\Http;

/**
 * A `multipart/form-data` body with one file part, built as a browser's
 * `FormData` writes it: the part is named `file`, carries the filename, and its
 * type defaults to `application/octet-stream`. Bytes never travel in a JSON body.
 *
 * @internal
 */
final class Multipart
{
    private function __construct() {}

    /**
     * @return array{body: string, contentType: string}
     */
    public static function file(string $field, string $filename, string $contents, string $contentType): array
    {
        $boundary = '----PingRoomPHP' . bin2hex(random_bytes(16));
        // The escapes the HTML form-data encoding applies to a quoted value.
        $quote = static fn(string $value): string => strtr($value, ['"' => '%22', "\r" => '%0D', "\n" => '%0A']);
        $body = "--{$boundary}\r\n"
            . 'Content-Disposition: form-data; name="' . $quote($field) . '"; filename="' . $quote($filename) . "\"\r\n"
            . 'Content-Type: ' . $contentType . "\r\n\r\n"
            . $contents . "\r\n"
            . "--{$boundary}--\r\n";

        return ['body' => $body, 'contentType' => "multipart/form-data; boundary={$boundary}"];
    }
}
