<?php

namespace App\Services\Ai\Domain;

use App\Services\Ai\Kernel\Decision\DecisionReceiptIssuer;
use App\Services\Ai\Kernel\Envelope\OperationEnvelopeFactory;
use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use App\Services\Ai\Support\AiStringListNormalizer;

class HealthReviewService
{
    use DomainInputNormalization;

    public const SCHEMA_VERSION = 'atlas.health.packet.v1';

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
        $topic = $this->string($input['topic'] ?? $input['subject'] ?? '');
        $goal = $this->string($input['goal'] ?? '');
        $signals = AiStringListNormalizer::trimmedCastValues($input['signals'] ?? []);
        $constraints = AiStringListNormalizer::trimmedCastValues($input['constraints'] ?? []);
        $evidenceRefs = AiStringListNormalizer::trimmedCastValues($input['evidence_refs'] ?? $input['evidence'] ?? []);
        $riskFlags = $this->riskFlags($input['risk_flags'] ?? [], $topic, $signals);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => 'ok',
            'mode' => $this->mode($flow),
            'domain' => 'health',
            'flow' => $flow,
            'brief' => [
                'topic' => $topic,
                'goal' => $goal,
                'signals' => $signals,
                'constraints' => $constraints,
                'evidence_refs' => $evidenceRefs,
                'risk_flags' => $riskFlags,
                'missing_inputs' => $this->missingInputs($topic, $goal),
            ],
            'health_contract' => [
                'non_clinical_review_only' => true,
                'wellness_planning_allowed' => true,
                'diagnosis_forbidden' => true,
                'treatment_or_dosage_forbidden' => true,
                'emergency_triage_forbidden' => true,
                'professional_review_required_for_risk_flags' => true,
            ],
            'output_contract' => $this->outputContract($flow),
            'gates' => $this->gates($topic, $goal, $riskFlags),
            'rules' => [
                'does_not_diagnose' => true,
                'does_not_prescribe' => true,
                'does_not_change_medication' => true,
                'does_not_replace_professional_care' => true,
                'requires_escalation_for_red_flags' => true,
            ],
            'forbidden_actions' => [
                'medical_diagnosis',
                'treatment_plan',
                'dosage_change',
                'emergency_decision',
                'lab_interpretation_as_diagnosis',
                'professional_care_replacement',
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
                'surface_id' => $this->string($input['surface_id'] ?? 'atlas_domain_orchestrator_health') ?: 'atlas_domain_orchestrator_health',
                'surface_version' => 'atlas.health.v1',
                'session_id' => $this->string($input['session_id'] ?? ''),
            ],
            'input' => [
                'primary_type' => 'text',
                'text' => trim(data_get($packet, 'brief.topic', '')."\n".data_get($packet, 'brief.goal', '')),
                'hints' => [
                    'domain' => 'health',
                    'flow' => $flow,
                    'mode' => $packet['mode'],
                    'audit_only' => true,
                ],
                'locale' => 'pt-BR',
            ],
        ]);

        $receipt = $this->receipts->issue($envelope, [
            'dry_run' => true,
            'signed_by' => 'atlas.health.packet.v1',
            'domain' => 'health',
            'flow' => $flow,
            'risk' => data_get($packet, 'brief.risk_flags') === [] ? 'medium' : 'high',
            'provider_selection' => [
                'primary' => 'none',
                'model' => 'not_applicable_health_packet',
                'fallbacks' => [],
                'selection_mode' => 'auto_best_allowed',
                'selection_reason' => 'Health orchestrator emits a governed non-clinical packet; medical decisions are forbidden.',
            ],
            'budgets' => [
                'max_prompt_tokens' => 0,
                'max_output_tokens' => 0,
                'max_cost_usd' => 0,
            ],
            'required_gates' => $requiredGates,
            'required_evidence' => ['health_packet', 'non_clinical_boundary', 'risk_flags', 'professional_review_notice'],
            'repair_policy' => [
                'enabled' => true,
                'max_attempts' => 1,
                'strategy' => 'request_topic_goal_constraints_and_risk_flags_or_escalate_to_professional_review',
            ],
            'metadata' => [
                'tenant_id' => $tenantId,
                'operator_id' => $operatorId,
                'non_clinical_review_only' => true,
                'medical_decisions_forbidden' => true,
                'packet_schema_version' => self::SCHEMA_VERSION,
            ],
        ])->toArray();

        $this->ledger->recordDecisionIssued($receipt, [
            'tenant_id' => $tenantId,
            'operator_id' => $operatorId,
            'trace_id' => $envelope->audit->traceId,
            'correlation_id' => $envelope->audit->traceId,
            'emitter_stage' => 'atlas.health.packet',
            'emitter_version' => self::SCHEMA_VERSION,
        ]);

        $packed = $this->ledger->record(LedgerEventType::EvidencePacked, [
            'envelope_id' => $envelope->envelopeId,
            'receipt_id' => $receipt['receipt_id'],
            'domain' => 'health',
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
            'emitter_stage' => 'atlas.health.packet',
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

    private function mode(string $flow): string
    {
        return match ($flow) {
            'health.routine_review' => 'routine_review',
            'health.recovery_review' => 'recovery_review',
            'health.safety_review' => 'safety_review',
            default => 'wellness_review',
        };
    }

    /**
     * @return array<string,bool>
     */
    private function outputContract(string $flow): array
    {
        return match ($flow) {
            'health.routine_review' => ['routine_observations' => true, 'constraints' => true, 'professional_review_notice' => true],
            'health.recovery_review' => ['recovery_considerations' => true, 'risk_flags' => true, 'non_clinical_next_steps' => true],
            'health.safety_review' => ['red_flags' => true, 'escalation_notice' => true, 'do_not_delay_care' => true],
            default => ['wellness_summary' => true, 'non_clinical_boundaries' => true, 'questions_for_professional' => true],
        };
    }

    /**
     * @return array<int,string>
     */
    private function missingInputs(string $topic, string $goal): array
    {
        return array_values(array_filter([
            $topic === '' ? 'topic' : null,
            $goal === '' ? 'goal' : null,
        ]));
    }

    /**
     * @param  array<int,string>  $riskFlags
     * @return array<int,array{id:string,status:string,reason:string}>
     */
    private function gates(string $topic, string $goal, array $riskFlags): array
    {
        return [
            $this->gate('health_scope', $topic !== '' && $goal !== '', 'Health topic and goal are required.'),
            $this->gate('non_clinical_boundary', true, 'Health domain is non-clinical review only.'),
            $this->gate('professional_review_notice', true, 'Professional review is required for medical decisions.'),
            $this->gate('red_flag_escalation', $riskFlags === [], 'Risk flags require escalation notice and no self-treatment.'),
        ];
    }

    /**
     * @return array{id:string,status:string,reason:string}
     */
    private function gate(string $id, bool $passed, string $reason): array
    {
        return [
            'id' => $id,
            'status' => $passed ? 'passed' : 'review_required',
            'reason' => $reason,
        ];
    }

    /**
     * @return array<int,string>
     */
    private function riskFlags(mixed $value, string $topic, array $signals): array
    {
        $flags = AiStringListNormalizer::trimmedCastValues($value);
        $haystack = strtolower($topic.' '.implode(' ', $signals));

        foreach (['chest pain', 'suicide', 'fainting', 'severe pain', 'shortness of breath', 'emergency'] as $flag) {
            if (str_contains($haystack, $flag)) {
                $flags[] = $flag;
            }
        }

        return array_values(array_unique($flags));
    }
}
