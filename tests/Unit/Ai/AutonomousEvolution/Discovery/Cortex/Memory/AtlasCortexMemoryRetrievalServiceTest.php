<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Discovery\Cortex\Memory;

use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Memory\AtlasCortexMemoryEpisodeRecord;
use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Memory\AtlasCortexMemoryEpisodicLedger;
use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Memory\AtlasCortexMemoryRetrievalService;
use Tests\TestCase;

final class AtlasCortexMemoryRetrievalServiceTest extends TestCase
{
    private string $ledgerPath = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->ledgerPath = sys_get_temp_dir().'/atlas-cortex-mem-ret-'.bin2hex(random_bytes(6)).'.ndjson';
    }

    protected function tearDown(): void
    {
        @unlink($this->ledgerPath);
        parent::tearDown();
    }

    private function seedService(): AtlasCortexMemoryRetrievalService
    {
        $ledger = new AtlasCortexMemoryEpisodicLedger($this->ledgerPath);

        // 6 episodes, varied facts. Episodes 1, 3, 5 contain inventory_item {X, fp7}.
        $episodes = [
            ['c-001', 1000, [['item_id' => 'X', 'kind' => 'class', 'fingerprint' => 'fp7']], [['gap_id' => 'g1', 'kind' => 'orphan']], [['intent_id' => 'int1', 'canonical_form' => 'a']]],
            ['c-002', 1100, [['item_id' => 'Y', 'kind' => 'class', 'fingerprint' => 'fp9']], [], []],
            ['c-003', 1200, [['item_id' => 'X', 'kind' => 'class', 'fingerprint' => 'fp7']], [], []],
            ['c-004', 1300, [['item_id' => 'X', 'kind' => 'class', 'fingerprint' => 'fp8']], [['gap_id' => 'g2', 'kind' => 'unwired']], []],
            ['c-005', 1400, [['item_id' => 'X', 'kind' => 'class', 'fingerprint' => 'fp7']], [], [['intent_id' => 'int1', 'canonical_form' => 'a']]],
            ['c-006', 1500, [['item_id' => 'Z', 'kind' => 'class', 'fingerprint' => 'fp0']], [], []],
        ];
        foreach ($episodes as [$cid, $at, $inv, $bs, $intents]) {
            $ledger->append(new AtlasCortexMemoryEpisodeRecord(
                cycleId: $cid,
                capturedAt: $at,
                scopeRoot: 'atlas-server',
                snapshotDigest: 'digest-'.$cid,
                inventoryItems: $inv,
                blindSpots: $bs,
                intentInterpretations: $intents,
            ));
        }

        return new AtlasCortexMemoryRetrievalService($ledger);
    }

    public function test_inventory_item_matches_return_reverse_chronological_with_limit(): void
    {
        $svc = $this->seedService();

        $out = $svc->retrieve(['kind' => 'inventory_item', 'item_id' => 'X', 'fingerprint' => 'fp7'], 3);

        $this->assertCount(3, $out);
        $this->assertSame(['c-005', 'c-003', 'c-001'], array_column($out, 'episode_cycle_id'));
        foreach ($out as $row) {
            $this->assertSame(['exact_id', 'exact_fingerprint'], $row['match_dimensions']);
            $this->assertSame('X', $row['matched_payload']['item_id']);
            $this->assertSame('fp7', $row['matched_payload']['fingerprint']);
        }
    }

    public function test_unrelated_probe_returns_empty(): void
    {
        $svc = $this->seedService();

        $out = $svc->retrieve(['kind' => 'inventory_item', 'item_id' => 'NOPE', 'fingerprint' => 'fp7'], 10);

        $this->assertSame([], $out);
    }

    public function test_no_fuzzy_match_returns_empty_for_unknown_fingerprint(): void
    {
        $svc = $this->seedService();

        $out = $svc->retrieve(['kind' => 'inventory_item', 'item_id' => 'X', 'fingerprint' => 'fp-unknown'], 10);

        $this->assertSame([], $out, 'must NOT fuzzy-match — exact set membership only');
    }

    public function test_two_reads_on_frozen_ledger_are_byte_identical(): void
    {
        $svc = $this->seedService();

        $a = $svc->retrieve(['kind' => 'inventory_item', 'item_id' => 'X', 'fingerprint' => 'fp7'], 10);
        $b = $svc->retrieve(['kind' => 'inventory_item', 'item_id' => 'X', 'fingerprint' => 'fp7'], 10);

        $this->assertSame(json_encode($a), json_encode($b));
    }

    public function test_intent_match_returns_only_exact_canonical_form_matches(): void
    {
        $svc = $this->seedService();

        $out = $svc->retrieve(['kind' => 'intent', 'intent_id' => 'int1', 'canonical_form' => 'a'], 10);
        $this->assertSame(['c-005', 'c-001'], array_column($out, 'episode_cycle_id'));

        $miss = $svc->retrieve(['kind' => 'intent', 'intent_id' => 'int1', 'canonical_form' => 'different'], 10);
        $this->assertSame([], $miss);
    }
}
