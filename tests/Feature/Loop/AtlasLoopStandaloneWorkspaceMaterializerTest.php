<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\AtlasLoopStandaloneWorkspaceMaterializer;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * §5.6 · ORPHAN-WIRING execution — the materialized workspace is a STANDALONE git repo whose stash/commit are
 * fully isolated from the base (the concurrency-safety the grinder's orphan-wiring route depends on).
 */
final class AtlasLoopStandaloneWorkspaceMaterializerTest extends TestCase
{
    private string $base;

    private array $made = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->base = sys_get_temp_dir().'/atlas-orphan-base-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($this->base.'/app');
        File::put($this->base.'/app/Orphan.php', "<?php\nclass Orphan { public function go(): int { return 1; } }\n");
        // A base that is itself a git repo with an UNCOMMITTED edit — the materializer must snapshot the working
        // tree (not HEAD), and must not be perturbed by the copy's later git surgery.
        $this->git($this->base, ['init', '-q']);
        $this->git($this->base, ['add', '-A']);
        $this->git($this->base, ['-c', 'user.email=t@t', '-c', 'user.name=t', '-c', 'commit.gpgsign=false', 'commit', '-q', '-m', 'b']);
        File::put($this->base.'/app/Uncommitted.php', "<?php\nclass Uncommitted {}\n");
    }

    protected function tearDown(): void
    {
        foreach (array_merge([$this->base], $this->made) as $d) {
            (new Process(['rm', '-rf', $d]))->run();
        }
        parent::tearDown();
    }

    private function git(string $cwd, array $argv): void
    {
        (new Process(array_merge(['git'], $argv), $cwd))->run();
    }

    public function test_materializes_a_standalone_repo_capturing_the_working_tree(): void
    {
        $ws = (new AtlasLoopStandaloneWorkspaceMaterializer)->materialize($this->base);
        $this->made[] = (string) $ws;

        $this->assertIsString($ws);
        $this->assertDirectoryExists($ws.'/.git', 'a standalone repo (own .git => own refs/stash)');
        $this->assertFileExists($ws.'/app/Orphan.php');
        $this->assertFileExists($ws.'/app/Uncommitted.php', 'snapshots the WORKING TREE, not just HEAD');
    }

    public function test_stash_in_the_copy_never_touches_the_base(): void
    {
        $materializer = new AtlasLoopStandaloneWorkspaceMaterializer;
        $ws = (string) $materializer->materialize($this->base);
        $this->made[] = $ws;

        // Mutate + stash inside the copy (what the executor's Guard 4e does).
        File::put($ws.'/app/Orphan.php', "<?php\nclass Orphan { public function go(): int { return 999; } }\n");
        $this->git($ws, ['stash', 'push', '--include-untracked', '--quiet']);

        // The base is completely untouched by the copy's stash (no shared refs/stash).
        $this->assertStringContainsString('return 1;', (string) file_get_contents($this->base.'/app/Orphan.php'));

        $baseStash = new Process(['git', 'stash', 'list'], $this->base);
        $baseStash->run();
        $this->assertSame('', trim($baseStash->getOutput()), 'the base has no stash entry — the copy is independent');

        $materializer->discard($ws);
        $this->assertDirectoryDoesNotExist($ws);
    }

    public function test_fails_closed_on_a_missing_base(): void
    {
        $this->assertNull((new AtlasLoopStandaloneWorkspaceMaterializer)->materialize('/nonexistent/atlas/base'));
    }
}
