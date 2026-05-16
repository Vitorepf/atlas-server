<?php

namespace App\Services\Ai\SelfConstruction;

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

    public function __construct(
        private readonly ?string $disk = null,
    ) {}

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

            $conflict = $this->detectWriteOverlap($writeSet, $taskPacketId);
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
     * @return array<string, mixed>|null
     */
    public function get(string $leaseId): ?array
    {
        return $this->readLeaseFile($leaseId);
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
        $taskPacketId = (string) ($options['task_packet_id'] ?? '');

        $this->withLock(function (): array {
            $this->expireLeasesInternal();

            return [];
        });

        $conflicts = $this->detectWriteOverlap($writeSet, $taskPacketId);

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
        $now = CarbonImmutable::now()->getTimestamp();
        $registry = $this->loadRegistry();
        $entries = (array) ($registry['entries'] ?? []);
        $expiredIds = [];

        foreach ($entries as $i => $entry) {
            if ((string) ($entry['lease_status'] ?? '') !== self::LEASE_STATUS_ACTIVE) {
                continue;
            }
            $expiresAt = (int) ($entry['expires_at_unix'] ?? 0);
            if ($expiresAt > 0 && $expiresAt <= $now) {
                $leaseId = (string) ($entry['lease_id'] ?? '');
                $lease = $this->readLeaseFile($leaseId);
                if ($lease === null) {
                    continue;
                }
                $expiredAt = CarbonImmutable::now()->toIso8601String();
                $lease['lease_status'] = self::LEASE_STATUS_EXPIRED;
                $receipt = $this->buildReceipt(self::RECEIPT_LEASE_EXPIRED, [
                    'task_packet_id' => (string) $lease['task_packet_id'],
                    'agent_id' => (string) $lease['agent_id'],
                    'lease_id' => $leaseId,
                ]);
                $lease['receipts'][] = $receipt;
                $lease['history'][] = [
                    'event' => 'lease_expired',
                    'at' => $expiredAt,
                    'receipt_hash' => $receipt['receipt_hash'],
                ];
                $this->writeLeaseFile($lease);
                $entries[$i]['lease_status'] = self::LEASE_STATUS_EXPIRED;
                $expiredIds[] = $leaseId;
            }
        }

        $registry['entries'] = $entries;
        $this->saveRegistry($registry);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $expiredIds === [] ? 'no_expirations' : 'expired',
            'expired_count' => count($expiredIds),
            'expired_lease_ids' => $expiredIds,
            'runtime_execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
        ];
    }

    private function hasActiveLeaseFor(string $taskPacketId): bool
    {
        $registry = $this->loadRegistry();
        foreach ((array) ($registry['entries'] ?? []) as $entry) {
            if ((string) ($entry['task_packet_id'] ?? '') === $taskPacketId
                && (string) ($entry['lease_status'] ?? '') === self::LEASE_STATUS_ACTIVE) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $writeSet
     * @return list<array<string, mixed>>
     */
    private function detectWriteOverlap(array $writeSet, string $excludeTaskPacketId = ''): array
    {
        if ($writeSet === []) {
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
            $existingWriteSet = (array) ($entry['write_set'] ?? []);
            $overlap = array_values(array_intersect($writeSet, $existingWriteSet));
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
     * @param  list<string>  $set
     * @return list<string>
     */
    private function normalizeSet(array $set): array
    {
        $out = [];
        foreach ($set as $path) {
            $value = trim((string) $path);
            if ($value === '') {
                continue;
            }
            $out[] = str_replace('\\', '/', $value);
        }
        $out = array_values(array_unique($out));
        sort($out);

        return $out;
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
        $registry['entries'][] = [
            'lease_id' => (string) ($lease['lease_id'] ?? ''),
            'task_packet_id' => (string) ($lease['task_packet_id'] ?? ''),
            'agent_id' => (string) ($lease['agent_id'] ?? ''),
            'lease_status' => (string) ($lease['lease_status'] ?? ''),
            'acquired_at' => (string) ($lease['acquired_at'] ?? ''),
            'expires_at' => (string) ($lease['expires_at'] ?? ''),
            'expires_at_unix' => (int) ($lease['expires_at_unix'] ?? 0),
            'write_set' => (array) ($lease['write_set'] ?? []),
            'read_set' => (array) ($lease['read_set'] ?? []),
        ];
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
     * The registry is an index for active conflict checks, not the audit log.
     * Full lease payloads remain persisted as individual lease files.
     *
     * @param  array<string, mixed>  $registry
     * @return array<string, mixed>
     */
    private function compactRegistry(array $registry): array
    {
        $entries = array_values(array_filter((array) ($registry['entries'] ?? []), 'is_array'));
        if (count($entries) <= self::MAX_REGISTRY_ENTRIES) {
            $registry['entries'] = $entries;

            return $registry;
        }

        usort($entries, static function (array $a, array $b): int {
            $aActive = (string) ($a['lease_status'] ?? '') === self::LEASE_STATUS_ACTIVE;
            $bActive = (string) ($b['lease_status'] ?? '') === self::LEASE_STATUS_ACTIVE;
            if ($aActive !== $bActive) {
                return $aActive ? -1 : 1;
            }

            return ((int) ($b['expires_at_unix'] ?? 0)) <=> ((int) ($a['expires_at_unix'] ?? 0));
        });

        $registry['entries'] = array_slice($entries, 0, self::MAX_REGISTRY_ENTRIES);
        $registry['compacted'] = true;
        $registry['compacted_from_entry_count'] = count($entries);
        $registry['compacted_at'] = CarbonImmutable::now()->toIso8601String();
        $registry['compaction_policy'] = 'keep_active_leases_first_then_recent_index_entries_without_deleting_lease_files';

        return $registry;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function buildReceipt(string $kind, array $data): array
    {
        $receipt = array_merge([
            'receipt_kind' => $kind,
            'recorded_at' => CarbonImmutable::now()->toIso8601String(),
            'runtime_execution_allowed' => false,
            'ledger_write_allowed' => false,
        ], $data);
        $receipt['receipt_hash'] = hash('sha256', (string) json_encode(
            ['kind' => $kind, 'data' => $data],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));

        return $receipt;
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

    private function leasePath(string $leaseId): string
    {
        $safe = preg_replace('/[^A-Za-z0-9_\-]/', '_', $leaseId) ?? $leaseId;

        return self::STORAGE_PREFIX.'/'.$safe.'.json';
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function encode(array $payload): string
    {
        return (string) json_encode(
            $payload,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT,
        );
    }

    /**
     * @param  array<string, mixed>  $lease
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function envelopeOk(string $event, array $lease, array $extra = []): array
    {
        return array_merge([
            'schema_version' => self::SCHEMA_VERSION,
            'status' => 'ok',
            'event' => $event,
            'lease_id' => (string) ($lease['lease_id'] ?? ''),
            'task_packet_id' => (string) ($lease['task_packet_id'] ?? ''),
            'lease_status' => (string) ($lease['lease_status'] ?? ''),
            'lease' => $lease,
            'runtime_execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
        ], $extra);
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function envelopeError(string $reason, string $taskPacketId, string $agentId, array $extra = []): array
    {
        return array_merge([
            'schema_version' => self::SCHEMA_VERSION,
            'status' => 'blocked',
            'event' => 'blocked',
            'reason' => $reason,
            'task_packet_id' => $taskPacketId,
            'agent_id' => $agentId,
            'runtime_execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
        ], $extra);
    }

    private function disk(): Filesystem
    {
        return Storage::disk($this->disk ?? self::DEFAULT_DISK);
    }
}
