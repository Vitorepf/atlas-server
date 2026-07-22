<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Defect;

/**
 * DEFECT FALSIFICATION GATE (R8.3) — the universal pre-worker gate that makes "finding a bug" HONEST: a defect
 * candidate becomes a task ONLY if it is falsifiable-RED. A candidate is admitted ONLY when it carries a real
 * reproduction command that ACTUALLY FAILS on the current tree (red_observed) AND the proposed fix moves
 * behavior in the right direction (behavior_delta_after_fix > 0).
 *
 * FAIL-CLOSED: a candidate without a red_command is NEVER admitted — this is the floor that kills
 * "plausible-but-unreproduced" false positives before a single worker is spent on them. Pure + deterministic;
 * a FACTS-only verdict (no scalar score). Each failed condition is named explicitly.
 */
final class AtlasLoopDefectFalsificationGate
{
    public const SCHEMA = 'atlas.loop.defect_falsification.v1';

    /**
     * @param  array{hypothesis?:string, red_command?:string, red_observed?:bool, behavior_delta_after_fix?:int}  $candidate
     * @return array{schema:string, admitted:bool, blocking_reasons:list<string>}
     */
    public function admit(array $candidate): array
    {
        $reasons = [];

        if (trim((string) ($candidate['red_command'] ?? '')) === '') {
            $reasons[] = 'no_red_command'; // fail-closed: no reproduction ⇒ never a task
        }
        if (($candidate['red_observed'] ?? null) !== true) {
            $reasons[] = 'not_reproduced'; // the command must FAIL on the current tree
        }
        if ((int) ($candidate['behavior_delta_after_fix'] ?? 0) <= 0) {
            $reasons[] = 'no_positive_delta'; // the fix must move behavior in the right direction
        }

        return [
            'schema' => self::SCHEMA,
            'admitted' => $reasons === [],
            'blocking_reasons' => $reasons,
        ];
    }
}
