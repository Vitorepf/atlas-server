<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainCompoundingOutcomeRouter;
use Tests\TestCase;

final class AtlasExternalBrainCompoundingOutcomeRouterTest extends TestCase
{
    private AtlasExternalBrainCompoundingOutcomeRouter $router;

    protected function setUp(): void
    {
        parent::setUp();
        $this->router = new AtlasExternalBrainCompoundingOutcomeRouter;
    }

    private function baseOutcome(array $overrides = []): array
    {
        return array_merge([
            'outcome_type'   => 'new_capability',
            'wired_callers_count' => 2,
            'evidence_refs'  => ['integration:organ_health_scorer_test'],
        ], $overrides);
    }

    // ── AC1: success, give_back, poison, quarantine route to different effects

    public function test_ac1_success_outcome_produces_promote_pattern_effect(): void
    {
        $result = $this->router->route($this->baseOutcome([
            'worker_outcome' => 'success',
            'confidence'     => 0.6,
        ]));

        $this->assertSame(
            AtlasExternalBrainCompoundingOutcomeRouter::EFFECT_PROMOTE_PATTERN,
            $result['next_decision_effect'],
        );
    }

    public function test_ac1_give_back_outcome_produces_requeue_spec_repair_effect(): void
    {
        $result = $this->router->route($this->baseOutcome([
            'worker_outcome'          => 'give_back',
            'negative_outcome_streak' => 1,
        ]));

        $this->assertSame(
            AtlasExternalBrainCompoundingOutcomeRouter::EFFECT_REQUEUE_SPEC_REPAIR,
            $result['next_decision_effect'],
        );
    }

    public function test_ac1_poison_outcome_produces_requeue_spec_repair_effect(): void
    {
        $result = $this->router->route($this->baseOutcome([
            'worker_outcome'          => 'poison',
            'negative_outcome_streak' => 1,
        ]));

        $this->assertSame(
            AtlasExternalBrainCompoundingOutcomeRouter::EFFECT_REQUEUE_SPEC_REPAIR,
            $result['next_decision_effect'],
        );
    }

    public function test_ac1_quarantine_outcome_produces_avoid_pattern_effect(): void
    {
        $result = $this->router->route($this->baseOutcome([
            'worker_outcome'          => 'quarantine',
            'negative_outcome_streak' => 1,
        ]));

        $this->assertSame(
            AtlasExternalBrainCompoundingOutcomeRouter::EFFECT_AVOID_PATTERN,
            $result['next_decision_effect'],
        );
    }

    public function test_ac1_all_four_worker_outcomes_produce_distinct_effects(): void
    {
        // Use inputs that naturally differentiate: success=mid-confidence, give_back=low streak,
        // poison=threshold streak (→ avoid_pattern), quarantine=threshold streak (→ self_heal).
        $inputs = [
            'success'    => ['worker_outcome' => 'success',    'confidence' => 0.6, 'negative_outcome_streak' => 0],
            'give_back'  => ['worker_outcome' => 'give_back',  'confidence' => 0.0, 'negative_outcome_streak' => 1],
            'poison'     => ['worker_outcome' => 'poison',     'confidence' => 0.0, 'negative_outcome_streak' => 3],
            'quarantine' => ['worker_outcome' => 'quarantine', 'confidence' => 0.0, 'negative_outcome_streak' => 3],
        ];

        $effects = [];
        foreach ($inputs as $workerOutcome => $extra) {
            $r = $this->router->route($this->baseOutcome($extra));
            $effects[$workerOutcome] = $r['next_decision_effect'];
        }

        $this->assertCount(4, array_unique(array_values($effects)), 'all four worker outcomes must map to distinct effects');
    }

    // ── AC2: repeated negative outcomes produce avoid_pattern or self_heal

    public function test_ac2_poison_streak_gte_threshold_produces_avoid_pattern(): void
    {
        $result = $this->router->route($this->baseOutcome([
            'worker_outcome'          => 'poison',
            'negative_outcome_streak' => 3,
        ]));

        $this->assertSame(
            AtlasExternalBrainCompoundingOutcomeRouter::EFFECT_AVOID_PATTERN,
            $result['next_decision_effect'],
        );
    }

    public function test_ac2_give_back_streak_gte_threshold_produces_self_heal(): void
    {
        $result = $this->router->route($this->baseOutcome([
            'worker_outcome'          => 'give_back',
            'negative_outcome_streak' => 4,
        ]));

        $this->assertSame(
            AtlasExternalBrainCompoundingOutcomeRouter::EFFECT_SELF_HEAL,
            $result['next_decision_effect'],
        );
    }

    public function test_ac2_quarantine_streak_gte_threshold_produces_self_heal(): void
    {
        $result = $this->router->route($this->baseOutcome([
            'worker_outcome'          => 'quarantine',
            'negative_outcome_streak' => 5,
        ]));

        $this->assertSame(
            AtlasExternalBrainCompoundingOutcomeRouter::EFFECT_SELF_HEAL,
            $result['next_decision_effect'],
        );
    }

    public function test_ac2_streak_below_threshold_does_not_produce_avoid_or_self_heal(): void
    {
        $result = $this->router->route($this->baseOutcome([
            'worker_outcome'          => 'poison',
            'negative_outcome_streak' => 2,
        ]));

        $this->assertNotSame(
            AtlasExternalBrainCompoundingOutcomeRouter::EFFECT_AVOID_PATTERN,
            $result['next_decision_effect'],
        );
    }

    // ── AC3: high-confidence positive outcomes produce promote_pattern or compound_next_batch

    public function test_ac3_high_confidence_success_produces_compound_next_batch(): void
    {
        $result = $this->router->route($this->baseOutcome([
            'worker_outcome' => 'success',
            'confidence'     => 0.9,
        ]));

        $this->assertSame(
            AtlasExternalBrainCompoundingOutcomeRouter::EFFECT_COMPOUND_NEXT_BATCH,
            $result['next_decision_effect'],
        );
    }

    public function test_ac3_mid_confidence_success_produces_promote_pattern(): void
    {
        $result = $this->router->route($this->baseOutcome([
            'worker_outcome' => 'success',
            'confidence'     => 0.65,
        ]));

        $this->assertSame(
            AtlasExternalBrainCompoundingOutcomeRouter::EFFECT_PROMOTE_PATTERN,
            $result['next_decision_effect'],
        );
    }

    public function test_ac3_low_confidence_success_produces_none_effect(): void
    {
        $result = $this->router->route($this->baseOutcome([
            'worker_outcome' => 'success',
            'confidence'     => 0.3,
        ]));

        $this->assertSame(
            AtlasExternalBrainCompoundingOutcomeRouter::EFFECT_NONE,
            $result['next_decision_effect'],
        );
    }

    public function test_ac3_exact_high_confidence_threshold_produces_compound_next_batch(): void
    {
        $result = $this->router->route($this->baseOutcome([
            'worker_outcome' => 'success',
            'confidence'     => 0.8,
        ]));

        $this->assertSame(
            AtlasExternalBrainCompoundingOutcomeRouter::EFFECT_COMPOUND_NEXT_BATCH,
            $result['next_decision_effect'],
        );
    }

    // ── AC4: pure and deterministic

    public function test_ac4_output_includes_next_decision_effect_field(): void
    {
        $result = $this->router->route($this->baseOutcome(['worker_outcome' => 'success', 'confidence' => 0.9]));

        $this->assertArrayHasKey('next_decision_effect', $result);
        $this->assertIsString($result['next_decision_effect']);
        $this->assertNotEmpty($result['next_decision_effect']);
    }

    public function test_ac4_same_input_produces_identical_output(): void
    {
        $outcome = $this->baseOutcome([
            'worker_outcome'          => 'poison',
            'negative_outcome_streak' => 3,
        ]);

        $this->assertSame(
            $this->router->route($outcome),
            $this->router->route($outcome),
        );
    }

    public function test_ac4_absent_worker_outcome_produces_none_effect(): void
    {
        $result = $this->router->route($this->baseOutcome());

        $this->assertSame(
            AtlasExternalBrainCompoundingOutcomeRouter::EFFECT_NONE,
            $result['next_decision_effect'],
        );
    }
}
