<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\L9Q1OperatorJudgmentAmplificationCertificationService;
use PHPUnit\Framework\TestCase;

final class L9Q1OperatorJudgmentAmplificationCertificationServiceTest extends TestCase
{
    private L9Q1OperatorJudgmentAmplificationCertificationService $service;

    protected function setUp(): void
    {
        $this->service = new L9Q1OperatorJudgmentAmplificationCertificationService();
    }

    /**
     * A throughput-lift measurement that satisfies all three Q1 facts: positive lift,
     * override rate improved (fell by 0.15), and zero out-of-bound decisions.
     *
     * @return array<string,mixed>
     */
    private function passingThroughput(): array
    {
        return [
            'throughput_lift' => 4.0,
            'override_rate_delta' => -0.15,
            'out_of_bound_count' => 0,
        ];
    }

    public function testCertifiesWhenLiftPositiveOverrideImprovesAndZeroOutOfBound(): void
    {
        $result = $this->service->certify([
            'q2' => true,
            'throughput' => $this->passingThroughput(),
        ]);

        $this->assertSame(
            'atlas.aaeos.l9.q1_operator_judgment_amplification_certification.v1',
            $result['schema_version'],
        );
        $this->assertSame('L9-Q1', $result['phase']);

        // q1_certified is a real computed bool, true here, with no blockers.
        $this->assertIsBool($result['q1_certified']);
        $this->assertTrue($result['q1_certified']);
        $this->assertSame('q1_certified', $result['status']);
        $this->assertSame([], $result['blockers']);

        // Every returned metric is surfaced from the evidence.
        $this->assertTrue($result['q2_certified']);
        $this->assertEqualsWithDelta(4.0, $result['throughput_lift'], 0.0001);
        $this->assertEqualsWithDelta(-0.15, $result['override_rate_delta'], 0.0001);
        $this->assertTrue($result['override_rate_improved']);
        $this->assertSame(0, $result['out_of_bound_count']);
    }

    public function testQ2NotCertifiedBlocks(): void
    {
        // Throughput evidence is otherwise perfect, but Q2 is not certified: Q1 has no
        // proven safety boundary to stand on.
        $result = $this->service->certify([
            'q2' => false,
            'throughput' => $this->passingThroughput(),
        ]);

        $this->assertFalse($result['q1_certified']);
        $this->assertSame('blocked_not_q1', $result['status']);
        $this->assertFalse($result['q2_certified']);
        $this->assertContains('q2_not_certified', $result['blockers']);
    }

    public function testMissingQ2EvidenceIsTreatedAsNotCertifiedAndBlocks(): void
    {
        // No q2 key at all -> fail-closed -> q2_certified=false -> blocks.
        $result = $this->service->certify([
            'throughput' => $this->passingThroughput(),
        ]);

        $this->assertFalse($result['q2_certified']);
        $this->assertFalse($result['q1_certified']);
        $this->assertContains('q2_not_certified', $result['blockers']);
    }

    public function testOutOfBoundCountAboveZeroBlocks(): void
    {
        // A single decision beyond the proven Q2 bounds breaks the "zero decisao fora do
        // escopo/risco delegado" criterion.
        $result = $this->service->certify([
            'q2' => true,
            'throughput' => [
                'throughput_lift' => 4.0,
                'override_rate_delta' => -0.15,
                'out_of_bound_count' => 1,
            ],
        ]);

        $this->assertFalse($result['q1_certified']);
        $this->assertSame('blocked_not_q1', $result['status']);
        $this->assertSame(1, $result['out_of_bound_count']);
        $this->assertContains('out_of_bound_decisions_present', $result['blockers']);
    }

    public function testOverrideRateUpBlocks(): void
    {
        // Override rate ROSE (delta > 0): the operator is correcting the system more, not
        // less, so judgment was not amplified.
        $result = $this->service->certify([
            'q2' => true,
            'throughput' => [
                'throughput_lift' => 4.0,
                'override_rate_delta' => 0.12,
                'out_of_bound_count' => 0,
            ],
        ]);

        $this->assertFalse($result['q1_certified']);
        $this->assertSame('blocked_not_q1', $result['status']);
        $this->assertEqualsWithDelta(0.12, $result['override_rate_delta'], 0.0001);
        $this->assertFalse($result['override_rate_improved']);
        $this->assertContains('override_rate_up', $result['blockers']);
    }

    public function testNonPositiveThroughputLiftBlocks(): void
    {
        // Zero lift is no amplification: the Item requires a positive throughput lift.
        $result = $this->service->certify([
            'q2' => true,
            'throughput' => [
                'throughput_lift' => 0.0,
                'override_rate_delta' => -0.15,
                'out_of_bound_count' => 0,
            ],
        ]);

        $this->assertFalse($result['q1_certified']);
        $this->assertEqualsWithDelta(0.0, $result['throughput_lift'], 0.0001);
        $this->assertContains('throughput_lift_not_positive', $result['blockers']);
    }

    public function testNegativeThroughputLiftBlocks(): void
    {
        $result = $this->service->certify([
            'q2' => true,
            'throughput' => [
                'throughput_lift' => -2.5,
                'override_rate_delta' => -0.15,
                'out_of_bound_count' => 0,
            ],
        ]);

        $this->assertFalse($result['q1_certified']);
        $this->assertEqualsWithDelta(-2.5, $result['throughput_lift'], 0.0001);
        $this->assertLessThan(0.0, $result['throughput_lift']);
        $this->assertContains('throughput_lift_not_positive', $result['blockers']);
    }

    public function testThroughputLiftIsComputedFromCurrentMinusBaselineWhenNoExplicitLift(): void
    {
        // No explicit throughput_lift -> computed as current - baseline = 17 - 10 = 7.
        $result = $this->service->certify([
            'q2' => true,
            'throughput' => [
                'current_throughput' => 17.0,
                'baseline_throughput' => 10.0,
                'override_rate_delta' => -0.2,
                'out_of_bound_count' => 0,
            ],
        ]);

        $this->assertEqualsWithDelta(7.0, $result['throughput_lift'], 0.0001);
        $this->assertTrue($result['q1_certified']);
    }

    public function testOverrideRateDeltaIsComputedFromCurrentMinusBaselineWhenNoExplicitDelta(): void
    {
        // No explicit override_rate_delta -> computed as 0.20 - 0.50 = -0.30 (improved).
        $result = $this->service->certify([
            'q2' => true,
            'throughput' => [
                'throughput_lift' => 3.0,
                'current_override_rate' => 0.20,
                'baseline_override_rate' => 0.50,
                'out_of_bound_count' => 0,
            ],
        ]);

        $this->assertEqualsWithDelta(-0.30, $result['override_rate_delta'], 0.0001);
        $this->assertTrue($result['override_rate_improved']);
        $this->assertTrue($result['q1_certified']);
    }

    public function testOverrideRateDeltaStaysWithinSignedUnitBoundWhenRatesAreMalformed(): void
    {
        // Malformed rates (above 1) clamp to 0..1 each, so the delta cannot leave -1..1:
        // current clamps 3.0 -> 1.0, baseline clamps -0.5 -> 0.0, delta = 1.0 (an
        // override that rose -> blocks, but the bound holds).
        $result = $this->service->certify([
            'q2' => true,
            'throughput' => [
                'throughput_lift' => 3.0,
                'current_override_rate' => 3.0,
                'baseline_override_rate' => -0.5,
                'out_of_bound_count' => 0,
            ],
        ]);

        $this->assertLessThanOrEqual(1.0, $result['override_rate_delta']);
        $this->assertGreaterThanOrEqual(-1.0, $result['override_rate_delta']);
        $this->assertEqualsWithDelta(1.0, $result['override_rate_delta'], 0.0001);
        $this->assertContains('override_rate_up', $result['blockers']);
    }

    public function testExplicitOverrideRateDeltaIsClampedToSignedUnitBound(): void
    {
        // An out-of-range explicit delta is clamped, never echoed past the bound.
        $result = $this->service->certify([
            'q2' => true,
            'throughput' => [
                'throughput_lift' => 3.0,
                'override_rate_delta' => -4.0,
                'out_of_bound_count' => 0,
            ],
        ]);

        $this->assertEqualsWithDelta(-1.0, $result['override_rate_delta'], 0.0001);
        $this->assertGreaterThanOrEqual(-1.0, $result['override_rate_delta']);
        $this->assertTrue($result['override_rate_improved']);
    }

    public function testNegativeOutOfBoundCountFloorsAtZeroAndDoesNotBlock(): void
    {
        // A malformed negative count is floored to 0 and must not trip the bound blocker.
        $result = $this->service->certify([
            'q2' => true,
            'throughput' => [
                'throughput_lift' => 3.0,
                'override_rate_delta' => -0.1,
                'out_of_bound_count' => -5,
            ],
        ]);

        $this->assertSame(0, $result['out_of_bound_count']);
        $this->assertNotContains('out_of_bound_decisions_present', $result['blockers']);
        $this->assertTrue($result['q1_certified']);
    }

    public function testQ2AcceptsArrayCertifiedFlag(): void
    {
        $result = $this->service->certify([
            'q2' => ['q2_certified' => true],
            'throughput' => $this->passingThroughput(),
        ]);

        $this->assertTrue($result['q2_certified']);
        $this->assertTrue($result['q1_certified']);
    }

    public function testTopLevelMetricsAreUsedWhenNoThroughputSubArray(): void
    {
        // The three metrics may be supplied at the top level alongside q2.
        $result = $this->service->certify([
            'q2_certified' => true,
            'throughput_lift' => 5.0,
            'override_rate_delta' => -0.05,
            'out_of_bound_count' => 0,
        ]);

        $this->assertTrue($result['q2_certified']);
        $this->assertEqualsWithDelta(5.0, $result['throughput_lift'], 0.0001);
        $this->assertEqualsWithDelta(-0.05, $result['override_rate_delta'], 0.0001);
        $this->assertSame(0, $result['out_of_bound_count']);
        $this->assertTrue($result['q1_certified']);
    }

    public function testAllFourBlockersFireInCanonicalOrderWhenEverythingFails(): void
    {
        // Q2 uncertified, non-positive lift, out-of-bound decisions present, and a rising
        // override rate: all four blockers present in canonical safety-first order.
        $result = $this->service->certify([
            'q2' => false,
            'throughput' => [
                'throughput_lift' => -1.0,
                'override_rate_delta' => 0.2,
                'out_of_bound_count' => 3,
            ],
        ]);

        $this->assertFalse($result['q1_certified']);
        $this->assertSame(
            [
                'q2_not_certified',
                'throughput_lift_not_positive',
                'out_of_bound_decisions_present',
                'override_rate_up',
            ],
            $result['blockers'],
        );
    }

    public function testOutOfBoundCountIsAlwaysAnIntNeverCoercedFromFloatKey(): void
    {
        // A float count is truncated to a non-negative int (contract honours int type).
        $result = $this->service->certify([
            'q2' => true,
            'throughput' => [
                'throughput_lift' => 3.0,
                'override_rate_delta' => -0.1,
                'out_of_bound_count' => 2.9,
            ],
        ]);

        $this->assertIsInt($result['out_of_bound_count']);
        $this->assertSame(2, $result['out_of_bound_count']);
        $this->assertContains('out_of_bound_decisions_present', $result['blockers']);
    }

    public function testHugeFloatOutOfBoundCountClampsToIntMaxWithoutWarningAndBlocks(): void
    {
        // A float out_of_bound_count at or beyond 2^63 is not representable as an int:
        // a naive (int) cast would emit a runtime warning and overflow to a platform-
        // dependent (even negative) value, breaking purity/determinism and the int >= 0
        // contract. Such a magnitude is unambiguously > 0, so it must clamp to a large
        // non-negative int and trip the bound blocker. Any leaked PHP warning fails.
        set_error_handler(static function (int $errno, string $errstr): bool {
            throw new \RuntimeException('PHP warning leaked: '.$errstr);
        });

        try {
            $result = $this->service->certify([
                'q2' => true,
                'throughput' => [
                    'throughput_lift' => 3.0,
                    'override_rate_delta' => -0.1,
                    'out_of_bound_count' => 1.0e30,
                ],
            ]);
        } finally {
            restore_error_handler();
        }

        $this->assertIsInt($result['out_of_bound_count']);
        $this->assertSame(PHP_INT_MAX, $result['out_of_bound_count']);
        $this->assertGreaterThanOrEqual(0, $result['out_of_bound_count']);
        $this->assertFalse($result['q1_certified']);
        $this->assertSame('blocked_not_q1', $result['status']);
        $this->assertContains('out_of_bound_decisions_present', $result['blockers']);
    }

    public function testZeroOverrideRateDeltaCountsAsImprovedAndDoesNotBlock(): void
    {
        // Override held flat (delta exactly 0): not a rise, so it does not block.
        $result = $this->service->certify([
            'q2' => true,
            'throughput' => [
                'throughput_lift' => 3.0,
                'override_rate_delta' => 0.0,
                'out_of_bound_count' => 0,
            ],
        ]);

        $this->assertTrue($result['override_rate_improved']);
        $this->assertNotContains('override_rate_up', $result['blockers']);
        $this->assertTrue($result['q1_certified']);
    }

    public function testIdenticalInputIsDeterministic(): void
    {
        $inputs = [
            'q2' => true,
            'throughput' => $this->passingThroughput(),
        ];

        $first = $this->service->certify($inputs);
        $second = $this->service->certify($inputs);

        $this->assertSame($first, $second);
    }
}
