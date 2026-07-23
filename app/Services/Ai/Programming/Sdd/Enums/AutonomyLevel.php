<?php

namespace App\Services\Ai\Programming\Sdd\Enums;


/**
 * Autonomy levels per autonomy-and-clarification-policy.md:102-111.
 *
 * The Decision Receipt MUST state the autonomy level. Runtime respects the
 * declared limit — no silent escalation.
 */
enum AutonomyLevel: string
{
    /** Manual: humano aprova spec e plan, escreve patch. */
    case L0Manual = 'L0_manual';

    /** Atlas propõe spec/plan; humano aprova implementação. */
    case L1Assisted = 'L1_assisted';

    /** Atlas aplica patch automatizado em escopo restrito. */
    case L2AutoPatch = 'L2_auto_patch';

    /** Atlas abre PR automaticamente; humano revisa antes de merge. */
    case L3AutoPr = 'L3_auto_pr';

    /** Atlas pode fazer merge bloqueado em conjunto restrito de paths/policies. */
    case L4RestrictedMerge = 'L4_restricted_merge';

    /** Atlas só pode propor learning/policy mudanças; nunca aplica. */
    case L5ProposalOnly = 'L5_proposal_only';

    public function allowsImplementation(): bool
    {
        return match ($this) {
            self::L0Manual, self::L5ProposalOnly => false,
            default => true,
        };
    }

    public function allowsAutoMerge(): bool
    {
        return $this === self::L4RestrictedMerge;
    }

    public function allowsPullRequest(): bool
    {
        return in_array($this, [self::L3AutoPr, self::L4RestrictedMerge], true);
    }
}
