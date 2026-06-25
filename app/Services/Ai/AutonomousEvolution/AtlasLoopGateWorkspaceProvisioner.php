<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Stateless collaborator extracted from AtlasLoopTaskGrinder: builds and cleans
 * temporary git worktrees used by the framework gate, applies the candidate diff,
 * and provisions hermetic .env.testing + support directories. Pure filesystem/git
 * provisioning — no certification/judge logic.
 */
final class AtlasLoopGateWorkspaceProvisioner
{
    public function materialize(string $baseWorkspace, string $diff): string
    {
        if ($baseWorkspace === '' || ! is_dir($baseWorkspace)) {
            throw new RuntimeException('framework gate: base workspace missing');
        }
        if ($diff === '' || str_ends_with($diff, '…')) {
            throw new RuntimeException('framework gate: proposal diff missing or truncated');
        }

        $workspace = sys_get_temp_dir().'/atlas-loop-fw-gate-'.bin2hex(random_bytes(5));
        $gateBaseIsGit = $this->isGitWorkspace($baseWorkspace);
        if ($gateBaseIsGit) {
            $this->mustRun(['git', '-C', $baseWorkspace, 'worktree', 'add', '--detach', $workspace, 'HEAD'], 'framework_gate_worktree_add_failed', 120.0);
        } else {
            // AUTÓPSIA 12/06 (a causa-raiz do "0 propostas"): no caminho DISCOVERY o
            // base_workspace é um cp -R SEM .git — o `git worktree add` acima estourava
            // `not a git repository` em 100% das propostas self-contained e o gate
            // (universal_certification ON) as dropava todas fail-closed. O provider
            // produzia; o gate jogava fora. Para base não-git: cp -R + git init +
            // baseline commit (o MESMO contrato que o explorer self-contained usa),
            // e o diff aplica sobre um baseline real.
            $this->mustRun(['bash', '-lc', 'cp -R '.escapeshellarg($baseWorkspace).' '.escapeshellarg($workspace)], 'framework_gate_copy_failed', 120.0);
            $this->mustRun(['git', '-C', $workspace, 'init', '-q'], 'framework_gate_git_init_failed', 30.0);
        }
        // Provision support + hermetic .env.testing BEFORE the baseline so the FrozenJudge scope census
        // never counts harness infrastructure as a candidate change. AUTÓPSIA 06-23 (the SECOND "0 propostas"
        // root, downstream of the 12/06 one): in the non-git path the fresh `git init` has NO shared
        // .git/info/exclude, so a .env.testing written AFTER the baseline commit showed up as an untracked
        // out-of-scope change and KILLED every self-contained proposal at the gate
        // (proposals_in=1, certified=0, reason target_acceptance_failed(out_of_scope_change)). The worktree
        // path already ignores it via the canonical repo's info/exclude — so this only bit DISCOVERY tasks.
        $this->copyLocalSupport($baseWorkspace, $workspace);
        AtlasLoopHermeticCommandEnvironment::writeTestingEnv($workspace, $baseWorkspace.'/.env');
        if (! $gateBaseIsGit) {
            // Commit the provisioned env + support INTO the baseline so they are not a "change". A candidate
            // diff that LATER edits one of them still shows as a tracked modification and is correctly flagged
            // out_of_scope by the FrozenJudge — the judge's scope/tamper guard is fully preserved.
            $this->mustRun(['git', '-C', $workspace, 'add', '-A'], 'framework_gate_baseline_add_failed', 60.0);
            $this->mustRun(['git', '-C', $workspace, '-c', 'user.email=atlas-loop@local', '-c', 'user.name=Atlas Loop', '-c', 'commit.gpgsign=false', 'commit', '-q', '--allow-empty', '--no-verify', '-m', 'gate baseline'], 'framework_gate_baseline_commit_failed', 60.0);
        }

        $apply = new Process(['git', 'apply', '--whitespace=nowarn', '-'], $workspace, null, null, 60.0);
        $apply->setInput($diff);
        $apply->run();
        if (! $apply->isSuccessful()) {
            $this->remove($baseWorkspace, $workspace);
            throw new RuntimeException('framework gate: proposal diff did not apply cleanly: '.mb_substr($apply->getErrorOutput() ?: $apply->getOutput(), -240));
        }

        return $workspace;
    }

    public function remove(string $baseWorkspace, string $workspace): void
    {
        if ($baseWorkspace !== '' && is_dir($baseWorkspace)) {
            (new Process(['git', '-C', $baseWorkspace, 'worktree', 'remove', '--force', $workspace], null, null, null, 60.0))->run();
            (new Process(['git', '-C', $baseWorkspace, 'worktree', 'prune'], null, null, null, 30.0))->run();
        }
        if (is_dir($workspace)) {
            (new Process(['rm', '-rf', $workspace], null, null, null, 60.0))->run();
        }
    }

    private function isGitWorkspace(string $workspace): bool
    {
        if ($workspace === '' || ! is_dir($workspace)) {
            return false;
        }

        $process = new Process(['git', '-C', $workspace, 'rev-parse', '--is-inside-work-tree'], null, null, null, 10.0);
        $process->run();

        return $process->isSuccessful() && trim($process->getOutput()) === 'true';
    }

    private function copyLocalSupport(string $baseWorkspace, string $workspace): void
    {
        // Guard de destino: no caminho base-não-git o workspace nasce de um cp -R completo
        // do base — vendor/.env já estão lá; copiar de novo aninharia (vendor/vendor).
        foreach (['vendor'] as $dir) {
            if (is_dir($baseWorkspace.'/'.$dir) && ! is_dir($workspace.'/'.$dir)) {
                $this->mustRun(['bash', '-lc', 'cp -R '.escapeshellarg($baseWorkspace.'/'.$dir).' '.escapeshellarg($workspace.'/'.$dir)], 'framework_gate_support_copy_failed_'.$dir, 180.0);
            }
        }
        foreach (['.env', '.env.testing'] as $file) {
            if (is_file($baseWorkspace.'/'.$file) && ! is_file($workspace.'/'.$file)) {
                copy($baseWorkspace.'/'.$file, $workspace.'/'.$file);
            }
        }
        foreach ([
            'bootstrap/cache',
            'storage/app',
            'storage/framework/cache',
            'storage/framework/sessions',
            'storage/framework/testing',
            'storage/framework/views',
            'storage/logs',
        ] as $relative) {
            $dir = $workspace.'/'.$relative;
            if (! is_dir($dir)) {
                @mkdir($dir, 0o755, true);
            }
        }
    }

    /**
     * @param  list<string>  $argv
     */
    private function mustRun(array $argv, string $stage, float $timeout): void
    {
        $process = new Process($argv, null, null, null, $timeout);
        $process->run();
        if (! $process->isSuccessful()) {
            throw new RuntimeException($stage.': '.mb_substr($process->getErrorOutput() ?: $process->getOutput(), -240));
        }
    }
}
