<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ArchitectureCouncil;

use App\Services\Ai\SelfConstruction\ArchitectureCouncil\AtlasArchitectureCouncilInvariantExtractor;
use Tests\TestCase;

final class AtlasArchitectureCouncilInvariantExtractorTest extends TestCase
{
    public function test_task_fabric_contract_emits_canonical_invariants(): void
    {
        $verdict = (new AtlasArchitectureCouncilInvariantExtractor)->extract([
            'organ' => 'task_fabric',
            'separates_roles' => true,
            'owning_runtime' => 'atlas_native',
            'workspace_topology' => 'shared_local_main_with_scope_lock',
            'requires_evidence' => true,
            'allows_self_certification' => false,
        ]);

        $this->assertSame([], $verdict['blockers']);
        $ids = array_column($verdict['invariants'], 'invariant_id');
        $this->assertContains('separation_of_powers', $ids);
        $this->assertContains('atlas_native_ownership', $ids);
        $this->assertContains('shared_main_topology', $ids);
        $this->assertContains('evidence_required', $ids);
        $this->assertContains('no_self_certification', $ids);
        foreach ($verdict['invariants'] as $inv) {
            $this->assertSame('task_fabric', $inv['organ']);
            foreach (['must_hold', 'violation_effect', 'test_hint'] as $key) {
                $this->assertArrayHasKey($key, $inv);
            }
        }
    }

    public function test_verification_court_contract_emits_only_relevant_invariants(): void
    {
        $verdict = (new AtlasArchitectureCouncilInvariantExtractor)->extract([
            'organ' => 'verification_court',
            'separates_roles' => true,
            'requires_evidence' => true,
            'allows_self_certification' => false,
        ]);

        $ids = array_column($verdict['invariants'], 'invariant_id');
        $this->assertSame(['evidence_contract_presence', 'evidence_required', 'no_self_certification', 'separation_of_powers'], $ids, 'deterministic order by invariant_id ASC');
        $this->assertNotContains('atlas_native_ownership', $ids);
        $this->assertNotContains('shared_main_topology', $ids);
    }

    public function test_empty_contract_is_blocked(): void
    {
        $verdict = (new AtlasArchitectureCouncilInvariantExtractor)->extract([]);
        $this->assertSame([], $verdict['invariants']);
        $this->assertSame(['empty_contract'], $verdict['blockers']);
    }

    public function test_duplicate_invariant_ids_are_blocked(): void
    {
        $verdict = (new AtlasArchitectureCouncilInvariantExtractor)->extract([
            'organ' => 'maestro',
            'separates_roles' => true,
            'extra_invariants' => [
                ['invariant_id' => 'separation_of_powers', 'must_hold' => 'dup'],
            ],
        ]);

        $this->assertSame([], $verdict['invariants']);
        $this->assertStringContainsString('duplicate_invariant_ids:separation_of_powers', $verdict['blockers'][0]);
    }

    public function test_deterministic_ordering_by_invariant_id_ascending(): void
    {
        $verdict = (new AtlasArchitectureCouncilInvariantExtractor)->extract([
            'organ' => 'cortex',
            'separates_roles' => true,
            'owning_runtime' => 'atlas_native',
            'workspace_topology' => 'shared_local_main_with_scope_lock',
            'requires_evidence' => true,
        ]);

        $sorted = $verdict['invariants'];
        $original = $sorted;
        usort($original, static fn (array $a, array $b): int => strcmp((string) $a['invariant_id'], (string) $b['invariant_id']));
        $this->assertSame(array_column($sorted, 'invariant_id'), array_column($original, 'invariant_id'));
    }

    public function test_extraction_is_byte_identical_across_calls(): void
    {
        $svc = new AtlasArchitectureCouncilInvariantExtractor;
        $contract = ['organ' => 'merge_governor', 'separates_roles' => true, 'requires_evidence' => true];
        $this->assertSame(json_encode($svc->extract($contract)), json_encode($svc->extract($contract)));
    }

    public function test_atlas_native_ownership_emits_runtime_boundary_ownership_invariant(): void
    {
        $verdict = (new AtlasArchitectureCouncilInvariantExtractor)->extract([
            'organ' => 'scheduler',
            'owning_runtime' => 'atlas_native',
        ]);

        $ids = array_column($verdict['invariants'], 'invariant_id');
        $this->assertContains('atlas_native_ownership', $ids);
        $this->assertContains('runtime_boundary_ownership', $ids);
        $this->assertSame([], $verdict['blockers']);
    }

    public function test_evidence_required_emits_evidence_contract_presence_invariant(): void
    {
        $verdict = (new AtlasArchitectureCouncilInvariantExtractor)->extract([
            'organ' => 'evidence_collector',
            'requires_evidence' => true,
        ]);

        $ids = array_column($verdict['invariants'], 'invariant_id');
        $this->assertContains('evidence_required', $ids);
        $this->assertContains('evidence_contract_presence', $ids);
        $this->assertSame([], $verdict['blockers']);
    }

    public function test_invalid_organ_ownership_is_blocked(): void
    {
        foreach (['human', 'operator', 'external_provider', 'claude_code'] as $forbidden) {
            $verdict = (new AtlasArchitectureCouncilInvariantExtractor)->extract([
                'organ' => 'rogue_organ',
                'owning_runtime' => $forbidden,
            ]);
            $this->assertSame([], $verdict['invariants'], "owning_runtime=$forbidden must block");
            $this->assertContains('invalid_organ_ownership:'.$forbidden, $verdict['blockers']);
        }
    }

    public function test_self_certifying_contract_is_blocked(): void
    {
        $verdict = (new AtlasArchitectureCouncilInvariantExtractor)->extract([
            'organ' => 'certification_organ',
            'allows_self_certification' => true,
        ]);

        $this->assertSame([], $verdict['invariants']);
        $this->assertContains('self_certification_forbidden', $verdict['blockers']);
    }

    // ── AC2: scope boundary, dependency order, proof floor, rollback requirement ──

    public function test_scope_locked_emits_scope_boundary_invariant(): void
    {
        $verdict = (new AtlasArchitectureCouncilInvariantExtractor)->extract([
            'organ' => 'task_fabric',
            'scope_locked' => true,
        ]);

        $ids = array_column($verdict['invariants'], 'invariant_id');
        $this->assertContains('scope_boundary', $ids);
        $this->assertSame([], $verdict['blockers']);
    }

    public function test_declares_dependency_order_emits_dependency_order_invariant(): void
    {
        $verdict = (new AtlasArchitectureCouncilInvariantExtractor)->extract([
            'organ' => 'task_fabric',
            'declares_dependency_order' => true,
        ]);

        $ids = array_column($verdict['invariants'], 'invariant_id');
        $this->assertContains('dependency_order', $ids);
        $this->assertSame([], $verdict['blockers']);
    }

    public function test_requires_proof_with_positive_floor_emits_proof_floor_invariant(): void
    {
        $verdict = (new AtlasArchitectureCouncilInvariantExtractor)->extract([
            'organ' => 'verification_court',
            'requires_proof' => true,
            'proof_floor' => 0.8,
        ]);

        $ids = array_column($verdict['invariants'], 'invariant_id');
        $this->assertContains('proof_floor', $ids);
        $this->assertSame([], $verdict['blockers']);
    }

    // ── AC3/AC4: vague contract rejection — missing proof invariant ──────────

    public function test_requires_proof_without_proof_floor_is_a_vague_contract_and_blocked(): void
    {
        $verdict = (new AtlasArchitectureCouncilInvariantExtractor)->extract([
            'organ' => 'verification_court',
            'requires_proof' => true,
        ]);

        $this->assertSame([], $verdict['invariants']);
        $this->assertContains('vague_contract:missing_proof_floor', $verdict['blockers']);
    }

    public function test_requires_proof_with_zero_floor_is_a_vague_contract_and_blocked(): void
    {
        $verdict = (new AtlasArchitectureCouncilInvariantExtractor)->extract([
            'organ' => 'verification_court',
            'requires_proof' => true,
            'proof_floor' => 0,
        ]);

        $this->assertContains('vague_contract:missing_proof_floor', $verdict['blockers']);
    }

    // ── AC4: rollback invariant extraction ────────────────────────────────────

    public function test_requires_rollback_with_plan_ref_emits_rollback_requirement_invariant(): void
    {
        $verdict = (new AtlasArchitectureCouncilInvariantExtractor)->extract([
            'organ' => 'merge_governor',
            'requires_rollback' => true,
            'rollback_plan_ref' => 'rollback_plan:merge_governor_v1',
        ]);

        $ids = array_column($verdict['invariants'], 'invariant_id');
        $this->assertContains('rollback_requirement', $ids);
        $this->assertSame([], $verdict['blockers']);
    }

    public function test_requires_rollback_without_plan_ref_is_a_vague_contract_and_blocked(): void
    {
        $verdict = (new AtlasArchitectureCouncilInvariantExtractor)->extract([
            'organ' => 'merge_governor',
            'requires_rollback' => true,
        ]);

        $this->assertSame([], $verdict['invariants']);
        $this->assertContains('vague_contract:missing_rollback_plan_ref', $verdict['blockers']);
    }

    public function test_empty_must_hold_in_extra_invariants_is_blocked(): void
    {
        $verdict = (new AtlasArchitectureCouncilInvariantExtractor)->extract([
            'organ' => 'loose_organ',
            'extra_invariants' => [
                ['invariant_id' => 'my_check', 'must_hold' => ''],
            ],
        ]);

        $this->assertSame([], $verdict['invariants']);
        $this->assertContains('empty_must_hold:my_check', $verdict['blockers']);
    }
}
