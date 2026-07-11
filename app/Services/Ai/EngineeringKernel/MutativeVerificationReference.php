<?php

declare(strict_types=1);

namespace App\Services\Ai\EngineeringKernel;

final readonly class MutativeVerificationReference
{
    public function __construct(
        public string $runId,
        public string $receiptHash,
        public string $candidateHash,
        public string $providerIdentity,
        public string $authorIdentity,
        public string $verifierIdentity,
    ) {}
}
