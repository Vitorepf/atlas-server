<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Brain;

/**
 * PATH SIGNAL AGGREGATOR — comprehension-deepening organ. Fuses per-path signals from multiple
 * upstream organs (momentum trend, velocity label, oscillation membership) into a single per-path
 * row with an "agreement" flag: signals_agree when momentum and velocity point the same direction
 * (both improving / both declining), signals_disagree when they conflict (one improving, one
 * decelerating), oscillation_pin when the path is one half of an oscillating pair (overrides
 * momentum readings — the pair is stuck, not improving).
 *
 * Pure decision over already-built inputs. No IO. Pétreo: réu would mark conflicting signals as
 * agreement to silence dissent.
 */
final class AtlasBrainPathSignalAggregator
{
    public const SCHEMA = 'atlas.brain.path_signal_aggregator.v1';

    /**
     * @param  array<string, array{trend:string}>  $momentumByPath  from AtlasBrainPathYieldMomentum
     * @param  array<string, array{label:string}>  $velocityByPath  from AtlasBrainCompoundingVelocity
     * @param  array{detected:bool, pair:?array{0:string,1:string}}  $oscillation  from AtlasBrainPathOscillationDetector
     * @return array{schema:string, by_path:array<string, array{momentum:string, velocity:string, oscillating:bool, agreement:string}>}
     */
    public function aggregate(array $momentumByPath, array $velocityByPath, array $oscillation): array
    {
        $oscPair = ($oscillation['detected'] && $oscillation['pair'] !== null) ? $oscillation['pair'] : [];
        $paths = array_unique(array_merge(array_keys($momentumByPath), array_keys($velocityByPath), $oscPair));
        $out = [];
        foreach ($paths as $path) {
            $momentum = $momentumByPath[$path]['trend'] ?? 'unknown';
            $velocity = $velocityByPath[$path]['label'] ?? 'unknown';
            $oscillating = in_array($path, $oscPair, true);

            if ($oscillating) {
                $agreement = 'oscillation_pin';
            } elseif ($momentum === 'unknown' || $velocity === 'unknown') {
                $agreement = 'insufficient_signal';
            } elseif ($this->positiveDirection($momentum) && $this->positiveDirection($velocity)) {
                $agreement = 'signals_agree_improving';
            } elseif ($this->negativeDirection($momentum) && $this->negativeDirection($velocity)) {
                $agreement = 'signals_agree_declining';
            } elseif ($momentum === 'flat' && $velocity === 'steady') {
                $agreement = 'signals_agree_steady';
            } else {
                $agreement = 'signals_disagree';
            }

            $out[$path] = [
                'momentum' => $momentum,
                'velocity' => $velocity,
                'oscillating' => $oscillating,
                'agreement' => $agreement,
            ];
        }

        return ['schema' => self::SCHEMA, 'by_path' => $out];
    }

    private function positiveDirection(string $label): bool
    {
        return $label === 'improving' || $label === 'accelerating';
    }

    private function negativeDirection(string $label): bool
    {
        return $label === 'declining' || $label === 'decelerating';
    }
}
