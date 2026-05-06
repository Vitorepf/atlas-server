<?php

namespace App\Services\Ai\Domain;

use App\Services\Ai\Kernel\Decision\DecisionReceiptIssuer;
use App\Services\Ai\Kernel\Envelope\OperationEnvelopeFactory;
use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;

class SecurityReviewService
{
    public const SCHEMA_VERSION = 'atlas.security.packet.v1';

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
        $asset = $this->string($input['asset'] ?? $input['subject'] ?? '');
        $scope = $this->string($input['scope'] ?? '');
        $threatModel = $this->list($input['threat_model'] ?? $input['threats'] ?? []);
        $controls = $this->list($input['controls'] ?? []);
        $evidenceRefs = $this->list($input['evidence_refs'] ?? $input['evidence'] ?? []);
        $riskClass = $this->string($input['risk_class'] ?? 'medium');
        $constraints = $this->list($input['constraints'] ?? []);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => 'ok',
            'mode' => $this->mode($flow),
            'domain' => 'security',
            'flow' => $flow,
            'brief' => [
                'asset' => $asset,
                'scope' => $scope,
                'threat_model' => $threatModel,
                'controls' => $controls,
                'evidence_refs' => $evidenceRefs,
                'risk_class' => $riskClass,
                'constraints' => $constraints,
                'missing_inputs' => $this->missingInputs($asset, $scope),
            ],
            'security_contract' => [
                'review_only' => true,
                'defensive_only' => true,
                'no_exploit_execution' => true,
                'no_secret_exfiltration' => true,
                'security_domain_is_not_programming_security_harness' => true,
            ],
            'output_contract' => $this->outputContract($flow),
            'gates' => $this->gates($flow, $asset, $scope, $evidenceRefs),
            'rules' => [
                'review_only_until_operator_acceptance' => true,
                'does_not_scan_networks' => true,
                'does_not_access_credentials' => true,
                'does_not_execute_exploits' => true,
                'requires_human_review_for_high_or_critical' => true,
            ],
            'forbidden_actions' => [
                'execute_exploit',
                'scan_external_network',
                'access_or_print_secrets',
                'disable_security_control',
                'claim_compliance_without_evidence',
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
                'surface_id' => $this->string($input['surface_id'] ?? 'atlas_domain_orchestrator_security') ?: 'atlas_domain_orchestrator_security',
                'surface_version' => 'atlas.security.v1',
                'session_id' => $this->string($input['session_id'] ?? ''),
            ],
            'input' => [
                'primary_type' => 'text',
                'text' => trim(data_get($packet, 'brief.asset', '')."\n".data_get($packet, 'brief.scope', '')),
                'hints' => [
                    'domain' => 'security',
                    'flow' => $flow,
                    'mode' => $packet['mode'],
                    'audit_only' => true,
                ],
                'locale' => 'pt-BR',
            ],
        ]);

        $receipt = $this->receipts->issue($envelope, [
            'dry_run' => true,
            'signed_by' => 'atlas.security.packet.v1',
            'domain' => 'security',
            'flow' => $flow,
            'risk' => $this->risk($packet),
            'provider_selection' => [
                'primary' => 'none',
                'model' => 'not_applicable_security_packet',
                'fallbacks' => [],
                'selection_mode' => 'auto_best_allowed',
                'selection_reason' => 'Security orchestrator emits a governed defensive review packet; active scanning or code security harness requires separate runtime receipt.',
            ],
            'budgets' => [
                'max_prompt_tokens' => 0,
                'max_output_tokens' => 0,
                'max_cost_usd' => 0,
            ],
            'required_gates' => $requiredGates,
            'required_evidence' => ['security_packet', 'scope_boundary', 'defensive_only', 'evidence_refs'],
            'repair_policy' => [
                'enabled' => true,
                'max_attempts' => 1,
                'strategy' => 'request_asset_scope_controls_or_evidence',
            ],
            'metadata' => [
                'tenant_id' => $tenantId,
                'operator_id' => $operatorId,
                'review_only_until_operator_acceptance' => true,
                'defensive_only' => true,
                'packet_schema_version' => self::SCHEMA_VERSION,
            ],
        ])->toArray();

        $this->ledger->recordDecisionIssued($receipt, [
            'tenant_id' => $tenantId,
            'operator_id' => $operatorId,
            'trace_id' => $envelope->audit->traceId,
            'correlation_id' => $envelope->audit->traceId,
            'emitter_stage' => 'atlas.security.packet',
            'emitter_version' => self::SCHEMA_VERSION,
        ]);

        $packed = $this->ledger->record(LedgerEventType::EvidencePacked, [
            'envelope_id' => $envelope->envelopeId,
            'receipt_id' => $receipt['receipt_id'],
            'domain' => 'security',
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
            'emitter_stage' => 'atlas.security.packet',
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
            'security.privacy_review' => 'privacy_review',
            'security.compliance_review' => 'compliance_review',
            'security.incident_review' => 'incident_review',
            default => 'threat_review',
        };
    }

    /**
     * @return array<string,bool>
     */
    private function outputContract(string $flow): array
    {
        return match ($flow) {
            'security.privacy_review' => ['data_classification' => true, 'privacy_risks' => true, 'redaction_plan' => true],
            'security.compliance_review' => ['control_mapping' => true, 'missing_evidence' => true, 'compliance_risk' => true],
            'security.incident_review' => ['incident_timeline' => true, 'containment_questions' => true, 'postmortem_actions' => true],
            default => ['threat_model' => true, 'risk_register' => true, 'mitigation_plan' => true],
        };
    }

    /**
     * @return array<int,string>
     */
    private function missingInputs(string $asset, string $scope): array
    {
        $missing = [];

        if ($asset === '') {
            $missing[] = 'asset';
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
    private function gates(string $flow, string $asset, string $scope, array $evidenceRefs): array
    {
        return [
            $this->gate('security_scope', $asset !== '' && $scope !== '', 'Security asset and scope are required.'),
            $this->gate('defensive_only', true, 'Security domain is defensive review only.'),
            $this->gate('evidence_refs', $flow !== 'security.compliance_review' || $evidenceRefs !== [], 'Compliance review requires evidence references.'),
            $this->gate('human_review_required', true, 'Security recommendations require human review before operational action.'),
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
