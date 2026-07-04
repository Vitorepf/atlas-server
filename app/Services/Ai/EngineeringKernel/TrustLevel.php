<?php

declare(strict_types=1);

namespace App\Services\Ai\EngineeringKernel;

/**
 * Engineering Kernel value: the operator-involvement level a delivery was produced under.
 *
 * Owns: naming who witnesses admission of a bundle (the witness-set) — human, planning-sealed
 * reviewer, or clean-checkout FrozenJudge.
 * Must never own: the acceptance invariants or the honesty floor — those are IDENTICAL across all
 * three levels (bar(dev)=bar(forge)=bar(autonomos)); trust_level only swaps the witness, never the bar.
 */
enum TrustLevel: string
{
    case Dev = 'dev';
    case Forge = 'forge';
    case Autonomos = 'autonomos';

    /**
     * The witness-set descriptor — the ONLY thing that varies per trust level.
     * The engineering invariants + honesty floor are the same for all three.
     */
    public function witnessSet(): string
    {
        return match ($this) {
            self::Dev => 'human_witnesses_disambiguation_only',
            self::Forge => 'planning_seal_plus_reviewer_agent',
            self::Autonomos => 'frozen_judge_clean_checkout_reproof',
        };
    }
}
