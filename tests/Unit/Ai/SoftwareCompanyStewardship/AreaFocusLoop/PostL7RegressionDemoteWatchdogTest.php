<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\PostL7RegressionDemoteWatchdog;
use PHPUnit\Framework\TestCase;

final class PostL7RegressionDemoteWatchdogTest extends TestCase
{
    private PostL7RegressionDemoteWatchdog $watchdog;

    protected function setUp(): void
    {
        $this->watchdog = new PostL7RegressionDemoteWatchdog();
    }

    public function testEvaluateReturnsRegressionWindowTriggeredRulesAndDemoteReceiptRef(): void
    {
        $result = $this->watchdog->evaluate([
            'current_level' => 7,
            'cycles' => [
                ['useful_cycle_rate' => 0.92],
                ['trust_ledger_score' => 0.80],
            ],
        ]);

        $this->assertSame('atlas.loop.post_l7_regression_demote_watchdog.v1', $result['schema_version']);

        $this->assertArrayHasKey('regression_window', $result);
        $this->assertSame(2, $result['regression_window']['observed_cycles']);
        $this->assertSame(2, $result['regression_window']['bad_cycle_count']);
        $this->assertSame(2, $result['regression_window']['max_consecutive_bad']);
        $this->assertTrue($result['regression_window']['evidence_sufficient']);

        $this->assertArrayHasKey('triggered_rules', $result);
        $this->assertSame(
            ['quality_utilization_below_96', 'trust_drop'],
            $result['triggered_rules'],
        );

        $this->assertArrayHasKey('demote_receipt_ref', $result);
        $this->assertSame(
            'atlas.loop.post_l7_regression_demote_watchdog.v1:demote:L7->L6',
            $result['demote_receipt_ref'],
        );
    }

    public function testTwoConsecutiveBadCyclesSetDemoteRequiredTrue(): void
    {
        $result = $this->watchdog->evaluate([
            'current_level' => 7,
            'cycles' => [
                ['invariant_breach_count' => 1],
                ['provider_honesty_ok' => false],
            ],
        ]);

        $this->assertTrue($result['demote_required']);
        $this->assertSame('demote_required', $result['status']);
        $this->assertSame(2, $result['regression_window']['max_consecutive_bad']);
    }

    public function testInsufficientEvidenceReturnsUnknownBlockedNotPass(): void
    {
        $result = $this->watchdog->evaluate([
            'current_level' => 7,
            'cycles' => [
                ['trust_ledger_score' => 0.40],
            ],
        ]);

        $this->assertSame('unknown_blocked', $result['status']);
        $this->assertNotSame('pass', $result['status']);
        $this->assertFalse($result['demote_required']);
        $this->assertNull($result['demote_receipt_ref']);
        $this->assertFalse($result['regression_window']['evidence_sufficient']);
        $this->assertSame(7, $result['target_level']);
    }

    public function testEmptyWindowIsInsufficientEvidence(): void
    {
        $result = $this->watchdog->evaluate([
            'current_level' => 7,
            'cycles' => [],
        ]);

        $this->assertSame('unknown_blocked', $result['status']);
        $this->assertFalse($result['demote_required']);
        $this->assertSame(0, $result['regression_window']['observed_cycles']);
        $this->assertSame([], $result['triggered_rules']);
    }

    public function testL7WithTwoQualityFailuresDemotesToL6(): void
    {
        $result = $this->watchdog->evaluate([
            'current_level' => 7,
            'cycles' => [
                ['useful_cycle_rate' => 0.95],
                ['useful_cycle_rate' => 0.90],
            ],
        ]);

        $this->assertTrue($result['demote_required']);
        $this->assertSame('demote_required', $result['status']);
        $this->assertSame(7, $result['current_level']);
        $this->assertSame(6, $result['target_level']);
        $this->assertSame(['quality_utilization_below_96'], $result['triggered_rules']);
        $this->assertSame(
            'atlas.loop.post_l7_regression_demote_watchdog.v1:demote:L7->L6',
            $result['demote_receipt_ref'],
        );
    }

    public function testQualityUtilizationPercentageBelow96Trips(): void
    {
        $result = $this->watchdog->evaluate([
            'current_level' => 7,
            'cycles' => [
                ['quality_utilization' => 95],
                ['quality_utilization' => 80],
            ],
        ]);

        $this->assertTrue($result['demote_required']);
        $this->assertSame(['quality_utilization_below_96'], $result['triggered_rules']);
    }

    public function testQualityUtilizationAtFloorIsHealthy(): void
    {
        $result = $this->watchdog->evaluate([
            'current_level' => 7,
            'cycles' => [
                ['useful_cycle_rate' => 0.96],
                ['quality_utilization' => 96],
            ],
        ]);

        $this->assertSame('pass', $result['status']);
        $this->assertFalse($result['demote_required']);
        $this->assertSame([], $result['triggered_rules']);
    }

    public function testTrustAtFloorIsHealthyButBelowFloorTrips(): void
    {
        $healthy = $this->watchdog->evaluate([
            'current_level' => 7,
            'cycles' => [
                ['trust_ledger_score' => 0.95],
                ['trust_ledger_score' => 0.99],
            ],
        ]);
        $this->assertSame('pass', $healthy['status']);
        $this->assertFalse($healthy['demote_required']);

        $regressed = $this->watchdog->evaluate([
            'current_level' => 7,
            'cycles' => [
                ['trust_ledger_score' => 0.9499],
                ['trust_ledger_score' => 0.9499],
            ],
        ]);
        $this->assertTrue($regressed['demote_required']);
        $this->assertSame(['trust_drop'], $regressed['triggered_rules']);
    }

    public function testRegressionFlagAndRegressionCountBothTrip(): void
    {
        $byFlag = $this->watchdog->evaluate([
            'current_level' => 7,
            'cycles' => [
                ['regression' => true],
                ['regression_count' => 3],
            ],
        ]);

        $this->assertTrue($byFlag['demote_required']);
        $this->assertSame(['post_l7_regression'], $byFlag['triggered_rules']);
    }

    public function testNonConsecutiveBadCyclesDoNotDemote(): void
    {
        $result = $this->watchdog->evaluate([
            'current_level' => 7,
            'cycles' => [
                ['trust_ledger_score' => 0.50],
                ['trust_ledger_score' => 0.99],
                ['invariant_breach_count' => 1],
            ],
        ]);

        $this->assertSame('pass', $result['status']);
        $this->assertFalse($result['demote_required']);
        $this->assertSame(2, $result['regression_window']['bad_cycle_count']);
        $this->assertSame(1, $result['regression_window']['max_consecutive_bad']);
        $this->assertNull($result['demote_receipt_ref']);
        $this->assertSame(7, $result['target_level']);
    }

    public function testAllFiveRulesTripWithinOneCycleAndAreOrderedUniqueStrings(): void
    {
        $result = $this->watchdog->evaluate([
            'current_level' => 7,
            'cycles' => [
                [
                    'regression' => true,
                    'trust_ledger_score' => 0.10,
                    'invariant_breach_count' => 2,
                    'useful_cycle_rate' => 0.10,
                    'provider_honesty_ok' => false,
                ],
                [
                    'regression_count' => 1,
                    'trust_ledger_score' => 0.20,
                    'invariant_breach_count' => 5,
                    'useful_cycle_rate' => 0.20,
                    'provider_honesty_ok' => false,
                ],
            ],
        ]);

        $this->assertTrue($result['demote_required']);
        $this->assertSame(
            [
                'post_l7_regression',
                'trust_drop',
                'invariant_breach',
                'quality_utilization_below_96',
                'provider_honesty_failure',
            ],
            $result['triggered_rules'],
        );

        // list<string> contract: sequential int keys, every value a string.
        $this->assertSame(
            array_keys($result['triggered_rules']),
            range(0, count($result['triggered_rules']) - 1),
        );
        foreach ($result['triggered_rules'] as $rule) {
            $this->assertIsString($rule);
        }
    }

    public function testHealthyL7WindowPassesWithNoTriggeredRules(): void
    {
        $result = $this->watchdog->evaluate([
            'current_level' => 7,
            'cycles' => [
                [
                    'regression' => false,
                    'trust_ledger_score' => 0.97,
                    'invariant_breach_count' => 0,
                    'useful_cycle_rate' => 0.98,
                    'provider_honesty_ok' => true,
                ],
                [
                    'trust_ledger_score' => 0.99,
                    'useful_cycle_rate' => 0.99,
                    'provider_honesty_ok' => true,
                ],
            ],
        ]);

        $this->assertSame('pass', $result['status']);
        $this->assertFalse($result['demote_required']);
        $this->assertSame([], $result['triggered_rules']);
        $this->assertSame(7, $result['current_level']);
        $this->assertSame(7, $result['target_level']);
        $this->assertNull($result['demote_receipt_ref']);
        $this->assertSame(0, $result['regression_window']['bad_cycle_count']);
    }

    public function testDemoteReceiptRefTracksArbitraryCurrentLevel(): void
    {
        $result = $this->watchdog->evaluate([
            'current_level' => 9,
            'cycles' => [
                ['useful_cycle_rate' => 0.10],
                ['useful_cycle_rate' => 0.10],
            ],
        ]);

        $this->assertTrue($result['demote_required']);
        $this->assertSame(9, $result['current_level']);
        $this->assertSame(8, $result['target_level']);
        $this->assertSame(
            'atlas.loop.post_l7_regression_demote_watchdog.v1:demote:L9->L8',
            $result['demote_receipt_ref'],
        );
    }

    public function testDefaultsToL7WhenCurrentLevelOmitted(): void
    {
        $result = $this->watchdog->evaluate([
            'cycles' => [
                ['invariant_breach_count' => 1],
                ['invariant_breach_count' => 1],
            ],
        ]);

        $this->assertSame(7, $result['current_level']);
        $this->assertSame(6, $result['target_level']);
        $this->assertTrue($result['demote_required']);
    }

    public function testEvaluationIsDeterministic(): void
    {
        $window = [
            'current_level' => 7,
            'cycles' => [
                ['useful_cycle_rate' => 0.91],
                ['trust_ledger_score' => 0.40],
            ],
        ];

        $first = $this->watchdog->evaluate($window);
        $second = $this->watchdog->evaluate($window);

        $this->assertSame($first, $second);
    }
}
