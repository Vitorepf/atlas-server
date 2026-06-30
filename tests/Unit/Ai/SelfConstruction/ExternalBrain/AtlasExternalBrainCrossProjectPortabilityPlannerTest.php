<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainCrossProjectPortabilityPlanner;
use Tests\TestCase;

final class AtlasExternalBrainCrossProjectPortabilityPlannerTest extends TestCase
{
    private function svc(): AtlasExternalBrainCrossProjectPortabilityPlanner
    {
        return new AtlasExternalBrainCrossProjectPortabilityPlanner;
    }

    private function readyProject(array $overrides = []): array
    {
        return array_merge([
            'project_name' => 'sibling-project',
            'has_docs_context_sync' => true,
            'has_task_namespace' => true,
            'has_worker_routing' => true,
            'has_evidence_gates' => true,
            'has_workspace_isolation' => true,
        ], $overrides);
    }

    // ── output structure ─────────────────────────────────────────────────────

    public function test_output_has_required_keys(): void
    {
        $r = $this->svc()->plan($this->readyProject());

        foreach (['portable', 'readiness_score', 'missing_prerequisites', 'portability_risks', 'first_safe_scope'] as $key) {
            $this->assertArrayHasKey($key, $r, "missing key: {$key}");
        }
        $this->assertSame(AtlasExternalBrainCrossProjectPortabilityPlanner::SCHEMA, $r['schema']);
    }

    // ── AC1: missing docs/context sync → not portable, with setup steps ────────

    public function test_missing_docs_context_sync_is_not_portable(): void
    {
        $r = $this->svc()->plan($this->readyProject(['has_docs_context_sync' => false]));

        $this->assertFalse($r['portable']);
        $this->assertContains('docs_context_sync', $r['missing_prerequisites']);
    }

    public function test_missing_docs_context_sync_receives_required_setup_steps(): void
    {
        $r = $this->svc()->plan($this->readyProject(['has_docs_context_sync' => false]));

        $this->assertNotEmpty($r['required_setup_steps']);
        $this->assertContains('set_up_docs_context_sync', $r['required_setup_steps']);
    }

    // ── AC2: namespace, evidence gates, worker routing, isolation ready → portable=true ──

    public function test_all_prerequisites_ready_returns_portable_true(): void
    {
        $r = $this->svc()->plan($this->readyProject());

        $this->assertTrue($r['portable']);
        $this->assertSame([], $r['missing_prerequisites']);
        $this->assertSame(1.0, $r['readiness_score']);
    }

    public function test_namespace_routing_evidence_isolation_ready_with_docs_sync_returns_portable(): void
    {
        $r = $this->svc()->plan([
            'has_task_namespace' => true,
            'has_evidence_gates' => true,
            'has_worker_routing' => true,
            'has_workspace_isolation' => true,
            'has_docs_context_sync' => true,
        ]);

        $this->assertTrue($r['portable']);
    }

    // ── AC3: Atlas-specific assumptions surfaced as portability_risks ──────────

    public function test_atlas_specific_assumptions_are_listed_as_portability_risks(): void
    {
        $r = $this->svc()->plan($this->readyProject([
            'atlas_specific_assumptions' => ['hardcoded_atlas_paths', 'hermes_only_provider'],
        ]));

        $this->assertNotEmpty($r['portability_risks']);
        $risksText = implode(',', $r['portability_risks']);
        $this->assertStringContainsString('hardcoded_atlas_paths', $risksText);
        $this->assertStringContainsString('hermes_only_provider', $risksText);
    }

    public function test_atlas_specific_assumptions_block_portable_even_with_full_checklist(): void
    {
        $r = $this->svc()->plan($this->readyProject([
            'atlas_specific_assumptions' => ['hardcoded_atlas_paths'],
        ]));

        $this->assertFalse($r['portable'], 'an unresolved Atlas-only assumption must not be silently accepted as portable');
    }

    public function test_no_atlas_assumptions_yields_empty_portability_risks(): void
    {
        $r = $this->svc()->plan($this->readyProject());

        $this->assertSame([], $r['portability_risks']);
    }

    // ── AC4: output fields ───────────────────────────────────────────────────────

    public function test_readiness_score_reflects_partial_completion(): void
    {
        $r = $this->svc()->plan($this->readyProject([
            'has_docs_context_sync' => false,
            'has_task_namespace' => false,
        ]));

        // 3 of 5 prerequisites met.
        $this->assertEqualsWithDelta(0.6, $r['readiness_score'], 0.001);
    }

    public function test_first_safe_scope_is_full_when_portable(): void
    {
        $r = $this->svc()->plan($this->readyProject());

        $this->assertSame('full_self_construction_scope', $r['first_safe_scope']);
    }

    public function test_first_safe_scope_is_read_only_when_not_portable(): void
    {
        $r = $this->svc()->plan($this->readyProject(['has_evidence_gates' => false]));

        $this->assertStringContainsString('read_only', $r['first_safe_scope']);
        $this->assertStringContainsString('evidence_gates', $r['first_safe_scope']);
    }

    public function test_missing_prerequisites_are_sorted(): void
    {
        $r = $this->svc()->plan([
            'has_workspace_isolation' => false,
            'has_docs_context_sync' => false,
        ]);

        $sorted = $r['missing_prerequisites'];
        $copy = $sorted;
        sort($copy, SORT_STRING);
        $this->assertSame($copy, $sorted);
    }

    public function test_completely_unset_project_is_fully_not_portable(): void
    {
        $r = $this->svc()->plan([]);

        $this->assertFalse($r['portable']);
        $this->assertCount(5, $r['missing_prerequisites']);
        $this->assertSame(0.0, $r['readiness_score']);
    }

    // ── determinism ───────────────────────────────────────────────────────────

    public function test_plan_is_deterministic(): void
    {
        $project = $this->readyProject(['has_evidence_gates' => false, 'atlas_specific_assumptions' => ['x']]);
        $a = $this->svc()->plan($project);
        $b = $this->svc()->plan($project);

        $this->assertSame(json_encode($a, JSON_UNESCAPED_SLASHES), json_encode($b, JSON_UNESCAPED_SLASHES));
    }
}
