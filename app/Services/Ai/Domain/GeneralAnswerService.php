<?php

namespace App\Services\Ai\Domain;

use App\Services\Ai\Kernel\Decision\DecisionReceiptIssuer;
use App\Services\Ai\Kernel\Envelope\OperationEnvelopeFactory;
use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;

class GeneralAnswerService
{
    public const SCHEMA_VERSION = 'atlas.general.packet.v1';

    public function __construct(
        private readonly OperationEnvelopeFactory $envelopes,
        private readonly DecisionReceiptIssuer $receipts,
        private readonly AtlasEvidenceLedger $ledger,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function packet(string $flow, array $input): array
    {
        $question = $this->string($input['question'] ?? $input['text'] ?? $input['prompt'] ?? '');
        $context = $this->list($input['context'] ?? []);
        $attachments = $this->list($input['attachments'] ?? []);
        $constraints = $this->list($input['constraints'] ?? []);
        $desiredOutput = $this->string($input['desired_output'] ?? 'answer');
        $suspectedDomain = $this->suspectedDomain($question, $context);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => 'ok',
            'mode' => 'general_answer',
            'domain' => 'general',
            'flow' => $flow,
            'brief' => [
                'question' => $question,
                'context' => $context,
                'attachments' => $attachments,
                'constraints' => $constraints,
                'desired_output' => $desiredOutput,
                'suspected_domain' => $suspectedDomain,
                'missing_inputs' => $question === '' ? ['question'] : [],
            ],
            'general_contract' => [
                'answer_or_triage_only' => true,
                'does_not_execute_domain_work' => true,
                'must_handoff_specialized_requests' => true,
                'no_destructive_action' => true,
                'no_policy_bypass' => true,
            ],
            'output_contract' => [
                'direct_answer' => true,
                'assumptions' => true,
                'domain_handoff' => true,
                'uncertainty' => true,
            ],
            'gates' => $this->gates($question, $suspectedDomain),
            'rules' => [
                'simple_questions_can_answer_directly' => true,
                'specialized_requests_return_handoff_recommendation' => true,
                'does_not_change_files' => true,
                'does_not_call_tools' => true,
                'does_not_override_decide' => true,
            ],
            'forbidden_actions' => [
                'code_change',
                'financial_action',
                'health_claim',
                'operational_mutation',
                'background_job_start',
                'provider_override',
            ],
            'generated_at' => now()->toJSON(),
        ];
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function auditedPacket(string $flow, array $input): array
    {
        $packet = $this->packet($flow, $input);
        $tenantId = $this->string($input['tenant_id'] ?? 'default') ?: 'default';
        $operatorId = $this->string($input['operator_id'] ?? 'system') ?: 'system';
        $requiredGates = collect((array) $packet['gates'])->pluck('id')->values()->all();

        $envelope = $this->envelopes->create([
            'operator' => [
                'tenant_id' => $tenantId,
                'operator_id' => $operatorId,
            ],
            'origin' => [
                'surface_id' => $this->string($input['surface_id'] ?? 'atlas_domain_orchestrator_general') ?: 'atlas_domain_orchestrator_general',
                'surface_version' => 'atlas.general.v1',
                'session_id' => $this->string($input['session_id'] ?? ''),
            ],
            'input' => [
                'primary_type' => 'text',
                'text' => (string) data_get($packet, 'brief.question', ''),
                'hints' => [
                    'domain' => 'general',
                    'flow' => $flow,
                    'mode' => 'general_answer',
                    'audit_only' => true,
                ],
                'locale' => 'pt-BR',
            ],
        ]);

        $receipt = $this->receipts->issue($envelope, [
            'dry_run' => true,
            'signed_by' => 'atlas.general.packet.v1',
            'domain' => 'general',
            'flow' => $flow,
            'risk' => data_get($packet, 'brief.suspected_domain') === 'general' ? 'low' : 'medium',
            'provider_selection' => [
                'primary' => 'none',
                'model' => 'not_applicable_general_packet',
                'fallbacks' => [],
                'selection_mode' => 'auto_best_allowed',
                'selection_reason' => 'General orchestrator emits a governed answer/triage packet; specialized work must be routed through Atlas Decide.',
            ],
            'budgets' => [
                'max_prompt_tokens' => 0,
                'max_output_tokens' => 0,
                'max_cost_usd' => 0,
            ],
            'required_gates' => $requiredGates,
            'required_evidence' => ['general_packet', 'domain_handoff_review', 'uncertainty_statement'],
            'repair_policy' => [
                'enabled' => true,
                'max_attempts' => 1,
                'strategy' => 'request_question_context_or_route_to_domain',
            ],
            'metadata' => [
                'tenant_id' => $tenantId,
                'operator_id' => $operatorId,
                'answer_or_triage_only' => true,
                'specialized_work_requires_decide' => true,
                'packet_schema_version' => self::SCHEMA_VERSION,
            ],
        ])->toArray();

        $this->ledger->recordDecisionIssued($receipt, [
            'tenant_id' => $tenantId,
            'operator_id' => $operatorId,
            'trace_id' => $envelope->audit->traceId,
            'correlation_id' => $envelope->audit->traceId,
            'emitter_stage' => 'atlas.general.packet',
            'emitter_version' => self::SCHEMA_VERSION,
        ]);

        $packed = $this->ledger->record(LedgerEventType::EvidencePacked, [
            'envelope_id' => $envelope->envelopeId,
            'receipt_id' => $receipt['receipt_id'],
            'domain' => 'general',
            'flow' => $flow,
            'packet' => $packet,
            'rules' => $packet['rules'],
        ], [
            'tenant_id' => $tenantId,
            'operator_id' => $operatorId,
            'envelope_id' => $envelope->envelopeId,
            'receipt_id' => $receipt['receipt_id'],
            'trace_id' => $envelope->audit->traceId,
            'correlation_id' => $envelope->audit->traceId,
            'emitter_stage' => 'atlas.general.packet',
            'emitter_version' => self::SCHEMA_VERSION,
        ]);

        return [
            'packet' => $packet,
            'receipt' => $receipt,
            'ledger' => [
                'envelope_id' => $envelope->envelopeId,
                'trace_id' => $envelope->audit->traceId,
                'events' => array_values(array_filter([
                    'ENVELOPE_CREATED',
                    'DECISION_ISSUED',
                    $packed?->event_type,
                ])),
            ],
        ];
    }

    /**
     * @return array<int,array{id:string,status:string,reason:string}>
     */
    private function gates(string $question, string $suspectedDomain): array
    {
        return [
            $this->gate('question_present', $question !== '', 'General answer requires a question.'),
            $this->gate('answer_or_triage_only', true, 'General domain can answer or triage only.'),
            $this->gate('domain_handoff_review', true, "Suspected domain: {$suspectedDomain}."),
            $this->gate('human_review_required_for_actions', true, 'General domain cannot execute actions.'),
        ];
    }

    /**
     * @return array{id:string,status:string,reason:string}
     */
    private function gate(string $id, bool $passed, string $reason): array
    {
        return [
            'id' => $id,
            'status' => $passed ? 'passed' : 'missing',
            'reason' => $reason,
        ];
    }

    /**
     * @param  array<int,string>  $context
     */
    private function suspectedDomain(string $question, array $context): string
    {
        $haystack = strtolower($question.' '.implode(' ', $context));

        return match (true) {
            str_contains($haystack, 'code') || str_contains($haystack, 'bug') || str_contains($haystack, 'program') => 'programming',
            str_contains($haystack, 'campaign') || str_contains($haystack, 'copy') || str_contains($haystack, 'marketing') => 'marketing',
            str_contains($haystack, 'finance') || str_contains($haystack, 'market ') || str_contains($haystack, 'trade') => 'finance',
            str_contains($haystack, 'deploy') || str_contains($haystack, 'incident') || str_contains($haystack, 'runbook') => 'operations',
            str_contains($haystack, 'habit') || str_contains($haystack, 'routine') || str_contains($haystack, 'focus') => 'personal_development',
            str_contains($haystack, 'health') || str_contains($haystack, 'medical') || str_contains($haystack, 'symptom') => 'health',
            default => 'general',
        };
    }

    private function string(mixed $value): string
    {
        return trim((string) $value);
    }

    /**
     * @return array<int,string>
     */
    private function list(mixed $value): array
    {
        if (is_array($value)) {
            return array_values(array_filter(array_map(
                fn (mixed $item): string => trim((string) $item),
                $value
            ), fn (string $item): bool => $item !== ''));
        }

        $string = $this->string($value);

        return $string === '' ? [] : [$string];
    }
}
