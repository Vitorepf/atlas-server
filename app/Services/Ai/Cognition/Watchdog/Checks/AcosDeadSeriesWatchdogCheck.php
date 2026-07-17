<?php

declare(strict_types=1);

namespace App\Services\Ai\Cognition\Watchdog\Checks;

use App\Services\Ai\AcosMax\AcosMaxMeasureSeriesRegistry;
use App\Services\Ai\AcosMax\AcosMeasureSeriesFreshnessReader;
use App\Services\Ai\Cognition\Watchdog\AtlasWatchdogCheck;
use App\Services\Ai\Cognition\Watchdog\AtlasWatchdogCheckResult;
use Carbon\CarbonImmutable;

final readonly class AcosDeadSeriesWatchdogCheck implements AtlasWatchdogCheck
{
    public function __construct(
        private AcosMaxMeasureSeriesRegistry $registry,
        private AcosMeasureSeriesFreshnessReader $freshness = new AcosMeasureSeriesFreshnessReader,
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
            'freshness_reader' => AcosMeasureSeriesFreshnessReader::SCHEMA,
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
        $lastAppendAt = $this->freshness->lastAppendAt($entry);
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
