<?php

namespace App\Services\Ai\Kernel\Failure;

final readonly class GenericFailureHandler implements FailureHandler
{
    public function __construct(
        private FailureDomain $failureDomain = FailureDomain::Unknown,
    ) {}

    public function domain(): FailureDomain
    {
        return $this->failureDomain;
    }

    public function forDomain(FailureDomain $domain): self
    {
        return new self($domain);
    }

    public function handle(array $failure, array $context = []): array
    {
        return [
            'schema_version' => 1,
            'failure_domain' => $this->failureDomain->value,
            'status' => 'classified',
            'retryable' => $this->retryable($this->failureDomain),
            'requires_human_review' => $this->requiresHumanReview($this->failureDomain),
            'failure' => $failure,
            'context' => $context,
        ];
    }

    private function retryable(FailureDomain $domain): bool
    {
        return in_array($domain, [
            FailureDomain::ProviderUnavailable,
            FailureDomain::ProviderTimeout,
            FailureDomain::ContextPackFailed,
            FailureDomain::MemoryUnavailable,
            FailureDomain::ToolExecutionFailed,
            FailureDomain::RuntimeFailed,
            FailureDomain::HarnessFailed,
            FailureDomain::GateFailed,
            FailureDomain::LedgerUnavailable,
        ], true);
    }

    private function requiresHumanReview(FailureDomain $domain): bool
    {
        return in_array($domain, [
            FailureDomain::PolicyDenied,
            FailureDomain::BudgetExceeded,
            FailureDomain::PrivacyViolation,
            FailureDomain::SecurityFinding,
            FailureDomain::ComplianceViolation,
            FailureDomain::ReplayMismatch,
            FailureDomain::SurfaceContractViolation,
        ], true);
    }
}
