<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainScaffoldOverfitDetector;
use Tests\TestCase;

final class AtlasExternalBrainScaffoldOverfitDetectorTest extends TestCase
{
    private function svc(): AtlasExternalBrainScaffoldOverfitDetector
    {
        return new AtlasExternalBrainScaffoldOverfitDetector;
    }

    private function metric(
        string $id,
        float $gate,
        float $commit,
        float $value,
        float $diversity,
        float $compounding,
        float $benchmark = 0.8,
        float $heldout = 0.8,
    ): array {
        return [
            'variant_id' => $id,
            'gate_pass_rate' => $gate,
            'commit_success_rate' => $commit,
            'value_proof_rate' => $value,
            'diversity_score' => $diversity,
            'compounding_impact' => $compounding,
            'benchmark_pass_rate' => $benchmark,
            'heldout_pass_rate' => $heldout,
        ];
    }

    private function detect(array $metrics): array
    {
        return $this->svc()->detect(['scaffold_metrics' => $metrics]);
    }

    // ── overfit_detected ──────────────────────────────────────────────────────

    public function test_high_gate_with_low_real_quality_is_suspect(): void
    {
        $r = $this->detect([
            $this->metric('v1', gate: 0.90, commit: 0.55, value: 0.60, diversity: 0.60, compounding: 0.60),
        ]);

        $this->assertTrue($r['overfit_detected']);
        $ids = array_column($r['suspect_scaffolds'], 'variant_id');
        $this->assertContains('v1', $ids);
    }

    public function test_high_gate_with_all_real_metrics_above_threshold_is_clean(): void
    {
        $r = $this->detect([
            // gate high, but all real metrics above thresholds
            $this->metric('v1', gate: 0.90, commit: 0.80, value: 0.80, diversity: 0.70, compounding: 0.70),
        ]);

        $this->assertFalse($r['overfit_detected']);
        $this->assertSame([], $r['suspect_scaffolds']);
    }

    public function test_low_gate_with_poor_real_metrics_is_not_overfit(): void
    {
        // Gate below HIGH_GATE_THRESHOLD — not an overfit signal (just a bad scaffold)
        $r = $this->detect([
            $this->metric('v1', gate: 0.50, commit: 0.40, value: 0.40, diversity: 0.30, compounding: 0.30),
        ]);

        $this->assertFalse($r['overfit_detected']);
    }

    // ── heldout_gap ───────────────────────────────────────────────────────────

    public function test_large_heldout_gap_marks_suspect_regardless_of_gate(): void
    {
        // benchmark=0.90, heldout=0.50 → gap=0.40 > threshold
        $r = $this->detect([
            $this->metric('v1', gate: 0.70, commit: 0.80, value: 0.80, diversity: 0.70, compounding: 0.70,
                benchmark: 0.90, heldout: 0.50),
        ]);

        $this->assertTrue($r['overfit_detected']);
        $this->assertTrue($r['heldout_gap']);
    }

    public function test_small_heldout_gap_is_not_flagged(): void
    {
        $r = $this->detect([
            $this->metric('v1', gate: 0.85, commit: 0.80, value: 0.80, diversity: 0.70, compounding: 0.70,
                benchmark: 0.85, heldout: 0.80), // gap=0.05 < threshold
        ]);

        $this->assertFalse($r['heldout_gap']);
    }

    // ── evidence_trend ────────────────────────────────────────────────────────

    public function test_two_or_more_declining_metrics_gives_declining_trend(): void
    {
        $r = $this->detect([
            $this->metric('v1', gate: 0.90, commit: 0.50, value: 0.50, diversity: 0.70, compounding: 0.70),
        ]);

        $this->assertSame('declining', $r['evidence_trend']['v1']['trend']);
        $this->assertContains('commit_success_rate', $r['evidence_trend']['v1']['declining_metrics']);
        $this->assertContains('value_proof_rate', $r['evidence_trend']['v1']['declining_metrics']);
    }

    public function test_one_declining_metric_gives_borderline_trend(): void
    {
        $r = $this->detect([
            $this->metric('v1', gate: 0.90, commit: 0.60, value: 0.80, diversity: 0.70, compounding: 0.70),
        ]);

        $this->assertSame('borderline', $r['evidence_trend']['v1']['trend']);
    }

    public function test_all_metrics_healthy_gives_stable_trend(): void
    {
        $r = $this->detect([
            $this->metric('v1', gate: 0.90, commit: 0.80, value: 0.80, diversity: 0.70, compounding: 0.70),
        ]);

        $this->assertSame('stable', $r['evidence_trend']['v1']['trend']);
    }

    // ── recommended_action ────────────────────────────────────────────────────

    public function test_two_suspects_recommends_reset_scaffold(): void
    {
        $r = $this->detect([
            $this->metric('v1', gate: 0.90, commit: 0.50, value: 0.50, diversity: 0.30, compounding: 0.30),
            $this->metric('v2', gate: 0.85, commit: 0.55, value: 0.55, diversity: 0.30, compounding: 0.30),
        ]);

        $this->assertSame('reset_scaffold', $r['recommended_action']);
    }

    public function test_heldout_overfit_only_recommends_increase_heldout_set(): void
    {
        $r = $this->detect([
            // Only one suspect, caused solely by heldout gap
            $this->metric('v1', gate: 0.70, commit: 0.80, value: 0.80, diversity: 0.70, compounding: 0.70,
                benchmark: 0.92, heldout: 0.55),
        ]);

        $this->assertSame('increase_heldout_set', $r['recommended_action']);
    }

    public function test_single_suspect_recommends_monitor(): void
    {
        $r = $this->detect([
            $this->metric('v1', gate: 0.90, commit: 0.55, value: 0.80, diversity: 0.70, compounding: 0.70),
        ]);

        $this->assertSame('monitor', $r['recommended_action']);
    }

    public function test_clean_scaffold_recommends_none(): void
    {
        $r = $this->detect([
            $this->metric('v1', gate: 0.90, commit: 0.80, value: 0.80, diversity: 0.70, compounding: 0.70),
        ]);

        $this->assertSame('none', $r['recommended_action']);
    }

    // ── empty + schema ────────────────────────────────────────────────────────

    public function test_empty_metrics_returns_clean_state(): void
    {
        $r = $this->svc()->detect([]);

        $this->assertFalse($r['overfit_detected']);
        $this->assertSame([], $r['suspect_scaffolds']);
        $this->assertSame('none', $r['recommended_action']);
    }

    public function test_schema_version_present(): void
    {
        $r = $this->svc()->detect([]);

        $this->assertSame(AtlasExternalBrainScaffoldOverfitDetector::SCHEMA, $r['schema_version']);
    }
}
