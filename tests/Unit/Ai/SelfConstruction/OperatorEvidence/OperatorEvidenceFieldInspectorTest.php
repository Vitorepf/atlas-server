<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\OperatorEvidence;

use App\Services\Ai\SelfConstruction\OperatorEvidence\OperatorEvidenceFieldInspector;
use Tests\TestCase;

class OperatorEvidenceFieldInspectorTest extends TestCase
{
    public function test_is_placeholder_value_empty(): void
    {
        self::assertTrue(OperatorEvidenceFieldInspector::isPlaceholderValue(''));
    }

    public function test_is_placeholder_value_angle_bracket(): void
    {
        self::assertTrue(OperatorEvidenceFieldInspector::isPlaceholderValue('<task>'));
    }

    public function test_is_placeholder_value_double_underscore(): void
    {
        self::assertTrue(OperatorEvidenceFieldInspector::isPlaceholderValue('__incomplete'));
    }

    public function test_is_placeholder_value_known_fragment(): void
    {
        self::assertTrue(OperatorEvidenceFieldInspector::isPlaceholderValue('this is a PLACEHOLDER'));
        self::assertTrue(OperatorEvidenceFieldInspector::isPlaceholderValue('TODO: fix later'));
        self::assertTrue(OperatorEvidenceFieldInspector::isPlaceholderValue('fake-value'));
    }

    public function test_is_placeholder_value_real_value(): void
    {
        self::assertFalse(OperatorEvidenceFieldInspector::isPlaceholderValue('real-hash-abc123'));
    }

    public function test_missing_provider_evidence_empty(): void
    {
        self::assertSame([], OperatorEvidenceFieldInspector::missingProviderEvidence([]));
    }

    public function test_missing_provider_evidence_detects_placeholders(): void
    {
        $smoke = [
            'provider_run_id' => '<placeholder>',
            'task_packet_id' => 'real-id',
            'provider_call_observed' => false,
            'token_spend_observed' => true,
        ];

        $missing = OperatorEvidenceFieldInspector::missingProviderEvidence($smoke);

        self::assertContains('missing_or_placeholder_provider_run_id', $missing);
        self::assertContains('missing_observation_flag_provider_call_observed', $missing);
    }

    public function test_missing_provider_evidence_all_present(): void
    {
        $smoke = [
            'provider_run_id' => 'real-run',
            'task_packet_id' => 'real-pkt',
            'observed_by' => 'operator',
            'approval_reason' => 'approved because tests pass',
            'cost_event_hash' => 'ce-hash',
            'work_product_manifest_hash' => 'wp-hash',
            'evidence_ledger_hash' => 'el-hash',
            'continuation_summary_hash' => 'cs-hash',
            'provider_response_hash' => 'pr-hash',
            'operator_approval_receipt_hash' => 'oa-hash',
            'provider_call_observed' => true,
            'token_spend_observed' => true,
            'claim_to_completion_observed' => true,
            'work_product_collected' => true,
            'operator_supplied_evidence' => true,
            'real_provider_run_observed_by_operator' => true,
        ];

        self::assertSame([], OperatorEvidenceFieldInspector::missingProviderEvidence($smoke));
    }

    public function test_human_context_from_options(): void
    {
        $result = OperatorEvidenceFieldInspector::humanContextFromOptions(
            options: [],
            runtimeGapMatrix: ['runtime_gap_matrix_hash' => 'rgm-hash'],
            runtimeReceipt: ['receipt_hash' => 'rr-hash'],
            realProviderSmoke: ['smoke_hash' => 'smoke-hash'],
            completionAudit: ['completion_audit_hash' => 'ca-hash'],
        );

        self::assertSame('ca-hash', $result['completion_audit_hash']);
        self::assertSame('rgm-hash', $result['runtime_gap_matrix_hash']);
        self::assertSame('rr-hash', $result['runtime_promotion_receipt_hash']);
        self::assertSame('smoke-hash', $result['real_provider_smoke_hash']);
    }

    public function test_stale_context_hashes_empty(): void
    {
        self::assertSame([], OperatorEvidenceFieldInspector::staleContextHashes([], 'a', 'b', 'c'));
    }

    public function test_stale_context_hashes_detects_mismatch(): void
    {
        $receipt = [
            'runtime_gap_matrix_hash' => 'old',
            'runtime_promotion_basis_hash' => 'same',
        ];

        $stale = OperatorEvidenceFieldInspector::staleContextHashes($receipt, 'new', 'same', '');

        self::assertContains('runtime_gap_matrix_hash', $stale);
        self::assertNotContains('runtime_promotion_basis_hash', $stale);
    }

    public function test_stale_context_hashes_ignores_empty_current(): void
    {
        $receipt = ['runtime_gap_matrix_hash' => 'some-value'];

        self::assertSame([], OperatorEvidenceFieldInspector::staleContextHashes($receipt, '', '', ''));
    }

    public function test_contains_placeholder_or_secret_detects_flat_placeholder(): void
    {
        self::assertTrue(OperatorEvidenceFieldInspector::containsPlaceholderOrSecret(['<placeholder>', 'real-hash']));
    }

    public function test_contains_placeholder_or_secret_detects_nested_placeholder(): void
    {
        self::assertTrue(OperatorEvidenceFieldInspector::containsPlaceholderOrSecret([
            'meta' => ['hash' => '__todo'],
        ]));
    }

    public function test_contains_placeholder_or_secret_detects_secret_in_nested_value(): void
    {
        self::assertTrue(OperatorEvidenceFieldInspector::containsPlaceholderOrSecret([
            'config' => ['nested' => 'TOKEN=abc123'],
        ]));
    }

    public function test_contains_placeholder_or_secret_returns_false_for_clean_nested_array(): void
    {
        self::assertFalse(OperatorEvidenceFieldInspector::containsPlaceholderOrSecret([
            'a' => ['b' => 'real-sha256-hash-abcdef123456'],
        ]));
    }
}
