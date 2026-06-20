<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\AtlasLoopTaskGrinder;
use Illuminate\Support\Facades\File;
use ReflectionMethod;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * AUTÓPSIA 12/06 — a causa-raiz do "0 propostas em 28 tasks".
 *
 * No caminho DISCOVERY (self-contained) o base_workspace é um cp -R SEM .git. O gate de
 * certificação universal (`materializeGateWorkspace`) fazia `git -C <base> worktree add`
 * incondicionalmente → `fatal: not a git repository` em 100% das propostas self-contained;
 * o grinder as dropava fail-closed. O provider PRODUZIA (proposals_in=1 por task); o gate
 * jogava fora. Este teste congela o contrato: base não-git ⇒ o gate materializa via
 * cp -R + git init + baseline e o diff da proposta APLICA; base git ⇒ worktree (inalterado).
 */
final class AtlasLoopGateWorkspaceNonGitBaseTest extends TestCase
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

    private function materialize(string $base, string $diff): string
    {
        $m = new ReflectionMethod(AtlasLoopTaskGrinder::class, 'materializeGateWorkspace');
        $m->setAccessible(true);
        $ws = $m->invoke(app(AtlasLoopTaskGrinder::class), $base, $diff);
        $this->dirs[] = $ws;

        return $ws;
    }

    /**
     * Base self-contained REAL: um dir com src/Snippet.php e SEM .git (o cp -R do explorer).
     */
    private function nonGitBase(): string
    {
        $base = sys_get_temp_dir().'/atlas-gate-nongit-'.bin2hex(random_bytes(4));
        $this->dirs[] = $base;
        File::ensureDirectoryExists($base.'/src');
        File::put($base.'/src/Snippet.php', "<?php\nfunction snippet() { return 1; }\n");

        return $base;
    }

    /** Diff real (gerado por git) contra o layout do base, paths a/src/... b/src/... */
    private function realDiffAgainst(string $base): string
    {
        $tmp = sys_get_temp_dir().'/atlas-gate-diffgen-'.bin2hex(random_bytes(4));
        $this->dirs[] = $tmp;
        (new Process(['cp', '-R', $base, $tmp]))->run();
        foreach ([
            ['git', '-C', $tmp, 'init', '-q'],
            ['git', '-C', $tmp, 'add', '-A'],
            ['git', '-C', $tmp, '-c', 'user.email=t@t', '-c', 'user.name=t', 'commit', '-q', '-m', 'b'],
        ] as $cmd) {
            (new Process($cmd))->run();
        }
        File::put($tmp.'/src/Snippet.php', "<?php\nfunction snippet() { return 2; } // improved\n");
        $p = new Process(['git', '-C', $tmp, 'diff']);
        $p->run();

        return $p->getOutput();
    }

    public function test_non_git_base_materializes_and_proposal_diff_applies(): void
    {
        $base = $this->nonGitBase();
        $diff = $this->realDiffAgainst($base);
        $this->assertNotSame('', trim($diff), 'sanidade: diff real gerado');

        $ws = $this->materialize($base, $diff);

        $this->assertDirectoryExists($ws);
        $this->assertDirectoryExists($ws.'/.git', 'o gate workspace de base não-git vira repo git (baseline)');
        $this->assertStringContainsString('return 2;', (string) File::get($ws.'/src/Snippet.php'), 'o diff da proposta APLICOU');
        // O baseline existe ANTES do apply: git diff mostra exatamente a mudança da proposta.
        $p = new Process(['git', '-C', $ws, 'diff', '--name-only']);
        $p->run();
        $this->assertStringContainsString('src/Snippet.php', $p->getOutput(), 'mudança visível contra o baseline');
    }

    public function test_git_base_still_uses_worktree_path_unchanged(): void
    {
        // Base GIT (o caminho framework): comportamento original preservado.
        $base = $this->nonGitBase();
        foreach ([
            ['git', '-C', $base, 'init', '-q'],
            ['git', '-C', $base, 'add', '-A'],
            ['git', '-C', $base, '-c', 'user.email=t@t', '-c', 'user.name=t', 'commit', '-q', '-m', 'b'],
        ] as $cmd) {
            (new Process($cmd))->run();
        }
        $diff = $this->realDiffAgainst($base);

        $ws = $this->materialize($base, $diff);

        $this->assertDirectoryExists($ws);
        $this->assertStringContainsString('return 2;', (string) File::get($ws.'/src/Snippet.php'));
        // Worktree de verdade: .git é um ARQUIVO gitdir-pointer, não um diretório.
        $this->assertFileExists($ws.'/.git');
        $this->assertFalse(is_dir($ws.'/.git'), 'base git continua via worktree (não regrediu)');
    }

    public function test_git_file_worktree_base_is_not_copied_into_hooked_baseline_commit(): void
    {
        $base = $this->nonGitBase();
        foreach ([
            ['git', '-C', $base, 'init', '-q'],
            ['git', '-C', $base, 'add', '-A'],
            ['git', '-C', $base, '-c', 'user.email=t@t', '-c', 'user.name=t', 'commit', '-q', '--no-verify', '-m', 'b'],
        ] as $cmd) {
            (new Process($cmd))->run();
        }

        $diff = $this->realDiffAgainst($base);

        $worktree = sys_get_temp_dir().'/atlas-gate-worktree-'.bin2hex(random_bytes(4));
        $this->dirs[] = $worktree;
        (new Process(['git', '-C', $base, 'worktree', 'add', '--detach', $worktree, 'HEAD']))->mustRun();
        File::put($base.'/.git/hooks/pre-commit', "#!/usr/bin/env bash\nexit 42\n");
        chmod($base.'/.git/hooks/pre-commit', 0o755);

        $ws = $this->materialize($worktree, $diff);

        $this->assertStringContainsString('return 2;', (string) File::get($ws.'/src/Snippet.php'));
        $this->assertFileExists($ws.'/.git');
        $this->assertFalse(is_dir($ws.'/.git'), 'git-file worktree base must stay on the worktree path, not copy+commit through hooks');
    }
}
