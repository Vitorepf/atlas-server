<?php

namespace App\Services\Ai\Programming\Sdd\Enums;

/**
 * Confidence class for context discovery findings.
 *
 * Per context-discovery-and-business-context.md:131-138.
 *
 * - confirmed_fact: derived from canonical docs / code / tests
 * - strong_inference: high-confidence inference from related code
 * - hypothesis: plausible but unverified
 * - blocking_ambiguity: ambiguous AND blocks safe execution → must clarify
 */
enum ConfidenceClass: string
{
    case ConfirmedFact = 'confirmed_fact';
    case StrongInference = 'strong_inference';
    case Hypothesis = 'hypothesis';
    case BlockingAmbiguity = 'blocking_ambiguity';

    public function isBlocking(): bool
    {
        return $this === self::BlockingAmbiguity;
    }

    public function rank(): int
    {
        return match ($this) {
            self::ConfirmedFact => 4,
            self::StrongInference => 3,
            self::Hypothesis => 2,
            self::BlockingAmbiguity => 1,
        };
    }
}
