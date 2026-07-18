<?php

declare(strict_types=1);

namespace App\Services\Ai\AcosMax;

use App\Services\Ai\Support\AiValueNormalizer;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

final class AcosMaxWindowOrchestratorService
{
    public const FIELD_ID = 'id';
    public const FIELD_DEAD_AFTER_DAYS = 'dead_after_days';
    public const SCHEMA_VERSION = 'atlas.acos.windows.v1';

    public const STATE_NOT_STARTED = 'not_started';

    public const STATE_UNKNOWN = 'unknown';

    public const BLOCKING_WINDOW_NOT_STARTED = 'window_not_started';

    public const STATUS_DEAD_WINDOW = 'dead_window';

    public const STATUS_UNAVAILABLE = 'unavailable';

    public const STATUS_OK = 'ok';

    public const REASON_NO_STARTED_WINDOW_WITH_NUMERIC_DURATION = 'no_started_window_with_numeric_duration';
    public const FIELD_SLICE = 'slice';
    public const FIELD_DAYS_REMAINING = 'days_remaining';
    public const FIELD_FLAG_ID = 'flag_id';
    public const FIELD_OBSERVATION_WINDOW_ID = 'observation_window_id';
    public const FIELD_STATUS = 'status';
    public const FIELD_SERIES = 'series';
    public const FIELD_FAMILY = 'family';
    public const FIELD_STATE = 'state';
    public const FIELD_BLOCKING = 'blocking';
    public const FIELD_REASON = 'reason';
    public const FIELD_NODES = 'nodes';
    public const FIELD_SCHEMA_VERSION = 'schema_version';
    public const FIELD_GENERATED_AT = 'generated_at';
    public const FIELD_SOURCE = 'source';
    public const FIELD_READ_ONLY = 'read_only';
    public const FIELD_PROMOTION_PROTOCOL_SCHEMA = 'promotion_protocol_schema';
    public const FIELD_DEPENDS_ON = 'depends_on';
    public const FIELD_WATCHDOG_ALERT = 'watchdog_alert';
    public const FIELD_STARTED_AT = 'started_at';
    public const FIELD_SHADOW_MINIMUM_WINDOW = 'shadow_minimum_window';
    public const FIELD_STARTS_WINDOWS = 'starts_windows';
    public const FIELD_CRITICAL_PATH = 'critical_path';
    public const FIELD_ALERTS = 'alerts';
    public const FIELD_DAYS_ELAPSED = 'days_elapsed';
    public const FIELD_DURATION_DAYS = 'duration_days';
    public const FIELD_LAST_DATA_AT = 'last_data_at';
    public const FIELD_MINIMUM_WINDOW = 'minimum_window';
    public const FIELD_MINIMUM_WINDOW_RUNNING = 'minimum_window_running';
    public const FIELD_PARALLELIZABLE_GROUPS = 'parallelizable_groups';
    public const FIELD_RECORDED_AT = 'recorded_at';
    public const FIELD_SCHEDULER = 'scheduler';
    public const FIELD_SILENT_DAYS = 'silent_days';
    public const FIELD_TO_STATE = 'to_state';
    public const FIELD_WATCHDOG = 'watchdog';


    public function __construct(
        private readonly AcosMeasureSeriesFreshnessReader $freshness = new AcosMeasureSeriesFreshnessReader,
    ) {}

    /**
     * @param  list<array<string,mixed>>|null  $protocolEntries
     * @param  list<array<string,mixed>>|null  $registryEntries
     * @return array<string,mixed>
     */
    public function report(
        PromotionProtocol $protocol,
        ?array $protocolEntries = null,
        ?array $registryEntries = null,
        ?DateTimeImmutable $now = null,
        int $deadAfterDays = 3,
    ): array {
        $now = $now ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $entries = $protocolEntries ?? $protocol->entries();
        $events = $protocol->ledgerEvents();
        $registry = $this->registryBySlice($registryEntries ?? (new AcosMaxMeasureSeriesRegistry)->entries());
        $windows = [];

        foreach ($entries as $entry) {
            $last = $this->lastEventFor((AiValueNormalizer::trimmedStringOrNull($entry[self::FIELD_ID] ?? null) ?? ''), $events);
            $windows[] = $this->windowForEntry($entry, $last, $registry[(AiValueNormalizer::trimmedStringOrNull($entry[self::FIELD_SLICE] ?? null) ?? '')] ?? null, $now, $deadAfterDays);
        }

        $active = array_values(array_filter(
            $windows,
            static fn (array $window): bool => ($window[self::FIELD_STATE] ?? null) !== self::STATE_NOT_STARTED
                && ($window[self::FIELD_OBSERVATION_WINDOW_ID] ?? null) !== null
        ));
        $critical = $this->criticalPath($active);

        return [
            self::FIELD_SCHEMA_VERSION => self::SCHEMA_VERSION,
            self::FIELD_STATUS => self::STATUS_OK,
            self::FIELD_GENERATED_AT => $now->format(DateTimeInterface::ATOM),
            self::FIELD_SOURCE => [
                self::FIELD_PROMOTION_PROTOCOL_SCHEMA => PromotionProtocol::SCHEMA,
                self::FIELD_READ_ONLY => true,
                self::FIELD_STARTS_WINDOWS => false,
                self::FIELD_SCHEDULER => false,
            ],
            self::FIELD_CRITICAL_PATH => $critical,
            self::FIELD_PARALLELIZABLE_GROUPS => $this->parallelizableGroups($active),
            self::FIELD_WATCHDOG => [
                self::FIELD_DEAD_AFTER_DAYS => max(1, $deadAfterDays),
                self::FIELD_ALERTS => array_values(array_filter(
                    array_map(static fn (array $window): ?array => $window[self::FIELD_WATCHDOG_ALERT] ?? null, $windows)
                )),
            ],
            'windows' => array_map(static function (array $window): array {
                unset($window[self::FIELD_WATCHDOG_ALERT]);

                return $window;
            }, $windows),
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $registryEntries
     * @return array<string,array<string,mixed>>
     */
    private function registryBySlice(array $registryEntries): array
    {
        $bySlice = [];
        foreach ($registryEntries as $entry) {
            $slice = (AiValueNormalizer::trimmedStringOrNull($entry[self::FIELD_SLICE] ?? null) ?? '');
            if ($slice !== '') {
                $bySlice[$slice] = $entry;
            }
        }

        return $bySlice;
    }

    /**
     * @param  list<array<string,mixed>>  $events
     * @return array<string,mixed>|null
     */
    private function lastEventFor(string $flagId, array $events): ?array
    {
        $last = null;
        foreach ($events as $event) {
            if ((AiValueNormalizer::trimmedStringOrNull($event[self::FIELD_FLAG_ID] ?? null) ?? '') === $flagId) {
                $last = $event;
            }
        }

        return $last;
    }

    /**
     * @param  array<string,mixed>  $entry
     * @param  array<string,mixed>|null  $lastEvent
     * @param  array<string,mixed>|null  $series
     * @return array<string,mixed>
     */
    private function windowForEntry(
        array $entry,
        ?array $lastEvent,
        ?array $series,
        DateTimeImmutable $now,
        int $deadAfterDays,
    ): array {
        $durationDays = $this->durationDays((AiValueNormalizer::trimmedStringOrNull($entry[self::FIELD_SHADOW_MINIMUM_WINDOW] ?? null) ?? ''));
        $base = [
            self::FIELD_FLAG_ID => (AiValueNormalizer::trimmedStringOrNull($entry[self::FIELD_ID] ?? null) ?? ''),
            self::FIELD_FAMILY => (AiValueNormalizer::trimmedStringOrNull($entry[self::FIELD_FAMILY] ?? null) ?? ''),
            self::FIELD_SLICE => (AiValueNormalizer::trimmedStringOrNull($entry[self::FIELD_SLICE] ?? null) ?? ''),
            self::FIELD_MINIMUM_WINDOW => (AiValueNormalizer::trimmedStringOrNull($entry[self::FIELD_SHADOW_MINIMUM_WINDOW] ?? null) ?? ''),
            self::FIELD_DURATION_DAYS => $durationDays,
            self::FIELD_DEPENDS_ON => array_values(array_map('strval', AiValueNormalizer::arrayOrEmpty($entry[self::FIELD_DEPENDS_ON] ?? null))),
            self::FIELD_SERIES => $series[self::FIELD_SERIES] ?? null,
        ];

        if ($lastEvent === null) {
            return array_merge($base, [
                self::FIELD_STATE => self::STATE_NOT_STARTED,
                self::FIELD_DAYS_REMAINING => null,
                self::FIELD_BLOCKING => [self::BLOCKING_WINDOW_NOT_STARTED],
            ]);
        }

        $startedAt = $this->dateOrNull($lastEvent[self::FIELD_RECORDED_AT] ?? null);
        $elapsed = $startedAt instanceof DateTimeImmutable
            ? max(0, intdiv($now->getTimestamp() - $startedAt->getTimestamp(), 86_400))
            : null;
        $remaining = $durationDays !== null && $elapsed !== null
            ? max(0, $durationDays - $elapsed)
            : null;

        $window = array_merge($base, [
            self::FIELD_STATE => (AiValueNormalizer::trimmedStringOrNull($lastEvent[self::FIELD_TO_STATE] ?? null) ?? self::STATE_UNKNOWN),
            self::FIELD_OBSERVATION_WINDOW_ID => (AiValueNormalizer::trimmedStringOrNull($lastEvent[self::FIELD_OBSERVATION_WINDOW_ID] ?? null) ?? ''),
            self::FIELD_STARTED_AT => $startedAt?->format(DateTimeInterface::ATOM),
            self::FIELD_DAYS_ELAPSED => $elapsed,
            self::FIELD_DAYS_REMAINING => $remaining,
            self::FIELD_BLOCKING => $remaining === 0 ? [] : [self::FIELD_MINIMUM_WINDOW_RUNNING],
        ]);

        $alert = $this->deadWindowAlert($window, $series, $now, $deadAfterDays);
        if ($alert !== null) {
            $window[self::FIELD_WATCHDOG_ALERT] = $alert;
        }

        return $window;
    }

    private function durationDays(string $window): ?int
    {
        $window = AiValueNormalizer::lowerTrimmedString($window);
        if (preg_match('/^(\d+)\s*d$/', $window, $m) === 1) {
            return max(1, (int) $m[1]);
        }
        if (preg_match('/^(\d+)\s*h$/', $window, $m) === 1) {
            return max(1, (int) ceil(((int) $m[1]) / 24));
        }

        return null;
    }

    private function dateOrNull(mixed $value): ?DateTimeImmutable
    {
        if (AiValueNormalizer::trimmedStringOrNull($value) === null) {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string,mixed>  $window
     * @param  array<string,mixed>|null  $series
     * @return array<string,mixed>|null
     */
    private function deadWindowAlert(array $window, ?array $series, DateTimeImmutable $now, int $deadAfterDays): ?array
    {
        if ($series === null) {
            return null;
        }

        $lastDataAt = $this->latestSeriesTimestamp($series);
        $reference = $lastDataAt ?? $this->dateOrNull($window[self::FIELD_STARTED_AT] ?? null);
        if (! $reference instanceof DateTimeImmutable) {
            return null;
        }
        $silentDays = max(0, intdiv($now->getTimestamp() - $reference->getTimestamp(), 86_400));
        if ($silentDays < max(1, $deadAfterDays)) {
            return null;
        }

        return [
            self::FIELD_STATUS => self::STATUS_DEAD_WINDOW,
            self::FIELD_FLAG_ID => $window[self::FIELD_FLAG_ID],
            self::FIELD_SLICE => $window[self::FIELD_SLICE],
            self::FIELD_SERIES => $series[self::FIELD_SERIES] ?? null,
            self::FIELD_SILENT_DAYS => $silentDays,
            self::FIELD_LAST_DATA_AT => $lastDataAt?->format(DateTimeInterface::ATOM),
            self::FIELD_REASON => $lastDataAt === null ? 'no_series_data_since_window_start' : 'series_stale_during_window',
        ];
    }

    /**
     * @param  array<string,mixed>  $series
     */
    private function latestSeriesTimestamp(array $series): ?DateTimeImmutable
    {
        $carbon = $this->freshness->lastAppendAt($series);
        if ($carbon === null) {
            return null;
        }

        return DateTimeImmutable::createFromInterface($carbon);
    }

    /**
     * @param  list<array<string,mixed>>  $active
     * @return array<string,mixed>
     */
    private function criticalPath(array $active): array
    {
        $withRemaining = array_values(array_filter(
            $active,
            static fn (array $window): bool => ($window[self::FIELD_DAYS_REMAINING] ?? null) !== null
        ));
        if ($withRemaining === []) {
            return [self::FIELD_STATUS => self::STATUS_UNAVAILABLE, self::FIELD_REASON => self::REASON_NO_STARTED_WINDOW_WITH_NUMERIC_DURATION, self::FIELD_DAYS_REMAINING => null, self::FIELD_NODES => []];
        }
        usort($withRemaining, static fn (array $a, array $b): int => ((int) (AiValueNormalizer::finiteFloatOrNull($b[self::FIELD_DAYS_REMAINING] ?? null) ?? 0)) <=> ((int) (AiValueNormalizer::finiteFloatOrNull($a[self::FIELD_DAYS_REMAINING] ?? null) ?? 0)));
        $top = $withRemaining[0];

        return [
            self::FIELD_STATUS => self::STATUS_OK,
            self::FIELD_DAYS_REMAINING => (int) (AiValueNormalizer::finiteFloatOrNull($top[self::FIELD_DAYS_REMAINING] ?? null) ?? 0),
            self::FIELD_NODES => [[
                self::FIELD_FLAG_ID => $top[self::FIELD_FLAG_ID],
                self::FIELD_FAMILY => $top[self::FIELD_FAMILY],
                self::FIELD_SLICE => $top[self::FIELD_SLICE],
                self::FIELD_OBSERVATION_WINDOW_ID => $top[self::FIELD_OBSERVATION_WINDOW_ID] ?? null,
            ]],
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $active
     * @return list<list<string>>
     */
    private function parallelizableGroups(array $active): array
    {
        $independent = array_values(array_filter(
            $active,
            static fn (array $window): bool => ($window[self::FIELD_DEPENDS_ON] ?? []) === []
        ));
        if (count($independent) < 2) {
            return [];
        }

        return [array_values(array_map(static fn (array $window): string => AiValueNormalizer::trimmedScalarStringOrNull($window[self::FIELD_FLAG_ID] ?? null) ?? '', $independent))];
    }
}
