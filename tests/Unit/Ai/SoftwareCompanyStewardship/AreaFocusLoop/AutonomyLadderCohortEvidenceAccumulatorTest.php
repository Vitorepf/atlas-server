<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AutonomyLadderCohortEvidenceAccumulator;
use PHPUnit\Framework\TestCase;

final class AutonomyLadderCohortEvidenceAccumulatorTest extends TestCase
{
    private AutonomyLadderCohortEvidenceAccumulator $accumulator;

    protected function setUp(): void
    {
        $this->accumulator = new AutonomyLadderCohortEvidenceAccumulator();
    }

    /**
     * Build one real-work cycle of the given kind, overridable per field so
     * tests can flip a single gate without disturbing the rest.
     *
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function realCycle(string $kind, array $overrides = []): array
    {
        return array_merge([
            'kind' => $kind,
            'dry_run' => false,
            'docs_only' => false,
            'main_advanced' => true,
            'judge_present' => true,
            'provider_receipt_present' => true,
        ], $overrides);
    }

    public function testAccumulateReturnsCountedRequiredAndRejectedByRung(): void
    {
        $cycles = [
            $this->realCycle('green_slice'),
            $this->realCycle('green_slice'),
            $this->realCycle('pair_work'),
            $this->realCycle('certified_feature'),
            $this->realCycle('certified_obra', [
                'operator_signature' => 'op-1',
                'architect_signature' => 'arch-1',
            ]),
        ];

        $result = $this->accumulator->accumulate($cycles);

        $this->assertSame('atlas.loop.autonomy_ladder_cohort_evidence.v1', $result['schema_version']);

        // counted by rung is computed from the real-work cycles above.
        $this->assertSame(
            ['L1' => 2, 'L2' => 1, 'L3' => 1, 'L4' => 1],
            $result['counted'],
        );

        // required is the byte-for-byte runbook cohort size per rung.
        $this->assertSame(
            ['L1' => 20, 'L2' => 30, 'L3' => 15, 'L4' => 5],
            $result['required'],
        );

        // rejected is keyed by reason; all zero when every cycle is real work.
        $this->assertSame(
            [
                'dry_run' => 0,
                'docs_only' => 0,
                'no_main_advance' => 0,
                'missing_judge' => 0,
                'missing_provider_receipt' => 0,
            ],
            $result['rejected'],
        );
        $this->assertSame(0, $result['rejected_total']);
    }

    public function testRejectedIncludesEveryEnumeratedReasonCountedSeparately(): void
    {
        $cycles = [
            // dry_run takes precedence and is the only counted reason here.
            $this->realCycle('green_slice', ['dry_run' => true]),
            $this->realCycle('pair_work', ['dry_run' => true]),
            // docs_only.
            $this->realCycle('green_slice', ['docs_only' => true]),
            // no_main_advance: same hash before/after.
            $this->realCycle('certified_feature', [
                'main_advanced' => null,
                'main_before' => 'abc',
                'main_after' => 'abc',
            ]),
            // missing_judge.
            $this->realCycle('pair_work', ['judge_present' => false]),
            // missing_provider_receipt.
            $this->realCycle('certified_obra', ['provider_receipt_present' => false]),
        ];

        $result = $this->accumulator->accumulate($cycles);

        $this->assertSame(2, $result['rejected']['dry_run']);
        $this->assertSame(1, $result['rejected']['docs_only']);
        $this->assertSame(1, $result['rejected']['no_main_advance']);
        $this->assertSame(1, $result['rejected']['missing_judge']);
        $this->assertSame(1, $result['rejected']['missing_provider_receipt']);
        $this->assertSame(6, $result['rejected_total']);

        // Rejected cycles never count toward a rung.
        $this->assertSame(
            ['L1' => 0, 'L2' => 0, 'L3' => 0, 'L4' => 0],
            $result['counted'],
        );
    }

    public function testRejectionReasonOrderingIsDeterministicWhenMultipleGatesFail(): void
    {
        // A single cycle failing several gates is attributed to the first gate
        // in the fixed order: dry_run -> docs_only -> no_main_advance ->
        // missing_judge -> missing_provider_receipt.
        $result = $this->accumulator->accumulate([
            $this->realCycle('green_slice', [
                'docs_only' => true,
                'main_advanced' => false,
                'judge_present' => false,
                'provider_receipt_present' => false,
            ]),
        ]);

        $this->assertSame(1, $result['rejected']['docs_only']);
        $this->assertSame(0, $result['rejected']['no_main_advance']);
        $this->assertSame(0, $result['rejected']['missing_judge']);
        $this->assertSame(0, $result['rejected']['missing_provider_receipt']);
        $this->assertSame(1, $result['rejected_total']);
    }

    public function testLevelOnePassesOnlyWithTwentyConsecutiveGreenSlices(): void
    {
        $cycles = [];
        for ($i = 0; $i < 20; $i++) {
            $cycles[] = $this->realCycle('green_slice');
        }

        $result = $this->accumulator->accumulate($cycles);

        $this->assertSame(20, $result['counted']['L1']);
        $this->assertSame(20, $result['consecutive_green']);
        $this->assertTrue($result['passed']['L1']);
        $this->assertSame('L1', $result['highest_passed_rung']);
    }

    public function testNineteenGreenSlicesDoNotPassLevelOne(): void
    {
        $cycles = [];
        for ($i = 0; $i < 19; $i++) {
            $cycles[] = $this->realCycle('green_slice');
        }

        $result = $this->accumulator->accumulate($cycles);

        $this->assertSame(19, $result['counted']['L1']);
        $this->assertSame(19, $result['consecutive_green']);
        $this->assertFalse($result['passed']['L1']);
        $this->assertNull($result['highest_passed_rung']);
    }

    public function testTwentyGreenSlicesBrokenByRejectionDoNotPassLevelOne(): void
    {
        // 10 green, one rejected (docs_only) slice in the middle, then 10 more.
        // Total real green = 20 but the longest consecutive run is only 10, so
        // the L1 exit criterion (20 *consecutive*) is not met.
        $cycles = [];
        for ($i = 0; $i < 10; $i++) {
            $cycles[] = $this->realCycle('green_slice');
        }
        $cycles[] = $this->realCycle('green_slice', ['docs_only' => true]);
        for ($i = 0; $i < 10; $i++) {
            $cycles[] = $this->realCycle('green_slice');
        }

        $result = $this->accumulator->accumulate($cycles);

        $this->assertSame(20, $result['counted']['L1']);
        $this->assertSame(1, $result['rejected']['docs_only']);
        $this->assertSame(10, $result['consecutive_green']);
        $this->assertFalse($result['passed']['L1']);
    }

    public function testInterleavedRealNonCohortWorkDoesNotBreakTheGreenStreak(): void
    {
        // The streak-break trigger is a *rejected* green slice, not merely a
        // different kind of work. 10 green slices, then one genuine (accepted)
        // non-L1 cohort cycle (a real pair_work), then 10 more green slices.
        // Per the class contract ("real work that does not map to an L1-L4
        // cohort ... does not break the L1 streak", and a non-green cohort is
        // not a rejected slice), the consecutive-green run bridges to 20 and L1
        // passes. This locks the documented semantics so a future refactor that
        // reset the counter on any non-green cycle would be caught here.
        $cycles = [];
        for ($i = 0; $i < 10; $i++) {
            $cycles[] = $this->realCycle('green_slice');
        }
        $cycles[] = $this->realCycle('pair_work');
        for ($i = 0; $i < 10; $i++) {
            $cycles[] = $this->realCycle('green_slice');
        }

        $result = $this->accumulator->accumulate($cycles);

        // The pair_work counted toward L2 and zero gates were rejected.
        $this->assertSame(20, $result['counted']['L1']);
        $this->assertSame(1, $result['counted']['L2']);
        $this->assertSame(0, $result['rejected_total']);
        // The interleaved real pair_work did not reset the streak: 20 in a row.
        $this->assertSame(20, $result['consecutive_green']);
        $this->assertTrue($result['passed']['L1']);
    }

    public function testLevelFourPassesOnlyWithFiveCertifiedObrasAndDualSignatureFive(): void
    {
        $cycles = [];
        for ($i = 0; $i < 5; $i++) {
            $cycles[] = $this->realCycle('certified_obra', [
                'operator_signature' => 'op-' . $i,
                'architect_signature' => 'arch-' . $i,
            ]);
        }

        $result = $this->accumulator->accumulate($cycles);

        $this->assertSame(5, $result['counted']['L4']);
        $this->assertSame(5, $result['dual_signature_count']);
        $this->assertTrue($result['passed']['L4']);
        $this->assertSame('L4', $result['highest_passed_rung']);
    }

    public function testMoreThanFiveFullyDualSignedCertifiedObrasStillPassLevelFour(): void
    {
        // The L4 gate is a threshold (>=5 certified Obras, >=5 dual signatures),
        // not an exact-count latch: extra qualifying evidence must never flip a
        // passed rung back to not-passed. Six fully dual-signed Obras pass.
        $cycles = [];
        for ($i = 0; $i < 6; $i++) {
            $cycles[] = $this->realCycle('certified_obra', [
                'operator_signature' => 'op-' . $i,
                'architect_signature' => 'arch-' . $i,
            ]);
        }

        $result = $this->accumulator->accumulate($cycles);

        $this->assertSame(6, $result['counted']['L4']);
        $this->assertSame(6, $result['dual_signature_count']);
        $this->assertTrue($result['passed']['L4']);
        $this->assertSame('L4', $result['highest_passed_rung']);
    }

    public function testFiveCertifiedObrasWithoutFullDualSignatureDoNotPassLevelFour(): void
    {
        $cycles = [];
        // Four Obras carry dual signature, one is single-signed.
        for ($i = 0; $i < 4; $i++) {
            $cycles[] = $this->realCycle('certified_obra', [
                'operator_signature' => 'op-' . $i,
                'architect_signature' => 'arch-' . $i,
            ]);
        }
        $cycles[] = $this->realCycle('certified_obra', [
            'operator_signature' => 'op-only',
            // architect_signature missing -> not dual.
        ]);

        $result = $this->accumulator->accumulate($cycles);

        $this->assertSame(5, $result['counted']['L4']);
        $this->assertSame(4, $result['dual_signature_count']);
        $this->assertFalse($result['passed']['L4']);
    }

    public function testCountsOnlyRealWorkWithNoArtificialCompression(): void
    {
        // 30 declared pair-work cycles, but only 18 are real work; the rest are
        // a mix of dry_run, docs_only and missing-receipt. The accumulator must
        // count exactly the 18 real ones and must not pass L2 (needs 30).
        $cycles = [];
        for ($i = 0; $i < 18; $i++) {
            $cycles[] = $this->realCycle('pair_work');
        }
        for ($i = 0; $i < 6; $i++) {
            $cycles[] = $this->realCycle('pair_work', ['dry_run' => true]);
        }
        for ($i = 0; $i < 4; $i++) {
            $cycles[] = $this->realCycle('pair_work', ['docs_only' => true]);
        }
        for ($i = 0; $i < 2; $i++) {
            $cycles[] = $this->realCycle('pair_work', ['provider_receipt_present' => false]);
        }

        $result = $this->accumulator->accumulate($cycles);

        $this->assertSame(18, $result['counted']['L2']);
        $this->assertSame(6, $result['rejected']['dry_run']);
        $this->assertSame(4, $result['rejected']['docs_only']);
        $this->assertSame(2, $result['rejected']['missing_provider_receipt']);
        $this->assertSame(12, $result['rejected_total']);
        $this->assertFalse($result['passed']['L2']);
    }

    public function testThirtyRealPairWorksPassLevelTwoAndFifteenFeaturesPassLevelThree(): void
    {
        $cycles = [];
        for ($i = 0; $i < 30; $i++) {
            $cycles[] = $this->realCycle('pair_work');
        }
        for ($i = 0; $i < 15; $i++) {
            $cycles[] = $this->realCycle('certified_feature');
        }

        $result = $this->accumulator->accumulate($cycles);

        $this->assertSame(30, $result['counted']['L2']);
        $this->assertSame(15, $result['counted']['L3']);
        $this->assertTrue($result['passed']['L2']);
        $this->assertTrue($result['passed']['L3']);
        $this->assertFalse($result['passed']['L1']);
        $this->assertFalse($result['passed']['L4']);
        // Highest contiguously is irrelevant: highest *passed* rung is L3 here.
        $this->assertSame('L3', $result['highest_passed_rung']);
    }

    public function testMainAdvanceFallsBackToHeadHashesWhenFlagIsNotBoolean(): void
    {
        // When main_advanced is not an explicit boolean (here null), the gate
        // must defer to the recorded main HEAD hashes. Differing hashes are a
        // real main advance and the cycle counts as real work; identical hashes
        // are no_main_advance. This proves the hash branch is genuinely live and
        // a real commit is never wrongly rejected just because the flag is null.
        $advanced = $this->accumulator->accumulate([
            $this->realCycle('pair_work', [
                'main_advanced' => null,
                'main_before' => 'sha-before',
                'main_after' => 'sha-after',
            ]),
        ]);

        $this->assertSame(1, $advanced['counted']['L2']);
        $this->assertSame(0, $advanced['rejected']['no_main_advance']);
        $this->assertSame(0, $advanced['rejected_total']);

        $notAdvanced = $this->accumulator->accumulate([
            $this->realCycle('pair_work', [
                'main_advanced' => null,
                'main_before' => 'same-sha',
                'main_after' => 'same-sha',
            ]),
        ]);

        $this->assertSame(0, $notAdvanced['counted']['L2']);
        $this->assertSame(1, $notAdvanced['rejected']['no_main_advance']);
    }

    public function testExplicitMainAdvancedFalseFlagIsAuthoritativeOverHashes(): void
    {
        // An explicit boolean flag wins even if hashes would suggest otherwise:
        // main_advanced=false rejects despite differing hashes.
        $result = $this->accumulator->accumulate([
            $this->realCycle('certified_feature', [
                'main_advanced' => false,
                'main_before' => 'x',
                'main_after' => 'y',
            ]),
        ]);

        $this->assertSame(0, $result['counted']['L3']);
        $this->assertSame(1, $result['rejected']['no_main_advance']);
    }

    public function testEmptyCyclesYieldZeroedCohortsAndNoPass(): void
    {
        $result = $this->accumulator->accumulate([]);

        $this->assertSame(
            ['L1' => 0, 'L2' => 0, 'L3' => 0, 'L4' => 0],
            $result['counted'],
        );
        $this->assertSame(0, $result['rejected_total']);
        $this->assertSame(0, $result['consecutive_green']);
        $this->assertSame(0, $result['dual_signature_count']);
        $this->assertSame(
            ['L1' => false, 'L2' => false, 'L3' => false, 'L4' => false],
            $result['passed'],
        );
        $this->assertNull($result['highest_passed_rung']);
    }

    public function testIdenticalInputIsDeterministic(): void
    {
        $cycles = [
            $this->realCycle('green_slice'),
            $this->realCycle('pair_work', ['dry_run' => true]),
            $this->realCycle('certified_obra', [
                'operator_signature' => 'op',
                'architect_signature' => 'arch',
            ]),
        ];

        $first = $this->accumulator->accumulate($cycles);
        $second = $this->accumulator->accumulate($cycles);

        $this->assertSame($first, $second);
    }
}
