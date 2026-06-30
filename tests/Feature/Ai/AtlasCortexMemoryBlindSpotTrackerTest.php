<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Memory\AtlasCortexMemoryBlindSpotTracker;
use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Memory\AtlasCortexMemoryEpisodicLedger;
use PHPUnit\Framework\TestCase;

final class AtlasCortexMemoryBlindSpotTrackerTest extends TestCase
{
    private string $ledgerFile;

    protected function setUp(): void
    {
        $this->ledgerFile = tempnam(sys_get_temp_dir(), 'blind_spot_test_');
    }

    protected function tearDown(): void
    {
        if (is_file($this->ledgerFile)) {
            unlink($this->ledgerFile);
        }
    }

    private function tracker(): AtlasCortexMemoryBlindSpotTracker
    {
        $ledger = new AtlasCortexMemoryEpisodicLedger($this->ledgerFile);
        return new AtlasCortexMemoryBlindSpotTracker($ledger);
    }

    private function episode(string $cycleId, int $capturedAt, array $blindSpots): string
    {
        return json_encode([
            'cycle_id'             => $cycleId,
            'captured_at'          => $capturedAt,
            'blind_spots'          => $blindSpots,
            'scope_root'           => '/test',
            'snapshot_digest'      => 'abc',
            'inventory_items'      => [],
            'intent_interpretations' => [],
            'source_facts_only'    => true,
        ])."\n";
    }

    private function gap(string $gapId, string $kind = 'missing_owner'): array
    {
        return ['gap_id' => $gapId, 'kind' => $kind];
    }

    // ── AC2: gap for ≥ minPersistence cycles → appears with first/last metadata ─

    public function test_gap_present_once_below_min_is_excluded(): void
    {
        file_put_contents($this->ledgerFile,
            $this->episode('c1', 1000, [$this->gap('gap-a')])
        );

        $results = $this->tracker()->persistentBlindSpots(2);

        $this->assertEmpty($results);
    }

    public function test_gap_present_at_min_persistence_is_included(): void
    {
        file_put_contents($this->ledgerFile,
            $this->episode('c1', 1000, [$this->gap('gap-a')]).
            $this->episode('c2', 2000, [$this->gap('gap-a')])
        );

        $results = $this->tracker()->persistentBlindSpots(2);

        $this->assertCount(1, $results);
        $this->assertSame('gap-a', $results[0]['gap_id']);
    }

    public function test_first_seen_and_last_seen_cycle_ids_are_populated(): void
    {
        file_put_contents($this->ledgerFile,
            $this->episode('c1', 1000, [$this->gap('gap-a')]).
            $this->episode('c2', 2000, [$this->gap('gap-a')]).
            $this->episode('c3', 3000, [$this->gap('gap-a')])
        );

        $results = $this->tracker()->persistentBlindSpots(2);

        $this->assertSame('c1', $results[0]['first_seen_cycle_id']);
        $this->assertSame('c3', $results[0]['last_seen_cycle_id']);
        $this->assertSame(1000, $results[0]['first_seen_unix']);
        $this->assertSame(3000, $results[0]['last_seen_unix']);
    }

    // ── AC3: disappearance resolves run; reappearance starts fresh run ─────────

    public function test_gap_disappearance_then_reappearance_creates_two_runs(): void
    {
        file_put_contents($this->ledgerFile,
            $this->episode('c1', 1000, [$this->gap('gap-a')]).
            $this->episode('c2', 2000, [$this->gap('gap-a')]).
            $this->episode('c3', 3000, []).                     // disappears
            $this->episode('c4', 4000, [$this->gap('gap-a')]).
            $this->episode('c5', 5000, [$this->gap('gap-a')])
        );

        $results = $this->tracker()->persistentBlindSpots(2);

        $this->assertCount(2, $results);
        $gapIds = array_column($results, 'gap_id');
        $this->assertCount(2, array_filter($gapIds, fn($id) => $id === 'gap-a'));
    }

    public function test_resolved_run_first_seen_matches_first_appearance(): void
    {
        file_put_contents($this->ledgerFile,
            $this->episode('c1', 1000, [$this->gap('gap-a')]).
            $this->episode('c2', 2000, [$this->gap('gap-a')]).
            $this->episode('c3', 3000, []).                     // disappears
            $this->episode('c4', 4000, [$this->gap('gap-a')]).
            $this->episode('c5', 5000, [$this->gap('gap-a')])
        );

        $results = $this->tracker()->persistentBlindSpots(2);

        $firstSeens = array_column($results, 'first_seen_cycle_id');
        $this->assertContains('c1', $firstSeens);
        $this->assertContains('c4', $firstSeens);
    }

    // ── AC4: sort order: persistence_runs desc, last_seen_cycle_id desc, gap_id asc

    public function test_sort_by_persistence_runs_descending(): void
    {
        file_put_contents($this->ledgerFile,
            $this->episode('c1', 1000, [$this->gap('gap-b')]).
            $this->episode('c2', 2000, [$this->gap('gap-a'), $this->gap('gap-b')]).
            $this->episode('c3', 3000, [$this->gap('gap-a'), $this->gap('gap-b')])
        );

        $results = $this->tracker()->persistentBlindSpots(2);

        // gap-b has persistence 3, gap-a has 2 — gap-b must come first
        $this->assertSame('gap-b', $results[0]['gap_id']);
        $this->assertSame('gap-a', $results[1]['gap_id']);
    }

    public function test_output_is_deterministic(): void
    {
        file_put_contents($this->ledgerFile,
            $this->episode('c1', 1000, [$this->gap('gap-a')]).
            $this->episode('c2', 2000, [$this->gap('gap-a')])
        );

        $a = $this->tracker()->persistentBlindSpots(2);
        $b = $this->tracker()->persistentBlindSpots(2);

        $this->assertSame(json_encode($a), json_encode($b));
    }
}
