<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Insights\Axis;

use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Insights\AtlasCortexInsightObservation;
use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Insights\AtlasCortexInsightObservationFactException;
use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Insights\AtlasCortexInsightObserverContract;

final class AtlasCortexInsightObserverAxisIntentDrift implements AtlasCortexInsightObserverContract
{
    /**
     * @param  array<string,mixed>  $facts
     * @return array<string,mixed>
     */
    public function observe(array $facts): array
    {
        $this->assertRequiredFacts($facts, ['unit_status_rows']);

        $matchedUnits = [];
        foreach ((array) $facts['unit_status_rows'] as $row) {
            if (! is_array($row)) {
                continue;
            }

            $unit = trim((string) ($row['unit'] ?? ''));
            if ($unit === '') {
                continue;
            }

            if (($row['last_merge_clean'] ?? false) === true && ($row['has_gate_block'] ?? false) === true) {
                $matchedUnits[] = $unit;
            }
        }

        $matchedUnits = $this->normalizeWitnesses($matchedUnits);
        if ($matchedUnits === []) {
            return [];
        }

        return (new AtlasCortexInsightObservation(
            axisId: 'intent_drift',
            observationKind: 'intent_drift',
            noticedAt: AtlasCortexInsightObservation::normalizeTimestamp(isset($facts['noticed_at']) ? (string) $facts['noticed_at'] : null),
            facts: [
                'matched_units' => $matchedUnits,
            ],
            witnesses: $matchedUnits,
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
     * @param  list<string>  $units
     * @return list<string>
     */
    private function normalizeWitnesses(array $units): array
    {
        $units = array_values(array_unique(array_filter(
            array_map(static fn (string $unit): string => trim($unit), $units),
            static fn (string $unit): bool => $unit !== '',
        )));
        sort($units, SORT_STRING);

        return $units;
    }
}
