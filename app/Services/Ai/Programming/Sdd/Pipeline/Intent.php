<?php

namespace App\Services\Ai\Programming\Sdd\Pipeline;

use App\Services\Ai\Programming\Sdd\Enums\ConfidenceClass;

/**
 * Output of the IntentRouter: classified, scored, and routable intent.
 */
final class Intent
{
    /**
     * @param  list<string>  $signals
     * @param  array<string,mixed>  $metadata
     */
    public function __construct(
        public readonly string $type,
        public readonly string $domain,
        public readonly string $riskLevel,
        public readonly ConfidenceClass $confidenceClass,
        public readonly bool $harnessRequired,
        public readonly array $signals = [],
        public readonly array $metadata = [],
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'domain' => $this->domain,
            'risk_level' => $this->riskLevel,
            'confidence_class' => $this->confidenceClass->value,
            'harness_required' => $this->harnessRequired,
            'signals' => $this->signals,
            'metadata' => $this->metadata,
        ];
    }
}
