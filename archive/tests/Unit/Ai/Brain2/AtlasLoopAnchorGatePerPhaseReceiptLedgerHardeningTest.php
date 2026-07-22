<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Brain2;

use App\Services\Ai\AutonomousEvolution\SymbolicAnchoring\PerPhase\AtlasLoopAnchorGatePerPhaseReceiptLedger;
use App\Services\Ai\AutonomousEvolution\SymbolicAnchoring\PerPhase\EnforcementVerdict;
use PHPUnit\Framework\TestCase;

/**
 * Proves AtlasLoopAnchorGatePerPhaseReceiptLedger returns an empty list when limit is zero.
 */
final class AtlasLoopAnchorGatePerPhaseReceiptLedgerHardeningTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . '/atlas_anchor_ledger_' . uniqid();
        mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->recursiveRemove($this->tmpDir);
        parent::tearDown();
    }

    private function recursiveRemove(string $dir): void
    {
        foreach (glob($dir . '/*') as $file) {
            is_dir($file) ? $this->recursiveRemove($file) : unlink($file);
        }
        @rmdir($dir);
    }

    private function createLedger(): AtlasLoopAnchorGatePerPhaseReceiptLedger
    {
        return new AtlasLoopAnchorGatePerPhaseReceiptLedger($this->tmpDir);
    }

    public function test_zero_limit_returns_empty(): void
    {
        $ledger = $this->createLedger();

        $ledger->recordVerdict('cycle-1', 'test-phase', EnforcementVerdict::allow(7.0, 6.0), ['t' => 1], '2026-06-25T00:00:00Z');

        $result = $ledger->recentForPhase('test-phase', 0);

        $this->assertSame([], $result);
    }

    public function test_negative_limit_returns_empty(): void
    {
        $ledger = $this->createLedger();

        $ledger->recordVerdict('cycle-1', 'test-phase', EnforcementVerdict::allow(7.0, 6.0), ['t' => 1], '2026-06-25T00:00:00Z');

        $result = $ledger->recentForPhase('test-phase', -1);

        $this->assertSame([], $result);
    }

    public function test_positive_limit_returns_receipts(): void
    {
        $ledger = $this->createLedger();

        $ledger->recordVerdict('cycle-1', 'test-phase', EnforcementVerdict::allow(7.0, 6.0), ['t' => 1], '2026-06-25T00:00:00Z');

        $result = $ledger->recentForPhase('test-phase', 1);

        $this->assertCount(1, $result);
    }
}
