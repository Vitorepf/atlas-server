<?php

declare(strict_types=1);

namespace App\Services\Ai\SpecialistFlows;

use App\Services\Ai\RouterRuntime\RouterRuntimeCanon;

/**
 * Canonical enums + schemas for the Atlas AI Specialist Flows backend core.
 *
 * Each specialist flow exposes:
 *   - canonical `flow_id` (mirror of RouterRuntimeCanon::FLOW_*);
 *   - intent / domain hint;
 *   - policy_refs (operator-facing rules the runtime relies on);
 *   - evidence_refs (audit trail the runtime expects);
 *   - runtime_contract (output shape + forbidden actions);
 *   - receipt schema with deterministic hash;
 *   - explicit fallback so any unknown flow lands on a safe default rather
 *     than silently borrowing Atlas Dev / Forge behaviour.
 *
 * No SpecialistFlow handler is allowed to delegate to Atlas Dev / Forge
 * unless the canonical Programming Adapter approved the handoff. This canon
 * declares the boundary; handlers enforce it.
 */
final class SpecialistFlowsCanon
{
    public const RUNTIME_SCHEMA_VERSION = 'atlas.ai.specialist_flow_runtime.v1';

    public const RECEIPT_SCHEMA_VERSION = 'atlas.ai.specialist_flow_receipt.v1';

    public const STATUS_PLANNED = 'planned';

    public const STATUS_READY = 'ready';

    public const STATUS_BLOCKED = 'blocked';

    public const STATUS_REQUIRES_OPERATOR_APPROVAL = 'requires_operator_approval';

    public const SIDE_EFFECT_READ_ONLY = 'read_only_until_confirmed';

    public const SIDE_EFFECT_REQUIRES_APPROVAL = 'requires_operator_approval_before_side_effect';

    public const SIDE_EFFECT_FORBIDDEN = 'forbidden_in_this_flow';

    /**
     * Canonical policy refs — pointers to operator-facing rules per domain.
     * Keys mirror flow_id constants from RouterRuntimeCanon::FLOW_*.
     *
     * @var array<string,array<int,string>>
     */
    public const POLICY_REFS = [
        RouterRuntimeCanon::FLOW_FINANCE => [
            'docs/engineering-knowledge-base/domains/finance.md',
            'policy:no_real_trade_without_operator_approval',
            'policy:redact_account_identifiers_in_logs',
        ],
        RouterRuntimeCanon::FLOW_MARKETING => [
            'docs/engineering-knowledge-base/domains/marketing.md',
            'policy:no_publish_without_operator_approval',
            'policy:no_spend_above_budget_without_operator_approval',
        ],
        RouterRuntimeCanon::FLOW_STRATEGY => [
            'docs/engineering-knowledge-base/domains/strategy.md',
            'policy:document_assumptions_explicitly',
        ],
        RouterRuntimeCanon::FLOW_CYBER => [
            'docs/engineering-knowledge-base/cyber-security/atlas-cyber-security-domain-plane.md',
            'policy:no_offensive_action_without_roe',
            'policy:defensive_advisory_only_by_default',
        ],
        RouterRuntimeCanon::FLOW_PERSONAL_DEVELOPMENT => [
            'docs/engineering-knowledge-base/domains/personal-development.md',
            'policy:non_clinical_boundary_required',
            'policy:never_replace_professional_advice',
        ],
        RouterRuntimeCanon::FLOW_AUTOMATION => [
            'docs/engineering-knowledge-base/domains/automation.md',
            'policy:plan_before_execute',
            'policy:no_destructive_action_without_operator_approval',
        ],
        RouterRuntimeCanon::FLOW_RESEARCH => [
            'docs/engineering-knowledge-base/domains/programming-agentic-rag-professional-spec.md',
            'policy:source_grounded_claims_only',
            'policy:declare_uncertainty_explicitly',
        ],
        RouterRuntimeCanon::FLOW_CONVERSATION => [
            'policy:keep_context_light',
            'policy:no_workspace_assumption',
        ],
    ];

    /**
     * Forbidden actions per flow. Handlers MUST attach the full list to the
     * runtime contract; downstream gates use this surface to refuse work.
     *
     * @var array<string,array<int,string>>
     */
    public const FORBIDDEN_ACTIONS = [
        RouterRuntimeCanon::FLOW_FINANCE => [
            'execute_live_trade_without_operator_approval',
            'fabricate_market_data_or_returns',
            'promise_returns',
            'leak_account_identifiers',
        ],
        RouterRuntimeCanon::FLOW_MARKETING => [
            'publish_campaign_without_operator_approval',
            'spend_above_budget_without_operator_approval',
            'fabricate_metrics',
            'send_message_to_real_audience_without_operator_approval',
        ],
        RouterRuntimeCanon::FLOW_STRATEGY => [
            'present_assumption_as_fact',
            'execute_strategic_action_without_operator_approval',
        ],
        RouterRuntimeCanon::FLOW_CYBER => [
            'run_offensive_tooling_without_signed_roe',
            'scan_third_party_target_without_authorization',
            'exfiltrate_data',
            'recommend_destructive_action_without_operator_approval',
        ],
        RouterRuntimeCanon::FLOW_PERSONAL_DEVELOPMENT => [
            'cross_clinical_boundary',
            'diagnose_medical_or_psychiatric_condition',
            'replace_licensed_professional_advice',
        ],
        RouterRuntimeCanon::FLOW_AUTOMATION => [
            'execute_workflow_without_explicit_run_confirmation',
            'fabricate_external_api_response',
            'silently_widen_workflow_scope',
        ],
        RouterRuntimeCanon::FLOW_RESEARCH => [
            'treat_unsourced_claim_as_fact',
            'hide_uncertainty',
            'promote_memory_without_review',
        ],
        RouterRuntimeCanon::FLOW_CONVERSATION => [
            'pretend_workspace_access',
            'silently_change_flow',
        ],
    ];

    /**
     * Output contract per flow — the shape downstream consumers (Atlas AI
     * Desktop / providers / receipts) expect the flow to produce.
     *
     * @var array<string,array<int,string>>
     */
    public const OUTPUT_CONTRACTS = [
        RouterRuntimeCanon::FLOW_FINANCE => [
            'data_snapshot',
            'assumptions',
            'analysis_summary',
            'risk_register',
            'decision_options',
            'open_questions',
            'evidence_refs',
        ],
        RouterRuntimeCanon::FLOW_MARKETING => [
            'campaign_brief',
            'audience_assumptions',
            'channels',
            'metrics_plan',
            'approval_checkpoint',
            'evidence_refs',
        ],
        RouterRuntimeCanon::FLOW_STRATEGY => [
            'objective',
            'assumptions',
            'options_with_tradeoffs',
            'recommendation',
            'risks',
            'decision_memo',
        ],
        RouterRuntimeCanon::FLOW_CYBER => [
            'scope_and_roe_declaration',
            'threat_model',
            'defensive_findings',
            'remediation_steps',
            'evidence_refs',
            'forbidden_actions',
        ],
        RouterRuntimeCanon::FLOW_PERSONAL_DEVELOPMENT => [
            'context_summary',
            'non_clinical_observations',
            'goal_options',
            'next_actions',
            'professional_referral_when_relevant',
        ],
        RouterRuntimeCanon::FLOW_AUTOMATION => [
            'workflow_objective',
            'plan_steps',
            'side_effects',
            'approval_gates',
            'rollback_plan',
            'execution_command_or_reason_for_holding',
        ],
        RouterRuntimeCanon::FLOW_RESEARCH => [
            'answer_summary',
            'claims_table',
            'source_refs',
            'uncertainty',
            'open_questions',
        ],
        RouterRuntimeCanon::FLOW_CONVERSATION => [
            'direct_answer',
            'clarifying_question_when_needed',
            'handoff_suggestion_when_scope_changes',
        ],
    ];

    /**
     * Required evidence refs per flow — handlers MUST include router_decision
     * plus the flow-specific anchors below.
     *
     * @var array<string,array<int,string>>
     */
    public const REQUIRED_EVIDENCE = [
        RouterRuntimeCanon::FLOW_FINANCE => ['router_decision', 'data_snapshot_or_missing_data_statement', 'policy_refs'],
        RouterRuntimeCanon::FLOW_MARKETING => ['router_decision', 'campaign_brief_or_scope_statement', 'policy_refs'],
        RouterRuntimeCanon::FLOW_STRATEGY => ['router_decision', 'assumption_log'],
        RouterRuntimeCanon::FLOW_CYBER => ['router_decision', 'scope_and_roe_declaration', 'policy_refs'],
        RouterRuntimeCanon::FLOW_PERSONAL_DEVELOPMENT => ['router_decision', 'non_clinical_boundary_acknowledgment'],
        RouterRuntimeCanon::FLOW_AUTOMATION => ['router_decision', 'plan_or_execution_intent_declaration', 'policy_refs'],
        RouterRuntimeCanon::FLOW_RESEARCH => ['router_decision', 'source_refs_or_uncertainty_statement'],
        RouterRuntimeCanon::FLOW_CONVERSATION => ['router_decision'],
    ];

    /**
     * Fallback flow_id when a handler cannot resolve. Never falls into Dev /
     * Forge — conversation is the safe default.
     */
    public const FALLBACK_FLOW = RouterRuntimeCanon::FLOW_CONVERSATION;

    /**
     * Returns the canonical policy_refs for a flow, or empty list when the
     * flow has no domain-specific policy (handler can still attach extras).
     *
     * @return array<int,string>
     */
    public static function policyRefsFor(string $flowId): array
    {
        return self::POLICY_REFS[$flowId] ?? [];
    }

    /**
     * @return array<int,string>
     */
    public static function forbiddenActionsFor(string $flowId): array
    {
        return self::FORBIDDEN_ACTIONS[$flowId] ?? [];
    }

    /**
     * @return array<int,string>
     */
    public static function outputContractFor(string $flowId): array
    {
        return self::OUTPUT_CONTRACTS[$flowId] ?? ['direct_answer', 'handoff_suggestion_when_scope_changes'];
    }

    /**
     * @return array<int,string>
     */
    public static function requiredEvidenceFor(string $flowId): array
    {
        return self::REQUIRED_EVIDENCE[$flowId] ?? ['router_decision'];
    }
}
