<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\SymbolicAnchoring\PerPhase;

use App\Services\Ai\AutonomousEvolution\SymbolicAnchoring\PerPhase\AtlasLoopAnchorGatePerPhaseReceiptLedger;
use App\Services\Ai\AutonomousEvolution\SymbolicAnchoring\PerPhase\EnforcementVerdict;
use Tests\TestCase;

class AtlasLoopAnchorGatePerPhaseReceiptLedgerTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/atlas-anchor-perphase-ledger-'.bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->root)) {
            foreach (glob($this->root.'/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($this->root);
        }
        parent::tearDown();
    }

    public function test_record_verdict_appends_one_jsonl_line_with_required_fields(): void
    {
        $ledger = new AtlasLoopAnchorGatePerPhaseReceiptLedger($this->root, enabled: true);
        $verdict = EnforcementVerdict::refuse(EnforcementVerdict::REASON_DENSITY_BELOW_FLOOR, 1.0, 5.0, ['class']);
        $row = $ledger->recordVerdict('cyc-1', 'decide', $verdict, ['text' => 'hi'], '2026-06-25T00:00:00Z');

        self::assertIsArray($row);
        foreach (['loop_cycle_id', 'phase', 'decision', 'reason_code', 'measured_density', 'required_density', 'missing_anchor_kinds', 'payload_sha256', 'ts_utc'] as $key) {
            self::assertArrayHasKey($key, $row);
        }
        $file = $this->root.'/2026-06-25.jsonl';
        self::assertFileExists($file);
        self::assertCount(1, file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []);
    }

    public function test_recent_for_cycle_returns_matching_rows_in_insertion_order(): void
    {
        $ledger = new AtlasLoopAnchorGatePerPhaseReceiptLedger($this->root, enabled: true);
        $ledger->recordVerdict('cyc-1', 'comprehend', EnforcementVerdict::allow(7.0, 6.0), ['t' => 1], '2026-06-25T00:00:00Z');
        $ledger->recordVerdict('cyc-2', 'decide', EnforcementVerdict::allow(7.0, 5.0), ['t' => 2], '2026-06-25T00:00:01Z');
        $ledger->recordVerdict('cyc-1', 'decide', EnforcementVerdict::allow(7.0, 5.0), ['t' => 3], '2026-06-25T00:00:02Z');

        $rows = $ledger->recentForCycle('cyc-1');
        self::assertCount(2, $rows);
        self::assertSame('comprehend', $rows[0]['phase']);
        self::assertSame('decide', $rows[1]['phase']);
    }

    public function test_recent_for_phase_caps_at_limit_with_most_recent_entries(): void
    {
        $ledger = new AtlasLoopAnchorGatePerPhaseReceiptLedger($this->root, enabled: true);
        for ($i = 0; $i < 7; $i++) {
            $ledger->recordVerdict('cyc-'.$i, 'decide', EnforcementVerdict::allow(7.0, 5.0), ['n' => $i], '2026-06-25T00:00:0'.$i.'Z');
        }
        $rows = $ledger->recentForPhase('decide', 5);
        self::assertCount(5, $rows);
        self::assertSame('cyc-2', $rows[0]['loop_cycle_id']);
        self::assertSame('cyc-6', $rows[4]['loop_cycle_id']);
    }

    public function test_disabled_ledger_is_noop_and_creates_no_file(): void
    {
        $ledger = new AtlasLoopAnchorGatePerPhaseReceiptLedger($this->root, enabled: false);
        $row = $ledger->recordVerdict('cyc-X', 'decide', EnforcementVerdict::allow(7.0, 5.0), ['x' => 1]);

        self::assertNull($row);
        self::assertFalse(is_dir($this->root));
    }
}
