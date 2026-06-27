<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Brain;

use App\Services\Ai\AutonomousEvolution\AtlasLoopHarnessGuard;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainDoneSetLedger;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainOriginationGapDetector;
use Tests\TestCase;

final class AtlasBrainOriginationGapDetectorTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/atlas-brain-origin-gap-'.bin2hex(random_bytes(6));
        @mkdir($this->root, 0o775, true);
    }

    public function test_gap_is_zero_when_last_cycle_was_served(): void
    {
        $ledger = new AtlasBrainDoneSetLedger('loop', $this->root);
        $ledger->record(['snapshot_id' => 'a', 'status' => 'refused']);
        $ledger->record(['snapshot_id' => 'b', 'status' => 'served']);

        $r = (new AtlasBrainOriginationGapDetector)->inspect($ledger);
        self::assertSame(2, $r['total_cycles']);
        self::assertSame(1, $r['last_served_index']);
        self::assertSame(0, $r['gap']);
    }

    public function test_gap_counts_cycles_since_last_served(): void
    {
        $ledger = new AtlasBrainDoneSetLedger('loop', $this->root);
        $ledger->record(['snapshot_id' => 'a', 'status' => 'served']);
        $ledger->record(['snapshot_id' => 'b', 'status' => 'refused']);
        $ledger->record(['snapshot_id' => 'c', 'status' => 'refused']);

        $r = (new AtlasBrainOriginationGapDetector)->inspect($ledger);
        self::assertSame(3, $r['total_cycles']);
        self::assertSame(0, $r['last_served_index']);
        self::assertSame(2, $r['gap']);
    }

    public function test_gap_equals_total_when_no_served_in_window(): void
    {
        $ledger = new AtlasBrainDoneSetLedger('loop', $this->root);
        $ledger->record(['snapshot_id' => 'a', 'status' => 'refused']);

        $r = (new AtlasBrainOriginationGapDetector)->inspect($ledger);
        self::assertSame(1, $r['total_cycles']);
        self::assertNull($r['last_served_index']);
        self::assertSame(1, $r['gap']);
    }

    public function test_detector_organ_is_a_petreo_forbidden_self_target(): void
    {
        $verdict = app(AtlasLoopHarnessGuard::class)->admit(
            'app/Services/Ai/AutonomousEvolution/Brain/AtlasBrainOriginationGapDetector.php',
            true
        );
        self::assertSame('forbidden', $verdict);
    }
}
