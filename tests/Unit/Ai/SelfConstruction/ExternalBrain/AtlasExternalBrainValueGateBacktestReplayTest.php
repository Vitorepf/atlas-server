<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainValueGateBacktestReplay;
use Tests\TestCase;

final class AtlasExternalBrainValueGateBacktestReplayTest extends TestCase
{
    private function replay(): AtlasExternalBrainValueGateBacktestReplay
    {
        return new AtlasExternalBrainValueGateBacktestReplay();
    }

    private function entry(string $outcome, float $impact = 0.80, float $risk = 0.20): array
    {
        return [
            'candidate' => [
                'target'                => 'AtlasFooService',
                'compound_impact_score' => $impact,
                'give_back_risk_score'  => $risk,
            ],
            'outcome' => $outcome,
        ];
    }

    // ── schema ────────────────────────────────────────────────────────────────

    public function test_schema_present(): void
    {
        $result = $this->replay()->replay(['candidates' => []]);
        $this->assertSame(AtlasExternalBrainValueGateBacktestReplay::SCHEMA, $result['schema']);
    }

    // ── empty input ───────────────────────────────────────────────────────────

    public function test_empty_candidates_all_zeros(): void
    {
        $result = $this->replay()->replay(['candidates' => []]);

        $this->assertSame(0, $result['would_admit']);
        $this->assertSame(0, $result['false_reject_green']);
        $this->assertSame(0, $result['true_reject_poison']);
        $this->assertSame(0, $result['missed_poison']);
        $this->assertSame([], $result['recommended_threshold_adjustments']);
    }

    // ── would_admit ───────────────────────────────────────────────────────────

    public function test_would_admit_counts_gate_passing_candidates(): void
    {
        $result = $this->replay()->replay(['candidates' => [
            $this->entry('commit_success', 0.80, 0.20),  // admitted
            $this->entry('commit_success', 0.80, 0.20),  // admitted
            $this->entry('commit_success', 0.10, 0.20),  // rejected (low impact)
        ]]);

        $this->assertSame(2, $result['would_admit']);
    }

    // ── false_reject_green ────────────────────────────────────────────────────

    public function test_false_reject_green_counts_rejected_successful_tasks(): void
    {
        $result = $this->replay()->replay(['candidates' => [
            // impact below floor (0.30) but outcome green → false reject
            $this->entry('commit_success', 0.10, 0.20),
        ]]);

        $this->assertSame(1, $result['false_reject_green']);
        $this->assertSame(0, $result['would_admit']);
    }

    // ── true_reject_poison ────────────────────────────────────────────────────

    public function test_true_reject_poison_on_give_back(): void
    {
        $result = $this->replay()->replay(['candidates' => [
            // rejected (low impact) AND give_back → correct rejection
            $this->entry('give_back', 0.10, 0.20),
        ]]);

        $this->assertSame(1, $result['true_reject_poison']);
        $this->assertSame(0, $result['missed_poison']);
    }

    public function test_true_reject_poison_on_poison(): void
    {
        $result = $this->replay()->replay(['candidates' => [
            $this->entry('poison', 0.10, 0.80),
        ]]);

        $this->assertSame(1, $result['true_reject_poison']);
    }

    // ── missed_poison ─────────────────────────────────────────────────────────

    public function test_missed_poison_when_admitted_but_give_back(): void
    {
        $result = $this->replay()->replay(['candidates' => [
            // admitted (impact ok, risk low) but gave back → missed
            $this->entry('give_back', 0.80, 0.20),
        ]]);

        $this->assertSame(1, $result['missed_poison']);
        $this->assertSame(1, $result['would_admit']);
    }

    public function test_missed_poison_when_admitted_but_poison(): void
    {
        $result = $this->replay()->replay(['candidates' => [
            $this->entry('poison', 0.80, 0.20),
        ]]);

        $this->assertSame(1, $result['missed_poison']);
    }

    // ── threshold adjustments — tighten risk ceiling ──────────────────────────

    public function test_recommends_lower_risk_ceiling_when_missed_poison_rate_high(): void
    {
        // 4 admitted → 2 give_back → missed rate = 0.50 > 0.30
        $candidates = array_merge(
            array_fill(0, 2, $this->entry('give_back', 0.80, 0.20)),
            array_fill(0, 2, $this->entry('commit_success', 0.80, 0.20)),
        );

        $result = $this->replay()->replay(['candidates' => $candidates]);

        $thresholds = array_column($result['recommended_threshold_adjustments'], 'threshold');
        $this->assertContains('give_back_risk_ceiling', $thresholds);
    }

    public function test_recommended_risk_ceiling_is_lower_than_current(): void
    {
        $candidates = array_fill(0, 4, $this->entry('give_back', 0.80, 0.20));

        $result = $this->replay()->replay([
            'candidates'          => $candidates,
            'current_thresholds'  => ['give_back_risk_ceiling' => 0.70],
        ]);

        $adj = array_filter(
            $result['recommended_threshold_adjustments'],
            fn(array $a): bool => $a['threshold'] === 'give_back_risk_ceiling'
        );
        $adj = array_values($adj)[0] ?? null;

        $this->assertNotNull($adj);
        $this->assertLessThan($adj['current_value'], $adj['recommended_value']);
    }

    // ── threshold adjustments — loosen impact floor ───────────────────────────

    public function test_recommends_lower_impact_floor_when_false_reject_rate_high(): void
    {
        // 5 entries: 2 false rejects (green but rejected) → rate = 0.40 > 0.20
        $candidates = array_merge(
            array_fill(0, 2, $this->entry('commit_success', 0.10, 0.20)), // rejected green
            array_fill(0, 3, $this->entry('commit_success', 0.80, 0.20)), // admitted green
        );

        $result = $this->replay()->replay(['candidates' => $candidates]);

        $thresholds = array_column($result['recommended_threshold_adjustments'], 'threshold');
        $this->assertContains('compound_impact_floor', $thresholds);
    }

    public function test_no_adjustment_when_metrics_within_tolerances(): void
    {
        // All succeed and all admitted → no adjustments needed
        $candidates = array_fill(0, 5, $this->entry('commit_success', 0.80, 0.20));

        $result = $this->replay()->replay(['candidates' => $candidates]);

        $this->assertSame([], $result['recommended_threshold_adjustments']);
    }

    // ── custom thresholds ─────────────────────────────────────────────────────

    public function test_custom_thresholds_used_for_admission(): void
    {
        // With risk_ceiling=0.10, risk=0.20 → rejected even though default would admit
        $result = $this->replay()->replay([
            'candidates'         => [$this->entry('commit_success', 0.80, 0.20)],
            'current_thresholds' => ['give_back_risk_ceiling' => 0.10],
        ]);

        $this->assertSame(0, $result['would_admit']);
        $this->assertSame(1, $result['false_reject_green']);
    }

    // ── determinism ──────────────────────────────────────────────────────────

    public function test_identical_input_yields_identical_output(): void
    {
        $input = ['candidates' => [
            $this->entry('commit_success'),
            $this->entry('give_back', 0.80, 0.20),
        ]];

        $this->assertSame(
            $this->replay()->replay($input),
            $this->replay()->replay($input),
        );
    }
}
