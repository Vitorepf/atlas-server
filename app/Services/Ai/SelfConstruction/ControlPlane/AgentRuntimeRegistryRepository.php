<?php

namespace App\Services\Ai\SelfConstruction\ControlPlane;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Throwable;
use App\Services\Ai\SelfConstruction\Support\EncodesPayloadAsPrettyJson;
use App\Services\Ai\SelfConstruction\Support\HashesPayloadCanonically;

/**
 * Persistent local registry of Agent Control Plane runtime agents.
 *
 * Persists agent records under the canonical storage prefix
 * `atlas/self-construction/agent-control-plane/agent-registry/` with an
 * indexed registry. Operations are idempotent on `agent_id` and use a
 * coarse advisory lock file to avoid partial writes.
 *
 * Runtime-safe: never starts processes, never calls Codex CLI/app, never
 * spawns subprocesses, never invokes adapters, never dispatches work,
 * never spends tokens, never advances the next required slice, never
 * enables self-programming and never writes the evidence ledger.
 */
final class AgentRuntimeRegistryRepository
{
    use AgentRuntimeRegistryStorageConcerns;

    use HashesPayloadCanonically;
    use EncodesPayloadAsPrettyJson;
    public const SCHEMA_VERSION = 'atlas.self_construction.agent_runtime_registry.v1';

    public const MODE = 'persistent_local_agent_runtime_registry';

    public const STORAGE_PREFIX = 'atlas/self-construction/agent-control-plane/agent-registry';

    public const REGISTRY_PATH = self::STORAGE_PREFIX.'/registry.json';

    public const LOCK_PATH = self::STORAGE_PREFIX.'/.lock';

    public const DEFAULT_DISK = 'local';

    public const DEFAULT_REGISTRY_CAP = 500;

    public const STATUSES = [
        'registered',
        'available',
        'busy',
        'stale',
        'quarantined',
        'disabled',
        'unregistered',
    ];

    public const KINDS = [
        'codex',
        'claude',
        'human_operator',
        'local_worker',
        'dry_run_agent',
    ];

    // AC4: redact any metadata key whose name suggests a raw transcript or a secret-like value.
    // Case-insensitive substring match — deliberately broad so callers don't need to enumerate
    // every possible secret field name.
    private const SENSITIVE_METADATA_KEY_SUBSTRINGS = [
        'secret', 'password', 'token', 'api_key', 'apikey', 'private_key',
        'raw_prompt', 'authorization', 'credential', 'provider_trace',
    ];

    public function __construct(
        private readonly ?string $disk = null,
    ) {}

    /**
     * Register an agent. Idempotent on `agent_id`:
     * - same id + identical payload → returns existing record with `idempotent: true`
     * - same id + different payload → status update with history entry
     *
     * @param  array<string, mixed>  $agent
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function register(array $agent, array $options = []): array
    {
        return $this->withLock(function () use ($agent, $options): array {
            $agentId = (string) ($agent['agent_id'] ?? '');
            if (! $this->isValidAgentId($agentId)) {
                return $this->envelopeError('invalid_agent_id', $agentId, [
                    'requested_agent_id' => $agentId,
                ]);
            }

            $kind = (string) ($agent['kind'] ?? '');
            if (! in_array($kind, self::KINDS, true)) {
                return $this->envelopeError('invalid_kind', $agentId, [
                    'requested_kind' => $kind,
                    'allowed_kinds' => self::KINDS,
                ]);
            }

            $requestedStatus = (string) ($agent['status'] ?? 'registered');
            if (! in_array($requestedStatus, self::STATUSES, true)) {
                return $this->envelopeError('invalid_status', $agentId, [
                    'requested_status' => $requestedStatus,
                    'allowed_statuses' => self::STATUSES,
                ]);
            }

            $maxParallelTasks = max(1, (int) ($agent['max_parallel_tasks'] ?? 1));
            $currentTaskCount = max(0, (int) ($agent['current_task_count'] ?? 0));
            if ($currentTaskCount > $maxParallelTasks) {
                $currentTaskCount = $maxParallelTasks;
            }

            $capabilities = $this->normalizeCapabilities((array) ($agent['capabilities'] ?? []));
            $surfaces = $this->normalizeStringList((array) ($agent['surfaces'] ?? []));
            $projectLane = trim((string) ($agent['project_lane'] ?? ''));
            $runtimeClass = trim((string) ($agent['runtime_class'] ?? ''));

            $existing = $this->readAgentFile($agentId);
            $now = CarbonImmutable::now()->toIso8601String();

            $redaction = $this->redactMetadata((array) ($options['metadata'] ?? ($agent['metadata'] ?? [])));

            $record = [
                'schema_version' => self::SCHEMA_VERSION,
                'agent_id' => $agentId,
                'label' => (string) ($agent['label'] ?? $agentId),
                'kind' => $kind,
                'status' => $requestedStatus,
                'capabilities' => $capabilities,
                'surfaces' => $surfaces,
                'project_lane' => $projectLane,
                'runtime_class' => $runtimeClass,
                'max_parallel_tasks' => $maxParallelTasks,
                'current_task_count' => $currentTaskCount,
                'heartbeat_required' => (bool) ($agent['heartbeat_required'] ?? true),
                'lease_supported' => (bool) ($agent['lease_supported'] ?? false),
                'workspace_isolation_supported' => (bool) ($agent['workspace_isolation_supported'] ?? false),
                'cost_meter_supported' => (bool) ($agent['cost_meter_supported'] ?? false),
                'continuation_summary_supported' => (bool) ($agent['continuation_summary_supported'] ?? false),
                'evidence_required' => (bool) ($agent['evidence_required'] ?? true),
                'metadata' => $redaction['metadata'],
                'metadata_redacted_fields' => $redaction['redacted_fields'],
                'tags' => $this->normalizeStringList((array) ($options['tags'] ?? ($agent['tags'] ?? []))),
                'updated_at' => $now,
                'runtime_execution_allowed' => false,
                'provider_call_allowed' => false,
                'token_spend_allowed' => false,
                'dispatch_allowed' => false,
                'self_programming_allowed' => false,
                'receipts' => [],
                'history' => [],
            ];

            if ($existing !== null && ! (bool) ($existing['corrupt'] ?? false)) {
                $signatureExisting = $this->signature($existing);
                $signatureIncoming = $this->signature($record);
                $record['registered_at'] = (string) ($existing['registered_at'] ?? $now);
                $record['history'] = (array) ($existing['history'] ?? []);
                $record['receipts'] = (array) ($existing['receipts'] ?? []);

                if ($signatureExisting === $signatureIncoming) {
                    $existing['updated_at'] = $now;

                    return $this->envelopeOk('idempotent_register', $existing, [
                        'idempotent' => true,
                    ]);
                }

                $record['history'][] = [
                    'event' => 'updated',
                    'at' => $now,
                    'previous_status' => (string) ($existing['status'] ?? ''),
                    'new_status' => $requestedStatus,
                ];
            } else {
                $record['registered_at'] = $now;
                $record['history'][] = [
                    'event' => 'registered',
                    'at' => $now,
                    'status' => $requestedStatus,
                ];
            }

            $this->writeAgentFile($agentId, $record);
            $this->registerInRegistry($record);

            return $this->envelopeOk('registered', $record);
        });
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(string $agentId): ?array
    {
        if (! $this->isValidAgentId($agentId)) {
            return null;
        }

        return $this->readAgentFile($agentId);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    public function list(array $filters = []): array
    {
        $registry = $this->loadRegistry();
        $entries = (array) ($registry['entries'] ?? []);
        $status = isset($filters['status']) ? (string) $filters['status'] : '';
        $kind = isset($filters['kind']) ? (string) $filters['kind'] : '';
        $capability = isset($filters['capability']) ? (string) $filters['capability'] : '';
        $tag = isset($filters['tag']) ? (string) $filters['tag'] : '';
        $projectLane = isset($filters['project_lane']) ? (string) $filters['project_lane'] : '';
        $runtimeClass = isset($filters['runtime_class']) ? (string) $filters['runtime_class'] : '';
        $limit = isset($filters['limit']) ? (int) $filters['limit'] : 0;

        $results = [];
        foreach ($entries as $entry) {
            if ($status !== '' && (string) ($entry['status'] ?? '') !== $status) {
                continue;
            }
            if ($kind !== '' && (string) ($entry['kind'] ?? '') !== $kind) {
                continue;
            }
            if ($capability !== '') {
                $caps = (array) ($entry['capabilities'] ?? []);
                if (! in_array($capability, $caps, true)) {
                    continue;
                }
            }
            if ($tag !== '') {
                $tags = (array) ($entry['tags'] ?? []);
                if (! in_array($tag, $tags, true)) {
                    continue;
                }
            }
            if ($projectLane !== '' && (string) ($entry['project_lane'] ?? '') !== $projectLane) {
                continue;
            }
            if ($runtimeClass !== '' && (string) ($entry['runtime_class'] ?? '') !== $runtimeClass) {
                continue;
            }
            $agentId = (string) ($entry['agent_id'] ?? '');
            $record = $this->readAgentFile($agentId);
            if ($record === null) {
                continue;
            }
            $results[] = $record;
            if ($limit > 0 && count($results) >= $limit) {
                break;
            }
        }

        return $results;
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    public function updateStatus(string $agentId, string $status, array $metadata = []): array
    {
        return $this->withLock(function () use ($agentId, $status, $metadata): array {
            if (! $this->isValidAgentId($agentId)) {
                return $this->envelopeError('invalid_agent_id', $agentId);
            }

            if (! in_array($status, self::STATUSES, true)) {
                return $this->envelopeError('invalid_status', $agentId, [
                    'requested_status' => $status,
                    'allowed_statuses' => self::STATUSES,
                ]);
            }

            $record = $this->readAgentFile($agentId);
            if ($record === null) {
                return $this->envelopeError('agent_not_found', $agentId);
            }
            if ((bool) ($record['corrupt'] ?? false)) {
                return $this->envelopeError('agent_record_corrupt', $agentId, [
                    'corrupt_reason' => (string) ($record['corrupt_reason'] ?? 'unknown'),
                ]);
            }

            $now = CarbonImmutable::now()->toIso8601String();
            $previous = (string) ($record['status'] ?? '');
            $record['status'] = $status;
            $record['updated_at'] = $now;
            $record['history'][] = [
                'event' => 'status_changed',
                'at' => $now,
                'from' => $previous,
                'to' => $status,
                'metadata' => $metadata,
            ];
            if ($metadata !== []) {
                $redaction = $this->redactMetadata(array_merge((array) ($record['metadata'] ?? []), $metadata));
                $record['metadata'] = $redaction['metadata'];
                $record['metadata_redacted_fields'] = array_values(array_unique(array_merge(
                    (array) ($record['metadata_redacted_fields'] ?? []),
                    $redaction['redacted_fields'],
                )));
                sort($record['metadata_redacted_fields'], SORT_STRING);
            }

            $this->writeAgentFile($agentId, $record);
            $this->updateRegistryEntry($agentId, $record);

            return $this->envelopeOk('status_updated', $record);
        });
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function unregister(string $agentId, array $options = []): array
    {
        return $this->withLock(function () use ($agentId, $options): array {
            if (! $this->isValidAgentId($agentId)) {
                return $this->envelopeError('invalid_agent_id', $agentId);
            }
            $record = $this->readAgentFile($agentId);
            if ($record === null) {
                return $this->envelopeError('agent_not_found', $agentId);
            }
            $now = CarbonImmutable::now()->toIso8601String();
            $previous = (string) ($record['status'] ?? '');
            $record['status'] = 'unregistered';
            $record['updated_at'] = $now;
            $record['unregistered_at'] = $now;
            $record['history'][] = [
                'event' => 'unregistered',
                'at' => $now,
                'from' => $previous,
                'reason' => (string) ($options['reason'] ?? 'operator_request'),
            ];

            $this->writeAgentFile($agentId, $record);
            $this->updateRegistryEntry($agentId, $record);

            return $this->envelopeOk('unregistered', $record);
        });
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function registry(array $filters = []): array
    {
        $registry = $this->loadRegistry();
        $entries = (array) ($registry['entries'] ?? []);
        $status = isset($filters['status']) ? (string) $filters['status'] : '';
        $kind = isset($filters['kind']) ? (string) $filters['kind'] : '';
        $cap = isset($filters['cap']) ? (int) $filters['cap'] : self::DEFAULT_REGISTRY_CAP;
        $corrupt = (bool) ($registry['corrupt'] ?? false);

        $filtered = $entries;
        if ($status !== '') {
            $filtered = array_values(array_filter(
                $filtered,
                static fn (array $entry): bool => (string) ($entry['status'] ?? '') === $status,
            ));
        }
        if ($kind !== '') {
            $filtered = array_values(array_filter(
                $filtered,
                static fn (array $entry): bool => (string) ($entry['kind'] ?? '') === $kind,
            ));
        }

        $statusCounts = [];
        $kindCounts = [];
        foreach ($entries as $entry) {
            $sKey = (string) ($entry['status'] ?? 'unknown');
            $kKey = (string) ($entry['kind'] ?? 'unknown');
            $statusCounts[$sKey] = ($statusCounts[$sKey] ?? 0) + 1;
            $kindCounts[$kKey] = ($kindCounts[$kKey] ?? 0) + 1;
        }
        ksort($statusCounts);
        ksort($kindCounts);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'storage_prefix' => self::STORAGE_PREFIX,
            'registry_path' => self::REGISTRY_PATH,
            'entry_count' => count($filtered),
            'total_count' => count($entries),
            'capped_to' => $cap,
            'corrupt' => $corrupt,
            'status_counts' => $statusCounts,
            'kind_counts' => $kindCounts,
            'allowed_statuses' => self::STATUSES,
            'allowed_kinds' => self::KINDS,
            'entries' => $filtered,
            'runtime_execution_allowed' => false,
            'dispatch_allowed' => false,
            'provider_call_allowed' => false,
            'token_spend_allowed' => false,
            'self_programming_allowed' => false,
            'ledger_write_allowed' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $receipt
     * @return array<string, mixed>
     */
    public function appendReceipt(string $agentId, array $receipt): array
    {
        return $this->withLock(function () use ($agentId, $receipt): array {
            if (! $this->isValidAgentId($agentId)) {
                return $this->envelopeError('invalid_agent_id', $agentId);
            }
            $record = $this->readAgentFile($agentId);
            if ($record === null) {
                return $this->envelopeError('agent_not_found', $agentId);
            }

            $now = CarbonImmutable::now()->toIso8601String();
            $receipt['receipt_kind'] = (string) ($receipt['receipt_kind'] ?? 'unknown');
            $receipt['recorded_at'] = $now;
            $receipt['runtime_execution_allowed'] = false;
            $receipt['ledger_write_allowed'] = false;
            $receipt['dispatch_allowed'] = false;
            $receipt['receipt_hash'] = $this->stableHash([
                'agent_id' => $agentId,
                'receipt_kind' => $receipt['receipt_kind'],
                'extra' => array_diff_key($receipt, ['recorded_at' => true, 'receipt_hash' => true]),
            ]);
            $record['receipts'][] = $receipt;
            $record['updated_at'] = $now;

            // AC: maintain a bounded skill_outcome_profile from receipts.
            $taskFamily = (string) ($receipt['task_family'] ?? 'unknown');
            $outcome = (string) ($receipt['outcome'] ?? $receipt['receipt_kind'] ?? 'unknown');
            if (! isset($record['skill_outcome_profile'])) {
                $record['skill_outcome_profile'] = [];
            }
            if (! isset($record['skill_outcome_profile'][$taskFamily])) {
                $record['skill_outcome_profile'][$taskFamily] = [
                    'success' => 0,
                    'give_back' => 0,
                    'weak_green' => 0,
                    'total' => 0,
                ];
            }
            $profileKey = match ($outcome) {
                'success', 'delivered', 'committed' => 'success',
                'give_back' => 'give_back',
                'weak_green' => 'weak_green',
                default => null,
            };
            if ($profileKey !== null) {
                $record['skill_outcome_profile'][$taskFamily][$profileKey]++;
            }
            $record['skill_outcome_profile'][$taskFamily]['total']++;

            // Bound the profile to prevent unbounded growth.
            $record['skill_outcome_profile'] = array_slice($record['skill_outcome_profile'], -50, null, true);

            $record['history'][] = [
                'event' => 'receipt_appended',
                'at' => $now,
                'receipt_kind' => $receipt['receipt_kind'],
                'receipt_hash' => $receipt['receipt_hash'],
            ];

            $this->writeAgentFile($agentId, $record);
            $this->updateRegistryEntry($agentId, $record);

            return $this->envelopeOk('receipt_appended', $record, [
                'receipt' => $receipt,
            ]);
        });
    }

    /** A worker in an active status with no heartbeat for this long is treated as stale evidence, not live capacity. */
    private const HEARTBEAT_STALENESS_CEILING_SECONDS = 300;

    /** Statuses where the worker is expected to be actively reachable. */
    private const ACTIVE_STATUSES = ['available', 'busy'];

    /**
     * Records a heartbeat for one worker. Only touches that worker's own
     * record — never any other agent. If the worker was marked `stale` and
     * is heartbeating again, it is restored to `available` so heartbeat
     * state and status stay consistent.
     *
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    public function heartbeat(string $agentId, array $metadata = []): array
    {
        return $this->withLock(function () use ($agentId, $metadata): array {
            if (! $this->isValidAgentId($agentId)) {
                return $this->envelopeError('invalid_agent_id', $agentId);
            }

            $record = $this->readAgentFile($agentId);
            if ($record === null) {
                return $this->envelopeError('agent_not_found', $agentId);
            }
            if ((bool) ($record['corrupt'] ?? false)) {
                return $this->envelopeError('agent_record_corrupt', $agentId, [
                    'corrupt_reason' => (string) ($record['corrupt_reason'] ?? 'unknown'),
                ]);
            }

            $now = CarbonImmutable::now()->toIso8601String();
            $previousStatus = (string) ($record['status'] ?? '');
            $record['last_heartbeat_at'] = $now;
            $record['updated_at'] = $now;

            if ($previousStatus === 'stale') {
                $record['status'] = 'available';
            }

            $record['history'][] = [
                'event' => 'heartbeat',
                'at' => $now,
                'previous_status' => $previousStatus,
                'status' => $record['status'],
                'metadata' => $metadata,
            ];

            $this->writeAgentFile($agentId, $record);
            $this->updateRegistryEntry($agentId, $record);

            return $this->envelopeOk('heartbeat_recorded', $record);
        });
    }

    /**
     * Compact, read-only consistency check across registration, heartbeat,
     * quarantine and capability state. Never mutates a single worker —
     * every finding carries a repair_hint describing the safe fix instead.
     *
     * @return array<string, mixed>
     */
    public function consistencyCheck(): array
    {
        $registry = $this->loadRegistry();
        $entries = (array) ($registry['entries'] ?? []);
        $now = CarbonImmutable::now();

        $issues = [];
        $checkedCount = 0;

        foreach ($entries as $entry) {
            $agentId = (string) ($entry['agent_id'] ?? '');
            if ($agentId === '') {
                continue;
            }
            $record = $this->readAgentFile($agentId);
            if ($record === null) {
                continue;
            }
            $checkedCount++;

            if ((bool) ($record['corrupt'] ?? false)) {
                $issues[] = $this->issue($agentId, 'corrupt_record', (string) ($record['corrupt_reason'] ?? 'unknown'), 'reread_or_re-register_this_agent_only');

                continue;
            }

            $status = (string) ($record['status'] ?? '');
            $registryStatus = (string) ($entry['status'] ?? '');
            if ($registryStatus !== $status) {
                $issues[] = $this->issue($agentId, 'registry_agent_status_mismatch', "registry={$registryStatus} agent_file={$status}", 'rewrite_registry_entry_from_agent_file_for_this_agent_only');
            }

            $maxParallel = (int) ($record['max_parallel_tasks'] ?? 0);
            $currentTasks = (int) ($record['current_task_count'] ?? 0);
            if ($currentTasks > $maxParallel) {
                $issues[] = $this->issue($agentId, 'task_count_exceeds_capacity', "current={$currentTasks} max={$maxParallel}", 'clamp_current_task_count_to_max_parallel_tasks_for_this_agent_only');
            }

            $heartbeatRequired = (bool) ($record['heartbeat_required'] ?? true);
            $lastHeartbeatAt = $record['last_heartbeat_at'] ?? null;
            if ($heartbeatRequired && in_array($status, self::ACTIVE_STATUSES, true)) {
                if ($lastHeartbeatAt === null) {
                    $issues[] = $this->issue($agentId, 'active_status_missing_heartbeat', "status={$status}", 'require_heartbeat_or_transition_this_agent_to_stale');
                } else {
                    try {
                        $ageSeconds = abs($now->diffInSeconds(CarbonImmutable::parse((string) $lastHeartbeatAt)));
                    } catch (\Throwable $e) {
                        $ageSeconds = 0;
                    }
                    if ($ageSeconds > self::HEARTBEAT_STALENESS_CEILING_SECONDS) {
                        $issues[] = $this->issue($agentId, 'stale_heartbeat_with_active_status', sprintf('status=%s age_seconds=%d', $status, (int) $ageSeconds), 'transition_this_agent_to_stale_until_next_heartbeat');
                    }
                }
            }

            if ($status === 'quarantined' && $currentTasks > 0) {
                $issues[] = $this->issue($agentId, 'quarantined_with_active_task_count', "current_task_count={$currentTasks}", 'drain_or_reassign_this_agent_tasks_before_quarantine_completes');
            }
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'checked_count' => $checkedCount,
            'inconsistent_count' => count($issues),
            'consistent_count' => $checkedCount - count(array_unique(array_column($issues, 'agent_id'))),
            'consistent' => $issues === [],
            'issues' => $issues,
            'runtime_execution_allowed' => false,
            'dispatch_allowed' => false,
            'provider_call_allowed' => false,
            'token_spend_allowed' => false,
            'self_programming_allowed' => false,
            'ledger_write_allowed' => false,
        ];
    }

    /** @return array<string, string> */
    private function issue(string $agentId, string $issueType, string $detail, string $repairHint): array
    {
        return [
            'agent_id' => $agentId,
            'issue_type' => $issueType,
            'detail' => $detail,
            'repair_hint' => $repairHint,
        ];
    }

    /**
     * @return array<string, bool>
     */
    public function runtimeFlags(): array
    {
        return [
            'runtime_execution_allowed' => false,
            'dispatch_allowed' => false,
            'provider_call_allowed' => false,
            'token_spend_allowed' => false,
            'self_programming_allowed' => false,
            'ledger_write_allowed' => false,
        ];
    }

    /**
     * AC4: strips any metadata key whose name looks like a raw provider transcript or a
     * secret-like value, before it is ever persisted. Capability facts (capabilities, surfaces,
     * tags, project_lane, runtime_class) and receipts are never touched — only the free-form
     * `metadata` bag is filtered.
     *
     * @param  array<string, mixed>  $metadata
     * @return array{metadata: array<string, mixed>, redacted_fields: list<string>}
     */
    private function redactMetadata(array $metadata): array
    {
        $clean = [];
        $redactedFields = [];
        foreach ($metadata as $key => $value) {
            $keyLower = strtolower((string) $key);
            $isSensitive = false;
            foreach (self::SENSITIVE_METADATA_KEY_SUBSTRINGS as $needle) {
                if (str_contains($keyLower, $needle)) {
                    $isSensitive = true;
                    break;
                }
            }
            if ($isSensitive) {
                $redactedFields[] = (string) $key;

                continue;
            }
            $clean[$key] = $value;
        }
        sort($redactedFields, SORT_STRING);

        return ['metadata' => $clean, 'redacted_fields' => $redactedFields];
    }

    /**
     * @param  array<int, mixed>  $capabilities
     * @return list<string>
     */
    private function normalizeCapabilities(array $capabilities): array
    {
        $normalized = [];
        foreach ($capabilities as $capability) {
            $value = trim((string) $capability);
            if ($value === '') {
                continue;
            }
            $normalized[strtolower($value)] = true;
        }
        $keys = array_keys($normalized);
        sort($keys);

        return array_values($keys);
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function signature(array $record): string
    {
        $signature = [
            'agent_id' => (string) ($record['agent_id'] ?? ''),
            'label' => (string) ($record['label'] ?? ''),
            'kind' => (string) ($record['kind'] ?? ''),
            'status' => (string) ($record['status'] ?? ''),
            'capabilities' => (array) ($record['capabilities'] ?? []),
            'surfaces' => (array) ($record['surfaces'] ?? []),
            'project_lane' => (string) ($record['project_lane'] ?? ''),
            'runtime_class' => (string) ($record['runtime_class'] ?? ''),
            'max_parallel_tasks' => (int) ($record['max_parallel_tasks'] ?? 0),
            'current_task_count' => (int) ($record['current_task_count'] ?? 0),
            'heartbeat_required' => (bool) ($record['heartbeat_required'] ?? true),
            'lease_supported' => (bool) ($record['lease_supported'] ?? false),
            'workspace_isolation_supported' => (bool) ($record['workspace_isolation_supported'] ?? false),
            'cost_meter_supported' => (bool) ($record['cost_meter_supported'] ?? false),
            'continuation_summary_supported' => (bool) ($record['continuation_summary_supported'] ?? false),
            'evidence_required' => (bool) ($record['evidence_required'] ?? true),
        ];

        return $this->stableHash($signature);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readAgentFile(string $agentId): ?array
    {
        if (! $this->isValidAgentId($agentId)) {
            return null;
        }
        $path = $this->agentPath($agentId);
        $disk = $this->disk();
        if (! $disk->exists($path)) {
            return null;
        }
        $raw = (string) $disk->get($path);
        try {
            $decoded = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return [
                'agent_id' => $agentId,
                'schema_version' => self::SCHEMA_VERSION,
                'corrupt' => true,
                'corrupt_reason' => 'invalid_json',
            ];
        }
        if (! is_array($decoded)) {
            return [
                'agent_id' => $agentId,
                'schema_version' => self::SCHEMA_VERSION,
                'corrupt' => true,
                'corrupt_reason' => 'non_array_payload',
            ];
        }

        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function writeAgentFile(string $agentId, array $record): void
    {
        $this->disk()->put($this->agentPath($agentId), $this->encode($record));
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function registerInRegistry(array $record): void
    {
        $registry = $this->loadRegistry();
        $entries = (array) ($registry['entries'] ?? []);
        $agentId = (string) ($record['agent_id'] ?? '');
        $entryPayload = $this->registryEntryPayload($record);
        $found = false;
        foreach ($entries as $i => $entry) {
            if ((string) ($entry['agent_id'] ?? '') === $agentId) {
                $entries[$i] = $entryPayload;
                $found = true;
                break;
            }
        }
        if (! $found) {
            $entries[] = $entryPayload;
        }
        $registry['entries'] = $entries;
        $registry = $this->capRegistry($registry, self::DEFAULT_REGISTRY_CAP);
        $this->saveRegistry($registry);
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function updateRegistryEntry(string $agentId, array $record): void
    {
        $registry = $this->loadRegistry();
        $entries = (array) ($registry['entries'] ?? []);
        $entryPayload = $this->registryEntryPayload($record);
        $found = false;
        foreach ($entries as $i => $entry) {
            if ((string) ($entry['agent_id'] ?? '') === $agentId) {
                $entries[$i] = $entryPayload;
                $found = true;
                break;
            }
        }
        if (! $found) {
            $entries[] = $entryPayload;
        }
        $registry['entries'] = $entries;
        $this->saveRegistry($registry);
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    private function registryEntryPayload(array $record): array
    {
        return [
            'agent_id' => (string) ($record['agent_id'] ?? ''),
            'label' => (string) ($record['label'] ?? ''),
            'kind' => (string) ($record['kind'] ?? ''),
            'status' => (string) ($record['status'] ?? ''),
            'capabilities' => (array) ($record['capabilities'] ?? []),
            'tags' => (array) ($record['tags'] ?? []),
            'project_lane' => (string) ($record['project_lane'] ?? ''),
            'runtime_class' => (string) ($record['runtime_class'] ?? ''),
            'registered_at' => (string) ($record['registered_at'] ?? ''),
            'updated_at' => (string) ($record['updated_at'] ?? ''),
            'max_parallel_tasks' => (int) ($record['max_parallel_tasks'] ?? 0),
            'current_task_count' => (int) ($record['current_task_count'] ?? 0),
        ];
    }

    /**
     * @return array{entries: array<int, array<string, mixed>>, corrupt?: bool}
     */
    private function loadRegistry(): array
    {
        $disk = $this->disk();
        if (! $disk->exists(self::REGISTRY_PATH)) {
            return ['entries' => []];
        }
        $raw = (string) $disk->get(self::REGISTRY_PATH);
        try {
            $decoded = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return ['entries' => [], 'corrupt' => true];
        }
        if (! is_array($decoded) || ! isset($decoded['entries']) || ! is_array($decoded['entries'])) {
            return ['entries' => [], 'corrupt' => true];
        }

        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $registry
     */
    private function saveRegistry(array $registry): void
    {
        $this->disk()->put(self::REGISTRY_PATH, $this->encode($registry));
    }

    /**
     * @param  array<string, mixed>  $registry
     * @return array<string, mixed>
     */
    private function capRegistry(array $registry, int $cap): array
    {
        $entries = array_values((array) ($registry['entries'] ?? []));
        if ($cap > 0 && count($entries) > $cap) {
            $entries = array_slice($entries, -$cap);
        }
        $registry['entries'] = $entries;
        unset($registry['corrupt']);

        return $registry;
    }

    /**
     * @param  array<string, mixed>  $record
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function envelopeOk(string $event, array $record, array $extra = []): array
    {
        return array_merge([
            'schema_version' => self::SCHEMA_VERSION,
            'status' => 'ok',
            'event' => $event,
            'agent_id' => (string) ($record['agent_id'] ?? ''),
            'record_status' => (string) ($record['status'] ?? ''),
            'record' => $record,
            'runtime_execution_allowed' => false,
            'dispatch_allowed' => false,
            'provider_call_allowed' => false,
            'token_spend_allowed' => false,
            'self_programming_allowed' => false,
            'ledger_write_allowed' => false,
        ], $extra);
    }

}
