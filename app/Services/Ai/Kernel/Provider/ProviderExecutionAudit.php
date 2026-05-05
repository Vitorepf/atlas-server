<?php

namespace App\Services\Ai\Kernel\Provider;

final readonly class ProviderExecutionAudit
{
    /**
     * @param  array<int,string>  $validationErrors
     * @param  array<int,string>  $validationWarnings
     */
    public function __construct(
        public string $providerId,
        public ?string $requestHash,
        public ?string $identityFragmentHash,
        public bool $dryRun,
        public bool $providerRealExecutionCalled,
        public array $validationErrors = [],
        public array $validationWarnings = [],
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'provider_id' => $this->providerId,
            'request_hash' => $this->requestHash,
            'identity_fragment_hash' => $this->identityFragmentHash,
            'dry_run' => $this->dryRun,
            'provider_real_execution_called' => $this->providerRealExecutionCalled,
            'validation_errors' => $this->validationErrors,
            'validation_warnings' => $this->validationWarnings,
        ];
    }
}
