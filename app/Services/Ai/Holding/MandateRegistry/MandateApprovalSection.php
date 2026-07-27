<?php

namespace App\Services\Ai\Holding\MandateRegistry;

use App\Models\AiHoldingExternalActionMandate;
use App\Models\AiOperatorApproval;
use App\Services\Ai\Holding\ExternalActionMandateRegistryService;
use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\Support\DatabaseTableAvailability;
use App\Support\AtlasEnvelope;

/**
 * GOD-DEBULK method-family section extracted verbatim from
 * ExternalActionMandateRegistryService. Behavior frozen by
 * ExternalActionMandateRegistryServiceGoldenCharacterizationTest.
 */
class MandateApprovalSection
{
    public function __construct(private readonly MandateRegistryHub $hub) {}

    /**
     * @return array<string,mixed>
     */
    public function register(?string $companyId = null, ?string $flowId = null): array
    {
        $suite = $this->hub->fixtureSuite->externalActionMandateSuite($companyId);
        $packets = $this->packets($suite, $flowId);
        $records = [];

        foreach ($packets as $packet) {
            $records[] = $this->persistPacket($packet);
        }

        $blocked = count(array_filter(
            $records,
            static fn (array $record): bool => (bool) ($record['auto_execute_allowed'] ?? true)
                || (bool) ($record['external_side_effects_enabled'] ?? true),
        ));

        return AtlasEnvelope::seal([
            'ok' => (bool) ($suite['ok'] ?? false) && $records !== [] && $blocked === 0,
            'schema' => ExternalActionMandateRegistryService::REGISTRY_SCHEMA,
            'status' => $records !== [] && $blocked === 0 ? 'queued_for_operator_review' : 'attention',
            'generated_at' => now()->toJSON(),
            'source_mandate_suite_hash' => $suite['mandate_suite_hash'] ?? null,
            'summary' => [
                'registered_count' => count($records),
                'company_count' => count(array_unique(array_map(static fn (array $record): string => (string) $record['company_id'], $records))),
                'flow_count' => count(array_unique(array_map(static fn (array $record): string => (string) $record['flow_id'], $records))),
                'auto_execute_allowed_count' => $blocked,
                'external_side_effects_enabled_count' => $blocked,
                'operator_signature_required' => true,
                'second_reviewer_required' => true,
            ],
            'records' => $records,
            'registry_policy' => [
                'queue_status' => 'queued_for_operator_review',
                'allowed_next_actions' => ['operator_preflight_review', 'reject', 'request_scope_change', 'manual_signed_execution_outside_this_suite'],
                'blocked_next_actions' => ['auto_execute', 'external_write_without_signature', 'spend_without_budget_cap', 'trade_without_signed_mandate'],
                'external_execution_enabled_by_registry' => false,
            ],
        ], 'registry_hash');
    }

    /**
     * @return array<string,mixed>
     */
    public function preflight(string $mandatePacketHash): array
    {
        $hash = trim($mandatePacketHash);
        $record = $hash !== ''
            ? AiHoldingExternalActionMandate::query()->where('mandate_packet_hash', $hash)->first()
            : null;

        if (! $record instanceof AiHoldingExternalActionMandate) {
            return [
                'ok' => false,
                'schema' => ExternalActionMandateRegistryService::PREFLIGHT_SCHEMA,
                'status' => 'missing_mandate_packet',
                'mandate_packet_hash' => $hash,
                'external_execution_allowed' => false,
                'reasons' => ['mandate_packet_not_registered'],
            ];
        }

        $checks = $this->preflightChecks($record);
        $failed = array_values(array_filter(
            $checks,
            static fn (array $check): bool => ! (bool) ($check['passed'] ?? false),
        ));
        $record->forceFill([
            'status' => $failed === [] ? 'preflight_green_awaiting_signatures' : 'preflight_blocked',
            'preflighted_at' => now(),
        ])->save();

        return AtlasEnvelope::seal([
            'ok' => $failed === [],
            'schema' => ExternalActionMandateRegistryService::PREFLIGHT_SCHEMA,
            'status' => $failed === [] ? 'preflight_green_awaiting_signatures' : 'preflight_blocked',
            'generated_at' => now()->toJSON(),
            'company_id' => $record->company_id,
            'flow_id' => $record->flow_id,
            'mandate_packet_hash' => $record->mandate_packet_hash,
            'external_execution_allowed' => false,
            'external_execution_blocker' => 'operator_and_second_reviewer_signatures_required_outside_this_suite',
            'checks' => $checks,
            'failed_checks' => $failed,
            'record' => $this->recordPayload($record->refresh()),
        ], 'preflight_hash');
    }

    /**
     * @return array<string,mixed>
     */
    public function requestApproval(string $mandatePacketHash): array
    {
        $record = $this->mandateRecord($mandatePacketHash);
        if (! $record instanceof AiHoldingExternalActionMandate) {
            return $this->missingMandatePayload(ExternalActionMandateRegistryService::APPROVAL_REQUEST_SCHEMA, $mandatePacketHash);
        }

        if ($record->status !== 'preflight_green_awaiting_signatures') {
            $preflight = $this->preflight($record->mandate_packet_hash);
            if (! (bool) ($preflight['ok'] ?? false)) {
                return [
                    'ok' => false,
                    'schema' => ExternalActionMandateRegistryService::APPROVAL_REQUEST_SCHEMA,
                    'status' => 'preflight_required_before_approval',
                    'mandate_packet_hash' => $record->mandate_packet_hash,
                    'external_execution_allowed' => false,
                    'preflight' => $preflight,
                ];
            }
            $record = $record->refresh();
        }

        $approvals = [
            $this->createApproval($record, 'operator_signature'),
            $this->createApproval($record, 'second_reviewer_signature'),
        ];
        $record->forceFill(['status' => 'awaiting_operator_and_reviewer_signatures'])->save();

        return AtlasEnvelope::seal([
            'ok' => true,
            'schema' => ExternalActionMandateRegistryService::APPROVAL_REQUEST_SCHEMA,
            'status' => 'awaiting_operator_and_reviewer_signatures',
            'generated_at' => now()->toJSON(),
            'company_id' => $record->company_id,
            'flow_id' => $record->flow_id,
            'mandate_packet_hash' => $record->mandate_packet_hash,
            'external_execution_allowed' => false,
            'approval_count' => count($approvals),
            'approvals' => $approvals,
            'required_roles' => ['operator_signature', 'second_reviewer_signature'],
        ], 'approval_request_hash');
    }

    /**
     * @return array<string,mixed>
     */
    public function decideApproval(string $approvalUuid, string $decision, string $operator, ?string $note = null): array
    {
        $normalizedDecision = trim(strtolower($decision));
        if (! in_array($normalizedDecision, ['approved', 'rejected'], true)) {
            return [
                'ok' => false,
                'schema' => ExternalActionMandateRegistryService::APPROVAL_DECISION_SCHEMA,
                'status' => 'invalid_decision',
                'allowed_decisions' => ['approved', 'rejected'],
                'external_execution_allowed' => false,
            ];
        }

        $approval = AiOperatorApproval::query()->where('uuid', trim($approvalUuid))->first();
        if (! $approval instanceof AiOperatorApproval) {
            return [
                'ok' => false,
                'schema' => ExternalActionMandateRegistryService::APPROVAL_DECISION_SCHEMA,
                'status' => 'approval_not_found',
                'approval_uuid' => trim($approvalUuid),
                'external_execution_allowed' => false,
            ];
        }

        $mandateHash = (string) data_get($approval->options, 'mandate_packet_hash', '');
        $record = $this->mandateRecord($mandateHash);
        if (! $record instanceof AiHoldingExternalActionMandate) {
            return $this->missingMandatePayload(ExternalActionMandateRegistryService::APPROVAL_DECISION_SCHEMA, $mandateHash);
        }

        $approval->forceFill([
            'status' => $normalizedDecision,
            'operator_decision' => $normalizedDecision,
            'operator' => trim($operator) !== '' ? trim($operator) : 'atlas_operator',
            'operator_note' => $note,
            'decided_at' => now(),
            'receipt_hash' => hash('sha256', 'holding_external_action_approval_decision|'.$approval->uuid.'|'.$normalizedDecision),
        ])->save();

        $status = $this->approvalStatus($record->mandate_packet_hash);
        $record->forceFill(['status' => (string) ($status['mandate_status'] ?? $record->status)])->save();

        return AtlasEnvelope::seal([
            'ok' => true,
            'schema' => ExternalActionMandateRegistryService::APPROVAL_DECISION_SCHEMA,
            'status' => $normalizedDecision,
            'generated_at' => now()->toJSON(),
            'approval_uuid' => $approval->uuid,
            'approval_role' => (string) data_get($approval->options, 'approval_role', 'unknown'),
            'operator_decision' => $normalizedDecision,
            'mandate_packet_hash' => $record->mandate_packet_hash,
            'mandate_status' => (string) ($status['mandate_status'] ?? $record->status),
            'external_execution_allowed' => false,
            'approval_status' => $status,
        ], 'approval_decision_hash');
    }

    /**
     * @return array<string,mixed>
     */
    public function approvalStatus(string $mandatePacketHash): array
    {
        $record = $this->mandateRecord($mandatePacketHash);
        if (! $record instanceof AiHoldingExternalActionMandate) {
            return $this->missingMandatePayload(ExternalActionMandateRegistryService::APPROVAL_STATUS_SCHEMA, $mandatePacketHash);
        }

        $approvals = $this->approvalRows($record->mandate_packet_hash);
        $operatorApproved = $this->roleApproved($approvals, 'operator_signature');
        $reviewerApproved = $this->roleApproved($approvals, 'second_reviewer_signature');
        $rejected = count(array_filter(
            $approvals,
            static fn (AiOperatorApproval $approval): bool => $approval->status === 'rejected'
                || $approval->operator_decision === 'rejected',
        ));
        $mandateStatus = $rejected > 0
            ? 'rejected_by_operator_gate'
            : ($operatorApproved && $reviewerApproved
                ? 'signed_mandate_ready_manual_execution_only'
                : ($approvals === [] ? (string) $record->status : 'awaiting_operator_and_reviewer_signatures'));

        return AtlasEnvelope::seal([
            'ok' => true,
            'schema' => ExternalActionMandateRegistryService::APPROVAL_STATUS_SCHEMA,
            'status' => $mandateStatus,
            'mandate_status' => $mandateStatus,
            'generated_at' => now()->toJSON(),
            'company_id' => $record->company_id,
            'flow_id' => $record->flow_id,
            'mandate_packet_hash' => $record->mandate_packet_hash,
            'approval_count' => count($approvals),
            'operator_approved' => $operatorApproved,
            'second_reviewer_approved' => $reviewerApproved,
            'rejected_count' => $rejected,
            'external_execution_allowed' => false,
            'external_execution_blocker' => 'manual_execution_only_even_after_signatures',
            'approvals' => array_map(fn (AiOperatorApproval $approval): array => $this->approvalPayload($approval), $approvals),
            'record' => $this->recordPayload($record),
        ], 'approval_status_hash');
    }

    /**
     * @param array<string,mixed> $suite
     * @return list<array<string,mixed>>
     */
    public function packets(array $suite, ?string $flowId): array
    {
        $wantedFlow = $flowId !== null && trim($flowId) !== '' ? trim($flowId) : null;
        $packets = [];

        foreach ((array) ($suite['companies'] ?? []) as $company) {
            foreach ((array) ($company['mandate_packets'] ?? []) as $packet) {
                if ($wantedFlow !== null && ($packet['flow_id'] ?? null) !== $wantedFlow) {
                    continue;
                }

                $packets[] = (array) $packet;
            }
        }

        return $packets;
    }

    /**
     * @param array<string,mixed> $packet
     * @return array<string,mixed>
     */
    public function persistPacket(array $packet): array
    {
        $record = AiHoldingExternalActionMandate::query()->updateOrCreate(
            ['mandate_packet_hash' => (string) ($packet['mandate_packet_hash'] ?? '')],
            [
                'company_id' => (string) ($packet['company_id'] ?? 'unknown'),
                'flow_id' => (string) ($packet['flow_id'] ?? 'unknown'),
                'status' => 'queued_for_operator_review',
                'source_runtime_receipt_hash' => (string) ($packet['source_runtime_receipt_hash'] ?? ''),
                'source_connector_certification_hash' => (string) ($packet['source_connector_certification_hash'] ?? ''),
                'source_flow_usage_attestation_hash' => (string) ($packet['source_flow_usage_attestation_hash'] ?? ''),
                'connector_scope_json' => array_values((array) ($packet['connector_scope'] ?? [])),
                'blocked_operations_json' => array_values((array) ($packet['blocked_operations_until_signed_mandate'] ?? [])),
                'preflight_checks_json' => array_values((array) ($packet['preflight_checks'] ?? [])),
                'risk_controls_json' => (array) ($packet['risk_controls'] ?? []),
                'rollback_or_compensation_json' => (array) ($packet['rollback_or_compensation_plan'] ?? []),
                'incident_route_json' => (array) ($packet['incident_route'] ?? []),
                'cost_budget_envelope_json' => (array) ($packet['cost_budget_envelope'] ?? []),
                'operator_signature_required' => (bool) ($packet['operator_signature_required'] ?? true),
                'second_reviewer_required' => (bool) ($packet['second_reviewer_required'] ?? true),
                'auto_execute_allowed' => (bool) ($packet['auto_execute_allowed'] ?? false),
                'external_side_effects_enabled' => (bool) ($packet['external_side_effects_enabled'] ?? false),
                'packet_json' => $packet,
                'queued_at' => now(),
            ],
        );

        return $this->recordPayload($record);
    }

    public function mandateRecord(string $mandatePacketHash): ?AiHoldingExternalActionMandate
    {
        $hash = trim($mandatePacketHash);

        return $hash !== ''
            ? AiHoldingExternalActionMandate::query()->where('mandate_packet_hash', $hash)->first()
            : null;
    }

    /**
     * @return array<string,mixed>
     */
    public function missingMandatePayload(string $schema, string $mandatePacketHash): array
    {
        return [
            'ok' => false,
            'schema' => $schema,
            'status' => 'missing_mandate_packet',
            'mandate_packet_hash' => trim($mandatePacketHash),
            'external_execution_allowed' => false,
            'reasons' => ['mandate_packet_not_registered'],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function createApproval(AiHoldingExternalActionMandate $record, string $role): array
    {
        $uuid = hash('sha256', 'holding_external_action_approval|'.$record->mandate_packet_hash.'|'.$role);
        $options = [
            'schema' => 'atlas.ai.holding.external_action_approval_options.v1',
            'mandate_packet_hash' => $record->mandate_packet_hash,
            'approval_role' => $role,
            'company_id' => $record->company_id,
            'flow_id' => $record->flow_id,
            'connector_scope' => array_values((array) $record->connector_scope_json),
            'blocked_operations' => array_values((array) $record->blocked_operations_json),
            'external_execution_allowed_after_approval' => false,
            'manual_execution_handoff_only' => true,
        ];
        $approval = AiOperatorApproval::query()->updateOrCreate(
            ['uuid' => $uuid],
            [
                'schema_version' => 'atlas.ai.operator_approval.v1',
                'requested_action' => 'holding.external_action.'.$record->company_id.'.'.$record->flow_id,
                'risk_level' => 'high',
                'gate_mode' => 'holding_external_action_mandate',
                'approval_required' => true,
                'reason' => 'External action mandate requires '.$role.' before manual handoff.',
                'options' => $options,
                'status' => 'pending',
                'operator_decision' => null,
                'operator' => null,
                'operator_note' => null,
                'expires_at' => now()->addDay(),
                'decided_at' => null,
                'consumed_at' => null,
                'evidence_refs' => [
                    'mandate_packet_hash:'.$record->mandate_packet_hash,
                    'runtime_receipt_hash:'.$record->source_runtime_receipt_hash,
                    'connector_certification_hash:'.$record->source_connector_certification_hash,
                ],
                'receipt_hash' => null,
                'hash' => MissionCanonicalHash::sha256([
                    'uuid' => $uuid,
                    'mandate_packet_hash' => $record->mandate_packet_hash,
                    'role' => $role,
                ]),
            ],
        );

        return $this->approvalPayload($approval);
    }

    /**
     * @return list<AiOperatorApproval>
     */
    public function approvalRows(string $mandatePacketHash): array
    {
        return AiOperatorApproval::query()
            ->where('gate_mode', 'holding_external_action_mandate')
            ->get()
            ->filter(static fn (AiOperatorApproval $approval): bool => data_get($approval->options, 'mandate_packet_hash') === $mandatePacketHash)
            ->values()
            ->all();
    }

    /**
     * @param list<AiOperatorApproval> $approvals
     */
    public function roleApproved(array $approvals, string $role): bool
    {
        foreach ($approvals as $approval) {
            if (data_get($approval->options, 'approval_role') === $role
                && $approval->status === 'approved'
                && $approval->operator_decision === 'approved') {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string,mixed>
     */
    public function approvalPayload(AiOperatorApproval $approval): array
    {
        return [
            'uuid' => (string) $approval->uuid,
            'status' => (string) $approval->status,
            'approval_role' => (string) data_get($approval->options, 'approval_role', 'unknown'),
            'requested_action' => (string) $approval->requested_action,
            'risk_level' => (string) $approval->risk_level,
            'operator_decision' => $approval->operator_decision,
            'operator' => $approval->operator,
            'operator_note' => $approval->operator_note,
            'expires_at' => $approval->expires_at?->toJSON(),
            'decided_at' => $approval->decided_at?->toJSON(),
            'receipt_hash' => $approval->receipt_hash,
            'external_execution_allowed_after_approval' => (bool) data_get($approval->options, 'external_execution_allowed_after_approval', false),
            'manual_execution_handoff_only' => (bool) data_get($approval->options, 'manual_execution_handoff_only', true),
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function preflightChecks(AiHoldingExternalActionMandate $record): array
    {
        $connectorScope = (array) $record->connector_scope_json;
        $blocked = (array) $record->blocked_operations_json;
        $preflightChecks = (array) $record->preflight_checks_json;

        return [
            [
                'id' => 'source_runtime_receipt_present',
                'passed' => strlen((string) $record->source_runtime_receipt_hash) === 64,
            ],
            [
                'id' => 'source_connector_certification_present',
                'passed' => strlen((string) $record->source_connector_certification_hash) === 64,
            ],
            [
                'id' => 'connector_scope_bound',
                'passed' => $connectorScope !== [],
            ],
            [
                'id' => 'dangerous_modes_blocked_until_signature',
                'passed' => count(array_intersect(['write', 'publish', 'spend', 'trade', 'deploy', 'delete'], $blocked)) >= 6,
            ],
            [
                'id' => 'operator_signature_required',
                'passed' => (bool) $record->operator_signature_required,
            ],
            [
                'id' => 'second_reviewer_required',
                'passed' => (bool) $record->second_reviewer_required,
            ],
            [
                'id' => 'auto_execute_disabled',
                'passed' => ! (bool) $record->auto_execute_allowed,
            ],
            [
                'id' => 'external_side_effects_disabled',
                'passed' => ! (bool) $record->external_side_effects_enabled,
            ],
            [
                'id' => 'budget_cap_required',
                'passed' => (bool) data_get($record->cost_budget_envelope_json, 'required', false)
                    && ! (bool) data_get($record->cost_budget_envelope_json, 'spend_without_cap_allowed', true),
            ],
            [
                'id' => 'rollback_and_incident_route_bound',
                'passed' => data_get($record->rollback_or_compensation_json, 'plan_hash') !== null
                    && data_get($record->incident_route_json, 'route_hash') !== null,
            ],
            [
                'id' => 'preflight_contract_complete',
                'passed' => count(array_intersect([
                    'operator_mandate_signature_present',
                    'second_reviewer_signature_present',
                    'connector_scope_matches_certification',
                    'budget_or_loss_cap_bound',
                    'rollback_or_compensation_accepted',
                    'incident_route_bound',
                    'dry_run_receipts_attached',
                ], $preflightChecks)) === 7,
            ],
        ];
    }

    public function toolRunsTableAvailable(): bool
    {
        return DatabaseTableAvailability::has('atlas_tool_runs');
    }

    /**
     * @return array<string,mixed>
     */
    public function recordPayload(AiHoldingExternalActionMandate $record): array
    {
        return [
            'id' => (string) $record->id,
            'company_id' => (string) $record->company_id,
            'flow_id' => (string) $record->flow_id,
            'status' => (string) $record->status,
            'mandate_packet_hash' => (string) $record->mandate_packet_hash,
            'source_runtime_receipt_hash' => (string) $record->source_runtime_receipt_hash,
            'source_connector_certification_hash' => (string) $record->source_connector_certification_hash,
            'source_flow_usage_attestation_hash' => (string) $record->source_flow_usage_attestation_hash,
            'connector_scope' => array_values((array) $record->connector_scope_json),
            'blocked_operations' => array_values((array) $record->blocked_operations_json),
            'preflight_checks' => array_values((array) $record->preflight_checks_json),
            'operator_signature_required' => (bool) $record->operator_signature_required,
            'second_reviewer_required' => (bool) $record->second_reviewer_required,
            'auto_execute_allowed' => (bool) $record->auto_execute_allowed,
            'external_side_effects_enabled' => (bool) $record->external_side_effects_enabled,
            'queued_at' => $record->queued_at?->toJSON(),
            'preflighted_at' => $record->preflighted_at?->toJSON(),
        ];
    }
}
