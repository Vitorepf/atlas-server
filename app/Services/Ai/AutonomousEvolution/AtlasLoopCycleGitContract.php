<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

use Symfony\Component\Process\Process;
use Throwable;

/**
 * THE CYCLE GIT CONTRACT — the drift-proof lifecycle the operator mandated (2026-06-17):
 *
 *   ciclo termina -> COMMIT -> MERGE na main -> NOVA branch do próximo ciclo a partir da MAIN FRESCA.
 *
 * The 579-commits-behind worktree incident proved why this must be enforced: a flow that cuts its cycle
 * branch ONCE and never re-syncs drifts arbitrarily far behind main, its merges become an unmergeable mess,
 * and every token spent on the stale base is wasted. This service guarantees the OPPOSITE invariant:
 *
 *   INVARIANT — every new cycle branch is cut from the CURRENT main HEAD (which already contains every prior
 *   cycle's merge), so a freshly-started cycle is ALWAYS 0 commits behind main. Drift is impossible by
 *   construction. The freshness is relative to the canonical $mainRef the caller supplies: the live wiring
 *   always passes config('atlas.loop.base_staleness_main_ref', 'main'), so mainRef MUST be the real main — a
 *   caller that passes a stale ref defeats the guarantee (adversarial finding #4, caller-contract, not a
 *   data-loss). The data-loss holes #1-#3 (concurrent merge / reset over a stale snapshot) are CLOSED by the
 *   exclusive merge lock + the in-lock pre-merge snapshot below.
 *
 * It does NOT replace the governance: the CERTIFICATION that authorizes a merge stays in the frozen judge +
 * AtlasLoopObraAutoMergeService (cert/gates/clean-tree/lock). This is the GIT MECHANICS layer underneath —
 * cut-from-fresh-main, commit, and the mechanical merge — with a STALENESS GUARD that refuses to even START a
 * cycle on a base too far behind main (the "começar 579-atrás tenebroso" the operator forbade).
 *
 * Every step is fail-closed on the moat (a conflict / dirty tree / missing ref refuses; main is never left
 * half-merged) and uses the main repo's own branches; the cycle branch is a governed `atlas/loop/cycle/*` ref
 * that {@see AtlasLoopObraAutoMergeService}-style discard can always reverse.
 */
final class AtlasLoopCycleGitContract
{
    public const SCHEMA = 'atlas.loop.cycle_git_contract.v1';

    public const CYCLE_PREFIX = 'atlas/loop/cycle/';

    private const GIT_TIMEOUT = 120.0;

    /**
     * STALENESS — how many commits `$ref` is BEHIND `$mainRef` (i.e. commits on main not in ref). 0 means
     * fully fresh. null when undeterminable (not a git repo / a ref does not resolve) — callers treat null as
     * "cannot prove staleness" and fail-OPEN on the guard (never a false refuse).
     */
    public function commitsBehindMain(string $repoRoot, string $ref = 'HEAD', string $mainRef = 'main'): ?int
    {
        $repoRoot = rtrim($repoRoot, '/');
        if (! is_dir($repoRoot.'/.git')) {
            return null;
        }
        foreach ([$ref, $mainRef] as $r) {
            [$ok] = $this->git($repoRoot, ['rev-parse', '--verify', '--quiet', $r]);
            if (! $ok) {
                return null;
            }
        }
        [$ok, $out] = $this->git($repoRoot, ['rev-list', '--count', $ref.'..'.$mainRef]);

        return $ok ? (int) trim($out) : null;
    }

    /**
     * START a cycle: cut a fresh branch from the CURRENT main HEAD. The returned base_head is main's HEAD, so
     * the branch is 0 commits behind main BY CONSTRUCTION. Refuses (fail-closed) when main does not resolve, a
     * non-clobberable cycle branch already exists, or — when the staleness guard is armed — the supplied
     * $baseGuardRef is more than $maxCommitsBehind behind main (the anti-"579-atrás" guard).
     *
     * The branch ref is created WITHOUT a checkout, so the main working tree is never disturbed.
     *
     * @return array{ok:bool, reason:?string, branch:?string, base_head:?string, behind:?int}
     */
    public function startCycle(string $repoRoot, string $cycleId, string $mainRef = 'main', int $maxCommitsBehind = 0, ?string $baseGuardRef = null): array
    {
        $repoRoot = rtrim($repoRoot, '/');
        $base = ['schema_version' => self::SCHEMA, 'ok' => false, 'reason' => null, 'branch' => null, 'base_head' => null, 'behind' => null];

        if (! is_dir($repoRoot.'/.git')) {
            return array_merge($base, ['reason' => 'repo_not_git']);
        }
        $branch = self::CYCLE_PREFIX.$this->sanitizeId($cycleId);
        if ($branch === self::CYCLE_PREFIX) {
            return array_merge($base, ['reason' => 'cycle_id_required']);
        }

        // The fresh base is main's CURRENT HEAD.
        [$okMain, $mainHead] = $this->git($repoRoot, ['rev-parse', '--verify', '--quiet', $mainRef]);
        if (! $okMain) {
            return array_merge($base, ['reason' => 'main_ref_unresolvable:'.$mainRef]);
        }
        $mainHead = trim($mainHead);

        // STALENESS GUARD (anti-579): refuse to start a cycle on a base too far behind main. Only enforced
        // when a guard ref is supplied; null staleness (undeterminable) fails OPEN (never a false refuse).
        if ($baseGuardRef !== null) {
            $behind = $this->commitsBehindMain($repoRoot, $baseGuardRef, $mainRef);
            if ($behind !== null && $behind > max(0, $maxCommitsBehind)) {
                return array_merge($base, [
                    'reason' => 'base_too_stale:'.$behind.'_behind_'.$mainRef.' (re-sync before running — never start a cycle on a stale base)',
                    'behind' => $behind,
                ]);
            }
        }

        // A leftover cycle branch from a prior run? Refuse rather than clobber (it is reversible/discardable).
        [$exists] = $this->git($repoRoot, ['rev-parse', '--verify', '--quiet', 'refs/heads/'.$branch]);
        if ($exists) {
            return array_merge($base, ['reason' => 'cycle_branch_already_exists:'.$branch, 'base_head' => $mainHead]);
        }

        // Create the branch ref from the fresh main HEAD WITHOUT checkout (main working tree untouched).
        [$okBranch, $branchOut] = $this->git($repoRoot, ['branch', $branch, $mainHead]);
        if (! $okBranch) {
            return array_merge($base, ['reason' => 'branch_create_failed:'.substr(trim($branchOut), 0, 120), 'base_head' => $mainHead]);
        }

        return [
            'schema_version' => self::SCHEMA,
            'ok' => true,
            'reason' => null,
            'branch' => $branch,
            'base_head' => $mainHead,
            'behind' => 0, // cut from main HEAD => 0 behind, by construction
        ];
    }

    /**
     * COMMIT the cycle's work onto its branch via a private, auto-removed worktree (the main working tree is
     * never touched). $write receives the worktree path and must place the cycle's files there; this then
     * stages + commits. Returns the commit sha. Fail-closed: a worktree/commit fault refuses and cleans up.
     * "nothing_to_commit" is returned (ok=false) when the write produced no change — never an empty commit.
     *
     * @param  callable(string):void  $write  given the worktree path, writes the cycle's files into it
     * @return array{ok:bool, reason:?string, commit:?string, files:list<string>}
     */
    public function commitCycle(string $repoRoot, string $branch, string $message, callable $write): array
    {
        $repoRoot = rtrim($repoRoot, '/');
        $base = ['ok' => false, 'reason' => null, 'commit' => null, 'files' => []];
        if (! $this->isCycleBranch($branch)) {
            return array_merge($base, ['reason' => 'not_a_governed_cycle_branch']);
        }
        [$exists] = $this->git($repoRoot, ['rev-parse', '--verify', '--quiet', 'refs/heads/'.$branch]);
        if (! $exists) {
            return array_merge($base, ['reason' => 'cycle_branch_missing']);
        }

        $worktree = sys_get_temp_dir().'/atlas-cycle-'.bin2hex(random_bytes(5));
        try {
            [$okWt, $wtOut] = $this->git($repoRoot, ['worktree', 'add', '-q', $worktree, $branch]);
            if (! $okWt) {
                return array_merge($base, ['reason' => 'worktree_add_failed:'.substr(trim($wtOut), 0, 120)]);
            }
            try {
                $write($worktree);
            } catch (Throwable $e) {
                return array_merge($base, ['reason' => 'write_failed:'.substr($e->getMessage(), 0, 120)]);
            }
            $this->git($worktree, ['add', '-A']);
            [$stagedOk, $staged] = $this->git($worktree, ['diff', '--cached', '--name-only']);
            $files = $stagedOk ? $this->lines($staged) : [];
            if ($files === []) {
                return array_merge($base, ['reason' => 'nothing_to_commit']);
            }
            [$okCommit, $commitOut] = $this->git($worktree, [
                '-c', 'user.email=cycle@atlas', '-c', 'user.name=atlas-loop',
                'commit', '-q', '-m', $message !== '' ? $message : 'atlas loop cycle', '--no-gpg-sign',
            ]);
            if (! $okCommit) {
                return array_merge($base, ['reason' => 'commit_failed:'.substr(trim($commitOut), 0, 120)]);
            }
            [, $sha] = $this->git($worktree, ['rev-parse', 'HEAD']);

            return ['ok' => true, 'reason' => null, 'commit' => trim($sha), 'files' => $files];
        } finally {
            if (is_dir($worktree)) {
                $this->git($repoRoot, ['worktree', 'remove', '--force', $worktree]);
            }
            $this->git($repoRoot, ['worktree', 'prune']);
        }
    }

    /**
     * MERGE the cycle branch into main, advancing main. The mechanical half of the crossing (the GOVERNANCE —
     * cert/gates — is the caller's, exactly like AtlasLoopObraAutoMergeService gates BEFORE calling a merge).
     * Captures main HEAD before; a conflict/abort restores main to EXACTLY there (fail-closed, never half-
     * merged). Uses a detached worktree checked out on main so the live main working tree is never touched.
     *
     * AFTER this returns merged=true, main HEAD contains the cycle — so the NEXT startCycle() cuts from a main
     * that already has this cycle: 0 drift, guaranteed.
     *
     * @return array{ok:bool, merged:bool, reason:?string, main_head_before:?string, main_head_after:?string}
     */
    public function mergeToMain(string $repoRoot, string $branch, string $mainRef = 'main', bool $deleteBranchOnMerge = true): array
    {
        $repoRoot = rtrim($repoRoot, '/');
        $base = ['schema_version' => self::SCHEMA, 'ok' => false, 'merged' => false, 'reason' => null, 'main_head_before' => null, 'main_head_after' => null];

        if (! is_dir($repoRoot.'/.git')) {
            return array_merge($base, ['reason' => 'repo_not_git']);
        }
        if (! $this->isCycleBranch($branch)) {
            return array_merge($base, ['reason' => 'not_a_governed_cycle_branch']);
        }
        [$brExists] = $this->git($repoRoot, ['rev-parse', '--verify', '--quiet', 'refs/heads/'.$branch]);
        if (! $brExists) {
            return array_merge($base, ['reason' => 'cycle_branch_missing']);
        }

        // EXCLUSIVE LOCK over the WHOLE main-mutating sequence (snapshot + merge + reset + branch -d). Without
        // it, a concurrent merge/checkout/commit can move main between our snapshot and our `reset --hard`,
        // silently DROPPING a certified commit (the adversarial data-loss finding). Non-blocking: a second
        // crossing is REFUSED, not raced. Mirrors AtlasLoopObraAutoMergeService::acquireMergeLock.
        $lock = $this->acquireMergeLock($repoRoot);
        if ($lock === null) {
            return array_merge($base, ['reason' => 'merge_lock_held_by_another_crossing']);
        }

        try {
            // A cycle merge ADVANCES main, so the repo must currently BE on main (loop home-repo contract) and
            // its tree CLEAN (never merge over uncommitted work — the day-2 obra invariant). The merge runs in
            // the main repo itself; a second worktree on main is impossible (git refuses an already-checked-out
            // branch), so this is the only place to advance main.
            [, $current] = $this->git($repoRoot, ['rev-parse', '--abbrev-ref', 'HEAD']);
            if (trim($current) !== $mainRef) {
                return array_merge($base, ['reason' => 'repo_not_on_main:'.trim($current).' (checkout '.$mainRef.' before a cycle merge)']);
            }
            if (! $this->workingTreeClean($repoRoot)) {
                return array_merge($base, ['reason' => 'working_tree_not_clean_refused (never merge over uncommitted work)']);
            }

            // Capture main HEAD INSIDE the lock, immediately before the merge — the lock guarantees nothing else
            // moves main meanwhile, so this snapshot is the EXACT pre-merge main and a safe reset target (it can
            // only ever discard our own failed merge, never concurrent/operator work).
            [$okMain, $mainBefore] = $this->git($repoRoot, ['rev-parse', '--verify', '--quiet', $mainRef]);
            if (! $okMain) {
                return array_merge($base, ['reason' => 'main_ref_unresolvable']);
            }
            $mainBefore = trim($mainBefore);

            [$okMerge, $mergeOut] = $this->git($repoRoot, [
                '-c', 'user.email=cycle@atlas', '-c', 'user.name=atlas-loop',
                'merge', '--no-ff', '--no-edit', '--no-gpg-sign', $branch,
            ]);
            if (! $okMerge) {
                // Conflict / failure: abort + hard-reset main to the in-lock snapshot. main is byte-identical;
                // nothing half-merged, and no concurrent commit can have landed (we hold the lock).
                $this->git($repoRoot, ['merge', '--abort']);
                $this->git($repoRoot, ['reset', '--hard', $mainBefore]);

                return array_merge($base, [
                    'reason' => 'merge_conflict_or_failed:'.substr(trim($mergeOut), 0, 160).' (main untouched)',
                    'main_head_before' => $mainBefore,
                    'main_head_after' => $mainBefore,
                ]);
            }
            [, $mainAfter] = $this->git($repoRoot, ['rev-parse', 'HEAD']);
            $mainAfter = trim($mainAfter);

            if ($deleteBranchOnMerge) {
                // The cycle is now ON main; the branch ref is redundant. `-d` refuses a not-merged branch; it IS
                // merged here. Only ever a governed atlas/loop/cycle/* ref.
                $this->git($repoRoot, ['branch', '-d', $branch]);
            }

            return [
                'schema_version' => self::SCHEMA,
                'ok' => true,
                'merged' => true,
                'reason' => null,
                'main_head_before' => $mainBefore,
                'main_head_after' => $mainAfter,
            ];
        } finally {
            $this->releaseMergeLock($lock);
        }
    }

    /** True only when there is NOTHING uncommitted/untracked — the precondition that makes a cycle merge safe. */
    private function workingTreeClean(string $repoRoot): bool
    {
        [$ok, $out] = $this->git($repoRoot, ['status', '--porcelain']);

        return $ok && trim($out) === '';
    }

    /**
     * Acquire a non-blocking EXCLUSIVE lock for the whole cycle-merge crossing. NULL => another crossing holds
     * it (refuse rather than race). The handle is released explicitly in mergeToMain's finally. Mirrors the
     * proven AtlasLoopObraAutoMergeService lock so the two never race on main either.
     *
     * @return resource|null
     */
    private function acquireMergeLock(string $repoRoot)
    {
        $handle = @fopen($repoRoot.'/.git/atlas-cycle-merge.lock', 'c');
        if ($handle === false) {
            return null;
        }
        if (! flock($handle, LOCK_EX | LOCK_NB)) {
            @fclose($handle);

            return null;
        }

        return $handle;
    }

    /** @param  resource|null  $handle */
    private function releaseMergeLock($handle): void
    {
        if (is_resource($handle)) {
            @flock($handle, LOCK_UN);
            @fclose($handle);
        }
    }

    /** Discard a cycle branch (reversible escape hatch). ONLY ever touches a governed atlas/loop/cycle/* ref. */
    public function discardCycle(string $repoRoot, string $branch): array
    {
        $repoRoot = rtrim($repoRoot, '/');
        if (! $this->isCycleBranch($branch)) {
            return ['discarded' => false, 'branch' => $branch, 'reason' => 'refused_non_cycle_branch'];
        }
        if (! is_dir($repoRoot.'/.git')) {
            return ['discarded' => false, 'branch' => $branch, 'reason' => 'repo_not_git'];
        }
        [$exists] = $this->git($repoRoot, ['rev-parse', '--verify', '--quiet', 'refs/heads/'.$branch]);
        if (! $exists) {
            return ['discarded' => true, 'branch' => $branch, 'reason' => 'already_absent'];
        }
        [$ok] = $this->git($repoRoot, ['branch', '-D', $branch]);

        return ['discarded' => $ok, 'branch' => $branch, 'reason' => $ok ? null : 'delete_failed'];
    }

    private function isCycleBranch(string $branch): bool
    {
        return $branch !== '' && str_starts_with($branch, self::CYCLE_PREFIX);
    }

    private function sanitizeId(string $id): string
    {
        $id = strtolower(preg_replace('/[^A-Za-z0-9._-]+/', '-', $id) ?? '');

        return trim($id, '-.');
    }

    /** @return list<string> */
    private function lines(string $out): array
    {
        return array_values(array_filter(array_map('trim', explode("\n", $out)), static fn (string $l): bool => $l !== ''));
    }

    /**
     * @param  list<string>  $argv
     * @return array{0:bool,1:string}
     */
    private function git(string $cwd, array $argv): array
    {
        $p = new Process(array_merge(['git'], array_values($argv)), $cwd, null, null, self::GIT_TIMEOUT);
        $p->run();

        return [$p->isSuccessful(), $p->getOutput().$p->getErrorOutput()];
    }
}
