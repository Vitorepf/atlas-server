<?php

namespace Tests\Feature\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AtlasSelfConstructionReadinessService;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionReservationRepository;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionRuntimePromotionClosurePackService;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionRuntimePromotionClosurePackVerifierService;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class AtlasSelfConstructionRuntimePromotionClosurePackTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_closure_pack_is_ready_when_all_runtime_gap_candidates_exist(): void
    {
        $pack = $this->pack();

        $this->assertSame(AtlasSelfConstructionRuntimePromotionClosurePackService::SCHEMA_VERSION, $pack['schema_version']);
        $this->assertSame('ready_for_operator_signature', $pack['status']);
        $this->assertSame(4, $pack['runtime_gap_count']);
        $this->assertSame(4, $pack['runtime_y_candidate_count']);
        $this->assertSame(0, $pack['runtime_enabled_count']);
        $this->assertSame([], $pack['blockers']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $pack['closure_pack_hash']);
    }

    public function test_closure_pack_contains_current_runtime_matrix_and_promotion_preimage(): void
    {
        $pack = $this->pack();

        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $pack['runtime_gap_matrix_hash']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $pack['runtime_promotion_basis_hash']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $pack['runtime_promotion_closure_basis_hash']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $pack['runtime_promotion_receipt_template_hash']);
        $this->assertSame($pack['runtime_promotion_closure_basis_hash'], $pack['runtime_promotion_receipt_preimage']['runtime_promotion_closure_basis_hash']);
        $this->assertSame($pack['promoted_gap_ids'], $pack['runtime_promotion_receipt_preimage']['promoted_gap_ids']);
        $this->assertSame($pack['graduation_evidence_hashes'], $pack['runtime_promotion_receipt_preimage']['graduation_evidence_hashes']);
        $this->assertSame('<operator>', $pack['runtime_promotion_receipt_preimage']['signed_by']);
        $this->assertSame('<operator_generated_64_hex_receipt_hash>', $pack['runtime_promotion_receipt_preimage']['receipt_hash']);
        $this->assertContains('refresh_terminal_loop_operational_proof', $pack['operator_checklist']);
        $this->assertContains('persist_terminal_loop_operational_proof_binding', $pack['operator_checklist']);
        $this->assertContains('rerun_completion_audit_with_terminal_loop_operational_proof', $pack['operator_checklist']);
        $this->assertTrue((bool) $pack['terminal_loop_operational_proof_required_before_final_audit']);
        $this->assertStringContainsString('terminal-loop-operational-proof-status', (string) $pack['terminal_loop_operational_proof_command']);
        $this->assertStringContainsString('--persist-terminal-loop-operational-proof-binding', (string) $pack['terminal_loop_operational_proof_binding_persist_command']);
        $this->assertSame('storage/app/private/atlas/self-construction/operator-submissions/terminal-loop-operational-proof-binding.json', $pack['terminal_loop_operational_proof_canonical_binding_path']);
        $this->assertStringContainsString('--agent-control-plane-terminal-loop-operational-proof-json=', (string) $pack['completion_audit_with_terminal_loop_operational_proof_command']);
        $this->assertStringContainsString('@/path/to/terminal-loop-operational-proof-binding.json', (string) $pack['completion_audit_with_terminal_loop_operational_proof_command']);
        $this->assertStringContainsString('@storage/app/private/atlas/self-construction/operator-submissions/terminal-loop-operational-proof-binding.json', (string) $pack['effective_completion_audit_with_canonical_terminal_loop_operational_proof_command']);
    }

    public function test_closure_pack_hash_is_deterministic(): void
    {
        $service = $this->service();
        $a = $service->build(['runtime_gap_matrix' => $this->runtimeGapMatrix()]);
        $b = $service->build(['runtime_gap_matrix' => $this->runtimeGapMatrix()]);

        $this->assertSame($a['closure_pack_hash'], $b['closure_pack_hash']);
        $this->assertSame('<operator_runtime_promotion_receipt_id>', $a['runtime_promotion_receipt_preimage']['receipt_id']);
    }

    public function test_verifier_accepts_integral_closure_pack(): void
    {
        $result = $this->verifier()->verify($this->pack());

        $this->assertSame('passed', $result['status']);
        $this->assertSame(0, $result['violation_count']);
        $this->assertTrue($result['closure_pack_hash_matches']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $result['verification_hash']);
    }

    public function test_verifier_rejects_promoted_gap_ids_incomplete(): void
    {
        $pack = $this->pack();
        array_pop($pack['promoted_gap_ids']);

        $result = $this->verifier()->verify($pack);

        $this->assertSame('blocked', $result['status']);
        $this->assertViolation($result, 'promoted_gap_ids_do_not_match_graduation_evidence_rows');
        $this->assertViolation($result, 'closure_pack_hash_mismatch');
    }

    public function test_verifier_rejects_graduation_hash_mutation(): void
    {
        $pack = $this->pack();
        $gapId = (string) $pack['promoted_gap_ids'][0];
        $pack['graduation_evidence_hashes'][$gapId] = str_repeat('0', 64);

        $result = $this->verifier()->verify($pack);

        $this->assertSame('blocked', $result['status']);
        $this->assertViolation($result, 'graduation_evidence_hash_map_mismatch');
        $this->assertViolation($result, 'closure_pack_hash_mismatch');
    }

    public function test_verifier_rejects_receipt_preimage_closure_basis_mismatch(): void
    {
        $pack = $this->pack();
        $pack['runtime_promotion_receipt_preimage']['runtime_promotion_closure_basis_hash'] = str_repeat('1', 64);

        $result = $this->verifier()->verify($pack);

        $this->assertSame('blocked', $result['status']);
        $this->assertViolation($result, 'receipt_preimage_closure_basis_hash_mismatch');
        $this->assertViolation($result, 'closure_pack_hash_mismatch');
    }

    public function test_verifier_rejects_runtime_enabled_true(): void
    {
        $pack = $this->pack();
        $pack['graduation_evidence'][0]['runtime_enabled'] = true;

        $result = $this->verifier()->verify($pack);

        $this->assertSame('blocked', $result['status']);
        $this->assertViolation($result, 'runtime_enabled_before_operator_receipt');
    }

    public function test_verifier_rejects_runtime_y_true_before_receipt(): void
    {
        $pack = $this->pack();
        $pack['graduation_evidence'][0]['runtime_y'] = true;

        $result = $this->verifier()->verify($pack);

        $this->assertSame('blocked', $result['status']);
        $this->assertViolation($result, 'gap_row_already_runtime_y_without_closure_receipt');
    }

    public function test_verifier_rejects_fake_operator_signature_claim(): void
    {
        $pack = $this->pack();
        $pack['runtime_promotion_receipt_preimage']['signed_by'] = 'codex-autosigned';

        $result = $this->verifier()->verify($pack);

        $this->assertSame('blocked', $result['status']);
        $this->assertViolation($result, 'operator_signature_must_not_be_claimed_in_closure_pack');
    }

    public function test_verifier_rejects_claimed_operator_receipt_hash(): void
    {
        $pack = $this->pack();
        $pack['runtime_promotion_receipt_preimage']['receipt_hash'] = str_repeat('a', 64);

        $result = $this->verifier()->verify($pack);

        $this->assertSame('blocked', $result['status']);
        $this->assertViolation($result, 'operator_receipt_hash_must_not_be_claimed_in_closure_pack');
    }

    public function test_verifier_rejects_runtime_enabling_flags(): void
    {
        $pack = $this->pack();
        $pack['dispatch_allowed'] = true;

        $result = $this->verifier()->verify($pack);

        $this->assertSame('blocked', $result['status']);
        $this->assertViolation($result, 'runtime_enabling_flag_forbidden_in_closure_pack');
    }

    public function test_closure_pack_does_not_persist_or_enable_runtime(): void
    {
        $pack = $this->pack();

        $this->assertFalse((bool) $pack['execution_allowed']);
        $this->assertFalse((bool) $pack['dispatch_allowed']);
        $this->assertFalse((bool) $pack['provider_call_allowed']);
        $this->assertFalse((bool) $pack['token_spend_allowed']);
        $this->assertFalse((bool) $pack['adapter_execution_allowed']);
        $this->assertFalse((bool) $pack['self_programming_allowed']);
        $this->assertFalse(Storage::disk('local')->exists('atlas/self-construction/os-completion/runtime-promotion-receipts/registry.json'));
    }

    private function pack(): array
    {
        return $this->service()->build(['runtime_gap_matrix' => $this->runtimeGapMatrix()]);
    }

    private function service(): AtlasSelfConstructionRuntimePromotionClosurePackService
    {
        return new AtlasSelfConstructionRuntimePromotionClosurePackService(
            new AtlasSelfConstructionReadinessService(new AtlasSelfConstructionReservationRepository),
        );
    }

    private function verifier(): AtlasSelfConstructionRuntimePromotionClosurePackVerifierService
    {
        return new AtlasSelfConstructionRuntimePromotionClosurePackVerifierService;
    }

    private function assertViolation(array $result, string $code): void
    {
        $codes = array_map(static fn (array $violation): string => (string) ($violation['code'] ?? ''), (array) $result['violations']);

        $this->assertContains($code, $codes);
    }

    private function runtimeGapMatrix(): array
    {
        $gapIds = [
            'adapter_execution_runtime',
            'automatic_cost_import_runtime',
            'automatic_work_product_collection_runtime',
            'automatic_dispatch_scheduler_codex_real_invoker_post_start_receipt_contract_runtime',
        ];
        $rows = array_map(static fn (string $gapId, int $index): array => [
            'gap_id' => $gapId,
            'listed_as_gap' => true,
            'runtime_y' => false,
            'runtime_y_candidate' => true,
            'runtime_enabled' => false,
            'certification_status' => 'available',
            'evidence_hash' => hash('sha256', 'evidence-'.$gapId),
            'graduation_schema' => 'atlas.self_construction.test_graduation.v1',
            'graduation_status' => 'passed',
            'graduation_evidence_hash' => hash('sha256', 'graduation-'.$gapId),
            'promoted_by_runtime_receipt' => false,
            'required_promotion' => 'operator_signed_runtime_promotion_'.$index,
            'blockers' => ['runtime_not_promoted_even_though_graduation_candidate_may_exist'],
        ], $gapIds, array_keys($gapIds));

        return [
            'schema_version' => 'atlas.self_construction.runtime_gap_matrix.v1',
            'status' => 'blocked',
            'all_runtime_y' => false,
            'runtime_gap_matrix_hash' => hash('sha256', 'runtime-gap-matrix-fixture'),
            'runtime_promotion_basis_hash' => hash('sha256', 'runtime-promotion-basis-fixture'),
            'runtime_promotion_closure_basis_hash' => hash('sha256', 'runtime-promotion-closure-basis-fixture'),
            'runtime_gap_count' => count($gapIds),
            'runtime_y_candidate_count' => count($gapIds),
            'rows' => $rows,
        ];
    }
}
