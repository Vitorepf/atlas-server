<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop\PlanExecution;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\FindingSlicePlannerService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\PlanExecution\BuildPlanDecomposerService;
use PHPUnit\Framework\TestCase;

/**
 * P1-DECOMP-INTEGRITY: structural integrity of the decomposed plan.
 *
 * Proves that a cyclic, dangling-edge, ambiguous-label, or misordered plan can
 * no longer certify decomposition_status=complete (real-or-blocked), while an
 * acyclic, fully-known, label-unique plan keeps `complete` with a stable hash.
 */
final class BuildPlanDecomposerDependencyIntegrityTest extends TestCase
{
    /**
     * Always-sliced fake planner so structure (not slicing) is under test.
     *
     * @return callable(array<string,mixed>):array<string,mixed>
     */
    private function slicedPlanner(): callable
    {
        return static function (array $input): array {
            return [
                'schema_version' => FindingSlicePlannerService::PLAN_SCHEMA,
                'finding_id' => (string) ($input['finding']['finding_id'] ?? ''),
                'decomposition_status' => FindingSlicePlannerService::STATUS_SLICED,
                'slices' => [[
                    'schema_version' => FindingSlicePlannerService::SLICE_SCHEMA,
                    'slice_id' => 'fake_slice_1',
                    'sequence' => 1,
                    'allowed_files' => ['app/Services/Foo.php'],
                    'owner' => FindingSlicePlannerService::OWNER_ATLAS_DEV,
                ]],
                'blockers' => [],
            ];
        };
    }

    private function service(): BuildPlanDecomposerService
    {
        $service = new BuildPlanDecomposerService;
        $service->setSlicePlannerCallableForTesting($this->slicedPlanner());

        return $service;
    }

    /**
     * @param  list<array{0:string,1:string,2:string,3:string}>  $rows
     */
    private function markdown(array $rows, string $section10 = ''): string
    {
        $table = "| Slice | Entrega | Aceite | Guarda |\n| --- | --- | --- | --- |\n";
        foreach ($rows as $r) {
            $table .= "| {$r[0]} | {$r[1]} | {$r[2]} | {$r[3]} |\n";
        }

        $seq = $section10 === '' ? '' : "\n## 10. Sequenciamento\n\n{$section10}\n";

        return "---\nid: PLAN-TEST\ntitle: Plan Test\n---\n\n## 6. Decomposicao em slices ordenados\n\n{$table}\n{$seq}";
    }

    private function defaultRows(): array
    {
        return [
            ['S1', 'Deliver foo', 'tests green', 'atlas_dev'],
            ['S2', 'Deliver bar', 'tests green', 'atlas_dev'],
            ['S3', 'Deliver baz', 'tests green', 'atlas_dev'],
        ];
    }

    private function dependsOnOf(array $plan, string $sliceId): array
    {
        foreach ($plan['slices'] as $slice) {
            if ($slice['slice_id'] === $sliceId) {
                return $slice['depends_on'];
            }
        }
        $this->fail("slice {$sliceId} not found");
    }

    public function test_cycle_forces_partial_with_offending_ids(): void
    {
        $plan = $this->service()->decompose([
            'build_plan_md' => $this->markdown($this->defaultRows(), 'S1 -> S2 -> S2 -> S1'),
        ]);

        $this->assertSame(BuildPlanDecomposerService::STATUS_PARTIAL, $plan['decomposition_status']);
        $cycleBlocker = $this->firstBlockerWithPrefix($plan, BuildPlanDecomposerService::BLOCKER_DEPENDENCY_CYCLE);
        $this->assertNotNull($cycleBlocker);
        // Offending slice ids appended after the canonical blocker token.
        $this->assertStringContainsString('S1', $cycleBlocker);
        $this->assertStringContainsString('S2', $cycleBlocker);
    }

    public function test_dangling_edge_forces_partial_and_drops_depends_on(): void
    {
        $plan = $this->service()->decompose([
            'build_plan_md' => $this->markdown($this->defaultRows(), 'S9 -> S2'),
        ]);

        $this->assertSame(BuildPlanDecomposerService::STATUS_PARTIAL, $plan['decomposition_status']);
        $this->assertContains(BuildPlanDecomposerService::BLOCKER_DANGLING_DEPENDENCY, $plan['blockers']);
        // The unknown S9 must NOT survive in S2.depends_on.
        $this->assertNotContains('S9', $this->dependsOnOf($plan, 'S2'));
    }

    public function test_duplicate_label_forces_partial_with_offending_labels(): void
    {
        $rows = $this->defaultRows();
        $rows[] = ['S1', 'Deliver foo duplicate', 'tests green', 'atlas_dev'];

        $plan = $this->service()->decompose([
            'build_plan_md' => $this->markdown($rows, 'S1 -> S2'),
        ]);

        $this->assertSame(BuildPlanDecomposerService::STATUS_PARTIAL, $plan['decomposition_status']);
        $dupBlocker = $this->firstBlockerWithPrefix($plan, BuildPlanDecomposerService::BLOCKER_DUPLICATE_SLICE_LABEL);
        $this->assertNotNull($dupBlocker);
        $this->assertStringContainsString('S1', $dupBlocker);
    }

    public function test_depends_on_is_always_subset_of_dependency_graph_nodes(): void
    {
        $plan = $this->service()->decompose([
            'build_plan_md' => $this->markdown($this->defaultRows(), 'S9 -> S2 -> S3'),
        ]);

        $nodes = [];
        foreach ($plan['dependency_graph'] as $edge) {
            $nodes[$edge['from_slice_id']] = true;
            $nodes[$edge['to_slice_id']] = true;
        }
        foreach ($plan['slices'] as $slice) {
            foreach ($slice['depends_on'] as $dep) {
                $this->assertArrayHasKey($dep, $nodes, "depends_on {$dep} must be a dependency_graph node");
            }
        }
    }

    public function test_sequence_uses_numeric_label_when_section_10_absent(): void
    {
        // Table row order S1, S3, S2 with no edges -> sequence by numeric label.
        $rows = [
            ['S1', 'a', 'tests green', 'atlas_dev'],
            ['S3', 'c', 'tests green', 'atlas_dev'],
            ['S2', 'b', 'tests green', 'atlas_dev'],
        ];
        $plan = $this->service()->decompose(['build_plan_md' => $this->markdown($rows)]);

        $seqOf = [];
        foreach ($plan['slices'] as $slice) {
            $seqOf[$slice['slice_id']] = $slice['sequence'];
        }
        $this->assertLessThan($seqOf['S3'], $seqOf['S2'], 'S2 must sequence before S3');
        $this->assertLessThan($seqOf['S2'], $seqOf['S1'], 'S1 must sequence first');
    }

    public function test_sequence_follows_topo_order_when_edges_present(): void
    {
        // S2 depends on S3 (S3 -> S2), so S3 must sequence before S2 despite the
        // numeric label suggesting otherwise.
        $rows = [
            ['S1', 'a', 'tests green', 'atlas_dev'],
            ['S2', 'b', 'tests green', 'atlas_dev'],
            ['S3', 'c', 'tests green', 'atlas_dev'],
        ];
        $plan = $this->service()->decompose([
            'build_plan_md' => $this->markdown($rows, 'S1 -> S3 -> S2'),
        ]);

        $this->assertSame(BuildPlanDecomposerService::STATUS_COMPLETE, $plan['decomposition_status']);
        $seqOf = [];
        foreach ($plan['slices'] as $slice) {
            $seqOf[$slice['slice_id']] = $slice['sequence'];
        }
        $this->assertLessThan($seqOf['S2'], $seqOf['S3'], 'S3 must sequence before its dependent S2');
    }

    public function test_acyclic_known_unique_plan_stays_complete_with_stable_hash(): void
    {
        $rows = [
            ['S1', 'a', 'tests green', 'atlas_dev'],
            ['S2', 'b', 'tests green', 'atlas_dev'],
            ['S3', 'c', 'tests green', 'atlas_dev'],
        ];
        $md = $this->markdown($rows, 'S1 -> S2 -> S3');

        $planA = $this->service()->decompose(['build_plan_md' => $md]);
        $planB = $this->service()->decompose(['build_plan_md' => $md]);

        $this->assertSame(BuildPlanDecomposerService::STATUS_COMPLETE, $planA['decomposition_status']);
        $this->assertSame([], $planA['blockers']);
        // Determinism preserved (MissionCanonicalHash sha256, total stable sort).
        $this->assertSame($planA['plan_hash'], $planB['plan_hash']);
        $this->assertStringStartsWith('sha256:', $planA['plan_hash']);
    }

    public function test_review_gated_rows_are_not_made_executable(): void
    {
        $rows = [
            ['S1', 'ready work [status=ready]', 'tests green', 'atlas_dev'],
            ['S2', 'review-only work [status=needs_operator_review auto_execution_allowed=false]', 'tests green', 'atlas_dev'],
        ];

        $plan = $this->service()->decompose([
            'build_plan_md' => $this->markdown($rows, 'S1 -> S2'),
        ]);

        $this->assertSame(BuildPlanDecomposerService::STATUS_COMPLETE, $plan['decomposition_status']);
        $this->assertSame(['S1'], array_column($plan['slices'], 'slice_id'));
        $this->assertSame([[
            'slice_id' => 'S2',
            'reason' => 'auto_execution_disallowed',
        ]], $plan['non_executable_slices']);
        $this->assertSame([], $plan['dependency_graph']);
    }

    public function test_done_rows_can_satisfy_context_without_reexecution(): void
    {
        $rows = [
            ['S1', 'already delivered [status=done]', 'tests green', 'atlas_dev'],
            ['S2', 'next ready work [status=ready]', 'tests green', 'atlas_dev'],
        ];

        $plan = $this->service()->decompose([
            'build_plan_md' => $this->markdown($rows, 'S1 -> S2'),
        ]);

        $this->assertSame(BuildPlanDecomposerService::STATUS_COMPLETE, $plan['decomposition_status']);
        $this->assertSame(['S2'], array_column($plan['slices'], 'slice_id'));
        $this->assertSame([[
            'slice_id' => 'S1',
            'reason' => 'status_done',
        ]], $plan['non_executable_slices']);
        $this->assertSame([], $this->dependsOnOf($plan, 'S2'));
    }

    public function test_plan_with_only_gated_rows_blocks_before_provider(): void
    {
        $rows = [
            ['S1', 'advisory work [status=needs_operator_review auto_execution_allowed=false]', 'tests green', 'atlas_dev'],
        ];

        $plan = $this->service()->decompose([
            'build_plan_md' => $this->markdown($rows, 'S1'),
        ]);

        $this->assertSame(BuildPlanDecomposerService::STATUS_BLOCKED, $plan['decomposition_status']);
        $this->assertContains(
            BuildPlanDecomposerService::BLOCKER_NO_EXECUTABLE_SLICE_AFTER_AUTHORITY_GATE,
            $plan['blockers'],
        );
        $this->assertSame([], $plan['slices']);
        $this->assertSame('S1', $plan['non_executable_slices'][0]['slice_id']);
    }

    public function test_gate_is_no_weaker_structural_defect_never_certifies_complete(): void
    {
        // Invariant guard: each structural defect must downgrade away from complete.
        $cyclic = $this->service()->decompose([
            'build_plan_md' => $this->markdown($this->defaultRows(), 'S1 -> S2 -> S1'),
        ]);
        $dangling = $this->service()->decompose([
            'build_plan_md' => $this->markdown($this->defaultRows(), 'S9 -> S2'),
        ]);
        $dupRows = $this->defaultRows();
        $dupRows[] = ['S2', 'dup', 'tests green', 'atlas_dev'];
        $duplicate = $this->service()->decompose([
            'build_plan_md' => $this->markdown($dupRows, 'S1 -> S2'),
        ]);

        foreach ([$cyclic, $dangling, $duplicate] as $plan) {
            $this->assertNotSame(BuildPlanDecomposerService::STATUS_COMPLETE, $plan['decomposition_status']);
        }
    }

    private function firstBlockerWithPrefix(array $plan, string $prefix): ?string
    {
        foreach ($plan['blockers'] as $blocker) {
            if (str_starts_with((string) $blocker, $prefix)) {
                return (string) $blocker;
            }
        }

        return null;
    }
}
