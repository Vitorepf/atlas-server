<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Brain;

/**
 * RUBBER-STAMP DETECTOR — adversarial-critique organ. Given a history of votes across many
 * findings tagged by critic, detects critics whose votes are >=THRESHOLD pct in a single
 * direction (always-support or always-refute). Such a critic is providing no information; its
 * presence inflates the consensus gate's apparent independence.
 *
 * Pure aggregation. No IO. Pétreo: réu would raise the threshold so its rubber-stamp critic
 * never tripped.
 */
final class AtlasBrainRubberStampDetector
{
    public const SCHEMA = 'atlas.brain.rubber_stamp_detector.v1';

    private const MIN_VOTES = 5;

    private const DEFAULT_BIAS_THRESHOLD = 0.90;

    /**
     * @param  list<array{critic:string, vote:bool}>  $votes
     * @return array{schema:string, threshold:float, suspect_critics:list<array{critic:string, votes:int, support_rate:float, direction:string}>}
     */
    public function detect(array $votes, float $threshold = self::DEFAULT_BIAS_THRESHOLD): array
    {
        $threshold = max(0.50, min(1.0, $threshold));
        $byCritic = [];
        foreach ($votes as $v) {
            $critic = (string) ($v['critic'] ?? '');
            if ($critic === '') {
                continue;
            }
            if (! isset($byCritic[$critic])) {
                $byCritic[$critic] = ['supports' => 0, 'total' => 0];
            }
            $byCritic[$critic]['total']++;
            if ((bool) ($v['vote'] ?? false)) {
                $byCritic[$critic]['supports']++;
            }
        }

        $suspects = [];
        foreach ($byCritic as $critic => $s) {
            if ($s['total'] < self::MIN_VOTES) {
                continue;
            }
            $rate = $s['supports'] / $s['total'];
            if ($rate >= $threshold) {
                $suspects[] = ['critic' => (string) $critic, 'votes' => $s['total'], 'support_rate' => round($rate, 4), 'direction' => 'rubber_stamp_support'];
            } elseif ($rate <= (1.0 - $threshold)) {
                $suspects[] = ['critic' => (string) $critic, 'votes' => $s['total'], 'support_rate' => round($rate, 4), 'direction' => 'rubber_stamp_refute'];
            }
        }

        return ['schema' => self::SCHEMA, 'threshold' => $threshold, 'suspect_critics' => $suspects];
    }
}
