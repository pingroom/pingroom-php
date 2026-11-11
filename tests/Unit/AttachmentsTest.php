<?php

declare(strict_types=1);

namespace PingRoom\Tests\Unit;

use PingRoom\Limits;
use PingRoom\Tests\Support\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class AttachmentsTest extends TestCase
{
    private const ATTACHMENT = ['id' => 'att_1', 'filename' => 'brief.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 5, 'created_at' => '2026-08-10T08:00:00Z'];

    public function testUploadPostsMultipartWithAGeneratedBoundaryAndNoJsonContentType(): void
    {
        [$pr, $http] = $this->clientAnswering(['attachment' => self::ATTACHMENT], 201);
        $attachment = $pr->attachments->upload("%PDF-\x00\xFF", 'brief.pdf', 'application/pdf');

        self::assertSame('att_1', $attachment['id']);
        $request = $http->last();
        self::assertSame(['POST', '/api/agent/attachments'], [$request->getMethod(), $request->getUri()->getPath()]);
        self::assertSame('Bearer ' . self::TOKEN, $request->getHeaderLine('Authorization'));

        $contentType = $request->getHeaderLine('Content-Type');
        self::assertMatchesRegularExpression('/^multipart\/form-data; boundary=(----PingRoomPHP[0-9a-f]{32})$/', $contentType);
        $boundary = substr($contentType, strlen('multipart/form-data; boundary='));
        self::assertSame(
            "--{$boundary}\r\n"
            . "Content-Disposition: form-data; name=\"file\"; filename=\"brief.pdf\"\r\n"
            . "Content-Type: application/pdf\r\n\r\n"
            . "%PDF-\x00\xFF\r\n"
            . "--{$boundary}--\r\n",
            (string) $request->getBody(),
        );
    }

    public function testTheTypeDefaultsToOctetStreamAndAFilenameIsQuotedSafely(): void
    {
        [$pr, $http] = $this->clientAnswering(['attachment' => self::ATTACHMENT], 201);
        $pr->attachments->upload('notes', "no\"te\r\ns.txt");
        $body = (string) $http->last()->getBody();
        self::assertStringContainsString('filename="no%22te%0D%0As.txt"', $body);
        self::assertStringContainsString("Content-Type: application/octet-stream\r\n\r\nnotes\r\n", $body);
    }

    public function testAContentTypeThatCouldInjectAPartHeaderIsRefused(): void
    {
        [$pr, $http] = $this->clientAnswering(['attachment' => self::ATTACHMENT], 201);
        $e = $this->thrown(fn() => $pr->attachments->upload('x', 'a.txt', "text/plain\r\nX-Evil: 1"));
        self::assertSame('invalid_request', $e->errorCode);
        self::assertSame([], $http->requests);
    }

    public function testAnOversizedFileIsRefusedLocallyBeforeAnyBytesTravel(): void
    {
        [$pr, $http] = $this->clientAnswering(['attachment' => self::ATTACHMENT], 201);
        $e = $this->thrown(fn() => $pr->attachments->upload(str_repeat('a', Limits::MAX_ATTACHMENT_BYTES + 1), 'big.txt'));
        self::assertSame('attachment_too_large', $e->errorCode);
        self::assertSame(413, $e->status);
        self::assertSame([], $http->requests);

        $pr->attachments->upload(str_repeat('a', Limits::MAX_ATTACHMENT_BYTES), 'max.txt');
        self::assertCount(1, $http->requests);
        self::assertSame('invalid_request', $this->thrown(fn() => $pr->attachments->upload('x', ' '))->errorCode);
    }

    public function testUploadFileReadsADiskFileAndChecksItsSizeFirst(): void
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'pr-upload');
        file_put_contents($path, 'hello');
        try {
            [$pr, $http] = $this->clientAnswering(['attachment' => self::ATTACHMENT], 201);
            $pr->attachments->uploadFile($path, contentType: 'text/plain');
            self::assertStringContainsString('filename="' . basename($path) . '"', (string) $http->last()->getBody());
            self::assertStringContainsString("Content-Type: text/plain\r\n\r\nhello\r\n", (string) $http->last()->getBody());

            $pr->attachments->uploadFile($path, 'renamed.txt');
            self::assertStringContainsString('filename="renamed.txt"', (string) $http->last()->getBody());
        } finally {
            unlink($path);
        }
        self::assertSame('invalid_request', $this->thrown(fn() => $pr->attachments->uploadFile($path))->errorCode);
    }

    public function testManifestReturnsAZipListingAndNullForANonArchive(): void
    {
        $manifest = ['entries' => [['name' => 'src', 'size_bytes' => null, 'is_directory' => true], ['name' => 'src/index.js', 'size_bytes' => 300, 'is_directory' => false]], 'total_entries' => 2, 'truncated' => false, 'total_uncompressed_bytes' => 300];
        [$pr, $http] = $this->clientWith(fn(RequestInterface $r): ResponseInterface => self::json(200, ['manifest' => str_contains($r->getUri()->getPath(), 'att_zip') ? $manifest : null]));

        $result = $pr->attachments->manifest('att_zip');
        self::assertNotNull($result);
        self::assertSame(2, $result['total_entries']);
        self::assertSame('src/index.js', $result['entries'][1]['name']);
        self::assertNull($result['entries'][0]['size_bytes']);
        self::assertNull($pr->attachments->manifest('att_pdf'));
        self::assertSame('/api/agent/attachments/att_pdf/manifest', $http->last()->getUri()->getPath());
    }

    public function testContentReturnsTheRawResponseButStillThrowsOnARefusal(): void
    {
        [$pr, $http] = $this->clientWith(fn(RequestInterface $r): ResponseInterface => str_contains($r->getUri()->getPath(), 'att_gone')
            ? self::json(404, ['message' => 'Not found'])
            : self::raw(200, '%PDF-bytes', ['Content-Type' => 'application/pdf']));

        $response = $pr->attachments->content('att_1');
        self::assertSame('application/pdf', $response->getHeaderLine('Content-Type'));
        self::assertSame('%PDF-bytes', (string) $response->getBody());
        self::assertSame('*/*', $http->last()->getHeaderLine('Accept'));
        self::assertSame('/api/agent/attachments/att_1/content', $http->last()->getUri()->getPath());

        $e = $this->thrown(fn() => $pr->attachments->content('att_gone'));
        self::assertSame(404, $e->status);
    }

    public function testDeleteDiscardsAnUnattachedUpload(): void
    {
        [$pr, $http] = $this->clientAnswering(['message' => 'deleted']);
        $pr->attachments->delete('att_1');
        self::assertSame(['DELETE', '/api/agent/attachments/att_1'], [$http->last()->getMethod(), $http->last()->getUri()->getPath()]);
        self::assertSame('invalid_request', $this->thrown(fn() => $pr->attachments->delete(' '))->errorCode);
    }

    public function testA402UploadSurfacesTheProGate(): void
    {
        [$pr] = $this->clientAnswering(['error' => 'pro_required', 'code' => 'pro_required', 'message' => 'Ping attachments are a Pro feature.'], 402);
        $e = $this->thrown(fn() => $pr->attachments->upload('notes', 'notes.txt'));
        self::assertSame([402, 'pro_required'], [$e->status, $e->errorCode]);
    }

    public function testAttachmentIdsRideTheBroadcastBodyWhileTheBytesDoNot(): void
    {
        [$pr, $http] = $this->clientAnswering(['id' => 'n1'], 201);
        $pr->broadcast('AB12', 'report attached', attachmentIds: ['att_1', 'att_2', 'att_3', 'att_4']);
        self::assertSame(['att_1', 'att_2', 'att_3', 'att_4'], $http->jsonBody()['attachment_ids']);
        self::assertSame('application/json', $http->last()->getHeaderLine('Content-Type'));
    }
}
