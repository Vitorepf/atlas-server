<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Brain;

use App\Services\Ai\AutonomousEvolution\AtlasLoopHarnessGuard;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainProvenanceLedger;
use Tests\TestCase;

/**
 * FROZEN proof of the provenance ledger — per-seed lineage append-only NDJSON.
 */
final class AtlasBrainProvenanceLedgerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/atlas-brain-provenance-'.bin2hex(random_bytes(6));
    }

    public function test_append_then_tail_returns_lineage(): void
    {
        $ledger = new AtlasBrainProvenanceLedger($this->root);
        $ledger->append('loop', [
            'cycle_id' => 'snap-A', 'task_packet_id' => 'pkt-1', 'target_path' => 'app/Foo.php',
            'action_hint' => 'use_drafted_candidate', 'recommended_path' => 'pattern-design',
            'source_finding' => 'brief_histogram_skewed',
        ], 100);

        $rows = $ledger->tail('loop', 10);
        self::assertCount(1, $rows);
        self::assertSame('snap-A', $rows[0]['cycle_id']);
        self::assertSame('brief_histogram_skewed', $rows[0]['source_finding']);
        self::assertSame(100, $rows[0]['recorded_at']);
    }

    public function test_append_refuses_empty_cycle_id(): void
    {
        $ledger = new AtlasBrainProvenanceLedger($this->root);
        self::assertNull($ledger->append('loop', ['cycle_id' => '']));
    }

    public function test_provenance_organ_is_a_petreo_forbidden_self_target(): void
    {
        $verdict = app(AtlasLoopHarnessGuard::class)->admit(
            'app/Services/Ai/AutonomousEvolution/Brain/AtlasBrainProvenanceLedger.php',
            true
        );
        self::assertSame('forbidden', $verdict);
    }
}
