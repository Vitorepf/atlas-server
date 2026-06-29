<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Insights;

use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Insights\Axis\AtlasCortexInsightObserverAxisOrphanSpike;
use InvalidArgumentException;

final class AtlasCortexInsightObserverRegistry
{
    /**
     * @param  array<string,array{fqcn:class-string,required_fact_keys:list<string>,envelope_shape:array<string,string>}>|null  $descriptors
     */
    public function __construct(
        private readonly ?array $descriptors = null,
    ) {}

    /**
     * @return array<string,array{fqcn:class-string,required_fact_keys:list<string>,envelope_shape:array<string,string>}>
     */
    public function axes(): array
    {
        $axes = $this->descriptors ?? $this->defaultAxes();
        ksort($axes, SORT_STRING);

        $normalized = [];
        foreach ($axes as $axisId => $descriptor) {
            $axisId = trim((string) $axisId);
            if ($axisId === '') {
                throw new InvalidArgumentException('Axis id must not be empty.');
            }

            $fqcn = trim((string) ($descriptor['fqcn'] ?? ''));
            if ($fqcn === '') {
                throw new InvalidArgumentException(sprintf('Axis [%s] must declare an observer FQCN.', $axisId));
            }
            if (! class_exists($fqcn) || ! is_subclass_of($fqcn, AtlasCortexInsightObserverContract::class)) {
                throw new InvalidArgumentException(sprintf('Axis [%s] observer [%s] must implement %s.', $axisId, $fqcn, AtlasCortexInsightObserverContract::class));
            }

            $requiredFactKeys = array_values(array_map(
                static fn (mixed $key): string => (string) $key,
                (array) ($descriptor['required_fact_keys'] ?? []),
            ));
            sort($requiredFactKeys, SORT_STRING);

            $envelopeShape = (array) ($descriptor['envelope_shape'] ?? []);
            ksort($envelopeShape, SORT_STRING);

            $normalized[$axisId] = [
                'fqcn' => $fqcn,
                'required_fact_keys' => $requiredFactKeys,
                'envelope_shape' => $envelopeShape,
            ];
        }

        return $normalized;
    }

    /**
     * @return array<string,array{fqcn:class-string,required_fact_keys:list<string>,envelope_shape:array<string,string>}>
     */
    private function defaultAxes(): array
    {
        return [
            'api_surface' => $this->descriptor(
                AtlasCortexApiSurfaceInsightObserver::class,
                ['scope_runtime_facts', 'scope_comprehension_read_model'],
            ),
            'memory_pressure' => $this->descriptor(
                AtlasCortexMemoryPressureInsightObserver::class,
                ['scope_comprehension_read_model', 'telemetry_fact_stream'],
            ),
            // Structural-alerting axis: a class that became orphaned (built but unwired) since the last snapshot.
            'orphan_spike' => $this->descriptor(
                AtlasCortexInsightObserverAxisOrphanSpike::class,
                ['current_orphan_fqcns', 'prior_orphan_fqcns'],
            ),
            'similarity_clusters' => $this->descriptor(
                AtlasCortexSimilarityInsightObserver::class,
                ['scope_runtime_facts', 'scope_comprehension_read_model'],
            ),
            'temporal_drift' => $this->descriptor(
                AtlasCortexTemporalDriftInsightObserver::class,
                ['scope_runtime_facts', 'telemetry_fact_stream'],
            ),
        ];
    }

    /**
     * @param  class-string  $fqcn
     * @param  list<string>  $requiredFactKeys
     * @return array{fqcn:class-string,required_fact_keys:list<string>,envelope_shape:array<string,string>}
     */
    private function descriptor(string $fqcn, array $requiredFactKeys): array
    {
        sort($requiredFactKeys, SORT_STRING);

        return [
            'fqcn' => $fqcn,
            'required_fact_keys' => $requiredFactKeys,
            'envelope_shape' => [
                'axis_id' => 'string',
                'facts' => 'array',
                'noticed_at' => 'string',
                'observation_kind' => 'string',
            ],
        ];
    }
}

final class AtlasCortexApiSurfaceInsightObserver implements AtlasCortexInsightObserverContract
{
    public function observe(array $facts): array
    {
        return ['observation_kind' => 'api_surface', 'facts' => $facts];
    }
}

final class AtlasCortexMemoryPressureInsightObserver implements AtlasCortexInsightObserverContract
{
    public function observe(array $facts): array
    {
        return ['observation_kind' => 'memory_pressure', 'facts' => $facts];
    }
}

final class AtlasCortexSimilarityInsightObserver implements AtlasCortexInsightObserverContract
{
    public function observe(array $facts): array
    {
        return ['observation_kind' => 'similarity_clusters', 'facts' => $facts];
    }
}

final class AtlasCortexTemporalDriftInsightObserver implements AtlasCortexInsightObserverContract
{
    public function observe(array $facts): array
    {
        return ['observation_kind' => 'temporal_drift', 'facts' => $facts];
    }
}
