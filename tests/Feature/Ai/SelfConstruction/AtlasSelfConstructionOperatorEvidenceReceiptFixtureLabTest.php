<?php

namespace Tests\Feature\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AtlasSelfConstructionCompletionEvidenceHashService;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionOperatorEvidenceReceiptFixtureLabService;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class AtlasSelfConstructionOperatorEvidenceReceiptFixtureLabTest extends TestCase
{
    public function test_no_input_returns_three_diagnostics_with_no_input_status(): void
    {
        $result = $this->lab()->build([]);

        $this->assertSame(AtlasSelfConstructionOperatorEvidenceReceiptFixtureLabService::SCHEMA_VERSION, $result['schema_version']);
        $this->assertSame(AtlasSelfConstructionOperatorEvidenceReceiptFixtureLabService::MODE, $result['mode']);
        $this->assertSame('available', $result['status']);
        $this->assertFalse((bool) $result['persistence_allowed_here']);
        $this->assertCount(3, $result['receipt_diagnostics']);

        $kinds = array_column((array) $result['receipt_diagnostics'], 'fixture_kind');
        $this->assertSame(['runtime_promotion_receipt', 'real_provider_smoke', 'human_completion_receipt'], $kinds);

        foreach ((array) $result['receipt_diagnostics'] as $diagnostic) {
            $this->assertFalse((bool) $diagnostic['input_present']);
            $this->assertSame('no_input', $diagnostic['status']);
            $this->assertSame('', $diagnostic['computed_hash']);
            $this->assertSame('', $diagnostic['submitted_hash']);
            $this->assertFalse((bool) $diagnostic['hash_matches']);
            $this->assertSame([], $diagnostic['missing_fields']);
            $this->assertSame([], $diagnostic['invalid_hash_fields']);
            $this->assertSame([], $diagnostic['placeholder_fields']);
            $this->assertSame([], $diagnostic['forbidden_flags_true']);
            $this->assertSame('', $diagnostic['verifier_status']);
            $this->assertSame(0, (int) $diagnostic['verifier_violation_count']);
            $this->assertSame([], $diagnostic['verifier_violations']);
            $this->assertFalse((bool) $diagnostic['persistence_allowed_here']);
        }
    }

    public function test_runtime_promotion_receipt_invalid_detects_missing_and_hash_mismatch(): void
    {
        $result = $this->lab()->build([
            'runtime_promotion_receipt' => [
                'receipt_id' => '',
                'signed_by' => '<operator>',
                'reason' => '',
                'runtime_gap_matrix_hash' => 'not-a-hash',
                'runtime_promotion_basis_hash' => '',
                'runtime_promotion_closure_basis_hash' => '',
                'receipt_hash' => str_repeat('0', 64),
                'promoted_gap_ids' => [],
            ],
        ]);

        $diagnostic = $this->diagnosticFor($result, 'runtime_promotion_receipt');
        $this->assertTrue((bool) $diagnostic['input_present']);
        $this->assertSame('invalid', $diagnostic['status']);
        $this->assertContains('receipt_id', $diagnostic['missing_fields']);
        $this->assertContains('reason', $diagnostic['missing_fields']);
        $this->assertContains('runtime_promotion_basis_hash', $diagnostic['missing_fields']);
        $this->assertContains('runtime_gap_matrix_hash', $diagnostic['invalid_hash_fields']);
        $this->assertContains('runtime_promotion_basis_hash', $diagnostic['invalid_hash_fields']);
        $this->assertContains('signed_by', $diagnostic['placeholder_fields']);
        $this->assertFalse((bool) $diagnostic['hash_matches']);
        $this->assertNotEmpty($diagnostic['verifier_violations']);
        $this->assertGreaterThan(0, (int) $diagnostic['verifier_violation_count']);
    }

    public function test_real_provider_smoke_invalid_detects_missing_provider_cost_work_product_and_continuation(): void
    {
        $result = $this->lab()->build([
            'real_provider_smoke' => [
                'kind' => 'wrong_kind',
                'status' => 'blocked',
                'provider_run_id' => '<provider_run_id>',
                'task_packet_id' => '',
                'observed_by' => '<operator>',
                'approval_reason' => '',
                'smoke_hash' => 'short',
                'operator_approval_receipt_hash' => '',
                'evidence_ledger_hash' => '',
                'work_product_manifest_hash' => '',
                'cost_event_hash' => '',
                'continuation_summary_hash' => '',
                'provider_response_hash' => '',
                'provider_call_observed' => false,
                'token_spend_observed' => false,
                'claim_to_completion_observed' => false,
                'work_product_collected' => false,
            ],
        ]);

        $diagnostic = $this->diagnosticFor($result, 'real_provider_smoke');
        $this->assertSame('invalid', $diagnostic['status']);
        $this->assertContains('task_packet_id', $diagnostic['missing_fields']);
        $this->assertContains('approval_reason', $diagnostic['missing_fields']);
        $this->assertContains('work_product_manifest_hash', $diagnostic['invalid_hash_fields']);
        $this->assertContains('cost_event_hash', $diagnostic['invalid_hash_fields']);
        $this->assertContains('continuation_summary_hash', $diagnostic['invalid_hash_fields']);
        $this->assertContains('provider_run_id', $diagnostic['placeholder_fields']);
        $this->assertContains('observed_by', $diagnostic['placeholder_fields']);
        $this->assertContains('task_packet_id', $diagnostic['placeholder_fields']);
        $this->assertContains('approval_reason', $diagnostic['placeholder_fields']);
        $this->assertGreaterThan(0, (int) $diagnostic['verifier_violation_count']);
    }

    public function test_human_completion_receipt_invalid_detects_placeholder_signer_and_missing_prereqs(): void
    {
        $result = $this->lab()->build([
            'completion_receipt' => [
                'receipt_id' => '',
                'signed_by' => 'codex',
                'reason' => '',
                'completion_audit_hash' => '',
                'release_dossier_hash' => '',
                'replay_diff_hash' => '',
                'runtime_gap_matrix_hash' => '',
                'runtime_promotion_receipt_hash' => '',
                'real_provider_smoke_hash' => '',
                'certification_status_batch_hash' => '',
                'receipt_hash' => str_repeat('0', 64),
                'os_complete_approved' => false,
                'operator_reviewed_completion_audit' => false,
                'no_autopromotion_acknowledged' => false,
            ],
        ]);

        $diagnostic = $this->diagnosticFor($result, 'human_completion_receipt');
        $this->assertSame('invalid', $diagnostic['status']);
        $this->assertContains('signed_by', $diagnostic['placeholder_fields']);
        $this->assertContains('completion_audit_hash', $diagnostic['invalid_hash_fields']);
        $this->assertContains('runtime_promotion_receipt_hash', $diagnostic['invalid_hash_fields']);
        $this->assertContains('real_provider_smoke_hash', $diagnostic['invalid_hash_fields']);
        $this->assertContains('receipt_id', $diagnostic['missing_fields']);
        $this->assertContains('reason', $diagnostic['missing_fields']);
        $codes = array_column((array) $diagnostic['verifier_violations'], 'code');
        $this->assertContains('human_completion_receipt_signer_invalid_or_placeholder', $codes);
    }

    public function test_forbidden_flags_true_are_reported(): void
    {
        $result = $this->lab()->build([
            'runtime_promotion_receipt' => [
                'receipt_id' => 'r',
                'signed_by' => 'Real Operator',
                'reason' => 'Reviewed runtime promotion evidence in full.',
                'runtime_gap_matrix_hash' => str_repeat('a', 64),
                'runtime_promotion_basis_hash' => str_repeat('a', 64),
                'runtime_promotion_closure_basis_hash' => str_repeat('a', 64),
                'receipt_hash' => str_repeat('a', 64),
                'execution_allowed' => true,
                'dispatch_allowed' => true,
                'provider_call_allowed' => true,
                'token_spend_allowed' => true,
                'adapter_execution_allowed' => true,
                'self_programming_allowed' => true,
            ],
            'real_provider_smoke' => [
                'provider_called_by_atlas' => true,
                'token_spent_by_atlas' => true,
                'dispatch_allowed' => true,
                'adapter_execution_allowed' => true,
                'self_programming_allowed' => true,
                'completion_claim_promoted_without_receipt' => true,
            ],
            'completion_receipt' => [
                'completion_autopromoted' => true,
                'execution_allowed' => true,
                'dispatch_allowed' => true,
                'provider_call_allowed' => true,
                'token_spend_allowed' => true,
                'adapter_execution_allowed' => true,
                'self_programming_allowed' => true,
            ],
        ]);

        $runtime = $this->diagnosticFor($result, 'runtime_promotion_receipt');
        $this->assertContains('execution_allowed', $runtime['forbidden_flags_true']);
        $this->assertContains('dispatch_allowed', $runtime['forbidden_flags_true']);
        $this->assertContains('self_programming_allowed', $runtime['forbidden_flags_true']);

        $smoke = $this->diagnosticFor($result, 'real_provider_smoke');
        $this->assertContains('provider_called_by_atlas', $smoke['forbidden_flags_true']);
        $this->assertContains('completion_claim_promoted_without_receipt', $smoke['forbidden_flags_true']);

        $completion = $this->diagnosticFor($result, 'human_completion_receipt');
        $this->assertContains('completion_autopromoted', $completion['forbidden_flags_true']);
        $this->assertContains('self_programming_allowed', $completion['forbidden_flags_true']);
    }

    public function test_computed_hash_is_deterministic_for_runtime_receipt(): void
    {
        $receipt = [
            'receipt_id' => 'runtime-test',
            'signed_by' => 'Real Operator',
            'reason' => 'Reviewed runtime promotion evidence in full.',
            'runtime_gap_matrix_hash' => str_repeat('a', 64),
            'runtime_promotion_basis_hash' => str_repeat('a', 64),
            'runtime_promotion_closure_basis_hash' => str_repeat('a', 64),
            'promoted_gap_ids' => [],
            'graduation_evidence_hashes' => [],
            'runtime_promotion_approved' => true,
            'operator_reviewed_runtime_graduations' => true,
            'no_runtime_autopromotion_acknowledged' => true,
        ];
        $receipt['receipt_hash'] = (new AtlasSelfConstructionCompletionEvidenceHashService)->runtimePromotionReceiptHash($receipt);

        $firstRun = $this->lab()->build(['runtime_promotion_receipt' => $receipt]);
        $secondRun = $this->lab()->build(['runtime_promotion_receipt' => $receipt]);

        $firstDiagnostic = $this->diagnosticFor($firstRun, 'runtime_promotion_receipt');
        $secondDiagnostic = $this->diagnosticFor($secondRun, 'runtime_promotion_receipt');

        $this->assertSame($firstDiagnostic['computed_hash'], $secondDiagnostic['computed_hash']);
        $this->assertTrue((bool) $firstDiagnostic['hash_matches']);
        $this->assertSame($receipt['receipt_hash'], $firstDiagnostic['computed_hash']);
    }

    public function test_synthetic_fixtures_are_marked_as_test_only_and_not_operator_evidence(): void
    {
        $result = $this->lab()->build([]);
        $catalog = (array) $result['synthetic_fixture_catalog'];

        $this->assertTrue((bool) $catalog['valid_fixture_is_test_only']);
        $this->assertTrue((bool) $catalog['not_operator_evidence']);
        $this->assertTrue((bool) $catalog['cannot_be_used_for_completion_claim']);
        $this->assertTrue((bool) $catalog['cannot_be_persisted_as_real_provider_smoke']);

        $kinds = array_column((array) $catalog['fixtures'], 'fixture_kind');
        $this->assertSame(['runtime_promotion_receipt', 'real_provider_smoke', 'human_completion_receipt'], $kinds);
        foreach ((array) $catalog['fixtures'] as $fixture) {
            $this->assertArrayHasKey('invalid_example', $fixture);
            $this->assertArrayHasKey('valid_example_test_only', $fixture);
        }
    }

    public function test_lab_does_not_persist_anything_to_storage(): void
    {
        Storage::fake('local');

        $this->lab()->build([
            'runtime_promotion_receipt' => ['receipt_id' => 'r'],
            'real_provider_smoke' => ['kind' => 'real_provider_packet_claim_to_completion'],
            'completion_receipt' => ['receipt_id' => 'c'],
        ]);

        $files = Storage::disk('local')->allFiles();
        $this->assertSame([], $files, 'Fixture lab must not persist any file.');
    }

    public function test_lab_payload_never_enables_provider_token_or_dispatch(): void
    {
        $result = $this->lab()->build([
            'runtime_promotion_receipt' => ['execution_allowed' => true, 'dispatch_allowed' => true, 'provider_call_allowed' => true],
            'real_provider_smoke' => ['provider_called_by_atlas' => true, 'token_spent_by_atlas' => true, 'dispatch_allowed' => true],
            'completion_receipt' => ['completion_autopromoted' => true],
        ]);

        $guarantees = (array) $result['non_execution_guarantees'];
        $this->assertTrue((bool) $guarantees['does_not_persist']);
        $this->assertTrue((bool) $guarantees['does_not_call_provider']);
        $this->assertTrue((bool) $guarantees['does_not_spend_tokens']);
        $this->assertTrue((bool) $guarantees['does_not_dispatch']);
        $this->assertTrue((bool) $guarantees['does_not_start_process']);
        $this->assertTrue((bool) $guarantees['does_not_enable_runtime']);
        $this->assertTrue((bool) $guarantees['does_not_promote_completion']);
        $this->assertTrue((bool) $guarantees['does_not_sign_for_operator']);

        $policy = (array) $result['anti_cheat_policy'];
        $this->assertTrue((bool) $policy['test_fixtures_are_not_operator_evidence']);
        $this->assertTrue((bool) $policy['synthetic_smoke_cannot_close_real_provider_blocker']);
        $this->assertTrue((bool) $policy['computed_hash_does_not_imply_operator_approval']);
        $this->assertTrue((bool) $policy['verifier_passed_in_test_storage_does_not_equal_real_completion']);
        $this->assertTrue((bool) $policy['no_completion_claim_from_fixture_lab']);
    }

    public function test_fixture_lab_hash_is_64_hex_and_stable_ignoring_generated_at(): void
    {
        $first = $this->lab()->build([]);
        $second = $this->lab()->build([]);

        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $first['fixture_lab_hash']);
        $this->assertSame($first['fixture_lab_hash'], $second['fixture_lab_hash']);
        $this->assertNotSame('', (string) $first['generated_at']);
        $this->assertNotSame('', (string) $second['generated_at']);
    }

    private function lab(): AtlasSelfConstructionOperatorEvidenceReceiptFixtureLabService
    {
        return new AtlasSelfConstructionOperatorEvidenceReceiptFixtureLabService;
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function diagnosticFor(array $result, string $kind): array
    {
        foreach ((array) $result['receipt_diagnostics'] as $diagnostic) {
            if (($diagnostic['fixture_kind'] ?? '') === $kind) {
                return (array) $diagnostic;
            }
        }
        $this->fail("Missing diagnostic for kind: {$kind}");
    }
}
