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

    public function test_blank_decision_ref_on_downstream_row_yields_blocker(): void
    {
        $f = $this->completeFacts();
        $f['task_receipts'][0]['decision_ref'] = ''; // present but blank
        $r = (new AtlasSelfConstructionReceiptFactIndex)->project($f);
        $this->assertContains('task_receipts:blank_decision_ref:t-1', $r['blockers']);
    }

    public function test_blank_decision_hash_on_downstream_row_yields_blocker(): void
    {
        $f = $this->completeFacts();
        $f['learning_receipts'] = [['id' => 'lr-1', 'hash' => 'h-lr1', 'ts' => 't', 'decision_hash' => '']];
        $r = (new AtlasSelfConstructionReceiptFactIndex)->project($f);
        $this->assertContains('learning_receipts:blank_decision_hash:lr-1', $r['blockers']);
    }

    public function test_absent_decision_ref_does_not_yield_blank_blocker(): void
    {
        $f = $this->completeFacts();
        // task_receipts row has no decision_ref key at all — no blocker expected
        $r = (new AtlasSelfConstructionReceiptFactIndex)->project($f);
        $this->assertNotContains('task_receipts:blank_decision_ref:t-1', $r['blockers']);
    }

    public function test_blank_decision_ref_on_merge_receipt_yields_blocker(): void
    {
        $f = $this->completeFacts();
        $f['merge_receipts'][0]['decision_ref'] = '  '; // whitespace only
        $r = (new AtlasSelfConstructionReceiptFactIndex)->project($f);
        $this->assertContains('merge_receipts:blank_decision_ref:m-1', $r['blockers']);
    }

    public function test_decision_link_summary_counts_bound_looking_rows(): void
    {
        $f = $this->completeFacts();
        $f['task_receipts'][0]['decision_ref'] = 'd-1';
        $f['task_receipts'][0]['decision_hash'] = 'h-d1';

        $r = (new AtlasSelfConstructionReceiptFactIndex)->project($f);

        $this->assertSame(1, $r['decision_link_summary']['bound_looking']);
    }

    public function test_decision_link_summary_counts_blank_rows_separately_and_still_produces_blockers(): void
    {
        $f = $this->completeFacts();
        $f['merge_receipts'][0]['decision_ref'] = '  ';

        $r = (new AtlasSelfConstructionReceiptFactIndex)->project($f);

        $this->assertSame(1, $r['decision_link_summary']['blank']);
        $this->assertContains('merge_receipts:blank_decision_ref:m-1', $r['blockers']);
    }

    public function test_unknown_chain_ref_blockers_remain_unchanged(): void
    {
        $f = $this->completeFacts();
        $f['verification_receipts'][0]['chain_ref'] = 'ghost-decision';

        $r = (new AtlasSelfConstructionReceiptFactIndex)->project($f);

        $this->assertContains('verification_receipts:chain_ref_unknown:ghost-decision', $r['blockers']);
    }

    // ── join surface: task / commit / worker / decision / capability (AC) ──────

    public function test_rows_are_joinable_by_task_commit_worker_decision_and_capability(): void
    {
        $r = (new AtlasSelfConstructionReceiptFactIndex)->project([
            'task_receipts' => [[
                'id' => 't-1', 'hash' => 'h', 'ts' => 't',
                'task_packet_id' => 'pkt-1', 'commit_sha' => 'abc123',
                'worker_id' => 'worker-a', 'decision_ref' => 'd-1', 'capability' => 'php',
            ]],
        ]);

        $this->assertSame([['kind' => 'task_receipts', 'id' => 't-1']], $r['by_task']['pkt-1']);
        $this->assertSame([['kind' => 'task_receipts', 'id' => 't-1']], $r['by_commit']['abc123']);
        $this->assertSame([['kind' => 'task_receipts', 'id' => 't-1']], $r['by_worker']['worker-a']);
        $this->assertSame([['kind' => 'task_receipts', 'id' => 't-1']], $r['by_decision']['d-1']);
        $this->assertSame([['kind' => 'task_receipts', 'id' => 't-1']], $r['by_capability']['php']);
    }

    public function test_duplicate_receipts_across_kinds_join_to_same_task_bucket(): void
    {
        $r = (new AtlasSelfConstructionReceiptFactIndex)->project([
            'task_receipts' => [['id' => 't-1', 'hash' => 'h', 'ts' => 't', 'task_packet_id' => 'pkt-1']],
            'verification_receipts' => [['id' => 'v-1', 'hash' => 'h', 'ts' => 't', 'task_packet_id' => 'pkt-1']],
        ]);

        $this->assertCount(2, $r['by_task']['pkt-1']);
        $kinds = array_column($r['by_task']['pkt-1'], 'kind');
        $this->assertContains('task_receipts', $kinds);
        $this->assertContains('verification_receipts', $kinds);
    }

    public function test_row_missing_all_join_fields_is_a_missing_evidence_gap(): void
    {
        $r = (new AtlasSelfConstructionReceiptFactIndex)->project([
            'lease_receipts' => [['id' => 'l-1', 'hash' => 'h', 'ts' => 't']],
        ]);

        $this->assertContains('lease_receipts:l-1', $r['missing_evidence_gaps']);
        $this->assertSame([], $r['by_task']);
    }

    public function test_row_with_one_join_field_is_not_flagged_a_missing_evidence_gap(): void
    {
        $r = (new AtlasSelfConstructionReceiptFactIndex)->project([
            'lease_receipts' => [['id' => 'l-1', 'hash' => 'h', 'ts' => 't', 'worker_id' => 'worker-x']],
        ]);

        $this->assertNotContains('lease_receipts:l-1', $r['missing_evidence_gaps']);
        $this->assertArrayHasKey('worker-x', $r['by_worker']);
    }

    public function test_join_indexes_are_sorted_byte_stably(): void
    {
        $r = (new AtlasSelfConstructionReceiptFactIndex)->project([
            'task_receipts' => [
                ['id' => 't-1', 'hash' => 'h', 'ts' => 't', 'task_packet_id' => 'zzz-pkt'],
                ['id' => 't-2', 'hash' => 'h', 'ts' => 't', 'task_packet_id' => 'aaa-pkt'],
            ],
        ]);

        $this->assertSame(['aaa-pkt', 'zzz-pkt'], array_keys($r['by_task']));
    }

    public function test_join_output_is_deterministic_across_two_calls(): void
    {
        $facts = [
            'task_receipts' => [['id' => 't-1', 'hash' => 'h', 'ts' => 't', 'task_packet_id' => 'pkt-1', 'worker_id' => 'w-a']],
            'merge_receipts' => [['id' => 'm-1', 'hash' => 'h', 'ts' => 't', 'commit_sha' => 'sha-1']],
        ];
        $index = new AtlasSelfConstructionReceiptFactIndex;
        $a = $index->project($facts);
        $b = $index->project($facts);

        $this->assertSame(json_encode($a), json_encode($b));
    }
}
