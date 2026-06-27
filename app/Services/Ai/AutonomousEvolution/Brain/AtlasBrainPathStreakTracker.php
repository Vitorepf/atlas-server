<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Brain;

/**
 * PATH STREAK TRACKER — compounding organ. Per path, walks the chronological reflection tail and
 * tracks the longest consecutive accepted-streak and refused-streak. Compounding lens because
 * runs of accepts compound momentum; runs of refusals compound stuckness. Distinct from momentum
 * (delta) and EWMA (level): streaks measure persistence of state.
 *
 * Pure scan over tail. No IO. Pétreo: réu would clamp its preferred path's refused streak so its
 * stuckness never showed.
 */
final class AtlasBrainPathStreakTracker
{
    public const SCHEMA = 'atlas.brain.path_streak_tracker.v1';

    /**
     * @param  list<array{action_hint?:string, result_kind?:string}>  $reflectionTail
     * @return array{schema:string, by_path:array<string, array{longest_accepted:int, longest_refused:int}>}
     */
    public function track(array $reflectionTail, AtlasBrainHintToPathTranslator $translator): array
    {
        $state = [];  // path => ['acc'=>longest, 'ref'=>longest, 'cur_acc'=>, 'cur_ref'=>]
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
            if (! isset($state[$path])) {
                $state[$path] = ['acc' => 0, 'ref' => 0, 'cur_acc' => 0, 'cur_ref' => 0];
            }
            if ($kind === 'accepted') {
                $state[$path]['cur_acc']++;
                $state[$path]['cur_ref'] = 0;
                $state[$path]['acc'] = max($state[$path]['acc'], $state[$path]['cur_acc']);
            } else {
                $state[$path]['cur_ref']++;
                $state[$path]['cur_acc'] = 0;
                $state[$path]['ref'] = max($state[$path]['ref'], $state[$path]['cur_ref']);
            }
        }

        $out = [];
        foreach ($state as $path => $s) {
            $out[$path] = ['longest_accepted' => $s['acc'], 'longest_refused' => $s['ref']];
        }

        return ['schema' => self::SCHEMA, 'by_path' => $out];
    }
}
