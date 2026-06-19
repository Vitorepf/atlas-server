<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Constitution;

use App\Models\AtlasLoopProposal;
use App\Services\Ai\AutonomousEvolution\Contracts\BroaderRegressionGateContract;
use App\Services\Ai\Governance\AtlasChangeClassTrustLadder;
use App\Services\Ai\Rsi\RealRsiGitRevertPort;
use App\Services\Ai\Rsi\RsiGitRevertPort;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * LOOP-OS · FASE 1 · SLICE 1.5 — the POST-MERGE health net (frozen / FORBIDDEN).
 *
 * The per-merge canary proves only the changed file's OWN sibling test. A change that is green in isolation
 * but RED in COMBINATION (it breaks a consumer's suite the canary never selected) can still land on main.
 * This sentinel is the real net: invoked by the EXTERNAL watchdog AFTER each drain (outside DISCOVERY_ROOTS,
 * so a process never health-checks-then-reverts inside its own edit surface), it re-runs the IMPACTED suite
 * over the window's freshly-landed loop commits against post-merge main; on RED it `git revert`s the culprit
 * (NEVER `git reset --hard` — the operator's rule, encoded in {@see RealRsiGitRevertPort}), parks the linked
 * proposal, and feeds the trust ladder a regression so the change class loses its streak.
 *
 * Three safety properties the design hardening demanded:
 *   1. The whole check+revert runs UNDER the single {@see AtlasLoopMergeActuator} lock, so it can never
 *      revert while a drain/obra/cycle crossing is mid-commit (the exact race the Constitution kills).
 *   2. It refuses to revert over a DIRTY working tree (mirrors the obra crossing) — a revert over
 *      uncommitted grind state would entangle it; defer to the next window instead.
 *   3. It reuses the broader-regression gate's FULL evaluate() envelope (not just selectTestPaths), so an
 *      uncovered app/ source FAIL-CLOSES (never a false "healthy") — the invariant is "health-verified or
 *      reverted," never "neither verified nor reverted yet green."
 */
final class AtlasLoopMainHealthSentinel
{
    public const SCHEMA_VERSION = 'atlas.loop.main_health_sentinel.v1';

    /** A landed loop commit is identified by its subject prefix (single-file drain + obra crossings). */
    private const LOOP_SUBJECT_PREFIXES = ['atlas loop auto-merge: ', 'atlas loop obra auto-merge: '];

    public function __construct(
        private readonly ?BroaderRegressionGateContract $gate = null,
        private readonly ?RsiGitRevertPort $revertPort = null,
    ) {}

    /**
     * @return array{schema_version:string, status:string, reverted_sha:?string, reason:?string,
     *               loop_commits:list<string>, changed_files:list<string>}
     */
    public function verify(string $repoRoot, int $windowCommits = 10): array
    {
        $repoRoot = rtrim($repoRoot, '/');
        $base = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => 'skipped',
            'reverted_sha' => null,
            'reason' => null,
            'loop_commits' => [],
            'changed_files' => [],
        ];
        if (! is_dir($repoRoot.'/.git')) {
            return array_merge($base, ['reason' => 'repo_not_git']);
        }

        // Under the SINGLE main-merge lock: a sentinel revert can never interleave with an in-flight crossing.
        $locked = app(AtlasLoopMergeActuator::class)->withMainMergeLock(
            $repoRoot,
            fn (): array => $this->verifyUnderLock($repoRoot, max(1, $windowCommits), $base),
            (float) config('atlas.loop.main_merge_lock_timeout_seconds', 8.0),
        );
        if (($locked['acquired'] ?? false) !== true) {
            return array_merge($base, ['status' => 'deferred', 'reason' => 'lock_'.((string) ($locked['reason'] ?? 'unavailable')).' (a crossing is in-flight; retry next window)']);
        }

        return $locked['result'];
    }

    /**
     * @param  array<string,mixed>  $base
     * @return array<string,mixed>
     */
    private function verifyUnderLock(string $repoRoot, int $windowCommits, array $base): array
    {
        $loopCommits = $this->recentLoopCommits($repoRoot, $windowCommits); // newest-first
        if ($loopCommits === []) {
            return array_merge($base, ['status' => 'healthy', 'reason' => 'no_loop_commits_in_window']);
        }
        $shas = array_map(static fn (array $c): string => $c['sha'], $loopCommits);

        // A revert over a dirty tree would clobber/entangle uncommitted grind state — defer (never revert).
        if (! $this->treeClean($repoRoot)) {
            return array_merge($base, ['status' => 'deferred', 'reason' => 'tree_dirty (defer revert to next window)', 'loop_commits' => $shas]);
        }

        $changed = $this->unionChanged($loopCommits);

        // REUSE the gate's full fail-closed envelope: uncovered app/ source ⇒ NOT passed ⇒ treated as RED,
        // never a false "healthy". (The gate already runs php -l + boot-smoke + the impacted suites.)
        $verdict = ($this->gate ?? app(BroaderRegressionGateContract::class))->evaluate($repoRoot, $changed);
        if (($verdict['passed'] ?? false) === true) {
            return array_merge($base, ['status' => 'healthy', 'reason' => 'impacted_suite_green', 'loop_commits' => $shas, 'changed_files' => $changed]);
        }

        // RED — revert the CULPRIT. A directory-level impacted suite cannot pinpoint which window commit is at
        // fault, so we revert the LATEST loop commit (most recently landed, most likely the regressor). Honest,
        // documented degradation; the next window re-checks and walks back further if it is still RED.
        $culprit = $loopCommits[0];
        $revert = ($this->revertPort ?? new RealRsiGitRevertPort())->revert($repoRoot, $culprit['sha']);
        if (($revert['reverted'] ?? false) !== true) {
            return array_merge($base, ['status' => 'revert_failed', 'reason' => 'git_revert_blocked:'.((string) ($revert['detail'] ?? '?')), 'loop_commits' => $shas, 'changed_files' => $changed]);
        }

        $this->parkAndPenalize($culprit, $changed);

        return array_merge($base, [
            'status' => 'reverted',
            'reverted_sha' => $culprit['sha'],
            'reason' => 'red_main_reverted:'.((string) ($verdict['reason'] ?? 'broader_regression')),
            'loop_commits' => $shas,
            'changed_files' => $changed,
        ]);
    }

    /**
     * The recent window's loop commits, newest-first, each with its changed files + the proposal hash12
     * embedded in the subject (`... [<hash12>]`).
     *
     * @return list<array{sha:string, subject:string, hash12:?string, changed:list<string>}>
     */
    private function recentLoopCommits(string $repoRoot, int $windowCommits): array
    {
        [$ok, $out] = $this->git($repoRoot, ['log', '--no-color', '--format=%H%x09%s', '-n', (string) $windowCommits]);
        if (! $ok) {
            return [];
        }
        $commits = [];
        foreach (preg_split('/\R/', trim($out)) ?: [] as $line) {
            $parts = explode("\t", $line, 2);
            if (count($parts) !== 2) {
                continue;
            }
            [$sha, $subject] = $parts;
            if (! $this->isLoopSubject($subject)) {
                continue;
            }
            $hash12 = preg_match('/\[([0-9a-f]{6,40})\]\s*$/i', $subject, $m) === 1 ? $m[1] : null;
            $commits[] = ['sha' => trim($sha), 'subject' => $subject, 'hash12' => $hash12, 'changed' => $this->changedFiles($repoRoot, trim($sha))];
        }

        return $commits;
    }

    private function isLoopSubject(string $subject): bool
    {
        foreach (self::LOOP_SUBJECT_PREFIXES as $prefix) {
            if (str_starts_with($subject, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private function changedFiles(string $repoRoot, string $sha): array
    {
        [$ok, $out] = $this->git($repoRoot, ['diff-tree', '--no-commit-id', '--name-only', '-r', $sha]);
        if (! $ok) {
            return [];
        }

        return array_values(array_filter(array_map('trim', preg_split('/\R/', trim($out)) ?: []), static fn (string $f): bool => $f !== ''));
    }

    /**
     * @param  list<array{sha:string, subject:string, hash12:?string, changed:list<string>}>  $commits
     * @return list<string>
     */
    private function unionChanged(array $commits): array
    {
        $set = [];
        foreach ($commits as $c) {
            foreach ($c['changed'] as $f) {
                $set[$f] = true;
            }
        }

        return array_keys($set);
    }

    private function treeClean(string $repoRoot): bool
    {
        [$ok, $out] = $this->git($repoRoot, ['status', '--porcelain']);

        return $ok && trim($out) === '';
    }

    /**
     * Park the reverted proposal (hash12 ⇒ the row) and feed the trust ladder a regression. The hash link is
     * fragile: 0 or >1 matches ⇒ revert stays, park is skipped (logged as unlinked) — main is still healthy.
     *
     * @param  array{sha:string, subject:string, hash12:?string, changed:list<string>}  $culprit
     * @param  list<string>  $changed
     */
    private function parkAndPenalize(array $culprit, array $changed): void
    {
        // Trust ladder: a reverted commit is a DETECTED regression for its change class — reset the streak,
        // exactly as the per-merge canary-red path does. Best-effort; the revert already protected main.
        try {
            app(AtlasChangeClassTrustLadder::class)->recordMergeOutcome($changed, $culprit['sha'], ['ran' => true, 'passed' => false]);
        } catch (Throwable) {
            // never crash the sentinel on a ladder hiccup.
        }

        $hash12 = $culprit['hash12'];
        if ($hash12 === null) {
            return; // unlinked revert — no proposal hash in the subject; main is still healthy.
        }
        try {
            $matches = AtlasLoopProposal::query()
                ->where('merged_to_main', true)
                ->where('proposal_hash', 'like', $hash12.'%')
                ->limit(2)
                ->get();
            if ($matches->count() !== 1) {
                return; // 0 or ambiguous ⇒ unlinked revert; do not guess which row to park.
            }
            $proposal = $matches->first();
            $quality = is_array($proposal->quality) ? $proposal->quality : [];
            $quality['_operator_review'] = [
                'schema_version' => 'atlas.loop.operator_review.v1',
                'status' => 'main_health_reverted',
                'reason' => 'post_merge_red_main:'.$culprit['sha'],
                'reviewed_at' => now()->toIso8601String(),
                'decision' => 'reverted_by_main_health_sentinel',
            ];
            // A non-governed save: the model guard forces merged_to_main=false (it IS reverted now), and
            // reviewed_at terminates drainability — exactly the reconciled, parked state we want.
            $proposal->forceFill(['reviewed_at' => now(), 'quality' => $quality])->save();
        } catch (Throwable) {
            // best-effort park; the revert is the load-bearing protection.
        }
    }

    /**
     * @param  list<string>  $args
     * @return array{0:bool,1:string}
     */
    private function git(string $repoRoot, array $args): array
    {
        $p = new Process(array_merge(['git'], $args), $repoRoot, null, null, 60.0);
        $p->run();

        return [$p->isSuccessful(), $p->getOutput().$p->getErrorOutput()];
    }
}
