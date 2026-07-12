<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AcosMax;

use App\Services\Ai\Governance\Recursion\RecursionValueMetric;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * REC-03 — `R = ΔM / (cost + complexity + risk)` MEDIDOR tests.
 *
 * Frontier plan §3049-3052 acceptance clauses exercised mechanically:
 *   - R published with raw components per hypothesis
 *   - non-measurable component ⇒ `unmeasurable` (never silently estimated)
 *   - property-test: remove an organ ⇒ complexity negative ⇒ R rises
 *     (simplification incentive is mechanical, not editorial)
 */
final class Rec03RecursionValueMetricTest extends TestCase
{
    #[Test]
    public function formula_version_is_pinned(): void
    {
        $this->assertSame('atlas.acos.rec_r.v1', RecursionValueMetric::FORMULA_VERSION);
    }

    #[Test]
    public function complete_inputs_produce_measured_r_with_raw_components(): void
    {
        $out = RecursionValueMetric::compute(
            deltaM: 5.0,
            cost: ['tokens' => 1000, 'wallclock_seconds' => 600],
            complexity: ['organs_added' => 1, 'organs_removed' => 0],
            riskBand: 'moderate',
        );

        $this->assertSame('measured', $out['status']);
        $this->assertIsFloat($out['r']);
        // denominator = tokens/1000 + wall/60 + delta + risk
        //             = 1 + 10 + 1 + 2 = 14
        // r = 5 / 14 ≈ 0.357143
        $this->assertEqualsWithDelta(5.0 / 14.0, $out['r'], 1e-4);
        $this->assertSame(14.0, $out['denominator_raw']);
        $this->assertFalse($out['denominator_floor_applied']);

        foreach (['delta_m', 'cost', 'complexity', 'risk'] as $key) {
            $this->assertArrayHasKey($key, $out['components']);
            $this->assertSame('measured', $out['components'][$key]['status']);
        }
        // Raw denominators must appear alongside the aggregate value.
        $this->assertSame(1000.0, $out['components']['cost']['raw']['tokens']);
        $this->assertSame(600.0, $out['components']['cost']['raw']['wallclock_seconds']);
        $this->assertSame(1, $out['components']['complexity']['raw']['organs_added']);
        $this->assertSame('moderate', $out['components']['risk']['raw']['band']);
    }

    #[Test]
    public function missing_delta_m_is_stamped_unmeasurable_never_estimated(): void
    {
        $out = RecursionValueMetric::compute(
            deltaM: null,
            cost: ['tokens' => 500, 'wallclock_seconds' => 60],
            complexity: ['organs_added' => 0, 'organs_removed' => 0],
            riskBand: 'low',
        );

        $this->assertSame('unmeasurable', $out['status']);
        $this->assertNull($out['r']);
        $this->assertSame('unmeasurable', $out['components']['delta_m']['status']);
    }

    #[Test]
    public function missing_cost_component_taints_the_whole_metric(): void
    {
        $out = RecursionValueMetric::compute(
            deltaM: 3.0,
            cost: null,
            complexity: ['organs_added' => 0, 'organs_removed' => 0],
            riskBand: 'low',
        );

        $this->assertSame('unmeasurable', $out['status']);
        $this->assertSame('unmeasurable', $out['components']['cost']['status']);
    }

    #[Test]
    public function invalid_risk_band_is_unmeasurable_never_defaulted(): void
    {
        $out = RecursionValueMetric::compute(
            deltaM: 2.0,
            cost: ['tokens' => 100, 'wallclock_seconds' => 30],
            complexity: ['organs_added' => 0, 'organs_removed' => 0],
            riskBand: 'medium', // not a canonical ASI-15 band
        );

        $this->assertSame('unmeasurable', $out['status']);
        $this->assertSame('unmeasurable', $out['components']['risk']['status']);
    }

    #[Test]
    public function removing_an_organ_makes_complexity_negative_and_r_rises(): void
    {
        $baseline = RecursionValueMetric::compute(
            deltaM: 5.0,
            cost: ['tokens' => 5000, 'wallclock_seconds' => 600],
            complexity: ['organs_added' => 0, 'organs_removed' => 0],
            riskBand: 'moderate',
        );
        $simplification = RecursionValueMetric::compute(
            deltaM: 5.0,
            cost: ['tokens' => 5000, 'wallclock_seconds' => 600],
            complexity: ['organs_added' => 0, 'organs_removed' => 3],
            riskBand: 'moderate',
        );

        $this->assertSame('measured', $baseline['status']);
        $this->assertSame('measured', $simplification['status']);
        // The property REC-03 §3051 pins: complexity NEGATIVE ⇒ denominator
        // shrinks ⇒ R rises. Simplification is rewarded MECHANICALLY.
        $this->assertGreaterThan(
            (float) $simplification['components']['complexity']['value'],
            (float) $baseline['components']['complexity']['value'],
        );
        $this->assertGreaterThan($baseline['r'], $simplification['r']);
        $this->assertSame(-3.0, $simplification['components']['complexity']['value']);
    }

    #[Test]
    public function denominator_floor_prevents_r_from_exploding_when_almost_free(): void
    {
        $out = RecursionValueMetric::compute(
            deltaM: 5.0,
            cost: ['tokens' => 0, 'wallclock_seconds' => 0],
            complexity: ['organs_added' => 0, 'organs_removed' => 5], // -5
            riskBand: 'low',                                          // +1
        );

        $this->assertSame('measured', $out['status']);
        $this->assertTrue($out['denominator_floor_applied']);
        // raw denominator = 0 + 0 + (-5) + 1 = -4 → floored to 1.0
        $this->assertSame(-4.0, $out['denominator_raw']);
        $this->assertSame(1.0, $out['denominator_effective']);
        $this->assertSame(5.0, $out['r']);
    }

    #[Test]
    public function raw_component_values_are_never_omitted_from_the_output(): void
    {
        $out = RecursionValueMetric::compute(
            deltaM: 1.5,
            cost: ['tokens' => 250, 'wallclock_seconds' => 120],
            complexity: ['organs_added' => 2, 'organs_removed' => 1],
            riskBand: 'high',
        );

        $this->assertArrayHasKey('components', $out);
        $this->assertArrayHasKey('formula', $out['components']['cost']);
        $this->assertArrayHasKey('formula', $out['components']['complexity']);
        $this->assertArrayHasKey('formula', $out['components']['risk']);
    }
}
