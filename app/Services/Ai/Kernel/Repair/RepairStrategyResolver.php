<?php

namespace App\Services\Ai\Kernel\Repair;

use App\Services\Ai\Kernel\Failure\FailureDomain;

final class RepairStrategyResolver
{
    public function strategyFor(FailureDomain $domain): string
    {
        return match ($domain) {
            FailureDomain::ProviderTimeout,
            FailureDomain::ProviderUnavailable => RepairStrategy::RetryProvider->value,

            FailureDomain::ContextPackFailed,
            FailureDomain::InputAttachmentUnavailable,
            FailureDomain::MemoryUnavailable,
            FailureDomain::ProfileMissing => RepairStrategy::RefreshContext->value,

            FailureDomain::EvidenceMissing,
            FailureDomain::GateFailed,
            FailureDomain::LedgerUnavailable => RepairStrategy::CollectEvidence->value,

            FailureDomain::OutputInvalid,
            FailureDomain::RuntimeFailed,
            FailureDomain::DecisionInvalid => RepairStrategy::RepairOutput->value,

            FailureDomain::ToolExecutionFailed,
            FailureDomain::ToolUnavailable => RepairStrategy::RerunTool->value,

            FailureDomain::HarnessFailed => RepairStrategy::RerunHarness->value,

            default => RepairStrategy::HumanReview->value,
        };
    }

    public function requiresHumanReview(FailureDomain $domain): bool
    {
        return in_array($domain, [
            FailureDomain::PolicyDenied,
            FailureDomain::ProviderRefused,
            FailureDomain::BudgetExceeded,
            FailureDomain::DecisionExpired,
            FailureDomain::ToolPolicyDenied,
            FailureDomain::RuntimeUnsupported,
            FailureDomain::RepairExhausted,
            FailureDomain::PrivacyViolation,
            FailureDomain::SecurityFinding,
            FailureDomain::ComplianceViolation,
            FailureDomain::ReplayMismatch,
            FailureDomain::SurfaceContractViolation,
            FailureDomain::Unknown,
        ], true);
    }

    /**
     * @return array{ok:bool,mapped_domains:int,strategies:array<int,string>,human_review_domains:array<int,string>,errors:array<int,string>}
     */
    public function complianceReport(): array
    {
        $errors = [];
        $humanReviewDomains = [];

        foreach (FailureDomain::cases() as $domain) {
            $strategy = $this->strategyFor($domain);

            if (! in_array($strategy, RepairStrategy::values(), true)) {
                $errors[] = "unknown repair strategy for failure domain [{$domain->value}]";
            }

            if ($this->requiresHumanReview($domain)) {
                $humanReviewDomains[] = $domain->value;
            }
        }

        return [
            'ok' => $errors === [],
            'mapped_domains' => count(FailureDomain::cases()),
            'strategies' => RepairStrategy::values(),
            'human_review_domains' => $humanReviewDomains,
            'errors' => $errors,
        ];
    }
}
