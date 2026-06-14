<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\AtlasLoopBroaderRegressionGate;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * The BROADER REGRESSION GATE in isolation — the load-bearing safety piece of obra-auto-merge.
 * These prove its decisions WITHOUT spawning the whole atlas-server suite:
 *  - the changed-files → test-modules blast-radius MAP (incl. the always-on never-merge test);
 *  - php -l short-circuits RED before any suite runs (a broken changed file blocks);
 *  - a non-runnable environment (no vendor/artisan) fails CLOSED (never silently green).
 */
final class AtlasLoopBroaderRegressionGateTest extends TestCase
{
    /** @var list<string> */
    private array $dirs = [];

    protected function tearDown(): void
    {
        foreach ($this->dirs as $d) {
            (new Process(['rm', '-rf', $d]))->run();
        }
        parent::tearDown();
    }

    private function gate(): AtlasLoopBroaderRegressionGate
    {
        return new AtlasLoopBroaderRegressionGate;
    }

    public function test_changed_loop_engine_file_selects_loop_test_modules_and_never_merge_invariant(): void
    {
        // base_path() is the real atlas-server repo, so the mapped test dirs + never-merge
        // invariant test actually exist (selectTestPaths keeps only existing paths).
        $selected = $this->gate()->selectTestPaths(base_path(), [
            'app/Services/Ai/AutonomousEvolution/AtlasLoopTaskGrinder.php',
        ]);

        $this->assertContains(AtlasLoopBroaderRegressionGate::NEVER_MERGE_INVARIANT_TEST, $selected,
            'the never-merge invariant test runs on EVERY gate, no matter what changed');
        $this->assertContains('tests/Feature/Loop', $selected, 'a loop engine change pulls in the loop feature tests');
        $this->assertContains('tests/Unit/Ai/AutonomousEvolution', $selected, 'and the unit tests for its namespace');
    }

    public function test_changed_obra_file_selects_obra_test_module(): void
    {
        $selected = $this->gate()->selectTestPaths(base_path(), [
            'app/Services/Ai/Obra/AtlasObraExecutor.php',
        ]);

        $this->assertContains(AtlasLoopBroaderRegressionGate::NEVER_MERGE_INVARIANT_TEST, $selected);
        $this->assertContains('tests/Feature/Ai/Obra', $selected, 'an obra change runs the obra feature tests');
    }

    public function test_a_changed_test_file_runs_itself(): void
    {
        $selected = $this->gate()->selectTestPaths(base_path(), [
            'tests/Feature/Loop/AtlasLoopAutoMergeServiceTest.php',
        ]);

        $this->assertContains('tests/Feature/Loop/AtlasLoopAutoMergeServiceTest.php', $selected);
    }

    public function test_php_lint_blocks_a_broken_changed_file_before_running_any_suite(): void
    {
        // A real git repo (vendor + artisan symlinked from base_path so the env is "runnable")
        // carrying a syntactically BROKEN changed php file. The gate must fail at php -l, RED,
        // before ever running a suite.
        $repo = $this->runnableRepoWithChangedFile("<?php\nfunction broken( {\n"); // syntax error

        $result = $this->gate()->evaluate($repo, ['changed.php']);

        $this->assertFalse($result['passed']);
        $this->assertStringStartsWith('php_lint_failed', (string) $result['reason']);
        $this->assertSame([], $result['suites'], 'no suite runs once php -l is red');
    }

    public function test_non_runnable_environment_fails_closed(): void
    {
        // A bare git repo with NO vendor/artisan — the gate cannot prove safety, so it must
        // NOT pass (fail-closed; never a silent green before a big obra reaches main).
        $d = sys_get_temp_dir().'/atlas-broader-bare-'.bin2hex(random_bytes(4));
        mkdir($d, 0o755, true);
        $this->dirs[] = $d;
        (new Process(['git', 'init', '-q'], $d))->run();

        $result = $this->gate()->evaluate($d, ['app/Foo.php']);

        $this->assertFalse($result['passed']);
        $this->assertStringContainsString('gate_environment_not_runnable', (string) $result['reason']);
    }

    private function runnableRepoWithChangedFile(string $brokenContent): string
    {
        $d = sys_get_temp_dir().'/atlas-broader-run-'.bin2hex(random_bytes(4));
        mkdir($d, 0o755, true);
        $this->dirs[] = $d;
        (new Process(['git', 'init', '-q'], $d))->run();
        // Make the env "runnable" cheaply: symlink the real vendor + a stub artisan. php -l
        // runs against the changed file and short-circuits before any suite, so the symlinks
        // only need to make the runnable-environment check pass.
        @symlink(base_path('vendor'), $d.'/vendor');
        file_put_contents($d.'/artisan', "#!/usr/bin/env php\n<?php\n");
        file_put_contents($d.'/changed.php', $brokenContent);
        (new Process(['git', 'add', '-A'], $d))->run();
        (new Process(['git', '-c', 'user.email=t@t', '-c', 'user.name=t', 'commit', '-q', '-m', 'base', '--no-gpg-sign'], $d))->run();

        return $d;
    }
}
