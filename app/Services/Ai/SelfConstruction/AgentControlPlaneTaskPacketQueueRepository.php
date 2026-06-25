<?php

namespace App\Services\Ai\SelfConstruction;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Persistent local queue of Agent Control Plane task packets.
 *
 * Persists task packets under the canonical storage prefix
 * `atlas/self-construction/agent-control-plane/task-queue/` with an indexed
 * registry. Operations are idempotent on `task_packet_id` + `task_packet_hash`
 * and use a coarse advisory lock file to avoid partial writes.
 *
 * Runtime-safe: never starts processes, never calls Codex CLI/app, never
 * spawns subprocesses, never invokes adapters, never dispatches work,
 * never spends tokens, never advances the next required slice, never
 * enables self-programming and never writes the evidence ledger.
 */
final class AgentControlPlaneTaskPacketQueueRepository
{
    public const SCHEMA_VERSION = 'atlas.self_construction.agent_control_plane_task_packet_queue.v1';

    public const MODE = 'persistent_local_agent_control_plane_task_packet_queue';

    public const STORAGE_PREFIX = 'atlas/self-construction/agent-control-plane/task-queue';

    public const REGISTRY_PATH = self::STORAGE_PREFIX.'/registry.json';

    public const LOCK_PATH = self::STORAGE_PREFIX.'/.lock';

    public const DEFAULT_DISK = 'local';

    public const DEFAULT_REGISTRY_CAP = 200;

    public const LOCK_ACQUIRE_TIMEOUT_SECONDS = 8.0;

    public const LOCK_STALE_AFTER_SECONDS = 60;

    /** A2/MF-16 — poll between non-blocking flock attempts (50ms): responsive without busy-spin. */
    private const LOCK_POLL_MICROSECONDS = 50_000;

    public const STATUSES = [
        'queued',
        'claimable',
        'claimed',
        'lease_expired',
        'released',
        'completed_dry_run',
        'blocked',
        'cancelled',
    ];

    public const ALLOWED_STATUS_TRANSITIONS = [
        'queued' => ['claimable', 'blocked', 'cancelled'],
        'claimable' => ['claimed', 'blocked', 'cancelled'],
        'claimed' => ['lease_expired', 'released', 'completed_dry_run', 'blocked'],
        'lease_expired' => ['claimable', 'released', 'cancelled'],
        'released' => ['claimable', 'cancelled'],
        'blocked' => ['claimable', 'cancelled'],
        'completed_dry_run' => [],
        'cancelled' => [],
    ];

    private readonly float $lockTimeoutSeconds;

    public function __construct(
        private readonly ?string $disk = null,
        ?float $lockTimeoutSeconds = null,
    ) {
        $this->lockTimeoutSeconds = ($lockTimeoutSeconds !== null && $lockTimeoutSeconds > 0.0)
            ? $lockTimeoutSeconds
            : self::LOCK_ACQUIRE_TIMEOUT_SECONDS;
    }

    /**
     * Enqueue a task packet. Idempotent on (task_packet_id, task_packet_hash):
     * - same id + same hash → returns existing record with `idempotent: true`
     * - same id + different hash → returns `status: blocked` with reason
     *
     * @param  array<string, mixed>  $taskPacket
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function enqueue(array $taskPacket, array $options = []): array
    {
        return $this->withLock(function () use ($taskPacket, $options): array {
            $taskPacketId = (string) ($taskPacket['task_packet_id'] ?? '');
            $taskPacketHash = (string) ($taskPacket['task_packet_hash'] ?? '');
            if ($taskPacketId === '') {
                $taskPacketId = (string) Str::uuid();
            }
            if ($taskPacketHash === '') {
                return $this->envelopeError('task_packet_hash_missing', $taskPacketId, [
                    'task_packet_id' => $taskPacketId,
                ]);
            }

            $packetStatusFromBuilder = (string) ($taskPacket['status'] ?? 'unknown');
            $initialStatus = $packetStatusFromBuilder === 'planned'
                ? 'claimable'
                : (isset(self::STATUSES[$packetStatusFromBuilder]) || in_array($packetStatusFromBuilder, self::STATUSES, true)
                    ? $packetStatusFromBuilder
                    : 'blocked');

            $existing = $this->readTaskFile($taskPacketId);
            if ($existing !== null) {
                $existingHash = (string) ($existing['task_packet']['task_packet_hash'] ?? '');
                if ($existingHash === $taskPacketHash) {
                    return $this->envelopeOk('idempotent_enqueue', $existing, [
                        'idempotent' => true,
                    ]);
                }

                return $this->envelopeError('task_packet_hash_conflict', $taskPacketId, [
                    'existing_hash' => $existingHash,
                    'incoming_hash' => $taskPacketHash,
                ]);
            }

            $now = CarbonImmutable::now()->toIso8601String();
            $record = [
                'schema_version' => self::SCHEMA_VERSION,
                'task_packet_id' => $taskPacketId,
                'task_packet_hash' => $taskPacketHash,
                'enqueued_at' => $now,
                'updated_at' => $now,
                'status' => $initialStatus,
                'priority' => (int) ($options['priority'] ?? 5),
                'tags' => array_values(array_map('strval', (array) ($options['tags'] ?? []))),
                'metadata' => (array) ($options['metadata'] ?? []),
                'task_packet' => $taskPacket,
                'history' => [
                    [
                        'event' => 'enqueued',
                        'at' => $now,
                        'status' => $initialStatus,
                    ],
                ],
                'receipts' => [],
                'read_only_until_runtime' => true,
                'dispatch_allowed' => false,
                'provider_call_allowed' => false,
                'token_spend_allowed' => false,
                'self_programming_allowed' => false,
                'ledger_write_allowed' => false,
                'runtime_execution_allowed' => false,
                'completion_real_allowed' => false,
            ];

            $this->writeTaskFile($taskPacketId, $record);
            $this->registerInRegistry($record);

            return $this->envelopeOk('enqueued', $record);
        });
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(string $taskPacketId): ?array
    {
        $record = $this->readTaskFile($taskPacketId);

        return $record;
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
        $tag = isset($filters['tag']) ? (string) $filters['tag'] : '';
        $tags = $this->stringList((array) ($filters['tags'] ?? []));
        if ($tag !== '') {
            $tags = array_values(array_unique(array_merge([$tag], $tags)));
        }
        $limit = isset($filters['limit']) ? (int) $filters['limit'] : 0;

        $results = [];
        foreach ($entries as $entry) {
            if ($status !== '' && (string) ($entry['status'] ?? '') !== $status) {
                continue;
            }
            if ($tags !== []) {
                $entryTags = array_values(array_map('strval', (array) ($entry['tags'] ?? [])));
                if (array_diff($tags, $entryTags) !== []) {
                    continue;
                }
            }
            $taskPacketId = (string) ($entry['task_packet_id'] ?? '');
            $record = $this->readTaskFile($taskPacketId);
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
    public function updateStatus(string $taskPacketId, string $status, array $metadata = []): array
    {
        return $this->withLock(function () use ($taskPacketId, $status, $metadata): array {
            if (! in_array($status, self::STATUSES, true)) {
                return $this->envelopeError('invalid_status', $taskPacketId, [
                    'requested_status' => $status,
                    'allowed_statuses' => self::STATUSES,
                ]);
            }

            $record = $this->readTaskFile($taskPacketId);
            if ($record === null) {
                return $this->envelopeError('task_packet_not_found', $taskPacketId);
            }

            $previous = (string) ($record['status'] ?? '');
            if (! $this->statusTransitionAllowed($previous, $status)) {
                return $this->envelopeError('invalid_status_transition', $taskPacketId, [
                    'from' => $previous,
                    'to' => $status,
                    'allowed_next_statuses' => self::ALLOWED_STATUS_TRANSITIONS[$previous] ?? [],
                    'transition_policy_hash' => $this->transitionPolicyHash(),
                ]);
            }

            $transitionValidation = $this->validateTransitionMetadata($status, $metadata);
            if ($transitionValidation !== []) {
                return $this->envelopeError('transition_metadata_missing', $taskPacketId, array_merge([
                    'from' => $previous,
                    'to' => $status,
                    'transition_policy_hash' => $this->transitionPolicyHash(),
                ], $transitionValidation));
            }

            $now = CarbonImmutable::now()->toIso8601String();
            $record['status'] = $status;
            $record['updated_at'] = $now;
            $record['history'][] = [
                'event' => 'status_changed',
                'at' => $now,
                'from' => $previous,
                'to' => $status,
                'metadata' => $metadata,
                'transition_policy_hash' => $this->transitionPolicyHash(),
            ];
            if ($metadata !== []) {
                $record['metadata'] = array_merge((array) ($record['metadata'] ?? []), $metadata);
            }

            $this->writeTaskFile($taskPacketId, $record);
            $this->updateRegistryEntry($taskPacketId, $record);

            return $this->envelopeOk('status_updated', $record);
        });
    }

    /**
     * A2/MF-16 — ATOMIC compare-and-swap of a packet's status under the exclusive lock: flip
     * $expectedStatus → $newStatus ONLY if the record is still exactly $expectedStatus. This is the
     * single-winner reservation primitive: two clients that both selected the same claimable packet can
     * never both flip it to claimed (the loser gets `swapped=false`/`cas_status_mismatch`). The whole
     * read-compare-write happens inside the flock, so it is genuinely atomic across N clients.
     *
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    public function compareAndSwapStatus(string $taskPacketId, string $expectedStatus, string $newStatus, array $metadata = []): array
    {
        return $this->withLock(function () use ($taskPacketId, $expectedStatus, $newStatus, $metadata): array {
            if (! in_array($newStatus, self::STATUSES, true)) {
                return $this->envelopeError('invalid_status', $taskPacketId, [
                    'requested_status' => $newStatus,
                    'allowed_statuses' => self::STATUSES,
                    'swapped' => false,
                ]);
            }

            $record = $this->readTaskFile($taskPacketId);
            if ($record === null) {
                return $this->envelopeError('task_packet_not_found', $taskPacketId, ['swapped' => false]);
            }

            $current = (string) ($record['status'] ?? '');
            if ($current !== $expectedStatus) {
                // The status moved since the caller selected it — the reservation is LOST to another winner.
                return $this->envelopeError('cas_status_mismatch', $taskPacketId, [
                    'expected_status' => $expectedStatus,
                    'actual_status' => $current,
                    'swapped' => false,
                ]);
            }

            if (! $this->statusTransitionAllowed($current, $newStatus)) {
                return $this->envelopeError('invalid_status_transition', $taskPacketId, [
                    'from' => $current,
                    'to' => $newStatus,
                    'allowed_next_statuses' => self::ALLOWED_STATUS_TRANSITIONS[$current] ?? [],
                    'transition_policy_hash' => $this->transitionPolicyHash(),
                    'swapped' => false,
                ]);
            }

            // Same transition-metadata contract as updateStatus (e.g. claimed requires lease_id + agent_id).
            $transitionValidation = $this->validateTransitionMetadata($newStatus, $metadata);
            if ($transitionValidation !== []) {
                return $this->envelopeError('transition_metadata_missing', $taskPacketId, array_merge([
                    'from' => $current,
                    'to' => $newStatus,
                    'transition_policy_hash' => $this->transitionPolicyHash(),
                    'swapped' => false,
                ], $transitionValidation));
            }

            $now = CarbonImmutable::now()->toIso8601String();
            $record['status'] = $newStatus;
            $record['updated_at'] = $now;
            $record['history'][] = [
                'event' => 'status_compare_and_swapped',
                'at' => $now,
                'from' => $current,
                'to' => $newStatus,
                'metadata' => $metadata,
                'transition_policy_hash' => $this->transitionPolicyHash(),
            ];
            if ($metadata !== []) {
                $record['metadata'] = array_merge((array) ($record['metadata'] ?? []), $metadata);
            }

            $this->writeTaskFile($taskPacketId, $record);
            $this->updateRegistryEntry($taskPacketId, $record);

            return $this->envelopeOk('status_compare_and_swapped', $record, ['swapped' => true]);
        });
    }

    /**
     * @param  array<string, mixed>  $receipt
     * @return array<string, mixed>
     */
    public function appendReceipt(string $taskPacketId, array $receipt): array
    {
        return $this->withLock(function () use ($taskPacketId, $receipt): array {
            $record = $this->readTaskFile($taskPacketId);
            if ($record === null) {
                return $this->envelopeError('task_packet_not_found', $taskPacketId);
            }

            $now = CarbonImmutable::now()->toIso8601String();
            $receipt['receipt_kind'] = (string) ($receipt['receipt_kind'] ?? 'unknown');
            $receipt['recorded_at'] = $now;
            $receipt['runtime_execution_allowed'] = false;
            $receipt['ledger_write_allowed'] = false;
            $receipt['receipt_hash'] = $this->stableHash([
                'task_packet_id' => $taskPacketId,
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

            $this->writeTaskFile($taskPacketId, $record);
            $this->updateRegistryEntry($taskPacketId, $record);

            return $this->envelopeOk('receipt_appended', $record, [
                'receipt' => $receipt,
            ]);
        });
    }

    /**
     * Replace a blocked packet with a rebuilt, self-sufficient packet while preserving its task id and history.
     * This is the safe repair path for task-serving backlog: dependencies keep pointing at the same id, but the
     * worker receives a fresh committable scope.
     *
     * @param  array<string, mixed>  $taskPacket
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    public function replaceBlockedTaskPacket(string $taskPacketId, array $taskPacket, array $metadata = []): array
    {
        return $this->withLock(function () use ($taskPacketId, $taskPacket, $metadata): array {
            $record = $this->readTaskFile($taskPacketId);
            if ($record === null) {
                return $this->envelopeError('task_packet_not_found', $taskPacketId);
            }
            $current = (string) ($record['status'] ?? '');
            if ($current !== 'blocked') {
                return $this->envelopeError('task_packet_not_blocked', $taskPacketId, [
                    'actual_status' => $current,
                ]);
            }
            if ((string) ($taskPacket['task_packet_id'] ?? '') !== $taskPacketId) {
                return $this->envelopeError('task_packet_id_mismatch', $taskPacketId, [
                    'incoming_task_packet_id' => (string) ($taskPacket['task_packet_id'] ?? ''),
                ]);
            }
            if ((string) ($taskPacket['status'] ?? '') !== 'planned' || (string) ($taskPacket['task_packet_hash'] ?? '') === '') {
                return $this->envelopeError('replacement_packet_not_planned', $taskPacketId, [
                    'incoming_status' => (string) ($taskPacket['status'] ?? ''),
                ]);
            }

            $now = CarbonImmutable::now()->toIso8601String();
            $previousHash = (string) ($record['task_packet_hash'] ?? '');
            $newHash = (string) $taskPacket['task_packet_hash'];
            $record['task_packet'] = $taskPacket;
            $record['task_packet_hash'] = $newHash;
            $record['status'] = 'claimable';
            $record['updated_at'] = $now;
            $record['metadata'] = array_merge((array) ($record['metadata'] ?? []), $metadata, [
                'previous_task_packet_hash' => $previousHash,
                'repair_task_packet_hash' => $newHash,
            ]);
            $record['history'][] = [
                'event' => 'task_packet_repaired_and_reopened',
                'at' => $now,
                'from' => 'blocked',
                'to' => 'claimable',
                'metadata' => $metadata,
                'previous_task_packet_hash' => $previousHash,
                'task_packet_hash' => $newHash,
                'transition_policy_hash' => $this->transitionPolicyHash(),
            ];

            $this->writeTaskFile($taskPacketId, $record);
            $this->updateRegistryEntry($taskPacketId, $record);

            return $this->envelopeOk('task_packet_repaired_and_reopened', $record, [
                'previous_task_packet_hash' => $previousHash,
                'task_packet_hash' => $newHash,
            ]);
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
        $cap = isset($filters['cap']) ? (int) $filters['cap'] : self::DEFAULT_REGISTRY_CAP;
        $corrupt = (bool) ($registry['corrupt'] ?? false);

        if ($status !== '') {
            $entries = array_values(array_filter(
                $entries,
                static fn (array $entry): bool => (string) ($entry['status'] ?? '') === $status,
            ));
        }

        $statusCounts = [];
        foreach ((array) ($registry['entries'] ?? []) as $entry) {
            $key = (string) ($entry['status'] ?? 'unknown');
            $statusCounts[$key] = ($statusCounts[$key] ?? 0) + 1;
        }
        ksort($statusCounts);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'storage_prefix' => self::STORAGE_PREFIX,
            'registry_path' => self::REGISTRY_PATH,
            'entry_count' => count($entries),
            'total_count' => count((array) ($registry['entries'] ?? [])),
            'capped_to' => $cap,
            'corrupt' => $corrupt,
            'status_counts' => $statusCounts,
            'allowed_statuses' => self::STATUSES,
            'entries' => $entries,
            'status_transition_policy' => self::ALLOWED_STATUS_TRANSITIONS,
            'status_transition_policy_hash' => $this->transitionPolicyHash(),
            'claim_transition_requires_lease_id' => true,
            'claim_transition_requires_agent_id' => true,
            'queue_write_lock_required' => true,
            'queue_write_lock_path' => self::LOCK_PATH,
            'queue_write_lock_acquire_timeout_seconds' => self::LOCK_ACQUIRE_TIMEOUT_SECONDS,
            'queue_write_lock_stale_after_seconds' => self::LOCK_STALE_AFTER_SECONDS,
            'queue_write_lock_timeout_blocks_mutation' => true,
            'queue_write_lock_owner_token_required_for_release' => true,
            'runtime_execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'token_spend_allowed' => false,
            'provider_call_allowed' => false,
            'self_programming_allowed' => false,
        ];
    }

    /**
     * Prune queue records that match explicit tags or id prefixes.
     *
     * This is intentionally narrow and defaults to preserving claimed packets
     * so certification probes can clean their own run-scoped artifacts without
     * endangering live worker leases or real operator tasks.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function prune(array $filters = []): array
    {
        return $this->withLock(function () use ($filters): array {
            $tags = $this->stringList((array) ($filters['tags'] ?? []));
            $prefixes = $this->stringList((array) ($filters['task_packet_id_prefixes'] ?? []));
            $deleteTaskFiles = (bool) ($filters['delete_task_files'] ?? false);
            $preserveStatuses = $this->stringList((array) ($filters['preserve_statuses'] ?? ['claimed']));

            if ($tags === [] && $prefixes === []) {
                return [
                    'schema_version' => self::SCHEMA_VERSION,
                    'status' => 'blocked',
                    'event' => 'prune_blocked',
                    'reason' => 'prune_requires_tag_or_task_packet_id_prefix',
                    'pruned_count' => 0,
                    'deleted_task_file_count' => 0,
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
            $deletedTaskFileCount = 0;
            $preservedCount = 0;

            foreach ($entries as $entry) {
                $taskPacketId = (string) ($entry['task_packet_id'] ?? '');
                $status = (string) ($entry['status'] ?? '');
                $matches = $this->entryMatchesPruneFilters($entry, $tags, $prefixes);
                if (! $matches) {
                    $kept[] = $entry;

                    continue;
                }
                if (in_array($status, $preserveStatuses, true)) {
                    $kept[] = $entry;
                    $preservedCount++;

                    continue;
                }

                $pruned[] = $entry;
                if ($deleteTaskFiles && $taskPacketId !== '') {
                    $path = $this->taskPath($taskPacketId);
                    if ($this->disk()->exists($path)) {
                        $this->disk()->delete($path);
                        $deletedTaskFileCount++;
                    }
                }
            }

            $registry['entries'] = $kept;
            $registry['last_pruned_at'] = CarbonImmutable::now()->toIso8601String();
            $registry['last_prune'] = [
                'matched_tags' => $tags,
                'matched_task_packet_id_prefixes' => $prefixes,
                'delete_task_files' => $deleteTaskFiles,
                'preserve_statuses' => $preserveStatuses,
                'pruned_count' => count($pruned),
                'deleted_task_file_count' => $deletedTaskFileCount,
                'preserved_count' => $preservedCount,
            ];
            $this->saveRegistry($registry);

            return [
                'schema_version' => self::SCHEMA_VERSION,
                'status' => 'ok',
                'event' => 'pruned',
                'pruned_count' => count($pruned),
                'deleted_task_file_count' => $deletedTaskFileCount,
                'preserved_count' => $preservedCount,
                'matched_tags' => $tags,
                'matched_task_packet_id_prefixes' => $prefixes,
                'delete_task_files' => $deleteTaskFiles,
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
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    /**
     * A2/MF-16 — run $callback holding a REAL exclusive OS lock (`flock(LOCK_EX)`), FAIL-CLOSED.
     *
     * Replaces the old `Storage::exists/put` check-then-act, whose non-atomic check window let two processes
     * both pass `! exists` before either `put` => concurrent writers to the shared registry.json lost updates
     * (the select+claim+updateStatus TOCTOU). A real flock makes the whole select→reserve→status-flip path
     * serialized. On timeout it returns the SAME `queue_lock_busy` blocked envelope as before (never runs the
     * mutation unlocked); the `finally` releases only THIS call's handle and never deletes a foreign lock.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T|array<string, mixed>
     */
    private function withLock(callable $callback): mixed
    {
        $path = $this->lockFilePath();
        $dir = \dirname($path);
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $handle = @fopen($path, 'c');
        if ($handle === false) {
            return $this->queueLockBusyEnvelope();
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

            return $this->queueLockBusyEnvelope();
        }

        try {
            return $callback();
        } finally {
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

    /** @return array<string, mixed> the FAIL-CLOSED blocked envelope when the queue lock can't be acquired */
    private function queueLockBusyEnvelope(): array
    {
        return $this->envelopeError('queue_lock_busy', '', [
            'lock_path' => self::LOCK_PATH,
            'lock_acquire_timeout_seconds' => $this->lockTimeoutSeconds,
            'lock_stale_after_seconds' => self::LOCK_STALE_AFTER_SECONDS,
            'queue_write_lock_required' => true,
            'mutation_blocked_until_lock_acquired' => true,
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readTaskFile(string $taskPacketId): ?array
    {
        if ($taskPacketId === '') {
            return null;
        }
        $path = $this->taskPath($taskPacketId);
        $disk = $this->disk();
        if (! $disk->exists($path)) {
            return null;
        }
        $raw = (string) $disk->get($path);
        try {
            $decoded = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return [
                'task_packet_id' => $taskPacketId,
                'corrupt' => true,
                'corrupt_reason' => 'invalid_json',
                'schema_version' => self::SCHEMA_VERSION,
            ];
        }
        if (! is_array($decoded)) {
            return [
                'task_packet_id' => $taskPacketId,
                'corrupt' => true,
                'corrupt_reason' => 'non_array_payload',
                'schema_version' => self::SCHEMA_VERSION,
            ];
        }

        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function writeTaskFile(string $taskPacketId, array $record): void
    {
        $this->disk()->put($this->taskPath($taskPacketId), $this->encode($record));
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function registerInRegistry(array $record): void
    {
        $registry = $this->loadRegistry();
        $registry['entries'][] = [
            'task_packet_id' => (string) ($record['task_packet_id'] ?? ''),
            'task_packet_hash' => (string) ($record['task_packet_hash'] ?? ''),
            'enqueued_at' => (string) ($record['enqueued_at'] ?? ''),
            'updated_at' => (string) ($record['updated_at'] ?? ''),
            'status' => (string) ($record['status'] ?? ''),
            'priority' => (int) ($record['priority'] ?? 0),
            'tags' => (array) ($record['tags'] ?? []),
        ];
        $registry = $this->capRegistry($registry, self::DEFAULT_REGISTRY_CAP);
        $this->saveRegistry($registry);
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function updateRegistryEntry(string $taskPacketId, array $record): void
    {
        $registry = $this->loadRegistry();
        $entries = (array) ($registry['entries'] ?? []);
        $found = false;
        foreach ($entries as $i => $entry) {
            if ((string) ($entry['task_packet_id'] ?? '') === $taskPacketId) {
                $entries[$i]['status'] = (string) ($record['status'] ?? '');
                $entries[$i]['updated_at'] = (string) ($record['updated_at'] ?? '');
                $found = true;
                break;
            }
        }
        if (! $found) {
            $entries[] = [
                'task_packet_id' => $taskPacketId,
                'task_packet_hash' => (string) ($record['task_packet_hash'] ?? ''),
                'enqueued_at' => (string) ($record['enqueued_at'] ?? ''),
                'updated_at' => (string) ($record['updated_at'] ?? ''),
                'status' => (string) ($record['status'] ?? ''),
                'priority' => (int) ($record['priority'] ?? 0),
                'tags' => (array) ($record['tags'] ?? []),
            ];
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
        $this->disk()->put(self::REGISTRY_PATH, $this->encode($registry));
    }

    /**
     * @param  array<string, mixed>  $registry
     * @return array<string, mixed>
     */
    /**
     * Bound the registry WITHOUT ever losing live work. The old FIFO `array_slice(-$cap)` evicted the OLDEST
     * entries regardless of status — so once the queue passed the cap, claimable tasks silently fell out of the
     * index and became invisible to list()/next()/health (confirmed live: 576 task files, 201 indexed → 323
     * claimable tasks lost). The cap exists to bound TERMINAL history, not to drop servable work. So: keep ALL
     * non-terminal entries (queued/claimable/claimed/lease_expired/released/blocked) always; evict only the
     * oldest TERMINAL (completed_dry_run/cancelled) entries to fit the cap. If live work alone exceeds the cap,
     * the registry grows past it (correctness over a fixed size) — never a lost claimable task.
     */
    private function capRegistry(array $registry, int $cap): array
    {
        $entries = array_values((array) ($registry['entries'] ?? []));
        if ($cap > 0 && count($entries) > $cap) {
            $terminal = ['completed_dry_run', 'cancelled'];
            $live = [];
            $done = [];
            foreach ($entries as $entry) {
                if (in_array((string) ($entry['status'] ?? ''), $terminal, true)) {
                    $done[] = $entry;
                } else {
                    $live[] = $entry;
                }
            }
            $roomForTerminal = max(0, $cap - count($live));
            $done = $roomForTerminal > 0 ? array_slice($done, -$roomForTerminal) : [];
            $entries = array_merge($live, $done);
        }
        $registry['entries'] = $entries;
        unset($registry['corrupt']);

        return $registry;
    }

    /**
     * REPAIR — rebuild the registry index from the task files on disk (the source of truth). Recovers any task
     * whose index entry was evicted by the old FIFO cap (silently invisible to serving). Atomic under the queue
     * lock, so a concurrent claim/enqueue can never race the rebuild. Idempotent.
     *
     * @return array<string, mixed>
     */
    public function rebuildRegistryFromDisk(): array
    {
        return $this->withLock(function (): array {
            $disk = $this->disk();
            $before = count((array) ($this->loadRegistry()['entries'] ?? []));

            $entries = [];
            $statusCounts = [];
            foreach ($disk->files(self::STORAGE_PREFIX) as $path) {
                $base = basename($path);
                if (! str_starts_with($base, 'task_') || ! str_ends_with($base, '.json')) {
                    continue; // skip registry.json / .lock / .health
                }
                try {
                    $record = json_decode((string) $disk->get($path), true, flags: JSON_THROW_ON_ERROR);
                } catch (Throwable) {
                    continue; // a corrupt task file never blocks the rebuild
                }
                if (! is_array($record) || (string) ($record['task_packet_id'] ?? '') === '') {
                    continue;
                }
                $status = (string) ($record['status'] ?? '');
                $statusCounts[$status] = ($statusCounts[$status] ?? 0) + 1;
                $entries[] = [
                    'task_packet_id' => (string) $record['task_packet_id'],
                    'task_packet_hash' => (string) ($record['task_packet_hash'] ?? ''),
                    'enqueued_at' => (string) ($record['enqueued_at'] ?? ''),
                    'updated_at' => (string) ($record['updated_at'] ?? ''),
                    'status' => $status,
                    'priority' => (int) ($record['priority'] ?? 0),
                    'tags' => array_values(array_map('strval', (array) ($record['tags'] ?? []))),
                ];
            }

            $registry = $this->capRegistry(['entries' => $entries], self::DEFAULT_REGISTRY_CAP);
            $registry['rebuilt_at'] = CarbonImmutable::now()->toIso8601String();
            $this->saveRegistry($registry);
            ksort($statusCounts);

            return [
                'schema_version' => self::SCHEMA_VERSION,
                'status' => 'ok',
                'event' => 'registry_rebuilt_from_disk',
                'entries_before' => $before,
                'task_files_scanned' => count($entries),
                'entries_after' => count((array) $registry['entries']),
                'recovered' => max(0, count((array) $registry['entries']) - $before),
                'status_counts' => $statusCounts,
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $entry
     * @param  list<string>  $tags
     * @param  list<string>  $prefixes
     */
    private function entryMatchesPruneFilters(array $entry, array $tags, array $prefixes): bool
    {
        $entryTags = array_values(array_map('strval', (array) ($entry['tags'] ?? [])));
        if ($tags !== [] && array_intersect($tags, $entryTags) !== []) {
            return true;
        }

        $taskPacketId = (string) ($entry['task_packet_id'] ?? '');
        foreach ($prefixes as $prefix) {
            if ($prefix !== '' && str_starts_with($taskPacketId, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function taskPath(string $taskPacketId): string
    {
        $safe = preg_replace('/[^A-Za-z0-9_\-]/', '_', $taskPacketId) ?? $taskPacketId;

        return self::STORAGE_PREFIX.'/task_'.$safe.'.json';
    }

    private function statusTransitionAllowed(string $previous, string $next): bool
    {
        if ($previous === $next) {
            return true;
        }

        return in_array($next, self::ALLOWED_STATUS_TRANSITIONS[$previous] ?? [], true);
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function validateTransitionMetadata(string $next, array $metadata): array
    {
        if ($next !== 'claimed') {
            return [];
        }

        $missing = [];
        foreach (['lease_id', 'agent_id'] as $field) {
            if ((string) ($metadata[$field] ?? '') === '') {
                $missing[] = $field;
            }
        }

        return $missing === []
            ? []
            : [
                'missing_metadata' => $missing,
                'claim_transition_requires_lease_id' => true,
                'claim_transition_requires_agent_id' => true,
            ];
    }

    private function transitionPolicyHash(): string
    {
        return $this->stableHash(self::ALLOWED_STATUS_TRANSITIONS);
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
            'task_packet_id' => (string) ($record['task_packet_id'] ?? ''),
            'record_status' => (string) ($record['status'] ?? ''),
            'record' => $record,
            'runtime_execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
        ], $extra);
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function envelopeError(string $reason, string $taskPacketId, array $extra = []): array
    {
        return array_merge([
            'schema_version' => self::SCHEMA_VERSION,
            'status' => 'blocked',
            'event' => 'blocked',
            'task_packet_id' => $taskPacketId,
            'reason' => $reason,
            'runtime_execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
        ], $extra);
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
     * @param  array<mixed, mixed>  $payload
     */
    private function stableHash(array $payload): string
    {
        return hash('sha256', (string) json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function disk(): Filesystem
    {
        return Storage::disk($this->disk ?? self::DEFAULT_DISK);
    }

    /**
     * @param  array<int, mixed>  $values
     * @return list<string>
     */
    private function stringList(array $values): array
    {
        return array_values(array_filter(array_map(
            static fn (mixed $value): string => trim((string) $value),
            $values,
        ), static fn (string $value): bool => $value !== ''));
    }
}
