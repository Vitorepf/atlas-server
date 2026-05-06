<?php

namespace App\Services\Ai\Domain;

use App\Services\Ai\Kernel\Decision\DecisionReceiptIssuer;
use App\Services\Ai\Kernel\Envelope\OperationEnvelopeFactory;
use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;

class BackgroundSafetyService
{
    public const SCHEMA_VERSION = 'atlas.background.packet.v1';

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
        $job = $this->string($input['job'] ?? $input['subject'] ?? '');
        $scope = $this->string($input['scope'] ?? '');
        $trigger = $this->string($input['trigger'] ?? '');
        $schedule = $this->string($input['schedule'] ?? '');
        $permissions = $this->list($input['permissions'] ?? []);
        $evidenceRefs = $this->list($input['evidence_refs'] ?? $input['evidence'] ?? []);
        $riskClass = $this->string($input['risk_class'] ?? 'medium');
        $constraints = $this->list($input['constraints'] ?? []);
        $stopConditions = $this->list($input['stop_conditions'] ?? []);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => 'ok',
            'mode' => $this->mode($flow),
            'domain' => 'background',
            'flow' => $flow,
            'brief' => [
                'job' => $job,
                'scope' => $scope,
                'trigger' => $trigger,
                'schedule' => $schedule,
                'permissions' => $permissions,
                'evidence_refs' => $evidenceRefs,
                'risk_class' => $riskClass,
                'constraints' => $constraints,
                'stop_conditions' => $stopConditions,
                'missing_inputs' => $this->missingInputs($job, $scope, $stopConditions),
            ],
            'background_contract' => [
                'background_review_only' => true,
                'no_autonomous_mutation' => true,
                'requires_explicit_policy' => true,
                'heartbeat_or_cron_must_be_declared' => true,
                'no_unbounded_background_work' => true,
                'background_execution_requires_separate_receipt' => true,
            ],
            'output_contract' => $this->outputContract($flow),
            'gates' => $this->gates($flow, $job, $scope, $schedule, $permissions, $stopConditions),
            'rules' => [
                'review_only_until_operator_acceptance' => true,
                'does_not_start_jobs' => true,
                'does_not_change_schedule' => true,
                'does_not_escalate_permissions' => true,
                'requires_stop_condition' => true,
            ],
            'forbidden_actions' => [
                'start_background_job',
                'change_schedule',
                'escalate_permissions',
                'run_unbounded_loop',
                'bypass_operator_review',
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
                'surface_id' => $this->string($input['surface_id'] ?? 'atlas_domain_orchestrator_background') ?: 'atlas_domain_orchestrator_background',
                'surface_version' => 'atlas.background.v1',
                'session_id' => $this->string($input['session_id'] ?? ''),
            ],
            'input' => [
                'primary_type' => 'text',
                'text' => trim(data_get($packet, 'brief.job', '')."\n".data_get($packet, 'brief.scope', '')),
                'hints' => [
                    'domain' => 'background',
                    'flow' => $flow,
                    'mode' => $packet['mode'],
                    'audit_only' => true,
                ],
                'locale' => 'pt-BR',
            ],
        ]);

        $receipt = $this->receipts->issue($envelope, [
            'dry_run' => true,
            'signed_by' => 'atlas.background.packet.v1',
            'domain' => 'background',
            'flow' => $flow,
            'risk' => $this->risk($packet),
            'provider_selection' => [
                'primary' => 'none',
                'model' => 'not_applicable_background_packet',
                'fallbacks' => [],
                'selection_mode' => 'auto_best_allowed',
                'selection_reason' => 'Background orchestrator emits a governed safety packet; background execution requires a separate runtime receipt.',
            ],
            'budgets' => [
                'max_prompt_tokens' => 0,
                'max_output_tokens' => 0,
                'max_cost_usd' => 0,
            ],
            'required_gates' => $requiredGates,
            'required_evidence' => ['background_packet', 'scope_boundary', 'permission_review', 'stop_conditions', 'human_review_required'],
            'repair_policy' => [
                'enabled' => true,
                'max_attempts' => 1,
                'strategy' => 'request_job_scope_schedule_permissions_and_stop_conditions',
            ],
            'metadata' => [
                'tenant_id' => $tenantId,
                'operator_id' => $operatorId,
                'review_only_until_operator_acceptance' => true,
                'background_execution_requires_separate_receipt' => true,
                'packet_schema_version' => self::SCHEMA_VERSION,
            ],
        ])->toArray();

        $this->ledger->recordDecisionIssued($receipt, [
            'tenant_id' => $tenantId,
            'operator_id' => $operatorId,
            'trace_id' => $envelope->audit->traceId,
            'correlation_id' => $envelope->audit->traceId,
            'emitter_stage' => 'atlas.background.packet',
            'emitter_version' => self::SCHEMA_VERSION,
        ]);

        $packed = $this->ledger->record(LedgerEventType::EvidencePacked, [
            'envelope_id' => $envelope->envelopeId,
            'receipt_id' => $receipt['receipt_id'],
            'domain' => 'background',
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
            'emitter_stage' => 'atlas.background.packet',
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
            'background.readiness_review' => 'readiness_review',
            'background.schedule_review' => 'schedule_review',
            'background.permission_review' => 'permission_review',
            default => 'safe_review',
        };
    }

    /**
     * @return array<string,bool>
     */
    private function outputContract(string $flow): array
    {
        return match ($flow) {
            'background.readiness_review' => ['readiness_score' => true, 'missing_controls' => true, 'approval_requirements' => true],
            'background.schedule_review' => ['schedule_risk' => true, 'cadence_review' => true, 'stop_conditions' => true],
            'background.permission_review' => ['permission_diff' => true, 'least_privilege_recommendation' => true, 'operator_approval_needed' => true],
            default => ['safety_decision' => true, 'permission_summary' => true, 'required_review' => true],
        };
    }

    /**
     * @param  array<int,string>  $stopConditions
     * @return array<int,string>
     */
    private function missingInputs(string $job, string $scope, array $stopConditions): array
    {
        $missing = [];

        if ($job === '') {
            $missing[] = 'job';
        }

        if ($scope === '') {
            $missing[] = 'scope';
        }

        if ($stopConditions === []) {
            $missing[] = 'stop_conditions';
        }

        return $missing;
    }

    /**
     * @param  array<int,string>  $permissions
     * @param  array<int,string>  $stopConditions
     * @return array<int,array{id:string,status:string,reason:string}>
     */
    private function gates(string $flow, string $job, string $scope, string $schedule, array $permissions, array $stopConditions): array
    {
        return [
            $this->gate('background_scope', $job !== '' && $scope !== '', 'Background job and scope are required.'),
            $this->gate('review_only', true, 'Background domain is review-only and cannot start jobs.'),
            $this->gate('stop_conditions', $stopConditions !== [], 'Background work must declare stop conditions.'),
            $this->gate('schedule_declared', $flow !== 'background.schedule_review' || $schedule !== '', 'Schedule review requires an explicit schedule.'),
            $this->gate('permission_review', $flow !== 'background.permission_review' || $permissions !== [], 'Permission review requires declared permissions.'),
            $this->gate('human_review_required', true, 'Background execution requires human review and separate receipt.'),
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
