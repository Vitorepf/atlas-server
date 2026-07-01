<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainDoneSetLedger;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainOriginationGapDetector;
use Tests\TestCase;

/**
 * Feature-level gate for AtlasBrainOriginationGapDetector.
 *
 * Verifies cycle-count distance detection: empty window, served+seeded as
 * origination events, and gap = total when no origination found.
 */
final class AtlasBrainOriginationGapDetectorTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/atlas-brain-origin-gap-feat-' . bin2hex(random_bytes(6));
        @mkdir($this->root, 0o775, true);
    }

    private function ledger(): AtlasBrainDoneSetLedger
    {
        return new AtlasBrainDoneSetLedger('loop', $this->root);
    }

    // ── AC1: Empty window returns total_cycles=0, last_served_index=null, gap=0

    public function test_empty_window_returns_zero_cycles_null_index_zero_gap(): void
    {
        $r = (new AtlasBrainOriginationGapDetector)->inspect($this->ledger());

        $this->assertSame(0, $r['total_cycles']);
        $this->assertNull($r['last_served_index']);
        $this->assertSame(0, $r['gap']);
    }

    // ── AC2: Both served and seeded treated as origination events ────────────

    public function test_served_and_seeded_are_origination_events(): void
    {
        $ledger = $this->ledger();
        $ledger->record(['snapshot_id' => 'a', 'status' => 'refused']);
        $ledger->record(['snapshot_id' => 'b', 'status' => 'seeded']);
        $ledger->record(['snapshot_id' => 'c', 'status' => 'served']);
        $ledger->record(['snapshot_id' => 'd', 'status' => 'refused']);

        $r = (new AtlasBrainOriginationGapDetector)->inspect($ledger);

        // Most recent origination = 'c' (served) at index 2.
        $this->assertSame(4, $r['total_cycles']);
        $this->assertSame(2, $r['last_served_index']);
        $this->assertSame(1, $r['gap']);
    }

    // ── AC3: gap equals total_cycles when no served or seeded row ────────────

    public function test_gap_equals_total_when_no_origination_in_window(): void
    {
        $ledger = $this->ledger();
        $ledger->record(['snapshot_id' => 'a', 'status' => 'refused']);
        $ledger->record(['snapshot_id' => 'b', 'status' => 'refused']);

        $r = (new AtlasBrainOriginationGapDetector)->inspect($ledger);

        $this->assertSame(2, $r['total_cycles']);
        $this->assertNull($r['last_served_index']);
        $this->assertSame(2, $r['gap']);
    }
}
