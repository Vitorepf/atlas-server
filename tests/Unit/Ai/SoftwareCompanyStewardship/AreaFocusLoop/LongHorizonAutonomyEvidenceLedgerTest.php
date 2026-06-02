<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\LongHorizonAutonomyEvidenceLedger;
use PHPUnit\Framework\TestCase;

final class LongHorizonAutonomyEvidenceLedgerTest extends TestCase
{
    private LongHorizonAutonomyEvidenceLedger $ledger;

    protected function setUp(): void
    {
        $this->ledger = new LongHorizonAutonomyEvidenceLedger();
    }

    public function testReportReturnsTheFourCanonicalEvidenceFields(): void
    {
        $report = $this->ledger->report([
            [
                'observed_from' => '2026-01-01',
                'days' => 40,
                'departments' => ['dev'],
                'interventions' => 1,
            ],
            [
                'observed_from' => '2026-02-10',
                'days' => 25,
                'departments' => ['dev', 'marketing'],
                'interventions' => 2,
            ],
        ]);

        $this->assertSame('atlas.autonomy.long_horizon_evidence_ledger.v1', $report['schema_version']);
        $this->assertSame(65, $report['wall_clock_days_observed']);
        $this->assertSame(2, $report['dept_count_active']);
        $this->assertSame(3, $report['intervention_count']);
        // Earliest anchor 2026-01-01 + remaining (90 - 65 = 25) days.
        $this->assertSame('2026-01-26', $report['earliest_possible_at']);
    }

    public function testDryRunAndTestModeEventsNeverIncrementDays(): void
    {
        $report = $this->ledger->report([
            [
                'observed_from' => '2026-01-01',
                'days' => 30,
                'departments' => ['dev'],
            ],
            [
                'days' => 500,
                'departments' => ['dev', 'finance', 'marketing'],
                'interventions' => 9,
                'dry_run' => true,
            ],
            [
                'days' => 400,
                'departments' => ['security', 'trading'],
                'interventions' => 4,
                'test_mode' => true,
            ],
        ]);

        // Only the single real event counts; dry-run + test_mode contribute zero.
        $this->assertSame(30, $report['wall_clock_days_observed']);
        $this->assertSame(1, $report['dept_count_active']);
        $this->assertSame(0, $report['intervention_count']);
        $this->assertSame(1, $report['real_event_count']);
        $this->assertSame(2, $report['excluded_event_count']);
    }

    public function testEightyNineDaysFailsL5(): void
    {
        $report = $this->ledger->report([
            [
                'observed_from' => '2026-01-01',
                'days' => 89,
                'departments' => ['dev'],
                'interventions' => 0,
            ],
        ]);

        $this->assertSame(89, $report['wall_clock_days_observed']);
        $this->assertFalse($report['l5_satisfied']);
        $this->assertContains('l5_wall_clock_days_below_threshold', $report['blockers']);
    }

    public function testNinetyDaysPassesL5OnlyWithZeroInterventions(): void
    {
        $report = $this->ledger->report([
            [
                'observed_from' => '2026-01-01',
                'days' => 90,
                'departments' => ['dev'],
                'interventions' => 0,
            ],
        ]);

        $this->assertSame(90, $report['wall_clock_days_observed']);
        $this->assertSame(0, $report['intervention_count']);
        $this->assertTrue($report['l5_satisfied']);
        // L5 carries none of its own blockers once the 90-day, zero-intervention bar is met.
        $this->assertNotContains('l5_wall_clock_days_below_threshold', $report['blockers']);
        $this->assertNotContains('l5_no_autonomous_department', $report['blockers']);
        $this->assertNotContains('l5_human_intervention_present', $report['blockers']);
    }

    public function testNinetyDaysWithAnInterventionFailsL5(): void
    {
        $report = $this->ledger->report([
            [
                'observed_from' => '2026-01-01',
                'days' => 90,
                'departments' => ['dev'],
                'interventions' => 1,
            ],
        ]);

        $this->assertSame(90, $report['wall_clock_days_observed']);
        $this->assertSame(1, $report['intervention_count']);
        $this->assertFalse($report['l5_satisfied']);
        $this->assertContains('l5_human_intervention_present', $report['blockers']);
    }

    public function testThirtyDaysWithThreeActiveDepartmentsSatisfiesL6(): void
    {
        $report = $this->ledger->report([
            [
                'observed_from' => '2026-03-01',
                'days' => 30,
                'departments' => ['dev', 'marketing', 'finance'],
                'interventions' => 0,
            ],
        ]);

        $this->assertSame(30, $report['wall_clock_days_observed']);
        $this->assertSame(3, $report['dept_count_active']);
        $this->assertTrue($report['l6_satisfied']);
        $this->assertNotContains('l6_insufficient_active_departments', $report['blockers']);
    }

    public function testThirtyDaysWithOnlyTwoActiveDepartmentsFailsL6(): void
    {
        $report = $this->ledger->report([
            [
                'observed_from' => '2026-03-01',
                'days' => 30,
                'departments' => ['dev', 'marketing'],
                'interventions' => 0,
            ],
        ]);

        $this->assertSame(2, $report['dept_count_active']);
        $this->assertFalse($report['l6_satisfied']);
        $this->assertContains('l6_insufficient_active_departments', $report['blockers']);
    }

    public function testEarliestPossibleAtIsAnchorPlusRemainingL5Days(): void
    {
        // 60 real days observed from 2026-01-10 -> 30 days remain to reach 90.
        $report = $this->ledger->report([
            [
                'observed_from' => '2026-01-10',
                'days' => 60,
                'departments' => ['dev'],
                'interventions' => 0,
            ],
        ]);

        $this->assertSame(60, $report['wall_clock_days_observed']);
        $this->assertSame('2026-02-09', $report['earliest_possible_at']);
    }

    public function testEarliestPossibleAtCollapsesToAnchorWhenHorizonMet(): void
    {
        // 95 days already exceeds the 90-day horizon -> no remaining days added.
        $report = $this->ledger->report([
            [
                'observed_from' => '2026-04-01',
                'days' => 95,
                'departments' => ['dev'],
                'interventions' => 0,
            ],
        ]);

        $this->assertSame('2026-04-01', $report['earliest_possible_at']);
    }

    public function testEarliestPossibleAtUsesTheEarliestAnchorAcrossEvents(): void
    {
        $report = $this->ledger->report([
            [
                'observed_from' => '2026-05-20',
                'days' => 10,
                'departments' => ['dev'],
                'interventions' => 0,
            ],
            [
                'observed_from' => '2026-01-05',
                'days' => 20,
                'departments' => ['dev'],
                'interventions' => 0,
            ],
        ]);

        // 30 real days observed, 60 remain to reach 90, from earliest anchor 2026-01-05.
        $this->assertSame(30, $report['wall_clock_days_observed']);
        $this->assertSame('2026-03-06', $report['earliest_possible_at']);
    }

    public function testEarliestPossibleAtIsNullWhenNoRealAnchorExists(): void
    {
        $report = $this->ledger->report([
            [
                'days' => 200,
                'departments' => ['dev', 'finance', 'marketing'],
                'dry_run' => true,
            ],
        ]);

        $this->assertSame(0, $report['wall_clock_days_observed']);
        $this->assertNull($report['earliest_possible_at']);
    }

    public function testBooleanInterventionFlagCountsAsOne(): void
    {
        $report = $this->ledger->report([
            [
                'observed_from' => '2026-01-01',
                'days' => 90,
                'departments' => ['dev'],
                'intervention' => true,
            ],
        ]);

        $this->assertSame(1, $report['intervention_count']);
        $this->assertFalse($report['l5_satisfied']);
    }

    public function testRulesGeneraliseToUntestedLargeInputs(): void
    {
        // Inputs not keyed to any threshold boundary: 130 days, 4 distinct
        // departments, 3 of them active in a single observation, zero interventions.
        $report = $this->ledger->report([
            [
                'observed_from' => '2025-12-01',
                'days' => 70,
                'departments' => ['dev', 'finance'],
                'interventions' => 0,
            ],
            [
                'observed_from' => '2026-02-15',
                'days' => 60,
                'departments' => ['dev', 'marketing', 'security'],
                'interventions' => 0,
            ],
        ]);

        $this->assertSame(130, $report['wall_clock_days_observed']);
        $this->assertSame(4, $report['dept_count_active']);
        $this->assertSame(0, $report['intervention_count']);
        $this->assertTrue($report['l5_satisfied']);
        $this->assertTrue($report['l6_satisfied']);
        // 130 >= 90 horizon -> earliest collapses to earliest anchor.
        $this->assertSame('2025-12-01', $report['earliest_possible_at']);
    }

    public function testDepartmentsAreDeduplicatedAcrossEvents(): void
    {
        $report = $this->ledger->report([
            [
                'observed_from' => '2026-01-01',
                'days' => 50,
                'departments' => ['dev', 'dev', 'marketing'],
                'interventions' => 0,
            ],
            [
                'observed_from' => '2026-02-20',
                'days' => 50,
                'departments' => ['marketing', 'finance'],
                'interventions' => 0,
            ],
        ]);

        $this->assertSame(3, $report['dept_count_active']);
        $this->assertSame(100, $report['wall_clock_days_observed']);
    }

    public function testNegativeAndMalformedDayValuesAreFlooredToZero(): void
    {
        $report = $this->ledger->report([
            [
                'observed_from' => '2026-01-01',
                'days' => -40,
                'departments' => ['dev'],
                'interventions' => 0,
            ],
            [
                'observed_from' => '2026-03-01',
                'days' => 90,
                'departments' => ['dev'],
                'interventions' => 0,
            ],
        ]);

        $this->assertSame(90, $report['wall_clock_days_observed']);
        $this->assertTrue($report['l5_satisfied']);
    }

    public function testNegativeOutOfRangeFloatDaysDoNotWrapPastTheZeroFloor(): void
    {
        // A bare (int) cast of a hugely-negative float wraps to a large POSITIVE
        // int, sneaking past max(0, ...) and manufacturing a fake autonomous run.
        // Clamp-before-cast must keep negative days floored to zero.
        $report = $this->ledger->report([
            [
                'observed_from' => '2026-01-01',
                'days' => -1.0e19,
                'departments' => ['dev'],
                'interventions' => 0,
            ],
        ]);

        $this->assertIsInt($report['wall_clock_days_observed']);
        $this->assertSame(0, $report['wall_clock_days_observed']);
        $this->assertFalse($report['l5_satisfied']);
        $this->assertContains('l5_wall_clock_days_below_threshold', $report['blockers']);
    }

    public function testHugeFloatDaysSaturateInsteadOfWrappingToASmallValue(): void
    {
        // A float beyond PHP_INT_MAX must saturate to PHP_INT_MAX, not wrap to a
        // smaller (or negative) int. The day counter stays an honest, monotone int.
        $report = $this->ledger->report([
            [
                'observed_from' => '2026-01-01',
                'days' => 1.0e30,
                'departments' => ['dev'],
                'interventions' => 0,
            ],
        ]);

        $this->assertIsInt($report['wall_clock_days_observed']);
        $this->assertSame(PHP_INT_MAX, $report['wall_clock_days_observed']);
        $this->assertTrue($report['l5_satisfied']);
    }

    public function testHugeFloatInterventionCountDoesNotWrapToZeroAndStillVetoesL5(): void
    {
        // The fail-open hazard: a huge POSITIVE float intervention count wraps to
        // 0 under a bare (int) cast, hiding real human interventions and letting
        // a 90-day run pass L5. Clamp-before-cast keeps the veto intact.
        $report = $this->ledger->report([
            [
                'observed_from' => '2026-01-01',
                'days' => 90,
                'departments' => ['dev'],
                'interventions' => 1.0e19,
            ],
        ]);

        $this->assertIsInt($report['intervention_count']);
        $this->assertGreaterThan(0, $report['intervention_count']);
        $this->assertFalse($report['l5_satisfied']);
        $this->assertContains('l5_human_intervention_present', $report['blockers']);
    }

    public function testFloatDayAndInterventionInputsDoNotEmitCastWarnings(): void
    {
        // Float days/interventions are a natural caller shape (duration / count).
        // The kernel is pure logic and must not raise an E_WARNING float->int
        // "not representable" notice while coercing them.
        $caught = [];
        set_error_handler(static function (int $errno, string $errstr) use (&$caught): bool {
            $caught[] = $errstr;

            return true;
        });

        try {
            $this->ledger->report([
                [
                    'observed_from' => '2026-01-01',
                    'days' => 1.0e30,
                    'departments' => ['dev'],
                    'interventions' => 1.0e19,
                ],
            ]);
        } finally {
            restore_error_handler();
        }

        $this->assertSame([], $caught);
    }

    public function testEmptyEventStreamYieldsZeroedHonestReport(): void
    {
        $report = $this->ledger->report([]);

        $this->assertSame(0, $report['wall_clock_days_observed']);
        $this->assertSame(0, $report['dept_count_active']);
        $this->assertSame(0, $report['intervention_count']);
        $this->assertNull($report['earliest_possible_at']);
        $this->assertFalse($report['l5_satisfied']);
        $this->assertFalse($report['l6_satisfied']);
    }

    public function testDaySumAcrossIntMaxStaysIntAndDoesNotOverflowToFloat(): void
    {
        // Two real events whose day counts cross PHP_INT_MAX must not promote the
        // accumulator to a float: that would break the int output contract and
        // throw a TypeError inside the int-typed blockers() helper. The counter
        // saturates at PHP_INT_MAX and stays an honest int.
        $report = $this->ledger->report([
            [
                'observed_from' => '2026-01-01',
                'days' => PHP_INT_MAX,
                'departments' => ['dev'],
                'interventions' => 0,
            ],
            [
                'observed_from' => '2026-01-01',
                'days' => 10,
                'departments' => ['dev'],
                'interventions' => 0,
            ],
        ]);

        $this->assertIsInt($report['wall_clock_days_observed']);
        $this->assertSame(PHP_INT_MAX, $report['wall_clock_days_observed']);
        $this->assertTrue($report['l5_satisfied']);
    }

    public function testInterventionSumAcrossIntMaxStaysIntAndStillBlocksL5(): void
    {
        // The intervention accumulator shares the same overflow hazard. It must
        // remain a typed int and any positive total still vetoes L5.
        $report = $this->ledger->report([
            [
                'observed_from' => '2026-01-01',
                'days' => 90,
                'departments' => ['dev'],
                'interventions' => PHP_INT_MAX,
            ],
            [
                'observed_from' => '2026-01-01',
                'days' => 1,
                'departments' => ['dev'],
                'interventions' => 5,
            ],
        ]);

        $this->assertIsInt($report['intervention_count']);
        $this->assertSame(PHP_INT_MAX, $report['intervention_count']);
        $this->assertFalse($report['l5_satisfied']);
        $this->assertContains('l5_human_intervention_present', $report['blockers']);
    }

    public function testIdenticalInputIsDeterministic(): void
    {
        $events = [
            [
                'observed_from' => '2026-01-01',
                'days' => 90,
                'departments' => ['dev', 'finance', 'marketing'],
                'interventions' => 0,
            ],
        ];

        $this->assertSame(
            $this->ledger->report($events),
            $this->ledger->report($events),
        );
    }
}
