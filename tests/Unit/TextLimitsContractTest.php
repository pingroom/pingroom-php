<?php

declare(strict_types=1);

namespace PingRoom\Tests\Unit;

use PingRoom\Limits;
use PingRoom\Tests\Support\Fixture;
use PingRoom\Tests\Support\TestCase;

/**
 * The text limits the SDK enforces locally, against tests/contracts/text-limits.json.
 * The SDK checks a ping's message and title only; the other fixture fields (room name,
 * handle, preset title, ...) belong to endpoints this SDK does not wrap yet.
 */
final class TextLimitsContractTest extends TestCase
{
    public function testThePingTextLimitsAreTheServersAndCountedInCodePoints(): void
    {
        $fields = Fixture::load('text-limits')['fields'];
        self::assertIsArray($fields);

        self::assertSame('code_points', $fields['ping.message.private']['unit']);
        self::assertSame('code_points', $fields['ping.message.public']['unit']);
        self::assertSame('code_points', $fields['ping.title']['unit']);
        self::assertSame($fields['ping.message.private']['max'], Limits::MAX_PRIVATE_PING_MESSAGE_LENGTH);
        self::assertSame($fields['ping.message.public']['max'], Limits::MAX_PUBLIC_PING_MESSAGE_LENGTH);
        self::assertSame($fields['ping.title']['max'], Limits::MAX_PING_TITLE_LENGTH);
    }

    public function testBroadcastEnforcesThePublicCeilingAndLeavesThePrivateLimitToTheServer(): void
    {
        $fields = Fixture::load('text-limits')['fields'];
        self::assertIsArray($fields);
        $private = $fields['ping.message.private']['max'];
        $public = $fields['ping.message.public']['max'];
        self::assertIsInt($private);
        self::assertIsInt($public);

        [$pr, $http] = $this->clientAnswering(['id' => 'n1'], 201);
        // Over the private limit but within the public one: the invite code does not
        // reveal visibility, so the SDK lets it through and the server decides.
        $pr->broadcast('ab12', str_repeat('m', $private + 1));
        $pr->broadcast('ab12', str_repeat('m', $public));
        self::assertCount(2, $http->requests);
        self::assertSame('invalid_request', $this->thrown(fn() => $pr->broadcast('ab12', str_repeat('m', $public + 1)))->errorCode);
    }
}
