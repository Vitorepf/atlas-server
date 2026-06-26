<?php

namespace App\Services\Ai\SelfConstruction;


use App\Services\Ai\SelfConstruction\Support\KsortsArraysByReference;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Persistent local dry-run journal for agent runtime evidence.
 *
 * This is not the Evidence Ledger. It stores local evidence envelopes that
 * can later be reviewed, summarized and promoted only by a separate signed
 * gate. It never dispatches work, never calls a provider and never writes
 * the canonical ledger.
 */
final class AgentRuntimeEvidenceJournalRepository
{
    use KsortsArraysByReference;


    /**
     * @param  array<string,mixed>  $value
     * @return array<string,mixed>
     */
    private function ksortRecursive(array $value): array
    {
        $this->ksortRecursiveByReference($value);

        return $value;
    }
    public const SCHEMA_VERSION = 'atlas.self_construction.agent_runtime_evidence_journal.v1';

    public const MODE = 'persistent_local_agent_runtime_evidence_journal';

    public const STORAGE_PREFIX = 'atlas/self-construction/agent-control-plane/runtime-evidence-journal';

    public const INDEX_PATH = self::STORAGE_PREFIX.'/index.json';

    public const LOCK_PATH = self::STORAGE_PREFIX.'/.lock';

    public const DEFAULT_DISK = 'local';

    public const DEFAULT_INDEX_CAP = 1000;

    public const ALLOWED_EVIDENCE_TYPES = [
        'dispatch_plan',
        'claim_lease',
        'scope_lock',
        'validation_result',
        'work_product_manifest',
        'cost_event',
        'continuation_summary',
        'merge_review',
        'operator_note',
    ];

    public function __construct(private readonly ?string $disk = null) {}

    /**
     * @param  array<string, mixed>  $entry
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function append(array $entry, array $options = []): array
    {
        return $this->withLock(function () use ($entry, $options): array {
            $taskPacketId = $this->requiredString($entry, 'task_packet_id');
            if ($taskPacketId === '') {
                return $this->error('task_packet_id_missing');
            }
            $agentId = $this->requiredString($entry, 'agent_id');
            if ($agentId === '') {
                return $this->error('agent_id_missing');
            }
            $evidenceType = $this->requiredString($entry, 'evidence_type');
            if (! in_array($evidenceType, self::ALLOWED_EVIDENCE_TYPES, true)) {
                return $this->error('evidence_type_not_allowed');
            }

            $evidenceHash = strtolower($this->requiredString($entry, 'evidence_hash'));
            if (! $this->isSha256($evidenceHash)) {
                $evidenceHash = $this->stableHash([
                    'evidence_type' => $evidenceType,
                    'evidence_ref' => (string) ($entry['evidence_ref'] ?? ''),
                    'payload' => $entry['payload'] ?? [],
                ]);
            }

            $journalEntryId = (string) ($options['journal_entry_id'] ?? $entry['journal_entry_id'] ?? Str::uuid());
            $now = CarbonImmutable::now()->toIso8601String();
            $record = [
                'schema_version' => self::SCHEMA_VERSION,
                'mode' => self::MODE,
                'journal_entry_id' => $journalEntryId,
                'task_packet_id' => $taskPacketId,
                'task_packet_hash' => strtolower((string) ($entry['task_packet_hash'] ?? '')),
                'agent_id' => $agentId,
                'run_id' => (string) ($entry['run_id'] ?? ''),
                'lease_id' => (string) ($entry['lease_id'] ?? ''),
                'evidence_type' => $evidenceType,
                'evidence_ref' => (string) ($entry['evidence_ref'] ?? ''),
                'evidence_hash' => $evidenceHash,
                'summary' => trim((string) ($entry['summary'] ?? '')),
                'recorded_at' => $now,
                'sequence' => $this->nextSequence(),
                'payload_hash' => $this->stableHash($entry['payload'] ?? []),
                'is_local_dry_run_evidence' => true,
                'is_canonical_evidence_ledger_entry' => false,
                'runtime_execution_allowed' => false,
                'dispatch_allowed' => false,
                'provider_call_allowed' => false,
                'token_spend_allowed' => false,
                'self_programming_allowed' => false,
                'ledger_write_allowed' => false,
                'completion_claim_allowed' => false,
            ];
            $record['journal_entry_hash'] = $this->stableHash($this->hashableRecord($record));

            $this->writeRecord($journalEntryId, $record);
            $this->updateIndex($record);

            return [
                'schema_version' => self::SCHEMA_VERSION,
                'status' => 'journal_entry_recorded',
                'mode' => self::MODE,
                'journal_entry_id' => $journalEntryId,
                'journal_entry_hash' => $record['journal_entry_hash'],
                'record' => $record,
                'runtime_safety' => $this->runtimeFlagsWithAllFalse(),
            ] + $this->runtimeFlags();
        });
    }

    /** @return array<string, mixed>|null */
    public function get(string $journalEntryId): ?array
    {
        $path = $this->recordPath($journalEntryId);
        $disk = $this->disk();
        if (! $disk->exists($path)) {
            return null;
        }

        try {
            $decoded = json_decode((string) $disk->get($path), true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    public function list(array $filters = []): array
    {
        $taskFilter = (string) ($filters['task_packet_id'] ?? '');
        $agentFilter = (string) ($filters['agent_id'] ?? '');
        $typeFilter = (string) ($filters['evidence_type'] ?? '');
        $limit = max(0, (int) ($filters['limit'] ?? 0));
        $records = [];

        foreach ($this->loadIndex() as $entry) {
            if ($taskFilter !== '' && (string) ($entry['task_packet_id'] ?? '') !== $taskFilter) {
                continue;
            }
            if ($agentFilter !== '' && (string) ($entry['agent_id'] ?? '') !== $agentFilter) {
                continue;
            }
            if ($typeFilter !== '' && (string) ($entry['evidence_type'] ?? '') !== $typeFilter) {
                continue;
            }
            $record = $this->get((string) ($entry['journal_entry_id'] ?? ''));
            if ($record === null) {
                continue;
            }
            $records[] = $record;
            if ($limit > 0 && count($records) >= $limit) {
                break;
            }
        }

        return $records;
    }

    /** @return array<string, mixed> */
    public function summary(array $filters = []): array
    {
        $records = $this->list($filters);
        $byType = [];
        $byTask = [];
        $byAgent = [];
        foreach ($records as $record) {
            $type = (string) ($record['evidence_type'] ?? 'unknown');
            $task = (string) ($record['task_packet_id'] ?? 'unknown');
            $agent = (string) ($record['agent_id'] ?? 'unknown');
            $byType[$type] = ($byType[$type] ?? 0) + 1;
            $byTask[$task] = ($byTask[$task] ?? 0) + 1;
            $byAgent[$agent] = ($byAgent[$agent] ?? 0) + 1;
        }
        ksort($byType);
        ksort($byTask);
        ksort($byAgent);

        $summary = [
            'schema_version' => 'atlas.self_construction.agent_runtime_evidence_journal_summary.v1',
            'mode' => 'read_only_agent_runtime_evidence_journal_summary',
            'entry_count' => count($records),
            'by_type' => $byType,
            'by_task_packet' => $byTask,
            'by_agent' => $byAgent,
            'latest_journal_entry_hash' => (string) data_get($records[count($records) - 1] ?? [], 'journal_entry_hash', ''),
            'runtime_safety' => $this->runtimeFlagsWithAllFalse(),
        ];
        $summary['journal_summary_hash'] = $this->stableHash($summary);

        return $summary;
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

    /** @return array<string, bool> */
    public function runtimeFlags(): array
    {
        return [
            'runtime_execution_allowed' => false,
            'dispatch_allowed' => false,
            'provider_call_allowed' => false,
            'token_spend_allowed' => false,
            'self_programming_allowed' => false,
            'ledger_write_allowed' => false,
            'completion_claim_allowed' => false,
        ];
    }

    /** @return array<string, bool> */
    public function runtimeFlagsWithAllFalse(): array
    {
        return ['runtime_safety_all_false' => true] + $this->runtimeFlags();
    }

    public function clear(): void
    {
        $disk = $this->disk();
        $disk->deleteDirectory(self::STORAGE_PREFIX);
    }

    private function writeRecord(string $id, array $record): void
    {
        $this->disk()->put($this->recordPath($id), (string) json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /** @return list<array<string, mixed>> */
    private function loadIndex(): array
    {
        $disk = $this->disk();
        if (! $disk->exists(self::INDEX_PATH)) {
            return [];
        }
        try {
            $decoded = json_decode((string) $disk->get(self::INDEX_PATH), true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return [];
        }

        return is_array($decoded) ? array_values(array_filter($decoded, 'is_array')) : [];
    }

    private function updateIndex(array $record): void
    {
        $index = array_values(array_filter(
            $this->loadIndex(),
            static fn (array $entry): bool => (string) ($entry['journal_entry_id'] ?? '') !== (string) $record['journal_entry_id'],
        ));
        array_unshift($index, [
            'journal_entry_id' => $record['journal_entry_id'],
            'task_packet_id' => $record['task_packet_id'],
            'agent_id' => $record['agent_id'],
            'evidence_type' => $record['evidence_type'],
            'journal_entry_hash' => $record['journal_entry_hash'],
            'recorded_at' => $record['recorded_at'],
        ]);
        $index = array_slice($index, 0, self::DEFAULT_INDEX_CAP);
        $this->disk()->put(self::INDEX_PATH, (string) json_encode($index, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private function recordPath(string $id): string
    {
        return self::STORAGE_PREFIX.'/entries/'.$id.'.json';
    }

    private function nextSequence(): int
    {
        return count($this->loadIndex()) + 1;
    }

    private function requiredString(array $payload, string $key): string
    {
        return trim((string) ($payload[$key] ?? ''));
    }

    private function isSha256(string $value): bool
    {
        return preg_match('/^[a-f0-9]{64}$/', $value) === 1;
    }

    private function hashableRecord(array $record): array
    {
        unset($record['recorded_at'], $record['sequence']);

        return $record;
    }

    private function error(string $reason): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => 'blocked',
            'mode' => self::MODE,
            'blocking_reasons' => [$reason],
            'runtime_safety' => $this->runtimeFlagsWithAllFalse(),
        ] + $this->runtimeFlags();
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
        while ($disk->exists(self::LOCK_PATH)) {
            if (microtime(true) - $start > 2.0) {
                throw new \RuntimeException('agent_runtime_evidence_journal_lock_timeout');
            }
            usleep(20000);
        }
        $disk->put(self::LOCK_PATH, (string) getmypid());

        try {
            return $callback();
        } finally {
            $disk->delete(self::LOCK_PATH);
        }
    }

    private function disk(): Filesystem
    {
        return Storage::disk($this->disk ?? self::DEFAULT_DISK);
    }

    /** @param mixed $payload */
    private function stableHash($payload): string
    {
        return hash('sha256', (string) json_encode($this->ksortRecursive($payload), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /** @param mixed $value */
}
