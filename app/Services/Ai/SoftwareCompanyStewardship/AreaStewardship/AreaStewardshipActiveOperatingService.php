<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaStewardship;

use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusBranchSandboxHandoffService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusDevForgeRouterService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusLoopOperationalOrchestratorService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusSpecDraftBridge;
use App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution\StewardshipEvolutionReadModelService;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * AP-744 · Area Stewardship active operating slice.
 *
 * Consumes the AP-743 active handoff packet and conducts one governed Area
 * Stewardship cycle by reusing the existing owners:
 *
 *   AP-743 handoff -> AP-722 Area Focus cycle -> AP-718 spec drafts
 *   -> AP-726 Dev/Forge preflight handoffs -> operator review.
 *
 * It is active in orchestration and stewardship responsibility, not in
 * permissionless mutation: it never invokes providers, creates branches,
 * dispatches Dev/Forge, mutates repos, merges, deploys or touches secrets.
 */
final class AreaStewardshipActiveOperatingService
{
    public const REPORT_SCHEMA = 'atlas.area_stewardship.active_operation.v1';

    public const RECORD_SCHEMA = 'atlas.area_stewardship.active_operation_record.v1';

    public const STATUS_READY = 'active_cycle_ready';

    public const STATUS_PARTIAL = 'active_cycle_partial';

    public const STATUS_AWAITING_HANDOFF = 'awaiting_active_handoff';

    public const STATUS_BLOCKED = 'blocked';

    private ?string $storageRootOverride = null;

    public function __construct(
        private readonly AreaStewardshipActiveHandoffService $activeHandoff,
        private readonly AreaFocusLoopOperationalOrchestratorService $areaFocus,
        private readonly AreaFocusSpecDraftBridge $specDraftBridge,
        private readonly AreaFocusBranchSandboxHandoffService $branchSandboxHandoff,
    ) {}

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
            ? storage_path('atlas/software_company_stewardship/area_stewardship_active_operations')
            : sys_get_temp_dir().'/atlas/software_company_stewardship/area_stewardship_active_operations';
    }

    public function operationFilePath(string $areaId): string
    {
        return $this->storageDir().DIRECTORY_SEPARATOR.$this->slug($areaId).'.jsonl';
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function operate(array $input = []): array
    {
        $areaId = $this->areaId($input);
        $recordOperation = (bool) ($input['record_active_operation'] ?? false);

        $handoff = is_array($input['active_handoff_report'] ?? null)
            ? $input['active_handoff_report']
            : $this->activeHandoff->project($input + ['area_id' => $areaId]);

        $handoffStatus = (string) ($handoff['status'] ?? 'unknown');
        if ($handoffStatus !== AreaStewardshipActiveHandoffService::STATUS_READY) {
            return $this->finalize($this->maybeRecord($areaId, [
                'schema_version' => self::REPORT_SCHEMA,
                'status' => $handoffStatus === AreaStewardshipActiveHandoffService::STATUS_AWAITING_OPERATOR_ACCEPTANCE
                    ? self::STATUS_AWAITING_HANDOFF
                    : self::STATUS_BLOCKED,
                'ap_contract' => 'AP-744',
                'area_id' => $areaId,
                'stack' => 'Atlas Software Company Stewardship Stack',
                'layer' => 'Area Stewardship Layer',
                'source_ap_contracts' => ['AP-743'],
                'record_active_operation_requested' => $recordOperation,
                'active_handoff_status' => $handoffStatus,
                'blockers' => $this->handoffBlockers($handoff),
                'next_actions' => ['Prepare AP-743 active handoff before running Area Stewardship active operation.'],
                'claim_policy' => $this->claimPolicy($recordOperation),
            ], $recordOperation));
        }

        $packet = $this->firstHandoffPacket($handoff);
        if ($packet === null) {
            return $this->finalize($this->maybeRecord($areaId, [
                'schema_version' => self::REPORT_SCHEMA,
                'status' => self::STATUS_BLOCKED,
                'ap_contract' => 'AP-744',
                'area_id' => $areaId,
                'stack' => 'Atlas Software Company Stewardship Stack',
                'layer' => 'Area Stewardship Layer',
                'source_ap_contracts' => ['AP-743'],
                'record_active_operation_requested' => $recordOperation,
                'active_handoff_status' => $handoffStatus,
                'blockers' => ['active_handoff_packet_missing'],
                'next_actions' => ['Record or project a valid AP-743 active handoff packet.'],
                'claim_policy' => $this->claimPolicy($recordOperation),
            ], $recordOperation));
        }

        $cycle = is_array($input['operational_cycle'] ?? null)
            ? $input['operational_cycle']
            : $this->areaFocus->run($this->cycleInput($input, $areaId));

        if ((string) ($cycle['status'] ?? '') === AreaFocusLoopOperationalOrchestratorService::STATUS_BLOCKED) {
            return $this->finalize($this->maybeRecord($areaId, [
                'schema_version' => self::REPORT_SCHEMA,
                'status' => self::STATUS_BLOCKED,
                'ap_contract' => 'AP-744',
                'area_id' => $areaId,
                'stack' => 'Atlas Software Company Stewardship Stack',
                'layer' => 'Area Stewardship Layer',
                'source_ap_contracts' => ['AP-743', 'AP-722'],
                'record_active_operation_requested' => $recordOperation,
                'active_handoff_status' => $handoffStatus,
                'active_handoff_packet' => $this->packetSummary($packet),
                'operational_cycle' => $cycle,
                'blockers' => ['area_focus_operational_cycle_blocked'],
                'next_actions' => ['Repair AP-722 Area Focus operational blockers before active Area Stewardship can operate.'],
                'claim_policy' => $this->claimPolicy($recordOperation),
            ], $recordOperation));
        }

        $findings = $this->cycleFindings($cycle, $input);
        $workOrderPlan = $this->workOrderPlan($cycle);
        $workOrders = $this->workOrders($workOrderPlan);
        $specDrafts = $this->specDrafts($findings, $workOrders);
        $branchHandoff = $this->branchHandoff($areaId, $cycle, $workOrderPlan, $input);
        $queue = $this->operationQueue($workOrders, $specDrafts, $branchHandoff);
        $status = $this->operationStatus($cycle, $branchHandoff);

        return $this->finalize($this->maybeRecord($areaId, [
            'schema_version' => self::REPORT_SCHEMA,
            'status' => $status,
            'ap_contract' => 'AP-744',
            'area_id' => $areaId,
            'stack' => 'Atlas Software Company Stewardship Stack',
            'layer' => 'Area Stewardship Layer',
            'source_ap_contracts' => ['AP-743', 'AP-722', 'AP-718', 'AP-719', 'AP-720', 'AP-726'],
            'record_active_operation_requested' => $recordOperation,
            'active_handoff_status' => $handoffStatus,
            'active_handoff_packet' => $this->packetSummary($packet),
            'active_handoff_hash' => (string) ($handoff['handoff_hash'] ?? ''),
            'operational_cycle_id' => (string) ($cycle['cycle_id'] ?? ''),
            'operational_cycle_hash' => (string) ($cycle['report_hash'] ?? ''),
            'stage_status' => is_array($cycle['stage_status'] ?? null) ? $cycle['stage_status'] : [],
            'counts' => [
                'findings' => count($findings),
                'work_orders' => count($workOrders),
                'spec_drafts' => count($specDrafts),
                'branch_handoffs' => count((array) ($branchHandoff['handoffs'] ?? [])),
                'ready_branch_handoffs' => (int) ($branchHandoff['counts'][AreaFocusBranchSandboxHandoffService::HO_READY] ?? 0),
                'awaiting_operator_handoffs' => (int) ($branchHandoff['counts'][AreaFocusBranchSandboxHandoffService::HO_AWAITING] ?? 0),
            ],
            'operation_queue' => $queue,
            'spec_drafts' => $specDrafts,
            'branch_sandbox_handoff' => $branchHandoff,
            'operational_cycle' => $cycle,
            'blockers' => $this->operationBlockers($status, $branchHandoff),
            'next_actions' => $this->nextActions($status, $queue),
            'reused_owners' => $this->reusedOwners(),
            'claim_policy' => $this->claimPolicy($recordOperation),
        ], $recordOperation));
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function cycleInput(array $input, string $areaId): array
    {
        $out = ['area_id' => $areaId];
        foreach (['hours', 'limit', 'findings', 'core_report', 'docs', 'service_files', 'test_files'] as $key) {
            if (array_key_exists($key, $input)) {
                $out[$key] = $input[$key];
            }
        }

        return $out;
    }

    /**
     * @param  array<string,mixed>  $handoff
     * @return list<string>
     */
    private function handoffBlockers(array $handoff): array
    {
        $blockers = array_values(array_filter((array) ($handoff['blockers'] ?? []), 'is_string'));

        return $blockers !== [] ? $blockers : ['ap743_active_handoff_not_ready'];
    }

    /**
     * @param  array<string,mixed>  $handoff
     * @return array<string,mixed>|null
     */
    private function firstHandoffPacket(array $handoff): ?array
    {
        $packets = is_array($handoff['active_handoff_packets'] ?? null) ? $handoff['active_handoff_packets'] : [];
        foreach ($packets as $packet) {
            if (is_array($packet)) {
                return $packet;
            }
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $packet
     * @return array<string,mixed>
     */
    private function packetSummary(array $packet): array
    {
        return [
            'schema_version' => (string) ($packet['schema_version'] ?? ''),
            'handoff_packet_id' => (string) ($packet['handoff_packet_id'] ?? ''),
            'handoff_status' => (string) ($packet['handoff_status'] ?? ''),
            'packet_hash' => (string) ($packet['packet_hash'] ?? ''),
            'target_id' => (string) ($packet['target_id'] ?? ''),
            'operator_acceptance' => is_array($packet['operator_acceptance'] ?? null) ? $packet['operator_acceptance'] : [],
        ];
    }

    /**
     * @param  array<string,mixed>  $cycle
     * @param  array<string,mixed>  $input
     * @return list<array<string,mixed>>
     */
    private function cycleFindings(array $cycle, array $input): array
    {
        if (is_array($input['findings'] ?? null)) {
            return array_values(array_filter($input['findings'], 'is_array'));
        }

        $scan = is_array($cycle['stages']['scan'] ?? null) ? $cycle['stages']['scan'] : [];

        return is_array($scan['findings'] ?? null) ? array_values(array_filter($scan['findings'], 'is_array')) : [];
    }

    /**
     * @param  array<string,mixed>  $cycle
     * @return array<string,mixed>
     */
    private function workOrderPlan(array $cycle): array
    {
        return is_array($cycle['stages']['work_orders'] ?? null) ? $cycle['stages']['work_orders'] : [];
    }

    /**
     * @param  array<string,mixed>  $workOrderPlan
     * @return list<array<string,mixed>>
     */
    private function workOrders(array $workOrderPlan): array
    {
        return is_array($workOrderPlan['work_orders'] ?? null)
            ? array_values(array_filter($workOrderPlan['work_orders'], 'is_array'))
            : [];
    }

    /**
     * @param  list<array<string,mixed>>  $findings
     * @param  list<array<string,mixed>>  $workOrders
     * @return list<array<string,mixed>>
     */
    private function specDrafts(array $findings, array $workOrders): array
    {
        $selfDirectedRefs = [];
        foreach ($workOrders as $workOrder) {
            if ((string) ($workOrder['route'] ?? '') === AreaFocusDevForgeRouterService::ROUTE_SELF_DIRECTED_EVOLUTION
                && (string) ($workOrder['status'] ?? '') === AreaFocusDevForgeRouterService::WO_EMITTED) {
                $selfDirectedRefs[(string) ($workOrder['source_ref'] ?? '')] = true;
            }
        }

        $drafts = [];
        foreach ($findings as $finding) {
            $ref = (string) ($finding['finding_hash'] ?? '');
            if ($ref === '' || ! isset($selfDirectedRefs[$ref])) {
                continue;
            }

            try {
                $drafts[] = $this->specDraftBridge->draftFromFinding($finding);
            } catch (Throwable $e) {
                $drafts[] = [
                    'schema_version' => AreaFocusSpecDraftBridge::DRAFT_SCHEMA,
                    'status' => 'blocked',
                    'finding_hash' => $ref,
                    'error' => $e->getMessage(),
                    'operator_approval_required' => true,
                    'autoimplementation_allowed' => false,
                ];
            }
        }

        return $drafts;
    }

    /**
     * @param  array<string,mixed>  $cycle
     * @param  array<string,mixed>  $workOrderPlan
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function branchHandoff(string $areaId, array $cycle, array $workOrderPlan, array $input): array
    {
        if ($this->workOrders($workOrderPlan) === []) {
            return [
                'schema_version' => AreaFocusBranchSandboxHandoffService::REPORT_SCHEMA,
                'status' => 'not_applicable',
                'reason' => 'no_work_orders',
                'area_id' => $areaId,
                'handoffs' => [],
                'counts' => [],
                'claim_policy' => [
                    'preflight_only' => true,
                    'branch_created' => false,
                    'work_dispatched' => false,
                    'execution_performed' => false,
                ],
            ];
        }

        return $this->branchSandboxHandoff->preflight([
            'area_id' => $areaId,
            'work_order_plan' => $workOrderPlan,
            'operator_receipts' => is_array($input['operator_receipts'] ?? null) ? $input['operator_receipts'] : [],
            'gate_report' => is_array($input['gate_report'] ?? null) ? $input['gate_report'] : null,
            'contract' => is_array($cycle['area_contract'] ?? null) ? $cycle['area_contract'] : null,
        ]);
    }

    /**
     * @param  list<array<string,mixed>>  $workOrders
     * @param  list<array<string,mixed>>  $specDrafts
     * @param  array<string,mixed>  $branchHandoff
     * @return array<string,mixed>
     */
    private function operationQueue(array $workOrders, array $specDrafts, array $branchHandoff): array
    {
        $byRoute = [];
        foreach ($workOrders as $workOrder) {
            $route = (string) ($workOrder['route'] ?? 'unknown');
            $byRoute[$route] = ($byRoute[$route] ?? 0) + 1;
        }

        $handoffs = is_array($branchHandoff['handoffs'] ?? null) ? $branchHandoff['handoffs'] : [];
        $operatorDecisions = [];
        foreach ($workOrders as $workOrder) {
            if (($workOrder['requires_operator_review'] ?? true) === true) {
                $operatorDecisions[] = [
                    'kind' => 'area_focus_work_order_decision',
                    'ap_contract' => 'AP-724',
                    'work_order_id' => (string) ($workOrder['work_order_id'] ?? ''),
                    'finding_hash' => (string) ($workOrder['source_ref'] ?? ''),
                    'route' => (string) ($workOrder['route'] ?? ''),
                    'decision_options' => ['accept', 'reject', 'defer', 'request_changes'],
                ];
            }
        }

        return [
            'schema_version' => 'atlas.area_stewardship.active_operation_queue.v1',
            'by_route' => $byRoute,
            'operator_decision_count' => count($operatorDecisions),
            'operator_decisions' => $operatorDecisions,
            'self_directed_spec_draft_count' => count($specDrafts),
            'dev_forge_handoff_count' => count($handoffs),
            'ready_dev_forge_handoff_count' => (int) ($branchHandoff['counts'][AreaFocusBranchSandboxHandoffService::HO_READY] ?? 0),
            'awaiting_operator_handoff_count' => (int) ($branchHandoff['counts'][AreaFocusBranchSandboxHandoffService::HO_AWAITING] ?? 0),
        ];
    }

    /**
     * @param  array<string,mixed>  $cycle
     * @param  array<string,mixed>  $branchHandoff
     */
    private function operationStatus(array $cycle, array $branchHandoff): string
    {
        if ((string) ($branchHandoff['status'] ?? '') === AreaFocusBranchSandboxHandoffService::STATUS_BLOCKED) {
            return self::STATUS_BLOCKED;
        }
        if ((string) ($cycle['status'] ?? '') === AreaFocusLoopOperationalOrchestratorService::STATUS_PARTIAL
            || (string) ($branchHandoff['status'] ?? '') === AreaFocusBranchSandboxHandoffService::STATUS_PARTIAL) {
            return self::STATUS_PARTIAL;
        }

        return self::STATUS_READY;
    }

    /**
     * @param  array<string,mixed>  $branchHandoff
     * @return list<string>
     */
    private function operationBlockers(string $status, array $branchHandoff): array
    {
        if ($status !== self::STATUS_BLOCKED) {
            return [];
        }

        $reason = (string) ($branchHandoff['reason'] ?? '');

        return [$reason !== '' ? $reason : 'active_operation_blocked'];
    }

    /**
     * @param  array<string,mixed>  $queue
     * @return list<string>
     */
    private function nextActions(string $status, array $queue): array
    {
        if ($status === self::STATUS_BLOCKED) {
            return ['Repair active operation blockers before releasing any owner handoff.'];
        }

        $actions = [
            'Review AP-724 operator decisions for emitted Area Focus work orders.',
            'Review AP-718 spec drafts before any canonical doc/AP write.',
        ];

        if ((int) ($queue['ready_dev_forge_handoff_count'] ?? 0) > 0) {
            $actions[] = 'Release ready AP-726 Dev/Forge handoffs only under explicit operator control.';
        }
        if ((int) ($queue['awaiting_operator_handoff_count'] ?? 0) > 0) {
            $actions[] = 'Record AP-724 accept/reject/defer/request_changes receipts for awaiting Dev/Forge handoffs.';
        }

        return $actions;
    }

    /**
     * @return array<string,array<string,string>>
     */
    private function reusedOwners(): array
    {
        return [
            'active_handoff' => ['ap' => 'AP-743', 'owner_service' => AreaStewardshipActiveHandoffService::class],
            'area_focus_operational_cycle' => ['ap' => 'AP-722', 'owner_service' => AreaFocusLoopOperationalOrchestratorService::class],
            'spec_draft_bridge' => ['ap' => 'AP-718', 'owner_service' => AreaFocusSpecDraftBridge::class],
            'branch_sandbox_handoff' => ['ap' => 'AP-726', 'owner_service' => AreaFocusBranchSandboxHandoffService::class],
        ];
    }

    /**
     * @return array<string,bool|string>
     */
    private function claimPolicy(bool $recordOperation): array
    {
        return [
            'active_stewardship_cycle_runs' => true,
            'records_active_operation_when_requested' => $recordOperation,
            'persistence' => 'jsonl_append_only',
            'read_only_over_repo' => true,
            'writes_local_state' => $recordOperation,
            'mutates_target_repo' => false,
            'provider_invoked' => false,
            'dev_invoked' => false,
            'forge_invoked' => false,
            'work_dispatched' => false,
            'branch_created' => false,
            'worktree_created' => false,
            'creates_executor' => false,
            'creates_runtime' => false,
            'creates_new_os' => false,
            'merges' => false,
            'deploys' => false,
            'touches_secrets' => false,
            'destructive_change' => false,
            'auto_promotion' => false,
            'operator_review_required' => true,
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function maybeRecord(string $areaId, array $payload, bool $record): array
    {
        if (! $record) {
            return $payload + ['operation_storage_status' => 'projected'];
        }

        $payload = $payload + ['operation_storage_status' => 'recorded'];
        $operationId = $this->operationId($payload);
        $existing = $this->findOperation($this->operationFilePath($areaId), $operationId);
        if ($existing !== null) {
            return array_merge($existing, ['operation_storage_status' => 'existing']);
        }

        $recordPayload = $payload + [
            'record_schema_version' => self::RECORD_SCHEMA,
            'operation_id' => $operationId,
            'recorded_at' => $this->now(),
        ];

        $this->appendJsonl($this->operationFilePath($areaId), $recordPayload);

        return $recordPayload;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function operationId(array $payload): string
    {
        return 'asop_'.substr(MissionCanonicalHash::sha256($this->stable($payload)), 0, 22);
    }

    /**
     * @return array<string,mixed>|null
     */
    private function findOperation(string $path, string $operationId): ?array
    {
        if (! is_file($path) || $operationId === '') {
            return null;
        }

        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded) && (string) ($decoded['operation_id'] ?? '') === $operationId) {
                return $decoded;
            }
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function appendJsonl(string $path, array $payload): void
    {
        File::ensureDirectoryExists(dirname($path));

        $fp = fopen($path, 'ab');
        if ($fp === false) {
            throw new \RuntimeException("Could not open {$path} for writing.");
        }

        try {
            if (flock($fp, LOCK_EX)) {
                fwrite($fp, json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL);
                fflush($fp);
                flock($fp, LOCK_UN);
            }
        } finally {
            fclose($fp);
        }
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function finalize(array $payload): array
    {
        $payload['operation_id'] = (string) ($payload['operation_id'] ?? $this->operationId($payload));
        $payload['operation_hash'] = 'sha256:'.MissionCanonicalHash::sha256($this->stable($payload));
        $payload['generated_at'] = $this->now();

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function stable(array $payload): array
    {
        unset($payload['operation_hash'], $payload['generated_at'], $payload['recorded_at'], $payload['operation_storage_status']);

        return $this->withoutVolatileTimestamps($payload);
    }

    /**
     * @param  array<string|int,mixed>  $value
     * @return array<string|int,mixed>
     */
    private function withoutVolatileTimestamps(array $value): array
    {
        foreach (['generated_at', 'recorded_at', 'decided_at'] as $key) {
            unset($value[$key]);
        }

        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->withoutVolatileTimestamps($item);
            }
        }

        return $value;
    }

    /**
     * @param  array<string,mixed>  $input
     */
    private function areaId(array $input): string
    {
        $value = trim((string) ($input['area_id'] ?? $input['area'] ?? ''));

        return $value !== '' ? $this->slug($value) : StewardshipEvolutionReadModelService::DEFAULT_AREA_ID;
    }

    private function slug(string $value): string
    {
        $slug = preg_replace('/[^a-z0-9_]+/', '_', strtolower(trim($value))) ?? '';
        $slug = trim($slug, '_');

        return $slug !== '' ? $slug : 'unknown';
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);
    }
}
