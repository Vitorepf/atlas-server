<?php

namespace App\Services\Ai\Kernel\Repair;

enum RepairReason: string
{
    case RepairPolicyDisabled = 'repair_policy_disabled';
    case MaxAttemptsReached = 'max_attempts_reached';
    case FailureDomainRequiresHumanReview = 'failure_domain_requires_human_review';
    case NoAutomaticStrategyForFailureDomain = 'no_automatic_strategy_for_failure_domain';
    case StrategyNotAllowed = 'strategy_not_allowed';
    case HeavyRepairRequiresEvidenceRefs = 'heavy_repair_requires_evidence_refs';
    case RepairPlanned = 'repair_planned';
    case RepairNotAllowed = 'repair_not_allowed';
    case ExecutionBlockedByDryRun = 'execution_blocked_by_dry_run';
    case ExecutionNotImplementedContractFoundationOnly = 'execution_not_implemented_contract_foundation_only';
    case EnvelopeIdRequired = 'envelope_id_required';
    case CurrentAttemptMustBeNonNegative = 'current_attempt_must_be_non_negative';
    case AllowedStrategiesRequired = 'allowed_strategies_required';

    /**
     * @return array<int,string>
     */
    public static function values(): array
    {
        return array_map(
            fn (self $reason): string => $reason->value,
            self::cases(),
        );
    }
}
