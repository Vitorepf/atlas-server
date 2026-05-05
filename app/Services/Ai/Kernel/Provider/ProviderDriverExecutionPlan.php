<?php

namespace App\Services\Ai\Kernel\Provider;

final readonly class ProviderDriverExecutionPlan
{
    /**
     * @param  array<string,mixed>  $executionPolicy
     * @param  array{ok:bool,errors:array<int,string>,warnings:array<int,string>}  $validation
     */
    public function __construct(
        public string $providerId,
        public string $legacyProvider,
        public ?string $requestHash,
        public bool $dryRun,
        public string $dryRunSource,
        public array $executionPolicy,
        public array $validation,
    ) {}

    /**
     * @param  array<string,mixed>  $request
     * @param  array<string,mixed>  $context
     * @param  array{ok:bool,errors:array<int,string>,warnings:array<int,string>}  $validation
     */
    public static function fromPreparedRequest(string $providerId, string $legacyProvider, array $request, array $context, array $validation): self
    {
        $executionPolicy = is_array($request['execution_policy'] ?? null) ? $request['execution_policy'] : [];
        $contextDryRun = $context['dry_run'] ?? null;
        $policyDryRun = $executionPolicy['dry_run'] ?? null;
        $dryRun = $policyDryRun === true || $contextDryRun === true;

        return new self(
            providerId: $providerId,
            legacyProvider: $legacyProvider,
            requestHash: is_string(data_get($request, 'audit.request_hash')) ? data_get($request, 'audit.request_hash') : null,
            dryRun: $dryRun,
            dryRunSource: self::dryRunSource($policyDryRun, $contextDryRun),
            executionPolicy: $executionPolicy,
            validation: $validation,
        );
    }

    public function canReachLegacyBoundary(): bool
    {
        return (bool) ($this->validation['ok'] ?? false) && ! $this->dryRun;
    }

    public function audit(?string $identityFragmentHash, bool $providerRealExecutionCalled = false): ProviderExecutionAudit
    {
        return new ProviderExecutionAudit(
            providerId: $this->providerId,
            requestHash: $this->requestHash,
            identityFragmentHash: $identityFragmentHash,
            dryRun: $this->dryRun,
            providerRealExecutionCalled: $providerRealExecutionCalled,
            validationErrors: $this->validation['errors'] ?? [],
            validationWarnings: $this->validation['warnings'] ?? [],
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'provider_id' => $this->providerId,
            'legacy_provider' => $this->legacyProvider,
            'request_hash' => $this->requestHash,
            'dry_run' => $this->dryRun,
            'dry_run_source' => $this->dryRunSource,
            'execution_policy' => $this->executionPolicy,
            'validation' => $this->validation,
            'can_reach_legacy_boundary' => $this->canReachLegacyBoundary(),
        ];
    }

    private static function dryRunSource(mixed $policyDryRun, mixed $contextDryRun): string
    {
        if ($policyDryRun === true && $contextDryRun === true) {
            return 'prepared_request_and_context';
        }

        if ($policyDryRun === true) {
            return 'prepared_request';
        }

        if ($contextDryRun === true) {
            return 'context';
        }

        return 'none';
    }
}
