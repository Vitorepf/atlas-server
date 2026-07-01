<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\VerificationCourt;

use App\Services\Ai\SelfConstruction\VerificationCourt\AtlasVerificationCourtFalseGreenDetector;
use App\Services\Ai\SelfConstruction\VerificationCourt\AtlasVerificationCourtVerdictLedger;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Proves AtlasVerificationCourtVerdictLedger: valid payload appends one canonical row; duplicate
 * verdict_hash returns already_recorded without a second row; invalid verdict throws; missing
 * replay_plan_hash throws; byTaskPacketId filters rows for that packet.
 */
final class AtlasVerificationCourtVerdictLedgerTest extends TestCase
{
    private string $ledgerPath;

    private AtlasVerificationCourtVerdictLedger $ledger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ledgerPath = sys_get_temp_dir().'/atlas_court_verdicts_'.bin2hex(random_bytes(6)).'.jsonl';
        $this->ledger = new AtlasVerificationCourtVerdictLedger($this->ledgerPath);
    }

    protected function tearDown(): void
    {
        if (is_file($this->ledgerPath)) {
            unlink($this->ledgerPath);
        }
        parent::tearDown();
    }

    private function payload(string $taskId = 'pkt-1'): array
    {
        return [
            'task_packet_id' => $taskId,
            'evidence_hash' => 'evh-1',
            'replay_plan_hash' => 'plan-h-1',
            'verdict' => AtlasVerificationCourtFalseGreenDetector::VERDICT_PASSED,
            'reasons' => [],
            'replay_outcome_hash' => 'out-h-1',
            'decided_at' => '2026-06-25T00:00:00Z',
        ];
    }

    public function test_valid_payload_appends_one_canonical_row(): void
    {
        $res = $this->ledger->append($this->payload());
        $this->assertSame(AtlasVerificationCourtVerdictLedger::STATUS_OK, $res['status']);
        $this->assertSame(64, strlen($res['row']['verdict_hash']));
        $this->assertCount(1, file($this->ledgerPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
    }

    public function test_worker_feed_evidence_records_reason_codes_and_snapshot_hash(): void
    {
        $payload = $this->payload() + [
            'worker_feed_reason_codes' => ['claimable_per_active_worker_low', 'no_claimable_task_recent'],
            'evidence_snapshot' => ['claimable_depth' => 3, 'active_worker_count' => 6],
        ];

        $res = $this->ledger->append($payload);

        $this->assertSame(AtlasVerificationCourtVerdictLedger::STATUS_OK, $res['status']);
        $this->assertSame(
            ['claimable_per_active_worker_low', 'no_claimable_task_recent'],
            $res['row']['worker_feed_reason_codes'],
        );
        $this->assertSame(64, strlen($res['row']['evidence_snapshot_hash']));
    }

    public function test_verdict_without_worker_feed_evidence_has_empty_codes_and_null_snapshot_hash(): void
    {
        $res = $this->ledger->append($this->payload());

        $this->assertSame([], $res['row']['worker_feed_reason_codes']);
        $this->assertNull($res['row']['evidence_snapshot_hash']);
    }

    public function test_identical_verdict_inputs_produce_the_same_verdict_hash(): void
    {
        $payload = $this->payload() + [
            'worker_feed_reason_codes' => ['no_claimable_task_recent'],
            'evidence_snapshot' => ['claimable_depth' => 1],
        ];

        $res1 = $this->ledger->append($payload);
        $ledgerPath2 = sys_get_temp_dir().'/atlas_court_verdicts_'.bin2hex(random_bytes(6)).'.jsonl';
        $ledger2 = new AtlasVerificationCourtVerdictLedger($ledgerPath2);
        $res2 = $ledger2->append($payload);

        $this->assertSame($res1['row']['verdict_hash'], $res2['row']['verdict_hash']);

        unlink($ledgerPath2);
    }

    public function test_duplicate_verdict_hash_is_idempotent_no_second_row(): void
    {
        $this->ledger->append($this->payload());
        $size = filesize($this->ledgerPath);
        $res2 = $this->ledger->append($this->payload());
        $this->assertSame(AtlasVerificationCourtVerdictLedger::STATUS_ALREADY, $res2['status']);
        $this->assertSame($size, filesize($this->ledgerPath));
    }

    public function test_invalid_verdict_throws(): void
    {
        $p = $this->payload();
        $p['verdict'] = 'ok_i_guess';
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/invalid verdict/');
        $this->ledger->append($p);
    }

    public function test_missing_replay_plan_hash_throws(): void
    {
        $p = $this->payload();
        $p['replay_plan_hash'] = '';
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/missing replay_plan_hash/');
        $this->ledger->append($p);
    }

    public function test_failed_verdict_without_diagnostic_reasons_throws(): void
    {
        $p = $this->payload();
        $p['verdict'] = AtlasVerificationCourtFalseGreenDetector::VERDICT_FAILED;
        $p['reasons'] = [];
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/non-empty diagnostic reasons/');
        $this->ledger->append($p);
    }

    public function test_blocked_verdict_without_diagnostic_reasons_throws(): void
    {
        $p = $this->payload();
        $p['verdict'] = AtlasVerificationCourtFalseGreenDetector::VERDICT_BLOCKED;
        $p['reasons'] = [];
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/non-empty diagnostic reasons/');
        $this->ledger->append($p);
    }

    public function test_passed_verdict_with_empty_reasons_is_accepted(): void
    {
        $p = $this->payload();
        $p['verdict'] = AtlasVerificationCourtFalseGreenDetector::VERDICT_PASSED;
        $p['reasons'] = [];
        $res = $this->ledger->append($p);
        $this->assertSame(AtlasVerificationCourtVerdictLedger::STATUS_OK, $res['status']);
    }

    public function test_failed_verdict_with_non_empty_reasons_is_accepted(): void
    {
        $p = $this->payload();
        $p['verdict'] = AtlasVerificationCourtFalseGreenDetector::VERDICT_FAILED;
        $p['reasons'] = ['test_suite_red'];
        $res = $this->ledger->append($p);
        $this->assertSame(AtlasVerificationCourtVerdictLedger::STATUS_OK, $res['status']);
    }

    public function test_decided_at_must_be_canonical_utc_iso8601(): void
    {
        $p = $this->payload();

        // Local timezone offset is not UTC — must throw
        $p['decided_at'] = '2026-06-25T00:00:00+03:00';
        try {
            $this->ledger->append($p);
            $this->fail('non-UTC decided_at must throw');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('decided_at', $e->getMessage());
        }

        // Entirely missing timestamp
        $p['decided_at'] = 'not-a-date';
        try {
            $this->ledger->append($p);
            $this->fail('non-ISO decided_at must throw');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('decided_at', $e->getMessage());
        }

        // Both valid UTC forms should be accepted
        $pZ = array_merge($this->payload(), ['decided_at' => '2026-06-25T12:00:00Z']);
        $this->assertSame(AtlasVerificationCourtVerdictLedger::STATUS_OK, $this->ledger->append($pZ)['status']);

        $pUtc = array_merge($this->payload(), ['decided_at' => '2026-06-25T12:00:01+00:00', 'replay_outcome_hash' => 'distinct']);
        $this->assertSame(AtlasVerificationCourtVerdictLedger::STATUS_OK, $this->ledger->append($pUtc)['status']);
    }

    public function test_by_task_packet_id_filters_to_that_packet(): void
    {
        $this->ledger->append($this->payload('pkt-a'));
        $this->ledger->append($this->payload('pkt-b'));
        $this->ledger->append(array_merge($this->payload('pkt-a'), ['replay_outcome_hash' => 'different-hash']));

        $rows = $this->ledger->byTaskPacketId('pkt-a');
        $this->assertCount(2, $rows);
        foreach ($rows as $r) {
            $this->assertSame('pkt-a', $r['task_packet_id']);
        }
    }

    // ── chain continuity ──────────────────────────────────────────────────────

    public function test_first_row_has_null_previous_verdict_hash(): void
    {
        $row = $this->ledger->append($this->payload())['row'];
        $this->assertArrayHasKey('previous_verdict_hash', $row);
        $this->assertNull($row['previous_verdict_hash']);
    }

    public function test_first_row_has_deterministic_ledger_chain_hash(): void
    {
        $path2 = sys_get_temp_dir().'/atlas_court_verdicts_dup_'.bin2hex(random_bytes(6)).'.jsonl';
        $ledger2 = new AtlasVerificationCourtVerdictLedger($path2);

        $row1 = $this->ledger->append($this->payload())['row'];
        $row2 = $ledger2->append($this->payload())['row'];

        $this->assertSame(64, strlen($row1['ledger_chain_hash']));
        $this->assertSame($row1['ledger_chain_hash'], $row2['ledger_chain_hash']);

        if (is_file($path2)) {
            unlink($path2);
        }
    }

    public function test_second_row_links_previous_verdict_hash_to_first_row(): void
    {
        $r1 = $this->ledger->append($this->payload())['row'];
        $r2 = $this->ledger->append(array_merge($this->payload(), ['replay_outcome_hash' => 'out-h-2']))['row'];

        $this->assertSame($r1['verdict_hash'], $r2['previous_verdict_hash']);
    }

    public function test_second_row_has_distinct_ledger_chain_hash(): void
    {
        $r1 = $this->ledger->append($this->payload())['row'];
        $r2 = $this->ledger->append(array_merge($this->payload(), ['replay_outcome_hash' => 'out-h-2']))['row'];

        $this->assertSame(64, strlen($r2['ledger_chain_hash']));
        $this->assertNotSame($r1['ledger_chain_hash'], $r2['ledger_chain_hash']);
    }

    public function test_duplicate_does_not_append_and_does_not_break_chain(): void
    {
        $r1 = $this->ledger->append($this->payload())['row'];
        $dup = $this->ledger->append($this->payload());
        $this->assertSame(AtlasVerificationCourtVerdictLedger::STATUS_ALREADY, $dup['status']);

        $r3 = $this->ledger->append(array_merge($this->payload(), ['replay_outcome_hash' => 'out-h-3']))['row'];
        $this->assertSame($r1['verdict_hash'], $r3['previous_verdict_hash']);
    }

    // ── integrityScan ─────────────────────────────────────────────────────────

    private function rewriteLedgerRows(array $rows): void
    {
        $lines = array_map(
            static fn (array $row): string => (string) json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            $rows,
        );
        file_put_contents($this->ledgerPath, implode("\n", $lines).($lines === [] ? '' : "\n"));
    }

    public function test_integrity_scan_ok_for_untampered_chain(): void
    {
        $this->ledger->append($this->payload());
        $this->ledger->append(array_merge($this->payload(), ['replay_outcome_hash' => 'out-h-2']));
        $this->ledger->append(array_merge($this->payload(), ['replay_outcome_hash' => 'out-h-3']));

        $result = $this->ledger->integrityScan();

        $this->assertTrue($result['ok']);
        $this->assertNull($result['tamper_reason']);
        $this->assertNull($result['tampered_row_index']);
        $this->assertSame(3, $result['checked_row_count']);
    }

    public function test_integrity_scan_empty_ledger_is_ok(): void
    {
        $result = $this->ledger->integrityScan();

        $this->assertTrue($result['ok']);
        $this->assertSame(0, $result['checked_row_count']);
    }

    public function test_integrity_scan_detects_row_content_tampering(): void
    {
        $this->ledger->append($this->payload());
        $rows = $this->ledger->all();
        $rows[0]['verdict'] = AtlasVerificationCourtFalseGreenDetector::VERDICT_FAILED;
        $this->rewriteLedgerRows($rows);

        $result = $this->ledger->integrityScan();

        $this->assertFalse($result['ok']);
        $this->assertSame('row_content_hash_mismatch', $result['tamper_reason']);
        $this->assertSame(0, $result['tampered_row_index']);
    }

    public function test_integrity_scan_detects_reordered_rows(): void
    {
        $this->ledger->append($this->payload());
        $this->ledger->append(array_merge($this->payload(), ['replay_outcome_hash' => 'out-h-2']));
        $rows = $this->ledger->all();
        $this->rewriteLedgerRows([$rows[1], $rows[0]]);

        $result = $this->ledger->integrityScan();

        $this->assertFalse($result['ok']);
        $this->assertContains($result['tamper_reason'], [
            'first_row_previous_verdict_hash_must_be_null',
            'previous_verdict_hash_missing_or_reordered',
        ]);
        $this->assertSame(0, $result['tampered_row_index']);
    }

    public function test_integrity_scan_detects_missing_previous_hash(): void
    {
        $this->ledger->append($this->payload());
        $this->ledger->append(array_merge($this->payload(), ['replay_outcome_hash' => 'out-h-2']));
        $rows = $this->ledger->all();
        unset($rows[1]['previous_verdict_hash']);
        $this->rewriteLedgerRows($rows);

        $result = $this->ledger->integrityScan();

        $this->assertFalse($result['ok']);
        $this->assertSame('previous_verdict_hash_missing_or_reordered', $result['tamper_reason']);
        $this->assertSame(1, $result['tampered_row_index']);
    }

    public function test_integrity_scan_detects_forged_chain_hash(): void
    {
        $this->ledger->append($this->payload());
        $rows = $this->ledger->all();
        $rows[0]['ledger_chain_hash'] = str_repeat('a', 64);
        $this->rewriteLedgerRows($rows);

        $result = $this->ledger->integrityScan();

        $this->assertFalse($result['ok']);
        $this->assertSame('ledger_chain_hash_mismatch', $result['tamper_reason']);
    }
}
