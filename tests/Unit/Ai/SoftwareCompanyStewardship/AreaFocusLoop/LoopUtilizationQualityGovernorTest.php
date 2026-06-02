<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\LoopUtilizationQualityGovernor;
use PHPUnit\Framework\TestCase;

final class LoopUtilizationQualityGovernorTest extends TestCase
{
    private LoopUtilizationQualityGovernor $governor;

    protected function setUp(): void
    {
        $this->governor = new LoopUtilizationQualityGovernor();
    }

    public function testEvaluateReturnsTheFourRequiredUtilizationFields(): void
    {
        $result = $this->governor->evaluate([
            'cycles' => [
                ['delivery_kind' => 'runtime_wiring', 'runtime_consumed' => true, 'provider_spend' => 4.0],
                ['delivery_kind' => 'class_plus_test', 'runtime_consumed' => false, 'provider_spend' => 6.0],
            ],
        ]);

        $this->assertSame('atlas.loop.utilization_quality_governor.v1', $result['schema_version']);
        $this->assertArrayHasKey('useful_cycle_rate', $result);
        $this->assertArrayHasKey('provider_spend_wasted_rate', $result);
        $this->assertArrayHasKey('inert_delivery_count', $result);
        $this->assertArrayHasKey('paused_for_low_utilization', $result);

        $this->assertEqualsWithDelta(0.5, $result['useful_cycle_rate'], 0.0000001);
        $this->assertEqualsWithDelta(0.6, $result['provider_spend_wasted_rate'], 0.0000001);
        $this->assertSame(1, $result['inert_delivery_count']);
        $this->assertTrue($result['paused_for_low_utilization']);
    }

    public function testClassPlusTestNotConsumedByRuntimeCountsAsInertDelivery(): void
    {
        $result = $this->governor->evaluate([
            'cycles' => [
                ['delivery_kind' => 'class_plus_test', 'runtime_consumed' => false, 'provider_spend' => 5.0],
                ['delivery_kind' => 'class_plus_test', 'runtime_consumed' => false, 'provider_spend' => 5.0],
                ['delivery_kind' => 'class_plus_test', 'runtime_consumed' => true, 'provider_spend' => 5.0],
            ],
        ]);

        // Two class+test deliveries are not consumed by runtime; the third one is wired.
        $this->assertSame(2, $result['inert_delivery_count']);
        $this->assertSame(1, $result['useful_cycles']);
    }

    public function testNonClassDeliveriesAreNeverCountedAsInert(): void
    {
        $result = $this->governor->evaluate([
            'cycles' => [
                ['delivery_kind' => 'runtime_wiring', 'runtime_consumed' => false, 'provider_spend' => 3.0],
                ['delivery_kind' => 'docs_only', 'runtime_consumed' => false, 'provider_spend' => 1.0],
            ],
        ]);

        // Inert delivery is specific to class+test slices; other unconsumed kinds are not inert deliveries.
        $this->assertSame(0, $result['inert_delivery_count']);
    }

    public function testProjectedUsefulRateBelowFloorBlocksTwentyFourHoursWithHonestBlocker(): void
    {
        $result = $this->governor->evaluate([
            'cycles' => [
                ['delivery_kind' => 'runtime_wiring', 'runtime_consumed' => true, 'provider_spend' => 2.0],
                ['delivery_kind' => 'class_plus_test', 'runtime_consumed' => false, 'provider_spend' => 8.0],
            ],
        ]);

        $this->assertEqualsWithDelta(0.5, $result['projected_useful_rate'], 0.0000001);
        $this->assertTrue($result['paused_for_low_utilization']);
        $this->assertSame(24, $result['pause_hours']);
        $this->assertSame('block', $result['gate_decision']);
        $this->assertFalse($result['ap_790_admitted']);
        $this->assertSame(['projected_useful_rate_below_floor'], $result['blockers']);
    }

    public function testNinetySixOfOneHundredUsefulCyclesPassesTheGate(): void
    {
        $result = $this->governor->evaluate([
            'cycles' => $this->cyclesWithUsefulCount(96, 100),
        ]);

        $this->assertSame(100, $result['total_cycles']);
        $this->assertSame(96, $result['useful_cycles']);
        $this->assertEqualsWithDelta(0.96, $result['projected_useful_rate'], 0.0000001);
        $this->assertEqualsWithDelta(0.96, $result['useful_cycle_rate'], 0.0000001);
        $this->assertFalse($result['paused_for_low_utilization']);
        $this->assertSame(0, $result['pause_hours']);
        $this->assertSame('pass', $result['gate_decision']);
        $this->assertTrue($result['ap_790_admitted']);
        $this->assertSame([], $result['blockers']);
    }

    public function testNinetyFiveOfOneHundredUsefulCyclesBlocksTheGate(): void
    {
        $result = $this->governor->evaluate([
            'cycles' => $this->cyclesWithUsefulCount(95, 100),
        ]);

        $this->assertSame(100, $result['total_cycles']);
        $this->assertSame(95, $result['useful_cycles']);
        $this->assertEqualsWithDelta(0.95, $result['projected_useful_rate'], 0.0000001);
        $this->assertTrue($result['paused_for_low_utilization']);
        $this->assertSame(24, $result['pause_hours']);
        $this->assertSame('block', $result['gate_decision']);
        $this->assertSame(['projected_useful_rate_below_floor'], $result['blockers']);
    }

    public function testOptimisticSelfReportCannotBypassTheHonestProjectedGate(): void
    {
        // Every cycle claims it was useful, but none were consumed by runtime.
        $cycles = [];
        for ($i = 0; $i < 10; $i++) {
            $cycles[] = [
                'delivery_kind' => 'class_plus_test',
                'runtime_consumed' => false,
                'reported_useful' => true,
                'provider_spend' => 1.0,
            ];
        }

        $result = $this->governor->evaluate(['cycles' => $cycles]);

        // Reported (optimistic) rate is perfect, but the honest projected rate is zero and blocks.
        $this->assertEqualsWithDelta(1.0, $result['useful_cycle_rate'], 0.0000001);
        $this->assertEqualsWithDelta(0.0, $result['projected_useful_rate'], 0.0000001);
        $this->assertSame(10, $result['inert_delivery_count']);
        $this->assertTrue($result['paused_for_low_utilization']);
        $this->assertSame('block', $result['gate_decision']);
    }

    public function testFullyUsefulRunWastesNoProviderSpendAndIsNotPaused(): void
    {
        $result = $this->governor->evaluate([
            'cycles' => [
                ['delivery_kind' => 'runtime_wiring', 'runtime_consumed' => true, 'provider_spend' => 7.0],
                ['delivery_kind' => 'runtime_wiring', 'runtime_consumed' => true, 'provider_spend' => 3.0],
            ],
        ]);

        $this->assertEqualsWithDelta(1.0, $result['useful_cycle_rate'], 0.0000001);
        $this->assertEqualsWithDelta(0.0, $result['provider_spend_wasted_rate'], 0.0000001);
        $this->assertSame(0, $result['inert_delivery_count']);
        $this->assertFalse($result['paused_for_low_utilization']);
        $this->assertTrue($result['ap_790_admitted']);
    }

    public function testRatesNeverExceedTheirUpperBoundOnExtremeInput(): void
    {
        $result = $this->governor->evaluate([
            'cycles' => [
                ['delivery_kind' => 'class_plus_test', 'runtime_consumed' => false, 'reported_useful' => true, 'provider_spend' => 1000000.0],
            ],
        ]);

        $this->assertGreaterThanOrEqual(0.0, $result['useful_cycle_rate']);
        $this->assertLessThanOrEqual(1.0, $result['useful_cycle_rate']);
        $this->assertGreaterThanOrEqual(0.0, $result['provider_spend_wasted_rate']);
        $this->assertLessThanOrEqual(1.0, $result['provider_spend_wasted_rate']);
        $this->assertGreaterThanOrEqual(0.0, $result['projected_useful_rate']);
        $this->assertLessThanOrEqual(1.0, $result['projected_useful_rate']);
    }

    public function testNonFiniteProviderSpendNeverPoisonsTheWastedRate(): void
    {
        // A non-finite spend (INF, NAN, or a numeric string such as "1e400" that
        // overflows to INF on cast) is malformed input. It must never produce a
        // NAN/INF wasted rate, which would break the 0..1 bound, break JSON
        // serialisation and silently fail-open on any downstream "< floor" check.
        foreach ([INF, -INF, NAN, '1e400', '-1e400'] as $poison) {
            $result = $this->governor->evaluate([
                'cycles' => [
                    ['delivery_kind' => 'class_plus_test', 'runtime_consumed' => false, 'provider_spend' => $poison],
                    ['delivery_kind' => 'runtime_wiring', 'runtime_consumed' => true, 'provider_spend' => 1.0],
                ],
            ]);

            foreach (['useful_cycle_rate', 'projected_useful_rate', 'provider_spend_wasted_rate'] as $rateKey) {
                $rate = $result[$rateKey];
                $this->assertIsFloat($rate, $rateKey.' must be a float for spend '.var_export($poison, true));
                $this->assertFalse(is_nan($rate), $rateKey.' must not be NAN for spend '.var_export($poison, true));
                $this->assertTrue(is_finite($rate), $rateKey.' must be finite for spend '.var_export($poison, true));
                $this->assertGreaterThanOrEqual(0.0, $rate, $rateKey.' must be >= 0.0');
                $this->assertLessThanOrEqual(1.0, $rate, $rateKey.' must be <= 1.0');
            }

            // The full receipt must remain JSON-serialisable (NAN/INF make json_encode fail).
            $this->assertIsString(json_encode($result, JSON_THROW_ON_ERROR));
        }
    }

    public function testEmptyRunDoesNotPauseAndAdmitsNothing(): void
    {
        $result = $this->governor->evaluate([]);

        $this->assertSame(0, $result['total_cycles']);
        $this->assertEqualsWithDelta(0.0, $result['useful_cycle_rate'], 0.0000001);
        $this->assertEqualsWithDelta(0.0, $result['projected_useful_rate'], 0.0000001);
        $this->assertEqualsWithDelta(0.0, $result['provider_spend_wasted_rate'], 0.0000001);
        $this->assertSame(0, $result['inert_delivery_count']);
        $this->assertFalse($result['paused_for_low_utilization']);
        $this->assertFalse($result['ap_790_admitted']);
        $this->assertSame([], $result['blockers']);
    }

    public function testIdenticalInputIsDeterministic(): void
    {
        $run = [
            'cycles' => [
                ['delivery_kind' => 'runtime_wiring', 'runtime_consumed' => true, 'provider_spend' => 2.0],
                ['delivery_kind' => 'class_plus_test', 'runtime_consumed' => false, 'provider_spend' => 2.0],
            ],
        ];

        $first = $this->governor->evaluate($run);
        $second = $this->governor->evaluate($run);

        $this->assertSame($first, $second);
    }

    /**
     * Build a run with exactly $useful runtime-consumed cycles out of $total.
     *
     * @return list<array<string, mixed>>
     */
    private function cyclesWithUsefulCount(int $useful, int $total): array
    {
        $cycles = [];
        for ($i = 0; $i < $total; $i++) {
            $consumed = $i < $useful;
            $cycles[] = [
                'delivery_kind' => $consumed ? 'runtime_wiring' : 'class_plus_test',
                'runtime_consumed' => $consumed,
                'provider_spend' => 1.0,
            ];
        }

        return $cycles;
    }
}
