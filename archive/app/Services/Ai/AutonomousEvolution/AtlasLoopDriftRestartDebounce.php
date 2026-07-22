<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

/**
 * LOOP-OS · FASE 5 · SLICE 13 — drift-restart re-enable, EXTERNALLY triggered + DEBOUNCED.
 *
 * Code-drift self-restart is what lets the loop pick up its own merged improvements without a human. But two
 * failure modes must be designed out: (1) a process must not edit its OWN respawn logic and die — so the
 * trigger is the EXTERNAL watchdog, never the supervisor itself; (2) a restart storm — so it is DEBOUNCED to
 * at most ONE restart per window AND only when real drift happened (≥N self-merges landed since the last
 * restart). This class is the pure debounce DECISION the watchdog consults; the checkpoint that lets an
 * in-flight projection survive the restart lives in atlas_loop_pipeline_state (Slice 8).
 */
final class AtlasLoopDriftRestartDebounce
{
    public const SCHEMA_VERSION = 'atlas.loop.drift_restart_debounce.v1';

    /**
     * Decide whether an externally-triggered drift restart is allowed RIGHT NOW.
     *
     * @return array{allowed:bool, reason:string, seconds_since_last:int, self_merges_since_last:int}
     */
    public function decide(
        int $secondsSinceLastRestart,
        int $selfMergesSinceLastRestart,
        int $windowSeconds = 600,
        int $minSelfMerges = 1,
    ): array {
        $secondsSinceLastRestart = max(0, $secondsSinceLastRestart);
        $selfMergesSinceLastRestart = max(0, $selfMergesSinceLastRestart);
        $windowSeconds = max(1, $windowSeconds);
        $minSelfMerges = max(1, $minSelfMerges);

        $base = ['seconds_since_last' => $secondsSinceLastRestart, 'self_merges_since_last' => $selfMergesSinceLastRestart];

        // DEBOUNCE: never more than one restart per window — a restart storm is worse than stale code.
        if ($secondsSinceLastRestart < $windowSeconds) {
            return array_merge($base, ['allowed' => false, 'reason' => 'debounced: '.$secondsSinceLastRestart.'s < '.$windowSeconds.'s window']);
        }

        // WARRANTED: only restart when real drift actually landed (the loop merged improvements to itself).
        // A restart with nothing merged just churns the process for no benefit.
        if ($selfMergesSinceLastRestart < $minSelfMerges) {
            return array_merge($base, ['allowed' => false, 'reason' => 'no_drift: '.$selfMergesSinceLastRestart.' self-merges < '.$minSelfMerges.' required']);
        }

        return array_merge($base, ['allowed' => true, 'reason' => 'window_elapsed_and_drift_landed']);
    }
}
