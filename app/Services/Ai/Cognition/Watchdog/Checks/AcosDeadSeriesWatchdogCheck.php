<?php

declare(strict_types=1);

namespace App\Services\Ai\Cognition\Watchdog\Checks;

use App\Services\Ai\Support\AiValueNormalizer;
use App\Services\Ai\AcosMax\AcosMaxMeasureSeriesRegistry;
use App\Services\Ai\AcosMax\AcosMeasureSeriesFreshnessReader;
use App\Services\Ai\Cognition\Watchdog\AtlasWatchdogCheck;
use App\Services\Ai\Cognition\Watchdog\AtlasWatchdogCheckResult;
use Carbon\CarbonImmutable;

final readonly class AcosDeadSeriesWatchdogCheck implements AtlasWatchdogCheck
{
    public const SCHEMA_VERSION = 'atlas.acos.dead_series_watchdog.v1';

    public const CHECK_ID = 'elev-20s.dead_series_registry';

    public const STATUS_OK = 'ok';

    public const STATUS_STALE = 'stale';

    public const STATUS_MISSING = 'missing';
    public const FIELD_SERIES = 'series';
    public const FIELD_SCHEMA_VERSION = 'schema_version';
    public const FIELD_GENERATED_AT = 'generated_at';
    public const FIELD_REGISTRY_COUNT = 'registry_count';
    public const FIELD_DEAD_COUNT = 'dead_count';
    public const FIELD_CODE = 'code';
    public const FIELD_MESSAGE = 'message';
    public const FIELD_LEDGER = 'ledger';
    public const FIELD_PATH = 'path';
    public const FIELD_TABLE = 'table';
    public const FIELD_SLICE = 'slice';
    public const FIELD_SOURCE_TYPE = 'source_type';
    public const FIELD_TIMESTAMP_FIELD = 'timestamp_field';
    public const FIELD_TTL_DAYS = 'ttl_days';
    public const FIELD_STATUS = 'status';
    public const FIELD_TTL_SOURCE = 'ttl_source';
    public const FIELD_FRESHNESS_READER = 'freshness_reader';
    public const FIELD_LAST_APPEND_AT = 'last_append_at';
    public const FIELD_AGE_DAYS = 'age_days';
    public const FIELD_ACOS_DEAD_SERIES_STALE = 'acos_dead_series_stale';
    public const FIELD_FREEZE = 'freeze';
    public const FIELD_RECORDED_AT = 'recorded_at';

    public function __construct(
        private AcosMaxMeasureSeriesRegistry $registry,
        private AcosMeasureSeriesFreshnessReader $freshness = new AcosMeasureSeriesFreshnessReader,
        private ?CarbonImmutable $now = null,
    ) {}

    public function id(): string
    {
        return self::CHECK_ID;
    }

    public function run(): AtlasWatchdogCheckResult
    {
        $now = $this->now ?? CarbonImmutable::now('UTC');
        $series = array_map(fn (array $entry): array => $this->seriesRow($entry, $now), $this->registry->entries());
        $dead = array_values(array_filter(
            $series,
            static fn (array $row): bool => in_array(AiValueNormalizer::trimmedScalarStringOrNull($row[self::FIELD_STATUS] ?? null) ?? '', [self::STATUS_STALE, self::STATUS_MISSING], true),
        ));

        $evidence = [
            self::FIELD_SCHEMA_VERSION => self::SCHEMA_VERSION,
            self::FIELD_GENERATED_AT => $now->toIso8601String(),
            self::FIELD_REGISTRY_COUNT => count($series),
            self::FIELD_DEAD_COUNT => count($dead),
            self::FIELD_SERIES => $series,
            self::FIELD_FRESHNESS_READER => AcosMeasureSeriesFreshnessReader::SCHEMA,
        ];

        if ($dead !== []) {
            return AtlasWatchdogCheckResult::alert($evidence, [
                self::FIELD_CODE => self::FIELD_ACOS_DEAD_SERIES_STALE,
                self::FIELD_MESSAGE => 'Registered ACOS measure series exceeded its frozen TTL or has no append.',
                self::FIELD_SERIES => array_values(array_map(static fn (array $row): string => AiValueNormalizer::trimmedScalarStringOrNull($row[self::FIELD_SERIES] ?? null) ?? '', $dead)),
                self::FIELD_LEDGER => 'atlas_ledger_events:watchdog_run_recorded',
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
        $ttlDays = max(1, (int) (AiValueNormalizer::finiteFloatOrNull($entry[self::FIELD_TTL_DAYS] ?? null) ?? 1));
        $lastAppendAt = $this->freshness->lastAppendAt($entry);
        $ageDays = $lastAppendAt instanceof CarbonImmutable
            ? (int) floor(max(0, $lastAppendAt->diffInSeconds($now)) / 86400)
            : null;
        $status = $ageDays === null ? self::STATUS_MISSING : ($ageDays > $ttlDays ? self::STATUS_STALE : self::STATUS_OK);

        return [
            self::FIELD_SLICE => (AiValueNormalizer::trimmedStringOrNull($entry[self::FIELD_SLICE] ?? null) ?? ''),
            self::FIELD_SERIES => (AiValueNormalizer::trimmedStringOrNull($entry[self::FIELD_SERIES] ?? null) ?? ''),
            self::FIELD_SOURCE_TYPE => AiValueNormalizer::trimmedStringOrNull($entry[self::FIELD_SOURCE_TYPE] ?? null) ?? (isset($entry[self::FIELD_TABLE]) ? 'table' : 'jsonl'),
            self::FIELD_PATH => isset($entry[self::FIELD_PATH]) ? $this->relativePath(AiValueNormalizer::trimmedScalarStringOrNull($entry[self::FIELD_PATH] ?? null) ?? '') : null,
            self::FIELD_TABLE => AiValueNormalizer::trimmedScalarStringOrNull($entry[self::FIELD_TABLE] ?? null),
            self::FIELD_TIMESTAMP_FIELD => (AiValueNormalizer::trimmedStringOrNull($entry[self::FIELD_TIMESTAMP_FIELD] ?? null) ?? self::FIELD_RECORDED_AT),
            self::FIELD_TTL_DAYS => $ttlDays,
            self::FIELD_TTL_SOURCE => (AiValueNormalizer::trimmedStringOrNull($entry[self::FIELD_TTL_SOURCE] ?? null) ?? self::FIELD_FREEZE),
            self::FIELD_LAST_APPEND_AT => $lastAppendAt?->toIso8601String(),
            self::FIELD_AGE_DAYS => $ageDays,
            self::FIELD_STATUS => $status,
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
