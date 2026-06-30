<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\Maestro\Projection\AtlasMaestroWorkloadConsumptionRateReporter;
use Carbon\CarbonImmutable;
use Tests\TestCase;

final class AtlasMaestroWorkloadConsumptionRateReporterTest extends TestCase
{
    public function test_report_computes_per_client_and_fleet_rates_without_fact_scores(): void
    {
        $now = CarbonImmutable::parse('2026-06-24T07:00:00Z');
        $spy = new class
        {
            public int $claimCalls = 0;
            public int $releaseCalls = 0;
            public int $completeCalls = 0;

            public function claimNext(): void
            {
                $this->claimCalls++;
            }

            public function release(): void
            {
                $this->releaseCalls++;
            }

            public function completeDryRun(): void
            {
                $this->completeCalls++;
            }
        };

        $report = (new AtlasMaestroWorkloadConsumptionRateReporter($spy))->report($this->snapshot($now), $now, 3600);

        $this->assertSame('atlas.maestro.projection.consumption_rate.v1', $report['schema']);
        $this->assertSame(3, count($report['rows']));
        $this->assertSame(6.0, $report['rows'][0]['tasks_per_hour']);
        $this->assertSame(3.0, $report['rows'][1]['tasks_per_hour']);
        $this->assertSame(9.0, $report['rows'][2]['tasks_per_hour']);
        $this->assertSame('fleet', $report['rows'][2]['client_id']);

        $this->assertSame(0, $spy->claimCalls);
        $this->assertSame(0, $spy->releaseCalls);
        $this->assertSame(0, $spy->completeCalls);

        $this->assertNoGoodhartKeys($report);
    }

    public function test_producer_shaped_events_with_to_key_yield_non_zero_throughput(): void
    {
        $now = CarbonImmutable::parse('2026-06-29T10:00:00Z');

        // Producer writes event='status_changed' / event='status_compare_and_swapped' with the real
        // status in the 'to' key — the reporter must read 'to' first, not 'event'.
        $events = [];
        for ($i = 0; $i < 4; $i++) {
            $events[] = [
                'client_id' => 'worker-x',
                'event' => 'status_compare_and_swapped',
                'to' => 'completed_dry_run',
                'recorded_at' => $now->subMinutes(5)->toIso8601String(),
            ];
        }
        $events[] = [
            'client_id' => 'worker-x',
            'event' => 'status_changed',
            'to' => 'claimed',
            'recorded_at' => $now->subMinutes(10)->toIso8601String(),
        ];

        $report = (new AtlasMaestroWorkloadConsumptionRateReporter())->report(['events' => $events], $now, 3600);

        // worker-x row: 4 completed → tasks_per_hour = 4 / (3600/3600) = 4.0
        $workerRow = $report['rows'][0];
        $this->assertSame('worker-x', $workerRow['client_id']);
        $this->assertSame(4, $workerRow['completed_count']);
        $this->assertGreaterThan(0.0, $workerRow['tasks_per_hour'],
            'tasks_per_hour must be non-zero when producer-shaped events have real status in to key');
        $this->assertSame(1, $workerRow['started_count']);

        // fleet row must also reflect non-zero throughput
        $fleetRow = $report['rows'][1];
        $this->assertSame('fleet', $fleetRow['client_id']);
        $this->assertGreaterThan(0.0, $fleetRow['tasks_per_hour']);
    }

    public function test_give_back_and_failed_appear_as_separate_buckets_and_not_as_completed(): void
    {
        $now = CarbonImmutable::parse('2026-06-30T08:00:00Z');
        $events = [
            ['client_id' => 'worker-a', 'event' => 'give_back', 'recorded_at' => $now->subMinutes(5)->toIso8601String()],
            ['client_id' => 'worker-a', 'event' => 'give_back', 'recorded_at' => $now->subMinutes(5)->toIso8601String()],
            ['client_id' => 'worker-a', 'event' => 'failed', 'recorded_at' => $now->subMinutes(5)->toIso8601String()],
            ['client_id' => 'worker-a', 'event' => 'completed_dry_run', 'recorded_at' => $now->subMinutes(5)->toIso8601String()],
        ];

        $report = (new AtlasMaestroWorkloadConsumptionRateReporter())->report(['events' => $events], $now, 3600);

        $row = $report['rows'][0];
        $this->assertSame(1, $row['completed_count'], 'give_back/failed must not inflate completed');
        $this->assertSame(2, $row['give_back_count']);
        $this->assertSame(1, $row['failed_count']);
        $this->assertSame(1.0, $row['tasks_per_hour'], 'rate based on completed only');

        $fleet = $report['rows'][1];
        $this->assertSame('fleet', $fleet['client_id']);
        $this->assertSame(2, $fleet['give_back_count']);
        $this->assertSame(1, $fleet['failed_count']);
    }

    public function test_tasks_per_hour_reported_by_task_family(): void
    {
        $now = CarbonImmutable::parse('2026-06-30T08:00:00Z');
        $events = [
            ['client_id' => 'w', 'event' => 'completed_dry_run', 'task_class' => 'refactor', 'recorded_at' => $now->subMinutes(5)->toIso8601String()],
            ['client_id' => 'w', 'event' => 'completed_dry_run', 'task_class' => 'refactor', 'recorded_at' => $now->subMinutes(5)->toIso8601String()],
            ['client_id' => 'w', 'event' => 'completed_dry_run', 'task_class' => 'docs', 'recorded_at' => $now->subMinutes(5)->toIso8601String()],
        ];

        $report = (new AtlasMaestroWorkloadConsumptionRateReporter())->report(['events' => $events], $now, 3600);

        $byFamily = array_column($report['family_rows'], null, 'task_family');
        $this->assertArrayHasKey('refactor', $byFamily);
        $this->assertArrayHasKey('docs', $byFamily);
        $this->assertSame(2, $byFamily['refactor']['completed_count']);
        $this->assertSame(1, $byFamily['docs']['completed_count']);
        $this->assertSame(2.0, $byFamily['refactor']['tasks_per_hour']);
        $this->assertSame(1.0, $byFamily['docs']['tasks_per_hour']);
    }

    public function test_active_claimed_does_not_increment_completed_count(): void
    {
        $now = CarbonImmutable::parse('2026-06-30T08:00:00Z');
        $events = [
            ['client_id' => 'w', 'event' => 'claimed', 'recorded_at' => $now->subMinutes(2)->toIso8601String()],
            ['client_id' => 'w', 'event' => 'claimed', 'recorded_at' => $now->subMinutes(1)->toIso8601String()],
        ];

        $report = (new AtlasMaestroWorkloadConsumptionRateReporter())->report(['events' => $events], $now, 3600);

        $row = $report['rows'][0];
        $this->assertSame(0, $row['completed_count']);
        $this->assertSame(0, $row['give_back_count']);
        $this->assertSame(0, $row['failed_count']);
        $this->assertSame(2, $row['started_count']);
        $this->assertSame(0.0, $row['tasks_per_hour']);
    }

    public function test_success_resolved_and_completed_aliases_all_count_as_completed(): void
    {
        $now = CarbonImmutable::parse('2026-06-30T08:00:00Z');
        $events = [
            ['client_id' => 'w', 'event' => 'success', 'recorded_at' => $now->subMinutes(5)->toIso8601String()],
            ['client_id' => 'w', 'event' => 'resolved', 'recorded_at' => $now->subMinutes(5)->toIso8601String()],
            ['client_id' => 'w', 'event' => 'completed', 'recorded_at' => $now->subMinutes(5)->toIso8601String()],
            ['client_id' => 'w', 'event' => 'completed_dry_run', 'recorded_at' => $now->subMinutes(5)->toIso8601String()],
        ];

        $report = (new AtlasMaestroWorkloadConsumptionRateReporter())->report(['events' => $events], $now, 3600);

        $row = $report['rows'][0];
        $this->assertSame('w', $row['client_id']);
        $this->assertSame(4, $row['completed_count']);
        $this->assertSame(4.0, $row['tasks_per_hour']);

        $fleet = $report['rows'][1];
        $this->assertSame('fleet', $fleet['client_id']);
        $this->assertSame(4, $fleet['completed_count']);
        $this->assertSame(4.0, $fleet['tasks_per_hour']);
    }

    public function test_give_back_and_failure_alias_still_populate_separate_counts_with_aliases(): void
    {
        $now = CarbonImmutable::parse('2026-06-30T08:00:00Z');
        $events = [
            ['client_id' => 'w', 'event' => 'give_back', 'recorded_at' => $now->subMinutes(5)->toIso8601String()],
            ['client_id' => 'w', 'event' => 'failure', 'recorded_at' => $now->subMinutes(5)->toIso8601String()],
            ['client_id' => 'w', 'event' => 'success', 'recorded_at' => $now->subMinutes(5)->toIso8601String()],
        ];

        $report = (new AtlasMaestroWorkloadConsumptionRateReporter())->report(['events' => $events], $now, 3600);

        $row = $report['rows'][0];
        $this->assertSame(1, $row['completed_count'], 'give_back/failure must not inflate completed');
        $this->assertSame(1, $row['give_back_count']);
        $this->assertSame(1, $row['failed_count']);
    }

    public function test_reporter_exposes_deterministic_alias_evidence_showing_which_labels_counted(): void
    {
        $now = CarbonImmutable::parse('2026-06-30T08:00:00Z');
        $events = [
            ['client_id' => 'w', 'event' => 'success', 'recorded_at' => $now->subMinutes(5)->toIso8601String()],
            ['client_id' => 'w', 'event' => 'success', 'recorded_at' => $now->subMinutes(5)->toIso8601String()],
            ['client_id' => 'w', 'event' => 'completed_dry_run', 'recorded_at' => $now->subMinutes(5)->toIso8601String()],
        ];

        $report = (new AtlasMaestroWorkloadConsumptionRateReporter())->report(['events' => $events], $now, 3600);

        $row = $report['rows'][0];
        $this->assertArrayHasKey('completed_alias_counts', $row);
        $this->assertSame(2, $row['completed_alias_counts']['success']);
        $this->assertSame(1, $row['completed_alias_counts']['completed_dry_run']);

        $this->assertArrayHasKey('terminal_alias_evidence', $report);
        $byAlias = array_column($report['terminal_alias_evidence'], 'count', 'alias');
        $this->assertSame(2, $byAlias['success']);
        $this->assertSame(1, $byAlias['completed_dry_run']);

        $this->assertNoGoodhartKeys($report);

        // determinism
        $second = (new AtlasMaestroWorkloadConsumptionRateReporter())->report(['events' => $events], $now, 3600);
        $this->assertSame($report, $second);
    }

    /**
     * @return array{events:list<array<string,string>>}
     */
    private function snapshot(CarbonImmutable $now): array
    {
        $events = [];

        for ($i = 0; $i < 6; $i++) {
            $events[] = [
                'client_id' => 'worker-a',
                'event' => 'completed_dry_run',
                'recorded_at' => $now->subMinutes(10)->toIso8601String(),
            ];
        }

        for ($i = 0; $i < 3; $i++) {
            $events[] = [
                'client_id' => 'worker-b',
                'event' => 'completed_dry_run',
                'recorded_at' => $now->subMinutes(15)->toIso8601String(),
            ];
        }

        $events[] = [
            'client_id' => 'worker-a',
            'event' => 'claimed',
            'recorded_at' => $now->subMinutes(20)->toIso8601String(),
        ];
        $events[] = [
            'client_id' => 'worker-b',
            'event' => 'claimed',
            'recorded_at' => $now->subMinutes(25)->toIso8601String(),
        ];

        return ['events' => $events];
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function assertNoGoodhartKeys(array $payload): void
    {
        $json = json_encode($payload, JSON_THROW_ON_ERROR);
        $this->assertIsString($json);
        $this->assertStringNotContainsString('"score"', $json);
        $this->assertStringNotContainsString('"quality"', $json);
        $this->assertStringNotContainsString('"rating"', $json);
        $this->assertStringNotContainsString('"rank"', $json);
    }
}
