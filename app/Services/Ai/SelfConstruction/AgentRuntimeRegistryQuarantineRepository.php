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
 * Persistent local quarantine ledger for Agent Control Plane agents.
 *
 * Persists quarantine state under
 * `atlas/self-construction/agent-control-plane/agent-quarantine/`.
 *
 * Runtime-safe: never dispatches, never claims, never calls providers,
 * never spends tokens, never writes the evidence ledger. Quarantine
 * blocks availability; release requires reviewer + reason. There is no
 * silent release.
 */
final class AgentRuntimeRegistryQuarantineRepository
{
    use HashesPayloadCanonically;
    use EncodesPayloadAsPrettyJson;
    public const SCHEMA_VERSION = 'atlas.self_construction.agent_runtime_registry_quarantine.v1';

    public const MODE = 'persistent_local_agent_runtime_registry_quarantine';

    public const STORAGE_PREFIX = 'atlas/self-construction/agent-control-plane/agent-quarantine';

    public const INDEX_PATH = self::STORAGE_PREFIX.'/index.json';

    public const LOCK_PATH = self::STORAGE_PREFIX.'/.lock';

    public const DEFAULT_DISK = 'local';

    public const REASONS = [
        'stale_heartbeat',
        'lease_violation',
        'scope_violation',
        'missing_continuation_summary',
        'evidence_failure',
        'operator_disabled',
        'runtime_safety_violation',
    ];

    public function __construct(
        private readonly ?string $disk = null,
    ) {}

    /**
     * @param  array<string, mixed>  $reason
     * @return array<string, mixed>
     */
    public function quarantine(string $agentId, array $reason): array
    {
        return $this->withLock(function () use ($agentId, $reason): array {
            if (! $this->isValidAgentId($agentId)) {
                return $this->envelopeError('invalid_agent_id', $agentId);
            }

            $code = (string) ($reason['code'] ?? '');
            if (! in_array($code, self::REASONS, true)) {
                return $this->envelopeError('invalid_reason', $agentId, [
                    'requested_reason' => $code,
                    'allowed_reasons' => self::REASONS,
                ]);
            }
            $detail = (string) ($reason['detail'] ?? '');
            $declaredBy = (string) ($reason['declared_by'] ?? '');
            if ($declaredBy === '') {
                return $this->envelopeError('declared_by_missing', $agentId);
            }

            $now = CarbonImmutable::now()->toIso8601String();
            $existing = $this->readQuarantineFile($agentId);
            $entryId = (string) Str::uuid();

            $record = [
                'schema_version' => self::SCHEMA_VERSION,
                'agent_id' => $agentId,
                'is_quarantined' => true,
                'quarantine_entry_id' => $entryId,
                'reason_code' => $code,
                'reason_detail' => $detail,
                'declared_by' => $declaredBy,
                'declared_at' => $now,
                'released' => false,
                'release_history' => $existing['release_history'] ?? [],
                'history' => $existing['history'] ?? [],
                'receipts' => $existing['receipts'] ?? [],
                'runtime_execution_allowed' => false,
                'dispatch_allowed' => false,
                'provider_call_allowed' => false,
                'token_spend_allowed' => false,
                'self_programming_allowed' => false,
                'ledger_write_allowed' => false,
            ];
            $record['history'][] = [
                'event' => 'quarantined',
                'at' => $now,
                'reason_code' => $code,
                'reason_detail' => $detail,
                'declared_by' => $declaredBy,
            ];
            $record['receipts'][] = [
                'receipt_kind' => 'quarantine_declared',
                'recorded_at' => $now,
                'reason_code' => $code,
                'declared_by' => $declaredBy,
                'receipt_hash' => $this->stableHash([
                    'agent_id' => $agentId,
                    'entry_id' => $entryId,
                    'reason_code' => $code,
                ]),
            ];

            $this->writeQuarantineFile($agentId, $record);
            $this->updateIndex($agentId, $record);

            return $this->envelopeOk('quarantined', $record);
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function release(string $agentId, array $payload): array
    {
        return $this->withLock(function () use ($agentId, $payload): array {
            if (! $this->isValidAgentId($agentId)) {
                return $this->envelopeError('invalid_agent_id', $agentId);
            }
            $reviewer = (string) ($payload['reviewer'] ?? '');
            $reason = (string) ($payload['reason'] ?? '');
            if ($reviewer === '') {
                return $this->envelopeError('reviewer_missing', $agentId);
            }
            if ($reason === '') {
                return $this->envelopeError('release_reason_missing', $agentId);
            }
            $record = $this->readQuarantineFile($agentId);
            if ($record === null) {
                return $this->envelopeError('not_quarantined', $agentId);
            }
            if ((bool) ($record['is_quarantined'] ?? false) !== true) {
                return $this->envelopeError('agent_not_currently_quarantined', $agentId);
            }

            $now = CarbonImmutable::now()->toIso8601String();
            $record['is_quarantined'] = false;
            $record['released'] = true;
            $record['released_at'] = $now;
            $record['released_by'] = $reviewer;
            $record['release_reason'] = $reason;
            $record['release_history'][] = [
                'at' => $now,
                'reviewer' => $reviewer,
                'reason' => $reason,
                'previous_reason_code' => (string) ($record['reason_code'] ?? ''),
            ];
            $record['history'][] = [
                'event' => 'released',
                'at' => $now,
                'reviewer' => $reviewer,
                'reason' => $reason,
            ];
            $record['receipts'][] = [
                'receipt_kind' => 'quarantine_released',
                'recorded_at' => $now,
                'reviewer' => $reviewer,
                'reason' => $reason,
                'receipt_hash' => $this->stableHash([
                    'agent_id' => $agentId,
                    'reviewer' => $reviewer,
                    'reason' => $reason,
                    'at' => $now,
                ]),
            ];

            $this->writeQuarantineFile($agentId, $record);
            $this->updateIndex($agentId, $record);

            return $this->envelopeOk('released', $record);
        });
    }

    public function isQuarantined(string $agentId): bool
    {
        if (! $this->isValidAgentId($agentId)) {
            return false;
        }
        $record = $this->readQuarantineFile($agentId);
        if ($record === null) {
            return false;
        }

        return (bool) ($record['is_quarantined'] ?? false);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    public function list(array $filters = []): array
    {
        $onlyActive = (bool) ($filters['only_active'] ?? false);
        $reasonCode = isset($filters['reason_code']) ? (string) $filters['reason_code'] : '';
        $limit = isset($filters['limit']) ? (int) $filters['limit'] : 0;

        $results = [];
        foreach ($this->loadIndex() as $entry) {
            $agentId = (string) ($entry['agent_id'] ?? '');
            if ($agentId === '') {
                continue;
            }
            $record = $this->readQuarantineFile($agentId);
            if ($record === null) {
                continue;
            }
            if ($onlyActive && ! (bool) ($record['is_quarantined'] ?? false)) {
                continue;
            }
            if ($reasonCode !== '' && (string) ($record['reason_code'] ?? '') !== $reasonCode) {
                continue;
            }
            $results[] = $record;
            if ($limit > 0 && count($results) >= $limit) {
                break;
            }
        }

        usort($results, static fn (array $a, array $b): int => strcmp((string) $a['agent_id'], (string) $b['agent_id']));

        return $results;
    }

    /**
     * @return list<string>
     */
    public function activeAgentIds(): array
    {
        $ids = [];
        foreach ($this->list(['only_active' => true]) as $record) {
            $ids[] = (string) ($record['agent_id'] ?? '');
        }

        return array_values(array_filter($ids, static fn (string $v): bool => $v !== ''));
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
     * @return array<string, mixed>|null
     */
    private function readQuarantineFile(string $agentId): ?array
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
            return null;
        }
        if (! is_array($decoded)) {
            return null;
        }

        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function writeQuarantineFile(string $agentId, array $record): void
    {
        $this->disk()->put($this->agentPath($agentId), $this->encode($record));
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function updateIndex(string $agentId, array $record): void
    {
        $index = $this->loadIndex();
        $payload = [
            'agent_id' => $agentId,
            'is_quarantined' => (bool) ($record['is_quarantined'] ?? false),
            'reason_code' => (string) ($record['reason_code'] ?? ''),
            'declared_at' => (string) ($record['declared_at'] ?? ''),
            'released_at' => (string) ($record['released_at'] ?? ''),
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
     * @return array<string, mixed>
     */
    private function envelopeOk(string $event, array $record): array
    {
        return [
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
        ];
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
