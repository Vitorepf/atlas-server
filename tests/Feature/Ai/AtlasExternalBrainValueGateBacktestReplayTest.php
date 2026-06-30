<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainValueGateBacktestReplay;
use Tests\TestCase;

final class AtlasExternalBrainValueGateBacktestReplayTest extends TestCase
{
    private AtlasExternalBrainValueGateBacktestReplay $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AtlasExternalBrainValueGateBacktestReplay;
    }

    private function green(float $impact = 0.5, float $risk = 0.3): array
    {
        return ['candidate' => ['compound_impact_score' => $impact, 'give_back_risk_score' => $risk], 'outcome' => 'commit_success'];
    }

    private function poison(float $impact = 0.5, float $risk = 0.3, string $type = 'give_back'): array
    {
        return ['candidate' => ['compound_impact_score' => $impact, 'give_back_risk_score' => $risk], 'outcome' => $type];
    }

    private function replay(array $candidates, array $thresholds = [], int $costPerTask = 1000): array
    {
        return $this->service->replay([
            'candidates'          => $candidates,
            'current_thresholds'  => $thresholds,
            'token_cost_per_task' => $costPerTask,
        ]);
    }

    // ── Schema / output shape ─────────────────────────────────────────────────

    public function test_output_has_all_required_keys(): void
    {
        $r = $this->replay([]);

        foreach ([
            'schema', 'would_admit', 'false_reject_green', 'true_reject_poison',
            'missed_poison', 'admitted_green', 'rejected_green', 'admitted_poison',
            'rejected_poison', 'precision', 'recall',
            'estimated_token_waste_avoided', 'recommended_threshold_adjustments',
        ] as $key) {
            $this->assertArrayHasKey($key, $r, "output must have {$key}");
        }
        $this->assertSame(AtlasExternalBrainValueGateBacktestReplay::SCHEMA, $r['schema']);
    }

    // ── AC1: confusion matrix ─────────────────────────────────────────────────

    public function test_admitted_green_counts_tasks_passing_gate_with_success_outcome(): void
    {
        // Admitted: impact=0.5 >= floor 0.30, risk=0.3 < ceiling 0.70. Outcome: green.
        $r = $this->replay([$this->green(0.5, 0.3)]);

        $this->assertSame(1, $r['admitted_green']);
        $this->assertSame(0, $r['rejected_green']);
        $this->assertSame(0, $r['admitted_poison']);
        $this->assertSame(0, $r['rejected_poison']);
    }

    public function test_rejected_green_counts_tasks_blocked_with_success_outcome(): void
    {
        // Below impact floor → rejected, but outcome was green (false reject).
        $r = $this->replay([$this->green(0.1, 0.3)]);

        $this->assertSame(0, $r['admitted_green']);
        $this->assertSame(1, $r['rejected_green']);
        $this->assertSame(0, $r['admitted_poison']);
        $this->assertSame(0, $r['rejected_poison']);
    }

    public function test_admitted_poison_counts_admitted_tasks_with_poison_outcome(): void
    {
        // Passes gate but outcome is give_back → admitted_poison (false positive).
        $r = $this->replay([$this->poison(0.5, 0.3)]);

        $this->assertSame(0, $r['admitted_green']);
        $this->assertSame(0, $r['rejected_green']);
        $this->assertSame(1, $r['admitted_poison']);
        $this->assertSame(0, $r['rejected_poison']);
    }

    public function test_rejected_poison_counts_blocked_tasks_with_poison_outcome(): void
    {
        // Risk above ceiling → rejected. Outcome was poison → rejected_poison (true negative).
        $r = $this->replay([$this->poison(0.5, 0.8)]);

        $this->assertSame(0, $r['admitted_green']);
        $this->assertSame(0, $r['rejected_green']);
        $this->assertSame(0, $r['admitted_poison']);
        $this->assertSame(1, $r['rejected_poison']);
    }

    public function test_full_confusion_matrix_with_mixed_set(): void
    {
        $candidates = [
            $this->green(0.5, 0.3),   // admitted_green
            $this->green(0.5, 0.3),   // admitted_green
            $this->green(0.1, 0.3),   // rejected_green (below floor)
            $this->poison(0.5, 0.3),  // admitted_poison
            $this->poison(0.5, 0.8),  // rejected_poison (risk too high)
            $this->poison(0.5, 0.8),  // rejected_poison
        ];

        $r = $this->replay($candidates);

        $this->assertSame(2, $r['admitted_green']);
        $this->assertSame(1, $r['rejected_green']);
        $this->assertSame(1, $r['admitted_poison']);
        $this->assertSame(2, $r['rejected_poison']);
        // Legacy aliases
        $this->assertSame(3,  $r['would_admit']);        // admitted_green + admitted_poison
        $this->assertSame(1,  $r['false_reject_green']); // same as rejected_green
        $this->assertSame(2,  $r['true_reject_poison']); // same as rejected_poison
        $this->assertSame(1,  $r['missed_poison']);       // same as admitted_poison
    }

    // ── AC2: tighten give_back_risk_ceiling for poison-heavy admissions ───────

    public function test_tighten_risk_ceiling_when_admitted_poison_dominates_admitted_green(): void
    {
        // 3 admitted_poison vs 1 admitted_green → admitted_poison dominates → tighten
        $candidates = [
            $this->green(0.5, 0.3),
            $this->poison(0.5, 0.3),
            $this->poison(0.5, 0.3),
            $this->poison(0.5, 0.3),
        ];

        $r = $this->replay($candidates, ['give_back_risk_ceiling' => 0.70]);

        $adjustments = $r['recommended_threshold_adjustments'];
        $riskAdj = array_values(array_filter($adjustments, fn ($a) => $a['threshold'] === 'give_back_risk_ceiling'));

        $this->assertNotEmpty($riskAdj, 'Expected give_back_risk_ceiling adjustment');
        $this->assertLessThan(0.70, $riskAdj[0]['recommended_value'], 'Ceiling must be tightened (lower)');
        $this->assertSame(round(0.70 - 0.05, 6), $riskAdj[0]['recommended_value']);
    }

    public function test_tighten_risk_ceiling_when_missed_poison_rate_exceeds_ceiling(): void
    {
        // 4 candidates, 2 admitted poison → missed rate = 0.5 > 0.30 ceiling → tighten
        $candidates = [
            $this->green(0.5, 0.3),
            $this->green(0.5, 0.3),
            $this->poison(0.5, 0.3),
            $this->poison(0.5, 0.3),
        ];

        $r = $this->replay($candidates, ['give_back_risk_ceiling' => 0.70]);

        $adjThresholds = array_column($r['recommended_threshold_adjustments'], 'threshold');
        $this->assertContains('give_back_risk_ceiling', $adjThresholds);
    }

    public function test_no_tighten_when_rejected_dominates_and_precision_adequate(): void
    {
        // More rejected_poison than admitted_poison, precision high → exempt from tightening
        $candidates = [
            $this->green(0.5, 0.3),   // admitted_green
            $this->green(0.5, 0.3),   // admitted_green
            $this->green(0.5, 0.3),   // admitted_green
            $this->poison(0.5, 0.8),  // rejected_poison
            $this->poison(0.5, 0.8),  // rejected_poison
            $this->poison(0.5, 0.8),  // rejected_poison
        ];

        $r = $this->replay($candidates, ['give_back_risk_ceiling' => 0.70]);

        $adjThresholds = array_column($r['recommended_threshold_adjustments'], 'threshold');
        $this->assertNotContains('give_back_risk_ceiling', $adjThresholds);
    }

    // ── AC3: estimated_token_waste_avoided ────────────────────────────────────

    public function test_token_waste_avoided_is_rejected_poison_times_cost_per_task(): void
    {
        // 3 rejected_poison, cost=500 → 1500
        $candidates = [
            $this->poison(0.5, 0.8),
            $this->poison(0.5, 0.8),
            $this->poison(0.5, 0.8),
        ];

        $r = $this->replay($candidates, [], 500);

        $this->assertSame(3, $r['rejected_poison']);
        $this->assertSame(1500, $r['estimated_token_waste_avoided']);
    }

    public function test_token_waste_avoided_zero_when_no_rejected_poison(): void
    {
        $r = $this->replay([$this->green()], [], 1000);
        $this->assertSame(0, $r['estimated_token_waste_avoided']);
    }

    public function test_token_waste_uses_default_cost_when_not_supplied(): void
    {
        $candidates = [$this->poison(0.5, 0.8)]; // 1 rejected_poison
        $r = $this->service->replay(['candidates' => $candidates]);

        $this->assertSame(1000, $r['estimated_token_waste_avoided']); // default cost = 1000
    }

    // ── AC4: pure and deterministic ───────────────────────────────────────────

    public function test_replay_is_deterministic(): void
    {
        $candidates = [
            $this->green(0.5, 0.3),
            $this->poison(0.5, 0.3),
            $this->poison(0.5, 0.8),
        ];

        $a = $this->replay($candidates);
        $b = $this->replay($candidates);

        $this->assertSame(json_encode($a), json_encode($b));
    }

    public function test_empty_candidates_produces_zero_counts(): void
    {
        $r = $this->replay([]);

        $this->assertSame(0, $r['admitted_green']);
        $this->assertSame(0, $r['rejected_green']);
        $this->assertSame(0, $r['admitted_poison']);
        $this->assertSame(0, $r['rejected_poison']);
        $this->assertSame(0, $r['estimated_token_waste_avoided']);
        $this->assertSame([], $r['recommended_threshold_adjustments']);
    }
}
