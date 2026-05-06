<?php

namespace App\Services\Ai\Kernel\Evidence;

enum LedgerEventType: string
{
    case EnvelopeCreated = 'ENVELOPE_CREATED';
    case InputNormalized = 'INPUT_NORMALIZED';
    case IntentClassified = 'INTENT_CLASSIFIED';
    case DomainResolved = 'DOMAIN_RESOLVED';
    case FlowResolved = 'FLOW_RESOLVED';
    case ProfileResolved = 'PROFILE_RESOLVED';
    case PolicyCompiled = 'POLICY_COMPILED';
    case ContextComposed = 'CONTEXT_COMPOSED';
    case ContextInjected = 'CONTEXT_INJECTED';
    case DecisionDrafted = 'DECISION_DRAFTED';
    case DecisionIssued = 'DECISION_ISSUED';
    case DecisionConsumed = 'DECISION_CONSUMED';
    case DecisionExpired = 'DECISION_EXPIRED';
    case KernelPipelineAccepted = 'KERNEL_PIPELINE_ACCEPTED';
    case KernelPipelineRejected = 'KERNEL_PIPELINE_REJECTED';
    case OrchestratorSelected = 'ORCHESTRATOR_SELECTED';
    case ExecutionStarted = 'EXECUTION_STARTED';
    case ProviderCalled = 'PROVIDER_CALLED';
    case ProviderReturned = 'PROVIDER_RETURNED';
    case ProviderFallback = 'PROVIDER_FALLBACK';
    case ToolPlanned = 'TOOL_PLANNED';
    case ToolApproved = 'TOOL_APPROVED';
    case ToolInvoked = 'TOOL_INVOKED';
    case ToolReturned = 'TOOL_RETURNED';
    case ToolNormalized = 'TOOL_NORMALIZED';
    case ToolEvidenceRecorded = 'TOOL_EVIDENCE_RECORDED';
    case GateEvaluated = 'GATE_EVALUATED';
    case GatePassed = 'GATE_PASSED';
    case GateBlocked = 'GATE_BLOCKED';
    case RepairInitiated = 'REPAIR_INITIATED';
    case RepairCompleted = 'REPAIR_COMPLETED';
    case EscalationRequested = 'ESCALATION_REQUESTED';
    case EvidencePacked = 'EVIDENCE_PACKED';
    case LearningProposed = 'LEARNING_PROPOSED';
    case InboxActionRecorded = 'INBOX_ACTION_RECORDED';
    case SelfImprovementScheduleObserved = 'SELF_IMPROVEMENT_SCHEDULE_OBSERVED';
    case MemoryDeltaAccepted = 'MEMORY_DELTA_ACCEPTED';
    case SloObserved = 'SLO_OBSERVED';
    case OperationCompleted = 'OPERATION_COMPLETED';
    case OperationFailed = 'OPERATION_FAILED';
    case OperationBlocked = 'OPERATION_BLOCKED';
    case OperationNeedsReview = 'OPERATION_NEEDS_REVIEW';
}
