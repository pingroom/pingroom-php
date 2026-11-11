<?php

declare(strict_types=1);

namespace PingRoom\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PingRoom\Internal\Data;
use PingRoom\Internal\Json;
use PingRoom\Internal\Path;
use PingRoom\Internal\Poller;
use PingRoom\Internal\Str;
use PingRoom\Internal\Url;

final class InternalsTest extends TestCase
{
    public function testTheQueryIsWrittenLikeUrlSearchParams(): void
    {
        self::assertSame('a=1&b=x+y&c=%C3%A9&d=%2F%3F%26%3D&e=*-._&f=%7E', Path::query(['a' => 1, 'b' => 'x y', 'c' => 'é', 'd' => '/?&=', 'e' => '*-._', 'f' => '~']));
        self::assertSame('a=true&b=false&c=0', Path::query(['a' => true, 'b' => false, 'c' => 0]));
        self::assertSame('x=1', Path::query(['skipped' => null, 'x' => 1]));
        self::assertSame('', Path::query(['only' => null]));
        self::assertSame('k%C3%A9y=v', Path::query(['kéy' => 'v']));
    }

    public function testASegmentIsEncodedAsEncodeUriComponentWritesIt(): void
    {
        self::assertSame("a-b_c.d!e~f*g'h(i)j", Path::segment("a-b_c.d!e~f*g'h(i)j"));
        self::assertSame('a%20b%2Fc%3Fd%23e%25f%2Bg', Path::segment('a b/c?d#e%f+g'));
        self::assertSame('%F0%9F%98%80', Path::segment('😀'));
    }

    public function testJsonEncodeMatchesJsonStringifyForTheCasesThatDiffer(): void
    {
        self::assertSame('{"a":1,"b":0.5,"c":"/","d":"é","e":"\u0001","f":"<>&\'"}', Json::encode(['a' => 1.0, 'b' => 0.5, 'c' => '/', 'd' => 'é', 'e' => "\x01", 'f' => "<>&'"]));
        self::assertSame('{}', Json::encode(new \stdClass()));
        self::assertSame('[]', Json::encode([]));
        self::assertSame('{}', Json::encode(Json::asObject([])));
        self::assertSame('null', Json::encode(null));
        $this->expectException(\JsonException::class);
        Json::encode("\xB1");
    }

    public function testFloatsAreTheShortestRoundTripWhateverSerializePrecisionTheHostSets(): void
    {
        $previous = ini_get('serialize_precision');
        try {
            ini_set('serialize_precision', '17');
            self::assertSame('{"v":0.1}', Json::encode(['v' => 0.1]));
            self::assertSame('17', ini_get('serialize_precision'), 'the host setting is restored');
            ini_set('serialize_precision', '-1');
        } finally {
            ini_set('serialize_precision', (string) $previous);
        }
    }

    public function testServerBytesTrimsStringsAndTurnsEmptyOnesIntoNullLikeTheServersMiddleware(): void
    {
        self::assertSame(2, Json::serverBytes([]));
        // {"note":"   spaced   ","n":1} reaches the 8 KB rule as {"note":"spaced","n":1}.
        self::assertSame(strlen('{"note":"spaced","n":1}'), Json::serverBytes(['note' => '   spaced   ', 'n' => 1]));
        // "" is null: 4 bytes, not 2.
        self::assertSame(strlen('{"e":null}'), Json::serverBytes(['e' => '']));
        self::assertSame(strlen('{"e":null}'), Json::serverBytes(['e' => " \t\n"]));
        // Non-ASCII is escaped to \uXXXX and a slash to \/.
        $slash = chr(92);
        self::assertSame(strlen('{"a":"' . $slash . 'u00e9' . $slash . '/"}'), Json::serverBytes(['a' => 'é/']));
        self::assertSame(strlen('{"a":"' . $slash . 'ud83d' . $slash . 'ude00"}'), Json::serverBytes(['a' => '😀']));
        // Laravel's trim also strips invisible characters.
        self::assertSame(strlen('{"a":"x"}'), Json::serverBytes(['a' => "\u{200B}x\u{FEFF}"]));
    }

    public function testDataCheckReturnsTheDataAsTheServerDecodesIt(): void
    {
        self::assertNull(Data::check(null));
        self::assertSame([], Data::check([]));
        self::assertSame([], Data::check(new \stdClass()));
        self::assertSame(['a' => ['b' => []]], Data::check(['a' => ['b' => new \stdClass()]]));
        self::assertSame([1 => 'a', 2 => 'b'], Data::check([1 => 'a', 2 => 'b']), 'non-sequential numeric keys are an object');
    }

    public function testStringHelpers(): void
    {
        self::assertSame(3, Str::length('😀é!'));
        self::assertTrue(Str::isBlank(" \t\u{00A0}\u{FEFF}\u{3000}"));
        self::assertFalse(Str::isBlank("\u{0085}"), 'U+0085 is not whitespace to JavaScript');
        self::assertSame('x', Str::jsTrim("\u{FEFF} x \u{2028}"));
        self::assertSame('abc-é', Str::lowerAscii('ABC-é'));
        self::assertSame('É', Str::lowerAscii('É'), 'a non-ASCII letter never folds');
        self::assertSame('x', Str::trimTag("\u{200B} x\u{200B}"));
    }

    public function testUrlParsingAndRedaction(): void
    {
        $url = Url::assertSecure('HTTPS://API.PingRoom.io:443/a/b/?q=1#f', false);
        self::assertSame('https://api.pingroom.io', $url['origin']);
        self::assertSame('https://api.pingroom.io/a/b', Url::base($url));
        self::assertTrue(Url::isLoopbackHost('[::1]'));
        self::assertFalse(Url::isLoopbackHost('127.0.0.2'));

        $hook = Url::assertSecure('https://example.invalid/webhooks/secret-SYNTHETIC?x=1', false);
        self::assertSame(
            'failed for https://example.invalid/[redacted] and /[redacted]',
            Url::redactInMessage('failed for https://example.invalid/webhooks/secret-SYNTHETIC?x=1 and /webhooks/secret-SYNTHETIC', $hook, 'https://example.invalid/webhooks/secret-SYNTHETIC?x=1'),
        );
    }

    public function testTimeOfParsesIsoStringsAndNothingElse(): void
    {
        $expected = gmmktime(13, 25, 0, 11, 7, 2026) * 1000;
        self::assertSame($expected, Poller::timeOf('2026-11-07T13:25:00+00:00'));
        self::assertSame($expected + 123, Poller::timeOf('2026-11-07T13:25:00.123Z'));
        self::assertSame($expected, Poller::timeOf('2026-11-07T17:25:00+04:00'));
        foreach ([null, 5, '', 'tomorrow', 'not a date', '2026-13-45T99:00:00Z'] as $value) {
            self::assertNull(Poller::timeOf($value), var_export($value, true));
        }
    }
}
