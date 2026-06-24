<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Council;

/**
 * The "thing" a Cortex Council lens observes — a deliberately generic value object so a single lens contract
 * can be applied to anything from a candidate origination to a proposed merge to a code path under review.
 * Carries the subject's id and a payload of facts the lens may interpret through its specific perspective.
 * No semantics are encoded here; meaning lives in the lens.
 */
final class CortexSubject
{
    /**
     * @param  array<string,mixed>  $facts  the raw facts the lens will interpret — provider-safe, no scores
     */
    public function __construct(
        public readonly string $id,
        public readonly string $kind,
        public readonly array $facts = [],
    ) {
    }
}
