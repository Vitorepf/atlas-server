<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\L8MetaCompoundingAdoptionGate;
use PHPUnit\Framework\TestCase;

final class L8MetaCompoundingAdoptionGateTest extends TestCase
{
    private L8MetaCompoundingAdoptionGate $gate;

    protected function setUp(): void
    {
        $this->gate = new L8MetaCompoundingAdoptionGate();
    }

    public function testPositiveMeasuredContributionAdmitsCandidate(): void
    {
        $result = $this->gate->evaluate(
            [
                'factor_id' => 'm_local_distillation_density',
                'evidence_refs' => ['ledger://prop/1'],
            ],
            [
                'p5_evidence_ref' => 'ledger://p5/cert/9',
                'p5_divergence_detected' => false,
                'gaming_detected' => false,
                'dm_dt_before' => 0.40,
                'dm_dt_after' => 0.52,
                'observability_before' => 0.90,
                'observability_after' => 0.92,
                'measured_contribution' => 0.18,
                'evidence_refs' => ['ledger://outcome/7'],
            ],
        );

        $this->assertSame('atlas.aaeos.l8.meta_compounding.adoption_gate.v1', $result['schema_version']);
        $this->assertSame('m_local_distillation_density', $result['adopted_candidate']);
        $this->assertNull($result['rejected_reason']);
        $this->assertTrue($result['p5_passed']);
        $this->assertEqualsWithDelta(0.12, $result['dm_dt_delta'], 1e-9);
        $this->assertEqualsWithDelta(0.02, $result['observability_delta'], 1e-9);
        $this->assertEqualsWithDelta(0.18, $result['measured_contribution'], 1e-9);
    }

    public function testDmDtDownRejects(): void
    {
        $result = $this->gate->evaluate(
            ['factor_id' => 'm_candidate'],
            [
                'p5_evidence_ref' => 'ledger://p5/cert/1',
                'p5_divergence_detected' => false,
                'dm_dt_before' => 0.60,
                'dm_dt_after' => 0.55,
                'observability_before' => 0.80,
                'observability_after' => 0.85,
                'measured_contribution' => 0.20,
            ],
        );

        $this->assertSame('dm_dt_down', $result['rejected_reason']);
        $this->assertNull($result['adopted_candidate']);
        $this->assertEqualsWithDelta(-0.05, $result['dm_dt_delta'], 1e-9);
    }

    public function testObservabilityDownRejects(): void
    {
        $result = $this->gate->evaluate(
            ['factor_id' => 'm_candidate'],
            [
                'p5_evidence_ref' => 'ledger://p5/cert/2',
                'p5_divergence_detected' => false,
                'dm_dt_before' => 0.30,
                'dm_dt_after' => 0.45,
                'observability_before' => 0.95,
                'observability_after' => 0.70,
                'measured_contribution' => 0.22,
            ],
        );

        $this->assertSame('observability_down', $result['rejected_reason']);
        $this->assertNull($result['adopted_candidate']);
        $this->assertEqualsWithDelta(-0.25, $result['observability_delta'], 1e-9);
    }

    public function testP5DivergenceRejects(): void
    {
        $result = $this->gate->evaluate(
            ['factor_id' => 'm_candidate'],
            [
                'p5_evidence_ref' => 'ledger://p5/cert/3',
                'p5_divergence_detected' => true,
                'dm_dt_before' => 0.30,
                'dm_dt_after' => 0.60,
                'observability_before' => 0.90,
                'observability_after' => 0.93,
                'measured_contribution' => 0.30,
            ],
        );

        $this->assertSame('p5_divergence', $result['rejected_reason']);
        $this->assertNull($result['adopted_candidate']);
        $this->assertFalse($result['p5_passed']);
    }

    public function testGamingDetectedIsTreatedAsP5Divergence(): void
    {
        $result = $this->gate->evaluate(
            ['factor_id' => 'm_candidate'],
            [
                'p5_evidence_ref' => 'ledger://p5/cert/4',
                'gaming_detected' => true,
                'dm_dt_before' => 0.10,
                'dm_dt_after' => 0.99,
                'observability_before' => 0.50,
                'observability_after' => 0.99,
                'measured_contribution' => 0.99,
            ],
        );

        $this->assertSame('p5_divergence', $result['rejected_reason']);
        $this->assertNull($result['adopted_candidate']);
        $this->assertFalse($result['p5_passed']);
    }

    public function testMissingP5EvidenceRejectsBeforeAnyMeasuredLift(): void
    {
        // Anti-Goodhart: without independent P5 evidence the candidate cannot be admitted,
        // even with strong dm/dt, rising observability and positive contribution.
        $result = $this->gate->evaluate(
            ['factor_id' => 'm_candidate'],
            [
                'dm_dt_before' => 0.20,
                'dm_dt_after' => 0.80,
                'observability_before' => 0.70,
                'observability_after' => 0.95,
                'measured_contribution' => 0.40,
            ],
        );

        $this->assertSame('missing_p5_evidence', $result['rejected_reason']);
        $this->assertNull($result['adopted_candidate']);
    }

    public function testNonPositiveMeasuredContributionRejects(): void
    {
        $result = $this->gate->evaluate(
            ['factor_id' => 'm_candidate'],
            [
                'p5_evidence_ref' => 'ledger://p5/cert/5',
                'p5_divergence_detected' => false,
                'dm_dt_before' => 0.40,
                'dm_dt_after' => 0.41,
                'observability_before' => 0.90,
                'observability_after' => 0.90,
                'measured_contribution' => 0.0,
            ],
        );

        $this->assertSame('no_measured_contribution', $result['rejected_reason']);
        $this->assertNull($result['adopted_candidate']);
    }

    public function testNonFiniteMeasuredContributionIsFailClosed(): void
    {
        // Anti-Goodhart fail-closed: a NaN measured_contribution is NOT a strictly
        // positive measurement. Unguarded, NAN <= 0.0 is false and would wrongly admit
        // the candidate; the gate must read it as "no measured contribution".
        $result = $this->gate->evaluate(
            ['factor_id' => 'm_candidate'],
            [
                'p5_evidence_ref' => 'ledger://p5/cert/nan',
                'p5_divergence_detected' => false,
                'dm_dt_before' => 0.40,
                'dm_dt_after' => 0.50,
                'observability_before' => 0.90,
                'observability_after' => 0.92,
                'measured_contribution' => NAN,
            ],
        );

        $this->assertSame('no_measured_contribution', $result['rejected_reason']);
        $this->assertNull($result['adopted_candidate']);
        $this->assertEqualsWithDelta(0.0, $result['measured_contribution'], 1e-9);
    }

    public function testNonFiniteDmDtIsFailClosed(): void
    {
        // An INF dm_dt_after must not slip past the dm/dt rule on a non-finite delta;
        // it collapses to no-signal (0.0) so the measured-down rule still governs.
        $result = $this->gate->evaluate(
            ['factor_id' => 'm_candidate'],
            [
                'p5_evidence_ref' => 'ledger://p5/cert/inf',
                'p5_divergence_detected' => false,
                'dm_dt_before' => 0.40,
                'dm_dt_after' => INF,
                'observability_before' => 0.90,
                'observability_after' => 0.92,
                'measured_contribution' => 0.20,
            ],
        );

        $this->assertSame('dm_dt_down', $result['rejected_reason']);
        $this->assertNull($result['adopted_candidate']);
        $this->assertTrue(is_finite($result['dm_dt_delta']), 'dm_dt_delta must stay finite');
    }

    public function testOverflowingFiniteDeltaCollapsesToFiniteNoSignal(): void
    {
        // Two FINITE operands near +/-PHP_FLOAT_MAX still overflow to +/-INF on subtraction.
        // That non-finite delta must not (a) leak into the float output, nor (b) read as a
        // real improvement that admits the candidate. dm_dt MAX-(-MAX) would be +INF (>=0,
        // passes), so an unguarded delta would wrongly admit on a non-finite "lift".
        $result = $this->gate->evaluate(
            ['factor_id' => 'm_candidate'],
            [
                'p5_evidence_ref' => 'ledger://p5/cert/overflow',
                'p5_divergence_detected' => false,
                'dm_dt_before' => -PHP_FLOAT_MAX,
                'dm_dt_after' => PHP_FLOAT_MAX,
                'observability_before' => -PHP_FLOAT_MAX,
                'observability_after' => PHP_FLOAT_MAX,
                'measured_contribution' => 0.20,
            ],
        );

        $this->assertTrue(is_finite($result['dm_dt_delta']), 'dm_dt_delta must stay finite under overflow');
        $this->assertTrue(is_finite($result['observability_delta']), 'observability_delta must stay finite under overflow');
        $this->assertEqualsWithDelta(0.0, $result['dm_dt_delta'], 1e-9);
        $this->assertEqualsWithDelta(0.0, $result['observability_delta'], 1e-9);
    }

    public function testTruthyNonBoolP5DivergenceFlagFailsClosed(): void
    {
        // Anti-Goodhart fail-closed: a divergence flag that arrives as a truthy NON-bool
        // (int 1 from a DB tinyint / JSON decode) still means "divergence WAS detected".
        // A strict `=== true` check would fail open and admit a gamed candidate.
        foreach ([1, '1', 'true', 1.0] as $truthy) {
            $result = $this->gate->evaluate(
                ['factor_id' => 'm_candidate'],
                [
                    'p5_evidence_ref' => 'ledger://p5/cert/truthy',
                    'p5_divergence_detected' => $truthy,
                    'dm_dt_before' => 0.40,
                    'dm_dt_after' => 0.60,
                    'observability_before' => 0.90,
                    'observability_after' => 0.95,
                    'measured_contribution' => 0.30,
                ],
            );

            $this->assertSame('p5_divergence', $result['rejected_reason']);
            $this->assertNull($result['adopted_candidate']);
            $this->assertFalse($result['p5_passed']);
        }
    }

    public function testTruthyNonBoolGamingFlagFailsClosed(): void
    {
        // Same fail-closed contract for the gaming signal.
        foreach ([1, '1', 'true', 1.0] as $truthy) {
            $result = $this->gate->evaluate(
                ['factor_id' => 'm_candidate'],
                [
                    'p5_evidence_ref' => 'ledger://p5/cert/truthy',
                    'gaming_detected' => $truthy,
                    'dm_dt_before' => 0.10,
                    'dm_dt_after' => 0.99,
                    'observability_before' => 0.50,
                    'observability_after' => 0.99,
                    'measured_contribution' => 0.99,
                ],
            );

            $this->assertSame('p5_divergence', $result['rejected_reason']);
            $this->assertNull($result['adopted_candidate']);
            $this->assertFalse($result['p5_passed']);
        }
    }

    public function testFalseyNonBoolDivergenceFlagStillPasses(): void
    {
        // The mirror of the truthy case: explicitly falsey non-bool flags (0, "0", "")
        // must leave P5 immunity intact so a clean candidate is not falsely rejected.
        foreach ([0, '0', ''] as $falsey) {
            $result = $this->gate->evaluate(
                ['factor_id' => 'm_candidate'],
                [
                    'p5_evidence_ref' => 'ledger://p5/cert/clean',
                    'p5_divergence_detected' => $falsey,
                    'gaming_detected' => $falsey,
                    'dm_dt_before' => 0.40,
                    'dm_dt_after' => 0.52,
                    'observability_before' => 0.90,
                    'observability_after' => 0.92,
                    'measured_contribution' => 0.18,
                ],
            );

            $this->assertNull($result['rejected_reason']);
            $this->assertSame('m_candidate', $result['adopted_candidate']);
            $this->assertTrue($result['p5_passed']);
        }
    }

    public function testFlatDmDtAndFlatObservabilityWithPositiveContributionAdmits(): void
    {
        // dm/dt and observability unchanged (delta 0) must not be read as "down".
        $result = $this->gate->evaluate(
            ['candidate_id' => 'm_flat_factor'],
            [
                'p5_evidence_ref' => 'ledger://p5/cert/6',
                'dm_dt_before' => 0.5,
                'dm_dt_after' => 0.5,
                'observability_before' => 0.88,
                'observability_after' => 0.88,
                'measured_contribution' => 0.01,
            ],
        );

        $this->assertSame('m_flat_factor', $result['adopted_candidate']);
        $this->assertNull($result['rejected_reason']);
        $this->assertEqualsWithDelta(0.0, $result['dm_dt_delta'], 1e-9);
        $this->assertEqualsWithDelta(0.0, $result['observability_delta'], 1e-9);
    }

    public function testSafetyFirstOrderingPrioritisesP5OverDmDtDown(): void
    {
        // When both P5 divergence and dm_dt_down hold, P5 (safety) is reported first.
        $result = $this->gate->evaluate(
            ['factor_id' => 'm_candidate'],
            [
                'p5_evidence_ref' => 'ledger://p5/cert/7',
                'p5_divergence_detected' => true,
                'dm_dt_before' => 0.70,
                'dm_dt_after' => 0.40,
                'observability_before' => 0.90,
                'observability_after' => 0.60,
                'measured_contribution' => -0.10,
            ],
        );

        $this->assertSame('p5_divergence', $result['rejected_reason']);
        $this->assertNull($result['adopted_candidate']);
    }

    public function testEvidenceRefsAreDedupedReindexedListOfStrings(): void
    {
        // list<string> contract: numeric-looking refs must not collapse to int keys and
        // duplicates across proposal/outcome/p5 must be merged into one re-indexed list.
        $result = $this->gate->evaluate(
            [
                'factor_id' => 'm_candidate',
                'evidence_refs' => ['1001', 'ledger://prop/a', '1001'],
            ],
            [
                'p5_evidence_ref' => 'ledger://p5/cert/8',
                'p5_divergence_detected' => false,
                'dm_dt_before' => 0.40,
                'dm_dt_after' => 0.50,
                'observability_before' => 0.90,
                'observability_after' => 0.91,
                'measured_contribution' => 0.10,
                'evidence_refs' => ['ledger://prop/a', '2002', ''],
            ],
        );

        $this->assertSame(
            ['1001', 'ledger://prop/a', '2002', 'ledger://p5/cert/8'],
            $result['evidence_refs'],
        );
        $this->assertSame(array_values($result['evidence_refs']), $result['evidence_refs']);
        $this->assertContainsOnlyString($result['evidence_refs']);
        $this->assertSame('m_candidate', $result['adopted_candidate']);
    }

    public function testGeneralisesToUnseenNumericInputs(): void
    {
        // Different magnitudes than any other case: proves deltas are computed, not canned.
        $result = $this->gate->evaluate(
            ['factor_id' => 'm_evidence_recency'],
            [
                'p5_evidence_ref' => 'ledger://p5/cert/x',
                'dm_dt_before' => 1.25,
                'dm_dt_after' => 3.75,
                'observability_before' => 12.0,
                'observability_after' => 12.5,
                'measured_contribution' => 0.66,
            ],
        );

        $this->assertSame('m_evidence_recency', $result['adopted_candidate']);
        $this->assertNull($result['rejected_reason']);
        $this->assertEqualsWithDelta(2.5, $result['dm_dt_delta'], 1e-9);
        $this->assertEqualsWithDelta(0.5, $result['observability_delta'], 1e-9);
        $this->assertEqualsWithDelta(0.66, $result['measured_contribution'], 1e-9);
    }

    public function testMissingCandidateIdNeverAdoptsEvenWhenAllSignalsPass(): void
    {
        $result = $this->gate->evaluate(
            [],
            [
                'p5_evidence_ref' => 'ledger://p5/cert/z',
                'dm_dt_before' => 0.10,
                'dm_dt_after' => 0.30,
                'observability_before' => 0.80,
                'observability_after' => 0.85,
                'measured_contribution' => 0.25,
            ],
        );

        $this->assertNull($result['adopted_candidate']);
        $this->assertNull($result['rejected_reason']);
    }
}
