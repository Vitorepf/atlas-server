<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainModelEscalationEconomyPolicy;
use Tests\TestCase;

final class AtlasExternalBrainModelEscalationEconomyPolicyTest extends TestCase
{
    private function policy(): AtlasExternalBrainModelEscalationEconomyPolicy
    {
        return new AtlasExternalBrainModelEscalationEconomyPolicy();
    }

    private function clearInput(array $overrides = []): array
    {
        return array_merge([
            'ambiguity_score'    => 0.20,
            'evidence_quality'   => 0.85,
            'scaffold_confidence' => 0.80,
        ], $overrides);
    }

    // ── schema ────────────────────────────────────────────────────────────────

    public function test_schema_present(): void
    {
        $result = $this->policy()->decide($this->clearInput());
        $this->assertSame(AtlasExternalBrainModelEscalationEconomyPolicy::SCHEMA, $result['schema']);
    }

    // ── small_model (rule 4) ──────────────────────────────────────────────────

    public function test_small_model_for_clear_low_ambiguity_strong_evidence(): void
    {
        $result = $this->policy()->decide($this->clearInput());

        $this->assertSame(AtlasExternalBrainModelEscalationEconomyPolicy::DECISION_SMALL_MODEL, $result['decision']);
        $this->assertTrue($result['anti_over_escalation']);
    }

    public function test_small_model_anti_over_escalation_is_true(): void
    {
        $result = $this->policy()->decide($this->clearInput());
        $this->assertTrue($result['anti_over_escalation']);
    }

    // ── small_model_with_scaffold (rule 6 default) ─────────────────────────────
    // Since 0d7c967b0 the default decision is DECISION_SMALL_MODEL_WITH_SCAFFOLD
    // (+ scaffold_contract); DECISION_SCAFFOLDED_SMALL_MODEL became the degraded
    // fallback when frontier is unavailable.

    public function test_scaffolded_default_for_moderate_ambiguity(): void
    {
        $result = $this->policy()->decide([
            'ambiguity_score'    => 0.50,
            'evidence_quality'   => 0.75,
            'scaffold_confidence' => 0.70,
        ]);

        $this->assertSame(AtlasExternalBrainModelEscalationEconomyPolicy::DECISION_SMALL_MODEL_WITH_SCAFFOLD, $result['decision']);
        $this->assertTrue($result['anti_over_escalation']);
        $this->assertArrayHasKey('scaffold_contract', $result);
    }

    public function test_scaffolded_when_ambiguity_low_but_evidence_insufficient(): void
    {
        // ambiguity ok but evidence_quality below floor
        $result = $this->policy()->decide([
            'ambiguity_score'    => 0.20,
            'evidence_quality'   => 0.50,
            'scaffold_confidence' => 0.80,
        ]);

        $this->assertSame(AtlasExternalBrainModelEscalationEconomyPolicy::DECISION_SMALL_MODEL_WITH_SCAFFOLD, $result['decision']);
    }

    public function test_scaffolded_small_model_is_degraded_fallback_when_frontier_unavailable(): void
    {
        $result = $this->policy()->decide([
            'ambiguity_score'     => 0.50,
            'evidence_quality'    => 0.75,
            'scaffold_confidence' => 0.70,
            'frontier_available'  => false,
        ]);

        $this->assertSame(AtlasExternalBrainModelEscalationEconomyPolicy::DECISION_SCAFFOLDED_SMALL_MODEL, $result['decision']);
    }

    // ── frontier_model (rule 2) — high ambiguity ──────────────────────────────

    public function test_frontier_when_ambiguity_high(): void
    {
        $result = $this->policy()->decide([
            'ambiguity_score'    => 0.75,
            'evidence_quality'   => 0.80,
            'scaffold_confidence' => 0.80,
        ]);

        $this->assertSame(AtlasExternalBrainModelEscalationEconomyPolicy::DECISION_FRONTIER_MODEL, $result['decision']);
        $this->assertFalse($result['anti_over_escalation']);
        $this->assertArrayHasKey('escalation_reason', $result);
    }

    public function test_frontier_when_conflicting_evidence(): void
    {
        $result = $this->policy()->decide([
            'ambiguity_score'      => 0.20,
            'evidence_quality'     => 0.85,
            'scaffold_confidence'  => 0.80,
            'conflicting_evidence' => true,
        ]);

        $this->assertSame(AtlasExternalBrainModelEscalationEconomyPolicy::DECISION_FRONTIER_MODEL, $result['decision']);
        $this->assertStringContainsString('conflicting_evidence', $result['escalation_reason']);
    }

    // ── frontier_model (rule 3) — high leverage + low scaffold confidence ─────

    public function test_frontier_when_high_leverage_and_low_scaffold_confidence(): void
    {
        $result = $this->policy()->decide([
            'ambiguity_score'    => 0.30,
            'evidence_quality'   => 0.80,
            'scaffold_confidence' => 0.40,
            'leverage_score'     => 0.90,
        ]);

        $this->assertSame(AtlasExternalBrainModelEscalationEconomyPolicy::DECISION_FRONTIER_MODEL, $result['decision']);
        $this->assertStringContainsString('leverage', $result['escalation_reason']);
    }

    public function test_not_frontier_when_high_leverage_but_high_scaffold_confidence(): void
    {
        // leverage high but scaffold is confident enough — no escalation needed
        $result = $this->policy()->decide([
            'ambiguity_score'    => 0.20,
            'evidence_quality'   => 0.85,
            'scaffold_confidence' => 0.90,
            'leverage_score'     => 0.90,
        ]);

        $this->assertNotSame(AtlasExternalBrainModelEscalationEconomyPolicy::DECISION_FRONTIER_MODEL, $result['decision']);
    }

    // ── frontier unavailable (rule 1) ─────────────────────────────────────────

    public function test_scaffolded_when_frontier_unavailable(): void
    {
        $result = $this->policy()->decide([
            'ambiguity_score'    => 0.80,
            'evidence_quality'   => 0.30,
            'scaffold_confidence' => 0.30,
            'frontier_available' => false,
        ]);

        $this->assertSame(AtlasExternalBrainModelEscalationEconomyPolicy::DECISION_SCAFFOLDED_SMALL_MODEL, $result['decision']);
        $this->assertArrayHasKey('degradation_risk', $result);
        $this->assertTrue($result['anti_over_escalation']);
    }

    public function test_frontier_unavailable_does_not_block_autonomy(): void
    {
        // Even with all signals pointing to frontier, if unavailable → graceful degradation
        $result = $this->policy()->decide([
            'ambiguity_score'      => 0.90,
            'evidence_quality'     => 0.10,
            'scaffold_confidence'  => 0.10,
            'conflicting_evidence' => true,
            'frontier_available'   => false,
        ]);

        $this->assertNotSame(AtlasExternalBrainModelEscalationEconomyPolicy::DECISION_FRONTIER_MODEL, $result['decision']);
        $this->assertArrayHasKey('degradation_risk', $result);
    }

    // ── expected_quality_delta passthrough ────────────────────────────────────

    public function test_expected_quality_delta_passed_through(): void
    {
        $result = $this->policy()->decide(array_merge($this->clearInput(), [
            'expected_quality_delta' => 0.35,
        ]));

        $this->assertArrayHasKey('expected_quality_delta', $result);
        $this->assertSame(0.35, $result['expected_quality_delta']);
    }

    public function test_no_quality_delta_key_when_not_provided(): void
    {
        $result = $this->policy()->decide($this->clearInput());
        $this->assertArrayNotHasKey('expected_quality_delta', $result);
    }

    // ── custom thresholds ─────────────────────────────────────────────────────

    public function test_custom_high_ambiguity_threshold_respected(): void
    {
        // With threshold=0.50, ambiguity=0.60 should escalate
        $result = $this->policy()->decide([
            'ambiguity_score'    => 0.60,
            'evidence_quality'   => 0.80,
            'scaffold_confidence' => 0.80,
            'thresholds'         => ['high_ambiguity_threshold' => 0.50],
        ]);

        $this->assertSame(AtlasExternalBrainModelEscalationEconomyPolicy::DECISION_FRONTIER_MODEL, $result['decision']);
    }

    // ── determinism ──────────────────────────────────────────────────────────

    public function test_identical_input_yields_identical_output(): void
    {
        $input = [
            'ambiguity_score'    => 0.65,
            'evidence_quality'   => 0.75,
            'scaffold_confidence' => 0.60,
        ];

        $this->assertSame($this->policy()->decide($input), $this->policy()->decide($input));
    }

    // ── AC2: defer_for_more_evidence (opt-in) ────────────────────────────────

    public function test_defer_for_weak_evidence_when_opted_in(): void
    {
        $result = $this->policy()->decide([
            'ambiguity_score'               => 0.20,
            'evidence_quality'              => 0.40,
            'scaffold_confidence'           => 0.80,
            'prefer_defer_for_weak_evidence' => true,
        ]);

        $this->assertSame(AtlasExternalBrainModelEscalationEconomyPolicy::DECISION_DEFER_FOR_MORE_EVIDENCE, $result['decision']);
        $this->assertTrue($result['anti_over_escalation']);
    }

    public function test_no_defer_when_not_opted_in(): void
    {
        // Same low-evidence input without the flag -> scaffolded_small_model (existing behaviour preserved)
        $result = $this->policy()->decide([
            'ambiguity_score'    => 0.20,
            'evidence_quality'   => 0.40,
            'scaffold_confidence' => 0.80,
        ]);

        $this->assertNotSame(AtlasExternalBrainModelEscalationEconomyPolicy::DECISION_DEFER_FOR_MORE_EVIDENCE, $result['decision']);
    }

    public function test_defer_not_triggered_when_evidence_meets_floor(): void
    {
        $result = $this->policy()->decide([
            'ambiguity_score'               => 0.20,
            'evidence_quality'              => 0.85, // >= floor
            'scaffold_confidence'           => 0.80,
            'prefer_defer_for_weak_evidence' => true,
        ]);

        $this->assertNotSame(AtlasExternalBrainModelEscalationEconomyPolicy::DECISION_DEFER_FOR_MORE_EVIDENCE, $result['decision']);
    }

    public function test_defer_not_triggered_when_frontier_triggered_first(): void
    {
        // High ambiguity -> frontier wins over defer (rule 2 before rule 4)
        $result = $this->policy()->decide([
            'ambiguity_score'               => 0.80,
            'evidence_quality'              => 0.40,
            'scaffold_confidence'           => 0.80,
            'prefer_defer_for_weak_evidence' => true,
        ]);

        $this->assertSame(AtlasExternalBrainModelEscalationEconomyPolicy::DECISION_FRONTIER_MODEL, $result['decision']);
    }

    // ── AC3: quality delta gates leverage-path frontier ──────────────────────

    public function test_frontier_blocked_when_quality_delta_below_minimum(): void
    {
        // High leverage + low scaffold, but quality delta is tiny -> should not escalate to frontier
        $result = $this->policy()->decide([
            'ambiguity_score'        => 0.30,
            'evidence_quality'       => 0.80,
            'scaffold_confidence'    => 0.40,
            'leverage_score'         => 0.90,
            'expected_quality_delta' => 0.05, // below default 0.20
        ]);

        $this->assertNotSame(AtlasExternalBrainModelEscalationEconomyPolicy::DECISION_FRONTIER_MODEL, $result['decision']);
    }

    public function test_frontier_chosen_when_quality_delta_meets_minimum(): void
    {
        $result = $this->policy()->decide([
            'ambiguity_score'        => 0.30,
            'evidence_quality'       => 0.80,
            'scaffold_confidence'    => 0.40,
            'leverage_score'         => 0.90,
            'expected_quality_delta' => 0.25, // above default 0.20
        ]);

        $this->assertSame(AtlasExternalBrainModelEscalationEconomyPolicy::DECISION_FRONTIER_MODEL, $result['decision']);
    }

    public function test_defer_for_more_evidence_constant_exists(): void
    {
        $this->assertSame('defer_for_more_evidence', AtlasExternalBrainModelEscalationEconomyPolicy::DECISION_DEFER_FOR_MORE_EVIDENCE);
    }

    // ── economyEscalation: escalation_decision, cost_justification, lower_tier_evidence, expected_capability_gain ──

    public function test_lower_tier_meets_floor_blocks_escalation(): void
    {
        $r = $this->policy()->economyEscalation([
            'lower_tier_quality' => 0.80,
            'quality_floor' => 0.70,
            'task_impact' => 0.9,
            'lower_tier_attempts' => 3,
            'replayable_evidence' => ['test_pass'],
            'expected_capability_gain' => 0.3,
            'frontier_cost' => 10.0,
            'lower_tier_cost' => 1.0,
        ]);
        $this->assertStringContainsString('deny_escalation', $r['escalation_decision']);
        $this->assertStringContainsString('cost_ratio', $r['cost_justification']);
        $this->assertTrue($r['lower_tier_evidence']['meets_floor']);
        $this->assertSame(0.3, $r['expected_capability_gain']);
    }

    public function test_high_impact_with_replayable_evidence_allows_escalation(): void
    {
        $r = $this->policy()->economyEscalation([
            'lower_tier_quality' => 0.40,
            'quality_floor' => 0.70,
            'task_impact' => 0.9,
            'lower_tier_attempts' => 3,
            'replayable_evidence' => ['test_fail', 'give_back'],
            'expected_capability_gain' => 0.5,
            'frontier_cost' => 10.0,
            'lower_tier_cost' => 1.0,
        ]);
        $this->assertStringContainsString('allow_escalation', $r['escalation_decision']);
        $this->assertFalse($r['lower_tier_evidence']['meets_floor']);
    }

    public function test_high_impact_without_replayable_evidence_conditional(): void
    {
        $r = $this->policy()->economyEscalation([
            'lower_tier_quality' => 0.40,
            'quality_floor' => 0.70,
            'task_impact' => 0.9,
            'lower_tier_attempts' => 3,
            'replayable_evidence' => [],
            'expected_capability_gain' => 0.5,
            'frontier_cost' => 10.0,
            'lower_tier_cost' => 1.0,
        ]);
        $this->assertStringContainsString('conditional_escalation', $r['escalation_decision']);
    }

    public function test_low_impact_denies_escalation(): void
    {
        $r = $this->policy()->economyEscalation([
            'lower_tier_quality' => 0.40,
            'quality_floor' => 0.70,
            'task_impact' => 0.3,
            'lower_tier_attempts' => 1,
            'replayable_evidence' => [],
            'expected_capability_gain' => 0.1,
            'frontier_cost' => 10.0,
            'lower_tier_cost' => 1.0,
        ]);
        $this->assertStringContainsString('deny_escalation', $r['escalation_decision']);
    }
}
