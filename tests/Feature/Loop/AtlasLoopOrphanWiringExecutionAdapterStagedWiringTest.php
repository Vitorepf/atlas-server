<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\AtlasLoopOrphanWiringExecutionAdapter;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * §5.6 · ORPHAN-WIRING — proves the adapter STAGES every authored wiring file (1 or N) into the git index
 * BEFORE the frozen judge scores, so a multi-file wiring is never invisible to a Guard-4e shape that inspects
 * the index. End-to-end with the real bridge + real judge (both final — no doubles); the staging is observed
 * by an authored test command that snapshots `git diff --cached --name-only` exactly once, at the first
 * post-staging green run (gated on the multi-file marker so neither the earned-RED run nor the neutralization
 * run can overwrite it). ZERO provider spend — the engine is an injected authoring fixture.
 */
final class AtlasLoopOrphanWiringExecutionAdapterStagedWiringTest extends TestCase
{
    private string $ws;

    protected function setUp(): void
    {
        parent::setUp();
        config(['atlas.loop.refactor_wired_proof' => true]);

        $this->ws = sys_get_temp_dir().'/atlas-orphan-staged-'.bin2hex(random_bytes(4));
        mkdir($this->ws.'/app', 0o755, true);
        mkdir($this->ws.'/tests', 0o755, true);

        // BASELINE (committed): Orphan is a real dead capability; Consumer does NOT reference it; NO test yet.
        file_put_contents($this->ws.'/app/Orphan.php', "<?php\nclass Orphan\n{\n    public function contribute(int \$n): int { return \$n * 10; }\n}\n");
        file_put_contents($this->ws.'/app/Consumer.php', "<?php\nclass Consumer\n{\n    public function total(int \$n): int { return \$n; }\n}\n");

        $this->git(['git', 'init', '-q']);
        $this->git(['git', 'add', '-A']);
        $this->git(['git', '-c', 'user.email=t@t', '-c', 'user.name=t', '-c', 'commit.gpgsign=false', 'commit', '-q', '-m', 'baseline']);
    }

    protected function tearDown(): void
    {
        (new Process(['rm', '-rf', $this->ws]))->run();
        parent::tearDown();
    }

    private function git(array $argv): void
    {
        (new Process($argv, $this->ws))->run();
    }

    private function headCommitCount(): int
    {
        $p = new Process(['git', 'rev-list', '--count', 'HEAD'], $this->ws);
        $p->run();

        return (int) trim($p->getOutput());
    }

    /**
     * Author a wired-behavior test that, at the FIRST run where the multi-file marker (Bootstrap.php) exists,
     * snapshots the staged index to a sentinel. Capture-once + marker-gated ⇒ only the post-`git add -A` green
     * run writes it; the pre-wiring earned-RED run (no Bootstrap) and the neutralization run (Bootstrap stashed)
     * never touch it. The wiring is load-bearing through BOTH files: Consumer delegates through Bootstrap.
     */
    private function authorWiredBehaviorTest(): callable
    {
        return function (): array {
            file_put_contents(
                $this->ws.'/tests/wiring_test.php',
                "<?php\n"
                ."\$sentinel = __DIR__.'/../staged_at_score.txt';\n"
                ."\$marker = __DIR__.'/../app/Bootstrap.php';\n"
                ."if (file_exists(\$marker) && ! file_exists(\$sentinel)) {\n"
                ."    \$repo = dirname(__DIR__);\n"
                ."    file_put_contents(\$sentinel, (string) shell_exec('git -C '.escapeshellarg(\$repo).' diff --cached --name-only'));\n"
                ."}\n"
                ."require __DIR__.'/../app/Consumer.php';\n"
                ."if ((new Consumer)->total(2) !== 20) { fwrite(STDERR, 'red'); exit(1); }\n"
                ."echo 'green';\n",
            );

            return ['test_rel' => 'tests/wiring_test.php', 'test_command' => 'php tests/wiring_test.php', 'allowed_globs' => ['app/**']];
        };
    }

    public function test_multi_file_wiring_stages_all_authored_files_before_the_judge_scores(): void
    {
        $adapter = new AtlasLoopOrphanWiringExecutionAdapter;
        $commitsBefore = $this->headCommitCount();

        $result = $adapter->execute(
            'app/Orphan.php',
            $this->ws,
            $this->authorWiredBehaviorTest(),
            // Genuine TWO-FILE wiring: Bootstrap delegates to the orphan; Consumer delegates through Bootstrap.
            function (): void {
                file_put_contents(
                    $this->ws.'/app/Bootstrap.php',
                    "<?php\nrequire_once __DIR__.'/Orphan.php';\nfunction wire_contribute(int \$n): int { return (new Orphan)->contribute(\$n); }\n",
                );
                file_put_contents(
                    $this->ws.'/app/Consumer.php',
                    "<?php\nrequire_once __DIR__.'/Bootstrap.php';\nclass Consumer\n{\n    public function total(int \$n): int { return wire_contribute(\$n); }\n}\n",
                );
            },
        );

        // The wiring is load-bearing across both files ⇒ it certifies end-to-end.
        $this->assertTrue($result['certified'], json_encode($result['verdict']['details'] ?? $result));

        // THE PROOF: at the green run (immediately after the adapter's `git add -A`, before the judge mutates
        // anything), BOTH authored files were in the index.
        $sentinel = $this->ws.'/staged_at_score.txt';
        $this->assertFileExists($sentinel, 'the judge must have run the authored command on the staged tree');
        $staged = (string) file_get_contents($sentinel);
        $this->assertStringContainsString('app/Bootstrap.php', $staged, 'the NEW second wiring file must be staged before score()');
        $this->assertStringContainsString('app/Consumer.php', $staged, 'the modified first wiring file must be staged before score()');

        // The staging step ADDS, never COMMITS: execute() creates EXACTLY ONE commit — the step-3 frozen-test
        // commit (unchanged) — and the `git add -A` at step 4b adds none. (commitsBefore was the baseline.)
        $this->assertSame($commitsBefore + 1, $this->headCommitCount(), 'only step 3 commits; step 4b adds, never commits');

        // The wiring file was STAGED for the judge but NEVER committed to HEAD — proof step 4b was add-only.
        $headTree = new Process(['git', 'ls-tree', '-r', '--name-only', 'HEAD'], $this->ws);
        $headTree->run();
        $this->assertStringNotContainsString('app/Bootstrap.php', $headTree->getOutput(), 'the wiring is staged for the cert, never committed');
    }

    public function test_legacy_single_file_wiring_still_certifies(): void
    {
        $adapter = new AtlasLoopOrphanWiringExecutionAdapter;

        $result = $adapter->execute(
            'app/Orphan.php',
            $this->ws,
            // A simple wired-behavior test (no multi-file marker) — the legacy single-wiring shape.
            function (): array {
                file_put_contents(
                    $this->ws.'/tests/wiring_test.php',
                    "<?php\nrequire __DIR__.'/../app/Consumer.php';\nif ((new Consumer)->total(2) !== 20) { fwrite(STDERR, 'red'); exit(1); }\necho 'green';\n",
                );

                return ['test_rel' => 'tests/wiring_test.php', 'test_command' => 'php tests/wiring_test.php', 'allowed_globs' => ['app/**']];
            },
            // ONE-file wiring: Consumer delegates directly to the orphan.
            fn () => file_put_contents(
                $this->ws.'/app/Consumer.php',
                "<?php\nrequire_once __DIR__.'/Orphan.php';\nclass Consumer\n{\n    public function total(int \$n): int { return (new Orphan)->contribute(\$n); }\n}\n",
            ),
        );

        $this->assertTrue($result['certified'], 'a legacy single-file wiring must still certify exactly as before');
        $this->assertSame('accepted', $result['reason']);
    }
}
