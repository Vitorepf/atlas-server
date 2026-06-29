<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\LiveCycle\AtlasLoopImplementPhaseRunner;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the implement-phase runner is live at the operator surface with a deterministic stub implementer: the
 * receipt references the packet (id + files_touched) and reports NO real edit (commit_sha null); a packet with
 * no allowed_files is honestly skipped (the runner never compensates by writing files itself).
 */
final class AtlasLoopImplementPhaseCommandTest extends TestCase
{
    private function implement(array $packet): array
    {
        $exit = Artisan::call('atlas:loop:implement-phase', [
            '--packet' => (string) json_encode($packet),
            '--json' => true,
        ]);

        return ['exit' => $exit, 'd' => json_decode(trim(Artisan::output()), true)];
    }

    public function test_receipt_references_packet_with_no_real_edit(): void
    {
        ['exit' => $exit, 'd' => $d] = $this->implement([
            'task_packet_id' => 't1',
            'allowed_files' => ['app/Foo.php'],
        ]);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.loop.implement_phase.v1', $d['schema']);
        $this->assertSame('t1', $d['task_packet_id'], (string) json_encode($d));
        $this->assertSame(AtlasLoopImplementPhaseRunner::STATUS_IMPLEMENTED, $d['status']);
        $this->assertContains('app/Foo.php', $d['files_touched']);
        $this->assertNull($d['commit_sha']); // no real edit / no commit
    }

    public function test_packet_with_no_files_is_skipped(): void
    {
        ['d' => $d] = $this->implement(['task_packet_id' => 't2']);

        $this->assertSame('t2', $d['task_packet_id']);
        $this->assertSame(AtlasLoopImplementPhaseRunner::STATUS_SKIPPED, $d['status'], (string) json_encode($d));
        $this->assertSame([], $d['files_touched']);
        $this->assertNull($d['commit_sha']);
    }

    public function test_missing_packet_is_usage_error(): void
    {
        $exit = Artisan::call('atlas:loop:implement-phase', ['--json' => true]);

        $this->assertNotSame(0, $exit);
        $this->assertStringContainsString('usage_error', Artisan::output());
    }
}
