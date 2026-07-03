<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Brain2;

use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Memory\AtlasCortexMemoryEpisodicLedger;
use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Memory\AtlasCortexMemoryRecurrencyDetector;
use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Memory\AtlasCortexMemoryEpisodeRecord;
use PHPUnit\Framework\TestCase;
use Tests\Feature\Loop\ArmsAtlasLoopMaster;

/**
 * Proves AtlasCortexMemoryRecurrencyDetector::recurrentItems does not merge
 * two distinct items that both lack a kind.
 */
final class AtlasCortexMemoryRecurrencyDetectorHardeningTest extends TestCase
{
    use ArmsAtlasLoopMaster;

    private string $ledgerPath;
    private AtlasCortexMemoryEpisodicLedger $ledger;
    private AtlasCortexMemoryRecurrencyDetector $detector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->armLoopMasterOn();
        $this->ledgerPath = sys_get_temp_dir().'/atlas_cortex_recurrency_hardening_'.bin2hex(random_bytes(6)).'.ndjson';
        $this->ledger = new AtlasCortexMemoryEpisodicLedger($this->ledgerPath);
        $this->detector = new AtlasCortexMemoryRecurrencyDetector($this->ledger);
    }

    protected function tearDown(): void
    {
        $this->disarmLoopMaster();
        @unlink($this->ledgerPath);
        parent::tearDown();
    }

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

    public function test_two_distinct_items_with_empty_kind_are_not_merged(): void
    {
        // Two items with different item_ids, both with empty kind, same fingerprint
        // With the fix, both are skipped — they should NOT appear in results
        $itemA = ['item_id' => 'A', 'kind' => '', 'fingerprint' => 'fp-shared'];
        $itemB = ['item_id' => 'B', 'kind' => '', 'fingerprint' => 'fp-shared'];

        for ($i = 1; $i <= 3; $i++) {
            $ep = $this->episode("cycle-{$i}", 1000 + $i, [$itemA, $itemB]);
            $this->ledger->append($ep);
        }

        $result = $this->detector->recurrentItems(minRuns: 3);

        // Empty-kind items are skipped — no results
        $this->assertCount(0, $result);
    }

    public function test_empty_kind_items_are_skipped(): void
    {
        $itemWithKind = ['item_id' => 'X', 'kind' => 'class', 'fingerprint' => 'fp-X'];
        $itemNoKind = ['item_id' => 'Y', 'kind' => '', 'fingerprint' => 'fp-Y'];

        for ($i = 1; $i <= 3; $i++) {
            $ep = $this->episode("cycle-{$i}", 1000 + $i, [$itemWithKind, $itemNoKind]);
            $this->ledger->append($ep);
        }

        $result = $this->detector->recurrentItems(minRuns: 3);

        // Only the item with a kind should appear
        $this->assertCount(1, $result);
        $this->assertSame('X', $result[0]['item_id']);
    }
}
