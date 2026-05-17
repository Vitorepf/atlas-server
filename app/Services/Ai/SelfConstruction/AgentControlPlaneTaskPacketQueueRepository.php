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

    public const LOCK_ACQUIRE_TIMEOUT_SECONDS = 4.0;

    public const LOCK_STALE_AFTER_SECONDS = 60;

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

    public function __construct(
        private readonly ?string $disk = null,
    ) {}

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
    private function withLock(callable $callback): mixed
    {
        $disk = $this->disk();
        $start = microtime(true);
        $lockToken = (string) Str::uuid();
        $acquired = false;

        while (true) {
            if (! $disk->exists(self::LOCK_PATH)) {
                $disk->put(self::LOCK_PATH, $this->encodeLockPayload($lockToken));
                $currentToken = $this->lockTokenFromContent((string) $disk->get(self::LOCK_PATH));
                if ($currentToken === $lockToken) {
                    $acquired = true;
                    break;
                }
            }
            if ($disk->exists(self::LOCK_PATH) && $this->lockIsStale()) {
                $disk->delete(self::LOCK_PATH);

                continue;
            }
            if ((microtime(true) - $start) > self::LOCK_ACQUIRE_TIMEOUT_SECONDS) {
                return $this->envelopeError('queue_lock_busy', '', [
                    'lock_path' => self::LOCK_PATH,
                    'lock_acquire_timeout_seconds' => self::LOCK_ACQUIRE_TIMEOUT_SECONDS,
                    'lock_stale_after_seconds' => self::LOCK_STALE_AFTER_SECONDS,
                    'queue_write_lock_required' => true,
                    'mutation_blocked_until_lock_acquired' => true,
                    'lock_owner_token_required_for_release' => true,
                ]);
            }
            usleep(50_000);
        }

        try {
            return $callback();
        } finally {
            if (
                $acquired
                && $disk->exists(self::LOCK_PATH)
                && $this->lockTokenFromContent((string) $disk->get(self::LOCK_PATH)) === $lockToken
            ) {
                $disk->delete(self::LOCK_PATH);
            }
        }
    }

    private function encodeLockPayload(string $lockToken): string
    {
        return $this->encode([
            'schema_version' => self::SCHEMA_VERSION,
            'lock_token' => $lockToken,
            'acquired_at' => CarbonImmutable::now()->toIso8601String(),
            'acquired_at_unix' => CarbonImmutable::now()->getTimestamp(),
            'stale_after_seconds' => self::LOCK_STALE_AFTER_SECONDS,
            'owner_token_required_for_release' => true,
        ]);
    }

    private function lockTokenFromContent(string $content): string
    {
        try {
            $decoded = json_decode($content, true, flags: JSON_THROW_ON_ERROR);
            if (is_array($decoded)) {
                return trim((string) ($decoded['lock_token'] ?? ''));
            }
        } catch (Throwable) {
            // Backward-compatible with legacy plain-token lock files.
        }

        return trim($content);
    }

    private function lockIsStale(): bool
    {
        try {
            $decoded = json_decode((string) $this->disk()->get(self::LOCK_PATH), true, flags: JSON_THROW_ON_ERROR);
            if (is_array($decoded) && (int) ($decoded['acquired_at_unix'] ?? 0) > 0) {
                return (CarbonImmutable::now()->getTimestamp() - (int) $decoded['acquired_at_unix']) > self::LOCK_STALE_AFTER_SECONDS;
            }
        } catch (Throwable) {
            // Fall back to filesystem metadata for legacy plain-token locks.
        }

        try {
            $modifiedAt = $this->disk()->lastModified(self::LOCK_PATH);
        } catch (Throwable) {
            return false;
        }

        return $modifiedAt > 0
            && (CarbonImmutable::now()->getTimestamp() - $modifiedAt) > self::LOCK_STALE_AFTER_SECONDS;
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
