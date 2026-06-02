<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\L8P3PredictiveTwinCertificationService;
use PHPUnit\Framework\TestCase;

final class L8P3PredictiveTwinCertificationServiceTest extends TestCase
{
    private L8P3PredictiveTwinCertificationService $service;

    protected function setUp(): void
    {
        $this->service = new L8P3PredictiveTwinCertificationService();
    }

    /**
     * P1/P2/P5 all certified.
     *
     * @return array<string,bool>
     */
    private function allPrerequisitesCertified(): array
    {
        return ['p1' => true, 'p2' => true, 'p5' => true];
    }

    /**
     * Four prediction records, three correct (predicted direction matches actual),
     * one wrong — all grounded. twin_accuracy = 3/4 = 0.75.
     *
     * @return list<array<string,mixed>>
     */
    private function fourGroundedPredictionsThreeCorrect(): array
    {
        return [
            ['predicted_improved' => true, 'actual_improved' => true],
            ['predicted_improved' => true, 'actual_improved' => true],
            ['predicted_improved' => false, 'actual_improved' => false],
            ['predicted_improved' => true, 'actual_improved' => false],
        ];
    }

    public function testCertifiesWhenTwinGuidanceRaisesRetainedRateWithAccuratePredictions(): void
    {
        $result = $this->service->certify([
            'prerequisites' => $this->allPrerequisitesCertified(),
            'twin_guided' => ['retained_count' => 9, 'total' => 10],
            'trial_baseline' => ['retained_count' => 5, 'total' => 10],
            'predictions' => $this->fourGroundedPredictionsThreeCorrect(),
        ]);

        $this->assertSame('atlas.aaeos.l8.p3_predictive_twin_certification.v1', $result['schema_version']);
        $this->assertSame('L8-P3', $result['phase']);

        $this->assertIsBool($result['p3_certified']);
        $this->assertTrue($result['p3_certified']);
        $this->assertSame('p3_certified', $result['status']);
        $this->assertSame([], $result['blockers']);
        $this->assertSame([], $result['missing_prerequisites']);

        // retained_rate_delta = 0.9 - 0.5 = 0.4 (> 0, the core P3 claim).
        $this->assertEqualsWithDelta(0.4, $result['retained_rate_delta'], 0.0001);
        $this->assertEqualsWithDelta(0.9, $result['twin_guided_retained_rate'], 0.0001);
        $this->assertEqualsWithDelta(0.5, $result['trial_baseline_retained_rate'], 0.0001);
        $this->assertGreaterThan(0.0, $result['retained_rate_delta']);

        // twin_accuracy = 3/4 correct.
        $this->assertEqualsWithDelta(0.75, $result['twin_accuracy'], 0.0001);
        $this->assertSame(4, $result['prediction_count']);
        $this->assertSame(4, $result['grounded_prediction_count']);
        $this->assertSame(3, $result['correct_prediction_count']);
        $this->assertSame(0, $result['ungrounded_prediction_count']);
    }

    public function testRetainedRateDeltaZeroOrBelowBlocks(): void
    {
        // Twin-guided arm retains no more than the trial baseline: delta <= 0.
        $result = $this->service->certify([
            'prerequisites' => $this->allPrerequisitesCertified(),
            'twin_guided' => ['retained_count' => 5, 'total' => 10],
            'trial_baseline' => ['retained_count' => 5, 'total' => 10],
            'predictions' => $this->fourGroundedPredictionsThreeCorrect(),
        ]);

        $this->assertFalse($result['p3_certified']);
        $this->assertSame('blocked_not_p3', $result['status']);
        $this->assertEqualsWithDelta(0.0, $result['retained_rate_delta'], 0.0001);
        $this->assertContains('retained_rate_not_improved', $result['blockers']);
    }

    public function testRetainedRateDeltaNegativeBlocks(): void
    {
        // Twin guidance actually retained fewer evolutions than trial-based.
        $result = $this->service->certify([
            'prerequisites' => $this->allPrerequisitesCertified(),
            'twin_guided' => ['retained_count' => 3, 'total' => 10],
            'trial_baseline' => ['retained_count' => 6, 'total' => 10],
            'predictions' => $this->fourGroundedPredictionsThreeCorrect(),
        ]);

        $this->assertFalse($result['p3_certified']);
        $this->assertEqualsWithDelta(-0.3, $result['retained_rate_delta'], 0.0001);
        $this->assertLessThan(0.0, $result['retained_rate_delta']);
        $this->assertContains('retained_rate_not_improved', $result['blockers']);
    }

    public function testMissingPrerequisitePhaseBlocksAndIsListed(): void
    {
        // P1 and P2 certified, P5 missing — even with a strong retained-rate lift.
        $result = $this->service->certify([
            'prerequisites' => ['p1' => true, 'p2' => true],
            'twin_guided' => ['retained_count' => 9, 'total' => 10],
            'trial_baseline' => ['retained_count' => 5, 'total' => 10],
            'predictions' => $this->fourGroundedPredictionsThreeCorrect(),
        ]);

        $this->assertFalse($result['p3_certified']);
        $this->assertSame('blocked_not_p3', $result['status']);
        $this->assertSame(['p5'], $result['missing_prerequisites']);
        $this->assertContains('prerequisite_phase_not_certified', $result['blockers']);
    }

    public function testAllThreePrerequisitesMissingAreListedInCanonicalOrder(): void
    {
        $result = $this->service->certify([
            'prerequisites' => [],
            'twin_guided' => ['retained_count' => 9, 'total' => 10],
            'trial_baseline' => ['retained_count' => 5, 'total' => 10],
            'predictions' => $this->fourGroundedPredictionsThreeCorrect(),
        ]);

        $this->assertFalse($result['p3_certified']);
        $this->assertSame(['p1', 'p2', 'p5'], $result['missing_prerequisites']);
        $this->assertContains('prerequisite_phase_not_certified', $result['blockers']);
    }

    public function testPredictionWithoutOutcomeBlocks(): void
    {
        // Three grounded + one forecast with no measured outcome (un-grounded).
        $result = $this->service->certify([
            'prerequisites' => $this->allPrerequisitesCertified(),
            'twin_guided' => ['retained_count' => 9, 'total' => 10],
            'trial_baseline' => ['retained_count' => 5, 'total' => 10],
            'predictions' => [
                ['predicted_improved' => true, 'actual_improved' => true],
                ['predicted_improved' => true, 'actual_improved' => true],
                ['predicted_improved' => false, 'actual_improved' => false],
                ['predicted_improved' => true],
            ],
        ]);

        $this->assertFalse($result['p3_certified']);
        $this->assertSame('blocked_not_p3', $result['status']);
        $this->assertSame(4, $result['prediction_count']);
        $this->assertSame(3, $result['grounded_prediction_count']);
        $this->assertSame(1, $result['ungrounded_prediction_count']);
        $this->assertContains('prediction_without_outcome', $result['blockers']);
    }

    public function testTwinAccuracyIsComputedFromPredictionEvidenceNotCanned(): void
    {
        // Two correct of five grounded => 0.4 (below the 0.5 floor), retained rate
        // strongly improved, all prerequisites met: only the floor blocker fires.
        $result = $this->service->certify([
            'prerequisites' => $this->allPrerequisitesCertified(),
            'twin_guided' => ['retained_count' => 8, 'total' => 10],
            'trial_baseline' => ['retained_count' => 4, 'total' => 10],
            'predictions' => [
                ['predicted_delta' => 1.0, 'actual_delta' => 2.0],   // both improved -> correct
                ['predicted_delta' => -1.0, 'actual_delta' => -0.5], // both worsened -> correct
                ['predicted_delta' => 1.0, 'actual_delta' => -1.0],  // wrong
                ['predicted_delta' => 1.0, 'actual_delta' => -2.0],  // wrong
                ['predicted_delta' => -1.0, 'actual_delta' => 3.0],  // wrong
            ],
        ]);

        $this->assertEqualsWithDelta(0.4, $result['twin_accuracy'], 0.0001);
        $this->assertSame(2, $result['correct_prediction_count']);
        $this->assertSame(5, $result['grounded_prediction_count']);
        $this->assertFalse($result['p3_certified']);
        $this->assertContains('twin_accuracy_below_floor', $result['blockers']);
        // The retained-rate lift itself is real and positive; the twin is the gap.
        $this->assertGreaterThan(0.0, $result['retained_rate_delta']);
        $this->assertNotContains('retained_rate_not_improved', $result['blockers']);
    }

    public function testTwinAccuracyNeverExceedsOneEvenWhenAllPredictionsCorrect(): void
    {
        $result = $this->service->certify([
            'prerequisites' => $this->allPrerequisitesCertified(),
            'twin_guided' => ['retained_rate' => 0.95],
            'trial_baseline' => ['retained_rate' => 0.40],
            'predictions' => [
                ['predicted_improved' => true, 'actual_improved' => true],
                ['predicted_improved' => false, 'actual_improved' => false],
                ['predicted_improved' => true, 'actual_improved' => true],
            ],
        ]);

        $this->assertEqualsWithDelta(1.0, $result['twin_accuracy'], 0.0001);
        $this->assertLessThanOrEqual(1.0, $result['twin_accuracy']);
        $this->assertGreaterThanOrEqual(0.0, $result['twin_accuracy']);
        $this->assertSame(0.5, $result['twin_accuracy_floor']);
        $this->assertTrue($result['p3_certified']);
    }

    public function testRetainedRateStaysWithinUnitBoundWhenCountsExceedTotal(): void
    {
        // Malformed arm where retained_count > total must not produce a rate above 1.
        $result = $this->service->certify([
            'prerequisites' => $this->allPrerequisitesCertified(),
            'twin_guided' => ['retained_count' => 30, 'total' => 10],
            'trial_baseline' => ['retained_count' => 2, 'total' => 10],
            'predictions' => $this->fourGroundedPredictionsThreeCorrect(),
        ]);

        $this->assertLessThanOrEqual(1.0, $result['twin_guided_retained_rate']);
        $this->assertEqualsWithDelta(1.0, $result['twin_guided_retained_rate'], 0.0001);
        $this->assertLessThanOrEqual(1.0, $result['retained_rate_delta']);
        $this->assertGreaterThanOrEqual(-1.0, $result['retained_rate_delta']);
    }

    public function testEmptyPredictionsYieldZeroAccuracyAndDoNotCertify(): void
    {
        $result = $this->service->certify([
            'prerequisites' => $this->allPrerequisitesCertified(),
            'twin_guided' => ['retained_count' => 9, 'total' => 10],
            'trial_baseline' => ['retained_count' => 5, 'total' => 10],
            'predictions' => [],
        ]);

        $this->assertEqualsWithDelta(0.0, $result['twin_accuracy'], 0.0001);
        $this->assertSame(0, $result['prediction_count']);
        $this->assertSame(0, $result['grounded_prediction_count']);
        $this->assertFalse($result['p3_certified']);
        // No un-grounded prediction (there are none), so the floor blocker owns it.
        $this->assertContains('twin_accuracy_below_floor', $result['blockers']);
        $this->assertNotContains('prediction_without_outcome', $result['blockers']);
    }

    public function testBlockersAreOrderedCanonicallyWhenMultipleFail(): void
    {
        // Missing P5, retained rate not improved, and an un-grounded prediction:
        // all three blockers present in canonical order.
        $result = $this->service->certify([
            'prerequisites' => ['p1' => true, 'p2' => true],
            'twin_guided' => ['retained_count' => 5, 'total' => 10],
            'trial_baseline' => ['retained_count' => 5, 'total' => 10],
            'predictions' => [
                ['predicted_improved' => true, 'actual_improved' => true],
                ['predicted_improved' => true],
            ],
        ]);

        $this->assertFalse($result['p3_certified']);
        $this->assertSame(
            [
                'prerequisite_phase_not_certified',
                'retained_rate_not_improved',
                'prediction_without_outcome',
            ],
            $result['blockers'],
        );
    }

    public function testPrerequisiteAcceptsArrayCertifiedFlag(): void
    {
        $result = $this->service->certify([
            'prerequisites' => [
                'p1' => ['certified' => true],
                'p2' => ['p2_certified' => true],
                'p5' => ['passed' => true],
            ],
            'twin_guided' => ['retained_count' => 7, 'total' => 10],
            'trial_baseline' => ['retained_count' => 4, 'total' => 10],
            'predictions' => $this->fourGroundedPredictionsThreeCorrect(),
        ]);

        $this->assertSame([], $result['missing_prerequisites']);
        $this->assertTrue($result['p3_certified']);
        $this->assertEqualsWithDelta(0.3, $result['retained_rate_delta'], 0.0001);
    }

    public function testExplicitFalsePrerequisiteIsTreatedAsMissing(): void
    {
        $result = $this->service->certify([
            'prerequisites' => ['p1' => true, 'p2' => false, 'p5' => true],
            'twin_guided' => ['retained_count' => 9, 'total' => 10],
            'trial_baseline' => ['retained_count' => 5, 'total' => 10],
            'predictions' => $this->fourGroundedPredictionsThreeCorrect(),
        ]);

        $this->assertSame(['p2'], $result['missing_prerequisites']);
        $this->assertFalse($result['p3_certified']);
    }

    public function testIdenticalInputIsDeterministic(): void
    {
        $inputs = [
            'prerequisites' => $this->allPrerequisitesCertified(),
            'twin_guided' => ['retained_count' => 9, 'total' => 10],
            'trial_baseline' => ['retained_count' => 5, 'total' => 10],
            'predictions' => $this->fourGroundedPredictionsThreeCorrect(),
        ];

        $first = $this->service->certify($inputs);
        $second = $this->service->certify($inputs);

        $this->assertSame($first, $second);
    }
}
