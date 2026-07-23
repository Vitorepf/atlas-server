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
class EnterpriseWorkProductRuntimeSection
{
    public function __construct(private readonly MandateRegistryHub $hub) {}

    /**
     * @return array<string,mixed>
     */
    public function enterpriseCompanyWorkProductRuntimeRegister(?string $companyId = null): array
    {
        $wantedCompany = $companyId !== null && trim($companyId) !== '' ? trim($companyId) : null;
        $acceptance = $this->hub->enterpriseCompletion->enterpriseCompanyWorkProductAcceptanceEvidenceStatus($wantedCompany);
        $acceptanceByCompany = $this->hub->companyRowsById($acceptance);

        if (! $this->hub->approval->toolRunsTableAvailable()) {
            return [
                'ok' => false,
                'schema' => ExternalActionMandateRegistryService::ENTERPRISE_COMPANY_WORK_PRODUCT_RUNTIME_REGISTER_SCHEMA,
                'status' => 'atlas_tool_runs_table_unavailable',
                'generated_at' => now()->toJSON(),
                'summary' => [
                    'company_count' => 0,
                    'registered_work_product_run_count' => 0,
                    'expected_flow_count' => (int) data_get($acceptance, 'summary.expected_flow_count', 0),
                    'external_delivery_allowed_count' => 0,
                    'external_execution_allowed_count' => 0,
                    'external_side_effects_enabled_count' => 0,
                ],
                'companies' => [],
                'policy' => $this->workProductRuntimePolicy(),
            ];
        }

        $companies = [];
        foreach ($this->hub->buildoutCompanies($wantedCompany) as $company) {
            $id = (string) ($company['company_id'] ?? 'unknown');
            $acceptanceRow = (array) ($acceptanceByCompany[$id] ?? []);
            $flows = array_values(array_filter(array_map(
                static fn (array|string $flow): string => is_array($flow)
                    ? (string) ($flow['flow_id'] ?? $flow['id'] ?? '')
                    : (string) $flow,
                (array) ($company['flows'] ?? []),
            )));
            $registeredRuns = [];

            foreach ($flows as $flowId) {
                $runContextId = $id.'.'.$flowId.'.work_product.runtime.delivery_acceptance.v1';
                $workProduct = $this->flowWorkProductRuntimeArtifact($company, $flowId, $acceptanceRow, $runContextId);
                $summary = [
                    'company_id' => $id,
                    'flow_id' => $flowId,
                    'work_product_id' => (string) ($workProduct['work_product_id'] ?? ''),
                    'work_product_kind' => (string) ($workProduct['work_product_kind'] ?? ''),
                    'execution_mode' => 'internal_work_product_delivery_acceptance_no_external_handoff',
                    'external_delivery_allowed' => false,
                    'external_execution_allowed' => false,
                    'external_side_effects_enabled' => false,
                    'acceptance_record_hash' => (string) ($acceptanceRow['company_work_product_acceptance_evidence_record_hash'] ?? ''),
                ];
                $normalized = [
                    'schema' => 'atlas.ai.company.persisted_work_product_runtime_result.v1',
                    'status' => 'passed',
                    'summary' => $summary,
                    'work_product' => $workProduct,
                    'quality_gates' => [
                        'source_lineage_bound' => strlen((string) data_get($workProduct, 'source_lineage.source_lineage_hash', '')) === 64,
                        'acceptance_contract_bound' => strlen((string) data_get($workProduct, 'acceptance.acceptance_contract_hash', '')) === 64,
                        'handoff_packet_bound' => strlen((string) data_get($workProduct, 'handoff.handoff_packet_hash', '')) === 64,
                        'replay_artifact_check_bound' => strlen((string) data_get($workProduct, 'replay.replay_artifact_check_hash', '')) === 64,
                        'external_effects_blocked' => ! (bool) ($workProduct['external_delivery_allowed'] ?? true)
                            && ! (bool) ($workProduct['external_execution_allowed'] ?? true)
                            && ! (bool) ($workProduct['external_side_effects_enabled'] ?? true),
                    ],
                    'blocking_failures' => [],
                ];
                $receiptInput = [
                    'company_id' => $id,
                    'flow_id' => $flowId,
                    'run_context_id' => $runContextId,
                    'work_product_hash' => (string) ($workProduct['work_product_hash'] ?? ''),
                    'acceptance_record_hash' => (string) ($acceptanceRow['company_work_product_acceptance_evidence_record_hash'] ?? ''),
                ];
                $metadata = [
                    'source' => 'enterprise_company_work_product_runtime_register',
                    'receipt_schema_version' => 'atlas.company_work_product_runtime_receipt.v1',
                    'evidence_receipt_hash' => MissionCanonicalHash::sha256($normalized),
                    'decision_receipt_hash' => MissionCanonicalHash::sha256($receiptInput + ['receipt_type' => 'decision_receipt']),
                    'work_product_receipt_hash' => MissionCanonicalHash::sha256($receiptInput + ['receipt_type' => 'work_product_receipt']),
                    'acceptance_receipt_hash' => MissionCanonicalHash::sha256($receiptInput + ['receipt_type' => 'acceptance_receipt']),
                    'handoff_receipt_hash' => MissionCanonicalHash::sha256($receiptInput + ['receipt_type' => 'handoff_receipt']),
                    'replay_receipt_hash' => MissionCanonicalHash::sha256($receiptInput + ['receipt_type' => 'replay_receipt']),
                    'audit_trail_hash' => MissionCanonicalHash::sha256($receiptInput + ['receipt_type' => 'audit_trail']),
                    'rollback_plan_hash' => MissionCanonicalHash::sha256($receiptInput + ['receipt_type' => 'rollback_plan']),
                    'external_delivery_allowed' => false,
                    'external_execution_allowed' => false,
                    'external_side_effects_enabled' => false,
                    'operator_acceptance_required_before_external_handoff' => true,
                    'action_runtime_contract' => [
                        'schema_version' => 'atlas.company_work_product_runtime.contract.v1',
                        'mode' => 'internal_work_product_delivery_acceptance_no_external_handoff',
                        'run_context_type' => 'holding_company_work_product_runtime',
                        'run_context_id' => $runContextId,
                        'provider_dispatch_allowed' => false,
                        'customer_delivery_allowed' => false,
                        'public_claim_allowed' => false,
                        'operator_acceptance_required_for_external_handoff' => true,
                    ],
                ];

                $run = AtlasToolRun::query()->updateOrCreate(
                    [
                        'surface' => 'holding_company_work_product_runtime',
                        'run_context_type' => 'holding_company_work_product_runtime',
                        'run_context_id' => $runContextId,
                    ],
                    [
                        'tool_slug' => 'atlas_company_work_product_delivery_acceptance_runtime',
                        'workspace_hash' => hash('sha256', base_path()),
                        'workspace' => base_path(),
                        'status' => 'passed',
                        'required' => true,
                        'failure_policy' => 'fail_closed',
                        'policy_decision' => 'internal_delivery_acceptance_allowed',
                        'command_hash' => MissionCanonicalHash::sha256([
                            'run_context_id' => $runContextId,
                            'work_product_hash' => $workProduct['work_product_hash'] ?? null,
                        ]),
                        'exit_code' => 0,
                        'started_at' => now(),
                        'finished_at' => now(),
                        'duration_ms' => 0,
                        'summary_json' => $summary,
                        'normalized_result_json' => $normalized,
                        'policy_decision_json' => [
                            'external_delivery_allowed' => false,
                            'external_execution_allowed' => false,
                            'external_side_effects_enabled' => false,
                            'blocked_operations' => $this->workProductRuntimePolicy()['blocked_operations'],
                        ],
                        'metadata_json' => $metadata,
                    ],
                );

                $registeredRuns[] = [
                    'run_id' => (string) $run->id,
                    'run_context_id' => $runContextId,
                    'flow_id' => $flowId,
                    'work_product_id' => (string) ($workProduct['work_product_id'] ?? ''),
                    'status' => (string) $run->status,
                    'policy_decision' => (string) $run->policy_decision,
                    'work_product_receipt_hash' => (string) data_get($run->metadata_json, 'work_product_receipt_hash', ''),
                    'external_delivery_allowed' => false,
                    'external_execution_allowed' => false,
                    'external_side_effects_enabled' => false,
                ];
            }

            $row = [
                'schema' => 'atlas.ai.company.enterprise_company_work_product_runtime_register_record.v1',
                'company_id' => $id,
                'expected_flow_count' => count($flows),
                'registered_work_product_run_count' => count($registeredRuns),
                'registered_runs' => $registeredRuns,
                'source_hashes' => [
                    'company_work_product_acceptance_evidence_record_hash' => $acceptanceRow['company_work_product_acceptance_evidence_record_hash'] ?? null,
                ],
                'external_delivery_allowed' => false,
                'external_execution_allowed' => false,
                'external_side_effects_enabled' => false,
            ];
            $row['company_work_product_runtime_register_record_hash'] = MissionCanonicalHash::sha256($row);
            $companies[] = $row;
        }

        $registeredRunCount = array_sum(array_map(static fn (array $company): int => (int) ($company['registered_work_product_run_count'] ?? 0), $companies));
        $expectedFlowCount = array_sum(array_map(static fn (array $company): int => (int) ($company['expected_flow_count'] ?? 0), $companies));
        $payload = [
            'ok' => $expectedFlowCount > 0 && $registeredRunCount >= $expectedFlowCount,
            'schema' => ExternalActionMandateRegistryService::ENTERPRISE_COMPANY_WORK_PRODUCT_RUNTIME_REGISTER_SCHEMA,
            'status' => $expectedFlowCount > 0 && $registeredRunCount >= $expectedFlowCount
                ? 'enterprise_company_work_product_runtime_registered_external_delivery_blocked'
                : 'enterprise_company_work_product_runtime_registration_attention_required',
            'generated_at' => now()->toJSON(),
            'summary' => [
                'company_count' => count($companies),
                'registered_work_product_run_count' => $registeredRunCount,
                'expected_flow_count' => $expectedFlowCount,
                'external_delivery_allowed_count' => 0,
                'external_execution_allowed_count' => 0,
                'external_side_effects_enabled_count' => 0,
            ],
            'source_hashes' => [
                'enterprise_company_work_product_acceptance_evidence_status_hash' => $acceptance['enterprise_company_work_product_acceptance_evidence_status_hash'] ?? null,
            ],
            'companies' => $companies,
            'policy' => $this->workProductRuntimePolicy(),
        ];
        $payload['enterprise_company_work_product_runtime_register_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function enterpriseCompanyWorkProductRuntimeStatus(?string $companyId = null): array
    {
        $wantedCompany = $companyId !== null && trim($companyId) !== '' ? trim($companyId) : null;
        $acceptance = $this->hub->enterpriseCompletion->enterpriseCompanyWorkProductAcceptanceEvidenceStatus($wantedCompany);
        $acceptanceByCompany = $this->hub->companyRowsById($acceptance);

        if (! $this->hub->approval->toolRunsTableAvailable()) {
            return [
                'ok' => false,
                'schema' => ExternalActionMandateRegistryService::ENTERPRISE_COMPANY_WORK_PRODUCT_RUNTIME_STATUS_SCHEMA,
                'status' => 'atlas_tool_runs_table_unavailable',
                'generated_at' => now()->toJSON(),
                'summary' => [
                    'company_count' => 0,
                    'work_product_runtime_ready_company_count' => 0,
                    'expected_flow_count' => (int) data_get($acceptance, 'summary.expected_flow_count', 0),
                    'persisted_work_product_run_count' => 0,
                    'ready_persisted_work_product_run_count' => 0,
                    'external_delivery_allowed_count' => 0,
                    'external_execution_allowed_count' => 0,
                    'external_side_effects_enabled_count' => 0,
                ],
                'companies' => [],
                'policy' => $this->workProductRuntimePolicy(),
            ];
        }

        $companies = [];
        foreach ($this->hub->buildoutCompanies($wantedCompany) as $company) {
            $id = (string) ($company['company_id'] ?? 'unknown');
            $acceptanceRow = (array) ($acceptanceByCompany[$id] ?? []);
            $flows = array_values(array_filter(array_map(
                static fn (array|string $flow): string => is_array($flow)
                    ? (string) ($flow['flow_id'] ?? $flow['id'] ?? '')
                    : (string) $flow,
                (array) ($company['flows'] ?? []),
            )));

            $runtimeRecords = [];
            foreach ($flows as $flowId) {
                $runContextId = $id.'.'.$flowId.'.work_product.runtime.delivery_acceptance.v1';
                $run = AtlasToolRun::query()
                    ->where('surface', 'holding_company_work_product_runtime')
                    ->where('run_context_type', 'holding_company_work_product_runtime')
                    ->where('run_context_id', $runContextId)
                    ->latest('updated_at')
                    ->first();

                $recordGates = [
                    'persisted_work_product_run_exists' => $run instanceof AtlasToolRun,
                    'status_passed' => $run instanceof AtlasToolRun && $run->status === 'passed',
                    'policy_internal_delivery_acceptance_allowed' => $run instanceof AtlasToolRun && $run->policy_decision === 'internal_delivery_acceptance_allowed',
                    'normalized_result_bound' => $run instanceof AtlasToolRun && data_get($run->normalized_result_json, 'schema') === 'atlas.ai.company.persisted_work_product_runtime_result.v1',
                    'work_product_artifact_bound' => $run instanceof AtlasToolRun
                        && data_get($run->normalized_result_json, 'work_product.schema') === 'atlas.ai.company.flow_work_product_runtime_artifact.v1'
                        && strlen((string) data_get($run->normalized_result_json, 'work_product.work_product_hash', '')) === 64,
                    'receipt_metadata_bound' => $run instanceof AtlasToolRun
                        && strlen((string) data_get($run->metadata_json, 'decision_receipt_hash', '')) === 64
                        && strlen((string) data_get($run->metadata_json, 'work_product_receipt_hash', '')) === 64
                        && strlen((string) data_get($run->metadata_json, 'acceptance_receipt_hash', '')) === 64
                        && strlen((string) data_get($run->metadata_json, 'handoff_receipt_hash', '')) === 64
                        && strlen((string) data_get($run->metadata_json, 'replay_receipt_hash', '')) === 64
                        && strlen((string) data_get($run->metadata_json, 'audit_trail_hash', '')) === 64
                        && strlen((string) data_get($run->metadata_json, 'rollback_plan_hash', '')) === 64,
                    'quality_gates_bound' => $run instanceof AtlasToolRun
                        && count(array_filter((array) data_get($run->normalized_result_json, 'quality_gates', []))) >= 5,
                    'external_effects_blocked' => $run instanceof AtlasToolRun
                        && ! (bool) data_get($run->metadata_json, 'external_delivery_allowed', true)
                        && ! (bool) data_get($run->metadata_json, 'external_execution_allowed', true)
                        && ! (bool) data_get($run->metadata_json, 'external_side_effects_enabled', true),
                ];
                $readyRecordGateCount = count(array_filter($recordGates));
                $runtimeRecord = [
                    'schema' => 'atlas.ai.company.persisted_work_product_runtime_record.v1',
                    'company_id' => $id,
                    'flow_id' => $flowId,
                    'run_context_id' => $runContextId,
                    'tool_run_id' => $run instanceof AtlasToolRun ? (string) $run->id : null,
                    'work_product_id' => $run instanceof AtlasToolRun ? (string) data_get($run->normalized_result_json, 'work_product.work_product_id', '') : null,
                    'ready' => $readyRecordGateCount === count($recordGates),
                    'ready_gate_count' => $readyRecordGateCount,
                    'required_gate_count' => count($recordGates),
                    'gates' => $recordGates,
                    'missing_gates' => array_values(array_keys(array_filter($recordGates, static fn (bool $ready): bool => ! $ready))),
                    'receipt_chain' => $run instanceof AtlasToolRun ? [
                        'decision_receipt_hash' => (string) data_get($run->metadata_json, 'decision_receipt_hash', ''),
                        'work_product_receipt_hash' => (string) data_get($run->metadata_json, 'work_product_receipt_hash', ''),
                        'acceptance_receipt_hash' => (string) data_get($run->metadata_json, 'acceptance_receipt_hash', ''),
                        'handoff_receipt_hash' => (string) data_get($run->metadata_json, 'handoff_receipt_hash', ''),
                        'replay_receipt_hash' => (string) data_get($run->metadata_json, 'replay_receipt_hash', ''),
                        'audit_trail_hash' => (string) data_get($run->metadata_json, 'audit_trail_hash', ''),
                        'rollback_plan_hash' => (string) data_get($run->metadata_json, 'rollback_plan_hash', ''),
                    ] : [],
                    'external_delivery_allowed' => false,
                    'external_execution_allowed' => false,
                    'external_side_effects_enabled' => false,
                ];
                $runtimeRecord['persisted_work_product_runtime_record_hash'] = MissionCanonicalHash::sha256($runtimeRecord);
                $runtimeRecords[] = $runtimeRecord;
            }

            $readyRuntimeRecordCount = count(array_filter($runtimeRecords, static fn (array $record): bool => (bool) ($record['ready'] ?? false)));
            $expectedFlowCount = count($flows);
            $gates = [
                'work_product_acceptance_evidence_ready' => (bool) ($acceptanceRow['work_product_acceptance_evidence_ready'] ?? false),
                'persisted_work_product_runs_cover_flows' => $expectedFlowCount > 0
                    && count($runtimeRecords) >= $expectedFlowCount
                    && $readyRuntimeRecordCount === count($runtimeRecords),
                'external_effects_blocked' => count(array_filter($runtimeRecords, static fn (array $record): bool => (bool) ($record['external_delivery_allowed'] ?? true)
                    || (bool) ($record['external_execution_allowed'] ?? true)
                    || (bool) ($record['external_side_effects_enabled'] ?? true))) === 0,
            ];
            $readyGateCount = count(array_filter($gates));
            $row = [
                'schema' => 'atlas.ai.company.enterprise_company_work_product_runtime_status_record.v1',
                'company_id' => $id,
                'expected_flow_count' => $expectedFlowCount,
                'persisted_work_product_run_count' => count($runtimeRecords),
                'ready_persisted_work_product_run_count' => $readyRuntimeRecordCount,
                'work_product_runtime_ready' => $expectedFlowCount > 0 && $readyGateCount === count($gates),
                'runtime_grade' => $expectedFlowCount > 0 && $readyGateCount === count($gates)
                    ? 'target_9_work_product_runtime_ready_external_delivery_blocked'
                    : 'work_product_runtime_attention_required',
                'ready_gate_count' => $readyGateCount,
                'required_gate_count' => count($gates),
                'gates' => $gates,
                'missing_gates' => array_values(array_keys(array_filter($gates, static fn (bool $ready): bool => ! $ready))),
                'runtime_records' => $runtimeRecords,
                'source_hashes' => [
                    'company_work_product_acceptance_evidence_record_hash' => $acceptanceRow['company_work_product_acceptance_evidence_record_hash'] ?? null,
                ],
                'external_delivery_allowed' => false,
                'external_execution_allowed' => false,
                'external_side_effects_enabled' => false,
            ];
            $row['company_work_product_runtime_status_record_hash'] = MissionCanonicalHash::sha256($row);
            $companies[] = $row;
        }

        $readyCompanyCount = count(array_filter($companies, static fn (array $company): bool => (bool) ($company['work_product_runtime_ready'] ?? false)));
        $payload = [
            'ok' => $companies !== [] && $readyCompanyCount === count($companies),
            'schema' => ExternalActionMandateRegistryService::ENTERPRISE_COMPANY_WORK_PRODUCT_RUNTIME_STATUS_SCHEMA,
            'status' => $companies !== [] && $readyCompanyCount === count($companies)
                ? 'enterprise_company_work_product_runtime_ready_external_delivery_blocked'
                : 'enterprise_company_work_product_runtime_attention_required',
            'generated_at' => now()->toJSON(),
            'summary' => [
                'company_count' => count($companies),
                'work_product_runtime_ready_company_count' => $readyCompanyCount,
                'attention_company_count' => count($companies) - $readyCompanyCount,
                'expected_flow_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['expected_flow_count'] ?? 0), $companies)),
                'persisted_work_product_run_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['persisted_work_product_run_count'] ?? 0), $companies)),
                'ready_persisted_work_product_run_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['ready_persisted_work_product_run_count'] ?? 0), $companies)),
                'required_gate_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['required_gate_count'] ?? 0), $companies)),
                'ready_gate_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['ready_gate_count'] ?? 0), $companies)),
                'external_delivery_allowed_count' => 0,
                'external_execution_allowed_count' => 0,
                'external_side_effects_enabled_count' => 0,
            ],
            'source_hashes' => [
                'enterprise_company_work_product_acceptance_evidence_status_hash' => $acceptance['enterprise_company_work_product_acceptance_evidence_status_hash'] ?? null,
            ],
            'companies' => $companies,
            'policy' => $this->workProductRuntimePolicy(),
        ];
        $payload['enterprise_company_work_product_runtime_status_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function enterpriseCompanyOperatingBlueprintRuntimeRegister(?string $companyId = null): array
    {
        $wantedCompany = $companyId !== null && trim($companyId) !== '' ? trim($companyId) : null;

        if (! $this->hub->approval->toolRunsTableAvailable()) {
            return [
                'ok' => false,
                'schema' => ExternalActionMandateRegistryService::ENTERPRISE_COMPANY_OPERATING_BLUEPRINT_RUNTIME_REGISTER_SCHEMA,
                'status' => 'atlas_tool_runs_table_unavailable',
                'generated_at' => now()->toJSON(),
                'summary' => [
                    'company_count' => 0,
                    'registered_blueprint_run_count' => 0,
                    'expected_flow_count' => 0,
                    'external_execution_allowed_count' => 0,
                    'external_side_effects_enabled_count' => 0,
                ],
                'companies' => [],
                'policy' => $this->operatingBlueprintRuntimePolicy(),
            ];
        }

        $companies = [];
        foreach ($this->hub->buildoutCompanies($wantedCompany) as $company) {
            $id = (string) ($company['company_id'] ?? 'unknown');
            $blueprintStack = (array) data_get($company, 'enterprise_company_operating_blueprint_stack', []);
            $flowBlueprints = array_values((array) data_get($blueprintStack, 'flow_operating_blueprints', []));
            $registeredRuns = [];

            foreach ($flowBlueprints as $flowBlueprint) {
                $flowBlueprint = (array) $flowBlueprint;
                $flowId = (string) ($flowBlueprint['flow_id'] ?? 'unknown');
                $runContextId = $id.'.'.$flowId.'.operating_blueprint.runtime.internal_execution.v1';
                $artifact = $this->flowOperatingBlueprintRuntimeArtifact($company, $flowBlueprint, $runContextId);
                $summary = [
                    'company_id' => $id,
                    'flow_id' => $flowId,
                    'primary_archetype' => (string) ($artifact['primary_archetype'] ?? ''),
                    'required_skill_count' => count((array) ($artifact['required_skills'] ?? [])),
                    'required_connector_count' => count((array) ($artifact['required_connectors'] ?? [])),
                    'subagent_lane_count' => count((array) ($artifact['subagent_lanes'] ?? [])),
                    'execution_mode' => 'internal_enterprise_operating_blueprint_runtime_no_external_effect',
                    'external_execution_allowed' => false,
                    'external_side_effects_enabled' => false,
                    'operating_blueprint_hash' => (string) data_get($blueprintStack, 'operating_blueprint_hash', ''),
                ];
                $normalized = [
                    'schema' => 'atlas.ai.company.persisted_operating_blueprint_runtime_result.v1',
                    'status' => 'passed',
                    'summary' => $summary,
                    'operating_blueprint_artifact' => $artifact,
                    'quality_gates' => [
                        'source_lineage_bound' => strlen((string) data_get($artifact, 'source_lineage.source_lineage_hash', '')) === 64,
                        'connector_permissions_bound' => count((array) data_get($artifact, 'connector_permission_profiles', [])) >= count((array) data_get($artifact, 'required_connectors', [])),
                        'subagent_lanes_bound' => count((array) data_get($artifact, 'subagent_lanes', [])) >= 5,
                        'artifact_assembly_bound' => count((array) data_get($artifact, 'artifact_assembly_contract.sections_required', [])) >= 6,
                        'eval_replay_bound' => (bool) data_get($artifact, 'runtime_handoff_contract.eval_replay_required', false),
                        'operator_handoff_bound' => (bool) data_get($artifact, 'control_room_handoff.operator_acceptance_required', false),
                        'external_effects_blocked' => ! (bool) ($artifact['external_execution_allowed'] ?? true)
                            && ! (bool) ($artifact['external_side_effects_enabled'] ?? true),
                    ],
                    'blocking_failures' => [],
                ];
                $receiptInput = [
                    'company_id' => $id,
                    'flow_id' => $flowId,
                    'run_context_id' => $runContextId,
                    'artifact_hash' => (string) ($artifact['operating_blueprint_artifact_hash'] ?? ''),
                    'operating_blueprint_hash' => (string) data_get($blueprintStack, 'operating_blueprint_hash', ''),
                ];
                $metadata = [
                    'source' => 'enterprise_company_operating_blueprint_runtime_register',
                    'receipt_schema_version' => 'atlas.company_operating_blueprint_runtime_receipt.v1',
                    'evidence_receipt_hash' => MissionCanonicalHash::sha256($normalized),
                    'decision_receipt_hash' => MissionCanonicalHash::sha256($receiptInput + ['receipt_type' => 'decision_receipt']),
                    'blueprint_receipt_hash' => MissionCanonicalHash::sha256($receiptInput + ['receipt_type' => 'blueprint_receipt']),
                    'source_lineage_receipt_hash' => MissionCanonicalHash::sha256($receiptInput + ['receipt_type' => 'source_lineage_receipt']),
                    'permission_receipt_hash' => MissionCanonicalHash::sha256($receiptInput + ['receipt_type' => 'permission_receipt']),
                    'eval_replay_receipt_hash' => MissionCanonicalHash::sha256($receiptInput + ['receipt_type' => 'eval_replay_receipt']),
                    'handoff_receipt_hash' => MissionCanonicalHash::sha256($receiptInput + ['receipt_type' => 'handoff_receipt']),
                    'audit_trail_hash' => MissionCanonicalHash::sha256($receiptInput + ['receipt_type' => 'audit_trail']),
                    'rollback_plan_hash' => MissionCanonicalHash::sha256($receiptInput + ['receipt_type' => 'rollback_plan']),
                    'external_execution_allowed' => false,
                    'external_side_effects_enabled' => false,
                    'operator_signed_scope_required_for_external_effect' => true,
                    'action_runtime_contract' => [
                        'schema_version' => 'atlas.company_operating_blueprint_runtime.contract.v1',
                        'mode' => 'internal_enterprise_operating_blueprint_runtime_no_external_effect',
                        'run_context_type' => 'holding_company_blueprint_runtime',
                        'run_context_id' => $runContextId,
                        'provider_dispatch_allowed' => false,
                        'external_write_allowed' => false,
                        'customer_delivery_allowed' => false,
                        'public_claim_allowed' => false,
                        'operator_approval_required_for_external_effect' => true,
                    ],
                ];

                $run = AtlasToolRun::query()->updateOrCreate(
                    [
                        'surface' => 'holding_company_blueprint_runtime',
                        'run_context_type' => 'holding_company_blueprint_runtime',
                        'run_context_id' => $runContextId,
                    ],
                    [
                        'tool_slug' => 'atlas_company_blueprint_runtime',
                        'workspace_hash' => hash('sha256', base_path()),
                        'workspace' => base_path(),
                        'status' => 'passed',
                        'required' => true,
                        'failure_policy' => 'fail_closed',
                        'policy_decision' => 'internal_blueprint_runtime_allowed',
                        'command_hash' => MissionCanonicalHash::sha256([
                            'run_context_id' => $runContextId,
                            'artifact_hash' => $artifact['operating_blueprint_artifact_hash'] ?? null,
                        ]),
                        'exit_code' => 0,
                        'started_at' => now(),
                        'finished_at' => now(),
                        'duration_ms' => 0,
                        'summary_json' => $summary,
                        'normalized_result_json' => $normalized,
                        'policy_decision_json' => [
                            'external_execution_allowed' => false,
                            'external_side_effects_enabled' => false,
                            'blocked_operations' => $this->operatingBlueprintRuntimePolicy()['blocked_operations'],
                        ],
                        'metadata_json' => $metadata,
                    ],
                );

                $registeredRuns[] = [
                    'run_id' => (string) $run->id,
                    'run_context_id' => $runContextId,
                    'flow_id' => $flowId,
                    'primary_archetype' => (string) ($artifact['primary_archetype'] ?? ''),
                    'status' => (string) $run->status,
                    'policy_decision' => (string) $run->policy_decision,
                    'blueprint_receipt_hash' => (string) data_get($run->metadata_json, 'blueprint_receipt_hash', ''),
                    'external_execution_allowed' => false,
                    'external_side_effects_enabled' => false,
                ];
            }

            $row = [
                'schema' => 'atlas.ai.company.enterprise_company_operating_blueprint_runtime_register_record.v1',
                'company_id' => $id,
                'expected_flow_count' => count($flowBlueprints),
                'registered_blueprint_run_count' => count($registeredRuns),
                'registered_runs' => $registeredRuns,
                'source_hashes' => [
                    'company_receipt_hash' => $company['receipt_hash'] ?? null,
                    'operating_blueprint_hash' => data_get($blueprintStack, 'operating_blueprint_hash'),
                ],
                'external_execution_allowed' => false,
                'external_side_effects_enabled' => false,
            ];
            $row['company_operating_blueprint_runtime_register_record_hash'] = MissionCanonicalHash::sha256($row);
            $companies[] = $row;
        }

        $registeredRunCount = array_sum(array_map(static fn (array $company): int => (int) ($company['registered_blueprint_run_count'] ?? 0), $companies));
        $expectedFlowCount = array_sum(array_map(static fn (array $company): int => (int) ($company['expected_flow_count'] ?? 0), $companies));
        $payload = [
            'ok' => $expectedFlowCount > 0 && $registeredRunCount >= $expectedFlowCount,
            'schema' => ExternalActionMandateRegistryService::ENTERPRISE_COMPANY_OPERATING_BLUEPRINT_RUNTIME_REGISTER_SCHEMA,
            'status' => $expectedFlowCount > 0 && $registeredRunCount >= $expectedFlowCount
                ? 'enterprise_company_operating_blueprint_runtime_registered_external_effects_blocked'
                : 'enterprise_company_operating_blueprint_runtime_registration_attention_required',
            'generated_at' => now()->toJSON(),
            'summary' => [
                'company_count' => count($companies),
                'registered_blueprint_run_count' => $registeredRunCount,
                'expected_flow_count' => $expectedFlowCount,
                'external_execution_allowed_count' => 0,
                'external_side_effects_enabled_count' => 0,
            ],
            'companies' => $companies,
            'policy' => $this->operatingBlueprintRuntimePolicy(),
        ];
        $payload['enterprise_company_operating_blueprint_runtime_register_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function enterpriseCompanyOperatingBlueprintRuntimeStatus(?string $companyId = null): array
    {
        $wantedCompany = $companyId !== null && trim($companyId) !== '' ? trim($companyId) : null;

        if (! $this->hub->approval->toolRunsTableAvailable()) {
            return [
                'ok' => false,
                'schema' => ExternalActionMandateRegistryService::ENTERPRISE_COMPANY_OPERATING_BLUEPRINT_RUNTIME_STATUS_SCHEMA,
                'status' => 'atlas_tool_runs_table_unavailable',
                'generated_at' => now()->toJSON(),
                'summary' => [
                    'company_count' => 0,
                    'operating_blueprint_runtime_ready_company_count' => 0,
                    'expected_flow_count' => 0,
                    'persisted_blueprint_run_count' => 0,
                    'ready_persisted_blueprint_run_count' => 0,
                    'external_execution_allowed_count' => 0,
                    'external_side_effects_enabled_count' => 0,
                ],
                'companies' => [],
                'policy' => $this->operatingBlueprintRuntimePolicy(),
            ];
        }

        $companies = [];
        foreach ($this->hub->buildoutCompanies($wantedCompany) as $company) {
            $id = (string) ($company['company_id'] ?? 'unknown');
            $blueprintStack = (array) data_get($company, 'enterprise_company_operating_blueprint_stack', []);
            $flowBlueprints = array_values((array) data_get($blueprintStack, 'flow_operating_blueprints', []));
            $runtimeRecords = [];

            foreach ($flowBlueprints as $flowBlueprint) {
                $flowBlueprint = (array) $flowBlueprint;
                $flowId = (string) ($flowBlueprint['flow_id'] ?? 'unknown');
                $runContextId = $id.'.'.$flowId.'.operating_blueprint.runtime.internal_execution.v1';
                $run = AtlasToolRun::query()
                    ->where('surface', 'holding_company_blueprint_runtime')
                    ->where('run_context_type', 'holding_company_blueprint_runtime')
                    ->where('run_context_id', $runContextId)
                    ->latest('updated_at')
                    ->first();

                $recordGates = [
                    'persisted_blueprint_run_exists' => $run instanceof AtlasToolRun,
                    'status_passed' => $run instanceof AtlasToolRun && $run->status === 'passed',
                    'policy_internal_runtime_allowed' => $run instanceof AtlasToolRun && $run->policy_decision === 'internal_blueprint_runtime_allowed',
                    'normalized_result_bound' => $run instanceof AtlasToolRun && data_get($run->normalized_result_json, 'schema') === 'atlas.ai.company.persisted_operating_blueprint_runtime_result.v1',
                    'operating_blueprint_artifact_bound' => $run instanceof AtlasToolRun
                        && data_get($run->normalized_result_json, 'operating_blueprint_artifact.schema') === 'atlas.ai.company.flow_operating_blueprint_runtime_artifact.v1'
                        && strlen((string) data_get($run->normalized_result_json, 'operating_blueprint_artifact.operating_blueprint_artifact_hash', '')) === 64,
                    'receipt_metadata_bound' => $run instanceof AtlasToolRun
                        && strlen((string) data_get($run->metadata_json, 'decision_receipt_hash', '')) === 64
                        && strlen((string) data_get($run->metadata_json, 'blueprint_receipt_hash', '')) === 64
                        && strlen((string) data_get($run->metadata_json, 'source_lineage_receipt_hash', '')) === 64
                        && strlen((string) data_get($run->metadata_json, 'permission_receipt_hash', '')) === 64
                        && strlen((string) data_get($run->metadata_json, 'eval_replay_receipt_hash', '')) === 64
                        && strlen((string) data_get($run->metadata_json, 'handoff_receipt_hash', '')) === 64
                        && strlen((string) data_get($run->metadata_json, 'audit_trail_hash', '')) === 64
                        && strlen((string) data_get($run->metadata_json, 'rollback_plan_hash', '')) === 64,
                    'quality_gates_bound' => $run instanceof AtlasToolRun
                        && count(array_filter((array) data_get($run->normalized_result_json, 'quality_gates', []))) >= 7,
                    'external_effects_blocked' => $run instanceof AtlasToolRun
                        && ! (bool) data_get($run->metadata_json, 'external_execution_allowed', true)
                        && ! (bool) data_get($run->metadata_json, 'external_side_effects_enabled', true),
                ];
                $readyRecordGateCount = count(array_filter($recordGates));
                $runtimeRecord = [
                    'schema' => 'atlas.ai.company.persisted_operating_blueprint_runtime_record.v1',
                    'company_id' => $id,
                    'flow_id' => $flowId,
                    'run_context_id' => $runContextId,
                    'tool_run_id' => $run instanceof AtlasToolRun ? (string) $run->id : null,
                    'primary_archetype' => $run instanceof AtlasToolRun ? (string) data_get($run->normalized_result_json, 'operating_blueprint_artifact.primary_archetype', '') : null,
                    'ready' => $readyRecordGateCount === count($recordGates),
                    'ready_gate_count' => $readyRecordGateCount,
                    'required_gate_count' => count($recordGates),
                    'gates' => $recordGates,
                    'missing_gates' => array_values(array_keys(array_filter($recordGates, static fn (bool $ready): bool => ! $ready))),
                    'receipt_chain' => $run instanceof AtlasToolRun ? [
                        'decision_receipt_hash' => (string) data_get($run->metadata_json, 'decision_receipt_hash', ''),
                        'blueprint_receipt_hash' => (string) data_get($run->metadata_json, 'blueprint_receipt_hash', ''),
                        'source_lineage_receipt_hash' => (string) data_get($run->metadata_json, 'source_lineage_receipt_hash', ''),
                        'permission_receipt_hash' => (string) data_get($run->metadata_json, 'permission_receipt_hash', ''),
                        'eval_replay_receipt_hash' => (string) data_get($run->metadata_json, 'eval_replay_receipt_hash', ''),
                        'handoff_receipt_hash' => (string) data_get($run->metadata_json, 'handoff_receipt_hash', ''),
                        'audit_trail_hash' => (string) data_get($run->metadata_json, 'audit_trail_hash', ''),
                        'rollback_plan_hash' => (string) data_get($run->metadata_json, 'rollback_plan_hash', ''),
                    ] : [],
                    'external_execution_allowed' => false,
                    'external_side_effects_enabled' => false,
                ];
                $runtimeRecord['persisted_operating_blueprint_runtime_record_hash'] = MissionCanonicalHash::sha256($runtimeRecord);
                $runtimeRecords[] = $runtimeRecord;
            }

            $readyRuntimeRecordCount = count(array_filter($runtimeRecords, static fn (array $record): bool => (bool) ($record['ready'] ?? false)));
            $expectedFlowCount = count($flowBlueprints);
            $gates = [
                'operating_blueprint_stack_ready' => data_get($blueprintStack, 'schema') === 'atlas.ai.company.enterprise_company_operating_blueprint_stack.v1'
                    && count((array) data_get($blueprintStack, 'workload_archetype_catalog', [])) >= 5
                    && count((array) data_get($blueprintStack, 'flow_operating_blueprints', [])) >= $expectedFlowCount
                    && (bool) data_get($blueprintStack, 'blueprint_policy.calendar_wait_blocker_enabled', true) === false,
                'persisted_blueprint_runs_cover_flows' => $expectedFlowCount > 0
                    && count($runtimeRecords) >= $expectedFlowCount
                    && $readyRuntimeRecordCount === count($runtimeRecords),
                'external_effects_blocked' => count(array_filter($runtimeRecords, static fn (array $record): bool => (bool) ($record['external_execution_allowed'] ?? true)
                    || (bool) ($record['external_side_effects_enabled'] ?? true))) === 0
                    && (bool) data_get($blueprintStack, 'blueprint_policy.external_execution_allowed', true) === false
                    && (bool) data_get($blueprintStack, 'blueprint_policy.external_side_effects_enabled', true) === false,
            ];
            $readyGateCount = count(array_filter($gates));
            $row = [
                'schema' => 'atlas.ai.company.enterprise_company_operating_blueprint_runtime_status_record.v1',
                'company_id' => $id,
                'expected_flow_count' => $expectedFlowCount,
                'persisted_blueprint_run_count' => count($runtimeRecords),
                'ready_persisted_blueprint_run_count' => $readyRuntimeRecordCount,
                'operating_blueprint_runtime_ready' => $expectedFlowCount > 0 && $readyGateCount === count($gates),
                'runtime_grade' => $expectedFlowCount > 0 && $readyGateCount === count($gates)
                    ? 'target_9_operating_blueprint_runtime_ready_external_effects_blocked'
                    : 'operating_blueprint_runtime_attention_required',
                'ready_gate_count' => $readyGateCount,
                'required_gate_count' => count($gates),
                'gates' => $gates,
                'missing_gates' => array_values(array_keys(array_filter($gates, static fn (bool $ready): bool => ! $ready))),
                'runtime_records' => $runtimeRecords,
                'source_hashes' => [
                    'company_receipt_hash' => $company['receipt_hash'] ?? null,
                    'operating_blueprint_hash' => data_get($blueprintStack, 'operating_blueprint_hash'),
                ],
                'external_execution_allowed' => false,
                'external_side_effects_enabled' => false,
            ];
            $row['company_operating_blueprint_runtime_status_record_hash'] = MissionCanonicalHash::sha256($row);
            $companies[] = $row;
        }

        $readyCompanyCount = count(array_filter($companies, static fn (array $company): bool => (bool) ($company['operating_blueprint_runtime_ready'] ?? false)));
        $payload = [
            'ok' => $companies !== [] && $readyCompanyCount === count($companies),
            'schema' => ExternalActionMandateRegistryService::ENTERPRISE_COMPANY_OPERATING_BLUEPRINT_RUNTIME_STATUS_SCHEMA,
            'status' => $companies !== [] && $readyCompanyCount === count($companies)
                ? 'enterprise_company_operating_blueprint_runtime_ready_external_effects_blocked'
                : 'enterprise_company_operating_blueprint_runtime_attention_required',
            'generated_at' => now()->toJSON(),
            'summary' => [
                'company_count' => count($companies),
                'operating_blueprint_runtime_ready_company_count' => $readyCompanyCount,
                'attention_company_count' => count($companies) - $readyCompanyCount,
                'expected_flow_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['expected_flow_count'] ?? 0), $companies)),
                'persisted_blueprint_run_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['persisted_blueprint_run_count'] ?? 0), $companies)),
                'ready_persisted_blueprint_run_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['ready_persisted_blueprint_run_count'] ?? 0), $companies)),
                'required_gate_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['required_gate_count'] ?? 0), $companies)),
                'ready_gate_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['ready_gate_count'] ?? 0), $companies)),
                'external_execution_allowed_count' => 0,
                'external_side_effects_enabled_count' => 0,
            ],
            'companies' => $companies,
            'policy' => $this->operatingBlueprintRuntimePolicy(),
        ];
        $payload['enterprise_company_operating_blueprint_runtime_status_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @param array<string,mixed> $company
     * @param array<string,mixed> $acceptanceRow
     * @return array<string,mixed>
     */
    public function flowWorkProductRuntimeArtifact(array $company, string $flowId, array $acceptanceRow, string $runContextId): array
    {
        $companyId = (string) ($company['company_id'] ?? 'unknown');
        $workProducts = array_values((array) ($company['work_products'] ?? []));
        $flowIds = array_values(array_filter(array_map(
            static fn (array|string $flow): string => is_array($flow)
                ? (string) ($flow['flow_id'] ?? $flow['id'] ?? '')
                : (string) $flow,
            (array) ($company['flows'] ?? []),
        )));
        $flowIndex = array_search($flowId, $flowIds, true);
        $workProductKind = (string) ($workProducts[is_int($flowIndex) && $workProducts !== [] ? $flowIndex % count($workProducts) : 0] ?? 'operating_packet');
        $blueprint = $this->hub->findByFlow($company, 'enterprise_flow_work_product_delivery_stack.flow_delivery_blueprints', $flowId);
        $acceptance = $this->hub->findByFlow($company, 'enterprise_flow_work_product_delivery_stack.flow_acceptance_contracts', $flowId);
        $handoff = $this->hub->findByFlow($company, 'enterprise_flow_work_product_delivery_stack.flow_handoff_packets', $flowId);
        $replay = $this->hub->findByFlow($company, 'enterprise_flow_work_product_delivery_stack.flow_replay_artifact_checks', $flowId);
        $businessPacket = $this->hub->findByFlow($company, 'enterprise_business_operating_packet_stack.flow_operating_packets', $flowId);
        $solutionPlaybook = $this->hub->findByFlow($company, 'enterprise_domain_solution_stack.enterprise_solution_playbooks', $flowId);
        $connectorContract = $this->hub->findByFlow($company, 'enterprise_domain_data_connector_operating_stack.flow_data_connector_contracts', $flowId);
        $sourceLineageHash = MissionCanonicalHash::sha256([
            'company_id' => $companyId,
            'flow_id' => $flowId,
            'solution_playbook' => $solutionPlaybook,
            'connector_contract' => $connectorContract,
            'business_packet' => $businessPacket,
        ]);

        $artifact = [
            'schema' => 'atlas.ai.company.flow_work_product_runtime_artifact.v1',
            'company_id' => $companyId,
            'flow_id' => $flowId,
            'run_context_id' => $runContextId,
            'work_product_id' => $companyId.'.'.$flowId.'.'.$workProductKind.'.v1',
            'work_product_kind' => $workProductKind,
            'runtime_mode' => 'internal_delivery_acceptance_replay_no_external_handoff',
            'enterprise_patterns' => [
                'unified_source_grounded_data_plane',
                'mcp_or_api_adapter_boundary',
                'durable_agentic_workflow_with_handoffs',
                'least_privilege_tool_permissions',
                'trace_eval_replay_and_operator_acceptance',
            ],
            'source_lineage' => [
                'direct_source_refs_required' => true,
                'solution_playbook_hash' => (string) ($solutionPlaybook['playbook_hash'] ?? hash('sha256', 'solution_playbook|'.$companyId.'|'.$flowId)),
                'connector_contract_hash' => (string) ($connectorContract['contract_hash'] ?? $connectorContract['connector_contract_hash'] ?? hash('sha256', 'connector_contract|'.$companyId.'|'.$flowId)),
                'business_packet_hash' => (string) ($businessPacket['operating_packet_hash'] ?? $businessPacket['packet_hash'] ?? hash('sha256', 'business_packet|'.$companyId.'|'.$flowId)),
                'source_lineage_hash' => $sourceLineageHash,
                'claim_without_source_reference_allowed' => false,
            ],
            'delivery' => [
                'delivery_blueprint_hash' => (string) ($blueprint['blueprint_hash'] ?? $blueprint['delivery_blueprint_hash'] ?? hash('sha256', 'delivery_blueprint|'.$companyId.'|'.$flowId)),
                'artifact_sections' => array_values(array_unique(array_merge(
                    ['executive_summary', 'source_refs', 'analysis', 'decision_or_action_packet', 'risk_controls', 'acceptance_evidence'],
                    (array) data_get($blueprint, 'required_sections', []),
                ))),
                'customer_visible_output_allowed' => false,
                'external_handoff_allowed' => false,
            ],
            'acceptance' => [
                'acceptance_contract_hash' => (string) ($acceptance['acceptance_contract_hash'] ?? $acceptance['contract_hash'] ?? hash('sha256', 'acceptance_contract|'.$companyId.'|'.$flowId)),
                'operator_acceptance_required' => true,
                'quality_threshold' => 0.95,
                'policy_findings_allowed' => 0,
            ],
            'handoff' => [
                'handoff_packet_hash' => (string) ($handoff['handoff_packet_hash'] ?? $handoff['packet_hash'] ?? hash('sha256', 'handoff_packet|'.$companyId.'|'.$flowId)),
                'handoff_target' => (string) ($handoff['target'] ?? $handoff['target_surface'] ?? 'operator_review_queue'),
                'external_delivery_requires_signed_scope' => true,
            ],
            'replay' => [
                'replay_artifact_check_hash' => (string) ($replay['replay_artifact_check_hash'] ?? $replay['check_hash'] ?? $replay['replay_hash'] ?? hash('sha256', 'replay_artifact_check|'.$companyId.'|'.$flowId)),
                'deterministic_replay_required' => true,
                'regression_case_required' => true,
                'trace_diff_required' => true,
            ],
            'acceptance_record_hash' => (string) ($acceptanceRow['company_work_product_acceptance_evidence_record_hash'] ?? ''),
            'external_delivery_allowed' => false,
            'external_execution_allowed' => false,
            'external_side_effects_enabled' => false,
            'generated_at' => now()->toJSON(),
        ];
        $artifact['work_product_hash'] = MissionCanonicalHash::sha256($artifact);

        return $artifact;
    }

    /**
     * @param array<string,mixed> $company
     * @param array<string,mixed> $flowBlueprint
     * @return array<string,mixed>
     */
    public function flowOperatingBlueprintRuntimeArtifact(array $company, array $flowBlueprint, string $runContextId): array
    {
        $companyId = (string) ($company['company_id'] ?? 'unknown');
        $flowId = (string) ($flowBlueprint['flow_id'] ?? 'unknown');
        $blueprintStack = (array) data_get($company, 'enterprise_company_operating_blueprint_stack', []);
        $connectorProfiles = array_values((array) data_get($blueprintStack, 'connector_permission_profiles', []));
        $requiredConnectors = array_values((array) ($flowBlueprint['required_connectors'] ?? []));
        $matchedProfiles = array_values(array_filter(
            $connectorProfiles,
            static fn (array $profile): bool => in_array((string) ($profile['connector_id'] ?? ''), $requiredConnectors, true),
        ));
        $assemblyLine = $this->hub->findByFlow($company, 'enterprise_company_operating_blueprint_stack.artifact_assembly_lines', $flowId);
        $controlRoomHandoff = $this->hub->findByFlow($company, 'enterprise_company_operating_blueprint_stack.control_room_handoffs', $flowId);

        $artifact = [
            'schema' => 'atlas.ai.company.flow_operating_blueprint_runtime_artifact.v1',
            'company_id' => $companyId,
            'flow_id' => $flowId,
            'run_context_id' => $runContextId,
            'primary_archetype' => (string) ($flowBlueprint['primary_archetype'] ?? ''),
            'required_skills' => array_values((array) ($flowBlueprint['required_skills'] ?? [])),
            'required_connectors' => $requiredConnectors,
            'subagent_lanes' => (array) ($flowBlueprint['subagent_lanes'] ?? []),
            'source_lineage' => [
                'source_refs' => array_values((array) ($flowBlueprint['source_refs'] ?? [])),
                'company_receipt_hash' => (string) ($company['receipt_hash'] ?? ''),
                'operating_blueprint_hash' => (string) data_get($blueprintStack, 'operating_blueprint_hash', ''),
                'flow_operating_blueprint_hash' => (string) ($flowBlueprint['flow_operating_blueprint_hash'] ?? ''),
                'source_lineage_hash' => MissionCanonicalHash::sha256([
                    'company_id' => $companyId,
                    'flow_id' => $flowId,
                    'source_refs' => array_values((array) ($flowBlueprint['source_refs'] ?? [])),
                    'operating_blueprint_hash' => data_get($blueprintStack, 'operating_blueprint_hash'),
                ]),
            ],
            'connector_permission_profiles' => $matchedProfiles,
            'artifact_assembly_contract' => (array) ($flowBlueprint['artifact_assembly_contract'] ?? []),
            'artifact_assembly_line' => $assemblyLine,
            'runtime_handoff_contract' => (array) ($flowBlueprint['runtime_handoff_contract'] ?? []),
            'control_room_handoff' => $controlRoomHandoff,
            'quality_and_eval_gates' => (array) ($flowBlueprint['quality_and_eval_gates'] ?? []),
            'enterprise_patterns' => [
                'task_specific_workload_templates',
                'unified_source_grounded_data_plane',
                'least_privilege_connector_permissions',
                'managed_vault_boundary',
                'durable_agentic_workflow_with_checkpoint_resume',
                'full_decision_tool_artifact_audit_log',
                'trace_replay_eval_and_operator_acceptance',
            ],
            'execution_policy' => [
                'calendar_wait_blocker_enabled' => false,
                'internal_runtime_allowed' => true,
                'external_execution_allowed' => false,
                'external_side_effects_enabled' => false,
                'operator_mandate_required_for_external_effect' => true,
            ],
            'external_execution_allowed' => false,
            'external_side_effects_enabled' => false,
            'generated_at' => now()->toJSON(),
        ];
        $artifact['operating_blueprint_artifact_hash'] = MissionCanonicalHash::sha256($artifact);

        return $artifact;
    }

    /**
     * @return array<string,mixed>
     */
    public function workProductRuntimePolicy(): array
    {
        return [
            'work_product_runtime_is_not_external_delivery_authority' => true,
            'calendar_wait_blocker_enabled' => false,
            'external_delivery_allowed' => false,
            'external_execution_allowed' => false,
            'external_side_effects_enabled' => false,
            'operator_acceptance_required_before_external_handoff' => true,
            'required_runtime_evidence' => ['atlas_tool_run', 'work_product_artifact', 'source_lineage', 'acceptance_contract', 'handoff_packet', 'replay_artifact_check', 'decision_receipt', 'work_product_receipt', 'acceptance_receipt', 'handoff_receipt', 'replay_receipt', 'audit_trail', 'rollback_plan'],
            'blocked_operations' => ['auto_send_to_customer', 'external_publish', 'unsupported_claim', 'claim_without_source_reference', 'skip_operator_acceptance', 'skip_source_lineage', 'external_write', 'spend', 'trade', 'deploy', 'delete', 'offensive_security'],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function operatingBlueprintRuntimePolicy(): array
    {
        return [
            'operating_blueprint_runtime_is_not_external_execution_authority' => true,
            'calendar_wait_blocker_enabled' => false,
            'external_execution_allowed' => false,
            'external_side_effects_enabled' => false,
            'operator_signed_scope_required_for_external_effect' => true,
            'required_runtime_evidence' => [
                'atlas_tool_run',
                'operating_blueprint_artifact',
                'source_lineage',
                'connector_permission_profiles',
                'artifact_assembly_contract',
                'runtime_handoff_contract',
                'control_room_handoff',
                'quality_and_eval_gates',
                'decision_receipt',
                'blueprint_receipt',
                'source_lineage_receipt',
                'permission_receipt',
                'eval_replay_receipt',
                'handoff_receipt',
                'audit_trail',
                'rollback_plan',
            ],
            'blocked_operations' => [
                'skip_blueprint_runtime',
                'skip_source_lineage',
                'skip_connector_permission_scope',
                'skip_eval_replay',
                'skip_operator_handoff',
                'claim_without_source_reference',
                'external_write',
                'publish',
                'spend',
                'trade',
                'deploy',
                'delete',
                'offensive_security',
                'secret_export',
            ],
        ];
    }
}
