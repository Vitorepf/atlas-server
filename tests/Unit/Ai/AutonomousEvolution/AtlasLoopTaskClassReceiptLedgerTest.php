<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\TaskClassDiscovery\AtlasLoopTaskClassReceipt;
use App\Services\Ai\AutonomousEvolution\TaskClassDiscovery\AtlasLoopTaskClassReceiptLedger;
use Tests\TestCase;

final class AtlasLoopTaskClassReceiptLedgerTest extends TestCase
{
    public function test_append_assigns_monotonic_seq_and_links_hash_chain(): void
    {
        $ledger = new AtlasLoopTaskClassReceiptLedger();
        $first = $ledger->append(AtlasLoopTaskClassReceipt::KIND_CLUSTER_MINED, ['cluster_id' => 'c-1'], ['ev-1'], 'classA');
        $second = $ledger->append(AtlasLoopTaskClassReceipt::KIND_PROPOSAL_EMITTED, ['proposal_id' => 'p-1'], ['ev-2'], 'classA');

        $this->assertSame(1, $first->seq);
        $this->assertSame(2, $second->seq);
        $this->assertSame(str_repeat('0', 64), $first->prevHash);
        $this->assertSame($first->thisHash, $second->prevHash);
    }

    public function test_history_filters_by_class_id(): void
    {
        $ledger = new AtlasLoopTaskClassReceiptLedger();
        $ledger->append(AtlasLoopTaskClassReceipt::KIND_PROPOSAL_APPROVED, [], [], 'classA');
        $ledger->append(AtlasLoopTaskClassReceipt::KIND_PROPOSAL_APPROVED, [], [], 'classB');
        $ledger->append(AtlasLoopTaskClassReceipt::KIND_REGISTRY_SUPERSEDE, [], [], 'classA');

        $rows = iterator_to_array($ledger->history('classA'));
        $this->assertCount(2, $rows);
    }

    public function test_verify_chain_returns_true_on_intact_ledger(): void
    {
        $ledger = new AtlasLoopTaskClassReceiptLedger();
        $ledger->append(AtlasLoopTaskClassReceipt::KIND_CLUSTER_MINED, ['a' => 1], [], 'c1');
        $ledger->append(AtlasLoopTaskClassReceipt::KIND_PROPOSAL_EMITTED, ['p' => 1], [], 'c1');
        $ledger->append(AtlasLoopTaskClassReceipt::KIND_PROPOSAL_APPROVED, [], [], 'c1');

        $this->assertTrue($ledger->verifyChain());
    }

    public function test_provider_unsafe_keys_are_stripped_before_persist(): void
    {
        $ledger = new AtlasLoopTaskClassReceiptLedger();
        $r = $ledger->append(AtlasLoopTaskClassReceipt::KIND_PACKET_MATCHED, [
            'class_id' => 'classA',
            'provider_id' => 'codex',
            'prompt' => 'do not leak this',
            'trace' => ['secret'],
            'tokens' => 1234,
            'cost_usd' => 0.01,
            'safe_field' => 'ok',
        ]);
        foreach (AtlasLoopTaskClassReceiptLedger::PROVIDER_UNSAFE_KEYS as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $r->payload, "payload must NOT contain {$forbidden}");
        }
        $this->assertSame('ok', $r->payload['safe_field']);
    }
}
