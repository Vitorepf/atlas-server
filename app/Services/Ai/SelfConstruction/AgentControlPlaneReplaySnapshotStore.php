<?php

namespace App\Services\Ai\SelfConstruction;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Persists deterministic chain replay snapshots locally so the Agent Control
 * Plane can certify macro-sprints by before/after comparison. The store never
 * starts processes, never calls Codex CLI/app, never spawns subprocesses,
 * never invokes adapters, never dispatches work, never spends tokens, never
 * advances the next required slice, never enables self-programming and never
 * writes the ledger.
 */
final class AgentControlPlaneReplaySnapshotStore
{
    public const SCHEMA_VERSION = 'atlas.self_construction.agent_control_plane_replay_snapshot.v1';

    public const MODE = 'read_only_agent_control_plane_replay_snapshot_store';

    public const STORAGE_PREFIX = 'atlas/self-construction/agent-control-plane/replay-snapshots';

    public const REGISTRY_PATH = self::STORAGE_PREFIX.'/registry.json';

    public const DEFAULT_KEEP = 20;

    public const DEFAULT_DISK = 'local';

    public function __construct(
        private readonly ?string $disk = null,
    ) {}

    /**
     * @param  array<string, mixed>  $replay
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function put(array $replay, array $options = []): array
    {
        $label = isset($options['label']) && is_string($options['label']) ? trim($options['label']) : '';
        $keep = isset($options['keep']) && is_int($options['keep']) && $options['keep'] >= 0
            ? $options['keep']
            : self::DEFAULT_KEEP;

        $snapshotId = $this->makeSnapshotId();
        $createdAt = CarbonImmutable::now()->toIso8601String();
        $proofBundle = (array) data_get($replay, 'proof_bundle', []);

        $snapshot = [
            'snapshot_id' => $snapshotId,
            'schema_version' => self::SCHEMA_VERSION,
            'created_at' => $createdAt,
            'label' => $label,
            'replay_hash' => (string) data_get($replay, 'replay_hash', ''),
            'deterministic_replay_hash' => (string) data_get($replay, 'deterministic_replay_hash', ''),
            'proof_bundle_hash' => (string) data_get($replay, 'proof_bundle_hash', ''),
            'current_pointer' => (string) data_get($replay, 'current_pointer', ''),
            'expected_pointer' => (string) data_get($replay, 'expected_pointer', ''),
            'next_build_slices' => (array) data_get($replay, 'next_build_slices', []),
            'not_yet_runtime_capable' => (array) data_get($replay, 'not_yet_runtime_capable', []),
            'chain_integrity_hash' => (string) data_get($replay, 'chain_integrity_hash', ''),
            'replayed_slice_count' => (int) data_get($replay, 'replayed_slice_count', 0),
            'replayed_edge_count' => (int) data_get($replay, 'replayed_edge_count', 0),
            'runtime_safety_all_false' => (bool) data_get($replay, 'runtime_safety.runtime_safety_all_false', false),
            'violation_count' => count((array) data_get($replay, 'violations', [])),
            'warning_count' => count((array) data_get($replay, 'warnings', [])),
            'replay_summary' => [
                'status' => (string) data_get($replay, 'status', ''),
                'mode' => (string) data_get($replay, 'mode', ''),
                'invariants_all_true' => (bool) data_get($replay, 'invariants_all_true', false),
                'next_safe_macro_batch' => (string) data_get($replay, 'next_safe_macro_batch', ''),
                'proof_bundle_keys' => array_values(array_keys($proofBundle)),
                'cycle_ok' => (bool) data_get($replay, 'cycle_integrity.cycle_ok', false),
                'horizon_ok' => (bool) data_get($replay, 'terminal_horizon_analysis.horizon_ok', false),
            ],
            'replay_payload' => $replay,
            'read_only' => true,
            'external_provider_call' => false,
            'token_spend' => false,
            'process_started' => false,
            'dispatch_allowed' => false,
            'self_programming_allowed' => false,
            'execution_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
        ];

        $path = $this->snapshotPath($snapshotId);
        $disk = $this->disk();
        $disk->put($path, $this->encode($snapshot));

        $registry = $this->loadRegistry();
        $registry['entries'][] = [
            'snapshot_id' => $snapshotId,
            'created_at' => $createdAt,
            'label' => $label,
            'deterministic_replay_hash' => (string) data_get($snapshot, 'deterministic_replay_hash', ''),
            'replay_hash' => (string) data_get($snapshot, 'replay_hash', ''),
            'proof_bundle_hash' => (string) data_get($snapshot, 'proof_bundle_hash', ''),
            'current_pointer' => (string) data_get($snapshot, 'current_pointer', ''),
            'violation_count' => (int) $snapshot['violation_count'],
            'warning_count' => (int) $snapshot['warning_count'],
            'runtime_safety_all_false' => (bool) $snapshot['runtime_safety_all_false'],
            'path' => $path,
        ];

        $registry = $this->capRegistry($registry, $keep);
        $this->saveRegistry($registry);

        return [
            'snapshot_id' => $snapshotId,
            'path' => $path,
            'created_at' => $createdAt,
            'snapshot' => $snapshot,
            'registry_size' => count($registry['entries']),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(string $snapshotId): ?array
    {
        $path = $this->snapshotPath($snapshotId);
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
     * @return array<string, mixed>|null
     */
    public function latest(): ?array
    {
        $registry = $this->loadRegistry();
        $entries = $registry['entries'] ?? [];

        if ($entries === []) {
            return null;
        }

        $last = end($entries);
        if (! is_array($last) || ! isset($last['snapshot_id'])) {
            return null;
        }

        return $this->get((string) $last['snapshot_id']);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function registry(array $options = []): array
    {
        $limit = isset($options['limit']) && is_int($options['limit']) && $options['limit'] > 0
            ? $options['limit']
            : null;

        $registry = $this->loadRegistry();
        $entries = $registry['entries'] ?? [];

        if ($limit !== null && count($entries) > $limit) {
            $entries = array_slice($entries, -$limit);
        }

        return [
            'entry_count' => count($entries),
            'entries' => array_values($entries),
            'storage_prefix' => self::STORAGE_PREFIX,
            'registry_path' => self::REGISTRY_PATH,
            'keep_default' => self::DEFAULT_KEEP,
            'corrupt' => (bool) ($registry['corrupt'] ?? false),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function prune(int $keep = self::DEFAULT_KEEP): array
    {
        if ($keep < 0) {
            $keep = 0;
        }

        $registry = $this->loadRegistry();
        $beforeCount = count($registry['entries'] ?? []);
        $registry = $this->capRegistry($registry, $keep);
        $this->saveRegistry($registry);

        return [
            'before_count' => $beforeCount,
            'after_count' => count($registry['entries']),
            'kept' => $keep,
            'removed_count' => max(0, $beforeCount - count($registry['entries'])),
        ];
    }

    /**
     * @param  array<string, mixed>  $registry
     * @return array<string, mixed>
     */
    private function capRegistry(array $registry, int $keep): array
    {
        $entries = array_values((array) ($registry['entries'] ?? []));
        if (count($entries) > $keep) {
            $excess = array_slice($entries, 0, count($entries) - $keep);
            foreach ($excess as $entry) {
                $path = (string) data_get($entry, 'path', '');
                if ($path !== '' && $this->disk()->exists($path)) {
                    $this->disk()->delete($path);
                }
            }
            $entries = $keep === 0 ? [] : array_slice($entries, -$keep);
        }
        $registry['entries'] = $entries;
        unset($registry['corrupt']);

        return $registry;
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

    private function snapshotPath(string $snapshotId): string
    {
        return self::STORAGE_PREFIX.'/'.$snapshotId.'.json';
    }

    private function makeSnapshotId(): string
    {
        return 'snap_'.(string) Str::ulid();
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

    private function disk(): Filesystem
    {
        return Storage::disk($this->disk ?? self::DEFAULT_DISK);
    }
}
