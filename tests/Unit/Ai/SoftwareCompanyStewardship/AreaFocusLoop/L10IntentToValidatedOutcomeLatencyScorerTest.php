<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\L10IntentToValidatedOutcomeLatencyScorer;
use PHPUnit\Framework\TestCase;

final class L10IntentToValidatedOutcomeLatencyScorerTest extends TestCase
{
    private L10IntentToValidatedOutcomeLatencyScorer $scorer;

    protected function setUp(): void
    {
        $this->scorer = new L10IntentToValidatedOutcomeLatencyScorer();
    }

    /**
     * score(events) returns p50_latency_ms, p95_latency_ms,
     * near_zero_threshold_met, sovereignty_intact and evidence_refs.
     * Measured near-zero collapse with sovereignty intact => fusion claim holds.
     */
    public function testMeasuredNearZeroCollapseWithSovereigntyIntactMeetsThreshold(): void
    {
        $result = $this->scorer->score([
            ['latency_ms' => 50, 'outcome_validated' => true, 'evidence_ref' => 'ev-1'],
            ['latency_ms' => 80, 'outcome_validated' => true, 'evidence_ref' => 'ev-2'],
            ['latency_ms' => 120, 'outcome_validated' => true, 'evidence_ref' => 'ev-3'],
            ['latency_ms' => 200, 'outcome_validated' => true, 'evidence_ref' => 'ev-4'],
            ['latency_ms' => 300, 'outcome_validated' => true, 'evidence_refs' => ['ev-5', 'ev-1']],
        ]);

        $this->assertSame('atlas.aaeos.l10.intent_to_validated_outcome_latency.v1', $result['schema_version']);
        // Nearest-rank percentiles over [50,80,120,200,300]: p50 -> rank 3 = 120, p95 -> rank 5 = 300.
        $this->assertSame(120, $result['p50_latency_ms']);
        $this->assertSame(300, $result['p95_latency_ms']);
        $this->assertTrue($result['near_zero_threshold_met']);
        $this->assertTrue($result['sovereignty_intact']);
        $this->assertFalse($result['insufficient_evidence']);
        $this->assertSame('pass', $result['verdict']);
        // evidence_refs is a deduplicated list<string> (ev-1 collapses to one entry).
        $this->assertSame(['ev-1', 'ev-2', 'ev-3', 'ev-4', 'ev-5'], $result['evidence_refs']);
        $this->assertSame([], $result['blockers']);
    }

    /**
     * Latency above the near-zero band is still measured and trusted, but the
     * fusion claim (near_zero_threshold_met) does NOT hold.
     */
    public function testLatencyAboveBandIsMeasuredButDoesNotMeetThreshold(): void
    {
        $result = $this->scorer->score([
            ['latency_ms' => 900, 'outcome_validated' => true],
            ['latency_ms' => 1100, 'outcome_validated' => true],
            ['latency_ms' => 1500, 'outcome_validated' => true],
            ['latency_ms' => 2000, 'outcome_validated' => true],
            ['latency_ms' => 5000, 'outcome_validated' => true],
        ]);

        // p50 -> rank 3 = 1500, p95 -> rank 5 = 5000.
        $this->assertSame(1500, $result['p50_latency_ms']);
        $this->assertSame(5000, $result['p95_latency_ms']);
        $this->assertFalse($result['near_zero_threshold_met']);
        $this->assertTrue($result['sovereignty_intact']);
        $this->assertSame('pass', $result['verdict']);
        $this->assertSame([], $result['blockers']);
    }

    /**
     * The near-zero band is inclusive at the exact threshold: a p95 of exactly
     * NEAR_ZERO_THRESHOLD_MS (1000ms) still MEETS the fusion claim (the gate is
     * `<=`, not `<`), while a single millisecond beyond it (1001ms) does NOT.
     * Locks the boundary so a `<=`->`<` regression cannot pass silently.
     */
    public function testNearZeroThresholdIsInclusiveAtExactBoundary(): void
    {
        $atBoundary = $this->scorer->score([
            ['latency_ms' => 200, 'outcome_validated' => true],
            ['latency_ms' => 400, 'outcome_validated' => true],
            ['latency_ms' => 600, 'outcome_validated' => true],
            ['latency_ms' => 800, 'outcome_validated' => true],
            ['latency_ms' => 1000, 'outcome_validated' => true],
        ]);

        // p95 -> rank 5 = 1000, exactly the band edge -> claim holds.
        $this->assertSame(1000, $atBoundary['p95_latency_ms']);
        $this->assertTrue($atBoundary['near_zero_threshold_met']);
        $this->assertSame('pass', $atBoundary['verdict']);

        $oneBeyond = $this->scorer->score([
            ['latency_ms' => 200, 'outcome_validated' => true],
            ['latency_ms' => 400, 'outcome_validated' => true],
            ['latency_ms' => 600, 'outcome_validated' => true],
            ['latency_ms' => 800, 'outcome_validated' => true],
            ['latency_ms' => 1001, 'outcome_validated' => true],
        ]);

        // p95 -> rank 5 = 1001, one ms past the band edge -> claim foreclosed.
        $this->assertSame(1001, $oneBeyond['p95_latency_ms']);
        $this->assertFalse($oneBeyond['near_zero_threshold_met']);
        // Still a trusted, sovereignty-intact, blocker-free sample: verdict pass.
        $this->assertSame('pass', $oneBeyond['verdict']);
    }

    /**
     * Acceptance rule 1: missing validated outcome blocks.
     */
    public function testMissingValidatedOutcomeBlocks(): void
    {
        $result = $this->scorer->score([
            ['latency_ms' => 50, 'outcome_validated' => true],
            ['latency_ms' => 60, 'outcome_validated' => false],
            ['latency_ms' => 70, 'outcome_validated' => true],
            ['latency_ms' => 80, 'outcome_validated' => true],
            ['latency_ms' => 90, 'outcome_validated' => true],
            ['latency_ms' => 40, 'outcome_validated' => true],
        ]);

        $this->assertSame('block', $result['verdict']);
        $this->assertSame(['missing_validated_outcome'], $result['blockers']);
        $this->assertFalse($result['near_zero_threshold_met']);
    }

    /**
     * Acceptance rule 2: sovereignty violation blocks and marks sovereignty
     * not intact, foreclosing the fusion claim.
     */
    public function testSovereigntyViolationBlocks(): void
    {
        $result = $this->scorer->score([
            ['latency_ms' => 50, 'outcome_validated' => true],
            ['latency_ms' => 60, 'outcome_validated' => true, 'sovereignty_violation' => true],
            ['latency_ms' => 70, 'outcome_validated' => true],
            ['latency_ms' => 80, 'outcome_validated' => true],
            ['latency_ms' => 90, 'outcome_validated' => true],
            ['latency_ms' => 40, 'outcome_validated' => true],
        ]);

        $this->assertSame('block', $result['verdict']);
        $this->assertSame(['sovereignty_violation'], $result['blockers']);
        $this->assertFalse($result['sovereignty_intact']);
        $this->assertFalse($result['near_zero_threshold_met']);
    }

    /**
     * sovereignty_intact also reacts to an explicit sovereignty_intact=false
     * flag on an otherwise validated event.
     */
    public function testExplicitSovereigntyNotIntactFlagBlocks(): void
    {
        $result = $this->scorer->score([
            ['latency_ms' => 50, 'outcome_validated' => true, 'sovereignty_intact' => false],
            ['latency_ms' => 60, 'outcome_validated' => true],
            ['latency_ms' => 70, 'outcome_validated' => true],
            ['latency_ms' => 80, 'outcome_validated' => true],
            ['latency_ms' => 90, 'outcome_validated' => true],
            ['latency_ms' => 40, 'outcome_validated' => true],
        ]);

        $this->assertSame('block', $result['verdict']);
        $this->assertSame(['sovereignty_violation'], $result['blockers']);
        $this->assertFalse($result['sovereignty_intact']);
    }

    /**
     * Acceptance rule 3: low sample_size returns insufficient_evidence.
     */
    public function testLowSampleSizeReturnsInsufficientEvidence(): void
    {
        $result = $this->scorer->score([
            ['latency_ms' => 50, 'outcome_validated' => true],
            ['latency_ms' => 60, 'outcome_validated' => true],
        ]);

        $this->assertSame('insufficient_evidence', $result['verdict']);
        $this->assertTrue($result['insufficient_evidence']);
        $this->assertSame(2, $result['sample_size']);
        $this->assertFalse($result['near_zero_threshold_met']);
        $this->assertSame([], $result['blockers']);
    }

    /**
     * Both ordered gates fire together (missing outcome before sovereignty
     * violation), non-array entries are dropped, and a negative latency clamps
     * to zero rather than corrupting the percentile.
     */
    public function testOrderedBlockersAndInputSanitisation(): void
    {
        $result = $this->scorer->score([
            'not-an-array',
            ['latency_ms' => -100, 'outcome_validated' => true],
            ['latency_ms' => 60, 'outcome_validated' => false],
            ['latency_ms' => 70, 'outcome_validated' => true, 'sovereignty_intact' => false],
        ]);

        $this->assertSame('block', $result['verdict']);
        $this->assertSame(['missing_validated_outcome', 'sovereignty_violation'], $result['blockers']);
        // Only the single validated, sovereignty-intact event with clamped latency 0 is measurable.
        $this->assertSame(1, $result['sample_size']);
        $this->assertSame(0, $result['p50_latency_ms']);
    }

    /**
     * No events at all is an insufficient-evidence verdict with zero percentiles
     * and no fusion claim, never a divide-by-zero or out-of-bound value.
     */
    public function testEmptyEventsIsInsufficientEvidence(): void
    {
        $result = $this->scorer->score([]);

        $this->assertSame('insufficient_evidence', $result['verdict']);
        $this->assertSame(0, $result['p50_latency_ms']);
        $this->assertSame(0, $result['p95_latency_ms']);
        $this->assertSame(0, $result['sample_size']);
        $this->assertTrue($result['insufficient_evidence']);
        $this->assertFalse($result['near_zero_threshold_met']);
        $this->assertSame([], $result['evidence_refs']);
    }

    /**
     * The p95 never exceeds the observed maximum latency, and the p50 never
     * exceeds the p95 (percentile ordering invariant) for an arbitrary sample.
     */
    public function testPercentilesRespectOrderingAndObservedMaximum(): void
    {
        $result = $this->scorer->score([
            ['latency_ms' => 10, 'outcome_validated' => true],
            ['latency_ms' => 4000, 'outcome_validated' => true],
            ['latency_ms' => 250, 'outcome_validated' => true],
            ['latency_ms' => 30, 'outcome_validated' => true],
            ['latency_ms' => 800, 'outcome_validated' => true],
            ['latency_ms' => 95, 'outcome_validated' => true],
            ['latency_ms' => 1200, 'outcome_validated' => true],
        ]);

        $observedMax = 4000;
        $this->assertLessThanOrEqual($observedMax, $result['p95_latency_ms']);
        $this->assertLessThanOrEqual($result['p95_latency_ms'], $result['p50_latency_ms']);
        // Nearest-rank over 7 sorted samples [10,30,95,250,800,1200,4000]:
        // p50 -> ceil(3.5)=4 => 250, p95 -> ceil(6.65)=7 => 4000.
        $this->assertSame(250, $result['p50_latency_ms']);
        $this->assertSame(4000, $result['p95_latency_ms']);
    }

    /**
     * A non-finite (INF/NAN) or magnitude-overflowing float latency is the
     * antithesis of a near-zero collapse and must NOT be silently coerced to a
     * tiny value that satisfies the fusion claim (fail-open). It clamps to a
     * large latency (PHP_INT_MAX), so near_zero_threshold_met stays false, the
     * percentiles stay within bounds, and no runtime warning is emitted.
     */
    public function testNonFiniteOrOverflowingLatencyCannotMeetNearZeroClaim(): void
    {
        foreach ([INF, NAN, 1.0e300] as $poison) {
            $errors = [];
            set_error_handler(static function (int $errno, string $msg) use (&$errors): bool {
                $errors[] = $msg;

                return true;
            });

            try {
                $result = $this->scorer->score([
                    ['latency_ms' => $poison, 'outcome_validated' => true],
                    ['latency_ms' => 10, 'outcome_validated' => true],
                    ['latency_ms' => 20, 'outcome_validated' => true],
                    ['latency_ms' => 30, 'outcome_validated' => true],
                    ['latency_ms' => 40, 'outcome_validated' => true],
                ]);
            } finally {
                restore_error_handler();
            }

            // No int-cast warning leaked (purity / no I/O side channel).
            $this->assertSame([], $errors);
            // A poisoned latency clamps to the largest measurable value: p95 is it,
            // so the near-zero band can never be satisfied.
            $this->assertSame(PHP_INT_MAX, $result['p95_latency_ms']);
            $this->assertFalse($result['near_zero_threshold_met']);
            // Percentile ordering invariant still holds and the sample is trusted.
            $this->assertLessThanOrEqual($result['p95_latency_ms'], $result['p50_latency_ms']);
            $this->assertSame('pass', $result['verdict']);
            $this->assertSame(5, $result['sample_size']);
        }
    }

    /**
     * A numeric-STRING latency (the shape a JSON-decoded event routinely carries;
     * the slice row only requires the value be "numeric") must be measured at its
     * real magnitude, not silently coerced to 0. A sample of large string
     * latencies (~90s, far above the 1000ms band) must therefore NOT meet the
     * near-zero fusion claim — coercing them to 0 would be a fail-open that lets a
     * latency 90x over the band masquerade as a near-zero collapse.
     */
    public function testNumericStringLatencyIsMeasuredAndCannotFakeNearZeroCollapse(): void
    {
        $bigStringLatencies = $this->scorer->score([
            ['latency_ms' => '90000', 'outcome_validated' => true],
            ['latency_ms' => '85000', 'outcome_validated' => true],
            ['latency_ms' => '120000', 'outcome_validated' => true],
            ['latency_ms' => '99000', 'outcome_validated' => true],
            ['latency_ms' => '110000', 'outcome_validated' => true],
        ]);

        // Sorted [85000,90000,99000,110000,120000]: p50 -> rank 3 = 99000, p95 -> rank 5 = 120000.
        $this->assertSame(99000, $bigStringLatencies['p50_latency_ms']);
        $this->assertSame(120000, $bigStringLatencies['p95_latency_ms']);
        $this->assertFalse($bigStringLatencies['near_zero_threshold_met']);
        $this->assertSame('pass', $bigStringLatencies['verdict']);

        // A numeric-string sample genuinely inside the band still reads as near-zero.
        $smallStringLatencies = $this->scorer->score([
            ['latency_ms' => '50', 'outcome_validated' => true],
            ['latency_ms' => '80', 'outcome_validated' => true],
            ['latency_ms' => '120', 'outcome_validated' => true],
            ['latency_ms' => '200', 'outcome_validated' => true],
            ['latency_ms' => '300', 'outcome_validated' => true],
        ]);

        $this->assertSame(120, $smallStringLatencies['p50_latency_ms']);
        $this->assertSame(300, $smallStringLatencies['p95_latency_ms']);
        $this->assertTrue($smallStringLatencies['near_zero_threshold_met']);
    }

    /**
     * Identical input always yields identical output (pure / deterministic).
     */
    public function testIdenticalInputIsDeterministic(): void
    {
        $events = [
            ['latency_ms' => 50, 'outcome_validated' => true, 'evidence_ref' => 'ev-1'],
            ['latency_ms' => 80, 'outcome_validated' => true, 'evidence_ref' => 'ev-2'],
            ['latency_ms' => 120, 'outcome_validated' => true, 'evidence_ref' => 'ev-3'],
            ['latency_ms' => 200, 'outcome_validated' => true, 'evidence_ref' => 'ev-4'],
            ['latency_ms' => 300, 'outcome_validated' => true, 'evidence_ref' => 'ev-5'],
        ];

        $this->assertSame(
            $this->scorer->score($events),
            $this->scorer->score($events),
        );
    }
}
