<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery;

/**
 * THE ADVERSARIAL SELF-CRITIC — "is this the biggest leap, or just the easiest-looking?"
 *
 * Leverage = leap / cost. That ratio correctly rewards efficiency, but it can be won by a TINY leap
 * that happens to be very cheap — exactly the "small implementation" trap. This critic separates the
 * two: it compares the ratio-winner's NUMERATOR (impact × breadth × compounding = the actual leap
 * magnitude) against the biggest-numerator candidate among the floor-passers. If the ratio-winner is
 * a small-but-cheap leap while a genuinely bigger leap was passed over only for being costlier, it
 * OVERRIDES the pick toward the bigger leap. Pure + deterministic.
 *
 * The caller passes only candidates that already cleared the ambition floor (verifiable, real
 * unblock), so any promotion is to another legitimate, executable leap.
 */
final class AtlasLoopAdversarialCritic
{
    /**
     * @param  array<string,mixed>  $winner  the leverage(ratio)-winner, carrying `_score`
     * @param  list<array<string,mixed>>  $floorPassers  all candidates that cleared the ambition floor
     * @return array{pick:array<string,mixed>, challenged:bool, reason:string}
     */
    public function challenge(array $winner, array $floorPassers): array
    {
        $threshold = (float) config('atlas.loop.critic_numerator_threshold', 0.6);

        $biggest = null;
        $biggestNum = 0.0;
        foreach ($floorPassers as $c) {
            $n = $this->leapMagnitude($c);
            if ($n > $biggestNum) {
                $biggestNum = $n;
                $biggest = $c;
            }
        }

        $winnerNum = $this->leapMagnitude($winner);

        if ($biggest !== null && $biggestNum > 0.0 && $winnerNum < $biggestNum * $threshold
            && ($biggest['path'] ?? null) !== ($winner['path'] ?? null)) {
            return [
                'pick' => $biggest,
                'challenged' => true,
                'reason' => sprintf(
                    'ratio-winner %s had leap-magnitude %.3f (cheap, not big); promoted %s with magnitude %.3f',
                    (string) ($winner['path'] ?? '?'), $winnerNum,
                    (string) ($biggest['path'] ?? '?'), $biggestNum,
                ),
            ];
        }

        return [
            'pick' => $winner,
            'challenged' => false,
            'reason' => 'ratio-winner is also the biggest genuine leap (or within threshold)',
        ];
    }

    /** The actual leap magnitude (numerator of leverage): impact × breadth × compounding. */
    private function leapMagnitude(array $candidate): float
    {
        $c = $candidate['_score']['components'] ?? [];

        return (float) ($c['strategic_impact'] ?? 0.0)
            * (float) ($c['breadth'] ?? 0.0)
            * (float) ($c['compounding'] ?? 0.0);
    }
}
