<?php

namespace App\Services\Ai\SelfConstruction;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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

            $existing = $this->readAgentFile($agentId);
            $now = CarbonImmutable::now()->toIso8601String();

            $record = [
                'schema_version' => self::SCHEMA_VERSION,
                'agent_id' => $agentId,
                'label' => (string) ($agent['label'] ?? $agentId),
                'kind' => $kind,
                'status' => $requestedStatus,
                'capabilities' => $capabilities,
                'surfaces' => $surfaces,
                'max_parallel_tasks' => $maxParallelTasks,
                'current_task_count' => $currentTaskCount,
                'heartbeat_required' => (bool) ($agent['heartbeat_required'] ?? true),
                'lease_supported' => (bool) ($agent['lease_supported'] ?? false),
                'workspace_isolation_supported' => (bool) ($agent['workspace_isolation_supported'] ?? false),
                'cost_meter_supported' => (bool) ($agent['cost_meter_supported'] ?? false),
                'continuation_summary_supported' => (bool) ($agent['continuation_summary_supported'] ?? false),
                'evidence_required' => (bool) ($agent['evidence_required'] ?? true),
                'metadata' => (array) ($options['metadata'] ?? ($agent['metadata'] ?? [])),
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
                $record['metadata'] = array_merge((array) ($record['metadata'] ?? []), $metadata);
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

    public function isAvailable(): bool
    {
        try {
            $disk = $this->disk();
            $probe = self::STORAGE_PREFIX.'/.health';
            $disk->put($probe, '');
            $exists = $disk->exists($probe);
            $disk->delete($probe);

            return $exists;
        } catch (Throwable) {
            return false;
        }
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

    private function isValidAgentId(string $agentId): bool
    {
        return $agentId !== '' && (bool) preg_match('/^[A-Za-z0-9][A-Za-z0-9._\-]{1,127}$/', $agentId);
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
     * @param  array<int, mixed>  $values
     * @return list<string>
     */
    private function normalizeStringList(array $values): array
    {
        $normalized = [];
        foreach ($values as $value) {
            $clean = trim((string) $value);
            if ($clean === '') {
                continue;
            }
            $normalized[$clean] = true;
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
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function withLock(callable $callback): mixed
    {
        $disk = $this->disk();
        $start = microtime(true);
        $lockToken = (string) Str::uuid();

        while (true) {
            if (! $disk->exists(self::LOCK_PATH)) {
                $disk->put(self::LOCK_PATH, $lockToken);
                $current = (string) $disk->get(self::LOCK_PATH);
                if ($current === $lockToken) {
                    break;
                }
            }
            if ((microtime(true) - $start) > 4.0) {
                break;
            }
            usleep(50_000);
        }

        try {
            return $callback();
        } finally {
            if ($disk->exists(self::LOCK_PATH)) {
                $disk->delete(self::LOCK_PATH);
            }
        }
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

    private function agentPath(string $agentId): string
    {
        $safe = preg_replace('/[^A-Za-z0-9_\-]/', '_', $agentId) ?? $agentId;

        return self::STORAGE_PREFIX.'/agent_'.$safe.'.json';
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

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function envelopeError(string $reason, string $agentId, array $extra = []): array
    {
        return array_merge([
            'schema_version' => self::SCHEMA_VERSION,
            'status' => 'blocked',
            'event' => 'blocked',
            'agent_id' => $agentId,
            'reason' => $reason,
            'runtime_execution_allowed' => false,
            'dispatch_allowed' => false,
            'provider_call_allowed' => false,
            'token_spend_allowed' => false,
            'self_programming_allowed' => false,
            'ledger_write_allowed' => false,
        ], $extra);
    }



    private function disk(): Filesystem
    {
        return Storage::disk($this->disk ?? self::DEFAULT_DISK);
    }
}
