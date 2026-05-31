<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\Mission\MissionCanonicalHash;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

/**
 * AP-769 · Stewardship Branch Merge Governor.
 *
 * Enterprise branch safety layer for 24/7 stewardship loops. It certifies that a
 * cycle branch is visible/reviewable, conflict-free against the current base,
 * low-risk enough for the requested merge mode, and only then permits an
 * optional ff-only auto-merge. By default it never rebases, force-pushes,
 * squashes, deploys or touches secrets. Pass rebase_diverged_before_evaluation=true
 * to attempt a non-destructive rebase of the branch onto the base inside the
 * branch's declared worktree before policy evaluation — this is opt-in so all
 * existing callers remain unaffected.
 *
 * When a merge is policy-eligible but cannot proceed (dirty worktree, divergence,
 * nothing-to-merge), the branch is enqueued in LoopMergeRetryQueueService so it
 * survives to the next iteration instead of being silently dropped. Before each
 * new evaluate() call the pending queue is drained so accepted diffs land as soon
 * as the loop is healthy again.
 */
final class StewardshipBranchMergeGovernorService implements StewardshipBranchMergeGovernor
{
    public const REPORT_SCHEMA = 'atlas.software_company_stewardship.branch_merge_governor.v1';

    public const RECORD_SCHEMA = 'atlas.software_company_stewardship.branch_merge_governor_record.v1';

    public const STATUS_REVIEW_REQUIRED = 'review_required';

    public const STATUS_AUTO_MERGE_ELIGIBLE = 'auto_merge_eligible';

    public const STATUS_MERGED = 'merged';

    public const STATUS_BLOCKED = 'blocked';

    public const DEFAULT_AREA_ID = 'agentic_engineering_os';

    private ?string $storageRootOverride = null;

    /** @var array<string, string> */
    private array $revParseCache = [];

    private readonly StewardshipMergeAutonomyPolicyService $autonomyPolicy;

    private readonly LoopMergeRetryQueueService $mergeRetryQueue;

    public function __construct(
        StewardshipMergeAutonomyPolicyService $autonomyPolicy,
        ?LoopMergeRetryQueueService $mergeRetryQueue = null,
    ) {
        $this->autonomyPolicy = $autonomyPolicy;
        $this->mergeRetryQueue = $mergeRetryQueue ?? new LoopMergeRetryQueueService;
    }

    public function setStorageRootForTesting(?string $dir): void
    {
        $this->storageRootOverride = $dir;
        $this->mergeRetryQueue->setStorageRootForTesting($dir !== null ? $dir.'/merge_retry_queue' : null);
    }

    public function storageDir(): string
    {
        if ($this->storageRootOverride !== null) {
            return $this->storageRootOverride;
        }

        return function_exists('storage_path')
            ? storage_path('atlas/software_company_stewardship/branch_merge_governor')
            : sys_get_temp_dir().'/atlas/software_company_stewardship/branch_merge_governor';
    }

    public function recordPath(string $areaId): string
    {
        return $this->storageDir().DIRECTORY_SEPARATOR.$this->slug($areaId).'.jsonl';
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function evaluate(array $input): array
    {
        $this->revParseCache = [];
        $areaId = $this->slug((string) ($input['area_id'] ?? self::DEFAULT_AREA_ID));

        // Drain any previously accepted-but-not-merged branches before evaluating
        // the new candidate. This ensures accepted diffs land as soon as the loop
        // is healthy again without requiring a separate orchestration step.
        $queueDrainResult = null;
        if ($this->mergeRetryQueue !== null && $this->mergeRetryQueue->hasPending()) {
            $baseRef = trim((string) ($input['base_ref'] ?? 'main')) ?: 'main';
            $queueDrainResult = $this->mergeRetryQueue->processQueue($baseRef);
        }
        $repoRoot = $this->repoRoot($input);
        if ($repoRoot === '') {
            return $this->blocked($areaId, 'repo_root_required', 'A git repository root is required.');
        }

        $branchRef = trim((string) ($input['branch_ref'] ?? $input['branch'] ?? ''));
        if ($branchRef === '') {
            return $this->blocked($areaId, 'branch_ref_required', 'A cycle branch ref is required.', ['repo_root' => $repoRoot]);
        }

        $baseRef = trim((string) ($input['base_ref'] ?? 'main')) ?: 'main';
        if (! $this->isGitRepo($repoRoot)) {
            return $this->blocked($areaId, 'repo_root_not_git_repository', 'Repo root is not a git repository.', ['repo_root' => $repoRoot]);
        }

        if ($this->revParse($repoRoot, $branchRef) === '') {
            return $this->blocked($areaId, 'branch_ref_not_found', 'Cycle branch ref was not found.', ['repo_root' => $repoRoot, 'branch_ref' => $branchRef]);
        }
        if ($this->revParse($repoRoot, $baseRef) === '') {
            return $this->blocked($areaId, 'base_ref_not_found', 'Base ref was not found.', ['repo_root' => $repoRoot, 'base_ref' => $baseRef]);
        }

        // Opt-in: rebase branch onto base inside its worktree before evaluation.
        // When auto-merge is requested against a diverged branch, rebasing here
        // lets the subsequent policy and conflict checks run against a rebased
        // diff instead of immediately returning branch_not_rebased_on_current_base.
        // Callers must explicitly set rebase_diverged_before_evaluation=true;
        // default behaviour is unchanged.
        $rebasePerformed = false;
        $rebaseAttemptResult = null;
        if ((bool) ($input['rebase_diverged_before_evaluation'] ?? false)) {
            $worktreePath = trim((string) ($input['worktree_path'] ?? ''));
            if ($worktreePath !== '' && is_dir($worktreePath)
                && ! $this->isAncestor($repoRoot, $baseRef, $branchRef)
            ) {
                $rebaseAttemptResult = $this->attemptRebaseInWorktree($worktreePath, $baseRef);
                if ($rebaseAttemptResult['ok']) {
                    $rebasePerformed = true;
                    // Invalidate cached pre-rebase hash so all subsequent revParse
                    // calls for this branch return the new post-rebase commit.
                    unset($this->revParseCache[$repoRoot."\0".$branchRef]);
                }
            }
        }

        $baseCommit = $this->revParse($repoRoot, $baseRef);
        $branchCommit = $this->revParse($repoRoot, $branchRef);
        $mergeBase = $this->mergeBase($repoRoot, $baseRef, $branchRef);
        $baseIsAncestor = $this->isAncestor($repoRoot, $baseRef, $branchRef);
        $branchIsAncestor = $this->isAncestor($repoRoot, $branchRef, $baseRef);
        [$baseOnly, $branchOnly] = $this->aheadBehind($repoRoot, $baseRef, $branchRef);
        $changedFiles = $this->changedFiles($repoRoot, $baseRef, $branchRef);
        $commits = $this->commits($repoRoot, $baseRef, $branchRef);
        $mergeTree = $this->mergeTree($repoRoot, $baseRef, $branchRef);
        $classification = $this->classify($changedFiles, (string) ($input['auto_merge_class'] ?? ''));
        $validation = $this->validation($input, $repoRoot);
        $workingTreeClean = $this->workingTreeClean($repoRoot);
        $executeMerge = (bool) ($input['execute_merge'] ?? false);
        $autoMergeRequested = (bool) ($input['auto_merge'] ?? false);

        $blockers = [];
        if ($branchIsAncestor) {
            $blockers[] = 'branch_already_merged_or_ancestor_of_base';
        }
        if (! $baseIsAncestor) {
            $blockers[] = 'branch_not_rebased_on_current_base';
        }
        if (! (bool) ($mergeTree['clean'] ?? false)) {
            $blockers[] = 'merge_conflict_detected';
        }
        if ($executeMerge && ! $workingTreeClean) {
            $blockers[] = 'base_worktree_dirty';
        }
        // Provider-proof (SEC-001 defense-in-depth at the single merge authority):
        // a forge diff produced with zero provider calls is unattributed (stray
        // worktree files / local stub) and must never auto-merge even if
        // classification and validation pass. Enforced only when the caller
        // supplies the provider-call count (the loop owner-flow does), so existing
        // callers are unaffected.
        if (array_key_exists('owner_cli_provider_calls', $input)
            && strtolower(trim((string) ($input['owner'] ?? ''))) === 'forge'
            && $changedFiles !== []
            && (int) $input['owner_cli_provider_calls'] <= 0
        ) {
            $blockers[] = 'forge_diff_without_provider_proof';
        }

        $policyChangedFiles = $this->policyChangedFiles($changedFiles);
        $excludedGovernancePaths = array_values(array_diff($changedFiles, $policyChangedFiles));
        $policyClassification = $this->classify($policyChangedFiles, (string) ($input['auto_merge_class'] ?? ''));
        $autonomyClassification = $this->autonomyClassification(
            $classification,
            $policyClassification,
            $policyChangedFiles,
        );
        $autoPolicy = $this->autoMergePolicy($autonomyClassification, $validation, $policyChangedFiles, $branchOnly, $blockers, $input);
        $retryableOperationalBlockers = $this->retryableOperationalMergeBlockers($blockers);
        $retryPolicy = null;
        if (($executeMerge || $autoMergeRequested) && $retryableOperationalBlockers !== []) {
            $retryPolicy = $this->autoMergePolicy(
                $autonomyClassification,
                $validation,
                $policyChangedFiles,
                $branchOnly,
                $this->withoutValues($blockers, $retryableOperationalBlockers),
                $input,
            );
        }
        $status = $autoPolicy['eligible'] ? self::STATUS_AUTO_MERGE_ELIGIBLE : self::STATUS_REVIEW_REQUIRED;
        if ($blockers !== []) {
            $status = self::STATUS_BLOCKED;
        }

        $mergeResult = null;
        $mergeRetryEnqueued = false;
        $mergeRetryReason = '';
        if ($executeMerge || $autoMergeRequested) {
            if (! $autoPolicy['eligible']) {
                $blockers[] = 'auto_merge_policy_not_satisfied';
                $status = self::STATUS_BLOCKED;
                if ($this->mergeRetryQueue !== null
                    && $retryPolicy !== null
                    && (bool) ($retryPolicy['eligible'] ?? false) === true
                ) {
                    $findingKey = trim((string) ($input['finding_id'] ?? $input['finding_key'] ?? $branchRef));
                    $mergeRetryReason = implode('+', $retryableOperationalBlockers);
                    $this->mergeRetryQueue->enqueue(
                        $branchRef,
                        $findingKey,
                        $this->acceptedDiffRef($branchRef, $branchCommit),
                        $mergeRetryReason,
                    );
                    $mergeRetryEnqueued = true;
                }
            } elseif (! $executeMerge) {
                $status = self::STATUS_AUTO_MERGE_ELIGIBLE;
                // Branch is accepted by policy but execute_merge=false: enqueue so
                // a later iteration can attempt the ff-only merge once the caller
                // is ready to permit execution.
                if ($this->mergeRetryQueue !== null) {
                    $findingKey = trim((string) ($input['finding_id'] ?? $input['finding_key'] ?? $branchRef));
                    $acceptedDiff = (string) ($input['accepted_diff'] ?? $this->acceptedDiffRef($branchRef, $branchCommit));
                    $this->mergeRetryQueue->enqueue($branchRef, $findingKey, $acceptedDiff, 'auto_merge_eligible_execute_not_requested');
                }
            } else {
                $mergeResult = $this->mergeFfOnly($repoRoot, $baseRef, $branchRef);
                if (($mergeResult['status'] ?? '') === self::STATUS_MERGED) {
                    $status = self::STATUS_MERGED;
                    $baseCommit = $this->revParse($repoRoot, $baseRef);
                } else {
                    $status = self::STATUS_BLOCKED;
                    $failReason = (string) ($mergeResult['reason'] ?? 'ff_only_merge_failed');
                    $blockers[] = $failReason;
                    // Eligible but merge did not land: persist to retry queue so the
                    // accepted diff is not lost. The queue will attempt rebase+merge on
                    // the next loop iteration (up to maxAttempts times before escalating).
                    if ($this->mergeRetryQueue !== null) {
                        $findingKey = trim((string) ($input['finding_id'] ?? $input['finding_key'] ?? $branchRef));
                        $acceptedDiff = (string) ($input['accepted_diff'] ?? $this->acceptedDiffRef($branchRef, $branchCommit));
                        $this->mergeRetryQueue->enqueue($branchRef, $findingKey, $acceptedDiff, $failReason);
                    }
                }
            }
        }

        $payload = [
            'schema_version' => self::REPORT_SCHEMA,
            'ap_contract' => 'AP-769',
            'status' => $status,
            'area_id' => $areaId,
            'stack' => 'Atlas Software Company Stewardship Stack',
            'source_ap_contracts' => ['AP-756', 'AP-765', 'AP-767', 'AP-768', 'AP-769', 'AP-774'],
            'repo' => [
                'repo_root' => $repoRoot,
                'repo_root_hash' => hash('sha256', $repoRoot),
                'base_ref' => $baseRef,
                'base_commit' => $baseCommit,
                'branch_ref' => $branchRef,
                'branch_commit' => $branchCommit,
                'merge_base' => $mergeBase,
                'base_is_ancestor_of_branch' => $baseIsAncestor,
                'branch_is_ancestor_of_base' => $branchIsAncestor,
                'base_only_commit_count' => $baseOnly,
                'branch_only_commit_count' => $branchOnly,
                'working_tree_clean' => $workingTreeClean,
            ],
            'gitkraken_review_surface' => $this->gitkrakenReviewSurface(
                $branchRef,
                $baseRef,
                $commits,
                $changedFiles,
                $baseIsAncestor,
                $input,
            ),
            'classification' => $classification,
            'throughput_evidence' => [
                'governance_artifact_count' => count($classification['governance_files'] ?? []),
                'policy_changed_file_count' => count($policyChangedFiles),
                'full_changed_file_count' => count($changedFiles),
                'governance_artifacts_excluded_from_auto_merge_policy' => count($changedFiles) !== count($policyChangedFiles),
                'excluded_governance_paths' => $excludedGovernancePaths,
                'policy_classification_kind' => $policyClassification['kind'],
                'autonomy_classification_kind' => $autonomyClassification['kind'],
                'autonomy_uses_policy_surface' => $policyChangedFiles !== $changedFiles,
            ],
            'merge_conflict_check' => $mergeTree,
            'validation' => $validation,
            'auto_merge_policy' => $autoPolicy,
            'auto_merge_policy_without_retryable_operational_blockers' => $retryPolicy,
            'merge_result' => $mergeResult,
            'blockers' => array_values(array_unique($blockers)),
            'next_actions' => $this->nextActions($status, $autoPolicy, $blockers, $branchRef, $baseRef),
            'claim_policy' => $this->claimPolicy($status, $rebasePerformed),
            'rebase_attempt' => $rebaseAttemptResult,
            'merge_retry_queue_drain' => $queueDrainResult,
            'merge_retry_queue_enqueued' => $mergeRetryEnqueued,
            'merge_retry_queue_reason' => $mergeRetryReason,
            'generated_at' => $this->now(),
        ];

        $payload['governor_hash'] = 'sha256:'.MissionCanonicalHash::sha256($this->identity($payload));

        return $this->maybeRecord($areaId, $payload, (bool) ($input['record_governance'] ?? false));
    }

    /**
     * @return array<string,mixed>
     */
    public function listRecords(string $areaId): array
    {
        $path = $this->recordPath($areaId);
        $records = [];
        if (is_file($path)) {
            foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $decoded = json_decode($line, true);
                if (is_array($decoded) && ($decoded['schema_version'] ?? '') === self::RECORD_SCHEMA) {
                    $records[] = $decoded;
                }
            }
        }

        return [
            'schema_version' => 'atlas.software_company_stewardship.branch_merge_governor_records.v1',
            'status' => 'ready',
            'area_id' => $areaId,
            'record_count' => count($records),
            'records' => $records,
        ];
    }

    /**
     * @param  array<string,mixed>  $input
     */
    private function repoRoot(array $input): string
    {
        $candidate = trim((string) ($input['repo_root'] ?? ''));
        if ($candidate === '' && function_exists('base_path')) {
            $candidate = base_path();
        }
        if ($candidate === '') {
            $candidate = getcwd() ?: '';
        }

        return $candidate !== '' ? (realpath($candidate) ?: $candidate) : '';
    }

    private function isGitRepo(string $repoRoot): bool
    {
        return $this->git($repoRoot, ['rev-parse', '--is-inside-work-tree'])['ok'] === true;
    }

    private function revParse(string $repoRoot, string $ref): string
    {
        $cacheKey = $repoRoot."\0".$ref;
        if (array_key_exists($cacheKey, $this->revParseCache)) {
            return $this->revParseCache[$cacheKey];
        }

        $resolved = $this->headCommit($repoRoot, $ref);
        $this->revParseCache[$cacheKey] = $resolved;

        return $resolved;
    }

    /**
     * Resolve a ref to its commit hash with a fresh, uncached git call. Use this
     * (not revParse) whenever the ref can move during the same service lifetime,
     * e.g. observing the base head before and after a merge.
     */
    private function headCommit(string $repoRoot, string $ref): string
    {
        $result = $this->git($repoRoot, ['rev-parse', '--verify', $ref]);

        return $result['ok'] ? trim((string) $result['out']) : '';
    }

    private function mergeBase(string $repoRoot, string $baseRef, string $branchRef): string
    {
        $result = $this->git($repoRoot, ['merge-base', $baseRef, $branchRef]);

        return $result['ok'] ? trim((string) $result['out']) : '';
    }

    private function isAncestor(string $repoRoot, string $ancestor, string $descendant): bool
    {
        return $this->git($repoRoot, ['merge-base', '--is-ancestor', $ancestor, $descendant])['ok'] === true;
    }

    /**
     * @return array{0:int,1:int}
     */
    private function aheadBehind(string $repoRoot, string $baseRef, string $branchRef): array
    {
        $result = $this->git($repoRoot, ['rev-list', '--left-right', '--count', $baseRef.'...'.$branchRef]);
        if (! $result['ok']) {
            return [0, 0];
        }
        $parts = preg_split('/\s+/', trim((string) $result['out'])) ?: [];

        return [(int) ($parts[0] ?? 0), (int) ($parts[1] ?? 0)];
    }

    /**
     * @return list<string>
     */
    private function changedFiles(string $repoRoot, string $baseRef, string $branchRef): array
    {
        $result = $this->git($repoRoot, ['diff', '--name-only', $baseRef.'...'.$branchRef]);
        if (! $result['ok']) {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode("\n", (string) $result['out']))));
    }

    /**
     * @return list<array<string,string>>
     */
    private function commits(string $repoRoot, string $baseRef, string $branchRef): array
    {
        $result = $this->git($repoRoot, ['log', '--format=%H%x1f%h%x1f%s', $baseRef.'..'.$branchRef]);
        if (! $result['ok']) {
            return [];
        }

        $commits = [];
        foreach (array_filter(explode("\n", (string) $result['out'])) as $line) {
            [$hash, $short, $subject] = array_pad(explode("\x1f", $line, 3), 3, '');
            $commits[] = ['hash' => $hash, 'short_hash' => $short, 'subject' => $subject];
        }

        return $commits;
    }

    /**
     * @return array<string,mixed>
     */
    private function mergeTree(string $repoRoot, string $baseRef, string $branchRef): array
    {
        $result = $this->git($repoRoot, ['merge-tree', '--write-tree', $baseRef, $branchRef]);

        return [
            'method' => 'git merge-tree --write-tree',
            'clean' => $result['ok'],
            'exit_code' => $result['exit_code'],
            'tree_or_output_hash' => $result['ok'] ? trim((string) $result['out']) : '',
            'error_excerpt' => $result['ok'] ? '' : substr(trim((string) $result['err']."\n".$result['out']), 0, 1200),
        ];
    }

    /**
     * @param  list<string>  $files
     * @return array<string,mixed>
     */
    private function classify(array $files, string $operatorClass): array
    {
        $docs = [];
        $tests = [];
        $code = [];
        $governance = [];
        $other = [];
        foreach ($files as $file) {
            $file = $this->normalizeRepoPath($file);
            if ($this->isGovernanceArtifact($file)) {
                $governance[] = $file;
            } elseif (str_starts_with($file, 'docs/') || str_ends_with($file, '.md')) {
                $docs[] = $file;
            } elseif (str_starts_with($file, 'tests/')) {
                $tests[] = $file;
            } elseif (str_starts_with($file, 'app/') || str_ends_with($file, '.php') || str_ends_with($file, '.ts') || str_ends_with($file, '.tsx')) {
                $code[] = $file;
            } else {
                $other[] = $file;
            }
        }

        $kind = match (true) {
            $files === [] => 'empty',
            $code === [] && $other === [] && $tests === [] && $docs === [] && $governance !== [] => 'documentation_only',
            $code === [] && $other === [] && $tests === [] => 'documentation_only',
            $code === [] && $other === [] && $docs === [] => 'tests_only',
            $code === [] && $other === [] => 'docs_and_tests',
            default => $operatorClass !== '' ? $operatorClass : 'code_or_mixed',
        };

        return [
            'kind' => $kind,
            'operator_declared_class' => $operatorClass,
            'changed_file_count' => count($files),
            'docs_files' => $docs,
            'test_files' => $tests,
            'code_files' => $code,
            'governance_files' => $governance,
            'other_files' => $other,
            'governance_metadata_only' => $code === [] && $other === [] && $tests === [] && $docs === [] && $governance !== [],
            'code_or_other_file_count' => count($code) + count($other),
        ];
    }

    /**
     * Atlas Dev cycles often append `.atlas/` provider receipts alongside safe
     * docs/tests/code patches. Those artifacts must stay visible in evidence but
     * must not inflate policy file counts or force operator review by themselves.
     *
     * @param  list<string>  $changedFiles
     * @return list<string>
     */
    private function policyChangedFiles(array $changedFiles): array
    {
        return array_values(array_filter(
            $changedFiles,
            fn (string $file): bool => ! $this->isGovernanceArtifact($this->normalizeRepoPath($file)),
        ));
    }

    /**
     * Derive the classification surface used for AP-774 auto-merge policy. Full
     * branch evidence keeps every changed path visible, but autonomy decisions
     * must reflect policy-relevant files only so `.atlas/` receipts cannot
     * inflate kind or code counts into operator review.
     *
     * @param  array<string,mixed>  $fullClassification
     * @param  array<string,mixed>  $policyClassification
     * @param  list<string>  $policyChangedFiles
     * @return array<string,mixed>
     */
    private function autonomyClassification(
        array $fullClassification,
        array $policyClassification,
        array $policyChangedFiles,
    ): array {
        if ($policyChangedFiles === []) {
            return $fullClassification;
        }

        return array_merge($fullClassification, [
            'kind' => $policyClassification['kind'],
            'operator_declared_class' => $policyClassification['operator_declared_class'],
            'changed_file_count' => $policyClassification['changed_file_count'],
            'docs_files' => $policyClassification['docs_files'],
            'test_files' => $policyClassification['test_files'],
            'code_files' => $policyClassification['code_files'],
            'other_files' => $policyClassification['other_files'],
            'code_or_other_file_count' => $policyClassification['code_or_other_file_count'],
        ]);
    }

    private function normalizeRepoPath(string $file): string
    {
        $file = trim($file);
        if (str_starts_with($file, './')) {
            return substr($file, 2);
        }

        return $file;
    }

    private function isGovernanceArtifact(string $file): bool
    {
        $file = $this->normalizeRepoPath($file);

        return str_starts_with($file, '.atlas/') || $file === '.atlas';
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function validation(array $input, string $repoRoot): array
    {
        $commands = array_values(array_filter((array) ($input['test_commands'] ?? []), 'is_string'));
        $run = (bool) ($input['run_validation'] ?? false);
        $cwd = trim((string) ($input['worktree_path'] ?? '')) ?: $repoRoot;
        $results = [];
        $passed = true;

        // A sandbox worktree symlinks vendor/ to main, so a phpunit run there resolves PSR-4
        // `App\` to MAIN's app/ — newly CREATED classes living only in the worktree are then
        // "class not found" and re-validation fails (the exact gap that let a test-for-an-
        // existing-class slice merge but a new-class build slice never could). Run phpunit
        // against a worktree-scoped bootstrap that prepends an `App\` -> <worktree>/app
        // resolver. A git worktree holds the full tree, so this finds EVERY class (new and
        // existing) — strictly more correct than the symlinked main autoloader, with no
        // behaviour change for existing-class slices. The owner runtime already validated in
        // this same worktree; this realigns the governor's independent re-validation with it.
        $bootstrap = $this->worktreeAutoloadBootstrap($cwd, $repoRoot);

        if ($run) {
            foreach ($commands as $command) {
                $normalizedCommand = $this->validationCommand($command);
                if ($bootstrap !== ''
                    && str_contains($normalizedCommand, 'phpunit')
                    && ! str_contains($normalizedCommand, '--bootstrap')) {
                    $normalizedCommand .= ' --bootstrap='.escapeshellarg($bootstrap);
                }
                $process = Process::fromShellCommandline($normalizedCommand, $cwd);
                $process->setTimeout(120);
                $process->run();
                $ok = $process->isSuccessful();
                $passed = $passed && $ok;
                $results[] = [
                    'command' => $normalizedCommand,
                    'requested_command' => $command,
                    'exit_code' => $process->getExitCode(),
                    'ok' => $ok,
                    'output_excerpt' => substr(trim($process->getOutput()."\n".$process->getErrorOutput()), 0, 1200),
                ];
            }
        }

        return [
            'commands' => $commands,
            'run_validation' => $run,
            'passed' => $commands === [] ? null : $passed,
            'results' => $results,
        ];
    }

    private function validationCommand(string $command): string
    {
        $command = trim($command);
        if (preg_match('/^php\s+artisan\s+test(?:\s+(.*))?$/', $command, $matches) === 1) {
            $args = trim((string) ($matches[1] ?? ''));

            return './vendor/bin/phpunit --configuration=phpunit.xml'.($args !== '' ? ' '.$args : '');
        }

        return $command;
    }

    /**
     * Write (idempotently) a worktree-scoped PHPUnit bootstrap that loads the symlinked
     * vendor autoloader THEN prepends a PSR-4 resolver mapping `App\` to the worktree's own
     * app/ directory, so classes CREATED in this worktree are loadable during re-validation.
     * Returns '' when validation runs in the repo root itself (no worktree) or the worktree
     * has no vendor autoloader — in those cases the default autoloader is already correct.
     * The file is written to the system temp dir (outside the worktree) so it never appears
     * in the worktree git diff / changed_files and cannot trip the scope/merge gate.
     */
    private function worktreeAutoloadBootstrap(string $cwd, string $repoRoot): string
    {
        $cwd = rtrim($cwd, '/');
        $root = rtrim($repoRoot, '/');
        if ($cwd === '' || $cwd === $root || ! is_file($cwd.'/vendor/autoload.php')) {
            return '';
        }
        $vendorAutoload = $cwd.'/vendor/autoload.php';
        $appDir = $cwd.'/app';
        $path = sys_get_temp_dir().'/atlas_govwt_autoload_'.substr(hash('sha256', $cwd), 0, 16).'.php';
        $contents = "<?php\n"
            .'require '.var_export($vendorAutoload, true).";\n"
            .'$__atlas_app = '.var_export($appDir, true).";\n"
            ."spl_autoload_register(static function (string \$class) use (\$__atlas_app): void {\n"
            ."    if (str_starts_with(\$class, 'App\\\\')) {\n"
            ."        \$file = \$__atlas_app.'/'.str_replace('\\\\', '/', substr(\$class, 4)).'.php';\n"
            ."        if (is_file(\$file)) { require \$file; }\n"
            ."    }\n"
            ."}, true, true);\n";
        @file_put_contents($path, $contents);

        return is_file($path) ? $path : '';
    }

    /**
     * @param  array<string,mixed>  $classification
     * @param  array<string,mixed>  $validation
     * @param  list<string>  $changedFiles
     * @param  list<string>  $blockers
     * @return array<string,mixed>
     */
    private function autoMergePolicy(array $classification, array $validation, array $changedFiles, int $branchOnly, array $blockers, array $input): array
    {
        return $this->autonomyPolicy->decide($classification, $validation, $changedFiles, $branchOnly, $blockers, $input);
    }

    /**
     * @return array<string,mixed>
     */
    private function mergeFfOnly(string $repoRoot, string $baseRef, string $branchRef): array
    {
        if (! $this->workingTreeClean($repoRoot)) {
            return ['status' => self::STATUS_BLOCKED, 'reason' => 'base_worktree_dirty'];
        }

        // Read commits via DIRECT uncached git calls. revParse() memoises per
        // ref for the service lifetime, so a cached pre-merge value would be
        // returned post-merge — that stale read recorded the base commit as the
        // merge hash (false merge). The post-merge head must be observed fresh.
        $baseHeadBefore = $this->headCommit($repoRoot, $baseRef);

        $checkout = $this->git($repoRoot, ['checkout', $baseRef], 120);
        if (! $checkout['ok']) {
            return ['status' => self::STATUS_BLOCKED, 'reason' => 'checkout_base_failed', 'git' => $checkout];
        }
        $merge = $this->git($repoRoot, ['merge', '--ff-only', $branchRef], 120);
        if (! $merge['ok']) {
            return ['status' => self::STATUS_BLOCKED, 'reason' => 'ff_only_merge_failed', 'git' => $merge];
        }

        $newHead = $this->headCommit($repoRoot, $baseRef);
        // A real merge MUST advance base. `git merge --ff-only` exits 0 with
        // "Already up to date." when the branch carries no commits over base,
        // which previously surfaced as STATUS_MERGED with new_head == base — a
        // false merge that inflated merge counts and recorded the base commit as
        // the merge hash. Treat a non-advancing merge as blocked: nothing landed.
        if ($newHead === '' || $newHead === $baseHeadBefore) {
            return [
                'status' => self::STATUS_BLOCKED,
                'reason' => 'nothing_to_merge_branch_no_new_commits',
                'base_ref' => $baseRef,
                'branch_ref' => $branchRef,
                'base_head' => $baseHeadBefore,
                'git' => $merge,
            ];
        }

        return [
            'status' => self::STATUS_MERGED,
            'strategy' => 'ff_only',
            'base_ref' => $baseRef,
            'branch_ref' => $branchRef,
            'base_head' => $baseHeadBefore,
            'new_head' => $newHead,
            'git' => $merge,
        ];
    }

    private function workingTreeClean(string $repoRoot): bool
    {
        $result = $this->git($repoRoot, ['status', '--porcelain']);

        return $result['ok'] && trim((string) $result['out']) === '';
    }

    /**
     * @param  list<array<string,string>>  $commits
     * @param  list<string>  $changedFiles
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function gitkrakenReviewSurface(
        string $branchRef,
        string $baseRef,
        array $commits,
        array $changedFiles,
        bool $baseIsAncestor,
        array $input,
    ): array {
        $traceability = array_filter([
            'finding_id' => trim((string) ($input['finding_id'] ?? '')),
            'spec_id' => trim((string) ($input['spec_id'] ?? '')),
            'receipt_id' => trim((string) ($input['receipt_id'] ?? $input['runtime_result_receipt_id'] ?? '')),
            'handoff_id' => trim((string) ($input['handoff_id'] ?? '')),
            'sandbox_id' => trim((string) ($input['sandbox_id'] ?? '')),
        ], static fn (string $value): bool => $value !== '');

        return [
            'visible_branch_ref' => $branchRef,
            'visible_base_ref' => $baseRef,
            'reviewable_commit_count' => count($commits),
            'reviewable_commits' => $commits,
            'changed_files' => $changedFiles,
            'graph_shape' => $baseIsAncestor ? 'branch_on_top_of_base' : 'diverged_or_stale_branch',
            'cycle_traceability' => $traceability,
            'operator_review_hint' => 'Open '.$branchRef.' against '.$baseRef.' in GitKraken'
                .($traceability !== [] ? ' (finding/spec/receipt linked in cycle_traceability)' : '')
                .'; inspect commits and changed files, then accept/reject/defer through Product Mode or merge governor.',
        ];
    }

    /**
     * @param  list<string>  $args
     * @return array{ok:bool,exit_code:int|null,out:string,err:string}
     */
    private function git(string $repoRoot, array $args, int $timeout = 30): array
    {
        $process = new Process(array_merge(['git'], $args), $repoRoot);
        $process->setTimeout($timeout);
        $process->run();

        return [
            'ok' => $process->isSuccessful(),
            'exit_code' => $process->getExitCode(),
            'out' => $process->getOutput(),
            'err' => $process->getErrorOutput(),
        ];
    }

    /**
     * @param  list<string>  $blockers
     * @return list<string>
     */
    private function retryableOperationalMergeBlockers(array $blockers): array
    {
        $retryable = [];
        foreach ($blockers as $blocker) {
            if (in_array($blocker, ['base_worktree_dirty', 'branch_not_rebased_on_current_base'], true)) {
                $retryable[] = $blocker;
            }
        }

        return array_values(array_unique($retryable));
    }

    /**
     * @param  list<string>  $values
     * @param  list<string>  $remove
     * @return list<string>
     */
    private function withoutValues(array $values, array $remove): array
    {
        $removeSet = array_fill_keys($remove, true);

        return array_values(array_filter($values, static fn (string $value): bool => ! isset($removeSet[$value])));
    }

    private function acceptedDiffRef(string $branchRef, string $branchCommit): string
    {
        return $branchRef.'@'.($branchCommit !== '' ? $branchCommit : $this->now());
    }

    /**
     * @param  list<string>  $blockers
     * @return list<string>
     */
    private function nextActions(string $status, array $autoPolicy, array $blockers, string $branchRef, string $baseRef): array
    {
        if ($status === self::STATUS_MERGED) {
            return ['Review main in GitKraken; branch '.$branchRef.' was fast-forward merged into '.$baseRef.'.'];
        }
        if ($status === self::STATUS_AUTO_MERGE_ELIGIBLE) {
            return ['Run the same command with --auto-merge --execute-merge to fast-forward merge '.$branchRef.' into '.$baseRef.'.'];
        }
        if ($blockers !== []) {
            return ['Resolve blockers first: '.implode(', ', $blockers).'.'];
        }

        return ['Review '.$branchRef.' visually in GitKraken and decide accept/reject/defer; auto-merge policy reasons: '.implode(', ', (array) ($autoPolicy['reasons'] ?? [])).'.'];
    }

    /**
     * Rebase the branch (checked out in $worktreePath) onto $baseRef.
     * Aborts cleanly on conflict; never force-pushes or touches other branches.
     *
     * @return array{ok:bool,reason?:string,err_excerpt?:string}
     */
    private function attemptRebaseInWorktree(string $worktreePath, string $baseRef): array
    {
        $rebase = $this->git($worktreePath, ['rebase', $baseRef], 120);
        if (! $rebase['ok']) {
            $this->git($worktreePath, ['rebase', '--abort'], 30);

            return [
                'ok' => false,
                'reason' => 'rebase_failed',
                'err_excerpt' => substr(trim((string) $rebase['err']), 0, 500),
            ];
        }

        return ['ok' => true];
    }

    /**
     * @return array<string,bool|string>
     */
    private function claimPolicy(string $status, bool $rebasePerformed = false): array
    {
        return [
            'creates_branch' => false,
            'creates_worktree' => false,
            'detects_conflicts_before_merge' => true,
            'requires_clean_base_worktree' => true,
            'dirty_base_blocks_review_only' => false,
            'dirty_base_blocks_execute_merge' => true,
            'auto_merge_default' => false,
            'auto_merge_strategy' => 'ff_only',
            'merge_performed' => $status === self::STATUS_MERGED,
            'rebase_performed' => $rebasePerformed,
            'force_push_performed' => false,
            'deploys' => false,
            'touches_secrets' => false,
            'operator_review_required_when_not_low_risk' => true,
            'gitkraken_visible_branch_required' => true,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function blocked(string $areaId, string $reason, string $detail, array $extra = []): array
    {
        return [
            'schema_version' => self::REPORT_SCHEMA,
            'ap_contract' => 'AP-769',
            'status' => self::STATUS_BLOCKED,
            'area_id' => $areaId,
            'reason' => $reason,
            'detail' => $detail,
            'blockers' => [$reason],
            'claim_policy' => $this->claimPolicy(self::STATUS_BLOCKED),
            'generated_at' => $this->now(),
        ] + $extra;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function maybeRecord(string $areaId, array $payload, bool $record): array
    {
        if (! $record) {
            return $payload + ['governance_storage_status' => 'projected'];
        }

        $recordPayload = ['schema_version' => self::RECORD_SCHEMA, 'recorded_at' => $this->now()] + $payload;
        File::ensureDirectoryExists(dirname($this->recordPath($areaId)));
        File::append($this->recordPath($areaId), json_encode($recordPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL);

        return $recordPayload + ['governance_storage_status' => 'recorded'];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function identity(array $payload): array
    {
        $copy = $payload;
        unset($copy['generated_at'], $copy['governor_hash'], $copy['recorded_at'], $copy['governance_storage_status']);

        return $copy;
    }

    private function slug(string $value): string
    {
        $slug = strtolower(preg_replace('/[^a-zA-Z0-9_-]+/', '_', trim($value)) ?: '');

        return trim($slug, '_') ?: self::DEFAULT_AREA_ID;
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);
    }
}
