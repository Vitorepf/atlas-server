<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

/**
 * ACDE U7 — deterministic CLARIFICATION ROUTER. Maps an abstention (reason + objective family + how many
 * times it has recurred) to a routing decision the operator's clarification inbox consumes: a SURFACE
 * (operator_inbox by default, operator_urgent when it is blocking/recurring) and a PRIORITY (low/normal/
 * high) from a transparent score. Pure — no I/O, no model self-report — so the route is reproducible and
 * unit-provable.
 *
 * The score is deterministic and bounded:
 *   - reason severity:    plan_not_ready=3 (an ill-formed DAG blocks delivery) > spec_not_ready=2 > vague=1
 *   - family weight:      feature=+1 (a blocked feature is delivery-blocking) > refactor/other=+0
 *   - recurrence:         + min(times_seen-1, 3) — a clarification the loop keeps re-hitting climbs
 * priority: score>=5 => high, >=3 => normal, else low. surface: high => operator_urgent, else operator_inbox.
 */
final class AtlasLoopClarificationRouter
{
    private const REASON_SEVERITY = [
        'plan_not_ready' => 3,
        'spec_not_ready' => 2,
        'vague_goal_no_anchor' => 1,
    ];

    /**
     * @return array{surface:string, priority:string, score:int}
     */
    public function route(string $reason, string $family, int $timesSeen): array
    {
        $score = self::REASON_SEVERITY[trim($reason)] ?? 1;
        if (trim(mb_strtolower($family)) === 'feature') {
            $score += 1;
        }
        $score += max(0, min($timesSeen - 1, 3));

        $priority = $score >= 5 ? 'high' : ($score >= 3 ? 'normal' : 'low');
        $surface = $priority === 'high' ? 'operator_urgent' : 'operator_inbox';

        return ['surface' => $surface, 'priority' => $priority, 'score' => $score];
    }
}
