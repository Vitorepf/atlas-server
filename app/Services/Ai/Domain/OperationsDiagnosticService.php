<?php

namespace App\Services\Ai\Domain;

use App\Services\Ai\Kernel\Decision\DecisionReceiptIssuer;
use App\Services\Ai\Kernel\Envelope\OperationEnvelopeFactory;
use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use App\Services\Ai\Support\AiStringListNormalizer;

class OperationsDiagnosticService
{
    public const SCHEMA_VERSION = 'atlas.operations.packet.v1';

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
        $system = $this->string($input['system'] ?? $input['subject'] ?? '');
        $scope = $this->string($input['scope'] ?? '');
        $symptoms = AiStringListNormalizer::trimmedCastValues($input['symptoms'] ?? []);
        $signals = AiStringListNormalizer::trimmedCastValues($input['signals'] ?? $input['metrics'] ?? []);
        $evidenceRefs = AiStringListNormalizer::trimmedCastValues($input['evidence_refs'] ?? $input['evidence'] ?? []);
        $riskClass = $this->string($input['risk_class'] ?? 'medium');
        $constraints = AiStringListNormalizer::trimmedCastValues($input['constraints'] ?? []);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => 'ok',
            'mode' => $this->mode($flow),
            'domain' => 'operations',
            'flow' => $flow,
            'brief' => [
                'system' => $system,
                'scope' => $scope,
                'symptoms' => $symptoms,
                'signals' => $signals,
                'evidence_refs' => $evidenceRefs,
                'risk_class' => $riskClass,
                'constraints' => $constraints,
                'missing_inputs' => $this->missingInputs($system, $scope),
            ],
            'operations_contract' => [
                'diagnostic_only' => true,
                'runbook_generation_allowed' => true,
                'no_infra_mutation' => true,
                'no_deploy_or_restart' => true,
                'operational_action_requires_separate_receipt' => true,
            ],
            'output_contract' => $this->outputContract($flow),
            'gates' => $this->gates($flow, $system, $scope, $evidenceRefs),
            'rules' => [
                'diagnostic_only_until_operator_acceptance' => true,
                'does_not_restart_services' => true,
                'does_not_deploy' => true,
                'does_not_modify_infrastructure' => true,
                'requires_human_review_for_high_or_critical' => true,
            ],
            'forbidden_actions' => [
                'restart_service',
                'deploy_change',
                'modify_infrastructure',
                'delete_data',
                'change_dns_or_secrets',
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
                'surface_id' => $this->string($input['surface_id'] ?? 'atlas_domain_orchestrator_operations') ?: 'atlas_domain_orchestrator_operations',
                'surface_version' => 'atlas.operations.v1',
                'session_id' => $this->string($input['session_id'] ?? ''),
            ],
            'input' => [
                'primary_type' => 'text',
                'text' => trim(data_get($packet, 'brief.system', '')."\n".data_get($packet, 'brief.scope', '')),
                'hints' => [
                    'domain' => 'operations',
                    'flow' => $flow,
                    'mode' => $packet['mode'],
                    'audit_only' => true,
                ],
                'locale' => 'pt-BR',
            ],
        ]);

        $receipt = $this->receipts->issue($envelope, [
            'dry_run' => true,
            'signed_by' => 'atlas.operations.packet.v1',
            'domain' => 'operations',
            'flow' => $flow,
            'risk' => $this->risk($packet),
            'provider_selection' => [
                'primary' => 'none',
                'model' => 'not_applicable_operations_packet',
                'fallbacks' => [],
                'selection_mode' => 'auto_best_allowed',
                'selection_reason' => 'Operations orchestrator emits a governed diagnostic packet; operational execution requires a separate runtime receipt.',
            ],
            'budgets' => [
                'max_prompt_tokens' => 0,
                'max_output_tokens' => 0,
                'max_cost_usd' => 0,
            ],
            'required_gates' => $requiredGates,
            'required_evidence' => ['operations_packet', 'scope_boundary', 'evidence_refs', 'human_review_required'],
            'repair_policy' => [
                'enabled' => true,
                'max_attempts' => 1,
                'strategy' => 'request_system_scope_signals_or_evidence',
            ],
            'metadata' => [
                'tenant_id' => $tenantId,
                'operator_id' => $operatorId,
                'diagnostic_only_until_operator_acceptance' => true,
                'operational_action_requires_separate_receipt' => true,
                'packet_schema_version' => self::SCHEMA_VERSION,
            ],
        ])->toArray();

        $this->ledger->recordDecisionIssued($receipt, [
            'tenant_id' => $tenantId,
            'operator_id' => $operatorId,
            'trace_id' => $envelope->audit->traceId,
            'correlation_id' => $envelope->audit->traceId,
            'emitter_stage' => 'atlas.operations.packet',
            'emitter_version' => self::SCHEMA_VERSION,
        ]);

        $packed = $this->ledger->record(LedgerEventType::EvidencePacked, [
            'envelope_id' => $envelope->envelopeId,
            'receipt_id' => $receipt['receipt_id'],
            'domain' => 'operations',
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
            'emitter_stage' => 'atlas.operations.packet',
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
            'operations.runbook' => 'runbook_plan',
            'operations.incident_review' => 'incident_review',
            'operations.readiness_review' => 'readiness_review',
            default => 'diagnostic',
        };
    }

    /**
     * @return array<string,bool>
     */
    private function outputContract(string $flow): array
    {
        return match ($flow) {
            'operations.runbook' => ['runbook_steps' => true, 'prechecks' => true, 'rollback_questions' => true],
            'operations.incident_review' => ['timeline' => true, 'probable_causes' => true, 'postmortem_actions' => true],
            'operations.readiness_review' => ['readiness_score' => true, 'blocking_risks' => true, 'operator_decision_needed' => true],
            default => ['triage_tree' => true, 'signal_map' => true, 'next_safe_checks' => true],
        };
    }

    /**
     * @return array<int,string>
     */
    private function missingInputs(string $system, string $scope): array
    {
        $missing = [];

        if ($system === '') {
            $missing[] = 'system';
        }

        if ($scope === '') {
            $missing[] = 'scope';
        }

        return $missing;
    }

    /**
     * @param  array<int,string>  $evidenceRefs
     * @return array<int,array{id:string,status:string,reason:string}>
     */
    private function gates(string $flow, string $system, string $scope, array $evidenceRefs): array
    {
        return [
            $this->gate('operations_scope', $system !== '' && $scope !== '', 'Operations system and scope are required.'),
            $this->gate('diagnostic_only', true, 'Operations domain is diagnostic/review only.'),
            $this->gate('evidence_refs', $flow !== 'operations.readiness_review' || $evidenceRefs !== [], 'Readiness review requires evidence references.'),
            $this->gate('human_review_required', true, 'Operational actions require human review and separate receipt.'),
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
     * @param  array<string,mixed>  $packet
     */
    private function risk(array $packet): string
    {
        $risk = strtolower((string) data_get($packet, 'brief.risk_class', 'medium'));

        return in_array($risk, ['low', 'medium', 'high', 'critical'], true) ? $risk : 'medium';
    }

    private function string(mixed $value): string
    {
        return trim((string) $value);
    }

}
