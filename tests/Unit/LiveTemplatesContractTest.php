<?php

declare(strict_types=1);

namespace PingRoom\Tests\Unit;

use PingRoom\LiveStatus;
use PingRoom\Tests\Support\Fixture;
use PingRoom\Tests\Support\TestCase;

/**
 * Pins the SDK's live-status template list and limits to
 * tests/contracts/live-templates.json. A template the server learned that the SDK
 * did not would have no builder.
 */
final class LiveTemplatesContractTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $fixture;

    protected function setUp(): void
    {
        $this->fixture = Fixture::load('live-templates');
    }

    public function testTheTemplateListIsTheFixtureListNamesAndOrder(): void
    {
        self::assertSame($this->fixture['templates'], LiveStatus::TEMPLATES);
    }

    public function testEveryFixtureTemplateHasABuilderThatStampsIt(): void
    {
        $templates = $this->fixture['templates'];
        self::assertIsArray($templates);
        foreach ($templates as $template) {
            self::assertIsString($template);
            self::assertTrue(is_callable([LiveStatus::class, $template]), "LiveStatus::{$template}() is missing");
            $body = call_user_func([LiveStatus::class, $template], 'c', ['state' => 'running']);
            self::assertIsArray($body);
            self::assertSame($template, $body['live_status']['template']);
        }
    }

    public function testTheTemplateAnUnknownOneFoldsBackOntoIsListed(): void
    {
        self::assertContains($this->fixture['fallback_template'], LiveStatus::TEMPLATES);
    }

    public function testEveryFieldATemplateOwnsSurvivesABuilderRoundTripAndHasLimits(): void
    {
        $owned = $this->fixture['template_fields'];
        $fields = $this->fixture['fields'];
        self::assertIsArray($owned);
        self::assertIsArray($fields);
        foreach ($owned as $template => $names) {
            self::assertIsArray($names);
            foreach ($names as $name) {
                self::assertArrayHasKey($name, $fields, "the fixture owns {$template}.{$name} but gives it no limits");
                $body = call_user_func([LiveStatus::class, (string) $template], 'c', ['state' => 'running', $name => 'x']);
                self::assertIsArray($body);
                self::assertSame('x', $body['live_status'][$name], "LiveStatus::{$template}() dropped {$name}");
            }
        }
    }

    public function testTheLimitsTheSdkExportsAreTheFixtureLimits(): void
    {
        $fields = $this->fixture['fields'];
        self::assertIsArray($fields);
        self::assertSame([
            'valueLength' => $fields['value']['max'],
            'valueLabelLength' => $fields['value_label']['max'],
            'deltaLength' => $fields['delta']['max'],
            'statsMin' => $fields['stats']['min'],
            'statsMax' => $fields['stats']['max'],
            'statLabelLength' => $fields['stats']['item']['label']['max'],
            'statValueLength' => $fields['stats']['item']['value']['max'],
            'segmentsMin' => $fields['segments']['min'],
            'segmentsMax' => $fields['segments']['max'],
        ], LiveStatus::LIMITS);
    }

    public function testTheImmutableFieldsAreTheOnesTheSdkDocuments(): void
    {
        // steps (steps_immutable), segments (segments_immutable), started_at
        // (started_at_immutable). The SDK documents them on LiveStatus, so a new
        // immutable field means a doc change there first.
        self::assertSame(['steps' => ['steps'], 'progress' => ['segments'], 'timer' => ['started_at']], $this->fixture['immutable_fields']);
    }
}
