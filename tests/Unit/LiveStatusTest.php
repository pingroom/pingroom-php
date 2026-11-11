<?php

declare(strict_types=1);

namespace PingRoom\Tests\Unit;

use PingRoom\LiveStatus;
use PingRoom\Tests\Support\TestCase;

final class LiveStatusTest extends TestCase
{
    public function testBuildersStampTheirTemplateAndPassContentThrough(): void
    {
        self::assertSame(
            ['correlation_id' => 'deploy-1', 'live_status' => ['template' => 'progress', 'state' => 'running', 'progress' => 0.4, 'message' => 'Building']],
            LiveStatus::progress('deploy-1', ['state' => 'running', 'progress' => 0.4, 'message' => 'Building']),
        );
    }

    public function testStepsCarriesItsLabels(): void
    {
        $ping = LiveStatus::steps('rel-1', ['state' => 'running', 'steps' => ['Build', 'Ship'], 'current_step' => 0]);
        self::assertSame('steps', $ping['live_status']['template']);
        self::assertSame(['Build', 'Ship'], $ping['live_status']['steps']);
    }

    public function testAlertSetsTheCategoryNotATemplate(): void
    {
        // `alert` has no template equivalent and is the only way to start a stream
        // time-sensitive without also demanding an acknowledgement.
        $ping = LiveStatus::alert('incident-1', ['state' => 'running', 'message' => '5xx climbing']);
        self::assertSame('alert', $ping['live_status']['category']);
        self::assertArrayNotHasKey('template', $ping['live_status']);
    }

    public function testTerminalBuildersStaySparse(): void
    {
        // The server merges a terminal leg over the stored stream, so a bare
        // {state: done} keeps the scoreboard and metrics on the final frame.
        self::assertSame(['correlation_id' => 'deploy-1', 'live_status' => ['state' => 'done', 'message' => 'Live']], LiveStatus::done('deploy-1', 'Live'));
        self::assertSame(['state' => 'failed'], LiveStatus::failed('deploy-1')['live_status']);
        self::assertSame(['state' => 'done'], LiveStatus::done('deploy-1', '')['live_status']);
        self::assertSame(['state' => 'done', 'message' => '0'], LiveStatus::done('deploy-1', '0')['live_status']);
    }

    public function testBuildersMergeTheRestOfThePingBody(): void
    {
        $ping = LiveStatus::status('c1', ['state' => 'running'], ['title' => 'Deploy', 'action' => 2, 'requires_ack' => true]);
        self::assertSame('Deploy', $ping['title']);
        self::assertSame(2, $ping['action']);
        self::assertTrue($ping['requires_ack']);
    }

    public function testLiveStatusCoversEveryTemplateTheServerValidates(): void
    {
        foreach (LiveStatus::TEMPLATES as $template) {
            self::assertTrue(is_callable([LiveStatus::class, $template]), "missing builder: {$template}");
            $ping = call_user_func([LiveStatus::class, $template], 'c', ['state' => 'running']);
            self::assertSame($template, $ping['live_status']['template']);
        }
        self::assertCount(11, LiveStatus::TEMPLATES);
    }

    public function testClaimStampsTheClaimTemplateAndCarriesTheRequestInTitle(): void
    {
        self::assertSame(
            ['correlation_id' => 'milk-1', 'live_status' => ['template' => 'claim', 'state' => 'running', 'deadline_at' => 1794148500], 'title' => 'Buy milk'],
            LiveStatus::claim('milk-1', ['state' => 'running', 'deadline_at' => 1794148500], ['title' => 'Buy milk']),
        );
    }

    public function testAWebhookEndsItsOwnClaimRequestWithTheOrdinaryTerminalBuilders(): void
    {
        self::assertSame(['state' => 'done'], LiveStatus::done('milk-1')['live_status']);
        self::assertSame(['state' => 'failed'], LiveStatus::failed('milk-1')['live_status']);
    }

    public function testTimerCountsUpFromStartedAtAndPassesItThrough(): void
    {
        self::assertSame(
            ['correlation_id' => 'build-9', 'live_status' => ['template' => 'timer', 'state' => 'running', 'started_at' => 1794146700, 'message' => 'Compiling'], 'title' => 'Build'],
            LiveStatus::timer('build-9', ['state' => 'running', 'started_at' => 1794146700, 'message' => 'Compiling'], ['title' => 'Build']),
        );
        // started_at is optional: the server defaults it to the first ping's time.
        self::assertArrayNotHasKey('started_at', LiveStatus::timer('build-9', ['state' => 'running'])['live_status']);
    }

    public function testValueCarriesTheValueItsLabelDeltaAndTrend(): void
    {
        self::assertSame(
            ['template' => 'value', 'state' => 'running', 'value' => '$12.4k', 'value_label' => 'MRR', 'delta' => '+3.2%', 'trend' => 'up'],
            LiveStatus::value('mrr-1', ['state' => 'running', 'value' => '$12.4k', 'value_label' => 'MRR', 'delta' => '+3.2%', 'trend' => 'up'])['live_status'],
        );
    }

    public function testStatsCarriesFourToEightPairsUntouched(): void
    {
        $stats = [['label' => 'RPS', 'value' => '1.2k'], ['label' => 'p99', 'value' => '180ms'], ['label' => '5xx', 'value' => '0.1%'], ['label' => 'CPU', 'value' => '45%']];
        $ping = LiveStatus::stats('health-2', ['state' => 'running', 'stats' => $stats]);
        self::assertSame('stats', $ping['live_status']['template']);
        self::assertSame($stats, $ping['live_status']['stats']);
    }

    public function testBuildersDoNotEnforceTheServerLimitsAnOverlongValueGoesOutAndTheServerAnswers422(): void
    {
        $value = str_repeat('x', 25);
        self::assertSame($value, LiveStatus::value('v', ['state' => 'running', 'value' => $value])['live_status']['value']);
        self::assertSame([], LiveStatus::stats('s', ['state' => 'running', 'stats' => []])['live_status']['stats']);
    }

    public function testProgressDrawsInSegmentsWhenAskedTo(): void
    {
        self::assertSame(
            ['template' => 'progress', 'state' => 'running', 'segments' => 5, 'current_segment' => 2],
            LiveStatus::progress('rel-2', ['state' => 'running', 'segments' => 5, 'current_segment' => 2])['live_status'],
        );
    }

    public function testEveryOtherTemplateCarriesItsOwnFieldsToTheWire(): void
    {
        $matchup = LiveStatus::matchup('m1', ['state' => 'running', 'left' => ['label' => 'ARS', 'value' => '2'], 'right' => ['label' => 'CHE', 'value' => '1'], 'center' => "74'"]);
        self::assertSame('{"correlation_id":"m1","live_status":{"template":"matchup","state":"running","left":{"label":"ARS","value":"2"},"right":{"label":"CHE","value":"1"},"center":"74\'"}}', json_encode($matchup, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $question = LiveStatus::question('q1', ['state' => 'running', 'prompt' => 'Ship?', 'options' => [['value' => 'y', 'label' => 'Yes'], ['value' => 'n', 'label' => 'No']]]);
        self::assertSame('Ship?', $question['live_status']['prompt']);

        $countdown = LiveStatus::countdown('c1', ['state' => 'running', 'deadline_at' => 1794148500, 'duration_seconds' => 600]);
        self::assertSame(600, $countdown['live_status']['duration_seconds']);

        $metrics = LiveStatus::metrics('x1', ['state' => 'running', 'metrics' => [['label' => 'Errors', 'value' => '3']]]);
        self::assertSame([['label' => 'Errors', 'value' => '3']], $metrics['live_status']['metrics']);
    }
}
