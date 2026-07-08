<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\NativeImplementation\AtlasSelfConstructionCompletionEvidenceHashService;
use App\Services\Ai\SelfConstruction\NativeImplementation\AtlasSelfConstructionRuntimePromotionClosurePackService;
use App\Services\Ai\SelfConstruction\NativeImplementation\AtlasSelfConstructionRuntimePromotionClosurePackVerifierService;
use PHPUnit\Framework\TestCase;

final class AtlasSelfConstructionRuntimePromotionClosurePackVerifierServiceTest extends TestCase
{
    private function verifier(): AtlasSelfConstructionRuntimePromotionClosurePackVerifierService
    {
        return new AtlasSelfConstructionRuntimePromotionClosurePackVerifierService;
    }

    /**
     * Builds a minimal, internally-consistent pack that satisfies every check in verify(),
     * with the opt-in minimal-evidence-contract fields (rollback_notes, per-row evidence_refs)
     * included and enforce_minimal_evidence_contract turned on.
     *
     * @param  array<string,mixed>  $overrides  applied to the top-level pack after hash computation inputs are set
     */
    private function validPack(array $overrides = []): array
    {
        $gapId = 'gap-1';
        $graduationHash = hash('sha256', 'graduation-evidence-'.$gapId);
        $matrixHash = hash('sha256', 'matrix');
        $basisHash = hash('sha256', 'basis');
        $closureBasisHash = hash('sha256', 'closure-basis');

        $promotedGapIds = [$gapId];
        $graduationHashes = [$gapId => $graduationHash];

        $preimage = [
            'receipt_id' => '<operator_runtime_promotion_receipt_id>',
            'signed_by' => '<operator>',
            'reason' => 'Operator reviewed the graduation candidates.',
            'runtime_gap_matrix_hash' => $matrixHash,
            'runtime_promotion_basis_hash' => $basisHash,
            'runtime_promotion_closure_basis_hash' => $closureBasisHash,
            'promoted_gap_ids' => $promotedGapIds,
            'graduation_evidence_hashes' => $graduationHashes,
            'receipt_hash' => '<operator_generated_64_hex_receipt_hash>',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'provider_call_allowed' => false,
            'token_spend_allowed' => false,
            'adapter_execution_allowed' => false,
            'self_programming_allowed' => false,
        ];
        $templateHash = (new AtlasSelfConstructionCompletionEvidenceHashService)->runtimePromotionReceiptHash($preimage);

        $pack = array_merge([
            'schema_version' => AtlasSelfConstructionRuntimePromotionClosurePackService::SCHEMA_VERSION,
            'runtime_gap_matrix_hash' => $matrixHash,
            'runtime_promotion_basis_hash' => $basisHash,
            'runtime_promotion_closure_basis_hash' => $closureBasisHash,
            'runtime_promotion_receipt_template_hash' => $templateHash,
            'promoted_gap_ids' => $promotedGapIds,
            'graduation_evidence_hashes' => $graduationHashes,
            'graduation_evidence' => [
                [
                    'gap_id' => $gapId,
                    'runtime_y_candidate' => true,
                    'runtime_y' => false,
                    'runtime_enabled' => false,
                    'graduation_evidence_hash' => $graduationHash,
                    'evidence_refs' => ['tests_or_gates_result'],
                ],
            ],
            'runtime_promotion_receipt_preimage' => $preimage,
            'rollback_notes' => 'Revert via git revert <commit>; no schema migration involved.',
            'changed_files' => ['app/Services/Ai/SelfConstruction/ExampleGraduatedService.php'],
            'runnable_gates' => [
                ['command' => '/opt/homebrew/bin/php artisan test tests/Unit/Ai/SelfConstruction/ExampleGraduatedServiceTest.php', 'passed' => true],
            ],
            'enforce_minimal_evidence_contract' => true,
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'provider_call_allowed' => false,
            'token_spend_allowed' => false,
            'adapter_execution_allowed' => false,
            'self_programming_allowed' => false,
            'runtime_write_allowed' => false,
            'ledger_write_allowed' => false,
        ], $overrides);

        $pack['closure_pack_hash'] = $this->canonicalHash($pack);

        return $pack;
    }

    private function canonicalHash(array $payload): string
    {
        unset(
            $payload['generated_at'],
            $payload['verified_at'],
            $payload['closure_pack_hash'],
            $payload['verification_hash'],
            $payload['runtime_promotion_receipt_preimage']['receipt_id'],
        );

        return hash('sha256', (string) json_encode($this->ksortRecursive($payload), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /** @param array<mixed,mixed> $value @return array<mixed,mixed> */
    private function ksortRecursive(array $value): array
    {
        $isAssoc = $value !== [] && array_keys($value) !== range(0, count($value) - 1);
        foreach ($value as $key => $entry) {
            if (is_array($entry)) {
                $value[$key] = $this->ksortRecursive($entry);
            }
        }
        if ($isAssoc) {
            ksort($value);
        }

        return $value;
    }

    private function violationCodes(array $result): array
    {
        return array_map(static fn (array $v): string => (string) ($v['code'] ?? ''), (array) $result['violations']);
    }

    // ── AC2: accepts a well-formed minimal closure pack ────────────────────────

    public function test_accepts_valid_pack_with_receipts_gates_and_rollback_notes(): void
    {
        $result = $this->verifier()->verify($this->validPack());

        $this->assertSame('passed', $result['status']);
        $this->assertSame(0, $result['violation_count']);
        $this->assertTrue($result['closure_pack_hash_matches']);
    }

    // ── AC4: compact passed/blockers report ────────────────────────────────────

    public function test_output_includes_blockers_alias_and_count(): void
    {
        $result = $this->verifier()->verify($this->validPack());

        $this->assertArrayHasKey('blockers', $result);
        $this->assertArrayHasKey('blocker_count', $result);
        $this->assertSame([], $result['blockers']);
        $this->assertSame(0, $result['blocker_count']);
    }

    public function test_blocked_pack_reports_blockers_matching_violations(): void
    {
        $pack = $this->validPack(['rollback_notes' => '']);
        $pack['closure_pack_hash'] = $this->canonicalHash($pack);

        $result = $this->verifier()->verify($pack);

        $this->assertSame('blocked', $result['status']);
        $this->assertSame($result['violations'], $result['blockers']);
        $this->assertGreaterThan(0, $result['blocker_count']);
    }

    // ── AC3: rejects missing rollback notes (when the minimal-evidence contract is enforced) ──

    public function test_rejects_pack_missing_rollback_notes(): void
    {
        $pack = $this->validPack(['rollback_notes' => '']);
        $pack['closure_pack_hash'] = $this->canonicalHash($pack);

        $result = $this->verifier()->verify($pack);

        $this->assertSame('blocked', $result['status']);
        $this->assertContains('rollback_notes_missing', $this->violationCodes($result));
    }

    // ── AC3: rejects a gap row missing evidence_refs ───────────────────────────

    public function test_rejects_gap_row_missing_evidence_refs(): void
    {
        $pack = $this->validPack();
        $pack['graduation_evidence'][0]['evidence_refs'] = [];
        $pack['closure_pack_hash'] = $this->canonicalHash($pack);

        $result = $this->verifier()->verify($pack);

        $this->assertSame('blocked', $result['status']);
        $this->assertContains('gap_row_missing_evidence_refs', $this->violationCodes($result));
    }

    public function test_evidence_refs_check_is_opt_in_and_does_not_affect_packs_without_the_flag(): void
    {
        $pack = $this->validPack(['enforce_minimal_evidence_contract' => false, 'rollback_notes' => '']);
        $pack['graduation_evidence'][0]['evidence_refs'] = [];
        $pack['closure_pack_hash'] = $this->canonicalHash($pack);

        $result = $this->verifier()->verify($pack);

        $this->assertSame('passed', $result['status'], 'without the opt-in flag, missing rollback_notes/evidence_refs must not block');
        $this->assertNotContains('gap_row_missing_evidence_refs', $this->violationCodes($result));
        $this->assertNotContains('rollback_notes_missing', $this->violationCodes($result));
    }

    // ── AC3: rejects a "bloated" pack promoting a gap id outside the declared matrix ──

    public function test_rejects_promoted_gap_id_not_in_declared_runtime_gap_matrix(): void
    {
        $pack = $this->validPack(['runtime_gap_matrix_gap_ids' => ['some-other-gap']]);
        $pack['closure_pack_hash'] = $this->canonicalHash($pack);

        $result = $this->verifier()->verify($pack);

        $this->assertSame('blocked', $result['status']);
        $this->assertContains('promoted_gap_id_not_in_runtime_gap_matrix', $this->violationCodes($result));
    }

    public function test_accepts_promoted_gap_id_present_in_declared_runtime_gap_matrix(): void
    {
        $pack = $this->validPack(['runtime_gap_matrix_gap_ids' => ['gap-1', 'gap-2']]);
        $pack['closure_pack_hash'] = $this->canonicalHash($pack);

        $result = $this->verifier()->verify($pack);

        $this->assertSame('passed', $result['status']);
    }

    public function test_matrix_gap_id_check_is_skipped_when_not_declared(): void
    {
        $result = $this->verifier()->verify($this->validPack());

        $this->assertNotContains('promoted_gap_id_not_in_runtime_gap_matrix', $this->violationCodes($result));
    }

    // ── existing invariants preserved (schema/hash/flag checks) ────────────────

    public function test_rejects_wrong_schema_version(): void
    {
        $pack = $this->validPack(['schema_version' => 'wrong.schema.v1']);
        $pack['closure_pack_hash'] = $this->canonicalHash($pack);

        $result = $this->verifier()->verify($pack);

        $this->assertSame('blocked', $result['status']);
        $this->assertContains('closure_pack_schema_version_invalid', $this->violationCodes($result));
    }

    public function test_rejects_tampered_closure_pack_hash(): void
    {
        $pack = $this->validPack();
        $pack['closure_pack_hash'] = str_repeat('0', 64);

        $result = $this->verifier()->verify($pack);

        $this->assertSame('blocked', $result['status']);
        $this->assertContains('closure_pack_hash_mismatch', $this->violationCodes($result));
        $this->assertFalse($result['closure_pack_hash_matches']);
    }

    public function test_rejects_runtime_enabling_flag(): void
    {
        $pack = $this->validPack(['dispatch_allowed' => true]);
        $pack['closure_pack_hash'] = $this->canonicalHash($pack);

        $result = $this->verifier()->verify($pack);

        $this->assertSame('blocked', $result['status']);
        $this->assertContains('runtime_enabling_flag_forbidden_in_closure_pack', $this->violationCodes($result));
    }

    public function test_verification_hash_is_present_and_64_hex(): void
    {
        $result = $this->verifier()->verify($this->validPack());

        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $result['verification_hash']);
    }

    // ── AC2/AC3: changed_files required when the minimal evidence contract is enforced ──

    public function test_rejects_pack_missing_changed_files_when_contract_enforced(): void
    {
        $pack = $this->validPack(['changed_files' => []]);
        $pack['closure_pack_hash'] = $this->canonicalHash($pack);

        $result = $this->verifier()->verify($pack);

        $this->assertSame('blocked', $result['status']);
        $this->assertContains('changed_files_missing', $this->violationCodes($result));
    }

    public function test_changed_files_check_is_opt_in(): void
    {
        $pack = $this->validPack(['enforce_minimal_evidence_contract' => false, 'rollback_notes' => '', 'changed_files' => [], 'runnable_gates' => []]);
        $pack['closure_pack_hash'] = $this->canonicalHash($pack);

        $result = $this->verifier()->verify($pack);

        $this->assertSame('passed', $result['status']);
        $this->assertNotContains('changed_files_missing', $this->violationCodes($result));
    }

    // ── AC3: rejects a bloated pack whose changed_files reach outside declared scope ──

    public function test_rejects_changed_file_outside_declared_scope(): void
    {
        $pack = $this->validPack(['declared_scope_files' => ['app/Services/Ai/SelfConstruction/ExampleGraduatedService.php']]);
        $pack['changed_files'][] = 'app/Services/Ai/UnrelatedDomain/SomethingElse.php';
        $pack['closure_pack_hash'] = $this->canonicalHash($pack);

        $result = $this->verifier()->verify($pack);

        $this->assertSame('blocked', $result['status']);
        $this->assertContains('closure_pack_includes_unrelated_file', $this->violationCodes($result));
    }

    public function test_accepts_changed_files_within_declared_scope(): void
    {
        $pack = $this->validPack(['declared_scope_files' => ['app/Services/Ai/SelfConstruction/ExampleGraduatedService.php']]);
        $pack['closure_pack_hash'] = $this->canonicalHash($pack);

        $result = $this->verifier()->verify($pack);

        $this->assertSame('passed', $result['status']);
    }

    public function test_scope_check_is_skipped_when_declared_scope_files_not_declared(): void
    {
        $result = $this->verifier()->verify($this->validPack());

        $this->assertNotContains('closure_pack_includes_unrelated_file', $this->violationCodes($result));
    }

    // ── AC2/AC3: runnable_gates required when enforced; any claimed-but-failed gate always blocks ──

    public function test_rejects_pack_missing_runnable_gates_when_contract_enforced(): void
    {
        $pack = $this->validPack(['runnable_gates' => []]);
        $pack['closure_pack_hash'] = $this->canonicalHash($pack);

        $result = $this->verifier()->verify($pack);

        $this->assertSame('blocked', $result['status']);
        $this->assertContains('runnable_gates_missing', $this->violationCodes($result));
    }

    public function test_runnable_gates_missing_check_is_opt_in(): void
    {
        $pack = $this->validPack(['enforce_minimal_evidence_contract' => false, 'rollback_notes' => '', 'changed_files' => [], 'runnable_gates' => []]);
        $pack['closure_pack_hash'] = $this->canonicalHash($pack);

        $result = $this->verifier()->verify($pack);

        $this->assertSame('passed', $result['status']);
        $this->assertNotContains('runnable_gates_missing', $this->violationCodes($result));
    }

    public function test_rejects_claimed_gate_that_did_not_pass_even_without_enforce_flag(): void
    {
        $pack = $this->validPack([
            'enforce_minimal_evidence_contract' => false,
            'runnable_gates' => [['command' => '/opt/homebrew/bin/php artisan test SomeTest.php', 'passed' => false]],
        ]);
        $pack['closure_pack_hash'] = $this->canonicalHash($pack);

        $result = $this->verifier()->verify($pack);

        $this->assertSame('blocked', $result['status']);
        $this->assertContains('runnable_gate_not_passed', $this->violationCodes($result));
    }
}
