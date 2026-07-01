<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\Maestro\Concurrency\AtlasMaestroWorkerFairnessAuditor;
use App\Services\Ai\SelfConstruction\Maestro\Concurrency\AtlasMaestroWorkerFleetProbe;
use Tests\TestCase;

final class AtlasMaestroWorkerFairnessAuditorTest extends TestCase
{
    /**
     * Build a real {@see AtlasMaestroWorkerFleetProbe} whose lease source synthesizes the rolled-up rows the
     * auditor expects. The probe rolls leases by client_id ⇒ for each desired (in_flight_count, lifetime_throughput)
     * pair we emit N closed leases (lifetime_throughput) + M open leases (in_flight_count).
     *
     * @param  list<array{client_id:string, in_flight_count:int, lifetime_throughput:int}>  $rows
     */
    private function stubProbe(array $rows): AtlasMaestroWorkerFleetProbe
    {
        $leases = [];
        foreach ($rows as $row) {
            for ($i = 0; $i < $row['lifetime_throughput']; $i++) {
                $leases[] = ['client_id' => $row['client_id'], 'opened_at' => 0, 'released_at' => 1];
            }
            for ($i = 0; $i < $row['in_flight_count']; $i++) {
                $leases[] = ['client_id' => $row['client_id'], 'opened_at' => 0, 'released_at' => null];
            }
        }

        return new AtlasMaestroWorkerFleetProbe(fn (): iterable => $leases);
    }

    public function test_skewed_distribution_yields_high_gini_and_correct_max_share(): void
    {
        // 4 workers; completed counts [100, 1, 1, 1]; ties are broken by FIRST insertion order.
        $auditor = new AtlasMaestroWorkerFairnessAuditor($this->stubProbe([
            ['client_id' => 'hog', 'in_flight_count' => 1, 'lifetime_throughput' => 100],
            ['client_id' => 'idle-a', 'in_flight_count' => 0, 'lifetime_throughput' => 1],
            ['client_id' => 'idle-b', 'in_flight_count' => 0, 'lifetime_throughput' => 1],
            ['client_id' => 'idle-c', 'in_flight_count' => 0, 'lifetime_throughput' => 1],
        ]));

        $facts = $auditor->audit();

        $this->assertSame(4, $facts['workers']);
        $this->assertSame('hog', $facts['max_share_client_id']);
        $this->assertGreaterThanOrEqual(0.96, $facts['max_share_value']);
        $this->assertGreaterThanOrEqual(0.7, $facts['gini_coefficient']);
    }

    public function test_perfectly_balanced_distribution_yields_zero_gini(): void
    {
        $auditor = new AtlasMaestroWorkerFairnessAuditor($this->stubProbe([
            ['client_id' => 'a', 'in_flight_count' => 0, 'lifetime_throughput' => 25],
            ['client_id' => 'b', 'in_flight_count' => 0, 'lifetime_throughput' => 25],
            ['client_id' => 'c', 'in_flight_count' => 0, 'lifetime_throughput' => 25],
            ['client_id' => 'd', 'in_flight_count' => 0, 'lifetime_throughput' => 25],
        ]));

        $facts = $auditor->audit();

        $this->assertLessThanOrEqual(0.01, $facts['gini_coefficient']);
        $this->assertCount(4, $facts['completed_share_histogram']);
        $sum = array_sum($facts['completed_share_histogram']);
        $this->assertEqualsWithDelta(1.0, $sum, 1e-9);
    }

    public function test_constructor_injection_swappable_probe_drives_the_output(): void
    {
        $stub = $this->stubProbe([
            ['client_id' => 'solo', 'in_flight_count' => 2, 'lifetime_throughput' => 7],
        ]);

        $facts = (new AtlasMaestroWorkerFairnessAuditor($stub))->audit();

        $this->assertSame(1, $facts['workers']);
        $this->assertSame(2, $facts['total_in_flight']);
        $this->assertSame(7, $facts['total_completed']);
        $this->assertSame('solo', $facts['max_share_client_id']);
    }

    public function test_skewed_throughput_emits_hogging_alert_with_max_share_client_id(): void
    {
        $auditor = new AtlasMaestroWorkerFairnessAuditor($this->stubProbe([
            ['client_id' => 'hog', 'in_flight_count' => 0, 'lifetime_throughput' => 90],
            ['client_id' => 'peer', 'in_flight_count' => 0, 'lifetime_throughput' => 10],
        ]));

        $facts = $auditor->audit();

        $hoggingAlerts = array_values(array_filter($facts['fairness_alerts'], fn ($a) => $a['alert'] === 'hogging'));
        $this->assertCount(1, $hoggingAlerts);
        $this->assertSame('hog', $hoggingAlerts[0]['max_share_client_id']);
        $this->assertGreaterThan(0.5, $hoggingAlerts[0]['max_share_value']);
    }

    public function test_starved_workers_listed_deterministically_in_starvation_alert(): void
    {
        // Two equal dominant workers (max_share=0.5, no hogging) and two starved workers.
        $auditor = new AtlasMaestroWorkerFairnessAuditor($this->stubProbe([
            ['client_id' => 'dom-a', 'in_flight_count' => 0, 'lifetime_throughput' => 25],
            ['client_id' => 'dom-b', 'in_flight_count' => 0, 'lifetime_throughput' => 25],
            ['client_id' => 'starved-b', 'in_flight_count' => 1, 'lifetime_throughput' => 0],
            ['client_id' => 'starved-a', 'in_flight_count' => 1, 'lifetime_throughput' => 0],
        ]));

        $facts = $auditor->audit();

        $starvAlerts = array_values(array_filter($facts['fairness_alerts'], fn ($a) => $a['alert'] === 'starvation'));
        $this->assertCount(1, $starvAlerts);
        $this->assertSame(['starved-a', 'starved-b'], $starvAlerts[0]['starved_client_ids']);
        // No hogging alert (max_share == 0.5, not strictly greater than 0.5)
        $hoggingAlerts = array_filter($facts['fairness_alerts'], fn ($a) => $a['alert'] === 'hogging');
        $this->assertCount(0, $hoggingAlerts);
    }

    public function test_balanced_fleet_emits_no_fairness_alerts(): void
    {
        $auditor = new AtlasMaestroWorkerFairnessAuditor($this->stubProbe([
            ['client_id' => 'a', 'in_flight_count' => 0, 'lifetime_throughput' => 25],
            ['client_id' => 'b', 'in_flight_count' => 0, 'lifetime_throughput' => 25],
            ['client_id' => 'c', 'in_flight_count' => 0, 'lifetime_throughput' => 25],
            ['client_id' => 'd', 'in_flight_count' => 0, 'lifetime_throughput' => 25],
        ]));

        $facts = $auditor->audit();

        $this->assertSame([], $facts['fairness_alerts']);
    }

    public function test_empty_probe_is_a_zero_facts_envelope(): void
    {
        $facts = (new AtlasMaestroWorkerFairnessAuditor($this->stubProbe([])))->audit();

        $this->assertSame(0, $facts['workers']);
        $this->assertSame(0, $facts['total_in_flight']);
        $this->assertSame(0, $facts['total_completed']);
        $this->assertSame([], $facts['in_flight_histogram']);
        $this->assertSame([], $facts['completed_share_histogram']);
        $this->assertSame(0.0, $facts['gini_coefficient']);
        $this->assertNull($facts['max_share_client_id']);
        $this->assertSame(0.0, $facts['max_share_value']);
    }

    // --- AC2: assignment share, success share, give_back burden, poison exposure ---

    public function test_assignment_share_histogram_reflects_in_flight_plus_completed(): void
    {
        $auditor = new AtlasMaestroWorkerFairnessAuditor($this->stubProbe([
            ['client_id' => 'a', 'in_flight_count' => 3, 'lifetime_throughput' => 7],
            ['client_id' => 'b', 'in_flight_count' => 0, 'lifetime_throughput' => 10],
        ]));

        $facts = $auditor->audit();

        // a: (3+7)=10, b: (0+10)=10, total=20 → 0.5 each.
        $this->assertEqualsWithDelta(0.5, $facts['assignment_share_histogram']['a'], 0.001);
        $this->assertEqualsWithDelta(0.5, $facts['assignment_share_histogram']['b'], 0.001);
    }

    public function test_outcome_stats_report_success_give_back_and_poison_shares(): void
    {
        $auditor = new AtlasMaestroWorkerFairnessAuditor($this->stubProbe([
            ['client_id' => 'worker-a', 'in_flight_count' => 0, 'lifetime_throughput' => 4],
        ]));

        $facts = $auditor->audit([
            ['client_id' => 'worker-a', 'outcome' => 'success'],
            ['client_id' => 'worker-a', 'outcome' => 'success'],
            ['client_id' => 'worker-a', 'outcome' => 'give_back'],
            ['client_id' => 'worker-a', 'outcome' => 'poison_detected'],
        ]);

        $stats = $facts['outcome_stats_by_client']['worker-a'];
        $this->assertSame(4, $stats['total_outcomes']);
        $this->assertEqualsWithDelta(0.5, $stats['success_share'], 0.001);
        $this->assertEqualsWithDelta(0.25, $stats['give_back_share'], 0.001);
        $this->assertEqualsWithDelta(0.25, $stats['poison_share'], 0.001);
    }

    public function test_outcome_stats_default_to_zero_when_no_outcome_facts_supplied(): void
    {
        $auditor = new AtlasMaestroWorkerFairnessAuditor($this->stubProbe([
            ['client_id' => 'solo', 'in_flight_count' => 0, 'lifetime_throughput' => 3],
        ]));

        $facts = $auditor->audit();

        $this->assertSame(0, $facts['outcome_stats_by_client']['solo']['total_outcomes']);
        $this->assertSame(0.0, $facts['outcome_stats_by_client']['solo']['success_share']);
    }

    // --- AC3: hard-task concentration alert + repair_advice on every alert ---

    public function test_hard_task_concentration_alert_fires_above_70_percent_share(): void
    {
        $auditor = new AtlasMaestroWorkerFairnessAuditor($this->stubProbe([
            ['client_id' => 'heavy', 'in_flight_count' => 0, 'lifetime_throughput' => 5],
            ['client_id' => 'light', 'in_flight_count' => 0, 'lifetime_throughput' => 5],
        ]));

        $outcomeFacts = array_merge(
            array_fill(0, 8, ['client_id' => 'heavy', 'outcome' => 'success', 'is_hard_task' => true]),
            array_fill(0, 2, ['client_id' => 'light', 'outcome' => 'success', 'is_hard_task' => true]),
        );

        $facts = $auditor->audit($outcomeFacts);

        $concentrationAlerts = array_values(array_filter($facts['fairness_alerts'], fn ($a) => $a['alert'] === 'hard_task_concentration'));
        $this->assertCount(1, $concentrationAlerts);
        $this->assertSame('heavy', $concentrationAlerts[0]['client_id']);
        $this->assertNotEmpty($concentrationAlerts[0]['repair_advice']);
    }

    public function test_hard_task_concentration_does_not_fire_when_evenly_split(): void
    {
        $auditor = new AtlasMaestroWorkerFairnessAuditor($this->stubProbe([
            ['client_id' => 'a', 'in_flight_count' => 0, 'lifetime_throughput' => 5],
            ['client_id' => 'b', 'in_flight_count' => 0, 'lifetime_throughput' => 5],
        ]));

        $outcomeFacts = array_merge(
            array_fill(0, 5, ['client_id' => 'a', 'outcome' => 'success', 'is_hard_task' => true]),
            array_fill(0, 5, ['client_id' => 'b', 'outcome' => 'success', 'is_hard_task' => true]),
        );

        $facts = $auditor->audit($outcomeFacts);

        $concentrationAlerts = array_filter($facts['fairness_alerts'], fn ($a) => $a['alert'] === 'hard_task_concentration');
        $this->assertCount(0, $concentrationAlerts);
    }

    public function test_every_fairness_alert_carries_repair_advice(): void
    {
        $auditor = new AtlasMaestroWorkerFairnessAuditor($this->stubProbe([
            ['client_id' => 'hog', 'in_flight_count' => 0, 'lifetime_throughput' => 90],
            ['client_id' => 'peer', 'in_flight_count' => 0, 'lifetime_throughput' => 10],
        ]));

        $facts = $auditor->audit();

        $this->assertNotEmpty($facts['fairness_alerts']);
        foreach ($facts['fairness_alerts'] as $alert) {
            $this->assertArrayHasKey('repair_advice', $alert);
            $this->assertNotSame('', $alert['repair_advice']);
        }
    }

    // --- AC4: healthy specialization vs unfair routing ---

    public function test_high_share_with_high_success_is_healthy_specialization(): void
    {
        $auditor = new AtlasMaestroWorkerFairnessAuditor($this->stubProbe([
            ['client_id' => 'specialist', 'in_flight_count' => 0, 'lifetime_throughput' => 9],
            ['client_id' => 'other', 'in_flight_count' => 0, 'lifetime_throughput' => 1],
        ]));

        $outcomeFacts = array_fill(0, 9, ['client_id' => 'specialist', 'outcome' => 'success']);
        $facts = $auditor->audit($outcomeFacts);

        $this->assertSame('healthy_specialization', $facts['specialization_classification']['specialist']);
    }

    public function test_high_share_with_high_give_back_is_unfair_routing(): void
    {
        $auditor = new AtlasMaestroWorkerFairnessAuditor($this->stubProbe([
            ['client_id' => 'overloaded', 'in_flight_count' => 0, 'lifetime_throughput' => 9],
            ['client_id' => 'other', 'in_flight_count' => 0, 'lifetime_throughput' => 1],
        ]));

        $outcomeFacts = array_merge(
            array_fill(0, 3, ['client_id' => 'overloaded', 'outcome' => 'success']),
            array_fill(0, 6, ['client_id' => 'overloaded', 'outcome' => 'give_back']),
        );
        $facts = $auditor->audit($outcomeFacts);

        $this->assertSame('unfair_routing', $facts['specialization_classification']['overloaded']);
    }

    public function test_no_outcome_data_is_insufficient_data(): void
    {
        $auditor = new AtlasMaestroWorkerFairnessAuditor($this->stubProbe([
            ['client_id' => 'a', 'in_flight_count' => 0, 'lifetime_throughput' => 5],
        ]));

        $facts = $auditor->audit();

        $this->assertSame('insufficient_data', $facts['specialization_classification']['a']);
    }

    public function test_low_share_worker_is_balanced_not_specialized_or_unfair(): void
    {
        $auditor = new AtlasMaestroWorkerFairnessAuditor($this->stubProbe([
            ['client_id' => 'a', 'in_flight_count' => 0, 'lifetime_throughput' => 5],
            ['client_id' => 'b', 'in_flight_count' => 0, 'lifetime_throughput' => 5],
        ]));

        $outcomeFacts = array_merge(
            array_fill(0, 5, ['client_id' => 'a', 'outcome' => 'success']),
            array_fill(0, 5, ['client_id' => 'b', 'outcome' => 'success']),
        );
        $facts = $auditor->audit($outcomeFacts);

        $this->assertSame('balanced', $facts['specialization_classification']['a']);
        $this->assertSame('balanced', $facts['specialization_classification']['b']);
    }
}
