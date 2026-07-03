<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Brain2;

use PHPUnit\Framework\TestCase;
use App\Services\Ai\SelfConstruction\Maestro\ProviderNegotiation\AtlasMaestroProviderBidReceiptLedger;

final class AtlasMaestroProviderBidReceiptLedgerHardeningTest extends TestCase
{
    protected function tearDown(): void
    {
        AtlasMaestroProviderBidReceiptLedger::setRootForTesting(null);
        parent::tearDown();
    }

    /**
     * recallOutcome must throw on a corrupt receipt line instead of
     * silently skipping it and returning null (no-match).
     */
    public function test_corrupt_line_throws(): void
    {
        $tmpDir = sys_get_temp_dir().'/bid-ledger-'.bin2hex(random_bytes(4));
        mkdir($tmpDir, 0o755, true);

        AtlasMaestroProviderBidReceiptLedger::setRootForTesting($tmpDir);

        $outcomesPath = $tmpDir.'/outcomes.jsonl';
        // Corrupt line FIRST, then the valid matching line.
        $content = '{corrupt json!!!'. "\n";
        $content .= json_encode(['task_id' => 'task-1', 'outcome' => 'success', 'recorded_at_iso' => '2026-01-01T00:00:00Z'])."\n";
        file_put_contents($outcomesPath, $content);

        $ledger = new AtlasMaestroProviderBidReceiptLedger();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('corrupt outcome ledger line');

        $ledger->recallOutcome('task-1');

        @unlink($outcomesPath);
        @rmdir($tmpDir);
    }

    /**
     * A missing outcomes file returns null (no error).
     */
    public function test_missing_file_returns_null(): void
    {
        $tmpDir = sys_get_temp_dir().'/bid-ledger-'.bin2hex(random_bytes(4));
        mkdir($tmpDir, 0o755, true);

        AtlasMaestroProviderBidReceiptLedger::setRootForTesting($tmpDir);

        $ledger = new AtlasMaestroProviderBidReceiptLedger();

        $this->assertNull($ledger->recallOutcome('nonexistent'));

        @rmdir($tmpDir);
    }

    /**
     * Valid outcomes are returned correctly.
     */
    public function test_valid_outcome_returns(): void
    {
        $tmpDir = sys_get_temp_dir().'/bid-ledger-'.bin2hex(random_bytes(4));
        mkdir($tmpDir, 0o755, true);

        AtlasMaestroProviderBidReceiptLedger::setRootForTesting($tmpDir);

        $outcomesPath = $tmpDir.'/outcomes.jsonl';
        $content = json_encode(['task_id' => 'task-1', 'outcome' => 'success', 'recorded_at_iso' => '2026-01-01T00:00:00Z'])."\n";
        $content .= json_encode(['task_id' => 'task-2', 'outcome' => 'failure', 'recorded_at_iso' => '2026-01-01T00:00:00Z'])."\n";
        file_put_contents($outcomesPath, $content);

        $ledger = new AtlasMaestroProviderBidReceiptLedger();

        $this->assertSame('success', $ledger->recallOutcome('task-1'));
        $this->assertSame('failure', $ledger->recallOutcome('task-2'));
        $this->assertNull($ledger->recallOutcome('task-99'));

        @unlink($outcomesPath);
        @rmdir($tmpDir);
    }
}
