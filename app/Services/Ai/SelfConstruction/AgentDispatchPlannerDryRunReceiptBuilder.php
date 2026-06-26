<?php

namespace App\Services\Ai\SelfConstruction;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;
use App\Services\Ai\SelfConstruction\Support\EncodesPayloadAsPrettyJson;

/**
 * Build and persist local dry-run dispatch receipts.
 *
 * These receipts live under
 * `atlas/self-construction/agent-control-plane/dispatch-planner/receipts/`
 * and are NEVER the evidence ledger and NEVER a real claim/lease
 * receipt. They are local audit-only artifacts that prove a planned
 * dispatch was considered, without authorizing or executing it.
 *
 * Runtime-safe: never claims, never dispatches, never calls providers,
 * never spends tokens, never writes the evidence ledger.
 */
final class AgentDispatchPlannerDryRunReceiptBuilder
{
    use EncodesPayloadAsPrettyJson;
    public const SCHEMA_VERSION = 'atlas.self_construction.agent_dispatch_planner_dry_run_receipt.v1';

    public const MODE = 'persistent_local_agent_dispatch_planner_dry_run_receipt';

    public const STORAGE_PREFIX = 'atlas/self-construction/agent-control-plane/dispatch-planner/receipts';

    public const INDEX_PATH = self::STORAGE_PREFIX.'/index.json';

    public const LOCK_PATH = self::STORAGE_PREFIX.'/.lock';

    public const DEFAULT_DISK = 'local';

    public const DEFAULT_INDEX_CAP = 500;

    public const RECEIPT_KIND = 'dispatch_plan_dry_run';

    public function __construct(
        private readonly ?string $disk = null,
    ) {}

    /**
     * @param  array<string, mixed>  $plannedDispatch
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function build(array $plannedDispatch, array $options = []): array
    {
        return $this->withLock(function () use ($plannedDispatch, $options): array {
            $taskPacketId = (string) ($plannedDispatch['task_packet_id'] ?? '');
            $taskPacketHash = (string) ($plannedDispatch['task_packet_hash'] ?? '');
            $agentId = (string) ($plannedDispatch['agent_id'] ?? '');

            if ($taskPacketId === '') {
                return $this->envelopeError('task_packet_id_missing');
            }
            if ($agentId === '') {
                return $this->envelopeError('agent_id_missing');
            }

            $scopeLock = (array) ($plannedDispatch['scope_lock'] ?? []);
            $writeSet = $this->normalizeSet((array) ($scopeLock['write_set'] ?? []));
            $readSet = $this->normalizeSet((array) ($scopeLock['read_set'] ?? []));

            $now = CarbonImmutable::now()->toIso8601String();
            $receiptId = (string) ($options['receipt_id'] ?? Str::uuid());

            $hashPayload = [
                'task_packet_id' => $taskPacketId,
                'task_packet_hash' => $taskPacketHash,
                'agent_id' => $agentId,
                'risk_level' => (string) ($plannedDispatch['risk_level'] ?? 'low'),
                'workspace_policy' => (string) ($plannedDispatch['workspace_policy'] ?? 'none'),
                'requires_lease' => (bool) ($plannedDispatch['requires_lease'] ?? false),
                'dry_run_only' => (bool) ($plannedDispatch['dry_run_only'] ?? false),
                'write_set' => $writeSet,
                'read_set' => $readSet,
                'matching_policy' => (string) ($options['matching_policy'] ?? ($plannedDispatch['matching_policy'] ?? '')),
                'evidence_refs' => $this->normalizeSet((array) ($plannedDispatch['evidence_refs'] ?? [])),
            ];
            $receiptHash = $this->stableHash($hashPayload);

            $record = array_merge(['receipt_id' => $receiptId], $hashPayload, [
                'schema_version' => self::SCHEMA_VERSION,
                'mode' => self::MODE,
                'receipt_kind' => self::RECEIPT_KIND,
                'recorded_at' => $now,
                'receipt_hash' => $receiptHash,
                'is_dispatched' => false,
                'is_real_claim' => false,
                'is_real_receipt' => false,
                'runtime_execution_allowed' => false,
                'dispatch_allowed' => false,
                'provider_call_allowed' => false,
                'token_spend_allowed' => false,
                'self_programming_allowed' => false,
                'ledger_write_allowed' => false,
                'claim_real_allowed' => false,
            ]);

            $this->writeReceipt($receiptId, $record);
            $this->updateIndex($record);

            return [
                'schema_version' => self::SCHEMA_VERSION,
                'status' => 'ok',
                'event' => 'receipt_built',
                'receipt_id' => $receiptId,
                'receipt_hash' => $receiptHash,
                'record' => $record,
                'runtime_execution_allowed' => false,
                'dispatch_allowed' => false,
                'provider_call_allowed' => false,
                'token_spend_allowed' => false,
                'self_programming_allowed' => false,
                'ledger_write_allowed' => false,
                'claim_real_allowed' => false,
            ];
        });
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(string $receiptId): ?array
    {
        $path = $this->receiptPath($receiptId);
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
        if (! is_array($decoded)) {
            return null;
        }

        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    public function list(array $filters = []): array
    {
        $taskFilter = isset($filters['task_packet_id']) ? (string) $filters['task_packet_id'] : '';
        $agentFilter = isset($filters['agent_id']) ? (string) $filters['agent_id'] : '';
        $limit = isset($filters['limit']) ? (int) $filters['limit'] : 0;

        $results = [];
        foreach ($this->loadIndex() as $entry) {
            if ($taskFilter !== '' && (string) ($entry['task_packet_id'] ?? '') !== $taskFilter) {
                continue;
            }
            if ($agentFilter !== '' && (string) ($entry['agent_id'] ?? '') !== $agentFilter) {
                continue;
            }
            $receiptId = (string) ($entry['receipt_id'] ?? '');
            $record = $this->get($receiptId);
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
            'claim_real_allowed' => false,
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
     */
    private function writeReceipt(string $receiptId, array $record): void
    {
        $this->disk()->put($this->receiptPath($receiptId), $this->encode($record));
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function updateIndex(array $record): void
    {
        $index = $this->loadIndex();
        $entryPayload = [
            'receipt_id' => (string) ($record['receipt_id'] ?? ''),
            'task_packet_id' => (string) ($record['task_packet_id'] ?? ''),
            'agent_id' => (string) ($record['agent_id'] ?? ''),
            'recorded_at' => (string) ($record['recorded_at'] ?? ''),
            'receipt_hash' => (string) ($record['receipt_hash'] ?? ''),
        ];
        $index[] = $entryPayload;
        if (count($index) > self::DEFAULT_INDEX_CAP) {
            $index = array_slice($index, -self::DEFAULT_INDEX_CAP);
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

    private function receiptPath(string $receiptId): string
    {
        $safe = preg_replace('/[^A-Za-z0-9_\-]/', '_', $receiptId) ?? $receiptId;

        return self::STORAGE_PREFIX.'/receipt_'.$safe.'.json';
    }

    /**
     * @param  array<int, mixed>  $values
     * @return list<string>
     */
    private function normalizeSet(array $values): array
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
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function envelopeError(string $reason, array $extra = []): array
    {
        return array_merge([
            'schema_version' => self::SCHEMA_VERSION,
            'status' => 'blocked',
            'event' => 'blocked',
            'reason' => $reason,
            'runtime_execution_allowed' => false,
            'dispatch_allowed' => false,
            'provider_call_allowed' => false,
            'token_spend_allowed' => false,
            'self_programming_allowed' => false,
            'ledger_write_allowed' => false,
            'claim_real_allowed' => false,
        ], $extra);
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
}
