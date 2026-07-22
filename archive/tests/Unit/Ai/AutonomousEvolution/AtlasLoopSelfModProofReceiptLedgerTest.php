<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\SelfMod\AtlasLoopSelfModProofReceiptLedger;
use RuntimeException;
use Tests\TestCase;

/**
 * Proves the SelfMod proof-receipt ledger: append-only (two record() calls land two lines, neither
 * overwriting the other); byProofStatus filters correctly; truncate() always throws with 'append-only';
 * invalid proof_status is refused.
 */
final class AtlasLoopSelfModProofReceiptLedgerTest extends TestCase
{
    private string $ledgerPath;

    private AtlasLoopSelfModProofReceiptLedger $ledger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ledgerPath = sys_get_temp_dir().'/atlas_selfmod_'.bin2hex(random_bytes(6)).'.jsonl';
        $this->ledger = new AtlasLoopSelfModProofReceiptLedger($this->ledgerPath);
    }

    protected function tearDown(): void
    {
        @unlink($this->ledgerPath);
        parent::tearDown();
    }

    private function approved(array $overrides = []): array
    {
        return array_merge([
            'target_files' => ['app/Foo.php'],
            'edit_classification' => ['kind' => 'helper'],
            'invariant_survival_report' => ['all' => true],
            'proof_status' => AtlasLoopSelfModProofReceiptLedger::PROOF_APPROVED,
            'frozen_judge_decision' => ['pass' => true],
            'cert_diff_decision' => ['pass' => true],
            'commit_sha' => 'abc1234',
        ], $overrides);
    }

    public function test_two_records_produce_two_jsonl_lines_neither_overwriting_the_other(): void
    {
        $a = $this->ledger->record($this->approved());
        $b = $this->ledger->record($this->approved());

        $lines = file($this->ledgerPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $this->assertCount(2, $lines, 'two appends ⇒ two lines');

        $ids = array_map(static fn (string $l): string => (string) json_decode($l, true)['receipt_id'], $lines);
        $this->assertContains($a['receipt_id'], $ids);
        $this->assertContains($b['receipt_id'], $ids);
        $this->assertNotSame($a['receipt_id'], $b['receipt_id']);
    }

    public function test_by_proof_status_filters_only_rejected_in_append_order(): void
    {
        $this->ledger->record($this->approved());
        $this->ledger->record($this->approved(['proof_status' => AtlasLoopSelfModProofReceiptLedger::PROOF_REJECTED, 'commit_sha' => null]));
        $this->ledger->record($this->approved(['proof_status' => AtlasLoopSelfModProofReceiptLedger::PROOF_REJECTED, 'commit_sha' => null, 'target_files' => ['app/Bar.php']]));
        $this->ledger->record($this->approved());

        $rejected = $this->ledger->byProofStatus(AtlasLoopSelfModProofReceiptLedger::PROOF_REJECTED);
        $this->assertCount(2, $rejected);
        // Both rejected, second one targets Bar.php → arrives after the first in append order.
        $this->assertSame(['app/Foo.php'], $rejected[0]['target_files']);
        $this->assertSame(['app/Bar.php'], $rejected[1]['target_files']);
    }

    public function test_by_receipt_id_returns_the_matching_record(): void
    {
        $a = $this->ledger->record($this->approved());
        $this->ledger->record($this->approved());

        $hit = $this->ledger->byReceiptId($a['receipt_id']);
        $this->assertNotNull($hit);
        $this->assertSame($a['receipt_id'], $hit['receipt_id']);
        $this->assertNull($this->ledger->byReceiptId('bogus-id'));
    }

    public function test_by_target_file_filters_correctly(): void
    {
        $this->ledger->record($this->approved(['target_files' => ['app/Foo.php']]));
        $this->ledger->record($this->approved(['target_files' => ['app/Bar.php', 'app/Baz.php']]));

        $this->assertCount(1, $this->ledger->byTargetFile('app/Foo.php'));
        $this->assertCount(1, $this->ledger->byTargetFile('app/Baz.php'));
        $this->assertCount(0, $this->ledger->byTargetFile('app/Nonexistent.php'));
    }

    public function test_truncate_always_throws_with_append_only_message(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/append-only/');
        $this->ledger->truncate();
    }

    public function test_invalid_proof_status_is_refused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->ledger->record($this->approved(['proof_status' => 'BOGUS']));
    }

    public function test_head_tail_since_are_deterministic(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->ledger->record($this->approved(['target_files' => ['file-'.$i.'.php']]));
        }
        $this->assertCount(2, $this->ledger->head(2));
        $this->assertCount(2, $this->ledger->tail(2));
        $this->assertSame('file-4.php', $this->ledger->tail(2)[0]['target_files'][0]);
        $this->assertSame('file-5.php', $this->ledger->tail(2)[1]['target_files'][0]);
    }
}
