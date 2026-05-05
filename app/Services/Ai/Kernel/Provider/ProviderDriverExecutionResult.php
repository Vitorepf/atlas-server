<?php

namespace App\Services\Ai\Kernel\Provider;

final readonly class ProviderDriverExecutionResult
{
    /**
     * @param  array<int,string>  $errors
     * @param  array<int,string>  $warnings
     * @param  array<string,mixed>  $metadata
     */
    public function __construct(
        public string $providerId,
        public ProviderDriverExecutionStatus $status,
        public bool $executed,
        public bool $providerRealExecutionCalled,
        public string $legacyProvider,
        public ?string $requestHash,
        public ProviderExecutionAudit $audit,
        public array $errors = [],
        public array $warnings = [],
        public array $metadata = [],
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'schema_version' => 1,
            'provider_id' => $this->providerId,
            'status' => $this->status->value,
            'executed' => $this->executed,
            'provider_real_execution_called' => $this->providerRealExecutionCalled,
            'legacy_provider' => $this->legacyProvider,
            'request_hash' => $this->requestHash,
            'audit' => $this->audit->toArray(),
            'errors' => $this->errors,
            'warnings' => $this->warnings,
            'metadata' => $this->metadata,
            'completed_at' => now()->toJSON(),
        ];
    }
}
