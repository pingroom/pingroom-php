<?php

declare(strict_types=1);

namespace PingRoom\Tests\Unit;

use PingRoom\Exception\PingRoomException;
use PingRoom\LinkPing;
use PingRoom\LocationPing;
use PingRoom\PingAction;
use PingRoom\Tests\Support\TestCase;

final class PingHelpersTest extends TestCase
{
    public function testLinkPingBuildsTheDataFragment(): void
    {
        self::assertSame(
            ['url' => 'https://ci.example.com/b/512', 'button_label' => 'Open build'],
            LinkPing::data('https://ci.example.com/b/512', 'Open build'),
        );
        self::assertSame(['url' => 'https://example.com'], LinkPing::data('https://example.com'));
        self::assertSame(['url' => 'HTTP://example.com'], LinkPing::data('HTTP://example.com'));
    }

    public function testLinkPingRejectsWhatTheServerWouldRefuse(): void
    {
        $cases = [
            ['/relative', null, 'not a valid URL'],
            ['example.com', null, 'not a valid URL'],
            ['https://', null, 'not a valid URL'],
            ['ftp://example.com/x', null, 'absolute http(s)'],
            ['javascript:alert(1)', null, 'absolute http(s)'],
            ['mailto:a@b.c', null, 'absolute http(s)'],
            ['https://example.com/' . str_repeat('x', 2048), null, 'at most 2048'],
            ['https://example.com', str_repeat('x', 27), 'at most 26'],
        ];
        foreach ($cases as [$url, $label, $message]) {
            $e = $this->thrown(fn() => LinkPing::data($url, $label));
            self::assertSame('invalid_data', $e->errorCode);
            self::assertStringContainsString($message, $e->getMessage());
        }
    }

    public function testLinkPingCountsCharactersLikeTheServerSoAnEmojiLabelIsNotDoubleCounted(): void
    {
        // 14 emoji are 28 UTF-16 units; the server's mb_strlen counts 14.
        $label = str_repeat('😀', 14);
        self::assertSame(['url' => 'https://example.com', 'button_label' => $label], LinkPing::data('https://example.com', $label));
        self::assertSame('invalid_data', $this->thrown(fn() => LinkPing::data('https://example.com', str_repeat('😀', 27)))->errorCode);

        $url = 'https://example.com/' . str_repeat('é', 2028);
        self::assertSame(2048, mb_strlen($url));
        self::assertSame($url, LinkPing::data($url)['url']);
    }

    public function testLinkPingSpreadsIntoABroadcastBody(): void
    {
        [$pr, $http] = $this->clientAnswering(['id' => 'n1'], 201);
        $pr->broadcast('ab12cd', 'Build 512 ready', data: ['commit' => 'abc123', ...LinkPing::data('https://ci.example.com/b/512', 'Open build')]);
        self::assertSame(
            '{"message":"Build 512 ready","data":{"commit":"abc123","url":"https://ci.example.com/b/512","button_label":"Open build"}}',
            $http->rawBody(),
        );
    }

    public function testLocationPingBuildsTheReservedLocationFragment(): void
    {
        self::assertSame(
            ['location' => ['latitude' => 25.2048, 'longitude' => 55.2708, 'label' => 'Dubai Mall', 'address' => 'Downtown Dubai']],
            LocationPing::data(25.2048, 55.2708, 'Dubai Mall', 'Downtown Dubai'),
        );
    }

    public function testLocationPingAcceptsInclusiveBoundariesAndOmitsAbsentStrings(): void
    {
        self::assertSame(['location' => ['latitude' => -90, 'longitude' => 180]], LocationPing::data(-90, 180));
        self::assertSame(['location' => ['latitude' => 90, 'longitude' => -180]], LocationPing::data(90, -180));
    }

    public function testLocationPingRequiresFiniteNumbersInRange(): void
    {
        foreach ([NAN, INF, -INF] as $latitude) {
            self::assertStringContainsString('latitude must be a finite JSON number', $this->thrown(fn() => LocationPing::data($latitude, 55.3))->getMessage());
        }
        self::assertMatchesRegularExpression('/latitude.*-90.*90/', $this->thrown(fn() => LocationPing::data(90.000001, 0))->getMessage());
        self::assertMatchesRegularExpression('/longitude.*-180.*180/', $this->thrown(fn() => LocationPing::data(0, -180.000001))->getMessage());
    }

    public function testLocationPingAppliesLabelAndAddressCapsToUnicodeCharacters(): void
    {
        self::assertSame('invalid_data', $this->thrown(fn() => LocationPing::data(0, 0, str_repeat('📍', 101)))->errorCode);
        self::assertStringContainsString('address must be at most 255 characters', $this->thrown(fn() => LocationPing::data(0, 0, null, str_repeat('📍', 256)))->getMessage());
        $ok = LocationPing::data(0, 0, str_repeat('📍', 100), str_repeat('📍', 255));
        self::assertSame(str_repeat('📍', 100), $ok['location']['label']);
    }

    public function testLocationPingEncodesAsAMapReadyPointInABroadcast(): void
    {
        [$pr, $http] = $this->clientAnswering(['id' => 'n1'], 201);
        $pr->broadcast('ab12cd', 'Meet me here', data: ['event' => 'lunch', ...LocationPing::data(25.2048, 55.2708, 'Dubai Mall')]);
        self::assertSame('{"message":"Meet me here","data":{"event":"lunch","location":{"latitude":25.2048,"longitude":55.2708,"label":"Dubai Mall"}}}', $http->rawBody());
    }

    public function testExtractLocationPingReturnsAValidatedCopyOrNull(): void
    {
        $source = ['location' => ['latitude' => 51.5072, 'longitude' => -0.1276, 'label' => 'London'], 'sibling' => true];
        self::assertSame(['latitude' => 51.5072, 'longitude' => -0.1276, 'label' => 'London'], LocationPing::extract($source));
        self::assertNull(LocationPing::extract([]));
        self::assertNull(LocationPing::extract(['location' => ['latitude' => '51.5', 'longitude' => -0.1]]));
        self::assertNull(LocationPing::extract(['location' => ['latitude' => 95, 'longitude' => 0]]));
        self::assertNull(LocationPing::extract(['location' => ['latitude' => 1, 'longitude' => 0, 'label' => null]]));
        self::assertNull(LocationPing::extract(null));
        self::assertNull(LocationPing::extract('x'));
    }

    public function testPingActionBuildsTheWireObjectsDataActionsTakes(): void
    {
        self::assertSame(
            ['label' => 'Open dashboard', 'type' => 'open_url', 'url' => 'https://grafana.example.com/d/disk'],
            PingAction::openUrl('Open dashboard', 'https://grafana.example.com/d/disk'),
        );
        self::assertSame(
            ['label' => 'Clean up now', 'type' => 'webhook', 'url' => 'https://ops.example.com/cleanup', 'id' => 'clean', 'style' => 'danger', 'body' => ['host' => 'db-2']],
            PingAction::webhook('Clean up now', 'https://ops.example.com/cleanup', id: 'clean', style: 'danger', body: ['host' => 'db-2']),
        );
        // The label every recipient sees: NFC, spaces collapsed, trimmed.
        self::assertSame('Open it', PingAction::openUrl('  Open  it ', 'https://example.com/')['label']);
    }

    public function testAnEmptyWebhookBodyStaysAnObject(): void
    {
        [$pr, $http] = $this->clientAnswering(['id' => 'n1'], 201);
        $pr->broadcast('AB12', 'x', data: ['actions' => [PingAction::webhook('Run', 'https://ops.example.com/run', body: [])]]);
        self::assertStringContainsString('"body":{}', $http->rawBody());
    }

    public function testPingActionRefusesWhatTheServerWouldRefuseBeforeAnyRequest(): void
    {
        $cases = [
            [fn() => PingAction::openUrl('paypal.com', 'https://evil.example.com/'), '/web address, domain name or IP/'],
            [fn() => PingAction::openUrl('👍 Pong Back', 'https://example.com/'), '/PingRoom\'s own buttons/'],
            [fn() => PingAction::openUrl("Pay \u{202E}lapyap", 'https://example.com/'), '/invisible formatting/'],
            [fn() => PingAction::openUrl('Go', 'http://example.com/'), '/https:\/\//'],
            [fn() => PingAction::openUrl('Go', 'https://paypal.com@evil.example.com/'), '/plain hostname/'],
            [fn() => PingAction::openUrl('Go', "https://p\u{0430}ypal.com/"), '/punycode/'],
            [fn() => PingAction::openUrl('Go', 'https://127.0.0.1/'), '/public hostname/'],
            [fn() => PingAction::webhook('Run', 'https://ops.example.com:8443/'), '/cannot name a port/'],
            [fn() => PingAction::webhook('Run', 'https://db.internal/'), '/public hostname/'],
            [fn() => PingAction::webhook('Run', 'https://ops.example.com/', body: ['x']), '/JSON object/'],
            [fn() => PingAction::webhook('Run', 'https://ops.example.com/', body: ['x' => str_repeat('é', 200)]), '/1024 bytes/'],
            [fn() => PingAction::openUrl('Go', 'https://example.com/', id: 'Bad Id'), '/button id/'],
            [fn() => PingAction::openUrl('Go', 'https://example.com/', style: 'red'), '/style/'],
        ];
        foreach ($cases as [$build, $pattern]) {
            $e = $this->thrown($build);
            self::assertSame('invalid_data', $e->errorCode);
            self::assertMatchesRegularExpression($pattern, $e->getMessage());
        }
    }

    public function testPingActionConstantsAreTheDocumentedLimits(): void
    {
        self::assertSame(3, PingAction::MAX_ACTIONS);
        self::assertSame(26, PingAction::MAX_LABEL_LENGTH);
        self::assertSame(['open_url', 'webhook'], PingAction::TYPES);
        self::assertSame(['default', 'primary', 'danger'], PingAction::STYLES);
        self::assertInstanceOf(PingRoomException::class, $this->thrown(fn() => PingAction::openUrl('', 'https://example.com/')));
    }
}
