<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainQueueGapRepairPlanner;
use Tests\TestCase;

final class AtlasExternalBrainQueueGapRepairPlannerTest extends TestCase
{
    private function planner(): AtlasExternalBrainQueueGapRepairPlanner
    {
        return new AtlasExternalBrainQueueGapRepairPlanner;
    }

    public function test_schema_present(): void
    {
        $r = $this->planner()->plan([]);
        $this->assertSame(AtlasExternalBrainQueueGapRepairPlanner::SCHEMA, $r['schema']);
    }

    public function test_healthy_packet_with_no_deficiencies_is_omitted(): void
    {
        $r = $this->planner()->plan([
            ['packet_id' => 'healthy-1', 'deficiencies' => []],
        ]);

        $this->assertSame([], $r['queue_repair_matrix']);
    }

    // ── each canonical defect is classified and produces a field-level respec action ──

    public function test_blocked_defect_maps_to_allowed_files_respec(): void
    {
        $r = $this->planner()->plan([
            ['packet_id' => 'p-blocked', 'deficiencies' => ['blocked_on_missing_dependency']],
        ]);

        $entry = $r['queue_repair_matrix'][0];
        $this->assertSame(AtlasExternalBrainQueueGapRepairPlanner::VERDICT_RESPEC_RECOMMENDED, $entry['verdict']);
        $this->assertContains('blocked', $entry['defects']);
        $this->assertSame('allowed_files', $entry['respec_actions'][0]['field']);
    }

    public function test_poison_defect_maps_to_objective_respec(): void
    {
        $r = $this->planner()->plan([
            ['packet_id' => 'p-poison', 'deficiencies' => ['poison_risk_high']],
        ]);

        $entry = $r['queue_repair_matrix'][0];
        $this->assertContains('poison', $entry['defects']);
        $action = array_values(array_filter($entry['respec_actions'], fn ($a) => $a['defect'] === 'poison'))[0];
        $this->assertSame('objective', $action['field']);
    }

    public function test_test_only_defect_maps_to_allowed_files_respec(): void
    {
        $r = $this->planner()->plan([
            ['packet_id' => 'p-testonly', 'deficiencies' => ['test_only_has_contract']],
        ]);

        $entry = $r['queue_repair_matrix'][0];
        $this->assertContains('test_only', $entry['defects']);
        $action = array_values(array_filter($entry['respec_actions'], fn ($a) => $a['defect'] === 'test_only'))[0];
        $this->assertSame('allowed_files', $action['field']);
    }

    public function test_missing_scope_defect_maps_to_allowed_files_respec(): void
    {
        $r = $this->planner()->plan([
            ['packet_id' => 'p-scope', 'deficiencies' => ['scope_incoherent']],
        ]);

        $entry = $r['queue_repair_matrix'][0];
        $this->assertContains('missing_scope', $entry['defects']);
        $action = array_values(array_filter($entry['respec_actions'], fn ($a) => $a['defect'] === 'missing_scope'))[0];
        $this->assertSame('allowed_files', $action['field']);
    }

    public function test_contradictory_acceptance_defect_maps_to_acceptance_criteria_respec(): void
    {
        $r = $this->planner()->plan([
            ['packet_id' => 'p-contra', 'deficiencies' => ['contradictory_acceptance']],
        ]);

        $entry = $r['queue_repair_matrix'][0];
        $this->assertContains('contradictory_acceptance', $entry['defects']);
        $action = array_values(array_filter($entry['respec_actions'], fn ($a) => $a['defect'] === 'contradictory_acceptance'))[0];
        $this->assertSame('acceptance_criteria', $action['field']);
    }

    public function test_weak_evidence_defect_maps_to_required_evidence_respec(): void
    {
        $r = $this->planner()->plan([
            ['packet_id' => 'p-weak', 'deficiencies' => ['weak_evidence_only_prose']],
        ]);

        $entry = $r['queue_repair_matrix'][0];
        $this->assertContains('weak_evidence', $entry['defects']);
        $action = array_values(array_filter($entry['respec_actions'], fn ($a) => $a['defect'] === 'weak_evidence'))[0];
        $this->assertSame('required_evidence', $action['field']);
    }

    public function test_acceptance_not_runnable_classifies_as_weak_evidence(): void
    {
        $r = $this->planner()->plan([
            ['packet_id' => 'p-notrun', 'deficiencies' => ['acceptance_not_runnable']],
        ]);

        $entry = $r['queue_repair_matrix'][0];
        $this->assertContains('weak_evidence', $entry['defects']);
    }

    // ── a single raw deficiency string can classify into multiple defects ────

    public function test_compound_deficiency_string_classifies_into_both_defects(): void
    {
        $r = $this->planner()->plan([
            ['packet_id' => 'p-compound', 'deficiencies' => ['hidden_poison:contradictory_acceptance']],
        ]);

        $entry = $r['queue_repair_matrix'][0];
        $this->assertContains('poison', $entry['defects']);
        $this->assertContains('contradictory_acceptance', $entry['defects']);
        $this->assertCount(2, $entry['respec_actions']);
    }

    // ── quarantine_recommended for unrecognized / unactionable deficiencies ───

    public function test_unrecognized_deficiency_is_marked_quarantine_recommended(): void
    {
        $r = $this->planner()->plan([
            ['packet_id' => 'p-mystery', 'deficiencies' => ['some_totally_unknown_defect_signal']],
        ]);

        $entry = $r['queue_repair_matrix'][0];
        $this->assertSame(AtlasExternalBrainQueueGapRepairPlanner::VERDICT_QUARANTINE_RECOMMENDED, $entry['verdict']);
        $this->assertSame([], $entry['respec_actions']);
        $this->assertNotNull($entry['reason']);
    }

    public function test_vague_objective_alone_is_unclassified_and_quarantines(): void
    {
        // Matches the real demo-poison shape: vague_objective has no field-level repair mapping.
        $r = $this->planner()->plan([
            ['packet_id' => 'op-demo-1', 'deficiencies' => ['vague_objective']],
        ]);

        $entry = $r['queue_repair_matrix'][0];
        $this->assertSame(AtlasExternalBrainQueueGapRepairPlanner::VERDICT_QUARANTINE_RECOMMENDED, $entry['verdict']);
    }

    // ── deduplication + determinism ───────────────────────────────────────────

    public function test_duplicate_defect_across_multiple_deficiency_strings_is_deduplicated(): void
    {
        $r = $this->planner()->plan([
            ['packet_id' => 'p-dup', 'deficiencies' => ['blocked_a', 'blocked_b']],
        ]);

        $entry = $r['queue_repair_matrix'][0];
        $this->assertSame(['blocked'], $entry['defects']);
        $this->assertCount(1, $entry['respec_actions']);
    }

    public function test_plan_is_deterministic(): void
    {
        $packets = [
            ['packet_id' => 'a', 'deficiencies' => ['hidden_poison:contradictory_acceptance']],
            ['packet_id' => 'b', 'deficiencies' => ['weak_evidence']],
        ];

        $this->assertSame(
            json_encode($this->planner()->plan($packets)),
            json_encode($this->planner()->plan($packets)),
        );
    }

    public function test_malformed_entries_are_skipped(): void
    {
        $r = $this->planner()->plan([
            ['deficiencies' => ['blocked']], // missing packet_id
            ['packet_id' => 'valid', 'deficiencies' => ['blocked']],
        ]);

        $this->assertCount(1, $r['queue_repair_matrix']);
        $this->assertSame('valid', $r['queue_repair_matrix'][0]['packet_id']);
    }
}
