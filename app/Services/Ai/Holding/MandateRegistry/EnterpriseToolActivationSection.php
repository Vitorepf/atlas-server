<?php

namespace App\Services\Ai\Holding\MandateRegistry;

use App\Models\AtlasToolRun;
use App\Services\Ai\Holding\ExternalActionMandateRegistryService;
use App\Services\Ai\Mission\MissionCanonicalHash;

/**
 * GOD-DEBULK method-family section extracted verbatim from
 * ExternalActionMandateRegistryService. Behavior frozen by
 * ExternalActionMandateRegistryServiceGoldenCharacterizationTest.
 */
class EnterpriseToolActivationSection
{
    public function __construct(private readonly MandateRegistryHub $hub) {}

    /**
     * @return array<string,mixed>
     */
    public function enterpriseCompanyExternalToolActivationWorkOrderRegister(?string $companyId = null): array
    {
        $wantedCompany = $companyId !== null && trim($companyId) !== '' ? trim($companyId) : null;
        unset($this->hub->runtimeStatusCache['external_tool_activation_work_order_status:'.($wantedCompany ?? '*')]);
        unset($this->hub->runtimeStatusCache['external_tool_activation_packet_status:'.($wantedCompany ?? '*')]);
        $supervisedExecution = $this->hub->enterpriseCapabilityRuntime->enterpriseCompanySupervisedConnectorExecutionStatus($wantedCompany);
        $supervisedByCompany = $this->hub->companyRowsById($supervisedExecution);

        if (! $this->hub->approval->toolRunsTableAvailable()) {
            return [
                'ok' => false,
                'schema' => ExternalActionMandateRegistryService::ENTERPRISE_COMPANY_EXTERNAL_TOOL_ACTIVATION_WORK_ORDER_REGISTER_SCHEMA,
                'status' => 'atlas_tool_runs_table_unavailable',
                'generated_at' => now()->toJSON(),
                'summary' => [
                    'company_count' => 0,
                    'expected_external_tool_activation_work_order_count' => 0,
                    'registered_external_tool_activation_work_order_count' => 0,
                    'external_execution_allowed_count' => 0,
                    'external_side_effects_enabled_count' => 0,
                ],
                'companies' => [],
                'policy' => $this->externalToolActivationWorkOrderPolicy(),
            ];
        }

        $companies = [];
        foreach ($this->hub->buildoutCompanies($wantedCompany) as $company) {
            $id = (string) ($company['company_id'] ?? 'unknown');
            $supervisedRow = (array) ($supervisedByCompany[$id] ?? []);
            $registeredWorkOrders = [];

            foreach ((array) ($supervisedRow['runtime_records'] ?? []) as $supervisedRecord) {
                $flowId = (string) ($supervisedRecord['flow_id'] ?? '');
                $capabilityId = (string) ($supervisedRecord['capability_id'] ?? '');
                $connectorId = (string) ($supervisedRecord['connector_id'] ?? '');
                if ($flowId === '' || $capabilityId === '' || $connectorId === '') {
                    continue;
                }

                $runContextId = $id.'.'.substr(MissionCanonicalHash::sha256([
                    'flow_id' => $flowId,
                    'capability_id' => $capabilityId,
                    'connector_id' => $connectorId,
                    'context' => 'external_tool_activation_work_order',
                ]), 0, 24).'.external_tool_activation_work_order.v1';
                $artifact = $this->externalToolActivationWorkOrderArtifact($company, (array) $supervisedRecord, $runContextId);
                $qualityGates = [
                    'supervised_connector_execution_ready' => (bool) ($supervisedRecord['ready'] ?? false)
                        && strlen((string) ($supervisedRecord['supervised_connector_execution_status_record_hash'] ?? '')) === 64,
                    'work_order_artifact_bound' => strlen((string) ($artifact['external_tool_activation_work_order_artifact_hash'] ?? '')) === 64,
                    'credential_scope_requirements_bound' => count((array) data_get($artifact, 'credential_scope_requirements.required_scopes', [])) >= 1
                        && (bool) data_get($artifact, 'credential_scope_requirements.credential_material_in_packet_allowed', true) === false,
                    'dry_run_operations_bound' => count((array) data_get($artifact, 'dry_run_operations', [])) >= 7,
                    'allowed_read_probe_actions_bound' => count((array) data_get($artifact, 'allowed_read_probe_actions', [])) >= 5,
                    'forbidden_external_effect_actions_bound' => count((array) data_get($artifact, 'forbidden_external_effect_actions', [])) >= 12,
                    'receipt_chain_bound' => strlen((string) data_get($artifact, 'receipt_chain.activation_work_order_receipt_hash', '')) === 64
                        && strlen((string) data_get($artifact, 'receipt_chain.credential_scope_receipt_hash', '')) === 64
                        && strlen((string) data_get($artifact, 'receipt_chain.operator_mandate_receipt_hash', '')) === 64,
                    'operator_mandate_required' => (bool) data_get($artifact, 'operator_mandate.operator_mandate_required_for_external_effect', false),
                    'external_effects_blocked' => ! (bool) ($artifact['external_execution_allowed'] ?? true)
                        && ! (bool) ($artifact['external_side_effects_enabled'] ?? true),
                ];
                $summary = [
                    'company_id' => $id,
                    'flow_id' => $flowId,
                    'capability_id' => $capabilityId,
                    'connector_id' => $connectorId,
                    'work_order_id' => (string) ($artifact['work_order_id'] ?? ''),
                    'activation_mode' => 'operator_scoped_read_only_probe_work_order_no_external_effect',
                    'quality_gate_count' => count($qualityGates),
                    'ready_quality_gate_count' => count(array_filter($qualityGates)),
                    'external_execution_allowed' => false,
                    'external_side_effects_enabled' => false,
                ];
                $normalized = [
                    'schema' => 'atlas.ai.company.external_tool_activation_work_order_result.v1',
                    'status' => count(array_filter($qualityGates)) === count($qualityGates) ? 'passed' : 'attention_required',
                    'summary' => $summary,
                    'external_tool_activation_work_order_artifact' => $artifact,
                    'quality_gates' => $qualityGates,
                    'blocking_failures' => array_values(array_keys(array_filter($qualityGates, static fn (bool $ready): bool => ! $ready))),
                ];
                $receiptInput = [
                    'company_id' => $id,
                    'flow_id' => $flowId,
                    'capability_id' => $capabilityId,
                    'connector_id' => $connectorId,
                    'run_context_id' => $runContextId,
                    'work_order_id' => (string) ($artifact['work_order_id'] ?? ''),
                    'artifact_hash' => (string) ($artifact['external_tool_activation_work_order_artifact_hash'] ?? ''),
                ];
                $metadata = [
                    'source' => 'enterprise_company_external_tool_activation_work_order_register',
                    'receipt_schema_version' => 'atlas.company_external_tool_activation_work_order_receipt.v1',
                    'evidence_receipt_hash' => MissionCanonicalHash::sha256($normalized),
                    'activation_work_order_receipt_hash' => MissionCanonicalHash::sha256($receiptInput + ['receipt_type' => 'activation_work_order_receipt']),
                    'credential_scope_receipt_hash' => MissionCanonicalHash::sha256($receiptInput + ['receipt_type' => 'credential_scope_receipt']),
                    'dry_run_operation_receipt_hash' => MissionCanonicalHash::sha256($receiptInput + ['receipt_type' => 'dry_run_operation_receipt']),
                    'allowed_read_probe_receipt_hash' => MissionCanonicalHash::sha256($receiptInput + ['receipt_type' => 'allowed_read_probe_receipt']),
                    'forbidden_external_effect_receipt_hash' => MissionCanonicalHash::sha256($receiptInput + ['receipt_type' => 'forbidden_external_effect_receipt']),
                    'operator_mandate_receipt_hash' => MissionCanonicalHash::sha256($receiptInput + ['receipt_type' => 'operator_mandate_receipt']),
                    'audit_trail_hash' => MissionCanonicalHash::sha256($receiptInput + ['receipt_type' => 'audit_trail']),
                    'rollback_plan_hash' => MissionCanonicalHash::sha256($receiptInput + ['receipt_type' => 'rollback_plan']),
                    'external_execution_allowed' => false,
                    'external_side_effects_enabled' => false,
                    'operator_signed_scope_required_for_external_effect' => true,
                    'action_runtime_contract' => [
                        'schema_version' => 'atlas.company_external_tool_activation_work_order.contract.v1',
                        'mode' => 'operator_scoped_read_only_probe_work_order_no_external_effect',
                        'run_context_type' => 'holding_company_external_tool_activation_work_order',
                        'run_context_id' => $runContextId,
                        'provider_dispatch_allowed' => false,
                        'external_write_allowed' => false,
                        'customer_message_allowed' => false,
                        'billing_or_capital_action_allowed' => false,
                        'trade_allowed' => false,
                        'deploy_allowed' => false,
                        'security_action_allowed' => false,
                        'operator_approval_required_for_external_effect' => true,
                    ],
                ];

                $run = AtlasToolRun::query()->updateOrCreate(
                    [
                        'surface' => 'holding_company_external_tool_activation_work_order',
                        'run_context_type' => 'holding_company_external_tool_activation_work_order',
                        'run_context_id' => $runContextId,
                    ],
                    [
                        'tool_slug' => 'atlas_company_external_tool_activation_work_order',
                        'workspace_hash' => hash('sha256', base_path()),
                        'workspace' => base_path(),
                        'status' => $normalized['status'] === 'passed' ? 'passed' : 'failed',
                        'required' => true,
                        'failure_policy' => 'fail_closed',
                        'policy_decision' => 'activation_work_order_registered_blocked',
                        'command_hash' => MissionCanonicalHash::sha256([
                            'run_context_id' => $runContextId,
                            'artifact_hash' => $artifact['external_tool_activation_work_order_artifact_hash'] ?? null,
                        ]),
                        'exit_code' => $normalized['status'] === 'passed' ? 0 : 1,
                        'started_at' => now(),
                        'finished_at' => now(),
                        'duration_ms' => 0,
                        'summary_json' => $summary,
                        'normalized_result_json' => $normalized,
                        'policy_decision_json' => [
                            'external_execution_allowed' => false,
                            'external_side_effects_enabled' => false,
                            'blocked_operations' => $this->externalToolActivationWorkOrderPolicy()['blocked_operations'],
                        ],
                        'metadata_json' => $metadata,
                    ],
                );

                $registeredWorkOrders[] = [
                    'run_id' => (string) $run->id,
                    'run_context_id' => $runContextId,
                    'work_order_id' => (string) ($artifact['work_order_id'] ?? ''),
                    'flow_id' => $flowId,
                    'capability_id' => $capabilityId,
                    'connector_id' => $connectorId,
                    'status' => (string) $run->status,
                    'policy_decision' => (string) $run->policy_decision,
                    'activation_work_order_receipt_hash' => (string) data_get($run->metadata_json, 'activation_work_order_receipt_hash', ''),
                    'external_execution_allowed' => false,
                    'external_side_effects_enabled' => false,
                ];
            }

            $row = [
                'schema' => 'atlas.ai.company.enterprise_external_tool_activation_work_order_register_record.v1',
                'company_id' => $id,
                'expected_supervised_connector_execution_run_count' => (int) ($supervisedRow['ready_supervised_connector_execution_run_count'] ?? 0),
                'registered_external_tool_activation_work_order_count' => count($registeredWorkOrders),
                'registered_work_orders' => $registeredWorkOrders,
                'external_execution_allowed' => false,
                'external_side_effects_enabled' => false,
            ];
            $row['company_external_tool_activation_work_order_register_record_hash'] = MissionCanonicalHash::sha256($row);
            $companies[] = $row;
        }

        $registeredWorkOrderCount = array_sum(array_map(static fn (array $company): int => (int) ($company['registered_external_tool_activation_work_order_count'] ?? 0), $companies));
        $expectedWorkOrderCount = array_sum(array_map(static fn (array $company): int => (int) ($company['expected_supervised_connector_execution_run_count'] ?? 0), $companies));
        $payload = [
            'ok' => $expectedWorkOrderCount > 0 && $registeredWorkOrderCount >= $expectedWorkOrderCount,
            'schema' => ExternalActionMandateRegistryService::ENTERPRISE_COMPANY_EXTERNAL_TOOL_ACTIVATION_WORK_ORDER_REGISTER_SCHEMA,
            'status' => $expectedWorkOrderCount > 0 && $registeredWorkOrderCount >= $expectedWorkOrderCount
                ? 'enterprise_company_external_tool_activation_work_orders_registered_external_effects_blocked'
                : 'enterprise_company_external_tool_activation_work_orders_attention_required',
            'generated_at' => now()->toJSON(),
            'summary' => [
                'company_count' => count($companies),
                'expected_external_tool_activation_work_order_count' => $expectedWorkOrderCount,
                'registered_external_tool_activation_work_order_count' => $registeredWorkOrderCount,
                'external_execution_allowed_count' => 0,
                'external_side_effects_enabled_count' => 0,
            ],
            'source_hashes' => [
                'enterprise_company_supervised_connector_execution_status_hash' => $supervisedExecution['enterprise_company_supervised_connector_execution_status_hash'] ?? null,
            ],
            'companies' => $companies,
            'policy' => $this->externalToolActivationWorkOrderPolicy(),
        ];
        $payload['enterprise_company_external_tool_activation_work_order_register_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function enterpriseCompanyExternalToolActivationWorkOrderStatus(?string $companyId = null): array
    {
        $wantedCompany = $companyId !== null && trim($companyId) !== '' ? trim($companyId) : null;
        $cacheKey = 'external_tool_activation_work_order_status:'.($wantedCompany ?? '*');
        if (isset($this->hub->runtimeStatusCache[$cacheKey])) {
            return $this->hub->runtimeStatusCache[$cacheKey];
        }

        $supervisedExecution = $this->hub->enterpriseCapabilityRuntime->enterpriseCompanySupervisedConnectorExecutionStatus($wantedCompany);
        $supervisedByCompany = $this->hub->companyRowsById($supervisedExecution);

        if (! $this->hub->approval->toolRunsTableAvailable()) {
            return [
                'ok' => false,
                'schema' => ExternalActionMandateRegistryService::ENTERPRISE_COMPANY_EXTERNAL_TOOL_ACTIVATION_WORK_ORDER_STATUS_SCHEMA,
                'status' => 'atlas_tool_runs_table_unavailable',
                'generated_at' => now()->toJSON(),
                'summary' => [
                    'company_count' => 0,
                    'expected_external_tool_activation_work_order_count' => 0,
                    'persisted_external_tool_activation_work_order_count' => 0,
                    'ready_external_tool_activation_work_order_count' => 0,
                    'external_execution_allowed_count' => 0,
                    'external_side_effects_enabled_count' => 0,
                ],
                'companies' => [],
                'policy' => $this->externalToolActivationWorkOrderPolicy(),
            ];
        }

        $companies = [];
        foreach ($this->hub->buildoutCompanies($wantedCompany) as $company) {
            $id = (string) ($company['company_id'] ?? 'unknown');
            $supervisedRow = (array) ($supervisedByCompany[$id] ?? []);
            $workOrderRecords = [];

            foreach ((array) ($supervisedRow['runtime_records'] ?? []) as $supervisedRecord) {
                $flowId = (string) ($supervisedRecord['flow_id'] ?? '');
                $capabilityId = (string) ($supervisedRecord['capability_id'] ?? '');
                $connectorId = (string) ($supervisedRecord['connector_id'] ?? '');
                if ($flowId === '' || $capabilityId === '' || $connectorId === '') {
                    continue;
                }

                $runContextId = $id.'.'.substr(MissionCanonicalHash::sha256([
                    'flow_id' => $flowId,
                    'capability_id' => $capabilityId,
                    'connector_id' => $connectorId,
                    'context' => 'external_tool_activation_work_order',
                ]), 0, 24).'.external_tool_activation_work_order.v1';
                $run = AtlasToolRun::query()
                    ->where('surface', 'holding_company_external_tool_activation_work_order')
                    ->where('run_context_type', 'holding_company_external_tool_activation_work_order')
                    ->where('run_context_id', $runContextId)
                    ->latest('updated_at')
                    ->first();

                $recordGates = [
                    'persisted_external_tool_activation_work_order_exists' => $run instanceof AtlasToolRun,
                    'status_passed' => $run instanceof AtlasToolRun && $run->status === 'passed',
                    'policy_activation_work_order_registered' => $run instanceof AtlasToolRun && $run->policy_decision === 'activation_work_order_registered_blocked',
                    'normalized_result_bound' => $run instanceof AtlasToolRun && data_get($run->normalized_result_json, 'schema') === 'atlas.ai.company.external_tool_activation_work_order_result.v1',
                    'activation_work_order_artifact_bound' => $run instanceof AtlasToolRun
                        && data_get($run->normalized_result_json, 'external_tool_activation_work_order_artifact.schema') === 'atlas.ai.company.external_tool_activation_work_order_artifact.v1'
                        && strlen((string) data_get($run->normalized_result_json, 'external_tool_activation_work_order_artifact.external_tool_activation_work_order_artifact_hash', '')) === 64,
                    'receipt_metadata_bound' => $run instanceof AtlasToolRun
                        && strlen((string) data_get($run->metadata_json, 'activation_work_order_receipt_hash', '')) === 64
                        && strlen((string) data_get($run->metadata_json, 'credential_scope_receipt_hash', '')) === 64
                        && strlen((string) data_get($run->metadata_json, 'dry_run_operation_receipt_hash', '')) === 64
                        && strlen((string) data_get($run->metadata_json, 'allowed_read_probe_receipt_hash', '')) === 64
                        && strlen((string) data_get($run->metadata_json, 'forbidden_external_effect_receipt_hash', '')) === 64
                        && strlen((string) data_get($run->metadata_json, 'operator_mandate_receipt_hash', '')) === 64
                        && strlen((string) data_get($run->metadata_json, 'audit_trail_hash', '')) === 64
                        && strlen((string) data_get($run->metadata_json, 'rollback_plan_hash', '')) === 64,
                    'quality_gates_bound' => $run instanceof AtlasToolRun
                        && count(array_filter((array) data_get($run->normalized_result_json, 'quality_gates', []))) >= 9,
                    'critical_operations_blocked' => $run instanceof AtlasToolRun
                        && in_array('credential_material_export', (array) data_get($run->policy_decision_json, 'blocked_operations', []), true)
                        && in_array('external_write', (array) data_get($run->policy_decision_json, 'blocked_operations', []), true)
                        && in_array('trade', (array) data_get($run->policy_decision_json, 'blocked_operations', []), true),
                    'external_effects_blocked' => $run instanceof AtlasToolRun
                        && ! (bool) data_get($run->metadata_json, 'external_execution_allowed', true)
                        && ! (bool) data_get($run->metadata_json, 'external_side_effects_enabled', true),
                ];
                $readyRecordGateCount = count(array_filter($recordGates));
                $workOrderRecord = [
                    'schema' => 'atlas.ai.company.external_tool_activation_work_order_status_record.v1',
                    'company_id' => $id,
                    'flow_id' => $flowId,
                    'capability_id' => $capabilityId,
                    'connector_id' => $connectorId,
                    'run_context_id' => $runContextId,
                    'work_order_id' => $run instanceof AtlasToolRun ? (string) data_get($run->summary_json, 'work_order_id', '') : '',
                    'tool_run_id' => $run instanceof AtlasToolRun ? (string) $run->id : null,
                    'ready' => $readyRecordGateCount === count($recordGates),
                    'ready_gate_count' => $readyRecordGateCount,
                    'required_gate_count' => count($recordGates),
                    'gates' => $recordGates,
                    'missing_gates' => array_values(array_keys(array_filter($recordGates, static fn (bool $ready): bool => ! $ready))),
                    'receipt_chain' => $run instanceof AtlasToolRun ? [
                        'activation_work_order_receipt_hash' => (string) data_get($run->metadata_json, 'activation_work_order_receipt_hash', ''),
                        'credential_scope_receipt_hash' => (string) data_get($run->metadata_json, 'credential_scope_receipt_hash', ''),
                        'dry_run_operation_receipt_hash' => (string) data_get($run->metadata_json, 'dry_run_operation_receipt_hash', ''),
                        'allowed_read_probe_receipt_hash' => (string) data_get($run->metadata_json, 'allowed_read_probe_receipt_hash', ''),
                        'forbidden_external_effect_receipt_hash' => (string) data_get($run->metadata_json, 'forbidden_external_effect_receipt_hash', ''),
                        'operator_mandate_receipt_hash' => (string) data_get($run->metadata_json, 'operator_mandate_receipt_hash', ''),
                        'audit_trail_hash' => (string) data_get($run->metadata_json, 'audit_trail_hash', ''),
                        'rollback_plan_hash' => (string) data_get($run->metadata_json, 'rollback_plan_hash', ''),
                    ] : [],
                    'external_execution_allowed' => false,
                    'external_side_effects_enabled' => false,
                ];
                $workOrderRecord['external_tool_activation_work_order_status_record_hash'] = MissionCanonicalHash::sha256($workOrderRecord);
                $workOrderRecords[] = $workOrderRecord;
            }

            $readyWorkOrderCount = count(array_filter($workOrderRecords, static fn (array $record): bool => (bool) ($record['ready'] ?? false)));
            $expectedWorkOrderCount = (int) ($supervisedRow['ready_supervised_connector_execution_run_count'] ?? 0);
            $gates = [
                'supervised_connector_execution_ready' => (bool) ($supervisedRow['supervised_connector_execution_ready'] ?? false),
                'external_tool_activation_work_orders_cover_supervised_connector_execution' => $expectedWorkOrderCount > 0
                    && count($workOrderRecords) >= $expectedWorkOrderCount
                    && $readyWorkOrderCount === count($workOrderRecords),
                'external_effects_blocked' => count(array_filter($workOrderRecords, static fn (array $record): bool => (bool) ($record['external_execution_allowed'] ?? true)
                    || (bool) ($record['external_side_effects_enabled'] ?? true))) === 0,
            ];
            $readyGateCount = count(array_filter($gates));
            $row = [
                'schema' => 'atlas.ai.company.enterprise_external_tool_activation_work_order_company_status.v1',
                'company_id' => $id,
                'expected_external_tool_activation_work_order_count' => $expectedWorkOrderCount,
                'persisted_external_tool_activation_work_order_count' => count($workOrderRecords),
                'ready_external_tool_activation_work_order_count' => $readyWorkOrderCount,
                'external_tool_activation_work_orders_ready' => $expectedWorkOrderCount > 0 && $readyGateCount === count($gates),
                'runtime_grade' => $expectedWorkOrderCount > 0 && $readyGateCount === count($gates)
                    ? 'target_9_external_tool_activation_work_orders_ready_external_effects_blocked'
                    : 'external_tool_activation_work_orders_attention_required',
                'ready_gate_count' => $readyGateCount,
                'required_gate_count' => count($gates),
                'gates' => $gates,
                'missing_gates' => array_values(array_keys(array_filter($gates, static fn (bool $ready): bool => ! $ready))),
                'work_order_records' => $workOrderRecords,
                'external_execution_allowed' => false,
                'external_side_effects_enabled' => false,
            ];
            $row['company_external_tool_activation_work_order_status_record_hash'] = MissionCanonicalHash::sha256($row);
            $companies[] = $row;
        }

        $readyCompanyCount = count(array_filter($companies, static fn (array $company): bool => (bool) ($company['external_tool_activation_work_orders_ready'] ?? false)));
        $payload = [
            'ok' => $companies !== [] && $readyCompanyCount === count($companies),
            'schema' => ExternalActionMandateRegistryService::ENTERPRISE_COMPANY_EXTERNAL_TOOL_ACTIVATION_WORK_ORDER_STATUS_SCHEMA,
            'status' => $companies !== [] && $readyCompanyCount === count($companies)
                ? 'enterprise_company_external_tool_activation_work_orders_ready_external_effects_blocked'
                : 'enterprise_company_external_tool_activation_work_orders_attention_required',
            'generated_at' => now()->toJSON(),
            'summary' => [
                'company_count' => count($companies),
                'external_tool_activation_work_orders_ready_company_count' => $readyCompanyCount,
                'attention_company_count' => count($companies) - $readyCompanyCount,
                'expected_external_tool_activation_work_order_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['expected_external_tool_activation_work_order_count'] ?? 0), $companies)),
                'persisted_external_tool_activation_work_order_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['persisted_external_tool_activation_work_order_count'] ?? 0), $companies)),
                'ready_external_tool_activation_work_order_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['ready_external_tool_activation_work_order_count'] ?? 0), $companies)),
                'required_gate_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['required_gate_count'] ?? 0), $companies)),
                'ready_gate_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['ready_gate_count'] ?? 0), $companies)),
                'external_execution_allowed_count' => 0,
                'external_side_effects_enabled_count' => 0,
            ],
            'source_hashes' => [
                'enterprise_company_supervised_connector_execution_status_hash' => $supervisedExecution['enterprise_company_supervised_connector_execution_status_hash'] ?? null,
            ],
            'companies' => $companies,
            'policy' => $this->externalToolActivationWorkOrderPolicy(),
        ];
        $payload['enterprise_company_external_tool_activation_work_order_status_hash'] = MissionCanonicalHash::sha256($payload);

        return $this->hub->runtimeStatusCache[$cacheKey] = $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function enterpriseCompanyExternalToolActivationPacketRegister(?string $companyId = null): array
    {
        $wantedCompany = $companyId !== null && trim($companyId) !== '' ? trim($companyId) : null;
        unset($this->hub->runtimeStatusCache['external_tool_activation_packet_status:'.($wantedCompany ?? '*')]);
        unset($this->hub->runtimeStatusCache['vertical_tool_operating_runtime_status:'.($wantedCompany ?? '*')]);
        $workOrders = $this->enterpriseCompanyExternalToolActivationWorkOrderStatus($wantedCompany);
        $workOrdersByCompany = $this->hub->companyRowsById($workOrders);

        if (! $this->hub->approval->toolRunsTableAvailable()) {
            return [
                'ok' => false,
                'schema' => ExternalActionMandateRegistryService::ENTERPRISE_COMPANY_EXTERNAL_TOOL_ACTIVATION_PACKET_REGISTER_SCHEMA,
                'status' => 'atlas_tool_runs_table_unavailable',
                'generated_at' => now()->toJSON(),
                'summary' => [
                    'company_count' => 0,
                    'expected_external_tool_activation_packet_count' => 0,
                    'registered_external_tool_activation_packet_count' => 0,
                    'external_execution_allowed_count' => 0,
                    'external_side_effects_enabled_count' => 0,
                ],
                'companies' => [],
                'policy' => $this->externalToolActivationPacketPolicy(),
            ];
        }

        $companies = [];
        foreach ($this->hub->buildoutCompanies($wantedCompany) as $company) {
            $id = (string) ($company['company_id'] ?? 'unknown');
            $workOrderRow = (array) ($workOrdersByCompany[$id] ?? []);
            $registeredPackets = [];

            foreach ((array) ($workOrderRow['work_order_records'] ?? []) as $workOrderRecord) {
                $flowId = (string) ($workOrderRecord['flow_id'] ?? '');
                $capabilityId = (string) ($workOrderRecord['capability_id'] ?? '');
                $connectorId = (string) ($workOrderRecord['connector_id'] ?? '');
                $workOrderId = (string) ($workOrderRecord['work_order_id'] ?? '');
                if ($flowId === '' || $capabilityId === '' || $connectorId === '' || $workOrderId === '') {
                    continue;
                }

                $runContextId = $id.'.'.substr(MissionCanonicalHash::sha256([
                    'flow_id' => $flowId,
                    'capability_id' => $capabilityId,
                    'connector_id' => $connectorId,
                    'work_order_id' => $workOrderId,
                    'context' => 'external_tool_activation_packet',
                ]), 0, 24).'.external_tool_activation_packet.v1';
                $artifact = $this->externalToolActivationPacketArtifact($company, (array) $workOrderRecord, $runContextId);
                $qualityGates = [
                    'activation_work_order_ready' => (bool) ($workOrderRecord['ready'] ?? false)
                        && strlen((string) ($workOrderRecord['external_tool_activation_work_order_status_record_hash'] ?? '')) === 64,
                    'activation_packet_artifact_bound' => strlen((string) ($artifact['external_tool_activation_packet_artifact_hash'] ?? '')) === 64,
                    'vault_binding_contract_bound' => strlen((string) data_get($artifact, 'vault_binding_contract.vault_binding_contract_hash', '')) === 64
                        && (bool) data_get($artifact, 'vault_binding_contract.credential_material_in_packet_allowed', true) === false,
                    'sandbox_probe_bound' => strlen((string) data_get($artifact, 'sandbox_probe.sandbox_probe_hash', '')) === 64
                        && count((array) data_get($artifact, 'sandbox_probe.probe_modes', [])) >= 5,
                    'eval_replay_bound' => strlen((string) data_get($artifact, 'eval_replay.eval_replay_hash', '')) === 64
                        && count((array) data_get($artifact, 'eval_replay.replay_assertions', [])) >= 6,
                    'observability_slo_bound' => strlen((string) data_get($artifact, 'observability_slo.observability_slo_hash', '')) === 64
                        && count((array) data_get($artifact, 'observability_slo.required_metrics', [])) >= 7,
                    'handoff_runbook_bound' => strlen((string) data_get($artifact, 'operator_handoff_runbook.operator_handoff_runbook_hash', '')) === 64
                        && (bool) data_get($artifact, 'operator_handoff_runbook.operator_acceptance_required', false),
                    'receipt_chain_bound' => strlen((string) data_get($artifact, 'receipt_chain.activation_packet_receipt_hash', '')) === 64
                        && strlen((string) data_get($artifact, 'receipt_chain.sandbox_probe_receipt_hash', '')) === 64
                        && strlen((string) data_get($artifact, 'receipt_chain.eval_replay_receipt_hash', '')) === 64,
                    'external_effects_blocked' => ! (bool) ($artifact['external_execution_allowed'] ?? true)
                        && ! (bool) ($artifact['external_side_effects_enabled'] ?? true),
                ];
                $summary = [
                    'company_id' => $id,
                    'flow_id' => $flowId,
                    'capability_id' => $capabilityId,
                    'connector_id' => $connectorId,
                    'work_order_id' => $workOrderId,
                    'activation_packet_id' => (string) ($artifact['activation_packet_id'] ?? ''),
                    'activation_mode' => 'vault_bound_sandbox_probe_eval_replay_operator_handoff_no_external_effect',
                    'quality_gate_count' => count($qualityGates),
                    'ready_quality_gate_count' => count(array_filter($qualityGates)),
                    'external_execution_allowed' => false,
                    'external_side_effects_enabled' => false,
                ];
                $normalized = [
                    'schema' => 'atlas.ai.company.external_tool_activation_packet_result.v1',
                    'status' => count(array_filter($qualityGates)) === count($qualityGates) ? 'passed' : 'attention_required',
                    'summary' => $summary,
                    'external_tool_activation_packet_artifact' => $artifact,
                    'quality_gates' => $qualityGates,
                    'blocking_failures' => array_values(array_keys(array_filter($qualityGates, static fn (bool $ready): bool => ! $ready))),
                ];
                $receiptInput = [
                    'company_id' => $id,
                    'flow_id' => $flowId,
                    'capability_id' => $capabilityId,
                    'connector_id' => $connectorId,
                    'work_order_id' => $workOrderId,
                    'run_context_id' => $runContextId,
                    'activation_packet_id' => (string) ($artifact['activation_packet_id'] ?? ''),
                    'artifact_hash' => (string) ($artifact['external_tool_activation_packet_artifact_hash'] ?? ''),
                ];
                $metadata = [
                    'source' => 'enterprise_company_external_tool_activation_packet_register',
                    'receipt_schema_version' => 'atlas.company_external_tool_activation_packet_receipt.v1',
                    'evidence_receipt_hash' => MissionCanonicalHash::sha256($normalized),
                    'activation_packet_receipt_hash' => MissionCanonicalHash::sha256($receiptInput + ['receipt_type' => 'activation_packet_receipt']),
                    'vault_binding_receipt_hash' => MissionCanonicalHash::sha256($receiptInput + ['receipt_type' => 'vault_binding_receipt']),
                    'sandbox_probe_receipt_hash' => MissionCanonicalHash::sha256($receiptInput + ['receipt_type' => 'sandbox_probe_receipt']),
                    'eval_replay_receipt_hash' => MissionCanonicalHash::sha256($receiptInput + ['receipt_type' => 'eval_replay_receipt']),
                    'observability_slo_receipt_hash' => MissionCanonicalHash::sha256($receiptInput + ['receipt_type' => 'observability_slo_receipt']),
                    'operator_handoff_receipt_hash' => MissionCanonicalHash::sha256($receiptInput + ['receipt_type' => 'operator_handoff_receipt']),
                    'audit_trail_hash' => MissionCanonicalHash::sha256($receiptInput + ['receipt_type' => 'audit_trail']),
                    'rollback_plan_hash' => MissionCanonicalHash::sha256($receiptInput + ['receipt_type' => 'rollback_plan']),
                    'external_execution_allowed' => false,
                    'external_side_effects_enabled' => false,
                    'operator_signed_scope_required_for_external_effect' => true,
                    'action_runtime_contract' => [
                        'schema_version' => 'atlas.company_external_tool_activation_packet.contract.v1',
                        'mode' => 'vault_bound_sandbox_probe_eval_replay_operator_handoff_no_external_effect',
                        'run_context_type' => 'holding_company_external_tool_activation_packet',
                        'run_context_id' => $runContextId,
                        'provider_dispatch_allowed' => false,
                        'external_write_allowed' => false,
                        'customer_message_allowed' => false,
                        'billing_or_capital_action_allowed' => false,
                        'trade_allowed' => false,
                        'deploy_allowed' => false,
                        'security_action_allowed' => false,
                        'operator_approval_required_for_external_effect' => true,
                    ],
                ];

                $run = AtlasToolRun::query()->updateOrCreate(
                    [
                        'surface' => 'holding_company_external_tool_activation_packet',
                        'run_context_type' => 'holding_company_external_tool_activation_packet',
                        'run_context_id' => $runContextId,
                    ],
                    [
                        'tool_slug' => 'atlas_company_external_tool_activation_packet',
                        'workspace_hash' => hash('sha256', base_path()),
                        'workspace' => base_path(),
                        'status' => $normalized['status'] === 'passed' ? 'passed' : 'failed',
                        'required' => true,
                        'failure_policy' => 'fail_closed',
                        'policy_decision' => 'activation_packet_registered_blocked',
                        'command_hash' => MissionCanonicalHash::sha256([
                            'run_context_id' => $runContextId,
                            'artifact_hash' => $artifact['external_tool_activation_packet_artifact_hash'] ?? null,
                        ]),
                        'exit_code' => $normalized['status'] === 'passed' ? 0 : 1,
                        'started_at' => now(),
                        'finished_at' => now(),
                        'duration_ms' => 0,
                        'summary_json' => $summary,
                        'normalized_result_json' => $normalized,
                        'policy_decision_json' => [
                            'external_execution_allowed' => false,
                            'external_side_effects_enabled' => false,
                            'blocked_operations' => $this->externalToolActivationPacketPolicy()['blocked_operations'],
                        ],
                        'metadata_json' => $metadata,
                    ],
                );

                $registeredPackets[] = [
                    'run_id' => (string) $run->id,
                    'run_context_id' => $runContextId,
                    'activation_packet_id' => (string) ($artifact['activation_packet_id'] ?? ''),
                    'work_order_id' => $workOrderId,
                    'flow_id' => $flowId,
                    'capability_id' => $capabilityId,
                    'connector_id' => $connectorId,
                    'status' => (string) $run->status,
                    'policy_decision' => (string) $run->policy_decision,
                    'activation_packet_receipt_hash' => (string) data_get($run->metadata_json, 'activation_packet_receipt_hash', ''),
                    'external_execution_allowed' => false,
                    'external_side_effects_enabled' => false,
                ];
            }

            $row = [
                'schema' => 'atlas.ai.company.enterprise_external_tool_activation_packet_register_record.v1',
                'company_id' => $id,
                'expected_external_tool_activation_work_order_count' => (int) ($workOrderRow['ready_external_tool_activation_work_order_count'] ?? 0),
                'registered_external_tool_activation_packet_count' => count($registeredPackets),
                'registered_packets' => $registeredPackets,
                'external_execution_allowed' => false,
                'external_side_effects_enabled' => false,
            ];
            $row['company_external_tool_activation_packet_register_record_hash'] = MissionCanonicalHash::sha256($row);
            $companies[] = $row;
        }

        $registeredPacketCount = array_sum(array_map(static fn (array $company): int => (int) ($company['registered_external_tool_activation_packet_count'] ?? 0), $companies));
        $expectedPacketCount = array_sum(array_map(static fn (array $company): int => (int) ($company['expected_external_tool_activation_work_order_count'] ?? 0), $companies));
        $payload = [
            'ok' => $expectedPacketCount > 0 && $registeredPacketCount >= $expectedPacketCount,
            'schema' => ExternalActionMandateRegistryService::ENTERPRISE_COMPANY_EXTERNAL_TOOL_ACTIVATION_PACKET_REGISTER_SCHEMA,
            'status' => $expectedPacketCount > 0 && $registeredPacketCount >= $expectedPacketCount
                ? 'enterprise_company_external_tool_activation_packets_registered_external_effects_blocked'
                : 'enterprise_company_external_tool_activation_packets_attention_required',
            'generated_at' => now()->toJSON(),
            'summary' => [
                'company_count' => count($companies),
                'expected_external_tool_activation_packet_count' => $expectedPacketCount,
                'registered_external_tool_activation_packet_count' => $registeredPacketCount,
                'external_execution_allowed_count' => 0,
                'external_side_effects_enabled_count' => 0,
            ],
            'source_hashes' => [
                'enterprise_company_external_tool_activation_work_order_status_hash' => $workOrders['enterprise_company_external_tool_activation_work_order_status_hash'] ?? null,
            ],
            'companies' => $companies,
            'policy' => $this->externalToolActivationPacketPolicy(),
        ];
        $payload['enterprise_company_external_tool_activation_packet_register_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function enterpriseCompanyExternalToolActivationPacketStatus(?string $companyId = null): array
    {
        $wantedCompany = $companyId !== null && trim($companyId) !== '' ? trim($companyId) : null;
        $cacheKey = 'external_tool_activation_packet_status:'.($wantedCompany ?? '*');
        if (isset($this->hub->runtimeStatusCache[$cacheKey])) {
            return $this->hub->runtimeStatusCache[$cacheKey];
        }

        $workOrders = $this->enterpriseCompanyExternalToolActivationWorkOrderStatus($wantedCompany);
        $workOrdersByCompany = $this->hub->companyRowsById($workOrders);

        if (! $this->hub->approval->toolRunsTableAvailable()) {
            return [
                'ok' => false,
                'schema' => ExternalActionMandateRegistryService::ENTERPRISE_COMPANY_EXTERNAL_TOOL_ACTIVATION_PACKET_STATUS_SCHEMA,
                'status' => 'atlas_tool_runs_table_unavailable',
                'generated_at' => now()->toJSON(),
                'summary' => [
                    'company_count' => 0,
                    'expected_external_tool_activation_packet_count' => 0,
                    'persisted_external_tool_activation_packet_count' => 0,
                    'ready_external_tool_activation_packet_count' => 0,
                    'external_execution_allowed_count' => 0,
                    'external_side_effects_enabled_count' => 0,
                ],
                'companies' => [],
                'policy' => $this->externalToolActivationPacketPolicy(),
            ];
        }

        $companies = [];
        foreach ($this->hub->buildoutCompanies($wantedCompany) as $company) {
            $id = (string) ($company['company_id'] ?? 'unknown');
            $workOrderRow = (array) ($workOrdersByCompany[$id] ?? []);
            $packetRecords = [];

            foreach ((array) ($workOrderRow['work_order_records'] ?? []) as $workOrderRecord) {
                $flowId = (string) ($workOrderRecord['flow_id'] ?? '');
                $capabilityId = (string) ($workOrderRecord['capability_id'] ?? '');
                $connectorId = (string) ($workOrderRecord['connector_id'] ?? '');
                $workOrderId = (string) ($workOrderRecord['work_order_id'] ?? '');
                if ($flowId === '' || $capabilityId === '' || $connectorId === '' || $workOrderId === '') {
                    continue;
                }

                $runContextId = $id.'.'.substr(MissionCanonicalHash::sha256([
                    'flow_id' => $flowId,
                    'capability_id' => $capabilityId,
                    'connector_id' => $connectorId,
                    'work_order_id' => $workOrderId,
                    'context' => 'external_tool_activation_packet',
                ]), 0, 24).'.external_tool_activation_packet.v1';
                $run = AtlasToolRun::query()
                    ->where('surface', 'holding_company_external_tool_activation_packet')
                    ->where('run_context_type', 'holding_company_external_tool_activation_packet')
                    ->where('run_context_id', $runContextId)
                    ->latest('updated_at')
                    ->first();

                $recordGates = [
                    'persisted_external_tool_activation_packet_exists' => $run instanceof AtlasToolRun,
                    'status_passed' => $run instanceof AtlasToolRun && $run->status === 'passed',
                    'policy_activation_packet_registered' => $run instanceof AtlasToolRun && $run->policy_decision === 'activation_packet_registered_blocked',
                    'normalized_result_bound' => $run instanceof AtlasToolRun && data_get($run->normalized_result_json, 'schema') === 'atlas.ai.company.external_tool_activation_packet_result.v1',
                    'activation_packet_artifact_bound' => $run instanceof AtlasToolRun
                        && data_get($run->normalized_result_json, 'external_tool_activation_packet_artifact.schema') === 'atlas.ai.company.external_tool_activation_packet_artifact.v1'
                        && strlen((string) data_get($run->normalized_result_json, 'external_tool_activation_packet_artifact.external_tool_activation_packet_artifact_hash', '')) === 64,
                    'receipt_metadata_bound' => $run instanceof AtlasToolRun
                        && strlen((string) data_get($run->metadata_json, 'activation_packet_receipt_hash', '')) === 64
                        && strlen((string) data_get($run->metadata_json, 'vault_binding_receipt_hash', '')) === 64
                        && strlen((string) data_get($run->metadata_json, 'sandbox_probe_receipt_hash', '')) === 64
                        && strlen((string) data_get($run->metadata_json, 'eval_replay_receipt_hash', '')) === 64
                        && strlen((string) data_get($run->metadata_json, 'observability_slo_receipt_hash', '')) === 64
                        && strlen((string) data_get($run->metadata_json, 'operator_handoff_receipt_hash', '')) === 64
                        && strlen((string) data_get($run->metadata_json, 'audit_trail_hash', '')) === 64
                        && strlen((string) data_get($run->metadata_json, 'rollback_plan_hash', '')) === 64,
                    'activation_controls_bound' => $run instanceof AtlasToolRun
                        && strlen((string) data_get($run->normalized_result_json, 'external_tool_activation_packet_artifact.vault_binding_contract.vault_binding_contract_hash', '')) === 64
                        && strlen((string) data_get($run->normalized_result_json, 'external_tool_activation_packet_artifact.sandbox_probe.sandbox_probe_hash', '')) === 64
                        && strlen((string) data_get($run->normalized_result_json, 'external_tool_activation_packet_artifact.eval_replay.eval_replay_hash', '')) === 64,
                    'critical_operations_blocked' => $run instanceof AtlasToolRun
                        && in_array('credential_material_export', (array) data_get($run->policy_decision_json, 'blocked_operations', []), true)
                        && in_array('external_write', (array) data_get($run->policy_decision_json, 'blocked_operations', []), true)
                        && in_array('trade', (array) data_get($run->policy_decision_json, 'blocked_operations', []), true)
                        && in_array('deploy', (array) data_get($run->policy_decision_json, 'blocked_operations', []), true),
                    'external_effects_blocked' => $run instanceof AtlasToolRun
                        && ! (bool) data_get($run->metadata_json, 'external_execution_allowed', true)
                        && ! (bool) data_get($run->metadata_json, 'external_side_effects_enabled', true),
                ];
                $readyRecordGateCount = count(array_filter($recordGates));
                $packetRecord = [
                    'schema' => 'atlas.ai.company.external_tool_activation_packet_status_record.v1',
                    'company_id' => $id,
                    'flow_id' => $flowId,
                    'capability_id' => $capabilityId,
                    'connector_id' => $connectorId,
                    'work_order_id' => $workOrderId,
                    'run_context_id' => $runContextId,
                    'activation_packet_id' => $run instanceof AtlasToolRun ? (string) data_get($run->summary_json, 'activation_packet_id', '') : '',
                    'tool_run_id' => $run instanceof AtlasToolRun ? (string) $run->id : null,
                    'ready' => $readyRecordGateCount === count($recordGates),
                    'ready_gate_count' => $readyRecordGateCount,
                    'required_gate_count' => count($recordGates),
                    'gates' => $recordGates,
                    'missing_gates' => array_values(array_keys(array_filter($recordGates, static fn (bool $ready): bool => ! $ready))),
                    'receipt_chain' => $run instanceof AtlasToolRun ? [
                        'activation_packet_receipt_hash' => (string) data_get($run->metadata_json, 'activation_packet_receipt_hash', ''),
                        'vault_binding_receipt_hash' => (string) data_get($run->metadata_json, 'vault_binding_receipt_hash', ''),
                        'sandbox_probe_receipt_hash' => (string) data_get($run->metadata_json, 'sandbox_probe_receipt_hash', ''),
                        'eval_replay_receipt_hash' => (string) data_get($run->metadata_json, 'eval_replay_receipt_hash', ''),
                        'observability_slo_receipt_hash' => (string) data_get($run->metadata_json, 'observability_slo_receipt_hash', ''),
                        'operator_handoff_receipt_hash' => (string) data_get($run->metadata_json, 'operator_handoff_receipt_hash', ''),
                        'audit_trail_hash' => (string) data_get($run->metadata_json, 'audit_trail_hash', ''),
                        'rollback_plan_hash' => (string) data_get($run->metadata_json, 'rollback_plan_hash', ''),
                    ] : [],
                    'external_execution_allowed' => false,
                    'external_side_effects_enabled' => false,
                ];
                $packetRecord['external_tool_activation_packet_status_record_hash'] = MissionCanonicalHash::sha256($packetRecord);
                $packetRecords[] = $packetRecord;
            }

            $readyPacketCount = count(array_filter($packetRecords, static fn (array $record): bool => (bool) ($record['ready'] ?? false)));
            $expectedPacketCount = (int) ($workOrderRow['ready_external_tool_activation_work_order_count'] ?? 0);
            $gates = [
                'external_tool_activation_work_orders_ready' => (bool) ($workOrderRow['external_tool_activation_work_orders_ready'] ?? false),
                'external_tool_activation_packets_cover_work_orders' => $expectedPacketCount > 0
                    && count($packetRecords) >= $expectedPacketCount
                    && $readyPacketCount === count($packetRecords),
                'external_effects_blocked' => count(array_filter($packetRecords, static fn (array $record): bool => (bool) ($record['external_execution_allowed'] ?? true)
                    || (bool) ($record['external_side_effects_enabled'] ?? true))) === 0,
            ];
            $readyGateCount = count(array_filter($gates));
            $row = [
                'schema' => 'atlas.ai.company.enterprise_external_tool_activation_packet_company_status.v1',
                'company_id' => $id,
                'expected_external_tool_activation_packet_count' => $expectedPacketCount,
                'persisted_external_tool_activation_packet_count' => count($packetRecords),
                'ready_external_tool_activation_packet_count' => $readyPacketCount,
                'external_tool_activation_packets_ready' => $expectedPacketCount > 0 && $readyGateCount === count($gates),
                'runtime_grade' => $expectedPacketCount > 0 && $readyGateCount === count($gates)
                    ? 'target_9_external_tool_activation_packets_ready_external_effects_blocked'
                    : 'external_tool_activation_packets_attention_required',
                'ready_gate_count' => $readyGateCount,
                'required_gate_count' => count($gates),
                'gates' => $gates,
                'missing_gates' => array_values(array_keys(array_filter($gates, static fn (bool $ready): bool => ! $ready))),
                'packet_records' => $packetRecords,
                'external_execution_allowed' => false,
                'external_side_effects_enabled' => false,
            ];
            $row['company_external_tool_activation_packet_status_record_hash'] = MissionCanonicalHash::sha256($row);
            $companies[] = $row;
        }

        $readyCompanyCount = count(array_filter($companies, static fn (array $company): bool => (bool) ($company['external_tool_activation_packets_ready'] ?? false)));
        $payload = [
            'ok' => $companies !== [] && $readyCompanyCount === count($companies),
            'schema' => ExternalActionMandateRegistryService::ENTERPRISE_COMPANY_EXTERNAL_TOOL_ACTIVATION_PACKET_STATUS_SCHEMA,
            'status' => $companies !== [] && $readyCompanyCount === count($companies)
                ? 'enterprise_company_external_tool_activation_packets_ready_external_effects_blocked'
                : 'enterprise_company_external_tool_activation_packets_attention_required',
            'generated_at' => now()->toJSON(),
            'summary' => [
                'company_count' => count($companies),
                'external_tool_activation_packets_ready_company_count' => $readyCompanyCount,
                'attention_company_count' => count($companies) - $readyCompanyCount,
                'expected_external_tool_activation_packet_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['expected_external_tool_activation_packet_count'] ?? 0), $companies)),
                'persisted_external_tool_activation_packet_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['persisted_external_tool_activation_packet_count'] ?? 0), $companies)),
                'ready_external_tool_activation_packet_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['ready_external_tool_activation_packet_count'] ?? 0), $companies)),
                'required_gate_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['required_gate_count'] ?? 0), $companies)),
                'ready_gate_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['ready_gate_count'] ?? 0), $companies)),
                'external_execution_allowed_count' => 0,
                'external_side_effects_enabled_count' => 0,
            ],
            'source_hashes' => [
                'enterprise_company_external_tool_activation_work_order_status_hash' => $workOrders['enterprise_company_external_tool_activation_work_order_status_hash'] ?? null,
            ],
            'companies' => $companies,
            'policy' => $this->externalToolActivationPacketPolicy(),
        ];
        $payload['enterprise_company_external_tool_activation_packet_status_hash'] = MissionCanonicalHash::sha256($payload);

        return $this->hub->runtimeStatusCache[$cacheKey] = $payload;
    }

    /**
     * @param array<string,mixed> $company
     * @param array<string,mixed> $supervisedRecord
     * @return array<string,mixed>
     */
    public function externalToolActivationWorkOrderArtifact(array $company, array $supervisedRecord, string $runContextId): array
    {
        $companyId = (string) ($company['company_id'] ?? 'unknown');
        $flowId = (string) ($supervisedRecord['flow_id'] ?? '');
        $capabilityId = (string) ($supervisedRecord['capability_id'] ?? '');
        $connectorId = (string) ($supervisedRecord['connector_id'] ?? '');
        $capability = (array) ($this->hub->enterpriseCapabilityRuntime->enterpriseCapabilityRuntimeCatalog()[$capabilityId] ?? []);
        $sourceRecordHash = (string) ($supervisedRecord['supervised_connector_execution_status_record_hash'] ?? '');
        $workOrderId = MissionCanonicalHash::sha256([
            'type' => 'external_tool_activation_work_order',
            'company_id' => $companyId,
            'flow_id' => $flowId,
            'capability_id' => $capabilityId,
            'connector_id' => $connectorId,
            'source_supervised_connector_execution_status_record_hash' => $sourceRecordHash,
        ]);
        $requiredScopes = array_values(array_unique(array_filter(array_map(
            static fn (mixed $scope): string => (string) $scope,
            (array) ($capability['least_privilege_scopes'] ?? ['read_connector_metadata']),
        ))));
        if ($requiredScopes === []) {
            $requiredScopes = ['read_connector_metadata'];
        }
        $receiptInput = [
            'work_order_id' => $workOrderId,
            'company_id' => $companyId,
            'flow_id' => $flowId,
            'capability_id' => $capabilityId,
            'connector_id' => $connectorId,
            'source_record_hash' => $sourceRecordHash,
        ];

        $artifact = [
            'schema' => 'atlas.ai.company.external_tool_activation_work_order_artifact.v1',
            'work_order_id' => $workOrderId,
            'company_id' => $companyId,
            'flow_id' => $flowId,
            'capability_id' => $capabilityId,
            'connector_id' => $connectorId,
            'run_context_id' => $runContextId,
            'activation_mode' => 'operator_scoped_read_only_probe_work_order_no_external_effect',
            'source_lineage' => [
                'supervised_connector_execution_status_record_hash' => $sourceRecordHash,
                'source_tool_run_id' => (string) ($supervisedRecord['tool_run_id'] ?? ''),
                'source_run_context_id' => (string) ($supervisedRecord['run_context_id'] ?? ''),
                'source_lineage_hash' => MissionCanonicalHash::sha256($receiptInput + ['lineage' => 'external_tool_activation_work_order']),
            ],
            'credential_scope_requirements' => [
                'required_scopes' => $requiredScopes,
                'credential_reference_required' => true,
                'credential_material_in_packet_allowed' => false,
                'vault_scope_receipt_required' => true,
                'least_privilege_required' => true,
                'read_only_default' => true,
                'write_scope_requires_separate_operator_mandate' => true,
            ],
            'dry_run_operations' => [
                'resolve_connector_contract',
                'validate_vault_reference_placeholder',
                'verify_least_privilege_scope_map',
                'build_read_only_probe_request',
                'replay_fixture_or_schema_probe',
                'validate_source_lineage_and_freshness',
                'emit_receipts_without_provider_dispatch',
                'prepare_operator_handoff_packet',
            ],
            'allowed_read_probe_actions' => [
                'read_connector_metadata',
                'read_schema_or_capability_descriptor',
                'read_public_or_internal_approved_source',
                'read_fixture_replay_dataset',
                'read_rate_limit_and_health_status',
                'validate_non_secret_credential_reference',
            ],
            'forbidden_external_effect_actions' => [
                'credential_material_export',
                'external_write',
                'customer_message',
                'invoice',
                'collect_payment',
                'trade',
                'capital_transfer',
                'paid_campaign',
                'public_publish',
                'legal_signature',
                'deploy',
                'delete',
                'offensive_security',
                'private_data_export',
            ],
            'operator_mandate' => [
                'operator_mandate_required_for_external_effect' => true,
                'operator_signed_scope_required' => true,
                'second_reviewer_required_for_money_security_customer_or_deploy_effect' => true,
                'change_window_required' => true,
                'kill_switch_required' => true,
            ],
            'receipt_chain' => [
                'activation_work_order_receipt_hash' => MissionCanonicalHash::sha256($receiptInput + ['receipt_type' => 'activation_work_order_receipt']),
                'credential_scope_receipt_hash' => MissionCanonicalHash::sha256($receiptInput + ['receipt_type' => 'credential_scope_receipt']),
                'dry_run_operation_receipt_hash' => MissionCanonicalHash::sha256($receiptInput + ['receipt_type' => 'dry_run_operation_receipt']),
                'allowed_read_probe_receipt_hash' => MissionCanonicalHash::sha256($receiptInput + ['receipt_type' => 'allowed_read_probe_receipt']),
                'forbidden_external_effect_receipt_hash' => MissionCanonicalHash::sha256($receiptInput + ['receipt_type' => 'forbidden_external_effect_receipt']),
                'operator_mandate_receipt_hash' => MissionCanonicalHash::sha256($receiptInput + ['receipt_type' => 'operator_mandate_receipt']),
            ],
            'external_execution_allowed' => false,
            'external_side_effects_enabled' => false,
            'generated_at' => now()->toJSON(),
        ];
        $artifact['external_tool_activation_work_order_artifact_hash'] = MissionCanonicalHash::sha256($artifact);

        return $artifact;
    }

    /**
     * @param array<string,mixed> $company
     * @param array<string,mixed> $workOrderRecord
     * @return array<string,mixed>
     */
    public function externalToolActivationPacketArtifact(array $company, array $workOrderRecord, string $runContextId): array
    {
        $companyId = (string) ($company['company_id'] ?? 'unknown');
        $flowId = (string) ($workOrderRecord['flow_id'] ?? '');
        $capabilityId = (string) ($workOrderRecord['capability_id'] ?? '');
        $connectorId = (string) ($workOrderRecord['connector_id'] ?? '');
        $workOrderId = (string) ($workOrderRecord['work_order_id'] ?? '');
        $capability = (array) ($this->hub->enterpriseCapabilityRuntime->enterpriseCapabilityRuntimeCatalog()[$capabilityId] ?? []);
        $sourceRecordHash = (string) ($workOrderRecord['external_tool_activation_work_order_status_record_hash'] ?? '');
        $activationPacketId = MissionCanonicalHash::sha256([
            'type' => 'external_tool_activation_packet',
            'company_id' => $companyId,
            'flow_id' => $flowId,
            'capability_id' => $capabilityId,
            'connector_id' => $connectorId,
            'work_order_id' => $workOrderId,
            'source_external_tool_activation_work_order_status_record_hash' => $sourceRecordHash,
        ]);
        $receiptInput = [
            'activation_packet_id' => $activationPacketId,
            'work_order_id' => $workOrderId,
            'company_id' => $companyId,
            'flow_id' => $flowId,
            'capability_id' => $capabilityId,
            'connector_id' => $connectorId,
            'source_record_hash' => $sourceRecordHash,
        ];
        $requiredScopes = array_values(array_unique(array_filter(array_map(
            static fn (mixed $scope): string => (string) $scope,
            (array) ($capability['least_privilege_scopes'] ?? ['read_connector_metadata']),
        ))));
        if ($requiredScopes === []) {
            $requiredScopes = ['read_connector_metadata'];
        }

        $vaultBinding = [
            'schema' => 'atlas.ai.company.external_tool_activation_packet_vault_binding.v1',
            'credential_reference_required' => true,
            'credential_reference_id' => $companyId.'.'.$connectorId.'.readonly.vault_ref',
            'required_scopes' => $requiredScopes,
            'credential_material_in_packet_allowed' => false,
            'secret_export_allowed' => false,
            'rotation_policy_required' => true,
            'least_privilege_attestation_required' => true,
        ];
        $vaultBinding['vault_binding_contract_hash'] = MissionCanonicalHash::sha256($vaultBinding);

        $sandboxProbe = [
            'schema' => 'atlas.ai.company.external_tool_activation_packet_sandbox_probe.v1',
            'probe_modes' => [
                'contract_schema_probe',
                'auth_boundary_probe',
                'rate_limit_probe',
                'fixture_replay_probe',
                'read_only_live_health_probe',
                'lineage_freshness_probe',
            ],
            'external_mutation_allowed' => false,
            'credential_material_observed' => false,
            'sandbox_or_fixture_first' => true,
            'green_status_required_before_live_read' => true,
        ];
        $sandboxProbe['sandbox_probe_hash'] = MissionCanonicalHash::sha256($sandboxProbe + $receiptInput);

        $evalReplay = [
            'schema' => 'atlas.ai.company.external_tool_activation_packet_eval_replay.v1',
            'replay_assertions' => [
                'source_lineage_preserved',
                'schema_contract_preserved',
                'read_only_scope_preserved',
                'unsupported_claim_blocked',
                'external_effect_blocked',
                'operator_handoff_emitted',
                'receipt_chain_complete',
            ],
            'minimum_replay_pass_rate' => 1.0,
            'fixture_replay_required' => true,
            'regression_case_required' => true,
        ];
        $evalReplay['eval_replay_hash'] = MissionCanonicalHash::sha256($evalReplay + $receiptInput);

        $observabilitySlo = [
            'schema' => 'atlas.ai.company.external_tool_activation_packet_observability_slo.v1',
            'required_metrics' => [
                'request_count',
                'error_count',
                'latency_ms_p95',
                'rate_limit_remaining',
                'credential_scope_mismatch_count',
                'external_mutation_attempt_block_count',
                'receipt_emission_count',
                'operator_handoff_count',
            ],
            'alert_routes' => ['operator_console', 'evidence_ledger', 'control_tower'],
            'fail_closed_on_missing_receipt' => true,
            'kill_switch_required' => true,
        ];
        $observabilitySlo['observability_slo_hash'] = MissionCanonicalHash::sha256($observabilitySlo + $receiptInput);

        $handoffRunbook = [
            'schema' => 'atlas.ai.company.external_tool_activation_packet_operator_handoff_runbook.v1',
            'operator_acceptance_required' => true,
            'second_reviewer_required_for_money_security_customer_or_deploy_effect' => true,
            'allowed_pre_mandate_actions' => ['read_metadata', 'read_schema', 'sandbox_probe', 'fixture_replay', 'health_check'],
            'external_effect_requires_new_mandate' => true,
            'rollback_plan_required' => true,
        ];
        $handoffRunbook['operator_handoff_runbook_hash'] = MissionCanonicalHash::sha256($handoffRunbook + $receiptInput);

        $artifact = [
            'schema' => 'atlas.ai.company.external_tool_activation_packet_artifact.v1',
            'activation_packet_id' => $activationPacketId,
            'work_order_id' => $workOrderId,
            'company_id' => $companyId,
            'flow_id' => $flowId,
            'capability_id' => $capabilityId,
            'connector_id' => $connectorId,
            'run_context_id' => $runContextId,
            'activation_mode' => 'vault_bound_sandbox_probe_eval_replay_operator_handoff_no_external_effect',
            'source_lineage' => [
                'external_tool_activation_work_order_status_record_hash' => $sourceRecordHash,
                'source_tool_run_id' => (string) ($workOrderRecord['tool_run_id'] ?? ''),
                'source_run_context_id' => (string) ($workOrderRecord['run_context_id'] ?? ''),
                'source_lineage_hash' => MissionCanonicalHash::sha256($receiptInput + ['lineage' => 'external_tool_activation_packet']),
            ],
            'vault_binding_contract' => $vaultBinding,
            'sandbox_probe' => $sandboxProbe,
            'eval_replay' => $evalReplay,
            'observability_slo' => $observabilitySlo,
            'operator_handoff_runbook' => $handoffRunbook,
            'receipt_chain' => [
                'activation_packet_receipt_hash' => MissionCanonicalHash::sha256($receiptInput + ['receipt_type' => 'activation_packet_receipt']),
                'vault_binding_receipt_hash' => MissionCanonicalHash::sha256($receiptInput + ['receipt_type' => 'vault_binding_receipt']),
                'sandbox_probe_receipt_hash' => MissionCanonicalHash::sha256($receiptInput + ['receipt_type' => 'sandbox_probe_receipt']),
                'eval_replay_receipt_hash' => MissionCanonicalHash::sha256($receiptInput + ['receipt_type' => 'eval_replay_receipt']),
                'observability_slo_receipt_hash' => MissionCanonicalHash::sha256($receiptInput + ['receipt_type' => 'observability_slo_receipt']),
                'operator_handoff_receipt_hash' => MissionCanonicalHash::sha256($receiptInput + ['receipt_type' => 'operator_handoff_receipt']),
            ],
            'external_execution_allowed' => false,
            'external_side_effects_enabled' => false,
            'generated_at' => now()->toJSON(),
        ];
        $artifact['external_tool_activation_packet_artifact_hash'] = MissionCanonicalHash::sha256($artifact);

        return $artifact;
    }

    /**
     * @return array<string,mixed>
     */
    public function externalToolActivationWorkOrderPolicy(): array
    {
        return [
            'external_tool_activation_work_order_is_not_external_execution_authority' => true,
            'calendar_wait_blocker_enabled' => false,
            'external_execution_allowed' => false,
            'external_side_effects_enabled' => false,
            'operator_signed_scope_required_for_external_effect' => true,
            'runtime_mode' => 'operator_scoped_read_only_probe_work_order_no_external_effect',
            'required_runtime_evidence' => [
                'atlas_tool_run',
                'external_tool_activation_work_order_artifact',
                'credential_scope_requirements',
                'dry_run_operations',
                'allowed_read_probe_actions',
                'forbidden_external_effect_actions',
                'activation_work_order_receipt',
                'credential_scope_receipt',
                'dry_run_operation_receipt',
                'allowed_read_probe_receipt',
                'forbidden_external_effect_receipt',
                'operator_mandate_receipt',
                'audit_trail',
                'rollback_plan',
            ],
            'blocked_operations' => [
                'credential_material_export',
                'external_write',
                'customer_message',
                'invoice',
                'collect_payment',
                'trade',
                'capital_transfer',
                'paid_campaign',
                'public_publish',
                'legal_signature',
                'deploy',
                'delete',
                'offensive_security',
                'private_data_export',
                'skip_operator_mandate',
                'skip_receipt_binding',
                'skip_vault_scope',
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function externalToolActivationPacketPolicy(): array
    {
        return [
            'external_tool_activation_packet_is_not_external_execution_authority' => true,
            'calendar_wait_blocker_enabled' => false,
            'external_execution_allowed' => false,
            'external_side_effects_enabled' => false,
            'operator_signed_scope_required_for_external_effect' => true,
            'runtime_mode' => 'vault_bound_sandbox_probe_eval_replay_operator_handoff_no_external_effect',
            'required_runtime_evidence' => [
                'atlas_tool_run',
                'external_tool_activation_packet_artifact',
                'vault_binding_contract',
                'sandbox_probe',
                'eval_replay',
                'observability_slo',
                'operator_handoff_runbook',
                'activation_packet_receipt',
                'vault_binding_receipt',
                'sandbox_probe_receipt',
                'eval_replay_receipt',
                'observability_slo_receipt',
                'operator_handoff_receipt',
                'audit_trail',
                'rollback_plan',
            ],
            'blocked_operations' => [
                'credential_material_export',
                'external_write',
                'customer_message',
                'invoice',
                'collect_payment',
                'trade',
                'capital_transfer',
                'paid_campaign',
                'public_publish',
                'legal_signature',
                'deploy',
                'delete',
                'offensive_security',
                'private_data_export',
                'skip_vault_binding',
                'skip_sandbox_probe',
                'skip_eval_replay',
                'skip_observability_slo',
                'skip_operator_handoff',
                'skip_receipt_binding',
            ],
        ];
    }
}
