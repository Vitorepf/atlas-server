<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainCognitionCascadeController;
use Tests\TestCase;

final class AtlasExternalBrainCognitionCascadeControllerTest extends TestCase
{
    private function controller(): AtlasExternalBrainCognitionCascadeController
    {
        return new AtlasExternalBrainCognitionCascadeController();
    }

    // ── schema + structure ────────────────────────────────────────────────────

    public function test_schema_present(): void
    {
        $result = $this->controller()->control([]);

        $this->assertSame(AtlasExternalBrainCognitionCascadeController::SCHEMA, $result['schema']);
    }

    public function test_output_has_required_keys(): void
    {
        $result = $this->controller()->control([]);

        foreach (['schema', 'selected_path', 'skipped_stages', 'escalation_reasons',
                  'stop_conditions', 'rollback_conditions', 'required_local_gates'] as $k) {
            $this->assertArrayHasKey($k, $result);
        }
    }

    public function test_selected_path_is_list_of_stage_strings(): void
    {
        $result = $this->controller()->control([]);

        $this->assertIsArray($result['selected_path']);
        $this->assertNotEmpty($result['selected_path']);
        foreach ($result['selected_path'] as $stage) {
            $this->assertIsString($stage);
        }
    }

    // ── required_local_gates ──────────────────────────────────────────────────

    public function test_required_local_gates_always_present(): void
    {
        $result = $this->controller()->control([]);

        $this->assertContains('evidence_list_non_empty', $result['required_local_gates']);
        $this->assertContains('dedup_proof_present', $result['required_local_gates']);
        $this->assertContains('no_retirable_patterns_in_scope', $result['required_local_gates']);
    }

    // ── cheap path (deterministic_preflight only) ─────────────────────────────

    public function test_cheap_path_when_quality_ok_and_not_ambiguous_or_risky(): void
    {
        $result = $this->controller()->control([
            'evidence_quality' => 0.80,
            'ambiguity_score'  => 0.30,
            'risk_score'       => 0.20,
        ]);

        $this->assertSame(
            [AtlasExternalBrainCognitionCascadeController::STAGE_DETERMINISTIC_PREFLIGHT],
            $result['selected_path'],
        );
    }

    // backward-compat alias: STAGE_LOCAL_ONLY = STAGE_DETERMINISTIC_PREFLIGHT
    public function test_local_only_when_quality_ok_and_not_ambiguous_or_risky(): void
    {
        $result = $this->controller()->control([
            'evidence_quality' => 0.80,
            'ambiguity_score'  => 0.30,
            'risk_score'       => 0.20,
        ]);

        $this->assertSame(AtlasExternalBrainCognitionCascadeController::STAGE_LOCAL_ONLY, $result['selected_stage']);
    }

    public function test_cheap_path_skips_all_expensive_stages(): void
    {
        $result = $this->controller()->control([
            'evidence_quality' => 0.80,
            'ambiguity_score'  => 0.30,
            'risk_score'       => 0.20,
        ]);

        $this->assertContains(AtlasExternalBrainCognitionCascadeController::STAGE_SCAFFOLDED_SMALL_MODEL, $result['skipped_stages']);
        $this->assertContains(AtlasExternalBrainCognitionCascadeController::STAGE_FRONTIER_REVIEW,         $result['skipped_stages']);
        $this->assertContains(AtlasExternalBrainCognitionCascadeController::STAGE_REPAIR_LOOP,             $result['skipped_stages']);
    }

    // backward-compat alias
    public function test_local_only_skips_scaffold_and_frontier(): void
    {
        $result = $this->controller()->control([
            'evidence_quality' => 0.80,
            'ambiguity_score'  => 0.30,
            'risk_score'       => 0.20,
        ]);

        $this->assertContains(AtlasExternalBrainCognitionCascadeController::STAGE_SCAFFOLDED_SMALL_MODEL, $result['skipped_stages']);
        $this->assertContains(AtlasExternalBrainCognitionCascadeController::STAGE_FRONTIER_ESCALATION,    $result['skipped_stages']);
    }

    public function test_cheap_path_has_no_escalation_reasons(): void
    {
        $result = $this->controller()->control([
            'evidence_quality' => 0.80,
            'ambiguity_score'  => 0.30,
            'risk_score'       => 0.20,
        ]);

        $this->assertSame([], $result['escalation_reasons']);
    }

    // backward-compat alias
    public function test_local_only_has_no_escalation_reasons(): void
    {
        $this->test_cheap_path_has_no_escalation_reasons();
    }

    // ── frontier skipped for low-risk high-evidence ───────────────────────────

    public function test_frontier_skipped_for_low_risk_high_evidence(): void
    {
        // AC: frontier is skipped when risk < ceiling AND quality >= floor
        $result = $this->controller()->control([
            'evidence_quality'          => 0.80,
            'ambiguity_score'           => 0.80,
            'risk_score'                => 0.20,   // low risk
            'has_conflicting_evidence'  => false,
        ]);

        $this->assertNotContains(AtlasExternalBrainCognitionCascadeController::STAGE_FRONTIER_REVIEW, $result['selected_path']);
    }

    // ── scaffolded path (cheap + scaffolded_small_model) ─────────────────────

    public function test_scaffolded_path_when_ambiguity_high(): void
    {
        // high ambiguity alone → scaffolded but not critique (no risk, no conflicting)
        $result = $this->controller()->control([
            'evidence_quality' => 0.80,
            'ambiguity_score'  => 0.75,
            'risk_score'       => 0.20,
        ]);

        $this->assertContains(AtlasExternalBrainCognitionCascadeController::STAGE_SCAFFOLDED_SMALL_MODEL, $result['selected_path']);
        $this->assertNotContains(AtlasExternalBrainCognitionCascadeController::STAGE_CRITIQUE_QUORUM,     $result['selected_path']);
        $this->assertNotContains(AtlasExternalBrainCognitionCascadeController::STAGE_FRONTIER_REVIEW,     $result['selected_path']);
    }

    // backward-compat alias
    public function test_scaffolded_when_quality_ok_but_ambiguity_high(): void
    {
        $result = $this->controller()->control([
            'evidence_quality' => 0.80,
            'ambiguity_score'  => 0.75,
            'risk_score'       => 0.20,
        ]);

        $this->assertSame(AtlasExternalBrainCognitionCascadeController::STAGE_SCAFFOLDED_SMALL_MODEL, $result['selected_stage']);
    }

    public function test_scaffolded_path_skips_frontier(): void
    {
        $result = $this->controller()->control([
            'evidence_quality' => 0.80,
            'ambiguity_score'  => 0.75,
            'risk_score'       => 0.20,
        ]);

        $this->assertContains(AtlasExternalBrainCognitionCascadeController::STAGE_FRONTIER_ESCALATION, $result['skipped_stages']);
        $this->assertNotContains(AtlasExternalBrainCognitionCascadeController::STAGE_LOCAL_ONLY,        $result['skipped_stages']);
    }

    // backward-compat alias
    public function test_scaffolded_skips_only_frontier(): void
    {
        $this->test_scaffolded_path_skips_frontier();
    }

    // ── risk_high → critique_quorum path ─────────────────────────────────────

    public function test_risk_high_escalates_to_critique_quorum(): void
    {
        $result = $this->controller()->control([
            'evidence_quality' => 0.80,
            'ambiguity_score'  => 0.20,
            'risk_score'       => 0.75,
        ]);

        $this->assertContains(AtlasExternalBrainCognitionCascadeController::STAGE_CRITIQUE_QUORUM, $result['selected_path']);
    }

    public function test_scaffolded_when_quality_ok_but_risk_high(): void
    {
        // risk_high now reaches critique_quorum, not just scaffolded
        $result = $this->controller()->control([
            'evidence_quality' => 0.80,
            'ambiguity_score'  => 0.20,
            'risk_score'       => 0.75,
        ]);

        $this->assertContains(AtlasExternalBrainCognitionCascadeController::STAGE_SCAFFOLDED_SMALL_MODEL, $result['selected_path']);
        $this->assertContains(AtlasExternalBrainCognitionCascadeController::STAGE_CRITIQUE_QUORUM,        $result['selected_path']);
    }

    // ── frontier path ─────────────────────────────────────────────────────────

    public function test_frontier_path_triggered_by_high_ambiguity_and_conflicting_evidence(): void
    {
        $result = $this->controller()->control([
            'evidence_quality'         => 0.80,
            'ambiguity_score'          => 0.80,
            'risk_score'               => 0.20,
            'has_conflicting_evidence' => true,
        ]);

        $this->assertContains(AtlasExternalBrainCognitionCascadeController::STAGE_FRONTIER_REVIEW, $result['selected_path']);
        $this->assertSame(AtlasExternalBrainCognitionCascadeController::STAGE_FRONTIER_REVIEW,     $result['selected_stage']);
    }

    public function test_frontier_path_triggered_by_high_leverage_and_low_scaffold_confidence(): void
    {
        $result = $this->controller()->control([
            'evidence_quality'      => 0.80,
            'ambiguity_score'       => 0.30,
            'risk_score'            => 0.20,
            'leverage_score'        => 0.90,
            'scaffold_confidence'   => 0.50,   // < 0.70
        ]);

        $this->assertContains(AtlasExternalBrainCognitionCascadeController::STAGE_FRONTIER_REVIEW, $result['selected_path']);
    }

    public function test_frontier_when_ambiguity_high_and_conflicting_evidence(): void
    {
        // risk intentionally low so repair_loop is not co-triggered (riskHi && conflicting)
        $result = $this->controller()->control([
            'evidence_quality'         => 0.80,
            'ambiguity_score'          => 0.75,
            'risk_score'               => 0.20,
            'has_conflicting_evidence' => true,
        ]);

        $this->assertSame(AtlasExternalBrainCognitionCascadeController::STAGE_FRONTIER_ESCALATION, $result['selected_stage']);
    }

    public function test_frontier_escalation_reasons_populated(): void
    {
        $result = $this->controller()->control([
            'evidence_quality'         => 0.40,
            'ambiguity_score'          => 0.80,
            'risk_score'               => 0.20,
            'has_conflicting_evidence' => true,
        ]);

        $this->assertNotEmpty($result['escalation_reasons']);
        $this->assertStringContainsString('evidence_quality', $result['escalation_reasons'][0]);
    }

    public function test_frontier_not_triggered_without_conflicting_evidence_or_leverage(): void
    {
        // Even with extreme ambiguity + risk, frontier requires conflicting_evidence or leverage signal
        $result = $this->controller()->control([
            'evidence_quality'         => 0.10,
            'ambiguity_score'          => 0.90,
            'risk_score'               => 0.90,
            'has_conflicting_evidence' => false,
        ]);

        $this->assertNotContains(AtlasExternalBrainCognitionCascadeController::STAGE_FRONTIER_REVIEW, $result['selected_path']);
    }

    // backward-compat alias
    public function test_never_frontier_when_safe_scaffold_fallback_true(): void
    {
        $this->test_frontier_not_triggered_without_conflicting_evidence_or_leverage();
    }

    // ── repair path ──────────────────────────────────────────────────────────

    public function test_repair_path_triggered_by_needs_repair(): void
    {
        $result = $this->controller()->control([
            'evidence_quality' => 0.80,
            'ambiguity_score'  => 0.30,
            'risk_score'       => 0.20,
            'needs_repair'     => true,
        ]);

        $this->assertContains(AtlasExternalBrainCognitionCascadeController::STAGE_REPAIR_LOOP, $result['selected_path']);
        $this->assertContains(AtlasExternalBrainCognitionCascadeController::STAGE_CRITIQUE_QUORUM, $result['selected_path']);
    }

    public function test_repair_path_triggered_by_risk_and_conflicting_evidence(): void
    {
        $result = $this->controller()->control([
            'evidence_quality'         => 0.80,
            'ambiguity_score'          => 0.30,
            'risk_score'               => 0.80,
            'has_conflicting_evidence' => true,
        ]);

        $this->assertContains(AtlasExternalBrainCognitionCascadeController::STAGE_REPAIR_LOOP, $result['selected_path']);
    }

    // ── stop_conditions ────────────────────────────────────────────────────────

    public function test_stop_conditions_always_contains_preflight_entry(): void
    {
        $result = $this->controller()->control([]);

        $this->assertNotEmpty($result['stop_conditions']);
        $this->assertStringContainsString('deterministic_preflight', $result['stop_conditions'][0]);
    }

    public function test_stop_conditions_grow_with_path_length(): void
    {
        $cheap    = $this->controller()->control([
            'evidence_quality' => 0.80, 'ambiguity_score' => 0.30, 'risk_score' => 0.20,
        ]);
        $frontier = $this->controller()->control([
            'evidence_quality' => 0.80, 'ambiguity_score' => 0.80, 'risk_score' => 0.20,
            'has_conflicting_evidence' => true,
        ]);

        $this->assertGreaterThan(count($cheap['stop_conditions']), count($frontier['stop_conditions']));
    }

    // ── rollback_conditions ───────────────────────────────────────────────────

    public function test_rollback_conditions_always_present(): void
    {
        $result = $this->controller()->control([]);

        $this->assertNotEmpty($result['rollback_conditions']);
        $combined = implode(' ', $result['rollback_conditions']);
        $this->assertStringContainsString('rollback', $combined);
    }

    public function test_rollback_conditions_mention_quarantine_when_repair_loop_in_path(): void
    {
        $result = $this->controller()->control(['needs_repair' => true]);

        $combined = implode(' ', $result['rollback_conditions']);
        $this->assertStringContainsString('quarantine', $combined);
    }

    // ── custom thresholds ─────────────────────────────────────────────────────

    public function test_custom_evidence_floor_respected(): void
    {
        // With floor=0.90, quality=0.80 is insufficient → not cheap path
        $result = $this->controller()->control([
            'evidence_quality' => 0.80,
            'ambiguity_score'  => 0.20,
            'risk_score'       => 0.20,
            'thresholds'       => ['evidence_floor' => 0.90],
        ]);

        $this->assertNotSame(AtlasExternalBrainCognitionCascadeController::STAGE_LOCAL_ONLY, $result['selected_stage']);
    }

    public function test_custom_ambiguity_ceiling_respected(): void
    {
        // With ceiling=0.50, ambiguity=0.60 triggers scaffolded even with good quality
        $result = $this->controller()->control([
            'evidence_quality' => 0.80,
            'ambiguity_score'  => 0.60,
            'risk_score'       => 0.20,
            'thresholds'       => ['ambiguity_ceiling' => 0.50],
        ]);

        $this->assertSame(AtlasExternalBrainCognitionCascadeController::STAGE_SCAFFOLDED_SMALL_MODEL, $result['selected_stage']);
    }

    // ── fallback_plan (backward-compat) ───────────────────────────────────────

    public function test_fallback_plan_non_empty(): void
    {
        foreach ([
            ['evidence_quality' => 0.80, 'ambiguity_score' => 0.20, 'risk_score' => 0.20],
            ['evidence_quality' => 0.80, 'ambiguity_score' => 0.80, 'risk_score' => 0.20],
            ['evidence_quality' => 0.80, 'ambiguity_score' => 0.80, 'risk_score' => 0.20, 'has_conflicting_evidence' => true],
        ] as $input) {
            $result = $this->controller()->control($input);
            $this->assertNotEmpty($result['fallback_plan']);
        }
    }

    // ── determinism ──────────────────────────────────────────────────────────

    public function test_identical_input_yields_identical_output(): void
    {
        $input = [
            'evidence_quality' => 0.75,
            'ambiguity_score'  => 0.40,
            'risk_score'       => 0.60,
        ];

        $this->assertSame($this->controller()->control($input), $this->controller()->control($input));
    }

    // ── cascadePlan(): explicit 7-stage origination cascade ───────────────────

    private function fullyDone(array $overrides = []): array
    {
        return array_merge([
            'is_high_impact' => true,
            'state_read_done' => true,
            'understanding_done' => true,
            'candidates_proposed' => true,
            'critique_done' => true,
            'repair_required' => false,
            'repair_done' => false,
        ], $overrides);
    }

    public function test_cascade_plan_has_required_keys(): void
    {
        $result = $this->controller()->cascadePlan($this->fullyDone());

        foreach (['cascade_stages', 'stage_status', 'blocked_stage', 'next_required_action', 'admitted'] as $k) {
            $this->assertArrayHasKey($k, $result);
        }
    }

    public function test_cascade_plan_lists_all_seven_stages_in_order(): void
    {
        $result = $this->controller()->cascadePlan($this->fullyDone());

        $this->assertSame([
            'read_state', 'understand', 'propose', 'critique', 'repair', 'admit', 'feedback',
        ], $result['cascade_stages']);
    }

    public function test_fully_completed_high_impact_task_is_admitted(): void
    {
        $result = $this->controller()->cascadePlan($this->fullyDone());

        $this->assertTrue($result['admitted']);
        $this->assertNull($result['blocked_stage']);
        $this->assertSame('completed', $result['stage_status']['critique']);
        $this->assertSame('completed', $result['stage_status']['feedback']);
    }

    public function test_high_impact_task_skipping_critique_fails_closed(): void
    {
        $result = $this->controller()->cascadePlan($this->fullyDone(['critique_done' => false]));

        $this->assertFalse($result['admitted']);
        $this->assertSame('critique', $result['blocked_stage']);
        $this->assertSame('blocked', $result['stage_status']['critique']);
        $this->assertSame('pending', $result['stage_status']['repair']);
        $this->assertSame('pending', $result['stage_status']['admit']);
        $this->assertSame('pending', $result['stage_status']['feedback']);
        $this->assertNotNull($result['next_required_action']);
    }

    public function test_low_impact_task_does_not_require_critique(): void
    {
        $result = $this->controller()->cascadePlan($this->fullyDone([
            'is_high_impact' => false,
            'critique_done' => false,
        ]));

        $this->assertSame('skipped', $result['stage_status']['critique']);
        $this->assertTrue($result['admitted']);
    }

    public function test_required_repair_skipped_fails_closed(): void
    {
        $result = $this->controller()->cascadePlan($this->fullyDone([
            'repair_required' => true,
            'repair_done' => false,
        ]));

        $this->assertFalse($result['admitted']);
        $this->assertSame('repair', $result['blocked_stage']);
        $this->assertSame('blocked', $result['stage_status']['repair']);
        $this->assertSame('pending', $result['stage_status']['admit']);
    }

    public function test_repair_completed_when_required_admits_task(): void
    {
        $result = $this->controller()->cascadePlan($this->fullyDone([
            'repair_required' => true,
            'repair_done' => true,
        ]));

        $this->assertTrue($result['admitted']);
        $this->assertSame('completed', $result['stage_status']['repair']);
    }

    public function test_repair_not_required_is_skipped(): void
    {
        $result = $this->controller()->cascadePlan($this->fullyDone(['repair_required' => false]));

        $this->assertSame('skipped', $result['stage_status']['repair']);
    }

    public function test_earlier_stage_blocked_stops_at_first_failure(): void
    {
        $result = $this->controller()->cascadePlan($this->fullyDone([
            'understanding_done' => false,
            'candidates_proposed' => false,
            'critique_done' => false,
        ]));

        $this->assertSame('understand', $result['blocked_stage']);
        $this->assertSame('pending', $result['stage_status']['propose']);
        $this->assertSame('pending', $result['stage_status']['critique']);
    }

    public function test_cascade_plan_is_deterministic(): void
    {
        $input = $this->fullyDone();

        $this->assertSame($this->controller()->cascadePlan($input), $this->controller()->cascadePlan($input));
    }

    // ── AC3: explicit stop condition — sufficient_evidence_to_decide ─────────

    public function test_sufficient_evidence_stops_escalation_despite_high_ambiguity_and_leverage(): void
    {
        $result = $this->controller()->control([
            'evidence_quality' => 0.80,
            'ambiguity_score'  => 0.80,
            'risk_score'       => 0.20,
            'leverage_score'   => 0.90,
            'scaffold_confidence' => 0.50,
            'sufficient_evidence_to_decide' => true,
        ]);

        $this->assertSame(
            [AtlasExternalBrainCognitionCascadeController::STAGE_DETERMINISTIC_PREFLIGHT],
            $result['selected_path'],
        );
        $this->assertTrue($result['stopped_early']);
    }

    public function test_sufficient_evidence_does_not_override_high_risk(): void
    {
        $result = $this->controller()->control([
            'evidence_quality' => 0.80,
            'ambiguity_score'  => 0.10,
            'risk_score'       => 0.80,
            'sufficient_evidence_to_decide' => true,
        ]);

        $this->assertContains(AtlasExternalBrainCognitionCascadeController::STAGE_CRITIQUE_QUORUM, $result['selected_path']);
        $this->assertFalse($result['stopped_early']);
    }

    public function test_sufficient_evidence_does_not_override_conflicting_evidence(): void
    {
        $result = $this->controller()->control([
            'evidence_quality' => 0.80,
            'ambiguity_score'  => 0.80,
            'risk_score'       => 0.20,
            'has_conflicting_evidence' => true,
            'sufficient_evidence_to_decide' => true,
        ]);

        $this->assertContains(AtlasExternalBrainCognitionCascadeController::STAGE_FRONTIER_REVIEW, $result['selected_path']);
        $this->assertFalse($result['stopped_early']);
    }

    public function test_stopped_early_defaults_to_false(): void
    {
        $result = $this->controller()->control([
            'evidence_quality' => 0.80,
            'ambiguity_score'  => 0.30,
            'risk_score'       => 0.20,
        ]);

        $this->assertFalse($result['stopped_early']);
    }

    // ── AC3: selected_stages, minimum_cost_path, safety_invariants_satisfied ─

    public function test_control_output_includes_selected_stages_and_minimum_cost_path(): void
    {
        $result = $this->controller()->control([
            'evidence_quality' => 0.80,
            'ambiguity_score'  => 0.10,
            'risk_score'       => 0.10,
        ]);

        $this->assertSame($result['selected_path'], $result['selected_stages']);
        $this->assertSame($result['selected_path'], $result['minimum_cost_path']);
    }

    public function test_safety_invariants_satisfied_true_for_safe_local_only_path(): void
    {
        $result = $this->controller()->control([
            'evidence_quality' => 0.80,
            'ambiguity_score'  => 0.10,
            'risk_score'       => 0.10,
        ]);

        $this->assertTrue($result['safety_invariants_satisfied']);
        $this->assertContains(AtlasExternalBrainCognitionCascadeController::STAGE_DETERMINISTIC_PREFLIGHT, $result['selected_stages']);
    }

    public function test_safety_invariants_satisfied_true_when_frontier_correctly_escalated(): void
    {
        $result = $this->controller()->control([
            'evidence_quality'         => 0.80,
            'ambiguity_score'          => 0.80,
            'risk_score'               => 0.20,
            'has_conflicting_evidence' => true,
        ]);

        $this->assertContains(AtlasExternalBrainCognitionCascadeController::STAGE_FRONTIER_REVIEW, $result['selected_stages']);
        $this->assertTrue($result['safety_invariants_satisfied']);
    }
}
