<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction;

use Symfony\Component\Process\Process;
use Throwable;

/**
 * G4 — o passo de EXECUÇÃO governada que faltava depois do plano dry-run: promove
 * um scaffold STAGED para um BRANCH novo do repositório, via git worktree isolado.
 *
 * Escada de gates (mesma rigidez do AtlasLoopProposalPromotionGate):
 *   1. flag `atlas.ai.self_construction.promote_to_source_enabled` (default OFF);
 *   2. aprovação explícita do operador por chamada (operator_id + approved=true);
 *   3. plano READY do AtlasSelfConstructionPromotionPlanService (que já passa
 *      Constitutional Kernel + admission + detecção de conflito + hashes);
 *   4. anti-tamper: re-hash de cada staged file contra o hash do plano;
 *   5. gate de sintaxe: `php -l` em cada .php staged;
 *   6. cópia DENTRO de um git worktree em branch novo `atlas/self-construction/...`
 *      — nunca a working tree do operador, nunca main;
 *   7. diff-verify: o worktree só pode conter EXATAMENTE os arquivos planejados;
 *   8. commit no branch + remoção do worktree (o branch persiste p/ review) +
 *      recibo no Evidence Ledger.
 *
 * Merge branch→main permanece um ato humano de git/PR. Estruturalmente este
 * serviço não escreve main nem a working tree.
 */
final class AtlasSelfConstructionPromotionExecutorService
{
    public const SCHEMA_VERSION = 'atlas.self_construction.promotion_execution.v1';

    private ?string $repoRootOverride = null;

    public function __construct(
        private readonly AtlasSelfConstructionPromotionPlanService $planner,
    ) {}

    public function setRepoRootForTesting(?string $path): void
    {
        $this->repoRootOverride = $path;
    }

    private function repoRoot(): string
    {
        return $this->repoRootOverride ?? base_path();
    }

    /**
     * @param  array<string,mixed>  $approval  ['operator_id' => string, 'approved' => bool]
     * @return array<string,mixed>
     */
    public function promote(string $proposalId, string $proposalHash, array $approval): array
    {
        if (! (bool) config('atlas.ai.self_construction.promote_to_source_enabled', false)) {
            return $this->deny('promote_to_source_disabled');
        }

        $operator = trim((string) ($approval['operator_id'] ?? ''));
        if ($operator === '' || ($approval['approved'] ?? false) !== true) {
            return $this->deny('operator_approval_required');
        }

        $plan = $this->planner->plan($proposalId, $proposalHash, $operator);
        if (($plan['status'] ?? null) !== AtlasSelfConstructionPromotionPlanService::STATUS_READY) {
            return $this->deny('plan_not_ready:'.(string) ($plan['status'] ?? 'unknown'));
        }

        $files = (array) ($plan['files'] ?? []);
        if ($files === []) {
            return $this->deny('plan_has_no_files');
        }

        // Anti-tamper: o conteúdo staged precisa ser BYTE-igual ao que o plano viu.
        foreach ($files as $file) {
            $staged = (string) ($file['staged_path'] ?? '');
            $expected = (string) ($file['staged_hash'] ?? '');
            if ($staged === '' || ! is_file($staged)) {
                return $this->deny('staged_file_missing:'.$staged);
            }
            if ($expected !== 'sha256:'.hash_file('sha256', $staged)) {
                return $this->deny('staged_files_tampered:'.basename($staged));
            }
            if (str_ends_with($staged, '.php') && ! $this->phpLints($staged)) {
                return $this->deny('syntax_invalid:'.basename($staged));
            }
        }

        $repo = $this->repoRoot();
        if (! is_dir($repo.'/.git')) {
            return $this->deny('repo_root_not_a_git_repository');
        }

        $branch = 'atlas/self-construction/'.substr(preg_replace('/[^a-z0-9_-]+/i', '-', $proposalId) ?: 'proposal', 0, 48)
            .'-'.substr(hash('sha256', $proposalHash), 0, 8);
        $worktree = sys_get_temp_dir().'/atlas-sc-promote-'.bin2hex(random_bytes(5));

        if (! $this->git($repo, ['worktree', 'add', '-b', $branch, $worktree, 'HEAD'])) {
            return $this->deny('worktree_create_failed');
        }

        try {
            $relativeTargets = [];
            foreach ($files as $file) {
                $target = (string) ($file['target_path'] ?? '');
                $relative = $this->relativeToPlannerBase($target);
                if ($relative === null) {
                    return $this->deny('target_outside_repo:'.$target, cleanupRepo: $repo, worktree: $worktree, branch: $branch);
                }
                $dest = $worktree.'/'.$relative;
                @mkdir(dirname($dest), 0o755, true);
                if (! copy((string) $file['staged_path'], $dest)) {
                    return $this->deny('copy_failed:'.$relative, cleanupRepo: $repo, worktree: $worktree, branch: $branch);
                }
                $relativeTargets[] = $relative;
            }

            // Diff-verify: o worktree contém EXATAMENTE os arquivos planejados.
            $porcelain = array_values(array_filter(array_map(
                static fn (string $entry): string => substr($entry, 3),
                array_filter(explode("\0", $this->gitOutput($worktree, ['status', '--porcelain', '-z', '--untracked-files=all']))),
            )));
            sort($porcelain);
            $expected = $relativeTargets;
            sort($expected);
            if ($porcelain !== $expected) {
                return $this->deny('unexpected_changes_in_worktree', cleanupRepo: $repo, worktree: $worktree, branch: $branch);
            }

            $this->git($worktree, ['add', '-A']);
            if (! $this->git($worktree, ['-c', 'user.email=self-construction@atlas', '-c', 'user.name=atlas', 'commit', '-q', '-m', 'atlas self-construction promotion: '.$proposalId, '--no-gpg-sign'])) {
                return $this->deny('commit_failed', cleanupRepo: $repo, worktree: $worktree, branch: $branch);
            }
        } finally {
            // O worktree é descartável; o BRANCH persiste para review do operador.
            $this->git($repo, ['worktree', 'remove', '--force', $worktree]);
        }

        $receiptHash = hash('sha256', $operator.'|'.$branch.'|'.$proposalHash.'|'.implode(',', $relativeTargets));
        $this->recordReceipt($proposalId, $proposalHash, $operator, $branch, $relativeTargets, $receiptHash);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'promoted' => true,
            'reason' => null,
            'branch' => $branch,
            'files' => $relativeTargets,
            'operator_id' => $operator,
            'receipt_hash' => $receiptHash,
            // Invariantes: branch novo apenas; main e working tree nunca escritos aqui.
            'merged_to_main' => false,
            'never_main' => true,
            'working_tree_untouched' => true,
        ];
    }

    /**
     * O plano resolve targets contra base_path(); reprojeta para caminho RELATIVO
     * (e refuse qualquer target que escape da raiz — defesa path-traversal).
     */
    private function relativeToPlannerBase(string $target): ?string
    {
        $base = rtrim(function_exists('base_path') ? base_path() : dirname(__DIR__, 4), '/').'/';
        if (! str_starts_with($target, $base)) {
            return null;
        }
        $relative = substr($target, strlen($base));
        if ($relative === '' || str_contains($relative, '..')) {
            return null;
        }

        return $relative;
    }

    /**
     * @return array<string,mixed>
     */
    private function deny(string $reason, ?string $cleanupRepo = null, ?string $worktree = null, ?string $branch = null): array
    {
        if ($cleanupRepo !== null && $worktree !== null) {
            $this->git($cleanupRepo, ['worktree', 'remove', '--force', $worktree]);
            if ($branch !== null) {
                $this->git($cleanupRepo, ['branch', '-D', $branch]);
            }
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'promoted' => false,
            'reason' => $reason,
            'branch' => null,
            'files' => [],
            'operator_id' => null,
            'receipt_hash' => null,
            'merged_to_main' => false,
            'never_main' => true,
            'working_tree_untouched' => true,
        ];
    }

    private function phpLints(string $path): bool
    {
        $p = new Process([PHP_BINARY, '-l', $path], null, null, null, 30.0);
        $p->run();

        return $p->isSuccessful();
    }

    /**
     * @param  list<string>  $files
     */
    private function recordReceipt(string $proposalId, string $proposalHash, string $operator, string $branch, array $files, string $receiptHash): void
    {
        try {
            app(\App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger::class)->record(
                \App\Services\Ai\Kernel\Evidence\LedgerEventType::DecisionIssued,
                [
                    'schema_version' => self::SCHEMA_VERSION,
                    'decision' => 'self_construction_promotion_to_branch',
                    'proposal_id' => $proposalId,
                    'proposal_hash' => $proposalHash,
                    'branch' => $branch,
                    'files' => $files,
                    'merged_to_main' => false,
                    'receipt_hash' => $receiptHash,
                ],
                [
                    'operator_id' => $operator,
                    'emitter_stage' => 'atlas.self_construction.promotion',
                    'emitter_version' => 'promotion-executor-v1',
                ],
            );
        } catch (Throwable) {
            // Recibo é best-effort; os invariantes do gate não dependem dele.
        }
    }

    /**
     * @param  list<string>  $argv
     */
    private function git(string $cwd, array $argv): bool
    {
        $p = new Process(array_merge(['git'], $argv), $cwd, null, null, 120.0);
        $p->run();

        return $p->isSuccessful();
    }

    /**
     * @param  list<string>  $argv
     */
    private function gitOutput(string $cwd, array $argv): string
    {
        $p = new Process(array_merge(['git'], $argv), $cwd, null, null, 120.0);
        $p->run();

        return $p->isSuccessful() ? $p->getOutput() : '';
    }
}
