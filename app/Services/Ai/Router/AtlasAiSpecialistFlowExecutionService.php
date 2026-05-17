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
            'operator_input_summary' => $this->summary((string) ($data['input_text'] ?? '')),
        ];
    }

    /**
     * @return array{handler_id:string,prompt_contract:array<int,string>,response_shape:array<int,string>,audit_checks:array<int,string>}
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
