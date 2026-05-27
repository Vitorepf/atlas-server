<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\ProductMode;

use App\Services\Ai\Mission\MissionCanonicalHash;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Support\Facades\File;

/**
 * Software Company Stewardship Stack · Product Mode runtime result event
 * read-model (AP-765).
 *
 * Atlas Software Company Stewardship Stack is a stack/capability family inside
 * the Atlas Autonomous Software Company Runtime, not a new OS. This is an
 * append-only JSONL event store that lets the Product Mode/Cockpit show, for
 * each closed 24h cycle, the full chain: area, loop (finding/spec/handoff),
 * sandbox, owner, execution, result, evidence and inbox. It never invokes a
 * provider, opens a branch, mutates the target repo, merges, deploys or touches
 * secrets. It is fed by {@see \App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution\StewardshipRuntimeResultBridgeService}
 * and read by the cockpit; it does not create a parallel ledger or inbox.
 */
final class ProductModeRuntimeResultEventService
{
    public const EVENT_SCHEMA = 'atlas.software_company_stewardship.product_mode_runtime_result_event.v1';

    public const LEDGER_SCHEMA = 'atlas.software_company_stewardship.product_mode_runtime_result_event_ledger.v1';

    public const DEFAULT_AREA_ID = 'agentic_engineering_os';

    private ?string $storageRootOverride = null;

    public function setStorageRootForTesting(?string $dir): void
    {
        $this->storageRootOverride = $dir;
    }

    public function storageDir(): string
    {
        if ($this->storageRootOverride !== null) {
            return $this->storageRootOverride;
        }

        return function_exists('storage_path')
            ? storage_path('atlas/software_company_stewardship/product_mode_runtime_result_events')
            : sys_get_temp_dir().'/atlas/software_company_stewardship/product_mode_runtime_result_events';
    }

    public function eventFilePath(string $areaId): string
    {
        return $this->storageDir().DIRECTORY_SEPARATOR.$this->slug($areaId).'.jsonl';
    }

    /**
     * Append a runtime result event idempotently. The caller is expected to
     * provide the full event payload; an `event_id` is derived deterministically
     * when missing so reruns of the same cycle do not duplicate the row.
     *
     * @param  array<string,mixed>  $event
     * @return array<string,mixed>
     */
    public function record(array $event): array
    {
        $areaId = $this->slug((string) ($event['area_id'] ?? self::DEFAULT_AREA_ID));
        $event['schema_version'] = self::EVENT_SCHEMA;
        $event['area_id'] = $areaId;
        $eventId = (string) ($event['event_id'] ?? '');
        if ($eventId === '') {
            $eventId = $this->eventId($event);
        }
        $event['event_id'] = $eventId;
        $event['event_hash'] = 'sha256:'.MissionCanonicalHash::sha256($this->identity($event));

        $path = $this->eventFilePath($areaId);
        $existing = $this->findInFile($path, $eventId);
        if ($existing !== null) {
            return $existing + ['event_storage_status' => 'existing'];
        }

        $record = $event + [
            'recorded_at' => $this->now(),
        ];
        File::ensureDirectoryExists(dirname($path));
        File::append($path, json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL);

        return $record + ['event_storage_status' => 'recorded'];
    }

    /**
     * @return array<string,mixed>
     */
    public function list(?string $areaId = null): array
    {
        $files = $areaId === null || trim($areaId) === ''
            ? $this->eventFiles()
            : [$this->eventFilePath($areaId)];

        $events = [];
        $corrupted = 0;
        foreach ($files as $file) {
            [$valid, $bad] = $this->readRecords($file);
            $events = array_merge($events, $valid);
            $corrupted += $bad;
        }

        $summaries = [];
        foreach ($events as $event) {
            $summaries[] = [
                'event_id' => (string) ($event['event_id'] ?? ''),
                'area_id' => (string) ($event['area_id'] ?? ''),
                'owner' => (string) ($event['owner'] ?? ''),
                'result_bridge_id' => (string) ($event['result_bridge_id'] ?? ''),
                'result_status' => (string) data_get($event, 'result.status', ''),
                'evidence_pack_id' => (string) data_get($event, 'evidence.evidence_pack_id', ''),
                'inbox_item_id' => data_get($event, 'inbox.inbox_item_id'),
                'recorded_at' => (string) ($event['recorded_at'] ?? ''),
                'event_hash' => (string) ($event['event_hash'] ?? ''),
            ];
        }

        usort($summaries, static fn (array $a, array $b): int => strcmp((string) $a['recorded_at'], (string) $b['recorded_at']));

        return [
            'schema_version' => self::LEDGER_SCHEMA,
            'ap_contract' => 'AP-765',
            'area_id' => $areaId,
            'event_count' => count($summaries),
            'corrupted_line_count' => $corrupted,
            'events' => $summaries,
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    public function replay(string $eventId): ?array
    {
        if (trim($eventId) === '') {
            return null;
        }

        foreach ($this->eventFiles() as $file) {
            $found = $this->findInFile($file, $eventId);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $event
     */
    private function eventId(array $event): string
    {
        return 'pmre_'.substr(MissionCanonicalHash::sha256([
            'AP-765',
            (string) ($event['result_bridge_id'] ?? ''),
            (string) data_get($event, 'evidence.evidence_pack_hash', ''),
            (string) ($event['area_id'] ?? ''),
        ]), 0, 18);
    }

    /**
     * @param  array<string,mixed>  $event
     * @return array<string,mixed>
     */
    private function identity(array $event): array
    {
        $copy = $event;
        unset($copy['event_hash'], $copy['recorded_at'], $copy['event_storage_status']);

        return $copy;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function findInFile(string $path, string $eventId): ?array
    {
        [$records] = $this->readRecords($path);
        foreach ($records as $record) {
            if ((string) ($record['event_id'] ?? '') === $eventId) {
                return $record;
            }
        }

        return null;
    }

    /**
     * @return array{0:list<array<string,mixed>>,1:int}
     */
    private function readRecords(string $path): array
    {
        if (! is_file($path)) {
            return [[], 0];
        }

        $records = [];
        $corrupted = 0;
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded) && isset($decoded['event_id']) && is_string($decoded['event_id'])) {
                $records[] = $decoded;
            } else {
                $corrupted++;
            }
        }

        return [$records, $corrupted];
    }

    /**
     * @return list<string>
     */
    private function eventFiles(): array
    {
        $dir = $this->storageDir();
        if (! is_dir($dir)) {
            return [];
        }

        return array_values(array_filter((array) glob($dir.DIRECTORY_SEPARATOR.'*.jsonl'), 'is_string'));
    }

    private function slug(string $value): string
    {
        $slug = strtolower(preg_replace('/[^a-zA-Z0-9_-]+/', '_', trim($value)) ?: '');

        return trim($slug, '_') ?: self::DEFAULT_AREA_ID;
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);
    }
}
