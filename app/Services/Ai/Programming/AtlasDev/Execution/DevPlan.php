<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Execution;

use App\Services\Ai\EngineeringKernel\CanonicalKernelPayload;
use App\Services\Ai\Programming\AtlasDev\Pipeline\PlanOnlyResult;

final readonly class DevPlan
{
    private function __construct(
        public DevIntent $intent,
        public PlanOnlyResult $result,
        public string $planHash,
    ) {}

    public static function fromResult(DevIntent $intent, PlanOnlyResult $result): self
    {
        return new self($intent, $result, CanonicalKernelPayload::hash([
            'intent_hash' => $intent->intentHash, 'summary' => $result->toSummaryArray(),
        ]));
    }

    public function isBlocked(): bool
    {
        return $this->result->isBlocked();
    }

    public function requiresForgeHandoff(): bool
    {
        return $this->result->isForgePreview();
    }

    public function isBoundTo(DevIntent $intent): bool
    {
        return hash_equals($this->intent->intentHash, $intent->intentHash);
    }
}
