<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

/**
 * ACDE lever D2 — the SINGLE machine-resolved per-delivery dimension definition.
 *
 * Rivals + the repeated head-to-head are DEAD (see docs/acde-teto-closure.md): quality is proven PER
 * DELIVERY by a dossier. For that to be honest, the dossier and any aggregate must compute the SAME
 * machine-resolved dimensions from the SAME definition — not two drifting copies, and never the private
 * resolvers buried inside the deprecated Rivals/DQS surface. This service is that one Rivals-free definition:
 * given one attempted-obra outcome it classifies the defect/clean state and clamps the tie-break axes, with
 * NO comparison to any other engine. {@see AtlasLoopDeliveryQualityScore} delegates to it (byte-identical),
 * and the per-delivery dossier (lever F3/D1) reuses it verbatim.
 *
 * Anti-selection-bias rule (carried from the scorer): a REFUSAL (attempted but not committed) is a delivery
 * DEFECT, and a committed-but-canary-RED is an ESCAPED defect; only attempted+committed+canary-not-red is
 * clean. Never a self-report — every field comes from the certify()/merge envelope.
 */
final class AtlasLoopDeliveryDimensionResolver
{
    /**
     * @param  array<string,mixed>  $outcome  one attempted-obra outcome (attempted/committed/canary + axes)
     * @return array{attempted:bool, committed:bool, canary:string, clean:bool, defect:bool, defect_reason:?string, mutation_kill_ratio:float, completeness:float, cyclomatic_drop:float}
     */
    public function resolve(array $outcome): array
    {
        $attempted = ($outcome['attempted'] ?? true) === true;
        $committed = ($outcome['committed'] ?? false) === true;
        $canary = mb_strtolower(trim((string) ($outcome['canary'] ?? 'not_run')));
        $canaryRed = $canary === 'red';

        $refused = $attempted && ! $committed;          // attempted but not committed => a delivery defect
        $escaped = $attempted && $committed && $canaryRed; // committed but the canary went RED => escaped defect
        $clean = $attempted && $committed && ! $canaryRed;

        return [
            'attempted' => $attempted,
            'committed' => $committed,
            'canary' => $canary,
            'clean' => $clean,
            'defect' => $refused || $escaped,
            'defect_reason' => $refused ? 'refusal' : ($escaped ? 'canary_red' : null),
            'mutation_kill_ratio' => max(0.0, min(1.0, (float) ($outcome['mutation_kill_ratio'] ?? 0.0))),
            'completeness' => max(0.0, min(1.0, (float) ($outcome['completeness'] ?? 0.0))),
            'cyclomatic_drop' => max(0.0, (float) ($outcome['cyclomatic_drop'] ?? 0.0)),
        ];
    }
}
