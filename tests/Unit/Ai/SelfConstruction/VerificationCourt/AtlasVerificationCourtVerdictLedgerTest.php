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
        @unlink($this->ledgerPath);
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
}
