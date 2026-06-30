<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainCounterfactualBatchEvaluator;
use Tests\TestCase;

final class AtlasExternalBrainCounterfactualBatchEvaluatorTest extends TestCase
{
    private function svc(): AtlasExternalBrainCounterfactualBatchEvaluator
    {
        return new AtlasExternalBrainCounterfactualBatchEvaluator;
    }

    private function batch(float $leverage = 8.0, float $risk = 3.0, float $backlog = 10.0, int $unlocks = 5, float $evidence = 8.0, float $impl = 8.0): array
    {
        return [
            'leverage_score' => $leverage,
            'risk_score' => $risk,
            'backlog_cost' => $backlog,
            'downstream_unlocks' => $unlocks,
            'evidence_strength' => $evidence,
            'implementability' => $impl,
        ];
    }

    // ── regret levels ─────────────────────────────────────────────────────────

    public function test_chosen_dominates_gives_low_regret(): void
    {
        $r = $this->svc()->evaluate([
            'chosen_batch' => $this->batch(8.0, 3.0, 10.0, 5, 8.0, 8.0),
            'alternatives' => [$this->batch(6.0, 5.0, 15.0, 3)],
        ]);

        $this->assertSame(AtlasExternalBrainCounterfactualBatchEvaluator::REGRET_LOW, $r['decision_regret_level']);
    }

    public function test_alternative_beats_on_unlocks_and_risk_gives_high_regret(): void
    {
        // alternative: more unlocks (10 > 5) AND lower risk (1 < 3)
        $r = $this->svc()->evaluate([
            'chosen_batch' => $this->batch(8.0, 3.0, 10.0, 5),
            'alternatives' => [$this->batch(7.0, 1.0, 12.0, 10)],
        ]);

        $this->assertSame(AtlasExternalBrainCounterfactualBatchEvaluator::REGRET_HIGH, $r['decision_regret_level']);
    }

    public function test_medium_regret_when_chosen_evidence_too_low(): void
    {
        // chosen doesn't meet quality floor on evidence (5.0 < 7.0)
        $r = $this->svc()->evaluate([
            'chosen_batch' => $this->batch(8.0, 3.0, 10.0, 5, 5.0, 8.0),
            'alternatives' => [$this->batch(6.0, 5.0, 15.0, 3)],
        ]);

        $this->assertSame(AtlasExternalBrainCounterfactualBatchEvaluator::REGRET_MEDIUM, $r['decision_regret_level']);
    }

    public function test_medium_regret_when_delta_leverage_negative(): void
    {
        // alternative has higher leverage (9 > 8) → deltaLeverage negative → medium
        $r = $this->svc()->evaluate([
            'chosen_batch' => $this->batch(8.0, 3.0, 10.0, 5, 8.0, 8.0),
            'alternatives' => [$this->batch(9.0, 5.0, 15.0, 3)],
        ]);

        $this->assertSame(AtlasExternalBrainCounterfactualBatchEvaluator::REGRET_MEDIUM, $r['decision_regret_level']);
    }

    // ── delta calculations ────────────────────────────────────────────────────

    public function test_delta_leverage_positive_when_chosen_has_more_leverage(): void
    {
        $r = $this->svc()->evaluate([
            'chosen_batch' => $this->batch(9.0),
            'alternatives' => [$this->batch(6.0)],
        ]);

        $this->assertGreaterThan(0.0, $r['delta_leverage']);
        $this->assertEqualsWithDelta(3.0, $r['delta_leverage'], 0.01);
    }

    public function test_delta_leverage_negative_when_alternative_has_more_leverage(): void
    {
        $r = $this->svc()->evaluate([
            'chosen_batch' => $this->batch(5.0),
            'alternatives' => [$this->batch(8.0)],
        ]);

        $this->assertLessThan(0.0, $r['delta_leverage']);
    }

    public function test_delta_risk_negative_when_chosen_is_less_risky(): void
    {
        // chosen risk=2, alt risk=6 → delta = 2-6 = -4 (negative = chosen better)
        $r = $this->svc()->evaluate([
            'chosen_batch' => $this->batch(8.0, 2.0),
            'alternatives' => [$this->batch(8.0, 6.0)],
        ]);

        $this->assertLessThan(0.0, $r['delta_risk']);
        $this->assertEqualsWithDelta(-4.0, $r['delta_risk'], 0.01);
    }

    public function test_missed_unlocks_is_difference_from_best_alternative(): void
    {
        // chosen=5 unlocks, best alt=12 → missed=7
        $r = $this->svc()->evaluate([
            'chosen_batch' => $this->batch(8.0, 3.0, 10.0, 5),
            'alternatives' => [
                $this->batch(7.0, 4.0, 11.0, 8),
                $this->batch(6.0, 5.0, 12.0, 12),
            ],
        ]);

        $this->assertSame(7, $r['missed_unlocks']);
    }

    public function test_missed_unlocks_zero_when_chosen_has_most_unlocks(): void
    {
        $r = $this->svc()->evaluate([
            'chosen_batch' => $this->batch(8.0, 3.0, 10.0, 20),
            'alternatives' => [$this->batch(7.0, 4.0, 11.0, 10)],
        ]);

        $this->assertSame(0, $r['missed_unlocks']);
    }

    public function test_delta_backlog_cost_negative_when_chosen_is_cheaper(): void
    {
        // chosen=5, alt=15 → delta=-10
        $r = $this->svc()->evaluate([
            'chosen_batch' => $this->batch(8.0, 3.0, 5.0),
            'alternatives' => [$this->batch(8.0, 3.0, 15.0)],
        ]);

        $this->assertEqualsWithDelta(-10.0, $r['delta_backlog_cost'], 0.01);
    }

    // ── edge cases ────────────────────────────────────────────────────────────

    public function test_no_alternatives_gives_low_regret_and_zero_deltas(): void
    {
        $r = $this->svc()->evaluate([
            'chosen_batch' => $this->batch(),
            'alternatives' => [],
        ]);

        $this->assertSame(AtlasExternalBrainCounterfactualBatchEvaluator::REGRET_LOW, $r['decision_regret_level']);
        $this->assertSame(0.0, $r['delta_leverage']);
        $this->assertSame(0, $r['missed_unlocks']);
        $this->assertSame(0, $r['comparison_count']);
    }

    public function test_schema_version_and_comparison_count_present(): void
    {
        $r = $this->svc()->evaluate([
            'chosen_batch' => $this->batch(),
            'alternatives' => [$this->batch(6.0), $this->batch(5.0)],
        ]);

        $this->assertSame(AtlasExternalBrainCounterfactualBatchEvaluator::SCHEMA, $r['schema_version']);
        $this->assertSame(2, $r['comparison_count']);
    }
}
