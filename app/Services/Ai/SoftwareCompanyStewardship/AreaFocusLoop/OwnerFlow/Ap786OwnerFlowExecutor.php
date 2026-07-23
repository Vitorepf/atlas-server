<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\OwnerFlow;

use App\Services\Ai\Programming\AtlasForgeProviderInvocationDriverRouter;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusDevForgeReleaseService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusOwnerQueueConsumptionGateService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusProviderNormalizer;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusScalarNormalizer;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusStringListNormalizer;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\RepairAgentFeedbackContextBuilderService;
use App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution\StewardshipOutcomeEvidenceBridgeService;
use App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution\StewardshipOwnerRuntimeExecutionAdapterService;
use App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution\StewardshipOwnerRuntimeResultBridgeService;
use App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution\StewardshipOwnerSandboxRuntimeRunnerService;
use App\Services\Ai\Support\JsonFileStore;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\OwnerFlow\Ap786OwnerFlow\Ap786OwnerFlowDiagnosticsSection;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\OwnerFlow\Ap786OwnerFlow\Ap786OwnerFlowIntentSection;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\OwnerFlow\Ap786OwnerFlow\Ap786OwnerFlowPreflightSignalsSection;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\OwnerFlow\Ap786OwnerFlow\Ap786OwnerFlowReportSection;

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

    /**
     * FASE 2 — the zero-provider pre-flight gate refused to spend tokens on an
     * unqualified slice. A cheap skip: no provider was invoked and the cycle is
     * NOT counted as token-spending in the merges/token-spending-cycles metric.
     */
    public const STATUS_PREFLIGHT_SKIPPED = 'preflight_skipped';

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

    private readonly ZeroProviderPreflightGate $preflightGate;

    private readonly Ap786OwnerFlowIntentSection $intentBuilder;

    private readonly Ap786OwnerFlowPreflightSignalsSection $preflightSignals;

    private readonly Ap786OwnerFlowReportSection $reporting;

    private readonly Ap786OwnerFlowDiagnosticsSection $diagnostics;

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
        ?ZeroProviderPreflightGate $preflightGate = null,
    ) {
        // Pre-return validation gate: defaults to a real subprocess runner in
        // production; tests inject a fake so the unit suite never shells out.
        $this->repairValidation = $repairValidation ?? new ShellRepairValidationRunner;
        $this->repairFeedback = $repairFeedback ?? new RepairAgentFeedbackContextBuilderService;
        $this->preflightGate = $preflightGate ?? new ZeroProviderPreflightGate;
        $this->intentBuilder = new Ap786OwnerFlowIntentSection;
        $this->preflightSignals = new Ap786OwnerFlowPreflightSignalsSection;
        $this->reporting = new Ap786OwnerFlowReportSection;
        $this->diagnostics = new Ap786OwnerFlowDiagnosticsSection($this->intentBuilder);
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
        $allowedFiles = AreaFocusStringListNormalizer::trimmedStrings($input['allowed_files'] ?? []);
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

        // FASE 2 — zero-provider pre-flight gate. Evaluated FIRST for atlas_dev so
        // an unqualified slice is skipped for free, before any owner command
        // (senior-loop / minimax-worker) and before any provider spend. A skip is
        // honest: status=preflight_skipped, merge never allowed, and the cycle is
        // explicitly NOT counted as token-spending in the loop metric.
        if ($owner === 'atlas_dev') {
            // Compute the test-authoring deliverability signal (reads the subject class from
            // the AP-756 worktree, which already exists at this point) and attach it so the
            // pure pre-flight gate can skip a not-autonomously-testable existing-class subject
            // BEFORE any provider spend — protecting the >=96% useful-output bar. Runtime
            // bugfix slices also carry a focused test path, so only pure test-authoring work
            // should be classified here; otherwise high-value factory fixes get skipped
            // solely because the target service is large.
            $findingForPreflight = array_replace($finding, [
                'preflight_test_authoring' => $this->testAuthoringSubjectSignal(
                    $finding,
                    $allowedFiles,
                    (string) ($input['worktree_path'] ?? ''),
                ),
                'preflight_runtime_surface' => $this->runtimeMutationSurfaceSignal(
                    $finding,
                    $allowedFiles,
                    (string) ($input['worktree_path'] ?? ''),
                ),
            ]);
            $preflightGate = $this->preflightGate->evaluate(
                $allowedFiles,
                AreaFocusStringListNormalizer::trimmedStrings($input['validation_commands'] ?? []),
                $findingForPreflight,
            );
            if (($preflightGate['admitted'] ?? false) !== true) {
                return $this->preflightSkipped($owner, $steps, $preflightGate);
            }
        }

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
        //    atlas_dev -> senior loop (or minimax-worker when provider=minimax_m27_cli);
        //    forge -> AP-787 governed dispatch command.
        $planOnly = false;
        $dispatchKind = $provider === 'minimax_m27_cli' ? 'atlas_dev_minimax_worker' : 'atlas_dev_senior_loop';
        $receiptExtra = [];
        if ($owner === 'forge') {
            $command = array_values(array_map(static fn ($p): string => (string) $p, (array) ($forgeDispatchPlan['command'] ?? [])));
            $receiptExtra = is_array($forgeDispatchPlan['receipt_extra'] ?? null) ? $forgeDispatchPlan['receipt_extra'] : [];
            $planOnly = (bool) ($forgeDispatchPlan['plan_only'] ?? false);
            $dispatchKind = (string) ($forgeDispatchPlan['dispatch_kind'] ?? ForgeOwnerRuntimeDispatchBridge::KIND_RUNTIME_DISPATCH);
        } else {
            $validationCommands = AreaFocusStringListNormalizer::trimmedStrings($input['validation_commands'] ?? []);
            if ($provider === 'minimax_m27_cli') {
                $command = $this->atlasMinimaxWorkerCommand(
                    $worktree,
                    $finding,
                    $allowedFiles,
                    $validationCommands,
                );
            } else {
                $command = $this->atlasDevCommand(
                    $worktree,
                    $this->buildOwnerIntent($finding, $allowedFiles, $validationCommands, $worktree),
                    $allowedFiles,
                    $validationCommands,
                    $provider,
                    $model,
                    $providerTimeout,
                );
            }
            $receiptExtra = [
                'provider_execution_authorized' => true,
                'budget_approved' => true,
                'provider_choice' => $provider,
                'model_family' => $model,
            ];
        }
        $receiptExtra = array_replace($receiptExtra, $this->supervisorReceipt($input));
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
        $firstChangedFiles = AreaFocusStringListNormalizer::trimmedStrings($ownerResult['changed_files'] ?? data_get($ownerResult, 'evidence_pack.changed_files', []));
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
        $changedFiles = AreaFocusStringListNormalizer::trimmedStrings($ownerResult['changed_files'] ?? data_get($ownerResult, 'evidence_pack.changed_files', []));
        $validatedTimeoutSalvage = $this->validatedTimeoutSalvage($owner, $ownerResult, $allowedFiles, $changedFiles);
        if (($validatedTimeoutSalvage['salvaged'] ?? false) === true) {
            $ownerResult = $this->withValidatedTimeoutSalvage($ownerResult, $validatedTimeoutSalvage);
            $resultStatus = (string) ($ownerResult['result_status'] ?? $ownerResult['status'] ?? '');
            $changedFiles = AreaFocusStringListNormalizer::trimmedStrings($ownerResult['changed_files'] ?? data_get($ownerResult, 'evidence_pack.changed_files', []));
        }

        $minimaxCodexReview = null;
        if ($this->shouldRunMinimaxCodexPreCommitReview($owner, $provider, $execute, $resultStatus, $changedFiles)) {
            $review = $this->runMinimaxCodexPreCommitReview(
                $areaId,
                $portfolioId,
                $adapter,
                $runner,
                $ownerResult,
                $finding,
                $allowedFiles,
                $validationCommands,
                $actor,
                $timeout,
                $providerTimeout,
                $execute,
                $receiptExtra,
                $worktree,
                $input,
                $provider,
            );
            $steps[] = $this->step('AP-759', 'minimax_codex_pre_commit_review', (string) data_get($review, 'runner.status', 'blocked'));
            $runner = $review['runner'];
            $ownerResult = $review['owner_result'];
            $command = $review['command'];
            $minimaxCodexReview = $review['review'];
            $resultStatus = (string) ($ownerResult['result_status'] ?? $ownerResult['status'] ?? '');
            $changedFiles = AreaFocusStringListNormalizer::trimmedStrings($ownerResult['changed_files'] ?? data_get($ownerResult, 'evidence_pack.changed_files', []));
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
        // Bug #2 hard rule (provider proof): an atlas_dev cycle that produced
        // file changes MUST have invoked a real provider. A patch with zero
        // provider calls is deterministic scaffold, never a completable implement
        // attempt — it must never be merged or counted as success.
        $providerCalls = $this->ownerCliProviderCalls($ownerResult);
        $providerCallsTotal = $providerCalls + AreaFocusScalarNormalizer::nonNegativeInt($minimaxCodexReview['reviewed_provider_calls'] ?? 0);
        // Completion requires a real owner result; forge additionally requires
        // real changed files (a plan with no changes can never be completed)
        // AND provider proof (SEC-001): a forge diff with zero provider calls is
        // unattributed (stray worktree files / local stub) and must never merge
        // or count as success — same provider-proof law as atlas_dev below.
        $completed = $resultStatus === 'completed'
            && $bridgeReady
            && ! $forgePlanned
            && ($owner !== 'forge' || ($changedFiles !== [] && $providerCalls > 0))
            && ($owner !== 'atlas_dev' || $changedFiles === [] || $providerCalls > 0);
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
            // FASE 2 metric: this cycle passed the pre-flight gate and reached the
            // owner command, so it IS a token-spending cycle (forge plan-only runs
            // never call a provider and are excluded). merges/token-spending-cycles
            // counts only cycles flagged true here and skips preflight_skipped ones.
            'token_spending_cycle' => $owner !== 'forge' || ! $planOnly,
            'merge_allowed' => $completed,
            'consumption_id' => (string) ($consumption['consumption_id'] ?? ''),
            'release_id' => (string) ($consumption['release_id'] ?? $release['release_id'] ?? ''),
            'queue_item_id' => (string) ($consumption['queue_item_id'] ?? ''),
            'owner_execution_id' => (string) ($adapter['owner_execution_id'] ?? ''),
            'owner_sandbox_run_id' => (string) ($runner['owner_sandbox_run_id'] ?? ''),
            'owner_result' => $ownerResult,
            'result_bridge' => $resultBridge,
            'result_bridge_id' => (string) ($resultBridge['result_bridge_id'] ?? ''),
            'minimax_codex_review' => $minimaxCodexReview,
            'provider_calls_total' => $providerCallsTotal,
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
     * @param  list<string>  $changedFiles
     */
    private function shouldRunMinimaxCodexPreCommitReview(string $owner, string $provider, bool $execute, string $resultStatus, array $changedFiles): bool
    {
        return $execute
            && $owner === 'atlas_dev'
            && in_array($provider, ['minimax_m27_cli', 'claude_cli'], true)
            && $resultStatus === 'completed'
            && $changedFiles !== [];
    }

    /**
     * @param  array<string,mixed>  $adapter
     * @param  array<string,mixed>  $minimaxRunner
     * @param  array<string,mixed>  $minimaxResult
     * @param  array<string,mixed>  $finding
     * @param  list<string>  $allowedFiles
     * @param  list<string>  $validationCommands
     * @param  array<string,mixed>  $receiptExtra
     * @param  array<string,mixed>  $input
     * @return array{runner:array<string,mixed>,owner_result:array<string,mixed>,command:list<string>,review:array<string,mixed>}
     */
    private function runMinimaxCodexPreCommitReview(
        string $areaId,
        string $portfolioId,
        array $adapter,
        array $minimaxRunner,
        array $minimaxResult,
        array $finding,
        array $allowedFiles,
        array $validationCommands,
        string $actor,
        int $timeout,
        int $providerTimeout,
        bool $execute,
        array $receiptExtra,
        string $worktree,
        array $input,
        string $reviewedProvider,
    ): array {
        $reviewModel = $this->codexReviewModel($input);
        $reviewProviderTimeout = $this->codexReviewProviderTimeout($input, $providerTimeout);
        $reviewCommand = $this->atlasDevCommand(
            $worktree,
            $this->buildMinimaxCodexReviewIntent($finding, $allowedFiles, $validationCommands, $minimaxResult),
            $allowedFiles,
            $validationCommands,
            'codex_cli',
            $reviewModel,
            $reviewProviderTimeout,
        );
        $reviewRunner = $this->runOwnerRuntimeCommand($areaId, $portfolioId, $adapter, $reviewCommand, $actor, max($timeout, $reviewProviderTimeout + 120), $execute, array_replace($receiptExtra, [
            'provider_choice' => 'codex_cli',
            'model_family' => $reviewModel,
            'minimax_codex_pre_commit_review' => true,
            'review_before_commit' => true,
            'reviewed_provider_choice' => $reviewedProvider,
            'review_provider_choice' => 'codex_cli',
            'reviewed_owner_sandbox_run_id' => (string) ($minimaxRunner['owner_sandbox_run_id'] ?? ''),
        ]));
        $reviewResult = is_array($reviewRunner['owner_result'] ?? null) ? $reviewRunner['owner_result'] : [];
        $reviewProviderCalls = $this->ownerCliProviderCalls($reviewResult);
        $reviewChangedFiles = AreaFocusStringListNormalizer::trimmedStrings($reviewResult['changed_files'] ?? data_get($reviewResult, 'evidence_pack.changed_files', []));
        $reviewStatus = (string) ($reviewResult['result_status'] ?? $reviewResult['status'] ?? '');
        $reviewCompletionState = strtolower(trim((string) ($reviewResult['completion_state'] ?? data_get($reviewResult, 'runtime_invocation.command_result.owner_cli_completion_state', ''))));
        $runnerStatus = (string) ($reviewRunner['status'] ?? '');
        $runnerReady = in_array($runnerStatus, [
            StewardshipOwnerSandboxRuntimeRunnerService::STATUS_READY,
            StewardshipOwnerSandboxRuntimeRunnerService::STATUS_RECORDED,
        ], true);
        $reviewBlockers = AreaFocusStringListNormalizer::trimmedStrings(data_get($reviewResult, 'runtime_invocation.command_result.owner_cli_blockers', []));
        $minimaxChangedFiles = AreaFocusStringListNormalizer::trimmedStrings($minimaxResult['changed_files'] ?? data_get($minimaxResult, 'evidence_pack.changed_files', []));
        $reviewFinishedCleanly = $reviewStatus === 'completed'
            || (in_array($reviewCompletionState, ['passed', 'completed', 'no_patch_needed'], true) && $reviewBlockers === []);
        $reviewScopeOk = $reviewChangedFiles === [] || $this->diffTouchesAllowedScope($reviewChangedFiles, $allowedFiles);
        $minimaxScopeOk = $this->diffTouchesAllowedScope($minimaxChangedFiles, $allowedFiles);
        $accepted = $runnerReady
            && $reviewFinishedCleanly
            && $reviewProviderCalls > 0
            && $reviewScopeOk
            && $minimaxScopeOk;
        $reviewReceipt = [
            'schema_version' => 'atlas.software_company_stewardship.minimax_codex_pre_commit_review.v1',
            'status' => $accepted ? 'accepted' : 'blocked',
            'review_before_commit' => true,
            'review_provider_choice' => 'codex_cli',
            'review_model_family' => $reviewModel,
            'reviewed_provider_choice' => $reviewedProvider,
            'reviewed_owner_sandbox_run_id' => (string) ($minimaxRunner['owner_sandbox_run_id'] ?? ''),
            'review_owner_sandbox_run_id' => (string) ($reviewRunner['owner_sandbox_run_id'] ?? ''),
            'review_runner_status' => $runnerStatus,
            'review_result_status' => $reviewStatus,
            'review_completion_state' => $reviewCompletionState,
            'review_provider_calls' => $reviewProviderCalls,
            'reviewed_provider_calls' => $this->ownerCliProviderCalls($minimaxResult),
            'review_changed_files' => $reviewChangedFiles,
            'review_kept_minimax_patch' => $reviewChangedFiles === [],
            'review_blockers' => $reviewBlockers,
            'quality_feedback' => $this->minimaxCodexQualityFeedback($accepted, $reviewResult),
        ];

        if ($accepted) {
            $acceptedResult = $reviewChangedFiles !== [] ? $reviewResult : $minimaxResult;

            return [
                'runner' => $reviewRunner,
                'owner_result' => $this->withMinimaxCodexReview($acceptedResult, $reviewReceipt),
                'command' => $reviewCommand,
                'review' => $reviewReceipt,
            ];
        }

        return [
            'runner' => $reviewRunner,
            'owner_result' => $this->withMinimaxCodexReviewFailure($reviewResult !== [] ? $reviewResult : $minimaxResult, $reviewReceipt),
            'command' => $reviewCommand,
            'review' => $reviewReceipt,
        ];
    }

    /**
     * @param  array<string,mixed>  $finding
     * @param  list<string>  $allowedFiles
     * @param  list<string>  $validationCommands
     * @param  array<string,mixed>  $minimaxResult
     */
    private function buildMinimaxCodexReviewIntent(array $finding, array $allowedFiles, array $validationCommands, array $minimaxResult): string
    {
        $title = trim((string) ($finding['title'] ?? ''));
        $changed = AreaFocusStringListNormalizer::trimmedStrings($minimaxResult['changed_files'] ?? data_get($minimaxResult, 'evidence_pack.changed_files', []));
        $acceptance = AreaFocusStringListNormalizer::trimmedStrings($finding['acceptance_criteria'] ?? data_get($finding, 'spec_seed.acceptance', []));
        $testsRequired = AreaFocusStringListNormalizer::trimmedStrings(data_get($finding, 'spec_seed.tests_required', []));
        $segments = array_filter([
            'Review and repair the existing provider patch before commit. Inspect the current workspace diff, keep useful scoped changes, fix correctness or quality issues, and leave blockers if the patch is not mergeable.',
            $title !== '' ? 'OBJECTIVE: '.$title : null,
            $allowedFiles !== [] ? 'ALLOWED_FILES: '.implode(', ', $allowedFiles) : null,
            $changed !== [] ? 'PROVIDER_CHANGED_FILES: '.implode(', ', $changed) : null,
            $acceptance !== [] ? 'ACCEPTANCE_CRITERIA: '.implode(' ; ', array_slice($acceptance, 0, 8)) : null,
            $testsRequired !== [] ? 'TESTS_REQUIRED: '.implode(', ', array_slice($testsRequired, 0, 6)) : null,
            $validationCommands !== [] ? 'VALIDATION_COMMANDS: '.implode(' | ', array_slice($validationCommands, 0, 3)) : null,
            'Do not broaden scope. Edit only allowed_files. Run the declared validation. Success means the provider patch is now production-quality and ready for AP-750/merge governance.',
            'Return concise quality feedback for improving future provider prompts when you had to repair or block anything.',
        ], static fn (?string $line): bool => is_string($line) && trim($line) !== '');

        return mb_substr($this->sanitizeIntentForExecutableRouting(implode(' ', $segments)), 0, 2400);
    }

    /**
     * @param  array<string,mixed>  $reviewResult
     * @param  array<string,mixed>  $reviewReceipt
     * @return array<string,mixed>
     */
    private function withMinimaxCodexReview(array $reviewResult, array $reviewReceipt): array
    {
        $reviewResult['minimax_codex_review'] = $reviewReceipt;
        $reviewResult['provider_invoked'] = true;

        return $reviewResult;
    }

    /**
     * @param  array<string,mixed>  $ownerResult
     * @param  array<string,mixed>  $reviewReceipt
     * @return array<string,mixed>
     */
    private function withMinimaxCodexReviewFailure(array $ownerResult, array $reviewReceipt): array
    {
        $ownerResult['result_status'] = 'failed';
        $ownerResult['status'] = 'failed';
        $ownerResult['completion_state'] = 'failed';
        $ownerResult['summary'] = 'MiniMax patch was blocked by the Codex CLI pre-commit review gate.';
        $ownerResult['minimax_codex_review'] = $reviewReceipt;

        $blockers = AreaFocusStringListNormalizer::uniqueMergedStringValues(
            AreaFocusStringListNormalizer::trimmedStrings(data_get($ownerResult, 'runtime_invocation.command_result.owner_cli_blockers', [])),
            ['minimax_codex_review_not_passed'],
        );
        data_set($ownerResult, 'runtime_invocation.command_result.owner_cli_completion_state', 'failed');
        data_set($ownerResult, 'runtime_invocation.command_result.owner_cli_status', 'failed');
        data_set($ownerResult, 'runtime_invocation.command_result.owner_cli_blockers', $blockers);

        return $ownerResult;
    }

    /**
     * @param  array<string,mixed>  $reviewResult
     * @return list<string>
     */
    private function minimaxCodexQualityFeedback(bool $accepted, array $reviewResult): array
    {
        $feedback = [];
        $summary = trim((string) ($reviewResult['summary'] ?? data_get($reviewResult, 'evidence_pack.summary', '')));
        if ($summary !== '') {
            $feedback[] = mb_substr($summary, 0, 400);
        }
        foreach (AreaFocusStringListNormalizer::trimmedStrings(data_get($reviewResult, 'runtime_invocation.command_result.owner_cli_blockers', [])) as $blocker) {
            $feedback[] = 'blocker='.$blocker;
        }
        if ($feedback === []) {
            $feedback[] = $accepted
                ? 'codex_review_passed_without_additional_feedback'
                : 'tighten_minimax_prompt_or_slice_scope_before_merge';
        }

        return array_slice(AreaFocusStringListNormalizer::uniqueStringValues($feedback), 0, 5);
    }

    /**
     * @param  array<string,mixed>  $input
     */
    private function codexReviewModel(array $input): string
    {
        $explicit = trim((string) ($input['codex_review_model'] ?? ''));
        if ($explicit !== '') {
            return $explicit;
        }
        $premium = function_exists('config') ? config('atlas.ai.providers.codex_cli.premium_model') : null;
        if (is_string($premium) && trim($premium) !== '') {
            return trim($premium);
        }
        $model = function_exists('config') ? config('atlas.ai.providers.codex_cli.model') : null;

        return is_string($model) && trim($model) !== '' ? trim($model) : 'gpt-5.3-codex-spark';
    }

    /**
     * @param  array<string,mixed>  $input
     */
    private function codexReviewProviderTimeout(array $input, int $default): int
    {
        $raw = $input['codex_review_provider_timeout_seconds'] ?? null;
        $seconds = is_numeric($raw) ? (int) $raw : max($default, 600);

        return max(60, min(1800, $seconds));
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,string>
     */
    private function supervisorReceipt(array $input): array
    {
        $killSwitchPath = trim((string) ($input['ap790_kill_switch_path'] ?? ''));
        if ($killSwitchPath === '') {
            return [];
        }

        return [
            'supervisor' => 'AP-790',
            'kill_switch_path' => $killSwitchPath,
        ];
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
                'validation_commands' => AreaFocusStringListNormalizer::trimmedStrings($input['validation_commands'] ?? []),
                'rejected_diff' => $this->candidateDiff($ownerResult),
                'merge_rejection_reason' => AreaFocusScalarNormalizer::nullableStringOnly($input['merge_rejection_reason'] ?? null),
                'sandbox_current_commit' => $this->sandboxCurrentCommit($input, $ownerResult),
                'expected_namespace' => AreaFocusScalarNormalizer::nullableStringOnly($input['expected_namespace'] ?? null),
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
            $repairChanged = AreaFocusStringListNormalizer::trimmedStrings($repairResult['changed_files'] ?? data_get($repairResult, 'evidence_pack.changed_files', []));
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

    private function diffTouchesAllowedScope(array $changedFiles, array $allowedFiles): bool
    {
        return $this->reporting->diffTouchesAllowedScope($changedFiles, $allowedFiles);
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
        $firstProviderCalls = $this->ownerCliProviderCalls($firstOwnerResult);
        $repairProviderCalls = $this->ownerCliProviderCalls($repairResult);

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
            'first_blockers' => AreaFocusStringListNormalizer::trimmedStrings(data_get($firstOwnerResult, 'runtime_invocation.command_result.owner_cli_blockers', [])),
            'first_provider_calls' => $firstProviderCalls,
            'repair_provider_calls' => $repairProviderCalls,
            'provider_calls_total' => $firstProviderCalls + $repairProviderCalls,
            'provider_invoked_any_attempt' => (bool) ($firstOwnerResult['provider_invoked'] ?? data_get($firstOwnerResult, 'runtime_invocation.provider_invoked', false))
                || (bool) ($repairResult['provider_invoked'] ?? data_get($repairResult, 'runtime_invocation.provider_invoked', false))
                || $firstProviderCalls > 0
                || $repairProviderCalls > 0,
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

        $changed = AreaFocusStringListNormalizer::trimmedStrings($ownerResult['changed_files'] ?? data_get($ownerResult, 'evidence_pack.changed_files', []));
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
        $forbidden = AreaFocusStringListNormalizer::trimmedStrings($input['forbidden_files'] ?? []);
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

        return AreaFocusScalarNormalizer::nullableStringOnly($input['validation_command'] ?? null);
    }

    /**
     * @param  array<string,mixed>  $input
     * @param  array<string,mixed>  $ownerResult
     */
    private function sandboxCurrentCommit(array $input, array $ownerResult): ?string
    {
        $explicit = AreaFocusScalarNormalizer::nullableStringOnly($input['sandbox_current_commit'] ?? null);
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

    private function repairShortCircuitReport(string $shortCircuit, string $owner, array $steps, array $repairAttempt, array $ownerResult): array
    {
        return $this->reporting->repairShortCircuitReport($shortCircuit, $owner, $steps, $repairAttempt, $ownerResult);
    }

    private function validatedTimeoutSalvage(string $owner, array $ownerResult, array $allowedFiles, array $changedFiles): array
    {
        return $this->reporting->validatedTimeoutSalvage($owner, $ownerResult, $allowedFiles, $changedFiles);
    }

    private function withValidatedTimeoutSalvage(array $ownerResult, array $salvage): array
    {
        return $this->reporting->withValidatedTimeoutSalvage($ownerResult, $salvage);
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

        $changedFiles = AreaFocusStringListNormalizer::trimmedStrings($ownerResult['changed_files'] ?? data_get($ownerResult, 'evidence_pack.changed_files', []));
        if ($changedFiles === []) {
            return false;
        }

        foreach ($changedFiles as $file) {
            if (! in_array($file, $allowedFiles, true)) {
                return false;
            }
        }

        $providerCalls = $this->ownerCliProviderCalls($ownerResult);
        $providerInvoked = (bool) ($ownerResult['provider_invoked'] ?? data_get($ownerResult, 'runtime_invocation.provider_invoked', false));
        if ($providerCalls < 1 && $providerInvoked !== true) {
            return false;
        }

        $blockers = AreaFocusStringListNormalizer::trimmedStrings(data_get($ownerResult, 'runtime_invocation.command_result.owner_cli_blockers', []));
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
        $reason = $this->providerSafeRepairIntent($reason.$this->repairFeedbackSegment($feedback));

        // The minimax-worker runtime has NO --intent option (it takes --finding-json
        // + an internal --max-repairs loop). Appending --intent= there makes artisan
        // abort with "The --intent option does not exist" before any provider call —
        // exactly why backlog build-slices never reached MiniMax. Route the repair
        // reason into the finding JSON's proposed_next_action instead.
        $isMinimaxWorker = in_array('atlas:dev:minimax-worker:run', $command, true);
        if ($isMinimaxWorker) {
            foreach ($command as $i => $part) {
                if (is_string($part) && str_starts_with($part, '--finding-json=')) {
                    $decoded = json_decode(substr($part, strlen('--finding-json=')), true);
                    if (is_array($decoded)) {
                        $existing = (string) ($decoded['proposed_next_action'] ?? '');
                        $decoded['proposed_next_action'] = mb_substr(trim($reason.' '.$existing), 0, 2400);
                        $decoded['repair_context'] = mb_substr($reason, 0, 2400);
                        $command[$i] = '--finding-json='.json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                    }

                    return $command;
                }
            }

            return $command;
        }

        foreach ($command as $i => $part) {
            if (is_string($part) && str_starts_with($part, '--intent=')) {
                $command[$i] = '--intent='.$this->providerSafeRepairIntent(
                    mb_substr($reason.' '.substr($part, strlen('--intent=')), 0, 2400)
                );

                return $command;
            }
        }

        $command[] = '--intent='.$this->providerSafeRepairIntent(mb_substr($reason, 0, 2400));

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
        $forbidden = AreaFocusStringListNormalizer::trimmedStrings($forbidden);
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

    private function ownerRuntimeFailureDiagnostics(array $ownerResult, array $command = []): array
    {
        return $this->diagnostics->ownerRuntimeFailureDiagnostics($ownerResult, $command);
    }

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
        return $this->reporting->executionResult($ownerResult, $consumption, $finding, $worktree, $owner, $command, $ownerSandboxRunId, $dispatchKind, $planOnly, $steps, $blockers, $repairAttempt, $validatedTimeoutSalvage);
    }

    private function ownerRuntimeBlockerReport(array $ownerResult, bool $forgePlanned, bool $completed, array $repairAttempt = []): array
    {
        return $this->reporting->ownerRuntimeBlockerReport($ownerResult, $forgePlanned, $completed, $repairAttempt);
    }

    private function buildOwnerIntent(array $finding, array $allowedFiles, array $validationCommands, string $worktree): string
    {
        return $this->intentBuilder->buildOwnerIntent($finding, $allowedFiles, $validationCommands, $worktree);
    }

    private function sanitizeIntentForExecutableRouting(string $intent): string
    {
        return $this->intentBuilder->sanitizeIntentForExecutableRouting($intent);
    }

    private function providerSafeRepairIntent(string $intent): string
    {
        return $this->intentBuilder->providerSafeRepairIntent($intent);
    }

    private function atlasDevCommand(string $worktree, string $intent, array $allowedFiles, array $validationCommands, string $provider, string $model, int $providerTimeout = 600): array
    {
        return $this->intentBuilder->atlasDevCommand($worktree, $intent, $allowedFiles, $validationCommands, $provider, $model, $providerTimeout);
    }

    private function testAuthoringSubjectSignal(array $finding, array $allowedFiles, string $worktree): array
    {
        return $this->preflightSignals->testAuthoringSubjectSignal($finding, $allowedFiles, $worktree);
    }

    private function runtimeMutationSurfaceSignal(array $finding, array $allowedFiles, string $worktree): array
    {
        return $this->preflightSignals->runtimeMutationSurfaceSignal($finding, $allowedFiles, $worktree);
    }

    private function atlasMinimaxWorkerCommand(string $worktree, array $finding, array $allowedFiles, array $validationCommands): array
    {
        return $this->intentBuilder->atlasMinimaxWorkerCommand($worktree, $finding, $allowedFiles, $validationCommands);
    }

    private function providerChoice(array $input): string
    {
        return $this->intentBuilder->providerChoice($input);
    }

    private function modelFamily(array $input, string $provider): string
    {
        return $this->intentBuilder->modelFamily($input, $provider);
    }

    private function blocked(string $reason, string $owner, array $steps, array $extra = []): array
    {
        return $this->reporting->blocked($reason, $owner, $steps, $extra);
    }

    private function preflightSkipped(string $owner, array $steps, array $preflightGate): array
    {
        return $this->reporting->preflightSkipped($owner, $steps, $preflightGate);
    }

    private function step(string $ap, string $name, string $status): array
    {
        return $this->reporting->step($ap, $name, $status);
    }

    private function ownerCliProviderCalls(array $ownerResult): int
    {
        return $this->reporting->ownerCliProviderCalls($ownerResult);
    }

    private function claimPolicy(): array
    {
        return $this->reporting->claimPolicy();
    }
}
