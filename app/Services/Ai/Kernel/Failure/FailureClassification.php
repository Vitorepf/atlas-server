<?php

namespace App\Services\Ai\Kernel\Failure;

final readonly class FailureClassification
{
    /**
     * @param  array<int,string>  $signals
     * @param  array<string,mixed>  $metadata
     */
    public function __construct(
        public FailureDomain $domain,
        public string $source,
        public array $signals = [],
        public float $confidence = 1.0,
        public array $metadata = [],
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'schema_version' => 1,
            'failure_domain' => $this->domain->value,
            'failure_domain_name' => $this->domain->name,
            'source' => $this->source,
            'signals' => $this->signals,
            'confidence' => $this->confidence,
            'metadata' => $this->metadata,
        ];
    }

    public function isUnknown(): bool
    {
        return $this->domain === FailureDomain::Unknown;
    }

    /**
     * @return array<string,mixed>
     */
    public function toFailurePayload(): array
    {
        return [
            'failure_domain' => $this->domain->value,
            'source' => $this->source,
            'signals' => $this->signals,
            'confidence' => $this->confidence,
            'metadata' => $this->metadata,
        ];
    }
}
