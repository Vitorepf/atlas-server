<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\LiveCycle;

use App\Services\Ai\AutonomousEvolution\LiveCycle\Integration\AtlasLoopLiveCycleAuditTrail;
use App\Services\Ai\AutonomousEvolution\LiveCycle\Integration\AtlasLoopLiveCyclePhaseReceiptComposer;
use Tests\TestCase;

final class AtlasLoopLiveCycleAuditTrailTest extends TestCase
{
    /**
     * @param  list<array<string,mixed>>  $facts
     */
    private function factStore(array $facts): object
    {
        return new class($facts)
        {
            public function __construct(private array $facts) {}

            public function facts(string $pattern): array
            {
                return $this->facts;
            }
        };
    }

    /**
     * @return list<string>
     */
    private function phaseHashes(string $cycleId): array
    {
        $hashes = [];
        for ($i = 1; $i <= 8; $i++) {
            $hashes[] = hash('sha256', $cycleId.':phase:'.$i);
        }

        return $hashes;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function cycleFacts(string $cycleId, int $startedAt, ?int $completedAt, bool $resumed, ?string $forceRootHash = null): array
    {
        $phaseHashes = $this->phaseHashes($cycleId);
        $composer = new AtlasLoopLiveCyclePhaseReceiptComposer();
        $composed = $composer->compose($cycleId, $phaseHashes, ['projection_outcome' => 'po-1']);
        $rootHash = $forceRootHash ?? $composed['root_hash'];

        $facts = [];
        $facts[] = ['name' => 'cycle.started', 'payload' => ['cycle_id' => $cycleId, 'started_at' => $startedAt]];
        if ($resumed) {
            $facts[] = ['name' => 'cycle.resume.planned', 'payload' => ['cycle_id' => $cycleId, 'from_phase' => 6, 'verified_chain' => true]];
        }
        $prev = '';
        foreach ($phaseHashes as $i => $h) {
            $facts[] = ['name' => 'cycle.phase.completed', 'payload' => [
                'cycle_id' => $cycleId,
                'phase_index' => $i + 1,
                'receipt_hash' => $h,
                'prev_receipt_hash' => $prev,
                'sub_ledger_links' => ['projection_outcome' => 'po-1'],
                'completed_at' => $startedAt + $i + 1,
            ]];
            $prev = $h;
        }
        $facts[] = ['name' => 'cycle.receipt.composed', 'payload' => [
            'cycle_id' => $cycleId,
            'root_hash' => $rootHash,
            'phase_receipt_hashes' => $phaseHashes,
            'sub_ledger_links' => ['projection_outcome' => 'po-1'],
        ]];
        if ($completedAt !== null) {
            $facts[] = ['name' => 'cycle.completed', 'payload' => ['cycle_id' => $cycleId, 'completed_at' => $completedAt]];
        }

        return $facts;
    }

    public function test_list_cycles_returns_both_with_status_root_hash_and_phase_count(): void
    {
        $facts = array_merge(
            $this->cycleFacts('cyc-A', startedAt: 1000, completedAt: 1100, resumed: false),
            $this->cycleFacts('cyc-B', startedAt: 2000, completedAt: 2200, resumed: true),
        );
        $trail = new AtlasLoopLiveCycleAuditTrail($this->factStore($facts));
        $rows = $trail->listCycles();

        $this->assertCount(2, $rows);
        $this->assertSame('cyc-A', $rows[0]['cycle_id']);
        $this->assertSame('completed', $rows[0]['status']);
        $this->assertSame(8, $rows[0]['phase_count_completed']);
        $this->assertNotEmpty($rows[0]['root_hash']);

        $this->assertSame('cyc-B', $rows[1]['cycle_id']);
        $this->assertSame('resumed', $rows[1]['status']);
        $this->assertSame(8, $rows[1]['phase_count_completed']);
        $this->assertNotEmpty($rows[1]['root_hash']);
    }

    public function test_describe_returns_8_phases_chain_that_round_trips_to_root_hash(): void
    {
        $facts = $this->cycleFacts('cyc-A', startedAt: 1000, completedAt: 1100, resumed: false);
        $trail = new AtlasLoopLiveCycleAuditTrail($this->factStore($facts));
        $describe = $trail->describe('cyc-A');

        $this->assertCount(8, $describe['phases']);
        foreach ($describe['phases'] as $i => $phase) {
            $this->assertSame($i + 1, $phase['phase_index']);
        }

        // Round-trip: feed the audit-trail's ordered hashes back through the composer.
        $recomputed = (new AtlasLoopLiveCyclePhaseReceiptComposer())
            ->compose('cyc-A', $describe['ordered_phase_receipt_hashes'], $describe['sub_ledger_links']);
        $this->assertSame($recomputed['root_hash'], $describe['root_hash']);
    }

    public function test_cycle_with_zero_facts_returns_empty_with_reason(): void
    {
        $trail = new AtlasLoopLiveCycleAuditTrail($this->factStore([]));
        $describe = $trail->describe('cyc-missing');

        $this->assertTrue($describe['empty']);
        $this->assertSame('no_facts_found', $describe['reason']);
        $this->assertSame([], $describe['phases']);
    }

    public function test_audit_trail_source_reads_only_from_fact_store(): void
    {
        $src = (string) file_get_contents(base_path('app/Services/Ai/AutonomousEvolution/LiveCycle/Integration/AtlasLoopLiveCycleAuditTrail.php'));
        foreach (['Conductor', 'shell_exec', 'proc_open', 'exec(', 'system(', 'DB::', 'Http::', 'AtlasLoopEvolutionConductorService'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $src, "audit trail must NOT contain {$forbidden}");
        }
    }
}
