<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\NativeImplementation\AtlasSelfConstructionCompletionEvidenceHashService;
use App\Services\Ai\SelfConstruction\NativeImplementation\AtlasSelfConstructionRuntimePromotionEndgameVerifierService;
use PHPUnit\Framework\TestCase;

final class AtlasSelfConstructionRuntimePromotionEndgameVerifierServiceTest extends TestCase
{
    /** @return array<string, mixed> */
    private function syntheticMatrix(): array
    {
        $rows = [
            [
                'gap_id' => 'synthetic_gap_alpha',
                'runtime_y' => false,
                'graduation_evidence_hash' => str_repeat('a', 64),
            ],
        ];

        return [
            'rows' => $rows,
            'expected_runtime_gap_matrix_hash_for_promotion_receipt' => str_repeat('1', 64),
            'runtime_promotion_basis_hash' => str_repeat('2', 64),
            'runtime_promotion_closure_basis_hash' => str_repeat('3', 64),
        ];
    }

    /** @return array<string, mixed> */
    private function syntheticReceipt(array $matrix): array
    {
        $rows = (array) data_get($matrix, 'rows', []);
        $promotedGapIds = array_values(array_map(static fn (array $r): string => (string) ($r['gap_id'] ?? ''), $rows));
        $graduationHashes = [];
        foreach ($rows as $r) {
            $graduationHashes[(string) ($r['gap_id'] ?? '')] = (string) ($r['graduation_evidence_hash'] ?? '');
        }
        $receipt = [
            'receipt_id' => 'endgame-synthetic-receipt',
            'signed_by' => 'operator-endgame-synthetic-user',
            'reason' => 'Operator endgame synthetic reason exceeding thirty-two chars for verifier tests.',
            'runtime_gap_matrix_hash' => (string) data_get($matrix, 'expected_runtime_gap_matrix_hash_for_promotion_receipt', ''),
            'runtime_promotion_basis_hash' => (string) data_get($matrix, 'runtime_promotion_basis_hash', ''),
            'runtime_promotion_closure_basis_hash' => (string) data_get($matrix, 'runtime_promotion_closure_basis_hash', ''),
            'promoted_gap_ids' => $promotedGapIds,
            'graduation_evidence_hashes' => $graduationHashes,
            'receipt_hash' => '',
            'runtime_promotion_approved' => true,
            'operator_reviewed_runtime_graduations' => true,
            'no_runtime_autopromotion_acknowledged' => true,
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'provider_call_allowed' => false,
            'token_spend_allowed' => false,
            'adapter_execution_allowed' => false,
            'self_programming_allowed' => false,
        ];
        $receipt['receipt_hash'] = (new AtlasSelfConstructionCompletionEvidenceHashService)->runtimePromotionReceiptHash($receipt);

        return $receipt;
    }

    private function svc(): AtlasSelfConstructionRuntimePromotionEndgameVerifierService
    {
        return new AtlasSelfConstructionRuntimePromotionEndgameVerifierService;
    }

    // ── baseline: existing canonical behavior unaffected ───────────────────────

    public function test_canonical_payload_still_passes(): void
    {
        $matrix = $this->syntheticMatrix();
        $receipt = $this->syntheticReceipt($matrix);

        $result = $this->svc()->verify($receipt, $matrix);

        $this->assertSame('passed', $result['status']);
        $this->assertTrue($result['can_persist']);
        $this->assertSame(0, $result['violation_count']);
    }

    // ── AC2: rollback proof missing/mismatched blocks promotion ────────────────

    public function test_rollback_proof_not_enforced_when_not_requested(): void
    {
        $matrix = $this->syntheticMatrix();
        $receipt = $this->syntheticReceipt($matrix);

        $result = $this->svc()->verify($receipt, $matrix);

        $this->assertFalse($result['rollback_proof_missing']);
        $this->assertSame('passed', $result['status']);
    }

    public function test_missing_rollback_proof_blocks_when_expected_hash_supplied(): void
    {
        $matrix = $this->syntheticMatrix();
        $matrix['rollback_proof_hash'] = str_repeat('9', 64);
        $receipt = $this->syntheticReceipt($matrix);

        $result = $this->svc()->verify($receipt, $matrix);

        $this->assertTrue($result['rollback_proof_missing']);
        $this->assertSame('blocked', $result['status']);
        $this->assertFalse($result['can_persist']);
        $codes = array_column($result['violations'], 'code');
        $this->assertContains('rollback_proof_missing', $codes);
    }

    public function test_mismatched_rollback_proof_blocks(): void
    {
        $matrix = $this->syntheticMatrix();
        $matrix['rollback_proof_hash'] = str_repeat('9', 64);
        $receipt = $this->syntheticReceipt($matrix);
        $receipt['rollback_proof_hash'] = str_repeat('8', 64);

        $result = $this->svc()->verify($receipt, $matrix);

        $this->assertTrue($result['rollback_proof_mismatch']);
        $codes = array_column($result['violations'], 'code');
        $this->assertContains('rollback_proof_mismatch', $codes);
    }

    public function test_matching_rollback_proof_passes(): void
    {
        $matrix = $this->syntheticMatrix();
        $matrix['rollback_proof_hash'] = str_repeat('9', 64);
        $receipt = $this->syntheticReceipt($matrix);
        $receipt['rollback_proof_hash'] = str_repeat('9', 64);
        $receipt['receipt_hash'] = (new AtlasSelfConstructionCompletionEvidenceHashService)->runtimePromotionReceiptHash($receipt);

        $result = $this->svc()->verify($receipt, $matrix);

        $this->assertFalse($result['rollback_proof_missing']);
        $this->assertFalse($result['rollback_proof_mismatch']);
        $this->assertSame('passed', $result['status']);
    }

    // ── AC3: passed / blocked / warning sections with evidence refs ───────────

    public function test_warning_emitted_when_no_gap_matrix_supplied(): void
    {
        $receipt = $this->syntheticReceipt($this->syntheticMatrix());

        $result = $this->svc()->verify($receipt, []);

        $codes = array_column($result['warnings'], 'code');
        $this->assertContains('no_runtime_gap_matrix_supplied', $codes);
    }

    public function test_no_warning_when_gap_matrix_supplied(): void
    {
        $matrix = $this->syntheticMatrix();
        $receipt = $this->syntheticReceipt($matrix);

        $result = $this->svc()->verify($receipt, $matrix);

        $this->assertSame([], $result['warnings']);
    }

    public function test_evidence_refs_include_every_runtime_promotion_requirement(): void
    {
        $matrix = $this->syntheticMatrix();
        $receipt = $this->syntheticReceipt($matrix);

        $result = $this->svc()->verify($receipt, $matrix);

        foreach (['receipt_hash', 'runtime_gap_matrix_hash', 'runtime_promotion_basis_hash', 'runtime_promotion_closure_basis_hash', 'rollback_proof_hash'] as $key) {
            $this->assertArrayHasKey($key, $result['evidence_refs'], "missing evidence ref: {$key}");
        }
        $this->assertSame($receipt['receipt_hash'], $result['evidence_refs']['receipt_hash']);
    }

    public function test_empty_verification_has_warnings_and_evidence_refs_sections(): void
    {
        $result = $this->svc()->emptyVerification();

        $this->assertArrayHasKey('warnings', $result);
        $this->assertArrayHasKey('evidence_refs', $result);
        $this->assertSame('not_supplied', $result['status']);
    }

    // ── AC4: smallest next repair action for every blocker ─────────────────────

    public function test_every_violation_has_a_repair_action(): void
    {
        $receipt = ['receipt_id' => '', 'signed_by' => '', 'reason' => ''];

        $result = $this->svc()->verify($receipt, $this->syntheticMatrix());

        $this->assertNotEmpty($result['violations']);
        foreach ($result['violations'] as $violation) {
            $this->assertArrayHasKey('repair_action', $violation);
            $this->assertNotEmpty($violation['repair_action']);
        }
    }

    public function test_repair_action_names_the_specific_field_for_missing_field(): void
    {
        $receipt = ['receipt_id' => '', 'signed_by' => 'x', 'reason' => 'x'];

        $result = $this->svc()->verify($receipt, $this->syntheticMatrix());

        $missing = array_values(array_filter($result['violations'], static fn (array $v): bool => $v['code'] === 'required_field_missing' && $v['field'] === 'receipt_id'));
        $this->assertNotEmpty($missing);
        $this->assertStringContainsString('receipt_id', $missing[0]['repair_action']);
    }

    public function test_repair_action_names_the_gap_id_for_missing_graduation_hash(): void
    {
        $matrix = $this->syntheticMatrix();
        $receipt = $this->syntheticReceipt($matrix);
        $receipt['graduation_evidence_hashes'] = [];
        $receipt['receipt_hash'] = (new AtlasSelfConstructionCompletionEvidenceHashService)->runtimePromotionReceiptHash($receipt);

        $result = $this->svc()->verify($receipt, $matrix);

        $missing = array_values(array_filter($result['violations'], static fn (array $v): bool => $v['code'] === 'missing_graduation_hash'));
        $this->assertNotEmpty($missing);
        $this->assertStringContainsString('synthetic_gap_alpha', $missing[0]['repair_action']);
    }

    public function test_empty_verification_violation_has_repair_action(): void
    {
        $result = $this->svc()->emptyVerification();

        $this->assertNotEmpty($result['violations'][0]['repair_action']);
    }
}
