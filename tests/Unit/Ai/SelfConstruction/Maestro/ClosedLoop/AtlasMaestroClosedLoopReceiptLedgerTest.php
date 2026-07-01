<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Maestro\ClosedLoop;

use App\Services\Ai\SelfConstruction\Maestro\ClosedLoop\AtlasMaestroClosedLoopReceiptLedger;
use App\Services\Ai\SelfConstruction\Maestro\ClosedLoop\AtlasMaestroReplenisherFeedback;
use Tests\TestCase;

final class AtlasMaestroClosedLoopReceiptLedgerTest extends TestCase
{
    private string $receiptPath;

    private string $shapePath;

    protected function setUp(): void
    {
        parent::setUp();
        $suffix = bin2hex(random_bytes(5));
        $this->receiptPath = sys_get_temp_dir()."/atlas-maestro-closed-loop-receipts-{$suffix}.jsonl";
        $this->shapePath = sys_get_temp_dir()."/atlas-maestro-closed-loop-shape-{$suffix}.jsonl";
        file_put_contents($this->shapePath, '{"task_packet_id":"packet-secret","payload":"must_not_leak"}'.PHP_EOL);
    }

    protected function tearDown(): void
    {
        foreach ([$this->receiptPath, $this->receiptPath.'.lock', $this->shapePath] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        parent::tearDown();
    }

    public function test_record_cycle_appends_provider_safe_receipt_without_raw_feedback_or_packet_payload(): void
    {
        $ledger = $this->ledger();

        $entry = $ledger->recordCycle([
            'feedback_block' => 'origin_kind=orphan: 14 delivered / 22 total',
            'mined_bucket_count' => 1,
            'guarded_pass_or_reject' => 'pass',
            'replenisher_consumed' => true,
            'flag_enabled' => true,
            'task_packet_id' => 'packet-secret',
        ]);

        $line = trim((string) file_get_contents($this->receiptPath));
        $row = json_decode($line, true);

        $this->assertSame($entry, $row);
        $this->assertArrayHasKey('ledger_snapshot_hash', $row);
        $this->assertArrayHasKey('feedback_block_sha256', $row);
        $this->assertSame(1, $row['mined_bucket_count']);
        $this->assertSame('pass', $row['guarded_pass_or_reject']);
        $this->assertTrue($row['flag_enabled']);
        $this->assertStringNotContainsString('origin_kind=orphan', $line);
        $this->assertStringNotContainsString('packet-secret', $line);
        $this->assertArrayNotHasKey('task_packet_id', $row);
    }

    public function test_replenisher_records_one_receipt_per_invocation_with_identical_hashes_for_identical_inputs(): void
    {
        config(['atlas.maestro.closed_loop.feedback_enabled' => true]);
        $feedback = new AtlasMaestroReplenisherFeedback($this->miner(), $this->ledger());

        $this->assertNotSame('', $feedback->renderFactsBlock());
        $this->assertNotSame('', $feedback->renderFactsBlock());

        $rows = iterator_to_array($this->ledger()->stream());
        $this->assertCount(2, $rows);
        $this->assertSame($rows[0]['ledger_snapshot_hash'], $rows[1]['ledger_snapshot_hash']);
        $this->assertSame($rows[0]['feedback_block_sha256'], $rows[1]['feedback_block_sha256']);
        $this->assertSame(['pass', 'pass'], array_column($rows, 'guarded_pass_or_reject'));
        $this->assertSame([true, true], array_column($rows, 'replenisher_consumed'));
    }

    public function test_replenisher_records_reject_receipt_when_flag_disabled(): void
    {
        config(['atlas.maestro.closed_loop.feedback_enabled' => false]);
        $feedback = new AtlasMaestroReplenisherFeedback($this->miner(), $this->ledger());

        $this->assertSame('', $feedback->renderFactsBlock());

        $rows = iterator_to_array($this->ledger()->stream());
        $this->assertCount(1, $rows);
        $this->assertSame('reject', $rows[0]['guarded_pass_or_reject']);
        $this->assertFalse($rows[0]['flag_enabled']);
        $this->assertFalse($rows[0]['replenisher_consumed']);
    }

    public function test_two_cycles_with_same_payload_produce_deterministic_hashes_and_ordered_stream(): void
    {
        $ledger = $this->ledger();
        $payload = [
            'feedback_block' => 'origin_kind=orphan: 7 delivered / 10 total',
            'mined_bucket_count' => 2,
            'guarded_pass_or_reject' => 'pass',
            'promoted_rules' => ['rule-A', 'rule-B'],
            'replenisher_consumed' => true,
            'flag_enabled' => true,
            'timestamp' => '2026-06-24T07:00:00+00:00',
        ];

        $first = $ledger->recordCycle($payload);
        $second = $ledger->recordCycle($payload);

        $this->assertSame($first['feedback_block_sha256'], $second['feedback_block_sha256']);
        $this->assertSame($first['ledger_snapshot_hash'], $second['ledger_snapshot_hash']);
        $this->assertSame($first['entry_hash'], $second['entry_hash']);
        $this->assertSame(['rule-A', 'rule-B'], $first['promoted_rules']);

        // Stream preserves insertion order
        $rows = iterator_to_array($ledger->stream());
        $this->assertCount(2, $rows);
        $this->assertSame($first['entry_hash'], $rows[0]['entry_hash']);
        $this->assertSame($second['entry_hash'], $rows[1]['entry_hash']);
    }

    public function test_altering_audited_fields_makes_entry_hash_invalid(): void
    {
        $ledger = $this->ledger();
        $ledger->recordCycle([
            'feedback_block' => 'some feedback',
            'guarded_pass_or_reject' => 'pass',
            'promoted_rules' => ['rule-X'],
            'timestamp' => '2026-06-24T08:00:00+00:00',
        ]);
        $original = iterator_to_array($ledger->stream())[0];

        $this->assertTrue($ledger->verifyEntry($original));

        $t1 = $original;
        $t1['feedback_block_sha256'] = str_repeat('a', 64);
        $this->assertFalse($ledger->verifyEntry($t1), 'tampered feedback_block_sha256 must fail verification');

        $t2 = $original;
        $t2['promoted_rules'] = ['injected'];
        $this->assertFalse($ledger->verifyEntry($t2), 'tampered promoted_rules must fail verification');

        $t3 = $original;
        $t3['guarded_pass_or_reject'] = 'reject';
        $this->assertFalse($ledger->verifyEntry($t3), 'tampered guarded_pass_or_reject must fail verification');
    }

    // ── AC: outcome, recommendation, quality flags, previous hash linkage ──────

    public function test_record_cycle_includes_outcome_recommendation_and_quality_flags(): void
    {
        $ledger = $this->ledger();

        $entry = $ledger->recordCycle([
            'guarded_pass_or_reject' => 'pass',
            'outcome' => 'delivered_two_rules',
            'next_action_recommendation' => 'raise_orphan_dimension_floor',
            'quality_flags' => ['low_support_bucket', 'stale_shape_ledger'],
        ]);

        $this->assertSame('delivered_two_rules', $entry['outcome']);
        $this->assertSame('raise_orphan_dimension_floor', $entry['next_action_recommendation']);
        $this->assertSame(['low_support_bucket', 'stale_shape_ledger'], $entry['quality_flags']);
    }

    public function test_record_cycle_defaults_outcome_from_guarded_pass_or_reject_when_omitted(): void
    {
        $ledger = $this->ledger();

        $pass = $ledger->recordCycle(['guarded_pass_or_reject' => 'pass']);
        $reject = $ledger->recordCycle(['guarded_pass_or_reject' => 'reject']);

        $this->assertSame('completed', $pass['outcome']);
        $this->assertSame('rejected', $reject['outcome']);
    }

    public function test_first_entry_has_empty_previous_hash_and_sequence_one(): void
    {
        $entry = $this->ledger()->recordCycle(['guarded_pass_or_reject' => 'pass']);

        $this->assertSame('', $entry['previous_hash']);
        $this->assertSame(1, $entry['sequence']);
    }

    public function test_second_entry_links_previous_hash_to_first_entry_hash(): void
    {
        $ledger = $this->ledger();
        $first = $ledger->recordCycle(['guarded_pass_or_reject' => 'pass', 'feedback_block' => 'a']);
        $second = $ledger->recordCycle(['guarded_pass_or_reject' => 'pass', 'feedback_block' => 'b']);

        $this->assertSame($first['entry_hash'], $second['previous_hash']);
        $this->assertSame(2, $second['sequence']);
    }

    public function test_identical_payload_still_produces_identical_entry_hash_despite_chain_position(): void
    {
        // Regression guard: previous_hash/sequence must NOT leak into entry_hash's own computation.
        $ledger = $this->ledger();
        $payload = ['guarded_pass_or_reject' => 'pass', 'feedback_block' => 'same'];

        $first = $ledger->recordCycle($payload);
        $second = $ledger->recordCycle($payload);

        $this->assertNotSame($first['previous_hash'], $second['previous_hash'], 'chain position differs');
        $this->assertSame($first['entry_hash'], $second['entry_hash'], 'audited content is identical');
    }

    // ── AC: verifyEntry chain validation and tamper detection ──────────────────

    public function test_verify_chain_ok_for_untampered_multi_entry_ledger(): void
    {
        $ledger = $this->ledger();
        $ledger->recordCycle(['guarded_pass_or_reject' => 'pass', 'feedback_block' => 'a']);
        $ledger->recordCycle(['guarded_pass_or_reject' => 'pass', 'feedback_block' => 'b']);
        $ledger->recordCycle(['guarded_pass_or_reject' => 'reject', 'feedback_block' => 'c']);

        $verdict = $ledger->verifyChain();

        $this->assertTrue($verdict['ok']);
        $this->assertSame(3, $verdict['entries_checked']);
        $this->assertNull($verdict['broken_at_sequence']);
    }

    public function test_verify_chain_detects_tampered_previous_hash_linkage(): void
    {
        $ledger = $this->ledger();
        $ledger->recordCycle(['guarded_pass_or_reject' => 'pass', 'feedback_block' => 'a']);
        $ledger->recordCycle(['guarded_pass_or_reject' => 'pass', 'feedback_block' => 'b']);

        $rows = iterator_to_array($ledger->stream());
        $rows[1]['previous_hash'] = str_repeat('f', 64);
        file_put_contents(
            $this->receiptPath,
            implode(PHP_EOL, array_map(fn (array $r) => json_encode($r, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $rows)).PHP_EOL,
        );

        $verdict = $ledger->verifyChain();

        $this->assertFalse($verdict['ok']);
        $this->assertSame('previous_hash_mismatch', $verdict['reason']);
        $this->assertSame(2, $verdict['broken_at_sequence']);
    }

    public function test_verify_chain_detects_tampered_entry_content(): void
    {
        $ledger = $this->ledger();
        $ledger->recordCycle(['guarded_pass_or_reject' => 'pass', 'feedback_block' => 'a']);

        $rows = iterator_to_array($ledger->stream());
        $rows[0]['outcome'] = 'injected_fake_outcome';
        file_put_contents(
            $this->receiptPath,
            json_encode($rows[0], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL,
        );

        $verdict = $ledger->verifyChain();

        $this->assertFalse($verdict['ok']);
        $this->assertSame('entry_hash_mismatch', $verdict['reason']);
    }

    // ── AC: summary projection for the latest cycle ─────────────────────────────

    public function test_latest_cycle_summary_is_null_for_empty_ledger(): void
    {
        $this->assertNull($this->ledger()->latestCycleSummary());
    }

    public function test_latest_cycle_summary_reflects_the_most_recent_entry_only(): void
    {
        $ledger = $this->ledger();
        $ledger->recordCycle(['guarded_pass_or_reject' => 'pass', 'outcome' => 'first', 'feedback_block' => 'a']);
        $ledger->recordCycle([
            'guarded_pass_or_reject' => 'reject',
            'outcome' => 'second',
            'next_action_recommendation' => 'tighten_guard',
            'quality_flags' => ['drift_detected'],
            'feedback_block' => 'b',
        ]);

        $summary = $ledger->latestCycleSummary();

        $this->assertSame('second', $summary['outcome']);
        $this->assertSame('reject', $summary['guarded_pass_or_reject']);
        $this->assertSame('tighten_guard', $summary['next_action_recommendation']);
        $this->assertSame(['drift_detected'], $summary['quality_flags']);
        $this->assertSame(2, $summary['sequence']);
    }

    private function ledger(): AtlasMaestroClosedLoopReceiptLedger
    {
        return new AtlasMaestroClosedLoopReceiptLedger($this->receiptPath, $this->shapePath);
    }

    private function miner(): object
    {
        return new class
        {
            public function mine(): array
            {
                return [
                    'origin_kind' => [
                        'orphan' => [
                            'dimension' => 'origin_kind',
                            'bucket' => 'orphan',
                            'delivered' => 14,
                            'total' => 22,
                            'insufficient_support' => false,
                            'delivery_rate' => 14 / 22,
                        ],
                    ],
                ];
            }
        };
    }
}
