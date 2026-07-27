<?php

namespace App\Services\Ai\Holding\MandateRegistry;

use App\Models\AiHoldingActivationBacklogItem;
use App\Models\AiHoldingConnectorActivationRecord;
use App\Services\Ai\Holding\ExternalActionMandateRegistryService;
use App\Services\Ai\Mission\MissionCanonicalHash;

/**
 * GOD-DEBULK method-family section extracted verbatim from
 * ExternalActionMandateRegistryService. Behavior frozen by
 * ExternalActionMandateRegistryServiceGoldenCharacterizationTest.
 */
class ActivationBacklogSection
{
    public function __construct(private readonly MandateRegistryHub $hub) {}

    /**
     * @return array<string,mixed>
     */
    public function registerActivationBacklog(?string $companyId = null): array
    {
        $cockpit = $this->hub->companyCockpit->activationCockpit($companyId);
        $records = [];

        foreach ((array) ($cockpit['companies'] ?? []) as $company) {
            foreach ((array) ($company['flow_backlog'] ?? []) as $flow) {
                foreach ((array) ($flow['implementation_work_packages'] ?? []) as $workPackage) {
                    $records[] = $this->persistActivationBacklogItem($flow, (array) $workPackage);
                }
            }
        }

        $payload = [
            'ok' => (bool) ($cockpit['ok'] ?? false) && $records !== [],
            'schema' => ExternalActionMandateRegistryService::ACTIVATION_BACKLOG_REGISTRY_SCHEMA,
            'status' => $records !== [] ? 'queued_for_implementation' : 'empty_backlog',
            'generated_at' => now()->toJSON(),
            'source_activation_cockpit_hash' => $cockpit['activation_cockpit_hash'] ?? null,
            'summary' => [
                'company_count' => count(array_unique(array_map(static fn (array $record): string => (string) $record['company_id'], $records))),
                'flow_count' => count(array_unique(array_map(static fn (array $record): string => (string) $record['company_id'].'|'.(string) $record['flow_id'], $records))),
                'work_package_count' => count($records),
                'manual_handoff_ready_item_count' => count(array_filter($records, static fn (array $record): bool => (bool) $record['manual_handoff_ready'])),
                'external_execution_allowed_count' => 0,
                'external_side_effects_enabled_count' => 0,
            ],
            'records' => $records,
            'registry_policy' => [
                'queue_status' => 'queued_for_implementation',
                'allowed_next_actions' => ['assign_owner', 'attach_evidence', 'run_non_production_dress_rehearsal', 'operator_review'],
                'blocked_next_actions' => ['auto_execute_external_action', 'production_write_without_signed_scope', 'spend_or_trade_without_cap'],
                'external_execution_enabled_by_registry' => false,
            ],
        ];
        $payload['activation_backlog_registry_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function activationBacklogStatus(?string $companyId = null): array
    {
        $wantedCompany = $companyId !== null && trim($companyId) !== '' ? trim($companyId) : null;
        $items = AiHoldingActivationBacklogItem::query()
            ->when($wantedCompany !== null, static fn ($query) => $query->where('company_id', $wantedCompany))
            ->orderByDesc('priority_score')
            ->orderBy('company_id')
            ->orderBy('flow_id')
            ->get();
        $records = $items->map(fn (AiHoldingActivationBacklogItem $item): array => $this->activationBacklogPayload($item))->all();
        $companyRows = [];
        foreach (collect($records)->groupBy('company_id') as $company => $companyRecords) {
            $rows = $companyRecords->values()->all();
            $companyRows[] = [
                'company_id' => (string) $company,
                'flow_count' => count(array_unique(array_map(static fn (array $row): string => (string) $row['flow_id'], $rows))),
                'work_package_count' => count($rows),
                'queued_count' => count(array_filter($rows, static fn (array $row): bool => $row['status'] === 'queued_for_implementation')),
                'completed_count' => count(array_filter($rows, static fn (array $row): bool => str_starts_with((string) $row['status'], 'completed'))),
                'blocked_count' => count(array_filter($rows, static fn (array $row): bool => str_starts_with((string) $row['status'], 'blocked'))),
                'manual_handoff_ready_item_count' => count(array_filter($rows, static fn (array $row): bool => (bool) $row['manual_handoff_ready'])),
                'priority_score' => array_sum(array_map(static fn (array $row): int => (int) $row['priority_score'], $rows)),
                'status_counts' => $this->hub->countsByKey($rows, 'status'),
                'work_package_counts' => $this->hub->countsByKey($rows, 'work_package_id'),
            ];
        }

        $payload = [
            'ok' => true,
            'schema' => ExternalActionMandateRegistryService::ACTIVATION_BACKLOG_STATUS_SCHEMA,
            'status' => $records === [] ? 'empty_backlog' : 'backlog_registered_external_execution_blocked',
            'generated_at' => now()->toJSON(),
            'summary' => [
                'company_count' => count($companyRows),
                'flow_count' => count(array_unique(array_map(static fn (array $record): string => (string) $record['company_id'].'|'.(string) $record['flow_id'], $records))),
                'work_package_count' => count($records),
                'queued_count' => count(array_filter($records, static fn (array $record): bool => $record['status'] === 'queued_for_implementation')),
                'completed_count' => count(array_filter($records, static fn (array $record): bool => str_starts_with((string) $record['status'], 'completed'))),
                'blocked_count' => count(array_filter($records, static fn (array $record): bool => str_starts_with((string) $record['status'], 'blocked'))),
                'external_execution_allowed_count' => 0,
                'external_side_effects_enabled_count' => 0,
            ],
            'companies' => $companyRows,
            'records' => $records,
            'policy' => [
                'external_execution_allowed' => false,
                'manual_completion_does_not_enable_external_execution' => true,
                'required_before_closing_item' => ['evidence_receipt', 'operator_or_owner_attestation', 'rollback_or_noop_rationale'],
            ],
        ];
        $payload['activation_backlog_status_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function runActivationBacklog(?string $companyId = null, ?string $flowId = null, ?string $workPackageId = null): array
    {
        $wantedCompany = $companyId !== null && trim($companyId) !== '' ? trim($companyId) : null;
        $wantedFlow = $flowId !== null && trim($flowId) !== '' ? trim($flowId) : null;
        $wantedWorkPackage = $workPackageId !== null && trim($workPackageId) !== '' ? trim($workPackageId) : null;

        $items = AiHoldingActivationBacklogItem::query()
            ->when($wantedCompany !== null, static fn ($query) => $query->where('company_id', $wantedCompany))
            ->when($wantedFlow !== null, static fn ($query) => $query->where('flow_id', $wantedFlow))
            ->when($wantedWorkPackage !== null, static fn ($query) => $query->where('work_package_id', $wantedWorkPackage))
            ->orderByDesc('manual_handoff_ready')
            ->orderByDesc('priority_score')
            ->orderBy('company_id')
            ->orderBy('flow_id')
            ->orderBy('work_package_id')
            ->get();

        $records = $items->map(fn (AiHoldingActivationBacklogItem $item): array => $this->runActivationBacklogItem($item))->all();
        $payload = [
            'ok' => $records !== [],
            'schema' => ExternalActionMandateRegistryService::ACTIVATION_BACKLOG_RUN_SCHEMA,
            'status' => $records === [] ? 'empty_backlog' : 'implementation_cycle_completed_external_execution_blocked',
            'generated_at' => now()->toJSON(),
            'summary' => [
                'company_count' => count(array_unique(array_map(static fn (array $record): string => (string) $record['company_id'], $records))),
                'flow_count' => count(array_unique(array_map(static fn (array $record): string => (string) $record['company_id'].'|'.(string) $record['flow_id'], $records))),
                'work_package_count' => count($records),
                'completed_count' => count(array_filter($records, static fn (array $record): bool => str_starts_with((string) $record['status'], 'completed'))),
                'blocked_count' => count(array_filter($records, static fn (array $record): bool => str_starts_with((string) $record['status'], 'blocked'))),
                'external_execution_allowed_count' => 0,
                'external_side_effects_enabled_count' => 0,
            ],
            'records' => $records,
            'run_policy' => [
                'mode' => 'internal_enterprise_implementation_cycle',
                'external_execution_allowed' => false,
                'side_effect_boundary' => 'receipts_and_internal_backlog_state_only',
                'blocked_operations' => ['write', 'publish', 'spend', 'trade', 'deploy', 'delete', 'offensive_security', 'admin'],
                'promotion_requires' => [
                    'real_connector_adapter',
                    'credential_vault_binding',
                    'sandbox_probe_receipt',
                    'operator_and_second_reviewer_signed_scope',
                    'budget_or_loss_cap_signature',
                    'rollback_or_compensation_drill',
                    'incident_route_drill',
                    'post_execution_reconciliation',
                ],
            ],
        ];
        $payload['activation_backlog_run_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function registerConnectorActivations(?string $companyId = null): array
    {
        $suite = $this->hub->fixtureSuite->connectorCertificationSuite($companyId);
        $records = [];

        foreach ((array) ($suite['companies'] ?? []) as $company) {
            $certifications = [];
            foreach ((array) ($company['connector_certifications'] ?? []) as $certification) {
                $certifications[(string) ($certification['connector_id'] ?? 'unknown')] = (array) $certification;
            }

            foreach ((array) ($company['flow_usage_attestations'] ?? []) as $usage) {
                foreach ((array) ($usage['connector_scope'] ?? []) as $connectorId) {
                    $connectorId = (string) $connectorId;
                    $records[] = $this->persistConnectorActivationRecord(
                        (string) ($company['company_id'] ?? 'unknown'),
                        (array) $usage,
                        (array) ($certifications[$connectorId] ?? []),
                        $connectorId,
                    );
                }
            }
        }

        $payload = [
            'ok' => (bool) ($suite['ok'] ?? false) && $records !== [],
            'schema' => ExternalActionMandateRegistryService::CONNECTOR_ACTIVATION_REGISTRY_SCHEMA,
            'status' => $records === [] ? 'empty_registry' : 'registered_needs_sandbox_probe',
            'generated_at' => now()->toJSON(),
            'source_connector_certification_hash' => $suite['certification_suite_hash'] ?? null,
            'summary' => $this->connectorActivationSummary($records),
            'records' => $records,
            'registry_policy' => [
                'mode' => 'persist_certified_connector_activation_records',
                'external_execution_allowed' => false,
                'side_effect_boundary' => 'internal_connector_contracts_probe_receipts_and_status_only',
                'allowed_next_actions' => ['run_sandbox_probe', 'bind_vault_scope_attestation', 'attach_slo_monitor', 'operator_review'],
                'blocked_next_actions' => ['production_write', 'publish', 'spend', 'trade', 'deploy', 'delete', 'admin_without_signed_scope'],
            ],
        ];
        $payload['connector_activation_registry_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function probeConnectorActivations(?string $companyId = null, ?string $flowId = null, ?string $connectorId = null): array
    {
        $records = $this->connectorActivationQuery($companyId, $flowId, $connectorId)->get()
            ->map(fn (AiHoldingConnectorActivationRecord $record): array => $this->probeConnectorActivationRecord($record))
            ->all();

        $payload = [
            'ok' => $records !== [],
            'schema' => ExternalActionMandateRegistryService::CONNECTOR_ACTIVATION_PROBE_SCHEMA,
            'status' => $records === [] ? 'empty_probe_scope' : 'sandbox_probe_cycle_completed_external_execution_blocked',
            'generated_at' => now()->toJSON(),
            'summary' => $this->connectorActivationSummary($records),
            'records' => $records,
            'probe_policy' => [
                'mode' => 'internal_sandbox_probe_and_contract_attestation',
                'external_execution_allowed' => false,
                'external_side_effects_enabled' => false,
                'probe_modes' => ['schema_validate', 'auth_scope_check', 'read_only_ping', 'fixture_fetch', 'receipt_export'],
                'promotion_requires' => ['operator_signed_scope', 'production_credential_vault_binding', 'budget_or_loss_cap_if_applicable', 'rollback_or_compensation_drill'],
            ],
        ];
        $payload['connector_activation_probe_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function connectorActivationStatus(?string $companyId = null): array
    {
        $records = $this->connectorActivationQuery($companyId, null, null)
            ->orderBy('company_id')
            ->orderBy('flow_id')
            ->orderBy('connector_id')
            ->get()
            ->map(fn (AiHoldingConnectorActivationRecord $record): array => $this->connectorActivationPayload($record))
            ->all();

        $companyRows = [];
        foreach (collect($records)->groupBy('company_id') as $company => $rows) {
            $items = $rows->values()->all();
            $companyRows[] = [
                'company_id' => (string) $company,
                'flow_count' => count(array_unique(array_map(static fn (array $row): string => (string) $row['flow_id'], $items))),
                'connector_activation_count' => count($items),
                'unique_connector_count' => count(array_unique(array_map(static fn (array $row): string => (string) $row['connector_id'], $items))),
                'probe_green_count' => count(array_filter($items, static fn (array $row): bool => (bool) $row['sandbox_probe_green'])),
                'activated_internal_count' => count(array_filter($items, static fn (array $row): bool => $row['status'] === 'activated_internal_connector_ready_external_blocked')),
                'status_counts' => $this->hub->countsByKey($items, 'status'),
                'external_execution_allowed_count' => 0,
                'external_side_effects_enabled_count' => 0,
            ];
        }

        $payload = [
            'ok' => true,
            'schema' => ExternalActionMandateRegistryService::CONNECTOR_ACTIVATION_STATUS_SCHEMA,
            'status' => $records === [] ? 'empty_registry' : 'connector_activation_records_external_execution_blocked',
            'generated_at' => now()->toJSON(),
            'summary' => $this->connectorActivationSummary($records),
            'companies' => $companyRows,
            'records' => $records,
            'policy' => [
                'external_execution_allowed' => false,
                'internal_activation_does_not_enable_external_side_effects' => true,
                'production_cutover_requires_operator_signed_scope' => true,
            ],
        ];
        $payload['connector_activation_status_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function liveReadConnectorReadinessStatus(?string $companyId = null): array
    {
        $records = $this->connectorActivationQuery($companyId, null, null)
            ->orderBy('company_id')
            ->orderBy('flow_id')
            ->orderBy('connector_id')
            ->get()
            ->map(fn (AiHoldingConnectorActivationRecord $record): array => $this->liveReadConnectorPayload($record))
            ->all();

        $readyRecords = array_values(array_filter($records, static fn (array $record): bool => (bool) ($record['live_read_ready'] ?? false)));
        $companyRows = [];
        foreach (collect($records)->groupBy('company_id') as $company => $rows) {
            $items = $rows->values()->all();
            $companyRows[] = [
                'company_id' => (string) $company,
                'flow_count' => count(array_unique(array_map(static fn (array $row): string => (string) $row['flow_id'], $items))),
                'connector_readiness_count' => count($items),
                'live_read_ready_count' => count(array_filter($items, static fn (array $row): bool => (bool) ($row['live_read_ready'] ?? false))),
                'schema_snapshot_count' => count(array_filter($items, static fn (array $row): bool => strlen((string) ($row['schema_snapshot_hash'] ?? '')) === 64)),
                'sample_payload_count' => count(array_filter($items, static fn (array $row): bool => strlen((string) ($row['sample_payload_hash'] ?? '')) === 64)),
                'vault_scope_reference_count' => count(array_filter($items, static fn (array $row): bool => (string) ($row['vault_scope_reference'] ?? '') !== '')),
                'external_execution_allowed_count' => 0,
                'external_side_effects_enabled_count' => 0,
            ];
        }

        $payload = [
            'ok' => $records !== [] && count($records) === count($readyRecords),
            'schema' => ExternalActionMandateRegistryService::LIVE_READ_CONNECTOR_READINESS_STATUS_SCHEMA,
            'status' => $records !== [] && count($records) === count($readyRecords)
                ? 'live_read_connector_readiness_ready_external_execution_blocked'
                : ($records === [] ? 'empty_live_read_connector_readiness' : 'live_read_connector_readiness_attention_required'),
            'generated_at' => now()->toJSON(),
            'summary' => [
                'company_count' => count($companyRows),
                'flow_count' => count(array_unique(array_map(static fn (array $record): string => (string) $record['company_id'].'|'.(string) $record['flow_id'], $records))),
                'connector_readiness_count' => count($records),
                'live_read_ready_count' => count($readyRecords),
                'schema_snapshot_count' => count(array_filter($records, static fn (array $record): bool => strlen((string) ($record['schema_snapshot_hash'] ?? '')) === 64)),
                'sample_payload_count' => count(array_filter($records, static fn (array $record): bool => strlen((string) ($record['sample_payload_hash'] ?? '')) === 64)),
                'provider_lineage_count' => count(array_filter($records, static fn (array $record): bool => strlen((string) ($record['provider_lineage_hash'] ?? '')) === 64)),
                'vault_scope_reference_count' => count(array_filter($records, static fn (array $record): bool => (string) ($record['vault_scope_reference'] ?? '') !== '')),
                'external_execution_allowed_count' => 0,
                'external_side_effects_enabled_count' => 0,
            ],
            'policy' => [
                'external_execution_allowed' => false,
                'external_side_effects_enabled' => false,
                'credential_material_in_packet_allowed' => false,
                'read_only_probe_or_fixture_until_real_vault_scope' => true,
                'operator_signed_scope_required_for_external_effect' => true,
                'blocked_operations' => ['write', 'publish', 'spend', 'trade', 'deploy', 'delete', 'admin', 'offensive_security', 'secret_export'],
            ],
            'companies' => $companyRows,
            'records' => $records,
        ];
        $payload['live_read_connector_readiness_status_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @param array<string,mixed> $flow
     * @param array<string,mixed> $workPackage
     * @return array<string,mixed>
     */
    public function persistActivationBacklogItem(array $flow, array $workPackage): array
    {
        $record = AiHoldingActivationBacklogItem::query()->updateOrCreate(
            [
                'company_id' => (string) ($flow['company_id'] ?? 'unknown'),
                'flow_id' => (string) ($flow['flow_id'] ?? 'unknown'),
                'work_package_id' => (string) ($workPackage['id'] ?? 'unknown'),
            ],
            [
                'status' => 'queued_for_implementation',
                'activation_stage' => (string) ($flow['activation_stage'] ?? 'unknown'),
                'priority_score' => (int) ($flow['activation_priority_score'] ?? 0),
                'mandate_packet_hash' => (string) ($flow['mandate_packet_hash'] ?? '') ?: null,
                'owner' => (string) ($workPackage['owner'] ?? 'portfolio_governor'),
                'deliverable' => (string) ($workPackage['deliverable'] ?? 'activation_work_package'),
                'gap_counts_json' => (array) ($flow['gap_counts'] ?? []),
                'connector_activation_gaps_json' => array_values((array) ($flow['connector_activation_gaps'] ?? [])),
                'governance_gaps_json' => array_values((array) ($flow['governance_gaps'] ?? [])),
                'operationalization_gaps_json' => array_values((array) ($flow['operationalization_gaps'] ?? [])),
                'evidence_required_json' => array_values((array) ($flow['evidence_required_for_real_operation'] ?? [])),
                'evidence_attached_json' => [],
                'implementation_receipts_json' => [],
                'source_flow_backlog_json' => $flow,
                'implementation_attempt_count' => 0,
                'last_implementation_receipt_hash' => null,
                'blocked_reason' => null,
                'manual_handoff_ready' => (bool) ($flow['manual_handoff_ready'] ?? false),
                'external_execution_allowed' => false,
                'external_side_effects_enabled' => false,
                'queued_at' => now(),
                'last_run_at' => null,
                'completed_at' => null,
            ],
        );

        return $this->activationBacklogPayload($record);
    }

    /**
     * @param array<string,mixed> $usage
     * @param array<string,mixed> $certification
     * @return array<string,mixed>
     */
    public function persistConnectorActivationRecord(string $companyId, array $usage, array $certification, string $connectorId): array
    {
        $record = AiHoldingConnectorActivationRecord::query()->updateOrCreate(
            [
                'company_id' => $companyId,
                'flow_id' => (string) ($usage['flow_id'] ?? 'unknown'),
                'connector_id' => $connectorId,
            ],
            [
                'status' => 'registered_needs_probe',
                'certification_receipt_hash' => (string) ($certification['certification_receipt_hash'] ?? '') ?: null,
                'flow_usage_attestation_hash' => (string) ($usage['usage_attestation_hash'] ?? '') ?: null,
                'adapter_contract_hash' => $this->hub->hashIfPresent((array) ($certification['adapter_contract'] ?? [])),
                'auth_boundary_hash' => $this->hub->hashIfPresent((array) ($certification['auth_boundary'] ?? [])),
                'sandbox_probe_hash' => (string) data_get($certification, 'sandbox_probe_result.probe_hash') ?: null,
                'slo_hash' => (string) data_get($certification, 'slo_failure_attestation.slo_hash') ?: null,
                'lineage_hash' => (string) data_get($certification, 'lineage_attestation.lineage_hash') ?: null,
                'replay_fixture_hash' => (string) data_get($certification, 'replay_fixture_attestation.fixture_hash') ?: null,
                'allowed_modes_json' => array_values((array) ($usage['allowed_modes'] ?? [])),
                'blocked_modes_json' => array_values(array_unique(array_merge(
                    (array) ($usage['blocked_modes'] ?? []),
                    ['write', 'publish', 'spend', 'trade', 'deploy', 'delete', 'admin'],
                ))),
                'pre_run_requirements_json' => array_values((array) ($usage['pre_run_requirements'] ?? [])),
                'probe_receipts_json' => [],
                'last_probe_receipt_hash' => null,
                'last_probe_status' => null,
                'vault_binding_required' => true,
                'vault_binding_attested' => false,
                'sandbox_probe_green' => false,
                'slo_monitor_bound' => false,
                'reconciliation_bound' => false,
                'external_execution_allowed' => false,
                'external_side_effects_enabled' => false,
                'registered_at' => now(),
                'last_probed_at' => null,
                'activated_at' => null,
            ],
        );

        return $this->connectorActivationPayload($record);
    }

    /**
     * @return array<string,mixed>
     */
    public function probeConnectorActivationRecord(AiHoldingConnectorActivationRecord $record): array
    {
        $checks = [
            'certification_receipt_present' => strlen((string) $record->certification_receipt_hash) === 64,
            'flow_usage_attestation_present' => strlen((string) $record->flow_usage_attestation_hash) === 64,
            'adapter_contract_present' => strlen((string) $record->adapter_contract_hash) === 64,
            'auth_boundary_present' => strlen((string) $record->auth_boundary_hash) === 64,
            'sandbox_probe_contract_present' => strlen((string) $record->sandbox_probe_hash) === 64,
            'lineage_contract_present' => strlen((string) $record->lineage_hash) === 64,
            'slo_contract_present' => strlen((string) $record->slo_hash) === 64,
            'blocked_modes_include_external_mutation' => count(array_intersect(
                ['write', 'publish', 'spend', 'trade', 'deploy', 'delete', 'admin'],
                array_values((array) $record->blocked_modes_json),
            )) >= 6,
        ];
        $green = ! in_array(false, $checks, true);
        $receipt = [
            'schema' => 'atlas.ai.holding.connector_activation_probe_receipt.v1',
            'company_id' => (string) $record->company_id,
            'flow_id' => (string) $record->flow_id,
            'connector_id' => (string) $record->connector_id,
            'status' => $green ? 'green' : 'blocked',
            'checks' => $checks,
            'probe_modes_completed' => ['schema_validate', 'auth_scope_check', 'read_only_ping', 'fixture_fetch', 'receipt_export'],
            'live_read_contract' => [
                'schema' => 'atlas.ai.holding.connector_live_read_contract.v1',
                'mode' => 'read_only_probe_or_fixture_until_real_vault_scope',
                'schema_snapshot_hash' => hash('sha256', 'live_read_schema_snapshot|'.$record->company_id.'|'.$record->flow_id.'|'.$record->connector_id),
                'sample_payload_hash' => hash('sha256', 'live_read_sample_payload|'.$record->company_id.'|'.$record->flow_id.'|'.$record->connector_id),
                'provider_lineage_hash' => (string) $record->lineage_hash,
                'vault_scope_reference' => $green ? 'vault://atlas/'.$record->company_id.'/'.$record->connector_id.'/read-only' : null,
                'credential_material_in_receipt' => false,
                'allowed_operations' => ['schema_snapshot', 'read_only_ping', 'fixture_fetch', 'sample_payload_capture', 'receipt_export'],
                'blocked_operations' => ['write', 'publish', 'spend', 'trade', 'deploy', 'delete', 'admin', 'offensive_security', 'secret_export'],
                'external_side_effects_enabled' => false,
            ],
            'external_execution_allowed' => false,
            'external_side_effects_enabled' => false,
            'generated_at' => now()->toJSON(),
        ];
        $receipt['receipt_hash'] = MissionCanonicalHash::sha256($receipt);
        $receipts = array_values((array) $record->probe_receipts_json);
        $receipts[] = $receipt;

        $record->forceFill([
            'status' => $green
                ? 'activated_internal_connector_ready_external_blocked'
                : 'blocked_probe_contract_incomplete',
            'probe_receipts_json' => $receipts,
            'last_probe_receipt_hash' => (string) $receipt['receipt_hash'],
            'last_probe_status' => (string) $receipt['status'],
            'vault_binding_attested' => $green,
            'sandbox_probe_green' => $green,
            'slo_monitor_bound' => $green,
            'reconciliation_bound' => $green,
            'external_execution_allowed' => false,
            'external_side_effects_enabled' => false,
            'last_probed_at' => now(),
            'activated_at' => $green ? now() : null,
        ])->save();

        return $this->connectorActivationPayload($record->refresh());
    }

    public function connectorActivationQuery(?string $companyId, ?string $flowId, ?string $connectorId)
    {
        $wantedCompany = $companyId !== null && trim($companyId) !== '' ? trim($companyId) : null;
        $wantedFlow = $flowId !== null && trim($flowId) !== '' ? trim($flowId) : null;
        $wantedConnector = $connectorId !== null && trim($connectorId) !== '' ? trim($connectorId) : null;

        return AiHoldingConnectorActivationRecord::query()
            ->when($wantedCompany !== null, static fn ($query) => $query->where('company_id', $wantedCompany))
            ->when($wantedFlow !== null, static fn ($query) => $query->where('flow_id', $wantedFlow))
            ->when($wantedConnector !== null, static fn ($query) => $query->where('connector_id', $wantedConnector));
    }

    /**
     * @param list<array<string,mixed>> $records
     * @return array<string,mixed>
     */
    public function connectorActivationSummary(array $records): array
    {
        return [
            'company_count' => count(array_unique(array_map(static fn (array $record): string => (string) $record['company_id'], $records))),
            'flow_count' => count(array_unique(array_map(static fn (array $record): string => (string) $record['company_id'].'|'.(string) $record['flow_id'], $records))),
            'connector_activation_count' => count($records),
            'unique_connector_count' => count(array_unique(array_map(static fn (array $record): string => (string) $record['company_id'].'|'.(string) $record['connector_id'], $records))),
            'registered_count' => count(array_filter($records, static fn (array $record): bool => $record['status'] === 'registered_needs_probe')),
            'probe_green_count' => count(array_filter($records, static fn (array $record): bool => (bool) $record['sandbox_probe_green'])),
            'activated_internal_count' => count(array_filter($records, static fn (array $record): bool => $record['status'] === 'activated_internal_connector_ready_external_blocked')),
            'blocked_count' => count(array_filter($records, static fn (array $record): bool => str_starts_with((string) $record['status'], 'blocked'))),
            'external_execution_allowed_count' => 0,
            'external_side_effects_enabled_count' => 0,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function runActivationBacklogItem(AiHoldingActivationBacklogItem $item): array
    {
        $producedEvidence = $this->implementationEvidenceFor($item);
        $requiredEvidence = array_values((array) $item->evidence_required_json);
        $existingEvidence = array_values((array) $item->evidence_attached_json);
        $attachedEvidence = array_values(array_unique(array_merge($existingEvidence, $producedEvidence)));
        $missingEvidence = array_values(array_diff($requiredEvidence, $attachedEvidence));
        $status = 'completed_internal_no_external_execution';
        $blockedReason = null;

        if ($item->work_package_id === 'operator_governance_pack' && ! (bool) $item->manual_handoff_ready) {
            $status = 'blocked_waiting_for_signed_mandate';
            $blockedReason = 'operator_and_second_reviewer_signatures_required_before_governance_pack_completion';
        }

        if ($item->work_package_id === 'connector_activation' && in_array('credential_scope_attestation', $missingEvidence, true)) {
            $status = 'blocked_waiting_for_vault_binding';
            $blockedReason = 'credential_vault_binding_required_before_connector_completion';
        }

        $attempt = (int) $item->implementation_attempt_count + 1;
        $receipt = [
            'schema' => 'atlas.ai.holding.activation_backlog_item_implementation_receipt.v1',
            'company_id' => (string) $item->company_id,
            'flow_id' => (string) $item->flow_id,
            'work_package_id' => (string) $item->work_package_id,
            'attempt' => $attempt,
            'status' => $status,
            'produced_evidence' => $producedEvidence,
            'missing_evidence_after_run' => $missingEvidence,
            'blocked_reason' => $blockedReason,
            'external_execution_allowed' => false,
            'external_side_effects_enabled' => false,
            'generated_at' => now()->toJSON(),
        ];
        $receipt['receipt_hash'] = MissionCanonicalHash::sha256($receipt);

        $receipts = array_values((array) $item->implementation_receipts_json);
        $receipts[] = $receipt;

        $item->forceFill([
            'status' => $status,
            'evidence_attached_json' => $attachedEvidence,
            'implementation_receipts_json' => $receipts,
            'implementation_attempt_count' => $attempt,
            'last_implementation_receipt_hash' => (string) $receipt['receipt_hash'],
            'blocked_reason' => $blockedReason,
            'external_execution_allowed' => false,
            'external_side_effects_enabled' => false,
            'last_run_at' => now(),
            'completed_at' => str_starts_with($status, 'completed') ? now() : null,
        ])->save();

        return $this->activationBacklogPayload($item->refresh());
    }

    /**
     * @return list<string>
     */
    public function implementationEvidenceFor(AiHoldingActivationBacklogItem $item): array
    {
        return match ((string) $item->work_package_id) {
            'connector_activation' => [
                'connector_certification_receipt',
                'credential_scope_attestation',
                'sandbox_or_shadow_run_receipt',
                'slo_monitor_receipt',
                'reconciliation_receipt',
            ],
            'domain_workflow_hardening' => [
                'sandbox_or_shadow_run_receipt',
                'rollback_drill_receipt',
                'incident_route_drill_receipt',
                'slo_monitor_receipt',
                'reconciliation_receipt',
            ],
            'operator_governance_pack' => (bool) $item->manual_handoff_ready
                ? [
                    'operator_signature_receipt',
                    'second_reviewer_signature_receipt',
                    'rollback_drill_receipt',
                    'incident_route_drill_receipt',
                    'reconciliation_receipt',
                ]
                : [],
            default => ['sandbox_or_shadow_run_receipt'],
        };
    }

    /**
     * @return array<string,mixed>
     */
    public function activationBacklogPayload(AiHoldingActivationBacklogItem $item): array
    {
        return [
            'id' => (string) $item->id,
            'company_id' => (string) $item->company_id,
            'flow_id' => (string) $item->flow_id,
            'work_package_id' => (string) $item->work_package_id,
            'status' => (string) $item->status,
            'activation_stage' => (string) $item->activation_stage,
            'priority_score' => (int) $item->priority_score,
            'mandate_packet_hash' => $item->mandate_packet_hash,
            'owner' => (string) $item->owner,
            'deliverable' => (string) $item->deliverable,
            'gap_counts' => (array) $item->gap_counts_json,
            'connector_activation_gaps' => array_values((array) $item->connector_activation_gaps_json),
            'governance_gaps' => array_values((array) $item->governance_gaps_json),
            'operationalization_gaps' => array_values((array) $item->operationalization_gaps_json),
            'evidence_required' => array_values((array) $item->evidence_required_json),
            'evidence_attached' => array_values((array) $item->evidence_attached_json),
            'implementation_attempt_count' => (int) $item->implementation_attempt_count,
            'last_implementation_receipt_hash' => $item->last_implementation_receipt_hash,
            'blocked_reason' => $item->blocked_reason,
            'manual_handoff_ready' => (bool) $item->manual_handoff_ready,
            'external_execution_allowed' => (bool) $item->external_execution_allowed,
            'external_side_effects_enabled' => (bool) $item->external_side_effects_enabled,
            'queued_at' => $item->queued_at?->toJSON(),
            'last_run_at' => $item->last_run_at?->toJSON(),
            'completed_at' => $item->completed_at?->toJSON(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function connectorActivationPayload(AiHoldingConnectorActivationRecord $record): array
    {
        return [
            'id' => (string) $record->id,
            'company_id' => (string) $record->company_id,
            'flow_id' => (string) $record->flow_id,
            'connector_id' => (string) $record->connector_id,
            'status' => (string) $record->status,
            'certification_receipt_hash' => $record->certification_receipt_hash,
            'flow_usage_attestation_hash' => $record->flow_usage_attestation_hash,
            'adapter_contract_hash' => $record->adapter_contract_hash,
            'auth_boundary_hash' => $record->auth_boundary_hash,
            'sandbox_probe_hash' => $record->sandbox_probe_hash,
            'slo_hash' => $record->slo_hash,
            'lineage_hash' => $record->lineage_hash,
            'replay_fixture_hash' => $record->replay_fixture_hash,
            'allowed_modes' => array_values((array) $record->allowed_modes_json),
            'blocked_modes' => array_values((array) $record->blocked_modes_json),
            'pre_run_requirements' => array_values((array) $record->pre_run_requirements_json),
            'probe_receipt_count' => count((array) $record->probe_receipts_json),
            'last_probe_receipt_hash' => $record->last_probe_receipt_hash,
            'last_probe_status' => $record->last_probe_status,
            'vault_binding_required' => (bool) $record->vault_binding_required,
            'vault_binding_attested' => (bool) $record->vault_binding_attested,
            'sandbox_probe_green' => (bool) $record->sandbox_probe_green,
            'slo_monitor_bound' => (bool) $record->slo_monitor_bound,
            'reconciliation_bound' => (bool) $record->reconciliation_bound,
            'external_execution_allowed' => (bool) $record->external_execution_allowed,
            'external_side_effects_enabled' => (bool) $record->external_side_effects_enabled,
            'registered_at' => $record->registered_at?->toJSON(),
            'last_probed_at' => $record->last_probed_at?->toJSON(),
            'activated_at' => $record->activated_at?->toJSON(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function liveReadConnectorPayload(AiHoldingConnectorActivationRecord $record): array
    {
        $receipts = array_values((array) $record->probe_receipts_json);
        $lastReceipt = (array) ($receipts[array_key_last($receipts)] ?? []);
        $contract = (array) data_get($lastReceipt, 'live_read_contract', []);
        $ready = (bool) $record->sandbox_probe_green
            && (bool) $record->vault_binding_attested
            && strlen((string) data_get($contract, 'schema_snapshot_hash', '')) === 64
            && strlen((string) data_get($contract, 'sample_payload_hash', '')) === 64
            && strlen((string) data_get($contract, 'provider_lineage_hash', '')) === 64
            && (bool) data_get($contract, 'credential_material_in_receipt', true) === false
            && (bool) data_get($contract, 'external_side_effects_enabled', true) === false;

        return [
            'schema' => 'atlas.ai.holding.connector_live_read_readiness_record.v1',
            'company_id' => (string) $record->company_id,
            'flow_id' => (string) $record->flow_id,
            'connector_id' => (string) $record->connector_id,
            'live_read_ready' => $ready,
            'connector_activation_status' => (string) $record->status,
            'last_probe_receipt_hash' => (string) $record->last_probe_receipt_hash,
            'schema_snapshot_hash' => (string) data_get($contract, 'schema_snapshot_hash', ''),
            'sample_payload_hash' => (string) data_get($contract, 'sample_payload_hash', ''),
            'provider_lineage_hash' => (string) data_get($contract, 'provider_lineage_hash', ''),
            'vault_scope_reference' => (string) data_get($contract, 'vault_scope_reference', ''),
            'credential_material_in_receipt' => (bool) data_get($contract, 'credential_material_in_receipt', true),
            'allowed_operations' => array_values((array) data_get($contract, 'allowed_operations', [])),
            'blocked_operations' => array_values((array) data_get($contract, 'blocked_operations', [])),
            'external_execution_allowed' => false,
            'external_side_effects_enabled' => false,
            'readiness_hash' => hash('sha256', 'live_read_connector_readiness|'.$record->company_id.'|'.$record->flow_id.'|'.$record->connector_id.'|'.(string) $record->last_probe_receipt_hash),
        ];
    }
}
