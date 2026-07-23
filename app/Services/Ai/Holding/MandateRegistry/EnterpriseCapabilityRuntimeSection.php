<?php

namespace App\Services\Ai\Holding\MandateRegistry;

use App\Models\AiHoldingActivationBacklogItem;
use App\Models\AiHoldingConnectorActivationRecord;
use App\Models\AiHoldingEnterpriseFlowOperationsRunbook;
use App\Models\AiHoldingEnterpriseFlowRunQueueItem;
use App\Models\AiHoldingExternalActionMandate;
use App\Models\AiHoldingExternalCutoverRuntimeInvocation;
use App\Models\AiHoldingExternalCutoverWorkItem;
use App\Models\AiHoldingExternalCutoverWorkOrder;
use App\Models\AiOperatorApproval;
use App\Models\AtlasToolRun;
use App\Services\Ai\Holding\ExternalActionMandateRegistryService;
use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\Support\DatabaseTableAvailability;
use App\Support\AtlasEnvelope;

/**
 * GOD-DEBULK method-family section extracted verbatim from
 * ExternalActionMandateRegistryService. Behavior frozen by
 * ExternalActionMandateRegistryServiceGoldenCharacterizationTest.
 */
class EnterpriseCapabilityRuntimeSection
{
    public function __construct(private readonly MandateRegistryHub $hub) {}

    /**
     * @return array<string,mixed>
     */
    public function enterpriseCompanyCapabilityRuntimeMeshRegister(?string $companyId = null): array
    {
        $wantedCompany = $companyId !== null && trim($companyId) !== '' ? trim($companyId) : null;

        if (! $this->hub->approval->toolRunsTableAvailable()) {
            return [
                'ok' => false,
                'schema' => ExternalActionMandateRegistryService::ENTERPRISE_COMPANY_CAPABILITY_RUNTIME_MESH_REGISTER_SCHEMA,
                'status' => 'atlas_tool_runs_table_unavailable',
                'generated_at' => now()->toJSON(),
                'summary' => [
                    'company_count' => 0,
                    'capability_count' => count($this->enterpriseCapabilityRuntimeCatalog()),
                    'expected_capability_runtime_run_count' => 0,
                    'registered_capability_runtime_run_count' => 0,
                    'external_execution_allowed_count' => 0,
                    'external_side_effects_enabled_count' => 0,
                ],
                'companies' => [],
                'policy' => $this->enterpriseCapabilityRuntimePolicy(),
            ];
        }

        $businessRuntime = $this->hub->enterprisePersistenceControlPlane->enterpriseCompanyBusinessRuntimePersistenceStatus($wantedCompany);
        $businessRuntimeByCompany = $this->hub->companyRowsById($businessRuntime);
        $blueprintRuntime = $this->hub->enterpriseWorkProductRuntime->enterpriseCompanyOperatingBlueprintRuntimeStatus($wantedCompany);
        $blueprintRuntimeByCompany = $this->hub->companyRowsById($blueprintRuntime);
        $workProductRuntime = $this->hub->enterpriseWorkProductRuntime->enterpriseCompanyWorkProductRuntimeStatus($wantedCompany);
        $workProductRuntimeByCompany = $this->hub->companyRowsById($workProductRuntime);

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

            foreach ($flows as $flowId) {
                foreach ($this->enterpriseCapabilityRuntimeCatalog() as $capability) {
                    $capabilityId = (string) $capability['capability_id'];
                    $runContextId = $id.'.'.$flowId.'.'.$capabilityId.'.enterprise_capability_runtime.v1';
                    $artifact = $this->enterpriseCapabilityRuntimeArtifact(
                        $company,
                        $flowId,
                        $capability,
                        (array) ($businessRuntimeByCompany[$id] ?? []),
                        (array) ($blueprintRuntimeByCompany[$id] ?? []),
                        (array) ($workProductRuntimeByCompany[$id] ?? []),
                        $runContextId,
                    );
                    $qualityGates = [
                        'capability_catalog_bound' => count((array) ($capability['required_surfaces'] ?? [])) >= 4,
                        'flow_bound' => $flowId !== '',
                        'company_connectors_bound' => count((array) ($company['connectors'] ?? [])) >= 3,
                        'source_lineage_bound' => strlen((string) data_get($artifact, 'source_lineage.source_lineage_hash', '')) === 64,
                        'adapter_contract_bound' => strlen((string) data_get($artifact, 'adapter_contract.adapter_contract_hash', '')) === 64,
                        'data_plane_bound' => count((array) data_get($artifact, 'data_plane.required_sources', [])) >= 3,
                        'agent_workflow_bound' => count((array) data_get($artifact, 'agent_workflow.workflow_steps', [])) >= 6,
                        'compliance_controls_bound' => count((array) data_get($artifact, 'compliance.controls', [])) >= 5,
                        'eval_replay_bound' => strlen((string) data_get($artifact, 'eval_replay.eval_replay_hash', '')) === 64,
                        'operator_handoff_bound' => (bool) data_get($artifact, 'operator_handoff.operator_acceptance_required', false),
                        'external_effects_blocked' => ! (bool) ($artifact['external_execution_allowed'] ?? true)
                            && ! (bool) ($artifact['external_side_effects_enabled'] ?? true),
                    ];
                    $summary = [
                        'company_id' => $id,
                        'flow_id' => $flowId,
                        'capability_id' => $capabilityId,
                        'capability_name' => (string) $capability['name'],
                        'domain_family' => (string) $capability['domain_family'],
                        'execution_mode' => 'internal_enterprise_capability_runtime_no_external_effect',
                        'quality_gate_count' => count($qualityGates),
                        'ready_quality_gate_count' => count(array_filter($qualityGates)),
                        'external_execution_allowed' => false,
                        'external_side_effects_enabled' => false,
                    ];
                    $normalized = [
                        'schema' => 'atlas.ai.company.enterprise_capability_runtime_result.v1',
                        'status' => count(array_filter($qualityGates)) === count($qualityGates) ? 'passed' : 'attention_required',
                        'summary' => $summary,
                        'capability_runtime_artifact' => $artifact,
                        'quality_gates' => $qualityGates,
                        'blocking_failures' => array_values(array_keys(array_filter($qualityGates, static fn (bool $ready): bool => ! $ready))),
                    ];
                    $receiptInput = [
                        'company_id' => $id,
                        'flow_id' => $flowId,
                        'capability_id' => $capabilityId,
                        'run_context_id' => $runContextId,
                        'artifact_hash' => (string) ($artifact['capability_runtime_artifact_hash'] ?? ''),
                    ];
                    $metadata = [
                        'source' => 'enterprise_company_capability_runtime_mesh_register',
                        'receipt_schema_version' => 'atlas.company_capability_runtime_mesh_receipt.v1',
                        'evidence_receipt_hash' => MissionCanonicalHash::sha256($normalized),
                        'decision_receipt_hash' => MissionCanonicalHash::sha256($receiptInput + ['receipt_type' => 'decision_receipt']),
                        'capability_runtime_receipt_hash' => MissionCanonicalHash::sha256($receiptInput + ['receipt_type' => 'capability_runtime_receipt']),
                        'source_lineage_receipt_hash' => MissionCanonicalHash::sha256($receiptInput + ['receipt_type' => 'source_lineage_receipt']),
                        'adapter_contract_receipt_hash' => MissionCanonicalHash::sha256($receiptInput + ['receipt_type' => 'adapter_contract_receipt']),
                        'eval_replay_receipt_hash' => MissionCanonicalHash::sha256($receiptInput + ['receipt_type' => 'eval_replay_receipt']),
                        'operator_handoff_receipt_hash' => MissionCanonicalHash::sha256($receiptInput + ['receipt_type' => 'operator_handoff_receipt']),
                        'audit_trail_hash' => MissionCanonicalHash::sha256($receiptInput + ['receipt_type' => 'audit_trail']),
                        'rollback_plan_hash' => MissionCanonicalHash::sha256($receiptInput + ['receipt_type' => 'rollback_plan']),
                        'external_execution_allowed' => false,
                        'external_side_effects_enabled' => false,
                        'operator_signed_scope_required_for_external_effect' => true,
                        'action_runtime_contract' => [
                            'schema_version' => 'atlas.company_capability_runtime_mesh.contract.v1',
                            'mode' => 'internal_enterprise_capability_runtime_no_external_effect',
                            'run_context_type' => 'holding_company_enterprise_capability_runtime',
                            'run_context_id' => $runContextId,
                            'provider_dispatch_allowed' => false,
                            'external_write_allowed' => false,
                            'customer_message_allowed' => false,
                            'billing_or_capital_action_allowed' => false,
                            'security_action_allowed' => false,
                            'operator_approval_required_for_external_effect' => true,
                        ],
                    ];

                    $run = AtlasToolRun::query()->updateOrCreate(
                        [
                            'surface' => 'holding_company_enterprise_capability_runtime',
                            'run_context_type' => 'holding_company_enterprise_capability_runtime',
                            'run_context_id' => $runContextId,
                        ],
                        [
                            'tool_slug' => 'atlas_company_enterprise_capability_runtime_mesh',
                            'workspace_hash' => hash('sha256', base_path()),
                            'workspace' => base_path(),
                            'status' => $normalized['status'] === 'passed' ? 'passed' : 'failed',
                            'required' => true,
                            'failure_policy' => 'fail_closed',
                            'policy_decision' => 'internal_enterprise_capability_allowed',
                            'command_hash' => MissionCanonicalHash::sha256([
                                'run_context_id' => $runContextId,
                                'artifact_hash' => $artifact['capability_runtime_artifact_hash'] ?? null,
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
                                'blocked_operations' => $this->enterpriseCapabilityRuntimePolicy()['blocked_operations'],
                            ],
                            'metadata_json' => $metadata,
                        ],
                    );

                    $registeredRuns[] = [
                        'run_id' => (string) $run->id,
                        'run_context_id' => $runContextId,
                        'flow_id' => $flowId,
                        'capability_id' => $capabilityId,
                        'status' => (string) $run->status,
                        'policy_decision' => (string) $run->policy_decision,
                        'capability_runtime_receipt_hash' => (string) data_get($run->metadata_json, 'capability_runtime_receipt_hash', ''),
                        'external_execution_allowed' => false,
                        'external_side_effects_enabled' => false,
                    ];
                }
            }

            $row = [
                'schema' => 'atlas.ai.company.enterprise_capability_runtime_mesh_register_record.v1',
                'company_id' => $id,
                'expected_flow_count' => count($flows),
                'capability_count' => count($this->enterpriseCapabilityRuntimeCatalog()),
                'expected_capability_runtime_run_count' => count($flows) * count($this->enterpriseCapabilityRuntimeCatalog()),
                'registered_capability_runtime_run_count' => count($registeredRuns),
                'registered_runs' => $registeredRuns,
                'external_execution_allowed' => false,
                'external_side_effects_enabled' => false,
            ];
            $row['company_capability_runtime_mesh_register_record_hash'] = MissionCanonicalHash::sha256($row);
            $companies[] = $row;
        }

        $registeredRunCount = array_sum(array_map(static fn (array $company): int => (int) ($company['registered_capability_runtime_run_count'] ?? 0), $companies));
        $expectedRunCount = array_sum(array_map(static fn (array $company): int => (int) ($company['expected_capability_runtime_run_count'] ?? 0), $companies));
        $payload = [
            'ok' => $expectedRunCount > 0 && $registeredRunCount >= $expectedRunCount,
            'schema' => ExternalActionMandateRegistryService::ENTERPRISE_COMPANY_CAPABILITY_RUNTIME_MESH_REGISTER_SCHEMA,
            'status' => $expectedRunCount > 0 && $registeredRunCount >= $expectedRunCount
                ? 'enterprise_company_capability_runtime_mesh_registered_external_effects_blocked'
                : 'enterprise_company_capability_runtime_mesh_registration_attention_required',
            'generated_at' => now()->toJSON(),
            'summary' => [
                'company_count' => count($companies),
                'capability_count' => count($this->enterpriseCapabilityRuntimeCatalog()),
                'registered_capability_runtime_run_count' => $registeredRunCount,
                'expected_capability_runtime_run_count' => $expectedRunCount,
                'external_execution_allowed_count' => 0,
                'external_side_effects_enabled_count' => 0,
            ],
            'companies' => $companies,
            'policy' => $this->enterpriseCapabilityRuntimePolicy(),
        ];
        $payload['enterprise_company_capability_runtime_mesh_register_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function enterpriseCompanyCapabilityRuntimeMeshStatus(?string $companyId = null): array
    {
        $wantedCompany = $companyId !== null && trim($companyId) !== '' ? trim($companyId) : null;

        if (! $this->hub->approval->toolRunsTableAvailable()) {
            return [
                'ok' => false,
                'schema' => ExternalActionMandateRegistryService::ENTERPRISE_COMPANY_CAPABILITY_RUNTIME_MESH_STATUS_SCHEMA,
                'status' => 'atlas_tool_runs_table_unavailable',
                'generated_at' => now()->toJSON(),
                'summary' => [
                    'company_count' => 0,
                    'capability_count' => count($this->enterpriseCapabilityRuntimeCatalog()),
                    'expected_capability_runtime_run_count' => 0,
                    'persisted_capability_runtime_run_count' => 0,
                    'ready_capability_runtime_run_count' => 0,
                    'external_execution_allowed_count' => 0,
                    'external_side_effects_enabled_count' => 0,
                ],
                'companies' => [],
                'policy' => $this->enterpriseCapabilityRuntimePolicy(),
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

            foreach ($flows as $flowId) {
                foreach ($this->enterpriseCapabilityRuntimeCatalog() as $capability) {
                    $capabilityId = (string) $capability['capability_id'];
                    $runContextId = $id.'.'.$flowId.'.'.$capabilityId.'.enterprise_capability_runtime.v1';
                    $run = AtlasToolRun::query()
                        ->where('surface', 'holding_company_enterprise_capability_runtime')
                        ->where('run_context_type', 'holding_company_enterprise_capability_runtime')
                        ->where('run_context_id', $runContextId)
                        ->latest('updated_at')
                        ->first();

                    $recordGates = [
                        'persisted_capability_runtime_run_exists' => $run instanceof AtlasToolRun,
                        'status_passed' => $run instanceof AtlasToolRun && $run->status === 'passed',
                        'policy_internal_enterprise_capability_allowed' => $run instanceof AtlasToolRun && $run->policy_decision === 'internal_enterprise_capability_allowed',
                        'normalized_result_bound' => $run instanceof AtlasToolRun && data_get($run->normalized_result_json, 'schema') === 'atlas.ai.company.enterprise_capability_runtime_result.v1',
                        'capability_runtime_artifact_bound' => $run instanceof AtlasToolRun
                            && data_get($run->normalized_result_json, 'capability_runtime_artifact.schema') === 'atlas.ai.company.enterprise_capability_runtime_artifact.v1'
                            && strlen((string) data_get($run->normalized_result_json, 'capability_runtime_artifact.capability_runtime_artifact_hash', '')) === 64,
                        'adapter_contract_bound' => $run instanceof AtlasToolRun
                            && strlen((string) data_get($run->normalized_result_json, 'capability_runtime_artifact.adapter_contract.adapter_contract_hash', '')) === 64,
                        'receipt_metadata_bound' => $run instanceof AtlasToolRun
                            && strlen((string) data_get($run->metadata_json, 'decision_receipt_hash', '')) === 64
                            && strlen((string) data_get($run->metadata_json, 'capability_runtime_receipt_hash', '')) === 64
                            && strlen((string) data_get($run->metadata_json, 'source_lineage_receipt_hash', '')) === 64
                            && strlen((string) data_get($run->metadata_json, 'adapter_contract_receipt_hash', '')) === 64
                            && strlen((string) data_get($run->metadata_json, 'eval_replay_receipt_hash', '')) === 64
                            && strlen((string) data_get($run->metadata_json, 'operator_handoff_receipt_hash', '')) === 64
                            && strlen((string) data_get($run->metadata_json, 'audit_trail_hash', '')) === 64
                            && strlen((string) data_get($run->metadata_json, 'rollback_plan_hash', '')) === 64,
                        'quality_gates_bound' => $run instanceof AtlasToolRun
                            && count(array_filter((array) data_get($run->normalized_result_json, 'quality_gates', []))) >= 10,
                        'external_effects_blocked' => $run instanceof AtlasToolRun
                            && ! (bool) data_get($run->metadata_json, 'external_execution_allowed', true)
                            && ! (bool) data_get($run->metadata_json, 'external_side_effects_enabled', true),
                    ];
                    $readyRecordGateCount = count(array_filter($recordGates));
                    $runtimeRecord = [
                        'schema' => 'atlas.ai.company.enterprise_capability_runtime_mesh_status_record.v1',
                        'company_id' => $id,
                        'flow_id' => $flowId,
                        'capability_id' => $capabilityId,
                        'run_context_id' => $runContextId,
                        'tool_run_id' => $run instanceof AtlasToolRun ? (string) $run->id : null,
                        'ready' => $readyRecordGateCount === count($recordGates),
                        'ready_gate_count' => $readyRecordGateCount,
                        'required_gate_count' => count($recordGates),
                        'gates' => $recordGates,
                        'missing_gates' => array_values(array_keys(array_filter($recordGates, static fn (bool $ready): bool => ! $ready))),
                        'receipt_chain' => $run instanceof AtlasToolRun ? [
                            'decision_receipt_hash' => (string) data_get($run->metadata_json, 'decision_receipt_hash', ''),
                            'capability_runtime_receipt_hash' => (string) data_get($run->metadata_json, 'capability_runtime_receipt_hash', ''),
                            'source_lineage_receipt_hash' => (string) data_get($run->metadata_json, 'source_lineage_receipt_hash', ''),
                            'adapter_contract_receipt_hash' => (string) data_get($run->metadata_json, 'adapter_contract_receipt_hash', ''),
                            'eval_replay_receipt_hash' => (string) data_get($run->metadata_json, 'eval_replay_receipt_hash', ''),
                            'operator_handoff_receipt_hash' => (string) data_get($run->metadata_json, 'operator_handoff_receipt_hash', ''),
                            'audit_trail_hash' => (string) data_get($run->metadata_json, 'audit_trail_hash', ''),
                            'rollback_plan_hash' => (string) data_get($run->metadata_json, 'rollback_plan_hash', ''),
                        ] : [],
                        'external_execution_allowed' => false,
                        'external_side_effects_enabled' => false,
                    ];
                    $runtimeRecord['capability_runtime_mesh_status_record_hash'] = MissionCanonicalHash::sha256($runtimeRecord);
                    $runtimeRecords[] = $runtimeRecord;
                }
            }

            $readyRuntimeRecordCount = count(array_filter($runtimeRecords, static fn (array $record): bool => (bool) ($record['ready'] ?? false)));
            $expectedRunCount = count($flows) * count($this->enterpriseCapabilityRuntimeCatalog());
            $capabilityGroups = array_values(array_unique(array_map(
                static fn (array $capability): string => (string) $capability['domain_family'],
                $this->enterpriseCapabilityRuntimeCatalog(),
            )));
            $domainFamilyGates = [];
            foreach ($capabilityGroups as $domainFamily) {
                $domainFamilyGates[$domainFamily.'_capability_family_ready'] = count(array_filter(
                    $runtimeRecords,
                    static fn (array $record): bool => (bool) ($record['ready'] ?? false)
                        && str_contains((string) ($record['capability_id'] ?? ''), str_replace('_operations', '', $domainFamily)),
                )) > 0 || $readyRuntimeRecordCount >= $expectedRunCount;
            }
            $gates = array_merge($domainFamilyGates, [
                'persisted_capability_runtime_runs_cover_all_capabilities_and_flows' => $expectedRunCount > 0
                    && count($runtimeRecords) >= $expectedRunCount
                    && $readyRuntimeRecordCount === count($runtimeRecords),
                'external_effects_blocked' => count(array_filter($runtimeRecords, static fn (array $record): bool => (bool) ($record['external_execution_allowed'] ?? true)
                    || (bool) ($record['external_side_effects_enabled'] ?? true))) === 0,
            ]);
            $readyGateCount = count(array_filter($gates));
            $row = [
                'schema' => 'atlas.ai.company.enterprise_capability_runtime_mesh_company_status.v1',
                'company_id' => $id,
                'expected_flow_count' => count($flows),
                'capability_count' => count($this->enterpriseCapabilityRuntimeCatalog()),
                'expected_capability_runtime_run_count' => $expectedRunCount,
                'persisted_capability_runtime_run_count' => count($runtimeRecords),
                'ready_capability_runtime_run_count' => $readyRuntimeRecordCount,
                'capability_runtime_mesh_ready' => $expectedRunCount > 0 && $readyGateCount === count($gates),
                'runtime_grade' => $expectedRunCount > 0 && $readyGateCount === count($gates)
                    ? 'target_9_enterprise_capability_runtime_mesh_ready_external_effects_blocked'
                    : 'enterprise_capability_runtime_mesh_attention_required',
                'ready_gate_count' => $readyGateCount,
                'required_gate_count' => count($gates),
                'gates' => $gates,
                'missing_gates' => array_values(array_keys(array_filter($gates, static fn (bool $ready): bool => ! $ready))),
                'runtime_records' => $runtimeRecords,
                'external_execution_allowed' => false,
                'external_side_effects_enabled' => false,
            ];
            $row['company_capability_runtime_mesh_status_record_hash'] = MissionCanonicalHash::sha256($row);
            $companies[] = $row;
        }

        $readyCompanyCount = count(array_filter($companies, static fn (array $company): bool => (bool) ($company['capability_runtime_mesh_ready'] ?? false)));
        $payload = [
            'ok' => $companies !== [] && $readyCompanyCount === count($companies),
            'schema' => ExternalActionMandateRegistryService::ENTERPRISE_COMPANY_CAPABILITY_RUNTIME_MESH_STATUS_SCHEMA,
            'status' => $companies !== [] && $readyCompanyCount === count($companies)
                ? 'enterprise_company_capability_runtime_mesh_ready_external_effects_blocked'
                : 'enterprise_company_capability_runtime_mesh_attention_required',
            'generated_at' => now()->toJSON(),
            'summary' => [
                'company_count' => count($companies),
                'capability_count' => count($this->enterpriseCapabilityRuntimeCatalog()),
                'capability_runtime_mesh_ready_company_count' => $readyCompanyCount,
                'attention_company_count' => count($companies) - $readyCompanyCount,
                'expected_capability_runtime_run_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['expected_capability_runtime_run_count'] ?? 0), $companies)),
                'persisted_capability_runtime_run_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['persisted_capability_runtime_run_count'] ?? 0), $companies)),
                'ready_capability_runtime_run_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['ready_capability_runtime_run_count'] ?? 0), $companies)),
                'required_gate_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['required_gate_count'] ?? 0), $companies)),
                'ready_gate_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['ready_gate_count'] ?? 0), $companies)),
                'external_execution_allowed_count' => 0,
                'external_side_effects_enabled_count' => 0,
            ],
            'companies' => $companies,
            'policy' => $this->enterpriseCapabilityRuntimePolicy(),
        ];
        $payload['enterprise_company_capability_runtime_mesh_status_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function enterpriseCompanySupervisedConnectorExecutionRegister(?string $companyId = null): array
    {
        $wantedCompany = $companyId !== null && trim($companyId) !== '' ? trim($companyId) : null;

        if (! $this->hub->approval->toolRunsTableAvailable()) {
            return [
                'ok' => false,
                'schema' => ExternalActionMandateRegistryService::ENTERPRISE_COMPANY_SUPERVISED_CONNECTOR_EXECUTION_REGISTER_SCHEMA,
                'status' => 'atlas_tool_runs_table_unavailable',
                'generated_at' => now()->toJSON(),
                'summary' => [
                    'company_count' => 0,
                    'expected_supervised_connector_execution_run_count' => 0,
                    'registered_supervised_connector_execution_run_count' => 0,
                    'external_execution_allowed_count' => 0,
                    'external_side_effects_enabled_count' => 0,
                ],
                'companies' => [],
                'policy' => $this->supervisedConnectorExecutionPolicy(),
            ];
        }

        $capabilityRuntime = $this->enterpriseCompanyCapabilityRuntimeMeshStatus($wantedCompany);
        $productionPreflight = $this->hub->companyOperatingStatus->productionConnectorPreflightStatus($wantedCompany);
        $liveRead = $this->hub->activationBacklog->liveReadConnectorReadinessStatus($wantedCompany);
        $connectorActivation = $this->hub->activationBacklog->connectorActivationStatus($wantedCompany);
        $capabilityByCompany = $this->hub->companyRowsById($capabilityRuntime);
        $productionByCompany = $this->hub->companyRowsById($productionPreflight);
        $liveReadByCompany = $this->hub->companyRowsById($liveRead);
        $connectorActivationByCompany = $this->hub->companyRowsById($connectorActivation);

        $companies = [];
        foreach ($this->hub->buildoutCompanies($wantedCompany) as $company) {
            $id = (string) ($company['company_id'] ?? 'unknown');
            $capabilityRow = (array) ($capabilityByCompany[$id] ?? []);
            $productionRow = (array) ($productionByCompany[$id] ?? []);
            $liveReadRow = (array) ($liveReadByCompany[$id] ?? []);
            $connectorActivationRow = (array) ($connectorActivationByCompany[$id] ?? []);
            $registeredRuns = [];

            foreach ((array) ($capabilityRow['runtime_records'] ?? []) as $capabilityRecord) {
                $flowId = (string) ($capabilityRecord['flow_id'] ?? '');
                $capabilityId = (string) ($capabilityRecord['capability_id'] ?? '');
                if ($flowId === '' || $capabilityId === '') {
                    continue;
                }

                $connectorId = $this->supervisedConnectorForCapability($company, $capabilityId);
                $runContextId = $id.'.'.substr(MissionCanonicalHash::sha256([
                    'flow_id' => $flowId,
                    'capability_id' => $capabilityId,
                    'connector_id' => $connectorId,
                    'context' => 'supervised_connector_execution',
                ]), 0, 24).'.supervised_connector_execution.v1';
                $artifact = $this->supervisedConnectorExecutionArtifact(
                    $company,
                    $flowId,
                    $capabilityId,
                    $connectorId,
                    (array) $capabilityRecord,
                    $productionRow,
                    $liveReadRow,
                    $connectorActivationRow,
                    $runContextId,
                );
                $qualityGates = [
                    'capability_runtime_ready' => (bool) ($capabilityRecord['ready'] ?? false)
                        && strlen((string) ($capabilityRecord['capability_runtime_mesh_status_record_hash'] ?? '')) === 64,
                    'connector_selected' => $connectorId !== '',
                    'connector_activation_ready_or_structural' => (bool) ($connectorActivationRow['connector_activation_ready'] ?? true)
                        || strlen((string) ($connectorActivation['connector_activation_status_hash'] ?? '')) === 64,
                    'production_preflight_bound' => (bool) ($productionRow['production_connector_preflight_ready'] ?? true)
                        || strlen((string) ($productionPreflight['production_connector_preflight_status_hash'] ?? '')) === 64,
                    'live_read_ready_or_simulated' => (bool) ($liveReadRow['live_read_connector_readiness_ready'] ?? true)
                        || strlen((string) ($liveRead['live_read_connector_readiness_status_hash'] ?? '')) === 64,
                    'adapter_contract_bound' => strlen((string) data_get($artifact, 'adapter_contract.adapter_contract_hash', '')) === 64,
                    'probe_packet_bound' => strlen((string) data_get($artifact, 'supervised_probe.probe_packet_hash', '')) === 64,
                    'execution_plan_bound' => count((array) data_get($artifact, 'execution_plan.steps', [])) >= 7,
                    'receipt_plan_bound' => count((array) data_get($artifact, 'receipt_plan.required_receipts', [])) >= 8,
                    'operator_handoff_bound' => (bool) data_get($artifact, 'operator_handoff.operator_acceptance_required', false),
                    'external_effects_blocked' => ! (bool) ($artifact['external_execution_allowed'] ?? true)
                        && ! (bool) ($artifact['external_side_effects_enabled'] ?? true),
                ];
                $summary = [
                    'company_id' => $id,
                    'flow_id' => $flowId,
                    'capability_id' => $capabilityId,
                    'connector_id' => $connectorId,
                    'execution_mode' => 'supervised_connector_execution_dry_run_no_external_effect',
                    'quality_gate_count' => count($qualityGates),
                    'ready_quality_gate_count' => count(array_filter($qualityGates)),
                    'external_execution_allowed' => false,
                    'external_side_effects_enabled' => false,
                ];
                $normalized = [
                    'schema' => 'atlas.ai.company.supervised_connector_execution_result.v1',
                    'status' => count(array_filter($qualityGates)) === count($qualityGates) ? 'passed' : 'attention_required',
                    'summary' => $summary,
                    'supervised_connector_execution_artifact' => $artifact,
                    'quality_gates' => $qualityGates,
                    'blocking_failures' => array_values(array_keys(array_filter($qualityGates, static fn (bool $ready): bool => ! $ready))),
                ];
                $receiptInput = [
                    'company_id' => $id,
                    'flow_id' => $flowId,
                    'capability_id' => $capabilityId,
                    'connector_id' => $connectorId,
                    'run_context_id' => $runContextId,
                    'artifact_hash' => (string) ($artifact['supervised_connector_execution_artifact_hash'] ?? ''),
                ];
                $metadata = [
                    'source' => 'enterprise_company_supervised_connector_execution_register',
                    'receipt_schema_version' => 'atlas.company_supervised_connector_execution_receipt.v1',
                    'evidence_receipt_hash' => MissionCanonicalHash::sha256($normalized),
                    'decision_receipt_hash' => MissionCanonicalHash::sha256($receiptInput + ['receipt_type' => 'decision_receipt']),
                    'connector_execution_receipt_hash' => MissionCanonicalHash::sha256($receiptInput + ['receipt_type' => 'connector_execution_receipt']),
                    'adapter_contract_receipt_hash' => MissionCanonicalHash::sha256($receiptInput + ['receipt_type' => 'adapter_contract_receipt']),
                    'probe_receipt_hash' => MissionCanonicalHash::sha256($receiptInput + ['receipt_type' => 'probe_receipt']),
                    'source_lineage_receipt_hash' => MissionCanonicalHash::sha256($receiptInput + ['receipt_type' => 'source_lineage_receipt']),
                    'operator_handoff_receipt_hash' => MissionCanonicalHash::sha256($receiptInput + ['receipt_type' => 'operator_handoff_receipt']),
                    'audit_trail_hash' => MissionCanonicalHash::sha256($receiptInput + ['receipt_type' => 'audit_trail']),
                    'rollback_plan_hash' => MissionCanonicalHash::sha256($receiptInput + ['receipt_type' => 'rollback_plan']),
                    'external_execution_allowed' => false,
                    'external_side_effects_enabled' => false,
                    'operator_signed_scope_required_for_external_effect' => true,
                    'action_runtime_contract' => [
                        'schema_version' => 'atlas.company_supervised_connector_execution.contract.v1',
                        'mode' => 'supervised_connector_execution_dry_run_no_external_effect',
                        'run_context_type' => 'holding_company_supervised_connector_execution',
                        'run_context_id' => $runContextId,
                        'provider_dispatch_allowed' => false,
                        'external_write_allowed' => false,
                        'customer_message_allowed' => false,
                        'billing_or_capital_action_allowed' => false,
                        'security_action_allowed' => false,
                        'operator_approval_required_for_external_effect' => true,
                    ],
                ];

                $run = AtlasToolRun::query()->updateOrCreate(
                    [
                        'surface' => 'holding_company_supervised_connector_execution',
                        'run_context_type' => 'holding_company_supervised_connector_execution',
                        'run_context_id' => $runContextId,
                    ],
                    [
                        'tool_slug' => 'atlas_company_supervised_connector_execution',
                        'workspace_hash' => hash('sha256', base_path()),
                        'workspace' => base_path(),
                        'status' => $normalized['status'] === 'passed' ? 'passed' : 'failed',
                        'required' => true,
                        'failure_policy' => 'fail_closed',
                        'policy_decision' => 'supervised_dry_run_allowed',
                        'command_hash' => MissionCanonicalHash::sha256([
                            'run_context_id' => $runContextId,
                            'artifact_hash' => $artifact['supervised_connector_execution_artifact_hash'] ?? null,
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
                            'blocked_operations' => $this->supervisedConnectorExecutionPolicy()['blocked_operations'],
                        ],
                        'metadata_json' => $metadata,
                    ],
                );

                $registeredRuns[] = [
                    'run_id' => (string) $run->id,
                    'run_context_id' => $runContextId,
                    'flow_id' => $flowId,
                    'capability_id' => $capabilityId,
                    'connector_id' => $connectorId,
                    'status' => (string) $run->status,
                    'policy_decision' => (string) $run->policy_decision,
                    'connector_execution_receipt_hash' => (string) data_get($run->metadata_json, 'connector_execution_receipt_hash', ''),
                    'external_execution_allowed' => false,
                    'external_side_effects_enabled' => false,
                ];
            }

            $row = [
                'schema' => 'atlas.ai.company.enterprise_supervised_connector_execution_register_record.v1',
                'company_id' => $id,
                'expected_capability_runtime_run_count' => (int) ($capabilityRow['ready_capability_runtime_run_count'] ?? 0),
                'registered_supervised_connector_execution_run_count' => count($registeredRuns),
                'registered_runs' => $registeredRuns,
                'external_execution_allowed' => false,
                'external_side_effects_enabled' => false,
            ];
            $row['company_supervised_connector_execution_register_record_hash'] = MissionCanonicalHash::sha256($row);
            $companies[] = $row;
        }

        $registeredRunCount = array_sum(array_map(static fn (array $company): int => (int) ($company['registered_supervised_connector_execution_run_count'] ?? 0), $companies));
        $expectedRunCount = array_sum(array_map(static fn (array $company): int => (int) ($company['expected_capability_runtime_run_count'] ?? 0), $companies));
        $payload = [
            'ok' => $expectedRunCount > 0 && $registeredRunCount >= $expectedRunCount,
            'schema' => ExternalActionMandateRegistryService::ENTERPRISE_COMPANY_SUPERVISED_CONNECTOR_EXECUTION_REGISTER_SCHEMA,
            'status' => $expectedRunCount > 0 && $registeredRunCount >= $expectedRunCount
                ? 'enterprise_company_supervised_connector_execution_registered_external_effects_blocked'
                : 'enterprise_company_supervised_connector_execution_registration_attention_required',
            'generated_at' => now()->toJSON(),
            'summary' => [
                'company_count' => count($companies),
                'expected_supervised_connector_execution_run_count' => $expectedRunCount,
                'registered_supervised_connector_execution_run_count' => $registeredRunCount,
                'external_execution_allowed_count' => 0,
                'external_side_effects_enabled_count' => 0,
            ],
            'source_hashes' => [
                'enterprise_company_capability_runtime_mesh_status_hash' => $capabilityRuntime['enterprise_company_capability_runtime_mesh_status_hash'] ?? null,
                'production_connector_preflight_status_hash' => $productionPreflight['production_connector_preflight_status_hash'] ?? null,
                'live_read_connector_readiness_status_hash' => $liveRead['live_read_connector_readiness_status_hash'] ?? null,
                'connector_activation_status_hash' => $connectorActivation['connector_activation_status_hash'] ?? null,
            ],
            'companies' => $companies,
            'policy' => $this->supervisedConnectorExecutionPolicy(),
        ];
        $payload['enterprise_company_supervised_connector_execution_register_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function enterpriseCompanySupervisedConnectorExecutionStatus(?string $companyId = null): array
    {
        $wantedCompany = $companyId !== null && trim($companyId) !== '' ? trim($companyId) : null;
        $capabilityRuntime = $this->enterpriseCompanyCapabilityRuntimeMeshStatus($wantedCompany);
        $capabilityByCompany = $this->hub->companyRowsById($capabilityRuntime);

        if (! $this->hub->approval->toolRunsTableAvailable()) {
            return [
                'ok' => false,
                'schema' => ExternalActionMandateRegistryService::ENTERPRISE_COMPANY_SUPERVISED_CONNECTOR_EXECUTION_STATUS_SCHEMA,
                'status' => 'atlas_tool_runs_table_unavailable',
                'generated_at' => now()->toJSON(),
                'summary' => [
                    'company_count' => 0,
                    'expected_supervised_connector_execution_run_count' => 0,
                    'persisted_supervised_connector_execution_run_count' => 0,
                    'ready_supervised_connector_execution_run_count' => 0,
                    'external_execution_allowed_count' => 0,
                    'external_side_effects_enabled_count' => 0,
                ],
                'companies' => [],
                'policy' => $this->supervisedConnectorExecutionPolicy(),
            ];
        }

        $companies = [];
        foreach ($this->hub->buildoutCompanies($wantedCompany) as $company) {
            $id = (string) ($company['company_id'] ?? 'unknown');
            $capabilityRow = (array) ($capabilityByCompany[$id] ?? []);
            $runtimeRecords = [];
            foreach ((array) ($capabilityRow['runtime_records'] ?? []) as $capabilityRecord) {
                $flowId = (string) ($capabilityRecord['flow_id'] ?? '');
                $capabilityId = (string) ($capabilityRecord['capability_id'] ?? '');
                if ($flowId === '' || $capabilityId === '') {
                    continue;
                }

                $connectorId = $this->supervisedConnectorForCapability($company, $capabilityId);
                $runContextId = $id.'.'.substr(MissionCanonicalHash::sha256([
                    'flow_id' => $flowId,
                    'capability_id' => $capabilityId,
                    'connector_id' => $connectorId,
                    'context' => 'supervised_connector_execution',
                ]), 0, 24).'.supervised_connector_execution.v1';
                $run = AtlasToolRun::query()
                    ->where('surface', 'holding_company_supervised_connector_execution')
                    ->where('run_context_type', 'holding_company_supervised_connector_execution')
                    ->where('run_context_id', $runContextId)
                    ->latest('updated_at')
                    ->first();

                $recordGates = [
                    'persisted_supervised_connector_execution_run_exists' => $run instanceof AtlasToolRun,
                    'status_passed' => $run instanceof AtlasToolRun && $run->status === 'passed',
                    'policy_supervised_dry_run_allowed' => $run instanceof AtlasToolRun && $run->policy_decision === 'supervised_dry_run_allowed',
                    'normalized_result_bound' => $run instanceof AtlasToolRun && data_get($run->normalized_result_json, 'schema') === 'atlas.ai.company.supervised_connector_execution_result.v1',
                    'supervised_connector_execution_artifact_bound' => $run instanceof AtlasToolRun
                        && data_get($run->normalized_result_json, 'supervised_connector_execution_artifact.schema') === 'atlas.ai.company.supervised_connector_execution_artifact.v1'
                        && strlen((string) data_get($run->normalized_result_json, 'supervised_connector_execution_artifact.supervised_connector_execution_artifact_hash', '')) === 64,
                    'receipt_metadata_bound' => $run instanceof AtlasToolRun
                        && strlen((string) data_get($run->metadata_json, 'decision_receipt_hash', '')) === 64
                        && strlen((string) data_get($run->metadata_json, 'connector_execution_receipt_hash', '')) === 64
                        && strlen((string) data_get($run->metadata_json, 'adapter_contract_receipt_hash', '')) === 64
                        && strlen((string) data_get($run->metadata_json, 'probe_receipt_hash', '')) === 64
                        && strlen((string) data_get($run->metadata_json, 'source_lineage_receipt_hash', '')) === 64
                        && strlen((string) data_get($run->metadata_json, 'operator_handoff_receipt_hash', '')) === 64
                        && strlen((string) data_get($run->metadata_json, 'audit_trail_hash', '')) === 64
                        && strlen((string) data_get($run->metadata_json, 'rollback_plan_hash', '')) === 64,
                    'quality_gates_bound' => $run instanceof AtlasToolRun
                        && count(array_filter((array) data_get($run->normalized_result_json, 'quality_gates', []))) >= 10,
                    'external_effects_blocked' => $run instanceof AtlasToolRun
                        && ! (bool) data_get($run->metadata_json, 'external_execution_allowed', true)
                        && ! (bool) data_get($run->metadata_json, 'external_side_effects_enabled', true),
                ];
                $readyRecordGateCount = count(array_filter($recordGates));
                $runtimeRecord = [
                    'schema' => 'atlas.ai.company.supervised_connector_execution_status_record.v1',
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
                        'decision_receipt_hash' => (string) data_get($run->metadata_json, 'decision_receipt_hash', ''),
                        'connector_execution_receipt_hash' => (string) data_get($run->metadata_json, 'connector_execution_receipt_hash', ''),
                        'adapter_contract_receipt_hash' => (string) data_get($run->metadata_json, 'adapter_contract_receipt_hash', ''),
                        'probe_receipt_hash' => (string) data_get($run->metadata_json, 'probe_receipt_hash', ''),
                        'source_lineage_receipt_hash' => (string) data_get($run->metadata_json, 'source_lineage_receipt_hash', ''),
                        'operator_handoff_receipt_hash' => (string) data_get($run->metadata_json, 'operator_handoff_receipt_hash', ''),
                        'audit_trail_hash' => (string) data_get($run->metadata_json, 'audit_trail_hash', ''),
                        'rollback_plan_hash' => (string) data_get($run->metadata_json, 'rollback_plan_hash', ''),
                    ] : [],
                    'external_execution_allowed' => false,
                    'external_side_effects_enabled' => false,
                ];
                $runtimeRecord['supervised_connector_execution_status_record_hash'] = MissionCanonicalHash::sha256($runtimeRecord);
                $runtimeRecords[] = $runtimeRecord;
            }

            $readyRuntimeRecordCount = count(array_filter($runtimeRecords, static fn (array $record): bool => (bool) ($record['ready'] ?? false)));
            $expectedRunCount = (int) ($capabilityRow['ready_capability_runtime_run_count'] ?? 0);
            $gates = [
                'capability_runtime_mesh_ready' => (bool) ($capabilityRow['capability_runtime_mesh_ready'] ?? false),
                'supervised_connector_execution_runs_cover_capability_runtime_mesh' => $expectedRunCount > 0
                    && count($runtimeRecords) >= $expectedRunCount
                    && $readyRuntimeRecordCount === count($runtimeRecords),
                'external_effects_blocked' => count(array_filter($runtimeRecords, static fn (array $record): bool => (bool) ($record['external_execution_allowed'] ?? true)
                    || (bool) ($record['external_side_effects_enabled'] ?? true))) === 0,
            ];
            $readyGateCount = count(array_filter($gates));
            $row = [
                'schema' => 'atlas.ai.company.enterprise_supervised_connector_execution_company_status.v1',
                'company_id' => $id,
                'expected_supervised_connector_execution_run_count' => $expectedRunCount,
                'persisted_supervised_connector_execution_run_count' => count($runtimeRecords),
                'ready_supervised_connector_execution_run_count' => $readyRuntimeRecordCount,
                'supervised_connector_execution_ready' => $expectedRunCount > 0 && $readyGateCount === count($gates),
                'runtime_grade' => $expectedRunCount > 0 && $readyGateCount === count($gates)
                    ? 'target_9_supervised_connector_execution_ready_external_effects_blocked'
                    : 'supervised_connector_execution_attention_required',
                'ready_gate_count' => $readyGateCount,
                'required_gate_count' => count($gates),
                'gates' => $gates,
                'missing_gates' => array_values(array_keys(array_filter($gates, static fn (bool $ready): bool => ! $ready))),
                'runtime_records' => $runtimeRecords,
                'external_execution_allowed' => false,
                'external_side_effects_enabled' => false,
            ];
            $row['company_supervised_connector_execution_status_record_hash'] = MissionCanonicalHash::sha256($row);
            $companies[] = $row;
        }

        $readyCompanyCount = count(array_filter($companies, static fn (array $company): bool => (bool) ($company['supervised_connector_execution_ready'] ?? false)));
        $payload = [
            'ok' => $companies !== [] && $readyCompanyCount === count($companies),
            'schema' => ExternalActionMandateRegistryService::ENTERPRISE_COMPANY_SUPERVISED_CONNECTOR_EXECUTION_STATUS_SCHEMA,
            'status' => $companies !== [] && $readyCompanyCount === count($companies)
                ? 'enterprise_company_supervised_connector_execution_ready_external_effects_blocked'
                : 'enterprise_company_supervised_connector_execution_attention_required',
            'generated_at' => now()->toJSON(),
            'summary' => [
                'company_count' => count($companies),
                'supervised_connector_execution_ready_company_count' => $readyCompanyCount,
                'attention_company_count' => count($companies) - $readyCompanyCount,
                'expected_supervised_connector_execution_run_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['expected_supervised_connector_execution_run_count'] ?? 0), $companies)),
                'persisted_supervised_connector_execution_run_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['persisted_supervised_connector_execution_run_count'] ?? 0), $companies)),
                'ready_supervised_connector_execution_run_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['ready_supervised_connector_execution_run_count'] ?? 0), $companies)),
                'required_gate_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['required_gate_count'] ?? 0), $companies)),
                'ready_gate_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['ready_gate_count'] ?? 0), $companies)),
                'external_execution_allowed_count' => 0,
                'external_side_effects_enabled_count' => 0,
            ],
            'source_hashes' => [
                'enterprise_company_capability_runtime_mesh_status_hash' => $capabilityRuntime['enterprise_company_capability_runtime_mesh_status_hash'] ?? null,
            ],
            'companies' => $companies,
            'policy' => $this->supervisedConnectorExecutionPolicy(),
        ];
        $payload['enterprise_company_supervised_connector_execution_status_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @param array<string,mixed> $company
     * @param array<string,mixed> $capability
     * @param array<string,mixed> $businessRuntimeRow
     * @param array<string,mixed> $blueprintRuntimeRow
     * @param array<string,mixed> $workProductRuntimeRow
     * @return array<string,mixed>
     */
    public function enterpriseCapabilityRuntimeArtifact(
        array $company,
        string $flowId,
        array $capability,
        array $businessRuntimeRow,
        array $blueprintRuntimeRow,
        array $workProductRuntimeRow,
        string $runContextId,
    ): array {
        $companyId = (string) ($company['company_id'] ?? 'unknown');
        $capabilityId = (string) $capability['capability_id'];
        $businessPacket = $this->hub->findByFlow($company, 'enterprise_business_operating_packet_stack.flow_operating_packets', $flowId);
        $operatingBlueprint = $this->hub->findByFlow($company, 'enterprise_company_operating_blueprint_stack.flow_operating_blueprints', $flowId);
        $connectorContract = $this->hub->findByFlow($company, 'enterprise_domain_data_connector_operating_stack.flow_data_connector_contracts', $flowId);
        $solutionPlaybook = $this->hub->findByFlow($company, 'enterprise_domain_solution_stack.enterprise_solution_playbooks', $flowId);
        $toolExecution = $this->hub->findByFlow($company, 'enterprise_domain_tool_execution_stack.flow_tool_contracts', $flowId);
        $connectors = array_values(array_filter(array_map(
            static fn (array|string $connector): string => is_array($connector)
                ? (string) ($connector['connector_id'] ?? $connector['id'] ?? $connector['slug'] ?? '')
                : (string) $connector,
            (array) ($company['connectors'] ?? []),
        )));
        $requiredConnectors = array_values(array_unique(array_merge(
            array_slice($connectors, 0, min(4, count($connectors))),
            (array) ($capability['reference_connectors'] ?? []),
        )));
        $requiredSources = array_values(array_unique(array_merge(
            [
                'company_context_pack',
                'flow_operating_packet',
                'source_grounded_work_product',
                'evidence_ledger',
            ],
            (array) ($capability['required_sources'] ?? []),
        )));
        $adapterContract = [
            'schema' => 'atlas.ai.company.enterprise_capability_adapter_contract.v1',
            'company_id' => $companyId,
            'flow_id' => $flowId,
            'capability_id' => $capabilityId,
            'adapter_mode' => 'read_only_or_internal_sandbox_until_operator_signed_scope',
            'required_connectors' => $requiredConnectors,
            'least_privilege_scopes' => array_values((array) ($capability['least_privilege_scopes'] ?? [])),
            'write_scopes_allowed' => false,
            'external_dispatch_allowed' => false,
            'operator_approval_required_for_external_effect' => true,
        ];
        $adapterContract['adapter_contract_hash'] = MissionCanonicalHash::sha256($adapterContract);

        $evalReplay = [
            'schema' => 'atlas.ai.company.enterprise_capability_eval_replay.v1',
            'golden_cases' => [
                $companyId.'.'.$flowId.'.'.$capabilityId.'.source_grounding',
                $companyId.'.'.$flowId.'.'.$capabilityId.'.policy_blocking',
                $companyId.'.'.$flowId.'.'.$capabilityId.'.operator_handoff',
            ],
            'required_eval_dimensions' => ['source_grounding', 'policy_safety', 'business_usefulness', 'traceability', 'rollbackability'],
            'minimum_quality_score' => 0.95,
            'policy_violation_tolerance' => 0,
            'deterministic_replay_required' => true,
        ];
        $evalReplay['eval_replay_hash'] = MissionCanonicalHash::sha256($evalReplay);

        $artifact = [
            'schema' => 'atlas.ai.company.enterprise_capability_runtime_artifact.v1',
            'company_id' => $companyId,
            'flow_id' => $flowId,
            'run_context_id' => $runContextId,
            'capability_id' => $capabilityId,
            'capability_name' => (string) $capability['name'],
            'domain_family' => (string) $capability['domain_family'],
            'runtime_mode' => 'internal_enterprise_capability_runtime_no_external_effect',
            'reference_patterns' => array_values((array) ($capability['reference_patterns'] ?? [])),
            'required_surfaces' => array_values((array) ($capability['required_surfaces'] ?? [])),
            'source_lineage' => [
                'company_receipt_hash' => (string) ($company['receipt_hash'] ?? ''),
                'business_runtime_status_record_hash' => (string) ($businessRuntimeRow['company_business_runtime_persistence_status_record_hash'] ?? ''),
                'operating_blueprint_status_record_hash' => (string) ($blueprintRuntimeRow['company_operating_blueprint_runtime_status_record_hash'] ?? ''),
                'work_product_runtime_status_record_hash' => (string) ($workProductRuntimeRow['company_work_product_runtime_status_record_hash'] ?? ''),
                'business_packet_hash' => (string) ($businessPacket['operating_packet_hash'] ?? $businessPacket['packet_hash'] ?? hash('sha256', 'business_packet|'.$companyId.'|'.$flowId)),
                'operating_blueprint_hash' => (string) ($operatingBlueprint['flow_operating_blueprint_hash'] ?? hash('sha256', 'operating_blueprint|'.$companyId.'|'.$flowId)),
                'connector_contract_hash' => (string) ($connectorContract['contract_hash'] ?? $connectorContract['connector_contract_hash'] ?? hash('sha256', 'connector_contract|'.$companyId.'|'.$flowId)),
                'solution_playbook_hash' => (string) ($solutionPlaybook['playbook_hash'] ?? hash('sha256', 'solution_playbook|'.$companyId.'|'.$flowId)),
                'tool_execution_contract_hash' => (string) ($toolExecution['tool_contract_hash'] ?? hash('sha256', 'tool_execution|'.$companyId.'|'.$flowId)),
                'source_lineage_hash' => MissionCanonicalHash::sha256([
                    'company_id' => $companyId,
                    'flow_id' => $flowId,
                    'capability_id' => $capabilityId,
                    'required_sources' => $requiredSources,
                    'required_connectors' => $requiredConnectors,
                ]),
                'claim_without_source_reference_allowed' => false,
            ],
            'data_plane' => [
                'required_sources' => $requiredSources,
                'source_verification_required' => true,
                'private_or_customer_data_requires_signed_scope' => true,
                'managed_vault_boundary_required' => true,
                'unscoped_export_allowed' => false,
            ],
            'adapter_contract' => $adapterContract,
            'agent_workflow' => [
                'workflow_steps' => [
                    'intake_and_scope_lock',
                    'source_fetch_or_fixture_bind',
                    'reasoning_plan',
                    'tool_or_adapter_dry_run',
                    'artifact_assembly',
                    'source_citation_check',
                    'risk_and_policy_review',
                    'eval_replay',
                    'operator_handoff',
                ],
                'checkpoint_resume_required' => true,
                'independent_reviewer_required' => true,
                'human_escalation_required_for_external_effect' => true,
            ],
            'compliance' => [
                'controls' => array_values(array_unique(array_merge(
                    ['audit_log', 'source_citation', 'pii_boundary', 'least_privilege', 'operator_approval'],
                    (array) ($capability['compliance_controls'] ?? []),
                ))),
                'regulated_advice_or_customer_commitment_allowed' => false,
                'external_financial_security_or_marketing_action_allowed' => false,
                'policy_violation_failure_mode' => 'fail_closed',
            ],
            'eval_replay' => $evalReplay,
            'operator_handoff' => [
                'operator_acceptance_required' => true,
                'external_action_requires_new_mandate' => true,
                'handoff_packet_hash' => MissionCanonicalHash::sha256([
                    'company_id' => $companyId,
                    'flow_id' => $flowId,
                    'capability_id' => $capabilityId,
                    'handoff' => 'operator_required_before_external_effect',
                ]),
            ],
            'external_execution_allowed' => false,
            'external_side_effects_enabled' => false,
            'generated_at' => now()->toJSON(),
        ];
        $artifact['capability_runtime_artifact_hash'] = MissionCanonicalHash::sha256($artifact);

        return $artifact;
    }

    /**
     * @param array<string,mixed> $company
     * @param array<string,mixed> $capabilityRecord
     * @param array<string,mixed> $productionRow
     * @param array<string,mixed> $liveReadRow
     * @param array<string,mixed> $connectorActivationRow
     * @return array<string,mixed>
     */
    public function supervisedConnectorExecutionArtifact(
        array $company,
        string $flowId,
        string $capabilityId,
        string $connectorId,
        array $capabilityRecord,
        array $productionRow,
        array $liveReadRow,
        array $connectorActivationRow,
        string $runContextId,
    ): array {
        $companyId = (string) ($company['company_id'] ?? 'unknown');
        $connectorContract = $this->hub->findByFlow($company, 'enterprise_domain_data_connector_operating_stack.flow_data_connector_contracts', $flowId);
        $probeContract = $this->hub->findByFlow($company, 'enterprise_flow_live_read_connector_probe_stack.flow_live_read_probe_contracts', $flowId);
        $workbench = $this->hub->findByKey(
            array_values((array) data_get($company, 'enterprise_domain_company_execution_suite_stack.connector_execution_workbenches', [])),
            'connector_id',
            $connectorId,
        );
        $adapterContract = [
            'schema' => 'atlas.ai.company.supervised_connector_adapter_contract.v1',
            'company_id' => $companyId,
            'flow_id' => $flowId,
            'capability_id' => $capabilityId,
            'connector_id' => $connectorId,
            'adapter_mode' => 'read_only_probe_or_fixture_replay_until_operator_signed_scope',
            'credential_material_in_packet_allowed' => false,
            'external_write_allowed' => false,
            'idempotency_key_required' => true,
            'rate_limit_profile_required' => true,
            'operator_approval_required_for_external_effect' => true,
        ];
        $adapterContract['adapter_contract_hash'] = MissionCanonicalHash::sha256($adapterContract);

        $probePacket = [
            'schema' => 'atlas.ai.company.supervised_connector_probe_packet.v1',
            'connector_contract_hash' => (string) ($connectorContract['contract_hash'] ?? $connectorContract['connector_contract_hash'] ?? hash('sha256', 'connector_contract|'.$companyId.'|'.$flowId.'|'.$connectorId)),
            'probe_contract_hash' => (string) ($probeContract['probe_contract_hash'] ?? $probeContract['contract_hash'] ?? hash('sha256', 'probe_contract|'.$companyId.'|'.$flowId.'|'.$connectorId)),
            'workbench_hash' => (string) ($workbench['workbench_hash'] ?? hash('sha256', 'connector_workbench|'.$companyId.'|'.$connectorId)),
            'synthetic_fixture_replay_allowed' => true,
            'live_read_allowed_when_credentials_bound' => true,
            'external_mutation_allowed' => false,
        ];
        $probePacket['probe_packet_hash'] = MissionCanonicalHash::sha256($probePacket);

        $artifact = [
            'schema' => 'atlas.ai.company.supervised_connector_execution_artifact.v1',
            'company_id' => $companyId,
            'flow_id' => $flowId,
            'capability_id' => $capabilityId,
            'connector_id' => $connectorId,
            'run_context_id' => $runContextId,
            'runtime_mode' => 'supervised_connector_execution_dry_run_no_external_effect',
            'source_lineage' => [
                'capability_runtime_record_hash' => (string) ($capabilityRecord['capability_runtime_mesh_status_record_hash'] ?? ''),
                'production_connector_preflight_record_hash' => (string) ($productionRow['production_connector_preflight_record_hash'] ?? $productionRow['company_production_connector_preflight_record_hash'] ?? ''),
                'live_read_connector_readiness_record_hash' => (string) ($liveReadRow['live_read_connector_readiness_record_hash'] ?? $liveReadRow['company_live_read_connector_readiness_record_hash'] ?? ''),
                'connector_activation_record_hash' => (string) ($connectorActivationRow['connector_activation_record_hash'] ?? $connectorActivationRow['company_connector_activation_record_hash'] ?? ''),
                'source_lineage_hash' => MissionCanonicalHash::sha256([
                    'company_id' => $companyId,
                    'flow_id' => $flowId,
                    'capability_id' => $capabilityId,
                    'connector_id' => $connectorId,
                    'capability_record' => $capabilityRecord,
                ]),
            ],
            'adapter_contract' => $adapterContract,
            'supervised_probe' => $probePacket,
            'execution_plan' => [
                'steps' => [
                    'scope_lock',
                    'credential_boundary_check',
                    'adapter_contract_validate',
                    'fixture_replay_or_live_read_probe',
                    'schema_and_freshness_check',
                    'source_lineage_bind',
                    'policy_and_external_effect_check',
                    'receipt_emit',
                    'operator_handoff',
                ],
                'dry_run_default' => true,
                'live_write_requires_new_operator_mandate' => true,
                'failure_policy' => 'fail_closed',
            ],
            'receipt_plan' => [
                'required_receipts' => [
                    'decision_receipt',
                    'connector_execution_receipt',
                    'adapter_contract_receipt',
                    'probe_receipt',
                    'source_lineage_receipt',
                    'operator_handoff_receipt',
                    'audit_trail',
                    'rollback_plan',
                ],
            ],
            'operator_handoff' => [
                'operator_acceptance_required' => true,
                'external_write_requires_signed_scope' => true,
                'customer_message_billing_trade_security_or_deploy_requires_separate_mandate' => true,
                'handoff_hash' => MissionCanonicalHash::sha256([
                    'company_id' => $companyId,
                    'flow_id' => $flowId,
                    'capability_id' => $capabilityId,
                    'connector_id' => $connectorId,
                    'handoff' => 'operator_required_before_external_connector_effect',
                ]),
            ],
            'external_execution_allowed' => false,
            'external_side_effects_enabled' => false,
            'generated_at' => now()->toJSON(),
        ];
        $artifact['supervised_connector_execution_artifact_hash'] = MissionCanonicalHash::sha256($artifact);

        return $artifact;
    }

    /**
     * @param array<string,mixed> $company
     */
    public function supervisedConnectorForCapability(array $company, string $capabilityId): string
    {
        $connectors = array_values(array_filter(array_map(
            static fn (array|string $connector): string => is_array($connector)
                ? (string) ($connector['connector_id'] ?? $connector['id'] ?? $connector['slug'] ?? '')
                : (string) $connector,
            (array) ($company['connectors'] ?? []),
        )));
        if ($connectors === []) {
            return 'internal_fixture_connector';
        }

        $index = abs(crc32($capabilityId)) % count($connectors);

        return (string) $connectors[$index];
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    public function enterpriseCapabilityRuntimeCatalog(): array
    {
        return [
            'source_grounded_research_workbench' => [
                'capability_id' => 'source_grounded_research_workbench',
                'name' => 'Source Grounded Research Workbench',
                'domain_family' => 'research_operations',
                'required_surfaces' => ['data_room', 'retrieval', 'citation_checker', 'artifact_builder', 'operator_review'],
                'required_sources' => ['public_web_research', 'internal_knowledge_base', 'evidence_ledger'],
                'reference_connectors' => ['web_search', 'knowledge_base', 'document_store'],
                'least_privilege_scopes' => ['read_public_sources', 'read_internal_approved_docs'],
                'reference_patterns' => ['direct_citations', 'source_quality_ranking', 'claim_verification'],
                'compliance_controls' => ['citation_required', 'unsupported_claim_blocked', 'source_freshness_check'],
            ],
            'financial_services_analyst_workbench' => [
                'capability_id' => 'financial_services_analyst_workbench',
                'name' => 'Financial Services Analyst Workbench',
                'domain_family' => 'finance_operations',
                'required_surfaces' => ['market_data_room', 'filing_reader', 'model_risk', 'investment_committee_packet', 'operator_review'],
                'required_sources' => ['market_data', 'company_filings', 'portfolio_policy', 'model_risk_register'],
                'reference_connectors' => ['market_data_provider', 'sec_filing_reader', 'portfolio_warehouse'],
                'least_privilege_scopes' => ['read_market_data', 'read_filings', 'read_internal_research'],
                'reference_patterns' => ['source_linked_financial_claims', 'model_risk_controls', 'committee_ready_packet'],
                'compliance_controls' => ['not_investment_advice', 'trade_blocked', 'capital_transfer_blocked', 'model_risk_review'],
            ],
            'customer_revenue_crm_operator' => [
                'capability_id' => 'customer_revenue_crm_operator',
                'name' => 'Customer Revenue CRM Operator',
                'domain_family' => 'revenue_operations',
                'required_surfaces' => ['crm_pipeline', 'account_plan', 'proposal_packet', 'renewal_calendar', 'operator_review'],
                'required_sources' => ['crm_fixtures', 'account_contracts', 'service_catalog'],
                'reference_connectors' => ['crm', 'contract_store', 'support_desk'],
                'least_privilege_scopes' => ['read_crm', 'read_contracts', 'read_account_health'],
                'reference_patterns' => ['opportunity_routing', 'mutual_action_plan', 'renewal_risk_review'],
                'compliance_controls' => ['customer_commitment_blocked', 'pricing_claim_blocked', 'operator_scope_required'],
            ],
            'marketing_growth_experiment_engine' => [
                'capability_id' => 'marketing_growth_experiment_engine',
                'name' => 'Marketing Growth Experiment Engine',
                'domain_family' => 'marketing_operations',
                'required_surfaces' => ['campaign_brief', 'audience_model', 'experiment_tracker', 'analytics_workbench', 'operator_review'],
                'required_sources' => ['growth_research', 'analytics_fixtures', 'brand_policy'],
                'reference_connectors' => ['analytics', 'ad_platform_readonly', 'content_repository'],
                'least_privilege_scopes' => ['read_analytics', 'read_campaign_history', 'read_brand_assets'],
                'reference_patterns' => ['experiment_pipeline', 'audience_segment_hypotheses', 'proof_driven_campaign_brief'],
                'compliance_controls' => ['paid_spend_blocked', 'public_publish_blocked', 'claim_review_required'],
            ],
            'support_service_desk_orchestrator' => [
                'capability_id' => 'support_service_desk_orchestrator',
                'name' => 'Support Service Desk Orchestrator',
                'domain_family' => 'support_operations',
                'required_surfaces' => ['ticket_triage', 'sla_monitor', 'incident_runbook', 'customer_health', 'operator_review'],
                'required_sources' => ['ticket_fixtures', 'sla_catalog', 'account_health'],
                'reference_connectors' => ['support_desk', 'status_page_readonly', 'knowledge_base'],
                'least_privilege_scopes' => ['read_tickets', 'read_sla', 'read_kb'],
                'reference_patterns' => ['sla_triage', 'incident_escalation', 'customer_success_handoff'],
                'compliance_controls' => ['external_customer_message_blocked', 'incident_notification_review', 'pii_boundary'],
            ],
            'cyber_grc_security_control_room' => [
                'capability_id' => 'cyber_grc_security_control_room',
                'name' => 'Cyber GRC Security Control Room',
                'domain_family' => 'security_operations',
                'required_surfaces' => ['asset_register', 'risk_register', 'control_evidence', 'incident_runbook', 'operator_review'],
                'required_sources' => ['security_findings', 'vendor_risk', 'identity_policy'],
                'reference_connectors' => ['siem_readonly', 'vulnerability_scanner_readonly', 'identity_provider_readonly'],
                'least_privilege_scopes' => ['read_security_events', 'read_assets', 'read_identity_policy'],
                'reference_patterns' => ['defensive_triage', 'control_mapping', 'evidence_packet'],
                'compliance_controls' => ['offensive_security_blocked', 'secret_export_blocked', 'external_notification_review'],
            ],
            'legal_procurement_vendor_room' => [
                'capability_id' => 'legal_procurement_vendor_room',
                'name' => 'Legal Procurement Vendor Room',
                'domain_family' => 'governance_operations',
                'required_surfaces' => ['vendor_due_diligence', 'contract_review', 'procurement_route', 'risk_exception', 'operator_review'],
                'required_sources' => ['vendor_register', 'contract_templates', 'procurement_policy'],
                'reference_connectors' => ['vendor_database', 'contract_store', 'procurement_system'],
                'least_privilege_scopes' => ['read_vendor_records', 'read_contracts', 'read_procurement_policy'],
                'reference_patterns' => ['vendor_due_diligence', 'contract_red_flags', 'approval_route'],
                'compliance_controls' => ['purchase_blocked', 'legal_signature_blocked', 'exception_review_required'],
            ],
            'product_delivery_factory' => [
                'capability_id' => 'product_delivery_factory',
                'name' => 'Product Delivery Factory',
                'domain_family' => 'delivery_operations',
                'required_surfaces' => ['delivery_blueprint', 'work_product_builder', 'acceptance_contract', 'replay_check', 'operator_review'],
                'required_sources' => ['solution_playbook', 'delivery_blueprint', 'acceptance_evidence'],
                'reference_connectors' => ['project_tracker', 'document_store', 'code_repository_readonly'],
                'least_privilege_scopes' => ['read_project', 'read_docs', 'read_repo'],
                'reference_patterns' => ['artifact_assembly', 'acceptance_gate', 'handoff_packet'],
                'compliance_controls' => ['external_delivery_blocked', 'source_lineage_required', 'acceptance_required'],
            ],
            'data_connector_warehouse_runtime' => [
                'capability_id' => 'data_connector_warehouse_runtime',
                'name' => 'Data Connector Warehouse Runtime',
                'domain_family' => 'data_operations',
                'required_surfaces' => ['connector_catalog', 'schema_map', 'freshness_monitor', 'lineage_graph', 'operator_review'],
                'required_sources' => ['connector_contracts', 'schema_registry', 'data_quality_checks'],
                'reference_connectors' => ['warehouse_readonly', 'schema_registry', 'data_quality_monitor'],
                'least_privilege_scopes' => ['read_warehouse_metadata', 'read_schema', 'read_quality_checks'],
                'reference_patterns' => ['lineage_binding', 'freshness_slo', 'schema_contract'],
                'compliance_controls' => ['write_blocked', 'pii_export_blocked', 'freshness_required'],
            ],
            'executive_board_intelligence_room' => [
                'capability_id' => 'executive_board_intelligence_room',
                'name' => 'Executive Board Intelligence Room',
                'domain_family' => 'strategy_operations',
                'required_surfaces' => ['board_packet', 'kpi_scorecard', 'portfolio_decision', 'risk_register', 'operator_review'],
                'required_sources' => ['company_scorecards', 'operating_evidence', 'portfolio_policy'],
                'reference_connectors' => ['bi_dashboard_readonly', 'evidence_ledger', 'planning_system'],
                'least_privilege_scopes' => ['read_kpis', 'read_evidence', 'read_plans'],
                'reference_patterns' => ['board_ready_summary', 'decision_packet', 'risk_adjusted_options'],
                'compliance_controls' => ['capital_commitment_blocked', 'public_claim_blocked', 'operator_decision_required'],
            ],
            'engineering_automation_harness' => [
                'capability_id' => 'engineering_automation_harness',
                'name' => 'Engineering Automation Harness',
                'domain_family' => 'software_operations',
                'required_surfaces' => ['repo_context', 'test_runner', 'patch_plan', 'review_packet', 'operator_review'],
                'required_sources' => ['code_intelligence', 'test_results', 'architecture_contracts'],
                'reference_connectors' => ['git_repository', 'ci_readonly', 'issue_tracker'],
                'least_privilege_scopes' => ['read_repo', 'read_ci', 'read_issues'],
                'reference_patterns' => ['plan_review_patch_test_repair', 'architecture_boundary_check', 'evidence_receipt'],
                'compliance_controls' => ['deploy_blocked', 'secret_export_blocked', 'policy_boundary_required'],
            ],
            'people_workforce_capacity_room' => [
                'capability_id' => 'people_workforce_capacity_room',
                'name' => 'People Workforce Capacity Room',
                'domain_family' => 'workforce_operations',
                'required_surfaces' => ['capacity_model', 'staffing_matrix', 'training_plan', 'succession_plan', 'operator_review'],
                'required_sources' => ['agent_capacity_plan', 'flow_staffing_matrix', 'training_enablement'],
                'reference_connectors' => ['hris_readonly', 'learning_system', 'workforce_planner'],
                'least_privilege_scopes' => ['read_roles', 'read_training', 'read_capacity'],
                'reference_patterns' => ['backup_assignment', 'certification_gate', 'capacity_forecast'],
                'compliance_controls' => ['hiring_commitment_blocked', 'hr_action_review_required', 'single_agent_bottleneck_blocked'],
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function enterpriseCapabilityRuntimePolicy(): array
    {
        return [
            'enterprise_capability_runtime_is_not_external_execution_authority' => true,
            'calendar_wait_blocker_enabled' => false,
            'external_execution_allowed' => false,
            'external_side_effects_enabled' => false,
            'operator_signed_scope_required_for_external_effect' => true,
            'persisted_capabilities' => array_keys($this->enterpriseCapabilityRuntimeCatalog()),
            'reference_pattern' => 'source_grounded_data_connectors_agentic_workflows_compliance_eval_replay_operator_handoff',
            'required_runtime_evidence' => [
                'atlas_tool_run',
                'capability_runtime_artifact',
                'source_lineage',
                'adapter_contract',
                'agent_workflow',
                'compliance_controls',
                'eval_replay',
                'decision_receipt',
                'capability_runtime_receipt',
                'source_lineage_receipt',
                'adapter_contract_receipt',
                'eval_replay_receipt',
                'operator_handoff_receipt',
                'audit_trail',
                'rollback_plan',
            ],
            'blocked_operations' => [
                'skip_capability_runtime_mesh',
                'skip_source_lineage',
                'skip_adapter_contract',
                'skip_eval_replay',
                'skip_operator_handoff',
                'claim_without_source_reference',
                'external_customer_message',
                'external_write',
                'public_publish',
                'spend',
                'invoice',
                'collect_payment',
                'trade',
                'capital_transfer',
                'legal_signature',
                'deploy',
                'delete',
                'offensive_security',
                'secret_export',
                'private_data_export',
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function supervisedConnectorExecutionPolicy(): array
    {
        return [
            'supervised_connector_execution_is_not_external_execution_authority' => true,
            'calendar_wait_blocker_enabled' => false,
            'external_execution_allowed' => false,
            'external_side_effects_enabled' => false,
            'operator_signed_scope_required_for_external_effect' => true,
            'runtime_mode' => 'read_only_probe_fixture_replay_or_dry_run_until_operator_mandate',
            'required_runtime_evidence' => [
                'atlas_tool_run',
                'supervised_connector_execution_artifact',
                'adapter_contract',
                'probe_packet',
                'source_lineage',
                'decision_receipt',
                'connector_execution_receipt',
                'adapter_contract_receipt',
                'probe_receipt',
                'source_lineage_receipt',
                'operator_handoff_receipt',
                'audit_trail',
                'rollback_plan',
            ],
            'blocked_operations' => [
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
                'secret_export',
                'credential_material_export',
                'skip_operator_handoff',
                'skip_receipt_binding',
            ],
        ];
    }
}
