<?php

namespace App\Services\Ai\Kernel\Failure;

enum FailureDomain: string
{
    case InputMalformed = 'input.malformed';
    case InputAttachmentUnavailable = 'input.attachment_unavailable';
    case IntentAmbiguous = 'intent.ambiguous';
    case DomainUnsupported = 'domain.unsupported';
    case ProfileMissing = 'profile.missing';
    case PolicyDenied = 'policy.denied';
    case BudgetExceeded = 'budget.exceeded';
    case DecisionExpired = 'decision.expired';
    case DecisionInvalid = 'decision.invalid';
    case ProviderUnavailable = 'provider.unavailable';
    case ProviderRefused = 'provider.refused';
    case ProviderTimeout = 'provider.timeout';
    case ContextPackFailed = 'context.pack_failed';
    case MemoryUnavailable = 'memory.unavailable';
    case ToolUnavailable = 'tool.unavailable';
    case ToolPolicyDenied = 'tool.policy_denied';
    case ToolExecutionFailed = 'tool.execution_failed';
    case RuntimeUnsupported = 'runtime.unsupported';
    case RuntimeFailed = 'runtime.failed';
    case HarnessFailed = 'harness.failed';
    case GateFailed = 'gate.failed';
    case EvidenceMissing = 'evidence.missing';
    case RepairExhausted = 'repair.exhausted';
    case OutputInvalid = 'output.invalid';
    case PrivacyViolation = 'privacy.violation';
    case SecurityFinding = 'security.finding';
    case ComplianceViolation = 'compliance.violation';
    case LedgerUnavailable = 'ledger.unavailable';
    case ReplayMismatch = 'replay.mismatch';
    case SurfaceContractViolation = 'surface.contract_violation';
    case Unknown = 'unknown';
}
