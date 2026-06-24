<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\AtlasTaskScopedCommitter;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * PART 2 · shared-main scoped committer — proves an AI's resolve commits ONLY its own files, never sweeping a
 * neighbour's uncommitted work, and never a pétreo forbidden target. Runs in a THROWAWAY git repo (never the
 * real one).
 */
final class AtlasTaskScopedCommitterTest extends TestCase
{
    private string $repo = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = sys_get_temp_dir().'/atlas-scoped-'.bin2hex(random_bytes(5));
        @mkdir($this->repo, 0775, true);
        $this->git(['init', '-q']);
        $this->git(['config', 'user.email', 'test@atlas.local']);
        $this->git(['config', 'user.name', 'Atlas Test']);
        @file_put_contents($this->repo.'/README.md', "seed\n");
        $this->git(['add', 'README.md']);
        $this->git(['commit', '-q', '-m', 'seed']);
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->repo);
        parent::tearDown();
    }

    public function test_commits_only_the_scope_never_a_neighbours_changes(): void
    {
        // Two AIs touched the SAME working tree: AI-A's file + AI-B's file are both changed at once.
        $this->writeFile('app/A/Alpha.php', "<?php // A\n");
        $this->writeFile('app/B/Beta.php', "<?php // B (a neighbour's uncommitted work)\n");

        $committer = new AtlasTaskScopedCommitter(null, $this->repo);
        $res = $committer->commitScope(['app/A/Alpha.php'], 'task-a', 'client-alpha', 'do A');

        $this->assertTrue($res['committed'], 'AI-A committed its file');
        $this->assertSame(['app/A/Alpha.php'], $res['files_committed']);

        // The commit contains ONLY Alpha.php; Beta.php is still uncommitted (the neighbour keeps its work).
        $committed = trim($this->git(['show', '--name-only', '--pretty=format:', 'HEAD'])['out']);
        $this->assertStringContainsString('app/A/Alpha.php', $committed);
        $this->assertStringNotContainsString('app/B/Beta.php', $committed, 'a neighbour\'s file is NEVER swept into this commit');

        $stillDirty = trim($this->git(['status', '--porcelain', '--untracked-files=all'])['out']);
        $this->assertStringContainsString('app/B/Beta.php', $stillDirty, 'Beta.php remains for AI-B to commit itself');

        // The commit is attributed to the AI.
        $msg = $this->git(['log', '-1', '--pretty=%B'])['out'];
        $this->assertStringContainsString('Resolved-by: client-alpha', $msg);
        $this->assertStringContainsString('Atlas-Task: task-a', $msg);
    }

    public function test_refuses_a_forbidden_self_target(): void
    {
        $forbidden = 'app/Services/Ai/AutonomousEvolution/AtlasLoopAutoMergeService.php';
        $this->writeFile($forbidden, "<?php // tampering\n");

        $res = (new AtlasTaskScopedCommitter(null, $this->repo))->commitScope([$forbidden], 'task-x', 'client-x');

        $this->assertFalse($res['committed'], 'the loop\'s own governed-merge service can never be committed by a served task');
        $this->assertSame('forbidden_self_target', $res['reason']);
        // Nothing was committed.
        $this->assertSame('seed', trim($this->git(['log', '-1', '--pretty=%s'])['out']));
    }

    public function test_nothing_to_commit_in_scope_is_an_honest_noop(): void
    {
        $res = (new AtlasTaskScopedCommitter(null, $this->repo))->commitScope(['app/A/Unchanged.php'], 'task-n', 'client-n');

        $this->assertFalse($res['committed']);
        $this->assertSame('nothing_to_commit_in_scope', $res['reason']);
    }

    private function writeFile(string $rel, string $content): void
    {
        $path = $this->repo.'/'.$rel;
        @mkdir(\dirname($path), 0775, true);
        @file_put_contents($path, $content);
    }

    /** @param list<string> $args @return array{code:int,out:string,err:string} */
    private function git(array $args): array
    {
        $p = new Process(array_merge(['git'], $args), $this->repo);
        $p->run();

        return ['code' => (int) $p->getExitCode(), 'out' => $p->getOutput(), 'err' => $p->getErrorOutput()];
    }

    private function rmrf(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $i) {
            if ($i === '.' || $i === '..') {
                continue;
            }
            $p = $dir.'/'.$i;
            is_dir($p) ? $this->rmrf($p) : @unlink($p);
        }
        @rmdir($dir);
    }
}
