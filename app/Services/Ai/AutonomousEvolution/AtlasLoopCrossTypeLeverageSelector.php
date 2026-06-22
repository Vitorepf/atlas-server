<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopComprehensionOriginationCandidates;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopScopeComprehensionModel;

/**
 * §3 · CROSS-TYPE LEVERAGE — "o cérebro escolhe o MAIOR passo, por valor real" ACROSS every comprehension
 * work type, not just within one lane.
 *
 * The per-lane {@see AtlasLoopLeverageSelector} ranks dedup specs against dedup specs, orphan against orphan.
 * But the brain's real question is which SINGLE evolution — wire THIS orphan, or unify THAT clone, or
 * originate THAT doc-gap capability — is the highest leverage RIGHT NOW. This composes the unranked grounded
 * candidate set ({@see AtlasLoopComprehensionOriginationCandidates::forModel} — orphan-wiring / clone-
 * unification / doc-gap) and runs the fabrication-proof leverage selector over the WHOLE set, so a capped
 * cycle does the biggest step across types.
 *
 * Anti-Goodhart preserved: NO scalar is computed here (that would be the cyclomatic proxy reborn) — the model
 * picks among the REAL grounded candidates and the selector validates the pick is in-range (it can only
 * REORDER, never fabricate or cross-type-launder). Fail-closed (no provider / off ⇒ the producer's
 * deterministic order). Pure composition; the candidates producer stays pure (no provider call leaks into it).
 */
final class AtlasLoopCrossTypeLeverageSelector
{
    public function __construct(
        private readonly ?AtlasLoopLeverageSelector $selector = null,
        private readonly ?AtlasLoopComprehensionOriginationCandidates $candidates = null,
    ) {
    }

    /**
     * The grounded comprehension candidates ACROSS all types, ranked highest-leverage first (when the
     * leverage flag is armed + a provider answers), else the producer's deterministic order.
     *
     * @return list<array<string,mixed>>
     */
    public function rankedForModel(AtlasLoopScopeComprehensionModel $model): array
    {
        $candidates = ($this->candidates ?? new AtlasLoopComprehensionOriginationCandidates)->forModel($model);
        if (count($candidates) < 2 || ! (bool) config('atlas.loop.leverage_selection_enabled', false)) {
            return $candidates; // 0/1 candidate, or off ⇒ nothing to rank (deterministic order)
        }

        return ($this->selector ?? new AtlasLoopLeverageSelector)->rank($candidates);
    }
}
