<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction\FinalOperatorClosureCorridor;

use App\Services\Ai\SelfConstruction\FinalOperatorClosureCorridor\OperatorSubmissionEnvelopeBuilder;
use Tests\TestCase;

/**
 * Focused contract test: proves ready_envelope_count/persisted_envelope_count reach 3 only
 * when runtime promotion, real provider smoke, and human completion receipt are all persisted;
 * proves nextRequiredEnvelope ordering, deterministic payload hashing, source_option_keys, and
 * that the builder never persists, calls a provider, spends tokens, dispatches, enables
 * runtime, signs for the operator, or promotes completion.
 */
final class OperatorSubmissionEnvelopeBuilderTest extends TestCase
{
    private function args(array $overrides = []): array
    {
        return array_merge([
            'options' => [],
            'completionAudit' => [],
            'completionEvidence' => [],
            'runtimeReceipt' => [],
            'realProviderSmoke' => [],
            'completionReceipt' => [],
            'runtimeReceiptReady' => false,
            'realProviderSmokeReady' => false,
            'realProviderSmokePersistedBeforeHumanReceiptCommand' => false,
            'humanReceiptReady' => false,
        ], $overrides);
    }

    public function test_ready_and_persisted_counts_reach_three_only_when_all_three_are_persisted(): void
    {
        $allReady = OperatorSubmissionEnvelopeBuilder::operatorSubmissionEnvelopes(...$this->args([
            'runtimeReceipt' => ['receipt_hash' => 'a'],
            'realProviderSmoke' => ['smoke_hash' => 'b'],
            'completionReceipt' => ['receipt_hash' => 'c'],
            'runtimeReceiptReady' => true,
            'realProviderSmokeReady' => true,
            'realProviderSmokePersistedBeforeHumanReceiptCommand' => true,
            'humanReceiptReady' => true,
        ]));

        self::assertSame(3, $allReady['ready_envelope_count']);
        self::assertSame(3, $allReady['persisted_envelope_count']);
        self::assertSame('all_required_operator_evidence_persisted', $allReady['status']);

        $onlyTwoReady = OperatorSubmissionEnvelopeBuilder::operatorSubmissionEnvelopes(...$this->args([
            'runtimeReceipt' => ['receipt_hash' => 'a'],
            'realProviderSmoke' => ['smoke_hash' => 'b'],
            'runtimeReceiptReady' => true,
            'realProviderSmokeReady' => true,
            'realProviderSmokePersistedBeforeHumanReceiptCommand' => true,
            'humanReceiptReady' => false,
        ]));

        self::assertSame(2, $onlyTwoReady['ready_envelope_count']);
        self::assertSame(2, $onlyTwoReady['persisted_envelope_count']);
        self::assertSame('blocked_until_all_required_operator_envelopes_are_ready', $onlyTwoReady['status']);
    }

    public function test_human_receipt_blocked_when_runtime_prerequisite_absent(): void
    {
        $result = OperatorSubmissionEnvelopeBuilder::operatorSubmissionEnvelopes(...$this->args([
            'completionReceipt' => ['receipt_hash' => 'c'],
            'runtimeReceiptReady' => false,
            'realProviderSmokeReady' => true,
            'realProviderSmokePersistedBeforeHumanReceiptCommand' => true,
            'humanReceiptReady' => false,
        ]));

        self::assertSame(
            'blocked_until_runtime_smoke_prior_persistence_and_evidence_context_are_green',
            $result['human_completion_receipt']['status'],
        );
    }

    public function test_human_receipt_blocked_when_smoke_prerequisite_absent(): void
    {
        $result = OperatorSubmissionEnvelopeBuilder::operatorSubmissionEnvelopes(...$this->args([
            'completionReceipt' => ['receipt_hash' => 'c'],
            'runtimeReceiptReady' => true,
            'realProviderSmokeReady' => false,
            'realProviderSmokePersistedBeforeHumanReceiptCommand' => false,
            'humanReceiptReady' => false,
        ]));

        self::assertSame(
            'blocked_until_runtime_smoke_prior_persistence_and_evidence_context_are_green',
            $result['human_completion_receipt']['status'],
        );
    }

    public function test_next_required_envelope_ordering_runtime_then_smoke_then_human_then_rerun(): void
    {
        self::assertSame('runtime_promotion_receipt', OperatorSubmissionEnvelopeBuilder::nextRequiredEnvelope([]));
        self::assertSame('real_provider_smoke', OperatorSubmissionEnvelopeBuilder::nextRequiredEnvelope([
            'runtime_promotion_receipt' => 'persisted_runtime_promotion_receipt',
        ]));
        self::assertSame('human_completion_receipt', OperatorSubmissionEnvelopeBuilder::nextRequiredEnvelope([
            'runtime_promotion_receipt' => 'persisted_runtime_promotion_receipt',
            'real_provider_smoke' => 'persisted_real_provider_smoke',
        ]));
        self::assertSame('rerun_completion_audit', OperatorSubmissionEnvelopeBuilder::nextRequiredEnvelope([
            'runtime_promotion_receipt' => 'persisted_runtime_promotion_receipt',
            'real_provider_smoke' => 'persisted_real_provider_smoke',
            'human_completion_receipt' => 'persisted_human_completion_receipt',
        ]));
    }

    public function test_payload_hash_is_deterministic_and_reflects_payload_content(): void
    {
        $a = OperatorSubmissionEnvelopeBuilder::operatorSubmissionEnvelopes(...$this->args([
            'runtimeReceipt' => ['receipt_hash' => 'a'],
        ]));
        $b = OperatorSubmissionEnvelopeBuilder::operatorSubmissionEnvelopes(...$this->args([
            'runtimeReceipt' => ['receipt_hash' => 'a'],
        ]));
        $different = OperatorSubmissionEnvelopeBuilder::operatorSubmissionEnvelopes(...$this->args([
            'runtimeReceipt' => ['receipt_hash' => 'z'],
        ]));

        self::assertSame($a['operator_submission_envelopes_hash'], $b['operator_submission_envelopes_hash']);
        self::assertNotSame($a['operator_submission_envelopes_hash'], $different['operator_submission_envelopes_hash']);
        self::assertSame(64, strlen($a['operator_submission_envelopes_hash']));
    }

    public function test_source_option_keys_reflect_supplied_payloads_and_options(): void
    {
        $result = OperatorSubmissionEnvelopeBuilder::operatorSubmissionEnvelopes(...$this->args([
            'options' => ['runtime_promotion_receipt_json' => '/tmp/x.json'],
            'runtimeReceipt' => ['receipt_hash' => 'a'],
            'completionReceipt' => ['receipt_hash' => 'c'],
        ]));

        self::assertContains('runtime_promotion_receipt', $result['source_option_keys']);
        self::assertContains('completion_receipt', $result['source_option_keys']);
        self::assertContains('runtime_promotion_receipt_json', $result['source_option_keys']);
        self::assertNotContains('real_provider_smoke', $result['source_option_keys']);
    }

    public function test_builder_never_persists_calls_provider_spends_tokens_dispatches_enables_runtime_signs_or_promotes(): void
    {
        $result = OperatorSubmissionEnvelopeBuilder::operatorSubmissionEnvelopes(...$this->args());

        self::assertContains('aggregated_envelopes_do_not_persist_receipts', $result['non_execution_guarantees']);
        self::assertContains('aggregated_envelopes_do_not_call_provider', $result['non_execution_guarantees']);
        self::assertContains('aggregated_envelopes_do_not_spend_tokens', $result['non_execution_guarantees']);
        self::assertContains('aggregated_envelopes_do_not_dispatch', $result['non_execution_guarantees']);
        self::assertContains('aggregated_envelopes_do_not_enable_runtime', $result['non_execution_guarantees']);
        self::assertContains('aggregated_envelopes_do_not_sign_for_operator', $result['non_execution_guarantees']);
        self::assertContains('aggregated_envelopes_do_not_promote_completion', $result['non_execution_guarantees']);

        foreach (['runtime_promotion_receipt', 'real_provider_smoke', 'human_completion_receipt'] as $key) {
            $envelope = $result[$key];
            self::assertFalse($envelope['can_persist_from_corridor']);
            foreach ($envelope['non_execution_guarantees'] as $guarantee) {
                self::assertTrue($guarantee);
            }
        }
    }
}
