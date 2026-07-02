<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery\Supply;

use App\Models\AtlasLoopCampaign;
use App\Models\AtlasLoopTarget;
use App\Services\Ai\AutonomousEvolution\Constitution\Frozen\AtlasLoopFrozenMutationOperators;
use App\Services\Ai\AutonomousEvolution\AtlasLoopMutationOperators;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopCoverageDeficitSource;
use Throwable;

/**
 * Coverage supply-lane POLICY, extracted from the refiller (supply-lane extraction
 * contract): the C/D2 portfolio cap decision and the frozen-operator pick. Pure
 * decisions over config + one availability query — enqueue/reflect/quarantine
 * effects stay with the refiller.
 */
final class AtlasLoopRefillerCoveragePortfolioGate
{
    public const ALLOW = 'allow';

    public const DEFER_SUBSTANTIVE_AVAILABLE = 'coverage_deferred_substantive_target_available';

    public const DEFER_CAP_REACHED = 'coverage_portfolio_cap_reached';

    /**
     * C/D2 — PORTFOLIO CAP (campaign-cumulative + per-refill inner bound). Characterization is
     * verification, not evolution, and must never dominate a campaign whose objective is loop
     * evolution. Coverage is DEFERRED while a genuine substantive (non-coverage-shaped) target
     * is still open to do instead — but when substantive targets are EXHAUSTED, a
     * characterization test is NOT padding: it PINS an untested file's behaviour (the mandatory
     * test-then-refactor STEP 1), so the loop keeps evolving instead of idling. Only a
     * CONCRETELY-shaped substantive target blocks coverage — a null/unclassified shape must NOT
     * count (an unmintable null-shape backlog would deadlock every coverage target forever; the
     * exact 26-coverage-starved-by-11-null-shape idle observed live). Fail-OPEN on any query
     * hiccup: doing real verification work beats idling. Portfolio gate OFF ⇒ no cap.
     */
    public function decide(AtlasLoopCampaign $campaign, AtlasLoopTarget $target, int $coverageMintedThisRefill): string
    {
        if (! (bool) config('atlas.loop.coverage_portfolio_gate_enabled', true)) {
            return self::ALLOW;
        }

        $perRefillCap = (int) config('atlas.loop.coverage_characterization_max_per_refill', 2);
        if ((bool) config('atlas.loop.coverage_relative_to_substantive', true)) {
            try {
                $substantiveTargetAvailable = AtlasLoopTarget::query()
                    ->where('campaign_id', $campaign->id)
                    ->whereIn('status', [AtlasLoopTarget::STATUS_CANDIDATE, AtlasLoopTarget::STATUS_QUEUED])
                    ->where('target_path', '!=', $target->target_path)
                    ->whereNotNull('signals->shape')
                    ->where('signals->shape', '!=', AtlasLoopCoverageDeficitSource::SHAPE)
                    ->exists();
            } catch (Throwable) {
                $substantiveTargetAvailable = false; // fail-open: allow coverage rather than idle
            }
            if ($substantiveTargetAvailable) {
                return self::DEFER_SUBSTANTIVE_AVAILABLE;
            }
        }

        if ($coverageMintedThisRefill >= $perRefillCap) {
            return self::DEFER_CAP_REACHED;
        }

        return self::ALLOW;
    }

    /** The first non-cosmetic frozen mutation operator found in the source body, or ''. */
    public function firstNonCosmeticFrozenOperator(string $source): string
    {
        $body = @file_get_contents($source);
        if (! is_string($body) || $body === '') {
            return '';
        }
        foreach (array_keys(AtlasLoopFrozenMutationOperators::neighborhood($body)) as $operator) {
            if (! AtlasLoopMutationOperators::isCosmetic($operator)) {
                return (string) $operator;
            }
        }

        return '';
    }
}
