<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AutonomousEvolutionSession;

use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusAppendOnlyJsonlRecorder;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusStringListNormalizer;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusUtcClock;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AutonomousEvolutionSessionService;
use App\Support\AtlasSecurity;
use Symfony\Component\Process\Process;

/**
 * AP-786 post-cycle classification + result/receipt projection section, extracted
 * VERBATIM from AutonomousEvolutionSessionService by the GOD-DEBULK split. Covers
 * finding/cycle classification (maintenance, review-lock, wasted/retryable blocker
 * sets, executable-contract false-positive), post-provider/post-execution skip
 * reasons, merged-into-main / landed-on-main checks, decision receipt, owner + auto-
 * merge class, execution/provider/finding summaries, and the low-level git / append-
 * only record / forbidden-path primitives. Pure classification + projection: it never
 * merges (the merge-governor coupling stays on the parent). Shared parent primitives
 * (changedFiles / recordPath) and the WASTED_CYCLE_BLOCKERS taxonomy are reached
 * through {@see AutonomousEvolutionSessionService}. commitMergedIntoMain is internal
 * to this section. Taxonomy classes are referenced qualified.
 */
final class CyclePostProcessingSection
{
    public function __construct(
        private readonly AutonomousEvolutionSessionService $parent,
    ) {}

    /** @param array<string,mixed> $finding */
    public function isFactoryMaintenanceFinding(array $finding): bool
    {
        return $this->isFactoryRoutineTestMaintenanceFinding($finding);
    }

    /** @param array<string,mixed> $finding */
    public function isFactoryRoutineTestMaintenanceFinding(array $finding): bool
    {
        $title = strtolower((string) ($finding['title'] ?? ''));
        $kind = strtolower((string) ($finding['kind'] ?? ''));
        $origin = strtolower((string) ($finding['origin'] ?? ''));
        $originType = strtolower((string) ($finding['origin_type'] ?? ''));
        $reason = strtolower((string) ($finding['autonomous_execution_reason'] ?? ''));

        return in_array($kind, ['test', 'tests', 'coverage', 'missing_test'], true)
            || $originType === 'missing_test'
            || ($origin === 'factory_max_seed' && str_ends_with($originType, '_test'))
            || str_contains($reason, 'missing_test')
            || str_contains($title, 'focused unit coverage')
            || str_contains($title, 'regression coverage')
            || str_starts_with($title, 'missing test for ');
    }

    /** @param array<string,true> $locked */
    public function findingIsReviewLocked(array $finding, array $locked): bool
    {
        foreach ($this->findingKeys($finding) as $key) {
            if (isset($locked[$key])) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string,true> */
    public function normalizeReviewLocked(mixed $locked): array
    {
        $normalized = [];
        foreach ((array) $locked as $key => $value) {
            if ($value === true && is_string($key) && $key !== '') {
                $normalized[$key] = true;

                continue;
            }
            if (is_string($value) && trim($value) !== '') {
                $normalized[trim($value)] = true;
            }
        }

        return $normalized;
    }

    /** @return list<string> */
    public function findingKeys(array $finding): array
    {
        // AP-806 slice-progression: a finding narrowed to a bounded semantic slice
        // locks/tracks ONLY that slice, so completing slice N never review-locks the
        // parent out of selection for slices N+1.. — the parent stays selectable
        // until every slice has merged (then runCycle finalizes + locks it).
        $activeSliceId = (string) ($finding['active_slice_id'] ?? '');
        if ($activeSliceId !== '') {
            return [$activeSliceId];
        }

        $findingId = (string) ($finding['finding_id'] ?? '');
        if (str_starts_with($findingId, 'factory_max_')) {
            return AreaFocusStringListNormalizer::uniqueStringValues(array_filter([
                $findingId,
                (string) ($finding['finding_hash'] ?? ''),
            ], static fn (string $value): bool => $value !== ''));
        }

        return AreaFocusStringListNormalizer::uniqueStringValues(array_filter([
            $findingId,
            (string) ($finding['finding_hash'] ?? ''),
            (string) ($finding['title'] ?? ''),
        ], static fn (string $value): bool => $value !== ''));
    }

    /**
     * @param  array<string,mixed>  $providerResult
     * @param  list<string>  $allowedFiles
     * @return array{reason:string,blockers:list<string>,unsafe_files?:list<string>}|null
     */
    public function postProviderSkipReason(array $providerResult, string $worktree, array $allowedFiles): ?array
    {
        $blockers = AreaFocusStringListNormalizer::coercedStringValues($providerResult['blockers'] ?? []);
        if ($blockers !== []) {
            return [
                'reason' => 'provider_reported_blockers',
                'blockers' => $blockers,
            ];
        }
        if ((bool) ($providerResult['provider_called'] ?? false) !== true) {
            return [
                'reason' => 'provider_not_called',
                'blockers' => ['provider_not_called'],
            ];
        }

        $changed = $this->parent->changedFiles($worktree);
        if ($changed === []) {
            return [
                'reason' => 'provider_produced_no_changes',
                'blockers' => ['provider_produced_no_changes'],
            ];
        }

        $unsafe = array_values(array_filter(
            $changed,
            static fn (string $file): bool => ! in_array($file, $allowedFiles, true),
        ));
        if ($unsafe !== []) {
            return [
                'reason' => 'provider_scope_violation',
                'blockers' => ['provider_scope_violation'],
                'unsafe_files' => $unsafe,
            ];
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $commit
     * @return array{reason:string,blockers:list<string>,unsafe_files?:list<string>}|null
     */
    public function postExecutionSkipReason(array $commit): ?array
    {
        $commitStatus = (string) ($commit['status'] ?? '');
        if ($commitStatus === 'committed') {
            return null;
        }

        if ($commitStatus === 'no_changes') {
            return [
                'reason' => 'commit_no_changes',
                'blockers' => ['provider_produced_no_changes'],
            ];
        }

        if ($commitStatus === 'blocked_scope_violation') {
            return [
                'reason' => 'commit_scope_violation',
                'blockers' => ['provider_scope_violation'],
                'unsafe_files' => array_values((array) ($commit['unsafe_files'] ?? [])),
            ];
        }

        return [
            'reason' => 'commit_failed',
            'blockers' => ['commit_failed'],
        ];
    }

    /**
     * AP-786 only spends sandbox/provider budget on findings explicitly cleared
     * for autonomous execution (factory-max seeds, operator-authorized packets).
     */
    public function findingAllowsAutonomousExecution(array $finding): bool
    {
        if (($finding['auto_execution_allowed'] ?? false) !== true) {
            return false;
        }

        return ($finding['operator_review_required'] ?? true) !== true;
    }

    /**
     * An injected build-plan slice the operator explicitly authorized for
     * autonomous plan execution (Pilar 1). Only such a slice qualifies for the
     * narrow injected-plan auto-merge exception on main; self-selected soak
     * findings keep their own factory-scoped exception and are NOT affected.
     *
     * @param  array<string,mixed>  $finding
     */
    public function isOperatorAuthorizedPlanSlice(array $finding): bool
    {
        if (! $this->findingAllowsAutonomousExecution($finding)) {
            return false;
        }

        return (string) ($finding['autonomous_execution_reason'] ?? '') === 'operator_authorized_plan_execution';
    }

    /** @param list<string> $blockers */
    public function shouldStopSessionAfterBlockedCycle(array $blockers): bool
    {
        return in_array('no_candidate_with_allowed_files', $blockers, true)
            || in_array('auto_execution_not_allowed', $blockers, true)
            || in_array('provider_scope_violation', $blockers, true);
    }

    /** @param list<string> $blockers */
    public function isWastedCycleBlockerSet(array $blockers): bool
    {
        foreach ($blockers as $blocker) {
            if (in_array($blocker, AutonomousEvolutionSessionService::WASTED_CYCLE_BLOCKERS, true)) {
                return true;
            }
        }

        return false;
    }

    /** @param list<string> $blockers */
    public function isRetryableRoutingBlockerSet(array $blockers): bool
    {
        return in_array('owner_runtime_routing_not_executable', $blockers, true);
    }

    /** @param list<string> $blockers */
    public function isExecutableContractGateFalsePositive(array $cycle, array $blockers): bool
    {
        if (! in_array('contract_only_diff_without_runtime_wiring', $blockers, true)
            || ! in_array(AutonomousEvolutionSessionService::PROVIDER_DIFF_QUALITY_BLOCKER, $blockers, true)) {
            return false;
        }

        $finding = is_array($cycle['selected_finding'] ?? null) ? $cycle['selected_finding'] : [];
        if ((string) ($finding['kind'] ?? '') !== 'plan_slice'
            || (string) ($finding['origin_type'] ?? '') !== 'build_plan_decomposition') {
            return false;
        }

        $text = implode(' ', [
            (string) ($finding['title'] ?? ''),
            (string) ($finding['why_it_matters'] ?? ''),
            (string) ($finding['proposed_next_action'] ?? ''),
        ]);
        if (! str_contains($text, 'Contract.php')) {
            return false;
        }

        foreach (['fromArray', 'toArray', 'defaults', 'score(', 'validate(', 'classify('] as $signal) {
            if (str_contains($text, $signal)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A repair review lock protects live WIP. If the branch/worktree was already
     * cleaned up, the historical lock must not starve the ordered backlog forever.
     *
     * @param  array<string,mixed>  $cycle
     */
    public function cycleHasLiveReviewArtifact(string $repoRoot, array $cycle): bool
    {
        $worktree = trim((string) ($cycle['worktree_path'] ?? ''));
        if ($worktree !== '' && is_dir($worktree)) {
            return true;
        }

        $branch = trim((string) ($cycle['branch_ref'] ?? ''));
        if ($branch === '') {
            return false;
        }

        $branchExists = $this->git($repoRoot, ['rev-parse', '--verify', '--quiet', $branch], 30);
        if (! $branchExists['ok']) {
            return false;
        }

        return ! $this->branchMergedIntoMain($repoRoot, $branch);
    }

    public function branchMergedIntoMain(string $repoRoot, string $branch): bool
    {
        $branchExists = $this->git($repoRoot, ['rev-parse', '--verify', '--quiet', $branch], 30);
        if (! $branchExists['ok']) {
            return true;
        }
        $merged = $this->git($repoRoot, ['merge-base', '--is-ancestor', $branch, 'main'], 30);

        return $merged['ok'];
    }

    /**
     * @param  array<string,mixed>  $cycle
     */
    public function completedCycleLandedOnMain(string $repoRoot, array $cycle): bool
    {
        $mergeHash = trim((string) ($cycle['merge_hash'] ?? data_get($cycle, 'loop_receipt.merge_hash', '')));
        if ($mergeHash !== '') {
            return $this->commitMergedIntoMain($repoRoot, $mergeHash);
        }

        $branch = trim((string) ($cycle['branch_ref'] ?? data_get($cycle, 'loop_receipt.branch_ref', '')));
        if ($branch !== '') {
            return $this->branchMergedIntoMain($repoRoot, $branch);
        }

        // Back-compat: legacy AP-786 records predate explicit merge truth
        // fields. Keep their existing de-duplication behavior.
        return true;
    }

    private function commitMergedIntoMain(string $repoRoot, string $commit): bool
    {
        $exists = $this->git($repoRoot, ['cat-file', '-e', $commit.'^{commit}'], 30);
        if (! $exists['ok']) {
            return false;
        }

        $merged = $this->git($repoRoot, ['merge-base', '--is-ancestor', $commit, 'main'], 30);

        return $merged['ok'];
    }

    /**
     * @param  array<string,mixed>  $finding
     * @return array<string,string>
     */
    public function decisionReceipt(string $cycleId, array $finding, array $allowedFiles, string $owner): array
    {
        $receipt = [
            'schema_version' => 'atlas.software_company_stewardship.ap786_decision_receipt.v1',
            'decision_receipt_id' => 'ap786_decision_'.$cycleId,
            'decision' => 'execute_autonomous_evolution_cycle',
            'owner' => $owner,
            'finding_id' => (string) ($finding['finding_id'] ?? ''),
            'finding_hash' => (string) ($finding['finding_hash'] ?? ''),
            'allowed_files' => $allowedFiles,
            'operator_actor' => 'operator_session_authorization',
        ];
        $receipt['decision_receipt_hash'] = 'sha256:'.MissionCanonicalHash::sha256($receipt);

        return $receipt;
    }

    /**
     * @param  array<string,mixed>  $finding
     */
    public function owner(array $finding): string
    {
        $owner = strtolower((string) ($finding['owner_candidate'] ?? data_get($finding, 'spec_seed.route_hint_owner', 'atlas_dev')));

        return $owner === 'forge' ? 'forge' : 'atlas_dev';
    }

    /**
     * @param  array<string,mixed>  $finding
     * @param  list<string>  $allowedFiles
     */
    public function autoMergeClass(array $finding, array $allowedFiles): string
    {
        $kind = strtolower((string) ($finding['kind'] ?? ''));
        if ($kind === 'test') {
            return 'test';
        }
        if ($allowedFiles !== [] && count(array_filter($allowedFiles, fn (string $f): bool => str_starts_with($f, 'docs/') || str_ends_with($f, '.md'))) === count($allowedFiles)) {
            return 'documentation';
        }
        if ($allowedFiles !== [] && count(array_filter($allowedFiles, fn (string $f): bool => str_starts_with($f, 'tests/'))) === count($allowedFiles)) {
            return 'test';
        }
        if ($kind === 'bug') {
            return 'bugfix';
        }
        if ($kind === 'cleanup') {
            return 'cleanup';
        }

        return '';
    }

    /**
     * @param  array<string,mixed>  $providerResult
     * @param  array<string,mixed>  $validation
     * @param  array<string,mixed>  $commit
     * @param  list<string>  $changedFiles
     * @return array<string,mixed>
     */
    public function executionResult(string $cycleId, string $areaId, string $owner, array $finding, array $sandbox, array $providerResult, array $validation, array $commit, array $changedFiles): array
    {
        return [
            'schema_version' => 'atlas.software_company_stewardship.ap786_execution_result.v1',
            'execution_id' => $cycleId,
            'area_id' => $areaId,
            'owner' => $owner,
            'result_status' => (($providerResult['blockers'] ?? []) === [] && ($commit['status'] ?? '') === 'committed') ? 'completed' : 'partial',
            'summary' => 'Atlas found "'.(string) ($finding['title'] ?? 'finding').'", invoked Cursor CLI in an isolated sandbox, committed the scoped result and produced merge governance.',
            // AP-765 inbox richness: carry the real finding identity so the inbox
            // shows WHAT was found / WHY it matters instead of generic boilerplate.
            'finding_title' => (string) ($finding['title'] ?? ''),
            'finding_kind' => (string) ($finding['kind'] ?? ''),
            'finding_why_it_matters' => (string) ($finding['why_it_matters'] ?? $finding['value_reason'] ?? ''),
            'finding_detail' => (string) ($finding['detail'] ?? ''),
            'commit_hash' => (string) ($commit['commit_hash'] ?? ''),
            'finding_id' => (string) ($finding['finding_id'] ?? ''),
            'spec_id' => (string) data_get($finding, 'spec_seed.candidate_id', ''),
            'handoff_id' => 'AP-786:'.$cycleId,
            'sandbox_id' => (string) ($sandbox['sandbox_id'] ?? ''),
            'branch_ref' => (string) data_get($sandbox, 'materialization.branch_name', ''),
            'worktree_path' => (string) data_get($sandbox, 'materialization.worktree_path', ''),
            'changed_files' => $changedFiles,
            'tests' => (array) ($validation['commands'] ?? []),
            'validation_commands' => (array) ($validation['commands'] ?? []),
            'test_results' => (array) ($validation['results'] ?? []),
            'evidence_pack' => [
                'summary' => 'Cursor CLI provider result plus AP-786 validation and git commit receipt.',
                'changed_files' => $changedFiles,
                'tests' => (array) ($validation['commands'] ?? []),
                'test_results' => (array) ($validation['results'] ?? []),
                'provider' => $providerResult['provider'] ?? 'cursor_cli',
                'model' => $providerResult['model'] ?? null,
                'commit_hash' => (string) ($commit['commit_hash'] ?? ''),
            ],
            'risks' => array_values((array) ($providerResult['blockers'] ?? [])),
            'rollback' => 'Revert the ff-only merge if merged, or delete the isolated branch/worktree if not merged.',
            'provider_invoked' => (bool) ($providerResult['provider_called'] ?? false),
            'provider' => (string) ($providerResult['provider'] ?? 'cursor_cli'),
            'model' => (string) ($providerResult['model'] ?? ''),
            'merge_performed' => false,
            'deploy_performed' => false,
            'external_push_performed' => false,
            'secret_access' => false,
            'destructive_change' => false,
        ];
    }

    /**
     * @param  array<string,mixed>  $providerResult
     * @return array<string,mixed>
     */
    public function providerSummary(array $providerResult): array
    {
        return [
            'provider' => (string) ($providerResult['provider'] ?? ''),
            'model' => (string) ($providerResult['model'] ?? ''),
            'provider_called' => (bool) ($providerResult['provider_called'] ?? false),
            'external_provider_call' => (bool) ($providerResult['external_provider_call'] ?? false),
            'exit_code' => $providerResult['exit_code'] ?? null,
            'duration_ms' => $providerResult['duration_ms'] ?? null,
            'changed_files' => array_values((array) ($providerResult['changed_files'] ?? [])),
            'blockers' => array_values((array) ($providerResult['blockers'] ?? [])),
            'note' => (string) ($providerResult['note'] ?? ''),
        ];
    }

    /**
     * @param  array<string,mixed>  $finding
     * @return array<string,mixed>
     */
    public function findingSummary(array $finding): array
    {
        return [
            'finding_id' => (string) ($finding['finding_id'] ?? ''),
            'finding_hash' => (string) ($finding['finding_hash'] ?? ''),
            'title' => (string) ($finding['title'] ?? ''),
            'kind' => (string) ($finding['kind'] ?? ''),
            'severity' => (string) ($finding['severity'] ?? ''),
            'origin_type' => (string) ($finding['origin_type'] ?? ''),
            'parent_finding_id' => (string) ($finding['parent_finding_id'] ?? ''),
            'why_it_matters' => (string) ($finding['why_it_matters'] ?? ''),
            'proposed_next_action' => (string) ($finding['proposed_next_action'] ?? ''),
            'autonomous_execution_reason' => (string) ($finding['autonomous_execution_reason'] ?? ''),
            'starvation_state_hash' => (string) ($finding['starvation_state_hash'] ?? ''),
            'runtime_version_hash' => (string) ($finding['runtime_version_hash'] ?? ''),
            'terminal_backlog_state_hash' => (string) ($finding['terminal_backlog_state_hash'] ?? ''),
            // AP-806 slice-progression: the bounded slice this cycle executed (empty
            // for whole/atomic findings). Drives completedSemanticSliceIds + the
            // runner's per-slice duplicate key.
            'active_slice_id' => (string) ($finding['active_slice_id'] ?? ''),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function pullMain(string $repoRoot): array
    {
        $checkout = $this->git($repoRoot, ['checkout', 'main'], 120);
        if (! $checkout['ok']) {
            return ['status' => 'checkout_main_failed', 'git' => $checkout];
        }
        $pull = $this->git($repoRoot, ['pull', '--ff-only'], 180);

        return [
            'status' => $pull['ok'] ? 'main_updated' : 'pull_failed_or_no_upstream',
            'git' => $pull,
        ];
    }

    /**
     * @param  list<string>  $blockers
     * @return array<string,mixed>
     */
    public function blockedCycle(string $cycleId, int $cycleIndex, array $blockers, array $extra = []): array
    {
        return [
            'cycle_id' => $cycleId,
            'cycle_index' => $cycleIndex,
            'final_status' => 'blocked',
            'continue_loop' => false,
            'blockers' => $blockers,
        ] + $extra;
    }

    /**
     * @param  list<array<string,mixed>>  $cycles
     */
    public function anyCycleFlag(array $cycles, string $key): bool
    {
        foreach ($cycles as $cycle) {
            if ((bool) ($cycle[$key] ?? false)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $blockers
     * @return list<string>
     */
    public function nextActions(string $status, array $blockers): array
    {
        if ($status === AutonomousEvolutionSessionService::STATUS_COMPLETED) {
            return ['Session completed. Review the emitted Inbox items and git history; the next scheduler tick can run another AP-786 session.'];
        }
        if ($status === AutonomousEvolutionSessionService::STATUS_DRY_RUN) {
            return ['Dry-run only. Re-run with --execute to invoke Cursor CLI in a real AP-756 sandbox.'];
        }

        return ['Resolve blockers before continuing: '.implode(', ', $blockers)];
    }

    public function forbidden(string $path): bool
    {
        foreach (AutonomousEvolutionSessionService::FORBIDDEN_PATHS as $forbidden) {
            if (str_starts_with($path, rtrim($forbidden, '/'))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $args
     * @return array{ok:bool,exit_code:int|null,out:string,err:string}
     */
    public function git(string $cwd, array $args, int $timeout = 60): array
    {
        if (! is_dir($cwd)) {
            return [
                'ok' => false,
                'exit_code' => null,
                'out' => '',
                'err' => 'cwd_missing:'.$cwd,
            ];
        }

        $process = new Process(array_merge(['git'], $args), $cwd, AtlasSecurity::processEnv(profile: 'tool'), null, $timeout);
        $process->run();

        return [
            'ok' => $process->isSuccessful(),
            'exit_code' => $process->getExitCode(),
            'out' => AtlasSecurity::redactString($process->getOutput()),
            'err' => AtlasSecurity::redactString($process->getErrorOutput()),
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    public function record(string $areaId, array $payload): array
    {
        $record = ['schema_version' => AutonomousEvolutionSessionService::RECORD_SCHEMA, 'recorded_at' => AreaFocusUtcClock::atomNow()] + $payload;
        AreaFocusAppendOnlyJsonlRecorder::append($this->parent->recordPath($areaId), $record);

        return $record + ['session_storage_status' => 'recorded'];
    }
}
