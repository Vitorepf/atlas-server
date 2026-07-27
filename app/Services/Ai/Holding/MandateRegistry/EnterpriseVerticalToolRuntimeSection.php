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
class EnterpriseVerticalToolRuntimeSection
{
    public function __construct(private readonly MandateRegistryHub $hub) {}

    /**
     * @return array<string,mixed>
     */
    public function enterpriseCompanyVerticalToolOperatingRuntimeRegister(?string $companyId = null): array
    {
        $wantedCompany = $companyId !== null && trim($companyId) !== '' ? trim($companyId) : null;
        unset($this->hub->runtimeStatusCache['vertical_tool_operating_runtime_status:'.($wantedCompany ?? '*')]);
        $activationPackets = $this->hub->enterpriseToolActivation->enterpriseCompanyExternalToolActivationPacketStatus($wantedCompany);
        $activationPacketsByCompany = $this->hub->companyRowsById($activationPackets);

        if (! $this->hub->approval->toolRunsTableAvailable()) {
            return [
                'ok' => false,
                'schema' => ExternalActionMandateRegistryService::ENTERPRISE_COMPANY_VERTICAL_TOOL_OPERATING_RUNTIME_REGISTER_SCHEMA,
                'status' => 'atlas_tool_runs_table_unavailable',
                'generated_at' => now()->toJSON(),
                'summary' => [
                    'company_count' => 0,
                    'expected_vertical_tool_runtime_count' => 0,
                    'registered_vertical_tool_runtime_count' => 0,
                    'external_execution_allowed_count' => 0,
                    'external_side_effects_enabled_count' => 0,
                ],
                'companies' => [],
                'policy' => $this->verticalToolOperatingRuntimePolicy(),
            ];
        }

        $companies = [];
        foreach ($this->hub->buildoutCompanies($wantedCompany) as $company) {
            $id = (string) ($company['company_id'] ?? 'unknown');
            $packetRow = (array) ($activationPacketsByCompany[$id] ?? []);
            $registeredRuntimes = [];

            foreach ((array) ($packetRow['packet_records'] ?? []) as $packetRecord) {
                $flowId = (string) ($packetRecord['flow_id'] ?? '');
                $capabilityId = (string) ($packetRecord['capability_id'] ?? '');
                $connectorId = (string) ($packetRecord['connector_id'] ?? '');
                $activationPacketId = (string) ($packetRecord['activation_packet_id'] ?? '');
                if ($flowId === '' || $capabilityId === '' || $connectorId === '' || $activationPacketId === '') {
                    continue;
                }

                $runContextId = $id.'.'.substr(MissionCanonicalHash::sha256([
                    'flow_id' => $flowId,
                    'capability_id' => $capabilityId,
                    'connector_id' => $connectorId,
                    'activation_packet_id' => $activationPacketId,
                    'context' => 'vertical_tool_operating_runtime',
                ]), 0, 24).'.vertical_tool_operating_runtime.v1';
                $artifact = $this->verticalToolOperatingRuntimeArtifact($company, (array) $packetRecord, $runContextId);
                $qualityGates = [
                    'activation_packet_ready' => (bool) ($packetRecord['ready'] ?? false)
                        && strlen((string) ($packetRecord['external_tool_activation_packet_status_record_hash'] ?? '')) === 64,
                    'tool_operating_artifact_bound' => strlen((string) ($artifact['vertical_tool_operating_runtime_artifact_hash'] ?? '')) === 64,
                    'tool_catalog_bound' => count((array) data_get($artifact, 'tool_catalog.tools', [])) >= 5
                        && strlen((string) data_get($artifact, 'tool_catalog.tool_catalog_hash', '')) === 64,
                    'domain_data_contract_bound' => strlen((string) data_get($artifact, 'domain_data_contract.domain_data_contract_hash', '')) === 64
                        && count((array) data_get($artifact, 'domain_data_contract.required_sources', [])) >= 3,
                    'operating_cell_bound' => strlen((string) data_get($artifact, 'operating_cell.operating_cell_hash', '')) === 64
                        && count((array) data_get($artifact, 'operating_cell.workcell_roles', [])) >= 5,
                    'slo_and_observability_bound' => strlen((string) data_get($artifact, 'slo_contract.slo_contract_hash', '')) === 64
                        && count((array) data_get($artifact, 'slo_contract.required_metrics', [])) >= 8,
                    'eval_replay_bound' => strlen((string) data_get($artifact, 'eval_replay.eval_replay_hash', '')) === 64
                        && count((array) data_get($artifact, 'eval_replay.replay_assertions', [])) >= 8,
                    'operator_handoff_bound' => strlen((string) data_get($artifact, 'operator_handoff.operator_handoff_hash', '')) === 64
                        && (bool) data_get($artifact, 'operator_handoff.operator_acceptance_required', false),
                    'receipt_chain_bound' => strlen((string) data_get($artifact, 'receipt_chain.vertical_tool_runtime_receipt_hash', '')) === 64
                        && strlen((string) data_get($artifact, 'receipt_chain.tool_catalog_receipt_hash', '')) === 64
                        && strlen((string) data_get($artifact, 'receipt_chain.eval_replay_receipt_hash', '')) === 64,
                    'external_effects_blocked' => ! (bool) ($artifact['external_execution_allowed'] ?? true)
                        && ! (bool) ($artifact['external_side_effects_enabled'] ?? true),
                ];
                $summary = [
                    'company_id' => $id,
                    'flow_id' => $flowId,
                    'capability_id' => $capabilityId,
                    'connector_id' => $connectorId,
                    'activation_packet_id' => $activationPacketId,
                    'tool_operating_cell_id' => (string) ($artifact['tool_operating_cell_id'] ?? ''),
                    'runtime_mode' => 'vertical_tool_operating_cell_readonly_replay_operator_handoff',
                    'quality_gate_count' => count($qualityGates),
                    'ready_quality_gate_count' => count(array_filter($qualityGates)),
                    'external_execution_allowed' => false,
                    'external_side_effects_enabled' => false,
                ];
                $normalized = [
                    'schema' => 'atlas.ai.company.vertical_tool_operating_runtime_result.v1',
                    'status' => count(array_filter($qualityGates)) === count($qualityGates) ? 'passed' : 'attention_required',
                    'summary' => $summary,
                    'vertical_tool_operating_runtime_artifact' => $artifact,
                    'quality_gates' => $qualityGates,
                    'blocking_failures' => array_values(array_keys(array_filter($qualityGates, static fn (bool $ready): bool => ! $ready))),
                ];
                $receiptInput = [
                    'company_id' => $id,
                    'flow_id' => $flowId,
                    'capability_id' => $capabilityId,
                    'connector_id' => $connectorId,
                    'activation_packet_id' => $activationPacketId,
                    'run_context_id' => $runContextId,
                    'artifact_hash' => (string) ($artifact['vertical_tool_operating_runtime_artifact_hash'] ?? ''),
                ];
                $metadata = [
                    'source' => 'enterprise_company_vertical_tool_operating_runtime_register',
                    'receipt_schema_version' => 'atlas.company_vertical_tool_operating_runtime_receipt.v1',
                    'evidence_receipt_hash' => MissionCanonicalHash::sha256($normalized),
                    'vertical_tool_runtime_receipt_hash' => MissionCanonicalHash::sha256($receiptInput + ['receipt_type' => 'vertical_tool_runtime_receipt']),
                    'tool_catalog_receipt_hash' => MissionCanonicalHash::sha256($receiptInput + ['receipt_type' => 'tool_catalog_receipt']),
                    'domain_data_contract_receipt_hash' => MissionCanonicalHash::sha256($receiptInput + ['receipt_type' => 'domain_data_contract_receipt']),
                    'operating_cell_receipt_hash' => MissionCanonicalHash::sha256($receiptInput + ['receipt_type' => 'operating_cell_receipt']),
                    'slo_contract_receipt_hash' => MissionCanonicalHash::sha256($receiptInput + ['receipt_type' => 'slo_contract_receipt']),
                    'eval_replay_receipt_hash' => MissionCanonicalHash::sha256($receiptInput + ['receipt_type' => 'eval_replay_receipt']),
                    'operator_handoff_receipt_hash' => MissionCanonicalHash::sha256($receiptInput + ['receipt_type' => 'operator_handoff_receipt']),
                    'audit_trail_hash' => MissionCanonicalHash::sha256($receiptInput + ['receipt_type' => 'audit_trail']),
                    'rollback_plan_hash' => MissionCanonicalHash::sha256($receiptInput + ['receipt_type' => 'rollback_plan']),
                    'external_execution_allowed' => false,
                    'external_side_effects_enabled' => false,
                ];

                $run = AtlasToolRun::query()->updateOrCreate(
                    [
                        'surface' => 'holding_company_vertical_tool_operating_runtime',
                        'run_context_type' => 'holding_company_vertical_tool_operating_runtime',
                        'run_context_id' => $runContextId,
                    ],
                    [
                        'tool_slug' => 'atlas_company_vertical_tool_operating_runtime',
                        'workspace_hash' => hash('sha256', base_path()),
                        'workspace' => base_path(),
                        'status' => $normalized['status'] === 'passed' ? 'passed' : 'failed',
                        'required' => true,
                        'failure_policy' => 'fail_closed',
                        'policy_decision' => 'vertical_tool_runtime_blocked',
                        'command_hash' => MissionCanonicalHash::sha256([
                            'run_context_id' => $runContextId,
                            'artifact_hash' => $artifact['vertical_tool_operating_runtime_artifact_hash'] ?? null,
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
                            'blocked_operations' => $this->verticalToolOperatingRuntimePolicy()['blocked_operations'],
                        ],
                        'metadata_json' => $metadata,
                    ],
                );

                $registeredRuntimes[] = [
                    'run_id' => (string) $run->id,
                    'run_context_id' => $runContextId,
                    'tool_operating_cell_id' => (string) ($artifact['tool_operating_cell_id'] ?? ''),
                    'flow_id' => $flowId,
                    'capability_id' => $capabilityId,
                    'connector_id' => $connectorId,
                    'status' => (string) $run->status,
                    'policy_decision' => (string) $run->policy_decision,
                    'vertical_tool_runtime_receipt_hash' => (string) data_get($run->metadata_json, 'vertical_tool_runtime_receipt_hash', ''),
                    'external_execution_allowed' => false,
                    'external_side_effects_enabled' => false,
                ];
            }

            $row = [
                'schema' => 'atlas.ai.company.enterprise_vertical_tool_operating_runtime_register_record.v1',
                'company_id' => $id,
                'expected_vertical_tool_runtime_count' => (int) ($packetRow['ready_external_tool_activation_packet_count'] ?? 0),
                'registered_vertical_tool_runtime_count' => count($registeredRuntimes),
                'registered_runtimes' => $registeredRuntimes,
                'external_execution_allowed' => false,
                'external_side_effects_enabled' => false,
            ];
            $row['company_vertical_tool_operating_runtime_register_record_hash'] = MissionCanonicalHash::sha256($row);
            $companies[] = $row;
        }

        $registeredCount = array_sum(array_map(static fn (array $company): int => (int) ($company['registered_vertical_tool_runtime_count'] ?? 0), $companies));
        $expectedCount = array_sum(array_map(static fn (array $company): int => (int) ($company['expected_vertical_tool_runtime_count'] ?? 0), $companies));
        $payload = [
            'ok' => $expectedCount > 0 && $registeredCount >= $expectedCount,
            'schema' => ExternalActionMandateRegistryService::ENTERPRISE_COMPANY_VERTICAL_TOOL_OPERATING_RUNTIME_REGISTER_SCHEMA,
            'status' => $expectedCount > 0 && $registeredCount >= $expectedCount
                ? 'enterprise_company_vertical_tool_operating_runtime_registered_external_effects_blocked'
                : 'enterprise_company_vertical_tool_operating_runtime_attention_required',
            'generated_at' => now()->toJSON(),
            'summary' => [
                'company_count' => count($companies),
                'expected_vertical_tool_runtime_count' => $expectedCount,
                'registered_vertical_tool_runtime_count' => $registeredCount,
                'external_execution_allowed_count' => 0,
                'external_side_effects_enabled_count' => 0,
            ],
            'source_hashes' => [
                'enterprise_company_external_tool_activation_packet_status_hash' => $activationPackets['enterprise_company_external_tool_activation_packet_status_hash'] ?? null,
            ],
            'companies' => $companies,
            'policy' => $this->verticalToolOperatingRuntimePolicy(),
        ];
        $payload['enterprise_company_vertical_tool_operating_runtime_register_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function enterpriseCompanyVerticalToolOperatingRuntimeStatus(?string $companyId = null): array
    {
        $wantedCompany = $companyId !== null && trim($companyId) !== '' ? trim($companyId) : null;
        $cacheKey = 'vertical_tool_operating_runtime_status:'.($wantedCompany ?? '*');
        if (isset($this->hub->runtimeStatusCache[$cacheKey])) {
            return $this->hub->runtimeStatusCache[$cacheKey];
        }

        $activationPackets = $this->hub->enterpriseToolActivation->enterpriseCompanyExternalToolActivationPacketStatus($wantedCompany);
        $activationPacketsByCompany = $this->hub->companyRowsById($activationPackets);

        if (! $this->hub->approval->toolRunsTableAvailable()) {
            return [
                'ok' => false,
                'schema' => ExternalActionMandateRegistryService::ENTERPRISE_COMPANY_VERTICAL_TOOL_OPERATING_RUNTIME_STATUS_SCHEMA,
                'status' => 'atlas_tool_runs_table_unavailable',
                'generated_at' => now()->toJSON(),
                'summary' => [
                    'company_count' => 0,
                    'expected_vertical_tool_runtime_count' => 0,
                    'persisted_vertical_tool_runtime_count' => 0,
                    'ready_vertical_tool_runtime_count' => 0,
                    'external_execution_allowed_count' => 0,
                    'external_side_effects_enabled_count' => 0,
                ],
                'companies' => [],
                'policy' => $this->verticalToolOperatingRuntimePolicy(),
            ];
        }

        $companies = [];
        foreach ($this->hub->buildoutCompanies($wantedCompany) as $company) {
            $id = (string) ($company['company_id'] ?? 'unknown');
            $packetRow = (array) ($activationPacketsByCompany[$id] ?? []);
            $runtimeRecords = [];

            foreach ((array) ($packetRow['packet_records'] ?? []) as $packetRecord) {
                $flowId = (string) ($packetRecord['flow_id'] ?? '');
                $capabilityId = (string) ($packetRecord['capability_id'] ?? '');
                $connectorId = (string) ($packetRecord['connector_id'] ?? '');
                $activationPacketId = (string) ($packetRecord['activation_packet_id'] ?? '');
                if ($flowId === '' || $capabilityId === '' || $connectorId === '' || $activationPacketId === '') {
                    continue;
                }

                $runContextId = $id.'.'.substr(MissionCanonicalHash::sha256([
                    'flow_id' => $flowId,
                    'capability_id' => $capabilityId,
                    'connector_id' => $connectorId,
                    'activation_packet_id' => $activationPacketId,
                    'context' => 'vertical_tool_operating_runtime',
                ]), 0, 24).'.vertical_tool_operating_runtime.v1';
                $run = AtlasToolRun::query()
                    ->where('surface', 'holding_company_vertical_tool_operating_runtime')
                    ->where('run_context_type', 'holding_company_vertical_tool_operating_runtime')
                    ->where('run_context_id', $runContextId)
                    ->latest('updated_at')
                    ->first();

                $recordGates = [
                    'persisted_vertical_tool_runtime_exists' => $run instanceof AtlasToolRun,
                    'status_passed' => $run instanceof AtlasToolRun && $run->status === 'passed',
                    'policy_vertical_tool_runtime_blocked' => $run instanceof AtlasToolRun && $run->policy_decision === 'vertical_tool_runtime_blocked',
                    'normalized_result_bound' => $run instanceof AtlasToolRun && data_get($run->normalized_result_json, 'schema') === 'atlas.ai.company.vertical_tool_operating_runtime_result.v1',
                    'artifact_bound' => $run instanceof AtlasToolRun
                        && data_get($run->normalized_result_json, 'vertical_tool_operating_runtime_artifact.schema') === 'atlas.ai.company.vertical_tool_operating_runtime_artifact.v1'
                        && strlen((string) data_get($run->normalized_result_json, 'vertical_tool_operating_runtime_artifact.vertical_tool_operating_runtime_artifact_hash', '')) === 64,
                    'tool_catalog_and_data_contract_bound' => $run instanceof AtlasToolRun
                        && strlen((string) data_get($run->normalized_result_json, 'vertical_tool_operating_runtime_artifact.tool_catalog.tool_catalog_hash', '')) === 64
                        && strlen((string) data_get($run->normalized_result_json, 'vertical_tool_operating_runtime_artifact.domain_data_contract.domain_data_contract_hash', '')) === 64,
                    'slo_eval_and_handoff_bound' => $run instanceof AtlasToolRun
                        && strlen((string) data_get($run->normalized_result_json, 'vertical_tool_operating_runtime_artifact.slo_contract.slo_contract_hash', '')) === 64
                        && strlen((string) data_get($run->normalized_result_json, 'vertical_tool_operating_runtime_artifact.eval_replay.eval_replay_hash', '')) === 64
                        && strlen((string) data_get($run->normalized_result_json, 'vertical_tool_operating_runtime_artifact.operator_handoff.operator_handoff_hash', '')) === 64,
                    'receipt_metadata_bound' => $run instanceof AtlasToolRun
                        && strlen((string) data_get($run->metadata_json, 'vertical_tool_runtime_receipt_hash', '')) === 64
                        && strlen((string) data_get($run->metadata_json, 'tool_catalog_receipt_hash', '')) === 64
                        && strlen((string) data_get($run->metadata_json, 'domain_data_contract_receipt_hash', '')) === 64
                        && strlen((string) data_get($run->metadata_json, 'slo_contract_receipt_hash', '')) === 64
                        && strlen((string) data_get($run->metadata_json, 'eval_replay_receipt_hash', '')) === 64
                        && strlen((string) data_get($run->metadata_json, 'operator_handoff_receipt_hash', '')) === 64,
                    'external_effects_blocked' => $run instanceof AtlasToolRun
                        && ! (bool) data_get($run->policy_decision_json, 'external_execution_allowed', true)
                        && ! (bool) data_get($run->policy_decision_json, 'external_side_effects_enabled', true),
                ];
                $readyRecordGateCount = count(array_filter($recordGates));
                $record = [
                    'schema' => 'atlas.ai.company.vertical_tool_operating_runtime_status_record.v1',
                    'company_id' => $id,
                    'flow_id' => $flowId,
                    'capability_id' => $capabilityId,
                    'connector_id' => $connectorId,
                    'activation_packet_id' => $activationPacketId,
                    'run_context_id' => $runContextId,
                    'tool_run_id' => $run instanceof AtlasToolRun ? (string) $run->id : null,
                    'ready' => $readyRecordGateCount === count($recordGates),
                    'ready_gate_count' => $readyRecordGateCount,
                    'required_gate_count' => count($recordGates),
                    'gates' => $recordGates,
                    'missing_gates' => array_values(array_keys(array_filter($recordGates, static fn (bool $ready): bool => ! $ready))),
                    'receipt_chain' => $run instanceof AtlasToolRun ? [
                        'vertical_tool_runtime_receipt_hash' => (string) data_get($run->metadata_json, 'vertical_tool_runtime_receipt_hash', ''),
                        'tool_catalog_receipt_hash' => (string) data_get($run->metadata_json, 'tool_catalog_receipt_hash', ''),
                        'domain_data_contract_receipt_hash' => (string) data_get($run->metadata_json, 'domain_data_contract_receipt_hash', ''),
                        'slo_contract_receipt_hash' => (string) data_get($run->metadata_json, 'slo_contract_receipt_hash', ''),
                        'eval_replay_receipt_hash' => (string) data_get($run->metadata_json, 'eval_replay_receipt_hash', ''),
                        'operator_handoff_receipt_hash' => (string) data_get($run->metadata_json, 'operator_handoff_receipt_hash', ''),
                    ] : [],
                    'external_execution_allowed' => false,
                    'external_side_effects_enabled' => false,
                ];
                $record['vertical_tool_operating_runtime_status_record_hash'] = MissionCanonicalHash::sha256($record);
                $runtimeRecords[] = $record;
            }

            $expectedRuntimeCount = (int) ($packetRow['ready_external_tool_activation_packet_count'] ?? 0);
            $readyRuntimeCount = count(array_filter($runtimeRecords, static fn (array $record): bool => (bool) ($record['ready'] ?? false)));
            $gates = [
                'external_tool_activation_packets_ready' => (bool) ($packetRow['external_tool_activation_packets_ready'] ?? false),
                'vertical_tool_runtime_covers_activation_packets' => $expectedRuntimeCount > 0
                    && count($runtimeRecords) >= $expectedRuntimeCount
                    && $readyRuntimeCount === count($runtimeRecords),
                'external_effects_blocked' => count(array_filter($runtimeRecords, static fn (array $record): bool => (bool) ($record['external_execution_allowed'] ?? true)
                    || (bool) ($record['external_side_effects_enabled'] ?? true))) === 0,
            ];
            $readyGateCount = count(array_filter($gates));
            $row = [
                'schema' => 'atlas.ai.company.enterprise_vertical_tool_operating_runtime_company_status.v1',
                'company_id' => $id,
                'expected_vertical_tool_runtime_count' => $expectedRuntimeCount,
                'persisted_vertical_tool_runtime_count' => count($runtimeRecords),
                'ready_vertical_tool_runtime_count' => $readyRuntimeCount,
                'vertical_tool_operating_runtime_ready' => $expectedRuntimeCount > 0 && $readyGateCount === count($gates),
                'runtime_grade' => $expectedRuntimeCount > 0 && $readyGateCount === count($gates)
                    ? 'target_9_vertical_tool_operating_runtime_ready_external_effects_blocked'
                    : 'vertical_tool_operating_runtime_attention_required',
                'ready_gate_count' => $readyGateCount,
                'required_gate_count' => count($gates),
                'gates' => $gates,
                'missing_gates' => array_values(array_keys(array_filter($gates, static fn (bool $ready): bool => ! $ready))),
                'runtime_records' => $runtimeRecords,
                'external_execution_allowed' => false,
                'external_side_effects_enabled' => false,
            ];
            $row['company_vertical_tool_operating_runtime_status_record_hash'] = MissionCanonicalHash::sha256($row);
            $companies[] = $row;
        }

        $readyCompanyCount = count(array_filter($companies, static fn (array $company): bool => (bool) ($company['vertical_tool_operating_runtime_ready'] ?? false)));
        $payload = [
            'ok' => $companies !== [] && $readyCompanyCount === count($companies),
            'schema' => ExternalActionMandateRegistryService::ENTERPRISE_COMPANY_VERTICAL_TOOL_OPERATING_RUNTIME_STATUS_SCHEMA,
            'status' => $companies !== [] && $readyCompanyCount === count($companies)
                ? 'enterprise_company_vertical_tool_operating_runtime_ready_external_effects_blocked'
                : 'enterprise_company_vertical_tool_operating_runtime_attention_required',
            'generated_at' => now()->toJSON(),
            'summary' => [
                'company_count' => count($companies),
                'vertical_tool_operating_runtime_ready_company_count' => $readyCompanyCount,
                'attention_company_count' => count($companies) - $readyCompanyCount,
                'expected_vertical_tool_runtime_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['expected_vertical_tool_runtime_count'] ?? 0), $companies)),
                'persisted_vertical_tool_runtime_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['persisted_vertical_tool_runtime_count'] ?? 0), $companies)),
                'ready_vertical_tool_runtime_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['ready_vertical_tool_runtime_count'] ?? 0), $companies)),
                'required_gate_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['required_gate_count'] ?? 0), $companies)),
                'ready_gate_count' => array_sum(array_map(static fn (array $company): int => (int) ($company['ready_gate_count'] ?? 0), $companies)),
                'external_execution_allowed_count' => 0,
                'external_side_effects_enabled_count' => 0,
            ],
            'source_hashes' => [
                'enterprise_company_external_tool_activation_packet_status_hash' => $activationPackets['enterprise_company_external_tool_activation_packet_status_hash'] ?? null,
            ],
            'companies' => $companies,
            'policy' => $this->verticalToolOperatingRuntimePolicy(),
        ];
        $payload['enterprise_company_vertical_tool_operating_runtime_status_hash'] = MissionCanonicalHash::sha256($payload);

        return $this->hub->runtimeStatusCache[$cacheKey] = $payload;
    }

    /**
     * @param array<string,mixed> $company
     * @param array<string,mixed> $packetRecord
     * @return array<string,mixed>
     */
    public function verticalToolOperatingRuntimeArtifact(array $company, array $packetRecord, string $runContextId): array
    {
        $companyId = (string) ($company['company_id'] ?? 'unknown');
        $flowId = (string) ($packetRecord['flow_id'] ?? '');
        $capabilityId = (string) ($packetRecord['capability_id'] ?? '');
        $connectorId = (string) ($packetRecord['connector_id'] ?? '');
        $activationPacketId = (string) ($packetRecord['activation_packet_id'] ?? '');
        $capability = (array) ($this->hub->enterpriseCapabilityRuntime->enterpriseCapabilityRuntimeCatalog()[$capabilityId] ?? []);
        $sourceRecordHash = (string) ($packetRecord['external_tool_activation_packet_status_record_hash'] ?? '');
        $cellId = MissionCanonicalHash::sha256([
            'type' => 'vertical_tool_operating_runtime',
            'company_id' => $companyId,
            'flow_id' => $flowId,
            'capability_id' => $capabilityId,
            'connector_id' => $connectorId,
            'activation_packet_id' => $activationPacketId,
            'source_external_tool_activation_packet_status_record_hash' => $sourceRecordHash,
        ]);
        $receiptInput = [
            'tool_operating_cell_id' => $cellId,
            'company_id' => $companyId,
            'flow_id' => $flowId,
            'capability_id' => $capabilityId,
            'connector_id' => $connectorId,
            'activation_packet_id' => $activationPacketId,
            'source_record_hash' => $sourceRecordHash,
        ];
        $requiredSurfaces = array_values((array) ($capability['required_surfaces'] ?? []));
        $referenceConnectors = array_values((array) ($capability['reference_connectors'] ?? []));
        $requiredSources = array_values((array) ($capability['required_sources'] ?? []));
        $referencePatterns = array_values((array) ($capability['reference_patterns'] ?? []));
        $complianceControls = array_values((array) ($capability['compliance_controls'] ?? []));

        $toolCatalog = [
            'schema' => 'atlas.ai.company.vertical_tool_operating_catalog.v1',
            'capability_id' => $capabilityId,
            'domain_family' => (string) ($capability['domain_family'] ?? $companyId.'_operations'),
            'tools' => array_values(array_unique(array_merge(
                $requiredSurfaces,
                $referenceConnectors,
                ['evidence_ledger', 'operator_console', 'replay_harness'],
            ))),
            'least_privilege_scopes' => array_values((array) ($capability['least_privilege_scopes'] ?? ['read_metadata'])),
            'write_tools_enabled' => false,
            'paid_or_mutating_modes_enabled' => false,
        ];
        $toolCatalog['tool_catalog_hash'] = MissionCanonicalHash::sha256($toolCatalog + $receiptInput);

        $domainDataContract = [
            'schema' => 'atlas.ai.company.vertical_tool_domain_data_contract.v1',
            'required_sources' => array_values(array_unique(array_merge($requiredSources, ['activation_packet', 'evidence_ledger']))),
            'reference_patterns' => $referencePatterns,
            'source_lineage_required' => true,
            'freshness_slo_required' => true,
            'unsupported_claim_blocked' => true,
            'private_data_export_allowed' => false,
        ];
        $domainDataContract['domain_data_contract_hash'] = MissionCanonicalHash::sha256($domainDataContract + $receiptInput);

        $operatingCell = [
            'schema' => 'atlas.ai.company.vertical_tool_operating_cell.v1',
            'workcell_roles' => [
                'domain_operator',
                'source_researcher',
                'tool_executor_readonly',
                'risk_compliance_reviewer',
                'operator_handoff_owner',
                'replay_auditor',
            ],
            'workflow' => [
                'prepare_context',
                'read_connector_metadata',
                'run_fixture_or_sandbox_probe',
                'assemble_work_product',
                'evaluate_replay',
                'emit_operator_handoff',
            ],
            'human_in_loop_required_for_external_effect' => true,
            'autonomous_external_action_allowed' => false,
        ];
        $operatingCell['operating_cell_hash'] = MissionCanonicalHash::sha256($operatingCell + $receiptInput);

        $sloContract = [
            'schema' => 'atlas.ai.company.vertical_tool_slo_contract.v1',
            'required_metrics' => [
                'tool_request_count',
                'tool_error_count',
                'latency_ms_p95',
                'source_lineage_count',
                'unsupported_claim_block_count',
                'external_effect_block_count',
                'replay_pass_rate',
                'operator_handoff_count',
                'receipt_emission_count',
            ],
            'fail_closed_on_missing_lineage' => true,
            'fail_closed_on_missing_receipt' => true,
            'alert_routes' => ['control_tower', 'evidence_ledger', 'operator_console'],
        ];
        $sloContract['slo_contract_hash'] = MissionCanonicalHash::sha256($sloContract + $receiptInput);

        $evalReplay = [
            'schema' => 'atlas.ai.company.vertical_tool_eval_replay.v1',
            'replay_assertions' => [
                'activation_packet_ready',
                'tool_catalog_bound',
                'domain_data_contract_bound',
                'source_lineage_preserved',
                'readonly_scope_preserved',
                'compliance_controls_applied',
                'external_effect_blocked',
                'operator_handoff_emitted',
                'receipt_chain_complete',
            ],
            'minimum_replay_pass_rate' => 1.0,
            'domain_compliance_controls' => $complianceControls,
            'regression_suite_required' => true,
        ];
        $evalReplay['eval_replay_hash'] = MissionCanonicalHash::sha256($evalReplay + $receiptInput);

        $operatorHandoff = [
            'schema' => 'atlas.ai.company.vertical_tool_operator_handoff.v1',
            'operator_acceptance_required' => true,
            'external_effect_requires_signed_mandate' => true,
            'second_reviewer_required_for_risk_domains' => true,
            'blocked_until_receipt_chain_complete' => true,
            'rollback_plan_required' => true,
        ];
        $operatorHandoff['operator_handoff_hash'] = MissionCanonicalHash::sha256($operatorHandoff + $receiptInput);

        $artifact = [
            'schema' => 'atlas.ai.company.vertical_tool_operating_runtime_artifact.v1',
            'tool_operating_cell_id' => $cellId,
            'company_id' => $companyId,
            'flow_id' => $flowId,
            'capability_id' => $capabilityId,
            'connector_id' => $connectorId,
            'activation_packet_id' => $activationPacketId,
            'run_context_id' => $runContextId,
            'runtime_mode' => 'vertical_tool_operating_cell_readonly_replay_operator_handoff',
            'source_lineage' => [
                'external_tool_activation_packet_status_record_hash' => $sourceRecordHash,
                'source_tool_run_id' => (string) ($packetRecord['tool_run_id'] ?? ''),
                'source_run_context_id' => (string) ($packetRecord['run_context_id'] ?? ''),
                'source_lineage_hash' => MissionCanonicalHash::sha256($receiptInput + ['lineage' => 'vertical_tool_operating_runtime']),
            ],
            'tool_catalog' => $toolCatalog,
            'domain_data_contract' => $domainDataContract,
            'operating_cell' => $operatingCell,
            'slo_contract' => $sloContract,
            'eval_replay' => $evalReplay,
            'operator_handoff' => $operatorHandoff,
            'receipt_chain' => [
                'vertical_tool_runtime_receipt_hash' => MissionCanonicalHash::sha256($receiptInput + ['receipt_type' => 'vertical_tool_runtime_receipt']),
                'tool_catalog_receipt_hash' => MissionCanonicalHash::sha256($receiptInput + ['receipt_type' => 'tool_catalog_receipt']),
                'domain_data_contract_receipt_hash' => MissionCanonicalHash::sha256($receiptInput + ['receipt_type' => 'domain_data_contract_receipt']),
                'operating_cell_receipt_hash' => MissionCanonicalHash::sha256($receiptInput + ['receipt_type' => 'operating_cell_receipt']),
                'slo_contract_receipt_hash' => MissionCanonicalHash::sha256($receiptInput + ['receipt_type' => 'slo_contract_receipt']),
                'eval_replay_receipt_hash' => MissionCanonicalHash::sha256($receiptInput + ['receipt_type' => 'eval_replay_receipt']),
                'operator_handoff_receipt_hash' => MissionCanonicalHash::sha256($receiptInput + ['receipt_type' => 'operator_handoff_receipt']),
            ],
            'external_execution_allowed' => false,
            'external_side_effects_enabled' => false,
            'generated_at' => now()->toJSON(),
        ];
        $artifact['vertical_tool_operating_runtime_artifact_hash'] = MissionCanonicalHash::sha256($artifact);

        return $artifact;
    }

    /**
     * @return array<string,mixed>
     */
    public function verticalToolOperatingRuntimePolicy(): array
    {
        return [
            'vertical_tool_operating_runtime_is_not_external_execution_authority' => true,
            'calendar_wait_blocker_enabled' => false,
            'external_execution_allowed' => false,
            'external_side_effects_enabled' => false,
            'operator_signed_scope_required_for_external_effect' => true,
            'runtime_mode' => 'vertical_tool_operating_cell_readonly_replay_operator_handoff',
            'required_runtime_evidence' => [
                'atlas_tool_run',
                'vertical_tool_operating_runtime_artifact',
                'tool_catalog',
                'domain_data_contract',
                'operating_cell',
                'slo_contract',
                'eval_replay',
                'operator_handoff',
                'vertical_tool_runtime_receipt',
                'tool_catalog_receipt',
                'domain_data_contract_receipt',
                'slo_contract_receipt',
                'eval_replay_receipt',
                'operator_handoff_receipt',
                'audit_trail',
                'rollback_plan',
            ],
            'blocked_operations' => [
                'skip_activation_packet',
                'skip_tool_catalog',
                'skip_domain_data_contract',
                'skip_eval_replay',
                'skip_operator_handoff',
                'claim_without_source_reference',
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
        ];
    }
}
