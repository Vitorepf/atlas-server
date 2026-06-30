<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainFinalReadinessMap;
use Tests\TestCase;

final class AtlasExternalBrainFinalReadinessMapTest extends TestCase
{
    private function map(): AtlasExternalBrainFinalReadinessMap
    {
        return new AtlasExternalBrainFinalReadinessMap();
    }

    /** All 12 critical areas fully proven with all evidence signals. */
    private function allProven(): array
    {
        $areas    = [
            'anti_goodhart', 'consolidation', 'learning', 'maestro', 'originator', 'runtime', 'task_fabric',
            'workers', 'gates', 'receipts', 'memory_docs_sync', 'model_amplifier',
        ];
        $evidence = [];
        foreach ($areas as $a) {
            $evidence[$a] = [
                'status'                  => 'proven',
                'evidence_count'          => 3,
                'unresolved_count'        => 0,
                'has_runnable_proof'      => true,
                'knowledge_sync_current'  => true,
                'operator_independence'   => true,
                'poison_blocker_open'     => false,
            ];
        }

        return $evidence;
    }

    // ── schema + structure ────────────────────────────────────────────────────

    public function test_schema_present(): void
    {
        $result = $this->map()->map([]);

        $this->assertSame(AtlasExternalBrainFinalReadinessMap::SCHEMA, $result['schema']);
    }

    public function test_output_has_required_keys(): void
    {
        $result = $this->map()->map($this->allProven());

        foreach (['schema', 'area_readiness', 'overall_status', 'blocking_areas', 'next_closure_action', 'final_readiness_percent', 'missing_evidence_by_area'] as $k) {
            $this->assertArrayHasKey($k, $result);
        }
    }

    public function test_each_area_readiness_entry_has_required_fields(): void
    {
        $result = $this->map()->map(['originator' => ['status' => 'proven']]);

        $this->assertCount(1, $result['area_readiness']);
        foreach (['area', 'status', 'is_critical', 'next_closure_action'] as $f) {
            $this->assertArrayHasKey($f, $result['area_readiness'][0]);
        }
    }

    // ── overall_status: final_ready ───────────────────────────────────────────

    public function test_all_critical_areas_proven_yields_final_ready(): void
    {
        $result = $this->map()->map($this->allProven());

        $this->assertSame(AtlasExternalBrainFinalReadinessMap::OVERALL_FINAL_READY, $result['overall_status']);
        $this->assertSame([], $result['blocking_areas']);
        $this->assertSame('none', $result['next_closure_action']);
    }

    public function test_empty_input_yields_final_ready_no_blocking_areas(): void
    {
        // No evidence = no critical areas in input → nothing to block.
        $result = $this->map()->map([]);

        $this->assertSame(AtlasExternalBrainFinalReadinessMap::OVERALL_FINAL_READY, $result['overall_status']);
    }

    // ── overall_status: not_ready ─────────────────────────────────────────────

    public function test_critical_area_missing_yields_not_ready(): void
    {
        $evidence               = $this->allProven();
        $evidence['originator'] = ['status' => 'missing'];

        $result = $this->map()->map($evidence);

        $this->assertSame(AtlasExternalBrainFinalReadinessMap::OVERALL_NOT_READY, $result['overall_status']);
        $this->assertContains('originator', $result['blocking_areas']);
    }

    public function test_critical_area_partially_proven_yields_not_ready(): void
    {
        $evidence            = $this->allProven();
        $evidence['maestro'] = ['status' => 'partially_proven'];

        $result = $this->map()->map($evidence);

        $this->assertSame(AtlasExternalBrainFinalReadinessMap::OVERALL_NOT_READY, $result['overall_status']);
        $this->assertContains('maestro', $result['blocking_areas']);
    }

    public function test_critical_area_proven_but_with_unresolved_count_yields_not_ready(): void
    {
        $evidence             = $this->allProven();
        $evidence['learning'] = [
            'status'                  => 'proven',
            'unresolved_count'        => 2,
            'has_runnable_proof'      => true,
            'knowledge_sync_current'  => true,
            'operator_independence'   => true,
        ];

        $result = $this->map()->map($evidence);

        $this->assertSame(AtlasExternalBrainFinalReadinessMap::OVERALL_NOT_READY, $result['overall_status']);
        $this->assertContains('learning', $result['blocking_areas']);
    }

    public function test_critical_area_duplicated_with_unresolved_yields_not_ready(): void
    {
        $evidence                = $this->allProven();
        $evidence['task_fabric'] = ['status' => 'duplicated', 'unresolved_count' => 3];

        $result = $this->map()->map($evidence);

        $this->assertSame(AtlasExternalBrainFinalReadinessMap::OVERALL_NOT_READY, $result['overall_status']);
    }

    public function test_critical_area_overgrown_yields_not_ready(): void
    {
        $evidence            = $this->allProven();
        $evidence['runtime'] = ['status' => 'overgrown', 'unresolved_count' => 1];

        $result = $this->map()->map($evidence);

        $this->assertSame(AtlasExternalBrainFinalReadinessMap::OVERALL_NOT_READY, $result['overall_status']);
    }

    // ── is_critical ───────────────────────────────────────────────────────────

    public function test_auto_critical_areas_are_flagged_critical(): void
    {
        $autoCritical = [
            'originator', 'task_fabric', 'maestro', 'learning', 'anti_goodhart', 'runtime', 'consolidation',
            'workers', 'gates', 'receipts', 'memory_docs_sync', 'model_amplifier',
        ];
        $evidence     = [];
        foreach ($autoCritical as $a) {
            $evidence[$a] = ['status' => 'proven'];
        }

        $result = $this->map()->map($evidence);

        foreach ($result['area_readiness'] as $entry) {
            $this->assertTrue($entry['is_critical'], "area {$entry['area']} must be auto-critical");
        }
    }

    public function test_non_auto_critical_area_is_not_critical_by_default(): void
    {
        $result = $this->map()->map([
            'custom_area' => ['status' => 'missing'],
        ]);

        $entry = $result['area_readiness'][0];
        $this->assertFalse($entry['is_critical']);
    }

    public function test_non_auto_critical_area_flagged_critical_in_evidence_is_critical(): void
    {
        $result = $this->map()->map([
            'custom_area' => ['status' => 'missing', 'critical' => true],
        ]);

        $entry = $result['area_readiness'][0];
        $this->assertTrue($entry['is_critical']);
    }

    public function test_non_critical_area_missing_does_not_block_final_ready(): void
    {
        // Only proven critical areas → final_ready even if a non-critical area is missing.
        $evidence               = $this->allProven();
        $evidence['bonus_area'] = ['status' => 'missing'];  // not critical

        $result = $this->map()->map($evidence);

        $this->assertSame(AtlasExternalBrainFinalReadinessMap::OVERALL_FINAL_READY, $result['overall_status']);
    }

    // ── per-area next_closure_action ──────────────────────────────────────────

    public function test_missing_area_closure_action_is_seed_evidence(): void
    {
        $result = $this->map()->map(['originator' => ['status' => 'missing']]);

        $entry = $result['area_readiness'][0];
        $this->assertSame('seed_evidence_for:originator', $entry['next_closure_action']);
    }

    public function test_partially_proven_area_closure_action_is_complete_proof(): void
    {
        $result = $this->map()->map(['task_fabric' => ['status' => 'partially_proven']]);

        $entry = $result['area_readiness'][0];
        $this->assertSame('complete_proof_for:task_fabric', $entry['next_closure_action']);
    }

    public function test_duplicated_area_with_unresolved_closure_action_is_resolve_duplicates(): void
    {
        $result = $this->map()->map(['maestro' => ['status' => 'duplicated', 'unresolved_count' => 2]]);

        $entry = $result['area_readiness'][0];
        $this->assertSame('resolve_duplicates_in:maestro', $entry['next_closure_action']);
    }

    public function test_overgrown_area_with_unresolved_closure_action_is_consolidate(): void
    {
        $result = $this->map()->map(['runtime' => ['status' => 'overgrown', 'unresolved_count' => 1]]);

        $entry = $result['area_readiness'][0];
        $this->assertSame('consolidate_overgrown:runtime', $entry['next_closure_action']);
    }

    public function test_proven_area_with_all_evidence_closure_action_is_none(): void
    {
        $result = $this->map()->map(['learning' => [
            'status'                  => 'proven',
            'unresolved_count'        => 0,
            'has_runnable_proof'      => true,
            'knowledge_sync_current'  => true,
            'operator_independence'   => true,
            'poison_blocker_open'     => false,
        ]]);

        $entry = $result['area_readiness'][0];
        $this->assertSame('none', $entry['next_closure_action']);
    }

    // ── global next_closure_action priority ───────────────────────────────────

    public function test_missing_area_takes_priority_over_partially_proven_in_global_action(): void
    {
        $evidence                = $this->allProven();
        $evidence['originator']  = ['status' => 'missing'];
        $evidence['task_fabric'] = ['status' => 'partially_proven'];

        $result = $this->map()->map($evidence);

        $this->assertStringStartsWith('seed_evidence_for:', $result['next_closure_action']);
    }

    public function test_global_next_closure_action_is_none_when_final_ready(): void
    {
        $result = $this->map()->map($this->allProven());

        $this->assertSame('none', $result['next_closure_action']);
    }

    // ── 95%-honesty gate: evidence signals ───────────────────────────────────

    public function test_critical_area_proven_but_missing_runnable_proof_blocks_final_ready(): void
    {
        $evidence               = $this->allProven();
        $evidence['originator'] = [
            'status'                  => 'proven',
            'unresolved_count'        => 0,
            'has_runnable_proof'      => false,
            'knowledge_sync_current'  => true,
            'operator_independence'   => true,
        ];

        $result = $this->map()->map($evidence);

        $this->assertSame(AtlasExternalBrainFinalReadinessMap::OVERALL_NOT_READY, $result['overall_status']);
        $this->assertContains('originator', $result['blocking_areas']);
    }

    public function test_critical_area_proven_but_missing_knowledge_sync_blocks_final_ready(): void
    {
        $evidence            = $this->allProven();
        $evidence['maestro'] = [
            'status'                  => 'proven',
            'unresolved_count'        => 0,
            'has_runnable_proof'      => true,
            'knowledge_sync_current'  => false,
            'operator_independence'   => true,
        ];

        $result = $this->map()->map($evidence);

        $this->assertSame(AtlasExternalBrainFinalReadinessMap::OVERALL_NOT_READY, $result['overall_status']);
        $this->assertContains('maestro', $result['blocking_areas']);
    }

    public function test_critical_area_proven_but_missing_operator_independence_blocks_final_ready(): void
    {
        $evidence             = $this->allProven();
        $evidence['learning'] = [
            'status'                  => 'proven',
            'unresolved_count'        => 0,
            'has_runnable_proof'      => true,
            'knowledge_sync_current'  => true,
            'operator_independence'   => false,
        ];

        $result = $this->map()->map($evidence);

        $this->assertSame(AtlasExternalBrainFinalReadinessMap::OVERALL_NOT_READY, $result['overall_status']);
        $this->assertContains('learning', $result['blocking_areas']);
    }

    public function test_critical_area_with_poison_blocker_open_blocks_final_ready(): void
    {
        $evidence            = $this->allProven();
        $evidence['runtime'] = [
            'status'                  => 'proven',
            'unresolved_count'        => 0,
            'has_runnable_proof'      => true,
            'knowledge_sync_current'  => true,
            'operator_independence'   => true,
            'poison_blocker_open'     => true,
        ];

        $result = $this->map()->map($evidence);

        $this->assertSame(AtlasExternalBrainFinalReadinessMap::OVERALL_NOT_READY, $result['overall_status']);
        $this->assertContains('runtime', $result['blocking_areas']);
    }

    public function test_missing_evidence_by_area_reports_missing_signals(): void
    {
        $evidence                   = $this->allProven();
        $evidence['consolidation']  = [
            'status'                  => 'proven',
            'unresolved_count'        => 0,
            'has_runnable_proof'      => false,
            'knowledge_sync_current'  => false,
            'operator_independence'   => true,
        ];

        $result = $this->map()->map($evidence);

        $this->assertArrayHasKey('consolidation', $result['missing_evidence_by_area']);
        $gaps = $result['missing_evidence_by_area']['consolidation'];
        $this->assertContains('has_runnable_proof',     $gaps);
        $this->assertContains('knowledge_sync_current', $gaps);
        $this->assertNotContains('operator_independence', $gaps);
    }

    public function test_missing_evidence_by_area_empty_when_all_areas_fully_ready(): void
    {
        $result = $this->map()->map($this->allProven());

        $this->assertSame([], $result['missing_evidence_by_area']);
    }

    public function test_final_readiness_percent_100_when_all_critical_areas_fully_ready(): void
    {
        $result = $this->map()->map($this->allProven());

        $this->assertSame(100.0, $result['final_readiness_percent']);
    }

    public function test_final_readiness_percent_reflects_proportion_of_fully_ready_critical_areas(): void
    {
        // 11 of 12 critical areas fully proven; 1 missing runnable proof.
        $evidence               = $this->allProven();
        $evidence['originator'] = [
            'status'                  => 'proven',
            'unresolved_count'        => 0,
            'has_runnable_proof'      => false,
            'knowledge_sync_current'  => true,
            'operator_independence'   => true,
        ];

        $result = $this->map()->map($evidence);

        // 11/12 ≈ 91.67%
        $this->assertEqualsWithDelta(91.67, $result['final_readiness_percent'], 0.1);
        $this->assertSame(AtlasExternalBrainFinalReadinessMap::OVERALL_NOT_READY, $result['overall_status']);
    }

    public function test_proven_area_missing_evidence_signals_closure_action_is_add_evidence(): void
    {
        $result = $this->map()->map(['anti_goodhart' => [
            'status'                  => 'proven',
            'unresolved_count'        => 0,
            'has_runnable_proof'      => false,
            'knowledge_sync_current'  => true,
            'operator_independence'   => true,
        ]]);

        $entry = $result['area_readiness'][0];
        $this->assertSame('add_evidence_for:anti_goodhart', $entry['next_closure_action']);
    }

    public function test_non_critical_missing_evidence_signals_do_not_appear_in_missing_evidence_by_area(): void
    {
        $result = $this->map()->map([
            'custom_non_critical' => [
                'status'             => 'proven',
                'has_runnable_proof' => false,
            ],
        ]);

        $this->assertArrayNotHasKey('custom_non_critical', $result['missing_evidence_by_area']);
    }

    // ── determinism ───────────────────────────────────────────────────────────

    public function test_identical_input_yields_identical_output(): void
    {
        $evidence               = $this->allProven();
        $evidence['originator'] = ['status' => 'missing'];

        $this->assertSame(
            $this->map()->map($evidence),
            $this->map()->map($evidence),
        );
    }

    // ── new Self-Construction OS domains ──────────────────────────────────────

    public function test_workers_domain_missing_blocks_final_ready(): void
    {
        $evidence = $this->allProven();
        $evidence['workers'] = ['status' => 'missing'];

        $result = $this->map()->map($evidence);

        $this->assertContains('workers', $result['blocking_areas']);
        $this->assertSame(AtlasExternalBrainFinalReadinessMap::OVERALL_NOT_READY, $result['overall_status']);
    }

    public function test_gates_receipts_and_memory_docs_sync_are_auto_critical(): void
    {
        $result = $this->map()->map([
            'gates'            => ['status' => 'proven'],
            'receipts'         => ['status' => 'proven'],
            'memory_docs_sync' => ['status' => 'proven'],
        ]);

        foreach ($result['area_readiness'] as $entry) {
            $this->assertTrue($entry['is_critical'], "area {$entry['area']} must be auto-critical");
        }
    }

    public function test_model_amplifier_domain_missing_blocks_final_ready(): void
    {
        $evidence = $this->allProven();
        $evidence['model_amplifier'] = ['status' => 'partially_proven'];

        $result = $this->map()->map($evidence);

        $this->assertContains('model_amplifier', $result['blocking_areas']);
    }
}
