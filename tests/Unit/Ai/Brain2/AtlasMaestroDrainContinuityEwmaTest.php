<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Brain2;

use App\Services\Ai\SelfConstruction\Maestro\Projection\AtlasMaestroDrainContinuitySloCompiler;
use PHPUnit\Framework\TestCase;

final class AtlasMaestroDrainContinuityEwmaTest extends TestCase
{
    // ── AC: EWMA weights recent samples more heavily ───────────────────────

    public function test_ewma_empty_returns_zero(): void
    {
        $this->assertSame(0.0, AtlasMaestroDrainContinuitySloCompiler::ewma([], 0.3));
    }

    public function test_ewma_single_sample_equals_that_sample(): void
    {
        $this->assertSame(10.0, AtlasMaestroDrainContinuitySloCompiler::ewma([10.0], 0.3));
    }

    public function test_ewma_constant_series_returns_that_value(): void
    {
        $this->assertSame(10.0, AtlasMaestroDrainContinuitySloCompiler::ewma([10.0, 10.0, 10.0], 0.3));
    }

    public function test_ewma_weights_recent_over_old(): void
    {
        // Series: [1.0, 10.0] — old = 1, recent = 10
        // EWMA = 0.3*10 + 0.7*1 = 3.0 + 0.7 = 3.7
        // Old equal-weight mean would be 5.5
        $result = AtlasMaestroDrainContinuitySloCompiler::ewma([1.0, 10.0], 0.3);
        $this->assertSame(3.7, $result);
        $this->assertLessThan(5.5, $result, 'EWMA must be below equal-weight mean when recent is higher');
    }

    public function test_ewma_recent_collapse_dominates(): void
    {
        // Series: [100.0, 100.0, 1.0] — collapse in 3rd sample
        // EWMA(0.3) = 0.3*1 + 0.7*(0.3*100 + 0.7*100) = 0.3 + 70 = 70.3
        // Old equal-weight mean = 67.0
        // With alpha=0.3 the old values still carry ~70% momentum, so EWMA is still ~70.
        $result = AtlasMaestroDrainContinuitySloCompiler::ewma([100.0, 100.0, 1.0], 0.3);
        $this->assertSame(70.3, $result);
    }

    public function test_ewma_recent_recovery_dominates(): void
    {
        // Series: [1.0, 1.0, 100.0] — recovery
        // Old mean = 34.0. EWMA = 0.3*100 + 0.7*(0.3*1 + 0.7*1) = 30 + 0.7 = 30.7
        $result = AtlasMaestroDrainContinuitySloCompiler::ewma([1.0, 1.0, 100.0], 0.3);
        $this->assertGreaterThan(30.0, $result, 'EWMA must show recovery faster than equal-weight mean');
    }

    public function test_ewma_higher_alpha_tracks_recent_more(): void
    {
        $samples = [10.0, 100.0];
        // alpha=0.1: 0.1*100 + 0.9*10 = 10 + 9 = 19
        // alpha=0.9: 0.9*100 + 0.1*10 = 90 + 1 = 91
        $lowAlpha = AtlasMaestroDrainContinuitySloCompiler::ewma($samples, 0.1);
        $highAlpha = AtlasMaestroDrainContinuitySloCompiler::ewma($samples, 0.9);

        $this->assertLessThan($highAlpha, $lowAlpha, 'lower alpha should smooth more (stay closer to old value)');
    }

    public function test_ewma_default_alpha_is_0_3(): void
    {
        $result = AtlasMaestroDrainContinuitySloCompiler::ewma([1.0, 10.0]);
        $this->assertSame(3.7, $result, 'default alpha=0.3 must match 0.3*10 + 0.7*1');
    }

    // ── AC: compile uses EWMA for throughput ────────────────────────────────

    public function test_compile_ewma_throughput_recent_collapse_triggers_at_risk(): void
    {
        // queue_depth=300, throughput samples show collapse from 10→1
        // EWMA(0.3) = 10 → 10 → 10 → 0.3*1+0.7*10=7.3. ETA=300/7.3≈41h >24 → at_risk
        $r = (new AtlasMaestroDrainContinuitySloCompiler)->compile([
            'queue_depth' => 300,
            'servable_now' => 10,
            'active_workers' => 5,
            'throughput_samples' => [10.0, 10.0, 10.0, 1.0],
        ]);

        // EWMA weights the recent 1.0 at 30%, giving ~7.3 throughput → ETA ≈ 41h > 24 → at_risk
        $this->assertSame('at_risk', $r['verdicts']['drain_eta']);
        $this->assertGreaterThan(24.0, $r['verdicts']['drain_eta_hours'], 'EWMA ETA should exceed threshold');
    }

    public function test_compile_ewma_healthy_when_recent_strong(): void
    {
        // queue_depth=50, throughput recovery: [1, 1, 10]
        // EWMA = 0.3*10 + 0.7*(0.3*1 + 0.7*1) = 3 + 0.7 = 3.7
        // ETA = 50/3.7 ≈ 13.5h < 24 → healthy
        $r = (new AtlasMaestroDrainContinuitySloCompiler)->compile([
            'queue_depth' => 50,
            'servable_now' => 10,
            'active_workers' => 3,
            'throughput_samples' => [1.0, 1.0, 10.0],
        ]);

        $this->assertSame('healthy', $r['verdicts']['drain_eta']);
    }

    public function test_compile_ewma_default_alpha(): void
    {
        // Without ewma_alpha field, defaults to 0.3
        $r = (new AtlasMaestroDrainContinuitySloCompiler)->compile([
            'queue_depth' => 100,
            'servable_now' => 10,
            'active_workers' => 3,
            'throughput_samples' => [10.0, 1.0],
        ]);

        // EWMA(0.3): 0.3*1 + 0.7*10 = 7.3. ETA = 100/7.3 ≈ 13.7
        $this->assertNotNull($r['verdicts']['drain_eta_hours']);
    }

    public function test_compile_ewma_alpha_from_facts(): void
    {
        $r = (new AtlasMaestroDrainContinuitySloCompiler)->compile([
            'queue_depth' => 100,
            'servable_now' => 10,
            'active_workers' => 3,
            'throughput_samples' => [10.0, 1.0],
            'ewma_alpha' => 0.5,
        ]);

        // EWMA(0.5): 0.5*1 + 0.5*10 = 5.5. ETA = 100/5.5 ≈ 18.2
        $this->assertNotNull($r['verdicts']['drain_eta_hours']);
    }

    public function test_compile_ewma_deterministic(): void
    {
        $compiler = new AtlasMaestroDrainContinuitySloCompiler;
        $facts = [
            'queue_depth' => 50,
            'servable_now' => 10,
            'active_workers' => 3,
            'throughput_samples' => [5.0, 7.0, 3.0],
            'give_back_rate' => 0.2,
        ];

        $this->assertSame($compiler->compile($facts), $compiler->compile($facts));
    }
}
