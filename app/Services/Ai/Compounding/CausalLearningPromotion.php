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
        /** @var array<string,mixed> */
        public array $ownerBinding = [],
        public string $owner = '',
        /** @var array<string,mixed> */
        public array $beforeState = [],
        /** @var array<string,mixed> */
        public array $afterState = [],
        public string $effectReceiptHash = '',
    ) {}
}
