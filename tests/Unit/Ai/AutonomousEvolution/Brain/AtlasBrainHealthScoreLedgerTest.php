<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Brain;

use App\Services\Ai\AutonomousEvolution\AtlasLoopHarnessGuard;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainHealthScoreLedger;
use Tests\TestCase;

/**
 * FROZEN proof of the health-score ledger — append-only per-scope NDJSON time-series.
 */
final class AtlasBrainHealthScoreLedgerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/atlas-brain-score-ledger-'.bin2hex(random_bytes(6));
        @mkdir($this->root, 0o775, true);
    }

    public function test_append_then_tail_returns_recorded_rows_in_order(): void
    {
        $ledger = new AtlasBrainHealthScoreLedger($this->root);
        $ledger->append('loop', 60, 100);
        $ledger->append('loop', 75, 200);
        $ledger->append('loop', 80, 300);

        $rows = $ledger->tail('loop', 5);
        self::assertCount(3, $rows);
        self::assertSame(60, $rows[0]['score']);
        self::assertSame(80, $rows[2]['score']);
        self::assertSame(200, $rows[1]['recorded_at']);
    }

    public function test_score_is_clamped_to_0_100(): void
    {
        $ledger = new AtlasBrainHealthScoreLedger($this->root);
        $ledger->append('loop', 150, 0);
        $ledger->append('loop', -10, 0);
        $rows = $ledger->tail('loop', 10);
        self::assertSame(100, $rows[0]['score']);
        self::assertSame(0, $rows[1]['score']);
    }

    public function test_ledger_organ_is_a_petreo_forbidden_self_target(): void
    {
        $verdict = app(AtlasLoopHarnessGuard::class)->admit(
            'app/Services/Ai/AutonomousEvolution/Brain/AtlasBrainHealthScoreLedger.php',
            true
        );
        self::assertSame('forbidden', $verdict);
    }
}
