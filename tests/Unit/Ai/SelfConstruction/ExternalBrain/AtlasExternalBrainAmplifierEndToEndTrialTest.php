<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainAmplifierEndToEndTrial;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainAmplifierEndToEndTrialTest extends TestCase
{
    private function trial(): AtlasExternalBrainAmplifierEndToEndTrial
    {
        return new AtlasExternalBrainAmplifierEndToEndTrial;
    }

    private function dims(array $overrides = []): array
    {
        return array_merge([
            'accuracy' => [
                'small_model_score' => 0.60,
                'scaffolded_score'  => 0.80,
                'frontier_score'    => 0.90,
            ],
        ], $overrides);
    }

    // ── AC4 / output shape ────────────────────────────────────────────────────

    public function test_output_has_required_keys(): void
    {
        $r = $this->trial()->run([]);
        $this->assertSame(AtlasExternalBrainAmplifierEndToEndTrial::SCHEMA, $r['schema_version']);
        $this->assertArrayHasKey('tier_scores',         $r);
        $this->assertArrayHasKey('scaffold_lift',       $r);
        $this->assertArrayHasKey('frontier_gain',       $r);
        $this->assertArrayHasKey('recommendation',      $r);
        $this->assertArrayHasKey('dimension_breakdown', $r);
        $this->assertArrayHasKey('quality_assessment',  $r);
    }

    // ── AC2: tier_scores / scaffold_lift / frontier_gain ─────────────────────

    public function test_tier_scores_are_per_dimension_averages(): void
    {
        $r = $this->trial()->run(['benchmark_dimensions' => $this->dims()]);
        $ts = $r['tier_scores'];
        $this->assertEqualsWithDelta(0.60, $ts['small_model'],            0.001);
        $this->assertEqualsWithDelta(0.80, $ts['scaffolded_small_model'], 0.001);
        $this->assertEqualsWithDelta(0.90, $ts['frontier_model'],         0.001);
    }

    public function test_scaffold_lift_computed_correctly(): void
    {
        $r = $this->trial()->run(['benchmark_dimensions' => $this->dims()]);
        $this->assertEqualsWithDelta(0.20, $r['scaffold_lift'], 0.001); // 0.80 - 0.60
    }

    public function test_frontier_gain_computed_correctly(): void
    {
        $r = $this->trial()->run(['benchmark_dimensions' => $this->dims()]);
        $this->assertEqualsWithDelta(0.10, $r['frontier_gain'], 0.001); // 0.90 - 0.80
    }

    public function test_multi_dimension_average_computed(): void
    {
        $r = $this->trial()->run([
            'benchmark_dimensions' => [
                'dim1' => ['small_model_score' => 0.6, 'scaffolded_score' => 0.8, 'frontier_score' => 0.9],
                'dim2' => ['small_model_score' => 0.4, 'scaffolded_score' => 0.6, 'frontier_score' => 0.7],
            ],
        ]);
        $ts = $r['tier_scores'];
        $this->assertEqualsWithDelta(0.50, $ts['small_model'],            0.001); // (0.6+0.4)/2
        $this->assertEqualsWithDelta(0.70, $ts['scaffolded_small_model'], 0.001); // (0.8+0.6)/2
    }

    // ── AC3: promote_scaffold ─────────────────────────────────────────────────

    public function test_promote_scaffold_when_meets_floor_and_lift_sufficient(): void
    {
        $r = $this->trial()->run([
            'benchmark_dimensions'   => $this->dims(), // scaffolded=0.80 >= 0.75, lift=0.20 >= 0.10
            'quality_floor'          => 0.75,
            'min_lift_for_promotion' => 0.10,
        ]);
        $this->assertSame('promote_scaffold', $r['recommendation']);
        $this->assertTrue($r['quality_assessment']['scaffold_meets_floor']);
        $this->assertTrue($r['quality_assessment']['lift_sufficient']);
    }

    // ── AC3: use_frontier ─────────────────────────────────────────────────────

    public function test_use_frontier_when_scaffold_below_floor_but_frontier_above(): void
    {
        $r = $this->trial()->run([
            'benchmark_dimensions' => [
                'dim' => ['small_model_score' => 0.5, 'scaffolded_score' => 0.70, 'frontier_score' => 0.90],
            ],
            'quality_floor' => 0.75, // scaffolded=0.70 < 0.75, frontier=0.90 >= 0.75
        ]);
        $this->assertSame('use_frontier', $r['recommendation']);
    }

    // ── AC3: use_scaffolded_small_model ───────────────────────────────────────

    public function test_use_scaffolded_when_meets_floor_but_lift_insufficient(): void
    {
        $r = $this->trial()->run([
            'benchmark_dimensions' => [
                'dim' => ['small_model_score' => 0.78, 'scaffolded_score' => 0.80, 'frontier_score' => 0.85],
            ],
            'quality_floor'          => 0.75, // scaffold 0.80 >= floor
            'min_lift_for_promotion' => 0.10, // lift = 0.02 < 0.10 → insufficient
        ]);
        $this->assertSame('use_scaffolded_small_model', $r['recommendation']);
    }

    // ── AC3: use_small_model ──────────────────────────────────────────────────

    public function test_use_small_model_when_nothing_meets_floor(): void
    {
        $r = $this->trial()->run([
            'benchmark_dimensions' => [
                'dim' => ['small_model_score' => 0.50, 'scaffolded_score' => 0.60, 'frontier_score' => 0.65],
            ],
            'quality_floor' => 0.75, // none meet floor
        ]);
        $this->assertSame('use_small_model', $r['recommendation']);
    }

    // ── dimension_breakdown ───────────────────────────────────────────────────

    public function test_dimension_breakdown_contains_all_tiers(): void
    {
        $r = $this->trial()->run(['benchmark_dimensions' => $this->dims()]);
        $bd = $r['dimension_breakdown']['accuracy'];
        $this->assertArrayHasKey('small_model',            $bd);
        $this->assertArrayHasKey('scaffolded_small_model', $bd);
        $this->assertArrayHasKey('frontier_model',         $bd);
    }

    // ── Determinism ───────────────────────────────────────────────────────────

    public function test_output_is_deterministic(): void
    {
        $facts = [
            'benchmark_dimensions'   => $this->dims(),
            'quality_floor'          => 0.75,
            'min_lift_for_promotion' => 0.10,
        ];
        $a = $this->trial()->run($facts);
        $b = $this->trial()->run($facts);
        $this->assertSame(json_encode($a), json_encode($b));
    }

    // ── AC1 + AC2: baseline_vs_scaffolded quality lift ────────────────────────

    private function qm(array $baseline, array $scaffolded, int $sampleCount = 0): array
    {
        return ['quality_metrics' => ['baseline' => $baseline, 'scaffolded' => $scaffolded, 'sample_count' => $sampleCount]];
    }

    private function healthyBaseline(): array
    {
        return ['valid_seed_rate' => 0.60, 'accepted_by_gate_rate' => 0.55, 'later_green_rate' => 0.50, 'give_back_rate' => 0.30, 'proxy_rate' => 0.15, 'average_cost' => 1.00];
    }

    public function test_baseline_vs_scaffolded_key_present_when_no_quality_metrics(): void
    {
        $r = $this->trial()->run([]);
        $this->assertArrayHasKey('baseline_vs_scaffolded', $r);
        $this->assertSame('no_data', $r['baseline_vs_scaffolded']['verdict']);
        $this->assertFalse($r['baseline_vs_scaffolded']['trial_passes']);
    }

    public function test_lift_verdict_when_scaffolded_meaningfully_better(): void
    {
        $scaffolded = ['valid_seed_rate' => 0.80, 'accepted_by_gate_rate' => 0.75, 'later_green_rate' => 0.70, 'give_back_rate' => 0.15, 'proxy_rate' => 0.08, 'average_cost' => 0.90];
        $r = $this->trial()->run($this->qm($this->healthyBaseline(), $scaffolded));
        $bvs = $r['baseline_vs_scaffolded'];
        $this->assertSame('lift', $bvs['verdict']);
        $this->assertTrue($bvs['trial_passes']);
        $this->assertGreaterThan(0.0, $bvs['lift']);
    }

    public function test_no_lift_verdict_when_scaffolded_marginally_better(): void
    {
        // valid_seed_rate +0.01, others same → composite < 0.05 min lift
        $scaffolded = array_merge($this->healthyBaseline(), ['valid_seed_rate' => 0.61]);
        $r = $this->trial()->run($this->qm($this->healthyBaseline(), $scaffolded));
        $bvs = $r['baseline_vs_scaffolded'];
        $this->assertSame('no_lift', $bvs['verdict']);
        $this->assertFalse($bvs['trial_passes']);
    }

    public function test_cheaper_but_worse_verdict_blocks_promotion(): void
    {
        // Cost goes down (good) but proxy_rate worsens by >0.05 (bad)
        $scaffolded = array_merge($this->healthyBaseline(), [
            'average_cost' => 0.50,   // cheaper
            'proxy_rate'   => 0.25,   // worsened by 0.10 > threshold 0.05
        ]);
        $r = $this->trial()->run($this->qm($this->healthyBaseline(), $scaffolded));
        $bvs = $r['baseline_vs_scaffolded'];
        $this->assertSame('cheaper_but_worse', $bvs['verdict']);
        $this->assertFalse($bvs['trial_passes']);
        $this->assertLessThan(0.0, $bvs['cost_delta']);    // cost improved
        $this->assertGreaterThan(0.0, $bvs['proxy_rate_delta']); // proxy worsened
    }

    public function test_cheaper_but_worse_via_give_back_worsening(): void
    {
        $scaffolded = array_merge($this->healthyBaseline(), [
            'average_cost'   => 0.50,  // cheaper
            'give_back_rate' => 0.40,  // worsened by 0.10 > threshold 0.05
        ]);
        $r = $this->trial()->run($this->qm($this->healthyBaseline(), $scaffolded));
        $this->assertSame('cheaper_but_worse', $r['baseline_vs_scaffolded']['verdict']);
        $this->assertFalse($r['baseline_vs_scaffolded']['trial_passes']);
    }

    public function test_low_sample_verdict_when_sample_count_below_threshold(): void
    {
        $scaffolded = array_merge($this->healthyBaseline(), ['valid_seed_rate' => 0.90]);
        $r = $this->trial()->run($this->qm($this->healthyBaseline(), $scaffolded, sampleCount: 5));
        $bvs = $r['baseline_vs_scaffolded'];
        $this->assertSame('low_sample', $bvs['verdict']);
        $this->assertFalse($bvs['trial_passes']);
    }

    public function test_sufficient_sample_count_does_not_trigger_low_sample(): void
    {
        $scaffolded = array_merge($this->healthyBaseline(), ['valid_seed_rate' => 0.90, 'accepted_by_gate_rate' => 0.85]);
        $r = $this->trial()->run($this->qm($this->healthyBaseline(), $scaffolded, sampleCount: 10));
        $this->assertNotSame('low_sample', $r['baseline_vs_scaffolded']['verdict']);
    }

    public function test_deterministic_quality_lift_summary(): void
    {
        $facts = $this->qm($this->healthyBaseline(), array_merge($this->healthyBaseline(), [
            'valid_seed_rate' => 0.80, 'accepted_by_gate_rate' => 0.75, 'give_back_rate' => 0.10,
        ]), sampleCount: 20);
        $a = $this->trial()->run($facts);
        $b = $this->trial()->run($facts);
        $this->assertSame(json_encode($a['baseline_vs_scaffolded']), json_encode($b['baseline_vs_scaffolded']));
    }

    // ── scaffolded_vs_frontier ───────────────────────────────────────────────

    private function svf(array $scaffolded, array $frontier, int $sampleCount = 0): array
    {
        return ['quality_metrics' => [
            'baseline'     => $this->healthyBaseline(),
            'scaffolded'   => $scaffolded,
            'frontier'     => $frontier,
            'sample_count' => $sampleCount,
        ]];
    }

    private function strongScaffolded(): array
    {
        return [
            'success_rate'      => 0.85,
            'evidence_quality'  => 0.80,
            'impact_score'      => 0.70,
            'average_cost'      => 0.30,
            'average_latency'   => 0.40,
            'give_back_rate'    => 0.10,
            'proxy_rate'        => 0.02,
        ];
    }

    private function strongFrontier(): array
    {
        return [
            'success_rate'      => 0.88,
            'evidence_quality'  => 0.82,
            'impact_score'      => 0.72,
            'average_cost'      => 1.00,
            'average_latency'   => 0.50,
            'give_back_rate'    => 0.08,
            'proxy_rate'        => 0.01,
        ];
    }

    public function test_scaffolded_vs_frontier_key_present_when_no_frontier(): void
    {
        $r = $this->trial()->run([]);
        $this->assertArrayHasKey('scaffolded_vs_frontier', $r);
        $this->assertSame('no_data', $r['scaffolded_vs_frontier']['verdict']);
        $this->assertFalse($r['scaffolded_vs_frontier']['trial_passes']);
        $this->assertFalse($r['scaffolded_vs_frontier']['small_model_ready']);
        $this->assertFalse($r['scaffolded_vs_frontier']['frontier_preferred']);
    }

    public function test_small_model_ready_when_scaffolded_meets_floors_and_no_poison(): void
    {
        // Scaffolded meets all floors, frontier only marginally better,
        // scaffolded much cheaper → small_model_ready.
        $r = $this->trial()->run($this->svf($this->strongScaffolded(), $this->strongFrontier(), sampleCount: 20));
        $svf = $r['scaffolded_vs_frontier'];
        $this->assertTrue($svf['small_model_ready']);
        $this->assertFalse($svf['frontier_preferred']);
        $this->assertSame('small_model_ready', $svf['verdict']);
        $this->assertTrue($svf['trial_passes']);
    }

    public function test_frontier_preferred_when_frontier_advantage_exceeds_threshold(): void
    {
        // Frontier much better on success/evidence/impact, and scaffolded
        // cost not cheap enough to raise the bar (ratio > 0.50 floor).
        $scaffolded = array_merge($this->strongScaffolded(), ['average_cost' => 0.60]);
        $frontier = array_merge($this->strongFrontier(), [
            'success_rate'     => 0.98,
            'evidence_quality' => 0.95,
            'impact_score'     => 0.90,
        ]);
        $r = $this->trial()->run($this->svf($scaffolded, $frontier, sampleCount: 20));
        $svf = $r['scaffolded_vs_frontier'];
        $this->assertTrue($svf['frontier_preferred']);
        $this->assertSame('frontier_preferred', $svf['verdict']);
        $this->assertFalse($svf['trial_passes']);
    }

    public function test_proxy_poison_blocks_small_model_ready(): void
    {
        // Scaffolded has proxy_rate above 0.05 → poison signal.
        $scaffolded = array_merge($this->strongScaffolded(), ['proxy_rate' => 0.10]);
        $r = $this->trial()->run($this->svf($scaffolded, $this->strongFrontier(), sampleCount: 20));
        $svf = $r['scaffolded_vs_frontier'];
        $this->assertFalse($svf['small_model_ready']);
    }

    public function test_scaffolded_below_success_floor_blocks_small_model_ready(): void
    {
        $scaffolded = array_merge($this->strongScaffolded(), ['success_rate' => 0.60]);
        $r = $this->trial()->run($this->svf($scaffolded, $this->strongFrontier(), sampleCount: 20));
        $this->assertFalse($r['scaffolded_vs_frontier']['small_model_ready']);
    }

    public function test_scaffolded_below_evidence_floor_blocks_small_model_ready(): void
    {
        $scaffolded = array_merge($this->strongScaffolded(), ['evidence_quality' => 0.50]);
        $r = $this->trial()->run($this->svf($scaffolded, $this->strongFrontier(), sampleCount: 20));
        $this->assertFalse($r['scaffolded_vs_frontier']['small_model_ready']);
    }

    public function test_scaffolded_below_impact_floor_blocks_small_model_ready(): void
    {
        $scaffolded = array_merge($this->strongScaffolded(), ['impact_score' => 0.40]);
        $r = $this->trial()->run($this->svf($scaffolded, $this->strongFrontier(), sampleCount: 20));
        $this->assertFalse($r['scaffolded_vs_frontier']['small_model_ready']);
    }

    public function test_frontier_preferred_when_scaffolded_latency_and_give_back_worse(): void
    {
        // Scaffolded meets floors but latency and give_back much worse than frontier.
        $scaffolded = array_merge($this->strongScaffolded(), [
            'average_latency' => 0.80,  // frontier 0.50 → delta 0.30 > 0.10
            'give_back_rate'  => 0.20,  // frontier 0.08 → delta 0.12 > 0.05
        ]);
        $r = $this->trial()->run($this->svf($scaffolded, $this->strongFrontier(), sampleCount: 20));
        $svf = $r['scaffolded_vs_frontier'];
        $this->assertTrue($svf['frontier_preferred']);
    }

    public function test_cost_adjusted_threshold_raises_when_scaffolded_much_cheaper(): void
    {
        // Scaffolded is 0.10 cost vs frontier 1.00 → ratio 0.10 < 0.50 floor.
        // Threshold should rise above the default 0.05.
        $scaffolded = array_merge($this->strongScaffolded(), ['average_cost' => 0.10]);
        $r = $this->trial()->run($this->svf($scaffolded, $this->strongFrontier(), sampleCount: 20));
        $svf = $r['scaffolded_vs_frontier'];
        $this->assertGreaterThan(0.05, $svf['cost_adjusted_threshold']);
    }

    public function test_low_sample_verdict_for_scaffolded_vs_frontier(): void
    {
        $r = $this->trial()->run($this->svf($this->strongScaffolded(), $this->strongFrontier(), sampleCount: 5));
        $svf = $r['scaffolded_vs_frontier'];
        $this->assertSame('low_sample', $svf['verdict']);
        $this->assertFalse($svf['trial_passes']);
    }

    public function test_sufficient_sample_does_not_trigger_low_sample_for_svfrontier(): void
    {
        $r = $this->trial()->run($this->svf($this->strongScaffolded(), $this->strongFrontier(), sampleCount: 10));
        $this->assertNotSame('low_sample', $r['scaffolded_vs_frontier']['verdict']);
    }

    public function test_svfrontier_deltas_computed_correctly(): void
    {
        $r = $this->trial()->run($this->svf($this->strongScaffolded(), $this->strongFrontier(), sampleCount: 20));
        $svf = $r['scaffolded_vs_frontier'];
        // success: 0.85 - 0.88 = -0.03
        $this->assertEqualsWithDelta(-0.03, $svf['success_delta'], 0.001);
        // cost: 0.30 - 1.00 = -0.70 (scaffolded cheaper)
        $this->assertEqualsWithDelta(-0.70, $svf['cost_delta'], 0.001);
        // latency: 0.40 - 0.50 = -0.10 (scaffolded faster)
        $this->assertEqualsWithDelta(-0.10, $svf['latency_delta'], 0.001);
    }

    public function test_svfrontier_parity_when_neither_clearly_better(): void
    {
        // Scaffolded below floors (so not small_model_ready) but frontier
        // advantage tiny (so not frontier_preferred).
        $scaffolded = array_merge($this->strongScaffolded(), [
            'success_rate'     => 0.50,
            'evidence_quality' => 0.50,
            'impact_score'     => 0.40,
        ]);
        $frontier = array_merge($this->strongFrontier(), [
            'success_rate'     => 0.52,
            'evidence_quality' => 0.51,
            'impact_score'     => 0.42,
        ]);
        $r = $this->trial()->run($this->svf($scaffolded, $frontier, sampleCount: 20));
        $svf = $r['scaffolded_vs_frontier'];
        $this->assertFalse($svf['small_model_ready']);
        $this->assertFalse($svf['frontier_preferred']);
        $this->assertSame('parity', $svf['verdict']);
    }

    public function test_svfrontier_is_deterministic(): void
    {
        $facts = $this->svf($this->strongScaffolded(), $this->strongFrontier(), sampleCount: 20);
        $a = $this->trial()->run($facts);
        $b = $this->trial()->run($facts);
        $this->assertSame(json_encode($a['scaffolded_vs_frontier']), json_encode($b['scaffolded_vs_frontier']));
    }
}
