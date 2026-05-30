<?php

declare(strict_types=1);

namespace Tests\Unit\PlanExecution;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\FindingSlicePlannerService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\PlanExecution\BuildPlanDecomposerService;
use PHPUnit\Framework\TestCase;

final class BuildPlanDecomposerServiceTest extends TestCase
{
    /** @var list<array<string,mixed>> */
    private array $plannerCalls = [];

    /**
     * Build a recording fake planner callable. It NEVER re-implements slicing;
     * it records every call and returns the requested status + slices verbatim.
     *
     * @param  list<array<string,mixed>>  $slices
     * @return callable(array<string,mixed>):array<string,mixed>
     */
    private function fakePlanner(string $status = FindingSlicePlannerService::STATUS_SLICED, array $slices = []): callable
    {
        $this->plannerCalls = [];
        $resolved = $slices === [] ? [[
            'schema_version' => FindingSlicePlannerService::SLICE_SCHEMA,
            'slice_id' => 'fake_slice_1',
            'sequence' => 1,
            'allowed_files' => ['app/Services/Foo.php'],
            'owner' => FindingSlicePlannerService::OWNER_ATLAS_DEV,
        ]] : $slices;

        return function (array $input) use ($status, $resolved): array {
            $this->plannerCalls[] = $input;

            return [
                'schema_version' => FindingSlicePlannerService::PLAN_SCHEMA,
                'finding_id' => (string) ($input['finding']['finding_id'] ?? ''),
                'decomposition_status' => $status,
                'slices' => $status === FindingSlicePlannerService::STATUS_SLICED ? $resolved : [],
                'blockers' => $status === FindingSlicePlannerService::STATUS_SLICED ? [] : ['blocked_for_test'],
            ];
        };
    }

    private const REAL_DOC = __DIR__.'/../../../docs/engineering-knowledge-base/atlas-forge-real-autonomous-authority-build-plan.md';

    private function realMarkdown(): string
    {
        $contents = file_get_contents(self::REAL_DOC);
        $this->assertIsString($contents);

        return (string) $contents;
    }

    public function test_real_doc_parses_six_ordered_slices_with_id_and_title(): void
    {
        $service = new BuildPlanDecomposerService;
        $plan = $service->decompose(['build_plan_md' => $this->realMarkdown()]);

        $this->assertSame(BuildPlanDecomposerService::PLAN_SCHEMA, $plan['schema_version']);
        $this->assertSame('atlas-forge-real-autonomous-authority-build-plan', $plan['plan_id']);
        $this->assertNotSame('', $plan['plan_title']);

        $this->assertCount(6, $plan['slices']);
        $labels = array_map(static fn (array $s): string => $s['slice_id'], $plan['slices']);
        $this->assertSame(['S1', 'S2', 'S3', 'S4', 'S5', 'S6'], $labels);

        foreach ($plan['slices'] as $index => $slice) {
            $this->assertSame($index + 1, $slice['sequence']);
            // finding_id is set equal to slice_id (join key).
            $this->assertSame($slice['slice_id'], $slice['finding']['finding_id']);
            $this->assertNotEmpty($slice['acceptance_criteria']);
            $this->assertNotSame('', $slice['authority_guard']);
        }
    }

    public function test_real_doc_dependency_graph_comes_only_from_section_10_arrows(): void
    {
        $service = new BuildPlanDecomposerService;
        $plan = $service->decompose(['build_plan_md' => $this->realMarkdown()]);

        $edges = array_map(
            static fn (array $e): string => $e['from_slice_id'].'->'.$e['to_slice_id'],
            $plan['dependency_graph'],
        );

        // Section 10 text: "S1 -> S2 ... -> S3 ... S4 (autoridade) -> S5 ... -> S6".
        $this->assertContains('S1->S2', $edges);
        $this->assertContains('S2->S3', $edges);
        $this->assertContains('S4->S5', $edges);
        $this->assertContains('S5->S6', $edges);

        // depends_on derived from the same edges.
        $bySlice = [];
        foreach ($plan['slices'] as $slice) {
            $bySlice[$slice['slice_id']] = $slice['depends_on'];
        }
        $this->assertSame(['S1'], $bySlice['S2']);
        $this->assertSame([], $bySlice['S1']);
    }

    public function test_fake_planner_called_once_per_section_and_slices_embedded_verbatim(): void
    {
        $fakeSlices = [[
            'schema_version' => FindingSlicePlannerService::SLICE_SCHEMA,
            'slice_id' => 'verbatim_slice',
            'sequence' => 1,
            'allowed_files' => ['app/Services/Ai/Foo.php'],
            'owner' => FindingSlicePlannerService::OWNER_ATLAS_DEV,
        ]];
        $service = new BuildPlanDecomposerService;
        $service->setSlicePlannerCallableForTesting($this->fakePlanner(FindingSlicePlannerService::STATUS_SLICED, $fakeSlices));
        $plan = $service->decompose(['build_plan_md' => $this->realMarkdown()]);

        // Called exactly once per parsed section (6 sections).
        $this->assertCount(6, $this->plannerCalls);
        // Each call carries the synthetic finding whose id == slice id.
        foreach ($this->plannerCalls as $i => $call) {
            $this->assertSame('S'.($i + 1), $call['finding']['finding_id']);
        }
        // Slices embedded verbatim.
        foreach ($plan['slices'] as $slice) {
            $this->assertSame($fakeSlices, $slice['executable_slices']);
            $this->assertSame(FindingSlicePlannerService::STATUS_SLICED, $slice['planner_status']);
        }
    }

    public function test_status_complete_only_when_section_10_present_and_all_sliced(): void
    {
        $service = new BuildPlanDecomposerService;
        $service->setSlicePlannerCallableForTesting($this->fakePlanner(FindingSlicePlannerService::STATUS_SLICED));

        $plan = $service->decompose(['build_plan_md' => $this->realMarkdown()]);

        $this->assertSame(BuildPlanDecomposerService::STATUS_COMPLETE, $plan['decomposition_status']);
        $this->assertSame([], $plan['blockers']);
    }

    public function test_planner_block_downgrades_to_partial_not_complete(): void
    {
        $service = new BuildPlanDecomposerService;
        $service->setSlicePlannerCallableForTesting($this->fakePlanner(FindingSlicePlannerService::STATUS_BLOCKED));

        $plan = $service->decompose(['build_plan_md' => $this->realMarkdown()]);

        $this->assertSame(BuildPlanDecomposerService::STATUS_PARTIAL, $plan['decomposition_status']);
        $this->assertContains(BuildPlanDecomposerService::BLOCKER_PLANNER_BLOCKED, $plan['blockers']);
    }

    public function test_real_planner_blocks_empty_file_findings_yields_partial_never_complete(): void
    {
        // Anti-fake: the REAL planner cannot slice an empty-file synthetic
        // finding, so the honest result is partial — never complete.
        $service = new BuildPlanDecomposerService;
        $plan = $service->decompose(['build_plan_md' => $this->realMarkdown()]);

        $this->assertNotSame(BuildPlanDecomposerService::STATUS_COMPLETE, $plan['decomposition_status']);
        $this->assertSame(BuildPlanDecomposerService::STATUS_PARTIAL, $plan['decomposition_status']);
    }

    public function test_section_10_absent_yields_empty_depends_on_no_invented_chain_and_partial(): void
    {
        $md = $this->stripSection($this->realMarkdown(), '## 10.');
        $service = new BuildPlanDecomposerService;
        $service->setSlicePlannerCallableForTesting($this->fakePlanner(FindingSlicePlannerService::STATUS_SLICED));

        $plan = $service->decompose(['build_plan_md' => $md]);

        $this->assertSame(BuildPlanDecomposerService::STATUS_PARTIAL, $plan['decomposition_status']);
        $this->assertContains(BuildPlanDecomposerService::BLOCKER_SECTION_10_MISSING, $plan['blockers']);
        $this->assertSame([], $plan['dependency_graph']);
        foreach ($plan['slices'] as $slice) {
            $this->assertSame([], $slice['depends_on'], 'no N-1 chain may be invented');
        }
    }

    public function test_missing_section_6_yields_blocked(): void
    {
        $md = $this->stripSection($this->realMarkdown(), '## 6.');
        $service = new BuildPlanDecomposerService;
        $plan = $service->decompose(['build_plan_md' => $md]);

        $this->assertSame(BuildPlanDecomposerService::STATUS_BLOCKED, $plan['decomposition_status']);
        $this->assertContains(BuildPlanDecomposerService::BLOCKER_SECTION_6_MISSING, $plan['blockers']);
        $this->assertSame([], $plan['slices']);
    }

    public function test_empty_aceite_downgrades_to_partial(): void
    {
        $md = "---\nid: tiny-plan\ntitle: Tiny Plan\n---\n\n"
            ."## 6. Decomposicao em slices ordenados\n\n"
            ."| Slice | Entrega | Aceite | Guarda |\n"
            ."|---|---|---|---|\n"
            ."| S1 | Build the thing | criteria one; criteria two | guard one |\n"
            ."| S2 | Build the other thing |  | guard two |\n\n"
            ."## 10. Sequenciamento\n\nS1 -> S2.\n";

        $service = new BuildPlanDecomposerService;
        $service->setSlicePlannerCallableForTesting($this->fakePlanner(FindingSlicePlannerService::STATUS_SLICED));

        $plan = $service->decompose(['build_plan_md' => $md]);

        // Planner still fed for S2 (2 calls), but status downgrades to partial.
        $this->assertCount(2, $this->plannerCalls);
        $this->assertSame(BuildPlanDecomposerService::STATUS_PARTIAL, $plan['decomposition_status']);
        $this->assertContains(BuildPlanDecomposerService::BLOCKER_ACCEPTANCE_MISSING, $plan['blockers']);

        $s2 = $plan['slices'][1];
        $this->assertSame('S2', $s2['slice_id']);
        $this->assertSame([], $s2['acceptance_criteria']);
    }

    public function test_synthetic_finding_derives_bounded_file_scope_from_slice_text(): void
    {
        // A slice row that names a concrete service + config file must yield a
        // synthetic finding with a real affected_files scope (source + its
        // conventional test + the config file) so the planner can slice it
        // instead of blocking on "no bounded file scope". This is the fix that
        // lets the 24h loop execute backlog slices at all.
        $md = "---\nid: scope-plan\ntitle: Scope Plan\n---\n\n"
            ."## 6. Decomposicao em slices ordenados\n\n"
            ."| Slice | Entrega | Aceite | Guarda |\n"
            ."|---|---|---|---|\n"
            ."| S1 | Implement AtlasScopeProbeService + config/atlas.php scope_probe flag. [area=aaeos route=atlas_dev] | unit test green | structural |\n\n"
            ."## 10. Sequenciamento\n\nS1.\n";

        $service = new BuildPlanDecomposerService;
        $service->setSlicePlannerCallableForTesting($this->fakePlanner(FindingSlicePlannerService::STATUS_SLICED));

        $service->decompose(['build_plan_md' => $md]);

        $this->assertCount(1, $this->plannerCalls);
        $affected = $this->plannerCalls[0]['finding']['affected_files'] ?? [];
        $this->assertNotSame([], $affected, 'synthetic finding must carry a derived file scope');
        $this->assertContains('config/atlas.php', $affected);
        $this->assertContains('app/Services/Ai/Aaeos/AtlasScopeProbeService.php', $affected);
        $this->assertContains('tests/Unit/Ai/Aaeos/AtlasScopeProbeServiceTest.php', $affected);
    }

    public function test_no_source_yields_blocked_and_never_throws(): void
    {
        $service = new BuildPlanDecomposerService;
        $plan = $service->decompose([]);

        $this->assertSame(BuildPlanDecomposerService::STATUS_BLOCKED, $plan['decomposition_status']);
        $this->assertContains(BuildPlanDecomposerService::BLOCKER_NO_SOURCE, $plan['blockers']);
    }

    public function test_plan_hash_is_deterministic(): void
    {
        $service = new BuildPlanDecomposerService;
        $a = $service->decompose(['build_plan_md' => $this->realMarkdown()]);
        $b = $service->decompose(['build_plan_md' => $this->realMarkdown()]);

        $this->assertSame($a['plan_hash'], $b['plan_hash']);
        $this->assertStringStartsWith('sha256:', $a['plan_hash']);
        $this->assertStringStartsWith('sha256:', $a['source_doc_hash']);
    }

    private function stripSection(string $markdown, string $headingPrefix): string
    {
        $lines = preg_split('/\r\n|\r|\n/', $markdown) ?: [];
        $out = [];
        $skipping = false;
        foreach ($lines as $line) {
            if (str_starts_with($line, '## ')) {
                $skipping = str_starts_with($line, $headingPrefix);
            }
            if (! $skipping) {
                $out[] = $line;
            }
        }

        return implode("\n", $out);
    }
}
