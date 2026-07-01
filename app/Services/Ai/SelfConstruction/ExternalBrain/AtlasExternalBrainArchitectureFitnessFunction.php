<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Single honest fitness function for simplification success: a Goodhart-proof gate that never
 * lets "fewer lines" alone count as improvement. Compares BEFORE and AFTER metric vectors —
 * entropy, coupling, proof coverage, capability preservation, and line count — and only reports
 * fitness_improved=true when NONE of entropy, coupling, proof_coverage or capability regressed.
 * Line count reduction is tracked as one delta among several, never a standalone success signal:
 * a smaller diff that regresses proof coverage or capability is a regression, full stop.
 *
 * Input shape (both `before` and `after`):
 *   { entropy_score?:      float,  // lower is better
 *     coupling_score?:     float,  // lower is better
 *     proof_coverage?:     float,  // higher is better
 *     capability_score?:   float,  // higher is better
 *     line_count?:         int,    // lower is informational-good, never gates alone
 *   }
 *
 * Pure PHP, deterministic, no I/O.
 */
final class AtlasExternalBrainArchitectureFitnessFunction
{
    public const SCHEMA = 'atlas.self_construction.external_brain.architecture_fitness_function.v1';

    /**
     * @param  array{before?: array<string,mixed>, after?: array<string,mixed>}  $facts
     * @return array{schema:string, fitness_improved:bool, regressions:list<string>, deltas:array<string,float>}
     */
    public function compute(array $facts): array
    {
        $before = is_array($facts['before'] ?? null) ? $facts['before'] : [];
        $after = is_array($facts['after'] ?? null) ? $facts['after'] : [];

        $entropyDelta = $this->numDelta($before, $after, 'entropy_score');
        $couplingDelta = $this->numDelta($before, $after, 'coupling_score');
        $proofDelta = $this->numDelta($before, $after, 'proof_coverage');
        $capabilityDelta = $this->numDelta($before, $after, 'capability_score');
        $lineDelta = $this->numDelta($before, $after, 'line_count');

        $regressions = [];
        if ($entropyDelta > 0.0) {
            $regressions[] = 'entropy_regressed';
        }
        if ($couplingDelta > 0.0) {
            $regressions[] = 'coupling_regressed';
        }
        if ($proofDelta < 0.0) {
            $regressions[] = 'proof_coverage_regressed';
        }
        if ($capabilityDelta < 0.0) {
            $regressions[] = 'capability_regressed';
        }

        // A regression on any real-health dimension always fails the gate, no matter how many
        // lines shrank — line_count is tracked below but never overrides a regression.
        $anyImprovement = $entropyDelta < 0.0 || $couplingDelta < 0.0 || $proofDelta > 0.0
            || $capabilityDelta > 0.0 || $lineDelta < 0.0;

        return [
            'schema' => self::SCHEMA,
            'fitness_improved' => $regressions === [] && $anyImprovement,
            'regressions' => $regressions,
            'deltas' => [
                'entropy' => $entropyDelta,
                'coupling' => $couplingDelta,
                'proof_coverage' => $proofDelta,
                'capability' => $capabilityDelta,
                'line_count' => $lineDelta,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $before
     * @param  array<string,mixed>  $after
     */
    private function numDelta(array $before, array $after, string $key): float
    {
        return (float) ($after[$key] ?? 0) - (float) ($before[$key] ?? 0);
    }
}
