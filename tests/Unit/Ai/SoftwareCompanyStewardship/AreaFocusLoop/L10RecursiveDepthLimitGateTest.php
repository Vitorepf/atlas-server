<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\L10RecursiveDepthLimitGate;
use PHPUnit\Framework\TestCase;

final class L10RecursiveDepthLimitGateTest extends TestCase
{
    private L10RecursiveDepthLimitGate $gate;

    protected function setUp(): void
    {
        $this->gate = new L10RecursiveDepthLimitGate();
    }

    public function testRequestWithinProvenBoundAndCoveredClassIsAllowed(): void
    {
        $result = $this->gate->decide(
            [
                'requested_depth' => 2,
                'recursion_class' => 'meta_compounding',
            ],
            [
                'verified' => true,
                'max_proven_depth' => 3,
                'covered_recursion_classes' => ['meta_compounding', 'method_distillation'],
            ],
        );

        $this->assertSame('atlas.aaeos.l10.recursive_depth_limit_gate.v1', $result['schema_version']);
        $this->assertTrue($result['allowed']);
        $this->assertSame(2, $result['requested_depth']);
        $this->assertSame(3, $result['max_allowed_depth']);
        $this->assertFalse($result['hard_stop']);
        $this->assertSame([], $result['blockers']);
    }

    public function testNoVerifiedProofSetsMaxAllowedDepthToZero(): void
    {
        $result = $this->gate->decide(
            [
                'requested_depth' => 1,
                'recursion_class' => 'meta_compounding',
            ],
            [
                'verified' => false,
                'max_proven_depth' => 5,
                'covered_recursion_classes' => ['meta_compounding'],
            ],
        );

        // Absent/unverified proof: the bound defaults to zero regardless of any
        // optimistic max_proven_depth the unverified envelope claims.
        $this->assertSame(0, $result['max_allowed_depth']);
        $this->assertFalse($result['allowed']);
        $this->assertContains('no_verified_proof', $result['blockers']);
    }

    public function testMissingProofPayloadDefaultsBoundToZeroAndBlocks(): void
    {
        $result = $this->gate->decide(
            [
                'requested_depth' => 1,
                'recursion_class' => 'meta_compounding',
            ],
            [],
        );

        $this->assertSame(0, $result['max_allowed_depth']);
        $this->assertFalse($result['allowed']);
        $this->assertContains('no_verified_proof', $result['blockers']);
        // A positive request against a zero bound must hard-stop the runaway.
        $this->assertTrue($result['hard_stop']);
    }

    public function testProofStatusNotVerifiedIsTreatedAsUnverified(): void
    {
        $result = $this->gate->decide(
            [
                'requested_depth' => 1,
                'recursion_class' => 'meta_compounding',
            ],
            [
                'verified' => true,
                'proof_status' => 'not_verified',
                'max_proven_depth' => 4,
                'covered_recursion_classes' => ['meta_compounding'],
            ],
        );

        $this->assertSame(0, $result['max_allowed_depth']);
        $this->assertFalse($result['allowed']);
        $this->assertContains('no_verified_proof', $result['blockers']);
    }

    public function testRequestedDepthAboveBoundBlocks(): void
    {
        $result = $this->gate->decide(
            [
                'requested_depth' => 4,
                'recursion_class' => 'meta_compounding',
            ],
            [
                'verified' => true,
                'max_proven_depth' => 3,
                'covered_recursion_classes' => ['meta_compounding'],
            ],
        );

        $this->assertFalse($result['allowed']);
        $this->assertSame(4, $result['requested_depth']);
        $this->assertSame(3, $result['max_allowed_depth']);
        $this->assertContains('requested_depth_above_bound', $result['blockers']);
        // Running beyond the proven bound triggers a hard stop.
        $this->assertTrue($result['hard_stop']);
    }

    public function testUnknownRecursionClassBlocks(): void
    {
        $result = $this->gate->decide(
            [
                'requested_depth' => 1,
                'recursion_class' => 'unproven_mutation',
            ],
            [
                'verified' => true,
                'max_proven_depth' => 3,
                'covered_recursion_classes' => ['meta_compounding', 'method_distillation'],
            ],
        );

        $this->assertFalse($result['allowed']);
        $this->assertContains('unknown_recursion_class', $result['blockers']);
        // The class is unknown, not the depth: depth is within bound, so no
        // hard stop on depth grounds and no depth blocker.
        $this->assertNotContains('requested_depth_above_bound', $result['blockers']);
        $this->assertFalse($result['hard_stop']);
    }

    public function testMissingRecursionClassIsUnknownAndBlocks(): void
    {
        $result = $this->gate->decide(
            [
                'requested_depth' => 1,
            ],
            [
                'verified' => true,
                'max_proven_depth' => 3,
                'covered_recursion_classes' => ['meta_compounding'],
            ],
        );

        $this->assertFalse($result['allowed']);
        $this->assertContains('unknown_recursion_class', $result['blockers']);
    }

    public function testVerifiedProofThatCoversNoClassBlocksEveryClass(): void
    {
        $result = $this->gate->decide(
            [
                'requested_depth' => 0,
                'recursion_class' => 'meta_compounding',
            ],
            [
                'verified' => true,
                'max_proven_depth' => 2,
                'covered_recursion_classes' => [],
            ],
        );

        // Proof is verified and depth 0 is within the bound, yet an empty
        // covered set means the class is unknown and the request still blocks.
        $this->assertFalse($result['allowed']);
        $this->assertContains('unknown_recursion_class', $result['blockers']);
        $this->assertNotContains('requested_depth_above_bound', $result['blockers']);
    }

    public function testRecursionRunsExactlyUpToProvenBoundNeverBeyond(): void
    {
        $proof = [
            'verified' => true,
            'max_proven_depth' => 5,
            'covered_recursion_classes' => ['meta_compounding'],
        ];

        // Exactly at the proven bound -> allowed.
        $atBound = $this->gate->decide(
            ['requested_depth' => 5, 'recursion_class' => 'meta_compounding'],
            $proof,
        );
        $this->assertTrue($atBound['allowed']);
        $this->assertSame(5, $atBound['max_allowed_depth']);
        $this->assertFalse($atBound['hard_stop']);
        $this->assertSame([], $atBound['blockers']);

        // One step beyond the proven bound -> blocked and hard-stopped.
        $beyondBound = $this->gate->decide(
            ['requested_depth' => 6, 'recursion_class' => 'meta_compounding'],
            $proof,
        );
        $this->assertFalse($beyondBound['allowed']);
        $this->assertTrue($beyondBound['hard_stop']);
        $this->assertContains('requested_depth_above_bound', $beyondBound['blockers']);
    }

    public function testDivergenceSignalBlocksAndHardStopsWithinBound(): void
    {
        $result = $this->gate->decide(
            [
                'requested_depth' => 1,
                'recursion_class' => 'meta_compounding',
            ],
            [
                'verified' => true,
                'max_proven_depth' => 3,
                'covered_recursion_classes' => ['meta_compounding'],
                'divergence_detected' => true,
            ],
        );

        // Within the proven bound and class covered, but a divergence signal
        // forces a hard stop (parada dura) and blocks.
        $this->assertFalse($result['allowed']);
        $this->assertTrue($result['hard_stop']);
        $this->assertContains('divergence_or_gaming_signal', $result['blockers']);
    }

    public function testGamingSignalBlocksAndHardStops(): void
    {
        $result = $this->gate->decide(
            [
                'requested_depth' => 2,
                'recursion_class' => 'method_distillation',
            ],
            [
                'verified' => true,
                'max_proven_depth' => 4,
                'covered_recursion_classes' => ['method_distillation'],
                'gaming_detected' => true,
            ],
        );

        $this->assertFalse($result['allowed']);
        $this->assertTrue($result['hard_stop']);
        $this->assertContains('divergence_or_gaming_signal', $result['blockers']);
    }

    public function testNegativeRequestedDepthClampsToZero(): void
    {
        $result = $this->gate->decide(
            [
                'requested_depth' => -3,
                'recursion_class' => 'meta_compounding',
            ],
            [
                'verified' => true,
                'max_proven_depth' => 2,
                'covered_recursion_classes' => ['meta_compounding'],
            ],
        );

        // A negative depth normalises to zero, stays within the bound, and is
        // allowed (no recursion requested, nothing to hard-stop).
        $this->assertSame(0, $result['requested_depth']);
        $this->assertTrue($result['allowed']);
        $this->assertFalse($result['hard_stop']);
    }

    public function testNegativeProvenDepthClampsBoundToZero(): void
    {
        $result = $this->gate->decide(
            [
                'requested_depth' => 1,
                'recursion_class' => 'meta_compounding',
            ],
            [
                'verified' => true,
                'max_proven_depth' => -5,
                'covered_recursion_classes' => ['meta_compounding'],
            ],
        );

        // A garbled negative bound cannot authorise recursion: clamps to zero,
        // so a depth-1 request is above the bound and blocks.
        $this->assertSame(0, $result['max_allowed_depth']);
        $this->assertFalse($result['allowed']);
        $this->assertContains('requested_depth_above_bound', $result['blockers']);
    }

    public function testNumericStringDepthsAreCoerced(): void
    {
        $result = $this->gate->decide(
            [
                'requested_depth' => '2',
                'recursion_class' => 'meta_compounding',
            ],
            [
                'verified' => true,
                'max_proven_depth' => '3',
                'covered_recursion_classes' => ['meta_compounding'],
            ],
        );

        $this->assertSame(2, $result['requested_depth']);
        $this->assertSame(3, $result['max_allowed_depth']);
        $this->assertTrue($result['allowed']);
    }

    public function testRunawayFloatRequestedDepthCannotWrapUnderTheBound(): void
    {
        // A runaway recursion request carrying a float depth at/beyond 2^63 must
        // not be cast naively: a raw (int) cast wraps to a platform-dependent,
        // often NEGATIVE value, which max(0,...) would flatten to ~0 and slip
        // UNDER the proven bound -> fail-open. It must saturate above the bound,
        // block, and hard-stop the runaway.
        $result = $this->gate->decide(
            [
                'requested_depth' => 1.0e19,
                'recursion_class' => 'meta_compounding',
            ],
            [
                'verified' => true,
                'max_proven_depth' => 3,
                'covered_recursion_classes' => ['meta_compounding'],
            ],
        );

        $this->assertGreaterThan($result['max_allowed_depth'], $result['requested_depth']);
        $this->assertFalse($result['allowed']);
        $this->assertTrue($result['hard_stop']);
        $this->assertContains('requested_depth_above_bound', $result['blockers']);
    }

    public function testNonFiniteRequestedAndProvenDepthFailClosed(): void
    {
        // INF/NAN carry no usable depth. requested_depth collapses to 0 (no
        // recursion asked); a non-finite proven bound collapses to 0 so it
        // cannot authorise recursion. A depth-1 request against a 0 bound blocks.
        $result = $this->gate->decide(
            [
                'requested_depth' => 1,
                'recursion_class' => 'meta_compounding',
            ],
            [
                'verified' => true,
                'max_proven_depth' => INF,
                'covered_recursion_classes' => ['meta_compounding'],
            ],
        );

        $this->assertSame(0, $result['max_allowed_depth']);
        $this->assertFalse($result['allowed']);
        $this->assertContains('requested_depth_above_bound', $result['blockers']);
        $this->assertTrue($result['hard_stop']);
    }

    public function testRecursionClassIsSluggedBeforeMatching(): void
    {
        $result = $this->gate->decide(
            [
                'requested_depth' => 1,
                'recursion_class' => 'Meta Compounding',
            ],
            [
                'verified' => true,
                'max_proven_depth' => 2,
                'covered_recursion_classes' => ['meta-compounding'],
            ],
        );

        // 'Meta Compounding' and 'meta-compounding' both slug to
        // 'meta_compounding', so the class is covered and the request allowed.
        $this->assertTrue($result['allowed']);
        $this->assertSame([], $result['blockers']);
    }

    public function testMultipleViolationsAccumulateInAcceptanceOrder(): void
    {
        $result = $this->gate->decide(
            [
                'requested_depth' => 9,
                'recursion_class' => 'unproven_mutation',
            ],
            [
                'verified' => false,
                'max_proven_depth' => 2,
                'covered_recursion_classes' => ['meta_compounding'],
                'gaming_detected' => true,
            ],
        );

        $this->assertFalse($result['allowed']);
        $this->assertTrue($result['hard_stop']);
        $this->assertSame(
            [
                'no_verified_proof',
                'requested_depth_above_bound',
                'unknown_recursion_class',
                'divergence_or_gaming_signal',
            ],
            $result['blockers'],
        );
    }

    public function testBlockersIsListOfStrings(): void
    {
        $result = $this->gate->decide(
            [
                'requested_depth' => 7,
                'recursion_class' => 'unproven_mutation',
            ],
            [
                'verified' => true,
                'max_proven_depth' => 1,
                'covered_recursion_classes' => ['meta_compounding'],
            ],
        );

        $this->assertSame(array_values($result['blockers']), $result['blockers']);
        foreach ($result['blockers'] as $index => $blocker) {
            $this->assertIsInt($index);
            $this->assertIsString($blocker);
        }
        $this->assertContains('requested_depth_above_bound', $result['blockers']);
        $this->assertContains('unknown_recursion_class', $result['blockers']);
    }

    public function testIdenticalInputIsDeterministic(): void
    {
        $request = [
            'requested_depth' => 3,
            'recursion_class' => 'meta_compounding',
        ];
        $proof = [
            'verified' => true,
            'max_proven_depth' => 3,
            'covered_recursion_classes' => ['meta_compounding'],
        ];

        $first = $this->gate->decide($request, $proof);
        $second = $this->gate->decide($request, $proof);

        $this->assertSame($first, $second);
    }
}
