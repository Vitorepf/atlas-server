<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\AtlasForge\AtlasForgeParallelDurableCoordinatorService;
use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\Programming\AtlasDevRuntimeService;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Support\Facades\File;

/**
 * AP-747 · operator-owned release from AP-726 to real Dev/Forge queues.
 *
 * This is the first post-AP-726 release boundary. It consumes a branch sandbox
 * preflight/handoff, requires an explicit operator release receipt, then emits
 * durable queue items for the existing Atlas Dev or Forge owners. It never
 * creates branches, invokes providers, mutates repos, merges, deploys, pushes or
 * touches secrets.
 */
final class AreaFocusDevForgeReleaseService implements \App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\OwnerFlow\OwnerQueueReleaseGate
{
    public const REPORT_SCHEMA = 'atlas.software_company_stewardship.area_focus_dev_forge_release.v1';

    public const RECORD_SCHEMA = 'atlas.software_company_stewardship.area_focus_dev_forge_release_record.v1';

    public const DEV_QUEUE_SCHEMA = 'atlas.software_company_stewardship.area_focus_atlas_dev_queue_item.v1';

    public const FORGE_QUEUE_SCHEMA = 'atlas.software_company_stewardship.area_focus_forge_queue_item.v1';

    public const STATUS_READY = 'ready_for_owner_queue';

    public const STATUS_RECORDED = 'owner_queue_recorded';

    public const STATUS_BLOCKED = 'blocked';

    public const DEFAULT_AREA_ID = 'agentic_engineering_os';

    private ?string $storageRootOverride = null;

    public function __construct(
        private readonly AtlasForgeParallelDurableCoordinatorService $forgeCoordinator,
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
            ? storage_path('atlas/software_company_stewardship/area_focus_dev_forge_releases')
            : sys_get_temp_dir().'/atlas/software_company_stewardship/area_focus_dev_forge_releases';
    }

    public function releaseFilePath(string $areaId): string
    {
        return $this->storageDir().DIRECTORY_SEPARATOR.$this->slug($areaId).'.jsonl';
    }

    /**
     * Release one AP-726 handoff into the real owner queue boundary.
     *
     * Required input:
     *   - preflight_report: AP-726 report (`handoff_packet` or `handoffs[]`)
     *   - release_receipt:  operator receipt with decision=release and a
     *                       target_handoff_hash/target_hash matching the AP-726
     * Optional:
     *   - record_release: append idempotent JSONL queue record
     *   - workspace: Atlas Dev workspace slug (default atlas-server)
     *   - forge_agents / existing_reservations: passed to the real Forge
     *     parallel durable coordinator for route=forge queue projections
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function release(array $input): array
    {
        $preflight = is_array($input['preflight_report'] ?? null) ? $input['preflight_report'] : [];
        if ($preflight === []) {
            return $this->blocked(self::DEFAULT_AREA_ID, 'preflight_report_required', 'AP-747 requires an AP-726 preflight or handoff report.');
        }

        $areaId = trim((string) ($preflight['area_id'] ?? $input['area_id'] ?? self::DEFAULT_AREA_ID)) ?: self::DEFAULT_AREA_ID;
        $receipt = is_array($input['release_receipt'] ?? null) ? $input['release_receipt'] : [];
        $receiptCheck = $this->releaseReceiptCheck($receipt);
        if ($receiptCheck !== null) {
            return $this->blocked($areaId, $receiptCheck['reason'], $receiptCheck['detail'], $preflight);
        }

        if ((bool) ($input['kill_switch'] ?? false)) {
            return $this->blocked($areaId, 'kill_switch_active', 'Product Mode kill switch is active; AP-747 release is blocked.', $preflight);
        }

        $readyHandoffs = $this->readyHandoffs($preflight);
        if ($readyHandoffs === []) {
            return $this->blocked($areaId, 'ready_handoff_required', 'AP-747 requires at least one AP-726 ready Dev/Forge handoff.', $preflight);
        }

        $targetHash = $this->targetHash($receipt);
        $handoff = $this->matchingHandoff($readyHandoffs, $targetHash);
        if ($handoff === null) {
            return $this->blocked($areaId, 'release_target_not_ready', 'Release receipt target hash does not match a ready AP-726 handoff.', $preflight, [
                'target_handoff_hash' => $targetHash,
                'ready_handoff_hashes' => array_map(static fn (array $h): string => (string) ($h['handoff_hash'] ?? ''), $readyHandoffs),
            ]);
        }

        $queueItem = $this->queueItem($areaId, $handoff, $receipt, $input);
        $releaseId = $this->releaseId($areaId, $handoff, $receipt);
        $recordRelease = (bool) ($input['record_release'] ?? false);

        $payload = [
            'schema_version' => self::REPORT_SCHEMA,
            'ap_contract' => 'AP-747',
            'status' => self::STATUS_READY,
            'mode' => 'operator_owned_release',
            'area_id' => $areaId,
            'release_id' => $releaseId,
            'stack' => 'Atlas Software Company Stewardship Stack',
            'source_ap_contracts' => ['AP-724', 'AP-726', 'AP-747'],
            'target_owner' => (string) ($queueItem['target_owner'] ?? ''),
            'target_runtime_schema' => (string) ($queueItem['target_runtime_schema'] ?? ''),
            'source_refs' => $this->sourceRefs($preflight, $handoff, $receipt),
            'release_receipt' => $this->receiptSummary($receipt),
            'queue_item' => $queueItem,
            'record_release_requested' => $recordRelease,
            'blockers' => [],
            'next_actions' => $this->nextActions($queueItem),
            'reused_owners' => $this->reusedOwners(),
            'claim_policy' => $this->claimPolicy($recordRelease, true),
        ];
        $payload['release_hash'] = 'sha256:'.MissionCanonicalHash::sha256($this->identity($payload));
        $payload['generated_at'] = $this->now();

        return $this->maybeRecord($areaId, $payload, $recordRelease);
    }

    /**
     * @param  array<string,mixed>  $preflight
     * @return list<array<string,mixed>>
     */
    private function readyHandoffs(array $preflight): array
    {
        $out = [];

        if (is_array($preflight['handoff_packet'] ?? null)) {
            $packet = $preflight['handoff_packet'];
            $route = (string) ($packet['route'] ?? '');
            if (in_array($route, [AreaFocusDevForgeRouterService::ROUTE_ATLAS_DEV, AreaFocusDevForgeRouterService::ROUTE_FORGE], true)) {
                $out[] = $this->normalizedHandoff($preflight, $packet, is_array($preflight['branch_plan'] ?? null) ? $preflight['branch_plan'] : []);
            }
        }

        foreach ((array) ($preflight['handoffs'] ?? []) as $handoff) {
            if (! is_array($handoff)) {
                continue;
            }
            if ((string) ($handoff['handoff_status'] ?? '') !== AreaFocusBranchSandboxHandoffService::HO_READY) {
                continue;
            }
            $route = (string) ($handoff['route'] ?? '');
            if (! in_array($route, [AreaFocusDevForgeRouterService::ROUTE_ATLAS_DEV, AreaFocusDevForgeRouterService::ROUTE_FORGE], true)) {
                continue;
            }
            $out[] = $this->normalizedHandoff($preflight, $handoff, is_array($handoff['branch_plan'] ?? null) ? $handoff['branch_plan'] : []);
        }

        return $out;
    }

    /**
     * @param  array<string,mixed>  $preflight
     * @param  array<string,mixed>  $handoff
     * @param  array<string,mixed>  $branchPlan
     * @return array<string,mixed>
     */
    private function normalizedHandoff(array $preflight, array $handoff, array $branchPlan): array
    {
        $hash = (string) ($handoff['handoff_hash'] ?? $preflight['preflight_hash'] ?? $preflight['report_hash'] ?? '');
        if ($hash === '') {
            $hash = 'sha256:'.MissionCanonicalHash::sha256([$handoff, $branchPlan]);
        }

        return [
            'handoff_hash' => $hash,
            'handoff_id' => (string) ($handoff['handoff_id'] ?? $handoff['work_order_id'] ?? ''),
            'route' => (string) ($handoff['route'] ?? ''),
            'target_owner' => (string) ($handoff['target_owner'] ?? ''),
            'work_order_id' => (string) ($handoff['work_order_id'] ?? ''),
            'work_order_hash' => (string) ($handoff['work_order_hash'] ?? ''),
            'finding_hash' => (string) ($handoff['finding_hash'] ?? ''),
            'decision_id' => (string) ($handoff['decision_id'] ?? data_get($handoff, 'operator_receipt.decision_id', '')),
            'decision_hash' => (string) ($handoff['decision_hash'] ?? data_get($handoff, 'operator_receipt.decision_hash', '')),
            'title' => (string) ($handoff['title'] ?? 'Area Focus owner queue item'),
            'risk_level' => (string) ($handoff['risk_level'] ?? 'medium'),
            'recommended_action' => (string) ($handoff['recommended_action'] ?? ''),
            'evidence_refs' => array_values(array_filter((array) ($handoff['evidence_refs'] ?? []), 'is_string')),
            'required_validations' => array_values(array_filter((array) ($handoff['required_validations'] ?? ['tests', 'docs-health', 'architecture-validate']), 'is_string')),
            'branch_plan' => $branchPlan,
        ];
    }

    /**
     * @param  array<string,mixed>  $handoff
     * @param  array<string,mixed>  $receipt
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function queueItem(string $areaId, array $handoff, array $receipt, array $input): array
    {
        $route = (string) ($handoff['route'] ?? '');

        return $route === AreaFocusDevForgeRouterService::ROUTE_FORGE
            ? $this->forgeQueueItem($areaId, $handoff, $receipt, $input)
            : $this->devQueueItem($areaId, $handoff, $receipt, $input);
    }

    /**
     * @param  array<string,mixed>  $handoff
     * @param  array<string,mixed>  $receipt
     * @return array<string,mixed>
     */
    private function devQueueItem(string $areaId, array $handoff, array $receipt, array $input): array
    {
        $workspace = trim((string) ($input['workspace'] ?? data_get($handoff, 'branch_plan.repo', 'atlas-server'))) ?: 'atlas-server';
        $allowedPaths = $this->stringList(data_get($handoff, 'branch_plan.allowed_paths', []));
        $forbiddenPaths = $this->stringList(data_get($handoff, 'branch_plan.forbidden_paths', []));

        $payload = [
            'atlas_mode' => 'programming',
            'mode' => 'programming',
            'task' => 'dev',
            'workspace' => $workspace,
            'flow_id' => AtlasDevRuntimeService::FLOW_DEV,
            'risk_band' => (string) ($handoff['risk_level'] ?? 'medium'),
            'input_text' => $this->objective($handoff),
            'expected_files' => $allowedPaths,
            'suggested_tests' => $this->stringList($handoff['required_validations'] ?? []),
            'acceptance_criteria' => $this->acceptanceCriteria($handoff),
            'artifact_agent_packet' => [
                'schema_version' => 'atlas.area_focus.dev_artifact_agent_packet.v1',
                'allowed_paths' => $allowedPaths,
                'forbidden_paths' => $forbiddenPaths,
                'context_refs' => $this->contextRefs($handoff),
                'done_when' => $this->acceptanceCriteria($handoff),
                'test_plan' => $this->stringList($handoff['required_validations'] ?? []),
            ],
        ];

        return [
            'schema_version' => self::DEV_QUEUE_SCHEMA,
            'target_owner' => 'atlas_dev',
            'target_runtime_schema' => AtlasDevRuntimeService::SCHEMA_VERSION,
            'target_runtime_service' => AtlasDevRuntimeService::class,
            'queue_item_id' => $this->queueItemId($areaId, $handoff, $receipt),
            'area_id' => $areaId,
            'work_order_id' => (string) ($handoff['work_order_id'] ?? ''),
            'handoff_hash' => (string) ($handoff['handoff_hash'] ?? ''),
            'release_receipt_hash' => (string) ($receipt['release_hash'] ?? $receipt['receipt_hash'] ?? ''),
            'dev_runtime_payload' => $payload,
            'queued_for_real_owner' => true,
            'runtime_execution_started' => false,
            'provider_invoked' => false,
            'branch_created' => false,
            'merge_performed' => false,
            'deploy_performed' => false,
            'secret_access' => false,
        ];
    }

    /**
     * @param  array<string,mixed>  $handoff
     * @param  array<string,mixed>  $receipt
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function forgeQueueItem(string $areaId, array $handoff, array $receipt, array $input): array
    {
        $allowedPaths = $this->stringList(data_get($handoff, 'branch_plan.allowed_paths', []));
        $ticket = [
            'ticket_id' => $this->queueItemId($areaId, $handoff, $receipt),
            'locked_paths' => $allowedPaths !== [] ? $allowedPaths : ['app/Services/Ai/SoftwareCompanyStewardship'],
            'priority' => $this->priority((string) ($handoff['risk_level'] ?? 'medium')),
        ];
        $agents = is_array($input['forge_agents'] ?? null) ? array_values(array_filter($input['forge_agents'], 'is_array')) : [
            ['agent_id' => 'forge_area_focus_primary', 'available' => true],
        ];
        $existing = is_array($input['existing_reservations'] ?? null) ? array_values(array_filter($input['existing_reservations'], 'is_array')) : [];
        $proposal = $this->forgeCoordinator->propose([$ticket], $agents, $existing);

        return [
            'schema_version' => self::FORGE_QUEUE_SCHEMA,
            'target_owner' => 'forge',
            'target_runtime_schema' => AtlasForgeParallelDurableCoordinatorService::SCHEMA_VERSION,
            'target_runtime_service' => AtlasForgeParallelDurableCoordinatorService::class,
            'queue_item_id' => $ticket['ticket_id'],
            'area_id' => $areaId,
            'work_order_id' => (string) ($handoff['work_order_id'] ?? ''),
            'handoff_hash' => (string) ($handoff['handoff_hash'] ?? ''),
            'release_receipt_hash' => (string) ($receipt['release_hash'] ?? $receipt['receipt_hash'] ?? ''),
            'forge_ticket' => $ticket,
            'forge_parallel_durable_proposal' => $proposal,
            'queued_for_real_owner' => true,
            'runtime_execution_started' => false,
            'provider_invoked' => false,
            'branch_created' => false,
            'merge_performed' => false,
            'deploy_performed' => false,
            'secret_access' => false,
        ];
    }

    /**
     * @param  array<string,mixed>  $receipt
     * @return array{reason:string,detail:string}|null
     */
    private function releaseReceiptCheck(array $receipt): ?array
    {
        if ($receipt === []) {
            return ['reason' => 'release_receipt_required', 'detail' => 'An explicit AP-747 operator release receipt is required.'];
        }
        if ((string) ($receipt['decision'] ?? '') !== 'release') {
            return ['reason' => 'release_decision_required', 'detail' => 'Release receipt decision must be release.'];
        }
        if ($this->targetHash($receipt) === '') {
            return ['reason' => 'target_handoff_hash_required', 'detail' => 'Release receipt must name target_handoff_hash or target_hash.'];
        }
        if (trim((string) ($receipt['operator_actor'] ?? '')) === '') {
            return ['reason' => 'operator_actor_required', 'detail' => 'Release receipt must name the operator actor.'];
        }

        return null;
    }

    /**
     * @param  list<array<string,mixed>>  $handoffs
     * @return array<string,mixed>|null
     */
    private function matchingHandoff(array $handoffs, string $targetHash): ?array
    {
        foreach ($handoffs as $handoff) {
            if ((string) ($handoff['handoff_hash'] ?? '') === $targetHash) {
                return $handoff;
            }
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $receipt
     */
    private function targetHash(array $receipt): string
    {
        return trim((string) ($receipt['target_handoff_hash'] ?? $receipt['target_hash'] ?? ''));
    }

    /**
     * @param  array<string,mixed>  $handoff
     * @param  array<string,mixed>  $receipt
     */
    private function releaseId(string $areaId, array $handoff, array $receipt): string
    {
        $explicit = trim((string) ($receipt['release_id'] ?? ''));
        if ($explicit !== '') {
            return $explicit;
        }

        return 'afrel_'.substr(MissionCanonicalHash::sha256([
            'ap' => 'AP-747',
            'area_id' => $areaId,
            'handoff_hash' => (string) ($handoff['handoff_hash'] ?? ''),
            'operator_actor' => (string) ($receipt['operator_actor'] ?? ''),
        ]), 0, 18);
    }

    /**
     * @param  array<string,mixed>  $handoff
     * @param  array<string,mixed>  $receipt
     */
    private function queueItemId(string $areaId, array $handoff, array $receipt): string
    {
        return 'afq_'.substr(MissionCanonicalHash::sha256([
            $areaId,
            (string) ($handoff['handoff_hash'] ?? ''),
            (string) ($receipt['operator_actor'] ?? ''),
        ]), 0, 18);
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function maybeRecord(string $areaId, array $payload, bool $record): array
    {
        if (! $record) {
            return $payload + ['release_storage_status' => 'projected'];
        }

        $path = $this->releaseFilePath($areaId);
        File::ensureDirectoryExists(dirname($path));
        $existing = $this->findRecord($path, (string) ($payload['release_id'] ?? ''));
        if ($existing !== null) {
            return $existing + ['release_storage_status' => 'existing'];
        }

        $recordPayload = [
            'schema_version' => self::RECORD_SCHEMA,
            'recorded_at' => $this->now(),
        ] + $payload;
        $recordPayload['status'] = self::STATUS_RECORDED;
        File::append($path, json_encode($recordPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL);

        return $recordPayload + ['release_storage_status' => 'recorded'];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function findRecord(string $path, string $releaseId): ?array
    {
        if ($releaseId === '' || ! is_file($path)) {
            return null;
        }
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded) && (string) ($decoded['release_id'] ?? '') === $releaseId) {
                return $decoded;
            }
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $preflight
     * @param  array<string,mixed>  $extra
     * @return array<string,mixed>
     */
    private function blocked(string $areaId, string $reason, string $detail, array $preflight = [], array $extra = []): array
    {
        $payload = [
            'schema_version' => self::REPORT_SCHEMA,
            'ap_contract' => 'AP-747',
            'status' => self::STATUS_BLOCKED,
            'mode' => 'operator_owned_release',
            'area_id' => $areaId,
            'reason' => $reason,
            'detail' => $detail,
            'source_ap_contracts' => ['AP-726', 'AP-747'],
            'source_refs' => [
                'preflight_hash' => (string) ($preflight['preflight_hash'] ?? ''),
                'handoff_report_hash' => (string) ($preflight['report_hash'] ?? ''),
                'preflight_status' => (string) ($preflight['status'] ?? ''),
            ],
            'blockers' => [$reason],
            'next_actions' => ['Resolve AP-747 blocker before releasing any AP-726 handoff to Atlas Dev or Forge.'],
            'reused_owners' => $this->reusedOwners(),
            'claim_policy' => $this->claimPolicy(false, false),
        ] + $extra;
        $payload['release_hash'] = 'sha256:'.MissionCanonicalHash::sha256($payload);
        $payload['generated_at'] = $this->now();

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $preflight
     * @param  array<string,mixed>  $handoff
     * @param  array<string,mixed>  $receipt
     * @return array<string,string>
     */
    private function sourceRefs(array $preflight, array $handoff, array $receipt): array
    {
        return [
            'preflight_hash' => (string) ($preflight['preflight_hash'] ?? ''),
            'handoff_report_hash' => (string) ($preflight['report_hash'] ?? ''),
            'handoff_hash' => (string) ($handoff['handoff_hash'] ?? ''),
            'handoff_id' => (string) ($handoff['handoff_id'] ?? ''),
            'work_order_id' => (string) ($handoff['work_order_id'] ?? ''),
            'work_order_hash' => (string) ($handoff['work_order_hash'] ?? ''),
            'decision_id' => (string) ($handoff['decision_id'] ?? ''),
            'release_receipt_id' => (string) ($receipt['release_id'] ?? ''),
        ];
    }

    /**
     * @param  array<string,mixed>  $receipt
     * @return array<string,string>
     */
    private function receiptSummary(array $receipt): array
    {
        return [
            'decision' => (string) ($receipt['decision'] ?? ''),
            'operator_actor' => (string) ($receipt['operator_actor'] ?? ''),
            'target_handoff_hash' => $this->targetHash($receipt),
            'release_id' => (string) ($receipt['release_id'] ?? ''),
            'release_hash' => (string) ($receipt['release_hash'] ?? $receipt['receipt_hash'] ?? ''),
        ];
    }

    /**
     * @param  array<string,mixed>  $handoff
     * @return list<string>
     */
    private function acceptanceCriteria(array $handoff): array
    {
        $criteria = [
            'Stay inside the AP-726 branch sandbox allowed paths.',
            'Return diff or explicit no-op reason.',
            'Run or justify required validations.',
            'Produce evidence pack and operator review summary.',
        ];
        foreach ($this->stringList($handoff['required_validations'] ?? []) as $validation) {
            $criteria[] = 'Validation required: '.$validation;
        }

        return array_values(array_unique($criteria));
    }

    /**
     * @param  array<string,mixed>  $handoff
     */
    private function objective(array $handoff): string
    {
        $title = trim((string) ($handoff['title'] ?? 'Area Focus work order'));
        $action = trim((string) ($handoff['recommended_action'] ?? ''));

        return $action !== '' ? "{$title}. {$action}" : $title;
    }

    /**
     * @param  array<string,mixed>  $handoff
     * @return list<string>
     */
    private function contextRefs(array $handoff): array
    {
        $refs = ['ap:AP-726', 'ap:AP-747', 'doc:docs/engineering-knowledge-base/atlas-software-company-stewardship-stack.md'];
        foreach ($this->stringList($handoff['evidence_refs'] ?? []) as $ref) {
            $refs[] = 'evidence:'.$ref;
        }

        return array_values(array_unique($refs));
    }

    /**
     * @param  array<string,mixed>  $queueItem
     * @return list<string>
     */
    private function nextActions(array $queueItem): array
    {
        $owner = (string) ($queueItem['target_owner'] ?? 'owner');

        return [
            "Review the recorded {$owner} queue item in Product Mode before starting runtime execution.",
            'Runtime execution still requires the owner-specific Dev/Forge execution gate.',
            'Merge, deploy, external push, secrets and destructive changes remain blocked without explicit operator approval.',
        ];
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function reusedOwners(): array
    {
        return [
            'branch_sandbox_preflight' => ['ap' => 'AP-726', 'owner_service' => AreaFocusBranchSandboxPreflightService::class],
            'branch_sandbox_handoff' => ['ap' => 'AP-726', 'owner_service' => AreaFocusBranchSandboxHandoffService::class],
            'operator_decision' => ['ap' => 'AP-724/AP-747', 'role' => 'explicit human release receipt'],
            'atlas_dev' => ['runtime_schema' => AtlasDevRuntimeService::SCHEMA_VERSION, 'owner_service' => AtlasDevRuntimeService::class],
            'forge' => ['runtime_schema' => AtlasForgeParallelDurableCoordinatorService::SCHEMA_VERSION, 'owner_service' => AtlasForgeParallelDurableCoordinatorService::class],
            'product_mode' => ['ap' => 'AP-739', 'role' => 'review surface and controls'],
        ];
    }

    /**
     * @return array<string,bool|string>
     */
    private function claimPolicy(bool $records, bool $released): array
    {
        return [
            'mode' => 'operator_owned_release',
            'requires_operator_release_receipt' => true,
            'release_queue_recorded_when_requested' => $records,
            'released_to_dev_or_forge_queue' => $released,
            'runtime_execution_started' => false,
            'provider_invoked' => false,
            'branch_created' => false,
            'worktree_created' => false,
            'target_repo_mutated' => false,
            'merge_performed' => false,
            'deploy_performed' => false,
            'pushed_external' => false,
            'secret_access' => false,
            'destructive_change' => false,
            'auto_approved' => false,
            'parallel_runtime_created' => false,
            'new_os_created' => false,
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function identity(array $payload): array
    {
        $copy = $payload;
        unset($copy['generated_at'], $copy['release_hash'], $copy['release_storage_status'], $copy['recorded_at']);

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

    private function priority(string $risk): int
    {
        return match ($risk) {
            'critical' => 100,
            'high' => 80,
            'medium' => 50,
            'low' => 25,
            default => 40,
        };
    }

    private function slug(string $value): string
    {
        $slug = strtolower(trim($value));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? $slug;

        return trim($slug, '-') ?: 'area';
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);
    }
}
