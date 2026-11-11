<?php

declare(strict_types=1);

namespace PingRoom\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PingRoom\Webhook;

final class WebhookSignatureTest extends TestCase
{
    private const SECRET = 'whsec_test_123';
    private const DELIVERY = 'delivery-test-1';
    private const NOW = 1_794_146_700;

    private string $body;

    private string $timestamp;

    private string $v1;

    private string $v2;

    protected function setUp(): void
    {
        $this->body = json_encode(['event' => 'ping', 'notification_id' => 'n1', 'message' => 'hi'], JSON_THROW_ON_ERROR);
        $this->timestamp = (string) self::NOW;
        $this->v1 = hash_hmac('sha256', "{$this->timestamp}.{$this->body}", self::SECRET);
        $this->v2 = hash_hmac('sha256', "v2\n{$this->timestamp}\n" . self::DELIVERY . "\n{$this->body}", self::SECRET);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function verify(array $overrides = []): bool
    {
        $arguments = [...[
            'payload' => $this->body,
            'secret' => self::SECRET,
            'timestamp' => $this->timestamp,
            'now' => self::NOW,
        ], ...$overrides];

        return Webhook::verifySignature(...$arguments);
    }

    public function testItFallsBackToAValidLegacySha256PrefixedSignature(): void
    {
        self::assertTrue($this->verify(['signature' => "sha256={$this->v1}"]));
    }

    public function testItFallsBackToLegacyBareHexWithoutPrefix(): void
    {
        self::assertTrue($this->verify(['signature' => $this->v1]));
        self::assertTrue($this->verify(['signature' => strtoupper($this->v1)]), 'hex case does not matter');
        self::assertTrue($this->verify(['signature' => "  sha256={$this->v1} "]), 'surrounding whitespace is trimmed');
    }

    public function testItAcceptsADeliveryBoundV2Signature(): void
    {
        self::assertTrue($this->verify(['signatureV2' => $this->v2, 'deliveryId' => self::DELIVERY]));
    }

    public function testItDoesNotDowngradeToValidV1WhenASuppliedV2SignatureFails(): void
    {
        self::assertFalse($this->verify(['signature' => $this->v1, 'signatureV2' => str_repeat('0', 64), 'deliveryId' => self::DELIVERY]));
    }

    public function testChangingOnlyTheDeliveryIdInvalidatesV2ButNotLegacyV1(): void
    {
        self::assertTrue($this->verify(['signature' => $this->v1, 'deliveryId' => 'delivery-tampered']));
        self::assertFalse($this->verify(['signature' => $this->v1, 'signatureV2' => $this->v2, 'deliveryId' => 'delivery-tampered']));
    }

    public function testItRequiresASafeDeliveryIdWheneverV2IsSupplied(): void
    {
        foreach ([null, '', "delivery\ninjected", 'has space', str_repeat('d', 256), "d\u{00E9}"] as $deliveryId) {
            self::assertFalse($this->verify(['signature' => $this->v1, 'signatureV2' => $this->v2, 'deliveryId' => $deliveryId]), json_encode($deliveryId) ?: '');
        }
    }

    public function testItPreservesNonUtf8RawBodyBytes(): void
    {
        $bytes = "\xFF\xFE\x00a";
        $v1 = hash_hmac('sha256', "{$this->timestamp}." . $bytes, self::SECRET);
        $v2 = hash_hmac('sha256', "v2\n{$this->timestamp}\n" . self::DELIVERY . "\n" . $bytes, self::SECRET);
        self::assertTrue($this->verify(['payload' => $bytes, 'signature' => $v1]));
        self::assertTrue($this->verify(['payload' => $bytes, 'signatureV2' => $v2, 'deliveryId' => self::DELIVERY]));
    }

    public function testItRejectsATamperedBody(): void
    {
        self::assertFalse($this->verify(['payload' => $this->body . ' ', 'signature' => $this->v1]));
        self::assertFalse($this->verify(['payload' => $this->body . ' ', 'signatureV2' => $this->v2, 'deliveryId' => self::DELIVERY]));
        // Re-encoding the parsed JSON changes the bytes: that is why the raw body is required.
        $reencoded = json_encode(json_decode($this->body, true), JSON_PRETTY_PRINT);
        self::assertFalse($this->verify(['payload' => (string) $reencoded, 'signature' => $this->v1]));
    }

    public function testItRejectsATamperedOrMissingTimestamp(): void
    {
        self::assertFalse($this->verify(['signature' => $this->v1, 'timestamp' => (string) (self::NOW - 1)]));
        self::assertFalse($this->verify(['signature' => $this->v1, 'timestamp' => null]));
        foreach (['', 'abc', '12.5', '-5', '1e3', '9007199254740993'] as $timestamp) {
            self::assertFalse($this->verify(['signature' => $this->v1, 'timestamp' => $timestamp]), $timestamp);
        }
        self::assertTrue($this->verify(['signature' => $this->v1, 'timestamp' => self::NOW]), 'an int timestamp is accepted');
        self::assertTrue($this->verify(['signature' => $this->v1, 'timestamp' => " {$this->timestamp} "]), 'a header value is trimmed');
    }

    public function testItRejectsTheWrongSecretAndAnEmptyOne(): void
    {
        self::assertFalse($this->verify(['signature' => $this->v1, 'secret' => 'nope']));
        self::assertFalse($this->verify(['signature' => $this->v1, 'secret' => '']));
    }

    public function testItRejectsMissingOrMalformedSignatures(): void
    {
        self::assertFalse($this->verify([]));
        self::assertFalse($this->verify(['signature' => null]));
        self::assertFalse($this->verify(['signature' => '']));
        self::assertFalse($this->verify(['signature' => 'sha256=zzzz']));
        self::assertFalse($this->verify(['signature' => substr($this->v1, 0, 63)]));
        self::assertFalse($this->verify(['signature' => $this->v1 . "\n0"]));
        self::assertFalse($this->verify(['signature' => $this->v1, 'signatureV2' => 'sha256=zzzz', 'deliveryId' => self::DELIVERY]));
        self::assertFalse($this->verify(['signature' => $this->v1, 'signatureV2' => '', 'deliveryId' => self::DELIVERY]));
        self::assertFalse($this->verify(['signature' => 'SHA256=' . $this->v1]), 'only the lowercase prefix is stripped');
    }

    public function testItEnforcesTheDefaultAndCustomReplayWindows(): void
    {
        $old = (string) (self::NOW - 301);
        $oldHex = hash_hmac('sha256', "{$old}.{$this->body}", self::SECRET);
        self::assertFalse($this->verify(['signature' => $oldHex, 'timestamp' => $old]));
        self::assertTrue($this->verify(['signature' => $oldHex, 'timestamp' => $old, 'maxAgeSeconds' => 600]));

        $edge = (string) (self::NOW - 300);
        self::assertTrue($this->verify(['signature' => hash_hmac('sha256', "{$edge}.{$this->body}", self::SECRET), 'timestamp' => $edge]));

        // A timestamp from the future is as stale as one from the past.
        $future = (string) (self::NOW + 301);
        self::assertFalse($this->verify(['signature' => hash_hmac('sha256', "{$future}.{$this->body}", self::SECRET), 'timestamp' => $future]));

        foreach ([-1, NAN] as $maxAge) {
            self::assertFalse($this->verify(['signature' => $this->v1, 'maxAgeSeconds' => $maxAge]));
        }
    }

    public function testRequireV2RefusesADeliveryThatCarriesOnlyTheLegacyV1Signature(): void
    {
        // The replay the option closes: a captured body re-sent without its V2 header
        // and under a new delivery id still passes V1.
        $replay = ['signature' => $this->v1, 'deliveryId' => 'delivery-replayed'];
        self::assertTrue($this->verify($replay));
        self::assertFalse($this->verify([...$replay, 'requireV2' => true]));
        self::assertFalse($this->verify([...$replay, 'signatureV2' => null, 'requireV2' => true]));
    }

    public function testRequireV2AcceptsAValidV2DeliveryAndStillRejectsABadOne(): void
    {
        $delivery = ['signature' => $this->v1, 'signatureV2' => $this->v2, 'deliveryId' => self::DELIVERY, 'requireV2' => true];
        self::assertTrue($this->verify($delivery));
        self::assertFalse($this->verify([...$delivery, 'deliveryId' => 'delivery-tampered']));
        self::assertFalse($this->verify([...$delivery, 'signatureV2' => str_repeat('0', 64)]));
    }

    public function testThePayloadMustBeTheRawStringAnythingElseIsATypeErrorNotFalse(): void
    {
        // What a body parser that did not capture the raw bytes leaves behind: a parsed
        // object or an array. It is a bug in the receiver, not a forged delivery.
        foreach ([json_decode($this->body, true), [], null, 42, true, new \stdClass()] as $payload) {
            try {
                Webhook::verifySignature($payload, self::SECRET, signatureV2: $this->v2, timestamp: $this->timestamp, deliveryId: self::DELIVERY, requireV2: true, now: self::NOW);
                self::fail('expected a TypeError');
            } catch (\TypeError $e) {
                self::assertStringContainsString('payload', $e->getMessage());
            }
        }
    }

    public function testItUsesTheRealClockByDefault(): void
    {
        $now = (string) time();
        $signature = hash_hmac('sha256', "{$now}.{$this->body}", self::SECRET);
        self::assertTrue(Webhook::verifySignature($this->body, self::SECRET, signature: $signature, timestamp: $now));
        $old = (string) (time() - 3600);
        self::assertFalse(Webhook::verifySignature($this->body, self::SECRET, signature: hash_hmac('sha256', "{$old}.{$this->body}", self::SECRET), timestamp: $old));
    }

    public function testTheHeaderNamesAreTheDocumentedOnes(): void
    {
        self::assertSame('X-PingRoom-Signature', Webhook::SIGNATURE_HEADER);
        self::assertSame('X-PingRoom-Signature-V2', Webhook::SIGNATURE_V2_HEADER);
        self::assertSame('X-PingRoom-Timestamp', Webhook::TIMESTAMP_HEADER);
        self::assertSame('X-PingRoom-Delivery', Webhook::DELIVERY_HEADER);
        self::assertSame('X-PingRoom-Event', Webhook::EVENT_HEADER);
    }

    public function testAPingButtonDeliveryVerifiesWithThePingsOwnSecret(): void
    {
        // The bytes and headers PingRoom's Worker sends a webhook button's URL.
        $secret = '81a29861609a0f98edae7253d871a336aa20ef1df78d1dac0da521d983b54623';
        $payload = json_encode([
            'event' => 'notification.action', 'notification_id' => '9b2f5c1e-0000-4000-8000-000000000001', 'action_id' => 'clean',
            'room' => ['code' => 'AB12CD', 'name' => 'Ops'], 'actor' => ['id' => '0123456789abcdef0123456789abcdef', 'display_name' => 'Sam'],
            'correlation_id' => null, 'body' => ['host' => 'db-2'], 'timestamp' => '2026-11-11T10:00:00+00:00',
        ], JSON_THROW_ON_ERROR);
        $timestamp = (string) self::NOW;
        $deliveryId = '6c5b1a2e-0000-4000-8000-00000000000a';
        $signatureV2 = hash_hmac('sha256', "v2\n{$timestamp}\n{$deliveryId}\n{$payload}", $secret);

        self::assertTrue(Webhook::verifySignature($payload, $secret, signatureV2: $signatureV2, timestamp: $timestamp, deliveryId: $deliveryId, requireV2: true, now: self::NOW));
        self::assertFalse(Webhook::verifySignature($payload, str_repeat('ff', 32), signatureV2: $signatureV2, timestamp: $timestamp, deliveryId: $deliveryId, requireV2: true, now: self::NOW));
        self::assertFalse(Webhook::verifySignature(str_replace('db-2', 'db-3', $payload), $secret, signatureV2: $signatureV2, timestamp: $timestamp, deliveryId: $deliveryId, requireV2: true, now: self::NOW));
        self::assertFalse(Webhook::verifySignature($payload, $secret, signatureV2: $signatureV2, timestamp: (string) (self::NOW - 3600), deliveryId: $deliveryId, requireV2: true, now: self::NOW));
    }
}
