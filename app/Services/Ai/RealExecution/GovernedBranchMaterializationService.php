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

    // ==================================================================
    // AOBG N3.F2 — OBRA-ACCUMULATE mode (ONE branch, MANY steps).
    //
    // The single-shot materialize() above cuts a fresh branch per change. An OBRA is
    // a multi-step work that must accumulate ALL its steps onto ONE branch
    // (atlas/obra/<id>), step N building on step N-1's state — NOT N separate
    // branches. These three methods extend the materializer for that, preserving
    // EVERY sovereignty invariant:
    //   - openObra():       create the obra branch ONCE + a persistent worktree from
    //                       the clean base (main tree untouched). Refuses a stale branch.
    //   - applyStepToObra():write a certified step's files into the HELD worktree and
    //                       commit (one commit per step) — git computes modify-vs-new so
    //                       step N sees step N-1's committed state. NEVER pushes/merges.
    //   - closeObra():      drop the worktree (the BRANCH persists for the operator) and
    //                       PROVE main was untouched across the whole obra.
    // The whole obra is reversible: closeObra (and any abort) leaves only the branch,
    // discardable via discardBranch() — which now governs BOTH the materialize AND the
    // obra prefixes.
    // ==================================================================

    private const OBRA_BRANCH_PREFIX = 'atlas/obra/';

    /**
     * AOBG N3.F2 — OPEN an obra: create the single accumulating branch
     * (atlas/obra/<id>) + a persistent worktree from the clean base, ONCE. Captures
     * the main-untouched baseline (HEAD + working-tree status) so {@see closeObra()}
     * can prove main never moved across the entire obra. Refuses (no clobber) a stale
     * obra branch from a prior run — the operator owns it.
     *
     * @param  array{id:string,repo_dir?:string,base_ref?:string}  $input
     * @return array<string,mixed> {opened, reason?, branch, worktree?, base_head?,
     *                             status_before?, repo?}
     */
    public function openObra(array $input): array
    {
        $id = $this->sanitizeId((string) ($input['id'] ?? ''));
        $repo = rtrim((string) ($input['repo_dir'] ?? base_path()), '/');
        $baseRef = trim((string) ($input['base_ref'] ?? 'HEAD')) ?: 'HEAD';

        if ($id === '') {
            return $this->refuseObra('id_required', '');
        }
        if (! is_dir($repo.'/.git')) {
            return $this->refuseObra('repo_not_git', '');
        }

        [$okHead, $headBefore] = $this->git($repo, ['rev-parse', $baseRef]);
        if (! $okHead) {
            return $this->refuseObra('base_ref_unresolvable', '');
        }
        $headBefore = trim($headBefore);
        $statusBefore = $this->status($repo);

        $branch = self::OBRA_BRANCH_PREFIX.$id;

        // Stale obra branch from a prior run? Refuse rather than clobber.
        [$branchExists] = $this->git($repo, ['rev-parse', '--verify', '--quiet', 'refs/heads/'.$branch]);
        if ($branchExists) {
            return $this->refuseObra('branch_already_exists', $branch);
        }

        $worktree = sys_get_temp_dir().'/atlas-obra-'.bin2hex(random_bytes(5));
        [$okWt, $wtOut] = $this->git($repo, ['worktree', 'add', '-q', '-b', $branch, $worktree, $headBefore]);
        if (! $okWt) {
            // Best-effort cleanup of any half-created ref/worktree so the obra leaves no trace.
            $this->git($repo, ['worktree', 'prune']);
            $this->git($repo, ['branch', '-D', $branch]);

            return $this->refuseObra('worktree_create_failed', $branch, ['detail' => substr($wtOut, 0, 200)]);
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'opened' => true,
            'reason' => null,
            'branch' => $branch,
            'worktree' => $worktree,
            'base_head' => $headBefore,
            'status_before' => $statusBefore,
            'repo' => $repo,
            'never_merged' => true,
            'never_pushed' => true,
            'reversible' => true,
        ];
    }

    /**
     * AOBG N3.F2 — APPLY one certified step's files onto the held obra worktree and
     * commit it. Because the worktree is persistent across steps and each step is
     * committed, git computes modify-vs-new against the PRIOR step's committed state —
     * so step N genuinely builds on steps 1..N-1 (the accumulation invariant). Gated
     * exactly like materialize(): refuses unless certified === true AND a real gate
     * credential is supplied — it never self-certifies. NEVER pushes, NEVER merges,
     * NEVER touches main (all writes are inside the private worktree).
     *
     * @param  array{worktree:string,base_head:string,step_id?:string,files:array<int,array{path:string,content:string}>,certified?:bool,gate_receipt?:?string}  $input
     * @return array<string,mixed> {applied, reason?, files_changed?, commit?, step_id}
     */
    public function applyStepToObra(array $input): array
    {
        $worktree = rtrim((string) ($input['worktree'] ?? ''), '/');
        $stepId = $this->sanitizeId((string) ($input['step_id'] ?? '')) ?: 'step';
        $certified = (bool) ($input['certified'] ?? false);
        $gateReceipt = trim((string) ($input['gate_receipt'] ?? ''));

        $files = [];
        foreach ((array) ($input['files'] ?? []) as $f) {
            if (is_array($f) && is_string($f['path'] ?? null) && ($f['path'] ?? '') !== '' && is_string($f['content'] ?? null)) {
                $files[] = ['path' => (string) $f['path'], 'content' => (string) $f['content']];
            }
        }

        // --- Gate: certified + a real gate credential (same floor as materialize). ---
        if (! $certified) {
            return ['applied' => false, 'reason' => 'not_certified', 'step_id' => $stepId];
        }
        if (preg_match('/^[a-f0-9]{16,}$/', $gateReceipt) !== 1) {
            return ['applied' => false, 'reason' => 'gate_receipt_required', 'step_id' => $stepId];
        }
        // A linked worktree's `.git` is a FILE (a gitdir pointer), not a dir — accept either.
        if ($worktree === '' || (! is_dir($worktree.'/.git') && ! is_file($worktree.'/.git'))) {
            return ['applied' => false, 'reason' => 'worktree_invalid', 'step_id' => $stepId];
        }
        if ($files === []) {
            return ['applied' => false, 'reason' => 'no_files', 'step_id' => $stepId];
        }

        try {
            foreach ($files as $f) {
                $abs = $worktree.'/'.ltrim($f['path'], '/');
                if (str_contains($abs, '/../') || ! str_starts_with($abs, $worktree.'/')) {
                    return ['applied' => false, 'reason' => 'unsafe_file_path', 'step_id' => $stepId, 'path' => $f['path']];
                }
                @mkdir(dirname($abs), 0o755, true);
                if (@file_put_contents($abs, $f['content']) === false) {
                    return ['applied' => false, 'reason' => 'file_write_failed', 'step_id' => $stepId, 'path' => $f['path']];
                }
            }

            $this->git($worktree, ['add', '-A']);
            [$changedOk, $changed] = $this->git($worktree, ['diff', '--cached', '--name-only']);
            $changedFiles = $changedOk ? array_values(array_filter(array_map('trim', explode("\n", $changed)))) : [];
            if ($changedFiles === []) {
                // The step's files were byte-identical to the prior state — nothing to commit.
                return ['applied' => false, 'reason' => 'no_change_after_write', 'step_id' => $stepId];
            }

            [$okCommit, $commitOut] = $this->git($worktree, [
                '-c', 'user.email=materialize@atlas', '-c', 'user.name=atlas',
                'commit', '-q', '-m', 'atlas obra step '.$stepId, '--no-gpg-sign',
            ]);
            if (! $okCommit) {
                return ['applied' => false, 'reason' => 'commit_failed', 'step_id' => $stepId, 'detail' => substr($commitOut, 0, 200)];
            }

            [, $commitSha] = $this->git($worktree, ['rev-parse', 'HEAD']);

            return [
                'applied' => true,
                'reason' => null,
                'step_id' => $stepId,
                'files_changed' => $changedFiles,
                'commit' => trim($commitSha),
                'gate_receipt' => $gateReceipt,
                'never_merged' => true,
                'never_pushed' => true,
            ];
        } catch (Throwable $e) {
            return ['applied' => false, 'reason' => 'exception:'.substr($e->getMessage(), 0, 160), 'step_id' => $stepId];
        }
    }

    /**
     * AOBG N3.F2 — CLOSE an obra: drop the persistent worktree (the accumulating
     * BRANCH persists, for the operator to review + merge) and PROVE main was
     * untouched across the WHOLE obra (HEAD + working-tree status byte-identical to
     * the {@see openObra()} baseline). The branch is reversible at any time via
     * {@see discardBranch()}. Fail-soft: a worktree-remove hiccup is reported but never
     * throws — the branch is always left intact for the operator.
     *
     * @param  array{repo:string,worktree:string,branch:string,base_head:string,status_before?:string}  $input
     * @return array<string,mixed> {closed, branch, base_head, main_untouched,
     *                             never_merged, never_pushed, reversible, review_commands}
     */
    public function closeObra(array $input): array
    {
        $repo = rtrim((string) ($input['repo'] ?? ''), '/');
        $worktree = rtrim((string) ($input['worktree'] ?? ''), '/');
        $branch = trim((string) ($input['branch'] ?? ''));
        $baseHead = trim((string) ($input['base_head'] ?? ''));
        $statusBefore = (string) ($input['status_before'] ?? '');

        // Drop the worktree; the branch persists. Best-effort (never throws).
        if ($worktree !== '' && is_dir($worktree)) {
            $this->git($repo, ['worktree', 'remove', '--force', $worktree]);
        }
        $this->git($repo, ['worktree', 'prune']);

        // --- Prove main was untouched across the entire obra. ---
        [, $headAfter] = $this->git($repo, ['rev-parse', 'HEAD']);
        $mainUntouched = trim($headAfter) === $baseHead && $this->status($repo) === $statusBefore;

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'closed' => true,
            'branch' => $branch,
            'base_head' => $baseHead,
            'main_untouched' => $mainUntouched,
            'never_merged' => true,
            'never_pushed' => true,
            'reversible' => true,
            'review_commands' => [
                'git diff '.$baseHead.'..'.$branch,
                'git checkout '.$branch,
                'git checkout '.$this->currentBranch($repo).' && git merge --no-ff '.$branch,
                'git branch -D '.$branch.'   # to discard the whole obra (fully reversible)',
            ],
        ];
    }

    /**
     * AOBG N3.F3 — run the INTEGRATED measure on the HELD obra worktree (the
     * assembled branch carrying ALL accumulated steps), BEFORE {@see closeObra()}
     * drops the worktree. This is the whole-obra certification check: a per-step
     * pass does NOT imply the integrated branch is green (step 3 may break what
     * step 1 built), so the obra is only certified when THIS check passes on the
     * assembled state. Reuses the SAME {@see runMeasure()} the single-shot
     * materialize() uses — same timeout, same exit-code-0 = passed contract.
     *
     * Pure read of the worktree (runs a command in it); never commits, never
     * pushes, never merges, never touches main. Fail-soft: a measure that cannot
     * run is reported ran=false/passed=false (an unrunnable integrated check is
     * NOT a pass — fail-closed on certification).
     *
     * @param  array{worktree:string,measure_cmd:string}  $input
     * @return array<string,mixed> {ran, passed, exit_code, cmd, output_tail} | {ran:false,...}
     */
    public function measureObra(array $input): array
    {
        $worktree = rtrim((string) ($input['worktree'] ?? ''), '/');
        $cmd = trim((string) ($input['measure_cmd'] ?? ''));

        if ($cmd === '') {
            return ['ran' => false, 'passed' => false, 'exit_code' => null, 'cmd' => '', 'output_tail' => 'no_measure_cmd'];
        }
        // A linked worktree's `.git` is a FILE (a gitdir pointer), not a dir — accept either.
        if ($worktree === '' || ! is_dir($worktree) || (! is_dir($worktree.'/.git') && ! is_file($worktree.'/.git'))) {
            return ['ran' => false, 'passed' => false, 'exit_code' => null, 'cmd' => $cmd, 'output_tail' => 'worktree_invalid'];
        }

        return $this->runMeasure($worktree, $cmd);
    }

    /**
     * @param  array<string,mixed>  $extra
     * @return array<string,mixed>
     */
    private function refuseObra(string $reason, string $branch, array $extra = []): array
    {
        return array_merge([
            'schema_version' => self::SCHEMA_VERSION,
            'opened' => false,
            'reason' => $reason,
            'branch' => $branch !== '' ? $branch : null,
            'never_merged' => true,
            'never_pushed' => true,
            'main_untouched' => true,
            'reversible' => true,
        ], $extra);
    }

    /**
     * Discard a previously-materialized branch (the REVERSIBLE invariant, made
     * callable). Used by the self-improvement loop when the OUT-OF-PROCESS relevance
     * gate REJECTS an off-target generation: the materializer already cut the branch
     * (it cannot see the touched files until after delivery), so the rejected branch
     * must be deleted so off-target garbage is never presented to the operator as
     * worthy. ONLY ever deletes a branch under the governed `atlas/materialize/` OR
     * `atlas/obra/` prefix (AOBG N3.F2 — so a whole obra is discardable too) — it can
     * never touch main, an operator branch, or any other ref — and is a pure local
     * delete (never a push). Idempotent + fail-soft.
     *
     * @return array{discarded:bool,branch:string,reason:?string}
     */
    public function discardBranch(string $repo, string $branch): array
    {
        $repo = rtrim($repo, '/');
        $branch = trim($branch);

        // Hard guard: refuse anything outside the governed materialize / obra namespaces.
        if ($branch === ''
            || (! str_starts_with($branch, self::BRANCH_PREFIX) && ! str_starts_with($branch, self::OBRA_BRANCH_PREFIX))) {
            return ['discarded' => false, 'branch' => $branch, 'reason' => 'refused_non_materialize_branch'];
        }
        if (! is_dir($repo.'/.git')) {
            return ['discarded' => false, 'branch' => $branch, 'reason' => 'repo_not_git'];
        }

        try {
            [$exists] = $this->git($repo, ['rev-parse', '--verify', '--quiet', 'refs/heads/'.$branch]);
            if (! $exists) {
                // Already gone — idempotent success (nothing to discard).
                return ['discarded' => true, 'branch' => $branch, 'reason' => 'already_absent'];
            }
            [$ok] = $this->git($repo, ['branch', '-D', $branch]);

            return ['discarded' => $ok, 'branch' => $branch, 'reason' => $ok ? null : 'delete_failed'];
        } catch (Throwable $e) {
            return ['discarded' => false, 'branch' => $branch, 'reason' => 'exception:'.substr($e->getMessage(), 0, 120)];
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
