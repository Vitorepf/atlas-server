<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Insights\Axis;

use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Insights\AtlasCortexInsightObservation;
use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Insights\AtlasCortexInsightObservationFactException;
use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Insights\AtlasCortexInsightObserverContract;

final class AtlasCortexInsightObserverAxisRecurrenceCluster implements AtlasCortexInsightObserverContract
{
    /**
     * @param  array<string,mixed>  $facts
     * @return array<string,mixed>
     */
    public function observe(array $facts): array
    {
        $this->assertRequiredFacts($facts, ['history_orphan_snapshots']);

        $recurrences = [];
        foreach ((array) $facts['history_orphan_snapshots'] as $snapshot) {
            $members = [];

            if (is_array($snapshot) && array_key_exists('orphan_fqcns', $snapshot)) {
                $members = $this->normalizeWitnesses((array) $snapshot['orphan_fqcns']);
            } elseif (is_array($snapshot)) {
                $members = $this->normalizeWitnesses($snapshot);
            }

            foreach ($members as $member) {
                $recurrences[$member] = ($recurrences[$member] ?? 0) + 1;
            }
        }

        $witnesses = [];
        foreach ($recurrences as $member => $count) {
            if ($count >= 3) {
                $witnesses[] = $member;
            }
        }

        $witnesses = $this->normalizeWitnesses($witnesses);
        if ($witnesses === []) {
            return [];
        }

        $recurringCounts = [];
        foreach ($witnesses as $member) {
            $recurringCounts[$member] = $recurrences[$member];
        }
        ksort($recurringCounts, SORT_STRING);

        return (new AtlasCortexInsightObservation(
            axisId: 'recurrence_cluster',
            observationKind: 'recurrence_cluster',
            noticedAt: AtlasCortexInsightObservation::normalizeTimestamp(isset($facts['noticed_at']) ? (string) $facts['noticed_at'] : null),
            facts: [
                'history_window_snapshots' => count((array) $facts['history_orphan_snapshots']),
                'recurring_units' => $recurringCounts,
            ],
            witnesses: $witnesses,
        ))->toArray();
    }

    /**
     * @param  array<string,mixed>  $facts
     * @param  list<string>  $requiredKeys
     */
    private function assertRequiredFacts(array $facts, array $requiredKeys): void
    {
        $missing = [];
        foreach ($requiredKeys as $key) {
            if (! array_key_exists($key, $facts)) {
                $missing[] = $key;
            }
        }

        if ($missing !== []) {
            throw AtlasCortexInsightObservationFactException::missing(self::class, $missing);
        }
    }

    /**
     * @param  list<mixed>  $values
     * @return list<string>
     */
    private function normalizeWitnesses(array $values): array
    {
        $values = array_values(array_unique(array_filter(
            array_map(static fn (mixed $value): string => trim((string) $value), $values),
            static fn (string $value): bool => $value !== '',
        )));
        sort($values, SORT_STRING);

        return $values;
    }
}
