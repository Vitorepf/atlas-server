<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\FinalOperatorClosureCorridor;

use App\Services\Ai\SelfConstruction\FinalOperatorClosureCorridor\OperatorSubmissionEnvelopeBuilder;
use Tests\TestCase;

class OperatorSubmissionEnvelopeBuilderTest extends TestCase
{
    public function test_next_required_envelope_returns_first_unpersisted(): void
    {
        $statuses = [
            'runtime_promotion_receipt' => 'persisted_runtime_promotion_receipt',
            'real_provider_smoke' => 'blocked_until_operator_smoke_payload_exists',
            'human_completion_receipt' => 'blocked',
        ];

        self::assertSame('real_provider_smoke', OperatorSubmissionEnvelopeBuilder::nextRequiredEnvelope($statuses));
    }

    public function test_next_required_envelope_returns_runtime_first(): void
    {
        $statuses = [
            'runtime_promotion_receipt' => 'blocked',
            'real_provider_smoke' => 'persisted_real_provider_smoke',
            'human_completion_receipt' => 'persisted_human_completion_receipt',
        ];

        self::assertSame('runtime_promotion_receipt', OperatorSubmissionEnvelopeBuilder::nextRequiredEnvelope($statuses));
    }

    public function test_next_required_envelope_returns_rerun_when_all_persisted(): void
    {
        $statuses = [
            'runtime_promotion_receipt' => 'persisted_runtime_promotion_receipt',
            'real_provider_smoke' => 'persisted_real_provider_smoke',
            'human_completion_receipt' => 'persisted_human_completion_receipt',
        ];

        self::assertSame('rerun_completion_audit', OperatorSubmissionEnvelopeBuilder::nextRequiredEnvelope($statuses));
    }

    public function test_envelope_summary_returns_expected_structure(): void
    {
        $envelope = OperatorSubmissionEnvelopeBuilder::operatorEnvelopeSummary(
            'test.schema.v1',
            'test_artifact',
            'test_status',
            ['receipt_hash' => 'abc123'],
            'receipt_hash',
            'php artisan test --persist',
            'php artisan test --endgame',
        );

        self::assertSame('test.schema.v1', $envelope['schema_version']);
        self::assertSame('test_artifact', $envelope['artifact']);
        self::assertSame('test_status', $envelope['status']);
        self::assertTrue($envelope['payload_present']);
        self::assertSame('abc123', $envelope['payload_hash']);
        self::assertSame('receipt_hash', $envelope['hash_field']);
        self::assertNotEmpty($envelope['payload_json_sha256']);
        self::assertNotEmpty($envelope['envelope_summary_hash']);
        self::assertFalse($envelope['can_persist_from_corridor']);
    }

    public function test_envelope_summary_empty_payload(): void
    {
        $envelope = OperatorSubmissionEnvelopeBuilder::operatorEnvelopeSummary(
            'test.schema.v1', 'art', 'blocked', [], 'hash', 'cmd', 'endgame',
        );

        self::assertFalse($envelope['payload_present']);
        self::assertSame('', $envelope['payload_json_sha256']);
        self::assertSame('', $envelope['payload_hash']);
    }

    public function test_submission_envelopes_blocked_when_empty(): void
    {
        $result = OperatorSubmissionEnvelopeBuilder::operatorSubmissionEnvelopes(
            options: [],
            completionAudit: [],
            completionEvidence: [],
            runtimeReceipt: [],
            realProviderSmoke: [],
            completionReceipt: [],
            runtimeReceiptReady: false,
            realProviderSmokeReady: false,
            realProviderSmokePersistedBeforeHumanReceiptCommand: false,
            humanReceiptReady: false,
        );

        self::assertSame('blocked_until_all_required_operator_envelopes_are_ready', $result['status']);
        self::assertSame(0, $result['ready_envelope_count']);
        self::assertSame(3, $result['required_envelope_count']);
        self::assertSame('runtime_promotion_receipt', $result['next_required_envelope']);
        self::assertArrayHasKey('operator_submission_envelopes_hash', $result);
    }

    public function test_submission_envelopes_all_ready_when_persisted(): void
    {
        $result = OperatorSubmissionEnvelopeBuilder::operatorSubmissionEnvelopes(
            options: [],
            completionAudit: [],
            completionEvidence: [],
            runtimeReceipt: ['receipt_hash' => 'a'],
            realProviderSmoke: ['smoke_hash' => 'b'],
            completionReceipt: ['receipt_hash' => 'c'],
            runtimeReceiptReady: true,
            realProviderSmokeReady: true,
            realProviderSmokePersistedBeforeHumanReceiptCommand: true,
            humanReceiptReady: true,
        );

        self::assertSame('all_required_operator_evidence_persisted', $result['status']);
        self::assertSame(3, $result['ready_envelope_count']);
        self::assertSame('rerun_completion_audit', $result['next_required_envelope']);
    }

    public function test_submission_envelopes_is_deterministic(): void
    {
        $args = [
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
        ];

        $a = OperatorSubmissionEnvelopeBuilder::operatorSubmissionEnvelopes(...$args);
        $b = OperatorSubmissionEnvelopeBuilder::operatorSubmissionEnvelopes(...$args);

        self::assertSame($a['operator_submission_envelopes_hash'], $b['operator_submission_envelopes_hash']);
    }

    public function test_submission_envelopes_includes_non_execution_guarantees(): void
    {
        $result = OperatorSubmissionEnvelopeBuilder::operatorSubmissionEnvelopes(
            options: [], completionAudit: [], completionEvidence: [], runtimeReceipt: [],
            realProviderSmoke: [], completionReceipt: [],
            runtimeReceiptReady: false, realProviderSmokeReady: false,
            realProviderSmokePersistedBeforeHumanReceiptCommand: false, humanReceiptReady: false,
        );

        self::assertNotEmpty($result['non_execution_guarantees']);
    }

    public function test_envelope_summary_with_evidence_context(): void
    {
        $ctx = ['completion_audit_hash' => 'xyz', 'runtime_gap_matrix_hash' => 'abc'];
        $envelope = OperatorSubmissionEnvelopeBuilder::operatorEnvelopeSummary(
            'v1', 'art', 'st', ['receipt_hash' => 'h'], 'receipt_hash', 'cmd', 'endgame', $ctx,
        );

        self::assertSame($ctx, $envelope['current_evidence_context']);
    }
}
