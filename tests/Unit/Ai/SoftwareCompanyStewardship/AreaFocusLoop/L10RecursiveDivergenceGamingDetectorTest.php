<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\L10RecursiveDivergenceGamingDetector;
use PHPUnit\Framework\TestCase;

final class L10RecursiveDivergenceGamingDetectorTest extends TestCase
{
    private L10RecursiveDivergenceGamingDetector $detector;

    protected function setUp(): void
    {
        $this->detector = new L10RecursiveDivergenceGamingDetector();
    }

    public function testDetectExposesEverySignalFieldForCleanEvidence(): void
    {
        // A real metric gain that the independent quality reading genuinely
        // corroborates, with every invariant stable: no stop signal at all.
        $result = $this->detector->detect([
            'dm_dt' => 0.30,
            'quality' => ['before' => 0.40, 'after' => 0.62],
            'invariants' => [
                ['id' => 'merge_truth', 'drift' => false],
                ['id' => 'provider_claim_truth', 'status' => 'stable'],
            ],
            'evidence_refs' => ['ledger://r3/cycle/41'],
        ]);

        $this->assertSame('atlas.aaeos.l10.recursive_divergence_gaming.v1', $result['schema_version']);
        $this->assertFalse($result['divergence_detected']);
        $this->assertFalse($result['gaming_detected']);
        $this->assertFalse($result['hard_stop_required']);
        $this->assertSame([], $result['signals']);
        $this->assertSame(['ledger://r3/cycle/41'], $result['evidence_refs']);
        $this->assertSame([], $result['blockers']);
    }

    public function testDmDtUpWithQualityDownFlagsGaming(): void
    {
        // dm_dt accelerates upward while independent quality really degrades:
        // textbook Goodhart metric gaming.
        $result = $this->detector->detect([
            'dm_dt' => 0.45,
            'quality' => ['before' => 0.80, 'after' => 0.55],
            'evidence_refs' => ['ledger://r3/cycle/77', 'ledger://probe/9'],
        ]);

        $this->assertTrue($result['gaming_detected']);
        $this->assertTrue($result['divergence_detected']);
        $this->assertTrue($result['hard_stop_required']);
        $this->assertSame(
            ['metric_gaming', 'metric_reality_divergence'],
            $result['signals'],
        );
        $this->assertSame(
            ['ledger://r3/cycle/77', 'ledger://probe/9'],
            $result['evidence_refs'],
        );
        $this->assertSame([], $result['blockers']);
    }

    public function testInvariantDriftFlagsHardStopRequired(): void
    {
        // Metric and quality move together honestly (no gaming, no divergence),
        // but one sacred invariant has drifted: hard stop is still forced.
        $result = $this->detector->detect([
            'dm_dt' => 0.20,
            'quality' => ['before' => 0.50, 'after' => 0.71],
            'invariants' => [
                ['id' => 'scope_engineering_only', 'drift' => false],
                ['id' => 'sovereignty', 'drift' => true],
            ],
        ]);

        $this->assertTrue($result['hard_stop_required']);
        $this->assertFalse($result['gaming_detected']);
        $this->assertFalse($result['divergence_detected']);
        $this->assertSame(['invariant_drift'], $result['signals']);
        $this->assertSame([], $result['evidence_refs']);
        $this->assertSame([], $result['blockers']);
    }

    public function testEmptyEvidenceBlocks(): void
    {
        $result = $this->detector->detect([]);

        $this->assertSame(['evidence_empty'], $result['blockers']);
        $this->assertFalse($result['divergence_detected']);
        $this->assertFalse($result['gaming_detected']);
        $this->assertFalse($result['hard_stop_required']);
        $this->assertSame([], $result['signals']);
        $this->assertSame([], $result['evidence_refs']);
    }

    public function testDivergenceWithoutGamingStillForcesHardStop(): void
    {
        // The metric makes a real DOWN move while quality stays flat: the metric
        // diverges from reality even though it is not the dm_dt-up gaming case.
        // DoD: recursive improvement stops on divergence OR gaming.
        $result = $this->detector->detect([
            'dm_dt' => -0.30,
            'quality' => ['before' => 0.60, 'after' => 0.605],
            'evidence_refs' => ['ledger://r3/cycle/12'],
        ]);

        $this->assertTrue($result['divergence_detected']);
        $this->assertFalse($result['gaming_detected']);
        $this->assertTrue($result['hard_stop_required']);
        $this->assertSame(['metric_reality_divergence'], $result['signals']);
    }

    public function testMetricDownWhileQualityUpIsDivergenceNotGaming(): void
    {
        // The metric makes a real DOWN move while independent quality makes a real
        // UP move: the metric and reality move in OPPOSITE directions, so the metric
        // fails to corroborate reality and the recursion has diverged. It is NOT
        // gaming (gaming requires the optimized metric to accelerate UP), so this
        // pins the opposite-sign branch of the corroboration rule distinctly from the
        // flat-quality branch above. A fail-open here would silently certify a metric
        // that contradicts the reality reading as clean.
        $result = $this->detector->detect([
            'dm_dt' => -0.30,
            'quality' => ['before' => 0.40, 'after' => 0.70],
        ]);

        $this->assertTrue($result['divergence_detected']);
        $this->assertFalse($result['gaming_detected']);
        $this->assertTrue($result['hard_stop_required']);
        $this->assertSame(['metric_reality_divergence'], $result['signals']);
    }

    public function testRealMetricGainCorroboratedByQualityIsNotDivergence(): void
    {
        // Generalisation guard with inputs absent from the other cases: a bare
        // numeric quality delta that moves the same direction as dm_dt and clears
        // the noise floor must read clean — proving the rule is computed, not canned.
        $result = $this->detector->detect([
            'dm_dt' => 0.18,
            'quality' => 0.12,
        ]);

        $this->assertFalse($result['divergence_detected']);
        $this->assertFalse($result['gaming_detected']);
        $this->assertFalse($result['hard_stop_required']);
        $this->assertSame([], $result['signals']);
    }

    public function testNoiseLevelMetricMoveIsNotGamingOrDivergence(): void
    {
        // dm_dt inside the noise floor is not a real move, so even a quality drop
        // raises no gaming/divergence signal: the detector respects the epsilon.
        $result = $this->detector->detect([
            'dm_dt' => 0.004,
            'quality' => ['before' => 0.70, 'after' => 0.40],
        ]);

        $this->assertFalse($result['gaming_detected']);
        $this->assertFalse($result['divergence_detected']);
        $this->assertFalse($result['hard_stop_required']);
        $this->assertSame([], $result['signals']);
    }

    public function testGamingAndInvariantDriftEmitBothSignalsInOrder(): void
    {
        // Both stop conditions at once: signals are ordered and de-duplicated,
        // and the metric_reality_divergence implied by gaming appears once.
        $result = $this->detector->detect([
            'dm_dt' => ['before' => 0.10, 'after' => 0.62],
            'quality' => ['before' => 0.90, 'after' => 0.50],
            'invariants' => [
                ['id' => 'merge_truth', 'status' => 'breached'],
            ],
            'evidence_refs' => ['ref://a', 'ref://a', 'ref://b'],
        ]);

        $this->assertTrue($result['gaming_detected']);
        $this->assertTrue($result['divergence_detected']);
        $this->assertTrue($result['hard_stop_required']);
        $this->assertSame(
            ['metric_gaming', 'metric_reality_divergence', 'invariant_drift'],
            $result['signals'],
        );
        // evidence_refs honours the list<string> contract: de-duplicated, re-indexed.
        $this->assertSame(['ref://a', 'ref://b'], $result['evidence_refs']);
    }

    public function testWhitespacePaddedStatusStillFlagsInvariantDrift(): void
    {
        // A status token arriving with surrounding whitespace / mixed case (e.g. from
        // a serialized envelope) must still be recognised as drift — the predicate
        // mirrors the loop's other invariant monitors, which trim before matching.
        // A fail-open here would silently skip the R3 hard stop on a drifted invariant.
        $result = $this->detector->detect([
            'dm_dt' => 0.20,
            'quality' => ['before' => 0.50, 'after' => 0.71],
            'invariants' => [
                ['id' => 'sovereignty', 'status' => '  BREACHED  '],
            ],
        ]);

        $this->assertTrue($result['hard_stop_required']);
        $this->assertSame(['invariant_drift'], $result['signals']);
    }

    public function testDetectionIsDeterministicForIdenticalEvidence(): void
    {
        $evidence = [
            'dm_dt' => 0.45,
            'quality' => ['before' => 0.80, 'after' => 0.55],
            'invariants' => [['id' => 'sovereignty', 'drift' => true]],
            'evidence_refs' => ['ledger://r3/cycle/77'],
        ];

        $first = $this->detector->detect($evidence);
        $second = $this->detector->detect($evidence);

        $this->assertSame($first, $second);
    }
}
