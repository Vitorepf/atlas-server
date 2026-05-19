<?php

namespace App\Services\Ai\Router;

class AtlasAiSpecialistFlowExecutionService
{
    public const SCHEMA_VERSION = 'atlas.ai.specialist_flow_execution.v1';

    /**
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    public function apply(array $data): array
    {
        $payload = is_array($data['payload'] ?? null) ? $data['payload'] : [];
        $runtime = is_array($payload['specialist_flow_runtime'] ?? null) ? $payload['specialist_flow_runtime'] : [];

        if ($runtime === [] || is_array($payload['specialist_flow_execution'] ?? null)) {
            return $data;
        }

        $flowId = $this->stringValue($runtime['flow_id'] ?? null);
        if ($flowId === null) {
            return $data;
        }

        $payload['specialist_flow_execution'] = $this->executionPacket($flowId, $runtime, $data);
        $data['payload'] = $payload;

        return $data;
    }

    /**
     * @param  array<string,mixed>  $runtime
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    private function executionPacket(string $flowId, array $runtime, array $data): array
    {
        $delegationStatus = $this->stringValue(data_get($runtime, 'delegation.status')) ?? 'not_delegated';
        $handler = $this->handler($flowId, $delegationStatus);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $delegationStatus === 'delegate_to_other_flow' ? 'delegated' : 'ready_for_provider',
            'flow_id' => $flowId,
            'handler_id' => $handler['handler_id'],
            'handler_version' => 'v1',
            'runtime_receipt_id' => data_get($runtime, 'receipt.receipt_id'),
            'runtime_contract_hash' => data_get($runtime, 'receipt.contract_hash'),
            'delegation' => is_array(data_get($runtime, 'delegation')) ? data_get($runtime, 'delegation') : [],
            'provider_prompt_contract' => $handler['prompt_contract'],
            'response_shape' => $handler['response_shape'],
            'audit_checks' => $handler['audit_checks'],
            'quality_rubric' => $handler['quality_rubric'],
            'completion_checks' => $handler['completion_checks'],
            'failure_modes' => $handler['failure_modes'],
            'learning_signal_contract' => $this->learningSignalContract($flowId, $handler),
            'operator_input_summary' => $this->summary((string) ($data['input_text'] ?? '')),
        ];
    }

    /**
     * @param  array<string,mixed>  $handler
     * @return array<string,mixed>
     */
    private function learningSignalContract(string $flowId, array $handler): array
    {
        return [
            'schema_version' => 'atlas.ai.compounding.flow_learning_signal_contract.v1',
            'flow_id' => $flowId,
            'emits_learning_signal' => true,
            'required_fields' => [
                'run_id',
                'flow_id',
                'outcome_status',
                'evidence_refs',
                'claim',
                'confidence',
            ],
            'candidate_memory_type' => match ($flowId) {
                'atlas_debug' => 'debug_memory',
                'atlas_review' => 'review_memory',
                'atlas_research' => 'retrieval_memory',
                'atlas_plan' => 'routing_memory',
                default => 'routing_memory',
            },
            'evidence_sources' => [
                'runtime_receipt_id',
                'runtime_contract_hash',
                'audit_checks',
                'completion_checks',
                'provider_or_operator_receipt',
            ],
            'noise_filters' => [
                'missing_evidence_refs',
                'raw_trace_without_outcome',
                'operator_preference_without_result',
            ],
            'failure_modes_feed_benchmark' => $handler['failure_modes'] ?? [],
        ];
    }

    /**
     * @return array{handler_id:string,prompt_contract:array<int,string>,response_shape:array<int,string>,audit_checks:array<int,string>,quality_rubric:array<int,string>,completion_checks:array<int,string>,failure_modes:array<int,string>}
     */
    private function handler(string $flowId, string $delegationStatus): array
    {
        if ($delegationStatus === 'delegate_to_other_flow') {
            return [
                'handler_id' => 'atlas_specialist_delegation_handler',
                'prompt_contract' => [
                    'Declare that this request belongs to another Atlas flow.',
                    'Do not execute the delegated work in the current flow.',
                    'Explain the target flow and the evidence that caused delegation.',
                ],
                'response_shape' => ['delegation_reason', 'target_flow_id', 'operator_next_step'],
                'audit_checks' => ['target_flow_present', 'no_work_executed_in_wrong_flow'],
                'quality_rubric' => ['handoff_is_explicit', 'target_flow_is_justified', 'no_hidden_execution'],
                'completion_checks' => ['target_flow_id_present', 'delegation_reason_present', 'operator_next_step_present'],
                'failure_modes' => ['executing_delegated_work', 'missing_target_flow', 'unclear_operator_next_step'],
            ];
        }

        return match ($flowId) {
            'atlas_research' => [
                'handler_id' => 'atlas_research_grounded_answer_handler',
                'prompt_contract' => [
                    'Answer only from available context, attached files, retrieved sources, or explicitly stated assumptions.',
                    'Separate evidence, inference, recommendation, uncertainty, and open questions.',
                    'Never present an unsourced claim as verified fact.',
                ],
                'response_shape' => ['answer_summary', 'claims_table', 'source_refs', 'uncertainty', 'open_questions'],
                'audit_checks' => ['source_refs_or_uncertainty_present', 'unsourced_claims_labeled', 'memory_not_promoted'],
                'quality_rubric' => ['claims_are_traceable', 'uncertainty_is_visible', 'recommendations_separate_from_facts'],
                'completion_checks' => ['material_claims_have_source_or_uncertainty', 'open_questions_are_listed', 'no_fabricated_sources'],
                'failure_modes' => ['fake_citation', 'unstated_inference', 'overconfident_unsourced_answer'],
            ],
            'atlas_explain' => [
                'handler_id' => 'atlas_explain_read_only_handler',
                'prompt_contract' => [
                    'Explain in plain language using only available context.',
                    'Do not claim code, files, configuration, or external systems were changed.',
                    'State assumptions and missing context when the explanation depends on unavailable evidence.',
                ],
                'response_shape' => ['plain_language_explanation', 'assumptions', 'relevant_context_refs', 'next_questions'],
                'audit_checks' => ['no_side_effect_claims', 'assumptions_visible', 'scope_visible'],
                'quality_rubric' => ['plain_language_without_losing_precision', 'assumptions_are_named', 'scope_boundaries_are_clear'],
                'completion_checks' => ['answer_matches_available_context', 'missing_context_is_called_out', 'no_workspace_action_claimed'],
                'failure_modes' => ['claiming_files_changed', 'hiding_assumptions', 'explaining_beyond_available_context_as_fact'],
            ],
            'atlas_debug' => [
                'handler_id' => 'atlas_debug_triage_handler',
                'prompt_contract' => [
                    'Triage symptoms, likely causes, and next diagnostic steps.',
                    'Do not invent logs, stack traces, commands, or reproduction results.',
                    'If workspace execution is needed, delegate to Atlas Dev instead of pretending a fix happened.',
                ],
                'response_shape' => ['symptoms', 'likely_causes', 'missing_evidence', 'next_debug_steps'],
                'audit_checks' => ['no_invented_logs', 'missing_evidence_visible', 'no_false_fix_claim'],
                'quality_rubric' => ['symptoms_are_separated_from_causes', 'diagnostics_are_reproducible', 'workspace_execution_is_delegated_when_needed'],
                'completion_checks' => ['missing_evidence_listed', 'next_debug_steps_are_ordered', 'no_fix_claim_without_execution'],
                'failure_modes' => ['invented_log_or_stacktrace', 'premature_root_cause', 'fix_claim_without_test'],
            ],
            'atlas_review' => [
                'handler_id' => 'atlas_review_findings_first_handler',
                'prompt_contract' => [
                    'Lead with findings ordered by severity.',
                    'Tie every finding to provided diff, artifact, file reference, or explicit scope.',
                    'Keep summaries secondary and call out missing tests or residual risk.',
                ],
                'response_shape' => ['findings', 'open_questions', 'test_gaps', 'change_summary'],
                'audit_checks' => ['findings_first', 'severity_ordered', 'file_or_scope_refs_present'],
                'quality_rubric' => ['findings_are_actionable', 'severity_is_defensible', 'references_are_precise'],
                'completion_checks' => ['findings_precede_summary', 'each_finding_has_scope_or_reference', 'test_gaps_or_residual_risk_are_visible'],
                'failure_modes' => ['summary_before_findings', 'style_only_review', 'unreferenced_behavioral_claim'],
            ],
            'atlas_plan' => [
                'handler_id' => 'atlas_plan_engineering_plan_handler',
                'prompt_contract' => [
                    'Create an actionable engineering plan before execution.',
                    'Separate assumptions, required evidence, risks, milestones, and recommended execution flow.',
                    'Do not claim implementation, file changes, tests, or provider execution happened.',
                ],
                'response_shape' => ['objective', 'assumptions', 'work_breakdown', 'risk_register', 'evidence_needed', 'execution_recommendation'],
                'audit_checks' => ['no_implementation_claim', 'risks_visible', 'execution_flow_recommended'],
                'quality_rubric' => ['plan_is_executable', 'risks_are_named_early', 'evidence_needed_is_concrete'],
                'completion_checks' => ['objective_is_restated', 'milestones_have_validation_evidence', 'recommended_flow_is_clear'],
                'failure_modes' => ['planning_as_completed_work', 'missing_risk_register', 'vague_next_steps'],
            ],
            'atlas_finance' => [
                'handler_id' => 'atlas_finance_analysis_with_assumptions_handler',
                'prompt_contract' => [
                    'Provide analysis with an explicit data snapshot, assumptions, and a risk register.',
                    'Never execute a live trade or recommend committing capital without explicit operator approval.',
                    'Never fabricate market data, returns, or account identifiers; declare missing data instead.',
                ],
                'response_shape' => ['data_snapshot', 'assumptions', 'analysis_summary', 'risk_register', 'decision_options', 'open_questions', 'evidence_refs'],
                'audit_checks' => ['data_snapshot_or_missing_data_statement_present', 'assumptions_logged', 'risk_register_visible', 'no_live_trade_executed_without_approval'],
                'quality_rubric' => ['assumptions_separated_from_facts', 'risks_quantified_or_declared_unknown', 'decision_options_have_tradeoffs'],
                'completion_checks' => ['data_snapshot_present_or_declared_missing', 'risk_register_present', 'no_promised_returns', 'no_account_identifier_leak'],
                'failure_modes' => ['fabricated_market_data', 'unauthorized_live_trade_claim', 'promised_returns', 'account_identifier_leak'],
            ],
            'atlas_marketing' => [
                'handler_id' => 'atlas_marketing_campaign_plan_handler',
                'prompt_contract' => [
                    'Produce a campaign plan with targeting, channels, content angles, and budget options.',
                    'Never publish content, never commit budget, never run paid ads without explicit operator approval.',
                    'State assumptions about audience and channel performance separately from past data.',
                ],
                'response_shape' => ['objective', 'audience_hypothesis', 'channels_and_angles', 'budget_options', 'success_metrics', 'risks', 'approval_gate'],
                'audit_checks' => ['publish_requires_operator_approval', 'spend_requires_operator_approval', 'assumptions_visible'],
                'quality_rubric' => ['audience_hypothesis_is_explicit', 'budget_options_have_tradeoffs', 'success_metrics_are_measurable'],
                'completion_checks' => ['no_publish_claim_without_approval', 'no_spend_claim_without_approval', 'approval_gate_present'],
                'failure_modes' => ['publish_claim_without_approval', 'spend_claim_without_approval', 'fabricated_audience_data'],
            ],
            'atlas_strategy' => [
                'handler_id' => 'atlas_strategy_decision_memo_handler',
                'prompt_contract' => [
                    'Produce a decision memo: situation, assumptions, options with tradeoffs, recommendation, rationale.',
                    'Never claim execution happened; this is read-only strategy work.',
                    'Separate facts from hypotheses; document assumptions explicitly.',
                ],
                'response_shape' => ['situation', 'assumptions', 'options_with_tradeoffs', 'recommendation', 'rationale', 'open_questions'],
                'audit_checks' => ['assumptions_explicit', 'options_have_tradeoffs', 'recommendation_has_rationale', 'no_execution_claim'],
                'quality_rubric' => ['options_are_actually_distinct', 'tradeoffs_are_honest', 'recommendation_is_defensible'],
                'completion_checks' => ['recommendation_present', 'rationale_present', 'no_action_claimed_as_executed'],
                'failure_modes' => ['single_option_disguised_as_choice', 'hidden_assumption', 'recommendation_without_rationale'],
            ],
            'atlas_cyber' => [
                'handler_id' => 'atlas_cyber_defensive_advisory_handler',
                'prompt_contract' => [
                    'Default posture is defensive advisory: threat triage, mitigation steps, detection guidance, no offensive action.',
                    'Offensive action requires explicit Rules of Engagement (ROE) signed and execute_approved by operator. Never assume.',
                    'Never invent attacker behavior, never claim a system was tested or compromised without evidence.',
                ],
                'response_shape' => ['threat_summary', 'evidence_or_indicators', 'mitigation_steps', 'detection_guidance', 'open_questions', 'roe_status'],
                'audit_checks' => ['roe_status_visible', 'offensive_action_blocked_when_roe_unsigned', 'no_invented_attacker_behavior', 'no_unsanctioned_compromise_claim'],
                'quality_rubric' => ['threat_is_specific', 'mitigation_steps_are_concrete', 'detection_guidance_is_actionable'],
                'completion_checks' => ['mitigation_steps_present', 'detection_guidance_present', 'roe_status_reported'],
                'failure_modes' => ['offensive_action_without_roe', 'fabricated_indicator_of_compromise', 'silent_assumption_of_authorization'],
            ],
            'atlas_personal_development' => [
                'handler_id' => 'atlas_personal_development_non_clinical_handler',
                'prompt_contract' => [
                    'Provide non-clinical advisory: reflective prompts, framework references, journaling structures, habit experiments.',
                    'Never replace professional psychological, medical, legal, or financial advice; declare boundary explicitly.',
                    'When risk indicators appear (crisis, self-harm, severe distress), recommend professional support instead of advising.',
                ],
                'response_shape' => ['reflective_frame', 'options_or_experiments', 'success_indicators', 'professional_boundary_note', 'open_questions'],
                'audit_checks' => ['professional_boundary_note_present', 'no_clinical_diagnosis_claimed', 'no_replacement_of_professional_advice'],
                'quality_rubric' => ['reflective_frame_is_honest', 'experiments_are_specific', 'boundary_is_visible_not_buried'],
                'completion_checks' => ['professional_boundary_note_present', 'no_clinical_claim', 'no_medical_or_legal_recommendation_as_substitute'],
                'failure_modes' => ['clinical_diagnosis_claim', 'professional_replacement_claim', 'crisis_routing_omitted'],
            ],
            'atlas_automation' => [
                'handler_id' => 'atlas_automation_plan_first_handler',
                'prompt_contract' => [
                    'Default stage is plan: workflow steps, triggers, side-effects, rollback plan, approval gates.',
                    'Execute stage requires explicit operator approval; never assume execute_approved.',
                    'Never claim a destructive or irreversible side effect happened without operator confirmation in the same loop.',
                ],
                'response_shape' => ['workflow_steps', 'triggers', 'side_effects', 'rollback_plan', 'approval_gate', 'risks', 'next_stage'],
                'audit_checks' => ['rollback_plan_present', 'side_effects_listed', 'approval_gate_when_destructive', 'plan_vs_execute_visible'],
                'quality_rubric' => ['steps_are_executable', 'side_effects_are_complete', 'rollback_is_concrete'],
                'completion_checks' => ['rollback_plan_present', 'no_destructive_action_claimed_without_approval', 'next_stage_clear'],
                'failure_modes' => ['silent_destructive_action', 'missing_rollback', 'execute_claim_without_approval'],
            ],
            'atlas_conversation' => [
                'handler_id' => 'atlas_conversation_direct_handler',
                'prompt_contract' => [
                    'Answer the operator directly and conversationally.',
                    'Ask a clarifying question only when required to avoid a wrong answer.',
                    'Suggest a flow handoff when the scope becomes programming, research, review, debug, or Forge work.',
                ],
                'response_shape' => ['direct_answer', 'clarifying_question_when_needed', 'handoff_suggestion_when_scope_changes'],
                'audit_checks' => ['no_fake_workspace_access', 'handoff_when_scope_changes'],
                'quality_rubric' => ['answer_is_direct', 'clarification_is_used_only_when_needed', 'handoff_boundary_is_visible'],
                'completion_checks' => ['operator_question_is_answered', 'uncertainty_is_not_hidden', 'scope_change_gets_handoff_suggestion'],
                'failure_modes' => ['pretending_workspace_access', 'unnecessary_clarification_loop', 'missing_handoff_for_engineering_scope'],
            ],
            default => [
                'handler_id' => 'atlas_conversation_direct_handler',
                'prompt_contract' => [
                    'Answer the operator directly and conversationally.',
                    'Ask a clarifying question only when required to avoid a wrong answer.',
                    'Suggest a flow handoff when the scope becomes programming, research, review, debug, or Forge work.',
                ],
                'response_shape' => ['direct_answer', 'clarifying_question_when_needed', 'handoff_suggestion_when_scope_changes'],
                'audit_checks' => ['no_fake_workspace_access', 'handoff_when_scope_changes'],
                'quality_rubric' => ['answer_is_direct', 'clarification_is_used_only_when_needed', 'handoff_boundary_is_visible'],
                'completion_checks' => ['operator_question_is_answered', 'uncertainty_is_not_hidden', 'scope_change_gets_handoff_suggestion'],
                'failure_modes' => ['pretending_workspace_access', 'unnecessary_clarification_loop', 'missing_handoff_for_engineering_scope'],
            ],
        };
    }

    private function summary(string $input): string
    {
        $input = trim(preg_replace('/\s+/', ' ', $input) ?: '');

        return mb_substr($input, 0, 240);
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
