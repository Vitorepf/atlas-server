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
        $this->assertSame(['evidence_required', 'no_self_certification', 'separation_of_powers'], $ids, 'deterministic order by invariant_id ASC');
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
}
