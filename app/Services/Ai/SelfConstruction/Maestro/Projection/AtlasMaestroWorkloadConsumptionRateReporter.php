<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Maestro\Projection;

use Carbon\CarbonImmutable;

final class AtlasMaestroWorkloadConsumptionRateReporter
{
    public const SCHEMA = 'atlas.maestro.projection.consumption_rate.v1';

    /** Terminal labels that all mean "this task is done" — completed_dry_run is the legacy/canonical name. */
    private const COMPLETED_ALIASES = ['completed_dry_run', 'success', 'resolved', 'completed'];

    private const FAILURE_ALIASES = ['failed', 'failure'];

    public function __construct(
        private readonly ?object $queueRepository = null,
    ) {}

    /**
     * @param  array{events?:list<array<string,mixed>>}  $registrySnapshot
     * @return array{
     *   observed_at_iso:string,
     *   rows:list<array<string,int|float|string>>,
     *   family_rows:list<array<string,int|float|string>>,
     *   schema:string
     * }
     */
    public function report(array $registrySnapshot, CarbonImmutable $now, int $windowSeconds = 3600): array
    {
        $windowSeconds = max(1, $windowSeconds);
        $windowStart = $now->subSeconds($windowSeconds);
        $events = array_values(array_filter((array) ($registrySnapshot['events'] ?? []), 'is_array'));

        $perClient = [];
        $perFamily = [];
        $aliasEvidence = [];

        foreach ($events as $event) {
            $clientId = trim((string) ($event['client_id'] ?? ''));
            $eventName = (string) ($event['to'] ?? $event['event'] ?? $event['status'] ?? '');
            $recordedAtRaw = (string) ($event['recorded_at'] ?? $event['at'] ?? '');
            $recordedAt = $recordedAtRaw !== '' ? CarbonImmutable::parse($recordedAtRaw) : null;
            $taskFamily = (string) ($event['task_family'] ?? $event['task_class'] ?? 'unknown');

            if ($clientId === '' || $recordedAt === null || $recordedAt->lt($windowStart) || $recordedAt->gt($now)) {
                continue;
            }

            $perClient[$clientId] ??= [
                'client_id' => $clientId,
                'window_seconds' => $windowSeconds,
                'completed_count' => 0,
                'give_back_count' => 0,
                'failed_count' => 0,
                'started_count' => 0,
                'tasks_per_hour' => 0.0,
                'completed_alias_counts' => [],
                'observed_at_iso' => $now->toIso8601String(),
            ];

            $perFamily[$taskFamily] ??= [
                'task_family' => $taskFamily,
                'window_seconds' => $windowSeconds,
                'completed_count' => 0,
                'give_back_count' => 0,
                'failed_count' => 0,
                'tasks_per_hour' => 0.0,
                'observed_at_iso' => $now->toIso8601String(),
            ];

            if (in_array($eventName, self::COMPLETED_ALIASES, true)) {
                $perClient[$clientId]['completed_count']++;
                $perFamily[$taskFamily]['completed_count']++;
                $perClient[$clientId]['completed_alias_counts'][$eventName] = ($perClient[$clientId]['completed_alias_counts'][$eventName] ?? 0) + 1;
                $aliasEvidence[$eventName] = ($aliasEvidence[$eventName] ?? 0) + 1;
            } elseif ($eventName === 'give_back') {
                $perClient[$clientId]['give_back_count']++;
                $perFamily[$taskFamily]['give_back_count']++;
            } elseif (in_array($eventName, self::FAILURE_ALIASES, true)) {
                $perClient[$clientId]['failed_count']++;
                $perFamily[$taskFamily]['failed_count']++;
            }

            // claimed/served/started = in-flight start; not consumed until terminal event
            if (in_array($eventName, ['claimed', 'served', 'started'], true)) {
                $perClient[$clientId]['started_count']++;
            }
        }

        ksort($perClient, SORT_STRING);
        ksort($perFamily, SORT_STRING);

        $rows = [];
        $fleetCompleted = 0;
        $fleetGiveBack = 0;
        $fleetFailed = 0;
        $fleetStarted = 0;
        foreach ($perClient as $row) {
            $row['tasks_per_hour'] = $this->tasksPerHour((int) $row['completed_count'], $windowSeconds);
            ksort($row['completed_alias_counts'], SORT_STRING);
            $fleetCompleted += (int) $row['completed_count'];
            $fleetGiveBack += (int) $row['give_back_count'];
            $fleetFailed += (int) $row['failed_count'];
            $fleetStarted += (int) $row['started_count'];
            $rows[] = $row;
        }

        ksort($aliasEvidence, SORT_STRING);

        $rows[] = [
            'client_id' => 'fleet',
            'window_seconds' => $windowSeconds,
            'completed_count' => $fleetCompleted,
            'give_back_count' => $fleetGiveBack,
            'failed_count' => $fleetFailed,
            'started_count' => $fleetStarted,
            'tasks_per_hour' => $this->tasksPerHour($fleetCompleted, $windowSeconds),
            'completed_alias_counts' => $aliasEvidence,
            'observed_at_iso' => $now->toIso8601String(),
        ];

        $familyRows = [];
        foreach ($perFamily as $row) {
            $row['tasks_per_hour'] = $this->tasksPerHour((int) $row['completed_count'], $windowSeconds);
            $familyRows[] = $row;
        }

        $terminalAliasEvidence = [];
        foreach ($aliasEvidence as $alias => $count) {
            $terminalAliasEvidence[] = ['alias' => $alias, 'count' => $count];
        }

        return [
            'schema' => self::SCHEMA,
            'rows' => $rows,
            'family_rows' => $familyRows,
            'terminal_alias_evidence' => $terminalAliasEvidence,
            'observed_at_iso' => $now->toIso8601String(),
        ];
    }

    private function tasksPerHour(int $completedCount, int $windowSeconds): float
    {
        return round($completedCount / ($windowSeconds / 3600), 4);
    }
}
