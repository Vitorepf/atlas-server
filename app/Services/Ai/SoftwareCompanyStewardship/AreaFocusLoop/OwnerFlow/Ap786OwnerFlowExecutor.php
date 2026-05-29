<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\OwnerFlow;

use App\Services\Ai\Programming\AtlasForgeProviderInvocationDriverRouter;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusDevForgeReleaseService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusOwnerQueueConsumptionGateService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\RepairAgentFeedbackContextBuilderService;
use App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution\StewardshipOutcomeEvidenceBridgeService;
use App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution\StewardshipOwnerRuntimeExecutionAdapterService;
use App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution\StewardshipOwnerRuntimeResultBridgeService;
use App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution\StewardshipOwnerSandboxRuntimeRunnerService;

/**
 * AP-786 full owner-runtime flow executor.
 *
 * Composes the REAL Atlas owner-flow chain so AP-786 stops faking Forge/Dev via
 * a direct provider driver. It never calls
 * {@see AtlasForgeProviderInvocationDriverRouter}.
 * The only component that runs a command is AP-759, and only an allowlisted
 * owner CLI inside the AP-756 worktree:
 *   - atlas_dev -> `atlas:dev:senior-loop:run`;
 *   - forge -> the AP-787 {@see ForgeOwnerRuntimeDispatchBridge} governed Forge
 *     dispatch command (e.g. `atlas:forge:runtime-dispatch`).
 *
 * Chain:
 *   AP-747 release -> AP-748 outcome -> AP-749 consumption gate (binds AP-757
 *   sandbox) -> AP-758 execution adapter -> AP-759 owner sandbox runtime runner
 *   -> AP-750 owner runtime result bridge.
 *
 * Forge honesty (AP-787): if a real Obra, live topology or live Forge decision
 * is missing, forge blocks with a precise machine-readable reason BEFORE any
 * execution claim. `atlas:forge:runtime-dispatch` only prepares a governed plan,
 * so a successful run with no real changed files is reported as PLANNED
 * (`owner_flow_forge_planned`), never completed; merge governance is never
 * reached for a plan.
 */
final class Ap786OwnerFlowExecutor implements Ap786OwnerFlowRunner
{
    public const REPORT_SCHEMA = 'atlas.software_company_stewardship.ap786_owner_flow.v1';

    public const STATUS_COMPLETED = 'owner_flow_completed';

    public const STATUS_RESULT_FAILED = 'owner_flow_result_failed';

    /** Forge runtime-dispatch produced a governed plan only; not an execution. */
    public const STATUS_FORGE_PLANNED = 'owner_flow_forge_planned';

    public const STATUS_BLOCKED = 'blocked';

    /** The repair agent re-emitted a diff already tried this cycle (no progress). */
    public const STATUS_REPEATED_REPAIR_NO_PROGRESS = 'repeated_repair_no_progress';

    /** The same slice failed repair too many times; skip it and advance. */
    public const STATUS_REVIEW_LOCKED = 'review_locked';

    /**
     * Number of failed repair attempts on the same slice before it is review
     * locked. Two failures (initial senior-loop fail + one repair fail) is the
     * ceiling so the loop never burns a 3rd provider call on the same broken
     * slice; the runner must then advance to the next finding.
     */
    public const REPAIR_REVIEW_LOCK_THRESHOLD = 2;

    public const DEFAULT_AREA_ID = 'agentic_engineering_os';

    public const DEFAULT_PORTFOLIO_ID = 'atlas_software_company';

    public const REAL_EXECUTION_BRIDGE_SCHEMA = 'atlas.software_company_stewardship.ap786_real_execution_bridge.v1';

    /** AP-790 priority backlog item materialized through AP-786 owner-flow diagnostics. */
    public const AP790_BACKLOG_OWNER_RUNTIME_REAL_EXECUTION_BRIDGE = 'owner_runtime_real_execution_bridge';

    private readonly RepairValidationRunner $repairValidation;

    private readonly RepairAgentFeedbackContextBuilderService $repairFeedback;

    public function __construct(
        private readonly OwnerQueueReleaseGate $release,
        private readonly StewardshipOutcomeProjector $outcome,
        private readonly OwnerQueueConsumptionGate $consumption,
        private readonly OwnerRuntimeExecutionAdapter $adapter,
        private readonly OwnerSandboxRuntimeRunner $runner,
        private readonly OwnerRuntimeResultProjector $resultBridge,
        private readonly ForgeOwnerRuntimeDispatchPlanner $forgeDispatch,
        ?RepairValidationRunner $repairValidation = null,
        ?RepairAgentFeedbackContextBuilderService $repairFeedback = null,
    ) {
        // Pre-return validation gate: defaults to a real subprocess runner in
        // production; tests inject a fake so the unit suite never shells out.
        $this->repairValidation = $repairValidation ?? new ShellRepairValidationRunner;
        $this->repairFeedback = $repairFeedback ?? new RepairAgentFeedbackContextBuilderService;
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function execute(array $input): array
    {
        $areaId = trim((string) ($input['area_id'] ?? self::DEFAULT_AREA_ID)) ?: self::DEFAULT_AREA_ID;
        $portfolioId = trim((string) ($input['portfolio_id'] ?? self::DEFAULT_PORTFOLIO_ID)) ?: self::DEFAULT_PORTFOLIO_ID;
        $owner = (string) ($input['owner'] ?? 'atlas_dev') === 'forge' ? 'forge' : 'atlas_dev';
        $actor = trim((string) ($input['actor'] ?? 'operator')) ?: 'operator';
        $execute = (bool) ($input['execute'] ?? true);
        $provider = $this->providerChoice($input);
        $model = $this->modelFamily($input, $provider);
        $preflight = is_array($input['preflight_report'] ?? null) ? $input['preflight_report'] : [];
        $sandboxRecord = is_array($input['sandbox_record'] ?? null) ? $input['sandbox_record'] : [];
        $finding = is_array($input['finding'] ?? null) ? $input['finding'] : [];
        $worktree = trim((string) ($input['worktree_path'] ?? ''));
        $allowedFiles = $this->stringList($input['allowed_files'] ?? []);
        $handoffHash = (string) data_get($preflight, 'handoff_packet.handoff_hash', '');
        // Inner provider-call ceiling threaded into the senior loop. The old
        // path left the provider on a 120s config default while only the outer
        // subprocess saw this timeout, so every real cycle died on
        // owner_runtime_provider_timeout. The outer subprocess timeout is kept
        // strictly larger so the provider call gets its full budget before the
        // wrapper process is ever killed.
        $providerTimeout = max(60, min(1200, (int) ($input['provider_timeout_seconds'] ?? 600)));
        $timeout = max(60, min(1800, (int) ($input['timeout_seconds'] ?? 900)), $providerTimeout + 120);

        $steps = [];

        // AP-787: route owner=forge through the REAL Atlas Forge/Obra dispatch
        // (an allowlisted AP-759 command), never a direct provider driver. Block
        // with a precise machine-readable reason if a real Obra, live topology or
        // live Forge decision is missing — before any execution claim.
        $forgeDispatchPlan = [];
        if ($owner === 'forge') {
            $forgeDispatchPlan = $this->forgeDispatch->plan(array_replace($input, ['finding' => $finding]));
            if (($forgeDispatchPlan['ok'] ?? false) !== true) {
                return $this->blocked((string) ($forgeDispatchPlan['blocker'] ?? 'forge_dispatch_not_ready'), $owner, $steps, [
                    'forge_dispatch' => $forgeDispatchPlan,
                ]);
            }
        }

        if ($handoffHash === '') {
            return $this->blocked('ap726_handoff_hash_required', $owner, $steps, [
                'detail' => 'AP-786 owner flow requires a preflight_report.handoff_packet.handoff_hash threaded through AP-747/AP-756.',
            ]);
        }

        // 1. AP-747 — release the AP-726/AP-756 handoff to the owner queue.
        $release = $this->release->release([
            'area_id' => $areaId,
            'preflight_report' => $preflight,
            'release_receipt' => [
                'decision' => 'release',
                'target_handoff_hash' => $handoffHash,
                'operator_actor' => $actor,
                'rationale' => 'AP-786 autonomous evolution session release of an operator-authorized handoff.',
            ],
            'workspace' => 'atlas-server',
        ]);
        $steps[] = $this->step('AP-747', 'release', $release['status'] ?? '');
        if (! in_array((string) ($release['status'] ?? ''), [
            AreaFocusDevForgeReleaseService::STATUS_READY,
            AreaFocusDevForgeReleaseService::STATUS_RECORDED,
        ], true)) {
            return $this->blocked('ap747_release_not_ready', $owner, $steps, ['release' => $release]);
        }

        // 2. AP-748/AP-740 — outcome bridge sufficient for AP-749.
        $outcome = $this->outcome->project([
            'area_id' => $areaId,
            'portfolio_id' => $portfolioId,
            'release_report' => $release,
        ]);
        $steps[] = $this->step('AP-748', 'outcome', $outcome['status'] ?? '');
        if ((string) ($outcome['status'] ?? '') !== StewardshipOutcomeEvidenceBridgeService::STATUS_READY) {
            return $this->blocked('ap748_outcome_bridge_incomplete', $owner, $steps, ['outcome' => $outcome]);
        }

        // 3. AP-749 — owner-specific consumption gate, binding the AP-757 sandbox.
        $consumption = $this->consumption->project([
            'area_id' => $areaId,
            'release_report' => $release,
            'outcome_bridge' => $outcome,
            'sandbox_record' => $sandboxRecord,
            'execution_receipt' => [
                'decision' => 'start_owner_runtime',
                'operator_actor' => $actor,
                'target_release_id' => (string) ($release['release_id'] ?? ''),
                'target_queue_item_id' => (string) data_get($release, 'queue_item.queue_item_id', ''),
                'target_handoff_hash' => $handoffHash,
                'rationale' => 'AP-786 owner-flow executor starts the governed owner runtime after AP-747 release, AP-748 evidence visibility and AP-756 sandbox binding.',
            ],
        ]);
        $steps[] = $this->step('AP-749', 'consumption_gate', $consumption['status'] ?? '');
        if ((string) ($consumption['status'] ?? '') !== AreaFocusOwnerQueueConsumptionGateService::STATUS_READY) {
            return $this->blocked('ap749_consumption_not_ready', $owner, $steps, ['consumption' => $consumption]);
        }

        // 4. AP-758 — owner runtime execution adapter.
        $adapter = $this->adapter->project([
            'area_id' => $areaId,
            'portfolio_id' => $portfolioId,
            'consumption_report' => $consumption,
            'runtime_start_receipt' => [
                'decision' => 'start_owner_runtime',
                'operator_actor' => $actor,
            ],
        ]);
        $steps[] = $this->step('AP-758', 'execution_adapter', $adapter['status'] ?? '');
        if ((string) ($adapter['status'] ?? '') !== StewardshipOwnerRuntimeExecutionAdapterService::STATUS_READY) {
            return $this->blocked('ap758_execution_not_ready', $owner, $steps, ['adapter' => $adapter]);
        }

        // 5. AP-759 — run the allowlisted owner command inside the AP-756 worktree.
        //    atlas_dev -> senior loop; forge -> AP-787 governed dispatch command.
        $planOnly = false;
        $dispatchKind = 'atlas_dev_senior_loop';
        $receiptExtra = [];
        if ($owner === 'forge') {
            $command = array_values(array_map(static fn ($p): string => (string) $p, (array) ($forgeDispatchPlan['command'] ?? [])));
            $receiptExtra = is_array($forgeDispatchPlan['receipt_extra'] ?? null) ? $forgeDispatchPlan['receipt_extra'] : [];
            $planOnly = (bool) ($forgeDispatchPlan['plan_only'] ?? false);
            $dispatchKind = (string) ($forgeDispatchPlan['dispatch_kind'] ?? ForgeOwnerRuntimeDispatchBridge::KIND_RUNTIME_DISPATCH);
        } else {
            $validationCommands = $this->stringList($input['validation_commands'] ?? []);
            $command = $this->atlasDevCommand(
                $worktree,
                $this->buildOwnerIntent($finding, $allowedFiles, $validationCommands, $worktree),
                $allowedFiles,
                $validationCommands,
                $provider,
                $model,
                $providerTimeout,
            );
            $receiptExtra = [
                'provider_execution_authorized' => true,
                'budget_approved' => true,
                'provider_choice' => $provider,
                'model_family' => $model,
            ];
        }
        $runner = $this->runOwnerRuntimeCommand($areaId, $portfolioId, $adapter, $command, $actor, $timeout, $execute, $receiptExtra);
        $steps[] = $this->step('AP-759', 'owner_sandbox_runtime_run', $runner['status'] ?? '');
        if (! in_array((string) ($runner['status'] ?? ''), [
            StewardshipOwnerSandboxRuntimeRunnerService::STATUS_READY,
            StewardshipOwnerSandboxRuntimeRunnerService::STATUS_RECORDED,
        ], true)) {
            return $this->blocked('ap759_owner_command_failed', $owner, $steps, ['runner' => $runner]);
        }

        $ownerResult = is_array($runner['owner_result'] ?? null) ? $runner['owner_result'] : [];
        if ($ownerResult === []) {
            return $this->blocked('ap759_owner_result_missing', $owner, $steps, ['runner' => $runner]);
        }

        // A provider can time out AFTER writing a validated scoped diff. That is
        // not a repairable failure — it is a salvage. Compute it on the first
        // result and skip the repair loop entirely so we never spend another
        // provider call (or trip repeated-repair detection) on a diff that
        // already passed scope + verification.
        $firstChangedFiles = $this->stringList($ownerResult['changed_files'] ?? data_get($ownerResult, 'evidence_pack.changed_files', []));
        $salvageable = $this->validatedTimeoutSalvage($owner, $ownerResult, $allowedFiles, $firstChangedFiles);

        $repairAttempt = ['attempted' => false, 'retried' => false];
        if (($salvageable['salvaged'] ?? false) !== true) {
            $repair = $this->runRepairLoop(
                $owner,
                $areaId,
                $portfolioId,
                $adapter,
                $runner,
                $ownerResult,
                $command,
                $allowedFiles,
                $actor,
                $timeout,
                $providerTimeout,
                $execute,
                $receiptExtra,
                $worktree,
                $input,
                $steps,
            );
            $runner = $repair['runner'];
            $ownerResult = $repair['owner_result'];
            $command = $repair['command'];
            $repairAttempt = $repair['repair_attempt'];

            // Short-circuit honestly when the repair loop detected no progress
            // (the same broken diff was re-emitted) or hit the review-lock
            // ceiling. The runner consumes these blockers to advance to the next
            // finding instead of burning another provider call on the same slice.
            if (($repair['short_circuit'] ?? '') !== '') {
                return $this->repairShortCircuitReport(
                    (string) $repair['short_circuit'],
                    $owner,
                    $steps,
                    $repairAttempt,
                    $ownerResult,
                );
            }
        }

        $resultStatus = (string) ($ownerResult['result_status'] ?? $ownerResult['status'] ?? '');
        $changedFiles = $this->stringList($ownerResult['changed_files'] ?? data_get($ownerResult, 'evidence_pack.changed_files', []));
        $validatedTimeoutSalvage = $this->validatedTimeoutSalvage($owner, $ownerResult, $allowedFiles, $changedFiles);
        if (($validatedTimeoutSalvage['salvaged'] ?? false) === true) {
            $ownerResult = $this->withValidatedTimeoutSalvage($ownerResult, $validatedTimeoutSalvage);
            $resultStatus = (string) ($ownerResult['result_status'] ?? $ownerResult['status'] ?? '');
            $changedFiles = $this->stringList($ownerResult['changed_files'] ?? data_get($ownerResult, 'evidence_pack.changed_files', []));
        }

        // AP-787 honesty gate: atlas:forge:runtime-dispatch only prepares a
        // governed PLAN (no provider call, no real changes). A successful run
        // with no real changed files is PLANNED, never completed — no merge.
        $forgePlanned = $owner === 'forge' && $planOnly && $changedFiles === [];

        // 6. AP-750 — bridge the owner runtime result (or plan) into Evidence/Inbox/Portfolio.
        //    A plan is recorded honestly as a non-completed (partial) result.
        $bridgeResult = $forgePlanned ? array_replace($ownerResult, ['result_status' => 'partial']) : $ownerResult;
        $resultBridge = $this->resultBridge->project([
            'area_id' => $areaId,
            'portfolio_id' => $portfolioId,
            'consumption_report' => $consumption,
            'owner_result' => $bridgeResult,
            'record_result' => true,
        ]);
        $steps[] = $this->step('AP-750', 'owner_runtime_result_bridge', $resultBridge['status'] ?? '');

        $bridgeReady = in_array((string) ($resultBridge['status'] ?? ''), [
            StewardshipOwnerRuntimeResultBridgeService::STATUS_READY,
            StewardshipOwnerRuntimeResultBridgeService::STATUS_RECORDED,
        ], true);
        // Completion requires a real owner result; forge additionally requires
        // real changed files (a plan with no changes can never be completed).
        $completed = $resultStatus === 'completed'
            && $bridgeReady
            && ! $forgePlanned
            && ($owner !== 'forge' || $changedFiles !== []);
        $status = $forgePlanned
            ? self::STATUS_FORGE_PLANNED
            : ($completed ? self::STATUS_COMPLETED : self::STATUS_RESULT_FAILED);

        $blockerReport = $this->ownerRuntimeBlockerReport($ownerResult, $forgePlanned, $completed, $repairAttempt);
        $blockers = $blockerReport['blockers'];

        return [
            'schema_version' => self::REPORT_SCHEMA,
            'ap_contract' => 'AP-786',
            'status' => $status,
            'owner' => $owner,
            'dispatch_kind' => $dispatchKind,
            'plan_only' => $planOnly,
            'forge_planned' => $forgePlanned,
            'forge_dispatch' => $forgeDispatchPlan !== [] ? $forgeDispatchPlan : null,
            'uses_full_owner_runtime_chain' => true,
            'provider_router_used' => false,
            'merge_allowed' => $completed,
            'consumption_id' => (string) ($consumption['consumption_id'] ?? ''),
            'release_id' => (string) ($consumption['release_id'] ?? $release['release_id'] ?? ''),
            'queue_item_id' => (string) ($consumption['queue_item_id'] ?? ''),
            'owner_execution_id' => (string) ($adapter['owner_execution_id'] ?? ''),
            'owner_sandbox_run_id' => (string) ($runner['owner_sandbox_run_id'] ?? ''),
            'owner_result' => $ownerResult,
            'result_bridge' => $resultBridge,
            'result_bridge_id' => (string) ($resultBridge['result_bridge_id'] ?? ''),
            'execution_result' => $this->executionResult(
                $ownerResult,
                $consumption,
                $finding,
                $worktree,
                $owner,
                $command,
                (string) ($runner['owner_sandbox_run_id'] ?? ''),
                $dispatchKind,
                $planOnly,
                $steps,
                $blockers,
                $repairAttempt,
                $validatedTimeoutSalvage,
            ),
            'steps' => $steps,
            'repair_attempt' => $repairAttempt,
            'blockers' => $blockers,
            'blocker_details' => $blockerReport['details'],
            'claim_policy' => $this->claimPolicy(),
            'generated_at' => gmdate('c'),
        ];
    }

    /**
     * @param  list<string>  $command
     * @param  array<string,mixed>  $adapter
     * @param  array<string,mixed>  $receiptExtra
     * @return array<string,mixed>
     */
    private function runOwnerRuntimeCommand(string $areaId, string $portfolioId, array $adapter, array $command, string $actor, int $timeout, bool $execute, array $receiptExtra): array
    {
        return $this->runner->project([
            'area_id' => $areaId,
            'portfolio_id' => $portfolioId,
            'execution_adapter_report' => $adapter,
            'runtime_command_receipt' => array_replace([
                'decision' => 'execute_owner_runtime_in_sandbox',
                'operator_actor' => $actor,
                'command' => $command,
                'allow_runtime_command_execution' => true,
                'timeout_seconds' => $timeout,
            ], $receiptExtra),
            'execute' => $execute,
            'record_run' => true,
        ]);
    }

    /**
     * Bounded, honest repair loop for atlas_dev senior-loop failures.
     *
     * Hardens the old single blind retry with three guards the loop needs to
     * stay honest across a 10h run:
     *   1. Repeated-repair detection — hash every candidate diff this cycle.
     *      If the agent re-emits a diff already tried, short-circuit with
     *      `repeated_repair_no_progress` WITHOUT calling the provider again.
     *   2. Review-lock counter — after REPAIR_REVIEW_LOCK_THRESHOLD failed
     *      repairs on the same slice, short-circuit with `review_locked` so the
     *      runner advances to the next finding instead of retrying this slice.
     *   3. Pre-return validation — a repaired result only counts when its diff
     *      is real (hash changed) AND the declared validation command passes.
     *
     * @param  array<string,mixed>  $adapter
     * @param  array<string,mixed>  $runner
     * @param  array<string,mixed>  $ownerResult
     * @param  list<string>  $command
     * @param  list<string>  $allowedFiles
     * @param  array<string,mixed>  $receiptExtra
     * @param  array<string,mixed>  $input
     * @param  list<array<string,mixed>>  $steps
     * @return array{runner:array<string,mixed>,owner_result:array<string,mixed>,command:list<string>,repair_attempt:array<string,mixed>,short_circuit:string}
     */
    private function runRepairLoop(
        string $owner,
        string $areaId,
        string $portfolioId,
        array $adapter,
        array $runner,
        array $ownerResult,
        array $command,
        array $allowedFiles,
        string $actor,
        int $timeout,
        int $providerTimeout,
        bool $execute,
        array $receiptExtra,
        string $worktree,
        array $input,
        array &$steps,
    ): array {
        $repairAttempt = ['attempted' => false, 'retried' => false];
        if (! $this->shouldRetryAtlasDevOwnerRuntime($owner, $ownerResult, $allowedFiles)) {
            return [
                'runner' => $runner,
                'owner_result' => $ownerResult,
                'command' => $command,
                'repair_attempt' => $repairAttempt,
                'short_circuit' => '',
            ];
        }

        // Seed the per-cycle diff-hash set with the first (failed) diff so a
        // repair that reproduces the identical broken diff is caught.
        $firstHash = $this->candidateDiffHash($ownerResult);
        $seenHashes = $firstHash !== '' ? [$firstHash => true] : [];
        $failureCount = 1; // the initial senior-loop failure already counts.
        $firstRunner = $runner;
        $firstOwnerResult = $ownerResult;
        $firstDiagnostics = $this->ownerRuntimeFailureDiagnostics($ownerResult, $command);

        $maxRepairs = self::REPAIR_REVIEW_LOCK_THRESHOLD - 1;
        for ($attempt = 1; $attempt <= $maxRepairs; $attempt++) {
            $feedback = $this->repairFeedback->build([
                'owner_result' => $ownerResult,
                'allowed_files' => $allowedFiles,
                'forbidden_files' => $this->forbiddenFiles($input, $allowedFiles),
                'validation_command' => $this->primaryValidationCommandForFeedback($input, $command),
                'validation_commands' => $this->stringList($input['validation_commands'] ?? []),
                'rejected_diff' => $this->candidateDiff($ownerResult),
                'merge_rejection_reason' => $this->nullableString($input['merge_rejection_reason'] ?? null),
                'sandbox_current_commit' => $this->sandboxCurrentCommit($input, $ownerResult),
                'expected_namespace' => $this->nullableString($input['expected_namespace'] ?? null),
                'repair_attempt_number' => $attempt,
                'worktree_path' => $worktree,
            ]);

            $repairCommand = $this->repairCommand($command, $ownerResult, $feedback);
            $repairOuterTimeout = max($this->repairTimeoutSeconds($timeout), $providerTimeout + 120);
            $repairRunner = $this->runOwnerRuntimeCommand($areaId, $portfolioId, $adapter, $repairCommand, $actor, $repairOuterTimeout, $execute, $receiptExtra + [
                'repair_attempt' => true,
                'repair_attempt_number' => $attempt,
                'repair_reason' => 'senior_loop_execution_not_passed',
            ]);
            $steps[] = $this->step('AP-759', 'owner_sandbox_runtime_repair_run', $repairRunner['status'] ?? '');
            $repairResult = is_array($repairRunner['owner_result'] ?? null) ? $repairRunner['owner_result'] : [];

            $repairAttempt = $this->repairAttemptMetadata(
                $firstRunner,
                $repairRunner,
                $firstOwnerResult,
                $repairResult,
                $firstDiagnostics,
                $attempt,
                $feedback,
            );

            // Adopt the repair result as the working result so blockers/evidence
            // reflect the latest attempt even when it did not complete.
            if ($repairResult !== []) {
                $runner = $repairRunner;
                $ownerResult = $repairResult;
                $command = $repairCommand;
            }

            $repairHash = $this->candidateDiffHash($repairResult);
            $repairChanged = $this->stringList($repairResult['changed_files'] ?? data_get($repairResult, 'evidence_pack.changed_files', []));
            $repairCompletedEarly = (string) ($repairResult['result_status'] ?? $repairResult['status'] ?? '') === 'completed';

            // (1) Repeated-repair detection: a STILL-FAILING repair that re-emits
            // a diff already tried this cycle made no progress — stop now, do not
            // loop into another provider call. A completed repair is progress and
            // is never treated as repeated, even if the changed-file set repeats.
            if (! $repairCompletedEarly && $repairHash !== '' && isset($seenHashes[$repairHash])) {
                $repairAttempt['repeated_repair_no_progress'] = true;
                $repairAttempt['repeated_diff_hash'] = $repairHash;

                return [
                    'runner' => $runner,
                    'owner_result' => $ownerResult,
                    'command' => $command,
                    'repair_attempt' => $repairAttempt,
                    'short_circuit' => self::STATUS_REPEATED_REPAIR_NO_PROGRESS,
                ];
            }
            if ($repairHash !== '') {
                $seenHashes[$repairHash] = true;
            }

            // (3) Pre-return validation: a repair only counts as repaired when
            // there is a real scoped diff (changed files within allowed scope)
            // AND the focused validation command passes. The gate re-runs only a
            // focused test command (phpunit/artisan) inside the worktree — never
            // a pure lint check like `git diff --check`. The diff hash is used
            // strictly for repeated-repair detection above, not as the proof of
            // change: a completed AP-759 result with no captured diff text is
            // still a real change when it touched allowed files.
            $repairCompleted = (string) ($repairResult['result_status'] ?? $repairResult['status'] ?? '') === 'completed';
            $diffIsReal = $this->diffTouchesAllowedScope($repairChanged, $allowedFiles);
            $validation = $this->validateRepair($worktree, $this->primaryValidationCommandForFeedback($input, $command), $repairChanged, $allowedFiles);
            $repairAttempt['pre_return_validation_result'] = $validation;

            if ($repairCompleted && $diffIsReal && ($validation['passed'] ?? false)) {
                $repairAttempt['repaired'] = true;

                return [
                    'runner' => $runner,
                    'owner_result' => $ownerResult,
                    'command' => $command,
                    'repair_attempt' => $repairAttempt,
                    'short_circuit' => '',
                ];
            }

            // This repair did not pass: it counts toward the review-lock ceiling.
            $repairAttempt['repaired'] = false;
            $failureCount++;
        }

        // (2) Review-lock: the slice exhausted its repair budget without a
        // validated fix. Skip it and let the runner advance to the next finding.
        if ($failureCount >= self::REPAIR_REVIEW_LOCK_THRESHOLD) {
            $repairAttempt['review_locked'] = true;
            $repairAttempt['repair_failure_count'] = $failureCount;
            $repairAttempt['last_error_summary'] = $this->lastErrorSummary($ownerResult, $firstDiagnostics);

            return [
                'runner' => $runner,
                'owner_result' => $ownerResult,
                'command' => $command,
                'repair_attempt' => $repairAttempt,
                'short_circuit' => self::STATUS_REVIEW_LOCKED,
            ];
        }

        return [
            'runner' => $runner,
            'owner_result' => $ownerResult,
            'command' => $command,
            'repair_attempt' => $repairAttempt,
            'short_circuit' => '',
        ];
    }

    /**
     * A real scoped diff: at least one changed file, all within allowed scope.
     *
     * @param  list<string>  $changedFiles
     * @param  list<string>  $allowedFiles
     */
    private function diffTouchesAllowedScope(array $changedFiles, array $allowedFiles): bool
    {
        if ($changedFiles === []) {
            return false;
        }
        foreach ($changedFiles as $file) {
            if (! in_array($file, $allowedFiles, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Pre-return validation gate. A repair is only trusted when the declared
     * validation command exits 0 inside the worktree. With no command, the gate
     * passes but records that no command ran (still requires a real diff above).
     *
     * @param  list<string>  $changedFiles
     * @param  list<string>  $allowedFiles
     * @return array{passed:bool,ran:bool,exit_code:int,command:string,output_excerpt:string,changed_within_scope:bool}
     */
    private function validateRepair(string $worktree, ?string $validationCommand, array $changedFiles, array $allowedFiles): array
    {
        $changedWithinScope = $changedFiles !== [];
        foreach ($changedFiles as $file) {
            if (! in_array($file, $allowedFiles, true)) {
                $changedWithinScope = false;
                break;
            }
        }

        $command = trim((string) ($validationCommand ?? ''));
        if ($command === '') {
            return [
                'passed' => $changedWithinScope,
                'ran' => false,
                'exit_code' => 0,
                'command' => '',
                'output_excerpt' => '',
                'changed_within_scope' => $changedWithinScope,
            ];
        }

        $result = $this->repairValidation->validate($worktree, $command);
        $ran = (bool) ($result['ran'] ?? false);
        $exitCode = (int) ($result['exit_code'] ?? 1);
        $passed = $changedWithinScope && (! $ran || $exitCode === 0);

        return [
            'passed' => $passed,
            'ran' => $ran,
            'exit_code' => $exitCode,
            'command' => $command,
            'output_excerpt' => mb_substr((string) ($result['output'] ?? ''), 0, 2000),
            'changed_within_scope' => $changedWithinScope,
        ];
    }

    /**
     * @param  array<string,mixed>  $firstRunner
     * @param  array<string,mixed>  $repairRunner
     * @param  array<string,mixed>  $firstOwnerResult
     * @param  array<string,mixed>  $repairResult
     * @param  list<string>  $firstDiagnostics
     * @param  array<string,mixed>  $feedback
     * @return array<string,mixed>
     */
    private function repairAttemptMetadata(
        array $firstRunner,
        array $repairRunner,
        array $firstOwnerResult,
        array $repairResult,
        array $firstDiagnostics,
        int $attemptNumber,
        array $feedback,
    ): array {
        return [
            'attempted' => true,
            'retried' => true,
            'reason' => 'senior_loop_execution_not_passed',
            'repair_attempt_number' => $attemptNumber,
            'first_owner_sandbox_run_id' => (string) ($firstRunner['owner_sandbox_run_id'] ?? ''),
            'repair_owner_sandbox_run_id' => (string) ($repairRunner['owner_sandbox_run_id'] ?? ''),
            'first_result_status' => (string) ($firstOwnerResult['result_status'] ?? $firstOwnerResult['status'] ?? ''),
            'repair_result_status' => (string) ($repairResult['result_status'] ?? $repairResult['status'] ?? ''),
            'first_diagnostics' => $firstDiagnostics,
            'first_blockers' => $this->stringList(data_get($firstOwnerResult, 'runtime_invocation.command_result.owner_cli_blockers', [])),
            'first_provider_calls' => max(0, (int) data_get($firstOwnerResult, 'runtime_invocation.command_result.owner_cli_provider_calls', 0)),
            'first_diff_hash' => $this->candidateDiffHash($firstOwnerResult),
            'repair_diff_hash' => $this->candidateDiffHash($repairResult),
            'feedback_context' => $feedback,
        ];
    }

    /**
     * Stable hash of the candidate diff for repeated-repair detection. Prefers
     * the literal diff/patch text; otherwise falls back to the sorted changed
     * files plus the failure signature so "same broken diff" is still caught.
     *
     * @param  array<string,mixed>  $ownerResult
     */
    private function candidateDiffHash(array $ownerResult): string
    {
        $diff = $this->candidateDiff($ownerResult);
        if ($diff !== null && trim($diff) !== '') {
            return 'sha256:'.hash('sha256', $diff);
        }

        $changed = $this->stringList($ownerResult['changed_files'] ?? data_get($ownerResult, 'evidence_pack.changed_files', []));
        if ($changed === []) {
            return '';
        }
        sort($changed);
        $signature = (string) data_get($ownerResult, 'runtime_invocation.senior_loop.run_summary.verification_receipt_hash', '');
        if ($signature === '') {
            foreach ((array) data_get($ownerResult, 'runtime_invocation.senior_loop.debug_loop.failure_capsules', []) as $capsule) {
                if (is_array($capsule) && (string) ($capsule['failure_signature'] ?? '') !== '') {
                    $signature = (string) $capsule['failure_signature'];
                    break;
                }
            }
        }

        return 'sha256:'.hash('sha256', implode('|', $changed).'::'.$signature);
    }

    /**
     * @param  array<string,mixed>  $ownerResult
     */
    private function candidateDiff(array $ownerResult): ?string
    {
        foreach (['diff', 'patch'] as $key) {
            $value = $ownerResult[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return $value;
            }
        }
        foreach (['diff', 'patch', 'unified_diff'] as $key) {
            $value = data_get($ownerResult, 'evidence_pack.'.$key);
            if (is_string($value) && trim($value) !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $input
     * @param  list<string>  $allowedFiles
     * @return list<string>
     */
    private function forbiddenFiles(array $input, array $allowedFiles): array
    {
        $forbidden = $this->stringList($input['forbidden_files'] ?? []);
        if ($forbidden !== []) {
            return $forbidden;
        }

        // Without an explicit list, sensitive infra is forbidden by default so
        // the repair never drifts into secrets/config/env outside its scope.
        return array_values(array_filter([
            'config/secrets.php',
            '.env',
            'composer.json',
        ], static fn (string $file): bool => ! in_array($file, $allowedFiles, true)));
    }

    /**
     * @param  array<string,mixed>  $input
     * @param  list<string>  $command
     */
    private function primaryValidationCommandForFeedback(array $input, array $command): ?string
    {
        foreach ($command as $part) {
            if (is_string($part) && str_starts_with($part, '--validation-command=')) {
                $value = trim(substr($part, strlen('--validation-command=')));
                if (preg_match('/phpunit|artisan test/i', $value) === 1) {
                    return $value;
                }
            }
        }

        return $this->nullableString($input['validation_command'] ?? null);
    }

    /**
     * @param  array<string,mixed>  $input
     * @param  array<string,mixed>  $ownerResult
     */
    private function sandboxCurrentCommit(array $input, array $ownerResult): ?string
    {
        $explicit = $this->nullableString($input['sandbox_current_commit'] ?? null);
        if ($explicit !== null) {
            return $explicit;
        }
        foreach ([
            'runtime_invocation.command_result.sandbox_head',
            'runtime_invocation.command_result.sandbox_current_commit',
            'evidence_pack.sandbox_head',
        ] as $path) {
            $value = trim((string) data_get($ownerResult, $path, ''));
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $ownerResult
     * @param  list<string>  $firstDiagnostics
     */
    private function lastErrorSummary(array $ownerResult, array $firstDiagnostics): string
    {
        $debugReason = trim((string) data_get($ownerResult, 'runtime_invocation.senior_loop.debug_loop.reason', ''));
        if ($debugReason !== '') {
            return mb_substr($debugReason, 0, 400);
        }
        if ($firstDiagnostics !== []) {
            return mb_substr(implode('; ', $firstDiagnostics), 0, 400);
        }

        return 'senior_loop_execution_not_passed';
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value !== '' ? $value : null;
    }

    /**
     * Honest terminal report for a repair short-circuit (repeated-repair or
     * review-lock). It records evidence through AP-750 so the cycle is auditable
     * and surfaces the precise blocker the runner uses to advance.
     *
     * @param  list<array<string,mixed>>  $steps
     * @param  array<string,mixed>  $repairAttempt
     * @param  array<string,mixed>  $ownerResult
     * @return array<string,mixed>
     */
    private function repairShortCircuitReport(string $shortCircuit, string $owner, array $steps, array $repairAttempt, array $ownerResult): array
    {
        [$blocker, $reason] = $shortCircuit === self::STATUS_REPEATED_REPAIR_NO_PROGRESS
            ? [
                'owner_runtime_repeated_repair_no_progress',
                'The repair agent re-emitted a diff already tried this cycle ('.(string) ($repairAttempt['repeated_diff_hash'] ?? '').'); stopped before another provider call. Advance to a different finding or change scope.',
            ]
            : [
                'owner_runtime_review_locked',
                sprintf(
                    'Slice failed repair %d times (ceiling %d); review-locked so the loop advances to the next finding. Last error: %s.',
                    max(0, (int) ($repairAttempt['repair_failure_count'] ?? self::REPAIR_REVIEW_LOCK_THRESHOLD)),
                    self::REPAIR_REVIEW_LOCK_THRESHOLD,
                    (string) ($repairAttempt['last_error_summary'] ?? 'senior_loop_execution_not_passed'),
                ),
            ];

        return [
            'schema_version' => self::REPORT_SCHEMA,
            'ap_contract' => 'AP-786',
            'status' => $shortCircuit,
            'owner' => $owner,
            'uses_full_owner_runtime_chain' => true,
            'provider_router_used' => false,
            'merge_allowed' => false,
            'reason' => $blocker,
            'owner_result' => $ownerResult,
            'steps' => $steps,
            'repair_attempt' => $repairAttempt,
            'blockers' => [$blocker],
            'blocker_details' => [[
                'blocker' => $blocker,
                'reason' => $reason,
            ]],
            'claim_policy' => $this->claimPolicy(),
            'generated_at' => gmdate('c'),
        ];
    }

    /**
     * A provider process can time out after writing a valid scoped diff and
     * after the senior loop has already captured passing scope/verification
     * evidence. In that narrow case, keep the evidence honest but do not throw
     * away the completed patch solely because the provider failed to exit.
     *
     * @param  array<string,mixed>  $ownerResult
     * @param  list<string>  $allowedFiles
     * @param  list<string>  $changedFiles
     * @return array<string,mixed>
     */
    private function validatedTimeoutSalvage(string $owner, array $ownerResult, array $allowedFiles, array $changedFiles): array
    {
        $timedOut = (bool) data_get($ownerResult, 'runtime_invocation.command_result.timed_out', false);
        $providerErrors = $this->stringList(data_get($ownerResult, 'runtime_invocation.senior_loop.run_summary.provider_call.error_codes', []));
        $providerTimedOut = $timedOut || in_array('timeout', $providerErrors, true);

        $scopePassed = $this->evidenceGatePassed($ownerResult, 'scope_guard')
            || (string) data_get($ownerResult, 'runtime_invocation.senior_loop.run_summary.scope_guard_status', '') === 'passed';
        $verificationPassed = $this->evidenceGatePassed($ownerResult, 'verification')
            || (string) data_get($ownerResult, 'runtime_invocation.senior_loop.run_summary.verification_status', '') === 'passed';

        $changedWithinScope = $changedFiles !== [];
        foreach ($changedFiles as $file) {
            if (! in_array($file, $allowedFiles, true)) {
                $changedWithinScope = false;
                break;
            }
        }

        $salvaged = $owner === 'atlas_dev'
            && $providerTimedOut
            && $changedWithinScope
            && $scopePassed
            && $verificationPassed;

        return [
            'salvaged' => $salvaged,
            'reason' => $salvaged ? 'provider_timed_out_after_validated_scoped_diff' : '',
            'provider_timed_out' => $providerTimedOut,
            'scope_guard_passed' => $scopePassed,
            'verification_passed' => $verificationPassed,
            'changed_files_within_allowed_scope' => $changedWithinScope,
            'changed_file_count' => count($changedFiles),
        ];
    }

    /**
     * @param  array<string,mixed>  $ownerResult
     * @param  array<string,mixed>  $salvage
     * @return array<string,mixed>
     */
    private function withValidatedTimeoutSalvage(array $ownerResult, array $salvage): array
    {
        $ownerResult['result_status'] = 'completed';
        $ownerResult['status'] = 'completed';
        $ownerResult['completion_state'] = 'passed';
        $ownerResult['summary'] = 'Atlas owner runtime produced a scoped diff with passing verification before the provider process timed out.';
        $ownerResult['validated_timeout_salvage'] = $salvage;
        data_set($ownerResult, 'runtime_invocation.command_result.owner_cli_completion_state', 'passed');
        data_set($ownerResult, 'runtime_invocation.command_result.owner_cli_status', 'completed');
        data_set($ownerResult, 'runtime_invocation.command_result.owner_cli_blockers', []);
        data_set($ownerResult, 'runtime_invocation.senior_loop.run_summary.status', 'completed');
        data_set($ownerResult, 'runtime_invocation.senior_loop.run_summary.completion_state', 'passed');

        return $ownerResult;
    }

    /**
     * @param  array<string,mixed>  $ownerResult
     */
    private function evidenceGatePassed(array $ownerResult, string $gate): bool
    {
        foreach ((array) ($ownerResult['test_results'] ?? data_get($ownerResult, 'evidence_pack.test_results', [])) as $result) {
            if (! is_array($result)) {
                continue;
            }
            if ((string) ($result['gate'] ?? '') === $gate && (string) ($result['status'] ?? '') === 'passed') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string,mixed>  $ownerResult
     * @param  list<string>  $allowedFiles
     */
    private function shouldRetryAtlasDevOwnerRuntime(string $owner, array $ownerResult, array $allowedFiles): bool
    {
        if ($owner !== 'atlas_dev') {
            return false;
        }

        if ((string) ($ownerResult['result_status'] ?? $ownerResult['status'] ?? '') === 'completed') {
            return false;
        }

        $changedFiles = $this->stringList($ownerResult['changed_files'] ?? data_get($ownerResult, 'evidence_pack.changed_files', []));
        if ($changedFiles === []) {
            return false;
        }

        foreach ($changedFiles as $file) {
            if (! in_array($file, $allowedFiles, true)) {
                return false;
            }
        }

        $providerCalls = (int) data_get($ownerResult, 'runtime_invocation.command_result.owner_cli_provider_calls', 0);
        $providerInvoked = (bool) ($ownerResult['provider_invoked'] ?? data_get($ownerResult, 'runtime_invocation.provider_invoked', false));
        if ($providerCalls < 1 && $providerInvoked !== true) {
            return false;
        }

        $blockers = $this->stringList(data_get($ownerResult, 'runtime_invocation.command_result.owner_cli_blockers', []));
        $completion = (string) ($ownerResult['completion_state'] ?? data_get($ownerResult, 'runtime_invocation.command_result.owner_cli_completion_state', ''));

        return $completion === 'failed' || in_array('senior_loop_execution_not_passed', $blockers, true);
    }

    /**
     * @param  list<string>  $command
     * @param  array<string,mixed>  $ownerResult
     * @param  array<string,mixed>  $feedback  targeted repair-agent feedback context
     * @return list<string>
     */
    private function repairCommand(array $command, array $ownerResult, array $feedback = []): array
    {
        $reason = 'Previous AP-759 senior-loop attempt edited allowed files but failed focused verification. Repair the failing test output only; keep the existing diff scoped and rerun the same validation command.';
        $diagnostics = $this->ownerRuntimeFailureDiagnostics($ownerResult, $command);
        if ($diagnostics !== []) {
            $reason .= ' Diagnostics: '.implode('; ', $diagnostics).'.';
        }
        $reason .= $this->repairFeedbackSegment($feedback);

        foreach ($command as $i => $part) {
            if (is_string($part) && str_starts_with($part, '--intent=')) {
                $command[$i] = '--intent='.$this->sanitizeIntentForExecutableRouting(
                    mb_substr($reason.' '.substr($part, strlen('--intent=')), 0, 2400)
                );

                return $command;
            }
        }

        $command[] = '--intent='.$this->sanitizeIntentForExecutableRouting(mb_substr($reason, 0, 2400));

        return $command;
    }

    /**
     * Render the targeted repair-agent feedback context into a concise prompt
     * segment so the next attempt sees the exact failed test, error, scope,
     * expected namespace, validation command and rejection reason — instead of
     * guessing and re-emitting the same broken diff.
     *
     * @param  array<string,mixed>  $feedback
     */
    private function repairFeedbackSegment(array $feedback): string
    {
        if ($feedback === []) {
            return '';
        }

        $parts = [];
        $failedTest = trim((string) ($feedback['failed_test'] ?? ''));
        if ($failedTest !== '') {
            $parts[] = 'FAILED_TEST: '.$failedTest;
        }
        $errorOutput = trim((string) ($feedback['test_error_output'] ?? ''));
        if ($errorOutput !== '') {
            $parts[] = 'TEST_ERROR: '.mb_substr($errorOutput, 0, 600);
        }
        $lintErrors = is_array($feedback['lint_errors'] ?? null) ? $feedback['lint_errors'] : [];
        if ($lintErrors !== []) {
            $rendered = [];
            foreach (array_slice($lintErrors, 0, 3) as $lint) {
                if (! is_array($lint)) {
                    continue;
                }
                $file = trim((string) ($lint['file'] ?? ''));
                $line = $lint['line'] ?? null;
                $rendered[] = $file.($line !== null ? ':'.$line : '');
            }
            if ($rendered !== []) {
                $parts[] = 'LINT_ERRORS: '.implode(', ', $rendered);
            }
        }
        $failedFile = trim((string) ($feedback['failed_file'] ?? ''));
        if ($failedFile !== '') {
            $parts[] = 'FAILED_FILE: '.$failedFile;
        }
        $expectedNamespace = trim((string) ($feedback['expected_namespace'] ?? ''));
        if ($expectedNamespace !== '') {
            $parts[] = 'EXPECTED_NAMESPACE: '.$expectedNamespace;
        }
        $validationCommand = trim((string) ($feedback['validation_command'] ?? ''));
        if ($validationCommand !== '') {
            $parts[] = 'VALIDATION_COMMAND: '.$validationCommand;
        }
        $mergeRejection = trim((string) ($feedback['merge_rejection_reason'] ?? ''));
        if ($mergeRejection !== '') {
            $parts[] = 'MERGE_REJECTION: '.$mergeRejection;
        }
        $forbidden = is_array($feedback['forbidden_files'] ?? null) ? $feedback['forbidden_files'] : [];
        $forbidden = array_values(array_filter(array_map(
            static fn ($f): string => is_string($f) ? trim($f) : '',
            $forbidden,
        ), static fn (string $f): bool => $f !== ''));
        if ($forbidden !== []) {
            $parts[] = 'DO_NOT_TOUCH: '.implode(', ', array_slice($forbidden, 0, 5));
        }
        $attempt = max(1, (int) ($feedback['repair_attempt_number'] ?? 1));
        $parts[] = 'REPAIR_ATTEMPT: '.$attempt;

        return $parts === [] ? '' : ' REPAIR_CONTEXT: '.implode('; ', $parts).'.';
    }

    private function repairTimeoutSeconds(int $timeout): int
    {
        return max(60, min(300, $timeout));
    }

    /**
     * @param  array<string,mixed>  $ownerResult
     * @return list<string>
     */
    private function ownerRuntimeFailureDiagnostics(array $ownerResult, array $command = []): array
    {
        $diagnostics = [];
        $completion = (string) data_get($ownerResult, 'runtime_invocation.command_result.owner_cli_completion_state', '');
        if ($completion !== '') {
            $diagnostics[] = 'completion_state='.$this->safeCliValue($completion);
        }

        $debugReason = trim((string) data_get($ownerResult, 'runtime_invocation.senior_loop.debug_loop.reason', ''));
        if ($debugReason !== '') {
            $diagnostics[] = 'debug_reason='.$this->safeCliValue($debugReason);
        }

        foreach ([
            'scope_guard_status' => 'runtime_invocation.senior_loop.run_summary.scope_guard_status',
            'verification_status' => 'runtime_invocation.senior_loop.run_summary.verification_status',
            'verification_receipt_hash' => 'runtime_invocation.senior_loop.run_summary.verification_receipt_hash',
            'persisted_ref' => 'runtime_invocation.senior_loop.persisted_ref',
            'error_ledger_ref' => 'runtime_invocation.senior_loop.learning.error_ledger_ref',
        ] as $label => $path) {
            $value = (string) data_get($ownerResult, $path, '');
            if ($value !== '') {
                $diagnostics[] = $label.'='.$this->safeCliValue($value);
            }
        }

        $failureRefs = [];
        foreach ((array) data_get($ownerResult, 'runtime_invocation.senior_loop.debug_loop.failure_capsules', []) as $capsule) {
            if (is_array($capsule) && (string) ($capsule['ref'] ?? '') !== '') {
                $failureRefs[] = $this->safeCliValue((string) $capsule['ref']);
            }
        }
        if ($failureRefs !== []) {
            $diagnostics[] = 'failure_capsules='.implode(',', array_slice(array_values(array_unique($failureRefs)), 0, 3));
        }
        foreach ($this->failureCapsuleDiagnostics($ownerResult, $command) as $capsuleDiagnostic) {
            $diagnostics[] = $capsuleDiagnostic;
        }

        $changedFiles = $this->stringList($ownerResult['changed_files'] ?? []);
        if ($changedFiles !== []) {
            $diagnostics[] = 'changed_files='.implode(',', array_map(
                fn (string $file): string => $this->safeCliValue($file),
                array_slice($changedFiles, 0, 5),
            ));
        }

        return array_values(array_unique($diagnostics));
    }

    /**
     * @param  array<string,mixed>  $ownerResult
     * @param  list<string>  $command
     * @return list<string>
     */
    private function failureCapsuleDiagnostics(array $ownerResult, array $command): array
    {
        $workspace = $this->workspaceFromCommand($command);
        if ($workspace === '') {
            return [];
        }

        $diagnostics = [];
        foreach ((array) data_get($ownerResult, 'runtime_invocation.senior_loop.debug_loop.failure_capsules', []) as $capsule) {
            if (! is_array($capsule)) {
                continue;
            }
            $ref = trim((string) ($capsule['ref'] ?? ''));
            if ($ref === '' || str_contains($ref, '..') || str_starts_with($ref, '/')) {
                continue;
            }
            $path = $workspace.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'atlas-dev'.DIRECTORY_SEPARATOR.$ref;
            if (! is_file($path)) {
                continue;
            }
            $payload = json_decode((string) file_get_contents($path), true);
            if (! is_array($payload)) {
                continue;
            }
            foreach ([
                'failing_test' => 'failing_test',
                'primary_error' => 'primary_error_excerpt',
                'failure_signature' => 'failure_signature',
            ] as $label => $key) {
                $value = trim((string) ($payload[$key] ?? ''));
                if ($value !== '') {
                    $diagnostics[] = $label.'='.$this->safeCliValue($value);
                }
            }
            $verificationLog = $this->failureCapsuleVerificationLogExcerpt($payload, $workspace);
            if ($verificationLog !== '') {
                $diagnostics[] = 'verification_log='.$this->safeCliValue($verificationLog);
            }
            if (count($diagnostics) >= 6) {
                break;
            }
        }

        return array_values(array_unique($diagnostics));
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function failureCapsuleVerificationLogExcerpt(array $payload, string $workspace): string
    {
        foreach ($this->failureLogPaths($payload) as $candidate) {
            $path = $this->resolveWorkspaceLogPath($workspace, $candidate);
            if ($path === '') {
                continue;
            }

            $excerpt = $this->verificationLogExcerptFromFile($path);
            if ($excerpt !== '') {
                return $excerpt;
            }
        }

        return '';
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return list<string>
     */
    private function failureLogPaths(array $payload): array
    {
        $paths = [];
        foreach (['output_path', 'full_error_log_path', 'error_log_path', 'test_log_path'] as $key) {
            $value = trim((string) ($payload[$key] ?? ''));
            if ($value !== '') {
                $paths[] = $value;
            }
        }

        foreach (['primary_error_excerpt', 'primary_error', 'error'] as $key) {
            $value = (string) ($payload[$key] ?? '');
            if ($value === '') {
                continue;
            }
            if (preg_match_all('/(?:output_path|full_error_log_path|error_log_path|test_log_path)=([^\\s)]+)/', $value, $matches) > 0) {
                foreach ($matches[1] ?? [] as $match) {
                    $paths[] = trim((string) $match, " \t\n\r\0\x0B'\"");
                }
            }
        }

        return array_values(array_unique(array_filter($paths, static fn (string $path): bool => $path !== '')));
    }

    private function resolveWorkspaceLogPath(string $workspace, string $candidate): string
    {
        if ($candidate === '' || str_contains($candidate, "\0")) {
            return '';
        }

        $workspaceRoot = realpath($workspace);
        if ($workspaceRoot === false) {
            return '';
        }

        $paths = str_starts_with($candidate, DIRECTORY_SEPARATOR)
            ? [$candidate]
            : [
                $workspace.DIRECTORY_SEPARATOR.$candidate,
                $workspace.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'atlas-dev'.DIRECTORY_SEPARATOR.$candidate,
            ];

        foreach ($paths as $path) {
            $real = realpath($path);
            if ($real === false || ! is_file($real)) {
                continue;
            }
            if ($real !== $workspaceRoot && ! str_starts_with($real, $workspaceRoot.DIRECTORY_SEPARATOR)) {
                continue;
            }

            return $real;
        }

        return '';
    }

    private function verificationLogExcerptFromFile(string $path): string
    {
        $contents = $this->readFilePrefix($path, 65536);
        if ($contents === '') {
            return '';
        }

        $strings = [];
        $decoded = json_decode($contents, true);
        if (is_array($decoded)) {
            $this->collectLogStrings($decoded, $strings);
        } else {
            $strings[] = $contents;
        }

        return $this->selectVerificationLogExcerpt($strings);
    }

    private function readFilePrefix(string $path, int $bytes): string
    {
        $handle = @fopen($path, 'rb');
        if (! is_resource($handle)) {
            return '';
        }

        try {
            return (string) fread($handle, $bytes);
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param  array<mixed>  $payload
     * @param  list<string>  $strings
     */
    private function collectLogStrings(array $payload, array &$strings): void
    {
        foreach ($payload as $value) {
            if (count($strings) >= 40) {
                return;
            }
            if (is_string($value) && trim($value) !== '') {
                $strings[] = $value;

                continue;
            }
            if (is_array($value)) {
                $this->collectLogStrings($value, $strings);
            }
        }
    }

    /**
     * @param  list<string>  $strings
     */
    private function selectVerificationLogExcerpt(array $strings): string
    {
        $lines = [];
        foreach ($strings as $string) {
            $clean = (string) preg_replace('/\e\[[0-9;]*m/', '', $string);
            foreach (preg_split('/\r\n|\r|\n/', $clean) ?: [] as $line) {
                $line = trim((string) $line);
                if ($line !== '') {
                    $lines[] = $line;
                }
            }
        }

        foreach ($lines as $line) {
            if (preg_match('/psr-4|autoload|class .*not found|fatal error|parse error|exception|error|failed/i', $line) === 1) {
                return $line;
            }
        }

        return $lines[0] ?? '';
    }

    /** @param list<string> $command */
    private function workspaceFromCommand(array $command): string
    {
        foreach ($command as $part) {
            if (! is_string($part) || ! str_starts_with($part, '--workspace=')) {
                continue;
            }
            $workspace = trim(substr($part, strlen('--workspace=')));
            if ($workspace !== '' && is_dir($workspace)) {
                return $workspace;
            }
        }

        return '';
    }

    /**
     * Build an AP-765-compatible execution_result from the AP-759 owner_result
     * so AP-786 can emit Product Mode / Inbox evidence before any merge attempt.
     *
     * @param  array<string,mixed>  $ownerResult
     * @param  array<string,mixed>  $consumption
     * @param  array<string,mixed>  $finding
     * @param  list<string>  $command
     * @param  list<array<string,mixed>>  $steps
     * @param  list<string>  $blockers
     * @param  array<string,mixed>  $repairAttempt
     * @return array<string,mixed>
     */
    private function executionResult(
        array $ownerResult,
        array $consumption,
        array $finding,
        string $worktree,
        string $owner,
        array $command,
        string $ownerSandboxRunId,
        string $dispatchKind,
        bool $planOnly,
        array $steps,
        array $blockers,
        array $repairAttempt,
        array $validatedTimeoutSalvage = [],
    ): array {
        $changedFiles = $this->stringList($ownerResult['changed_files'] ?? []);
        $tests = $this->stringList($ownerResult['tests'] ?? data_get($ownerResult, 'evidence_pack.tests', []));
        $testResults = is_array($ownerResult['test_results'] ?? null) ? $ownerResult['test_results'] : (array) data_get($ownerResult, 'evidence_pack.test_results', []);
        $completionState = (string) ($ownerResult['completion_state'] ?? data_get($ownerResult, 'runtime_invocation.command_result.owner_cli_completion_state', ''));
        $status = (string) ($ownerResult['result_status'] ?? $ownerResult['status'] ?? 'partial');

        return [
            'schema_version' => 'atlas.software_company_stewardship.ap786_owner_flow_execution_result.v1',
            'execution_id' => (string) ($ownerResult['result_id'] ?? ''),
            'owner' => $owner,
            'result_status' => $status,
            'completion_state' => $completionState !== '' ? $completionState : ($status === 'completed' ? 'passed' : 'failed'),
            'summary' => (string) ($ownerResult['summary'] ?? data_get($ownerResult, 'evidence_pack.summary', 'Atlas owner runtime ran an allowlisted command inside the AP-756 sandbox via AP-759.')),
            'finding_id' => (string) ($finding['finding_id'] ?? ''),
            'spec_id' => (string) data_get($finding, 'spec_seed.candidate_id', ''),
            'handoff_id' => 'AP-786:'.(string) ($consumption['consumption_id'] ?? ''),
            'sandbox_id' => (string) data_get($consumption, 'sandbox_binding.sandbox_id', ''),
            'branch_ref' => (string) data_get($consumption, 'sandbox_binding.branch_name', ''),
            'worktree_path' => $worktree,
            'changed_files' => $changedFiles,
            'tests' => $tests !== [] ? $tests : ['atlas:dev:senior-loop:run (AP-759 owner command)'],
            'validation_commands' => [implode(' ', array_map(static fn ($p): string => (string) $p, $command))],
            'test_results' => $testResults,
            'evidence_pack' => is_array($ownerResult['evidence_pack'] ?? null) ? $ownerResult['evidence_pack'] : [
                'summary' => 'AP-759 owner runtime command receipt.',
                'changed_files' => $changedFiles,
                'tests' => $tests,
            ],
            'risks' => $this->stringList($ownerResult['risks'] ?? []),
            'rollback' => (string) ($ownerResult['rollback'] ?? 'Discard the isolated AP-756 branch/worktree; no merge was performed.'),
            'runtime_execution_started' => true,
            'uses_full_owner_runtime_chain' => true,
            'provider_router_used' => false,
            'owner_sandbox_run_id' => $ownerSandboxRunId,
            'real_execution_bridge' => [
                'schema_version' => self::REAL_EXECUTION_BRIDGE_SCHEMA,
                'ap790_backlog_item' => self::AP790_BACKLOG_OWNER_RUNTIME_REAL_EXECUTION_BRIDGE,
                'owner_chain_ap_contracts' => $this->ownerChainApContracts($steps),
                'dispatch_kind' => $dispatchKind,
                'plan_only' => $planOnly,
                'repair_attempt' => $repairAttempt,
                'validated_timeout_salvage' => $validatedTimeoutSalvage,
                'blockers' => $blockers,
            ],
            'provider_invoked' => (bool) ($ownerResult['provider_invoked'] ?? false),
            'merge_performed' => false,
            'deploy_performed' => false,
            'external_push_performed' => false,
            'secret_access' => false,
            'destructive_change' => false,
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $steps
     * @return list<string>
     */
    private function ownerChainApContracts(array $steps): array
    {
        $contracts = [];
        foreach ($steps as $step) {
            if (! is_array($step)) {
                continue;
            }
            $ap = trim((string) ($step['ap_contract'] ?? ''));
            if ($ap !== '' && ! in_array($ap, $contracts, true)) {
                $contracts[] = $ap;
            }
        }

        return $contracts;
    }

    /**
     * @param  array<string,mixed>  $ownerResult
     * @param  array<string,mixed>  $repairAttempt
     * @return array{blockers:list<string>,details:list<array<string,string>>}
     */
    private function ownerRuntimeBlockerReport(array $ownerResult, bool $forgePlanned, bool $completed, array $repairAttempt = []): array
    {
        if ($completed) {
            return ['blockers' => [], 'details' => []];
        }
        if ($forgePlanned) {
            return [
                'blockers' => ['forge_runtime_dispatch_planned_only'],
                'details' => [[
                    'blocker' => 'forge_runtime_dispatch_planned_only',
                    'reason' => 'Forge runtime-dispatch produced a governed plan only; re-run with live Obra authority or switch owner to atlas_dev for executable patches.',
                ]],
            ];
        }

        $blockers = [];
        $details = [];
        $commandResult = is_array(data_get($ownerResult, 'runtime_invocation.command_result'))
            ? data_get($ownerResult, 'runtime_invocation.command_result')
            : [];
        $resultStatus = strtolower(trim((string) ($ownerResult['result_status'] ?? $ownerResult['status'] ?? '')));
        $completion = strtolower(trim((string) ($commandResult['owner_cli_completion_state'] ?? '')));
        $providerCalls = max(0, (int) ($commandResult['owner_cli_provider_calls'] ?? 0));
        $changedFiles = $this->stringList($ownerResult['changed_files'] ?? []);
        $routingDecision = strtolower(trim((string) data_get(
            $ownerResult,
            'runtime_invocation.senior_loop.routing_decision',
            data_get($ownerResult, 'runtime_invocation.senior_loop.run_summary.routing_decision', ''),
        )));
        $debugReason = trim((string) data_get($ownerResult, 'runtime_invocation.senior_loop.debug_loop.reason', ''));
        $providerErrors = $this->stringList(data_get($ownerResult, 'runtime_invocation.senior_loop.run_summary.provider_call.error_codes', []));
        $commandTimedOut = (bool) ($commandResult['timed_out'] ?? false);

        if ($completion === 'no_patch_needed') {
            if ($providerCalls === 0 || $changedFiles === []) {
                $blockers[] = 'owner_runtime_no_patch_needed_without_proof';
                $details[] = [
                    'blocker' => 'owner_runtime_no_patch_needed_without_proof',
                    'reason' => 'Atlas Dev returned no_patch_needed without provider proof or sandbox diff; edit an allowed file or cite file:line plus passing focused test output.',
                ];
            } else {
                $blockers[] = 'owner_runtime_no_patch_needed';
                $details[] = [
                    'blocker' => 'owner_runtime_no_patch_needed',
                    'reason' => 'Provider claimed no_patch_needed despite execution; prove the exact acceptance criterion with file:line evidence and passing focused tests.',
                ];
            }
        }

        if ($commandTimedOut || in_array('timeout', $providerErrors, true)) {
            $blockers[] = 'owner_runtime_provider_timeout';
            $details[] = [
                'blocker' => 'owner_runtime_provider_timeout',
                'reason' => 'Owner provider invocation timed out before producing a mergeable diff; retry with a larger provider timeout or reroute through AtlasDecide failover.',
            ];
        }

        if ($completion === 'failed' || (string) ($commandResult['owner_cli_status'] ?? '') === 'failed') {
            $details[] = [
                'blocker' => 'owner_runtime_senior_loop_failed',
                'reason' => $debugReason !== ''
                    ? $debugReason
                    : 'Senior loop failed verification or scope; inspect verification_receipt and scope_guard in the AP-759 stdout JSON.',
            ];
        }

        if ($resultStatus !== '' && $resultStatus !== 'completed' && $completion === '' && $blockers === []) {
            $blockers[] = 'owner_runtime_result_failed';
            $details[] = [
                'blocker' => 'owner_runtime_result_failed',
                'reason' => 'Owner runtime returned result_status='.$resultStatus.' without machine-readable owner_cli_blockers; AP-759 must expose completion_state/blockers or the candidate is quarantined instead of being retried blindly.',
            ];
        }

        foreach ($this->stringList($commandResult['owner_cli_blockers'] ?? []) as $blocker) {
            $mapped = match ($blocker) {
                'senior_loop_execution_not_passed' => 'owner_runtime_senior_loop_execution_not_passed',
                'routing_not_executable' => 'owner_runtime_routing_not_executable',
                'scope_violation' => 'owner_runtime_scope_violation',
                default => 'owner_runtime_'.$blocker,
            };
            $blockers[] = $mapped;
            $exitCode = $blocker === 'senior_loop_execution_not_passed'
                ? ($commandResult['exit_code'] ?? null)
                : null;
            $stderrExcerpt = $blocker === 'senior_loop_execution_not_passed'
                ? trim((string) ($commandResult['stderr_excerpt'] ?? ''))
                : '';
            $details[] = [
                'blocker' => $mapped,
                'reason' => match ($blocker) {
                    'senior_loop_execution_not_passed' => 'Senior loop did not reach passed scope_guard and verification; apply a minimal patch in allowed_files and rerun the focused worktree validation command.'
                        .($exitCode !== null ? ' (exit_code='.$exitCode.')' : '')
                        .($stderrExcerpt !== '' ? ' stderr: '.mb_substr($stderrExcerpt, 0, 500) : ''),
                    'routing_not_executable' => $routingDecision !== ''
                        ? 'Atlas Dev routing blocked execution (routing_decision='.$routingDecision.'); keep the task as a scoped repair with allowed_files and avoid forge-preview trigger phrases in the owner intent.'
                        : 'Atlas Dev routing blocked execution; keep the task as a scoped repair inside allowed_files only.',
                    'scope_violation' => 'Patch touched paths outside allowed_files; restrict edits to the declared allowed_files list.',
                    default => 'Owner CLI reported blocker '.$blocker.'; inspect AP-759 command_result stdout JSON.',
                },
            ];
        }

        if ($completion === 'scope_violation' && ! in_array('owner_runtime_scope_violation', $blockers, true)) {
            $blockers[] = 'owner_runtime_scope_violation';
            $details[] = [
                'blocker' => 'owner_runtime_scope_violation',
                'reason' => 'Completion state scope_violation indicates edits outside allowed_files; restrict changes to the declared scope.',
            ];
        }

        if (($repairAttempt['retried'] ?? false) === true) {
            $firstDiagnostics = $this->stringList($repairAttempt['first_diagnostics'] ?? []);
            $blockers[] = 'owner_runtime_senior_loop_repair_exhausted';
            $details[] = [
                'blocker' => 'owner_runtime_senior_loop_repair_exhausted',
                'reason' => sprintf(
                    'AP-786 retried senior-loop once after scoped diff (first_run=%s status=%s provider_calls=%d, repair_run=%s status=%s); first diagnostics: %s.',
                    (string) ($repairAttempt['first_owner_sandbox_run_id'] ?? ''),
                    (string) ($repairAttempt['first_result_status'] ?? ''),
                    max(0, (int) ($repairAttempt['first_provider_calls'] ?? 0)),
                    (string) ($repairAttempt['repair_owner_sandbox_run_id'] ?? ''),
                    (string) ($repairAttempt['repair_result_status'] ?? ''),
                    $firstDiagnostics !== [] ? implode('; ', $firstDiagnostics) : 'none',
                ),
            ];
        }

        $blockers = array_values(array_unique($blockers !== [] ? $blockers : ['owner_runtime_result_not_completed']));
        if ($details === [] && $blockers !== []) {
            $details[] = [
                'blocker' => $blockers[0],
                'reason' => 'Owner runtime did not complete with mergeable evidence; review changed_files and test_results on the AP-759 owner_result.',
            ];
        }

        return ['blockers' => $blockers, 'details' => $details];
    }

    /**
     * @param  array<string,mixed>  $finding
     * @param  list<string>  $allowedFiles
     * @param  list<string>  $validationCommands
     */
    private function buildOwnerIntent(array $finding, array $allowedFiles, array $validationCommands, string $worktree): string
    {
        $title = trim((string) ($finding['title'] ?? ''));
        $detail = trim((string) ($finding['detail'] ?? $finding['why_it_matters'] ?? ''));
        $nextAction = trim((string) ($finding['proposed_next_action'] ?? ''));
        $tests = $this->testsRequiredForHandoff($finding, $allowedFiles);
        $acceptance = $this->acceptanceForHandoff($finding);
        $scopeFiles = $allowedFiles !== [] ? $allowedFiles : $this->stringList($finding['affected_files'] ?? []);
        $primaryTest = $this->primaryTestPath($tests, $validationCommands);
        $patchMandate = $this->patchMandate($primaryTest, $scopeFiles, $worktree);

        $segments = array_filter([
            'Implement the smallest correct scoped repair now inside allowed_files only.',
            $title !== '' ? 'OBJECTIVE: '.$title : null,
            $detail !== '' ? 'WHY: '.$detail : null,
            $nextAction !== '' ? 'NEXT: '.$nextAction : null,
            $scopeFiles !== [] ? 'ALLOWED_FILES: '.implode(', ', $scopeFiles) : null,
            $tests !== [] ? 'TESTS_REQUIRED: '.implode(', ', $tests) : null,
            $acceptance !== [] ? 'ACCEPTANCE: '.implode(' | ', array_slice($acceptance, 0, 3)) : null,
            'PATCH_MANDATE: '.$patchMandate,
            'Must edit an allowed file or cite exact proof (file:line plus passing focused test output).',
            'no_patch_needed is invalid unless the focused test already proves this exact improvement.',
        ], static fn (?string $line): bool => is_string($line) && trim($line) !== '');

        $intent = $this->sanitizeIntentForExecutableRouting(implode(' ', $segments));

        return $intent === '' ? 'Implement the smallest correct fix inside the allowed files only.' : mb_substr($intent, 0, 2400);
    }

    /**
     * @param  array<string,mixed>  $finding
     * @param  list<string>  $allowedFiles
     * @return list<string>
     */
    private function testsRequiredForHandoff(array $finding, array $allowedFiles): array
    {
        $tests = $this->stringList(data_get($finding, 'spec_seed.tests_required', []));
        foreach ($allowedFiles as $file) {
            if (str_starts_with($file, 'tests/') || str_ends_with($file, 'Test.php')) {
                $tests[] = $file;
            }
        }

        return array_values(array_unique($tests));
    }

    /**
     * @param  array<string,mixed>  $finding
     * @return list<string>
     */
    private function acceptanceForHandoff(array $finding): array
    {
        $acceptance = $this->stringList(data_get($finding, 'spec_seed.acceptance', []));
        if ($acceptance !== []) {
            return $acceptance;
        }

        $title = trim((string) ($finding['title'] ?? ''));

        return $title !== '' ? ['Given the selected finding, '.$title.' is implemented and proven by the focused test.'] : [];
    }

    /**
     * @param  list<string>  $tests
     * @param  list<string>  $validationCommands
     */
    private function primaryTestPath(array $tests, array $validationCommands): string
    {
        foreach ($tests as $test) {
            if (str_starts_with($test, 'tests/') && str_ends_with($test, '.php')) {
                return $test;
            }
        }

        foreach ($validationCommands as $command) {
            if (preg_match('/php artisan test\s+(\S+\.php)/', $command, $matches) === 1) {
                return (string) $matches[1];
            }
        }

        return '';
    }

    /**
     * @param  list<string>  $scopeFiles
     */
    private function patchMandate(string $primaryTest, array $scopeFiles, string $worktree): string
    {
        if ($primaryTest !== '' && ! $this->testFileExists($primaryTest, $worktree)) {
            return 'CREATE focused test '.$primaryTest.' with a failing assertion that proves the gap, then implement the minimal runtime fix in '.($scopeFiles !== [] ? implode(', ', $scopeFiles) : 'allowed_files').'.';
        }
        if ($primaryTest !== '') {
            return 'HARDEN '.$primaryTest.' with a specific assertion that fails before the fix and passes after the minimal change in '.($scopeFiles !== [] ? implode(', ', $scopeFiles) : 'allowed_files').'.';
        }

        return 'Apply a minimal code change in '.($scopeFiles !== [] ? implode(', ', $scopeFiles) : 'allowed_files').' and prove it with the declared validation_command.';
    }

    private function testFileExists(string $relativePath, string $worktree): bool
    {
        $candidates = [];
        if ($worktree !== '') {
            $candidates[] = rtrim($worktree, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.ltrim($relativePath, '/');
        }
        if (function_exists('base_path')) {
            $candidates[] = base_path($relativePath);
        }

        foreach ($candidates as $path) {
            if (is_file($path)) {
                return true;
            }
        }

        return false;
    }

    private function sanitizeIntentForExecutableRouting(string $intent): string
    {
        $intent = (string) preg_replace('/[;&|<>`$\r\n]+/', ' ', $intent);
        $intent = trim((string) preg_replace('/\s+/', ' ', $intent));
        $replacements = [
            '/\bforge promotion preview\b/i' => 'factory runtime preview',
            '/\bmulti-?agent\b/i' => 'governed workcell',
            '/\batlas dev\s*\/\s*forge flow\b/i' => 'AAEOS software-development flow',
            '/\bdev\s*\/\s*forge flow\b/i' => 'software-development flow',
            '/\batlas dev and forge flow\b/i' => 'AAEOS software-development flow',
            '/\bdev and forge flow\b/i' => 'software-development flow',
            '/\bforge obra\b/i' => 'factory obra',
            '/\bobra de\b/i' => 'factory work packet',
            '/\bwhole system\b/i' => 'scoped factory module',
            '/\bentire codebase\b/i' => 'scoped codebase slice',
            '/\batlas forge\b/i' => 'atlas factory runtime',
            '/\bforge runtime\b/i' => 'factory runtime',
            '/\bforge\b/i' => 'factory',
            '/\bcouncil\b/i' => 'review group',
        ];
        foreach ($replacements as $pattern => $replacement) {
            $intent = (string) preg_replace($pattern, $replacement, $intent);
        }

        return trim($intent);
    }

    private function artisanPath(string $worktree = ''): string
    {
        $worktreeArtisan = $worktree !== ''
            ? rtrim($worktree, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'artisan'
            : '';
        if ($worktreeArtisan !== '' && is_file($worktreeArtisan)) {
            return $worktreeArtisan;
        }

        return function_exists('base_path') ? base_path('artisan') : 'artisan';
    }

    /**
     * @param  list<string>  $allowedFiles
     * @param  list<string>  $validationCommands
     * @return list<string>
     */
    private function atlasDevCommand(string $worktree, string $intent, array $allowedFiles, array $validationCommands, string $provider, string $model, int $providerTimeout = 600): array
    {
        $command = [
            PHP_BINARY,
            $this->artisanPath($worktree),
            'atlas:dev:senior-loop:run',
            '--workspace='.$worktree,
            '--intent='.$intent,
            '--surface-id=atlas_cli_dev',
            '--flow-origin=atlas_ai_router',
            '--operator-explicit',
            '--provider-choice='.$provider,
            '--composer-model='.$model,
            '--provider-timeout-seconds='.max(60, min(1200, $providerTimeout)),
            '--json',
        ];

        foreach ($allowedFiles as $file) {
            $file = $this->safeCliValue($file);
            if ($file !== '') {
                $command[] = '--allowed-file='.$file;
            }
        }

        foreach ($validationCommands as $validationCommand) {
            $validationCommand = $this->safeCliValue($this->worktreeValidationCommand($validationCommand));
            if ($validationCommand !== '') {
                $command[] = '--validation-command='.$validationCommand;
            }
        }

        return $command;
    }

    private function worktreeValidationCommand(string $command): string
    {
        $command = trim($command);
        if (preg_match('/^php artisan test\s+(\S+\.php)$/', $command, $matches) === 1) {
            return './vendor/bin/phpunit --configuration=phpunit.xml '.(string) $matches[1];
        }

        return $command;
    }

    /**
     * @param  array<string,mixed>  $input
     */
    private function providerChoice(array $input): string
    {
        $provider = strtolower(trim((string) ($input['provider'] ?? $input['provider_choice'] ?? 'cursor_cli')));

        return match ($provider) {
            'cursor', 'cursor-agent', 'cursor_agent', 'composer', 'composer_2_5' => 'cursor_cli',
            'claude', 'claude-code', 'claude_code', 'sonnet', 'opus' => 'claude_cli',
            'codex', 'openai_codex' => 'codex_cli',
            'gemini' => 'gemini_cli',
            default => $provider !== '' ? $provider : 'cursor_cli',
        };
    }

    /**
     * @param  array<string,mixed>  $input
     */
    private function modelFamily(array $input, string $provider): string
    {
        $model = trim((string) ($input['model'] ?? $input['model_family'] ?? ''));
        if ($model !== '') {
            return $model;
        }

        return $provider === 'cursor_cli' ? 'composer-2.5-fast' : 'sonnet';
    }

    private function safeCliValue(string $value): string
    {
        $value = trim((string) preg_replace('/[;&|<>`$\r\n]+/', ' ', $value));
        $value = (string) preg_replace('/\s+/', ' ', $value);

        return mb_substr($value, 0, 240);
    }

    /**
     * @param  array<string,mixed>  $extra
     * @return array<string,mixed>
     */
    private function blocked(string $reason, string $owner, array $steps, array $extra = []): array
    {
        return [
            'schema_version' => self::REPORT_SCHEMA,
            'ap_contract' => 'AP-786',
            'status' => self::STATUS_BLOCKED,
            'owner' => $owner,
            'uses_full_owner_runtime_chain' => $steps !== [],
            'provider_router_used' => false,
            'merge_allowed' => false,
            'reason' => $reason,
            'blockers' => [$reason],
            'steps' => $steps,
            'claim_policy' => $this->claimPolicy(),
            'generated_at' => gmdate('c'),
        ] + $extra;
    }

    /**
     * @return array<string,mixed>
     */
    private function step(string $ap, string $name, string $status): array
    {
        return ['ap_contract' => $ap, 'step' => $name, 'status' => (string) $status];
    }

    /**
     * @return array<string,bool|string>
     */
    private function claimPolicy(): array
    {
        return [
            'mode' => 'full_atlas_owner_runtime_flow',
            'provider_router_invoked' => false,
            'direct_provider_driver_used' => false,
            'owner_command_runs_only_via_ap759' => true,
            'merges' => false,
            'deploys' => false,
            'external_push' => false,
            'secret_access' => false,
            'operator_review_required' => true,
        ];
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (mixed $item): string => is_string($item) ? trim($item) : '',
            $value,
        ), static fn (string $item): bool => $item !== ''));
    }
}
