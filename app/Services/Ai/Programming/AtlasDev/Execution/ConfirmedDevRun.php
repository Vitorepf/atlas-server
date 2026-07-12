<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Execution;

use App\Services\Ai\EngineeringKernel\CanonicalKernelPayload;
use InvalidArgumentException;

final readonly class ConfirmedDevRun
{
    private function __construct(
        public DevIntent $intent,
        public string $operatorId,
        public string $authorityHash,
        public string $intentHash,
        public string $runHash,
    ) {}

    public static function fromIntent(DevIntent $intent, string $operatorId, string $authorityHash): self
    {
        if ($operatorId === '' || $operatorId !== $intent->operatorId || ! hash_equals($intent->authorityHash, $authorityHash)) {
            throw new InvalidArgumentException('dev_run_authority_mismatch');
        }
        $binding = ['intent_hash' => $intent->intentHash, 'operator_id' => $operatorId, 'authority_hash' => $authorityHash];

        return new self($intent, $operatorId, $authorityHash, $intent->intentHash, CanonicalKernelPayload::hash($binding));
    }
}
