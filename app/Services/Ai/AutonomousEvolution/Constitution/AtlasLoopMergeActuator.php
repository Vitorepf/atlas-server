<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Constitution;

use Closure;
use Symfony\Component\Process\Process;

/**
 * LOOP-OS · FASE 1 · SLICE 1 — o ATUADOR DE MERGE pétreo (frozen / FORBIDDEN).
 *
 * O ÚNICO lock exclusivo, de caminho estável, para TODA escrita na main. Hoje três fechaduras
 * coexistem ({@see AtlasLoopCycleGitContract} `atlas-cycle-merge.lock`, {@see AtlasLoopObraAutoMergeService}
 * `atlas-obra-automerge.lock}`) E o `AtlasLoopAutoMergeService` faz `git commit` SEM flock nenhum —
 * então o supervisor da campanha e o dreno do watchdog (a cada 60s, processo SEPARADO) corriam no
 * mesmo worktree. Essa corrida é o jeito #1 de o loop corromper a main, e é o mecanismo por trás dos
 * edits sibling-ride no PRÓPRIO juiz confirmados no histórico (Slice 0 provenance receipt).
 *
 * Aqui todo commit/apply passa por {@see withMainMergeLock()}: `LOCK_EX` com poll LIMITADO — uma
 * merge contendida ESPERA e então DEFERE (a proposta volta para a fila, `merged_to_main=false`),
 * NUNCA dropa em silêncio (o bug do `LOCK_NB`) nem trava para sempre (o deadlock do bloqueante puro).
 *
 * PÉTREO: esta classe está em {@see AtlasLoopHarnessGuard::FORBIDDEN_SELF_TARGETS} (a subárvore
 * `Constitution/` inteira). O loop jamais pode realocar ou neutralizar a fechadura que guarda os
 * próprios merges — nem editando o caller, porque o site de invocação do commit vive aqui dentro.
 */
final class AtlasLoopMergeActuator
{
    /** Fechadura ÚNICA de caminho estável: a identidade do objeto não importa, só o caminho. */
    public const LOCK_BASENAME = 'atlas-main-merge.lock';

    /** Orçamento da janela sob-lock (sub-segundo de trabalho; o teto evita deadlock/starvation). */
    public const DEFAULT_TIMEOUT_SECONDS = 8.0;

    /** Intervalo entre tentativas não-bloqueantes (50ms): responsivo sem busy-spin. */
    private const POLL_MICROSECONDS = 50_000;

    /**
     * Executa $critical segurando o lock exclusivo único da main-merge.
     *
     * @template T
     *
     * @param  Closure():T  $critical
     * @return array{acquired:bool,result?:T,reason?:string,waited_seconds:float}
     *               acquired=true  => $critical rodou sob o lock; 'result' carrega o retorno.
     *               acquired=false => 'reason' ∈ {repo_not_git, lock_open_failed, lock_timeout}.
     *               lock_timeout    => o CALLER DEVE re-enfileirar (deferred-retry); NUNCA dropar.
     */
    public function withMainMergeLock(string $repoRoot, Closure $critical, float $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS): array
    {
        $gitDir = rtrim($repoRoot, '/').'/.git';
        if (! is_dir($gitDir)) {
            return ['acquired' => false, 'reason' => 'repo_not_git', 'waited_seconds' => 0.0];
        }

        $handle = @fopen($gitDir.'/'.self::LOCK_BASENAME, 'c');
        if ($handle === false) {
            return ['acquired' => false, 'reason' => 'lock_open_failed', 'waited_seconds' => 0.0];
        }

        $start = hrtime(true);
        $deadline = $start + (int) ($timeoutSeconds * 1_000_000_000);
        $acquired = false;
        while (true) {
            if (flock($handle, LOCK_EX | LOCK_NB)) {
                $acquired = true;
                break;
            }
            if (hrtime(true) >= $deadline) {
                break;
            }
            usleep(self::POLL_MICROSECONDS);
        }

        if (! $acquired) {
            @fclose($handle);

            return ['acquired' => false, 'reason' => 'lock_timeout', 'waited_seconds' => (hrtime(true) - $start) / 1e9];
        }

        try {
            return ['acquired' => true, 'result' => $critical(), 'waited_seconds' => (hrtime(true) - $start) / 1e9];
        } finally {
            @flock($handle, LOCK_UN);
            @fclose($handle);
        }
    }

    /**
     * Floor barato e determinístico para rodar DENTRO do lock antes do commit: `php -l` em cada
     * arquivo .php alterado. Um diff que nem faz parse jamais entra na main. Rápido (ms/arquivo),
     * fail-closed (parse error OU processo que falha => false).
     *
     * @param  list<string>  $changedPhpFiles  caminhos absolutos
     */
    public function phpLintOk(array $changedPhpFiles, float $perFileTimeout = 5.0): bool
    {
        foreach ($changedPhpFiles as $file) {
            if (! is_string($file) || $file === '' || ! str_ends_with($file, '.php') || ! is_file($file)) {
                continue;
            }
            $proc = new Process([PHP_BINARY, '-l', $file], null, null, null, $perFileTimeout);
            $proc->run();
            if (! $proc->isSuccessful()) {
                return false;
            }
        }

        return true;
    }

    /**
     * LOOP-OS · SLICE 4 — the merge-time COMMIT-SPINE: under the exclusive lock, stage the candidate's
     * changed files, recompute the POST-APPLY tree sha, and verify the Constitution PASS-token re-binds to
     * (post-apply-tree, current battery root, PASS, a fresh nonce). Commit ONLY if the token re-verifies AND
     * the cheap php -l floor passes — so a property_gated edit lands iff the gate said PASS for THIS exact
     * tree against THIS exact battery, once. Any divergence (tree moved since the gate, battery bumped,
     * replayed nonce, parse error) ⇒ NO commit. This is what makes the gate's verdict enforceable at merge.
     *
     * @param  list<string>  $changedFiles    repo-relative paths the candidate touched
     * @param  list<string>  $consumedNonces  nonces already spent (replay defense)
     * @return array{committed:bool, reason:string, commit:?string, tree_sha?:string}
     */
    public function commitWithConstitutionToken(string $repoRoot, array $changedFiles, string $message, string $token, string $batteryRootHash, string $nonce, array $consumedNonces = []): array
    {
        $repoRoot = rtrim($repoRoot, '/');
        $locked = $this->withMainMergeLock($repoRoot, function () use ($repoRoot, $changedFiles, $message, $token, $batteryRootHash, $nonce, $consumedNonces): array {
            $this->git($repoRoot, array_merge(['add', '--'], $changedFiles));

            [$treeOk, $treeOut] = $this->git($repoRoot, ['write-tree']);
            if (! $treeOk) {
                return ['committed' => false, 'reason' => 'write_tree_failed', 'commit' => null];
            }
            $postApplyTreeSha = trim($treeOut);

            $verdict = (new AtlasLoopConstitutionGateToken)->verify($token, $postApplyTreeSha, $batteryRootHash, $nonce, $consumedNonces);
            if (! $verdict['valid']) {
                return ['committed' => false, 'reason' => 'constitution_token_invalid:'.$verdict['reason'], 'commit' => null, 'tree_sha' => $postApplyTreeSha];
            }

            $abs = array_map(static fn (string $f): string => $repoRoot.'/'.ltrim($f, '/'), $changedFiles);
            if (! $this->phpLintOk($abs)) {
                return ['committed' => false, 'reason' => 'php_lint_failed', 'commit' => null, 'tree_sha' => $postApplyTreeSha];
            }

            [$cok] = $this->git($repoRoot, ['-c', 'user.email=loop@atlas', '-c', 'user.name=atlas-loop', 'commit', '-q', '-m', $message !== '' ? $message : 'atlas loop constitution commit', '--no-gpg-sign']);
            if (! $cok) {
                return ['committed' => false, 'reason' => 'commit_failed', 'commit' => null, 'tree_sha' => $postApplyTreeSha];
            }
            [, $sha] = $this->git($repoRoot, ['rev-parse', 'HEAD']);

            return ['committed' => true, 'reason' => 'constitution_token_verified', 'commit' => trim($sha), 'tree_sha' => $postApplyTreeSha];
        });

        if (($locked['acquired'] ?? false) !== true) {
            return ['committed' => false, 'reason' => 'lock_'.((string) ($locked['reason'] ?? 'unavailable')), 'commit' => null];
        }

        return $locked['result'];
    }

    /**
     * @param  list<string>  $args
     * @return array{0:bool,1:string}
     */
    private function git(string $repoRoot, array $args): array
    {
        $p = new Process(array_merge(['git'], array_values($args)), $repoRoot, null, null, 60.0);
        $p->run();

        return [$p->isSuccessful(), $p->getOutput().$p->getErrorOutput()];
    }
}
