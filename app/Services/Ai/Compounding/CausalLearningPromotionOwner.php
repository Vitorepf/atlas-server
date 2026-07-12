<?php

declare(strict_types=1);

namespace App\Services\Ai\Compounding;

interface CausalLearningPromotionOwner
{
    /** @return array{applied:bool,owner:string,before_state:array<string,mixed>,after_state:array<string,mixed>,effect_receipt_hash:string,reason?:string} */
    public function apply(CausalLearningCandidate $candidate, string $nextVersion): array;

    /** @return array{rolled_back:bool,owner:string,before_state:array<string,mixed>,after_state:array<string,mixed>,effect_receipt_hash:string,reason?:string} */
    public function rollback(CausalLearningPromotion $promotion): array;
}
