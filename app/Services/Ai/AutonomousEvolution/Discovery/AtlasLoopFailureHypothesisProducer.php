<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery;

/**
 * ROADMAP #6 — failure-driven supply: when a direction FAILS, instead of just dropping it, frame N
 * ALTERNATIVE directions for the same goal so the tree gets fresh competing siblings (the loop's #1
 * gargalo is work-supply; a failure is a signal, not just a dead end).
 *
 * DETERMINISTIC + provider-free: it operationalizes the idea_drafting rubric's four orthogonal moves
 * (assumption-inversion / backward-from-success / analogical-transfer / failure-reverse-engineering) into
 * generation FRAMES seeded by the failed objective + reason. The frames are not final hypotheses — they are
 * the distinct angles the generator (the single provider chokepoint) expands into real RED-verified tasks,
 * which then become competing sibling nodes via AtlasLoopHypothesisTreeProducer. ADVISORY: a frame never
 * gates anything; the unchanged out-of-process cert still proves every expansion.
 */
final class AtlasLoopFailureHypothesisProducer
{
    /**
     * N alternative-direction frames for a failed objective, one per orthogonal move (cycled if N>4). Pure +
     * deterministic: the same (objective, reason, n) always yields the same frames.
     *
     * @return list<string>
     */
    public static function alternativeFrames(string $failedObjective, string $reason, int $n = 4): array
    {
        $obj = trim(preg_replace('/\s+/', ' ', $failedObjective) ?? $failedObjective);
        $why = trim(preg_replace('/\s+/', ' ', $reason) ?? $reason);
        if ($obj === '') {
            return [];
        }
        $why = $why === '' ? 'the prior attempt did not earn the metric' : $why;
        $n = max(1, min(8, $n));

        $moves = [
            "Assumption-inversion: the failed direction for «{$obj}» relied on a hidden assumption; name it and design a mechanism that drops it.",
            "Backward-from-success: imagine «{$obj}» already solved — what pipeline stage / data structure would it have that the failed attempt lacked? Build that.",
            "Analogical-transfer: map the bottleneck behind «{$obj}» to a neighbouring solved problem (search / verification / caching / control) and borrow its mechanism.",
            "Failure-reverse-engineering: the failure was «{$why}» — what is the minimal new capability that would have prevented exactly that, and how does it attack the bottleneck class?",
        ];

        $frames = [];
        for ($i = 0; $i < $n; $i++) {
            $frames[] = $moves[$i % count($moves)];
        }

        return $frames;
    }
}
