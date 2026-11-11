<?php

declare(strict_types=1);

namespace PingRoom\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PingRoom\ErrorCodes;
use PingRoom\Exception\NetworkException;
use PingRoom\Exception\PingRoomException;
use PingRoom\Exception\TimeoutException;

final class ErrorCodesTest extends TestCase
{
    public function testTheHandoffCodesMatchTheCodesTheHandoffEndpointsEmit(): void
    {
        self::assertSame([
            'feature_temporarily_unavailable', 'handoff_room_unsupported', 'invalid_target', 'no_room_configured', 'target_not_permitted',
            'recipient_not_ready', 'idempotency_conflict', 'invalid_idempotency_key', 'capability_check_unavailable', 'insufficient_scope',
            'free_limit_reached',
        ], ErrorCodes::HANDOFF_ERROR_CODES);
        // These four had zero emitters on the server and are not codes.
        foreach (['target_not_found', 'target_unavailable', 'pings_closed', 'not_room_member'] as $dead) {
            self::assertNotContains($dead, ErrorCodes::HANDOFF_ERROR_CODES);
        }
        // `room_not_granted` comes from the room gate, which POST /handoffs is not behind.
        self::assertNotContains('room_not_granted', ErrorCodes::HANDOFF_ERROR_CODES);
    }

    public function testTheRoomScopedInboxQuickActionAndOtherSurfaceCodes(): void
    {
        self::assertSame(['room_not_granted'], ErrorCodes::ROOM_SCOPED_ERROR_CODES);
        self::assertSame([
            'activation_evidence_unavailable', 'no_room_configured', 'activation_room_muted', 'activation_delivery_unavailable', 'feature_temporarily_unavailable',
        ], ErrorCodes::AGENT_INBOX_ERROR_CODES);
        self::assertSame([
            'quick_action_input_required', 'quick_action_input_type', 'action_not_configured', 'pro_required', 'quick_action_layout_changed',
            'quick_action_page_in_use', 'location_trigger_owner_only', 'location_trigger_inactive',
        ], ErrorCodes::QUICK_ACTION_ERROR_CODES);
        self::assertSame(['rate_limited', 'feature_disabled'], ErrorCodes::COMMON_ERROR_CODES);
        self::assertSame(['payload_too_large', 'attachment_too_large'], ErrorCodes::REQUEST_LIMIT_ERROR_CODES);
    }

    public function testNoListHasADuplicateOrANonSnakeCaseCode(): void
    {
        $lists = [
            ErrorCodes::HANDOFF_ERROR_CODES, ErrorCodes::ROOM_SCOPED_ERROR_CODES, ErrorCodes::AGENT_INBOX_ERROR_CODES,
            ErrorCodes::QUICK_ACTION_ERROR_CODES, ErrorCodes::LIVE_CLAIM_ERROR_CODES, ErrorCodes::REPLACE_ERROR_CODES,
            ErrorCodes::WITHDRAW_ERROR_CODES, ErrorCodes::METRIC_ERROR_CODES, ErrorCodes::PING_ACTION_ERROR_CODES,
            ErrorCodes::REQUEST_LIMIT_ERROR_CODES, ErrorCodes::COMMON_ERROR_CODES, ErrorCodes::CLIENT_ERROR_CODES,
        ];
        foreach ($lists as $codes) {
            self::assertSame(count($codes), count(array_unique($codes)));
            foreach ($codes as $code) {
                self::assertMatchesRegularExpression('/^[a-z][a-z0-9_]*\z/', $code);
            }
        }
        // None of the replace or withdraw codes is a code any route can answer.
        foreach ([...ErrorCodes::REPLACE_ERROR_CODES, ...ErrorCodes::WITHDRAW_ERROR_CODES] as $code) {
            self::assertNotContains($code, ErrorCodes::COMMON_ERROR_CODES);
        }
    }

    public function testAllIsTheUnionOfTheServerCodeLists(): void
    {
        $all = ErrorCodes::all();
        self::assertSame(count($all), count(array_unique($all)));
        foreach (['room_not_granted', 'rate_limited', 'claim_conflict', 'ping_withdrawn', 'metric_not_found', 'ping_buttons_with_ack', 'payload_too_large'] as $code) {
            self::assertContains($code, $all);
        }
        self::assertNotContains('network_error', $all, 'client codes are a separate list');
    }

    public function testEveryClientCodeTheSdkRaisesIsListed(): void
    {
        // The codes the SDK raises with status 0, collected from the source.
        $found = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(__DIR__ . '/../../src', \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php' || $file->getFilename() === 'ErrorCodes.php') {
                continue;
            }
            $source = (string) file_get_contents($file->getPathname());
            preg_match_all("/'(no_token|no_http_client|invalid_request|invalid_data|invalid_url|insecure_url|invalid_response|redirect_refused|network_error|timeout|unavailable|cancelled|attachment_too_large)'/", $source, $matches);
            $found = [...$found, ...$matches[1]];
        }
        self::assertSame([], array_diff(array_unique($found), ErrorCodes::CLIENT_ERROR_CODES));
        self::assertGreaterThanOrEqual(10, count(array_unique($found)));
    }

    public function testTheSubclassesCarryTheirCodes(): void
    {
        $timeout = new TimeoutException();
        self::assertSame(['timeout', 0, 'Request timed out'], [$timeout->errorCode, $timeout->status, $timeout->getMessage()]);
        $network = new NetworkException('down');
        self::assertSame(['network_error', 0], [$network->errorCode, $network->status]);
        self::assertInstanceOf(PingRoomException::class, $timeout);
        self::assertInstanceOf(PingRoomException::class, $network);
        self::assertInstanceOf(\RuntimeException::class, $timeout);
    }

    public function testFromResponseKeepsTheDecodedBodyAndTheStatusAsTheExceptionCode(): void
    {
        $e = PingRoomException::fromResponse(409, ['code' => 'claim_conflict', 'message' => 'Sam is already on it.', 'claim' => ['status' => 'claimed']]);
        self::assertSame(409, $e->getCode());
        self::assertSame(409, $e->status);
        self::assertSame(['code' => 'claim_conflict', 'message' => 'Sam is already on it.', 'claim' => ['status' => 'claimed']], $e->body);

        // A list body, a string body and a null body carry no code.
        foreach ([[1, 2], 'text', null] as $body) {
            $e = PingRoomException::fromResponse(500, $body);
            self::assertNull($e->errorCode);
            self::assertSame('HTTP 500', $e->getMessage());
            self::assertSame($body, $e->body);
        }
        // A retry_after that is not a number is ignored.
        self::assertNull(PingRoomException::fromResponse(429, ['retry_after' => '5'])->retryAfter);
        self::assertSame(2.5, PingRoomException::fromResponse(429, ['retry_after' => 2.5])->retryAfter);
    }
}
