<?php

declare(strict_types=1);

namespace PingRoom\Tests\Unit;

use PingRoom\Api\Metrics;
use PingRoom\ErrorCodes;
use PingRoom\Tests\Support\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class MetricsTest extends TestCase
{
    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private static function stored(array $overrides = []): array
    {
        return [...['key' => 'mrr', 'label' => 'MRR', 'format' => 'currency', 'unit' => 'USD', 'value' => 12400, 'value_updated_at' => '2026-11-11T08:12:30.000000Z'], ...$overrides];
    }

    public function testTheLimitsMirrorTheServer(): void
    {
        self::assertSame(8, Metrics::MAX_METRICS_PER_ROOM);
        self::assertSame(['number', 'currency', 'percent', 'unit', 'text'], Metrics::FORMATS);
        self::assertSame(['metrics_limit_reached', 'metric_not_found', 'metric_exists'], ErrorCodes::METRIC_ERROR_CODES);
    }

    public function testListReturnsTheMetricsAndThePerRoomLimit(): void
    {
        [$pr, $http] = $this->clientAnswering(['metrics' => [self::stored(), self::stored(['key' => 'status', 'format' => 'text', 'unit' => null, 'value' => 'All clear'])], 'limit' => 8]);
        $result = $pr->metrics->list('ab12');
        self::assertSame(8, $result['limit']);
        self::assertSame(['mrr', 'status'], array_column($result['metrics'], 'key'));
        self::assertSame(12400, $result['metrics'][0]['value']);
        self::assertSame(['GET', '/api/agent/rooms/ab12/metrics'], [$http->last()->getMethod(), $http->last()->getUri()->getPath()]);
        self::assertSame('Bearer ' . self::TOKEN, $http->last()->getHeaderLine('Authorization'));
    }

    public function testSetPutsTheLowercasedKeyWithOnlyTheFieldsGiven(): void
    {
        [$pr, $http] = $this->clientWith(fn(RequestInterface $r): ResponseInterface => self::json(201, self::stored(json_decode((string) $r->getBody(), true))));
        $created = $pr->metrics->set('ab12', ' MRR ', 12400, label: 'MRR', format: 'currency', unit: 'usd');
        self::assertSame('mrr', $created['key']);
        self::assertSame(['PUT', '/api/agent/rooms/ab12/metrics/mrr'], [$http->last()->getMethod(), $http->last()->getUri()->getPath()]);
        self::assertSame('{"value":12400,"label":"MRR","format":"currency","unit":"usd"}', $http->rawBody());

        $pr->metrics->set('ab12', 'mrr', 12650);
        self::assertSame('{"value":12650}', $http->rawBody(1));
    }

    public function testAnOmittedUnitKeepsTheStoredOneAndNullClearsIt(): void
    {
        [$pr, $http] = $this->clientAnswering(self::stored());
        $pr->metrics->set('ab12', 'mrr', 1);
        $pr->metrics->set('ab12', 'mrr', 1, unit: null);
        self::assertSame('{"value":1}', $http->rawBody(0));
        self::assertSame('{"value":1,"unit":null}', $http->rawBody(1));
    }

    public function testSetAcceptsEveryValueTheServerAccepts(): void
    {
        [$pr, $http] = $this->clientAnswering(self::stored());
        $cases = [
            [['value' => 0], 0],
            [['value' => -3.5], -3.5],
            [['value' => 1e15], 1000000000000000],
            [['value' => -1e15], -1000000000000000],
            [['value' => 12.0], 12],
            [['value' => '12.50'], '12.50'],
            [['value' => '-0.5', 'format' => 'percent'], '-0.5'],
            [['value' => 'All clear'], 'All clear'],
            [['value' => str_repeat('é', 24), 'format' => 'text'], str_repeat('é', 24)],
            [['value' => '42', 'format' => 'text'], '42'],
            [['value' => 'NaN', 'format' => 'text'], 'NaN'],
            [['value' => 240, 'format' => 'unit', 'unit' => 'abcdefgh'], 240],
        ];
        foreach ($cases as $n => [$input, $expected]) {
            $pr->metrics->set('ab12', 'k', ...$input);
            self::assertSame($expected, $http->jsonBody($n)['value'], json_encode($input) ?: '');
        }
        self::assertCount(count($cases), $http->requests);
        // An integral float goes out as an integer, as JavaScript writes it.
        self::assertSame('{"value":1000000000000000}', $http->rawBody(2));
        self::assertSame('{"value":12}', $http->rawBody(4));
    }

    /**
     * @return array<string, array{string, int|float|string, array<string, mixed>, string}>
     */
    public static function refusals(): array
    {
        return [
            'empty key' => ['', 1, [], '/`key` must be 1–40/'],
            'key with a space' => ['bad key', 1, [], '/`key` must be 1–40/'],
            'key with a dot' => ['a.b', 1, [], '/`key` must be 1–40/'],
            'key led by a dash' => ['-a', 1, [], '/`key` must be 1–40/'],
            'key too long' => [str_repeat('a', 41), 1, [], '/`key` must be 1–40/'],
            'NaN' => ['k', NAN, [], '/finite number/'],
            'Infinity' => ['k', INF, [], '/finite number/'],
            '-Infinity as a number' => ['k', -INF, ['format' => 'number'], '/finite number/'],
            'too large' => ['k', 1e15 + 1e6, [], '/absolute value of at most 1000000000000000/'],
            'text for a number' => ['k', 'abc', ['format' => 'number'], '/`value` must be a number for the number format/'],
            'exponent' => ['k', '1e5', ['format' => 'number'], '/must be a number/'],
            'NaN text for percent' => ['k', 'NaN', ['format' => 'percent'], '/must be a number/'],
            'hex' => ['k', '0x10', ['format' => 'unit', 'unit' => 'ms'], '/must be a number/'],
            'string too long' => ['k', str_repeat('x', 25), [], '/1–24 characters/'],
            'control characters' => ['k', "a\nb", [], '/control characters/'],
            'number for text' => ['k', 5, ['format' => 'text'], '/must be a string for the text format/'],
            'text too long' => ['k', str_repeat('x', 25), ['format' => 'text'], '/1–24 characters for the text format/'],
            'unknown format' => ['k', 1, ['format' => 'money'], '/`format` must be one of: number, currency, percent, unit, text/'],
            'blank label' => ['k', 1, ['label' => ' '], '/`label` must be 1–24/'],
            'label too long' => ['k', 1, ['label' => str_repeat('x', 25)], '/`label` must be 1–24/'],
            'label with a tab' => ['k', 1, ['label' => "a\tb"], '/`label` must not contain control characters/'],
            'unit on number' => ['k', 1, ['format' => 'number', 'unit' => 'ms'], '/only allowed with the currency and unit formats/'],
            'unit on text' => ['k', 'a', ['format' => 'text', 'unit' => 'ms'], '/only allowed with the currency and unit formats/'],
            'unit on percent' => ['k', 1, ['format' => 'percent', 'unit' => '%'], '/only allowed with the currency and unit formats/'],
            'two-letter currency' => ['k', 1, ['format' => 'currency', 'unit' => 'US'], '/ISO 4217 code/'],
            'four-letter currency' => ['k', 1, ['format' => 'currency', 'unit' => 'USDX'], '/ISO 4217 code/'],
            'numeric currency' => ['k', 1, ['format' => 'currency', 'unit' => '123'], '/ISO 4217 code/'],
            'unit too long' => ['k', 1, ['format' => 'unit', 'unit' => 'abcdefghi'], '/`unit` must be 1–8/'],
            'empty unit' => ['k', 1, ['format' => 'unit', 'unit' => ''], '/`unit` must be 1–8/'],
        ];
    }

    /**
     * @param array<string, mixed> $options
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('refusals')]
    public function testSetRefusesAWriteTheServerWouldRefuseBeforeAnyRequest(string $key, int|float|string $value, array $options, string $pattern): void
    {
        [$pr, $http] = $this->clientAnswering(self::stored());
        $e = $this->thrown(fn() => $pr->metrics->set('ab12', $key, $value, ...$options));
        self::assertSame('invalid_request', $e->errorCode);
        self::assertMatchesRegularExpression($pattern, $e->getMessage());
        self::assertSame([], $http->requests);
    }

    public function testLabelUnitAndTextLimitsCountCodePointsAsTheServerDoes(): void
    {
        [$pr, $http] = $this->clientAnswering(self::stored());
        $pr->metrics->set('ab12', 'k', 1, label: str_repeat('😀', 24));
        $pr->metrics->set('ab12', 'k', str_repeat('😀', 24), format: 'text');
        self::assertCount(2, $http->requests);
        self::assertSame('invalid_request', $this->thrown(fn() => $pr->metrics->set('ab12', 'k', 1, label: str_repeat('😀', 25)))->errorCode);
        self::assertSame('invalid_request', $this->thrown(fn() => $pr->metrics->set('ab12', 'k', str_repeat('😀', 25), format: 'text'))->errorCode);
    }

    public function testACurrencyCodeIsCheckedForItsShapeOnlyTheServerOwnsTheList(): void
    {
        [$pr, $http] = $this->clientAnswering(self::stored());
        // ZZZ is not an ISO 4217 code and the server will refuse it, but a code ISO adds
        // later must not be refused here first.
        $pr->metrics->set('ab12', 'k', 1, format: 'currency', unit: 'zzz');
        self::assertSame('zzz', $http->jsonBody()['unit']);
    }

    public function testNormalizeKeyTrimsLowercasesAsciiOnlyAndChecksTheStoredShape(): void
    {
        self::assertSame('open-tickets_2', Metrics::normalizeKey('  Open-Tickets_2 '));
        foreach (['É', 'İ', ''] as $bad) {
            self::assertSame('invalid_request', $this->thrown(fn() => Metrics::normalizeKey($bad))->errorCode, $bad);
        }
    }

    public function testDeleteSendsDeleteForTheLowercasedKeyAndReturnsNothing(): void
    {
        [$pr, $http] = $this->clientWith(fn(RequestInterface $r): ResponseInterface => str_ends_with($r->getUri()->getPath(), '/gone')
            ? self::json(404, ['message' => 'Metric not found', 'code' => 'metric_not_found'])
            : self::json(200, ['message' => 'Metric deleted successfully']));

        $pr->metrics->delete('ab12', 'MRR');
        self::assertSame(['DELETE', '/api/agent/rooms/ab12/metrics/mrr'], [$http->last()->getMethod(), $http->last()->getUri()->getPath()]);

        $e = $this->thrown(fn() => $pr->metrics->delete('ab12', 'gone'));
        self::assertSame([404, 'metric_not_found'], [$e->status, $e->errorCode]);
        self::assertSame('invalid_request', $this->thrown(fn() => $pr->metrics->delete('ab12', '..'))->errorCode);
        self::assertSame('invalid_request', $this->thrown(fn() => $pr->metrics->delete('ab12', 'bad key'))->errorCode);
        self::assertCount(2, $http->requests);
    }

    public function testRefusalsCarryTheirCodeStatusAndServerMessage(): void
    {
        $answers = [
            [422, ['message' => 'Maximum of 8 metrics per room reached.', 'code' => 'metrics_limit_reached']],
            [403, ['message' => 'Room metrics are turned off.', 'code' => 'feature_disabled', 'feature' => 'rooms.metrics']],
            [403, ['code' => 'insufficient_scope', 'message' => 'This credential lacks pingroom:metrics:write']],
        ];
        foreach ($answers as [$status, $body]) {
            [$pr] = $this->clientAnswering($body, $status);
            $e = $this->thrown(fn() => $pr->metrics->set('ab12', 'k', 1));
            self::assertSame([$status, $body['code'], $body['message']], [$e->status, $e->errorCode, $e->getMessage()]);
        }
    }
}
