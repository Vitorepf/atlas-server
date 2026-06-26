<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery\Supply;

use App\Models\AtlasLoopTask;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopCoverageDeficitSource;

/**
 * Read-only task-classification predicates for the Atlas loop queue refiller.
 *
 * Extracted from AtlasLoopQueueRefiller to reduce the god-class.
 * Pure static methods — no instance state.
 */
final class AtlasLoopRefillerTaskClassifier
{
    /**
     * Whether a queued AtlasLoopTask is a behaviour-preserving PROXY refactor
     * (non-material). Non-refactor kinds (coverage / bug / feature) are never
     * proxy here — only the behaviour-preserving refactor is.
     */
    public static function isProxyRefactorTask(AtlasLoopTask $task): bool
    {
        $p = is_array($task->payload) ? $task->payload : [];
        $kind = mb_strtolower(trim((string) ($p['objective_kind'] ?? '')));
        if (! str_starts_with($kind, 'refactor')) {
            return false;
        }

        $isTrue = static fn ($v): bool => $v === true || $v === 1
            || (is_string($v) && in_array(mb_strtolower(trim($v)), ['1', 'true', 'yes'], true));

        // EXACTLY the honest scorecard's real-work rule for a refactor: behaviour proof (revert_recheck /
        // red_required) OR the GOVERNED self-improvement triple (self-marked + complexity_proof + quality_bar).
        // A standalone complexity_proof is deliberately NOT material — the scorecard classifies such a
        // refactor as proxy, so the supply gate must drop it too (otherwise gate and ruler disagree and a
        // proxy still reaches the queue, which the live AFTER run exposed).
        $material = $isTrue($p['revert_recheck'] ?? null)
            || $isTrue(data_get($p, 'acceptance.revert_recheck'))
            || $isTrue(data_get($p, 'acceptance.red_required'))
            // §5.6 DEDUP — a clone-unification is behaviour-preserving (revert_recheck=false) but MATERIAL: its
            // value is duplication-removed, proven by the frozen judge's Guard 4d count-drop. The dedup_proof
            // pair (payload + frozen acceptance, which the provider cannot author) is the honest material mark.
            || ($isTrue($p['dedup_proof'] ?? null) && $isTrue(data_get($p, 'acceptance.dedup_proof')))
            || (
                $isTrue($p['is_self_improvement'] ?? null)
                && ($isTrue($p['complexity_proof'] ?? null) || $isTrue(data_get($p, 'acceptance.complexity_proof')))
                && ($isTrue($p['quality_bar_gate'] ?? null) || $isTrue(data_get($p, 'acceptance.quality_bar_gate')))
            );

        return ! $material;
    }

    /**
     * D2 — is this a characterization/coverage task (counted against the
     * coverage cap, not substantive)?
     */
    public static function taskIsCoverage(AtlasLoopTask $task): bool
    {
        $p = is_array($task->payload) ? $task->payload : [];
        $kind = mb_strtolower(trim((string) ($p['objective_kind'] ?? '')));

        return $kind === AtlasLoopCoverageDeficitSource::SHAPE || str_contains($kind, 'characterization');
    }
}
