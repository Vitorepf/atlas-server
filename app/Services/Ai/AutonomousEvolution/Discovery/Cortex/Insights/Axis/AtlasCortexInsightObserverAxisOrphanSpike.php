<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Insights\Axis;

use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Insights\AtlasCortexInsightObservation;
use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Insights\AtlasCortexInsightObservationFactException;
use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Insights\AtlasCortexInsightObserverContract;

final class AtlasCortexInsightObserverAxisOrphanSpike implements AtlasCortexInsightObserverContract
{
    /**
     * @param  array<string,mixed>  $facts
     * @return array<string,mixed>
     */
    public function observe(array $facts): array
    {
        $this->assertRequiredFacts($facts, ['current_orphan_fqcns', 'prior_orphan_fqcns']);

        $current = $this->normalizeWitnesses((array) $facts['current_orphan_fqcns']);
        $prior = $this->normalizeWitnesses((array) $facts['prior_orphan_fqcns']);

        if (array_diff($prior, $current) !== []) {
            return [];
        }

        $newWitnesses = $this->normalizeWitnesses(array_values(array_diff($current, $prior)));
        if ($newWitnesses === []) {
            return [];
        }

        return (new AtlasCortexInsightObservation(
            axisId: 'orphan_spike',
            observationKind: 'orphan_spike',
            noticedAt: AtlasCortexInsightObservation::normalizeTimestamp(isset($facts['noticed_at']) ? (string) $facts['noticed_at'] : null),
            facts: [
                'current_orphan_fqcns' => $current,
                'prior_orphan_fqcns' => $prior,
                'new_orphan_fqcns' => $newWitnesses,
            ],
            witnesses: $newWitnesses,
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
