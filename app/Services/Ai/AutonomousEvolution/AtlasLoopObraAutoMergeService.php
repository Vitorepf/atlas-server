<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

use App\Models\AtlasLoopProposal;
use App\Services\Ai\AutonomousEvolution\Contracts\BroaderRegressionGateContract;
use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * OBRA-AUTO-MERGE (Phase 2, the consequential one) — take a GENUINELY CERTIFIED obra branch
 * and merge it to main WITHOUT operator review, but ONLY AFTER a NEW BROADER REGRESSION GATE
 * passes. This is a SEPARATE, flag-gated path; it does NOT change the obra executor's own
 * NEVER-MERGE contract (the executor still never merges/pushes/touches main — this service is
 * the deliberate, governed crossing the operator authorized).
 *
 * Gated by `atlas.loop.obra_auto_merge_enabled` (env ATLAS_LOOP_OBRA_AUTO_MERGE_ENABLED,
 * DEFAULT FALSE). With it OFF, an obra ALWAYS stays on the operator-review path — this service
 * refuses (status=disabled) and main is never touched.
 *
 * Pipeline (fail-closed at every step; main is byte-identical unless the FULL pipeline is
 * green and the merge commits):
 *   1. FLAG — `obra_auto_merge_enabled` must be ON, else disabled (operator-review path).
 *   2. GENUINE CERTIFICATION — the obra result must report status=done, certified=true, every
 *      node delivered + done (per-node gate green by construction of the executor), the whole-
 *      obra integrated test supplied+ran+passed, never_merged=true, main_untouched=true, and a
 *      governed branch atlas/obra/<id>. A NON-certified obra (needs_review / halted / failed)
 *      is REFUSED — never auto-merged.
 *   3. NET-DIRECTION THROTTLE — the same measured-breakage throttle that governs the single-
 *      file auto-merger; a negative net direction parks the crossing.
 *   4. APPLY-NO-COMMIT — `git merge --no-commit --no-ff <branch>` brings the obra change into
 *      the working tree WITHOUT committing. A conflict aborts (main untouched).
 *   5. THE BROADER REGRESSION GATE ({@see AtlasLoopBroaderRegressionGate}) — map the obra's
 *      changed files to their affected test modules and RUN those suites + the never-merge
 *      invariant test + boot-smoke + php -l. ANY red => `git merge --abort` (main untouched)
 *      and the obra is PARKED for the operator. This is what makes "no operator review" SAFE.
 *   6. COMMIT — only on a GREEN broader gate: commit the merge on main (governed door),
 *      receipt to the Evidence Ledger, brain/compounding accrual is intentionally left to the
 *      operator-review path (this path is conservative by design).
 *
 * Reuses the existing governed door: never-merge default, the model's governed scope
 * (AtlasLoopProposal::$governedMergeInProgress) so any proposal row stamping is governed, the
 * net-direction throttle. The whole crossing is reversible — pre-merge HEAD is captured and
 * any red verdict leaves main exactly where it was.
 */
final class AtlasLoopObraAutoMergeService
{
    public const SCHEMA_VERSION = 'atlas.ai.loop_obra_auto_merge.v1';

    public function __construct(
        private readonly BroaderRegressionGateContract $broaderGate,
    ) {}

    /**
     * Attempt to auto-merge ONE certified obra branch to main.
     *
     * @param  array<string,mixed>  $obra  the AtlasObraExecutor result envelope (status, certified,
     *                              branch, nodes, integrated_test_result, never_merged, main_untouched)
     * @return array{schema_version:string, status:string, merged:bool, reason:?string, branch:?string, commit:?string, broader_gate:?array<string,mixed>}
     */
    public function autoMerge(array $obra, string $repoRoot): array
    {
        $branch = $this->stringOrNull($obra['branch'] ?? null);
        $base = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => 'blocked',
            'merged' => false,
            'reason' => null,
            'branch' => $branch,
            'commit' => null,
            'broader_gate' => null,
        ];

        // 1. FLAG — default OFF. With it OFF, the obra stays on the operator-review path and
        //    main is NEVER touched. This is the hard guarantee for the default-OFF tests.
        if (! (bool) config('atlas.loop.obra_auto_merge_enabled', false)) {
            return array_merge($base, ['status' => 'disabled', 'reason' => 'obra_auto_merge_disabled (operator-review path)']);
        }

        $repoRoot = rtrim($repoRoot, '/');
        if (! is_dir($repoRoot.'/.git')) {
            return array_merge($base, ['reason' => 'repo_root_not_a_git_tree']);
        }
        if ($branch === null || ! str_starts_with($branch, 'atlas/obra/')) {
            return array_merge($base, ['reason' => 'not_a_governed_obra_branch']);
        }

        // 2. GENUINE CERTIFICATION — fail-closed. A non-certified obra is NEVER auto-merged.
        $certVerdict = $this->genuinelyCertified($obra);
        if (! ($certVerdict['certified'] ?? false)) {
            return array_merge($base, ['status' => 'not_certified', 'reason' => 'obra_not_genuinely_certified:'.(string) ($certVerdict['reason'] ?? '?')]);
        }

        // 3. NET-DIRECTION THROTTLE — the measured-breakage dial governs this crossing too.
        $net = app(AtlasLoopNetDirectionGuard::class)->verdict();
        if ((bool) ($net['throttled'] ?? false)) {
            return array_merge($base, ['status' => 'throttled', 'reason' => 'net_direction_throttled:'.(string) ($net['reason'] ?? '')]);
        }

        // The branch must resolve to a real ref in this repo.
        if (! $this->branchExists($repoRoot, $branch)) {
            return array_merge($base, ['reason' => 'obra_branch_missing_in_repo:'.$branch]);
        }

        // DAY-2 SAFETY (adversarial finding): a recovery `git reset --hard` over a DIRTY tree would
        // clobber uncommitted work, and two concurrent crossings (this + the single-file auto-merger)
        // would race on main. (1) REFUSE unless the working tree is CLEAN — this is what makes every
        // later reset --hard provably safe (nothing uncommitted to lose); (2) hold an EXCLUSIVE,
        // non-blocking lock for the whole git-mutating sequence (auto-released when this method
        // returns) — a second crossing is refused rather than racing.
        if (! $this->workingTreeClean($repoRoot)) {
            return array_merge($base, ['reason' => 'working_tree_not_clean_refused (never reset --hard over uncommitted work)']);
        }
        $mergeLock = $this->acquireMergeLock($repoRoot);
        if ($mergeLock === null) {
            return array_merge($base, ['reason' => 'merge_lock_held_by_another_crossing']);
        }

        // Capture pre-merge HEAD: every red verdict / abort restores main to EXACTLY here.
        $headBefore = $this->headSha($repoRoot);
        if ($headBefore === null) {
            return array_merge($base, ['reason' => 'cannot_resolve_head']);
        }

        // 4. APPLY-NO-COMMIT — bring the obra change into the working tree WITHOUT committing.
        //    A conflict / dirty tree aborts and leaves main untouched.
        $merge = $this->git($repoRoot, ['merge', '--no-commit', '--no-ff', $branch]);
        if (! $merge) {
            // Abort any partial merge state; main must be byte-identical after a failed apply.
            $this->git($repoRoot, ['merge', '--abort']);
            $this->hardResetTo($repoRoot, $headBefore);

            return array_merge($base, ['reason' => 'obra_merge_apply_conflict (main untouched)']);
        }

        try {
            // 5. THE BROADER REGRESSION GATE — the load-bearing safety piece. Runs the affected
            //    test modules + the never-merge invariant test + boot-smoke + php -l against the
            //    APPLIED (not-yet-committed) tree. ANY red => abort, main untouched, park.
            $changed = $this->obraChangedFiles($repoRoot, $obra, $headBefore);
            $gate = $this->broaderGate->evaluate($repoRoot, $changed);
            $base['broader_gate'] = $gate;

            if (! ($gate['passed'] ?? false)) {
                $this->git($repoRoot, ['merge', '--abort']);
                $this->hardResetTo($repoRoot, $headBefore);

                return array_merge($base, [
                    'status' => 'broader_gate_red',
                    'reason' => 'broader_regression_gate_red:'.(string) ($gate['reason'] ?? '?').' (merge BLOCKED, main untouched, parked for operator)',
                    'broader_gate' => $gate,
                ]);
            }

            // 6. COMMIT — only on a GREEN broader gate. The merge is already staged by
            //    `--no-commit`; commit it on main under the governed door + receipt.
            $msg = 'atlas loop obra auto-merge: '.$branch.' (broader-regression GREEN)';
            $committed = $this->git($repoRoot, ['-c', 'user.email=loop@atlas', '-c', 'user.name=atlas-loop', 'commit', '-q', '-m', $msg, '--no-gpg-sign']);
            if (! $committed) {
                $this->git($repoRoot, ['merge', '--abort']);
                $this->hardResetTo($repoRoot, $headBefore);

                return array_merge($base, ['reason' => 'commit_failed (main untouched)']);
            }
            $commit = $this->headSha($repoRoot);

            $this->receipt($obra, $branch, $commit, $changed, $gate);

            return array_merge($base, [
                'status' => 'merged',
                'merged' => true,
                'commit' => $commit,
                'broader_gate' => $gate,
            ]);
        } catch (Throwable $e) {
            // Any unexpected error: abort + restore. main is byte-identical.
            $this->git($repoRoot, ['merge', '--abort']);
            $this->hardResetTo($repoRoot, $headBefore);

            return array_merge($base, ['reason' => 'error:'.mb_substr($e->getMessage(), 0, 160).' (main untouched)']);
        }
    }

    /**
     * Is the obra GENUINELY certified? Fail-closed: every load-bearing fact must be present.
     * A needs_review / halted / failed obra, a per-node failure, an absent/unrun/failed
     * integrated test, or an already-merged obra is NOT certified.
     *
     * @param  array<string,mixed>  $obra
     * @return array{certified:bool, reason:?string}
     */
    private function genuinelyCertified(array $obra): array
    {
        if (\App\Services\Ai\Obra\AtlasObraExecutor::STATUS_DONE !== (string) ($obra['status'] ?? '')) {
            return ['certified' => false, 'reason' => 'status_not_done:'.(string) ($obra['status'] ?? '')];
        }
        if (($obra['certified'] ?? false) !== true) {
            return ['certified' => false, 'reason' => 'certified_flag_false'];
        }
        if (($obra['never_merged'] ?? false) !== true) {
            return ['certified' => false, 'reason' => 'already_merged_or_never_merged_false'];
        }
        if (($obra['main_untouched'] ?? false) !== true) {
            return ['certified' => false, 'reason' => 'main_not_untouched'];
        }

        $nodes = array_values((array) ($obra['nodes'] ?? []));
        if ($nodes === []) {
            return ['certified' => false, 'reason' => 'no_nodes'];
        }
        $deliveredNodes = (int) ($obra['delivered_nodes'] ?? 0);
        $nodeCount = (int) ($obra['node_count'] ?? count($nodes));
        if ($deliveredNodes < 1 || $deliveredNodes !== $nodeCount) {
            return ['certified' => false, 'reason' => 'not_every_node_delivered:'.$deliveredNodes.'/'.$nodeCount];
        }
        foreach ($nodes as $node) {
            if (! is_array($node)) {
                return ['certified' => false, 'reason' => 'malformed_node'];
            }
            if ((string) ($node['status'] ?? '') !== \App\Services\Ai\Obra\AtlasObraExecutor::NODE_DONE) {
                return ['certified' => false, 'reason' => 'node_not_done:'.(string) ($node['id'] ?? '?')];
            }
        }

        // The WHOLE-obra integrated test must have been SUPPLIED, RUN and PASSED. A per-step
        // pass does NOT imply the obra integrates — this is the executor's own F3 contract.
        $integrated = (array) ($obra['integrated_test_result'] ?? []);
        if (! (bool) ($integrated['supplied'] ?? false) || ! (bool) ($integrated['ran'] ?? false) || ! (bool) ($integrated['passed'] ?? false)) {
            return ['certified' => false, 'reason' => 'integrated_test_not_green'];
        }

        return ['certified' => true, 'reason' => null];
    }

    /**
     * The obra's changed files relative to repo root. Prefer the diff of the obra branch
     * against the pre-merge HEAD (the authoritative set the merge actually applies); fall back
     * to the executor-reported node files_changed if the diff cannot run.
     *
     * @param  array<string,mixed>  $obra
     * @return list<string>
     */
    private function obraChangedFiles(string $repoRoot, array $obra, string $headBefore): array
    {
        $branch = (string) ($obra['branch'] ?? '');
        $files = [];
        if ($branch !== '') {
            $p = new Process(['git', 'diff', '--name-only', '--no-ext-diff', $headBefore, $branch], $repoRoot, null, null, 60.0);
            $p->run();
            if ($p->isSuccessful()) {
                foreach (preg_split('/\R/', trim($p->getOutput())) ?: [] as $line) {
                    $line = trim($line);
                    if ($line !== '') {
                        $files[$line] = true;
                    }
                }
            }
        }
        if ($files === []) {
            foreach ($obra['nodes'] ?? [] as $node) {
                if (! is_array($node)) {
                    continue;
                }
                foreach ((array) ($node['files_changed'] ?? []) as $f) {
                    if (is_string($f) && trim($f) !== '') {
                        $files[trim($f)] = true;
                    }
                }
            }
        }

        return array_keys($files);
    }

    private function branchExists(string $repoRoot, string $branch): bool
    {
        return $this->git($repoRoot, ['rev-parse', '--verify', '--quiet', 'refs/heads/'.$branch]);
    }

    private function hardResetTo(string $repoRoot, string $sha): void
    {
        $this->git($repoRoot, ['reset', '--hard', $sha]);
    }

    /** True only when there is NOTHING uncommitted/untracked — the precondition that makes a recovery reset --hard safe. */
    private function workingTreeClean(string $repoRoot): bool
    {
        $p = new Process(['git', 'status', '--porcelain'], $repoRoot, null, null, 30.0);
        $p->run();

        return $p->isSuccessful() && trim($p->getOutput()) === '';
    }

    /**
     * Acquire a non-blocking EXCLUSIVE lock for the git-mutating crossing. The returned handle is
     * held by the caller; PHP releases the lock when it goes out of scope (the autoMerge() return),
     * so no explicit unlock is needed on the many early-return paths. NULL = a crossing is already
     * in flight (refuse rather than race).
     *
     * @return resource|null
     */
    private function acquireMergeLock(string $repoRoot)
    {
        $handle = @fopen($repoRoot.'/.git/atlas-obra-automerge.lock', 'c');
        if ($handle === false) {
            return null;
        }
        if (! flock($handle, LOCK_EX | LOCK_NB)) {
            @fclose($handle);

            return null;
        }

        return $handle;
    }

    private function headSha(string $repoRoot): ?string
    {
        $p = new Process(['git', 'rev-parse', 'HEAD'], $repoRoot, null, null, 15.0);
        $p->run();

        return $p->isSuccessful() ? trim($p->getOutput()) : null;
    }

    /**
     * @param  list<string>  $argv
     */
    private function git(string $cwd, array $argv): bool
    {
        $p = new Process(array_merge(['git'], array_values($argv)), $cwd, null, null, 120.0);
        $p->run();

        return $p->isSuccessful();
    }

    /**
     * Receipt to the Evidence Ledger under the governed scope. Best-effort — the merge does
     * not depend on it. The governed scope is opened so any incidental proposal-row stamping
     * inside the ledger write path stays inside the door (defense-in-depth; the ledger itself
     * does not write proposals, but the scope makes the crossing auditable as governed).
     *
     * @param  array<string,mixed>  $obra
     * @param  list<string>  $changed
     * @param  array<string,mixed>  $gate
     */
    private function receipt(array $obra, string $branch, ?string $commit, array $changed, array $gate): void
    {
        $this->governed(function () use ($obra, $branch, $commit, $changed, $gate): void {
            try {
                app(AtlasEvidenceLedger::class)->record(
                    LedgerEventType::DecisionIssued,
                    [
                        'schema_version' => self::SCHEMA_VERSION,
                        'decision' => 'loop_obra_auto_merge_to_main',
                        'obra_id' => (string) ($obra['plan_id'] ?? ''),
                        'branch' => $branch,
                        'commit' => $commit,
                        'node_count' => (int) ($obra['node_count'] ?? 0),
                        'delivered_nodes' => (int) ($obra['delivered_nodes'] ?? 0),
                        'changed_files' => array_values($changed),
                        'broader_regression_gate' => [
                            'passed' => (bool) ($gate['passed'] ?? false),
                            'selected_tests' => array_values((array) ($gate['selected_tests'] ?? [])),
                            'boot_smoke' => $gate['boot_smoke'] ?? null,
                        ],
                        'policy' => 'obra_auto_merge_v2_broader_regression_gate',
                    ],
                    [
                        'operator_id' => 'operator-authorized-obra-auto-merge-v2',
                        'emitter_stage' => 'atlas.ai.loop_obra_auto_merge',
                        'emitter_version' => 'loop-obra-auto-merge-v1',
                    ],
                );
            } catch (Throwable) {
                // receipt is best-effort; the merge already happened.
            }
        });
    }

    /**
     * Open the governed merge door (model static guard + pgsql session var inside the txn),
     * exactly like {@see AtlasLoopAutoMergeService::governedSave}. Any proposal-row save that
     * happens inside stays governed; nothing else can mark merged.
     */
    private function governed(callable $write): void
    {
        AtlasLoopProposal::$governedMergeInProgress = true;
        try {
            DB::transaction(function () use ($write): void {
                if (DB::connection()->getDriverName() === 'pgsql') {
                    DB::statement("SET LOCAL atlas.governed_merge = 'on'");
                }
                $write();
            });
        } catch (Throwable) {
            // The merge does not depend on the receipt write; never let it escape.
        } finally {
            AtlasLoopProposal::$governedMergeInProgress = false;
        }
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
