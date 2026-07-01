<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Maestro\Fairness;

use App\Services\Ai\SelfConstruction\Maestro\Concurrency\AtlasMaestroWorkerFleetProbe;
use App\Services\Ai\SelfConstruction\Maestro\Fairness\AtlasMaestroFairnessGiniReporter;
use Tests\TestCase;

final class AtlasMaestroFairnessGiniReporterTest extends TestCase
{
    private function probe(array $rows): AtlasMaestroWorkerFleetProbe
    {
        $leases = [];
        foreach ($rows as $row) {
            $clientId = (string) $row['client_id'];
            $throughput = (int) ($row['lifetime_throughput'] ?? 0);
            for ($i = 0; $i < $throughput; $i++) {
                $leases[] = ['client_id' => $clientId, 'opened_at' => 1_000_000 + $i, 'released_at' => 1_000_000 + $i + 1];
            }
        }

        return new AtlasMaestroWorkerFleetProbe(fn () => $leases);
    }

    public function test_reporter_returns_gini_and_starvation_for_worker_lane_family_and_tier(): void
    {
        $probe = $this->probe([
            ['client_id' => 'a', 'lifetime_throughput' => 90],
            ['client_id' => 'b', 'lifetime_throughput' => 1],
        ]);
        $tasks = [];
        for ($i = 0; $i < 90; $i++) {
            $tasks[] = ['task_packet_id' => 'maestro-task-'.$i, 'outcome' => 'success', 'lane' => 'lane-a', 'risk_tier' => 'low'];
        }
        $tasks[] = ['task_packet_id' => 'loop-task-1', 'outcome' => 'success', 'lane' => 'lane-b', 'risk_tier' => 'high'];

        $report = (new AtlasMaestroFairnessGiniReporter($probe, fn () => $tasks))->report();

        foreach (['gini_workers', 'gini_lanes', 'gini_task_classes', 'gini_tiers'] as $key) {
            $this->assertArrayHasKey($key, $report, "missing {$key}");
        }
        foreach (['starved_workers', 'starved_lanes', 'starved_task_classes', 'starved_tiers'] as $key) {
            $this->assertArrayHasKey($key, $report, "missing {$key}");
        }
        $this->assertContains('lane-b', $report['starved_lanes']);
        $this->assertContains('high', $report['starved_tiers']);
    }

    public function test_balanced_lanes_and_tiers_produce_no_false_starvation(): void
    {
        $probe = $this->probe([['client_id' => 'a', 'lifetime_throughput' => 2]]);
        $tasks = [
            ['task_packet_id' => 'maestro-task-1', 'outcome' => 'success', 'lane' => 'lane-a', 'risk_tier' => 'low'],
            ['task_packet_id' => 'maestro-task-2', 'outcome' => 'success', 'lane' => 'lane-b', 'risk_tier' => 'high'],
        ];

        $report = (new AtlasMaestroFairnessGiniReporter($probe, fn () => $tasks))->report();

        $this->assertSame([], $report['starved_lanes']);
        $this->assertSame([], $report['starved_tiers']);
        $this->assertLessThanOrEqual(0.01, $report['gini_lanes']);
        $this->assertLessThanOrEqual(0.01, $report['gini_tiers']);
    }

    public function test_empty_input_returns_zero_gini_not_nan_for_all_dimensions(): void
    {
        $probe = $this->probe([]);

        $report = (new AtlasMaestroFairnessGiniReporter($probe, fn () => []))->report();

        $this->assertSame(0.0, $report['gini_workers']);
        $this->assertSame(0.0, $report['gini_lanes']);
        $this->assertSame(0.0, $report['gini_task_classes']);
        $this->assertSame(0.0, $report['gini_tiers']);
        $this->assertSame([], $report['starved_lanes']);
        $this->assertSame([], $report['starved_tiers']);
        $this->assertNull($report['max_lane_share_id']);
        $this->assertNull($report['max_tier_share_id']);
    }

    public function test_missing_lane_and_tier_dimension_facts_do_not_crash_or_false_starve(): void
    {
        $probe = $this->probe([['client_id' => 'a', 'lifetime_throughput' => 3]]);
        // no 'lane' or 'risk_tier' keys supplied at all — facts unavailable for those dimensions.
        $tasks = [
            ['task_packet_id' => 'maestro-task-1', 'outcome' => 'success'],
            ['task_packet_id' => 'maestro-task-2', 'outcome' => 'success'],
            ['task_packet_id' => 'maestro-task-3', 'outcome' => 'success'],
        ];

        $report = (new AtlasMaestroFairnessGiniReporter($probe, fn () => $tasks))->report();

        $this->assertSame(0.0, $report['gini_lanes']);
        $this->assertSame([], $report['lane_share_histogram']);
        $this->assertSame([], $report['starved_lanes']);
        $this->assertSame(0.0, $report['gini_tiers']);
        $this->assertSame([], $report['tier_share_histogram']);
        $this->assertSame([], $report['starved_tiers']);
    }

    public function test_single_lane_is_never_flagged_starved(): void
    {
        $probe = $this->probe([['client_id' => 'a', 'lifetime_throughput' => 1]]);
        $tasks = [['task_packet_id' => 'maestro-task-1', 'outcome' => 'success', 'lane' => 'only-lane']];

        $report = (new AtlasMaestroFairnessGiniReporter($probe, fn () => $tasks))->report();

        $this->assertSame([], $report['starved_lanes'], 'a single-lane distribution is 100% share, never starved');
    }
}
