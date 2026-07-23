<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Summarizes each originator round into next-round keep, avoid,
 * repair, and pivot directives using enqueue results, health gates,
 * malformed sweep, and queued-target collision data.
 *
 * Read-only: never starts processes, never calls providers.
 */
final class AtlasExternalBrainOriginatorRoundRetrospectiveCompiler
{
    public const SCHEMA = 'atlas.self_construction.external_brain_originator_round_retrospective.v1';

    public const DIRECTIVE_KEEP = 'keep';
    public const DIRECTIVE_AVOID = 'avoid';
    public const DIRECTIVE_REPAIR = 'repair';
    public const DIRECTIVE_PIVOT = 'pivot';

    /**
     * @param  array<string, mixed>  $roundData
     * @return array<string, mixed>
     */
    public function compile(array $roundData): array
    {
        $enqueueResults = (array) ($roundData['enqueue_results'] ?? []);
        $healthGates = (array) ($roundData['health_gates'] ?? []);
        $malformedSweep = (array) ($roundData['malformed_sweep'] ?? []);
        $collisions = (array) ($roundData['queued_target_collisions'] ?? []);

        $keep = [];
        $avoid = [];
        $repair = [];
        $pivot = [];

        // Process enqueue results
        $enqueued = 0;
        $rejected = 0;
        foreach ($enqueueResults as $result) {
            if (! is_array($result)) {
                continue;
            }
            $status = (string) ($result['status'] ?? '');
            if ($status === 'enqueued') {
                $enqueued++;
                $keep[] = 'enqueue_strategy:'.$result['strategy'] ?? 'default';
            } elseif ($status === 'rejected') {
                $rejected++;
                $repair[] = 'rejected_spec:'.$result['reason'] ?? 'unknown';
            }
        }

        // Process health gates
        foreach ($healthGates as $gate) {
            if (! is_array($gate)) {
                continue;
            }
            $passed = (bool) ($gate['passed'] ?? true);
            if (! $passed) {
                $avoid[] = 'health_gate:'.$gate['name'] ?? 'unknown';
            }
        }

        // Process malformed sweep
        foreach ($malformedSweep as $malformed) {
            if (! is_array($malformed)) {
                continue;
            }
            $avoid[] = 'malformed:'.$malformed['reason'] ?? 'unknown';
        }

        // Process collisions
        if ($collisions !== []) {
            $pivot[] = 'collision_pressure:'.count($collisions).' targets collided';
        }

        // Clean round: all enqueued, no issues
        $cleanRound = $rejected === 0 && $healthGates === [] && $malformedSweep === [] && $collisions === [];
        if ($cleanRound && $enqueued > 0) {
            $keep[] = 'round_strategy:all_enqueued_clean';
        }

        return [
            'schema' => self::SCHEMA,
            'keep' => array_values(array_unique($keep)),
            'avoid' => array_values(array_unique($avoid)),
            'repair' => array_values(array_unique($repair)),
            'pivot' => array_values(array_unique($pivot)),
            'enqueued_count' => $enqueued,
            'rejected_count' => $rejected,
            'clean_round' => $cleanRound,
        ];
    }
}
