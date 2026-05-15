<?php

namespace App\Services\Ai\SelfConstruction;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Persistent local heartbeat ledger for Agent Control Plane agents.
 *
 * Persists heartbeats under the canonical storage prefix
 * `atlas/self-construction/agent-control-plane/agent-heartbeats/`.
 *
 * Runtime-safe: never starts processes, never pings real agents, never
 * spawns subprocesses, never invokes adapters, never dispatches work,
 * never spends tokens, never advances the next required slice and never
 * writes the evidence ledger. Heartbeats are recorded only when supplied
 * by the caller or a test.
 */
final class AgentRuntimeRegistryHeartbeatRepository
{
    public const SCHEMA_VERSION = 'atlas.self_construction.agent_runtime_registry_heartbeat.v1';

    public const MODE = 'persistent_local_agent_runtime_registry_heartbeat';

    public const STORAGE_PREFIX = 'atlas/self-construction/agent-control-plane/agent-heartbeats';

    public const INDEX_PATH = self::STORAGE_PREFIX.'/index.json';

    public const LOCK_PATH = self::STORAGE_PREFIX.'/.lock';

    public const DEFAULT_DISK = 'local';

    public const DEFAULT_TTL_SECONDS = 90;

    public const DEFAULT_PER_AGENT_CAP = 50;

    public const STATUSES = [
        'healthy',
        'busy',
        'degraded',
        'stale',
        'unknown',
    ];

    public function __construct(
        private readonly ?string $disk = null,
    ) {}

    /**
     * @param  array<string, mixed>  $heartbeat
     * @return array<string, mixed>
     */
    public function record(string $agentId, array $heartbeat): array
    {
        return $this->withLock(function () use ($agentId, $heartbeat): array {
            if (! $this->isValidAgentId($agentId)) {
                return $this->envelopeError('invalid_agent_id', $agentId);
            }

            $status = (string) ($heartbeat['status'] ?? 'healthy');
            if (! in_array($status, self::STATUSES, true)) {
                return $this->envelopeError('invalid_status', $agentId, [
                    'requested_status' => $status,
                    'allowed_statuses' => self::STATUSES,
                ]);
            }

            $now = CarbonImmutable::now()->toIso8601String();
            $observedAt = (string) ($heartbeat['observed_at'] ?? $now);
            $heartbeatId = (string) ($heartbeat['heartbeat_id'] ?? Str::uuid());
            $maxParallel = max(1, (int) ($heartbeat['max_parallel_tasks'] ?? 1));
            $currentTasks = max(0, (int) ($heartbeat['current_task_count'] ?? 0));
            if ($currentTasks > $maxParallel) {
                $currentTasks = $maxParallel;
            }

            $record = [
                'schema_version' => self::SCHEMA_VERSION,
                'heartbeat_id' => $heartbeatId,
                'agent_id' => $agentId,
                'observed_at' => $observedAt,
                'recorded_at' => $now,
                'status' => $status,
                'current_task_count' => $currentTasks,
                'max_parallel_tasks' => $maxParallel,
                'active_lease_ids' => $this->normalizeStringList((array) ($heartbeat['active_lease_ids'] ?? [])),
                'current_workspace_ids' => $this->normalizeStringList((array) ($heartbeat['current_workspace_ids'] ?? [])),
                'last_continuation_summary_hash' => (string) ($heartbeat['last_continuation_summary_hash'] ?? ''),
                'metadata' => (array) ($heartbeat['metadata'] ?? []),
                'runtime_execution_allowed' => false,
                'provider_call_allowed' => false,
                'token_spend_allowed' => false,
                'dispatch_allowed' => false,
                'self_programming_allowed' => false,
                'ledger_write_allowed' => false,
            ];

            $heartbeatList = $this->readAgentHeartbeats($agentId);
            $heartbeatList[] = $record;
            if (count($heartbeatList) > self::DEFAULT_PER_AGENT_CAP) {
                $heartbeatList = array_slice($heartbeatList, -self::DEFAULT_PER_AGENT_CAP);
            }
            $this->writeAgentHeartbeats($agentId, $heartbeatList);
            $this->updateIndex($agentId, $record);

            return $this->envelopeOk('heartbeat_recorded', $record);
        });
    }

    /**
     * @return array<string, mixed>|null
     */
    public function latest(string $agentId): ?array
    {
        if (! $this->isValidAgentId($agentId)) {
            return null;
        }
        $heartbeats = $this->readAgentHeartbeats($agentId);
        if ($heartbeats === []) {
            return null;
        }

        return $heartbeats[count($heartbeats) - 1];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    public function list(array $filters = []): array
    {
        $agentId = isset($filters['agent_id']) ? (string) $filters['agent_id'] : '';
        $limit = isset($filters['limit']) ? (int) $filters['limit'] : 0;
        $sinceIso = isset($filters['since']) ? (string) $filters['since'] : '';
        $sinceTimestamp = $sinceIso !== '' ? strtotime($sinceIso) : false;

        if ($agentId !== '') {
            $heartbeats = $this->readAgentHeartbeats($agentId);
        } else {
            $heartbeats = [];
            foreach ($this->loadIndex() as $entry) {
                $id = (string) ($entry['agent_id'] ?? '');
                if ($id === '') {
                    continue;
                }
                foreach ($this->readAgentHeartbeats($id) as $hb) {
                    $heartbeats[] = $hb;
                }
            }
        }

        $results = [];
        foreach ($heartbeats as $hb) {
            if ($sinceTimestamp !== false) {
                $observed = strtotime((string) ($hb['observed_at'] ?? ''));
                if ($observed === false || $observed < $sinceTimestamp) {
                    continue;
                }
            }
            $results[] = $hb;
            if ($limit > 0 && count($results) >= $limit) {
                break;
            }
        }

        return $results;
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function staleAgents(array $options = []): array
    {
        $ttl = max(1, (int) ($options['ttl_seconds'] ?? self::DEFAULT_TTL_SECONDS));
        $referenceIso = (string) ($options['reference_time'] ?? CarbonImmutable::now()->toIso8601String());
        $referenceTs = strtotime($referenceIso);
        if ($referenceTs === false) {
            $referenceTs = time();
        }

        $stale = [];
        $fresh = [];
        foreach ($this->loadIndex() as $entry) {
            $agentId = (string) ($entry['agent_id'] ?? '');
            $observed = (string) ($entry['observed_at'] ?? '');
            $ts = strtotime($observed);
            if ($ts === false) {
                $stale[] = [
                    'agent_id' => $agentId,
                    'observed_at' => $observed,
                    'age_seconds' => null,
                    'reason' => 'invalid_timestamp',
                ];

                continue;
            }
            $age = $referenceTs - $ts;
            $payload = [
                'agent_id' => $agentId,
                'observed_at' => $observed,
                'age_seconds' => $age,
                'status' => (string) ($entry['status'] ?? 'unknown'),
            ];
            if ($age > $ttl) {
                $payload['reason'] = 'older_than_ttl';
                $stale[] = $payload;
            } else {
                $fresh[] = $payload;
            }
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'ttl_seconds' => $ttl,
            'reference_time' => $referenceIso,
            'stale_count' => count($stale),
            'fresh_count' => count($fresh),
            'stale_agents' => $stale,
            'fresh_agents' => $fresh,
            'runtime_execution_allowed' => false,
            'dispatch_allowed' => false,
            'provider_call_allowed' => false,
            'token_spend_allowed' => false,
            'self_programming_allowed' => false,
            'ledger_write_allowed' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function heartbeatStatus(string $agentId, array $options = []): array
    {
        $ttl = max(1, (int) ($options['ttl_seconds'] ?? self::DEFAULT_TTL_SECONDS));
        $referenceIso = (string) ($options['reference_time'] ?? CarbonImmutable::now()->toIso8601String());
        $referenceTs = strtotime($referenceIso);
        if ($referenceTs === false) {
            $referenceTs = time();
        }

        $latest = $this->latest($agentId);
        if ($latest === null) {
            return [
                'schema_version' => self::SCHEMA_VERSION,
                'agent_id' => $agentId,
                'has_heartbeat' => false,
                'is_stale' => true,
                'is_fresh' => false,
                'reason' => 'no_heartbeat',
                'reference_time' => $referenceIso,
                'ttl_seconds' => $ttl,
                'runtime_execution_allowed' => false,
                'dispatch_allowed' => false,
                'ledger_write_allowed' => false,
            ];
        }

        $observed = (string) ($latest['observed_at'] ?? '');
        $ts = strtotime($observed);
        if ($ts === false) {
            $age = null;
            $stale = true;
            $reason = 'invalid_timestamp';
        } else {
            $age = $referenceTs - $ts;
            $stale = $age > $ttl;
            $reason = $stale ? 'older_than_ttl' : 'fresh';
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'agent_id' => $agentId,
            'has_heartbeat' => true,
            'is_stale' => $stale,
            'is_fresh' => ! $stale,
            'reason' => $reason,
            'age_seconds' => $age,
            'observed_at' => $observed,
            'reference_time' => $referenceIso,
            'ttl_seconds' => $ttl,
            'status' => (string) ($latest['status'] ?? 'unknown'),
            'current_task_count' => (int) ($latest['current_task_count'] ?? 0),
            'max_parallel_tasks' => (int) ($latest['max_parallel_tasks'] ?? 0),
            'runtime_execution_allowed' => false,
            'dispatch_allowed' => false,
            'provider_call_allowed' => false,
            'token_spend_allowed' => false,
            'self_programming_allowed' => false,
            'ledger_write_allowed' => false,
        ];
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
     * @return list<array<string, mixed>>
     */
    private function readAgentHeartbeats(string $agentId): array
    {
        if (! $this->isValidAgentId($agentId)) {
            return [];
        }
        $path = $this->agentPath($agentId);
        $disk = $this->disk();
        if (! $disk->exists($path)) {
            return [];
        }
        $raw = (string) $disk->get($path);
        try {
            $decoded = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return [];
        }
        if (! is_array($decoded)) {
            return [];
        }

        return array_values($decoded);
    }

    /**
     * @param  list<array<string, mixed>>  $heartbeats
     */
    private function writeAgentHeartbeats(string $agentId, array $heartbeats): void
    {
        $this->disk()->put($this->agentPath($agentId), $this->encode($heartbeats));
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function updateIndex(string $agentId, array $record): void
    {
        $index = $this->loadIndex();
        $payload = [
            'agent_id' => $agentId,
            'observed_at' => (string) ($record['observed_at'] ?? ''),
            'recorded_at' => (string) ($record['recorded_at'] ?? ''),
            'status' => (string) ($record['status'] ?? ''),
            'current_task_count' => (int) ($record['current_task_count'] ?? 0),
            'max_parallel_tasks' => (int) ($record['max_parallel_tasks'] ?? 0),
        ];
        $found = false;
        foreach ($index as $i => $entry) {
            if ((string) ($entry['agent_id'] ?? '') === $agentId) {
                $index[$i] = $payload;
                $found = true;
                break;
            }
        }
        if (! $found) {
            $index[] = $payload;
        }
        $this->disk()->put(self::INDEX_PATH, $this->encode($index));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function loadIndex(): array
    {
        $disk = $this->disk();
        if (! $disk->exists(self::INDEX_PATH)) {
            return [];
        }
        $raw = (string) $disk->get(self::INDEX_PATH);
        try {
            $decoded = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return [];
        }
        if (! is_array($decoded)) {
            return [];
        }

        return array_values($decoded);
    }

    private function agentPath(string $agentId): string
    {
        $safe = preg_replace('/[^A-Za-z0-9_\-]/', '_', $agentId) ?? $agentId;

        return self::STORAGE_PREFIX.'/agent_'.$safe.'.json';
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

    /**
     * @param  array<int, array<string, mixed>>|list<array<string, mixed>>  $payload
     */
    private function encode(array $payload): string
    {
        return (string) json_encode(
            $payload,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT,
        );
    }

    private function disk(): Filesystem
    {
        return Storage::disk($this->disk ?? self::DEFAULT_DISK);
    }
}
