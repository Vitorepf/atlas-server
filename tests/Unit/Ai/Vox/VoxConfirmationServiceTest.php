<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Vox;

use App\Services\Ai\Vox\Confirmation\VoxConfirmationService;
use App\Services\Ai\Vox\VoxSchema;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

final class VoxConfirmationServiceTest extends TestCase
{
    private function makeService(): VoxConfirmationService
    {
        return new VoxConfirmationService(Cache::store());
    }

    /**
     * @return array{intent: array<string,mixed>, receipt: array<string,mixed>}
     */
    private function makePair(string $risk = VoxSchema::RISK_R2): array
    {
        return [
            'intent' => [
                'intent_id' => 'intent-'.uniqid('', true),
                'session_id' => 'session-x',
                'mode' => VoxSchema::MODE_GOVERNED_EXECUTE,
                'executor_hint' => 'terminal_propose',
                'risk_class' => $risk,
            ],
            'receipt' => [
                'receipt_id' => 'rcpt-'.uniqid('', true),
            ],
        ];
    }

    public function test_issue_returns_request_with_token_and_actions_includes_cancel(): void
    {
        $svc = $this->makeService();
        $pair = $this->makePair(VoxSchema::RISK_R2);

        $issued = $svc->issue($pair['intent'], $pair['receipt'], [
            'mode' => VoxSchema::MODE_GOVERNED_EXECUTE,
            'risk_class' => VoxSchema::RISK_R2,
            'preview' => ['what_i_heard' => 'x'],
            'actions_available' => ['execute', 'edit_intent', 'save_as_note'],
        ]);

        $this->assertSame(VoxSchema::CONFIRMATION_REQUEST, $issued['request']['schema']);
        $this->assertSame($issued['token'], $issued['request']['confirmation_token']);
        $this->assertNotEmpty($issued['token']);
        $this->assertContains('cancel', $issued['request']['actions_available']);
        $this->assertFalse($issued['request']['requires_literal_confirmation']);
    }

    public function test_r4_issue_requires_literal_confirmation_text(): void
    {
        $svc = $this->makeService();
        $pair = $this->makePair(VoxSchema::RISK_R4);

        $issued = $svc->issue($pair['intent'], $pair['receipt'], [
            'mode' => VoxSchema::MODE_GOVERNED_EXECUTE,
            'risk_class' => VoxSchema::RISK_R4,
            'preview' => ['what_i_heard' => 'x'],
            'actions_available' => ['execute', 'edit_intent', 'save_as_note'],
        ]);

        $this->assertTrue($issued['request']['requires_literal_confirmation']);
        $this->assertNotEmpty($issued['request']['literal_confirmation_text']);
    }

    public function test_consume_with_valid_token_returns_ok_and_is_single_use(): void
    {
        $svc = $this->makeService();
        $pair = $this->makePair(VoxSchema::RISK_R2);

        $issued = $svc->issue($pair['intent'], $pair['receipt'], [
            'mode' => VoxSchema::MODE_GOVERNED_EXECUTE,
            'risk_class' => VoxSchema::RISK_R2,
            'preview' => ['what_i_heard' => 'x'],
            'actions_available' => ['execute'],
        ]);

        $first = $svc->consume(
            requestId: $issued['request']['request_id'],
            intentId: $pair['intent']['intent_id'],
            receiptId: $pair['receipt']['receipt_id'],
            decision: 'execute',
            confirmationToken: $issued['token'],
        );
        $this->assertTrue($first['ok']);

        $second = $svc->consume(
            requestId: $issued['request']['request_id'],
            intentId: $pair['intent']['intent_id'],
            receiptId: $pair['receipt']['receipt_id'],
            decision: 'execute',
            confirmationToken: $issued['token'],
        );
        $this->assertFalse($second['ok']);
        $this->assertSame('confirmation_unknown_or_expired', $second['code']);
    }

    public function test_consume_with_wrong_intent_id_returns_binding_mismatch(): void
    {
        $svc = $this->makeService();
        $pair = $this->makePair();

        $issued = $svc->issue($pair['intent'], $pair['receipt'], [
            'mode' => VoxSchema::MODE_GOVERNED_EXECUTE,
            'risk_class' => VoxSchema::RISK_R2,
            'preview' => ['what_i_heard' => 'x'],
            'actions_available' => ['execute'],
        ]);

        $result = $svc->consume(
            requestId: $issued['request']['request_id'],
            intentId: 'WRONG-INTENT',
            receiptId: $pair['receipt']['receipt_id'],
            decision: 'execute',
            confirmationToken: $issued['token'],
        );

        $this->assertFalse($result['ok']);
        $this->assertSame('confirmation_binding_mismatch', $result['code']);
    }

    public function test_consume_rejects_invalid_token_signature(): void
    {
        $svc = $this->makeService();
        $pair = $this->makePair();

        $issued = $svc->issue($pair['intent'], $pair['receipt'], [
            'mode' => VoxSchema::MODE_GOVERNED_EXECUTE,
            'risk_class' => VoxSchema::RISK_R2,
            'preview' => ['what_i_heard' => 'x'],
            'actions_available' => ['execute'],
        ]);

        $result = $svc->consume(
            requestId: $issued['request']['request_id'],
            intentId: $pair['intent']['intent_id'],
            receiptId: $pair['receipt']['receipt_id'],
            decision: 'execute',
            confirmationToken: 'hmac_sha256:bogus',
        );

        $this->assertFalse($result['ok']);
        $this->assertSame('confirmation_token_invalid', $result['code']);
    }

    public function test_consume_rejects_decision_not_in_actions_available(): void
    {
        $svc = $this->makeService();
        $pair = $this->makePair();

        $issued = $svc->issue($pair['intent'], $pair['receipt'], [
            'mode' => VoxSchema::MODE_GOVERNED_EXECUTE,
            'risk_class' => VoxSchema::RISK_R2,
            'preview' => ['what_i_heard' => 'x'],
            'actions_available' => ['execute'],
        ]);

        $result = $svc->consume(
            requestId: $issued['request']['request_id'],
            intentId: $pair['intent']['intent_id'],
            receiptId: $pair['receipt']['receipt_id'],
            decision: 'save_as_note',
            confirmationToken: $issued['token'],
        );

        $this->assertFalse($result['ok']);
        $this->assertSame('decision_not_in_actions_available', $result['code']);
    }

    public function test_r4_consume_requires_exact_literal_input_case_sensitive(): void
    {
        $svc = $this->makeService();
        $pair = $this->makePair(VoxSchema::RISK_R4);

        $issued = $svc->issue($pair['intent'], $pair['receipt'], [
            'mode' => VoxSchema::MODE_GOVERNED_EXECUTE,
            'risk_class' => VoxSchema::RISK_R4,
            'preview' => ['what_i_heard' => 'x'],
            'actions_available' => ['execute'],
            'literal_confirmation_text' => 'execute terminal propose',
        ]);

        // wrong case
        $wrong = $svc->consume(
            requestId: $issued['request']['request_id'],
            intentId: $pair['intent']['intent_id'],
            receiptId: $pair['receipt']['receipt_id'],
            decision: 'execute',
            confirmationToken: $issued['token'],
            literalConfirmationInput: 'Execute Terminal Propose',
        );
        $this->assertFalse($wrong['ok']);
        $this->assertSame('literal_confirmation_mismatch', $wrong['code']);
    }
}
