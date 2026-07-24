<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution;

use App\Services\Ai\AtlasForge\AtlasForgeParallelDurableCoordinatorService;
use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\Programming\AtlasDevRuntimeService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusOwnerQueueConsumptionGateService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\OwnerFlow\OwnerRuntimeExecutionAdapter;
use App\Services\Ai\SoftwareCompanyStewardship\Concerns\HasStewardshipStorageRoot;
use App\Services\Ai\SoftwareCompanyStewardship\StewardshipStringListNormalizer;
use App\Services\Ai\Support\AppendOnlyJsonlStore;
use Throwable;

/**
 * AP-758 · governed owner runtime execution adapter.
 *
 * Bridges the AP-749 "ready for owner consumption" packet into the existing
 * Atlas Dev / Forge owners and emits an AP-750-compatible owner result receipt.
 * This adapter is deliberately narrow: it never creates a new runtime, never
 * invokes providers directly, never merges/deploys/pushes and never accesses
 * secrets. Provider/full mutation authority remains inside the existing owners.
 */
final class StewardshipOwnerRuntimeExecutionAdapterService implements OwnerRuntimeExecutionAdapter
{
    use StewardshipEvolutionClock;

    public const REPORT_SCHEMA = 'atlas.software_company_stewardship.owner_runtime_execution_adapter.v1';

    public const RECORD_SCHEMA = 'atlas.software_company_stewardship.owner_runtime_execution_record.v1';

    public const INVOCATION_SCHEMA = 'atlas.software_company_stewardship.ap758_owner_runtime_invocation.v1';

    public const STATUS_READY = 'ready_for_ap750_result_bridge';

    public const STATUS_RECORDED = 'owner_runtime_execution_recorded';

    public const STATUS_BLOCKED = 'blocked';

    use HasStewardshipStorageRoot;

    private const STORAGE_SUBPATH = 'atlas/software_company_stewardship/owner_runtime_executions';

    public function __construct(
        private readonly AtlasDevRuntimeService $atlasDevRuntime,
        private readonly AtlasForgeParallelDurableCoordinatorService $forgeCoordinator,
    ) {}

    public function executionFilePath(string $areaId): string
    {
        return $this->storageDir().DIRECTORY_SEPARATOR.$this->slug($areaId).'.jsonl';
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function project(array $input): array
    {
        $consumption = $this->consumption($input);
        if ($consumption === []) {
            return $this->blocked('agentic_engineering_os', 'ap749_consumption_required', 'AP-758 requires an AP-749 owner queue consumption report or record.');
        }

        $areaId = trim((string) ($consumption['area_id'] ?? $input['area_id'] ?? 'agentic_engineering_os')) ?: 'agentic_engineering_os';
        if (! $this->isAp749Consumption($consumption)) {
            return $this->blocked($areaId, 'ap749_consumption_required', 'AP-758 can execute only AP-749 owner queue consumption reports or records.', $consumption);
        }

        if (! in_array((string) ($consumption['status'] ?? ''), [
            AreaFocusOwnerQueueConsumptionGateService::STATUS_READY,
            AreaFocusOwnerQueueConsumptionGateService::STATUS_RECORDED,
        ], true)) {
            return $this->blocked($areaId, 'ap749_consumption_not_ready', 'AP-749 must be ready or recorded before AP-758 can invoke owner runtime projection.', $consumption);
        }

        if ((bool) ($input['kill_switch'] ?? false)) {
            return $this->blocked($areaId, 'kill_switch_active', 'Product Mode kill switch is active; AP-758 owner runtime execution is blocked.', $consumption);
        }

        if ((bool) data_get($consumption, 'claim_policy.owner_runtime_start_authorized', false) !== true) {
            return $this->blocked($areaId, 'owner_runtime_start_not_authorized', 'AP-749 claim policy must authorize owner runtime start before AP-758.', $consumption);
        }

        $sandboxCheck = $this->sandboxCheck($consumption);
        if ($sandboxCheck['ok'] !== true) {
            return $this->blocked($areaId, 'ap757_sandbox_binding_required', 'AP-758 requires a matching AP-757 branch sandbox binding with an existing worktree.', $consumption, [
                'sandbox_check' => $sandboxCheck,
            ]);
        }

        $receipt = is_array($input['runtime_start_receipt'] ?? null)
            ? $input['runtime_start_receipt']
            : (is_array($input['start_receipt'] ?? null) ? $input['start_receipt'] : []);
        $receiptCheck = $this->runtimeStartReceiptCheck($receipt, $consumption);
        if ($receiptCheck['ok'] !== true) {
            return $this->blocked($areaId, 'runtime_start_receipt_required', 'AP-758 requires an explicit operator runtime start receipt for the AP-749 consumption.', $consumption, [
                'runtime_start_receipt_check' => $receiptCheck,
            ]);
        }

        $ownerInput = is_array($consumption['owner_runtime_input'] ?? null) ? $consumption['owner_runtime_input'] : [];
        $targetOwner = (string) ($consumption['target_owner'] ?? $ownerInput['target_owner'] ?? '');
        if (! in_array($targetOwner, ['atlas_dev', 'forge'], true)) {
            return $this->blocked($areaId, 'unsupported_target_owner', 'AP-758 supports only atlas_dev and forge owner runtimes.', $consumption, [
                'target_owner' => $targetOwner,
            ]);
        }

        $runtimeInvocation = $targetOwner === 'forge'
            ? $this->forgeInvocation($consumption, $ownerInput, $input)
            : $this->atlasDevInvocation($consumption, $ownerInput);

        $ownerResult = $this->ownerResult($consumption, $runtimeInvocation);
        $recordExecution = (bool) ($input['record_execution'] ?? false);
        $payload = [
            'schema_version' => self::REPORT_SCHEMA,
            'ap_contract' => 'AP-758',
            'status' => self::STATUS_READY,
            'mode' => 'owner_runtime_execution_adapter',
            'area_id' => $areaId,
            'portfolio_id' => (string) ($input['portfolio_id'] ?? $consumption['portfolio_id'] ?? 'atlas_software_company'),
            'stack' => 'Atlas Software Company Stewardship Stack',
            'source_ap_contracts' => ['AP-747', 'AP-748', 'AP-749', 'AP-756', 'AP-757', 'AP-758', 'AP-750'],
            'consumption_id' => $this->consumptionId($consumption),
            'release_id' => (string) ($consumption['release_id'] ?? ''),
            'queue_item_id' => (string) ($consumption['queue_item_id'] ?? ''),
            'target_owner' => $targetOwner,
            'target_runtime_schema' => (string) ($consumption['target_runtime_schema'] ?? data_get($ownerInput, 'target_runtime_schema', '')),
            'sandbox_check' => $sandboxCheck,
            'runtime_start_receipt_check' => $receiptCheck,
            'runtime_invocation' => $runtimeInvocation,
            'owner_result' => $ownerResult,
            'ap750_bridge_input' => [
                'schema_version' => 'atlas.software_company_stewardship.ap758_ap750_bridge_input.v1',
                'consumption_report' => $this->consumptionId($consumption),
                'owner_result_id' => (string) ($ownerResult['result_id'] ?? ''),
                'command' => 'php artisan atlas:software-company-stewardship owner-runtime-result-bridge --consumption-file=<ap749.jsonl> --result-file=<ap758-owner-result.json> --record-result',
            ],
            'record_execution_requested' => $recordExecution,
            'next_actions' => $this->nextActions($ownerResult),
            'reused_owners' => $this->reusedOwners(),
            'claim_policy' => $this->claimPolicy($recordExecution),
        ];
        $payload['owner_execution_id'] = $this->ownerExecutionId($payload);
        $payload['owner_execution_hash'] = 'sha256:'.MissionCanonicalHash::sha256($this->identity($payload));
        $payload['generated_at'] = $this->now();

        return $this->maybeRecord($areaId, $payload, $recordExecution);
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function consumption(array $input): array
    {
        foreach (['consumption_report', 'consumption_record', 'owner_queue_consumption'] as $key) {
            if (is_array($input[$key] ?? null)) {
                return $input[$key];
            }
        }

        foreach (['consumption_reports', 'consumption_records'] as $key) {
            foreach ((array) ($input[$key] ?? []) as $item) {
                if (is_array($item)) {
                    return $item;
                }
            }
        }

        return [];
    }

    /**
     * @param  array<string,mixed>  $consumption
     */
    private function isAp749Consumption(array $consumption): bool
    {
        return (string) ($consumption['ap_contract'] ?? '') === 'AP-749'
            || in_array((string) ($consumption['schema_version'] ?? ''), [
                AreaFocusOwnerQueueConsumptionGateService::REPORT_SCHEMA,
                AreaFocusOwnerQueueConsumptionGateService::RECORD_SCHEMA,
            ], true);
    }

    /**
     * @param  array<string,mixed>  $consumption
     * @return array<string,mixed>
     */
    private function sandboxCheck(array $consumption): array
    {
        $binding = is_array($consumption['sandbox_binding'] ?? null) ? $consumption['sandbox_binding'] : [];
        $runtimeSandbox = is_array(data_get($consumption, 'owner_runtime_input.branch_sandbox'))
            ? data_get($consumption, 'owner_runtime_input.branch_sandbox')
            : [];
        $worktreePath = (string) ($binding['worktree_path'] ?? $runtimeSandbox['worktree_path'] ?? '');
        $violations = [];

        if ((string) ($binding['schema_version'] ?? '') !== 'atlas.software_company_stewardship.ap757_sandbox_binding_check.v1') {
            $violations[] = 'ap757_binding_schema_required';
        }
        if ((bool) ($binding['ok'] ?? false) !== true) {
            $violations[] = 'ap757_binding_not_ok';
        }
        if ((bool) ($runtimeSandbox['binding_ok'] ?? false) !== true) {
            $violations[] = 'owner_runtime_sandbox_binding_not_ok';
        }
        if ((string) ($binding['sandbox_id'] ?? '') === '') {
            $violations[] = 'sandbox_id_required';
        }
        if ((string) ($binding['branch_name'] ?? '') === '') {
            $violations[] = 'branch_name_required';
        }
        if ($worktreePath === '') {
            $violations[] = 'worktree_path_required';
        }
        if ($worktreePath !== '' && ! is_dir($worktreePath)) {
            $violations[] = 'worktree_path_not_found';
        }
        if ($worktreePath !== '' && (string) ($binding['worktree_path_hash'] ?? '') !== hash('sha256', $worktreePath)) {
            $violations[] = 'worktree_path_hash_mismatch';
        }

        return [
            'schema_version' => 'atlas.software_company_stewardship.ap758_sandbox_check.v1',
            'ok' => $violations === [],
            'sandbox_id' => (string) ($binding['sandbox_id'] ?? ''),
            'branch_name' => (string) ($binding['branch_name'] ?? ''),
            'worktree_path' => $worktreePath,
            'worktree_path_hash' => $worktreePath !== '' ? hash('sha256', $worktreePath) : '',
            'violations' => $violations,
        ];
    }

    /**
     * @param  array<string,mixed>  $receipt
     * @param  array<string,mixed>  $consumption
     * @return array<string,mixed>
     */
    private function runtimeStartReceiptCheck(array $receipt, array $consumption): array
    {
        $missing = [];
        $decision = (string) ($receipt['decision'] ?? '');
        if (! in_array($decision, ['start_owner_runtime', 'execute_owner_runtime', 'invoke_owner_runtime'], true)) {
            $missing[] = 'runtime_start_decision_required';
        }
        if (trim((string) ($receipt['operator_actor'] ?? '')) === '') {
            $missing[] = 'operator_actor_required';
        }

        $expectedConsumption = $this->consumptionId($consumption);
        $targetConsumption = trim((string) ($receipt['target_consumption_id'] ?? $receipt['consumption_id'] ?? ''));
        if ($targetConsumption !== '' && $targetConsumption !== $expectedConsumption) {
            $missing[] = 'target_consumption_id_mismatch';
        }
        $targetRelease = trim((string) ($receipt['target_release_id'] ?? $receipt['release_id'] ?? ''));
        if ($targetRelease !== '' && $targetRelease !== (string) ($consumption['release_id'] ?? '')) {
            $missing[] = 'target_release_id_mismatch';
        }
        $targetQueue = trim((string) ($receipt['target_queue_item_id'] ?? $receipt['queue_item_id'] ?? ''));
        if ($targetQueue !== '' && $targetQueue !== (string) ($consumption['queue_item_id'] ?? '')) {
            $missing[] = 'target_queue_item_id_mismatch';
        }

        return [
            'schema_version' => 'atlas.software_company_stewardship.ap758_runtime_start_receipt_check.v1',
            'ok' => $missing === [],
            'decision' => $decision,
            'operator_actor' => (string) ($receipt['operator_actor'] ?? ''),
            'target_consumption_id' => $targetConsumption,
            'missing' => $missing,
        ];
    }

    /**
     * @param  array<string,mixed>  $consumption
     * @param  array<string,mixed>  $ownerInput
     * @return array<string,mixed>
     */
    private function atlasDevInvocation(array $consumption, array $ownerInput): array
    {
        $payload = is_array($ownerInput['dev_runtime_payload'] ?? null) ? $ownerInput['dev_runtime_payload'] : [];
        $sandbox = is_array($ownerInput['branch_sandbox'] ?? null) ? $ownerInput['branch_sandbox'] : [];
        $workspace = (string) ($payload['workspace'] ?? 'atlas-server');
        $artifactPacket = is_array($payload['artifact_agent_packet'] ?? null) ? $payload['artifact_agent_packet'] : [];
        $artifactPacket['schema_version'] = 'atlas.workspace_artifact_agent_packet.v1';
        $artifactPacket['workspace_id'] = (string) ($artifactPacket['workspace_id'] ?? $workspace);
        $artifactPacket['consumer'] = (string) ($artifactPacket['consumer'] ?? 'atlas_dev');
        $artifactPacket['route_target'] = (string) ($artifactPacket['route_target'] ?? 'dev');
        $artifactPacket['raw_conversation_included'] = false;
        $artifactPacket['artifact_body_included'] = false;
        $artifactPacket['branch_sandbox'] = $sandbox;

        $payload['atlas_mode'] = 'programming';
        $payload['workspace'] = $workspace;
        $payload['routing_task'] = (string) ($payload['routing_task'] ?? 'dev');
        $payload['artifact_agent_packet'] = $artifactPacket;
        $payload['input_text'] = (string) ($payload['input_text'] ?? 'Execute AP-749 owner runtime input under AP-756 branch sandbox.');

        try {
            $projection = $this->atlasDevRuntime->apply(['payload' => $payload]);
            $status = 'projected';
            $blockers = [];
        } catch (Throwable $e) {
            $projection = ['error' => $e->getMessage()];
            $status = 'blocked';
            $blockers = ['atlas_dev_runtime_projection_failed'];
        }

        $runtimeSlice = is_array(data_get($projection, 'payload.atlas_dev_runtime')) ? data_get($projection, 'payload.atlas_dev_runtime') : [];

        return [
            'schema_version' => self::INVOCATION_SCHEMA,
            'ap_contract' => 'AP-758',
            'target_owner' => 'atlas_dev',
            'driver_mode' => 'atlas_dev_runtime_projection',
            'status' => $status,
            'runtime_schema' => AtlasDevRuntimeService::SCHEMA_VERSION,
            'runtime_service' => AtlasDevRuntimeService::class,
            'runtime_projection_ready' => $runtimeSlice !== [],
            'provider_execution_allowed_by_owner_projection' => (bool) data_get($runtimeSlice, 'provider_execution_allowed', false),
            'provider_invoked_by_adapter' => false,
            'target_repo_mutated_by_adapter' => false,
            'branch_sandbox' => $sandbox,
            'runtime_projection_hash' => 'sha256:'.MissionCanonicalHash::sha256($projection),
            'blockers' => $blockers,
            'summary' => $runtimeSlice !== []
                ? 'Atlas Dev runtime projection accepted the AP-749 owner input; AP-758 did not invoke provider or mutate repo.'
                : 'Atlas Dev runtime projection did not produce a runtime slice.',
            'consumption_id' => $this->consumptionId($consumption),
        ];
    }

    /**
     * @param  array<string,mixed>  $consumption
     * @param  array<string,mixed>  $ownerInput
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function forgeInvocation(array $consumption, array $ownerInput, array $input): array
    {
        $ticket = is_array($ownerInput['forge_ticket'] ?? null) ? $ownerInput['forge_ticket'] : [];
        $sandbox = is_array($ownerInput['branch_sandbox'] ?? null) ? $ownerInput['branch_sandbox'] : [];
        $ticketId = (string) ($ticket['ticket_id'] ?? $consumption['queue_item_id'] ?? 'forge_owner_ticket');
        $lockedPaths = StewardshipStringListNormalizer::trimmedUniqueStrings($ticket['locked_paths'] ?? data_get($consumption, 'branch_isolation.allowed_paths', []));
        $agents = $this->agents($input);
        $existingReservations = $this->existingReservations($input);
        $proposal = $this->forgeCoordinator->propose([[
            'ticket_id' => $ticketId,
            'locked_paths' => $lockedPaths,
            'priority' => (int) ($ticket['priority'] ?? 50),
        ]], $agents, $existingReservations);

        return [
            'schema_version' => self::INVOCATION_SCHEMA,
            'ap_contract' => 'AP-758',
            'target_owner' => 'forge',
            'driver_mode' => 'forge_parallel_durable_projection',
            'status' => ((int) data_get($proposal, 'counts.assignments', 0)) > 0 ? 'projected' : 'blocked',
            'runtime_schema' => AtlasForgeParallelDurableCoordinatorService::SCHEMA_VERSION,
            'runtime_service' => AtlasForgeParallelDurableCoordinatorService::class,
            'forge_parallel_durable_proposal' => $proposal,
            'provider_invoked_by_adapter' => false,
            'target_repo_mutated_by_adapter' => false,
            'branch_sandbox' => $sandbox,
            'runtime_projection_hash' => (string) ($proposal['proposal_hash'] ?? 'sha256:'.MissionCanonicalHash::sha256($proposal)),
            'blockers' => ((int) data_get($proposal, 'counts.assignments', 0)) > 0 ? [] : ['forge_assignment_unavailable'],
            'summary' => 'Forge parallel durable owner projection prepared assignment boundaries; AP-758 did not invoke provider or mutate repo.',
            'consumption_id' => $this->consumptionId($consumption),
        ];
    }

    /**
     * @param  array<string,mixed>  $input
     * @return list<array{agent_id:string,available?:bool}>
     */
    private function agents(array $input): array
    {
        $agents = [];
        foreach ((array) ($input['agents'] ?? []) as $agent) {
            if (is_array($agent) && (string) ($agent['agent_id'] ?? '') !== '') {
                $agents[] = [
                    'agent_id' => (string) $agent['agent_id'],
                    'available' => (bool) ($agent['available'] ?? true),
                ];
            }
        }

        return $agents !== [] ? $agents : [['agent_id' => 'stewardship_forge_agent', 'available' => true]];
    }

    /**
     * @param  array<string,mixed>  $input
     * @return list<array{agent_id:string,ticket_id:string,locked_paths:list<string>}>
     */
    private function existingReservations(array $input): array
    {
        $reservations = [];
        foreach ((array) ($input['existing_reservations'] ?? []) as $reservation) {
            if (! is_array($reservation)) {
                continue;
            }
            $agentId = (string) ($reservation['agent_id'] ?? '');
            $ticketId = (string) ($reservation['ticket_id'] ?? '');
            if ($agentId === '' || $ticketId === '') {
                continue;
            }
            $reservations[] = [
                'agent_id' => $agentId,
                'ticket_id' => $ticketId,
                'locked_paths' => StewardshipStringListNormalizer::trimmedUniqueStrings($reservation['locked_paths'] ?? []),
            ];
        }

        return $reservations;
    }

    /**
     * @param  array<string,mixed>  $consumption
     * @param  array<string,mixed>  $runtimeInvocation
     * @return array<string,mixed>
     */
    private function ownerResult(array $consumption, array $runtimeInvocation): array
    {
        $status = ($runtimeInvocation['status'] ?? '') === 'projected' ? 'partial' : 'blocked';
        $summary = (string) ($runtimeInvocation['summary'] ?? 'Owner runtime projection produced an AP-758 result.');
        $evidencePayload = [
            'AP-758',
            $this->consumptionId($consumption),
            (string) ($runtimeInvocation['runtime_projection_hash'] ?? ''),
            $status,
        ];
        $evidenceHash = 'sha256:'.MissionCanonicalHash::sha256($evidencePayload);

        return [
            'schema_version' => StewardshipOwnerRuntimeResultBridgeService::RESULT_SCHEMA,
            'result_id' => 'afexecres_'.substr(MissionCanonicalHash::sha256($evidencePayload), 0, 18),
            'source_ap_contract' => 'AP-758',
            'consumption_id' => $this->consumptionId($consumption),
            'release_id' => (string) ($consumption['release_id'] ?? ''),
            'queue_item_id' => (string) ($consumption['queue_item_id'] ?? ''),
            'target_owner' => (string) ($consumption['target_owner'] ?? ''),
            'result_status' => $status,
            'summary' => $summary,
            'changed_files' => [],
            'tests' => [],
            'runtime_execution_started' => true,
            'provider_invoked' => false,
            'branch_created' => false,
            'merge_performed' => false,
            'deploy_performed' => false,
            'external_push_performed' => false,
            'secret_access' => false,
            'destructive_change' => false,
            'branch_sandbox' => is_array($runtimeInvocation['branch_sandbox'] ?? null) ? $runtimeInvocation['branch_sandbox'] : [],
            'runtime_invocation' => $runtimeInvocation,
            'evidence_pack' => [
                'schema_version' => 'atlas.software_company_stewardship.ap758_owner_runtime_evidence_pack.v1',
                'evidence_hash' => $evidenceHash,
                'summary' => $summary,
                'changed_files' => [],
                'tests' => [],
                'runtime_projection_hash' => (string) ($runtimeInvocation['runtime_projection_hash'] ?? ''),
                'provider_invoked' => false,
                'target_repo_mutated' => false,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function maybeRecord(string $areaId, array $payload, bool $record): array
    {
        if (! $record) {
            return $payload + ['execution_storage_status' => 'projected'];
        }

        if (($payload['status'] ?? '') !== self::STATUS_READY) {
            return $payload + ['execution_storage_status' => 'not_recorded_until_ready'];
        }

        $path = $this->executionFilePath($areaId);
        $existing = $this->findRecord($path, (string) ($payload['owner_execution_id'] ?? ''));
        if ($existing !== null) {
            return $existing + ['execution_storage_status' => 'existing'];
        }

        $recordPayload = [
            'schema_version' => self::RECORD_SCHEMA,
            'recorded_at' => $this->now(),
        ] + $payload;
        $recordPayload['status'] = self::STATUS_RECORDED;
        AppendOnlyJsonlStore::appendUsingFilePutContents(
            $path,
            $recordPayload,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            0o755,
        );

        return $recordPayload + ['execution_storage_status' => 'recorded'];
    }

    private function findRecord(string $path, string $ownerExecutionId): ?array
    {
        if ($ownerExecutionId === '' || ! is_file($path)) {
            return null;
        }

        $fh = fopen($path, 'rb');
        if ($fh === false) {
            return null;
        }
        try {
            while (($line = fgets($fh)) !== false) {
                $line = rtrim($line, "\r\n");
                if ($line === '') {
                    continue;
                }
                $decoded = json_decode($line, true);
                if (is_array($decoded) && (string) ($decoded['owner_execution_id'] ?? '') === $ownerExecutionId) {
                    return $decoded;
                }
            }
        } finally {
            fclose($fh);
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $source
     * @param  array<string,mixed>  $extra
     * @return array<string,mixed>
     */
    private function blocked(string $areaId, string $reason, string $detail, array $source = [], array $extra = []): array
    {
        $payload = [
            'schema_version' => self::REPORT_SCHEMA,
            'ap_contract' => 'AP-758',
            'status' => self::STATUS_BLOCKED,
            'mode' => 'owner_runtime_execution_adapter',
            'area_id' => $areaId,
            'consumption_id' => $this->consumptionId($source),
            'release_id' => (string) ($source['release_id'] ?? ''),
            'queue_item_id' => (string) ($source['queue_item_id'] ?? ''),
            'target_owner' => (string) ($source['target_owner'] ?? ''),
            'reason' => $reason,
            'detail' => $detail,
            'source_ap_contracts' => ['AP-747', 'AP-748', 'AP-749', 'AP-756', 'AP-757', 'AP-758', 'AP-750'],
            'blockers' => [$reason],
            'next_actions' => ['Resolve AP-758 blocker before creating an AP-750 owner runtime result bridge.'],
            'reused_owners' => $this->reusedOwners(),
            'claim_policy' => $this->claimPolicy(false),
        ] + $extra;
        $payload['owner_execution_hash'] = 'sha256:'.MissionCanonicalHash::sha256($payload);
        $payload['generated_at'] = $this->now();

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function ownerExecutionId(array $payload): string
    {
        return 'afexec_'.substr(MissionCanonicalHash::sha256([
            'AP-758',
            (string) ($payload['consumption_id'] ?? ''),
            (string) ($payload['release_id'] ?? ''),
            (string) ($payload['queue_item_id'] ?? ''),
            (string) ($payload['target_owner'] ?? ''),
        ]), 0, 18);
    }

    private function consumptionId(array $consumption): string
    {
        return (string) ($consumption['consumption_id'] ?? '');
    }

    /**
     * @return list<string>
     */
    private function nextActions(array $ownerResult): array
    {
        if ((string) ($ownerResult['result_status'] ?? '') === 'blocked') {
            return [
                'Review AP-758 runtime invocation blockers before AP-750.',
                'Do not retry outside Atlas Dev/Forge owner boundaries.',
            ];
        }

        return [
            'Feed AP-758 owner_result into AP-750 owner-runtime-result-bridge.',
            'Review AP-750 Evidence and Morning Inbox before any merge, deploy or external push.',
        ];
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function reusedOwners(): array
    {
        return [
            'owner_consumption_gate' => ['ap' => 'AP-749', 'owner_service' => AreaFocusOwnerQueueConsumptionGateService::class],
            'atlas_dev' => ['runtime_schema' => AtlasDevRuntimeService::SCHEMA_VERSION, 'owner_service' => AtlasDevRuntimeService::class],
            'forge' => ['runtime_schema' => AtlasForgeParallelDurableCoordinatorService::SCHEMA_VERSION, 'owner_service' => AtlasForgeParallelDurableCoordinatorService::class],
            'owner_runtime_result_bridge' => ['ap' => 'AP-750', 'owner_service' => StewardshipOwnerRuntimeResultBridgeService::class],
        ];
    }

    /**
     * @return array<string,bool|string>
     */
    private function claimPolicy(bool $recorded): array
    {
        return [
            'mode' => 'owner_runtime_execution_adapter',
            'requires_ap749_consumption' => true,
            'requires_ap757_sandbox_binding' => true,
            'requires_operator_runtime_start_receipt' => true,
            'reuses_existing_owner_runtime' => true,
            'execution_recorded_when_requested' => $recorded,
            'provider_invoked_by_adapter' => false,
            'target_repo_mutated_by_adapter' => false,
            'branch_created_by_adapter' => false,
            'worktree_created_by_adapter' => false,
            'merge_performed_by_adapter' => false,
            'deploy_performed_by_adapter' => false,
            'pushed_external_by_adapter' => false,
            'secret_access_by_adapter' => false,
            'destructive_change_by_adapter' => false,
            'parallel_runtime_created' => false,
            'new_os_created' => false,
            'ap750_required_after_execution' => true,
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function identity(array $payload): array
    {
        $copy = $payload;
        unset($copy['generated_at'], $copy['owner_execution_hash'], $copy['execution_storage_status'], $copy['recorded_at']);

        return $copy;
    }

    private function slug(string $value): string
    {
        $slug = strtolower(preg_replace('/[^a-zA-Z0-9_-]+/', '_', trim($value)) ?: '');

        return trim($slug, '_') ?: 'agentic_engineering_os';
    }
}
