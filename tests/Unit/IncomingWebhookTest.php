<?php

declare(strict_types=1);

namespace PingRoom\Tests\Unit;

use Nyholm\Psr7\Factory\Psr17Factory;
use PingRoom\Exception\PingRoomException;
use PingRoom\IncomingWebhook;
use PingRoom\PingAction;
use PingRoom\Tests\Support\MockHttpClient;
use PingRoom\Tests\Support\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class IncomingWebhookTest extends TestCase
{
    private const URL = 'https://api.pingroom.io/api/webhooks/AB12/secret';

    /**
     * @return array{IncomingWebhook, MockHttpClient}
     */
    private function hook(mixed $body = ['success' => true], int $status = 200, bool $allowInsecure = false): array
    {
        $http = new MockHttpClient(fn(): ResponseInterface => self::json($status, $body));
        $factory = new Psr17Factory();

        return [new IncomingWebhook(httpClient: $http, requestFactory: $factory, streamFactory: $factory, allowInsecure: $allowInsecure), $http];
    }

    public function testItPostsJsonToTheUrlAsGivenAndReturnsTheResult(): void
    {
        [$hook, $http] = $this->hook(['success' => true, 'notification_id' => 'n9'], 201);
        $result = $hook->send(self::URL, ['message' => 'hi', 'action' => 2, 'data' => ['a' => 1], 'requires_ack' => true, 'ack_timeout_seconds' => 120], 'k1');

        self::assertSame('n9', $result['notification_id']);
        $request = $http->last();
        self::assertSame('POST', $request->getMethod());
        self::assertSame(self::URL, (string) $request->getUri());
        self::assertSame('k1', $request->getHeaderLine('Idempotency-Key'));
        self::assertSame('application/json', $request->getHeaderLine('Content-Type'));
        self::assertSame('application/json', $request->getHeaderLine('Accept'));
        self::assertSame('pingroom-php/0.1.0', $request->getHeaderLine('User-Agent'));
        self::assertFalse($request->hasHeader('Authorization'), 'the URL is the credential: no token is ever sent');
        self::assertSame('{"message":"hi","action":2,"data":{"a":1},"requires_ack":true,"ack_timeout_seconds":120}', $http->rawBody());
    }

    public function testAClientsTokenIsNeverSentToAWebhook(): void
    {
        [$pr, $http] = $this->clientAnswering(['success' => true]);
        $pr->sendIncomingWebhook(self::URL, ['message' => 'hi']);
        self::assertFalse($http->last()->hasHeader('Authorization'));
        self::assertSame(self::URL, (string) $http->last()->getUri());
    }

    public function testAnExplicitNullActionBypassesAStoredPreset(): void
    {
        [$hook, $http] = $this->hook(['success' => true, 'action' => null]);
        $hook->send(self::URL, ['title' => 'Deployment', 'message' => 'Version 2 is live', 'action' => null]);
        self::assertSame('{"title":"Deployment","message":"Version 2 is live","action":null}', $http->rawBody());
    }

    public function testItAcceptsThePublicRoomPingTextBoundaries(): void
    {
        [$hook, $http] = $this->hook();
        $message = str_repeat('m', 160);
        $title = str_repeat('t', 40);
        $hook->send(self::URL, ['message' => $message, 'title' => $title]);
        self::assertSame(['title' => $title, 'message' => $message], $http->jsonBody());
    }

    public function testItRejectsPingTextOverThePublicCeilingBeforeSending(): void
    {
        [$hook, $http] = $this->hook();
        $e = $this->thrown(fn() => $hook->send(self::URL, ['message' => str_repeat('m', 161)]));
        self::assertMatchesRegularExpression('/message.*160.*161/', $e->getMessage());
        $e = $this->thrown(fn() => $hook->send(self::URL, ['message' => 'ok', 'title' => str_repeat('t', 41)]));
        self::assertMatchesRegularExpression('/title.*40.*41/', $e->getMessage());
        self::assertSame([], $http->requests);
    }

    public function testTheFieldListIsTheServersOwnOrder(): void
    {
        // SERVER CONTRACT: WebhookController::trigger. A change here is a change to
        // IncomingWebhook::FIELDS, in the same order.
        self::assertSame([
            'title', 'message', 'action', 'emoji', 'icon', 'color', 'sound', 'data', 'correlation_id', 'reply_to', 'tags',
            'is_urgent', 'requires_ack', 'ack_mode', 'ack_timeout_seconds', 'live_status', 'metric',
        ], IncomingWebhook::FIELDS);
    }

    public function testItForwardsEveryFieldTheServerAccepts(): void
    {
        $payload = [
            'title' => 'Build 512', 'message' => 'green', 'action' => 3, 'emoji' => '🚀', 'icon' => 'rocket', 'color' => '#e33122', 'sound' => 'ting',
            'data' => ['commit' => 'abc123'], 'correlation_id' => 'c-1', 'reply_to' => 'r-1', 'tags' => ['deploy', 'prod'],
            'is_urgent' => true, 'requires_ack' => true, 'ack_mode' => 'all', 'ack_timeout_seconds' => 300,
            'live_status' => ['state' => 'running', 'template' => 'status'],
        ];
        $metricPayload = [...$payload, 'metric' => ['key' => 'mrr', 'value' => 12400, 'label' => 'MRR', 'format' => 'currency', 'unit' => 'USD']];
        unset($metricPayload['live_status']);
        // Every documented field must be exercised, or a dropped one slips past.
        $keys = array_unique([...array_keys($payload), ...array_keys($metricPayload)]);
        sort($keys);
        $fields = IncomingWebhook::FIELDS;
        sort($fields);
        self::assertSame($fields, $keys);

        [$hook, $http] = $this->hook();
        foreach ([$payload, $metricPayload] as $n => $body) {
            $hook->send(self::URL, $body);
            self::assertSame($body, $http->jsonBody($n));
        }
    }

    public function testAKeyThatIsNotAServerFieldIsNotSent(): void
    {
        [$hook, $http] = $this->hook();
        $hook->send(self::URL, ['message' => 'hi', 'correlationId' => 'typo', 'foo' => 1]);
        self::assertSame('{"message":"hi"}', $http->rawBody());
    }

    public function testAnEmptyPayloadIsAnEmptyObject(): void
    {
        [$hook, $http] = $this->hook();
        $hook->send(self::URL, []);
        self::assertSame('{}', $http->rawBody());
    }

    public function testItRefusesAnInsecureUrlAndNeverEchoesAMalformedOne(): void
    {
        [$hook, $http] = $this->hook();
        self::assertSame('insecure_url', $this->thrown(fn() => $hook->send('http://evil.example.com/x', ['message' => 'x']))->errorCode);

        $e = $this->thrown(fn() => $hook->send('not-a-url/secret-SYNTHETIC', ['message' => 'hi']));
        self::assertSame('invalid_url', $e->errorCode);
        self::assertStringNotContainsString('secret-SYNTHETIC', $e->getMessage());
        self::assertSame([], $http->requests);

        // Allowed on purpose: a trusted local development hook.
        [$hook] = $this->hook(allowInsecure: true);
        self::assertTrue($hook->send('http://hooks.internal.example/x', ['message' => 'x'])['success']);
        [$hook] = $this->hook();
        self::assertTrue($hook->send('http://localhost:8000/x', ['message' => 'x'])['success']);
    }

    public function testItValidatesTheActionSlot(): void
    {
        [$hook, $http] = $this->hook();
        self::assertSame('invalid_request', $this->thrown(fn() => $hook->send(self::URL, ['action' => 17]))->errorCode);
        self::assertSame('invalid_request', $this->thrown(fn() => $hook->send(self::URL, ['action' => '2']))->errorCode);
        self::assertSame([], $http->requests);
    }

    public function testAFailureBodyBecomesAnErrorWithRetryAfter(): void
    {
        [$hook] = $this->hook(['success' => false, 'error' => 'cooldown_active', 'message' => 'wait', 'retry_after' => 5], 429);
        $e = $this->thrown(fn() => $hook->send(self::URL, ['message' => 'hi']));
        self::assertSame([429, 'cooldown_active', 5, 'wait'], [$e->status, $e->errorCode, $e->retryAfter, $e->getMessage()]);
    }

    public function testA2xxThatSaysSuccessFalseMapsToTheServerError(): void
    {
        [$hook] = $this->hook(['success' => false, 'error' => 'webhook_disabled', 'message' => 'Webhook is currently disabled'], 200);
        $e = $this->thrown(fn() => $hook->send(self::URL, ['message' => 'hi']));
        self::assertSame([200, 'webhook_disabled'], [$e->status, $e->errorCode]);
    }

    public function testA2xxThatIsNotAJsonObjectWithSuccessTrueIsInvalidResponseAndNeverLeaksTheUrl(): void
    {
        $url = 'https://api.pingroom.io/api/webhooks/AB12/secret-SYNTHETIC';
        $bodies = [
            'an HTML page' => ['<html>Please sign in to the hotspot</html>', 200],
            'plain text' => ['OK', 200],
            'an empty body' => ['', 200],
            'an empty 204' => ['', 204],
            'a JSON null' => ['null', 200],
            'an array' => ['[]', 200],
            'an object without success' => ['{"notification_id":"n1"}', 200],
            'a non-boolean success' => ['{"success":"true"}', 200],
        ];
        foreach ($bodies as $label => [$body, $status]) {
            $hook = new IncomingWebhook(httpClient: new MockHttpClient(fn() => self::raw($status, $body)), requestFactory: new Psr17Factory(), streamFactory: new Psr17Factory());
            $e = $this->thrown(fn() => $hook->send($url, ['message' => 'hi']));
            self::assertSame('invalid_response', $e->errorCode, $label);
            self::assertSame(0, $e->status, $label);
            self::assertStringNotContainsString('secret-SYNTHETIC', $e->getMessage(), $label);
        }
    }

    public function testItStillReturnsEveryNormalSuccessShapeTheServerSends(): void
    {
        $bodies = [
            [
                'success' => true, 'message' => 'Webhook triggered successfully', 'notification_id' => 'n1', 'room' => ['id' => 'r1', 'name' => 'Builds'],
                'webhook' => ['id' => 'w1', 'name' => 'CI', 'emoji' => null, 'icon' => null], 'action' => null, 'notification_title' => null,
                'notification_message' => 'hi', 'action_state' => null, 'triggered_at' => '2026-11-07T10:00:00+00:00',
            ],
            ['success' => true, 'notification_id' => 'n2', 'correlation_id' => 'deploy-1', 'action_state' => null, 'live_status' => ['state' => 'running'], 'idempotent' => true],
        ];
        foreach ($bodies as $body) {
            foreach ([200, 201] as $status) {
                [$hook] = $this->hook($body, $status);
                self::assertSame($body, $hook->send(self::URL, ['message' => 'hi']));
            }
        }
    }

    public function testEveryoneConfirmationIsForwardedAndPartialProgressReturned(): void
    {
        $state = ['status' => 'open', 'mode' => 'all', 'confirmed_count' => 1, 'required_count' => 3];
        [$hook, $http] = $this->hook(['success' => true, 'action_state' => $state]);
        $result = $hook->send(self::URL, ['message' => 'Confirm', 'requires_ack' => true, 'ack_mode' => 'all']);
        self::assertSame('{"message":"Confirm","requires_ack":true,"ack_mode":"all"}', $http->rawBody());
        self::assertSame($state, $result['action_state']);
    }

    public function testAWrappedClientErrorHasTheWebhookUrlStrippedOut(): void
    {
        $url = 'https://example.invalid/webhooks/secret-SYNTHETIC?x=1';
        $hook = new IncomingWebhook(
            httpClient: new MockHttpClient(function (RequestInterface $request): ResponseInterface {
                throw new \RuntimeException("request to {$request->getUri()} failed, reason: getaddrinfo ENOTFOUND (path /webhooks/secret-SYNTHETIC)");
            }),
            requestFactory: new Psr17Factory(),
            streamFactory: new Psr17Factory(),
        );
        $e = $this->thrown(fn() => $hook->send($url, ['message' => 'hi']));
        self::assertSame('network_error', $e->errorCode);
        self::assertStringNotContainsString('secret-SYNTHETIC', $e->getMessage());
        self::assertStringStartsWith('Webhook request failed: ', $e->getMessage());
        self::assertStringContainsString('https://example.invalid/[redacted]', $e->getMessage());
        self::assertStringContainsString('ENOTFOUND', $e->getMessage(), 'the useful part of the reason survives');
    }

    public function testATimeoutIsATimeoutException(): void
    {
        $hook = new IncomingWebhook(
            httpClient: new MockHttpClient(function (): ResponseInterface {
                throw new \RuntimeException('cURL error 28: Operation timed out');
            }),
            requestFactory: new Psr17Factory(),
            streamFactory: new Psr17Factory(),
        );
        self::assertSame('timeout', $this->thrown(fn() => $hook->send(self::URL, ['message' => 'hi']))->errorCode);
    }

    public function testARedirectIsRefusedAndTheBodyIsNotReplayed(): void
    {
        foreach ([307, 308] as $status) {
            $http = new MockHttpClient(fn() => self::raw($status, '', ['Location' => 'https://evil.example/collect']));
            $hook = new IncomingWebhook(httpClient: $http, requestFactory: new Psr17Factory(), streamFactory: new Psr17Factory());
            $e = $this->thrown(fn() => $hook->send(self::URL, ['message' => 'fixture-private-message']));
            self::assertSame(['redirect_refused', $status], [$e->errorCode, $e->status]);
            self::assertCount(1, $http->requests);
        }
    }

    public function testAReplaceKeyIsSentAsReplaceKeyAndReplacedIsReturned(): void
    {
        [$hook, $http] = $this->hook(['success' => true, 'notification_id' => 'n1', 'replaced' => true]);
        $result = $hook->send(self::URL, ['message' => 'Build 41: green', 'replace_key' => 'build:41']);
        self::assertTrue($result['replaced']);
        self::assertSame('{"message":"Build 41: green","replace_key":"build:41"}', $http->rawBody());

        // The SDK's alias, as the TypeScript SDK spells it.
        $hook->send(self::URL, ['message' => 'Build 41: green', 'replaceKey' => 'build:41']);
        self::assertSame('{"message":"Build 41: green","replace_key":"build:41"}', $http->rawBody(1));
        self::assertStringNotContainsString('replaceKey', $http->rawBody(1));

        foreach (['no spaces allowed', '', str_repeat('x', 65)] as $key) {
            self::assertSame('invalid_request', $this->thrown(fn() => $hook->send(self::URL, ['message' => 'm', 'replace_key' => $key]))->errorCode, $key);
        }
        self::assertSame('invalid_request', $this->thrown(fn() => $hook->send(self::URL, ['message' => 'm', 'replace_key' => 'a', 'replaceKey' => 'b']))->errorCode);
        self::assertSame('invalid_request', $this->thrown(fn() => $hook->send(self::URL, ['message' => 'm', 'replace_key' => 7]))->errorCode);
        self::assertCount(2, $http->requests);
    }

    public function testTagsAreSentAlsoWithALiveStatusStreamAndValidated(): void
    {
        [$hook, $http] = $this->hook();
        $hook->send(self::URL, ['message' => 'green', 'tags' => ['ci', 'main']]);
        $hook->send(self::URL, ['correlation_id' => 'c1', 'tags' => ['nightly'], 'live_status' => ['state' => 'running']]);
        self::assertSame(['ci', 'main'], $http->jsonBody(0)['tags']);
        self::assertSame(['nightly'], $http->jsonBody(1)['tags']);
        self::assertSame('invalid_request', $this->thrown(fn() => $hook->send(self::URL, ['message' => 'x', 'tags' => ['bad tag']]))->errorCode);
        self::assertCount(2, $http->requests);
    }

    public function testButtonsRideAnOrdinaryPingAndAreRefusedOnAStream(): void
    {
        $hookButton = ['id' => 'clean', 'label' => 'Clean up now', 'type' => 'webhook', 'url' => 'https://ops.example.com/cleanup', 'body' => ['host' => 'db-2'], 'style' => 'danger'];
        [$hook, $http] = $this->hook(['success' => true, 'notification_id' => 'n1', 'actions' => [['id' => 'clean', 'label' => 'Clean up now', 'type' => 'webhook', 'style' => 'danger']], 'action_signing_secret' => str_repeat('cd', 32)]);
        $result = $hook->send(self::URL, ['message' => 'Disk 91%', 'data' => ['actions' => [$hookButton]]]);
        self::assertSame([$hookButton], $http->jsonBody()['data']['actions']);
        self::assertSame(str_repeat('cd', 32), $result['action_signing_secret']);

        $e = $this->thrown(fn() => $hook->send(self::URL, ['data' => ['actions' => [$hookButton]], 'live_status' => ['state' => 'running'], 'correlation_id' => 'c']));
        self::assertSame('invalid_data', $e->errorCode);
        self::assertStringContainsString('live-progress stream cannot carry buttons', $e->getMessage());

        $e = $this->thrown(fn() => $hook->send(self::URL, ['message' => 'x', 'data' => ['actions' => [[...$hookButton, 'url' => 'https://10.0.0.5/']]]]));
        self::assertStringContainsString('public hostname', $e->getMessage());
        self::assertCount(1, $http->requests);

        $hook->send(self::URL, ['message' => 'x', 'data' => ['actions' => [PingAction::openUrl('Open', 'https://example.com/')]]]);
        self::assertCount(2, $http->requests);
    }

    public function testAnEmptyDataStaysAnObjectAndOversizedDataIsRefused(): void
    {
        [$hook, $http] = $this->hook();
        $hook->send(self::URL, ['message' => 'x', 'data' => []]);
        self::assertSame('{"message":"x","data":{}}', $http->rawBody());
        self::assertSame('invalid_data', $this->thrown(fn() => $hook->send(self::URL, ['message' => 'x', 'data' => ['n' => str_repeat('س', 2000)]]))->errorCode);
        self::assertCount(1, $http->requests);
    }

    public function testIdempotencyKeysFollowTheSameHeaderSafeRuleAndEmptyMeansNoKey(): void
    {
        [$hook, $http] = $this->hook();
        foreach (['deploy-✅', str_repeat('k', 256), '   '] as $key) {
            $e = $this->thrown(fn() => $hook->send(self::URL, ['message' => 'hi'], $key));
            self::assertSame('invalid_request', $e->errorCode);
            self::assertStringContainsString('idempotencyKey', $e->getMessage());
        }
        self::assertSame([], $http->requests);

        $hook->send(self::URL, ['message' => 'hi'], 'build 42');
        $hook->send(self::URL, ['message' => 'hi'], '');
        $hook->send(self::URL, ['message' => 'hi']);
        self::assertSame('build 42', $http->request(0)->getHeaderLine('Idempotency-Key'));
        self::assertFalse($http->request(1)->hasHeader('Idempotency-Key'));
        self::assertFalse($http->request(2)->hasHeader('Idempotency-Key'));
    }

    public function testATopLevelMetricIsPostedAndTheStoredMetricReturned(): void
    {
        $stored = ['key' => 'mrr', 'label' => 'MRR', 'format' => 'currency', 'unit' => 'USD', 'value' => 12400, 'value_updated_at' => '2026-11-11T08:12:30.000000Z'];
        [$hook, $http] = $this->hook(['success' => true, 'metric' => $stored], 201);
        $result = $hook->send(self::URL, ['metric' => ['key' => 'MRR', 'value' => 12400, 'label' => 'MRR', 'format' => 'currency', 'unit' => 'USD']]);
        self::assertSame('mrr', $result['metric']['key']);
        self::assertArrayNotHasKey('unchanged', $result);
        self::assertSame('{"metric":{"key":"MRR","value":12400,"label":"MRR","format":"currency","unit":"USD"}}', $http->rawBody());

        [$hook] = $this->hook(['success' => true, 'unchanged' => true, 'metric' => $stored]);
        self::assertTrue($hook->send(self::URL, ['metric' => ['key' => 'mrr', 'value' => 12400]])['unchanged']);

        // An integral float goes out as an integer.
        [$hook, $http] = $this->hook();
        $hook->send(self::URL, ['metric' => ['key' => 'mrr', 'value' => 12400.0]]);
        self::assertSame('{"metric":{"key":"mrr","value":12400}}', $http->rawBody());
    }

    public function testAMetricIsValidatedLikeMetricsSetAndRefusedWithLiveStatus(): void
    {
        [$hook, $http] = $this->hook();
        $cases = [
            [['metric' => ['key' => 'bad key', 'value' => 1]], '/`metric.key` must be 1–40/'],
            [['metric' => ['key' => 'mrr']], '/`metric.value` is required/'],
            [['metric' => ['key' => 'mrr', 'value' => NAN]], '/finite number/'],
            [['metric' => ['key' => 'mrr', 'value' => 1, 'format' => 'currency', 'unit' => 'US']], '/`metric.unit` for the currency format/'],
            [['metric' => ['key' => 'mrr', 'value' => 1, 'format' => 'number', 'unit' => 'ms']], '/`metric.unit` is only allowed/'],
            [['metric' => ['key' => 'mrr', 'value' => str_repeat('x', 25), 'format' => 'text']], '/`metric.value` must be 1–24/'],
            [['metric' => 'mrr'], '/`metric` must be an object/'],
            [['metric' => null], '/`metric` must be an object/'],
            [['metric' => [1]], '/`metric` must be an object/'],
            [['metric' => ['key' => 'mrr', 'value' => 1], 'live_status' => ['state' => 'running'], 'correlation_id' => 'c'], '/cannot be combined with `live_status`/'],
        ];
        foreach ($cases as [$payload, $pattern]) {
            $e = $this->thrown(fn() => $hook->send(self::URL, $payload));
            self::assertSame('invalid_request', $e->errorCode);
            self::assertMatchesRegularExpression($pattern, $e->getMessage());
        }
        self::assertSame([], $http->requests);
    }

    public function testItSurfacesTheWebhookEnvelopeRefusalsOfAMetric(): void
    {
        [$hook] = $this->hook(['success' => false, 'error' => 'metrics_limit_reached', 'code' => 'metrics_limit_reached', 'message' => 'Maximum of 8 metrics per room reached.'], 422);
        $e = $this->thrown(fn() => $hook->send(self::URL, ['metric' => ['key' => 'ninth', 'value' => 1]]));
        self::assertSame([422, 'metrics_limit_reached'], [$e->status, $e->errorCode]);
    }

    public function testAConstructedWebhookNeedsNoTokenAndFindsItsOwnFactories(): void
    {
        // Discovery finds the installed PSR-17 factories and a PSR-18 client.
        self::assertInstanceOf(IncomingWebhook::class, new IncomingWebhook());
        self::assertInstanceOf(PingRoomException::class, $this->thrown(fn() => (new IncomingWebhook())->send('http://evil.example.com', [])));
    }
}
