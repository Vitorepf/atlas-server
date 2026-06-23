<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\AtlasLoopTaskGrinder;
use ReflectionMethod;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * REGRESSION (the second "0 propostas" root, 2026-06-23): the framework implementation gate provisions a
 * hermetic `.env.testing` into the candidate workspace. On the non-git (DISCOVERY / self-contained) path the
 * gate workspace is a fresh `git init` with NO shared `.git/info/exclude`, so a `.env.testing` written AFTER
 * the baseline commit showed up as an untracked out-of-scope change — and the FrozenJudge SCOPE guard killed
 * EVERY self-contained proposal (proposals_in=1, certified=0, target_acceptance_failed(out_of_scope_change)),
 * delivering nothing for days. The fix provisions + commits the env INTO the baseline so it is not a change.
 * The judge stays honest: a candidate diff that itself edits `.env.testing` still shows as a tracked change.
 */
final class AtlasLoopFrameworkGateEnvScopeTest extends TestCase
{
    public function test_provisioned_env_is_not_an_out_of_scope_change_on_a_non_git_base(): void
    {
        $tmp = sys_get_temp_dir().'/atlas-gate-fix-'.bin2hex(random_bytes(4));
        $base = $tmp.'/base';
        @mkdir($base.'/src', 0o755, true);
        file_put_contents($base.'/src/Target.php', "<?php\n\nfunction targetValue(): int\n{\n    return 1;\n}\n");
        file_put_contents($base.'/.env', 'APP_KEY=base64:'.base64_encode(random_bytes(32))."\n");

        // A real unified diff that edits ONLY the in-scope target, generated via git for reliability.
        $diff = $this->diffEditingTarget($tmp, $base.'/src/Target.php');
        $this->assertNotSame('', trim($diff), 'precondition: a non-empty candidate diff');

        $grinder = app(AtlasLoopTaskGrinder::class);
        $method = new ReflectionMethod($grinder, 'materializeGateWorkspace');
        $method->setAccessible(true);

        /** @var string $workspace */
        $workspace = $method->invoke($grinder, $base, $diff);

        try {
            $this->assertDirectoryExists($workspace);
            $pending = $this->gitPendingPaths($workspace);

            // The provisioned hermetic env is in the baseline → NOT a pending change.
            $this->assertNotContains('.env.testing', $pending, 'provisioned .env.testing must not be an out-of-scope change');
            $this->assertNotContains('.env', $pending, 'provisioned .env must not be an out-of-scope change');
            // The candidate's real edit IS pending (so the gate still sees the actual work).
            $this->assertContains('src/Target.php', $pending, 'the candidate target edit is the only pending change');
        } finally {
            (new Process(['rm', '-rf', $workspace]))->run();
            (new Process(['rm', '-rf', $tmp]))->run();
        }
    }

    /** Build a clean unified diff that flips the target's return value, via a throwaway git repo. */
    private function diffEditingTarget(string $tmp, string $targetFile): string
    {
        $scratch = $tmp.'/scratch';
        @mkdir($scratch.'/src', 0o755, true);
        copy($targetFile, $scratch.'/src/Target.php');
        $this->git($scratch, ['init', '-q']);
        $this->git($scratch, ['add', '-A']);
        $this->git($scratch, ['-c', 'user.email=t@t', '-c', 'user.name=t', 'commit', '-q', '--no-verify', '-m', 'base']);
        file_put_contents($scratch.'/src/Target.php', "<?php\n\nfunction targetValue(): int\n{\n    return 2;\n}\n");
        $p = new Process(['git', 'diff', '--no-ext-diff'], $scratch);
        $p->run();

        return $p->getOutput();
    }

    /** @return list<string> tracked-modified + staged + untracked(honoring ignore) paths, like the judge census */
    private function gitPendingPaths(string $workspace): array
    {
        $paths = [];
        foreach ([
            ['git', 'diff', '--name-only', '--no-ext-diff'],
            ['git', 'diff', '--cached', '--name-only', '--no-ext-diff'],
            ['git', 'ls-files', '--others', '--exclude-standard'],
        ] as $argv) {
            $p = new Process($argv, $workspace);
            $p->run();
            foreach (preg_split('/\R/', trim($p->getOutput())) ?: [] as $line) {
                $line = trim($line);
                if ($line !== '') {
                    $paths[$line] = true;
                }
            }
        }

        return array_keys($paths);
    }

    private function git(string $cwd, array $argv): void
    {
        $p = new Process(array_merge(['git'], $argv), $cwd);
        $p->run();
    }
}
