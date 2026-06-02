<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\L7PromotionRequestBuilder;
use PHPUnit\Framework\TestCase;

final class L7PromotionRequestBuilderTest extends TestCase
{
    private L7PromotionRequestBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new L7PromotionRequestBuilder();
    }

    /**
     * @return array<string,mixed>
     */
    private function completeEvidence(): array
    {
        return [
            'approved_self_construction_proposals' => 10,
            'broken_invariant_count' => 0,
            'trust_ledger_score' => 0.97,
            'rollback_window_seconds' => 1800,
            'operator_signature' => 'op-sig-7f3a',
            'architect_signature' => 'arch-sig-91be',
        ];
    }

    public function testCompleteRequestValidatesWithFixedLevelsAndSchema(): void
    {
        $request = $this->builder->build($this->completeEvidence());

        $this->assertSame('atlas.autonomy.promotion_request.v1', $request['schema_version']);
        $this->assertSame('L6', $request['from_level']);
        $this->assertSame('L7', $request['to_level']);
        $this->assertTrue($request['valid']);
        $this->assertSame('ready_for_signature', $request['status']);
        $this->assertSame([], $request['blockers']);
    }

    public function testCompleteRequestEchoesEvidenceTrustScoreAndRollbackWindow(): void
    {
        $request = $this->builder->build($this->completeEvidence());

        $this->assertSame(
            [
                'approved_self_construction_proposals' => 10,
                'broken_invariant_count' => 0,
                'trust_ledger_score' => 0.97,
            ],
            $request['evidence'],
        );
        $this->assertSame(0.97, $request['trust_ledger_score']);
        $this->assertSame(1800, $request['rollback_window_seconds']);
    }

    public function testSignatureSlotsCarryProvidedSignatures(): void
    {
        $request = $this->builder->build($this->completeEvidence());

        $this->assertSame('op-sig-7f3a', $request['operator_signature']);
        $this->assertSame('arch-sig-91be', $request['architect_signature']);
    }

    public function testSignatureSlotsAreNullWhenUnsigned(): void
    {
        $evidence = $this->completeEvidence();
        unset($evidence['operator_signature'], $evidence['architect_signature']);

        $request = $this->builder->build($evidence);

        $this->assertNull($request['operator_signature']);
        $this->assertNull($request['architect_signature']);
        // Empty/whitespace strings are not real signatures: still an open slot.
        $request = $this->builder->build(['operator_signature' => '   '] + $evidence);
        $this->assertNull($request['operator_signature']);
    }

    public function testMissingTenProposalsBlocks(): void
    {
        $evidence = $this->completeEvidence();
        $evidence['approved_self_construction_proposals'] = 9;

        $request = $this->builder->build($evidence);

        $this->assertFalse($request['valid']);
        $this->assertSame('blocked', $request['status']);
        $this->assertContains('insufficient_self_construction_proposals', $request['blockers']);
    }

    public function testTenProposalsIsTheInclusiveBoundary(): void
    {
        $nine = $this->completeEvidence();
        $nine['approved_self_construction_proposals'] = 9;
        $ten = $this->completeEvidence();
        $ten['approved_self_construction_proposals'] = 10;

        $this->assertFalse($this->builder->build($nine)['valid']);
        $this->assertTrue($this->builder->build($ten)['valid']);
    }

    public function testTrustBelowThresholdBlocksAndExactThresholdPasses(): void
    {
        $below = $this->completeEvidence();
        $below['trust_ledger_score'] = 0.949;
        $belowRequest = $this->builder->build($below);

        $this->assertFalse($belowRequest['valid']);
        $this->assertSame('blocked', $belowRequest['status']);
        $this->assertContains('trust_ledger_below_threshold', $belowRequest['blockers']);

        $exact = $this->completeEvidence();
        $exact['trust_ledger_score'] = 0.95;
        $exactRequest = $this->builder->build($exact);

        $this->assertTrue($exactRequest['valid']);
        $this->assertSame(0.95, $exactRequest['trust_ledger_score']);
        $this->assertNotContains('trust_ledger_below_threshold', $exactRequest['blockers']);
    }

    public function testBrokenInvariantBlocksEvenWhenEverythingElsePasses(): void
    {
        $evidence = $this->completeEvidence();
        $evidence['broken_invariant_count'] = 1;

        $request = $this->builder->build($evidence);

        $this->assertFalse($request['valid']);
        $this->assertSame('blocked', $request['status']);
        $this->assertContains('invariant_breached', $request['blockers']);
    }

    public function testMissingRollbackWindowProvidedAsZeroBlocks(): void
    {
        $evidence = $this->completeEvidence();
        $evidence['rollback_window_seconds'] = 0;

        $request = $this->builder->build($evidence);

        $this->assertFalse($request['valid']);
        $this->assertSame(0, $request['rollback_window_seconds']);
        $this->assertContains('rollback_window_missing', $request['blockers']);
    }

    public function testAllThreeExitCriteriaMissingAccumulateOrderedBlockers(): void
    {
        $request = $this->builder->build([
            'approved_self_construction_proposals' => 3,
            'broken_invariant_count' => 2,
            'trust_ledger_score' => 0.40,
            'rollback_window_seconds' => 600,
        ]);

        $this->assertFalse($request['valid']);
        $this->assertSame(
            [
                'insufficient_self_construction_proposals',
                'trust_ledger_below_threshold',
                'invariant_breached',
            ],
            $request['blockers'],
        );
    }

    public function testBlockersIsAZeroIndexedListOfStrings(): void
    {
        $request = $this->builder->build([
            'approved_self_construction_proposals' => 0,
            'broken_invariant_count' => 5,
            'trust_ledger_score' => 0.10,
            'rollback_window_seconds' => 0,
        ]);

        $blockers = $request['blockers'];
        $this->assertSame(array_values($blockers), $blockers);
        $this->assertSame(range(0, count($blockers) - 1), array_keys($blockers));
        foreach ($blockers as $blocker) {
            $this->assertIsString($blocker);
        }
    }

    public function testNumericStringEvidenceIsCoercedAndStillGeneralises(): void
    {
        $request = $this->builder->build([
            'approved_self_construction_proposals' => '12',
            'broken_invariant_count' => '0',
            'trust_ledger_score' => '0.96',
            'rollback_window_seconds' => '3600',
        ]);

        $this->assertSame(12, $request['evidence']['approved_self_construction_proposals']);
        $this->assertSame(0, $request['evidence']['broken_invariant_count']);
        $this->assertSame(0.96, $request['trust_ledger_score']);
        $this->assertSame(3600, $request['rollback_window_seconds']);
        $this->assertTrue($request['valid']);
        $this->assertSame([], $request['blockers']);
    }

    public function testTrustScoreNeverFabricatedWhenAbsent(): void
    {
        $request = $this->builder->build([
            'approved_self_construction_proposals' => 11,
            'broken_invariant_count' => 0,
            'rollback_window_seconds' => 900,
        ]);

        $this->assertSame(0.0, $request['trust_ledger_score']);
        $this->assertFalse($request['valid']);
        $this->assertContains('trust_ledger_below_threshold', $request['blockers']);
    }

    public function testNonFiniteTrustScoreFailsClosed(): void
    {
        // NaN trust would slip past the `< threshold` gate (NaN comparisons are
        // all false) and mark the request valid with a NaN score. It must fail
        // closed: a finite 0.0 score and a trust blocker.
        $nan = $this->completeEvidence();
        $nan['trust_ledger_score'] = NAN;

        $nanRequest = $this->builder->build($nan);

        $this->assertFalse(is_nan($nanRequest['trust_ledger_score']));
        $this->assertSame(0.0, $nanRequest['trust_ledger_score']);
        $this->assertFalse($nanRequest['valid']);
        $this->assertContains('trust_ledger_below_threshold', $nanRequest['blockers']);

        // A numeric string that overflows to +INF must also fail closed.
        $inf = $this->completeEvidence();
        $inf['trust_ledger_score'] = '1e400';

        $infRequest = $this->builder->build($inf);

        $this->assertSame(0.0, $infRequest['trust_ledger_score']);
        $this->assertFalse($infRequest['valid']);
        $this->assertContains('trust_ledger_below_threshold', $infRequest['blockers']);
    }

    public function testHugeFloatBrokenInvariantCountFailsClosedWithoutWrap(): void
    {
        // A broken-invariant count arriving as a float beyond the representable
        // int range must NOT wrap to a negative int (which would slip past the
        // `> 0` breach gate and mark the request valid on a breach signal). It
        // saturates to a large positive count, raises the invariant blocker and
        // never echoes a negative, non-auditable count into the evidence record.
        $evidence = $this->completeEvidence();
        $evidence['broken_invariant_count'] = 9.9e18;

        $request = $this->builder->build($evidence);

        $this->assertFalse($request['valid']);
        $this->assertSame('blocked', $request['status']);
        $this->assertContains('invariant_breached', $request['blockers']);
        $this->assertGreaterThan(0, $request['evidence']['broken_invariant_count']);

        // A non-finite breach signal arriving as an overflowing numeric string
        // ('1e400' -> INF) must also fail closed, not be read as zero breaches.
        $infEvidence = $this->completeEvidence();
        $infEvidence['broken_invariant_count'] = '1e400';

        $infRequest = $this->builder->build($infEvidence);

        $this->assertFalse($infRequest['valid']);
        $this->assertContains('invariant_breached', $infRequest['blockers']);
        $this->assertGreaterThan(0, $infRequest['evidence']['broken_invariant_count']);
    }

    public function testDefaultRollbackWindowAppliedWhenKeyAbsent(): void
    {
        $evidence = $this->completeEvidence();
        unset($evidence['rollback_window_seconds']);

        $request = $this->builder->build($evidence);

        $this->assertSame(86_400, $request['rollback_window_seconds']);
        $this->assertNotContains('rollback_window_missing', $request['blockers']);
        $this->assertTrue($request['valid']);
    }

    public function testBuildIsDeterministicAndDoesNotMutateInput(): void
    {
        $evidence = $this->completeEvidence();
        $snapshot = $evidence;

        $first = $this->builder->build($evidence);
        $second = $this->builder->build($evidence);

        $this->assertSame($first, $second);
        $this->assertSame($snapshot, $evidence);
        // No level mutation: the request always asks L6 -> L7, never a different rung.
        $this->assertSame('L6', $first['from_level']);
        $this->assertSame('L7', $first['to_level']);
    }
}
