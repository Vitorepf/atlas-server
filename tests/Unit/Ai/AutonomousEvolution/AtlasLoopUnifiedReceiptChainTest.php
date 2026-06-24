<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\UnifiedReceipts\AtlasLoopUnifiedReceiptChain;
use Tests\TestCase;

final class AtlasLoopUnifiedReceiptChainTest extends TestCase
{
    public function test_append_links_nodes_with_genesis_prev_hash_and_prev_hash_chain(): void
    {
        $chain = $this->chain();

        $first = $chain->append($this->receipt('substrate', 'sub-1', ['kind' => 'substrate', 'value' => 1]));
        $second = $chain->append($this->receipt('cycle', 'cyc-1', ['kind' => 'cycle', 'value' => 2]));
        $third = $chain->append($this->receipt('goodhart', 'goo-1', ['kind' => 'goodhart', 'value' => 3]));

        $this->assertSame(str_repeat('0', 64), $first->prev_hash);
        $this->assertSame($first->node_hash, $second->prev_hash);
        $this->assertSame($second->node_hash, $third->prev_hash);
    }

    public function test_payload_hash_is_byte_identical_for_the_same_facts_across_runs(): void
    {
        $pathOne = tempnam(sys_get_temp_dir(), 'chain_a_') ?: sys_get_temp_dir().'/chain_a.jsonl';
        $pathTwo = tempnam(sys_get_temp_dir(), 'chain_b_') ?: sys_get_temp_dir().'/chain_b.jsonl';
        @unlink($pathOne);
        @unlink($pathTwo);

        $first = (new AtlasLoopUnifiedReceiptChain($pathOne, clock: fn (): int => 1000))
            ->append($this->receipt('substrate', 'sub-1', ['b' => 2, 'a' => 1]));
        $second = (new AtlasLoopUnifiedReceiptChain($pathTwo, clock: fn (): int => 1000))
            ->append($this->receipt('substrate', 'sub-1', ['a' => 1, 'b' => 2]));

        $this->assertSame($first->payload_hash, $second->payload_hash);
    }

    public function test_source_facts_json_round_trips_to_the_same_decoded_payload(): void
    {
        $facts = ['z' => ['b' => 2, 'a' => 1], 'a' => 'root'];
        $node = $this->chain()->append($this->receipt('cycle', 'cyc-1', $facts));

        $this->assertSame($facts, json_decode($node->source_facts_json, true, flags: JSON_THROW_ON_ERROR));
    }

    public function test_two_instances_append_sequential_nodes_with_monotonic_seq_and_unique_hashes(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'chain_shared_') ?: sys_get_temp_dir().'/chain_shared.jsonl';
        @unlink($path);
        $ticks = [1001, 1002];
        $clock = static function () use (&$ticks): int {
            return array_shift($ticks) ?? 1003;
        };

        $firstChain = new AtlasLoopUnifiedReceiptChain($path, $clock);
        $secondChain = new AtlasLoopUnifiedReceiptChain($path, $clock);

        $first = $firstChain->append($this->receipt('substrate', 'sub-1', ['a' => 1]));
        $second = $secondChain->append($this->receipt('cycle', 'cyc-2', ['a' => 2]));

        $this->assertSame(1, $first->seq);
        $this->assertSame(2, $second->seq);
        $this->assertNotSame($first->node_hash, $second->node_hash);
    }

    private function chain(): AtlasLoopUnifiedReceiptChain
    {
        $path = tempnam(sys_get_temp_dir(), 'unified_chain_') ?: sys_get_temp_dir().'/unified_chain.jsonl';
        @unlink($path);
        $ticks = [1001, 1002, 1003, 1004];
        $clock = static function () use (&$ticks): int {
            return array_shift($ticks) ?? 1005;
        };

        return new AtlasLoopUnifiedReceiptChain($path, $clock);
    }

    /**
     * @param  array<string,mixed>  $facts
     * @return array{facts:array<string,mixed>,receipt_id:string,source_ledger:string}
     */
    private function receipt(string $sourceLedger, string $receiptId, array $facts): array
    {
        return [
            'facts' => $facts,
            'receipt_id' => $receiptId,
            'source_ledger' => $sourceLedger,
        ];
    }
}
