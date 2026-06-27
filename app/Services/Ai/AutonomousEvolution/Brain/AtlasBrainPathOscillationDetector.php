<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Brain;

/**
 * PATH OSCILLATION DETECTOR — pattern-design organ. Scans the recent path sequence for ABABAB
 * ping-pong shapes (alternating between exactly two paths for ≥6 consecutive picks). Looks like
 * rotation, but it's stuck exploration over only two paths while the other 5 starve.
 *
 * Pure scan over the path-mapped tail. No IO. Pétreo: a réu would widen the window so oscillation
 * never tripped.
 */
final class AtlasBrainPathOscillationDetector
{
    public const SCHEMA = 'atlas.brain.path_oscillation_detector.v1';

    private const MIN_RUN = 6;

    /**
     * @param  list<array{action_hint?:string}>  $reflectionTail
     * @return array{schema:string, detected:bool, pair:?array{0:string,1:string}, run_length:int}
     */
    public function detect(array $reflectionTail, AtlasBrainHintToPathTranslator $translator): array
    {
        $paths = [];
        foreach ($reflectionTail as $r) {
            $hint = (string) ($r['action_hint'] ?? '');
            if ($hint === '') {
                continue;
            }
            $p = $translator->pathFor($hint);
            if ($p === null) {
                continue;
            }
            $paths[] = $p;
        }
        $count = count($paths);
        if ($count < self::MIN_RUN) {
            return ['schema' => self::SCHEMA, 'detected' => false, 'pair' => null, 'run_length' => 0];
        }

        $bestRun = 0;
        $bestPair = null;
        for ($start = 0; $start <= $count - self::MIN_RUN; $start++) {
            $a = $paths[$start];
            $b = $paths[$start + 1];
            if ($a === $b) {
                continue;
            }
            $run = 2;
            for ($i = $start + 2; $i < $count; $i++) {
                $expected = ($i - $start) % 2 === 0 ? $a : $b;
                if ($paths[$i] !== $expected) {
                    break;
                }
                $run++;
            }
            if ($run >= self::MIN_RUN && $run > $bestRun) {
                $bestRun = $run;
                $bestPair = [$a, $b];
            }
        }

        if ($bestPair === null) {
            return ['schema' => self::SCHEMA, 'detected' => false, 'pair' => null, 'run_length' => 0];
        }

        return ['schema' => self::SCHEMA, 'detected' => true, 'pair' => $bestPair, 'run_length' => $bestRun];
    }
}
