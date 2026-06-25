<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Discovery\Cortex\Memory;

use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Memory\AtlasCortexMemoryBlindSpotTracker;
use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Memory\AtlasCortexMemoryEpisodeRecord;
use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Memory\AtlasCortexMemoryEpisodicLedger;
use PHPUnit\Framework\TestCase;

/**
 * Proves the Cortex blind-spot tracker: G1 in cycles 1,2,3 then absent in cycle 4 ⇒ persistence_runs=3
 * (resolved run); G2 in cycles 1..4 ⇒ persistence_runs=4 (still active at end-of-stream). With
 * minPersistence=3 both qualify; ordering is by persistence_runs desc. Reappearance after resolution emits
 * a SEPARATE shorter run (not merged). Same input ⇒ byte-identical output across calls.
 */
final class AtlasCortexMemoryBlindSpotTrackerTest extends TestCase
{
    private string $ledgerPath;

    private AtlasCortexMemoryEpisodicLedger $ledger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ledgerPath = sys_get_temp_dir().'/atlas_cortex_blindspot_'.bin2hex(random_bytes(6)).'.ndjson';
        $this->ledger = new AtlasCortexMemoryEpisodicLedger($this->ledgerPath);
    }

    protected function tearDown(): void
    {
        @unlink($this->ledgerPath);
        parent::tearDown();
    }

    /**
     * @param  list<array{gap_id:string, kind:string}>  $blindSpots
     */
    private function episode(string $cycleId, int $capturedAt, array $blindSpots): AtlasCortexMemoryEpisodeRecord
    {
        return new AtlasCortexMemoryEpisodeRecord(
            cycleId: $cycleId,
            capturedAt: $capturedAt,
            scopeRoot: '/atlas/scope',
            snapshotDigest: 'sha256:'.str_repeat('a', 8),
            inventoryItems: [],
            blindSpots: $blindSpots,
            intentInterpretations: [],
            sourceFactsOnly: true,
        );
    }

    public function test_g1_runs3_resolved_at_cycle4_and_g2_runs4_still_active_both_qualify_at_min3(): void
    {
        $g1 = ['gap_id' => 'G1', 'kind' => 'missing_call'];
        $g2 = ['gap_id' => 'G2', 'kind' => 'missing_doc'];

        $this->ledger->append($this->episode('c1', 100, [$g1, $g2]));
        $this->ledger->append($this->episode('c2', 200, [$g1, $g2]));
        $this->ledger->append($this->episode('c3', 300, [$g1, $g2]));
        $this->ledger->append($this->episode('c4', 400, [$g2])); // G1 resolved; G2 persists

        $tracker = new AtlasCortexMemoryBlindSpotTracker($this->ledger);
        $rows = $tracker->persistentBlindSpots(minPersistence: 3);

        $this->assertCount(2, $rows);
        // Order: G2(4) before G1(3).
        $this->assertSame('G2', $rows[0]['gap_id']);
        $this->assertSame(4, $rows[0]['persistence_runs']);
        $this->assertSame('c1', $rows[0]['first_seen_cycle_id']);
        $this->assertSame('c4', $rows[0]['last_seen_cycle_id']);
        $this->assertSame(100, $rows[0]['first_seen_unix']);
        $this->assertSame(400, $rows[0]['last_seen_unix']);

        $this->assertSame('G1', $rows[1]['gap_id']);
        $this->assertSame(3, $rows[1]['persistence_runs']);
        $this->assertSame('c1', $rows[1]['first_seen_cycle_id']);
        $this->assertSame('c3', $rows[1]['last_seen_cycle_id']);
    }

    public function test_reappearance_after_resolution_creates_a_separate_run_not_merged(): void
    {
        $g = ['gap_id' => 'G', 'kind' => 'missing_call'];

        $this->ledger->append($this->episode('c1', 100, [$g]));
        $this->ledger->append($this->episode('c2', 200, [$g]));
        $this->ledger->append($this->episode('c3', 300, [$g])); // run1 = 3
        $this->ledger->append($this->episode('c4', 400, []));   // RESOLVED
        $this->ledger->append($this->episode('c5', 500, [$g])); // run2 = 1 fresh
        $this->ledger->append($this->episode('c6', 600, [$g])); // run2 = 2

        $tracker = new AtlasCortexMemoryBlindSpotTracker($this->ledger);
        $rows = $tracker->persistentBlindSpots(minPersistence: 1);

        $this->assertCount(2, $rows, 'two separate runs, not merged');
        // Order: run1 (persistence=3) first, then run2 (persistence=2).
        $this->assertSame(3, $rows[0]['persistence_runs']);
        $this->assertSame('c3', $rows[0]['last_seen_cycle_id']);
        $this->assertSame(2, $rows[1]['persistence_runs']);
        $this->assertSame('c6', $rows[1]['last_seen_cycle_id']);
    }

    public function test_two_calls_byte_identical_json(): void
    {
        $g1 = ['gap_id' => 'A', 'kind' => 'k'];
        $g2 = ['gap_id' => 'B', 'kind' => 'k'];
        $this->ledger->append($this->episode('c1', 1, [$g1, $g2]));
        $this->ledger->append($this->episode('c2', 2, [$g1, $g2]));

        $tracker = new AtlasCortexMemoryBlindSpotTracker($this->ledger);
        $a = json_encode($tracker->persistentBlindSpots(minPersistence: 1), JSON_UNESCAPED_SLASHES);
        $b = json_encode($tracker->persistentBlindSpots(minPersistence: 1), JSON_UNESCAPED_SLASHES);
        $this->assertSame($a, $b);
    }

    public function test_below_threshold_runs_are_filtered_out(): void
    {
        $g = ['gap_id' => 'G', 'kind' => 'k'];
        $this->ledger->append($this->episode('c1', 1, [$g]));
        $this->ledger->append($this->episode('c2', 2, [])); // G already resolved at run=1
        $tracker = new AtlasCortexMemoryBlindSpotTracker($this->ledger);
        $this->assertSame([], $tracker->persistentBlindSpots(minPersistence: 2));
    }
}
