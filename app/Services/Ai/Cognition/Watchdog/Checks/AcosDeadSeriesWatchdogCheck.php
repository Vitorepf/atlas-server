<?php

declare(strict_types=1);

namespace App\Services\Ai\Cognition\Watchdog\Checks;

use App\Services\Ai\AcosMax\AcosMaxMeasureSeriesRegistry;
use App\Services\Ai\Cognition\Watchdog\AtlasWatchdogCheck;
use App\Services\Ai\Cognition\Watchdog\AtlasWatchdogCheckResult;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Throwable;

final readonly class AcosDeadSeriesWatchdogCheck implements AtlasWatchdogCheck
{
    public function __construct(
        private AcosMaxMeasureSeriesRegistry $registry,
        private ?CarbonImmutable $now = null,
    ) {}

    public function id(): string
    {
        return 'elev-20s.dead_series_registry';
    }

    public function run(): AtlasWatchdogCheckResult
    {
        $now = $this->now ?? CarbonImmutable::now('UTC');
        $series = array_map(fn (array $entry): array => $this->seriesRow($entry, $now), $this->registry->entries());
        $dead = array_values(array_filter(
            $series,
            static fn (array $row): bool => in_array((string) $row['status'], ['stale', 'missing'], true),
        ));

        $evidence = [
            'schema_version' => 'atlas.acos.dead_series_watchdog.v1',
            'generated_at' => $now->toIso8601String(),
            'registry_count' => count($series),
            'dead_count' => count($dead),
            'series' => $series,
        ];

        if ($dead !== []) {
            return AtlasWatchdogCheckResult::alert($evidence, [
                'code' => 'acos_dead_series_stale',
                'message' => 'Registered ACOS measure series exceeded its frozen TTL or has no append.',
                'series' => array_values(array_map(static fn (array $row): string => (string) $row['series'], $dead)),
                'ledger' => 'atlas_ledger_events:watchdog_run_recorded',
            ]);
        }

        return AtlasWatchdogCheckResult::ok($evidence);
    }

    /**
     * @param  array<string,mixed>  $entry
     * @return array<string,mixed>
     */
    private function seriesRow(array $entry, CarbonImmutable $now): array
    {
        $ttlDays = max(1, (int) ($entry['ttl_days'] ?? 1));
        $lastAppendAt = $this->lastAppendAt($entry);
        $ageDays = $lastAppendAt instanceof CarbonImmutable
            ? (int) floor(max(0, $lastAppendAt->diffInSeconds($now)) / 86400)
            : null;
        $status = $ageDays === null ? 'missing' : ($ageDays > $ttlDays ? 'stale' : 'ok');

        return [
            'slice' => (string) ($entry['slice'] ?? ''),
            'series' => (string) ($entry['series'] ?? ''),
            'source_type' => (string) ($entry['source_type'] ?? (isset($entry['table']) ? 'table' : 'jsonl')),
            'path' => isset($entry['path']) ? $this->relativePath((string) $entry['path']) : null,
            'table' => isset($entry['table']) ? (string) $entry['table'] : null,
            'timestamp_field' => (string) ($entry['timestamp_field'] ?? 'recorded_at'),
            'ttl_days' => $ttlDays,
            'ttl_source' => (string) ($entry['ttl_source'] ?? 'freeze'),
            'last_append_at' => $lastAppendAt?->toIso8601String(),
            'age_days' => $ageDays,
            'status' => $status,
        ];
    }

    /** @param array<string,mixed> $entry */
    private function lastAppendAt(array $entry): ?CarbonImmutable
    {
        $sourceType = (string) ($entry['source_type'] ?? '');
        if (($entry['table'] ?? null) !== null || $sourceType === 'table') {
            return $this->tableLastAppendAt($entry);
        }
        if ($sourceType === 'command') {
            return $this->commandLastAppendAt($entry);
        }

        $path = (string) ($entry['path'] ?? '');
        if ($path === '') {
            return null;
        }

        if ($sourceType === 'jsonl_dir' || is_dir($path)) {
            return $this->jsonlDirLastAppendAt($path, $entry);
        }

        return $this->jsonlFileLastAppendAt($path, $entry);
    }

    /** @param array<string,mixed> $entry */
    private function commandLastAppendAt(array $entry): ?CarbonImmutable
    {
        $command = trim((string) ($entry['path'] ?? ''));
        if ($command === '' || ! str_starts_with($command, 'atlas:')) {
            return null;
        }

        try {
            Artisan::call($command);
            $decoded = json_decode(trim(Artisan::output()), true);
            if (! is_array($decoded)) {
                return null;
            }

            return $this->rowTimestamp(
                $this->flattenFirstPayload($decoded),
                (string) ($entry['timestamp_field'] ?? 'generated_at'),
            );
        } catch (Throwable) {
            return null;
        }
    }

    /** @param array<string,mixed> $entry */
    private function tableLastAppendAt(array $entry): ?CarbonImmutable
    {
        $table = trim((string) ($entry['table'] ?? ''));
        $timestampField = trim((string) ($entry['timestamp_field'] ?? 'occurred_at')) ?: 'occurred_at';
        if ($table === '' || ! DatabaseTableAvailability::has($table)) {
            return null;
        }

        try {
            $query = DB::table($table);
            foreach ((array) ($entry['where'] ?? []) as $column => $value) {
                $query->where((string) $column, $value);
            }

            return $this->parseDate($query->max($timestampField));
        } catch (Throwable) {
            return null;
        }
    }

    /** @param array<string,mixed> $entry */
    private function jsonlDirLastAppendAt(string $dir, array $entry): ?CarbonImmutable
    {
        $latest = null;
        $files = is_dir($dir) ? glob(rtrim($dir, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'*.jsonl') : [];
        foreach (is_array($files) ? $files : [] as $file) {
            $candidate = $this->jsonlFileLastAppendAt((string) $file, $entry);
            if ($candidate instanceof CarbonImmutable && ($latest === null || $candidate->greaterThan($latest))) {
                $latest = $candidate;
            }
        }

        return $latest;
    }

    /** @param array<string,mixed> $entry */
    private function jsonlFileLastAppendAt(string $path, array $entry): ?CarbonImmutable
    {
        if (! is_file($path)) {
            return null;
        }

        $latest = null;
        $handle = fopen($path, 'rb');
        if ($handle !== false) {
            try {
                while (($line = fgets($handle)) !== false) {
                    $decoded = json_decode(trim($line), true);
                    if (! is_array($decoded)) {
                        continue;
                    }
                    $candidate = $this->rowTimestamp($decoded, (string) ($entry['timestamp_field'] ?? 'recorded_at'));
                    if ($candidate instanceof CarbonImmutable && ($latest === null || $candidate->greaterThan($latest))) {
                        $latest = $candidate;
                    }
                }
            } finally {
                fclose($handle);
            }
        }

        if ($latest instanceof CarbonImmutable) {
            return $latest;
        }

        $mtime = @filemtime($path);

        return is_int($mtime) ? CarbonImmutable::createFromTimestampUTC($mtime) : null;
    }

    /** @param array<string,mixed> $row */
    private function rowTimestamp(array $row, string $preferredField): ?CarbonImmutable
    {
        foreach (array_values(array_unique([$preferredField, 'recorded_at', 'ts', 'created_at', 'occurred_at', 'timestamp'])) as $field) {
            $parsed = $this->parseDate($row[$field] ?? null);
            if ($parsed instanceof CarbonImmutable) {
                return $parsed;
            }
        }

        return null;
    }

    /** @param array<string,mixed> $decoded @return array<string,mixed> */
    private function flattenFirstPayload(array $decoded): array
    {
        foreach ($decoded as $value) {
            if (is_array($value)) {
                return $value;
            }
        }

        return $decoded;
    }

    private function parseDate(mixed $value): ?CarbonImmutable
    {
        if ($value instanceof \DateTimeInterface) {
            return CarbonImmutable::instance($value)->utc();
        }
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->utc();
        } catch (Throwable) {
            return null;
        }
    }

    private function relativePath(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $base = str_replace('\\', '/', base_path());
        if (str_starts_with($path, $base.'/')) {
            return substr($path, strlen($base) + 1);
        }

        return $path;
    }
}
