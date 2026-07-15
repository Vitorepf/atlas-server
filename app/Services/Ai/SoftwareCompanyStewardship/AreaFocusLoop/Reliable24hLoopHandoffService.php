<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\Support\JsonFileStore;
use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * Durable, lock-scoped handoff receipts for AP-790.
 *
 * A handoff never chooses a host and never claims the successor is running. It
 * asks the lock holder to yield at an iteration boundary, records that release,
 * then lets the queue assign a successor. Only the successor's acquired lock
 * can move the receipt to target_claimed.
 */
final class Reliable24hLoopHandoffService
{
    public const SCHEMA = 'atlas.software_company_stewardship.ap790_loop_handoff.v1';

    public function __construct(private readonly Reliable24hLoopRunnerService $runner) {}

    /**
     * @param array<string,mixed> $sourceHolder
     * @return array<string,mixed>
     */
    public function request(string $areaId, string $focus, array $sourceHolder, string $actor, string $reason): array
    {
        $sourceRunId = trim((string) ($sourceHolder['run_id'] ?? ''));
        if ($sourceRunId === '') {
            throw new RuntimeException('A live source run_id is required for a handoff.');
        }

        return $this->withinScopeLock($areaId, $focus, function () use ($areaId, $focus, $sourceHolder, $sourceRunId, $actor, $reason): array {
            $active = $this->active($areaId, $focus);
            if ($active !== null && in_array((string) ($active['status'] ?? ''), ['transfer_requested', 'source_released', 'successor_enqueued'], true)) {
                if ((string) data_get($active, 'source.run_id', '') === $sourceRunId) {
                    return $active;
                }

                throw new RuntimeException('A different handoff is already active for this area/focus.');
            }

            $handoffId = 'ap790handoff_'.substr(MissionCanonicalHash::sha256([
                $areaId,
                $focus,
                $sourceRunId,
                microtime(true),
                bin2hex(random_bytes(12)),
            ]), 0, 18);
            $record = [
                'schema_version' => self::SCHEMA,
                'handoff_id' => $handoffId,
                'area_id' => $areaId,
                'focus' => $focus,
                'status' => 'transfer_requested',
                'source' => [
                    'run_id' => $sourceRunId,
                    'host' => trim((string) ($sourceHolder['host'] ?? '')) ?: null,
                    'acquired_at' => trim((string) ($sourceHolder['acquired_at'] ?? '')) ?: null,
                ],
                'target' => [
                    'status' => 'awaiting_source_release',
                    'run_id' => null,
                    'host' => null,
                    'claimed_at' => null,
                ],
                'requested_by' => $actor,
                'reason' => $reason,
                'requested_at' => AreaFocusUtcClock::atomNow(),
                'source_released_at' => null,
                'successor_enqueued_at' => null,
                'checkpoint' => null,
                'updated_at' => AreaFocusUtcClock::atomNow(),
            ];
            $this->write($record);
            JsonFileStore::writeAtomic($this->activePath($areaId, $focus), ['handoff_id' => $handoffId]);

            return $record;
        });
    }

    /** @return array<string,mixed>|null */
    public function requestForSource(string $areaId, string $focus, string $sourceRunId): ?array
    {
        $record = $this->active($areaId, $focus);

        return $record !== null
            && (string) ($record['status'] ?? '') === 'transfer_requested'
            && (string) data_get($record, 'source.run_id', '') === $sourceRunId
            ? $record
            : null;
    }

    /**
     * @param array<string,mixed> $checkpoint
     * @return array<string,mixed>|null
     */
    public function markSourceReleased(string $handoffId, string $sourceRunId, array $checkpoint): ?array
    {
        return $this->update($handoffId, function (array $record) use ($sourceRunId, $checkpoint): ?array {
            if ((string) ($record['status'] ?? '') !== 'transfer_requested'
                || (string) data_get($record, 'source.run_id', '') !== $sourceRunId) {
                return null;
            }

            $record['status'] = 'source_released';
            $record['source_released_at'] = AreaFocusUtcClock::atomNow();
            $record['checkpoint'] = $checkpoint;
            $record['target']['status'] = 'awaiting_queue_worker';

            return $record;
        });
    }

    /** @return array<string,mixed>|null */
    public function markSuccessorEnqueued(string $handoffId, string $sourceRunId): ?array
    {
        return $this->update($handoffId, function (array $record) use ($sourceRunId): ?array {
            if ((string) ($record['status'] ?? '') !== 'source_released'
                || (string) data_get($record, 'source.run_id', '') !== $sourceRunId) {
                return null;
            }

            $record['status'] = 'successor_enqueued';
            $record['successor_enqueued_at'] = AreaFocusUtcClock::atomNow();
            $record['target']['status'] = 'awaiting_queue_worker';

            return $record;
        });
    }

    /** @return array<string,mixed>|null */
    public function markDispatchFailed(string $handoffId, string $sourceRunId): ?array
    {
        return $this->update($handoffId, function (array $record) use ($sourceRunId): ?array {
            if ((string) ($record['status'] ?? '') !== 'successor_enqueued'
                || (string) data_get($record, 'source.run_id', '') !== $sourceRunId) {
                return null;
            }

            $record['status'] = 'dispatch_failed';
            $record['target']['status'] = 'not_enqueued';

            return $record;
        });
    }

    /** @return array<string,mixed>|null */
    public function claimTarget(string $handoffId, string $areaId, string $focus, string $targetRunId, string $host): ?array
    {
        return $this->update($handoffId, function (array $record) use ($areaId, $focus, $targetRunId, $host): ?array {
            if ((string) ($record['status'] ?? '') !== 'successor_enqueued'
                || (string) ($record['area_id'] ?? '') !== $areaId
                || (string) ($record['focus'] ?? '') !== $focus
                || data_get($record, 'target.run_id') !== null) {
                return null;
            }

            $record['status'] = 'target_claimed';
            $record['target'] = [
                'status' => 'claimed',
                'run_id' => $targetRunId,
                'host' => $host !== '' ? $host : null,
                'claimed_at' => AreaFocusUtcClock::atomNow(),
            ];

            return $record;
        });
    }

    /** @return array<string,mixed>|null */
    public function publicRecord(?string $handoffId): ?array
    {
        if (! is_string($handoffId) || trim($handoffId) === '') {
            return null;
        }
        $record = JsonFileStore::readArray($this->recordPath($handoffId));
        if ($record === null || (string) ($record['schema_version'] ?? '') !== self::SCHEMA) {
            return null;
        }

        return [
            'schema_version' => self::SCHEMA,
            'handoff_id' => (string) ($record['handoff_id'] ?? ''),
            'area_id' => (string) ($record['area_id'] ?? ''),
            'focus' => (string) ($record['focus'] ?? ''),
            'status' => (string) ($record['status'] ?? ''),
            'source' => is_array($record['source'] ?? null) ? $record['source'] : [],
            'target' => is_array($record['target'] ?? null) ? $record['target'] : [],
            'requested_at' => $record['requested_at'] ?? null,
            'source_released_at' => $record['source_released_at'] ?? null,
            'successor_enqueued_at' => $record['successor_enqueued_at'] ?? null,
            'checkpoint' => is_array($record['checkpoint'] ?? null) ? $record['checkpoint'] : null,
            'updated_at' => $record['updated_at'] ?? null,
        ];
    }

    /** @return array<string,mixed>|null */
    private function active(string $areaId, string $focus): ?array
    {
        $pointer = JsonFileStore::readArray($this->activePath($areaId, $focus));

        return is_array($pointer) ? JsonFileStore::readArray($this->recordPath((string) ($pointer['handoff_id'] ?? ''))) : null;
    }

    /** @param callable():array<string,mixed> $callback */
    private function withinScopeLock(string $areaId, string $focus, callable $callback): array
    {
        $path = $this->scopeLockPath($areaId, $focus);
        File::ensureDirectoryExists(dirname($path));
        $handle = fopen($path, 'c+');
        if ($handle === false) {
            throw new RuntimeException('Could not acquire the durable handoff guard.');
        }
        try {
            if (! flock($handle, LOCK_EX)) {
                throw new RuntimeException('Could not lock the durable handoff guard.');
            }

            return $callback();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /** @param callable(array<string,mixed>):?array<string,mixed> $mutator */
    private function update(string $handoffId, callable $mutator): ?array
    {
        $record = JsonFileStore::readArray($this->recordPath($handoffId));
        if ($record === null || (string) ($record['schema_version'] ?? '') !== self::SCHEMA) {
            return null;
        }

        return $this->withinScopeLock((string) $record['area_id'], (string) $record['focus'], function () use ($handoffId, $mutator): ?array {
            $fresh = JsonFileStore::readArray($this->recordPath($handoffId));
            if ($fresh === null) {
                return null;
            }
            $next = $mutator($fresh);
            if ($next === null) {
                return null;
            }
            $next['updated_at'] = AreaFocusUtcClock::atomNow();
            $this->write($next);

            return $next;
        });
    }

    /** @param array<string,mixed> $record */
    private function write(array $record): void
    {
        JsonFileStore::writeAtomic($this->recordPath((string) $record['handoff_id']), $record);
    }

    private function baseDir(): string
    {
        return rtrim($this->runner->storageDir(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'handoffs';
    }

    private function recordPath(string $handoffId): string
    {
        return $this->baseDir().DIRECTORY_SEPARATOR.'records'.DIRECTORY_SEPARATOR.$this->safeToken($handoffId).'.json';
    }

    private function activePath(string $areaId, string $focus): string
    {
        return $this->baseDir().DIRECTORY_SEPARATOR.'active'.DIRECTORY_SEPARATOR.$this->safeToken($areaId.'-'.$focus).'.json';
    }

    private function scopeLockPath(string $areaId, string $focus): string
    {
        return $this->baseDir().DIRECTORY_SEPARATOR.'guards'.DIRECTORY_SEPARATOR.$this->safeToken($areaId.'-'.$focus).'.lock';
    }

    private function safeToken(string $value): string
    {
        return preg_replace('/[^A-Za-z0-9_.-]+/', '-', trim($value)) ?: 'unknown';
    }
}
