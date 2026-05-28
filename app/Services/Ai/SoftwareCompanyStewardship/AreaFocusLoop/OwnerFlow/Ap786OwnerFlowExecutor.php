<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\OwnerFlow;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusDevForgeReleaseService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusOwnerQueueConsumptionGateService;
use App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution\StewardshipOutcomeEvidenceBridgeService;
use App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution\StewardshipOwnerRuntimeExecutionAdapterService;
use App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution\StewardshipOwnerRuntimeResultBridgeService;
use App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution\StewardshipOwnerSandboxRuntimeRunnerService;

/**
 * AP-786 full owner-runtime flow executor.
 *
 * Composes the REAL Atlas owner-flow chain so AP-786 stops faking Forge/Dev via
 * a direct provider driver. It never calls
 * {@see \App\Services\Ai\Programming\AtlasForgeProviderInvocationDriverRouter}.
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

    public const DEFAULT_AREA_ID = 'agentic_engineering_os';

    public const DEFAULT_PORTFOLIO_ID = 'atlas_software_company';

    public function __construct(
        private readonly OwnerQueueReleaseGate $release,
        private readonly StewardshipOutcomeProjector $outcome,
        private readonly OwnerQueueConsumptionGate $consumption,
        private readonly OwnerRuntimeExecutionAdapter $adapter,
        private readonly OwnerSandboxRuntimeRunner $runner,
        private readonly OwnerRuntimeResultProjector $resultBridge,
        private readonly ForgeOwnerRuntimeDispatchPlanner $forgeDispatch,
    ) {}

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
        $timeout = max(60, min(1800, (int) ($input['timeout_seconds'] ?? 900)));

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

        $repairAttempt = ['attempted' => false, 'retried' => false];
        if ($this->shouldRetryAtlasDevOwnerRuntime($owner, $ownerResult, $allowedFiles)) {
            $repairCommand = $this->repairCommand($command, $ownerResult);
            $repairRunner = $this->runOwnerRuntimeCommand($areaId, $portfolioId, $adapter, $repairCommand, $actor, $timeout, $execute, $receiptExtra + [
                'repair_attempt' => true,
                'repair_reason' => 'senior_loop_execution_not_passed',
            ]);
            $steps[] = $this->step('AP-759', 'owner_sandbox_runtime_repair_run', $repairRunner['status'] ?? '');
            $repairResult = is_array($repairRunner['owner_result'] ?? null) ? $repairRunner['owner_result'] : [];
            $repairAttempt = [
                'attempted' => true,
                'retried' => true,
                'reason' => 'senior_loop_execution_not_passed',
                'first_owner_sandbox_run_id' => (string) ($runner['owner_sandbox_run_id'] ?? ''),
                'repair_owner_sandbox_run_id' => (string) ($repairRunner['owner_sandbox_run_id'] ?? ''),
                'first_result_status' => (string) ($ownerResult['result_status'] ?? $ownerResult['status'] ?? ''),
                'repair_result_status' => (string) ($repairResult['result_status'] ?? $repairResult['status'] ?? ''),
            ];
            if ($repairResult !== []) {
                $runner = $repairRunner;
                $ownerResult = $repairResult;
                $command = $repairCommand;
            }
        }

        $resultStatus = (string) ($ownerResult['result_status'] ?? $ownerResult['status'] ?? '');
        $changedFiles = $this->stringList($ownerResult['changed_files'] ?? data_get($ownerResult, 'evidence_pack.changed_files', []));

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

        $blockerReport = $this->ownerRuntimeBlockerReport($ownerResult, $forgePlanned, $completed);
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
            'execution_result' => $this->executionResult($ownerResult, $consumption, $finding, $worktree, $owner, $command),
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
     * @return list<string>
     */
    private function repairCommand(array $command, array $ownerResult): array
    {
        $reason = 'Previous AP-759 senior-loop attempt edited allowed files but failed focused verification. Repair the failing test output only; keep the existing diff scoped and rerun the same validation command.';
        $failure = (string) data_get($ownerResult, 'runtime_invocation.command_result.owner_cli_completion_state', '');
        if ($failure !== '') {
            $reason .= ' Previous completion_state='.$this->safeCliValue($failure).'.';
        }

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
     * Build an AP-765-compatible execution_result from the AP-759 owner_result
     * so AP-786 can emit Product Mode / Inbox evidence before any merge attempt.
     *
     * @param  array<string,mixed>  $ownerResult
     * @param  array<string,mixed>  $consumption
     * @param  array<string,mixed>  $finding
     * @param  list<string>  $command
     * @return array<string,mixed>
     */
    private function executionResult(array $ownerResult, array $consumption, array $finding, string $worktree, string $owner, array $command): array
    {
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
            'provider_invoked' => (bool) ($ownerResult['provider_invoked'] ?? false),
            'merge_performed' => false,
            'deploy_performed' => false,
            'external_push_performed' => false,
            'secret_access' => false,
            'destructive_change' => false,
        ];
    }

    /**
     * @param  array<string,mixed>  $ownerResult
     * @return array{blockers:list<string>,details:list<array<string,string>>}
     */
    private function ownerRuntimeBlockerReport(array $ownerResult, bool $forgePlanned, bool $completed): array
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

        foreach ($this->stringList($commandResult['owner_cli_blockers'] ?? []) as $blocker) {
            $mapped = match ($blocker) {
                'senior_loop_execution_not_passed' => 'owner_runtime_senior_loop_execution_not_passed',
                'routing_not_executable' => 'owner_runtime_routing_not_executable',
                'scope_violation' => 'owner_runtime_scope_violation',
                default => 'owner_runtime_'.$blocker,
            };
            $blockers[] = $mapped;
            $details[] = [
                'blocker' => $mapped,
                'reason' => match ($blocker) {
                    'senior_loop_execution_not_passed' => 'Senior loop did not reach passed scope_guard and verification; apply a minimal patch in allowed_files and rerun the focused worktree validation command.',
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
    private function atlasDevCommand(string $worktree, string $intent, array $allowedFiles, array $validationCommands, string $provider, string $model): array
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
