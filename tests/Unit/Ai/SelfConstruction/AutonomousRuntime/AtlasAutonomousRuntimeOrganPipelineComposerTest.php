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
}
