<?php

namespace App\Services\Ai\Router;

class AtlasAiSpecialistFlowRuntimeService
{
    public const SCHEMA_VERSION = 'atlas.ai.specialist_flow_runtime.v1';

    public const RECEIPT_SCHEMA_VERSION = 'atlas.ai.specialist_flow_receipt.v1';

    /**
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    public function apply(array $data): array
    {
        $payload = is_array($data['payload'] ?? null) ? $data['payload'] : [];
        $router = is_array($payload['atlas_ai_router'] ?? null) ? $payload['atlas_ai_router'] : [];
        $flowId = $this->stringValue($router['flow_id'] ?? null);

        if ($flowId === null || is_array($payload['specialist_flow_runtime'] ?? null)) {
            return $data;
        }

        if (in_array($flowId, ['atlas_dev', 'atlas_forge'], true) || is_array($payload['atlas_dev_runtime'] ?? null)) {
            return $data;
        }

        $payload['specialist_flow_runtime'] = $this->withReceipt($this->contract($flowId, $router, $payload));
        $data['payload'] = $payload;

        return $data;
    }

    /**
     * @param  array<string,mixed>  $router
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function contract(string $flowId, array $router, array $payload): array
    {
        $workspacePresent = (bool) data_get($router, 'handoff_payload.workspace_present', false);
        $base = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => 'planned',
            'flow_id' => $flowId,
            'owner' => $flowId,
            'flow_origin' => $this->stringValue($router['flow_origin'] ?? null) ?? 'router_auto',
            'command_intent' => $this->stringValue($router['command_intent'] ?? null),
            'routing_reason' => $this->stringValue($router['routing_reason'] ?? null),
            'workspace_present' => $workspacePresent,
            'surface_id' => $this->stringValue(data_get($router, 'handoff_payload.surface_id'))
                ?? $this->stringValue($payload['surface_id'] ?? null),
            'side_effect_policy' => 'read_only_until_confirmed',
            'required_evidence' => ['router_decision', 'flow_contract'],
            'delegation' => [
                'status' => 'not_delegated',
                'reason' => 'flow_matches_router_decision',
            ],
        ];

        return match ($flowId) {
            'atlas_research' => array_merge($base, [
                'execution_mode' => 'source_grounded_answer',
                'output_contract' => ['answer_summary', 'claims_table', 'source_refs', 'uncertainty', 'open_questions'],
                'required_evidence' => ['router_decision', 'source_refs_or_uncertainty_statement'],
                'forbidden_actions' => ['treat_unsourced_claim_as_fact', 'hide_uncertainty', 'promote_memory_without_review'],
            ]),
            'atlas_explain' => array_merge($base, [
                'execution_mode' => 'read_only_explanation',
                'output_contract' => ['plain_language_explanation', 'assumptions', 'relevant_context_refs', 'next_questions'],
                'required_evidence' => ['router_decision', 'context_refs_or_scope_statement'],
                'forbidden_actions' => ['modify_workspace', 'execute_external_side_effects', 'claim_code_was_changed'],
            ]),
            'atlas_debug' => array_merge($base, [
                'execution_mode' => $workspacePresent ? 'delegate_to_atlas_dev_repair' : 'diagnostic_triage',
                'output_contract' => ['symptoms', 'likely_causes', 'repro_questions', 'next_debug_steps'],
                'required_evidence' => ['router_decision', 'logs_or_missing_logs_statement'],
                'delegation' => $workspacePresent
                    ? ['status' => 'delegate_to_other_flow', 'target_flow_id' => 'atlas_dev', 'reason' => 'workspace_debug_belongs_to_atlas_dev_repair']
                    : ['status' => 'not_delegated', 'reason' => 'no_workspace_for_repair_runtime'],
                'forbidden_actions' => ['invent_log_lines', 'claim_fix_without_workspace', 'execute_destructive_action'],
            ]),
            'atlas_review' => array_merge($base, [
                'execution_mode' => $workspacePresent ? 'delegate_to_atlas_dev_review' : 'diff_or_artifact_review',
                'output_contract' => ['findings_first', 'risk_ranking', 'file_refs_or_scope_statement', 'test_gaps'],
                'required_evidence' => ['router_decision', 'diff_or_review_scope'],
                'delegation' => $workspacePresent
                    ? ['status' => 'delegate_to_other_flow', 'target_flow_id' => 'atlas_dev', 'reason' => 'workspace_review_belongs_to_atlas_dev_review']
                    : ['status' => 'not_delegated', 'reason' => 'review_can_run_without_workspace_when_diff_or_scope_is_present'],
                'forbidden_actions' => ['rewrite_code_during_review', 'bury_findings_after_summary', 'ignore_missing_tests'],
            ]),
            'atlas_plan' => array_merge($base, [
                'execution_mode' => 'engineering_plan',
                'output_contract' => ['objective', 'assumptions', 'work_breakdown', 'risk_register', 'evidence_needed', 'execution_recommendation'],
                'required_evidence' => ['router_decision', 'scope_statement', 'risk_assessment'],
                'delegation' => [
                    'status' => 'not_delegated',
                    'reason' => 'plan_flow_prepares_execution_or_handoff',
                ],
                'forbidden_actions' => ['modify_workspace', 'claim_implementation_completed', 'skip_risk_assessment'],
            ]),
            'atlas_conversation' => array_merge($base, [
                'execution_mode' => 'conversation',
                'output_contract' => ['direct_answer', 'clarifying_question_when_needed', 'handoff_suggestion_when_scope_changes'],
                'required_evidence' => ['router_decision'],
                'forbidden_actions' => ['pretend_workspace_access', 'silently_change_flow'],
            ]),
            'atlas_finance' => array_merge($base, [
                'execution_mode' => 'analysis_with_assumptions',
                'output_contract' => ['data_snapshot', 'assumptions', 'analysis_summary', 'risk_register', 'decision_options', 'open_questions', 'evidence_refs'],
                'required_evidence' => ['router_decision', 'data_snapshot_or_missing_data_statement', 'policy_refs'],
                'forbidden_actions' => ['execute_live_trade_without_operator_approval', 'fabricate_market_data_or_returns', 'promise_returns', 'leak_account_identifiers'],
            ]),
            'atlas_marketing' => array_merge($base, [
                'execution_mode' => 'campaign_plan',
                'output_contract' => ['objective', 'audience_hypothesis', 'channels_and_angles', 'budget_options', 'success_metrics', 'risks', 'approval_gate'],
                'required_evidence' => ['router_decision', 'policy_refs'],
                'forbidden_actions' => ['publish_without_operator_approval', 'spend_above_budget_without_operator_approval', 'fabricate_audience_data'],
            ]),
            'atlas_strategy' => array_merge($base, [
                'execution_mode' => 'decision_memo',
                'output_contract' => ['situation', 'assumptions', 'options_with_tradeoffs', 'recommendation', 'rationale', 'open_questions'],
                'required_evidence' => ['router_decision', 'assumption_log'],
                'forbidden_actions' => ['claim_execution_happened', 'hide_assumption', 'single_option_disguised_as_choice'],
            ]),
            'atlas_cyber' => array_merge($base, [
                'execution_mode' => 'defensive_advisory',
                'output_contract' => ['threat_summary', 'evidence_or_indicators', 'mitigation_steps', 'detection_guidance', 'open_questions', 'roe_status'],
                'required_evidence' => ['router_decision', 'roe_status', 'policy_refs'],
                'forbidden_actions' => ['offensive_action_without_roe', 'fabricate_indicator_of_compromise', 'claim_compromise_without_evidence'],
            ]),
            'atlas_personal_development' => array_merge($base, [
                'execution_mode' => 'non_clinical_advisory',
                'output_contract' => ['reflective_frame', 'options_or_experiments', 'success_indicators', 'professional_boundary_note', 'open_questions'],
                'required_evidence' => ['router_decision', 'professional_boundary_note'],
                'forbidden_actions' => ['clinical_diagnosis', 'replace_professional_advice', 'crisis_routing_omitted'],
            ]),
            'atlas_automation' => array_merge($base, [
                'execution_mode' => 'workflow_plan_only',
                'output_contract' => ['workflow_steps', 'triggers', 'side_effects', 'rollback_plan', 'approval_gate', 'risks', 'next_stage'],
                'required_evidence' => ['router_decision', 'rollback_plan', 'policy_refs'],
                'forbidden_actions' => ['destructive_action_without_operator_approval', 'execute_without_approval', 'missing_rollback'],
            ]),
            default => array_merge($base, [
                'execution_mode' => 'conversation',
                'output_contract' => ['direct_answer', 'clarifying_question_when_needed', 'handoff_suggestion_when_scope_changes'],
                'required_evidence' => ['router_decision'],
                'forbidden_actions' => ['pretend_workspace_access', 'silently_change_flow'],
            ]),
        };
    }

    /**
     * @param  array<string,mixed>  $contract
     * @return array<string,mixed>
     */
    private function withReceipt(array $contract): array
    {
        $hashableContract = $this->canonicalize($contract);
        $contractHash = hash('sha256', json_encode($hashableContract, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $contract['receipt'] = [
            'schema_version' => self::RECEIPT_SCHEMA_VERSION,
            'receipt_id' => 'sfr_'.substr($contractHash, 0, 32),
            'contract_hash' => $contractHash,
            'issued_by' => self::SCHEMA_VERSION,
            'flow_id' => $contract['flow_id'] ?? null,
            'owner' => $contract['owner'] ?? null,
            'execution_mode' => $contract['execution_mode'] ?? null,
            'delegation_status' => data_get($contract, 'delegation.status'),
            'required_evidence' => $contract['required_evidence'] ?? [],
        ];

        return $contract;
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $canonical = [];
        foreach ($value as $key => $item) {
            $canonical[$key] = $this->canonicalize($item);
        }

        if (! array_is_list($canonical)) {
            ksort($canonical);
        }

        return $canonical;
    }

    private function stringValue(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }
}
