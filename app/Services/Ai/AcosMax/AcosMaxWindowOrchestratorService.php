<?php

declare(strict_types=1);

namespace App\Services\Ai\AcosMax;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

final class AcosMaxWindowOrchestratorService
{
    public const SCHEMA_VERSION = 'atlas.acos.windows.v1';

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
            $last = $this->lastEventFor((string) ($entry['id'] ?? ''), $events);
            $windows[] = $this->windowForEntry($entry, $last, $registry[(string) ($entry['slice'] ?? '')] ?? null, $now, $deadAfterDays);
        }

        $active = array_values(array_filter(
            $windows,
            static fn (array $window): bool => ($window['state'] ?? null) !== 'not_started'
                && ($window['observation_window_id'] ?? null) !== null
        ));
        $critical = $this->criticalPath($active);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => 'ok',
            'generated_at' => $now->format(DateTimeInterface::ATOM),
            'source' => [
                'promotion_protocol_schema' => PromotionProtocol::SCHEMA,
                'read_only' => true,
                'starts_windows' => false,
                'scheduler' => false,
            ],
            'critical_path' => $critical,
            'parallelizable_groups' => $this->parallelizableGroups($active),
            'watchdog' => [
                'dead_after_days' => max(1, $deadAfterDays),
                'alerts' => array_values(array_filter(
                    array_map(static fn (array $window): ?array => $window['watchdog_alert'] ?? null, $windows)
                )),
            ],
            'windows' => array_map(static function (array $window): array {
                unset($window['watchdog_alert']);

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
            $slice = (string) ($entry['slice'] ?? '');
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
            if ((string) ($event['flag_id'] ?? '') === $flagId) {
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
        $durationDays = $this->durationDays((string) ($entry['shadow_minimum_window'] ?? ''));
        $base = [
            'flag_id' => (string) ($entry['id'] ?? ''),
            'family' => (string) ($entry['family'] ?? ''),
            'slice' => (string) ($entry['slice'] ?? ''),
            'minimum_window' => (string) ($entry['shadow_minimum_window'] ?? ''),
            'duration_days' => $durationDays,
            'depends_on' => array_values(array_map('strval', (array) ($entry['depends_on'] ?? []))),
            'series' => $series['series'] ?? null,
        ];

        if ($lastEvent === null) {
            return array_merge($base, [
                'state' => 'not_started',
                'days_remaining' => null,
                'blocking' => ['window_not_started'],
            ]);
        }

        $startedAt = $this->dateOrNull($lastEvent['recorded_at'] ?? null);
        $elapsed = $startedAt instanceof DateTimeImmutable
            ? max(0, intdiv($now->getTimestamp() - $startedAt->getTimestamp(), 86_400))
            : null;
        $remaining = $durationDays !== null && $elapsed !== null
            ? max(0, $durationDays - $elapsed)
            : null;

        $window = array_merge($base, [
            'state' => (string) ($lastEvent['to_state'] ?? 'unknown'),
            'observation_window_id' => (string) ($lastEvent['observation_window_id'] ?? ''),
            'started_at' => $startedAt?->format(DateTimeInterface::ATOM),
            'days_elapsed' => $elapsed,
            'days_remaining' => $remaining,
            'blocking' => $remaining === 0 ? [] : ['minimum_window_running'],
        ]);

        $alert = $this->deadWindowAlert($window, $series, $now, $deadAfterDays);
        if ($alert !== null) {
            $window['watchdog_alert'] = $alert;
        }

        return $window;
    }

    private function durationDays(string $window): ?int
    {
        $window = strtolower(trim($window));
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
        if (! is_string($value) || trim($value) === '') {
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
        $reference = $lastDataAt ?? $this->dateOrNull($window['started_at'] ?? null);
        if (! $reference instanceof DateTimeImmutable) {
            return null;
        }
        $silentDays = max(0, intdiv($now->getTimestamp() - $reference->getTimestamp(), 86_400));
        if ($silentDays < max(1, $deadAfterDays)) {
            return null;
        }

        return [
            'status' => 'dead_window',
            'flag_id' => $window['flag_id'],
            'slice' => $window['slice'],
            'series' => $series['series'] ?? null,
            'silent_days' => $silentDays,
            'last_data_at' => $lastDataAt?->format(DateTimeInterface::ATOM),
            'reason' => $lastDataAt === null ? 'no_series_data_since_window_start' : 'series_stale_during_window',
        ];
    }

    /**
     * @param  array<string,mixed>  $series
     */
    private function latestSeriesTimestamp(array $series): ?DateTimeImmutable
    {
        $path = (string) ($series['path'] ?? '');
        if ($path === '' || ! is_file($path)) {
            return null;
        }
        $field = (string) ($series['timestamp_field'] ?? 'recorded_at');
        $latest = null;

        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $row = json_decode((string) $line, true);
            if (! is_array($row)) {
                continue;
            }
            $candidate = $this->dateOrNull($row[$field] ?? null);
            if ($candidate instanceof DateTimeImmutable && ($latest === null || $candidate > $latest)) {
                $latest = $candidate;
            }
        }

        return $latest;
    }

    /**
     * @param  list<array<string,mixed>>  $active
     * @return array<string,mixed>
     */
    private function criticalPath(array $active): array
    {
        $withRemaining = array_values(array_filter(
            $active,
            static fn (array $window): bool => ($window['days_remaining'] ?? null) !== null
        ));
        if ($withRemaining === []) {
            return ['status' => 'unavailable', 'reason' => 'no_started_window_with_numeric_duration', 'days_remaining' => null, 'nodes' => []];
        }
        usort($withRemaining, static fn (array $a, array $b): int => ((int) ($b['days_remaining'] ?? 0)) <=> ((int) ($a['days_remaining'] ?? 0)));
        $top = $withRemaining[0];

        return [
            'status' => 'ok',
            'days_remaining' => (int) ($top['days_remaining'] ?? 0),
            'nodes' => [[
                'flag_id' => $top['flag_id'],
                'family' => $top['family'],
                'slice' => $top['slice'],
                'observation_window_id' => $top['observation_window_id'] ?? null,
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
            static fn (array $window): bool => ($window['depends_on'] ?? []) === []
        ));
        if (count($independent) < 2) {
            return [];
        }

        return [array_values(array_map(static fn (array $window): string => (string) $window['flag_id'], $independent))];
    }
}
