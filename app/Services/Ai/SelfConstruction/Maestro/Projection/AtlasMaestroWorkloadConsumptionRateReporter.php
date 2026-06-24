<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Maestro\Projection;

use Carbon\CarbonImmutable;

final class AtlasMaestroWorkloadConsumptionRateReporter
{
    public const SCHEMA = 'atlas.maestro.projection.consumption_rate.v1';

    public function __construct(
        private readonly ?object $queueRepository = null,
    ) {}

    /**
     * @param  array{events?:list<array<string,mixed>>}  $registrySnapshot
     * @return array{
     *   observed_at_iso:string,
     *   rows:list<array<string,int|float|string>>,
     *   schema:string
     * }
     */
    public function report(array $registrySnapshot, CarbonImmutable $now, int $windowSeconds = 3600): array
    {
        $windowSeconds = max(1, $windowSeconds);
        $windowStart = $now->subSeconds($windowSeconds);
        $events = array_values(array_filter((array) ($registrySnapshot['events'] ?? []), 'is_array'));

        $perClient = [];
        foreach ($events as $event) {
            $clientId = trim((string) ($event['client_id'] ?? ''));
            $eventName = (string) ($event['event'] ?? $event['status'] ?? '');
            $recordedAtRaw = (string) ($event['recorded_at'] ?? $event['at'] ?? '');
            $recordedAt = $recordedAtRaw !== '' ? CarbonImmutable::parse($recordedAtRaw) : null;

            if ($clientId === '' || $recordedAt === null || $recordedAt->lt($windowStart) || $recordedAt->gt($now)) {
                continue;
            }

            $perClient[$clientId] ??= [
                'client_id' => $clientId,
                'window_seconds' => $windowSeconds,
                'completed_count' => 0,
                'started_count' => 0,
                'tasks_per_hour' => 0.0,
                'observed_at_iso' => $now->toIso8601String(),
            ];

            if ($eventName === 'completed_dry_run') {
                $perClient[$clientId]['completed_count']++;
            }

            if (in_array($eventName, ['claimed', 'served', 'started'], true)) {
                $perClient[$clientId]['started_count']++;
            }
        }

        ksort($perClient, SORT_STRING);

        $rows = [];
        $fleetCompleted = 0;
        $fleetStarted = 0;
        foreach ($perClient as $row) {
            $row['tasks_per_hour'] = $this->tasksPerHour((int) $row['completed_count'], $windowSeconds);
            $fleetCompleted += (int) $row['completed_count'];
            $fleetStarted += (int) $row['started_count'];
            $rows[] = $row;
        }

        $rows[] = [
            'client_id' => 'fleet',
            'window_seconds' => $windowSeconds,
            'completed_count' => $fleetCompleted,
            'started_count' => $fleetStarted,
            'tasks_per_hour' => $this->tasksPerHour($fleetCompleted, $windowSeconds),
            'observed_at_iso' => $now->toIso8601String(),
        ];

        return [
            'schema' => self::SCHEMA,
            'rows' => $rows,
            'observed_at_iso' => $now->toIso8601String(),
        ];
    }

    private function tasksPerHour(int $completedCount, int $windowSeconds): float
    {
        return round($completedCount / ($windowSeconds / 3600), 4);
    }
}
