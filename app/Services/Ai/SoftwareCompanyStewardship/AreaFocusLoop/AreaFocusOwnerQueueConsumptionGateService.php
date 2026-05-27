<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\AtlasForge\AtlasForgeParallelDurableCoordinatorService;
use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\Programming\AtlasDevRuntimeService;
use App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution\StewardshipOutcomeEvidenceBridgeService;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Support\Facades\File;

/**
 * AP-749 · owner-specific Dev/Forge queue consumption gate.
 *
 * Consumes AP-747 queue releases only after AP-748 made them visible in
 * Evidence, Morning Inbox and Portfolio and AP-756 proves a materialized
 * branch/worktree sandbox for the same handoff. It produces an owner runtime
 * input packet for Atlas Dev or Forge, but never invokes providers, creates
 * branches, mutates repos, merges, deploys or touches secrets.
 */
final class AreaFocusOwnerQueueConsumptionGateService
{
    public const REPORT_SCHEMA = 'atlas.software_company_stewardship.owner_queue_consumption_gate.v1';

    public const RECORD_SCHEMA = 'atlas.software_company_stewardship.owner_queue_consumption_record.v1';

    public const STATUS_REVIEW_REQUIRED = 'operator_review_required';

    public const STATUS_READY = 'ready_for_owner_consumption';

    public const STATUS_RECORDED = 'owner_consumption_recorded';

    public const STATUS_BLOCKED = 'blocked';

    private ?string $storageRootOverride = null;

    public function setStorageRootForTesting(?string $dir): void
    {
        $this->storageRootOverride = $dir;
    }

    public function storageDir(): string
    {
        if ($this->storageRootOverride !== null) {
            return $this->storageRootOverride;
        }

        return function_exists('storage_path')
            ? storage_path('atlas/software_company_stewardship/area_focus_owner_queue_consumptions')
            : sys_get_temp_dir().'/atlas/software_company_stewardship/area_focus_owner_queue_consumptions';
    }

    public function consumptionFilePath(string $areaId): string
    {
        return $this->storageDir().DIRECTORY_SEPARATOR.$this->slug($areaId).'.jsonl';
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function project(array $input): array
    {
        $release = $this->releaseReport($input);
        if ($release === []) {
            return $this->blocked('agentic_engineering_os', 'release_report_required', 'AP-749 requires one AP-747 release report or record.');
        }

        $areaId = trim((string) ($release['area_id'] ?? $input['area_id'] ?? AreaFocusDevForgeReleaseService::DEFAULT_AREA_ID)) ?: AreaFocusDevForgeReleaseService::DEFAULT_AREA_ID;
        $releaseId = $this->releaseId($release);
        $queueItem = is_array($release['queue_item'] ?? null) ? $release['queue_item'] : [];
        $queueItemId = (string) ($queueItem['queue_item_id'] ?? '');
        $targetOwner = (string) ($queueItem['target_owner'] ?? $release['target_owner'] ?? '');

        if ((bool) ($input['kill_switch'] ?? false)) {
            return $this->blocked($areaId, 'kill_switch_active', 'Product Mode kill switch is active; AP-749 consumption is blocked.', $release);
        }

        if (! $this->isAp747Release($release)) {
            return $this->blocked($areaId, 'ap747_release_required', 'AP-749 can consume only AP-747 release reports or records.', $release);
        }

        if ((string) ($release['status'] ?? '') === AreaFocusDevForgeReleaseService::STATUS_BLOCKED) {
            return $this->blocked($areaId, 'ap747_release_blocked', 'Blocked AP-747 releases cannot be consumed by Dev/Forge.', $release);
        }

        if ($queueItem === [] || $queueItemId === '') {
            return $this->blocked($areaId, 'queue_item_required', 'AP-749 requires the AP-747 queue_item with a queue_item_id.', $release);
        }

        if (! in_array($targetOwner, ['atlas_dev', 'forge'], true)) {
            return $this->blocked($areaId, 'unsupported_target_owner', 'AP-749 supports only atlas_dev or forge owner queues.', $release, [
                'target_owner' => $targetOwner,
            ]);
        }

        $outcome = $this->outcomeBridge($input);
        if ($outcome === []) {
            return $this->blocked($areaId, 'ap748_outcome_bridge_required', 'AP-749 requires an AP-748/AP-740 outcome bridge report before owner consumption.', $release);
        }

        $outcomeCheck = $this->outcomeCheck($release, $outcome);
        if ($outcomeCheck['ok'] !== true) {
            return $this->blocked($areaId, 'ap748_outcome_bridge_incomplete', 'AP-748 must prove Evidence, Morning Inbox and Portfolio visibility before owner consumption.', $release, [
                'outcome_check' => $outcomeCheck,
            ]);
        }

        $isolationCheck = $this->branchIsolationCheck($queueItem);
        if ($isolationCheck['ok'] !== true) {
            return $this->blocked($areaId, 'branch_isolation_incomplete', 'AP-749 requires branch/path isolation before owner consumption.', $release, [
                'branch_isolation' => $isolationCheck,
            ]);
        }

        $sandboxBinding = $this->sandboxBindingCheck($release, $queueItem, $input);
        $recordConsumption = (bool) ($input['record_consumption'] ?? false);
        $receipt = is_array($input['execution_receipt'] ?? null)
            ? $input['execution_receipt']
            : (is_array($input['operator_execution_receipt'] ?? null) ? $input['operator_execution_receipt'] : []);

        $receiptCheck = $this->executionReceiptCheck($receipt, $releaseId, $queueItemId);
        if ($receiptCheck === null && $sandboxBinding['ok'] !== true) {
            return $this->blocked($areaId, 'ap756_materialized_sandbox_required', 'AP-757 requires an AP-756 materialized branch sandbox record before AP-749 can authorize owner runtime consumption.', $release, [
                'sandbox_binding' => $sandboxBinding,
            ]);
        }
        $status = $receiptCheck === null ? self::STATUS_READY : self::STATUS_REVIEW_REQUIRED;

        $payload = [
            'schema_version' => self::REPORT_SCHEMA,
            'ap_contract' => 'AP-749',
            'status' => $status,
            'mode' => 'owner_specific_queue_consumption_gate',
            'area_id' => $areaId,
            'release_id' => $releaseId,
            'queue_item_id' => $queueItemId,
            'target_owner' => $targetOwner,
            'target_runtime_schema' => (string) ($queueItem['target_runtime_schema'] ?? ''),
            'stack' => 'Atlas Software Company Stewardship Stack',
            'source_ap_contracts' => ['AP-747', 'AP-748', 'AP-749', 'AP-756', 'AP-757'],
            'outcome_check' => $outcomeCheck,
            'branch_isolation' => $isolationCheck,
            'sandbox_binding' => $sandboxBinding,
            'execution_receipt_status' => $receiptCheck === null ? 'accepted' : 'required',
            'execution_receipt_blocker' => $receiptCheck,
            'owner_runtime_input' => $this->ownerRuntimeInput($queueItem, $sandboxBinding),
            'record_consumption_requested' => $recordConsumption,
            'blockers' => $receiptCheck === null ? [] : [$receiptCheck],
            'next_actions' => $this->nextActions($targetOwner, $receiptCheck === null),
            'reused_owners' => $this->reusedOwners(),
            'claim_policy' => $this->claimPolicy($receiptCheck === null, $recordConsumption, $sandboxBinding['ok'] === true),
        ];
        $payload['consumption_id'] = $this->consumptionId($payload);
        $payload['consumption_hash'] = 'sha256:'.MissionCanonicalHash::sha256($this->identity($payload));
        $payload['generated_at'] = $this->now();

        return $this->maybeRecord($areaId, $payload, $recordConsumption);
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function releaseReport(array $input): array
    {
        foreach (['release_report', 'dev_forge_release', 'release_record'] as $key) {
            if (is_array($input[$key] ?? null)) {
                return $input[$key];
            }
        }

        foreach (['release_reports', 'dev_forge_releases', 'release_records'] as $key) {
            foreach ((array) ($input[$key] ?? []) as $item) {
                if (is_array($item)) {
                    return $item;
                }
            }
        }

        return [];
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function sandboxRecord(array $input): array
    {
        foreach (['branch_sandbox_record', 'sandbox_record', 'ap756_sandbox_record', 'branch_sandbox'] as $key) {
            if (is_array($input[$key] ?? null)) {
                return $input[$key];
            }
        }

        foreach (['branch_sandbox_records', 'sandbox_records', 'ap756_sandbox_records'] as $key) {
            foreach ((array) ($input[$key] ?? []) as $item) {
                if (is_array($item)) {
                    return $item;
                }
            }
        }

        return [];
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function outcomeBridge(array $input): array
    {
        foreach (['outcome_bridge', 'release_outcome_bridge', 'outcome_evidence'] as $key) {
            if (is_array($input[$key] ?? null)) {
                return $input[$key];
            }
        }

        return [];
    }

    /**
     * @param  array<string,mixed>  $release
     */
    private function isAp747Release(array $release): bool
    {
        return (string) ($release['ap_contract'] ?? '') === 'AP-747'
            || in_array((string) ($release['schema_version'] ?? ''), [
                AreaFocusDevForgeReleaseService::REPORT_SCHEMA,
                AreaFocusDevForgeReleaseService::RECORD_SCHEMA,
            ], true);
    }

    /**
     * @param  array<string,mixed>  $release
     * @param  array<string,mixed>  $outcome
     * @return array<string,mixed>
     */
    private function outcomeCheck(array $release, array $outcome): array
    {
        $releaseId = $this->releaseId($release);
        $queueItemId = (string) data_get($release, 'queue_item.queue_item_id', '');
        $areaId = (string) ($release['area_id'] ?? AreaFocusDevForgeReleaseService::DEFAULT_AREA_ID);
        $sourceAps = array_values(array_filter((array) ($outcome['source_ap_contracts'] ?? []), 'is_string'));
        $evidence = $this->matchingEvidenceItems($releaseId, $queueItemId, (array) ($outcome['evidence_items'] ?? []));
        $inbox = $this->matchingInboxItems($releaseId, $queueItemId, (array) ($outcome['morning_inbox_items'] ?? []));
        $portfolio = $this->portfolioSignalReady($areaId, $queueItemId, $outcome);

        $checks = [
            'outcome_schema_valid' => (string) ($outcome['schema_version'] ?? '') === StewardshipOutcomeEvidenceBridgeService::REPORT_SCHEMA,
            'outcome_status_ready' => (string) ($outcome['status'] ?? '') === StewardshipOutcomeEvidenceBridgeService::STATUS_READY,
            'source_ap747_present' => in_array('AP-747', $sourceAps, true),
            'source_ap748_present' => in_array('AP-748', $sourceAps, true),
            'evidence_item_present' => $evidence !== [],
            'morning_inbox_item_present' => $inbox !== [],
            'portfolio_feed_present' => $portfolio,
        ];

        $missing = [];
        foreach ($checks as $key => $ok) {
            if ($ok !== true) {
                $missing[] = $key;
            }
        }

        return [
            'schema_version' => 'atlas.software_company_stewardship.ap748_outcome_visibility_check.v1',
            'ok' => $missing === [],
            'release_id' => $releaseId,
            'queue_item_id' => $queueItemId,
            'checks' => $checks,
            'missing' => $missing,
            'evidence_event_ids' => array_values(array_filter(array_map(static fn (array $item): string => (string) ($item['event_id'] ?? ''), $evidence))),
            'morning_inbox_dedupe_keys' => array_values(array_filter(array_map(static fn (array $item): string => (string) ($item['dedupe_key'] ?? ''), $inbox))),
        ];
    }

    /**
     * @param  list<mixed>  $items
     * @return list<array<string,mixed>>
     */
    private function matchingEvidenceItems(string $releaseId, string $queueItemId, array $items): array
    {
        $out = [];
        foreach ($items as $item) {
            if (! is_array($item) || (string) ($item['source_kind'] ?? '') !== 'ap747_owner_queue_release') {
                continue;
            }
            $payload = is_array($item['payload'] ?? null) ? $item['payload'] : [];
            if ((string) ($payload['release_id'] ?? $item['source_id'] ?? '') === $releaseId
                || ($queueItemId !== '' && (string) ($payload['queue_item_id'] ?? '') === $queueItemId)) {
                $out[] = $item;
            }
        }

        return $out;
    }

    /**
     * @param  list<mixed>  $items
     * @return list<array<string,mixed>>
     */
    private function matchingInboxItems(string $releaseId, string $queueItemId, array $items): array
    {
        $out = [];
        foreach ($items as $item) {
            if (! is_array($item) || (string) ($item['kind'] ?? '') !== 'ap747_owner_queue_release_review') {
                continue;
            }
            if ((string) ($item['release_id'] ?? '') === $releaseId
                || ($queueItemId !== '' && (string) ($item['queue_item_id'] ?? '') === $queueItemId)) {
                $out[] = $item;
            }
        }

        return $out;
    }

    /**
     * @param  array<string,mixed>  $outcome
     */
    private function portfolioSignalReady(string $areaId, string $queueItemId, array $outcome): bool
    {
        foreach ((array) data_get($outcome, 'portfolio_feed.areas', []) as $area) {
            if (is_array($area)
                && (string) ($area['area_id'] ?? '') === $areaId
                && (int) ($area['owner_queue_pending_count'] ?? 0) > 0) {
                return true;
            }
        }

        return $queueItemId !== ''
            && in_array($queueItemId, (array) data_get($outcome, 'release_outcome_summary.queue_item_ids', []), true);
    }

    /**
     * @param  array<string,mixed>  $queueItem
     * @return array<string,mixed>
     */
    private function branchIsolationCheck(array $queueItem): array
    {
        $owner = (string) ($queueItem['target_owner'] ?? '');
        $allowedPaths = $owner === 'forge'
            ? $this->stringList(data_get($queueItem, 'forge_ticket.locked_paths', []))
            : $this->stringList(data_get($queueItem, 'dev_runtime_payload.artifact_agent_packet.allowed_paths', data_get($queueItem, 'dev_runtime_payload.expected_files', [])));
        $forbiddenPaths = $owner === 'forge'
            ? ['.env', 'secrets', 'vendor', 'node_modules']
            : $this->stringList(data_get($queueItem, 'dev_runtime_payload.artifact_agent_packet.forbidden_paths', ['.env']));
        $violations = [];
        if ($allowedPaths === []) {
            $violations[] = 'allowed_paths_required';
        }
        foreach (['runtime_execution_started', 'provider_invoked', 'branch_created', 'merge_performed', 'deploy_performed', 'secret_access'] as $flag) {
            if ((bool) ($queueItem[$flag] ?? false)) {
                $violations[] = $flag.'_must_be_false_before_ap749';
            }
        }

        return [
            'schema_version' => 'atlas.software_company_stewardship.branch_isolation_check.v1',
            'ok' => $violations === [],
            'target_owner' => $owner,
            'allowed_paths' => $allowedPaths,
            'forbidden_paths' => $forbiddenPaths,
            'violations' => $violations,
        ];
    }

    /**
     * @param  array<string,mixed>  $release
     * @param  array<string,mixed>  $queueItem
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function sandboxBindingCheck(array $release, array $queueItem, array $input): array
    {
        $record = $this->sandboxRecord($input);
        $releaseHandoff = (string) (data_get($release, 'source_refs.handoff_hash', '') ?: ($queueItem['handoff_hash'] ?? ''));
        $violations = [];

        if ($record === []) {
            $violations[] = 'ap756_sandbox_record_required';
        }

        $sourceHandoff = (string) data_get($record, 'source_refs.handoff_hash', '');
        $worktreePath = (string) data_get($record, 'materialization.worktree_path', '');
        $branchName = (string) data_get($record, 'materialization.branch_name', '');
        $schema = (string) ($record['schema_version'] ?? '');
        $status = (string) ($record['status'] ?? '');

        if ($record !== [] && ! in_array($schema, [
            AreaFocusBranchSandboxMaterializerService::REPORT_SCHEMA,
            AreaFocusBranchSandboxMaterializerService::RECORD_SCHEMA,
        ], true)) {
            $violations[] = 'ap756_sandbox_schema_invalid';
        }
        if ($record !== [] && (string) ($record['ap_contract'] ?? '') !== 'AP-756') {
            $violations[] = 'ap756_contract_required';
        }
        if ($record !== [] && $status !== AreaFocusBranchSandboxMaterializerService::STATUS_MATERIALIZED) {
            $violations[] = 'ap756_sandbox_must_be_materialized';
        }
        if ($record !== [] && ! (bool) data_get($record, 'materialization.branch_created', false)) {
            $violations[] = 'ap756_branch_created_required';
        }
        if ($record !== [] && ! (bool) data_get($record, 'materialization.worktree_created', false)) {
            $violations[] = 'ap756_worktree_created_required';
        }
        if ($record !== [] && $worktreePath === '') {
            $violations[] = 'ap756_worktree_path_required';
        }
        if ($worktreePath !== '' && ! is_dir($worktreePath)) {
            $violations[] = 'ap756_worktree_path_not_found';
        }
        if ($record !== [] && $branchName === '') {
            $violations[] = 'ap756_branch_name_required';
        }
        if ($releaseHandoff === '') {
            $violations[] = 'ap747_handoff_hash_required';
        }
        if ($record !== [] && $sourceHandoff === '') {
            $violations[] = 'ap756_handoff_hash_required';
        }
        if ($releaseHandoff !== '' && $sourceHandoff !== '' && $releaseHandoff !== $sourceHandoff) {
            $violations[] = 'ap756_handoff_hash_mismatch';
        }

        return [
            'schema_version' => 'atlas.software_company_stewardship.ap757_sandbox_binding_check.v1',
            'ap_contract' => 'AP-757',
            'ok' => $violations === [],
            'required' => true,
            'release_handoff_hash' => $releaseHandoff,
            'sandbox_handoff_hash' => $sourceHandoff,
            'sandbox_id' => (string) ($record['sandbox_id'] ?? ''),
            'sandbox_status' => $status,
            'branch_name' => $branchName,
            'worktree_path' => $worktreePath,
            'worktree_path_hash' => $worktreePath !== '' ? hash('sha256', $worktreePath) : '',
            'violations' => $violations,
        ];
    }

    /**
     * @param  array<string,mixed>  $receipt
     */
    private function executionReceiptCheck(array $receipt, string $releaseId, string $queueItemId): ?string
    {
        if ($receipt === []) {
            return 'operator_execution_receipt_required';
        }
        if (! in_array((string) ($receipt['decision'] ?? ''), ['consume', 'start_owner_runtime', 'execute_owner_runtime'], true)) {
            return 'consume_decision_required';
        }
        if (trim((string) ($receipt['operator_actor'] ?? '')) === '') {
            return 'operator_actor_required';
        }
        $targetRelease = trim((string) ($receipt['target_release_id'] ?? $receipt['release_id'] ?? ''));
        if ($targetRelease !== '' && $targetRelease !== $releaseId) {
            return 'target_release_id_mismatch';
        }
        $targetQueue = trim((string) ($receipt['target_queue_item_id'] ?? $receipt['queue_item_id'] ?? ''));
        if ($targetQueue !== '' && $targetQueue !== $queueItemId) {
            return 'target_queue_item_id_mismatch';
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $queueItem
     * @return array<string,mixed>
     */
    private function ownerRuntimeInput(array $queueItem, array $sandboxBinding): array
    {
        $owner = (string) ($queueItem['target_owner'] ?? '');
        if ($owner === 'forge') {
            return [
                'schema_version' => 'atlas.software_company_stewardship.ap749_forge_owner_runtime_input.v1',
                'target_owner' => 'forge',
                'target_runtime_schema' => AtlasForgeParallelDurableCoordinatorService::SCHEMA_VERSION,
                'target_runtime_service' => AtlasForgeParallelDurableCoordinatorService::class,
                'queue_item_id' => (string) ($queueItem['queue_item_id'] ?? ''),
                'forge_ticket' => is_array($queueItem['forge_ticket'] ?? null) ? $queueItem['forge_ticket'] : [],
                'forge_parallel_durable_proposal' => is_array($queueItem['forge_parallel_durable_proposal'] ?? null) ? $queueItem['forge_parallel_durable_proposal'] : [],
                'branch_sandbox' => $this->ownerRuntimeSandbox($sandboxBinding),
                'execution_boundary' => $this->executionBoundary(),
            ];
        }

        $devPayload = is_array($queueItem['dev_runtime_payload'] ?? null) ? $queueItem['dev_runtime_payload'] : [];
        $artifactPacket = is_array($devPayload['artifact_agent_packet'] ?? null) ? $devPayload['artifact_agent_packet'] : [];
        $artifactPacket['branch_sandbox'] = $this->ownerRuntimeSandbox($sandboxBinding);
        $devPayload['artifact_agent_packet'] = $artifactPacket;

        return [
            'schema_version' => 'atlas.software_company_stewardship.ap749_atlas_dev_owner_runtime_input.v1',
            'target_owner' => 'atlas_dev',
            'target_runtime_schema' => AtlasDevRuntimeService::SCHEMA_VERSION,
            'target_runtime_service' => AtlasDevRuntimeService::class,
            'queue_item_id' => (string) ($queueItem['queue_item_id'] ?? ''),
            'dev_runtime_payload' => $devPayload,
            'branch_sandbox' => $this->ownerRuntimeSandbox($sandboxBinding),
            'execution_boundary' => $this->executionBoundary(),
        ];
    }

    /**
     * @param  array<string,mixed>  $sandboxBinding
     * @return array<string,mixed>
     */
    private function ownerRuntimeSandbox(array $sandboxBinding): array
    {
        return [
            'schema_version' => 'atlas.software_company_stewardship.ap757_owner_runtime_sandbox_binding.v1',
            'ap_contract' => 'AP-757',
            'sandbox_id' => (string) ($sandboxBinding['sandbox_id'] ?? ''),
            'branch_name' => (string) ($sandboxBinding['branch_name'] ?? ''),
            'worktree_path' => (string) ($sandboxBinding['worktree_path'] ?? ''),
            'worktree_path_hash' => (string) ($sandboxBinding['worktree_path_hash'] ?? ''),
            'handoff_hash' => (string) ($sandboxBinding['release_handoff_hash'] ?? ''),
            'binding_ok' => (bool) ($sandboxBinding['ok'] ?? false),
        ];
    }

    /**
     * @return array<string,bool|string>
     */
    private function executionBoundary(): array
    {
        return [
            'branch_isolation_required' => true,
            'materialized_ap756_sandbox_required' => true,
            'evidence_pack_required' => true,
            'owner_review_required' => true,
            'merge_allowed' => false,
            'deploy_allowed' => false,
            'external_push_allowed' => false,
            'secret_access_allowed' => false,
            'destructive_change_allowed' => false,
            'provider_invoked_by_gate' => false,
        ];
    }

    /**
     * @return list<string>
     */
    private function nextActions(string $targetOwner, bool $ready): array
    {
        if (! $ready) {
            return [
                'Review AP-748 Evidence/Morning Inbox/Portfolio signals in Product Mode.',
                'Provide an AP-749 operator execution receipt before owner runtime consumption.',
                'Materialize and attach an AP-756 branch sandbox record before runtime start.',
            ];
        }

        return [
            "Hand the owner_runtime_input to {$targetOwner} only through its existing owner runtime.",
            'Run owner work inside the AP-756 materialized worktree carried by branch_sandbox.',
            'Require branch isolation, evidence pack and owner review before completion.',
            'Keep merge, deploy, external push, secrets and destructive changes blocked until a later explicit operator approval.',
        ];
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function reusedOwners(): array
    {
        return [
            'release_queue' => ['ap' => 'AP-747', 'owner_service' => AreaFocusDevForgeReleaseService::class],
            'release_outcome_bridge' => ['ap' => 'AP-748/AP-740', 'owner_service' => StewardshipOutcomeEvidenceBridgeService::class],
            'branch_sandbox_materializer' => ['ap' => 'AP-756/AP-757', 'owner_service' => AreaFocusBranchSandboxMaterializerService::class],
            'atlas_dev' => ['runtime_schema' => AtlasDevRuntimeService::SCHEMA_VERSION, 'owner_service' => AtlasDevRuntimeService::class],
            'forge' => ['runtime_schema' => AtlasForgeParallelDurableCoordinatorService::SCHEMA_VERSION, 'owner_service' => AtlasForgeParallelDurableCoordinatorService::class],
            'product_mode' => ['ap' => 'AP-739', 'role' => 'operator review/control surface'],
        ];
    }

    /**
     * @return array<string,bool|string>
     */
    private function claimPolicy(bool $ownerRuntimeStartAuthorized, bool $recorded, bool $sandboxBound = false): array
    {
        return [
            'mode' => 'owner_specific_queue_consumption_gate',
            'requires_ap747_release' => true,
            'requires_ap748_evidence_inbox_portfolio' => true,
            'requires_ap756_materialized_sandbox' => true,
            'ap756_sandbox_bound' => $sandboxBound,
            'requires_operator_execution_receipt' => true,
            'owner_runtime_start_authorized' => $ownerRuntimeStartAuthorized,
            'consumption_recorded_when_requested' => $recorded,
            'runtime_execution_started_by_gate' => false,
            'provider_invoked_by_gate' => false,
            'branch_created_by_gate' => false,
            'worktree_created_by_gate' => false,
            'target_repo_mutated_by_gate' => false,
            'merge_performed' => false,
            'deploy_performed' => false,
            'pushed_external' => false,
            'secret_access' => false,
            'destructive_change' => false,
            'parallel_runtime_created' => false,
            'new_os_created' => false,
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function maybeRecord(string $areaId, array $payload, bool $record): array
    {
        if (! $record) {
            return $payload + ['consumption_storage_status' => 'projected'];
        }

        if (($payload['status'] ?? '') !== self::STATUS_READY) {
            return $payload + ['consumption_storage_status' => 'not_recorded_until_ready'];
        }

        $path = $this->consumptionFilePath($areaId);
        File::ensureDirectoryExists(dirname($path));
        $existing = $this->findRecord($path, (string) ($payload['consumption_id'] ?? ''));
        if ($existing !== null) {
            return $existing + ['consumption_storage_status' => 'existing'];
        }

        $recordPayload = [
            'schema_version' => self::RECORD_SCHEMA,
            'recorded_at' => $this->now(),
        ] + $payload;
        $recordPayload['status'] = self::STATUS_RECORDED;
        File::append($path, json_encode($recordPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL);

        return $recordPayload + ['consumption_storage_status' => 'recorded'];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function findRecord(string $path, string $consumptionId): ?array
    {
        if ($consumptionId === '' || ! is_file($path)) {
            return null;
        }
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded) && (string) ($decoded['consumption_id'] ?? '') === $consumptionId) {
                return $decoded;
            }
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
            'ap_contract' => 'AP-749',
            'status' => self::STATUS_BLOCKED,
            'mode' => 'owner_specific_queue_consumption_gate',
            'area_id' => $areaId,
            'release_id' => $this->releaseId($source),
            'queue_item_id' => (string) data_get($source, 'queue_item.queue_item_id', ''),
            'target_owner' => (string) data_get($source, 'queue_item.target_owner', $source['target_owner'] ?? ''),
            'reason' => $reason,
            'detail' => $detail,
            'source_ap_contracts' => ['AP-747', 'AP-748', 'AP-749', 'AP-756', 'AP-757'],
            'blockers' => [$reason],
            'next_actions' => ['Resolve AP-749 blocker before any Dev/Forge owner runtime consumes the queue item.'],
            'reused_owners' => $this->reusedOwners(),
            'claim_policy' => $this->claimPolicy(false, false, false),
        ] + $extra;
        $payload['consumption_hash'] = 'sha256:'.MissionCanonicalHash::sha256($payload);
        $payload['generated_at'] = $this->now();

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $release
     */
    private function releaseId(array $release): string
    {
        return (string) ($release['release_id'] ?? data_get($release, 'release_receipt.release_id', data_get($release, 'queue_item.release_id', '')));
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function consumptionId(array $payload): string
    {
        return 'afcons_'.substr(MissionCanonicalHash::sha256([
            'AP-749',
            (string) ($payload['release_id'] ?? ''),
            (string) ($payload['queue_item_id'] ?? ''),
            (string) ($payload['target_owner'] ?? ''),
        ]), 0, 18);
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function identity(array $payload): array
    {
        $copy = $payload;
        unset($copy['generated_at'], $copy['consumption_hash'], $copy['consumption_storage_status'], $copy['recorded_at']);

        return $copy;
    }

    /**
     * @param  mixed  $value
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        $out = [];
        foreach ((array) $value as $item) {
            if (is_string($item) && trim($item) !== '') {
                $out[] = trim($item);
            }
        }

        return array_values(array_unique($out));
    }

    private function slug(string $value): string
    {
        $slug = strtolower(preg_replace('/[^a-zA-Z0-9_-]+/', '_', trim($value)) ?: '');

        return trim($slug, '_') ?: AreaFocusDevForgeReleaseService::DEFAULT_AREA_ID;
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);
    }
}
