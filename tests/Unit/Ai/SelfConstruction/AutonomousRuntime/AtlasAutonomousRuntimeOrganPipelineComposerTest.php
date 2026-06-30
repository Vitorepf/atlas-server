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
}
