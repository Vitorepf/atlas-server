<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Feedback;

use Throwable;

/**
 * GIVE-BACK → REPLENISHER FEEDBACK WIRE — the missing link that makes the mined give_back FACTs
 * (from {@see AtlasLoopGiveBackPatternMiner}) actually reach the AtlasTaskBrainReplenisher's round context,
 * so next-round packet structuring can learn from what cold workers HANDED BACK (and why) instead of
 * re-minting the same unsatisfiable shapes. Without this wire the FACTs are mined but dead.
 *
 * ADDITIVE + flag-gated: when atlas.loop.feedback.replenisher_enabled is false (default, fail-closed) augment()
 * returns the context BYTE-IDENTICAL; when true it adds exactly ONE key — give_back_facts — and never mutates
 * any existing key. Fail-open: a miner failure degrades to an empty fact set, never an exception.
 */
final class AtlasLoopGiveBackToReplenisherFeedback
{
    public function __construct(private readonly ?AtlasLoopGiveBackPatternMiner $miner = null) {}

    public function enabled(): bool
    {
        return (bool) config('atlas.loop.feedback.replenisher_enabled', false);
    }

    /**
     * Additively decorate the Replenisher's round context with the mined give_back FACTs (flag-gated).
     *
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    public function augment(array $context): array
    {
        if (! $this->enabled()) {
            return $context; // flag OFF ⇒ byte-identical no-op
        }
        if (array_key_exists('give_back_facts', $context)) {
            return $context; // never clobber an existing key — additive only
        }

        // `+` (union) preserves every existing key/shape; only the absent give_back_facts is added.
        return $context + ['give_back_facts' => $this->facts()];
    }

    /**
     * @return array<string,mixed>
     */
    private function facts(): array
    {
        try {
            return ($this->miner ?? new AtlasLoopGiveBackPatternMiner)->mine();
        } catch (Throwable) {
            return []; // fail-open: dead FACTs never break replenishment
        }
    }
}
