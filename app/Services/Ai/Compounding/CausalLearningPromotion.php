<?php

declare(strict_types=1);

namespace App\Services\Ai\Compounding;

final readonly class CausalLearningPromotion
{
    public function __construct(
        public string $status,
        public string $candidateHash,
        public string $decisionHash,
        public string $scope,
        public string $activeVersion,
        public string $previousVersion,
        public string $rollbackVersion,
        public string $expiresAt,
        public array $observationSchedule,
        public string $reason,
        public bool $claimEligible = false,
    ) {}
}
