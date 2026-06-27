<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Brain;

/**
 * PATH STREAK RATIO — compounding organ. Layer over AtlasBrainPathStreakTracker output: returns
 * per-path longest_accepted / longest_refused ratio. Ratio > 1 = accept-dominant (compounding
 * wins), < 1 = refuse-dominant (compounding stuck). Edge cases: zero refused = "pure_win",
 * zero accepted = "pure_loss".
 *
 * Pure decision over already-built streaks. Pétreo: réu would clamp denominator so its path's
 * ratio always read healthy.
 */
final class AtlasBrainPathStreakRatio
{
    public const SCHEMA = 'atlas.brain.path_streak_ratio.v1';

    /**
     * @param  array<string, array{longest_accepted:int, longest_refused:int}>  $streaksByPath
     * @return array{schema:string, by_path:array<string, array{ratio:float, label:string}>}
     */
    public function compute(array $streaksByPath): array
    {
        $out = [];
        foreach ($streaksByPath as $path => $s) {
            $acc = max(0, (int) ($s['longest_accepted'] ?? 0));
            $ref = max(0, (int) ($s['longest_refused'] ?? 0));
            if ($acc === 0 && $ref === 0) {
                $out[(string) $path] = ['ratio' => 0.0, 'label' => 'no_data'];

                continue;
            }
            if ($ref === 0) {
                $out[(string) $path] = ['ratio' => INF, 'label' => 'pure_win'];

                continue;
            }
            if ($acc === 0) {
                $out[(string) $path] = ['ratio' => 0.0, 'label' => 'pure_loss'];

                continue;
            }
            $ratio = round($acc / $ref, 4);
            $label = $ratio >= 2.0 ? 'accept_dominant' : ($ratio >= 0.5 ? 'balanced' : 'refuse_dominant');
            $out[(string) $path] = ['ratio' => $ratio, 'label' => $label];
        }

        return ['schema' => self::SCHEMA, 'by_path' => $out];
    }
}
