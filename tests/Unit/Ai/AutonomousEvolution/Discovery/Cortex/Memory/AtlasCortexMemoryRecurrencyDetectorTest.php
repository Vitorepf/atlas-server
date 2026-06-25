<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Discovery\Cortex\Memory;

use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Memory\AtlasCortexMemoryEpisodeRecord;
use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Memory\AtlasCortexMemoryEpisodicLedger;
use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Memory\AtlasCortexMemoryRecurrencyDetector;
use PHPUnit\Framework\TestCase;

/**
 * Proves the Cortex recurrency detector: across 5 episodes, item Y appears every cycle (run=5) and item X
 * appears in cycles 1,2,3 then misses cycle 4 then reappears at cycle 5 — the gap at cycle 4 RESETS the
 * run. With minRuns=3 only Y qualifies. Same input ⇒ byte-identical json_encode output (zero hidden state).
 */
final class AtlasCortexMemoryRecurrencyDetectorTest extends TestCase
{
    private string $ledgerPath;

    private AtlasCortexMemoryEpisodicLedger $ledger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ledgerPath = sys_get_temp_dir().'/atlas_cortex_recurrency_'.bin2hex(random_bytes(6)).'.ndjson';
        $this->ledger = new AtlasCortexMemoryEpisodicLedger($this->ledgerPath);
    }

    protected function tearDown(): void
    {
        @unlink($this->ledgerPath);
        parent::tearDown();
    }

    /**
     * @param  list<array{item_id:string,kind:string,fingerprint:string}>  $inventory
     */
    private function episode(string $cycleId, int $capturedAt, array $inventory): AtlasCortexMemoryEpisodeRecord
    {
        return new AtlasCortexMemoryEpisodeRecord(
            cycleId: $cycleId,
            capturedAt: $capturedAt,
            scopeRoot: '/atlas/scope',
            snapshotDigest: 'sha256:'.str_repeat('a', 8),
            inventoryItems: $inventory,
            blindSpots: [],
            intentInterpretations: [],
            sourceFactsOnly: true,
        );
    }

    public function test_only_uninterrupted_run_meeting_min_runs_qualifies_gap_breaks_run(): void
    {
        $x = ['item_id' => 'X', 'kind' => 'class', 'fingerprint' => 'fp-X'];
        $y = ['item_id' => 'Y', 'kind' => 'class', 'fingerprint' => 'fp-Y'];

        $this->ledger->append($this->episode('c1', 1000, [$x, $y]));
        $this->ledger->append($this->episode('c2', 1001, [$x, $y]));
        $this->ledger->append($this->episode('c3', 1002, [$x, $y]));
        $this->ledger->append($this->episode('c4', 1003, [$y]));       // X missing ⇒ gap
        $this->ledger->append($this->episode('c5', 1004, [$x, $y]));   // X back, but its active run is 1

        $detector = new AtlasCortexMemoryRecurrencyDetector($this->ledger);
        $rows = $detector->recurrentItems(minRuns: 3);

        $this->assertCount(1, $rows, 'only Y meets minRuns=3 because gap reset X');
        $this->assertSame('Y', $rows[0]['item_id']);
        $this->assertSame('class', $rows[0]['kind']);
        $this->assertSame(5, $rows[0]['run_length']);
        $this->assertSame('c1', $rows[0]['first_seen_cycle_id']);
        $this->assertSame('c5', $rows[0]['last_seen_cycle_id']);
        $this->assertSame('fp-Y', $rows[0]['fingerprint']);
    }

    public function test_two_invocations_on_same_ledger_produce_byte_identical_json(): void
    {
        $i = ['item_id' => 'A', 'kind' => 'class', 'fingerprint' => 'fp-A'];
        $this->ledger->append($this->episode('c1', 1, [$i]));
        $this->ledger->append($this->episode('c2', 2, [$i]));
        $this->ledger->append($this->episode('c3', 3, [$i]));

        $detector = new AtlasCortexMemoryRecurrencyDetector($this->ledger);
        $a = json_encode($detector->recurrentItems(minRuns: 2), JSON_UNESCAPED_SLASHES);
        $b = json_encode($detector->recurrentItems(minRuns: 2), JSON_UNESCAPED_SLASHES);
        $this->assertSame($a, $b, 'pure: identical input ⇒ identical output');
    }

    public function test_ordering_is_run_length_desc_then_last_seen_cycle_id_desc_then_item_id_asc(): void
    {
        $longA = ['item_id' => 'A', 'kind' => 'class', 'fingerprint' => 'fp-A'];
        $longB = ['item_id' => 'B', 'kind' => 'class', 'fingerprint' => 'fp-B'];
        $longC = ['item_id' => 'C', 'kind' => 'class', 'fingerprint' => 'fp-C'];
        // A run_length=3, B and C both run_length=2 (last cycle = c3).
        $this->ledger->append($this->episode('c1', 1, [$longA]));
        $this->ledger->append($this->episode('c2', 2, [$longA, $longB, $longC]));
        $this->ledger->append($this->episode('c3', 3, [$longA, $longB, $longC]));

        $detector = new AtlasCortexMemoryRecurrencyDetector($this->ledger);
        $rows = $detector->recurrentItems(minRuns: 2);

        $ids = array_column($rows, 'item_id');
        $this->assertSame(['A', 'B', 'C'], $ids, 'A first (run=3), then B/C tied on last_seen_cycle_id → item_id asc');
    }

    public function test_returns_empty_when_no_episodes(): void
    {
        $detector = new AtlasCortexMemoryRecurrencyDetector($this->ledger);
        $this->assertSame([], $detector->recurrentItems(minRuns: 1));
    }

    public function test_fingerprint_change_resets_run(): void
    {
        $a1 = ['item_id' => 'A', 'kind' => 'class', 'fingerprint' => 'fp-1'];
        $a2 = ['item_id' => 'A', 'kind' => 'class', 'fingerprint' => 'fp-2'];
        $this->ledger->append($this->episode('c1', 1, [$a1]));
        $this->ledger->append($this->episode('c2', 2, [$a1]));
        $this->ledger->append($this->episode('c3', 3, [$a2])); // fingerprint changed → identity-tuple different

        $detector = new AtlasCortexMemoryRecurrencyDetector($this->ledger);
        $rows = $detector->recurrentItems(minRuns: 3);
        $this->assertSame([], $rows, 'fingerprint change ⇒ new identity-tuple, run breaks');
    }
}
