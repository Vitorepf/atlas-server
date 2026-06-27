<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Brain;

/**
 * TOP CHURN HINT DETECTOR — finds the action_hint with the highest RAW refused count over recent
 * cycles (joined from reflection stream + done-set via L75 analyzer). Distinct from L75/L77 low-yield:
 * that's ratio-based ("80% refused of 5 tries"); this is absolute pain count ("18 refused total").
 *
 * Pure + deterministic + read-only. Pétreo.
 */
final class AtlasBrainTopChurnHintDetector
{
    public const SCHEMA = 'atlas.brain.top_churn_hint_detector.v1';

    /**
     * @param  array{by_hint?:list<array{hint:string, refused?:int, served?:int, total?:int}>}  $analyzerReport  L75 cascade analyzer output
     * @return array{schema:string, top_hint:?string, refused_count:int, total:int}
     */
    public function detect(array $analyzerReport): array
    {
        $rows = (array) ($analyzerReport['by_hint'] ?? []);
        $best = null;
        $bestRefused = 0;
        $bestTotal = 0;
        foreach ($rows as $r) {
            $ref = (int) ($r['refused'] ?? 0);
            if ($ref > $bestRefused) {
                $bestRefused = $ref;
                $best = (string) ($r['hint'] ?? '');
                $bestTotal = (int) ($r['total'] ?? 0);
            }
        }

        return [
            'schema' => self::SCHEMA,
            'top_hint' => $best,
            'refused_count' => $bestRefused,
            'total' => $bestTotal,
        ];
    }
}
