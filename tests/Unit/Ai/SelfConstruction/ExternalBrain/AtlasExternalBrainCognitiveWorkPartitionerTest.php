<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainCognitiveWorkPartitioner;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainCognitiveWorkPartitionerTest extends TestCase
{
    private function partitioner(): AtlasExternalBrainCognitiveWorkPartitioner
    {
        return new AtlasExternalBrainCognitiveWorkPartitioner;
    }

    private function phase(array $overrides = []): array
    {
        return array_merge(['id' => 'p1', 'description' => 'gather facts'], $overrides);
    }

    // ── AC4: output shape ─────────────────────────────────────────────────────

    public function test_output_has_required_keys(): void
    {
        $r = $this->partitioner()->partition([]);
        $this->assertSame(AtlasExternalBrainCognitiveWorkPartitioner::SCHEMA, $r['schema_version']);
        $this->assertArrayHasKey('phase_plan', $r);
        $this->assertArrayHasKey('model_tier_hint', $r);
        $this->assertArrayHasKey('required_artifacts', $r);
        $this->assertArrayHasKey('escalation_points', $r);
        $this->assertArrayHasKey('fallback_plan', $r);
    }

    // ── AC2: phase type classification ────────────────────────────────────────

    public function test_explicit_type_extraction_accepted(): void
    {
        $r = $this->partitioner()->partition(['phases' => [$this->phase(['type' => 'extraction'])]]);
        $this->assertSame('extraction', $r['phase_plan'][0]['phase_type']);
    }

    public function test_keyword_gather_infers_extraction(): void
    {
        $r = $this->partitioner()->partition(['phases' => [$this->phase(['description' => 'gather all relevant facts'])]]);
        $this->assertSame('extraction', $r['phase_plan'][0]['phase_type']);
    }

    public function test_keyword_validate_infers_verification(): void
    {
        $r = $this->partitioner()->partition(['phases' => [$this->phase(['description' => 'validate the produced output'])]]);
        $this->assertSame('verification', $r['phase_plan'][0]['phase_type']);
    }

    public function test_keyword_critique_infers_critique(): void
    {
        $r = $this->partitioner()->partition(['phases' => [$this->phase(['description' => 'critique the proposed design'])]]);
        $this->assertSame('critique', $r['phase_plan'][0]['phase_type']);
    }

    public function test_keyword_draft_infers_synthesis(): void
    {
        $r = $this->partitioner()->partition(['phases' => [$this->phase(['description' => 'draft the final proposal'])]]);
        $this->assertSame('synthesis', $r['phase_plan'][0]['phase_type']);
    }

    public function test_high_blast_radius_forces_escalation_type(): void
    {
        // blast_radius >= 0.70 → escalation regardless of description.
        $r = $this->partitioner()->partition(['phases' => [$this->phase([
            'description'  => 'gather facts',
            'blast_radius' => 0.80,
        ])]]);
        $this->assertSame('escalation', $r['phase_plan'][0]['phase_type']);
    }

    // ── AC3: model tier assignment ────────────────────────────────────────────

    public function test_extraction_gets_small_model(): void
    {
        $r = $this->partitioner()->partition(['phases' => [$this->phase(['type' => 'extraction'])]]);
        $this->assertSame('small_model', $r['phase_plan'][0]['model_tier']);
    }

    public function test_verification_gets_small_model(): void
    {
        $r = $this->partitioner()->partition(['phases' => [$this->phase(['type' => 'verification'])]]);
        $this->assertSame('small_model', $r['phase_plan'][0]['model_tier']);
    }

    public function test_critique_gets_scaffolded(): void
    {
        $r = $this->partitioner()->partition(['phases' => [$this->phase(['type' => 'critique'])]]);
        $this->assertSame('scaffolded_small_model', $r['phase_plan'][0]['model_tier']);
    }

    public function test_synthesis_without_high_risk_gets_scaffolded(): void
    {
        $r = $this->partitioner()->partition([
            'phases'       => [$this->phase(['type' => 'synthesis'])],
            'risk_profile' => ['ambiguity' => 0.3, 'conflicting_evidence' => false],
        ]);
        $this->assertSame('scaffolded_small_model', $r['phase_plan'][0]['model_tier']);
    }

    public function test_synthesis_with_high_ambiguity_escalates_to_frontier(): void
    {
        $r = $this->partitioner()->partition([
            'phases'       => [$this->phase(['type' => 'synthesis'])],
            'risk_profile' => ['ambiguity' => 0.80],
        ]);
        $this->assertSame('frontier_model', $r['phase_plan'][0]['model_tier']);
        $this->assertSame('escalation',     $r['phase_plan'][0]['phase_type']);
    }

    public function test_synthesis_with_conflicting_evidence_escalates(): void
    {
        $r = $this->partitioner()->partition([
            'phases'       => [$this->phase(['type' => 'synthesis'])],
            'risk_profile' => ['conflicting_evidence' => true],
        ]);
        $this->assertSame('frontier_model', $r['phase_plan'][0]['model_tier']);
    }

    public function test_escalation_type_gets_frontier(): void
    {
        $r = $this->partitioner()->partition(['phases' => [$this->phase(['type' => 'escalation'])]]);
        $this->assertSame('frontier_model', $r['phase_plan'][0]['model_tier']);
    }

    // ── AC4: aggregate outputs ────────────────────────────────────────────────

    public function test_model_tier_hint_is_highest_tier_in_plan(): void
    {
        $r = $this->partitioner()->partition(['phases' => [
            $this->phase(['id' => 'a', 'type' => 'extraction']),
            $this->phase(['id' => 'b', 'type' => 'escalation']),
            $this->phase(['id' => 'c', 'type' => 'verification']),
        ]]);
        $this->assertSame('frontier_model', $r['model_tier_hint']);
    }

    public function test_escalation_points_lists_frontier_phase_ids(): void
    {
        $r = $this->partitioner()->partition(['phases' => [
            $this->phase(['id' => 'extract', 'type' => 'extraction']),
            $this->phase(['id' => 'synth',   'type' => 'escalation']),
        ]]);
        $this->assertContains('synth',   $r['escalation_points']);
        $this->assertNotContains('extract', $r['escalation_points']);
    }

    public function test_required_artifacts_union_across_all_phases(): void
    {
        $r = $this->partitioner()->partition(['phases' => [
            $this->phase(['id' => 'a', 'type' => 'extraction',   'produces_artifacts' => ['fact_list']]),
            $this->phase(['id' => 'b', 'type' => 'synthesis',    'produces_artifacts' => ['proposal']]),
        ]]);
        $this->assertContains('fact_list', $r['required_artifacts']);
        $this->assertContains('proposal',  $r['required_artifacts']);
    }

    public function test_fallback_plan_names_degraded_frontier_phases(): void
    {
        $r = $this->partitioner()->partition(['phases' => [
            $this->phase(['id' => 'risky', 'type' => 'escalation']),
        ]]);
        $this->assertContains('risky', $r['fallback_plan']['degraded_phases']);
    }

    // ── AC2: high risk escalates all phase types (not just synthesis) ─────────

    public function test_extraction_with_high_ambiguity_escalates_to_frontier(): void
    {
        $r = $this->partitioner()->partition([
            'phases'       => [$this->phase(['type' => 'extraction'])],
            'risk_profile' => ['ambiguity' => 0.80],
        ]);
        $this->assertSame('escalation',     $r['phase_plan'][0]['phase_type']);
        $this->assertSame('frontier_model', $r['phase_plan'][0]['model_tier']);
    }

    public function test_verification_with_conflicting_evidence_escalates_to_frontier(): void
    {
        $r = $this->partitioner()->partition([
            'phases'       => [$this->phase(['type' => 'verification'])],
            'risk_profile' => ['conflicting_evidence' => true],
        ]);
        $this->assertSame('escalation',     $r['phase_plan'][0]['phase_type']);
        $this->assertSame('frontier_model', $r['phase_plan'][0]['model_tier']);
    }

    public function test_critique_with_high_ambiguity_escalates_to_frontier(): void
    {
        $r = $this->partitioner()->partition([
            'phases'       => [$this->phase(['type' => 'critique'])],
            'risk_profile' => ['ambiguity' => 0.70],
        ]);
        $this->assertSame('escalation',     $r['phase_plan'][0]['phase_type']);
        $this->assertSame('frontier_model', $r['phase_plan'][0]['model_tier']);
    }

    public function test_extraction_stays_small_model_when_risk_low(): void
    {
        $r = $this->partitioner()->partition([
            'phases'       => [$this->phase(['type' => 'extraction'])],
            'risk_profile' => ['ambiguity' => 0.30, 'conflicting_evidence' => false],
        ]);
        $this->assertSame('extraction',  $r['phase_plan'][0]['phase_type']);
        $this->assertSame('small_model', $r['phase_plan'][0]['model_tier']);
    }

    public function test_keyword_inferred_extraction_with_high_risk_escalates(): void
    {
        $r = $this->partitioner()->partition([
            'phases'       => [$this->phase(['description' => 'gather all relevant evidence'])],
            'risk_profile' => ['ambiguity' => 0.75],
        ]);
        $this->assertSame('escalation',     $r['phase_plan'][0]['phase_type']);
        $this->assertSame('frontier_model', $r['phase_plan'][0]['model_tier']);
    }

    public function test_keyword_inferred_verification_with_conflicting_evidence_escalates(): void
    {
        $r = $this->partitioner()->partition([
            'phases'       => [$this->phase(['description' => 'validate output against spec'])],
            'risk_profile' => ['conflicting_evidence' => true],
        ]);
        $this->assertSame('escalation',     $r['phase_plan'][0]['phase_type']);
        $this->assertSame('frontier_model', $r['phase_plan'][0]['model_tier']);
    }

    public function test_escalation_explicit_type_unaffected_by_risk_profile(): void
    {
        // Explicit 'escalation' must never be double-escalated or demoted by risk profile.
        $r = $this->partitioner()->partition([
            'phases'       => [$this->phase(['type' => 'escalation'])],
            'risk_profile' => ['ambiguity' => 0.95, 'conflicting_evidence' => true],
        ]);
        $this->assertSame('escalation',     $r['phase_plan'][0]['phase_type']);
        $this->assertSame('frontier_model', $r['phase_plan'][0]['model_tier']);
    }

    // ── escalation_reason / fallback_plan / advisory-not-steady-state ─────────

    public function test_small_model_phase_has_no_escalation_reason(): void
    {
        $r = $this->partitioner()->partition(['phases' => [$this->phase(['type' => 'extraction'])]]);
        $this->assertNull($r['phase_plan'][0]['escalation_reason']);
        $this->assertNull($r['phase_plan'][0]['fallback_plan']);
    }

    public function test_frontier_phase_has_explicit_escalation_reason_and_fallback_plan(): void
    {
        $r = $this->partitioner()->partition(['phases' => [$this->phase(['id' => 'risky', 'type' => 'escalation', 'blast_radius' => 0.9])]]);

        $this->assertNotNull($r['phase_plan'][0]['escalation_reason']);
        $this->assertStringContainsString('blast_radius', $r['phase_plan'][0]['escalation_reason']);
        $this->assertNotNull($r['phase_plan'][0]['fallback_plan']);
        $this->assertStringContainsString('risky', $r['phase_plan'][0]['fallback_plan']);
    }

    public function test_scaffolded_critique_phase_has_escalation_reason(): void
    {
        $r = $this->partitioner()->partition(['phases' => [$this->phase(['type' => 'critique'])]]);
        $this->assertNotNull($r['phase_plan'][0]['escalation_reason']);
        $this->assertStringContainsString('critique', $r['phase_plan'][0]['escalation_reason']);
    }

    public function test_output_states_escalation_is_advisory_not_steady_state_dependency(): void
    {
        $r = $this->partitioner()->partition([]);
        $this->assertTrue($r['escalation_is_advisory_not_steady_state_dependency']);
        $this->assertNotEmpty($r['steady_state_note']);
    }

    // ── Determinism ───────────────────────────────────────────────────────────

    public function test_output_is_deterministic(): void
    {
        $facts = [
            'phases' => [
                $this->phase(['id' => 'a', 'type' => 'extraction']),
                $this->phase(['id' => 'b', 'type' => 'synthesis']),
                $this->phase(['id' => 'c', 'blast_radius' => 0.9]),
            ],
            'risk_profile' => ['ambiguity' => 0.5],
        ];
        $a = $this->partitioner()->partition($facts);
        $b = $this->partitioner()->partition($facts);
        $this->assertSame(json_encode($a), json_encode($b));
    }

    // ── partitionSpecialistLanes() — AC: simple single-lane work ──────────────

    public function test_simple_low_risk_work_stays_in_a_single_requested_lane(): void
    {
        $r = $this->partitioner()->partitionSpecialistLanes(['ambiguity' => 0.1, 'requested_lanes' => ['code_evidence']]);

        $this->assertSame('single_lane', $r['decision']);
        $this->assertFalse($r['rejected']);
        $laneNames = array_column($r['lanes'], 'lane');
        $this->assertContains('code_evidence', $laneNames);
        $this->assertContains('final_decision', $laneNames);
    }

    // ── AC: high-risk origination rejects a single-lane decision ──────────────

    public function test_high_risk_single_lane_request_is_rejected(): void
    {
        $r = $this->partitioner()->partitionSpecialistLanes([
            'high_risk' => true,
            'requested_lanes' => ['code_evidence'],
        ]);

        $this->assertSame('rejected', $r['decision']);
        $this->assertTrue($r['rejected']);
        $this->assertSame('single_lane_insufficient_for_high_risk_origination', $r['rejection_reason']);
        $this->assertContains('model_weakness', $r['required_lanes']);
    }

    public function test_high_ambiguity_without_explicit_lanes_partitions_all_specialist_lanes(): void
    {
        $r = $this->partitioner()->partitionSpecialistLanes(['ambiguity' => 0.9]);

        $this->assertSame('multi_lane', $r['decision']);
        $this->assertFalse($r['rejected']);
        $laneNames = array_column($r['lanes'], 'lane');
        foreach (AtlasExternalBrainCognitiveWorkPartitioner::SPECIALIST_LANES as $lane) {
            $this->assertContains($lane, $laneNames);
        }
        $this->assertContains('final_decision', $laneNames);
    }

    // ── AC: missing evidence lane is surfaced, not silently assumed present ───

    public function test_missing_evidence_lane_is_reported(): void
    {
        $r = $this->partitioner()->partitionSpecialistLanes([
            'high_risk' => true,
            'evidence_available' => ['model_weakness' => false],
        ]);

        $this->assertContains('model_weakness', $r['missing_evidence_lanes']);
        $row = array_values(array_filter($r['lanes'], fn (array $l): bool => $l['lane'] === 'model_weakness'))[0];
        $this->assertFalse($row['has_evidence']);
    }

    public function test_lane_with_evidence_present_is_not_flagged_missing(): void
    {
        $r = $this->partitioner()->partitionSpecialistLanes([
            'high_risk' => true,
            'evidence_available' => ['model_weakness' => true],
        ]);

        $this->assertNotContains('model_weakness', $r['missing_evidence_lanes']);
    }

    // ── AC: final synthesis inputs — final_decision depends on every active lane ──

    public function test_final_decision_lane_depends_on_all_active_lanes(): void
    {
        $r = $this->partitioner()->partitionSpecialistLanes(['high_risk' => true]);

        $finalRow = array_values(array_filter($r['lanes'], fn (array $l): bool => $l['lane'] === 'final_decision'))[0];
        foreach (AtlasExternalBrainCognitiveWorkPartitioner::SPECIALIST_LANES as $lane) {
            $this->assertContains($lane, $finalRow['depends_on']);
        }
        $this->assertSame(AtlasExternalBrainCognitiveWorkPartitioner::SPECIALIST_LANES, $r['final_decision_inputs']);
    }
}
