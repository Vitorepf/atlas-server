<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Brain;

/**
 * CRITIC INDEPENDENCE SCORE — adversarial-critique organ. Given a list of findings each with the
 * same set of critics voting bool, computes per-pair agreement_rate = matches / shared_findings.
 * Pairs with rate >= HIGH_THRESHOLD are flagged as "non_independent" — they always vote together
 * so they don't add information to the consensus gate.
 *
 * Pure aggregation, no IO. Pétreo: réu would lower the threshold so its preferred critic-pair
 * never tripped.
 */
final class AtlasBrainCriticIndependenceScore
{
    public const SCHEMA = 'atlas.brain.critic_independence_score.v1';

    public const DEFAULT_HIGH_THRESHOLD = 0.90;

    private const MIN_SHARED = 5;

    /**
     * @param  list<array<string, bool>>  $findingVotes  each entry: {critic => vote}
     * @return array{schema:string, pairs:list<array{critic_a:string, critic_b:string, shared:int, agreement_rate:float, label:string}>}
     */
    public function score(array $findingVotes, float $highThreshold = self::DEFAULT_HIGH_THRESHOLD): array
    {
        $threshold = max(0.50, min(1.0, $highThreshold));
        $critics = [];
        foreach ($findingVotes as $vote) {
            foreach (array_keys($vote) as $c) {
                $critics[(string) $c] = true;
            }
        }
        $critics = array_keys($critics);
        sort($critics);

        $pairs = [];
        for ($i = 0; $i < count($critics); $i++) {
            for ($j = $i + 1; $j < count($critics); $j++) {
                $a = $critics[$i];
                $b = $critics[$j];
                $shared = 0;
                $agree = 0;
                foreach ($findingVotes as $v) {
                    if (! array_key_exists($a, $v) || ! array_key_exists($b, $v)) {
                        continue;
                    }
                    $shared++;
                    if ((bool) $v[$a] === (bool) $v[$b]) {
                        $agree++;
                    }
                }
                if ($shared < self::MIN_SHARED) {
                    continue;
                }
                $rate = round($agree / $shared, 4);
                $label = $rate >= $threshold ? 'non_independent' : 'ok';
                $pairs[] = ['critic_a' => $a, 'critic_b' => $b, 'shared' => $shared, 'agreement_rate' => $rate, 'label' => $label];
            }
        }

        return ['schema' => self::SCHEMA, 'pairs' => $pairs];
    }
}
