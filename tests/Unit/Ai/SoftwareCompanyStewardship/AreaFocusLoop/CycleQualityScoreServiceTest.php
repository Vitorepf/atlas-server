<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\CycleQualityScoreService;
use Tests\TestCase;

final class CycleQualityScoreServiceTest extends TestCase
{
    private function service(): CycleQualityScoreService
    {
        return app(CycleQualityScoreService::class);
    }

    /**
     * A real, high-value cycle: a merged packet that reduced a blocker, advanced
     * packet progression, has strong test evidence and compounds multiple axes.
     *
     * @return array<string,mixed>
     */
    private function highValueCycle(): array
    {
        return [
            'cycle' => [
                'run_id' => 'run-1',
                'cycle_index' => 3,
                'finding_id' => 'finding-abc',
                'packet_id' => 'packet-1',
                'blocked' => false,
                'merge_performed' => true,
                'sandbox_commit_only' => false,
                'forge_plan_only' => false,
                'is_filler' => false,
                'blocker_reduced' => 1.0,
                'packet_progression_advanced' => 1.0,
                'aaeos_relevance' => 0.9,
                'factory_evolution_relevance' => 0.8,
                'test_evidence_quality' => 1.0,
                'code_churn_files' => 3,
                'impact_on' => [
                    'context' => 0.8,
                    'memory' => 0.7,
                    'quality' => 0.9,
                    'agents' => 0.6,
                    'speed' => 0.5,
                    'robustness' => 0.8,
                ],
            ],
        ];
    }

    /**
     * A trivial valid merge: only a missing-test was added. The merge is valid but
     * it reduced no blocker and advanced no packet — the canonical "not a salto".
     *
     * @return array<string,mixed>
     */
    private function trivialValidMerge(): array
    {
        return [
            'cycle' => [
                'run_id' => 'run-1',
                'cycle_index' => 4,
                'finding_id' => 'finding-missing-test',
                'packet_id' => 'packet-trivial',
                'blocked' => false,
                'merge_performed' => true,
                'sandbox_commit_only' => false,
                'forge_plan_only' => false,
                'missing_test_filler' => true,
                'blocker_reduced' => 0.0,
                'packet_progression_advanced' => 0.0,
                'aaeos_relevance' => 0.0,
                'factory_evolution_relevance' => 0.0,
                'test_evidence_quality' => 0.3,
                'code_churn_files' => 1,
            ],
        ];
    }

    public function test_trivial_valid_merge_scores_low_and_is_not_a_leap_and_below_floor(): void
    {
        $report = $this->service()->score($this->trivialValidMerge());

        $this->assertSame(CycleQualityScoreService::STATUS_LOW, $report['status']);
        $this->assertSame('low', $report['band']);
        $this->assertFalse($report['counts_as_leap'], 'a missing-test-only valid merge must NOT count as a salto');
        $this->assertFalse($report['meets_quality_floor'], 'trivial merge must fall below the quality floor');
        $this->assertLessThan($report['floor'], $report['quality_score']);
        // It WAS a real merge (not blocked/sandbox/plan-only) — just low value.
        $this->assertTrue($report['real_productive_merge']);
        $this->assertContains('recovery_or_filler_is_not_productivity', $report['warnings']);
    }

    public function test_real_blocker_reducing_packet_progression_scores_high_and_meets_floor(): void
    {
        $report = $this->service()->score($this->highValueCycle());

        $this->assertSame(CycleQualityScoreService::STATUS_HIGH, $report['status']);
        $this->assertTrue($report['counts_as_leap'], 'blocker-reducing packet progression must count as a salto');
        $this->assertTrue($report['meets_quality_floor']);
        $this->assertGreaterThanOrEqual($report['floor'], $report['quality_score']);
        $this->assertGreaterThanOrEqual(0.66, $report['quality_score']);
        $this->assertSame([], $report['blockers']);
    }

    public function test_deterministic_report_hash_for_identical_input(): void
    {
        $first = $this->service()->score($this->highValueCycle());
        $second = $this->service()->score($this->highValueCycle());

        $this->assertSame($first['report_hash'], $second['report_hash']);
        // Volatile fields excluded from the hash, so the rest of the report matches too.
        unset($first['checked_at'], $first['report_hash'], $second['checked_at'], $second['report_hash']);
        $this->assertSame($first, $second);
    }

    public function test_distinct_inputs_produce_distinct_hashes(): void
    {
        $high = $this->service()->score($this->highValueCycle());
        $trivial = $this->service()->score($this->trivialValidMerge());

        $this->assertNotSame($high['report_hash'], $trivial['report_hash']);
    }

    public function test_blocked_cycle_is_never_a_leap_and_scores_zero(): void
    {
        $report = $this->service()->score([
            'cycle' => [
                'blocked' => true,
                'merge_performed' => false,
                // Even if signals claim value, a blocked cycle is not productivity.
                'blocker_reduced' => 1.0,
                'packet_progression_advanced' => 1.0,
                'test_evidence_quality' => 1.0,
            ],
        ]);

        $this->assertSame(CycleQualityScoreService::STATUS_LOW, $report['status']);
        $this->assertSame(0.0, $report['quality_score']);
        $this->assertFalse($report['counts_as_leap']);
        $this->assertFalse($report['meets_quality_floor']);
        $this->assertFalse($report['real_productive_merge']);
        $this->assertContains('blocked_cycle_is_not_productivity', $report['blockers']);
    }

    public function test_sandbox_commit_only_is_not_a_merge_and_scores_zero(): void
    {
        $report = $this->service()->score([
            'cycle' => [
                'blocked' => false,
                'merge_performed' => true,
                'sandbox_commit_only' => true,
                'blocker_reduced' => 1.0,
                'packet_progression_advanced' => 1.0,
                'test_evidence_quality' => 1.0,
            ],
        ]);

        $this->assertSame(0.0, $report['quality_score']);
        $this->assertFalse($report['counts_as_leap']);
        $this->assertFalse($report['real_productive_merge']);
        $this->assertContains('sandbox_commit_is_not_merge', $report['blockers']);
    }

    public function test_forge_plan_only_is_not_implementation_and_scores_zero(): void
    {
        $report = $this->service()->score([
            'cycle' => [
                'blocked' => false,
                'merge_performed' => true,
                'forge_plan_only' => true,
                'blocker_reduced' => 1.0,
                'packet_progression_advanced' => 1.0,
                'test_evidence_quality' => 1.0,
            ],
        ]);

        $this->assertSame(0.0, $report['quality_score']);
        $this->assertFalse($report['counts_as_leap']);
        $this->assertFalse($report['real_productive_merge']);
        $this->assertContains('forge_plan_only_is_not_implementation', $report['blockers']);
    }

    public function test_custom_floor_is_respected(): void
    {
        // The high-value cycle meets the default floor (0.5) but we raise the floor
        // above its score to prove the floor seam drives meets_quality_floor.
        $input = $this->highValueCycle();
        $input['floor'] = 0.99;

        $report = $this->service()->score($input);

        $this->assertSame(0.99, $report['floor']);
        $this->assertFalse(
            $report['meets_quality_floor'],
            'a floor above the achieved score must fail meets_quality_floor',
        );
    }

    public function test_empty_input_does_not_crash_and_reports_no_value(): void
    {
        $report = $this->service()->score();

        $this->assertSame(CycleQualityScoreService::REPORT_SCHEMA, $report['schema_version']);
        $this->assertSame('LHL-11', $report['slice_id']);
        $this->assertSame(CycleQualityScoreService::STATUS_LOW, $report['status']);
        $this->assertSame(0.0, $report['quality_score']);
        $this->assertFalse($report['counts_as_leap']);
        $this->assertFalse($report['real_productive_merge']);
        $this->assertArrayHasKey('report_hash', $report);
    }

    public function test_fixture_seam_is_accepted_like_cycle_seam(): void
    {
        // The CLI passes parsed --fixture-file JSON under `fixture`; it must score
        // identically to the same payload under `cycle` (minus volatile fields).
        $cycle = $this->highValueCycle()['cycle'];

        $viaCycle = $this->service()->score(['cycle' => $cycle]);
        $viaFixture = $this->service()->score(['fixture' => $cycle]);

        unset($viaCycle['checked_at'], $viaCycle['report_hash']);
        unset($viaFixture['checked_at'], $viaFixture['report_hash']);
        $this->assertSame($viaCycle, $viaFixture);
    }

    public function test_compounding_impact_is_mean_of_axes_and_present_in_report(): void
    {
        $report = $this->service()->score($this->highValueCycle());

        $axes = $report['impact_axes'];
        $expected = round(array_sum($axes) / count($axes), 4);
        $this->assertSame($expected, $report['compounding_impact']);
        $this->assertArrayHasKey('context', $axes);
        $this->assertArrayHasKey('robustness', $axes);
    }
}
