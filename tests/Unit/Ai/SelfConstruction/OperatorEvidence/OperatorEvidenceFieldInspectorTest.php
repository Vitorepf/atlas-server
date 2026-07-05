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

    // --- inspectEvidenceField structured output -----------------------

    public function test_inspect_evidence_field_missing_required_proof(): void
    {
        $result = OperatorEvidenceFieldInspector::inspectEvidenceField(
            'provider_run_id',
            '',
            ['required_proof_fields' => ['provider_run_id']],
        );

        self::assertSame('missing_required_proof', $result['classification']);
        self::assertSame('error', $result['severity']);
        self::assertArrayHasKey('field', $result);
        self::assertArrayHasKey('reason', $result);
        self::assertArrayHasKey('repair', $result);
    }

    public function test_inspect_evidence_field_placeholder(): void
    {
        $result = OperatorEvidenceFieldInspector::inspectEvidenceField(
            'provider_run_id',
            '<placeholder>',
        );

        self::assertSame('placeholder_or_self_declared', $result['classification']);
        self::assertSame('error', $result['severity']);
    }

    public function test_inspect_evidence_field_self_declared(): void
    {
        $result = OperatorEvidenceFieldInspector::inspectEvidenceField(
            'approval_reason',
            'fake-approval',
        );

        self::assertSame('placeholder_or_self_declared', $result['classification']);
        self::assertSame('error', $result['severity']);
    }

    public function test_inspect_evidence_field_secret_like(): void
    {
        $result = OperatorEvidenceFieldInspector::inspectEvidenceField(
            'api_key_plaintext',
            'API_KEY=sk-abc123',
        );

        self::assertSame('secret_like', $result['classification']);
        self::assertSame('error', $result['severity']);
    }

    public function test_inspect_evidence_field_stale_timestamp(): void
    {
        $oldTs = time() - 7_200; // 2 hours ago
        $result = OperatorEvidenceFieldInspector::inspectEvidenceField(
            'evidence_ts',
            'some-value',
            ['max_age_seconds' => 3_600, 'ts' => (string) $oldTs],
        );

        self::assertSame('stale_timestamp', $result['classification']);
        self::assertSame('warning', $result['severity']);
        self::assertStringContainsString('older than', $result['reason']);
    }

    public function test_inspect_evidence_field_fresh_timestamp_no_stale(): void
    {
        $now = time();
        $result = OperatorEvidenceFieldInspector::inspectEvidenceField(
            'evidence_ts',
            'some-value',
            ['max_age_seconds' => 3_600, 'ts' => (string) $now],
        );

        self::assertNotSame('stale_timestamp', $result['classification']);
    }

    public function test_inspect_evidence_field_proof_bearing(): void
    {
        $result = OperatorEvidenceFieldInspector::inspectEvidenceField(
            'provider_response_hash',
            'abc123def456',
            ['required_proof_fields' => ['provider_response_hash']],
        );

        self::assertSame('proof_bearing', $result['classification']);
        self::assertSame('info', $result['severity']);
    }

    public function test_inspect_evidence_field_volatile_hash_suffix(): void
    {
        $result = OperatorEvidenceFieldInspector::inspectEvidenceField(
            'evidence_ledger_hash',
            'real-hash-xyz',
        );

        self::assertSame('volatile', $result['classification']);
        self::assertSame('info', $result['severity']);
    }

    public function test_inspect_evidence_field_valid_value(): void
    {
        $result = OperatorEvidenceFieldInspector::inspectEvidenceField(
            'operator_name',
            'João da Silva',
        );

        self::assertSame('valid', $result['classification']);
        self::assertSame('info', $result['severity']);
        self::assertSame('', $result['repair']);
    }

    public function test_inspect_evidence_field_empty_optional(): void
    {
        $result = OperatorEvidenceFieldInspector::inspectEvidenceField(
            'optional_field',
            '',
        );

        self::assertSame('empty_optional', $result['classification']);
        self::assertSame('warning', $result['severity']);
    }

    public function test_inspect_evidence_field_return_shape(): void
    {
        $result = OperatorEvidenceFieldInspector::inspectEvidenceField('test_field', 'real-value');

        self::assertArrayHasKey('field', $result);
        self::assertArrayHasKey('classification', $result);
        self::assertArrayHasKey('severity', $result);
        self::assertArrayHasKey('reason', $result);
        self::assertArrayHasKey('repair', $result);
        self::assertIsString($result['classification']);
        self::assertIsString($result['severity']);
        self::assertIsString($result['reason']);
        self::assertIsString($result['repair']);
    }
}
