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
            $command = $this->atlasDevCommand($worktree, $this->intent($finding), $allowedFiles, $this->stringList($input['validation_commands'] ?? []));
            $receiptExtra = [
                'provider_execution_authorized' => true,
                'budget_approved' => true,
                'provider_choice' => 'cursor_cli',
                'model_family' => 'composer-2.5-fast',
            ];
        }
        $runner = $this->runner->project([
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
            'blockers' => $forgePlanned
                ? ['forge_runtime_dispatch_planned_only']
                : ($completed ? [] : ['owner_runtime_result_not_completed']),
            'claim_policy' => $this->claimPolicy(),
            'generated_at' => gmdate('c'),
        ];
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
        $status = (string) ($ownerResult['result_status'] ?? $ownerResult['status'] ?? 'partial');

        return [
            'schema_version' => 'atlas.software_company_stewardship.ap786_owner_flow_execution_result.v1',
            'execution_id' => (string) ($ownerResult['result_id'] ?? ''),
            'owner' => $owner,
            'result_status' => $status,
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
     * @param  array<string,mixed>  $finding
     */
    private function intent(array $finding): string
    {
        $title = trim((string) ($finding['title'] ?? ''));
        $detail = trim((string) ($finding['detail'] ?? $finding['why_it_matters'] ?? ''));
        $nextAction = trim((string) ($finding['proposed_next_action'] ?? ''));
        $allowedFiles = $this->stringList($finding['affected_files'] ?? []);
        $tests = $this->stringList(data_get($finding, 'spec_seed.tests_required', []));
        $acceptance = $this->stringList(data_get($finding, 'spec_seed.acceptance', []));

        $intent = implode(' ', array_filter([
            'Edit the allowed files now and return a concrete unified diff.',
            $nextAction !== '' ? $nextAction : null,
            $title !== '' ? 'Target: '.$title.'.' : null,
            $detail !== '' ? 'Why: '.$detail : null,
            $allowedFiles !== [] ? 'Change only: '.implode(', ', $allowedFiles).'.' : null,
            $tests !== [] ? 'Prove with: '.implode(', ', $tests).'.' : null,
            $acceptance !== [] ? 'Acceptance: '.implode(' ', array_slice($acceptance, 0, 2)) : null,
            'Do not return no_patch_needed unless the target runtime and focused test already prove this exact improvement.',
        ], static fn (?string $line): bool => is_string($line) && trim($line) !== ''));

        if ($intent === '') {
            $intent = 'Implement the smallest correct fix inside the allowed files only.';
        }

        // Keep the intent a single safe CLI argument (AP-759 rejects shell metacharacters).
        $intent = (string) preg_replace('/[;&|<>`$\r\n]+/', ' ', $intent);
        $intent = trim((string) preg_replace('/\s+/', ' ', $intent));

        return $intent === '' ? 'Implement the smallest correct fix inside the allowed files only.' : mb_substr($intent, 0, 2400);
    }

    private function artisanPath(): string
    {
        return function_exists('base_path') ? base_path('artisan') : 'artisan';
    }

    /**
     * @param  list<string>  $allowedFiles
     * @param  list<string>  $validationCommands
     * @return list<string>
     */
    private function atlasDevCommand(string $worktree, string $intent, array $allowedFiles, array $validationCommands): array
    {
        $command = [
            PHP_BINARY,
            $this->artisanPath(),
            'atlas:dev:senior-loop:run',
            '--workspace='.$worktree,
            '--intent='.$intent,
            '--surface-id=atlas_cli_dev',
            '--provider-choice=cursor_cli',
            '--composer-model=composer-2.5-fast',
            '--json',
        ];

        foreach ($allowedFiles as $file) {
            $file = $this->safeCliValue($file);
            if ($file !== '') {
                $command[] = '--allowed-file='.$file;
            }
        }

        foreach ($validationCommands as $validationCommand) {
            $validationCommand = $this->safeCliValue($validationCommand);
            if ($validationCommand !== '') {
                $command[] = '--validation-command='.$validationCommand;
            }
        }

        return $command;
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
