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
 * owner CLI inside the AP-756 worktree (atlas_dev -> `atlas:dev:senior-loop:run`).
 *
 * Chain:
 *   AP-747 release -> AP-748 outcome -> AP-749 consumption gate (binds AP-757
 *   sandbox) -> AP-758 execution adapter -> AP-759 owner sandbox runtime runner
 *   -> AP-750 owner runtime result bridge.
 *
 * Forge does not yet have a real Obra dispatch wired, so forge work blocks
 * honestly with `forge_obra_dispatch_required` instead of pretending to execute.
 */
final class Ap786OwnerFlowExecutor implements Ap786OwnerFlowRunner
{
    public const REPORT_SCHEMA = 'atlas.software_company_stewardship.ap786_owner_flow.v1';

    public const STATUS_COMPLETED = 'owner_flow_completed';

    public const STATUS_RESULT_FAILED = 'owner_flow_result_failed';

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

        // Forge: do not fake an Obra dispatch. Block honestly until a real Forge
        // owner runtime is materializable through AP-759.
        if ($owner === 'forge') {
            return $this->blocked('forge_obra_dispatch_required', $owner, $steps, [
                'detail' => 'AP-786 does not yet materialize a real Forge Obra dispatch. Forge work must route through a real AP-759 forge runtime command (atlas:forge:runtime-dispatch / parallel-durable) with provider authority and budget receipts before it can be claimed as executed; it must never fall back to a direct provider driver.',
            ]);
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
        $command = [PHP_BINARY, 'artisan', 'atlas:dev:senior-loop:run', '--workspace='.$worktree, '--intent='.$this->intent($finding), '--json'];
        $runner = $this->runner->project([
            'area_id' => $areaId,
            'portfolio_id' => $portfolioId,
            'execution_adapter_report' => $adapter,
            'runtime_command_receipt' => [
                'decision' => 'execute_owner_runtime_in_sandbox',
                'operator_actor' => $actor,
                'command' => $command,
                'allow_runtime_command_execution' => true,
                'timeout_seconds' => $timeout,
            ],
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

        // 6. AP-750 — bridge the owner runtime result into Evidence/Inbox/Portfolio.
        $resultBridge = $this->resultBridge->project([
            'area_id' => $areaId,
            'portfolio_id' => $portfolioId,
            'consumption_report' => $consumption,
            'owner_result' => $ownerResult,
            'record_result' => true,
        ]);
        $steps[] = $this->step('AP-750', 'owner_runtime_result_bridge', $resultBridge['status'] ?? '');

        $resultStatus = (string) ($ownerResult['result_status'] ?? $ownerResult['status'] ?? '');
        $bridgeReady = in_array((string) ($resultBridge['status'] ?? ''), [
            StewardshipOwnerRuntimeResultBridgeService::STATUS_READY,
            StewardshipOwnerRuntimeResultBridgeService::STATUS_RECORDED,
        ], true);
        $completed = $resultStatus === 'completed' && $bridgeReady;

        return [
            'schema_version' => self::REPORT_SCHEMA,
            'ap_contract' => 'AP-786',
            'status' => $completed ? self::STATUS_COMPLETED : self::STATUS_RESULT_FAILED,
            'owner' => $owner,
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
            'blockers' => $completed ? [] : ['owner_runtime_result_not_completed'],
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
        $intent = trim((string) ($finding['proposed_next_action'] ?? $finding['title'] ?? 'Implement the smallest correct fix inside the allowed files only.'));
        // Keep the intent a single safe CLI argument (AP-759 rejects shell metacharacters).
        $intent = (string) preg_replace('/[;&|<>`$\r\n]+/', ' ', $intent);
        $intent = trim((string) preg_replace('/\s+/', ' ', $intent));

        return $intent === '' ? 'Implement the smallest correct fix inside the allowed files only.' : mb_substr($intent, 0, 240);
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
