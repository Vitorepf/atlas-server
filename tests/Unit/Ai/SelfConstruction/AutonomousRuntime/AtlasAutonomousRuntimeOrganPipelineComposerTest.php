<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\AutonomousRuntime;

use App\Services\Ai\SelfConstruction\AutonomousRuntime\AtlasAutonomousRuntimeOrganPipelineComposer;
use PHPUnit\Framework\TestCase;

/**
 * Proves AtlasAutonomousRuntimeOrganPipelineComposer: complete organ facts ⇒ plan_status=ready with 10
 * ordered stages in canonical order; missing organ facts ⇒ plan_status=blocked with
 * missing_organ:<name> blockers; ordering is deterministic across runs.
 */
final class AtlasAutonomousRuntimeOrganPipelineComposerTest extends TestCase
{
    private function allOrgans(): array
    {
        $out = [];
        foreach (AtlasAutonomousRuntimeOrganPipelineComposer::ORGAN_ORDER as $organ) {
            $out[$organ] = ['observed_at_unix' => 1, 'snapshot' => $organ];
        }

        return $out;
    }

    public function test_complete_plan_emits_ten_stages_in_canonical_order(): void
    {
        $r = (new AtlasAutonomousRuntimeOrganPipelineComposer)->compose($this->allOrgans());

        $this->assertSame(AtlasAutonomousRuntimeOrganPipelineComposer::STATUS_READY, $r['plan_status']);
        $this->assertCount(10, $r['ordered_stages']);
        $organs = array_column($r['ordered_stages'], 'organ');
        $this->assertSame(AtlasAutonomousRuntimeOrganPipelineComposer::ORGAN_ORDER, $organs);
        $this->assertSame([], $r['blockers']);
    }

    public function test_missing_organ_facts_yield_blocked_status_with_named_blocker(): void
    {
        $organs = $this->allOrgans();
        unset($organs['verification_court'], $organs['merge_governor']);
        $r = (new AtlasAutonomousRuntimeOrganPipelineComposer)->compose($organs);

        $this->assertSame(AtlasAutonomousRuntimeOrganPipelineComposer::STATUS_BLOCKED, $r['plan_status']);
        $this->assertContains('missing_organ:merge_governor', $r['blockers']);
        $this->assertContains('missing_organ:verification_court', $r['blockers']);
        $this->assertCount(8, $r['ordered_stages']);
    }

    public function test_ordering_is_deterministic_across_runs_even_with_shuffled_input(): void
    {
        $shuffled = array_reverse($this->allOrgans(), true);
        $c = new AtlasAutonomousRuntimeOrganPipelineComposer;
        $a = $c->compose($shuffled);
        $b = $c->compose($shuffled);
        $this->assertSame($a['ordered_stages'], $b['ordered_stages']);
        // Reverse-input still emits canonical order:
        $this->assertSame(AtlasAutonomousRuntimeOrganPipelineComposer::ORGAN_ORDER, array_column($a['ordered_stages'], 'organ'));
    }

    public function test_organ_facts_are_carried_verbatim_into_each_stage(): void
    {
        $organs = $this->allOrgans();
        $organs['task_fabric']['extra_fact'] = 'witness';
        $r = (new AtlasAutonomousRuntimeOrganPipelineComposer)->compose($organs);

        $byOrgan = [];
        foreach ($r['ordered_stages'] as $stage) {
            $byOrgan[$stage['organ']] = $stage['facts'];
        }
        $this->assertSame('witness', $byOrgan['task_fabric']['extra_fact'] ?? null);
    }

    public function test_no_inputs_yields_blocked_with_all_organs_missing(): void
    {
        $r = (new AtlasAutonomousRuntimeOrganPipelineComposer)->compose([]);
        $this->assertSame(AtlasAutonomousRuntimeOrganPipelineComposer::STATUS_BLOCKED, $r['plan_status']);
        $this->assertCount(10, $r['missing_organs']);
    }

    public function test_readiness_rows_covers_all_canonical_organs_even_when_some_are_missing(): void
    {
        $organs = $this->allOrgans();
        unset($organs['task_fabric']);
        $r = (new AtlasAutonomousRuntimeOrganPipelineComposer)->compose($organs);

        $this->assertCount(10, $r['readiness_rows'], 'readiness_rows must have one row per canonical organ');
        $rowOrgans = array_column($r['readiness_rows'], 'organ');
        $this->assertSame(AtlasAutonomousRuntimeOrganPipelineComposer::ORGAN_ORDER, $rowOrgans);

        $byOrgan = array_column($r['readiness_rows'], null, 'organ');
        $this->assertFalse($byOrgan['task_fabric']['ready']);
        $this->assertSame('missing_facts', $byOrgan['task_fabric']['reason']);
        // Organs before the missing one are ready.
        $this->assertTrue($byOrgan['control_plane']['ready']);
    }

    public function test_first_blocked_stage_is_first_non_ready_organ_in_canonical_order(): void
    {
        $organs = $this->allOrgans();
        unset($organs['strategy_council'], $organs['merge_governor']);
        $r = (new AtlasAutonomousRuntimeOrganPipelineComposer)->compose($organs);

        // strategy_council is earlier in the order than merge_governor.
        $this->assertSame('strategy_council', $r['first_blocked_stage']);
    }

    public function test_unhealthy_organ_is_not_ready_and_blocks_downstream(): void
    {
        $organs = $this->allOrgans();
        $organs['architecture_council']['healthy'] = false;
        $r = (new AtlasAutonomousRuntimeOrganPipelineComposer)->compose($organs);

        $this->assertSame(AtlasAutonomousRuntimeOrganPipelineComposer::STATUS_BLOCKED, $r['plan_status']);
        $this->assertSame('architecture_council', $r['first_blocked_stage']);

        $byOrgan = array_column($r['readiness_rows'], null, 'organ');
        $this->assertSame('unhealthy', $byOrgan['architecture_council']['reason']);
        $this->assertSame('upstream_blocked', $byOrgan['task_fabric']['reason']);
        $this->assertSame('upstream_blocked', $byOrgan['learning_transfer']['reason']);
    }

    public function test_complete_plan_has_null_first_blocked_stage(): void
    {
        $r = (new AtlasAutonomousRuntimeOrganPipelineComposer)->compose($this->allOrgans());
        $this->assertNull($r['first_blocked_stage']);
    }

    // ── AC3: optional organ (learning_transfer) missing does not block the plan ──

    public function test_missing_optional_organ_does_not_block_plan(): void
    {
        $organs = $this->allOrgans();
        unset($organs['learning_transfer']);

        $r = (new AtlasAutonomousRuntimeOrganPipelineComposer)->compose($organs);

        $this->assertSame(AtlasAutonomousRuntimeOrganPipelineComposer::STATUS_READY, $r['plan_status']);
        $this->assertNull($r['first_blocked_stage']);
        $this->assertSame([], $r['blockers']);
        $this->assertContains('learning_transfer', $r['missing_organs']);
        $this->assertContains('learning_transfer', $r['missing_optional_organs']);
        $this->assertSame([], $r['missing_required_organs']);
    }

    public function test_readiness_rows_mark_required_flag_per_organ(): void
    {
        $r = (new AtlasAutonomousRuntimeOrganPipelineComposer)->compose($this->allOrgans());

        $byOrgan = array_column($r['readiness_rows'], null, 'organ');
        $this->assertFalse($byOrgan['learning_transfer']['required']);
        $this->assertTrue($byOrgan['control_plane']['required']);
        $this->assertTrue($byOrgan['verification_court']['required']);
    }

    public function test_missing_optional_organ_reason_is_distinct_from_required(): void
    {
        $organs = $this->allOrgans();
        unset($organs['learning_transfer']);

        $r = (new AtlasAutonomousRuntimeOrganPipelineComposer)->compose($organs);

        $byOrgan = array_column($r['readiness_rows'], null, 'organ');
        $this->assertSame('missing_facts_optional', $byOrgan['learning_transfer']['reason']);
        $this->assertFalse($byOrgan['learning_transfer']['ready']);
    }

    public function test_missing_required_organ_still_blocks_even_with_optional_also_missing(): void
    {
        $organs = $this->allOrgans();
        unset($organs['learning_transfer'], $organs['merge_governor']);

        $r = (new AtlasAutonomousRuntimeOrganPipelineComposer)->compose($organs);

        $this->assertSame(AtlasAutonomousRuntimeOrganPipelineComposer::STATUS_BLOCKED, $r['plan_status']);
        $this->assertContains('missing_organ:merge_governor', $r['blockers']);
        $this->assertNotContains('missing_organ:learning_transfer', $r['blockers']);
    }

    // ── AC4: evidence capture points for queue, task_fabric, worker, proof_system, knowledge_sync ──

    public function test_evidence_capture_points_present_for_all_five_named_capture_points(): void
    {
        $r = (new AtlasAutonomousRuntimeOrganPipelineComposer)->compose($this->allOrgans());

        $byPoint = array_column($r['evidence_capture_points'], null, 'capture_point');
        foreach (['queue', 'task_fabric', 'worker', 'proof_system', 'knowledge_sync'] as $point) {
            $this->assertArrayHasKey($point, $byPoint, "missing evidence capture point: {$point}");
            $this->assertTrue($byPoint[$point]['present']);
        }
        $this->assertSame('maestro', $byPoint['queue']['organ']);
        $this->assertSame('worker_swarm', $byPoint['worker']['organ']);
        $this->assertSame('verification_court', $byPoint['proof_system']['organ']);
    }

    public function test_evidence_capture_point_reports_absent_when_backing_organ_missing(): void
    {
        $organs = $this->allOrgans();
        unset($organs['worker_swarm']);

        $r = (new AtlasAutonomousRuntimeOrganPipelineComposer)->compose($organs);

        $byPoint = array_column($r['evidence_capture_points'], null, 'capture_point');
        $this->assertFalse($byPoint['worker']['present']);
    }

    // ── AC3: stop conditions surfaced with their monitoring organ + activity status ──

    public function test_stop_conditions_are_surfaced_and_active_when_monitoring_organ_present(): void
    {
        $r = (new AtlasAutonomousRuntimeOrganPipelineComposer)->compose($this->allOrgans());

        $byCondition = array_column($r['stop_conditions'], null, 'condition');
        foreach (['queue_starvation', 'poison_signal_detected', 'budget_exceeded', 'proof_regression'] as $condition) {
            $this->assertArrayHasKey($condition, $byCondition, "missing stop condition: {$condition}");
            $this->assertTrue($byCondition[$condition]['active']);
            $this->assertNotEmpty($byCondition[$condition]['description']);
        }
    }

    public function test_stop_condition_is_inactive_when_its_monitoring_organ_is_missing(): void
    {
        $organs = $this->allOrgans();
        unset($organs['verification_court']);

        $r = (new AtlasAutonomousRuntimeOrganPipelineComposer)->compose($organs);

        $byCondition = array_column($r['stop_conditions'], null, 'condition');
        $this->assertFalse($byCondition['poison_signal_detected']['active']);
        $this->assertFalse($byCondition['proof_regression']['active']);
    }
}
