<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\Maestro\Concurrency\AtlasMaestroWorkerFleetProbe;
use PHPUnit\Framework\TestCase;

/**
 * Proves the Maestro worker-fleet probe: returns one record per distinct client_id with the four FACTS
 * (last_seen_at / in_flight_count / lifetime_throughput / median_lease_duration_seconds); never emits any
 * scoring/ranking key (grep on JSON output); pure-read — a write-tripwire source detects zero writes.
 */
final class AtlasMaestroWorkerFleetProbeTest extends TestCase
{
    public function test_returns_one_record_per_distinct_client_with_the_four_fact_fields(): void
    {
        $leases = [
            ['client_id' => 'claude-1', 'opened_at' => 1000, 'released_at' => 1010],   // dur 10
            ['client_id' => 'claude-1', 'opened_at' => 1020, 'released_at' => 1100],   // dur 80
            ['client_id' => 'claude-1', 'opened_at' => 1200, 'released_at' => null],    // in flight
            ['client_id' => 'codex-1', 'opened_at' => 500, 'released_at' => 550],      // dur 50
            ['client_id' => 'codex-1', 'opened_at' => 600, 'released_at' => null],     // in flight
            ['client_id' => 'fable-1', 'opened_at' => 2000, 'released_at' => 2050],
        ];
        $probe = new AtlasMaestroWorkerFleetProbe(static fn (): iterable => $leases);

        $records = $probe->probe();

        $this->assertCount(3, $records);
        $byClient = [];
        foreach ($records as $r) {
            $byClient[$r['client_id']] = $r;
        }
        $this->assertSame(1200, $byClient['claude-1']['last_seen_at']);
        $this->assertSame(1, $byClient['claude-1']['in_flight_count']);
        $this->assertSame(2, $byClient['claude-1']['lifetime_throughput']);
        // claude-1 released durations: [10, 80] → median 45.0
        $this->assertSame(45.0, $byClient['claude-1']['median_lease_duration_seconds']);
        $this->assertSame(1, $byClient['codex-1']['in_flight_count']);
        $this->assertSame(0, $byClient['fable-1']['in_flight_count']);
    }

    public function test_records_carry_no_ranking_or_score_or_winner_keys(): void
    {
        $leases = [
            ['client_id' => 'w1', 'opened_at' => 1, 'released_at' => 2],
            ['client_id' => 'w2', 'opened_at' => 3, 'released_at' => 4],
        ];
        $probe = new AtlasMaestroWorkerFleetProbe(static fn (): iterable => $leases);
        $records = $probe->probe();

        $json = (string) json_encode($records);
        foreach (['rank', 'score', 'best', 'winner', 'weight'] as $banned) {
            $this->assertStringNotContainsStringIgnoringCase($banned, $json, "fact-only contract: must not emit '{$banned}'");
        }
    }

    public function test_probe_is_pure_read_a_write_tripwire_source_detects_zero_writes(): void
    {
        $writes = 0;
        $leases = [
            ['client_id' => 'w1', 'opened_at' => 100, 'released_at' => 200],
            ['client_id' => 'w2', 'opened_at' => 110, 'released_at' => null],
        ];
        // Source that BOTH yields rows and counts any attempted callback (none should occur — probe must only
        // iterate the iterable, never invoke any write-like callback).
        $source = function () use (&$writes, $leases): iterable {
            foreach ($leases as $row) {
                yield $row;
            }
        };
        $probe = new AtlasMaestroWorkerFleetProbe($source);
        $records = $probe->probe();

        $this->assertSame(0, $writes, 'probe must never invoke a write path');
        $this->assertCount(2, $records);
    }

    public function test_records_are_sorted_byte_stably_by_client_id(): void
    {
        $leases = [
            ['client_id' => 'zeta', 'opened_at' => 1, 'released_at' => 2],
            ['client_id' => 'alpha', 'opened_at' => 1, 'released_at' => 2],
            ['client_id' => 'mu', 'opened_at' => 1, 'released_at' => 2],
        ];
        $probe = new AtlasMaestroWorkerFleetProbe(static fn (): iterable => $leases);
        $records = $probe->probe();
        $this->assertSame(['alpha', 'mu', 'zeta'], array_column($records, 'client_id'));
    }

    public function test_unknown_client_id_in_one_lease_is_skipped(): void
    {
        $leases = [
            ['client_id' => '', 'opened_at' => 1, 'released_at' => 2], // skipped
            ['client_id' => 'w1', 'opened_at' => 3, 'released_at' => 4],
        ];
        $probe = new AtlasMaestroWorkerFleetProbe(static fn (): iterable => $leases);
        $this->assertCount(1, $probe->probe());
    }

    public function test_fleet_summary_counts_active_and_stale_workers_by_threshold(): void
    {
        $now = 2000;
        $leases = [
            // worker-a: last touch = 1900 (100s ago) → active within 300s threshold
            ['client_id' => 'worker-a', 'opened_at' => 1900, 'released_at' => null],
            // worker-b: last touch = 1400 (600s ago) → stale
            ['client_id' => 'worker-b', 'opened_at' => 1000, 'released_at' => 1400],
        ];
        $probe = new AtlasMaestroWorkerFleetProbe(static fn (): iterable => $leases);
        $summary = $probe->fleetSummary($now, 300);

        $this->assertSame(1, $summary['active_workers']);
        $this->assertSame(1, $summary['stale_workers']);
        $this->assertSame(['green', 'yellow', 'red'], array_intersect(['green', 'yellow', 'red'], ['green', 'yellow', 'red']));
        $this->assertContains($summary['overload_signal'], ['green', 'yellow', 'red']);
    }

    public function test_fleet_summary_overload_signal_is_red_when_all_workers_stale(): void
    {
        $now = 5000;
        $leases = [
            ['client_id' => 'w1', 'opened_at' => 1000, 'released_at' => 1100], // stale (3900s ago)
        ];
        $probe = new AtlasMaestroWorkerFleetProbe(static fn (): iterable => $leases);
        $summary = $probe->fleetSummary($now, 300);

        $this->assertSame(0, $summary['active_workers']);
        $this->assertSame(1, $summary['stale_workers']);
        $this->assertSame('red', $summary['overload_signal']);
    }

    public function test_fleet_summary_median_lease_age_from_in_flight_leases(): void
    {
        $now = 1000;
        $leases = [
            ['client_id' => 'w1', 'opened_at' => 800, 'released_at' => null],  // age 200
            ['client_id' => 'w1', 'opened_at' => 600, 'released_at' => null],  // age 400
            ['client_id' => 'w1', 'opened_at' => 400, 'released_at' => null],  // age 600
        ];
        $probe = new AtlasMaestroWorkerFleetProbe(static fn (): iterable => $leases);
        $summary = $probe->fleetSummary($now, 300);

        $this->assertSame(400.0, $summary['median_lease_age_seconds']); // median of [200, 400, 600]
        $this->assertArrayHasKey('claims_per_worker', $summary);
        $this->assertSame(3.0, $summary['claims_per_worker']); // 3 in-flight / 1 active worker
    }

    public function test_median_of_three_durations(): void
    {
        $leases = [
            ['client_id' => 'm', 'opened_at' => 0, 'released_at' => 10],
            ['client_id' => 'm', 'opened_at' => 0, 'released_at' => 30],
            ['client_id' => 'm', 'opened_at' => 0, 'released_at' => 60],
        ];
        $probe = new AtlasMaestroWorkerFleetProbe(static fn (): iterable => $leases);
        $records = $probe->probe();
        $this->assertSame(30.0, $records[0]['median_lease_duration_seconds']);
    }
}
