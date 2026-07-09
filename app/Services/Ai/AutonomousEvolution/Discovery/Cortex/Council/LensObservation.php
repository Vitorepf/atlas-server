<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Council;

/**
 * The output of one Cortex lens observing one {@see CortexSubject} — FACTS plus disagreement_signals (strings
 * naming WHY this lens dissents on aspects the council would otherwise treat as agreed), and NOTHING else.
 *
 * PÉTREO / ANTI-GOODHART: this value object has NO numeric score, NO ranking, NO confidence. The downstream
 * triangulator composes verdicts FROM the facts + disagreements; the lens itself never sneaks an opinion in
 * as a scalar. A reflection-based test pins the no-score invariant against the class shape.
 */
final class LensObservation
{
    /**
     * @param  array<string,mixed>  $facts
     * @param  list<string>  $disagreementSignals  named reasons this lens diverges from a default reading
     */
    public function __construct(
        public readonly string $lensId,
        public readonly string $subjectId,
        public readonly array $facts,
        public readonly array $disagreementSignals = [],
    ) {
    }
}
