<?php

declare(strict_types=1);

namespace PingRoom\Tests\Unit;

use PingRoom\Tests\Support\TestCase;

/** Questions, approvals and handoffs: the exact requests, and what is refused before sending. */
final class HumanLoopTest extends TestCase
{
    private const OPEN = ['label' => 'Open', 'type' => 'open_url', 'url' => 'https://example.com/'];

    public function testQuestionsAskPostsThePromptAndOptionsToTheRoomQuestionsPath(): void
    {
        [$pr, $http] = $this->clientAnswering(['id' => 'q1', 'kind' => 'question', 'prompt' => 'Deploy?', 'state' => 'pending', 'options' => [], 'answer' => null], 201);
        $question = $pr->questions->ask(
            'ab12',
            'Deploy?',
            options: ['ship', 'hold'],
            responderScope: 'room',
            ttl: 600,
            correlationId: 'd-1',
            attachmentIds: ['att_1', 'att_2', 'att_3', 'att_4'],
        );

        self::assertSame('q1', $question['id']);
        self::assertSame('pending', $question['state']);
        $request = $http->last();
        self::assertSame('POST', $request->getMethod());
        self::assertSame('/api/agent/rooms/ab12/questions', $request->getUri()->getPath());
        self::assertSame(
            '{"prompt":"Deploy?","options":["ship","hold"],"responder_scope":"room","ttl":600,"correlation_id":"d-1","attachment_ids":["att_1","att_2","att_3","att_4"]}',
            $http->rawBody(),
        );
        self::assertFalse($request->hasHeader('Idempotency-Key'));
    }

    public function testQuestionsAskSendsEveryFieldUnderItsWireNameAndTheIdempotencyKeyAsAHeader(): void
    {
        [$pr, $http] = $this->clientAnswering(['id' => 'q1', 'state' => 'pending'], 201);
        $pr->questions->ask(
            'ab12',
            'Which?',
            context: 'build 42',
            options: ['a', ['value' => 'b', 'label' => 'Bee', 'style' => 'danger']],
            textInput: ['placeholder' => 'why', 'max_length' => 30],
            responderScope: 'direct',
            resolutionPolicy: 'first',
            targetUserId: 'user-uuid',
            ttl: 60,
            data: ['k' => 'v'],
            correlationId: 'c',
            replyTo: 'r',
            idempotencyKey: 'ask-1',
        );
        self::assertSame([
            'prompt' => 'Which?',
            'context' => 'build 42',
            'options' => ['a', ['value' => 'b', 'label' => 'Bee', 'style' => 'danger']],
            'text_input' => ['placeholder' => 'why', 'max_length' => 30],
            'responder_scope' => 'direct',
            'resolution_policy' => 'first',
            'target_user_id' => 'user-uuid',
            'ttl' => 60,
            'data' => ['k' => 'v'],
            'correlation_id' => 'c',
            'reply_to' => 'r',
        ], $http->jsonBody());
        self::assertSame('ask-1', $http->last()->getHeaderLine('Idempotency-Key'));

        // An empty text_input object stays an object.
        $pr->questions->ask('ab12', 'Why?', textInput: []);
        self::assertSame('{"prompt":"Why?","text_input":{}}', $http->rawBody(1));
    }

    public function testAPollCannotBeCombinedWithADirectScopeATargetOrATypedAnswer(): void
    {
        [$pr, $http] = $this->clientAnswering(['id' => 'q1', 'state' => 'pending'], 201);
        $attempts = [
            fn() => $pr->questions->ask('ab12', 'Lunch?', options: ['a', 'b'], resolutionPolicy: 'all', responderScope: 'direct'),
            fn() => $pr->questions->ask('ab12', 'Lunch?', options: ['a', 'b'], resolutionPolicy: 'all', targetUserId: 'u1'),
            fn() => $pr->questions->ask('ab12', 'Lunch?', resolutionPolicy: 'all', textInput: ['max_length' => 10]),
        ];
        foreach ($attempts as $attempt) {
            self::assertSame('invalid_request', $this->thrown($attempt)->errorCode);
        }
        self::assertSame([], $http->requests);

        $pr->questions->ask('ab12', 'Lunch?', options: ['a', 'b'], resolutionPolicy: 'all', responderScope: 'room', targetUserId: '');
        self::assertCount(1, $http->requests);
    }

    public function testQuestionsRefuseABlankPromptButtonsInDataAndOversizedData(): void
    {
        [$pr, $http] = $this->clientAnswering(['id' => 'q1'], 201);
        self::assertSame('invalid_request', $this->thrown(fn() => $pr->questions->ask('ab12', ' '))->errorCode);
        $e = $this->thrown(fn() => $pr->questions->ask('ab12', 'Ship?', data: ['actions' => [self::OPEN]]));
        self::assertSame('invalid_data', $e->errorCode);
        self::assertStringStartsWith('A Question cannot carry buttons.', $e->getMessage());
        self::assertSame('invalid_data', $this->thrown(fn() => $pr->questions->ask('ab12', 'Ship?', data: ['x' => str_repeat('a', 9000)]))->errorCode);
        self::assertSame([], $http->requests);
    }

    public function testQuestionsListUnwrapsTheQuestionsAndSendsTheStateFilter(): void
    {
        [$pr, $http] = $this->clientAnswering(['questions' => [['id' => 'q1', 'kind' => 'question', 'state' => 'answered']]]);
        $list = $pr->questions->list(state: 'answered');
        self::assertCount(1, $list);
        self::assertSame('q1', $list[0]['id']);
        self::assertSame('/api/agent/questions', $http->last()->getUri()->getPath());
        self::assertSame('state=answered', $http->last()->getUri()->getQuery());

        [$pr] = $this->clientAnswering([]);
        self::assertSame([], $pr->questions->list());
    }

    public function testQuestionsGetWaitAndCancel(): void
    {
        [$pr, $http] = $this->clientAnswering(['id' => 'q1', 'kind' => 'question', 'state' => 'cancelled']);
        $pr->questions->get('q1');
        self::assertSame(['GET', '/api/agent/questions/q1'], [$http->last()->getMethod(), $http->last()->getUri()->getPath()]);

        $pr->questions->wait('q1', timeout: 12);
        self::assertSame('/api/agent/questions/q1/wait', $http->last()->getUri()->getPath());
        self::assertSame('timeout=12', $http->last()->getUri()->getQuery());

        $question = $pr->questions->cancel('q1');
        self::assertSame('cancelled', $question['state']);
        self::assertSame(['POST', '/api/agent/questions/q1/cancel'], [$http->last()->getMethod(), $http->last()->getUri()->getPath()]);
        self::assertSame('{}', $http->rawBody(2));
    }

    public function testQuestionsWaitForAnswerLoopsUntilTheStateLeavesPending(): void
    {
        $n = 0;
        [$pr, $http] = $this->clientWith(function () use (&$n) {
            ++$n;

            return self::json(200, $n < 2
                ? ['id' => 'q1', 'kind' => 'question', 'state' => 'pending', 'answer' => null]
                : ['id' => 'q1', 'kind' => 'question', 'state' => 'answered', 'answer' => ['value' => 'ship', 'label' => 'Ship']]);
        });
        $resolved = $pr->questions->waitForAnswer('q1');
        self::assertSame('answered', $resolved['state']);
        self::assertSame('ship', $resolved['answer']['value']);
        self::assertSame(2, $n);
        self::assertCount(2, $http->requests);
    }

    public function testApprovalsRequestPostsTheDecisionAndCarriesTheIdempotencyKey(): void
    {
        [$pr, $http] = $this->clientAnswering(['id' => 'a1', 'status' => 'pending', 'question' => 'Ship it?'], 201);
        $approval = $pr->approvals->request(
            'AB12',
            'Ship it?',
            title: 'Release',
            options: ['approve', 'deny'],
            correlationId: 'rel-1',
            data: ['build' => 42],
            ttl: 300,
            idempotencyKey: 'ship-42',
        );
        self::assertSame('a1', $approval['id']);
        $request = $http->last();
        self::assertSame(['POST', '/api/agent/rooms/AB12/approvals'], [$request->getMethod(), $request->getUri()->getPath()]);
        self::assertSame('{"question":"Ship it?","title":"Release","options":["approve","deny"],"correlation_id":"rel-1","data":{"build":42},"ttl":300}', $http->rawBody());
        self::assertSame('ship-42', $request->getHeaderLine('Idempotency-Key'));

        $pr->approvals->request('AB12', 'Ship it?');
        self::assertSame('{"question":"Ship it?"}', $http->rawBody(1));
        self::assertFalse($http->requests[1]->hasHeader('Idempotency-Key'));
    }

    public function testApprovalsRefuseABlankQuestionAndButtonsBeforeSending(): void
    {
        [$pr, $http] = $this->clientAnswering(['id' => 'a1'], 201);
        self::assertSame('invalid_request', $this->thrown(fn() => $pr->approvals->request('AB12', ''))->errorCode);
        self::assertStringStartsWith('An approval cannot carry buttons.', $this->thrown(fn() => $pr->approvals->request('AB12', 'Ship?', data: ['actions' => [self::OPEN]]))->getMessage());
        self::assertSame([], $http->requests);
    }

    public function testApprovalsGetAndWaitHitTheApprovalPaths(): void
    {
        [$pr, $http] = $this->clientAnswering(['id' => 'a1', 'status' => 'pending']);
        $pr->approvals->get('a1');
        self::assertSame('/api/agent/approvals/a1', $http->last()->getUri()->getPath());
        $pr->approvals->wait('a1', timeout: 9);
        self::assertSame('/api/agent/approvals/a1/wait', $http->last()->getUri()->getPath());
        self::assertSame('timeout=9', $http->last()->getUri()->getQuery());
    }

    public function testHandoffsRequestAckPostsADirectAckHandoffWithTheIdempotencyKeyHeader(): void
    {
        [$pr, $http] = $this->clientAnswering(['id' => 'h1', 'kind' => 'ack', 'prompt' => 'Ack me', 'state' => 'open', 'delivery_state' => 'enqueued'], 201);
        $handoff = $pr->handoffs->requestAck('Ack me', expiresIn: 600, correlationId: 'deploy-1', data: ['commit' => 'abc'], idempotencyKey: 'idem-1');

        self::assertSame('h1', $handoff['id']);
        self::assertSame('enqueued', $handoff['delivery_state']);
        $request = $http->last();
        self::assertSame(['POST', '/api/agent/handoffs'], [$request->getMethod(), $request->getUri()->getPath()]);
        self::assertSame('idem-1', $request->getHeaderLine('Idempotency-Key'));
        self::assertSame(
            '{"kind":"ack","prompt":"Ack me","audience":{"type":"direct","user_id":"me"},"expires_in":600,"correlation_id":"deploy-1","data":{"commit":"abc"}}',
            $http->rawBody(),
        );
    }

    public function testHandoffsSendNoKeyWhenOmittedOrEmptyAndHonourAnExplicitTargetAndEveryField(): void
    {
        [$pr, $http] = $this->clientAnswering(['id' => 'h2', 'kind' => 'ack', 'state' => 'open'], 201);
        $pr->handoffs->requestAck('hi', target: 'user-uuid-9');
        self::assertFalse($http->last()->hasHeader('Idempotency-Key'));
        self::assertSame(['type' => 'direct', 'user_id' => 'user-uuid-9'], $http->jsonBody()['audience']);

        $pr->handoffs->requestAck('hi', idempotencyKey: '');
        self::assertFalse($http->last()->hasHeader('Idempotency-Key'));

        $pr->handoffs->requestAck('hi', urgency: 'passive', correlationId: 'c', replyTo: 'r');
        self::assertSame(['kind' => 'ack', 'prompt' => 'hi', 'audience' => ['type' => 'direct', 'user_id' => 'me'], 'urgency' => 'passive', 'correlation_id' => 'c', 'reply_to' => 'r'], $http->jsonBody(2));
    }

    public function testHandoffsAskNormalizesBareStringOptionsAndChecksTheOptionCount(): void
    {
        [$pr, $http] = $this->clientAnswering(['id' => 'h3', 'kind' => 'question', 'prompt' => 'Ship?', 'state' => 'pending', 'options' => []], 201);
        $handoff = $pr->handoffs->ask('Ship?', ['deploy', ['value' => 'hold', 'style' => 'danger']]);
        self::assertSame('question', $handoff['kind']);
        self::assertSame([['value' => 'deploy', 'label' => 'deploy'], ['value' => 'hold', 'style' => 'danger']], $http->jsonBody()['options']);
        self::assertSame('question', $http->jsonBody()['kind']);

        foreach ([['only-one'], ['one', 'two', 'three', 'four', 'five'], []] as $options) {
            $e = $this->thrown(fn() => $pr->handoffs->ask('x', $options));
            self::assertSame('invalid_request', $e->errorCode);
            self::assertStringContainsString('between 2 and 4', $e->getMessage());
        }
        self::assertCount(1, $http->requests);
    }

    public function testHandoffsRefuseABlankPromptButtonsAndAKeyThatCannotBeAHeader(): void
    {
        [$pr, $http] = $this->clientAnswering(['id' => 'h1'], 201);
        self::assertSame('invalid_request', $this->thrown(fn() => $pr->handoffs->requestAck(' '))->errorCode);
        self::assertStringStartsWith('A handoff cannot carry buttons.', $this->thrown(fn() => $pr->handoffs->requestAck('Look', data: ['actions' => [self::OPEN]]))->getMessage());
        self::assertStringStartsWith('A handoff cannot carry buttons.', $this->thrown(fn() => $pr->handoffs->ask('Which?', ['a', 'b'], data: ['actions' => [self::OPEN]]))->getMessage());
        foreach (['deploy-✅', str_repeat('k', 256), '   '] as $key) {
            self::assertSame('invalid_request', $this->thrown(fn() => $pr->handoffs->requestAck('Ship?', idempotencyKey: $key))->errorCode);
            self::assertSame('invalid_request', $this->thrown(fn() => $pr->handoffs->ask('Ship?', ['a', 'b'], idempotencyKey: $key))->errorCode);
        }
        self::assertSame([], $http->requests);
    }

    public function testHandoffsSurfaceAnIdempotencyConflictAsATypedError(): void
    {
        [$pr] = $this->clientAnswering(['code' => 'idempotency_conflict', 'message' => 'reused key'], 409);
        $e = $this->thrown(fn() => $pr->handoffs->requestAck('hi', idempotencyKey: 'dup'));
        self::assertSame([409, 'idempotency_conflict'], [$e->status, $e->errorCode]);
    }

    public function testHandoffsGetListAndWaitForResult(): void
    {
        [$pr, $http] = $this->clientAnswering(['handoffs' => [['id' => 'h5', 'kind' => 'ack', 'state' => 'open']]]);
        $list = $pr->handoffs->list(state: 'all');
        self::assertSame('h5', $list[0]['id']);
        self::assertSame('state=all', $http->last()->getUri()->getQuery());

        [$pr, $http] = $this->clientAnswering(['id' => 'h4', 'kind' => 'question', 'state' => 'answered', 'answer' => ['value' => 'hold', 'label' => 'Hold']]);
        $resolved = $pr->handoffs->waitForResult('h4', timeout: 5);
        // A negative answer is a successful `answered` result.
        self::assertSame('hold', $resolved['answer']['value']);
        self::assertSame('/api/agent/handoffs/h4/wait', $http->last()->getUri()->getPath());
        self::assertSame('timeout=5', $http->last()->getUri()->getQuery());

        $pr->handoffs->get('h4');
        self::assertSame('/api/agent/handoffs/h4', $http->last()->getUri()->getPath());
    }

    public function testHandoffsTreatExpiredAndCancelledAsNormalTerminalResults(): void
    {
        foreach (['expired', 'cancelled'] as $state) {
            [$pr] = $this->clientAnswering(['id' => 'h4', 'kind' => 'question', 'state' => $state]);
            self::assertSame($state, $pr->handoffs->waitForResult('h4')['state']);
        }
    }
}
