<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\Aael\Execution\InFlight\AtlasAaelInFlightDriftAuditor;
use Tests\TestCase;

final class AtlasAaelInFlightDriftAuditorTest extends TestCase
{
    public function test_all_hold_returns_zero_broken_invariants_without_score_fields(): void
    {
        $report = (new AtlasAaelInFlightDriftAuditor)->audit($this->stream([
            1 => true,
            2 => true,
            3 => true,
            4 => true,
            5 => true,
        ]));

        $this->assertSame(5, $report['steps_observed']);
        $this->assertSame([], $report['invariants_broken_set']);
        $this->assertNull($report['first_break_step_index']);
        $this->assertFalse($report['ever_broken']);
        $this->assertFalse($report['permanently_broken']);
        $this->assertFalse($report['transient_flap']);
        $this->assertNoScoreFields($report);
    }

    public function test_flap_marks_ever_broken_but_not_permanently_broken(): void
    {
        $report = (new AtlasAaelInFlightDriftAuditor)->audit($this->stream([
            1 => true,
            2 => false,
            3 => false,
            4 => true,
            5 => true,
        ]));

        $this->assertSame(5, $report['steps_observed']);
        $this->assertSame(['inv-ready'], $report['invariants_broken_set']);
        $this->assertSame(2, $report['first_break_step_index']);
        $this->assertTrue($report['ever_broken']);
        $this->assertFalse($report['permanently_broken']);
        $this->assertTrue($report['transient_flap']);
        $this->assertSame([], $report['permanently_broken_set']);
        $this->assertSame(['inv-ready'], $report['recovered_invariants']);
        $this->assertNoScoreFields($report);
    }

    public function test_permanent_break_stays_broken_at_last_step(): void
    {
        $report = (new AtlasAaelInFlightDriftAuditor)->audit($this->stream([
            1 => true,
            2 => true,
            3 => false,
            4 => false,
            5 => false,
        ]));

        $this->assertSame(5, $report['steps_observed']);
        $this->assertSame(['inv-ready'], $report['invariants_broken_set']);
        $this->assertSame(3, $report['first_break_step_index']);
        $this->assertTrue($report['ever_broken']);
        $this->assertTrue($report['permanently_broken']);
        $this->assertFalse($report['transient_flap']);
        $this->assertSame(['inv-ready'], $report['permanently_broken_set']);
        $this->assertSame([], $report['recovered_invariants']);
        $this->assertNoScoreFields($report);
    }

    /**
     * @param  array<int,bool>  $holdsByStep
     * @return list<array<string,mixed>>
     */
    private function stream(array $holdsByStep): array
    {
        $stream = [];
        foreach ($holdsByStep as $stepIndex => $holds) {
            $stream[] = [
                'facts' => [[
                    'invariant_id' => 'inv-ready',
                    'holds' => $holds,
                    'observed_value' => $holds,
                    'declared_value' => true,
                    'delta' => $holds ? null : ['declared' => true, 'observed' => false],
                    'step_index' => $stepIndex,
                    'reason' => $holds ? null : 'invariant_delta',
                ]],
            ];
        }

        return $stream;
    }

    /**
     * @param  array<string,mixed>  $report
     */
    private function assertNoScoreFields(array $report): void
    {
        $this->assertArrayNotHasKey('score', $report);
        $this->assertArrayNotHasKey('quality', $report);
        $this->assertArrayNotHasKey('grade', $report);
    }
}
