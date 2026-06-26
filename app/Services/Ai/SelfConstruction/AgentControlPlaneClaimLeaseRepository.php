<?php

namespace App\Services\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\Leasing\AgentControlPlaneLeaseEnvelopeFactory;
use App\Services\Ai\SelfConstruction\Leasing\AgentControlPlaneLeasePathCanonicalizer;
use App\Services\Ai\SelfConstruction\Leasing\AgentControlPlaneLeaseRegistryShaper;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Persistent local claim/lease registry for Agent Control Plane task packets.
 *
 * Holds at most one ACTIVE lease per task_packet_id. Conflicts are computed
 * over the write_set declared in the scope lock plan; read-only overlap is
 * permitted. Leases require a TTL, can be renewed only by the owner and
 * released only by the owner (or an explicitly authorised operator).
 *
 * Runtime-safe: never starts processes, never calls Codex CLI/app, never
 * spawns subprocesses, never invokes adapters, never dispatches work,
 * never spends tokens, never advances the next required slice, never
 * enables self-programming and never writes the evidence ledger.
 */
final class AgentControlPlaneClaimLeaseRepository
{
    public const SCHEMA_VERSION = 'atlas.self_construction.agent_control_plane_claim_lease_runtime.v1';

    public const MODE = 'persistent_local_agent_control_plane_claim_lease_runtime';

    public const STORAGE_PREFIX = 'atlas/self-construction/agent-control-plane/leases';

    public const REGISTRY_PATH = self::STORAGE_PREFIX.'/registry.json';

    public const LOCK_PATH = self::STORAGE_PREFIX.'/.lock';

    /** A1/MF-06 — exclusive-lock acquisition budget. FAIL-CLOSED on timeout: the caller re-queues, never drops. */
    public const LOCK_TIMEOUT_SECONDS = 8.0;

    /** Poll between non-blocking flock attempts (50ms): responsive without busy-spin. */
    private const LOCK_POLL_MICROSECONDS = 50_000;

    public const DEFAULT_DISK = 'local';

    public const DEFAULT_TTL_SECONDS = 1800;

    public const MIN_TTL_SECONDS = 60;

    public const MAX_TTL_SECONDS = 14_400;

    public const MAX_REGISTRY_ENTRIES = 1_000;

    public const LEASE_STATUS_ACTIVE = 'active';

    public const LEASE_STATUS_EXPIRED = 'expired';

    public const LEASE_STATUS_RELEASED = 'released';

    public const RECEIPT_CLAIM_ACQUIRED = 'claim_acquired';

    public const RECEIPT_CLAIM_BLOCKED_CONFLICT = 'claim_blocked_conflict';

    public const RECEIPT_LEASE_RENEWED = 'lease_renewed';

    public const RECEIPT_LEASE_RELEASED = 'lease_released';

    public const RECEIPT_LEASE_EXPIRED = 'lease_expired';

    private readonly float $lockTimeoutSeconds;

    public function __construct(
        private readonly ?string $disk = null,
        ?float $lockTimeoutSeconds = null,
    ) {
        $this->lockTimeoutSeconds = ($lockTimeoutSeconds !== null && $lockTimeoutSeconds > 0.0)
            ? $lockTimeoutSeconds
            : self::LOCK_TIMEOUT_SECONDS;
    }

    /**
     * @param  array<string, mixed>  $scopeLock
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function claim(string $taskPacketId, string $agentId, array $scopeLock, array $options = []): array
    {
        return $this->withLock(function () use ($taskPacketId, $agentId, $scopeLock, $options): array {
            if ($taskPacketId === '') {
                return $this->envelopeError('task_packet_id_missing', '', $agentId);
            }
            if ($agentId === '') {
                return $this->envelopeError('agent_id_missing', $taskPacketId, '');
            }

            $writeSet = $this->normalizeSet((array) ($scopeLock['write_set'] ?? []));
            $readSet = $this->normalizeSet((array) ($scopeLock['read_set'] ?? []));
            $ttl = (int) ($options['ttl_seconds'] ?? self::DEFAULT_TTL_SECONDS);
            $ttl = max(self::MIN_TTL_SECONDS, min(self::MAX_TTL_SECONDS, $ttl));

            // Expire stale active leases before evaluating conflict.
            $this->expireLeasesInternal();

            if ($this->hasActiveLeaseFor($taskPacketId)) {
                $blockedReceipt = $this->buildReceipt(self::RECEIPT_CLAIM_BLOCKED_CONFLICT, [
                    'task_packet_id' => $taskPacketId,
                    'agent_id' => $agentId,
                    'reason' => 'task_already_claimed',
                ]);

                return $this->envelopeError('task_already_claimed', $taskPacketId, $agentId, [
                    'blocked_receipt' => $blockedReceipt,
                ]);
            }

            $conflict = $this->detectWriteOverlap($writeSet, $readSet, $taskPacketId);
            if ($conflict !== []) {
                $blockedReceipt = $this->buildReceipt(self::RECEIPT_CLAIM_BLOCKED_CONFLICT, [
                    'task_packet_id' => $taskPacketId,
                    'agent_id' => $agentId,
                    'reason' => 'write_set_overlap',
                    'conflict' => $conflict,
                ]);

                return $this->envelopeError('write_set_overlap', $taskPacketId, $agentId, [
                    'conflict' => $conflict,
                    'blocked_receipt' => $blockedReceipt,
                ]);
            }

            $leaseId = 'lease_'.(string) Str::ulid();
            $now = CarbonImmutable::now();
            $lease = [
                'schema_version' => self::SCHEMA_VERSION,
                'lease_id' => $leaseId,
                'task_packet_id' => $taskPacketId,
                'agent_id' => $agentId,
                'lease_status' => self::LEASE_STATUS_ACTIVE,
                'acquired_at' => $now->toIso8601String(),
                'acquired_at_unix' => $now->getTimestamp(),
                'ttl_seconds' => $ttl,
                'expires_at' => $now->addSeconds($ttl)->toIso8601String(),
                'expires_at_unix' => $now->getTimestamp() + $ttl,
                'renew_count' => 0,
                'released_at' => null,
                'released_by' => null,
                'release_reason' => null,
                'write_set' => $writeSet,
                'read_set' => $readSet,
                'scope_lock_plan_hash' => (string) ($scopeLock['scope_lock_plan_hash'] ?? ''),
                'operator_authorisation' => (array) ($options['operator_authorisation'] ?? []),
                'receipts' => [],
                'history' => [],
                'runtime_execution_allowed' => false,
                'dispatch_allowed' => false,
                'provider_call_allowed' => false,
                'token_spend_allowed' => false,
                'self_programming_allowed' => false,
                'ledger_write_allowed' => false,
                'completion_real_allowed' => false,
            ];

            $receipt = $this->buildReceipt(self::RECEIPT_CLAIM_ACQUIRED, [
                'task_packet_id' => $taskPacketId,
                'agent_id' => $agentId,
                'lease_id' => $leaseId,
                'ttl_seconds' => $ttl,
            ]);
            $lease['receipts'][] = $receipt;
            $lease['history'][] = [
                'event' => 'claim_acquired',
                'at' => $now->toIso8601String(),
                'agent_id' => $agentId,
                'receipt_hash' => $receipt['receipt_hash'],
            ];

            $this->writeLeaseFile($lease);
            $this->registerLease($lease);

            return $this->envelopeOk('claim_acquired', $lease, ['receipt' => $receipt]);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function renew(string $leaseId, string $agentId, int $ttlSeconds): array
    {
        return $this->withLock(function () use ($leaseId, $agentId, $ttlSeconds): array {
            $lease = $this->readLeaseFile($leaseId);
            if ($lease === null) {
                return $this->envelopeError('lease_not_found', '', $agentId, ['lease_id' => $leaseId]);
            }
            if ((string) $lease['agent_id'] !== $agentId) {
                return $this->envelopeError('not_lease_owner', (string) $lease['task_packet_id'], $agentId, [
                    'lease_id' => $leaseId,
                    'lease_owner' => (string) $lease['agent_id'],
                ]);
            }
            if ((string) $lease['lease_status'] !== self::LEASE_STATUS_ACTIVE) {
                return $this->envelopeError('lease_not_active', (string) $lease['task_packet_id'], $agentId, [
                    'lease_id' => $leaseId,
                    'lease_status' => (string) $lease['lease_status'],
                ]);
            }

            $ttl = max(self::MIN_TTL_SECONDS, min(self::MAX_TTL_SECONDS, $ttlSeconds));
            $now = CarbonImmutable::now();
            $lease['ttl_seconds'] = $ttl;
            $lease['expires_at'] = $now->addSeconds($ttl)->toIso8601String();
            $lease['expires_at_unix'] = $now->getTimestamp() + $ttl;
            $lease['renew_count'] = (int) $lease['renew_count'] + 1;

            $receipt = $this->buildReceipt(self::RECEIPT_LEASE_RENEWED, [
                'task_packet_id' => (string) $lease['task_packet_id'],
                'agent_id' => $agentId,
                'lease_id' => $leaseId,
                'ttl_seconds' => $ttl,
            ]);
            $lease['receipts'][] = $receipt;
            $lease['history'][] = [
                'event' => 'lease_renewed',
                'at' => $now->toIso8601String(),
                'agent_id' => $agentId,
                'receipt_hash' => $receipt['receipt_hash'],
            ];

            $this->writeLeaseFile($lease);
            $this->updateRegistryEntry($lease);

            return $this->envelopeOk('lease_renewed', $lease, ['receipt' => $receipt]);
        });
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function release(string $leaseId, string $agentId, array $options = []): array
    {
        return $this->withLock(function () use ($leaseId, $agentId, $options): array {
            $lease = $this->readLeaseFile($leaseId);
            if ($lease === null) {
                return $this->envelopeError('lease_not_found', '', $agentId, ['lease_id' => $leaseId]);
            }

            $operatorAuthorised = (bool) ($options['operator_authorised'] ?? false);
            if ((string) $lease['agent_id'] !== $agentId && ! $operatorAuthorised) {
                return $this->envelopeError('not_lease_owner', (string) $lease['task_packet_id'], $agentId, [
                    'lease_id' => $leaseId,
                    'lease_owner' => (string) $lease['agent_id'],
                ]);
            }

            $now = CarbonImmutable::now();
            $reason = (string) ($options['reason'] ?? 'released_by_owner');
            $lease['lease_status'] = self::LEASE_STATUS_RELEASED;
            $lease['released_at'] = $now->toIso8601String();
            $lease['released_by'] = $agentId;
            $lease['release_reason'] = $reason;

            $receipt = $this->buildReceipt(self::RECEIPT_LEASE_RELEASED, [
                'task_packet_id' => (string) $lease['task_packet_id'],
                'agent_id' => $agentId,
                'lease_id' => $leaseId,
                'release_reason' => $reason,
                'operator_authorised' => $operatorAuthorised,
            ]);
            $lease['receipts'][] = $receipt;
            $lease['history'][] = [
                'event' => 'lease_released',
                'at' => $now->toIso8601String(),
                'agent_id' => $agentId,
                'receipt_hash' => $receipt['receipt_hash'],
            ];

            $this->writeLeaseFile($lease);
            $this->updateRegistryEntry($lease);

            return $this->envelopeOk('lease_released', $lease, ['receipt' => $receipt]);
        });
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function expireLeases(array $options = []): array
    {
        return $this->withLock(function () use ($options): array {
            return $this->expireLeasesInternal($options);
        });
    }

    /**
     * Expire active leases and return the task packet ids that became reclaimable in this call.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function reclaimExpiredLeases(array $options = []): array
    {
        return $this->withLock(function () use ($options): array {
            $expirations = $this->collectExpirations(CarbonImmutable::now()->getTimestamp());
            $tasks = [];
            $seen = [];
            foreach ($expirations as $expiration) {
                $taskPacketId = (string) ($expiration['task_packet_id'] ?? '');
                if ($taskPacketId === '' || isset($seen[$taskPacketId])) {
                    continue;
                }
                $seen[$taskPacketId] = true;
                $tasks[] = $taskPacketId;
            }

            return [
                'schema_version' => self::SCHEMA_VERSION,
                'status' => $expirations === [] ? 'no_expirations' : 'ok',
                'expired_count' => count($expirations),
                'expired_lease_ids' => array_values(array_map(static fn (array $expiration): string => (string) ($expiration['lease_id'] ?? ''), $expirations)),
                'expired_leases' => $expirations,
                'reclaimable_tasks' => $tasks,
                'runtime_execution_allowed' => false,
                'dispatch_allowed' => false,
                'ledger_write_allowed' => false,
            ];
        });
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(string $leaseId): ?array
    {
        return $this->readLeaseFile($leaseId);
    }

    /**
     * Rebuild the registry index from the durable per-lease files.
     *
     * @return array<string, mixed>
     */
    public function rebuildRegistryFromLeaseFiles(): array
    {
        return $this->withLock(fn (): array => $this->rebuildRegistryFromLeaseFilesInternal(), false);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    public function activeLeases(array $filters = []): array
    {
        $this->withLock(function (): array {
            $this->expireLeasesInternal();

            return [];
        });

        $registry = $this->loadRegistry();
        $entries = (array) ($registry['entries'] ?? []);
        $agentFilter = isset($filters['agent_id']) ? (string) $filters['agent_id'] : '';
        $taskFilter = isset($filters['task_packet_id']) ? (string) $filters['task_packet_id'] : '';

        $results = [];
        foreach ($entries as $entry) {
            if ((string) ($entry['lease_status'] ?? '') !== self::LEASE_STATUS_ACTIVE) {
                continue;
            }
            if ($agentFilter !== '' && (string) ($entry['agent_id'] ?? '') !== $agentFilter) {
                continue;
            }
            if ($taskFilter !== '' && (string) ($entry['task_packet_id'] ?? '') !== $taskFilter) {
                continue;
            }
            $leaseId = (string) ($entry['lease_id'] ?? '');
            $lease = $this->readLeaseFile($leaseId);
            if ($lease !== null) {
                $results[] = $lease;
            }
        }

        return $results;
    }

    /**
     * @param  array<string, mixed>  $scopeLock
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function conflictCheck(array $scopeLock, array $options = []): array
    {
        $writeSet = $this->normalizeSet((array) ($scopeLock['write_set'] ?? []));
        $readSet = $this->normalizeSet((array) ($scopeLock['read_set'] ?? []));
        $taskPacketId = (string) ($options['task_packet_id'] ?? '');

        $this->withLock(function (): array {
            $this->expireLeasesInternal();

            return [];
        });

        $conflicts = $this->detectWriteOverlap($writeSet, $readSet, $taskPacketId);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $conflicts === [] ? 'clear' : 'conflict',
            'task_packet_id' => $taskPacketId,
            'write_set' => $writeSet,
            'conflict_count' => count($conflicts),
            'conflicts' => $conflicts,
            'runtime_execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
        ];
    }

    /**
     * Prune non-active leases by task/agent/lease prefixes.
     *
     * Certification probes create many short-lived leases. This keeps their
     * evidence visible in the certification payload while preventing run-scoped
     * lease registry noise from accumulating across repeated dry-run batteries.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function prune(array $filters = []): array
    {
        return $this->withLock(function () use ($filters): array {
            $taskPrefixes = $this->stringList((array) ($filters['task_packet_id_prefixes'] ?? []));
            $agentPrefixes = $this->stringList((array) ($filters['agent_id_prefixes'] ?? []));
            $leasePrefixes = $this->stringList((array) ($filters['lease_id_prefixes'] ?? []));
            $deleteLeaseFiles = (bool) ($filters['delete_lease_files'] ?? false);
            $preserveStatuses = $this->stringList((array) ($filters['preserve_statuses'] ?? [self::LEASE_STATUS_ACTIVE]));

            if ($taskPrefixes === [] && $agentPrefixes === [] && $leasePrefixes === []) {
                return [
                    'schema_version' => self::SCHEMA_VERSION,
                    'status' => 'blocked',
                    'event' => 'prune_blocked',
                    'reason' => 'prune_requires_task_agent_or_lease_prefix',
                    'pruned_count' => 0,
                    'deleted_lease_file_count' => 0,
                    'preserved_count' => 0,
                    'runtime_execution_allowed' => false,
                    'dispatch_allowed' => false,
                    'ledger_write_allowed' => false,
                ];
            }

            $registry = $this->loadRegistry();
            $entries = array_values((array) ($registry['entries'] ?? []));
            $kept = [];
            $pruned = [];
            $deletedLeaseFileCount = 0;
            $preservedCount = 0;

            foreach ($entries as $entry) {
                $leaseStatus = (string) ($entry['lease_status'] ?? '');
                $leaseId = (string) ($entry['lease_id'] ?? '');
                $matches = $this->leaseEntryMatchesPruneFilters($entry, $taskPrefixes, $agentPrefixes, $leasePrefixes);
                if (! $matches) {
                    $kept[] = $entry;
                    continue;
                }
                if (in_array($leaseStatus, $preserveStatuses, true)) {
                    $kept[] = $entry;
                    $preservedCount++;
                    continue;
                }

                $pruned[] = $entry;
                if ($deleteLeaseFiles && $leaseId !== '') {
                    $path = self::STORAGE_PREFIX.'/'.$leaseId.'.json';
                    if ($this->disk()->exists($path)) {
                        $this->disk()->delete($path);
                        $deletedLeaseFileCount++;
                    }
                }
            }

            $registry['entries'] = $kept;
            $registry['last_pruned_at'] = CarbonImmutable::now()->toIso8601String();
            $registry['last_prune'] = [
                'matched_task_packet_id_prefixes' => $taskPrefixes,
                'matched_agent_id_prefixes' => $agentPrefixes,
                'matched_lease_id_prefixes' => $leasePrefixes,
                'delete_lease_files' => $deleteLeaseFiles,
                'preserve_statuses' => $preserveStatuses,
                'pruned_count' => count($pruned),
                'deleted_lease_file_count' => $deletedLeaseFileCount,
                'preserved_count' => $preservedCount,
            ];
            $this->saveRegistry($registry);

            return [
                'schema_version' => self::SCHEMA_VERSION,
                'status' => 'ok',
                'event' => 'pruned',
                'pruned_count' => count($pruned),
                'deleted_lease_file_count' => $deletedLeaseFileCount,
                'preserved_count' => $preservedCount,
                'matched_task_packet_id_prefixes' => $taskPrefixes,
                'matched_agent_id_prefixes' => $agentPrefixes,
                'matched_lease_id_prefixes' => $leasePrefixes,
                'delete_lease_files' => $deleteLeaseFiles,
                'preserve_statuses' => $preserveStatuses,
                'runtime_execution_allowed' => false,
                'dispatch_allowed' => false,
                'ledger_write_allowed' => false,
            ];
        });
    }

    public function isAvailable(): bool
    {
        try {
            $disk = $this->disk();
            $probePath = self::STORAGE_PREFIX.'/.health';
            $disk->put($probePath, '');
            $exists = $disk->exists($probePath);
            $disk->delete($probePath);

            return $exists;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @return array<string, mixed>
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
            'completion_real_allowed' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function expireLeasesInternal(array $options = []): array
    {
        $expirations = $this->collectExpirations(CarbonImmutable::now()->getTimestamp());
        $expiredIds = array_values(array_map(static fn (array $expiration): string => (string) ($expiration['lease_id'] ?? ''), $expirations));

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $expiredIds === [] ? 'no_expirations' : 'expired',
            'expired_count' => count($expiredIds),
            'expired_lease_ids' => $expiredIds,
            'expired_leases' => $expirations,
            'runtime_execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
        ];
    }

    /**
     * Transition only leases that expire during THIS call and return their task-rich reclaim facts.
     *
     * @return list<array{lease_id:string, task_packet_id:string, agent_id:string, expires_at_unix:int}>
     */
    private function collectExpirations(int $now): array
    {
        $registry = $this->loadRegistry();
        $entries = (array) ($registry['entries'] ?? []);
        $expirations = [];

        foreach ($entries as $i => $entry) {
            if ((string) ($entry['lease_status'] ?? '') !== self::LEASE_STATUS_ACTIVE) {
                continue;
            }
            $expiresAt = (int) ($entry['expires_at_unix'] ?? 0);
            $leaseId = (string) ($entry['lease_id'] ?? '');
            $lease = $this->readLeaseFile($leaseId);
            if ($lease === null) {
                $entries[$i]['lease_status'] = self::LEASE_STATUS_EXPIRED;
                $entries[$i]['orphaned_at'] = CarbonImmutable::now()->toIso8601String();
                $entries[$i]['orphaned_reason'] = 'lease_file_missing';
                $expirations[] = [
                    'lease_id' => $leaseId,
                    'task_packet_id' => (string) ($entry['task_packet_id'] ?? ''),
                    'agent_id' => (string) ($entry['agent_id'] ?? ''),
                    'expires_at_unix' => $expiresAt,
                ];

                continue;
            }
            if ((string) ($lease['lease_status'] ?? '') !== self::LEASE_STATUS_ACTIVE) {
                $entries[$i]['lease_status'] = (string) ($lease['lease_status'] ?? self::LEASE_STATUS_EXPIRED);

                continue;
            }
            if ($expiresAt > 0 && $expiresAt <= $now) {
                $expiredAt = CarbonImmutable::now()->toIso8601String();
                $lease['lease_status'] = self::LEASE_STATUS_EXPIRED;
                $receipt = $this->buildReceipt(self::RECEIPT_LEASE_EXPIRED, [
                    'task_packet_id' => (string) $lease['task_packet_id'],
                    'agent_id' => (string) $lease['agent_id'],
                    'lease_id' => $leaseId,
                    'reclaim_eligible' => true,
                ]);
                $lease['receipts'][] = $receipt;
                $lease['history'][] = [
                    'event' => 'lease_expired',
                    'at' => $expiredAt,
                    'receipt_hash' => $receipt['receipt_hash'],
                ];
                $this->writeLeaseFile($lease);
                $entries[$i]['lease_status'] = self::LEASE_STATUS_EXPIRED;
                $expirations[] = [
                    'lease_id' => $leaseId,
                    'task_packet_id' => (string) $lease['task_packet_id'],
                    'agent_id' => (string) $lease['agent_id'],
                    'expires_at_unix' => $expiresAt,
                ];
            }
        }

        $registry['entries'] = $entries;
        $this->saveRegistry($registry);

        return $expirations;
    }

    private function hasActiveLeaseFor(string $taskPacketId): bool
    {
        $registry = $this->loadRegistry();
        foreach ((array) ($registry['entries'] ?? []) as $entry) {
            if ((string) ($entry['task_packet_id'] ?? '') === $taskPacketId
                && (string) ($entry['lease_status'] ?? '') === self::LEASE_STATUS_ACTIVE) {
                $lease = $this->readLeaseFile((string) ($entry['lease_id'] ?? ''));

                return $lease !== null && (string) ($lease['lease_status'] ?? '') === self::LEASE_STATUS_ACTIVE;
            }
        }

        return false;
    }

    /**
     * A4/MF-07 — conflict detection across ALL active leases, prefix-aware (dir-vs-file) and read-vs-write,
     * via the single {@see WriteSetOverlap::conflicts} predicate. A conflict exists when the candidate and an
     * existing lease share a path that AT LEAST ONE of them writes (write∩write, write∩read, read∩write) —
     * the old `array_intersect` saw only write∩write and never a bare directory.
     *
     * @param  list<string>  $writeSet
     * @param  list<string>  $readSet
     * @return list<array<string, mixed>>
     */
    private function detectWriteOverlap(array $writeSet, array $readSet = [], string $excludeTaskPacketId = ''): array
    {
        if ($writeSet === [] && $readSet === []) {
            return [];
        }
        $registry = $this->loadRegistry();
        $conflicts = [];
        foreach ((array) ($registry['entries'] ?? []) as $entry) {
            if ((string) ($entry['lease_status'] ?? '') !== self::LEASE_STATUS_ACTIVE) {
                continue;
            }
            $existingTask = (string) ($entry['task_packet_id'] ?? '');
            if ($excludeTaskPacketId !== '' && $existingTask === $excludeTaskPacketId) {
                continue;
            }
            $lease = $this->readLeaseFile((string) ($entry['lease_id'] ?? ''));
            if ($lease === null || (string) ($lease['lease_status'] ?? '') !== self::LEASE_STATUS_ACTIVE) {
                continue;
            }
            $existingWriteSet = (array) ($lease['write_set'] ?? $entry['write_set'] ?? []);
            $existingReadSet = (array) ($lease['read_set'] ?? []);
            $overlap = WriteSetOverlap::conflicts($writeSet, $readSet, $existingWriteSet, $existingReadSet);
            if ($overlap !== []) {
                $conflicts[] = [
                    'lease_id' => (string) ($entry['lease_id'] ?? ''),
                    'task_packet_id' => $existingTask,
                    'agent_id' => (string) ($entry['agent_id'] ?? ''),
                    'overlap_files' => $overlap,
                    'overlap_count' => count($overlap),
                ];
            }
        }

        return $conflicts;
    }

    /**
     * ITEM8 — cohesive stateless path / set / filter / list canonicalization the claim-lease
     * repository uses to normalize scope-lock path sets, list strings, match prune filters, and
     * derive per-lease storage paths. Extracted into {@see AgentControlPlaneLeasePathCanonicalizer};
     * we keep the four private methods (`normalizeSet`, `stringList`, `leaseEntryMatchesPruneFilters`,
     * `leasePath`) as thin private delegators so every existing call site (`claim`'s
     * scope-lock-normalization, `prune`'s filter matching, the lease-path-on-disk reads/writes,
     * and the lease-id list normalizations) stays byte-identical and the public signature of the
     * repository does not move. Lazy-instantiated per call so production callers pay no construction
     * cost beyond the first use.
     */
    private function pathCanonicalizer(): AgentControlPlaneLeasePathCanonicalizer
    {
        return new AgentControlPlaneLeasePathCanonicalizer;
    }

    /**
     * @param  list<string>  $set
     * @return list<string>
     */
    private function normalizeSet(array $set): array
    {
        return $this->pathCanonicalizer()->normalizeSet($set);
    }
    /**
     * @return array<string, mixed>|null
     */
    private function readLeaseFile(string $leaseId): ?array
    {
        if ($leaseId === '') {
            return null;
        }
        $path = $this->leasePath($leaseId);
        $disk = $this->disk();
        if (! $disk->exists($path)) {
            return null;
        }
        $raw = (string) $disk->get($path);
        try {
            $decoded = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param  array<string, mixed>  $lease
     */
    private function writeLeaseFile(array $lease): void
    {
        $this->disk()->put($this->leasePath((string) $lease['lease_id']), $this->encode($lease));
    }

    /**
     * @param  array<string, mixed>  $lease
     */
    private function registerLease(array $lease): void
    {
        $registry = $this->loadRegistry();
        $registry['entries'][] = $this->registryEntryFromLease($lease);
        $this->saveRegistry($registry);
    }

    /**
     * @param  array<string, mixed>  $lease
     */
    private function updateRegistryEntry(array $lease): void
    {
        $registry = $this->loadRegistry();
        $entries = (array) ($registry['entries'] ?? []);
        $leaseId = (string) $lease['lease_id'];
        foreach ($entries as $i => $entry) {
            if ((string) ($entry['lease_id'] ?? '') === $leaseId) {
                $entries[$i]['lease_status'] = (string) ($lease['lease_status'] ?? '');
                $entries[$i]['expires_at'] = (string) ($lease['expires_at'] ?? '');
                $entries[$i]['expires_at_unix'] = (int) ($lease['expires_at_unix'] ?? 0);

                break;
            }
        }
        $registry['entries'] = $entries;
        $this->saveRegistry($registry);
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
        $this->disk()->put(self::REGISTRY_PATH, $this->encode($this->compactRegistry($registry)));
    }

    /**
     * @return array<string, mixed>
     */
    private function rebuildRegistryFromLeaseFilesInternal(): array
    {
        $entriesByLeaseId = [];
        $skippedCorrupt = 0;

        foreach ($this->disk()->files(self::STORAGE_PREFIX) as $path) {
            $basename = basename((string) $path);
            if (! str_starts_with($basename, 'lease_') || ! str_ends_with($basename, '.json')) {
                continue;
            }

            try {
                $decoded = json_decode((string) $this->disk()->get((string) $path), true, flags: JSON_THROW_ON_ERROR);
            } catch (Throwable) {
                $skippedCorrupt++;

                continue;
            }

            if (! is_array($decoded) || trim((string) ($decoded['lease_id'] ?? '')) === '') {
                $skippedCorrupt++;

                continue;
            }

            $entry = $this->registryEntryFromLease($decoded);
            $entriesByLeaseId[(string) $entry['lease_id']] = $entry;
        }

        $entries = array_values($entriesByLeaseId);
        usort($entries, static fn (array $a, array $b): int => strcmp((string) ($a['lease_id'] ?? ''), (string) ($b['lease_id'] ?? '')));

        $activeCount = 0;
        foreach ($entries as $entry) {
            if ((string) ($entry['lease_status'] ?? '') === self::LEASE_STATUS_ACTIVE) {
                $activeCount++;
            }
        }

        $registry = [
            'entries' => $entries,
            'rebuilt_at' => CarbonImmutable::now()->toIso8601String(),
            'rebuilt_from_file_count' => count($entries),
            'recovered_active_count' => $activeCount,
            'skipped_corrupt_lease_files' => $skippedCorrupt,
        ];
        $this->saveRegistry($registry);

        return array_merge([
            'schema_version' => self::SCHEMA_VERSION,
            'status' => 'rebuilt',
            'runtime_execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
        ], $registry);
    }

    /**
     * ITEM8 — cohesive stateless registry-shaping the claim-lease repository uses to project
     * a full lease into a compact registry entry and to capacity-bound the in-memory registry.
     * Extracted into {@see AgentControlPlaneLeaseRegistryShaper}; we keep the two private methods
     * (`registryEntryFromLease`, `compactRegistry`) as thin private delegators so every existing
     * call site (`updateRegistryEntry`, `saveRegistry`'s compact branch) stays byte-identical and
     * the public signature of the repository does not move. Lazy-instantiated per call so
     * production callers pay no construction cost beyond the first use.
     */
    private function registryShaper(): AgentControlPlaneLeaseRegistryShaper
    {
        return new AgentControlPlaneLeaseRegistryShaper;
    }

    /**
     * @param  array<string, mixed>  $lease
     * @return array<string, mixed>
     */
    private function registryEntryFromLease(array $lease): array
    {
        return $this->registryShaper()->registryEntryFromLease($lease);
    }

    private function rebuildCorruptRegistryIfNeeded(): void
    {
        if ((bool) ($this->loadRegistry()['corrupt'] ?? false)) {
            $this->rebuildRegistryFromLeaseFilesInternal();
        }
    }

    /**
     * The registry is an index for active conflict checks, not the audit log.
     * Full lease payloads remain persisted as individual lease files.
     *
     * @param  array<string, mixed>  $registry
     * @return array<string, mixed>
     */
    private function compactRegistry(array $registry): array
    {
        return $this->registryShaper()->compactRegistry($registry);
    }

    /**
     * ITEM8 — cohesive stateless envelope / receipt / encode factory the claim-lease repository
     * uses to build its success envelope, error envelope, lock-contention envelope, receipt, and
     * canonical JSON output. Extracted into {@see AgentControlPlaneLeaseEnvelopeFactory}; we keep
     * the five private methods (`buildReceipt`, `envelopeOk`, `envelopeError`,
     * `lockContentionEnvelope`, `encode`) as thin private delegators so every existing call site
     * (`claim`, `renew`, `release`, `expireLeases`, `reclaimExpiredLeases`, `withLock`'s
     * contention + open-failed paths) stays byte-identical and the public signature of the
     * repository does not move. Lazy-instantiated per call so production callers pay no
     * construction cost beyond the first use.
     */
    private function envelopeFactory(): AgentControlPlaneLeaseEnvelopeFactory
    {
        return new AgentControlPlaneLeaseEnvelopeFactory;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function buildReceipt(string $kind, array $data): array
    {
        return $this->envelopeFactory()->buildReceipt($kind, $data);
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    /**
     * A1/MF-06 — run $callback holding a REAL exclusive OS lock (`flock(LOCK_EX)`), FAIL-CLOSED.
     *
     * Replaces the old `Storage::exists/put` check-then-act, whose 4s break ran the mutation UNLOCKED and
     * whose `finally` deleted the lock file unconditionally (a foreign lock). Now: a bounded non-blocking
     * poll; on timeout the callback is NEVER run (returns a blocked envelope — the caller re-queues); the
     * `finally` releases ONLY this call's handle and never deletes the lock file. Mirrors
     * {@see \App\Services\Ai\AutonomousEvolution\Constitution\AtlasLoopMergeActuator::withMainMergeLock}.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T|array<string, mixed>
     */
    private function withLock(callable $callback, bool $rebuildCorruptRegistry = true): mixed
    {
        $path = $this->lockFilePath();
        $dir = \dirname($path);
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $handle = @fopen($path, 'c');
        if ($handle === false) {
            // FAIL-CLOSED: cannot open the lock file => NEVER run the mutation unlocked.
            return $this->lockContentionEnvelope('lock_open_failed');
        }

        $start = hrtime(true);
        $deadline = $start + (int) ($this->lockTimeoutSeconds * 1_000_000_000);
        $acquired = false;
        while (true) {
            if (flock($handle, LOCK_EX | LOCK_NB)) {
                $acquired = true;
                break;
            }
            if (hrtime(true) >= $deadline) {
                break;
            }
            usleep(self::LOCK_POLL_MICROSECONDS);
        }

        if (! $acquired) {
            @fclose($handle);

            // FAIL-CLOSED: contention within the budget => abort; caller re-queues. NEVER run unlocked.
            return $this->lockContentionEnvelope('lock_timeout');
        }

        try {
            if ($rebuildCorruptRegistry) {
                $this->rebuildCorruptRegistryIfNeeded();
            }

            return $callback();
        } finally {
            // Release ONLY the handle THIS call owns; keep the flock target file (never delete a foreign lock).
            @flock($handle, LOCK_UN);
            @fclose($handle);
        }
    }

    /** Absolute filesystem path of the exclusive lock file (flock needs a real local path). */
    private function lockFilePath(): string
    {
        $disk = $this->disk();

        return method_exists($disk, 'path')
            ? $disk->path(self::LOCK_PATH)
            : storage_path('app/'.self::LOCK_PATH);
    }

    /**
     * The FAIL-CLOSED result when the exclusive lock cannot be acquired: a blocked, NON-mutating envelope the
     * callers surface honestly (no lease written, nothing dispatched). The critical callback never runs.
     /**
      * @return array<string, mixed>
      */
     private function lockContentionEnvelope(string $reason): array
     {
         return $this->envelopeFactory()->lockContentionEnvelope($reason);
     }

     private function leasePath(string $leaseId): string
     {
         return $this->pathCanonicalizer()->leasePath($leaseId);
     }

     /**
      * @param  array<string, mixed>  $payload
      */
     private function encode(array $payload): string
     {
         return $this->envelopeFactory()->encode($payload);
     }

     /**
      * @param  array<string, mixed>  $lease
      * @param  array<string, mixed>  $extra
      * @return array<string, mixed>
      */
     private function envelopeOk(string $event, array $lease, array $extra = []): array
     {
         return $this->envelopeFactory()->envelopeOk($event, $lease, $extra);
     }

     /**
      * @param  array<string, mixed>  $extra
      * @return array<string, mixed>
      */
     private function envelopeError(string $reason, string $taskPacketId, string $agentId, array $extra = []): array
     {
         return $this->envelopeFactory()->envelopeError($reason, $taskPacketId, $agentId, $extra);
     }

    private function disk(): Filesystem
    {
        return Storage::disk($this->disk ?? self::DEFAULT_DISK);
    }

    /**
     * @param  array<string, mixed>  $entry
     * @param  list<string>  $taskPrefixes
     * @param  list<string>  $agentPrefixes
     * @param  list<string>  $leasePrefixes
     */
    private function leaseEntryMatchesPruneFilters(array $entry, array $taskPrefixes, array $agentPrefixes, array $leasePrefixes): bool
    {
        return $this->pathCanonicalizer()->leaseEntryMatchesPruneFilters($entry, $taskPrefixes, $agentPrefixes, $leasePrefixes);
    }

    /**
     * @param  array<int, mixed>  $values
     * @return list<string>
     */
    private function stringList(array $values): array
    {
        return $this->pathCanonicalizer()->stringList($values);
    }
}
