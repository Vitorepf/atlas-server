<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Compounding;

use App\Services\Ai\SelfConstruction\Compounding\AtlasSelfConstructionCompoundingVelocityTracker;
use Tests\TestCase;

final class AtlasSelfConstructionCompoundingVelocityTrackerTest extends TestCase
{
    public function test_improving_sequence_labels_every_dimension_improving(): void
    {
        $verdict = (new AtlasSelfConstructionCompoundingVelocityTracker)->track([
            ['cycle_id' => 'c-1', 'passed_count' => 1, 'blockers_count' => 5, 'rework_count' => 4, 'evidence_completeness' => 0.3],
            ['cycle_id' => 'c-2', 'passed_count' => 4, 'blockers_count' => 3, 'rework_count' => 2, 'evidence_completeness' => 0.6],
            ['cycle_id' => 'c-3', 'passed_count' => 7, 'blockers_count' => 1, 'rework_count' => 0, 'evidence_completeness' => 0.9],
        ]);

        $rows = $verdict['rows'];
        $this->assertCount(3, $rows);
        for ($i = 1; $i < 3; $i++) {
            $this->assertSame(AtlasSelfConstructionCompoundingVelocityTracker::TREND_IMPROVING, $rows[$i]['throughput_trend']);
            $this->assertSame(AtlasSelfConstructionCompoundingVelocityTracker::TREND_IMPROVING, $rows[$i]['blocker_trend']);
            $this->assertSame(AtlasSelfConstructionCompoundingVelocityTracker::TREND_IMPROVING, $rows[$i]['rework_trend']);
            $this->assertSame(AtlasSelfConstructionCompoundingVelocityTracker::TREND_IMPROVING, $rows[$i]['evidence_trend']);
        }
    }

    public function test_flat_sequence_labels_every_dimension_flat(): void
    {
        $verdict = (new AtlasSelfConstructionCompoundingVelocityTracker)->track([
            ['cycle_id' => 'c-1', 'passed_count' => 3, 'blockers_count' => 1, 'rework_count' => 1, 'evidence_completeness' => 0.5],
            ['cycle_id' => 'c-2', 'passed_count' => 3, 'blockers_count' => 1, 'rework_count' => 1, 'evidence_completeness' => 0.5],
        ]);

        $row = $verdict['rows'][1];
        $this->assertSame(AtlasSelfConstructionCompoundingVelocityTracker::TREND_FLAT, $row['throughput_trend']);
        $this->assertSame(AtlasSelfConstructionCompoundingVelocityTracker::TREND_FLAT, $row['blocker_trend']);
        $this->assertSame(AtlasSelfConstructionCompoundingVelocityTracker::TREND_FLAT, $row['rework_trend']);
        $this->assertSame(AtlasSelfConstructionCompoundingVelocityTracker::TREND_FLAT, $row['evidence_trend']);
    }

    public function test_regressing_sequence_labels_every_dimension_regressing(): void
    {
        $verdict = (new AtlasSelfConstructionCompoundingVelocityTracker)->track([
            ['cycle_id' => 'c-1', 'passed_count' => 10, 'blockers_count' => 0, 'rework_count' => 0, 'evidence_completeness' => 0.9],
            ['cycle_id' => 'c-2', 'passed_count' => 5, 'blockers_count' => 3, 'rework_count' => 4, 'evidence_completeness' => 0.4],
        ]);

        $row = $verdict['rows'][1];
        $this->assertSame(AtlasSelfConstructionCompoundingVelocityTracker::TREND_REGRESSING, $row['throughput_trend']);
        $this->assertSame(AtlasSelfConstructionCompoundingVelocityTracker::TREND_REGRESSING, $row['blocker_trend']);
        $this->assertSame(AtlasSelfConstructionCompoundingVelocityTracker::TREND_REGRESSING, $row['rework_trend']);
        $this->assertSame(AtlasSelfConstructionCompoundingVelocityTracker::TREND_REGRESSING, $row['evidence_trend']);
    }

    public function test_first_row_is_flat_baseline(): void
    {
        $verdict = (new AtlasSelfConstructionCompoundingVelocityTracker)->track([
            ['cycle_id' => 'c-1', 'passed_count' => 5, 'blockers_count' => 2, 'rework_count' => 1, 'evidence_completeness' => 0.5],
        ]);

        $row = $verdict['rows'][0];
        $this->assertSame(AtlasSelfConstructionCompoundingVelocityTracker::TREND_FLAT, $row['throughput_trend']);
        $this->assertSame(0, $row['throughput_delta']);
        $this->assertSame(0.0, $row['evidence_completeness_delta']);
    }

    public function test_output_order_is_input_order(): void
    {
        $verdict = (new AtlasSelfConstructionCompoundingVelocityTracker)->track([
            ['cycle_id' => 'z'],
            ['cycle_id' => 'm'],
            ['cycle_id' => 'a'],
        ]);

        $this->assertSame(['z', 'm', 'a'], array_column($verdict['rows'], 'cycle_id'), 'tracker preserves input order (NOT alphabetical)');
    }

    public function test_verdict_carries_no_composite_velocity_score(): void
    {
        $verdict = (new AtlasSelfConstructionCompoundingVelocityTracker)->track([['cycle_id' => 'c-1']]);
        foreach (array_keys($verdict) as $key) {
            $this->assertStringNotContainsString('velocity_score', strtolower((string) $key));
            $this->assertStringNotContainsString('vanity', strtolower((string) $key));
        }
        foreach ($verdict['rows'] as $row) {
            foreach (array_keys($row) as $key) {
                $this->assertStringNotContainsString('vanity', strtolower((string) $key));
                $this->assertStringNotContainsString('total_score', strtolower((string) $key));
            }
        }
    }
}
