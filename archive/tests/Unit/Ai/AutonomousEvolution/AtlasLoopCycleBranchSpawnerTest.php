<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\Branching\AtlasLoopCycleBranchSpawner;
use RuntimeException;
use Tests\TestCase;

final class AtlasLoopCycleBranchSpawnerTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/atlas-branch-'.bin2hex(random_bytes(6));
        @mkdir($this->root, 0o755, true);
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
            if (is_dir((string) $f)) {
                $this->rrm((string) $f);
            } else {
                @unlink((string) $f);
            }
        }
        @rmdir($dir);
    }

    public function test_spawn_creates_subworkspace_and_manifest_outside_live_source(): void
    {
        $spawner = new AtlasLoopCycleBranchSpawner(
            branchesRoot: $this->root,
            nowIso: fn () => '2026-06-25T05:00:00Z',
            branchIdGenerator: fn () => 'b-1234',
            masterEnabled: fn () => true,
        );
        $res = $spawner->spawn('cycle-99', 'try-X', 'abc123');

        $this->assertTrue($res['ok']);
        $m = $res['manifest'];
        $this->assertSame('cycle-99', $m->parentCycleId);
        $this->assertSame('b-1234', $m->branchId);
        $this->assertSame('try-X', $m->hypothesis);
        $this->assertSame('abc123', $m->baseCommitSha);
        $this->assertSame('2026-06-25T05:00:00Z', $m->createdAt);
        $this->assertSame($this->root.'/b-1234', $m->workspacePath);

        $this->assertDirectoryExists($m->workspacePath);
        $manifestPath = $m->workspacePath.'/manifest.json';
        $this->assertFileExists($manifestPath);
        $persisted = json_decode((string) file_get_contents($manifestPath), true);
        $this->assertSame($m->toArray(), $persisted);

        // Confirm not inside app_path()
        $this->assertStringNotContainsString(app_path(), $m->workspacePath);
    }

    public function test_master_off_returns_fail_closed_no_workspace_no_manifest(): void
    {
        $spawner = new AtlasLoopCycleBranchSpawner(
            branchesRoot: $this->root,
            nowIso: fn () => '2026-06-25T05:00:00Z',
            branchIdGenerator: fn () => 'b-99',
            masterEnabled: fn () => false,
        );
        $listBefore = (array) glob($this->root.'/*');
        $res = $spawner->spawn('c', 'h', 'sha');

        $this->assertFalse($res['ok']);
        $this->assertSame('loop_master_off', $res['refusal_reason']);
        $this->assertArrayNotHasKey('manifest', $res);
        $this->assertSame($listBefore, (array) glob($this->root.'/*'));
    }

    public function test_branches_root_inside_app_path_raises_sandbox_floor(): void
    {
        $bad = app_path('Services/Ai/AutonomousEvolution/Branching/_test_branches');
        $spawner = new AtlasLoopCycleBranchSpawner(
            branchesRoot: $bad,
            nowIso: fn () => '2026-06-25T05:00:00Z',
            branchIdGenerator: fn () => 'b-99',
            masterEnabled: fn () => true,
        );

        try {
            $spawner->spawn('c', 'h', 'sha');
            $this->fail('expected sandbox floor to throw');
        } catch (RuntimeException) {
            $this->assertDirectoryDoesNotExist($bad.'/b-99');
        }
    }
}
