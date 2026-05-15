<?php

namespace Tests\Feature\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AtlasSelfConstructionCompletionEvidenceHashService;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionHumanCompletionReceiptMutationGuardService;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionHumanCompletionReceiptPreflightService;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionHumanCompletionReceiptVerifierService;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionReadinessService;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionReservationRepository;
use Tests\TestCase;

final class AtlasSelfConstructionHumanCompletionReceiptCorridorTest extends TestCase
{
    public function test_preflight_blocks_when_non_human_completion_criteria_are_failed(): void
    {
        $preflight = $this->preflight()->build(['completion_audit' => $this->audit([
            'runtime_gap_matrix_all_runtime_y',
            'human_signed_os_complete_receipt_present',
        ])]);

        $this->assertSame('blocked', $preflight['status']);
        $this->assertSame(['runtime_gap_matrix_all_runtime_y'], $preflight['blocking_failures_before_human_signature']);
        $this->assertFalse((bool) $preflight['completion_claim_allowed']);
    }

    public function test_preflight_is_ready_only_when_human_receipt_is_final_missing_item(): void
    {
        $preflight = $this->preflight()->build(['completion_audit' => $this->audit([
            'human_signed_os_complete_receipt_present',
        ])]);

        $this->assertSame('ready_for_human_signature', $preflight['status']);
        $this->assertSame([], $preflight['blocking_failures_before_human_signature']);
        $this->assertSame('<operator>', $preflight['receipt_preimage']['signed_by']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $preflight['receipt_template_hash']);
    }

    public function test_verifier_rejects_missing_signer_and_reason(): void
    {
        $result = $this->verifier()->verify([], $this->context());

        $this->assertSame('blocked', $result['status']);
        $this->assertViolation($result, 'required_receipt_field_missing');
        $this->assertViolation($result, 'human_completion_receipt_signer_invalid_or_placeholder');
    }

    public function test_verifier_rejects_hash_mismatch(): void
    {
        $receipt = $this->validReceipt();
        $receipt['receipt_hash'] = str_repeat('0', 64);

        $result = $this->verifier()->verify($receipt, $this->context());

        $this->assertSame('blocked', $result['status']);
        $this->assertViolation($result, 'receipt_hash_mismatch');
    }

    public function test_verifier_rejects_fake_signer(): void
    {
        $receipt = $this->validReceipt(['signed_by' => 'codex']);
        $receipt['receipt_hash'] = (new AtlasSelfConstructionCompletionEvidenceHashService)->humanCompletionReceiptHash($receipt);

        $result = $this->verifier()->verify($receipt, $this->context());

        $this->assertSame('blocked', $result['status']);
        $this->assertViolation($result, 'human_completion_receipt_signer_invalid_or_placeholder');
    }

    public function test_verifier_rejects_context_hash_mismatch(): void
    {
        $result = $this->verifier()->verify($this->validReceipt(), ['completion_audit_hash' => str_repeat('b', 64)]);

        $this->assertSame('blocked', $result['status']);
        $this->assertViolation($result, 'context_hash_mismatch');
    }

    public function test_verifier_rejects_final_artifact_hash_mismatch(): void
    {
        $result = $this->verifier()->verify($this->validReceipt(), [
            'runtime_promotion_receipt_hash' => str_repeat('b', 64),
            'real_provider_smoke_hash' => str_repeat('c', 64),
        ]);

        $this->assertSame('blocked', $result['status']);
        $this->assertViolation($result, 'context_hash_mismatch');
        $this->assertContains('runtime_promotion_receipt_hash', array_column((array) $result['violations'], 'field'));
        $this->assertContains('real_provider_smoke_hash', array_column((array) $result['violations'], 'field'));
    }

    public function test_verifier_rejects_missing_final_artifact_hashes(): void
    {
        $receipt = $this->validReceipt();
        unset($receipt['runtime_promotion_receipt_hash'], $receipt['real_provider_smoke_hash']);
        $receipt['receipt_hash'] = (new AtlasSelfConstructionCompletionEvidenceHashService)->humanCompletionReceiptHash($receipt);

        $result = $this->verifier()->verify($receipt, $this->context());

        $this->assertSame('blocked', $result['status']);
        $this->assertViolation($result, 'required_receipt_field_missing');
        $this->assertViolation($result, 'required_evidence_hash_invalid');
    }

    public function test_verifier_rejects_completion_autopromotion_flag(): void
    {
        $receipt = $this->validReceipt(['completion_autopromoted' => true]);
        $receipt['receipt_hash'] = (new AtlasSelfConstructionCompletionEvidenceHashService)->humanCompletionReceiptHash($receipt);

        $result = $this->verifier()->verify($receipt, $this->context());

        $this->assertSame('blocked', $result['status']);
        $this->assertViolation($result, 'forbidden_flag_in_human_completion_receipt');
    }

    public function test_verifier_accepts_valid_human_completion_receipt(): void
    {
        $result = $this->verifier()->verify($this->validReceipt(), $this->context());

        $this->assertSame('passed', $result['status']);
        $this->assertSame(0, $result['violation_count']);
        $this->assertTrue((bool) $result['receipt_hash_matches_payload']);
        $this->assertTrue((bool) $result['completion_claim_allowed']);
    }

    public function test_mutation_guard_detects_audit_hash_change(): void
    {
        $before = $this->validReceipt();
        $after = $before;
        $after['completion_audit_hash'] = str_repeat('c', 64);

        $result = $this->mutationGuard()->compare($before, $after);

        $this->assertSame('blocked', $result['status']);
        $this->assertSame(1, $result['mutation_count']);
        $this->assertSame('completion_audit_hash', $result['mutations'][0]['field']);
    }

    public function test_mutation_guard_detects_signer_change(): void
    {
        $before = $this->validReceipt();
        $after = $before;
        $after['signed_by'] = 'Other Operator';

        $result = $this->mutationGuard()->compare($before, $after);

        $this->assertSame('blocked', $result['status']);
        $this->assertSame('signed_by', $result['mutations'][0]['field']);
    }

    public function test_mutation_guard_accepts_idempotent_replay(): void
    {
        $receipt = $this->validReceipt();

        $result = $this->mutationGuard()->compare($receipt, $receipt);

        $this->assertSame('passed', $result['status']);
        $this->assertTrue((bool) $result['guard_passed']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $result['mutation_guard_hash']);
    }

    private function preflight(): AtlasSelfConstructionHumanCompletionReceiptPreflightService
    {
        return new AtlasSelfConstructionHumanCompletionReceiptPreflightService(
            new AtlasSelfConstructionReadinessService(new AtlasSelfConstructionReservationRepository),
        );
    }

    private function verifier(): AtlasSelfConstructionHumanCompletionReceiptVerifierService
    {
        return new AtlasSelfConstructionHumanCompletionReceiptVerifierService;
    }

    private function mutationGuard(): AtlasSelfConstructionHumanCompletionReceiptMutationGuardService
    {
        return new AtlasSelfConstructionHumanCompletionReceiptMutationGuardService;
    }

    /** @param list<string> $failed */
    private function audit(array $failed): array
    {
        $hash = str_repeat('a', 64);

        return [
            'schema_version' => 'atlas.self_construction.os_completion_audit.v1',
            'status' => $failed === [] ? 'complete' : 'incomplete',
            'completion_audit_hash' => $hash,
            'failed_criteria' => $failed,
            'criteria' => [
                ['id' => 'release_dossier_green', 'passed' => true, 'evidence' => ['hash' => $hash]],
                ['id' => 'replay_diff_against_completion_snapshot_green', 'passed' => true, 'evidence' => ['diff_hash' => $hash]],
                ['id' => 'runtime_gap_matrix_all_runtime_y', 'passed' => ! in_array('runtime_gap_matrix_all_runtime_y', $failed, true), 'evidence' => ['runtime_gap_matrix_hash' => $hash, 'runtime_promotion_receipt_hash' => $hash]],
                ['id' => 'end_to_end_real_provider_smoke_green', 'passed' => ! in_array('end_to_end_real_provider_smoke_green', $failed, true), 'evidence' => ['smoke_hash' => $hash]],
                ['id' => 'certification_status_batch_green', 'passed' => true, 'evidence' => ['hash' => $hash]],
            ],
        ];
    }

    /** @param array<string, mixed> $overrides */
    private function validReceipt(array $overrides = []): array
    {
        $receipt = array_merge([
            'receipt_id' => 'operator-os-complete-test',
            'signed_by' => 'Vitore Operator',
            'reason' => 'Reviewed final completion evidence.',
            'completion_audit_hash' => str_repeat('a', 64),
            'release_dossier_hash' => str_repeat('a', 64),
            'replay_diff_hash' => str_repeat('a', 64),
            'runtime_gap_matrix_hash' => str_repeat('a', 64),
            'runtime_promotion_receipt_hash' => str_repeat('a', 64),
            'real_provider_smoke_hash' => str_repeat('a', 64),
            'certification_status_batch_hash' => str_repeat('a', 64),
            'receipt_hash' => '',
            'os_complete_approved' => true,
            'operator_reviewed_completion_audit' => true,
            'no_autopromotion_acknowledged' => true,
        ], $overrides);
        $receipt['receipt_hash'] = (new AtlasSelfConstructionCompletionEvidenceHashService)->humanCompletionReceiptHash($receipt);

        return $receipt;
    }

    private function context(): array
    {
        return [
            'completion_audit_hash' => str_repeat('a', 64),
            'release_dossier_hash' => str_repeat('a', 64),
            'replay_diff_hash' => str_repeat('a', 64),
            'runtime_gap_matrix_hash' => str_repeat('a', 64),
            'runtime_promotion_receipt_hash' => str_repeat('a', 64),
            'real_provider_smoke_hash' => str_repeat('a', 64),
            'certification_status_batch_hash' => str_repeat('a', 64),
        ];
    }

    private function assertViolation(array $result, string $code): void
    {
        $codes = array_map(static fn (array $violation): string => (string) ($violation['code'] ?? ''), (array) $result['violations']);

        $this->assertContains($code, $codes);
    }
}
