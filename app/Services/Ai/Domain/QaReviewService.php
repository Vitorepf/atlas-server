<?php

namespace App\Services\Ai\Domain;

use App\Services\Ai\Kernel\Decision\DecisionReceiptIssuer;
use App\Services\Ai\Kernel\Envelope\OperationEnvelopeFactory;
use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;

class QaReviewService
{
    public const SCHEMA_VERSION = 'atlas.qa.packet.v1';

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
        $subject = $this->string($input['subject'] ?? $input['target'] ?? '');
        $scope = $this->string($input['scope'] ?? '');
        $changeSummary = $this->string($input['change_summary'] ?? $input['summary'] ?? '');
        $acceptanceCriteria = $this->list($input['acceptance_criteria'] ?? $input['criteria'] ?? []);
        $evidenceRefs = $this->list($input['evidence_refs'] ?? $input['evidence'] ?? []);
        $riskClass = $this->string($input['risk_class'] ?? 'medium');
        $constraints = $this->list($input['constraints'] ?? []);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => 'ok',
            'mode' => $this->mode($flow),
            'domain' => 'qa',
            'flow' => $flow,
            'brief' => [
                'subject' => $subject,
                'scope' => $scope,
                'change_summary' => $changeSummary,
                'acceptance_criteria' => $acceptanceCriteria,
                'evidence_refs' => $evidenceRefs,
                'risk_class' => $riskClass,
                'constraints' => $constraints,
                'missing_inputs' => $this->missingInputs($subject, $scope, $acceptanceCriteria),
            ],
            'qa_contract' => [
                'review_only' => true,
                'cross_domain' => true,
                'evidence_required' => true,
                'uncertainty_must_be_visible' => true,
                'domain_gates_cannot_be_overridden' => true,
            ],
            'output_contract' => $this->outputContract($flow),
            'gates' => $this->gates($flow, $subject, $scope, $acceptanceCriteria, $evidenceRefs),
            'rules' => [
                'review_only_until_operator_acceptance' => true,
                'does_not_execute_tests' => true,
                'does_not_deploy' => true,
                'does_not_override_domain_gates' => true,
                'blocks_release_readiness_without_evidence' => true,
            ],
            'forbidden_actions' => [
                'auto_deploy',
                'auto_mark_release_ready_without_evidence',
                'execute_tests_without_runtime_receipt',
                'override_domain_gate',
                'hide_uncertainty',
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
                'surface_id' => $this->string($input['surface_id'] ?? 'atlas_domain_orchestrator_qa') ?: 'atlas_domain_orchestrator_qa',
                'surface_version' => 'atlas.qa.v1',
                'session_id' => $this->string($input['session_id'] ?? ''),
            ],
            'input' => [
                'primary_type' => 'text',
                'text' => trim(data_get($packet, 'brief.subject', '')."\n".data_get($packet, 'brief.change_summary', '')),
                'hints' => [
                    'domain' => 'qa',
                    'flow' => $flow,
                    'mode' => $packet['mode'],
                    'audit_only' => true,
                ],
                'locale' => 'pt-BR',
            ],
        ]);

        $receipt = $this->receipts->issue($envelope, [
            'dry_run' => true,
            'signed_by' => 'atlas.qa.packet.v1',
            'domain' => 'qa',
            'flow' => $flow,
            'risk' => $this->risk($packet),
            'provider_selection' => [
                'primary' => 'none',
                'model' => 'not_applicable_qa_packet',
                'fallbacks' => [],
                'selection_mode' => 'auto_best_allowed',
                'selection_reason' => 'QA orchestrator emits a governed review packet; test execution belongs to a later runtime with its own receipt.',
            ],
            'budgets' => [
                'max_prompt_tokens' => 0,
                'max_output_tokens' => 0,
                'max_cost_usd' => 0,
            ],
            'required_gates' => $requiredGates,
            'required_evidence' => ['qa_packet', 'acceptance_criteria', 'evidence_refs', 'uncertainty_statement'],
            'repair_policy' => [
                'enabled' => true,
                'max_attempts' => 1,
                'strategy' => 'request_scope_acceptance_criteria_or_evidence',
            ],
            'metadata' => [
                'tenant_id' => $tenantId,
                'operator_id' => $operatorId,
                'review_only_until_operator_acceptance' => true,
                'does_not_execute_tests' => true,
                'packet_schema_version' => self::SCHEMA_VERSION,
            ],
        ])->toArray();

        $this->ledger->recordDecisionIssued($receipt, [
            'tenant_id' => $tenantId,
            'operator_id' => $operatorId,
            'trace_id' => $envelope->audit->traceId,
            'correlation_id' => $envelope->audit->traceId,
            'emitter_stage' => 'atlas.qa.packet',
            'emitter_version' => self::SCHEMA_VERSION,
        ]);

        $packed = $this->ledger->record(LedgerEventType::EvidencePacked, [
            'envelope_id' => $envelope->envelopeId,
            'receipt_id' => $receipt['receipt_id'],
            'domain' => 'qa',
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
            'emitter_stage' => 'atlas.qa.packet',
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
            'qa.acceptance_review' => 'acceptance_review',
            'qa.evidence_audit' => 'evidence_audit',
            'qa.release_readiness' => 'release_readiness_review',
            default => 'regression_review',
        };
    }

    /**
     * @return array<string,bool>
     */
    private function outputContract(string $flow): array
    {
        return match ($flow) {
            'qa.acceptance_review' => ['criteria_coverage' => true, 'missing_evidence' => true, 'decision_recommendation' => true],
            'qa.evidence_audit' => ['traceability_map' => true, 'evidence_gaps' => true, 'confidence_score' => true],
            'qa.release_readiness' => ['release_risk' => true, 'blocking_findings' => true, 'go_no_go_recommendation' => true],
            default => ['regression_matrix' => true, 'risk_map' => true, 'test_suggestions' => true],
        };
    }

    /**
     * @param  array<int,string>  $acceptanceCriteria
     * @return array<int,string>
     */
    private function missingInputs(string $subject, string $scope, array $acceptanceCriteria): array
    {
        $missing = [];

        if ($subject === '') {
            $missing[] = 'subject';
        }

        if ($scope === '') {
            $missing[] = 'scope';
        }

        if ($acceptanceCriteria === []) {
            $missing[] = 'acceptance_criteria';
        }

        return $missing;
    }

    /**
     * @param  array<int,string>  $acceptanceCriteria
     * @param  array<int,string>  $evidenceRefs
     * @return array<int,array{id:string,status:string,reason:string}>
     */
    private function gates(string $flow, string $subject, string $scope, array $acceptanceCriteria, array $evidenceRefs): array
    {
        return [
            $this->gate('qa_scope', $subject !== '' && $scope !== '', 'QA subject and scope are required.'),
            $this->gate('acceptance_criteria', $acceptanceCriteria !== [], 'Acceptance criteria must be explicit.'),
            $this->gate('evidence_refs', $flow !== 'qa.evidence_audit' || $evidenceRefs !== [], 'Evidence audit requires evidence references.'),
            $this->gate('risk_review', true, 'Every QA packet includes risk review.'),
            $this->gate('human_review_required', true, 'QA recommendations require human review before release or operational action.'),
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
