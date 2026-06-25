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
}
