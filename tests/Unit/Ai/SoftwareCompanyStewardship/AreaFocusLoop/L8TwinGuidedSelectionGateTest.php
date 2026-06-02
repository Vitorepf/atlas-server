<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\L8TwinGuidedSelectionGate;
use PHPUnit\Framework\TestCase;

final class L8TwinGuidedSelectionGateTest extends TestCase
{
    private L8TwinGuidedSelectionGate $gate;

    protected function setUp(): void
    {
        $this->gate = new L8TwinGuidedSelectionGate();
    }

    public function testDecideReturnsOrderedCandidatesVerificationRequiredAndBlockedReasons(): void
    {
        $result = $this->gate->decide(
            [
                ['candidate_id' => 'evo_a', 'twin_priority' => 0.30],
                ['candidate_id' => 'evo_b', 'twin_priority' => 0.90],
                ['candidate_id' => 'evo_c', 'twin_priority' => 0.60],
            ],
            ['twin_accuracy' => 0.85],
        );

        $this->assertSame('atlas.aaeos.l8.twin_guided_selection.v1', $result['schema_version']);
        $this->assertTrue($result['verification_required']);
        $this->assertSame([], $result['blocked_reasons']);
        $this->assertTrue($result['guidance_applied']);

        // Ordered by twin priority descending: b (0.90) > c (0.60) > a (0.30).
        $this->assertSame(
            ['evo_b', 'evo_c', 'evo_a'],
            array_column($result['ordered_candidates'], 'candidate_id'),
        );
        $this->assertSame([1, 2, 3], array_column($result['ordered_candidates'], 'rank'));
    }

    public function testSelectedCandidateStillCarriesMeasuredOrRevertedRequired(): void
    {
        $result = $this->gate->decide(
            [
                ['candidate_id' => 'evo_top', 'twin_priority' => 0.99],
                ['candidate_id' => 'evo_low', 'twin_priority' => 0.10],
            ],
            ['twin_accuracy' => 0.92],
        );

        $this->assertTrue($result['guidance_applied']);
        $this->assertNotSame([], $result['ordered_candidates']);

        foreach ($result['ordered_candidates'] as $candidate) {
            $this->assertTrue($candidate['measured_or_reverted_required']);
        }

        // The twin's top pick is still subject to real verification: it can be tried, not claimed.
        $this->assertSame('evo_top', $result['ordered_candidates'][0]['candidate_id']);
        $this->assertTrue($result['ordered_candidates'][0]['measured_or_reverted_required']);
        $this->assertTrue($result['verification_required']);
    }

    public function testLowTwinAccuracyBlocksGuidance(): void
    {
        // Below the 0.6 floor: the twin is not trusted to reprioritise.
        $result = $this->gate->decide(
            [
                ['candidate_id' => 'evo_first', 'twin_priority' => 0.10],
                ['candidate_id' => 'evo_second', 'twin_priority' => 0.95],
            ],
            ['twin_accuracy' => 0.45],
        );

        $this->assertFalse($result['guidance_applied']);
        $this->assertContains('twin_accuracy_below_floor', $result['blocked_reasons']);

        // Fallback is the deterministic input order, NOT the twin's reordering.
        $this->assertSame(
            ['evo_first', 'evo_second'],
            array_column($result['ordered_candidates'], 'candidate_id'),
        );

        // Blocked guidance reports 0.0 effective priority — the twin did not rank these.
        $this->assertSame(0.0, $result['ordered_candidates'][0]['twin_priority']);
        $this->assertSame(0.0, $result['ordered_candidates'][1]['twin_priority']);
    }

    public function testBlockedGuidanceNeverRelaxesVerification(): void
    {
        // Doctrine: "Twin chooses what to try, never what to claim." Even when guidance is
        // blocked, verification stays mandatory and every candidate keeps the revert contract.
        $result = $this->gate->decide(
            [
                ['candidate_id' => 'evo_x', 'twin_priority' => 0.80],
            ],
            ['twin_accuracy' => 0.20],
        );

        $this->assertFalse($result['guidance_applied']);
        $this->assertTrue($result['verification_required']);

        foreach ($result['ordered_candidates'] as $candidate) {
            $this->assertTrue($candidate['measured_or_reverted_required']);
        }
    }

    public function testStaleTwinModelBlocksGuidanceEvenWithHighAccuracy(): void
    {
        $result = $this->gate->decide(
            [
                ['candidate_id' => 'evo_p', 'twin_priority' => 0.20],
                ['candidate_id' => 'evo_q', 'twin_priority' => 0.99],
            ],
            ['twin_accuracy' => 0.97, 'stale_model' => true],
        );

        $this->assertFalse($result['guidance_applied']);
        $this->assertContains('twin_model_stale', $result['blocked_reasons']);
        $this->assertSame(
            ['evo_p', 'evo_q'],
            array_column($result['ordered_candidates'], 'candidate_id'),
        );
        $this->assertTrue($result['verification_required']);
    }

    public function testTwinAccuracyExactlyAtFloorAllowsGuidance(): void
    {
        // Boundary: accuracy == 0.6 is trusted (>= floor), so guidance applies.
        $result = $this->gate->decide(
            [
                ['candidate_id' => 'evo_lo', 'twin_priority' => 0.10],
                ['candidate_id' => 'evo_hi', 'twin_priority' => 0.70],
            ],
            ['twin_accuracy' => 0.6],
        );

        $this->assertTrue($result['guidance_applied']);
        $this->assertSame([], $result['blocked_reasons']);
        $this->assertSame(
            ['evo_hi', 'evo_lo'],
            array_column($result['ordered_candidates'], 'candidate_id'),
        );
    }

    public function testTieOnPriorityKeepsStableInputOrder(): void
    {
        $result = $this->gate->decide(
            [
                ['candidate_id' => 'evo_1', 'twin_priority' => 0.50],
                ['candidate_id' => 'evo_2', 'twin_priority' => 0.50],
                ['candidate_id' => 'evo_3', 'twin_priority' => 0.50],
            ],
            ['twin_accuracy' => 0.80],
        );

        $this->assertTrue($result['guidance_applied']);
        $this->assertSame(
            ['evo_1', 'evo_2', 'evo_3'],
            array_column($result['ordered_candidates'], 'candidate_id'),
        );
    }

    public function testPrioritiesCanComeFromTwinScoreMapWhenCandidateOmitsThem(): void
    {
        $result = $this->gate->decide(
            [
                ['candidate_id' => 'evo_alpha'],
                ['candidate_id' => 'evo_beta'],
                ['candidate_id' => 'evo_gamma'],
            ],
            [
                'twin_accuracy' => 0.75,
                'priorities' => [
                    'evo_alpha' => 0.20,
                    'evo_beta' => 0.88,
                    'evo_gamma' => 0.55,
                ],
            ],
        );

        $this->assertTrue($result['guidance_applied']);
        $this->assertSame(
            ['evo_beta', 'evo_gamma', 'evo_alpha'],
            array_column($result['ordered_candidates'], 'candidate_id'),
        );
        $this->assertEqualsWithDelta(0.88, $result['ordered_candidates'][0]['twin_priority'], 1e-9);
    }

    public function testEmptyCandidatesAreBlockedButVerificationStaysRequired(): void
    {
        $result = $this->gate->decide([], ['twin_accuracy' => 0.95]);

        $this->assertSame([], $result['ordered_candidates']);
        $this->assertContains('no_candidates', $result['blocked_reasons']);
        $this->assertFalse($result['guidance_applied']);
        $this->assertTrue($result['verification_required']);
    }

    public function testTwinAccuracyIsClampedToUnitIntervalAndNeverExceedsOne(): void
    {
        $high = $this->gate->decide(
            [['candidate_id' => 'evo_only', 'twin_priority' => 0.3]],
            ['twin_accuracy' => 5.0],
        );
        $this->assertSame(1.0, $high['twin_accuracy']);
        $this->assertLessThanOrEqual(1.0, $high['twin_accuracy']);
        $this->assertTrue($high['guidance_applied']);

        $negative = $this->gate->decide(
            [['candidate_id' => 'evo_only', 'twin_priority' => 0.3]],
            ['twin_accuracy' => -2.0],
        );
        $this->assertSame(0.0, $negative['twin_accuracy']);
        $this->assertFalse($negative['guidance_applied']);
        $this->assertContains('twin_accuracy_below_floor', $negative['blocked_reasons']);
    }

    public function testNonFiniteTwinAccuracyFailsClosedWithRecordedReason(): void
    {
        // A NaN accuracy is not a trustworthy measurement. It must fail closed: the output
        // twin_accuracy stays inside [0,1], guidance is blocked, and the block carries the
        // below-floor reason — never a silent block with empty blocked_reasons that leaks NaN.
        $result = $this->gate->decide(
            [
                ['candidate_id' => 'evo_a', 'twin_priority' => 0.50],
                ['candidate_id' => 'evo_b', 'twin_priority' => 0.90],
            ],
            ['twin_accuracy' => NAN],
        );

        $this->assertFalse(is_nan($result['twin_accuracy']));
        $this->assertGreaterThanOrEqual(0.0, $result['twin_accuracy']);
        $this->assertLessThanOrEqual(1.0, $result['twin_accuracy']);
        $this->assertSame(0.0, $result['twin_accuracy']);
        $this->assertFalse($result['guidance_applied']);
        $this->assertNotSame([], $result['blocked_reasons']);
        $this->assertContains('twin_accuracy_below_floor', $result['blocked_reasons']);
        // Verification stays mandatory regardless and the input order is preserved.
        $this->assertTrue($result['verification_required']);
        $this->assertSame(
            ['evo_a', 'evo_b'],
            array_column($result['ordered_candidates'], 'candidate_id'),
        );
    }

    public function testNonFinitePriorityIsSanitisedToFiniteDeterministicOrder(): void
    {
        // A NaN priority is not a usable ordering key (NAN <=> anything === 1 makes the
        // comparator non-transitive). It falls back to the 0.0 default so the sort stays a
        // consistent, reproducible proposal and no NaN leaks into twin_priority.
        $candidates = [
            ['candidate_id' => 'evo_nan', 'twin_priority' => NAN],
            ['candidate_id' => 'evo_hi', 'twin_priority' => 0.90],
            ['candidate_id' => 'evo_inf', 'twin_priority' => INF],
            ['candidate_id' => 'evo_lo', 'twin_priority' => 0.10],
        ];

        $result = $this->gate->decide($candidates, ['twin_accuracy' => 0.90]);

        $this->assertTrue($result['guidance_applied']);

        foreach ($result['ordered_candidates'] as $candidate) {
            $this->assertTrue(is_finite($candidate['twin_priority']));
        }

        // evo_hi (0.90) ranks first; the non-finite priorities collapse to 0.0 and hold their
        // stable input order behind the real positive priority. evo_lo (0.10) sits above them.
        $this->assertSame(
            ['evo_hi', 'evo_lo', 'evo_nan', 'evo_inf'],
            array_column($result['ordered_candidates'], 'candidate_id'),
        );
        $this->assertSame(0.0, $result['ordered_candidates'][2]['twin_priority']);
        $this->assertSame(0.0, $result['ordered_candidates'][3]['twin_priority']);

        // Deterministic: identical input yields an identical ordering (no usort drift).
        $repeat = $this->gate->decide($candidates, ['twin_accuracy' => 0.90]);
        $this->assertSame(
            array_column($result['ordered_candidates'], 'candidate_id'),
            array_column($repeat['ordered_candidates'], 'candidate_id'),
        );
    }

    public function testCandidatesWithoutUsableIdAreDropped(): void
    {
        $result = $this->gate->decide(
            [
                ['candidate_id' => '', 'twin_priority' => 0.99],
                ['twin_priority' => 0.99],
                ['candidate_id' => 'evo_real', 'twin_priority' => 0.10],
            ],
            ['twin_accuracy' => 0.90],
        );

        $this->assertSame(
            ['evo_real'],
            array_column($result['ordered_candidates'], 'candidate_id'),
        );
    }

    public function testBlockedReasonsIsAListOfStrings(): void
    {
        $result = $this->gate->decide([], ['twin_accuracy' => 0.1, 'stale_model' => true]);

        $this->assertSame(array_values($result['blocked_reasons']), $result['blocked_reasons']);
        $this->assertContainsOnlyString($result['blocked_reasons']);
        // No candidates + stale + below floor: all three reasons accumulate.
        $this->assertContains('no_candidates', $result['blocked_reasons']);
        $this->assertContains('twin_model_stale', $result['blocked_reasons']);
        $this->assertContains('twin_accuracy_below_floor', $result['blocked_reasons']);
    }

    public function testGeneralisesToUnseenInputsWithIdField(): void
    {
        // Uses the 'id' alias and magnitudes not present in any other case: proves ordering
        // is computed from inputs, not canned to specific test fixtures.
        $result = $this->gate->decide(
            [
                ['id' => 'cand_k', 'predicted_lift' => 12.5],
                ['id' => 'cand_m', 'predicted_lift' => 41.0],
                ['id' => 'cand_n', 'predicted_lift' => 3.25],
                ['id' => 'cand_p', 'predicted_lift' => 41.0],
            ],
            ['twin_accuracy' => 0.71],
        );

        $this->assertTrue($result['guidance_applied']);
        // 41.0 ties between cand_m and cand_p resolve by stable input order (m before p),
        // then cand_k (12.5), then cand_n (3.25).
        $this->assertSame(
            ['cand_m', 'cand_p', 'cand_k', 'cand_n'],
            array_column($result['ordered_candidates'], 'candidate_id'),
        );
        $this->assertSame([1, 2, 3, 4], array_column($result['ordered_candidates'], 'rank'));
        $this->assertEqualsWithDelta(41.0, $result['ordered_candidates'][0]['twin_priority'], 1e-9);
        $this->assertEqualsWithDelta(3.25, $result['ordered_candidates'][3]['twin_priority'], 1e-9);

        foreach ($result['ordered_candidates'] as $candidate) {
            $this->assertTrue($candidate['measured_or_reverted_required']);
        }
    }
}
