<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Maestro\Concurrency;

use App\Services\Ai\SelfConstruction\Maestro\Concurrency\AtlasMaestroWorkerFleetProbe;
use PHPUnit\Framework\TestCase;

final class AtlasMaestroWorkerFleetProbeTest extends TestCase
{
    public function test_lease_older_than_stale_threshold_is_classified_stale_worker(): void
    {
        $now = 100000;
        $probe = new AtlasMaestroWorkerFleetProbe(static fn (): array => [
            ['client_id' => 'w-stale', 'opened_at' => $now - 1000, 'released_at' => $now - 900],
        ]);

        $classifications = $probe->workerClassifications($now, staleThresholdSeconds: 300);

        $this->assertSame('stale_worker', $classifications[0]['classification']);
    }

    public function test_recently_active_lease_is_classified_active_or_idle_not_stale(): void
    {
        $now = 100000;
        $probe = new AtlasMaestroWorkerFleetProbe(static fn (): array => [
            ['client_id' => 'w-fresh', 'opened_at' => $now - 10, 'released_at' => null],
        ]);

        $classifications = $probe->workerClassifications($now, staleThresholdSeconds: 300);

        $this->assertSame('active_worker', $classifications[0]['classification']);
    }

    public function test_claimed_free_lease_ghost_is_classified_ghost_worker_signal(): void
    {
        $now = 100000;
        $probe = new AtlasMaestroWorkerFleetProbe(static fn (): array => [
            // claimed (opened) long ago, never released — a ghost, regardless of recent last_seen churn.
            ['client_id' => 'w-ghost', 'opened_at' => $now - 7200, 'released_at' => null],
        ]);

        $classifications = $probe->workerClassifications($now, staleThresholdSeconds: 300, ghostThresholdSeconds: 3600);

        $this->assertSame('ghost_worker_signal', $classifications[0]['classification']);
    }

    public function test_ghost_classification_takes_precedence_over_stale(): void
    {
        $now = 100000;
        $probe = new AtlasMaestroWorkerFleetProbe(static fn (): array => [
            ['client_id' => 'w-both', 'opened_at' => $now - 10000, 'released_at' => null],
        ]);

        $classifications = $probe->workerClassifications($now, staleThresholdSeconds: 300, ghostThresholdSeconds: 3600);

        $this->assertSame('ghost_worker_signal', $classifications[0]['classification']);
    }

    public function test_fleet_summary_includes_coordination_hints_without_blocking_healthy_workers(): void
    {
        $now = 100000;
        $probe = new AtlasMaestroWorkerFleetProbe(static fn (): array => [
            ['client_id' => 'w-healthy', 'opened_at' => $now - 5, 'released_at' => null],
            ['client_id' => 'w-ghost', 'opened_at' => $now - 7200, 'released_at' => null],
        ]);

        $summary = $probe->fleetSummary($now, 300);

        $this->assertArrayHasKey('coordination_hints', $summary);
        $this->assertNotEmpty($summary['coordination_hints']);
        $this->assertSame(1, $summary['ghost_worker_count']);
        // healthy worker still counted as active, not excluded/blocked by the ghost signal.
        $this->assertSame(1, $summary['active_workers']);
    }

    public function test_fleet_summary_has_no_hints_when_all_workers_healthy(): void
    {
        $now = 100000;
        $probe = new AtlasMaestroWorkerFleetProbe(static fn (): array => [
            ['client_id' => 'w-1', 'opened_at' => $now - 5, 'released_at' => null],
        ]);

        $summary = $probe->fleetSummary($now, 300);

        $this->assertSame([], $summary['coordination_hints']);
        $this->assertSame(0, $summary['ghost_worker_count']);
    }
}
