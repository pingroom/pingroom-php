<?php

declare(strict_types=1);

namespace PingRoom\Api;

use PingRoom\Exception\PingRoomException;
use PingRoom\Http\Transport;
use PingRoom\Internal\Guards;
use PingRoom\Internal\Path;
use PingRoom\Internal\Validate;
use PingRoom\Limits;
use Psr\Http\Message\ResponseInterface;

/**
 * `$client->attachments`: upload, read and discard private files.
 *
 * Upload first, then pass the returned ids to `broadcast()` or `questions->ask()`
 * as `attachmentIds`. Bytes never travel inside a JSON ping body. An upload that is
 * never attached expires on its own after 24 hours. Each file must be 1 byte to 5
 * MiB with an md, pdf, html, txt, jpg, jpeg, png or zip filename; a Ping or Question
 * accepts at most four ids.
 *
 * Uploading requires the bound account to hold Pro (the API answers 402
 * `pro_required` otherwise). Reading and deleting are not gated.
 */
final class Attachments
{
    public function __construct(
        private readonly Transport $http,
    ) {}

    /**
     * Upload one file and get back the id a send can claim.
     *
     * @param string      $content     the file's bytes (1 byte to 5 MiB)
     * @param string      $filename    display filename; its extension must be one the server accepts
     * @param string|null $contentType overrides the type sent in the multipart part (default `application/octet-stream`)
     *
     * @return array<string, mixed> `id`, `filename`, `mime_type`, `size_bytes`, `created_at`
     */
    public function upload(string $content, string $filename, ?string $contentType = null): array
    {
        Guards::requireNonEmpty($filename, 'filename');
        // The server answers an oversized body with 413 before reading it; refuse
        // locally so no bytes travel for a file that can never be accepted.
        if (strlen($content) > Limits::MAX_ATTACHMENT_BYTES) {
            throw new PingRoomException('Attachments are at most 5 MiB each.', 'attachment_too_large', 413);
        }
        $type = $contentType ?? 'application/octet-stream';
        if (preg_match('/^[\x20-\x7E]+\z/', $type) !== 1) {
            throw PingRoomException::invalidRequest('`contentType` must be printable ASCII.');
        }
        if (!mb_check_encoding($filename, 'UTF-8')) {
            throw PingRoomException::invalidRequest('`filename` must be valid UTF-8.');
        }

        $result = Validate::record($this->http->request('POST', '/api/agent/attachments', [
            'multipart' => ['filename' => $filename, 'contents' => $content, 'contentType' => $type],
        ]));
        $attachment = $result['attachment'] ?? null;
        if (!Validate::isRecord($attachment)) {
            throw PingRoomException::invalidResponse(200, 'an attachment object', $result);
        }

        /** @var array<string, mixed> $attachment */
        return $attachment;
    }

    /**
     * Upload a file from disk. The filename defaults to the file's own name.
     *
     * @return array<string, mixed>
     */
    public function uploadFile(string $path, ?string $filename = null, ?string $contentType = null): array
    {
        if (!is_file($path) || !is_readable($path)) {
            throw PingRoomException::invalidRequest('The file to upload does not exist or cannot be read.');
        }
        $size = filesize($path);
        if ($size !== false && $size > Limits::MAX_ATTACHMENT_BYTES) {
            throw new PingRoomException('Attachments are at most 5 MiB each.', 'attachment_too_large', 413);
        }
        $content = file_get_contents($path);
        if ($content === false) {
            throw PingRoomException::invalidRequest('The file to upload cannot be read.');
        }

        return $this->upload($content, $filename ?? basename($path), $contentType);
    }

    /**
     * The raw bytes of an attachment this credential is allowed to see, as the
     * PSR-7 response: read `getBody()` and `getHeaderLine('Content-Type')`. A
     * refusal still throws a PingRoomException, never an error body read as content.
     */
    public function content(string $attachmentId): ResponseInterface
    {
        Guards::requireNonEmpty($attachmentId, 'attachmentId');

        return $this->http->raw('GET', '/api/agent/attachments/' . Path::segment($attachmentId) . '/content');
    }

    /**
     * The listing of a `.zip`'s contents, recorded when it was uploaded; null for a
     * non-archive, and for an archive whose central directory did not parse. Nothing
     * decompresses the file to produce this, so the declared sizes are what the
     * archive claims, not verified output.
     *
     * @return array<string, mixed>|null `entries`, `total_entries`, `truncated`, `total_uncompressed_bytes`
     */
    public function manifest(string $attachmentId): ?array
    {
        Guards::requireNonEmpty($attachmentId, 'attachmentId');
        $result = Validate::record($this->http->request('GET', '/api/agent/attachments/' . Path::segment($attachmentId) . '/manifest'));
        $manifest = $result['manifest'] ?? null;
        if ($manifest === null) {
            return null;
        }
        if (!Validate::isRecord($manifest)) {
            throw PingRoomException::invalidResponse(200, 'a manifest object or null', $result);
        }

        /** @var array<string, mixed> $manifest */
        return $manifest;
    }

    /**
     * Discard an upload that has not been attached to a ping yet.
     */
    public function delete(string $attachmentId): void
    {
        Guards::requireNonEmpty($attachmentId, 'attachmentId');
        $this->http->request('DELETE', '/api/agent/attachments/' . Path::segment($attachmentId));
    }
}
