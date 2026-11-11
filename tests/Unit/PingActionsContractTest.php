<?php

declare(strict_types=1);

namespace PingRoom\Tests\Unit;

use PingRoom\ErrorCodes;
use PingRoom\Exception\PingRoomException;
use PingRoom\Internal\PingActionRules;
use PingRoom\PingAction;
use PingRoom\Tests\Support\Fixture;
use PingRoom\Tests\Support\TestCase;
use PingRoom\Webhook;

/**
 * Replays tests/contracts/ping-actions.json: the limits, the reserved labels, the
 * server's verdict on every label and URL vector, and the per-Ping signing vector.
 */
final class PingActionsContractTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $fixture;

    protected function setUp(): void
    {
        $this->fixture = Fixture::load('ping-actions');
    }

    public function testTheSdkLimitsAreTheServers(): void
    {
        self::assertSame($this->fixture['limits'], [
            'max_actions' => PingAction::MAX_ACTIONS,
            'id_pattern' => PingAction::ID_PATTERN_SOURCE,
            'label_max' => PingAction::MAX_LABEL_LENGTH,
            'url_max' => PingAction::MAX_URL_LENGTH,
            'body_max_bytes' => PingAction::MAX_BODY_BYTES,
            'body_max_keys' => PingAction::MAX_BODY_KEYS,
            'run_window_hours' => PingAction::RUN_WINDOW_HOURS,
            'types' => PingAction::TYPES,
            'styles' => PingAction::STYLES,
        ]);
        // The pattern the SDK matches with is the published one, anchored the PHP way.
        self::assertSame('/' . PingAction::ID_PATTERN_SOURCE . '/', str_replace('\z', '$', PingAction::ID_PATTERN));
    }

    public function testTheSdkRefusesExactlyTheServersReservedLabels(): void
    {
        self::assertSame($this->fixture['reserved_labels'], PingActionRules::RESERVED_LABELS);
        foreach (PingActionRules::RESERVED_LABELS as $label) {
            self::assertSame($label, PingActionRules::comparisonForm($label), "{$label} is not in its comparison form");
        }
    }

    public function testEveryLabelVectorGetsTheServersVerdictCleanedTextAndReason(): void
    {
        $vectors = $this->fixture['label_vectors'];
        self::assertIsArray($vectors);
        self::assertGreaterThan(10, count($vectors));
        foreach ($vectors as $vector) {
            self::assertIsArray($vector);
            $verdict = PingActionRules::cleanLabel($vector['label']);
            self::assertSame($vector['accepted'], $verdict['error'] === null, json_encode($vector['label']) ?: '');
            if ($vector['accepted']) {
                self::assertSame($vector['cleaned'], $verdict['label'], json_encode($vector['label']) ?: '');
            } else {
                self::assertSame($vector['reason'], $verdict['error'], json_encode($vector['label']) ?: '');
            }
        }
    }

    public function testEveryUrlVectorGetsTheServersVerdictHostAndReason(): void
    {
        $vectors = $this->fixture['url_vectors'];
        self::assertIsArray($vectors);
        self::assertGreaterThan(10, count($vectors));
        foreach ($vectors as $vector) {
            self::assertIsArray($vector);
            $verdict = PingActionRules::urlVerdict($vector['url'], $vector['type']);
            self::assertSame($vector['accepted'], $verdict['error'] === null, $vector['url']);
            if ($vector['accepted']) {
                self::assertSame($vector['host'], $verdict['host'], $vector['url']);
            } else {
                self::assertSame($vector['reason'], $verdict['error'], $vector['url']);
            }
        }
    }

    public function testThePingActionBuildersAgreeWithTheLabelAndUrlVectors(): void
    {
        foreach ($this->fixture['label_vectors'] as $vector) {
            $built = null;
            try {
                $built = PingAction::openUrl($vector['label'], 'https://example.com/');
            } catch (PingRoomException $e) {
                self::assertFalse($vector['accepted'], json_encode($vector['label']) ?: '');
                self::assertSame('invalid_data', $e->errorCode);
            }
            if ($built !== null) {
                self::assertTrue($vector['accepted']);
                self::assertSame($vector['cleaned'], $built['label']);
            }
        }
        foreach ($this->fixture['url_vectors'] as $vector) {
            try {
                $built = $vector['type'] === 'webhook'
                    ? PingAction::webhook('Run', $vector['url'])
                    : PingAction::openUrl('Run', $vector['url']);
                self::assertTrue($vector['accepted'], $vector['url']);
                self::assertSame($vector['url'], $built['url']);
            } catch (PingRoomException $e) {
                self::assertFalse($vector['accepted'], $vector['url']);
                self::assertStringContainsString($vector['reason'], $e->getMessage());
            }
        }
    }

    /**
     * The signing vector: HKDF over the server's JWT secret with the published `info`
     * gives the per-deployment key; the HMAC of the notification id under it is the
     * `action_signing_secret` the Ping's deliveries are signed with. A receiver never
     * derives it (it is handed `action_signing_secret` when it sends the Ping), but the
     * vector pins that the secret in the fixture is what verifies a delivery here.
     */
    public function testTheSigningVectorDerivesTheSecretAndVerifiesAButtonDelivery(): void
    {
        $signing = $this->fixture['signing'];
        self::assertIsArray($signing);
        self::assertSame('pingroom/ping-actions/signing/v1', $signing['info']);
        self::assertIsArray($signing['vectors']);
        self::assertNotEmpty($signing['vectors']);

        foreach ($signing['vectors'] as $vector) {
            self::assertIsArray($vector);
            $key = hash_hkdf('sha256', $vector['jwt_secret'], 32, $signing['info'], '');
            self::assertSame($vector['key_hex'], bin2hex($key));
            $secret = hash_hmac('sha256', $vector['notification_id'], $key);
            self::assertSame($vector['secret'], $secret);

            // The bytes and headers PingRoom's Worker sends a webhook button's URL.
            $payload = json_encode([
                'event' => 'notification.action',
                'notification_id' => $vector['notification_id'],
                'action_id' => 'clean',
                'room' => ['code' => 'AB12CD', 'name' => 'Ops'],
                'actor' => ['id' => '0123456789abcdef0123456789abcdef', 'display_name' => 'Sam'],
                'correlation_id' => null,
                'body' => ['host' => 'db-2'],
                'timestamp' => '2026-11-11T10:00:00+00:00',
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $timestamp = '1794146700';
            $deliveryId = '6c5b1a2e-0000-4000-8000-00000000000a';
            $signatureV2 = hash_hmac('sha256', "v2\n{$timestamp}\n{$deliveryId}\n{$payload}", $vector['secret']);
            $now = 1794146700 + 30;

            self::assertTrue(Webhook::verifySignature($payload, $vector['secret'], signatureV2: $signatureV2, timestamp: $timestamp, deliveryId: $deliveryId, requireV2: true, now: $now));
            self::assertFalse(Webhook::verifySignature($payload, str_repeat('ff', 32), signatureV2: $signatureV2, timestamp: $timestamp, deliveryId: $deliveryId, requireV2: true, now: $now));
            self::assertFalse(Webhook::verifySignature(str_replace('db-2', 'db-3', $payload), $vector['secret'], signatureV2: $signatureV2, timestamp: $timestamp, deliveryId: $deliveryId, requireV2: true, now: $now));
            self::assertFalse(Webhook::verifySignature($payload, $vector['secret'], signatureV2: $signatureV2, timestamp: $timestamp, deliveryId: $deliveryId, requireV2: true, now: $now + 3600));
        }
    }

    public function testTheButtonErrorCodesAreDistinctSnakeCaseCodes(): void
    {
        self::assertSame(
            ['ping_buttons_agents_only', 'ping_buttons_unsupported_room', 'ping_buttons_with_ack', 'ping_buttons_require_pro'],
            ErrorCodes::PING_ACTION_ERROR_CODES,
        );
    }
}
