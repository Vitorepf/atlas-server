<?php

declare(strict_types=1);

namespace App\Services\Ai\Compounding;

final readonly class CausalLearningVerdict
{
    public function __construct(public string $verdict, public string $reason, public string $decisionHash, public bool $claimEligible = false) {}
}
