<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\L9OperatorThroughputLiftScorer;
use PHPUnit\Framework\TestCase;

final class L9OperatorThroughputLiftScorerTest extends TestCase
{
    private L9OperatorThroughputLiftScorer $scorer;

    protected function setUp(): void
    {
        $this->scorer = new L9OperatorThroughputLiftScorer();
    }

    public function testScoreReturnsThroughputLiftOverrideDeltaOutOfBoundAndSampleSize(): void
    {
        // baseline rate = 10 validated / 10 period units = 1.0
        // current  rate = 18 validated / 12 period units = 1.5  => lift = 0.5
        // override rate baseline = 8/20 = 0.40 ; current = 4/20 = 0.20 => delta = -0.20
        $result = $this->scorer->score(
            [
                'validated_decisions' => 10,
                'period_units' => 10,
                'decision_count' => 20,
                'override_count' => 8,
                'out_of_bound_count' => 0,
            ],
            [
                'validated_decisions' => 18,
                'period_units' => 12,
                'decision_count' => 20,
                'override_count' => 4,
                'out_of_bound_count' => 0,
            ],
        );

        $this->assertSame('atlas.aaeos.l9.operator_throughput_lift.v1', $result['schema_version']);
        $this->assertSame(0.5, $result['throughput_lift']);
        $this->assertSame(-0.2, $result['override_rate_delta']);
        $this->assertSame(0, $result['out_of_bound_count']);
        $this->assertSame(18, $result['sample_size']);
        $this->assertSame('pass', $result['verdict']);
        $this->assertFalse($result['insufficient_evidence']);
        $this->assertTrue($result['passed']);
        $this->assertSame([], $result['blockers']);
    }

    public function testNonPositiveLiftBlocks(): void
    {
        // current rate 0.8 < baseline rate 1.0 => lift = -0.2 (<= 0) blocks.
        $result = $this->scorer->score(
            [
                'validated_decisions' => 10,
                'period_units' => 10,
                'decision_count' => 20,
                'override_count' => 5,
                'out_of_bound_count' => 0,
            ],
            [
                'validated_decisions' => 8,
                'period_units' => 10,
                'decision_count' => 20,
                'override_count' => 5,
                'out_of_bound_count' => 0,
            ],
        );

        $this->assertSame(-0.2, $result['throughput_lift']);
        $this->assertSame('block', $result['verdict']);
        $this->assertFalse($result['passed']);
        $this->assertFalse($result['insufficient_evidence']);
        $this->assertSame(['non_positive_throughput_lift'], $result['blockers']);
    }

    public function testOutOfBoundDecisionsBlockEvenWithPositiveLift(): void
    {
        // lift = (1.5 - 1.0) / 1.0 = 0.5 (positive) but 2 decisions breached the
        // proven boundary, so the score blocks.
        $result = $this->scorer->score(
            [
                'validated_decisions' => 10,
                'period_units' => 10,
                'decision_count' => 20,
                'override_count' => 6,
                'out_of_bound_count' => 0,
            ],
            [
                'validated_decisions' => 18,
                'period_units' => 12,
                'decision_count' => 20,
                'override_count' => 6,
                'out_of_bound_count' => 2,
            ],
        );

        $this->assertSame(0.5, $result['throughput_lift']);
        $this->assertSame(2, $result['out_of_bound_count']);
        $this->assertSame('block', $result['verdict']);
        $this->assertFalse($result['passed']);
        $this->assertSame(['out_of_bound_decisions_present'], $result['blockers']);
    }

    public function testLowSampleSizeReturnsInsufficientEvidence(): void
    {
        // Only 4 operator-validated decisions (< MIN_SAMPLE_SIZE = 5): the
        // measurement is not trusted, so the verdict is insufficient_evidence
        // and the lift/out-of-bound gates do not fire.
        $result = $this->scorer->score(
            [
                'validated_decisions' => 10,
                'period_units' => 10,
                'decision_count' => 20,
                'override_count' => 6,
                'out_of_bound_count' => 0,
            ],
            [
                'validated_decisions' => 4,
                'period_units' => 12,
                'decision_count' => 8,
                'override_count' => 1,
                'out_of_bound_count' => 3,
            ],
        );

        $this->assertSame(4, $result['sample_size']);
        $this->assertSame('insufficient_evidence', $result['verdict']);
        $this->assertTrue($result['insufficient_evidence']);
        $this->assertFalse($result['passed']);
        $this->assertSame([], $result['blockers']);
    }

    public function testBothGatesFireInOrderWhenLiftIsNonPositiveAndBoundsAreBreached(): void
    {
        // lift = (0.5 - 1.0) / 1.0 = -0.5 (<= 0) AND 1 out-of-bound decision,
        // with a sufficient sample of 6: both blockers are reported in order.
        $result = $this->scorer->score(
            [
                'validated_decisions' => 10,
                'period_units' => 10,
                'decision_count' => 20,
                'override_count' => 4,
                'out_of_bound_count' => 0,
            ],
            [
                'validated_decisions' => 6,
                'period_units' => 12,
                'decision_count' => 20,
                'override_count' => 10,
                'out_of_bound_count' => 1,
            ],
        );

        $this->assertSame(-0.5, $result['throughput_lift']);
        $this->assertSame(6, $result['sample_size']);
        $this->assertSame(1, $result['out_of_bound_count']);
        $this->assertSame('block', $result['verdict']);
        $this->assertSame(
            ['non_positive_throughput_lift', 'out_of_bound_decisions_present'],
            $result['blockers'],
        );
    }

    public function testHugeFloatCountsClampToIntMaxWithoutWarningAndStayInt(): void
    {
        // A float count at or beyond 2^63 is not representable as an int: a naive
        // (int) cast would emit a runtime warning and overflow to a platform-
        // dependent (even negative) value, breaking purity/determinism and the
        // out_of_bound_count >= 0 bound. Such a magnitude must clamp to PHP_INT_MAX
        // deterministically, keep the int type, and (being > 0) trip the boundary
        // gate. Any leaked PHP warning fails the test.
        set_error_handler(static function (int $errno, string $errstr): bool {
            throw new \RuntimeException('PHP warning leaked: '.$errstr);
        });

        try {
            $result = $this->scorer->score(
                [
                    'validated_decisions' => 10,
                    'period_units' => 10,
                    'decision_count' => 20,
                    'override_count' => 8,
                    'out_of_bound_count' => 0,
                ],
                [
                    'validated_decisions' => 18,
                    'period_units' => 12,
                    'decision_count' => 20,
                    'override_count' => 4,
                    'out_of_bound_count' => 1.0e30,
                ],
            );
        } finally {
            restore_error_handler();
        }

        $this->assertIsInt($result['out_of_bound_count']);
        $this->assertSame(PHP_INT_MAX, $result['out_of_bound_count']);
        $this->assertGreaterThanOrEqual(0, $result['out_of_bound_count']);
        $this->assertSame('block', $result['verdict']);
        $this->assertSame(['out_of_bound_decisions_present'], $result['blockers']);

        // Deterministic: a second identical call yields the identical structure.
        $again = $this->scorer->score(
            [
                'validated_decisions' => 10,
                'period_units' => 10,
                'decision_count' => 20,
                'override_count' => 8,
                'out_of_bound_count' => 0,
            ],
            [
                'validated_decisions' => 18,
                'period_units' => 12,
                'decision_count' => 20,
                'override_count' => 4,
                'out_of_bound_count' => 1.0e30,
            ],
        );
        $this->assertSame($result, $again);
    }

    public function testZeroBaselineRateYieldsFlatLiftThatBlocksAndIsDeterministic(): void
    {
        // baseline period 0 => baseline rate 0 => flat lift 0.0 (no divide-by-
        // zero), which is non-positive and therefore blocks. override delta is
        // computed from real rates: current 6/20 = 0.30, baseline 0/0 = 0.0.
        $baseline = [
            'validated_decisions' => 0,
            'period_units' => 0,
            'decision_count' => 0,
            'override_count' => 0,
            'out_of_bound_count' => 0,
        ];
        $current = [
            'validated_decisions' => 12,
            'period_units' => 6,
            'decision_count' => 20,
            'override_count' => 6,
            'out_of_bound_count' => 0,
        ];

        $first = $this->scorer->score($baseline, $current);
        $second = $this->scorer->score($baseline, $current);

        $this->assertSame(0.0, $first['throughput_lift']);
        $this->assertSame(0.3, $first['override_rate_delta']);
        $this->assertSame(12, $first['sample_size']);
        $this->assertSame('block', $first['verdict']);
        $this->assertSame(['non_positive_throughput_lift'], $first['blockers']);
        $this->assertSame($first, $second);
    }
}
