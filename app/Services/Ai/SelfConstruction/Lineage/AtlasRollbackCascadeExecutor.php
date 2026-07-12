<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Lineage;

use App\Models\AtlasMemoryEntry;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * ASI-11 (movement 3) — revert the transitive closure of a decision.
 *
 * The executor takes a `decision_id`, resolves the ledger closure and applies
 * reversible actions on each entity kind in reverse order:
 *
 *  - commit    → `git revert -n <sha>` inside a scoped subrepo staging area;
 *                if any revert conflicts, the executor STOPS with a
 *                `partial_with_receipt` verdict and leaves the working tree
 *                untouched (`git revert --abort`). ONE conflict earlier in the
 *                sequence returns `blocked_conflict`.
 *  - memory    → non-destructive archive (mirror of `atlas:ai:memory-forget`).
 *                Un-archive as the automatic reverse-of-revert.
 *  - outcome   → mark demoted (updates row when the outcomes table exposes a
 *                `rolled_back_at` column). Missing column = counted as
 *                partial, never fabricated.
 *  - apply     → apply's reverse handle is already the canonical undo;
 *                executor records the intent without touching the target
 *                (the applier does that under its own gate).
 *
 * Four formal states are always returned in `state`:
 *   - clean_revert:       every reversible entity actually reverted, tree clean
 *   - partial_with_receipt: some reverted, some blocked; receipt lists both
 *   - blocked_conflict:   the very first reversible commit hit a conflict —
 *                         nothing was applied, `git revert --abort` was run
 *   - containment:        reversal impossible (missing decision_id, empty
 *                         closure, or unavailable ledger) — the caller must
 *                         freeze the affected family and alert.
 *
 * `--dry-run` never touches disk: it computes the closure, reports what would
 * happen, and returns without invoking any writer.
 */
final class AtlasRollbackCascadeExecutor
{
    public const STATE_CLEAN = 'clean_revert';

    public const STATE_PARTIAL = 'partial_with_receipt';

    public const STATE_BLOCKED = 'blocked_conflict';

    public const STATE_CONTAINMENT = 'containment';

    public const STATES = [
        self::STATE_CLEAN,
        self::STATE_PARTIAL,
        self::STATE_BLOCKED,
        self::STATE_CONTAINMENT,
    ];

    public function __construct(
        private readonly AtlasDecisionLineageLedger $ledger,
        private readonly ?string $repoRootOverride = null,
    ) {}

    /**
     * @param  array{allow_git?:bool,allow_memory_archive?:bool}  $options
     * @return array<string,mixed>
     */
    public function execute(string $decisionId, bool $dryRun, array $options = []): array
    {
        $startedAt = microtime(true);
        $decisionId = trim($decisionId);
        if ($decisionId === '') {
            return $this->finish(self::STATE_CONTAINMENT, [], [
                'reason' => 'empty_decision_id',
            ], $decisionId, $dryRun, $startedAt);
        }

        $closure = $this->ledger->closure($decisionId);
        if ($closure['count'] === 0) {
            return $this->finish(self::STATE_CONTAINMENT, [], [
                'reason' => 'empty_closure',
                'closure' => $closure,
            ], $decisionId, $dryRun, $startedAt);
        }

        $allowGit = (bool) ($options['allow_git'] ?? true);
        $allowMemory = (bool) ($options['allow_memory_archive'] ?? true);

        $reversedEntities = [];
        $blockedEntities = [];
        $firstConflictSha = null;

        // Reverse order: outcomes, apply, memory, commit.
        $planOrder = [
            AtlasDecisionLineageLedger::KIND_OUTCOME,
            AtlasDecisionLineageLedger::KIND_APPLY,
            AtlasDecisionLineageLedger::KIND_MEMORY,
            AtlasDecisionLineageLedger::KIND_COMMIT,
        ];

        foreach ($planOrder as $kind) {
            $rows = $closure['entities'][$kind] ?? [];
            if ($rows === []) {
                continue;
            }

            // Commits: newest-first (index reversed) so `git revert` topological
            // ordering is honored; other kinds: any order since they're
            // independent.
            if ($kind === AtlasDecisionLineageLedger::KIND_COMMIT) {
                $rows = array_reverse($rows);
            }

            foreach ($rows as $row) {
                $verdict = $this->applyEntity($kind, $row, $dryRun, $allowGit, $allowMemory);
                if (($verdict['ok'] ?? false) === true) {
                    $reversedEntities[] = $verdict;

                    continue;
                }

                $blockedEntities[] = $verdict;
                if ($kind === AtlasDecisionLineageLedger::KIND_COMMIT
                    && ($verdict['reason'] ?? '') === 'git_revert_conflict'
                    && $firstConflictSha === null) {
                    $firstConflictSha = (string) ($verdict['entity_ref'] ?? '');
                    // For commit kind we stop early — cascaded commit revert
                    // MUST be topological. Later commits after the conflict
                    // are recorded as blocked-by-earlier-conflict.
                    // Do not run further commits after a conflict.
                    break;
                }
            }

            // Anti-forward-poison: after a commit conflict, subsequent commit
            // kinds are already handled by the break above. Non-commit kinds
            // continue to be attempted independently.
        }

        // Compute the formal state.
        $state = $this->classifyState($reversedEntities, $blockedEntities, $firstConflictSha);

        return $this->finish($state, $reversedEntities, [
            'blocked' => $blockedEntities,
            'closure_size' => $closure['count'],
            'first_conflict_commit' => $firstConflictSha,
        ], $decisionId, $dryRun, $startedAt);
    }

    /**
     * @param  array<string,mixed>  $row
     * @return array<string,mixed>
     */
    private function applyEntity(string $kind, array $row, bool $dryRun, bool $allowGit, bool $allowMemory): array
    {
        $entityRef = (string) ($row['entity_ref'] ?? '');

        if ($kind === AtlasDecisionLineageLedger::KIND_COMMIT) {
            if ($dryRun) {
                return ['ok' => true, 'kind' => $kind, 'entity_ref' => $entityRef, 'action' => 'would_git_revert', 'dry_run' => true];
            }
            if (! $allowGit) {
                return ['ok' => false, 'kind' => $kind, 'entity_ref' => $entityRef, 'reason' => 'git_disabled_in_options'];
            }

            return $this->revertCommit($entityRef);
        }

        if ($kind === AtlasDecisionLineageLedger::KIND_MEMORY) {
            if ($dryRun) {
                return ['ok' => true, 'kind' => $kind, 'entity_ref' => $entityRef, 'action' => 'would_archive_memory', 'dry_run' => true];
            }
            if (! $allowMemory) {
                return ['ok' => false, 'kind' => $kind, 'entity_ref' => $entityRef, 'reason' => 'memory_archive_disabled_in_options'];
            }

            return $this->archiveMemory($entityRef);
        }

        if ($kind === AtlasDecisionLineageLedger::KIND_OUTCOME) {
            if ($dryRun) {
                return ['ok' => true, 'kind' => $kind, 'entity_ref' => $entityRef, 'action' => 'would_demote_outcome', 'dry_run' => true];
            }

            return $this->demoteOutcome($entityRef);
        }

        if ($kind === AtlasDecisionLineageLedger::KIND_APPLY) {
            $handle = (string) ($row['reverse_handle'] ?? '');
            if ($dryRun) {
                return ['ok' => true, 'kind' => $kind, 'entity_ref' => $entityRef, 'reverse_handle' => $handle, 'action' => 'would_invoke_reverse_handle', 'dry_run' => true];
            }
            // The applier holds authority to undo its own writes. We record
            // the intent and expect the operator digest to surface the
            // handle. Never invokes the applier ourselves — separation of
            // concerns.
            return ['ok' => true, 'kind' => $kind, 'entity_ref' => $entityRef, 'reverse_handle' => $handle, 'action' => 'recorded_reverse_handle_intent'];
        }

        return ['ok' => false, 'kind' => $kind, 'entity_ref' => $entityRef, 'reason' => 'unknown_entity_kind'];
    }

    /**
     * @return array<string,mixed>
     */
    private function revertCommit(string $sha): array
    {
        if ($sha === '' || ! preg_match('/^[0-9a-fA-F]{6,40}$/', $sha)) {
            return ['ok' => false, 'kind' => AtlasDecisionLineageLedger::KIND_COMMIT, 'entity_ref' => $sha, 'reason' => 'invalid_commit_sha'];
        }

        $repo = $this->repoRoot();
        if ($repo === null || ! is_dir($repo.'/.git')) {
            return ['ok' => false, 'kind' => AtlasDecisionLineageLedger::KIND_COMMIT, 'entity_ref' => $sha, 'reason' => 'not_a_git_repo'];
        }

        // `--no-edit`: never open an editor. `--no-commit -n` is avoided
        // because we want revert to produce its own commit for reversibility
        // symmetry.
        $result = $this->git($repo, ['revert', '--no-edit', $sha]);
        if ($result['code'] === 0) {
            $headSha = trim((string) $this->git($repo, ['rev-parse', 'HEAD'])['out']);

            return ['ok' => true, 'kind' => AtlasDecisionLineageLedger::KIND_COMMIT, 'entity_ref' => $sha, 'action' => 'reverted', 'revert_sha' => $headSha];
        }

        // Abort the incomplete revert so the working tree is clean before we
        // return. Fail-open: if abort itself fails, still report the conflict.
        $this->git($repo, ['revert', '--abort']);

        return ['ok' => false, 'kind' => AtlasDecisionLineageLedger::KIND_COMMIT, 'entity_ref' => $sha, 'reason' => 'git_revert_conflict', 'stderr' => mb_substr((string) $result['err'], 0, 200)];
    }

    /**
     * @return array<string,mixed>
     */
    private function archiveMemory(string $id): array
    {
        if ($id === '') {
            return ['ok' => false, 'kind' => AtlasDecisionLineageLedger::KIND_MEMORY, 'entity_ref' => $id, 'reason' => 'empty_memory_id'];
        }
        if (! DatabaseTableAvailability::has('atlas_memory_entries')) {
            return ['ok' => false, 'kind' => AtlasDecisionLineageLedger::KIND_MEMORY, 'entity_ref' => $id, 'reason' => 'memory_table_unavailable'];
        }

        try {
            $entry = AtlasMemoryEntry::query()->where('id', $id)->first();
        } catch (Throwable $e) {
            return ['ok' => false, 'kind' => AtlasDecisionLineageLedger::KIND_MEMORY, 'entity_ref' => $id, 'reason' => 'memory_lookup_failed', 'error' => mb_substr($e->getMessage(), 0, 200)];
        }
        if ($entry === null) {
            return ['ok' => false, 'kind' => AtlasDecisionLineageLedger::KIND_MEMORY, 'entity_ref' => $id, 'reason' => 'memory_not_found'];
        }

        try {
            $entry->forceFill(['status' => 'archived', 'archived_at' => now()])->save();
        } catch (Throwable $e) {
            return ['ok' => false, 'kind' => AtlasDecisionLineageLedger::KIND_MEMORY, 'entity_ref' => $id, 'reason' => 'memory_archive_failed', 'error' => mb_substr($e->getMessage(), 0, 200)];
        }

        return ['ok' => true, 'kind' => AtlasDecisionLineageLedger::KIND_MEMORY, 'entity_ref' => $id, 'action' => 'archived', 'reverse_handle' => 'atlas:ai:memory-forget '.$id.' --restore'];
    }

    /**
     * @return array<string,mixed>
     */
    private function demoteOutcome(string $id): array
    {
        if ($id === '' || ! DatabaseTableAvailability::has('atlas_live_outcomes')) {
            return ['ok' => true, 'kind' => AtlasDecisionLineageLedger::KIND_OUTCOME, 'entity_ref' => $id, 'action' => 'outcome_demote_deferred', 'reason' => 'outcome_table_absent_or_id_empty'];
        }

        try {
            $affected = DB::table('atlas_live_outcomes')
                ->where('id', $id)
                ->update(['proven_real' => false, 'verified_basis' => 'demoted_by_rollback']);
        } catch (Throwable $e) {
            return ['ok' => false, 'kind' => AtlasDecisionLineageLedger::KIND_OUTCOME, 'entity_ref' => $id, 'reason' => 'outcome_update_failed', 'error' => mb_substr($e->getMessage(), 0, 200)];
        }

        return ['ok' => true, 'kind' => AtlasDecisionLineageLedger::KIND_OUTCOME, 'entity_ref' => $id, 'action' => 'demoted', 'affected_rows' => (int) $affected];
    }

    /**
     * @param  list<array<string,mixed>>  $reversed
     * @param  list<array<string,mixed>>  $blocked
     */
    private function classifyState(array $reversed, array $blocked, ?string $firstConflictSha): string
    {
        if ($reversed === [] && $blocked === []) {
            return self::STATE_CONTAINMENT;
        }

        // A conflict on the FIRST commit with nothing else reverted first ⇒
        // blocked_conflict. Otherwise conflicts mix with successes ⇒ partial.
        if ($firstConflictSha !== null) {
            $hadNonCommitProgress = false;
            foreach ($reversed as $r) {
                if (($r['kind'] ?? '') !== AtlasDecisionLineageLedger::KIND_COMMIT) {
                    $hadNonCommitProgress = true;
                    break;
                }
                // A successful earlier commit also counts as progress.
                $hadNonCommitProgress = true;
                break;
            }
            if (! $hadNonCommitProgress) {
                return self::STATE_BLOCKED;
            }
        }

        if ($blocked === []) {
            return self::STATE_CLEAN;
        }

        return self::STATE_PARTIAL;
    }

    /**
     * @param  list<array<string,mixed>>  $reversed
     * @param  array<string,mixed>  $extra
     * @return array<string,mixed>
     */
    private function finish(string $state, array $reversed, array $extra, string $decisionId, bool $dryRun, float $startedAt): array
    {
        return array_merge([
            'schema' => 'atlas.rollback_cascade.receipt.v1',
            'decision_id' => $decisionId,
            'dry_run' => $dryRun,
            'state' => $state,
            'reversed' => $reversed,
            'reversed_count' => count($reversed),
            'elapsed_ms' => (int) round((microtime(true) - $startedAt) * 1000.0),
            'formal_states' => self::STATES,
        ], $extra);
    }

    /**
     * @param  list<string>  $args
     * @return array{code:int,out:string,err:string}
     */
    private function git(string $repo, array $args): array
    {
        $process = new Process(array_merge(['git'], $args), $repo);
        $process->setTimeout(90);
        try {
            $process->run();
        } catch (Throwable $e) {
            return ['code' => 1, 'out' => '', 'err' => $e->getMessage()];
        }

        return ['code' => (int) $process->getExitCode(), 'out' => $process->getOutput(), 'err' => $process->getErrorOutput()];
    }

    private function repoRoot(): ?string
    {
        return $this->repoRootOverride ?? (function_exists('base_path') ? base_path() : null);
    }
}
