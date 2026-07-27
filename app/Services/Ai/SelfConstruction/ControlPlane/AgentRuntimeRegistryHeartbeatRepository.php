<?php

namespace App\Services\Ai\SelfConstruction\ControlPlane;

use App\Services\Ai\SelfConstruction\ControlPlane\Support\DiskJsonIndexLoader;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;
use App\Services\Ai\SelfConstruction\Support\EncodesPayloadAsPrettyJson;

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
    use AgentRuntimeRegistryStorageConcerns;

    use EncodesPayloadAsPrettyJson;
    public const SCHEMA_VERSION = 'atlas.self_construction.agent_runtime_registry_heartbeat.v1';

    public const MODE = 'persistent_local_agent_runtime_registry_heartbeat';

    public const STORAGE_PREFIX = 'atlas/self-construction/agent-control-plane/agent-heartbeats';

    public const INDEX_PATH = self::STORAGE_PREFIX.'/index.json';

    public const LOCK_PATH = self::STORAGE_PREFIX.'/.lock';

    public const DEFAULT_DISK = 'local';

    public const DEFAULT_TTL_SECONDS = 90;

    public const DEFAULT_PER_AGENT_CAP = 50;

    public const DEFAULT_STALE_AGENT_DETAIL_CAP = 100;

    public const DEFAULT_INDEX_AGENT_CAP = 5000;

    public const STATUSES = [
        'healthy',
        'busy',
        'degraded',
        'stale',
        'unknown',
        'quarantined',
    ];

    /** AC3: heartbeat status category — always exactly one of these four, deterministically. */
    public const STATUS_CATEGORY_FRESH = 'fresh';
    public const STATUS_CATEGORY_STALE = 'stale';
    public const STATUS_CATEGORY_MISSING = 'missing';
    public const STATUS_CATEGORY_QUARANTINED = 'quarantined';

    /**
     * Heartbeat productivity states (AC2/AC3) — distinguishes a worker actually doing work from
     * one that is merely alive (process up, lease held, heartbeat pinging) with zero real
     * movement. fake_alive is the state this exists to catch: current_task_count > 0 but neither
     * progress_marker nor last_outcome_marker advanced since the previous heartbeat.
     */
    public const HEARTBEAT_STATE_PRODUCTIVE = 'productive';
    public const HEARTBEAT_STATE_IDLE = 'idle';
    public const HEARTBEAT_STATE_STALE = 'stale';
    public const HEARTBEAT_STATE_FAKE_ALIVE = 'fake_alive';

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
                'capabilities' => $this->normalizeStringList((array) ($heartbeat['capabilities'] ?? [])),
                'last_continuation_summary_hash' => (string) ($heartbeat['last_continuation_summary_hash'] ?? ''),
                'progress_marker' => (string) ($heartbeat['progress_marker'] ?? ''),
                'last_outcome_marker' => (string) ($heartbeat['last_outcome_marker'] ?? ''),
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
        $detailLimit = max(0, (int) ($options['detail_limit'] ?? self::DEFAULT_STALE_AGENT_DETAIL_CAP));
        $referenceIso = (string) ($options['reference_time'] ?? CarbonImmutable::now()->toIso8601String());
        $referenceTs = strtotime($referenceIso);
        if ($referenceTs === false) {
            $referenceTs = time();
        }

        $stale = [];
        $fresh = [];
        $fakeAlive = [];
        $staleCount = 0;
        $freshCount = 0;
        $fakeAliveCount = 0;
        foreach ($this->loadIndex() as $entry) {
            $agentId = (string) ($entry['agent_id'] ?? '');
            $observed = (string) ($entry['observed_at'] ?? '');
            $ts = strtotime($observed);
            if ($ts === false) {
                $staleCount++;
                if ($detailLimit === 0 || count($stale) < $detailLimit) {
                    $stale[] = [
                        'agent_id' => $agentId,
                        'observed_at' => $observed,
                        'age_seconds' => null,
                        'reason' => 'invalid_timestamp',
                        'suggested_recovery_action' => 'investigate_clock_or_serialization_bug_then_restart_agent',
                    ];
                }

                continue;
            }
            $age = $referenceTs - $ts;
            $payload = [
                'agent_id' => $agentId,
                'observed_at' => $observed,
                'age_seconds' => $age,
                'status' => (string) ($entry['status'] ?? 'unknown'),
            ];

            // Check for fake_alive: agent has tasks but no progress markers moved.
            $heartbeats = $this->readAgentHeartbeats($agentId);
            if (count($heartbeats) >= 2) {
                $latest = $heartbeats[count($heartbeats) - 1];
                $previous = $heartbeats[count($heartbeats) - 2];
                $state = $this->classifyHeartbeatState(false, $latest, $previous);
                if ($state === self::HEARTBEAT_STATE_FAKE_ALIVE) {
                    $fakeAliveCount++;
                    $fakeAlive[] = [
                        'agent_id' => $agentId,
                        'observed_at' => $observed,
                        'age_seconds' => $age,
                        'status' => (string) ($entry['status'] ?? 'unknown'),
                        'reason' => 'fake_alive_no_progress_delta',
                        'suggested_recovery_action' => 'investigate_worker_progress_then_restart_or_give_back',
                    ];
                }
            }

            if ($age > $ttl) {
                $payload['reason'] = 'older_than_ttl';
                $payload['suggested_recovery_action'] = 'reap_and_reclaim_leases_then_restart_agent';
                $staleCount++;
                if ($detailLimit === 0 || count($stale) < $detailLimit) {
                    $stale[] = $payload;
                }
            } else {
                $freshCount++;
                if ($detailLimit === 0 || count($fresh) < $detailLimit) {
                    $fresh[] = $payload;
                }
            }
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'ttl_seconds' => $ttl,
            'detail_limit' => $detailLimit,
            'reference_time' => $referenceIso,
            'stale_count' => $staleCount,
            'fresh_count' => $freshCount,
            'fake_alive_count' => $fakeAliveCount,
            'stale_detail_count' => count($stale),
            'fresh_detail_count' => count($fresh),
            'fake_alive_detail_count' => count($fakeAlive),
            'stale_detail_truncated' => $detailLimit > 0 && $staleCount > count($stale),
            'fresh_detail_truncated' => $detailLimit > 0 && $freshCount > count($fresh),
            'stale_agents' => $stale,
            'fresh_agents' => $fresh,
            'fake_alive_agents' => $fakeAlive,
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

        $heartbeats = $this->readAgentHeartbeats($agentId);
        $latest = $heartbeats !== [] ? $heartbeats[count($heartbeats) - 1] : null;
        if ($latest === null) {
            return [
                'schema_version' => self::SCHEMA_VERSION,
                'agent_id' => $agentId,
                'has_heartbeat' => false,
                'is_stale' => true,
                'is_fresh' => false,
                'is_quarantined' => false,
                'status_category' => self::STATUS_CATEGORY_MISSING,
                'reason' => 'no_heartbeat',
                'heartbeat_state' => self::HEARTBEAT_STATE_STALE,
                'reference_time' => $referenceIso,
                'ttl_seconds' => $ttl,
                'runtime_execution_allowed' => false,
                'dispatch_allowed' => false,
                'ledger_write_allowed' => false,
            ];
        }
        $previous = count($heartbeats) >= 2 ? $heartbeats[count($heartbeats) - 2] : null;

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

        $heartbeatState = $this->classifyHeartbeatState($stale, $latest, $previous);

        // AC3: quarantine is a safety state that always wins over freshness — a quarantined
        // agent must never be reported as routable just because its heartbeat is recent.
        $isQuarantined = (string) ($latest['status'] ?? '') === 'quarantined';
        $statusCategory = match (true) {
            $isQuarantined => self::STATUS_CATEGORY_QUARANTINED,
            $stale => self::STATUS_CATEGORY_STALE,
            default => self::STATUS_CATEGORY_FRESH,
        };
        if ($isQuarantined) {
            $reason = 'quarantined';
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'agent_id' => $agentId,
            'has_heartbeat' => true,
            'is_stale' => $stale,
            'is_fresh' => ! $stale,
            'is_quarantined' => $isQuarantined,
            'status_category' => $statusCategory,
            'reason' => $reason,
            'heartbeat_state' => $heartbeatState,
            'age_seconds' => $age,
            'observed_at' => $observed,
            'reference_time' => $referenceIso,
            'ttl_seconds' => $ttl,
            'status' => (string) ($latest['status'] ?? 'unknown'),
            'current_task_count' => (int) ($latest['current_task_count'] ?? 0),
            'max_parallel_tasks' => (int) ($latest['max_parallel_tasks'] ?? 0),
            'progress_marker' => (string) ($latest['progress_marker'] ?? ''),
            'last_outcome_marker' => (string) ($latest['last_outcome_marker'] ?? ''),
            'capabilities' => (array) ($latest['capabilities'] ?? []),
            'runtime_execution_allowed' => false,
            'dispatch_allowed' => false,
            'provider_call_allowed' => false,
            'token_spend_allowed' => false,
            'self_programming_allowed' => false,
            'ledger_write_allowed' => false,
        ];
    }

    /**
     * AC2: classifies a heartbeat as productive, idle, stale or fake_alive — suitable for load
     * balancing (route work away from fake_alive/idle agents) and operational proof (a worker
     * claiming progress must show its markers actually moved between heartbeats).
     *
     * @param  array<string,mixed>  $latest
     * @param  array<string,mixed>|null  $previous
     */
    private function classifyHeartbeatState(bool $stale, array $latest, ?array $previous): string
    {
        if ($stale) {
            return self::HEARTBEAT_STATE_STALE;
        }

        $currentTaskCount = (int) ($latest['current_task_count'] ?? 0);
        if ($currentTaskCount === 0) {
            return self::HEARTBEAT_STATE_IDLE;
        }

        // First-ever heartbeat for this agent: no prior marker to compare against, so we cannot
        // yet prove the claim is fake — give the benefit of the doubt until the next heartbeat.
        if ($previous === null) {
            return self::HEARTBEAT_STATE_PRODUCTIVE;
        }

        $progressMarker = (string) ($latest['progress_marker'] ?? '');
        $outcomeMarker = (string) ($latest['last_outcome_marker'] ?? '');
        $prevProgressMarker = (string) ($previous['progress_marker'] ?? '');
        $prevOutcomeMarker = (string) ($previous['last_outcome_marker'] ?? '');

        $progressMoved = $progressMarker !== '' && $progressMarker !== $prevProgressMarker;
        $outcomeMoved = $outcomeMarker !== '' && $outcomeMarker !== $prevOutcomeMarker;

        return ($progressMoved || $outcomeMoved) ? self::HEARTBEAT_STATE_PRODUCTIVE : self::HEARTBEAT_STATE_FAKE_ALIVE;
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
        $index = $this->compactIndex($index);
        $this->disk()->put(self::INDEX_PATH, $this->encode($index));
    }

    /**
     * @param  list<array<string, mixed>>  $index
     * @return list<array<string, mixed>>
     */
    private function compactIndex(array $index): array
    {
        $byAgent = [];
        foreach ($index as $entry) {
            $agentId = (string) ($entry['agent_id'] ?? '');
            if ($agentId === '') {
                continue;
            }
            $candidateTs = strtotime((string) ($entry['recorded_at'] ?? $entry['observed_at'] ?? '')) ?: 0;
            $current = $byAgent[$agentId] ?? null;
            $currentTs = is_array($current)
                ? (strtotime((string) ($current['recorded_at'] ?? $current['observed_at'] ?? '')) ?: 0)
                : -1;
            if ($candidateTs >= $currentTs) {
                $byAgent[$agentId] = $entry;
            }
        }

        $compacted = array_values($byAgent);
        usort(
            $compacted,
            static fn (array $a, array $b): int => (strtotime((string) ($b['recorded_at'] ?? $b['observed_at'] ?? '')) ?: 0)
                <=> (strtotime((string) ($a['recorded_at'] ?? $a['observed_at'] ?? '')) ?: 0),
        );

        if (count($compacted) > self::DEFAULT_INDEX_AGENT_CAP) {
            $compacted = array_slice($compacted, 0, self::DEFAULT_INDEX_AGENT_CAP);
        }

        return array_values($compacted);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function loadIndex(): array
    {
        return DiskJsonIndexLoader::load($this->disk(), self::INDEX_PATH);
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

}
