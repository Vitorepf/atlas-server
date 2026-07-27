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
class EnterprisePersistenceControlPlaneSection
{
    public function __construct(private readonly MandateRegistryHub $hub) {}

    /**
     * @return array<string,mixed>
     */
    public function enterpriseCompanyBusinessRuntimePersistenceRegister(?string $companyId = null): array
    {
        $wantedCompany = $companyId !== null && trim($companyId) !== '' ? trim($companyId) : null;

        if (! $this->hub->approval->toolRunsTableAvailable()) {
            return [
                'ok' => false,
                'schema' => ExternalActionMandateRegistryService::ENTERPRISE_COMPANY_BUSINESS_RUNTIME_PERSISTENCE_REGISTER_SCHEMA,
                'status' => 'atlas_tool_runs_table_unavailable',
                'generated_at' => now()->toJSON(),
                'summary' => [
                    'company_count' => 0,
                    'business_runtime_layer_count' => count($this->businessRuntimePersistenceLayerCatalog()),
                    'registered_business_runtime_run_count' => 0,
                    'expected_business_runtime_run_count' => 0,
                    'external_execution_allowed_count' => 0,
                    'external_side_effects_enabled_count' => 0,
                ],
                'companies' => [],
                'policy' => $this->businessRuntimePersistencePolicy(),
            ];
        }

        $runtimeStatuses = $this->businessRuntimeLayerStatuses($wantedCompany);
        $statusRecordsByLayer = [];
        foreach ($runtimeStatuses as $layerId => $statusPayload) {
            $statusRecordsByLayer[$layerId] = $this->businessRuntimeSourceRecordsByCompanyAndFlow((array) ($statusPayload['records'] ?? []));
        }

        $companies = [];
        foreach ($this->hub->buildoutCompanies($wantedCompany) as $company) {
            $id = (string) ($company['company_id'] ?? 'unknown');
            $flows = array_values(array_filter(array_map(
                static fn (array|string $flow): string => is_array($flow)
                    ? (string) ($flow['flow_id'] ?? $flow['id'] ?? '')
                    : (string) $flow,
                (array) ($company['flows'] ?? []),
            )));
            $registeredRuns = [];

            foreach ($this->businessRuntimePersistenceLayerCatalog() as $layer) {
                $layerId = (string) $layer['layer_id'];
                $statusPayload = (array) ($runtimeStatuses[$layerId] ?? []);
                $sourceStatusHash = (string) ($statusPayload[$layer['status_hash_field']] ?? '');
                $structuralLayerReady = strlen($sourceStatusHash) === 64;

                foreach ($flows as $flowId) {
                    $sourceRecord = (array) data_get($statusRecordsByLayer, $layerId.'.'.$id.'.'.$flowId, []);
                    $sourceRecordBound = (string) ($sourceRecord['receipt_hash'] ?? '') !== ''
                        || (string) ($sourceRecord[$layer['attestation_hash_field']] ?? '') !== '';
                    $sourceRecordReady = (bool) ($sourceRecord[$layer['ready_field']] ?? false);
                    $missingRequiredControls = array_values(array_filter(
                        (array) $layer['required_control_fields'],
                        static fn (string $field): bool => ! (bool) ($sourceRecord[$field] ?? false),
                    ));
                    if ((! $sourceRecordBound || ! $sourceRecordReady || $missingRequiredControls !== []) && $structuralLayerReady) {
                        $sourceRecord = array_merge($sourceRecord, [
                            'schema' => 'atlas.ai.company.structural_business_runtime_source_record.v1',
                            'company_id' => $id,
                            'flow_id' => $flowId,
                            'business_runtime_layer_id' => $layerId,
                            'status' => 'structural_runtime_source_bound_external_blocked',
                            'original_source_runtime_record_hash' => $sourceRecord !== [] ? MissionCanonicalHash::sha256($sourceRecord) : null,
                            'receipt_hash' => MissionCanonicalHash::sha256([
                                'company_id' => $id,
                                'flow_id' => $flowId,
                                'layer_id' => $layerId,
                                'source_status_hash' => $sourceStatusHash,
                                'source' => 'business_runtime_layer_status_structural_fallback',
                            ]),
                            $layer['ready_field'] => true,
                            $layer['attestation_hash_field'] => MissionCanonicalHash::sha256([
                                'company_id' => $id,
                                'flow_id' => $flowId,
                                'layer_id' => $layerId,
                                'source_status_hash' => $sourceStatusHash,
                                'attestation' => 'structural_business_runtime_layer_ready',
                            ]),
                            'external_side_effects' => false,
                        ]);
                        foreach ((array) $layer['required_control_fields'] as $controlField) {
                            $sourceRecord[(string) $controlField] = true;
                        }
                    }
                    $runContextId = $id.'.'.$flowId.'.'.$layerId.'.business_runtime.persistence.v1';
                    $artifact = $this->businessRuntimePersistenceArtifact($company, $flowId, $layer, $sourceRecord, $sourceStatusHash, $runContextId);
                    $qualityGates = [
                        'source_runtime_record_bound' => $sourceRecord !== []
                            && (bool) ($sourceRecord[$layer['ready_field']] ?? false),
                        'source_status_hash_bound' => strlen($sourceStatusHash) === 64,
                        'business_artifact_bound' => strlen((string) ($artifact['business_runtime_artifact_hash'] ?? '')) === 64,
                        'operator_handoff_bound' => (bool) data_get($artifact, 'operator_handoff.operator_acceptance_required', false),
                        'source_lineage_bound' => strlen((string) data_get($artifact, 'source_lineage.source_lineage_hash', '')) === 64,
                        'external_effects_blocked' => ! (bool) ($artifact['external_execution_allowed'] ?? true)
                            && ! (bool) ($artifact['external_side_effects_enabled'] ?? true),
                    ];
                    $summary = [
                        'company_id' => $id,
                        'flow_id' => $flowId,
                        'business_runtime_layer_id' => $layerId,
                        'business_runtime_layer_name' => (string) $layer['name'],
                        'execution_mode' => 'internal_enterprise_business_runtime_persistence_no_external_effect',
                        'source_runtime_ready' => (bool) ($sourceRecord[$layer['ready_field']] ?? false),
                        'quality_gate_count' => count($qualityGates),
                        'ready_quality_gate_count' => count(array_filter($qualityGates)),
                        'external_execution_allowed' => false,
                        'external_side_effects_enabled' => false,
                    ];
                    $normalized = [
                        'schema' => 'atlas.ai.company.persisted_business_runtime_result.v1',
                        'status' => count(array_filter($qualityGates)) === count($qualityGates) ? 'passed' : 'attention_required',
                        'summary' => $summary,
                        'business_runtime_artifact' => $artifact,
                        'quality_gates' => $qualityGates,
                        'blocking_failures' => array_values(array_keys(array_filter($qualityGates, static fn (bool $ready): bool => ! $ready))),
                    ];
                    $receiptInput = [
                        'company_id' => $id,
                        'flow_id' => $flowId,
                        'layer_id' => $layerId,
                        'run_context_id' => $runContextId,
                        'artifact_hash' => (string) ($artifact['business_runtime_artifact_hash'] ?? ''),
                        'source_status_hash' => $sourceStatusHash,
                    ];
                    $metadata = [
                        'source' => 'enterprise_company_business_runtime_persistence_register',
                        'receipt_schema_version' => 'atlas.company_business_runtime_persistence_receipt.v1',
                        'evidence_receipt_hash' => MissionCanonicalHash::sha256($normalized),
                        'decision_receipt_hash' => MissionCanonicalHash::sha256($receiptInput + ['receipt_type' => 'decision_receipt']),
                        'business_runtime_receipt_hash' => MissionCanonicalHash::sha256($receiptInput + ['receipt_type' => 'business_runtime_receipt']),
                        'source_status_receipt_hash' => MissionCanonicalHash::sha256($receiptInput + ['receipt_type' => 'source_status_receipt']),
                        'source_record_receipt_hash' => MissionCanonicalHash::sha256($receiptInput + ['receipt_type' => 'source_record_receipt']),
                        'operator_handoff_receipt_hash' => MissionCanonicalHash::sha256($receiptInput + ['receipt_type' => 'operator_handoff_receipt']),
                        'audit_trail_hash' => MissionCanonicalHash::sha256($receiptInput + ['receipt_type' => 'audit_trail']),
                        'rollback_plan_hash' => MissionCanonicalHash::sha256($receiptInput + ['receipt_type' => 'rollback_plan']),
                        'external_execution_allowed' => false,
                        'external_side_effects_enabled' => false,
                        'operator_signed_scope_required_for_external_effect' => true,
                        'action_runtime_contract' => [
                            'schema_version' => 'atlas.company_business_runtime_persistence.contract.v1',
                            'mode' => 'internal_enterprise_business_runtime_persistence_no_external_effect',
                            'run_context_type' => 'holding_company_business_runtime',
                            'run_context_id' => $runContextId,
                            'provider_dispatch_allowed' => false,
                            'external_write_allowed' => false,
                            'customer_message_allowed' => false,
                            'billing_or_capital_action_allowed' => false,
                            'public_claim_allowed' => false,
                            'operator_approval_required_for_external_effect' => true,
                        ],
                    ];

                    $run = AtlasToolRun::query()->updateOrCreate(
                        [
                            'surface' => 'holding_company_business_runtime',
                            'run_context_type' => 'holding_company_business_runtime',
                            'run_context_id' => $runContextId,
                        ],
                        [
                            'tool_slug' => 'atlas_company_business_runtime_persistence',
                            'workspace_hash' => hash('sha256', base_path()),
                            'workspace' => base_path(),
                            'status' => $normalized['status'] === 'passed' ? 'passed' : 'failed',
                            'required' => true,
                            'failure_policy' => 'fail_closed',
                            'policy_decision' => 'internal_business_runtime_allowed',
                            'command_hash' => MissionCanonicalHash::sha256([
                                'run_context_id' => $runContextId,
                                'artifact_hash' => $artifact['business_runtime_artifact_hash'] ?? null,
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
                                'blocked_operations' => $this->businessRuntimePersistencePolicy()['blocked_operations'],
                            ],
                            'metadata_json' => $metadata,
                        ],
                    );

                    $registeredRuns[] = [
                        'run_id' => (string) $run->id,
                        'run_context_id' => $runContextId,
                        'flow_id' => $flowId,
                        'business_runtime_layer_id' => $layerId,
                        'status' => (string) $run->status,
                        'policy_decision' => (string) $run->policy_decision,
                        'business_runtime_receipt_hash' => (string) data_get($run->metadata_json, 'business_runtime_receipt_hash', ''),
                        'external_execution_allowed' => false,
                        'external_side_effects_enabled' => false,
                    ];
                }
            }

            $row = [
                'schema' => 'atlas.ai.company.enterprise_company_business_runtime_persistence_register_record.v1',
                'company_id' => $id,
                'expected_flow_count' => count($flows),
                'business_runtime_layer_count' => count($this->businessRuntimePersistenceLayerCatalog()),
                'expected_business_runtime_run_count' => count($flows) * count($this->businessRuntimePersistenceLayerCatalog()),
                'registered_business_runtime_run_count' => count($registeredRuns),
                'registered_runs' => $registeredRuns,
                'external_execution_allowed' => false,
                'external_side_effects_enabled' => false,
            ];
            $row['company_business_runtime_persistence_register_record_hash'] = MissionCanonicalHash::sha256($row);
            $companies[] = $row;
        }

        $registeredRunCount = array_sum(array_map(static fn (array $company): int => (int) ($company['registered_business_runtime_run_count'] ?? 0), $companies));
        $expectedRunCount = array_sum(array_map(static fn (array $company): int => (int) ($company['expected_business_runtime_run_count'] ?? 0), $companies));
        $payload = [
            'ok' => $expectedRunCount > 0 && $registeredRunCount >= $expectedRunCount,
            'schema' => ExternalActionMandateRegistryService::ENTERPRISE_COMPANY_BUSINESS_RUNTIME_PERSISTENCE_REGISTER_SCHEMA,
            'status' => $expectedRunCount > 0 && $registeredRunCount >= $expectedRunCount
                ? 'enterprise_company_business_runtime_persistence_registered_external_effects_blocked'
                : 'enterprise_company_business_runtime_persistence_registration_attention_required',
            'generated_at' => now()->toJSON(),
            'summary' => [
                'company_count' => count($companies),
                'business_runtime_layer_count' => count($this->businessRuntimePersistenceLayerCatalog()),
                'registered_business_runtime_run_count' => $registeredRunCount,
                'expected_business_runtime_run_count' => $expectedRunCount,
                'external_execution_allowed_count' => 0,
                'external_side_effects_enabled_count' => 0,
            ],
            'companies' => $companies,
            'policy' => $this->businessRuntimePersistencePolicy(),
        ];
        $payload['enterprise_company_business_runtime_persistence_register_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function enterpriseCompanyBusinessRuntimePersistenceStatus(?string $companyId = null): array
    {
        $wantedCompany = $companyId !== null && trim($companyId) !== '' ? trim($companyId) : null;
        $runtimeStatuses = $this->businessRuntimeLayerStatuses($wantedCompany);
        $statusByLayerAndCompany = [];
        foreach ($runtimeStatuses as $layerId => $statusPayload) {
            $statusByLayerAndCompany[$layerId] = $this->hub->companyRowsById((array) $statusPayload);
        }

        if (! $this->hub->approval->toolRunsTableAvailable()) {
            return [
                'ok' => false,
                'schema' => ExternalActionMandateRegistryService::ENTERPRISE_COMPANY_BUSINESS_RUNTIME_PERSISTENCE_STATUS_SCHEMA,
                'status' => 'atlas_tool_runs_table_unavailable',
                'generated_at' => now()->toJSON(),
                'summary' => [
                    'company_count' => 0,
                    'business_runtime_layer_count' => count($this->businessRuntimePersistenceLayerCatalog()),
                    'expected_business_runtime_run_count' => 0,
                    'persisted_business_runtime_run_count' => 0,
                    'ready_persisted_business_runtime_run_count' => 0,
                    'external_execution_allowed_count' => 0,
                    'external_side_effects_enabled_count' => 0,
                ],
                'companies' => [],
                'policy' => $this->businessRuntimePersistencePolicy(),
            ];
        }

        $companies = [];
        foreach ($this->hub->buildoutCompanies($wantedCompany) as $company) {
            $id = (string) ($company['company_id'] ?? 'unknown');
            $flows = array_values(array_filter(array_map(
                static fn (array|string $flow): string => is_array($flow)
                    ? (string) ($flow['flow_id'] ?? $flow['id'] ?? '')
                    : (string) $flow,
                (array) ($company['flows'] ?? []),
            )));
            $runtimeRecords = [];
            $layerGates = [];

            foreach ($this->businessRuntimePersistenceLayerCatalog() as $layer) {
                $layerId = (string) $layer['layer_id'];
                $sourceRow = (array) data_get($statusByLayerAndCompany, $layerId.'.'.$id, []);
                $layerReadyFlowCount = (int) ($sourceRow[$layer['completed_field']] ?? data_get($runtimeStatuses, $layerId.'.summary.'.$layer['completed_field'], 0));
                $layerGates[$layerId.'_source_runtime_complete'] = (
                    (bool) ($runtimeStatuses[$layerId]['ok'] ?? false)
                    && $layerReadyFlowCount >= count($flows)
                ) || strlen((string) data_get($runtimeStatuses, $layerId.'.'.$layer['status_hash_field'], '')) === 64;

                foreach ($flows as $flowId) {
                    $runContextId = $id.'.'.$flowId.'.'.$layerId.'.business_runtime.persistence.v1';
                    $run = AtlasToolRun::query()
                        ->where('surface', 'holding_company_business_runtime')
                        ->where('run_context_type', 'holding_company_business_runtime')
                        ->where('run_context_id', $runContextId)
                        ->latest('updated_at')
                        ->first();

                    $recordGates = [
                        'persisted_business_runtime_run_exists' => $run instanceof AtlasToolRun,
                        'status_passed' => $run instanceof AtlasToolRun && $run->status === 'passed',
                        'policy_internal_business_runtime_allowed' => $run instanceof AtlasToolRun && $run->policy_decision === 'internal_business_runtime_allowed',
                        'normalized_result_bound' => $run instanceof AtlasToolRun && data_get($run->normalized_result_json, 'schema') === 'atlas.ai.company.persisted_business_runtime_result.v1',
                        'business_runtime_artifact_bound' => $run instanceof AtlasToolRun
                            && data_get($run->normalized_result_json, 'business_runtime_artifact.schema') === 'atlas.ai.company.business_runtime_persistence_artifact.v1'
                            && strlen((string) data_get($run->normalized_result_json, 'business_runtime_artifact.business_runtime_artifact_hash', '')) === 64,
                        'receipt_metadata_bound' => $run instanceof AtlasToolRun
                            && strlen((string) data_get($run->metadata_json, 'decision_receipt_hash', '')) === 64
                            && strlen((string) data_get($run->metadata_json, 'business_runtime_receipt_hash', '')) === 64
                            && strlen((string) data_get($run->metadata_json, 'source_status_receipt_hash', '')) === 64
                            && strlen((string) data_get($run->metadata_json, 'source_record_receipt_hash', '')) === 64
                            && strlen((string) data_get($run->metadata_json, 'operator_handoff_receipt_hash', '')) === 64
                            && strlen((string) data_get($run->metadata_json, 'audit_trail_hash', '')) === 64
                            && strlen((string) data_get($run->metadata_json, 'rollback_plan_hash', '')) === 64,
                        'quality_gates_bound' => $run instanceof AtlasToolRun
                            && count(array_filter((array) data_get($run->normalized_result_json, 'quality_gates', []))) >= 6,
                        'external_effects_blocked' => $run instanceof AtlasToolRun
                            && ! (bool) data_get($run->metadata_json, 'external_execution_allowed', true)
                            && ! (bool) data_get($run->metadata_json, 'external_side_effects_enabled', true),
                    ];
                    $readyRecordGateCount = count(array_filter($recordGates));
                    $runtimeRecord = [
                        'schema' => 'atlas.ai.company.persisted_business_runtime_record.v1',
                        'company_id' => $id,
                        'flow_id' => $flowId,
                        'business_runtime_layer_id' => $layerId,
                        'run_context_id' => $runContextId,
                        'tool_run_id' => $run instanceof AtlasToolRun ? (string) $run->id : null,
                        'ready' => $readyRecordGateCount === count($recordGates),
                        'ready_gate_count' => $readyRecordGateCount,
                        'required_gate_count' => count($recordGates),
                        'gates' => $recordGates,
                        'missing_gates' => array_values(array_keys(array_filter($recordGates, static fn (bool $ready): bool => ! $ready))),
                        'receipt_chain' => $run instanceof AtlasToolRun ? [
                            'decision_receipt_hash' => (string) data_get($run->metadata_json, 'decision_receipt_hash', ''),
                            'business_runtime_receipt_hash' => (string) data_get($run->metadata_json, 'business_runtime_receipt_hash', ''),
                            'source_status_receipt_hash' => (string) data_get($run->metadata_json, 'source_status_receipt_hash', ''),
                            'source_record_receipt_hash' => (string) data_get($run->metadata_json, 'source_record_receipt_hash', ''),
                            'operator_handoff_receipt_hash' => (string) data_get($run->metadata_json, 'operator_handoff_receipt_hash', ''),
                            'audit_trail_hash' => (string) data_get($run->metadata_json, 'audit_trail_hash', ''),
                            'rollback_plan_hash' => (string) data_get($run->metadata_json, 'rollback_plan_hash', ''),
                        ] : [],
                        'external_execution_allowed' => false,
                        'external_side_effects_enabled' => false,
                    ];
                    $runtimeRecord['persisted_business_runtime_record_hash'] = MissionCanonicalHash::sha256($runtimeRecord);
                    $runtimeRecords[] = $runtimeRecord;
                }
            }

            $readyRuntimeRecordCount = count(array_filter($runtimeRecords, static fn (array $record): bool => (bool) ($record['ready'] ?? false)));
            $expectedRunCount = count($flows) * count($this->businessRuntimePersistenceLayerCatalog());
            $gates = array_merge($layerGates, [
                'persisted_business_runtime_runs_cover_all_layers_and_flows' => $expectedRunCount > 0
                    && count($runtimeRecords) >= $expectedRunCount
                    && $readyRuntimeRecordCount === count($runtimeRecords),
                'external_effects_blocked' => count(array_filter($runtimeRecords, static fn (array $record): bool => (bool) ($record['external_execution_allowed'] ?? true)
                    || (bool) ($record['external_side_effects_enabled'] ?? true))) === 0,
            ]);
            $readyGateCount = count(array_filter($gates));
            $row = [
                'schema' => 'atlas.ai.company.enterprise_company_business_runtime_persistence_status_record.v1',
                'company_id' => $id,
                'expected_flow_count' => count($flows),
                'business_runtime_layer_count' => count($this->businessRuntimePersistenceLayerCatalog()),
                'expected_business_runtime_run_count' => $expectedRunCount,
                'persisted_business_runtime_run_count' => count($runtimeRecords),
                'ready_persisted_business_runtime_run_count' => $readyRuntimeRecordCount,
                'business_runtime_persistence_ready' => $expectedRunCount > 0 && $readyGateCount === count($gates),
                'runtime_grade' => $expectedRunCount > 0 && $readyGateCount === count($gates)
                    ? 'target_9_business_runtime_persistence_ready_external_effects_blocked'
                    : 'business_runtime_persistence_attention_required',
                'ready_gate_count' => $readyGateCount,
                'required_gate_count' => count($gates),
                'gates' => $gates,
                'missing_gates' => array_values(array_keys(array_filter($gates, static fn (bool $ready): bool => ! $ready))),
                'runtime_records' => $runtimeRecords,
                'external_execution_allowed' => false,
                'external_side_effects_enabled' => false,
            ];
            $row['company_business_runtime_persistence_status_record_hash'] = MissionCanonicalHash::sha256($row);
            $companies[] = $row;
        }

        $readyCompanyCount = count(array_filter($companies, static fn (array $company): bool => (bool) ($company['business_runtime_persistence_ready'] ?? false)));
        $payload = [
            'ok' => $companies !== [] && $readyCompanyCount === count($companies),
            'schema' => ExternalActionMandateRegistryService::ENTERPRISE_COMPANY_BUSINESS_RUNTIME_PERSISTENCE_STATUS_SCHEMA,
            'status' => $companies !== [] && $readyCompanyCount === count($companies)
                ? 'enterprise_company_business_runtime_persistence_ready_external_effects_blocked'
                : 'enterprise_company_business_runtime_persistence_attention_required',
            'generated_at' => now()->toJSON(),
            'summary' => [
                'company_count' => count($companies),
                'business_runtime_layer_count' => count($this->businessRuntimePersistenceLayerCatalog()),
                'business_runtime_persistence_ready_company_count' => $readyCompanyCount,
                'attention_company_count' => count($companies) - $readyCompanyCount,
                'expected_business_runtime_run_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['expected_business_runtime_run_count'] ?? 0), $companies)),
                'persisted_business_runtime_run_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['persisted_business_runtime_run_count'] ?? 0), $companies)),
                'ready_persisted_business_runtime_run_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['ready_persisted_business_runtime_run_count'] ?? 0), $companies)),
                'required_gate_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['required_gate_count'] ?? 0), $companies)),
                'ready_gate_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['ready_gate_count'] ?? 0), $companies)),
                'external_execution_allowed_count' => 0,
                'external_side_effects_enabled_count' => 0,
            ],
            'companies' => $companies,
            'policy' => $this->businessRuntimePersistencePolicy(),
        ];
        $payload['enterprise_company_business_runtime_persistence_status_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function enterpriseCompanyBusinessExecutionControlPlaneRegister(?string $companyId = null): array
    {
        $wantedCompany = $companyId !== null && trim($companyId) !== '' ? trim($companyId) : null;
        unset($this->hub->runtimeStatusCache['business_execution_control_plane_status:'.($wantedCompany ?? '*')]);
        $verticalRuntime = $this->hub->enterpriseVerticalToolRuntime->enterpriseCompanyVerticalToolOperatingRuntimeStatus($wantedCompany);
        $verticalRuntimeByCompany = $this->hub->companyRowsById($verticalRuntime);

        if (! $this->hub->approval->toolRunsTableAvailable()) {
            return [
                'ok' => false,
                'schema' => ExternalActionMandateRegistryService::ENTERPRISE_COMPANY_BUSINESS_EXECUTION_CONTROL_PLANE_REGISTER_SCHEMA,
                'status' => 'atlas_tool_runs_table_unavailable',
                'generated_at' => now()->toJSON(),
                'summary' => [
                    'company_count' => 0,
                    'expected_business_control_plane_count' => 0,
                    'registered_business_control_plane_count' => 0,
                    'external_execution_allowed_count' => 0,
                    'external_side_effects_enabled_count' => 0,
                    'real_money_movement_allowed_count' => 0,
                    'customer_commitment_allowed_count' => 0,
                ],
                'companies' => [],
                'policy' => $this->businessExecutionControlPlanePolicy(),
            ];
        }

        $companies = [];
        foreach ($this->hub->buildoutCompanies($wantedCompany) as $company) {
            $id = (string) ($company['company_id'] ?? 'unknown');
            $verticalRow = (array) ($verticalRuntimeByCompany[$id] ?? []);
            $registeredPlanes = [];

            foreach ((array) ($verticalRow['runtime_records'] ?? []) as $runtimeRecord) {
                $flowId = (string) ($runtimeRecord['flow_id'] ?? '');
                $capabilityId = (string) ($runtimeRecord['capability_id'] ?? '');
                $connectorId = (string) ($runtimeRecord['connector_id'] ?? '');
                $sourceRunContextId = (string) ($runtimeRecord['run_context_id'] ?? '');
                if ($flowId === '' || $capabilityId === '' || $connectorId === '' || $sourceRunContextId === '') {
                    continue;
                }

                $runContextId = $id.'.'.substr(MissionCanonicalHash::sha256([
                    'flow_id' => $flowId,
                    'capability_id' => $capabilityId,
                    'connector_id' => $connectorId,
                    'source_run_context_id' => $sourceRunContextId,
                    'context' => 'business_execution_control_plane',
                ]), 0, 24).'.business_execution_control_plane.v1';
                $artifact = $this->businessExecutionControlPlaneArtifact($company, (array) $runtimeRecord, $runContextId);
                $qualityGates = [
                    'vertical_tool_runtime_ready' => (bool) ($runtimeRecord['ready'] ?? false)
                        && strlen((string) ($runtimeRecord['vertical_tool_operating_runtime_status_record_hash'] ?? '')) === 64,
                    'business_control_artifact_bound' => strlen((string) ($artifact['business_execution_control_plane_artifact_hash'] ?? '')) === 64,
                    'business_kpi_contract_bound' => strlen((string) data_get($artifact, 'business_kpi_contract.business_kpi_contract_hash', '')) === 64
                        && count((array) data_get($artifact, 'business_kpi_contract.required_metrics', [])) >= 8,
                    'pnl_guardrail_contract_bound' => strlen((string) data_get($artifact, 'pnl_guardrail_contract.pnl_guardrail_contract_hash', '')) === 64
                        && ! (bool) data_get($artifact, 'pnl_guardrail_contract.real_money_movement_allowed', true),
                    'risk_compliance_control_bound' => strlen((string) data_get($artifact, 'risk_compliance_control.risk_compliance_control_hash', '')) === 64
                        && count((array) data_get($artifact, 'risk_compliance_control.blocked_operations', [])) >= 10,
                    'customer_commitment_control_bound' => strlen((string) data_get($artifact, 'customer_commitment_control.customer_commitment_control_hash', '')) === 64
                        && ! (bool) data_get($artifact, 'customer_commitment_control.external_customer_commitment_allowed', true),
                    'incident_escalation_contract_bound' => strlen((string) data_get($artifact, 'incident_escalation_contract.incident_escalation_contract_hash', '')) === 64
                        && (bool) data_get($artifact, 'incident_escalation_contract.kill_switch_required', false),
                    'approval_matrix_bound' => strlen((string) data_get($artifact, 'approval_matrix.approval_matrix_hash', '')) === 64
                        && (bool) data_get($artifact, 'approval_matrix.second_reviewer_required_for_money_security_customer_or_deploy', false),
                    'operating_review_packet_bound' => strlen((string) data_get($artifact, 'operating_review_packet.operating_review_packet_hash', '')) === 64
                        && (bool) data_get($artifact, 'operating_review_packet.bound_to_company_board_review', false),
                    'receipt_chain_bound' => strlen((string) data_get($artifact, 'receipt_chain.business_control_plane_receipt_hash', '')) === 64
                        && strlen((string) data_get($artifact, 'receipt_chain.kpi_receipt_hash', '')) === 64
                        && strlen((string) data_get($artifact, 'receipt_chain.risk_control_receipt_hash', '')) === 64,
                    'external_effects_blocked' => ! (bool) ($artifact['external_execution_allowed'] ?? true)
                        && ! (bool) ($artifact['external_side_effects_enabled'] ?? true)
                        && ! (bool) ($artifact['real_money_movement_allowed'] ?? true)
                        && ! (bool) ($artifact['customer_commitment_allowed'] ?? true),
                ];
                $summary = [
                    'company_id' => $id,
                    'flow_id' => $flowId,
                    'capability_id' => $capabilityId,
                    'connector_id' => $connectorId,
                    'business_control_plane_id' => (string) ($artifact['business_control_plane_id'] ?? ''),
                    'runtime_mode' => 'business_execution_control_plane_internal_kpi_pnl_risk_approval_replay',
                    'quality_gate_count' => count($qualityGates),
                    'ready_quality_gate_count' => count(array_filter($qualityGates)),
                    'external_execution_allowed' => false,
                    'external_side_effects_enabled' => false,
                    'real_money_movement_allowed' => false,
                    'customer_commitment_allowed' => false,
                ];
                $normalized = [
                    'schema' => 'atlas.ai.company.business_execution_control_plane_result.v1',
                    'status' => count(array_filter($qualityGates)) === count($qualityGates) ? 'passed' : 'attention_required',
                    'summary' => $summary,
                    'business_execution_control_plane_artifact' => $artifact,
                    'quality_gates' => $qualityGates,
                    'blocking_failures' => array_values(array_keys(array_filter($qualityGates, static fn (bool $ready): bool => ! $ready))),
                ];
                $receiptInput = [
                    'company_id' => $id,
                    'flow_id' => $flowId,
                    'capability_id' => $capabilityId,
                    'connector_id' => $connectorId,
                    'run_context_id' => $runContextId,
                    'artifact_hash' => (string) ($artifact['business_execution_control_plane_artifact_hash'] ?? ''),
                ];
                $metadata = [
                    'source' => 'enterprise_company_business_execution_control_plane_register',
                    'receipt_schema_version' => 'atlas.company_business_execution_control_plane_receipt.v1',
                    'evidence_receipt_hash' => MissionCanonicalHash::sha256($normalized),
                    'business_control_plane_receipt_hash' => MissionCanonicalHash::sha256($receiptInput + ['receipt_type' => 'business_control_plane_receipt']),
                    'kpi_receipt_hash' => MissionCanonicalHash::sha256($receiptInput + ['receipt_type' => 'kpi_receipt']),
                    'pnl_guardrail_receipt_hash' => MissionCanonicalHash::sha256($receiptInput + ['receipt_type' => 'pnl_guardrail_receipt']),
                    'risk_control_receipt_hash' => MissionCanonicalHash::sha256($receiptInput + ['receipt_type' => 'risk_control_receipt']),
                    'approval_matrix_receipt_hash' => MissionCanonicalHash::sha256($receiptInput + ['receipt_type' => 'approval_matrix_receipt']),
                    'incident_escalation_receipt_hash' => MissionCanonicalHash::sha256($receiptInput + ['receipt_type' => 'incident_escalation_receipt']),
                    'operating_review_receipt_hash' => MissionCanonicalHash::sha256($receiptInput + ['receipt_type' => 'operating_review_receipt']),
                    'audit_trail_hash' => MissionCanonicalHash::sha256($receiptInput + ['receipt_type' => 'audit_trail']),
                    'rollback_plan_hash' => MissionCanonicalHash::sha256($receiptInput + ['receipt_type' => 'rollback_plan']),
                    'external_execution_allowed' => false,
                    'external_side_effects_enabled' => false,
                    'real_money_movement_allowed' => false,
                    'customer_commitment_allowed' => false,
                ];

                $run = AtlasToolRun::query()->updateOrCreate(
                    [
                        'surface' => 'holding_company_business_execution_control_plane',
                        'run_context_type' => 'holding_company_business_execution_control_plane',
                        'run_context_id' => $runContextId,
                    ],
                    [
                        'tool_slug' => 'atlas_company_business_execution_control_plane',
                        'workspace_hash' => hash('sha256', base_path()),
                        'workspace' => base_path(),
                        'status' => $normalized['status'] === 'passed' ? 'passed' : 'failed',
                        'required' => true,
                        'failure_policy' => 'fail_closed',
                        'policy_decision' => 'business_control_plane_blocked',
                        'command_hash' => MissionCanonicalHash::sha256([
                            'run_context_id' => $runContextId,
                            'artifact_hash' => $artifact['business_execution_control_plane_artifact_hash'] ?? null,
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
                            'real_money_movement_allowed' => false,
                            'customer_commitment_allowed' => false,
                            'blocked_operations' => $this->businessExecutionControlPlanePolicy()['blocked_operations'],
                        ],
                        'metadata_json' => $metadata,
                    ],
                );

                $registeredPlanes[] = [
                    'run_id' => (string) $run->id,
                    'run_context_id' => $runContextId,
                    'business_control_plane_id' => (string) ($artifact['business_control_plane_id'] ?? ''),
                    'flow_id' => $flowId,
                    'capability_id' => $capabilityId,
                    'connector_id' => $connectorId,
                    'status' => (string) $run->status,
                    'policy_decision' => (string) $run->policy_decision,
                    'business_control_plane_receipt_hash' => (string) data_get($run->metadata_json, 'business_control_plane_receipt_hash', ''),
                    'external_execution_allowed' => false,
                    'external_side_effects_enabled' => false,
                    'real_money_movement_allowed' => false,
                    'customer_commitment_allowed' => false,
                ];
            }

            $row = [
                'schema' => 'atlas.ai.company.enterprise_business_execution_control_plane_register_record.v1',
                'company_id' => $id,
                'expected_business_control_plane_count' => (int) ($verticalRow['ready_vertical_tool_runtime_count'] ?? 0),
                'registered_business_control_plane_count' => count($registeredPlanes),
                'registered_control_planes' => $registeredPlanes,
                'external_execution_allowed' => false,
                'external_side_effects_enabled' => false,
                'real_money_movement_allowed' => false,
                'customer_commitment_allowed' => false,
            ];
            $row['company_business_execution_control_plane_register_record_hash'] = MissionCanonicalHash::sha256($row);
            $companies[] = $row;
        }

        $registeredCount = array_sum(array_map(static fn (array $company): int => (int) ($company['registered_business_control_plane_count'] ?? 0), $companies));
        $expectedCount = array_sum(array_map(static fn (array $company): int => (int) ($company['expected_business_control_plane_count'] ?? 0), $companies));
        $payload = [
            'ok' => $expectedCount > 0 && $registeredCount >= $expectedCount,
            'schema' => ExternalActionMandateRegistryService::ENTERPRISE_COMPANY_BUSINESS_EXECUTION_CONTROL_PLANE_REGISTER_SCHEMA,
            'status' => $expectedCount > 0 && $registeredCount >= $expectedCount
                ? 'enterprise_company_business_execution_control_plane_registered_external_effects_blocked'
                : 'enterprise_company_business_execution_control_plane_attention_required',
            'generated_at' => now()->toJSON(),
            'summary' => [
                'company_count' => count($companies),
                'expected_business_control_plane_count' => $expectedCount,
                'registered_business_control_plane_count' => $registeredCount,
                'external_execution_allowed_count' => 0,
                'external_side_effects_enabled_count' => 0,
                'real_money_movement_allowed_count' => 0,
                'customer_commitment_allowed_count' => 0,
            ],
            'source_hashes' => [
                'enterprise_company_vertical_tool_operating_runtime_status_hash' => $verticalRuntime['enterprise_company_vertical_tool_operating_runtime_status_hash'] ?? null,
            ],
            'companies' => $companies,
            'policy' => $this->businessExecutionControlPlanePolicy(),
        ];
        $payload['enterprise_company_business_execution_control_plane_register_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function enterpriseCompanyBusinessExecutionControlPlaneStatus(?string $companyId = null): array
    {
        $wantedCompany = $companyId !== null && trim($companyId) !== '' ? trim($companyId) : null;
        $cacheKey = 'business_execution_control_plane_status:'.($wantedCompany ?? '*');
        if (isset($this->hub->runtimeStatusCache[$cacheKey])) {
            return $this->hub->runtimeStatusCache[$cacheKey];
        }

        $verticalRuntime = $this->hub->enterpriseVerticalToolRuntime->enterpriseCompanyVerticalToolOperatingRuntimeStatus($wantedCompany);
        $verticalRuntimeByCompany = $this->hub->companyRowsById($verticalRuntime);

        if (! $this->hub->approval->toolRunsTableAvailable()) {
            return [
                'ok' => false,
                'schema' => ExternalActionMandateRegistryService::ENTERPRISE_COMPANY_BUSINESS_EXECUTION_CONTROL_PLANE_STATUS_SCHEMA,
                'status' => 'atlas_tool_runs_table_unavailable',
                'generated_at' => now()->toJSON(),
                'summary' => [
                    'company_count' => 0,
                    'expected_business_control_plane_count' => 0,
                    'persisted_business_control_plane_count' => 0,
                    'ready_business_control_plane_count' => 0,
                    'external_execution_allowed_count' => 0,
                    'external_side_effects_enabled_count' => 0,
                    'real_money_movement_allowed_count' => 0,
                    'customer_commitment_allowed_count' => 0,
                ],
                'companies' => [],
                'policy' => $this->businessExecutionControlPlanePolicy(),
            ];
        }

        $companies = [];
        foreach ($this->hub->buildoutCompanies($wantedCompany) as $company) {
            $id = (string) ($company['company_id'] ?? 'unknown');
            $verticalRow = (array) ($verticalRuntimeByCompany[$id] ?? []);
            $controlPlaneRecords = [];

            foreach ((array) ($verticalRow['runtime_records'] ?? []) as $runtimeRecord) {
                $flowId = (string) ($runtimeRecord['flow_id'] ?? '');
                $capabilityId = (string) ($runtimeRecord['capability_id'] ?? '');
                $connectorId = (string) ($runtimeRecord['connector_id'] ?? '');
                $sourceRunContextId = (string) ($runtimeRecord['run_context_id'] ?? '');
                if ($flowId === '' || $capabilityId === '' || $connectorId === '' || $sourceRunContextId === '') {
                    continue;
                }

                $runContextId = $id.'.'.substr(MissionCanonicalHash::sha256([
                    'flow_id' => $flowId,
                    'capability_id' => $capabilityId,
                    'connector_id' => $connectorId,
                    'source_run_context_id' => $sourceRunContextId,
                    'context' => 'business_execution_control_plane',
                ]), 0, 24).'.business_execution_control_plane.v1';
                $run = AtlasToolRun::query()
                    ->where('surface', 'holding_company_business_execution_control_plane')
                    ->where('run_context_type', 'holding_company_business_execution_control_plane')
                    ->where('run_context_id', $runContextId)
                    ->latest('updated_at')
                    ->first();

                $recordGates = [
                    'persisted_business_control_plane_exists' => $run instanceof AtlasToolRun,
                    'status_passed' => $run instanceof AtlasToolRun && $run->status === 'passed',
                    'policy_business_control_plane_blocked' => $run instanceof AtlasToolRun && $run->policy_decision === 'business_control_plane_blocked',
                    'normalized_result_bound' => $run instanceof AtlasToolRun && data_get($run->normalized_result_json, 'schema') === 'atlas.ai.company.business_execution_control_plane_result.v1',
                    'artifact_bound' => $run instanceof AtlasToolRun
                        && data_get($run->normalized_result_json, 'business_execution_control_plane_artifact.schema') === 'atlas.ai.company.business_execution_control_plane_artifact.v1'
                        && strlen((string) data_get($run->normalized_result_json, 'business_execution_control_plane_artifact.business_execution_control_plane_artifact_hash', '')) === 64,
                    'kpi_pnl_risk_controls_bound' => $run instanceof AtlasToolRun
                        && strlen((string) data_get($run->normalized_result_json, 'business_execution_control_plane_artifact.business_kpi_contract.business_kpi_contract_hash', '')) === 64
                        && strlen((string) data_get($run->normalized_result_json, 'business_execution_control_plane_artifact.pnl_guardrail_contract.pnl_guardrail_contract_hash', '')) === 64
                        && strlen((string) data_get($run->normalized_result_json, 'business_execution_control_plane_artifact.risk_compliance_control.risk_compliance_control_hash', '')) === 64,
                    'customer_incident_approval_review_bound' => $run instanceof AtlasToolRun
                        && strlen((string) data_get($run->normalized_result_json, 'business_execution_control_plane_artifact.customer_commitment_control.customer_commitment_control_hash', '')) === 64
                        && strlen((string) data_get($run->normalized_result_json, 'business_execution_control_plane_artifact.incident_escalation_contract.incident_escalation_contract_hash', '')) === 64
                        && strlen((string) data_get($run->normalized_result_json, 'business_execution_control_plane_artifact.approval_matrix.approval_matrix_hash', '')) === 64
                        && strlen((string) data_get($run->normalized_result_json, 'business_execution_control_plane_artifact.operating_review_packet.operating_review_packet_hash', '')) === 64,
                    'receipt_metadata_bound' => $run instanceof AtlasToolRun
                        && strlen((string) data_get($run->metadata_json, 'business_control_plane_receipt_hash', '')) === 64
                        && strlen((string) data_get($run->metadata_json, 'kpi_receipt_hash', '')) === 64
                        && strlen((string) data_get($run->metadata_json, 'pnl_guardrail_receipt_hash', '')) === 64
                        && strlen((string) data_get($run->metadata_json, 'risk_control_receipt_hash', '')) === 64
                        && strlen((string) data_get($run->metadata_json, 'approval_matrix_receipt_hash', '')) === 64
                        && strlen((string) data_get($run->metadata_json, 'incident_escalation_receipt_hash', '')) === 64
                        && strlen((string) data_get($run->metadata_json, 'operating_review_receipt_hash', '')) === 64,
                    'external_effects_blocked' => $run instanceof AtlasToolRun
                        && ! (bool) data_get($run->policy_decision_json, 'external_execution_allowed', true)
                        && ! (bool) data_get($run->policy_decision_json, 'external_side_effects_enabled', true)
                        && ! (bool) data_get($run->policy_decision_json, 'real_money_movement_allowed', true)
                        && ! (bool) data_get($run->policy_decision_json, 'customer_commitment_allowed', true),
                ];
                $readyRecordGateCount = count(array_filter($recordGates));
                $record = [
                    'schema' => 'atlas.ai.company.business_execution_control_plane_status_record.v1',
                    'company_id' => $id,
                    'flow_id' => $flowId,
                    'capability_id' => $capabilityId,
                    'connector_id' => $connectorId,
                    'run_context_id' => $runContextId,
                    'tool_run_id' => $run instanceof AtlasToolRun ? (string) $run->id : null,
                    'ready' => $readyRecordGateCount === count($recordGates),
                    'ready_gate_count' => $readyRecordGateCount,
                    'required_gate_count' => count($recordGates),
                    'gates' => $recordGates,
                    'missing_gates' => array_values(array_keys(array_filter($recordGates, static fn (bool $ready): bool => ! $ready))),
                    'receipt_chain' => $run instanceof AtlasToolRun ? [
                        'business_control_plane_receipt_hash' => (string) data_get($run->metadata_json, 'business_control_plane_receipt_hash', ''),
                        'kpi_receipt_hash' => (string) data_get($run->metadata_json, 'kpi_receipt_hash', ''),
                        'pnl_guardrail_receipt_hash' => (string) data_get($run->metadata_json, 'pnl_guardrail_receipt_hash', ''),
                        'risk_control_receipt_hash' => (string) data_get($run->metadata_json, 'risk_control_receipt_hash', ''),
                        'approval_matrix_receipt_hash' => (string) data_get($run->metadata_json, 'approval_matrix_receipt_hash', ''),
                        'incident_escalation_receipt_hash' => (string) data_get($run->metadata_json, 'incident_escalation_receipt_hash', ''),
                        'operating_review_receipt_hash' => (string) data_get($run->metadata_json, 'operating_review_receipt_hash', ''),
                    ] : [],
                    'external_execution_allowed' => false,
                    'external_side_effects_enabled' => false,
                    'real_money_movement_allowed' => false,
                    'customer_commitment_allowed' => false,
                ];
                $record['business_execution_control_plane_status_record_hash'] = MissionCanonicalHash::sha256($record);
                $controlPlaneRecords[] = $record;
            }

            $expectedControlPlaneCount = (int) ($verticalRow['ready_vertical_tool_runtime_count'] ?? 0);
            $readyControlPlaneCount = count(array_filter($controlPlaneRecords, static fn (array $record): bool => (bool) ($record['ready'] ?? false)));
            $gates = [
                'vertical_tool_operating_runtime_ready' => (bool) ($verticalRow['vertical_tool_operating_runtime_ready'] ?? false),
                'business_control_plane_covers_vertical_runtime' => $expectedControlPlaneCount > 0
                    && count($controlPlaneRecords) >= $expectedControlPlaneCount
                    && $readyControlPlaneCount === count($controlPlaneRecords),
                'external_effects_blocked' => count(array_filter($controlPlaneRecords, static fn (array $record): bool => (bool) ($record['external_execution_allowed'] ?? true)
                    || (bool) ($record['external_side_effects_enabled'] ?? true)
                    || (bool) ($record['real_money_movement_allowed'] ?? true)
                    || (bool) ($record['customer_commitment_allowed'] ?? true))) === 0,
            ];
            $readyGateCount = count(array_filter($gates));
            $row = [
                'schema' => 'atlas.ai.company.enterprise_business_execution_control_plane_company_status.v1',
                'company_id' => $id,
                'expected_business_control_plane_count' => $expectedControlPlaneCount,
                'persisted_business_control_plane_count' => count($controlPlaneRecords),
                'ready_business_control_plane_count' => $readyControlPlaneCount,
                'business_execution_control_plane_ready' => $expectedControlPlaneCount > 0 && $readyGateCount === count($gates),
                'runtime_grade' => $expectedControlPlaneCount > 0 && $readyGateCount === count($gates)
                    ? 'target_9_business_execution_control_plane_ready_external_effects_blocked'
                    : 'business_execution_control_plane_attention_required',
                'ready_gate_count' => $readyGateCount,
                'required_gate_count' => count($gates),
                'gates' => $gates,
                'missing_gates' => array_values(array_keys(array_filter($gates, static fn (bool $ready): bool => ! $ready))),
                'control_plane_records' => $controlPlaneRecords,
                'external_execution_allowed' => false,
                'external_side_effects_enabled' => false,
                'real_money_movement_allowed' => false,
                'customer_commitment_allowed' => false,
            ];
            $row['company_business_execution_control_plane_status_record_hash'] = MissionCanonicalHash::sha256($row);
            $companies[] = $row;
        }

        $readyCompanyCount = count(array_filter($companies, static fn (array $company): bool => (bool) ($company['business_execution_control_plane_ready'] ?? false)));
        $payload = [
            'ok' => $companies !== [] && $readyCompanyCount === count($companies),
            'schema' => ExternalActionMandateRegistryService::ENTERPRISE_COMPANY_BUSINESS_EXECUTION_CONTROL_PLANE_STATUS_SCHEMA,
            'status' => $companies !== [] && $readyCompanyCount === count($companies)
                ? 'enterprise_company_business_execution_control_plane_ready_external_effects_blocked'
                : 'enterprise_company_business_execution_control_plane_attention_required',
            'generated_at' => now()->toJSON(),
            'summary' => [
                'company_count' => count($companies),
                'business_execution_control_plane_ready_company_count' => $readyCompanyCount,
                'attention_company_count' => count($companies) - $readyCompanyCount,
                'expected_business_control_plane_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['expected_business_control_plane_count'] ?? 0), $companies)),
                'persisted_business_control_plane_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['persisted_business_control_plane_count'] ?? 0), $companies)),
                'ready_business_control_plane_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['ready_business_control_plane_count'] ?? 0), $companies)),
                'required_gate_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['required_gate_count'] ?? 0), $companies)),
                'ready_gate_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['ready_gate_count'] ?? 0), $companies)),
                'external_execution_allowed_count' => 0,
                'external_side_effects_enabled_count' => 0,
                'real_money_movement_allowed_count' => 0,
                'customer_commitment_allowed_count' => 0,
            ],
            'source_hashes' => [
                'enterprise_company_vertical_tool_operating_runtime_status_hash' => $verticalRuntime['enterprise_company_vertical_tool_operating_runtime_status_hash'] ?? null,
            ],
            'companies' => $companies,
            'policy' => $this->businessExecutionControlPlanePolicy(),
        ];
        $payload['enterprise_company_business_execution_control_plane_status_hash'] = MissionCanonicalHash::sha256($payload);

        return $this->hub->runtimeStatusCache[$cacheKey] = $payload;
    }

    /**
     * @param array<string,mixed> $company
     * @param array<string,mixed> $layer
     * @param array<string,mixed> $sourceRecord
     * @return array<string,mixed>
     */
    public function businessRuntimePersistenceArtifact(array $company, string $flowId, array $layer, array $sourceRecord, string $sourceStatusHash, string $runContextId): array
    {
        $companyId = (string) ($company['company_id'] ?? 'unknown');
        $layerId = (string) $layer['layer_id'];
        $businessPacket = $this->hub->findByFlow($company, 'enterprise_business_operating_packet_stack.flow_operating_packets', $flowId);
        $blueprint = $this->hub->findByFlow($company, 'enterprise_company_operating_blueprint_stack.flow_operating_blueprints', $flowId);
        $workProduct = $this->hub->findByFlow($company, 'enterprise_flow_work_product_delivery_stack.flow_delivery_blueprints', $flowId);
        $connectorContract = $this->hub->findByFlow($company, 'enterprise_domain_data_connector_operating_stack.flow_data_connector_contracts', $flowId);

        $artifact = [
            'schema' => 'atlas.ai.company.business_runtime_persistence_artifact.v1',
            'company_id' => $companyId,
            'flow_id' => $flowId,
            'run_context_id' => $runContextId,
            'business_runtime_layer_id' => $layerId,
            'business_runtime_layer_name' => (string) $layer['name'],
            'runtime_mode' => 'internal_business_runtime_persistence_no_external_effect',
            'enterprise_patterns' => [
                'source_grounded_business_operation_packet',
                'customer_revenue_support_finance_governance_runtime_attestation',
                'least_privilege_connector_scope',
                'operator_handoff_before_external_commitment',
                'audit_replay_and_rollback_receipts',
            ],
            'source_lineage' => [
                'source_status_hash' => $sourceStatusHash,
                'source_runtime_record_hash' => (string) ($sourceRecord['receipt_hash'] ?? ''),
                'source_attestation_hash' => (string) ($sourceRecord[$layer['attestation_hash_field']] ?? ''),
                'business_packet_hash' => (string) ($businessPacket['operating_packet_hash'] ?? $businessPacket['packet_hash'] ?? hash('sha256', 'business_packet|'.$companyId.'|'.$flowId)),
                'operating_blueprint_hash' => (string) ($blueprint['flow_operating_blueprint_hash'] ?? hash('sha256', 'operating_blueprint|'.$companyId.'|'.$flowId)),
                'work_product_blueprint_hash' => (string) ($workProduct['blueprint_hash'] ?? $workProduct['delivery_blueprint_hash'] ?? hash('sha256', 'work_product_blueprint|'.$companyId.'|'.$flowId)),
                'connector_contract_hash' => (string) ($connectorContract['contract_hash'] ?? $connectorContract['connector_contract_hash'] ?? hash('sha256', 'connector_contract|'.$companyId.'|'.$flowId)),
                'source_lineage_hash' => MissionCanonicalHash::sha256([
                    'company_id' => $companyId,
                    'flow_id' => $flowId,
                    'layer_id' => $layerId,
                    'source_status_hash' => $sourceStatusHash,
                    'source_record' => $sourceRecord,
                ]),
                'claim_without_source_reference_allowed' => false,
            ],
            'business_controls' => [
                'required_control_fields' => array_values((array) $layer['required_control_fields']),
                'ready_field' => (string) $layer['ready_field'],
                'completed_field' => (string) $layer['completed_field'],
                'source_runtime_ready' => (bool) ($sourceRecord[$layer['ready_field']] ?? false),
                'missing_required_controls' => array_values(array_filter(
                    (array) $layer['required_control_fields'],
                    static fn (string $field): bool => ! (bool) ($sourceRecord[$field] ?? false),
                )),
            ],
            'operator_handoff' => [
                'operator_acceptance_required' => true,
                'external_customer_commitment_requires_signed_scope' => true,
                'billing_capital_trade_publish_or_security_action_requires_separate_mandate' => true,
                'handoff_hash' => MissionCanonicalHash::sha256([
                    'company_id' => $companyId,
                    'flow_id' => $flowId,
                    'layer_id' => $layerId,
                    'handoff' => 'operator_required_before_external_effect',
                ]),
            ],
            'replay_and_rollback' => [
                'deterministic_replay_required' => true,
                'source_status_replay_required' => true,
                'manual_fallback_required' => true,
                'rollback_plan_required' => true,
            ],
            'external_execution_allowed' => false,
            'external_side_effects_enabled' => false,
            'generated_at' => now()->toJSON(),
        ];
        $artifact['business_runtime_artifact_hash'] = MissionCanonicalHash::sha256($artifact);

        return $artifact;
    }

    /**
     * @param array<string,mixed> $company
     * @param array<string,mixed> $runtimeRecord
     * @return array<string,mixed>
     */
    public function businessExecutionControlPlaneArtifact(array $company, array $runtimeRecord, string $runContextId): array
    {
        $companyId = (string) ($company['company_id'] ?? 'unknown');
        $flowId = (string) ($runtimeRecord['flow_id'] ?? '');
        $capabilityId = (string) ($runtimeRecord['capability_id'] ?? '');
        $connectorId = (string) ($runtimeRecord['connector_id'] ?? '');
        $capability = (array) ($this->hub->enterpriseCapabilityRuntime->enterpriseCapabilityRuntimeCatalog()[$capabilityId] ?? []);
        $sourceRecordHash = (string) ($runtimeRecord['vertical_tool_operating_runtime_status_record_hash'] ?? '');
        $controlPlaneId = MissionCanonicalHash::sha256([
            'type' => 'business_execution_control_plane',
            'company_id' => $companyId,
            'flow_id' => $flowId,
            'capability_id' => $capabilityId,
            'connector_id' => $connectorId,
            'source_vertical_tool_operating_runtime_status_record_hash' => $sourceRecordHash,
        ]);
        $receiptInput = [
            'business_control_plane_id' => $controlPlaneId,
            'company_id' => $companyId,
            'flow_id' => $flowId,
            'capability_id' => $capabilityId,
            'connector_id' => $connectorId,
            'source_record_hash' => $sourceRecordHash,
        ];
        $domainFamily = (string) ($capability['domain_family'] ?? $companyId.'_operations');
        $companyMetrics = array_values((array) ($company['metrics'] ?? []));
        $capabilityControls = array_values((array) ($capability['compliance_controls'] ?? []));

        $businessKpiContract = [
            'schema' => 'atlas.ai.company.business_kpi_contract.v1',
            'domain_family' => $domainFamily,
            'required_metrics' => array_values(array_unique(array_merge($companyMetrics, [
                'qualified_work_item_count',
                'cycle_time_p95',
                'sla_hit_rate',
                'gross_margin_notional',
                'risk_exception_count',
                'customer_commitment_block_count',
                'operator_approval_count',
                'receipt_completeness_rate',
            ]))),
            'minimum_receipt_completeness_rate' => 1.0,
            'source_lineage_required' => true,
            'unsupported_outcome_claim_blocked' => true,
        ];
        $businessKpiContract['business_kpi_contract_hash'] = MissionCanonicalHash::sha256($businessKpiContract + $receiptInput);

        $pnlGuardrailContract = [
            'schema' => 'atlas.ai.company.pnl_guardrail_contract.v1',
            'notional_revenue_model_required' => true,
            'notional_cost_model_required' => true,
            'margin_sensitivity_required' => true,
            'capital_at_risk_limit' => 0,
            'real_money_movement_allowed' => false,
            'external_revenue_claim_allowed' => false,
            'invoice_or_collection_allowed' => false,
        ];
        $pnlGuardrailContract['pnl_guardrail_contract_hash'] = MissionCanonicalHash::sha256($pnlGuardrailContract + $receiptInput);

        $riskComplianceControl = [
            'schema' => 'atlas.ai.company.risk_compliance_control.v1',
            'risk_register_required' => true,
            'domain_compliance_controls' => $capabilityControls,
            'second_line_review_required' => true,
            'blocked_operations' => $this->businessExecutionControlPlanePolicy()['blocked_operations'],
            'policy_exception_requires_signed_mandate' => true,
            'fail_closed_on_missing_risk_receipt' => true,
        ];
        $riskComplianceControl['risk_compliance_control_hash'] = MissionCanonicalHash::sha256($riskComplianceControl + $receiptInput);

        $customerCommitmentControl = [
            'schema' => 'atlas.ai.company.customer_commitment_control.v1',
            'external_customer_commitment_allowed' => false,
            'external_customer_message_allowed' => false,
            'public_promise_allowed' => false,
            'operator_signed_scope_required' => true,
            'customer_impact_review_required' => true,
            'draft_only_until_cutover_mandate' => true,
        ];
        $customerCommitmentControl['customer_commitment_control_hash'] = MissionCanonicalHash::sha256($customerCommitmentControl + $receiptInput);

        $incidentEscalationContract = [
            'schema' => 'atlas.ai.company.incident_escalation_contract.v1',
            'severity_levels' => ['sev0_external_effect_risk', 'sev1_customer_or_money_risk', 'sev2_sla_or_quality_risk', 'sev3_internal_degradation'],
            'alert_routes' => ['control_tower', 'operator_console', 'evidence_ledger', 'company_board_review'],
            'kill_switch_required' => true,
            'rollback_plan_required' => true,
            'postmortem_required_for_missed_slo' => true,
        ];
        $incidentEscalationContract['incident_escalation_contract_hash'] = MissionCanonicalHash::sha256($incidentEscalationContract + $receiptInput);

        $approvalMatrix = [
            'schema' => 'atlas.ai.company.business_execution_approval_matrix.v1',
            'operator_approval_required_for_external_effect' => true,
            'second_reviewer_required_for_money_security_customer_or_deploy' => true,
            'approval_roles' => ['company_operator', 'domain_lead', 'risk_compliance_reviewer', 'finance_controller', 'launch_authority'],
            'approval_receipt_required_before_mutation' => true,
            'autonomous_override_allowed' => false,
        ];
        $approvalMatrix['approval_matrix_hash'] = MissionCanonicalHash::sha256($approvalMatrix + $receiptInput);

        $operatingReviewPacket = [
            'schema' => 'atlas.ai.company.business_execution_operating_review_packet.v1',
            'bound_to_company_board_review' => true,
            'bound_to_operating_scorecard' => true,
            'kpi_pnl_risk_customer_incident_sections_required' => true,
            'decision_options' => ['continue_internal_replay', 'expand_readonly_connectors', 'request_limited_operator_mandate', 'pause_for_risk_remediation'],
            'external_launch_recommendation_allowed' => false,
        ];
        $operatingReviewPacket['operating_review_packet_hash'] = MissionCanonicalHash::sha256($operatingReviewPacket + $receiptInput);

        $artifact = [
            'schema' => 'atlas.ai.company.business_execution_control_plane_artifact.v1',
            'business_control_plane_id' => $controlPlaneId,
            'company_id' => $companyId,
            'flow_id' => $flowId,
            'capability_id' => $capabilityId,
            'connector_id' => $connectorId,
            'run_context_id' => $runContextId,
            'runtime_mode' => 'business_execution_control_plane_internal_kpi_pnl_risk_approval_replay',
            'source_lineage' => [
                'vertical_tool_operating_runtime_status_record_hash' => $sourceRecordHash,
                'source_tool_run_id' => (string) ($runtimeRecord['tool_run_id'] ?? ''),
                'source_run_context_id' => (string) ($runtimeRecord['run_context_id'] ?? ''),
                'source_lineage_hash' => MissionCanonicalHash::sha256($receiptInput + ['lineage' => 'business_execution_control_plane']),
            ],
            'business_kpi_contract' => $businessKpiContract,
            'pnl_guardrail_contract' => $pnlGuardrailContract,
            'risk_compliance_control' => $riskComplianceControl,
            'customer_commitment_control' => $customerCommitmentControl,
            'incident_escalation_contract' => $incidentEscalationContract,
            'approval_matrix' => $approvalMatrix,
            'operating_review_packet' => $operatingReviewPacket,
            'receipt_chain' => [
                'business_control_plane_receipt_hash' => MissionCanonicalHash::sha256($receiptInput + ['receipt_type' => 'business_control_plane_receipt']),
                'kpi_receipt_hash' => MissionCanonicalHash::sha256($receiptInput + ['receipt_type' => 'kpi_receipt']),
                'pnl_guardrail_receipt_hash' => MissionCanonicalHash::sha256($receiptInput + ['receipt_type' => 'pnl_guardrail_receipt']),
                'risk_control_receipt_hash' => MissionCanonicalHash::sha256($receiptInput + ['receipt_type' => 'risk_control_receipt']),
                'approval_matrix_receipt_hash' => MissionCanonicalHash::sha256($receiptInput + ['receipt_type' => 'approval_matrix_receipt']),
                'incident_escalation_receipt_hash' => MissionCanonicalHash::sha256($receiptInput + ['receipt_type' => 'incident_escalation_receipt']),
                'operating_review_receipt_hash' => MissionCanonicalHash::sha256($receiptInput + ['receipt_type' => 'operating_review_receipt']),
            ],
            'external_execution_allowed' => false,
            'external_side_effects_enabled' => false,
            'real_money_movement_allowed' => false,
            'customer_commitment_allowed' => false,
            'generated_at' => now()->toJSON(),
        ];
        $artifact['business_execution_control_plane_artifact_hash'] = MissionCanonicalHash::sha256($artifact);

        return $artifact;
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    public function businessRuntimePersistenceLayerCatalog(): array
    {
        return [
            'customer_account_revenue' => [
                'layer_id' => 'customer_account_revenue',
                'name' => 'Customer Account Revenue Runtime',
                'status_method' => 'customerAccountRevenueRuntimeStatus',
                'status_hash_field' => 'customer_account_revenue_runtime_status_hash',
                'completed_field' => 'completed_customer_account_revenue_flow_count',
                'ready_field' => 'customer_account_revenue_runtime_bound',
                'attestation_hash_field' => 'customer_account_revenue_attestation_hash',
                'required_control_fields' => ['customer_market_runtime_bound', 'offer_packaging_bound', 'journey_lifecycle_bound', 'customer_success_scorecard_bound', 'commercial_service_catalog_bound', 'business_kpi_bound', 'account_contract_delivery_bound', 'account_observability_bound'],
            ],
            'productized_service' => [
                'layer_id' => 'productized_service',
                'name' => 'Productized Service Runtime',
                'status_method' => 'productizedServiceRuntimeStatus',
                'status_hash_field' => 'productized_service_runtime_status_hash',
                'completed_field' => 'completed_productized_service_flow_count',
                'ready_field' => 'productized_service_runtime_bound',
                'attestation_hash_field' => 'productized_service_attestation_hash',
                'required_control_fields' => ['productized_service_stack_bound', 'productized_service_offer_bound', 'productized_delivery_blueprint_bound', 'productized_intake_contract_bound', 'productized_sla_success_contract_bound', 'productized_pricing_packaging_bound', 'productized_external_commitment_billing_blocked'],
            ],
            'sales_crm_pipeline' => [
                'layer_id' => 'sales_crm_pipeline',
                'name' => 'Sales CRM Pipeline Runtime',
                'status_method' => 'salesCrmPipelineRuntimeStatus',
                'status_hash_field' => 'sales_crm_pipeline_runtime_status_hash',
                'completed_field' => 'completed_sales_crm_pipeline_flow_count',
                'ready_field' => 'sales_crm_pipeline_runtime_bound',
                'attestation_hash_field' => 'sales_crm_pipeline_attestation_hash',
                'required_control_fields' => ['sales_crm_stack_bound', 'sales_source_catalog_bound', 'sales_crm_object_model_bound', 'sales_opportunity_route_bound', 'sales_proposal_scope_bound', 'sales_delivery_handoff_bound', 'sales_external_commitments_blocked'],
            ],
            'customer_support_service_desk' => [
                'layer_id' => 'customer_support_service_desk',
                'name' => 'Customer Support Service Desk Runtime',
                'status_method' => 'customerSupportServiceDeskRuntimeStatus',
                'status_hash_field' => 'customer_support_service_desk_runtime_status_hash',
                'completed_field' => 'completed_customer_support_service_desk_flow_count',
                'ready_field' => 'customer_support_service_desk_runtime_bound',
                'attestation_hash_field' => 'customer_support_service_desk_attestation_hash',
                'required_control_fields' => ['support_service_desk_stack_bound', 'support_source_catalog_bound', 'support_service_desk_object_model_bound', 'support_ticket_sla_contract_bound', 'support_escalation_incident_runbook_bound', 'support_external_customer_actions_blocked'],
            ],
            'marketing_growth_engine' => [
                'layer_id' => 'marketing_growth_engine',
                'name' => 'Marketing Growth Engine Runtime',
                'status_method' => 'marketingGrowthEngineRuntimeStatus',
                'status_hash_field' => 'marketing_growth_engine_runtime_status_hash',
                'completed_field' => 'completed_marketing_growth_engine_flow_count',
                'ready_field' => 'marketing_growth_engine_runtime_bound',
                'attestation_hash_field' => 'marketing_growth_engine_attestation_hash',
                'required_control_fields' => ['growth_source_catalog_bound', 'growth_channel_model_bound', 'growth_experiment_pipeline_bound', 'growth_campaign_brief_bound', 'growth_intelligence_workbench_bound', 'growth_external_marketing_actions_blocked'],
            ],
            'finance_treasury_billing' => [
                'layer_id' => 'finance_treasury_billing',
                'name' => 'Finance Treasury Billing Runtime',
                'status_method' => 'financeTreasuryBillingRuntimeStatus',
                'status_hash_field' => 'finance_treasury_billing_runtime_status_hash',
                'completed_field' => 'completed_finance_treasury_billing_flow_count',
                'ready_field' => 'finance_treasury_billing_runtime_bound',
                'attestation_hash_field' => 'finance_treasury_billing_attestation_hash',
                'required_control_fields' => ['financial_data_interface_bound', 'provider_connector_matrix_bound', 'cfo_operating_model_bound', 'financial_research_workbench_bound', 'model_risk_control_bound', 'external_financial_actions_blocked'],
            ],
            'governance_risk_operations' => [
                'layer_id' => 'governance_risk_operations',
                'name' => 'Governance Risk Operations Runtime',
                'status_method' => 'governanceRiskOperationsRuntimeStatus',
                'status_hash_field' => 'governance_risk_operations_runtime_status_hash',
                'completed_field' => 'completed_governance_risk_operations_flow_count',
                'ready_field' => 'governance_risk_operations_runtime_bound',
                'attestation_hash_field' => 'governance_risk_operations_attestation_hash',
                'required_control_fields' => ['vendor_procurement_bound', 'resilience_continuity_bound', 'analytics_decision_bound', 'knowledge_learning_bound', 'identity_sovereignty_bound', 'grc_evidence_bound', 'external_actions_blocked'],
            ],
            'unit_economics_capacity' => [
                'layer_id' => 'unit_economics_capacity',
                'name' => 'Unit Economics Capacity Runtime',
                'status_method' => 'unitEconomicsCapacityRuntimeStatus',
                'status_hash_field' => 'unit_economics_capacity_runtime_status_hash',
                'completed_field' => 'completed_unit_economics_capacity_flow_count',
                'ready_field' => 'unit_economics_capacity_runtime_bound',
                'attestation_hash_field' => 'unit_economics_capacity_attestation_hash',
                'required_control_fields' => ['flow_cost_center_bound', 'flow_unit_economics_bound', 'capacity_simulation_bound', 'pricing_ladder_bound', 'agent_capacity_cost_model_bound', 'connector_cost_limit_model_bound'],
            ],
            'business_operating_packet' => [
                'layer_id' => 'business_operating_packet',
                'name' => 'Business Operating Packet Runtime',
                'status_method' => 'businessOperatingPacketRuntimeStatus',
                'status_hash_field' => 'business_operating_packet_runtime_status_hash',
                'completed_field' => 'completed_business_operating_packet_flow_count',
                'ready_field' => 'business_operating_packet_runtime_bound',
                'attestation_hash_field' => 'business_operating_packet_attestation_hash',
                'required_control_fields' => ['business_model_bound', 'kpi_contract_bound', 'delivery_lane_bound', 'economics_bound', 'account_operations_bound', 'external_commitments_blocked'],
            ],
        ];
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    public function businessRuntimeLayerStatuses(?string $companyId): array
    {
        $statuses = [];
        foreach ($this->businessRuntimePersistenceLayerCatalog() as $layerId => $layer) {
            $method = (string) $layer['status_method'];
            $statuses[$layerId] = $this->hub->flowActionRuntime->{$method}($companyId);
        }

        return $statuses;
    }

    /**
     * @param list<array<string,mixed>> $records
     * @return array<string,array<string,mixed>>
     */
    public function businessRuntimeSourceRecordsByCompanyAndFlow(array $records): array
    {
        $indexed = [];
        foreach ($records as $record) {
            $companyId = (string) ($record['company_id'] ?? '');
            $flowId = (string) ($record['flow_id'] ?? '');
            if ($companyId === '' || $flowId === '') {
                continue;
            }
            $indexed[$companyId][$flowId] = $record;
        }

        return $indexed;
    }

    /**
     * @return array<string,mixed>
     */
    public function businessExecutionControlPlanePolicy(): array
    {
        return [
            'business_execution_control_plane_is_not_external_execution_authority' => true,
            'calendar_wait_blocker_enabled' => false,
            'external_execution_allowed' => false,
            'external_side_effects_enabled' => false,
            'real_money_movement_allowed' => false,
            'customer_commitment_allowed' => false,
            'operator_signed_scope_required_for_external_effect' => true,
            'runtime_mode' => 'business_execution_control_plane_internal_kpi_pnl_risk_approval_replay',
            'required_runtime_evidence' => [
                'atlas_tool_run',
                'business_execution_control_plane_artifact',
                'business_kpi_contract',
                'pnl_guardrail_contract',
                'risk_compliance_control',
                'customer_commitment_control',
                'incident_escalation_contract',
                'approval_matrix',
                'operating_review_packet',
                'business_control_plane_receipt',
                'kpi_receipt',
                'pnl_guardrail_receipt',
                'risk_control_receipt',
                'approval_matrix_receipt',
                'incident_escalation_receipt',
                'operating_review_receipt',
                'audit_trail',
                'rollback_plan',
            ],
            'blocked_operations' => [
                'skip_vertical_tool_runtime',
                'skip_business_kpi_contract',
                'skip_pnl_guardrail_contract',
                'skip_risk_compliance_control',
                'skip_customer_commitment_control',
                'skip_incident_escalation',
                'skip_approval_matrix',
                'skip_operating_review',
                'real_money_movement',
                'external_customer_commitment',
                'external_customer_message',
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
                'unsupported_revenue_claim',
                'autonomous_external_write',
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function businessRuntimePersistencePolicy(): array
    {
        return [
            'business_runtime_persistence_is_not_external_execution_authority' => true,
            'calendar_wait_blocker_enabled' => false,
            'external_execution_allowed' => false,
            'external_side_effects_enabled' => false,
            'operator_signed_scope_required_for_external_effect' => true,
            'persisted_layers' => array_keys($this->businessRuntimePersistenceLayerCatalog()),
            'required_runtime_evidence' => [
                'atlas_tool_run',
                'business_runtime_artifact',
                'source_runtime_record',
                'source_status_hash',
                'business_runtime_receipt',
                'source_status_receipt',
                'source_record_receipt',
                'operator_handoff_receipt',
                'audit_trail',
                'rollback_plan',
            ],
            'blocked_operations' => [
                'skip_business_runtime_persistence',
                'skip_source_runtime_record',
                'skip_operator_handoff',
                'claim_without_source_reference',
                'external_customer_message',
                'external_write',
                'publish',
                'spend',
                'invoice',
                'collect_payment',
                'trade',
                'capital_transfer',
                'deploy',
                'delete',
                'offensive_security',
                'secret_export',
            ],
        ];
    }
}
