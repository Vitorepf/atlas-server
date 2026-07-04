<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\Maestro\Concurrency\AtlasMaestroWorkerFleetProbe;
use App\Services\Ai\SelfConstruction\Maestro\Fairness\AtlasMaestroFairnessGiniReporter;
use Tests\TestCase;

final class AtlasMaestroFairnessGiniReporterTest extends TestCase
{
    private function probe(array $rows): AtlasMaestroWorkerFleetProbe
    {
        // Translate the desired probe-output rows into the lease shape the real probe consumes,
        // so each row's lifetime_throughput is produced by counting equivalent (opened_at, released_at)
        // pairs. AtlasMaestroWorkerFleetProbe is `final` — we build a real instance with synthetic leases.
        $leases = [];
        foreach ($rows as $row) {
            $clientId = (string) $row['client_id'];
            $throughput = (int) ($row['lifetime_throughput'] ?? 0);
            for ($i = 0; $i < $throughput; $i++) {
                $leases[] = [
                    'client_id' => $clientId,
                    'opened_at' => 1_000_000 + $i,
                    'released_at' => 1_000_000 + $i + 1,
                ];
            }
        }

        return new AtlasMaestroWorkerFleetProbe(fn () => $leases);
    }

    public function test_skewed_workers_balanced_task_classes(): void
    {
        $probe = $this->probe([
            ['client_id' => 'a', 'last_seen_at' => 0, 'in_flight_count' => 0, 'lifetime_throughput' => 100, 'median_lease_duration_seconds' => 0.0],
            ['client_id' => 'b', 'last_seen_at' => 0, 'in_flight_count' => 0, 'lifetime_throughput' => 1, 'median_lease_duration_seconds' => 0.0],
            ['client_id' => 'c', 'last_seen_at' => 0, 'in_flight_count' => 0, 'lifetime_throughput' => 1, 'median_lease_duration_seconds' => 0.0],
            ['client_id' => 'd', 'last_seen_at' => 0, 'in_flight_count' => 0, 'lifetime_throughput' => 1, 'median_lease_duration_seconds' => 0.0],
        ]);

        $tasks = [];
        foreach (['maestro', 'loop', 'cortex', 'self'] as $cls) {
            for ($i = 0; $i < 25; $i++) {
                $tasks[] = ['task_packet_id' => $cls.'-task-'.$i, 'outcome' => 'success'];
            }
        }

        $report = (new AtlasMaestroFairnessGiniReporter($probe, fn () => $tasks))->report();

        $this->assertGreaterThanOrEqual(0.7, $report['gini_workers']);
        $this->assertSame('a', $report['max_worker_share_id']);
        $this->assertGreaterThanOrEqual(0.96, $report['worker_share_histogram']['a']);
        $this->assertLessThanOrEqual(0.01, $report['gini_task_classes']);
    }

    public function test_balanced_workers_skewed_task_classes(): void
    {
        $probe = $this->probe([
            ['client_id' => 'a', 'last_seen_at' => 0, 'in_flight_count' => 0, 'lifetime_throughput' => 25, 'median_lease_duration_seconds' => 0.0],
            ['client_id' => 'b', 'last_seen_at' => 0, 'in_flight_count' => 0, 'lifetime_throughput' => 25, 'median_lease_duration_seconds' => 0.0],
            ['client_id' => 'c', 'last_seen_at' => 0, 'in_flight_count' => 0, 'lifetime_throughput' => 25, 'median_lease_duration_seconds' => 0.0],
            ['client_id' => 'd', 'last_seen_at' => 0, 'in_flight_count' => 0, 'lifetime_throughput' => 25, 'median_lease_duration_seconds' => 0.0],
        ]);

        $tasks = [];
        for ($i = 0; $i < 97; $i++) {
            $tasks[] = ['task_packet_id' => 'maestro-task-'.$i, 'outcome' => 'success'];
        }
        foreach (['loop', 'cortex', 'self'] as $cls) {
            $tasks[] = ['task_packet_id' => $cls.'-task-0', 'outcome' => 'success'];
        }

        $report = (new AtlasMaestroFairnessGiniReporter($probe, fn () => $tasks))->report();

        $this->assertLessThanOrEqual(0.01, $report['gini_workers']);
        $this->assertGreaterThanOrEqual(0.7, $report['gini_task_classes']);
        $this->assertSame('maestro', $report['max_task_class_share_id']);
    }

    public function test_known_families_group_as_stable_multi_segment_labels(): void
    {
        $probe = $this->probe([
            ['client_id' => 'w1', 'last_seen_at' => 0, 'in_flight_count' => 0, 'lifetime_throughput' => 3, 'median_lease_duration_seconds' => 0.0],
        ]);
        $tasks = [
            ['task_packet_id' => 'external-brain-task-001', 'outcome' => 'success'],
            ['task_packet_id' => 'codex-meta-xyz-001', 'outcome' => 'success'],
            ['task_packet_id' => 'final-brain-task-001', 'outcome' => 'success'],
        ];
        $report = (new AtlasMaestroFairnessGiniReporter($probe, fn () => $tasks))->report();

        $this->assertArrayHasKey('external-brain', $report['task_class_share_histogram']);
        $this->assertArrayHasKey('codex-meta', $report['task_class_share_histogram']);
        $this->assertArrayHasKey('final-brain', $report['task_class_share_histogram']);
        $this->assertArrayNotHasKey('external', $report['task_class_share_histogram']);
        $this->assertArrayNotHasKey('codex', $report['task_class_share_histogram']);
        $this->assertArrayNotHasKey('final', $report['task_class_share_histogram']);
    }

    public function test_concentration_warning_fires_when_one_worker_dominates(): void
    {
        $probe = $this->probe([
            ['client_id' => 'heavy', 'last_seen_at' => 0, 'in_flight_count' => 0, 'lifetime_throughput' => 100, 'median_lease_duration_seconds' => 0.0],
            ['client_id' => 'light', 'last_seen_at' => 0, 'in_flight_count' => 0, 'lifetime_throughput' => 1, 'median_lease_duration_seconds' => 0.0],
        ]);
        $report = (new AtlasMaestroFairnessGiniReporter($probe, fn () => []))->report();

        $this->assertContains('worker_dominant:heavy', $report['concentration_warnings']);
    }

    public function test_concentration_warning_fires_when_one_task_class_dominates(): void
    {
        $probe = $this->probe([
            ['client_id' => 'w1', 'last_seen_at' => 0, 'in_flight_count' => 0, 'lifetime_throughput' => 1, 'median_lease_duration_seconds' => 0.0],
        ]);
        $tasks = [];
        for ($i = 0; $i < 97; $i++) {
            $tasks[] = ['task_packet_id' => 'maestro-task-'.$i, 'outcome' => 'success'];
        }
        $tasks[] = ['task_packet_id' => 'loop-task-0', 'outcome' => 'success'];
        $tasks[] = ['task_packet_id' => 'cortex-task-0', 'outcome' => 'success'];
        $tasks[] = ['task_packet_id' => 'self-task-0', 'outcome' => 'success'];
        $report = (new AtlasMaestroFairnessGiniReporter($probe, fn () => $tasks))->report();

        $this->assertContains('task_class_dominant:maestro', $report['concentration_warnings']);
    }

    public function test_no_concentration_warning_when_balanced(): void
    {
        $probe = $this->probe([
            ['client_id' => 'a', 'last_seen_at' => 0, 'in_flight_count' => 0, 'lifetime_throughput' => 25, 'median_lease_duration_seconds' => 0.0],
            ['client_id' => 'b', 'last_seen_at' => 0, 'in_flight_count' => 0, 'lifetime_throughput' => 25, 'median_lease_duration_seconds' => 0.0],
        ]);
        $tasks = [];
        for ($i = 0; $i < 25; $i++) {
            $tasks[] = ['task_packet_id' => 'maestro-task-'.$i, 'outcome' => 'success'];
            $tasks[] = ['task_packet_id' => 'loop-task-'.$i, 'outcome' => 'success'];
        }
        $report = (new AtlasMaestroFairnessGiniReporter($probe, fn () => $tasks))->report();

        $this->assertSame([], $report['concentration_warnings']);
    }

    public function test_zero_completed_tasks_returns_zero_not_nan(): void
    {
        $probe = $this->probe([]);
        $report = (new AtlasMaestroFairnessGiniReporter($probe, fn () => []))->report();

        $this->assertSame(0.0, $report['gini_workers']);
        $this->assertSame(0.0, $report['gini_task_classes']);
        $this->assertSame([], $report['worker_share_histogram']);
        $this->assertSame([], $report['task_class_share_histogram']);
        $this->assertNull($report['max_worker_share_id']);
        $this->assertNull($report['max_task_class_share_id']);
    }

    public function test_stub_probe_payload_flows_through_to_output(): void
    {
        $probe = $this->probe([
            ['client_id' => 'only-worker', 'last_seen_at' => 0, 'in_flight_count' => 0, 'lifetime_throughput' => 42, 'median_lease_duration_seconds' => 0.0],
        ]);
        $tasks = [
            ['task_packet_id' => 'maestro-task-1', 'outcome' => 'success'],
            ['task_packet_id' => 'maestro-task-2', 'outcome' => 'success'],
        ];

        $report = (new AtlasMaestroFairnessGiniReporter($probe, fn () => $tasks))->report();

        $this->assertSame(1, $report['workers']);
        $this->assertSame('only-worker', $report['max_worker_share_id']);
        $this->assertEqualsWithDelta(1.0, $report['worker_share_histogram']['only-worker'], 1e-9);
        $this->assertSame(2, $report['total_completed']);
        $this->assertSame('maestro', $report['max_task_class_share_id']);
    }

    // ── idle_worker_ratio ────────────────────────────────────────────────────

    public function test_zero_throughput_workers_contribute_to_idle_worker_ratio(): void
    {
        $leases = [
            ['client_id' => 'active', 'opened_at' => 1_000_000, 'released_at' => 1_000_001],
            // in-flight-only lease: appears in the probe with lifetime_throughput=0.
            ['client_id' => 'idle', 'opened_at' => 1_000_000, 'released_at' => null],
        ];
        $probe = new AtlasMaestroWorkerFleetProbe(fn () => $leases);
        $tasks = [['task_packet_id' => 'maestro-task-1', 'outcome' => 'success']];

        $report = (new AtlasMaestroFairnessGiniReporter($probe, fn () => $tasks))->report();

        $this->assertSame(['idle'], $report['idle_worker_ids']);
        $this->assertEqualsWithDelta(0.5, $report['idle_worker_ratio'], 1e-9);
    }

    public function test_max_idle_worker_id_is_deterministic_with_multiple_idle_workers(): void
    {
        $leases = [
            ['client_id' => 'zeta-idle', 'opened_at' => 1_000_000, 'released_at' => null],
            ['client_id' => 'alpha-idle', 'opened_at' => 1_000_000, 'released_at' => null],
        ];
        $probe = new AtlasMaestroWorkerFleetProbe(fn () => $leases);

        $report = (new AtlasMaestroFairnessGiniReporter($probe, fn () => []))->report();

        $this->assertSame('alpha-idle', $report['max_idle_worker_id']);
        $this->assertSame(1.0, $report['idle_worker_ratio']);
    }

    public function test_no_idle_workers_yields_zero_ratio_and_null_max_idle_worker(): void
    {
        $probe = $this->probe([
            ['client_id' => 'a', 'last_seen_at' => 0, 'in_flight_count' => 0, 'lifetime_throughput' => 5, 'median_lease_duration_seconds' => 0.0],
        ]);

        $report = (new AtlasMaestroFairnessGiniReporter($probe, fn () => []))->report();

        $this->assertSame(0.0, $report['idle_worker_ratio']);
        $this->assertNull($report['max_idle_worker_id']);
    }

    public function test_existing_gini_and_concentration_fields_remain_unchanged_alongside_idle_axis(): void
    {
        $probe = $this->probe([
            ['client_id' => 'a', 'last_seen_at' => 0, 'in_flight_count' => 0, 'lifetime_throughput' => 100, 'median_lease_duration_seconds' => 0.0],
            ['client_id' => 'b', 'last_seen_at' => 0, 'in_flight_count' => 0, 'lifetime_throughput' => 1, 'median_lease_duration_seconds' => 0.0],
        ]);
        $tasks = [['task_packet_id' => 'maestro-task-1', 'outcome' => 'success']];

        $report = (new AtlasMaestroFairnessGiniReporter($probe, fn () => $tasks))->report();

        $this->assertArrayHasKey('gini_workers', $report);
        $this->assertArrayHasKey('gini_task_classes', $report);
        $this->assertArrayHasKey('concentration_warnings', $report);
        $this->assertArrayHasKey('idle_worker_ratio', $report);
        $this->assertArrayHasKey('max_idle_worker_id', $report);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // AC2/AC3/AC4: contract — stable empty, histograms, no score, compound families
    // ═══════════════════════════════════════════════════════════════════════

    public function test_empty_input_returns_stable_zeros_not_nan(): void
    {
        $probe = $this->probe([]);
        $report = (new AtlasMaestroFairnessGiniReporter($probe, fn () => []))->report();

        $this->assertSame(0.0, $report['gini_workers']);
        $this->assertSame(0.0, $report['gini_task_classes']);
        $this->assertSame([], $report['worker_share_histogram']);
        $this->assertSame(0.0, $report['idle_worker_ratio']);
        $this->assertSame([], $report['idle_worker_ids']);
    }

    public function test_output_includes_histograms_and_max_share_ids(): void
    {
        $probe = $this->probe([
            ['client_id' => 'w1', 'last_seen_at' => 0, 'in_flight_count' => 0, 'lifetime_throughput' => 10, 'median_lease_duration_seconds' => 0.0],
            ['client_id' => 'w2', 'last_seen_at' => 0, 'in_flight_count' => 0, 'lifetime_throughput' => 2, 'median_lease_duration_seconds' => 0.0],
        ]);
        $tasks = [['task_packet_id' => 'codex-meta-task-1', 'outcome' => 'success']];

        $report = (new AtlasMaestroFairnessGiniReporter($probe, fn () => $tasks))->report();

        $this->assertArrayHasKey('worker_share_histogram', $report);
        $this->assertArrayHasKey('max_worker_share_id', $report);
        $this->assertArrayHasKey('max_task_class_share_id', $report);
    }

    public function test_compound_task_family_is_recognized(): void
    {
        $probe = $this->probe([]);
        $tasks = [
            ['task_packet_id' => 'codex-meta-task-1', 'outcome' => 'success'],
            ['task_packet_id' => 'external-brain-task-2', 'outcome' => 'success'],
            ['task_packet_id' => 'final-brain-task-3', 'outcome' => 'success'],
        ];

        $report = (new AtlasMaestroFairnessGiniReporter($probe, fn () => $tasks))->report();

        $this->assertArrayHasKey('gini_task_classes', $report);
        // Compound families produce class keys in max_task_class_share_id.
        $distinct = array_unique(array_map(
            static fn (array $r): string => $r['task_packet_id'],
            $tasks,
        ));
        $this->assertCount(3, $distinct);
    }

    public function test_never_emits_scalar_score_or_rank(): void
    {
        $probe = $this->probe([]);
        $report = (new AtlasMaestroFairnessGiniReporter($probe, fn () => []))->report();
        $encoded = json_encode($report);

        foreach (['"score"', '"rank"', '"recommendation"'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $encoded,
                "report must never contain {$forbidden}");
        }
    }
}
