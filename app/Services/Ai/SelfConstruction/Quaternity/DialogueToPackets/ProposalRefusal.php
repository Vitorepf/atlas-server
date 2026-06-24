<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Quaternity\DialogueToPackets;

/**
 * The proposer's refusal value. Emitted (in place of a {@see ProposedPacketShape}) when the inputs do NOT
 * satisfy the proposer's preconditions — never auto-rewrites, never softens, never enqueues. The reason code
 * is a stable slug a downstream caller (the dialogue surface) can branch on; the detail is human-facing.
 */
final class ProposalRefusal
{
    /** Intent FACTS contained zero scope tags overlapping a Cortex symbol. */
    public const CODE_NO_SCOPE_GROUNDING = 'NO_SCOPE_GROUNDING';

    /** Intent is multi-scope (mixes Loop+Maestro+Cortex). */
    public const CODE_MULTI_SCOPE_AMBIGUOUS = 'MULTI_SCOPE_AMBIGUOUS';

    /** Intent fact bundle is empty (no phrases). */
    public const CODE_EMPTY_INTENT = 'EMPTY_INTENT';

    public function __construct(
        public readonly string $code,
        public readonly string $detail,
    ) {
    }
}
