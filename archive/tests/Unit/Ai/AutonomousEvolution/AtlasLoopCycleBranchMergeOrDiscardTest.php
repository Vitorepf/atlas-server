<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\Branching\AtlasLoopCycleBranchManifest;
use App\Services\Ai\AutonomousEvolution\Branching\AtlasLoopCycleBranchMergeOrDiscard;
use App\Services\Ai\AutonomousEvolution\Branching\AtlasLoopCycleBranchOutcome;
use Tests\TestCase;

final class AtlasLoopCycleBranchMergeOrDiscardTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/atlas-bmd-'.bin2hex(random_bytes(6));
        @mkdir($this->root.'/branches/b-1/', 0o755, true);
        @mkdir($this->root.'/history', 0o755, true);
    }

    protected function tearDown(): void
    {
        $this->rrm($this->root);
        parent::tearDown();
    }

    private function rrm(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach ((array) glob($dir.'/*') as $f) {
            $abs = (string) $f;
            if (is_dir($abs)) {
                $this->rrm($abs);
            } else {
                @unlink($abs);
            }
        }
        @rmdir($dir);
    }

    private function manifest(): AtlasLoopCycleBranchManifest
    {
        return new AtlasLoopCycleBranchManifest('c-1', 'b-1', 'try-X', 'abc', '2026-06-25T05:00:00Z', $this->root.'/branches/b-1');
    }

    public function test_merge_routes_through_cert_gate_when_accepted_marks_terminal_merged(): void
    {
        $callCount = 0;
        $svc = new AtlasLoopCycleBranchMergeOrDiscard(
            $this->root.'/history',
            function (string $diff, array $manifest) use (&$callCount): array {
                $callCount++;
                return ['accepted' => true, 'reason' => 'gate_ok'];
            },
        );
        $rec = $svc->merge($this->manifest(), new AtlasLoopCycleBranchOutcome(true, 'sum', 'diff-bytes', ['k' => 1]));
        $this->assertSame('merged', $rec['terminal_state']);
        $this->assertSame('gate_ok', $rec['cert_reason']);
        $this->assertSame(1, $callCount);

        $rec2 = $svc->merge($this->manifest(), new AtlasLoopCycleBranchOutcome(true, 'sum', 'diff-bytes', ['k' => 1]));
        $this->assertSame($rec, $rec2);
        $this->assertSame(1, $callCount, 'idempotent: second merge does not call cert gate again');
    }

    public function test_merge_with_gate_reject_marks_rejected_by_cert(): void
    {
        $svc = new AtlasLoopCycleBranchMergeOrDiscard(
            $this->root.'/history',
            fn (string $d, array $m): array => ['accepted' => false, 'reason' => 'invariant_broken'],
        );
        $rec = $svc->merge($this->manifest(), new AtlasLoopCycleBranchOutcome(false, 's', 'd'));
        $this->assertSame('rejected_by_cert', $rec['terminal_state']);
        $this->assertSame('invariant_broken', $rec['cert_reason']);
    }

    public function test_discard_deletes_workspace_and_writes_history(): void
    {
        // Plant a file inside the branch workspace
        file_put_contents($this->root.'/branches/b-1/scratch.txt', 'hello');
        $this->assertDirectoryExists($this->root.'/branches/b-1');

        $callCount = 0;
        $svc = new AtlasLoopCycleBranchMergeOrDiscard(
            $this->root.'/history',
            function () use (&$callCount): array { $callCount++; return ['accepted' => false]; },
        );
        $rec = $svc->discard($this->manifest());
        $this->assertSame('discarded', $rec['terminal_state']);
        $this->assertDirectoryDoesNotExist($this->root.'/branches/b-1');
        $this->assertFileExists($this->root.'/history/b-1.json');
        $this->assertSame(0, $callCount, 'discard does not call cert gate');

        $rec2 = $svc->discard($this->manifest());
        $this->assertSame($rec, $rec2);
    }
}
