<?php

declare(strict_types=1);

namespace App\Services\Ai\RealExecution;

use Symfony\Component\Process\Process;
use Throwable;

/**
 * Governed branch materialization — the "Atlas delivers a ready-to-merge branch,
 * the OPERATOR merges" frontier (operator-chosen governance, 2026-06-09).
 *
 * Where the loop/forge stop at an isolated throwaway workspace
 * ({@see \App\Services\Ai\AutonomousEvolution\AtlasLoopProposalMaterializer}),
 * this takes a CERTIFIED + gate-credentialed diff one principled step further: it
 * applies it to a REAL git branch (`atlas/materialize/<id>`) created in a private
 * git WORKTREE, commits it, runs the focused measure, and hands back a
 * ready-to-merge artifact + the exact git commands the operator runs to review and
 * merge.
 *
 * SOVEREIGNTY INVARIANTS (non-negotiable; enforced + asserted):
 *  - NEVER merges to main, NEVER pushes, NEVER touches the main working tree.
 *    All writes happen in a private worktree on a fresh branch; the main HEAD and
 *    `git status` are captured before/after and proven byte-identical.
 *  - The actual merge to main is the OPERATOR's act. This service produces the
 *    branch + artifact; it stops exactly at the human line.
 *  - REVERSIBLE: the branch is a detached ref the operator can delete with zero
 *    trace on main; on any failure the partial branch + worktree are cleaned up.
 *  - GATED: refuses unless `certified === true` AND a non-empty gate credential
 *    (the gates-passed receipt) is supplied — it never self-certifies.
 */
final class GovernedBranchMaterializationService
{
    public const SCHEMA_VERSION = 'atlas.ai.governed_branch_materialization.v1';

    private const BRANCH_PREFIX = 'atlas/materialize/';

    private const GIT_TIMEOUT = 120.0;

    /**
     * @param  array{id:string,diff_text:string,repo_dir?:string,base_ref?:string,measure_cmd?:?string,gate_receipt?:?string,certified?:bool}  $input
     * @return array<string,mixed>
     */
    public function materialize(array $input): array
    {
        $id = $this->sanitizeId((string) ($input['id'] ?? ''));
        $diff = (string) ($input['diff_text'] ?? '');
        // AP: files = [{path, content}] written straight into the worktree so git computes
        // modify-vs-new — existing-file fixes work where a new-file-only diff cannot.
        $inputFiles = [];
        foreach ((array) ($input['files'] ?? []) as $f) {
            if (is_array($f) && is_string($f['path'] ?? null) && ($f['path'] ?? '') !== '' && is_string($f['content'] ?? null)) {
                $inputFiles[] = ['path' => (string) $f['path'], 'content' => (string) $f['content']];
            }
        }
        $repo = rtrim((string) ($input['repo_dir'] ?? base_path()), '/');
        $baseRef = trim((string) ($input['base_ref'] ?? 'HEAD')) ?: 'HEAD';
        $measureCmd = isset($input['measure_cmd']) ? trim((string) $input['measure_cmd']) : '';
        $gateReceipt = trim((string) ($input['gate_receipt'] ?? ''));
        $certified = (bool) ($input['certified'] ?? false);

        // --- Gate: certified + a real gate credential. Never materialize ungated. ---
        if (! $certified) {
            return $this->refuse('not_certified', $id);
        }
        if (preg_match('/^[a-f0-9]{16,}$/', $gateReceipt) !== 1) {
            return $this->refuse('gate_receipt_required', $id);
        }
        if ($id === '' || (trim($diff) === '' && $inputFiles === [])) {
            return $this->refuse('id_and_change_required', $id);
        }
        if (! is_dir($repo.'/.git')) {
            return $this->refuse('repo_not_git', $id);
        }

        // --- Capture the main-untouched baseline (HEAD + working-tree status). ---
        [$okHead, $headBefore] = $this->git($repo, ['rev-parse', $baseRef]);
        if (! $okHead) {
            return $this->refuse('base_ref_unresolvable', $id);
        }
        $headBefore = trim($headBefore);
        $statusBefore = $this->status($repo);

        $branch = self::BRANCH_PREFIX.$id;
        $worktree = sys_get_temp_dir().'/atlas-materialize-'.bin2hex(random_bytes(5));

        try {
            // Stale branch from a prior run? Refuse rather than clobber (operator owns it).
            [$branchExists] = $this->git($repo, ['rev-parse', '--verify', '--quiet', 'refs/heads/'.$branch]);
            if ($branchExists) {
                return $this->refuse('branch_already_exists', $id, ['branch' => $branch]);
            }

            // Private worktree on a fresh branch from the clean base — main tree untouched.
            [$okWt] = $this->git($repo, ['worktree', 'add', '-q', '-b', $branch, $worktree, $headBefore]);
            if (! $okWt) {
                return $this->cleanupAndRefuse($repo, $worktree, $branch, 'worktree_create_failed', $id);
            }

            // Write the change into the worktree: prefer explicit files (git computes
            // modify-vs-new, so existing-file fixes work); else apply the unified diff.
            if ($inputFiles !== []) {
                foreach ($inputFiles as $f) {
                    $abs = $worktree.'/'.ltrim($f['path'], '/');
                    if (str_contains($abs, '/../') || ! str_starts_with($abs, $worktree.'/')) {
                        return $this->cleanupAndRefuse($repo, $worktree, $branch, 'unsafe_file_path', $id, ['path' => $f['path']]);
                    }
                    @mkdir(dirname($abs), 0o755, true);
                    if (@file_put_contents($abs, $f['content']) === false) {
                        return $this->cleanupAndRefuse($repo, $worktree, $branch, 'file_write_failed', $id, ['path' => $f['path']]);
                    }
                }
            } else {
                $patch = $worktree.'/.atlas-materialize.patch';
                @file_put_contents($patch, $diff);
                [$okApply, $applyOut] = $this->git($worktree, ['apply', '--whitespace=nowarn', '.atlas-materialize.patch']);
                @unlink($patch);
                if (! $okApply) {
                    return $this->cleanupAndRefuse($repo, $worktree, $branch, 'git_apply_failed', $id, ['detail' => substr($applyOut, 0, 300)]);
                }
            }

            // Commit to the branch (so it is a real, mergeable ref).
            $this->git($worktree, ['add', '-A']);
            [$changedOk, $changed] = $this->git($worktree, ['diff', '--cached', '--name-only']);
            $files = $changedOk ? array_values(array_filter(array_map('trim', explode("\n", $changed)))) : [];
            [$okCommit] = $this->git($worktree, [
                '-c', 'user.email=materialize@atlas', '-c', 'user.name=atlas',
                'commit', '-q', '-m', 'atlas materialize '.$id, '--no-gpg-sign',
            ]);
            if (! $okCommit) {
                return $this->cleanupAndRefuse($repo, $worktree, $branch, 'commit_failed', $id);
            }

            [$diffOk, $branchDiff] = $this->git($worktree, ['diff', $headBefore.'..HEAD']);

            // Optional: run the success-metric measure ON THE BRANCH (in the worktree).
            $measure = $measureCmd !== '' ? $this->runMeasure($worktree, $measureCmd) : null;

            // Drop the worktree; the BRANCH persists for the operator to review + merge.
            $this->git($repo, ['worktree', 'remove', '--force', $worktree]);

            // --- Prove main was untouched. ---
            [, $headAfter] = $this->git($repo, ['rev-parse', $baseRef]);
            $mainUntouched = trim($headAfter) === $headBefore && $this->status($repo) === $statusBefore;

            return [
                'schema_version' => self::SCHEMA_VERSION,
                'materialized' => true,
                'reason' => null,
                'branch' => $branch,
                'base_head' => $headBefore,
                'applied' => true,
                'files_changed' => $files,
                'diff' => $diffOk ? $branchDiff : null,
                'measure' => $measure,
                // Sovereignty: Atlas stopped at the human line.
                'never_merged' => true,
                'never_pushed' => true,
                'main_untouched' => $mainUntouched,
                'reversible' => true,
                'gate_receipt' => $gateReceipt,
                'review_commands' => [
                    'git diff '.$headBefore.'..'.$branch,
                    'git checkout '.$branch,
                    'git checkout '.$this->currentBranch($repo).' && git merge --no-ff '.$branch,
                    'git branch -D '.$branch.'   # to discard (fully reversible)',
                ],
                'receipt_hash' => hash('sha256', (string) json_encode([
                    'schema' => self::SCHEMA_VERSION, 'id' => $id, 'branch' => $branch,
                    'base_head' => $headBefore, 'files' => $files, 'gate_receipt' => $gateReceipt,
                ], JSON_UNESCAPED_SLASHES)),
            ];
        } catch (Throwable $e) {
            return $this->cleanupAndRefuse($repo, $worktree, $branch, 'exception', $id, ['detail' => substr($e->getMessage(), 0, 200)]);
        }
    }

    private function runMeasure(string $cwd, string $cmd): array
    {
        try {
            $p = Process::fromShellCommandline($cmd, $cwd, null, null, self::GIT_TIMEOUT);
            $p->run();

            return [
                'cmd' => $cmd,
                'ran' => true,
                'exit_code' => $p->getExitCode(),
                'passed' => $p->getExitCode() === 0,
                'output_tail' => substr(trim($p->getOutput()."\n".$p->getErrorOutput()), -400),
            ];
        } catch (Throwable $e) {
            return ['cmd' => $cmd, 'ran' => false, 'exit_code' => null, 'passed' => false, 'output_tail' => substr($e->getMessage(), 0, 200)];
        }
    }

    private function status(string $repo): string
    {
        [, $out] = $this->git($repo, ['status', '--porcelain']);

        return $out;
    }

    private function currentBranch(string $repo): string
    {
        [$ok, $out] = $this->git($repo, ['rev-parse', '--abbrev-ref', 'HEAD']);

        return $ok ? (trim($out) ?: 'main') : 'main';
    }

    private function sanitizeId(string $id): string
    {
        $id = strtolower(preg_replace('/[^A-Za-z0-9._-]+/', '-', $id) ?? '');

        return trim($id, '-.') ?: '';
    }

    /**
     * @return array{0:bool,1:string}
     */
    private function git(string $cwd, array $argv): array
    {
        $p = new Process(array_merge(['git'], $argv), $cwd, null, null, self::GIT_TIMEOUT);
        $p->run();

        return [$p->isSuccessful(), $p->getOutput().$p->getErrorOutput()];
    }

    /**
     * @param  array<string,mixed>  $extra
     * @return array<string,mixed>
     */
    private function cleanupAndRefuse(string $repo, string $worktree, string $branch, string $reason, string $id, array $extra = []): array
    {
        // Best-effort full cleanup so a failed materialization leaves ZERO trace.
        if (is_dir($worktree)) {
            $this->git($repo, ['worktree', 'remove', '--force', $worktree]);
        }
        $this->git($repo, ['worktree', 'prune']);
        $this->git($repo, ['branch', '-D', $branch]);

        return $this->refuse($reason, $id, $extra);
    }

    /**
     * @param  array<string,mixed>  $extra
     * @return array<string,mixed>
     */
    private function refuse(string $reason, string $id, array $extra = []): array
    {
        return array_merge([
            'schema_version' => self::SCHEMA_VERSION,
            'materialized' => false,
            'reason' => $reason,
            'branch' => null,
            'applied' => false,
            'never_merged' => true,
            'never_pushed' => true,
            'main_untouched' => true,
            'reversible' => true,
            'id' => $id,
        ], $extra);
    }
}
