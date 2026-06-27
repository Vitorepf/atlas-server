<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Brain;

/**
 * PATH YIELD EWMA — compounding organ. Per-path exponentially weighted moving average over
 * the chronological reflection tail. Recent samples weigh more (alpha 0..1); historic samples
 * compound into the long-run baseline. Distinct from momentum (delta) and velocity (acceleration);
 * the EWMA is the smoothed level signal.
 *
 * Pure, deterministic, no IO. Pétreo: réu would clamp alpha to 1.0 (no smoothing) so noise hid
 * deterioration trends.
 */
final class AtlasBrainPathYieldEwma
{
    public const SCHEMA = 'atlas.brain.path_yield_ewma.v1';

    public const DEFAULT_ALPHA = 0.3;

    /**
     * @param  list<array{action_hint?:string, result_kind?:string}>  $reflectionTail
     * @return array{schema:string, alpha:float, by_path:array<string, array{ewma:float, samples:int}>}
     */
    public function compute(array $reflectionTail, AtlasBrainHintToPathTranslator $translator, float $alpha = self::DEFAULT_ALPHA): array
    {
        $alpha = max(0.01, min(1.0, $alpha));
        $state = [];  // path => ['ewma' => float|null, 'samples' => int]
        foreach ($reflectionTail as $r) {
            $hint = (string) ($r['action_hint'] ?? '');
            $kind = (string) ($r['result_kind'] ?? '');
            if ($hint === '' || $kind === '') {
                continue;
            }
            $path = $translator->pathFor($hint);
            if ($path === null) {
                continue;
            }
            $sampleYield = $kind === 'accepted' ? 1.0 : 0.0;
            if (! isset($state[$path])) {
                $state[$path] = ['ewma' => $sampleYield, 'samples' => 1];
            } else {
                $state[$path]['ewma'] = $alpha * $sampleYield + (1.0 - $alpha) * $state[$path]['ewma'];
                $state[$path]['samples']++;
            }
        }

        $out = [];
        foreach ($state as $path => $s) {
            $out[$path] = ['ewma' => round($s['ewma'], 4), 'samples' => $s['samples']];
        }

        return ['schema' => self::SCHEMA, 'alpha' => $alpha, 'by_path' => $out];
    }
}
