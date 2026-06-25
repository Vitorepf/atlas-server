<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Receipts;

use App\Services\Ai\SelfConstruction\Receipts\AtlasSelfConstructionReceiptFactIndex;
use PHPUnit\Framework\TestCase;

/**
 * Proves AtlasSelfConstructionReceiptFactIndex: a complete supply yields a populated index with zero
 * blockers; duplicate id ⇒ <kind>:duplicate_id:<id>; missing hash ⇒ <kind>:missing_hash:<id>; missing
 * timestamp ⇒ <kind>:missing_ts:<id>; chain_ref pointing to an unknown decision_receipt id ⇒
 * <kind>:chain_ref_unknown:<id>; output ordering is deterministic.
 */
final class AtlasSelfConstructionReceiptFactIndexTest extends TestCase
{
    private function completeFacts(): array
    {
        return [
            'decision_receipts' => [['id' => 'd-1', 'hash' => 'h-d1', 'ts' => 't']],
            'task_receipts' => [['id' => 't-1', 'hash' => 'h-t1', 'ts' => 't']],
            'lease_receipts' => [['id' => 'l-1', 'hash' => 'h-l1', 'ts' => 't']],
            'verification_receipts' => [['id' => 'v-1', 'hash' => 'h-v1', 'ts' => 't', 'chain_ref' => 'd-1']],
            'merge_receipts' => [['id' => 'm-1', 'hash' => 'h-m1', 'ts' => 't', 'chain_ref' => 'd-1']],
            'rollback_receipts' => [['id' => 'r-1', 'hash' => 'h-r1', 'ts' => 't']],
            'knowledge_receipts' => [['id' => 'k-1', 'hash' => 'h-k1', 'ts' => 't']],
            'learning_receipts' => [['id' => 'l-learn', 'hash' => 'h-lr1', 'ts' => 't']],
        ];
    }

    public function test_complete_index_yields_zero_blockers_and_populated_summary(): void
    {
        $r = (new AtlasSelfConstructionReceiptFactIndex)->project($this->completeFacts());
        $this->assertSame([], $r['blockers']);
        foreach (AtlasSelfConstructionReceiptFactIndex::KINDS as $k) {
            $this->assertSame(1, $r['summary'][$k]);
            $this->assertCount(1, $r['index'][$k]);
        }
    }

    public function test_duplicate_id_yields_named_blocker(): void
    {
        $f = $this->completeFacts();
        $f['task_receipts'][] = ['id' => 't-1', 'hash' => 'h-t2', 'ts' => 't'];
        $r = (new AtlasSelfConstructionReceiptFactIndex)->project($f);
        $this->assertContains('task_receipts:duplicate_id:t-1', $r['blockers']);
    }

    public function test_missing_hash_yields_named_blocker(): void
    {
        $f = $this->completeFacts();
        $f['lease_receipts'][0]['hash'] = '';
        $r = (new AtlasSelfConstructionReceiptFactIndex)->project($f);
        $this->assertContains('lease_receipts:missing_hash:l-1', $r['blockers']);
    }

    public function test_missing_ts_yields_named_blocker(): void
    {
        $f = $this->completeFacts();
        $f['rollback_receipts'][0]['ts'] = '';
        $r = (new AtlasSelfConstructionReceiptFactIndex)->project($f);
        $this->assertContains('rollback_receipts:missing_ts:r-1', $r['blockers']);
    }

    public function test_unknown_chain_ref_yields_named_blocker(): void
    {
        $f = $this->completeFacts();
        $f['verification_receipts'][0]['chain_ref'] = 'd-phantom';
        $r = (new AtlasSelfConstructionReceiptFactIndex)->project($f);
        $this->assertContains('verification_receipts:chain_ref_unknown:d-phantom', $r['blockers']);
    }

    public function test_id_map_is_sorted_byte_stably_per_kind(): void
    {
        $r = (new AtlasSelfConstructionReceiptFactIndex)->project([
            'task_receipts' => [
                ['id' => 'z-task', 'hash' => 'h', 'ts' => 't'],
                ['id' => 'a-task', 'hash' => 'h', 'ts' => 't'],
                ['id' => 'm-task', 'hash' => 'h', 'ts' => 't'],
            ],
        ]);
        $this->assertSame(['a-task', 'm-task', 'z-task'], array_keys($r['index']['task_receipts']));
    }
}
