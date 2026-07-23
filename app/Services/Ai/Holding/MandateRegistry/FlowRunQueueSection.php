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
class FlowRunQueueSection
{
    public function __construct(private readonly MandateRegistryHub $hub) {}

    /**
     * @return array<string,mixed>
     */
    public function registerFlowRunQueue(?string $companyId = null): array
    {
        $cockpit = $this->hub->companyCockpit->activationCockpit($companyId);
        $records = [];

        foreach ((array) ($cockpit['companies'] ?? []) as $company) {
            foreach ((array) ($company['flow_backlog'] ?? []) as $flow) {
                $records[] = $this->persistFlowRunQueueItem((array) $flow);
            }
        }

        $payload = [
            'ok' => (bool) ($cockpit['ok'] ?? false) && $records !== [],
            'schema' => ExternalActionMandateRegistryService::FLOW_RUN_QUEUE_REGISTRY_SCHEMA,
            'status' => $records === [] ? 'empty_queue' : 'queued_for_internal_execution',
            'generated_at' => now()->toJSON(),
            'source_activation_cockpit_hash' => $cockpit['activation_cockpit_hash'] ?? null,
            'summary' => $this->flowRunQueueSummary($records),
            'records' => $records,
            'queue_policy' => [
                'mode' => 'durable_internal_flow_execution_queue',
                'external_execution_allowed' => false,
                'side_effect_boundary' => 'internal_queue_receipts_execution_receipts_and_dlq_state_only',
                'blocked_operations' => ['write', 'publish', 'spend', 'trade', 'deploy', 'delete', 'offensive_security', 'admin'],
                'execute_requires' => ['connector_activation_probe_green_or_no_connector_scope', 'work_packages_not_blocked_by_governance', 'policy_boundary_green'],
            ],
        ];
        $payload['flow_run_queue_registry_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function executeFlowRunQueue(?string $companyId = null, ?string $flowId = null): array
    {
        $items = $this->flowRunQueueQuery($companyId, $flowId)
            ->orderByDesc('priority_score')
            ->orderBy('company_id')
            ->orderBy('flow_id')
            ->get()
            ->map(fn (AiHoldingEnterpriseFlowRunQueueItem $item): array => $this->executeFlowRunQueueItem($item))
            ->all();

        $payload = [
            'ok' => $items !== [],
            'schema' => ExternalActionMandateRegistryService::FLOW_RUN_QUEUE_EXECUTION_SCHEMA,
            'status' => $items === [] ? 'empty_queue' : 'flow_queue_execution_cycle_completed_external_execution_blocked',
            'generated_at' => now()->toJSON(),
            'summary' => $this->flowRunQueueSummary($items),
            'records' => $items,
            'execution_policy' => [
                'mode' => 'internal_supervised_flow_queue_execution',
                'external_execution_allowed' => false,
                'external_side_effects_enabled' => false,
                'dlq_replay_requires' => ['fix_connector_probe', 'complete_governance_pack', 'operator_review'],
            ],
        ];
        $payload['flow_run_queue_execution_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function replayFlowRunQueue(?string $companyId = null, ?string $flowId = null): array
    {
        $items = $this->flowRunQueueQuery($companyId, $flowId)
            ->where('status', 'dlq_blocked_internal_execution')
            ->orderByDesc('priority_score')
            ->orderBy('company_id')
            ->orderBy('flow_id')
            ->get()
            ->map(fn (AiHoldingEnterpriseFlowRunQueueItem $item): array => $this->executeFlowRunQueueItem($item, true))
            ->all();

        $payload = [
            'ok' => $items !== [],
            'schema' => ExternalActionMandateRegistryService::FLOW_RUN_QUEUE_REPLAY_SCHEMA,
            'status' => $items === [] ? 'empty_dlq' : 'dlq_replay_cycle_completed_external_execution_blocked',
            'generated_at' => now()->toJSON(),
            'summary' => $this->flowRunQueueSummary($items),
            'records' => $items,
            'replay_policy' => [
                'mode' => 'durable_dlq_replay_after_resolution',
                'external_execution_allowed' => false,
                'external_side_effects_enabled' => false,
                'replay_scope' => 'dlq_blocked_internal_execution_only',
                'resolution_signals_checked' => [
                    'connector_activation_probe_green_or_no_connector_scope',
                    'blocked_work_package_count_zero',
                    'policy_boundary_green',
                ],
                'still_blocked_next_actions' => ['resolve_backlog_item', 'run_connector_probe', 'operator_review'],
            ],
        ];
        $payload['flow_run_queue_replay_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function flowRunQueueStatus(?string $companyId = null): array
    {
        $records = $this->flowRunQueueQuery($companyId, null)
            ->orderBy('company_id')
            ->orderBy('flow_id')
            ->get()
            ->map(fn (AiHoldingEnterpriseFlowRunQueueItem $item): array => $this->flowRunQueuePayload($item))
            ->all();

        $companyRows = [];
        foreach (collect($records)->groupBy('company_id') as $company => $rows) {
            $items = $rows->values()->all();
            $companyRows[] = [
                'company_id' => (string) $company,
                'flow_count' => count($items),
                'completed_count' => count(array_filter($items, static fn (array $item): bool => $item['status'] === 'completed_internal_flow_execution')),
                'dlq_count' => count(array_filter($items, static fn (array $item): bool => $item['status'] === 'dlq_blocked_internal_execution')),
                'queued_count' => count(array_filter($items, static fn (array $item): bool => $item['status'] === 'queued_for_internal_execution')),
                'status_counts' => $this->hub->countsByKey($items, 'status'),
                'external_execution_allowed_count' => 0,
            ];
        }

        $payload = [
            'ok' => true,
            'schema' => ExternalActionMandateRegistryService::FLOW_RUN_QUEUE_STATUS_SCHEMA,
            'status' => $records === [] ? 'empty_queue' : 'flow_run_queue_external_execution_blocked',
            'generated_at' => now()->toJSON(),
            'summary' => $this->flowRunQueueSummary($records),
            'companies' => $companyRows,
            'records' => $records,
            'policy' => [
                'external_execution_allowed' => false,
                'completed_internal_flow_does_not_enable_external_execution' => true,
                'dlq_replay_requires_operator_or_owner_attestation' => true,
            ],
        ];
        $payload['flow_run_queue_status_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function registerFlowOperationsRunbooks(?string $companyId = null): array
    {
        $queueItems = $this->flowRunQueueQuery($companyId, null)
            ->orderBy('company_id')
            ->orderBy('flow_id')
            ->get();

        if ($queueItems->isEmpty()) {
            $this->registerFlowRunQueue($companyId);
            $queueItems = $this->flowRunQueueQuery($companyId, null)
                ->orderBy('company_id')
                ->orderBy('flow_id')
                ->get();
        }

        $records = $queueItems
            ->map(fn (AiHoldingEnterpriseFlowRunQueueItem $item): array => $this->persistFlowOperationsRunbook($item))
            ->all();

        $payload = [
            'ok' => $records !== [],
            'schema' => ExternalActionMandateRegistryService::FLOW_OPERATIONS_RUNBOOK_REGISTRY_SCHEMA,
            'status' => $records === [] ? 'empty_operations_runbook_registry' : 'registered_needs_operations_drill',
            'generated_at' => now()->toJSON(),
            'summary' => $this->flowOperationsRunbookSummary($records),
            'records' => $records,
            'registry_policy' => [
                'mode' => 'persistent_flow_slo_incident_reconciliation_runbooks',
                'external_execution_allowed' => false,
                'external_side_effects_enabled' => false,
                'required_before_supervised_external_handoff' => [
                    'slo_contract_drilled',
                    'incident_route_drilled',
                    'reconciliation_contract_drilled',
                    'promotion_gates_green',
                ],
                'pattern_sources' => [
                    'openai_agents_handoffs_guardrails_tracing',
                    'langgraph_checkpoint_resume_human_in_the_loop',
                    'sre_incident_runbook_per_alert_or_flow',
                ],
            ],
        ];
        $payload['flow_operations_runbook_registry_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function drillFlowOperationsRunbooks(?string $companyId = null, ?string $flowId = null): array
    {
        $records = $this->flowOperationsRunbookQuery($companyId, $flowId)
            ->orderBy('company_id')
            ->orderBy('flow_id')
            ->get()
            ->map(fn (AiHoldingEnterpriseFlowOperationsRunbook $runbook): array => $this->drillFlowOperationsRunbook($runbook))
            ->all();

        $payload = [
            'ok' => $records !== [],
            'schema' => ExternalActionMandateRegistryService::FLOW_OPERATIONS_RUNBOOK_DRILL_SCHEMA,
            'status' => $records === [] ? 'empty_operations_runbook_drill_scope' : 'operations_runbook_drill_completed_external_execution_blocked',
            'generated_at' => now()->toJSON(),
            'summary' => $this->flowOperationsRunbookSummary($records),
            'records' => $records,
            'drill_policy' => [
                'mode' => 'internal_slo_incident_reconciliation_dress_rehearsal',
                'external_execution_allowed' => false,
                'external_side_effects_enabled' => false,
                'drill_modes' => ['slo_contract_check', 'incident_route_check', 'reconciliation_check', 'promotion_gate_check'],
                'blocked_operations' => ['page_real_oncall', 'write_production', 'publish', 'spend', 'trade', 'deploy', 'delete', 'admin'],
            ],
        ];
        $payload['flow_operations_runbook_drill_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function flowOperationsRunbookStatus(?string $companyId = null): array
    {
        $records = $this->flowOperationsRunbookQuery($companyId, null)
            ->orderBy('company_id')
            ->orderBy('flow_id')
            ->get()
            ->map(fn (AiHoldingEnterpriseFlowOperationsRunbook $runbook): array => $this->flowOperationsRunbookPayload($runbook))
            ->all();

        $companyRows = [];
        foreach (collect($records)->groupBy('company_id') as $company => $rows) {
            $items = $rows->values()->all();
            $companyRows[] = [
                'company_id' => (string) $company,
                'flow_count' => count($items),
                'operations_green_count' => count(array_filter($items, static fn (array $item): bool => $item['status'] === 'operations_runbook_green_external_blocked')),
                'status_counts' => $this->hub->countsByKey($items, 'status'),
                'external_execution_allowed_count' => 0,
                'external_side_effects_enabled_count' => 0,
            ];
        }

        $payload = [
            'ok' => true,
            'schema' => ExternalActionMandateRegistryService::FLOW_OPERATIONS_RUNBOOK_STATUS_SCHEMA,
            'status' => $records === [] ? 'empty_operations_runbook_registry' : 'flow_operations_runbooks_external_execution_blocked',
            'generated_at' => now()->toJSON(),
            'summary' => $this->flowOperationsRunbookSummary($records),
            'companies' => $companyRows,
            'records' => $records,
            'policy' => [
                'external_execution_allowed' => false,
                'operations_green_does_not_enable_external_execution' => true,
                'production_promotion_requires_operator_signed_mandate' => true,
            ],
        ];
        $payload['flow_operations_runbook_status_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @param array<string,mixed> $flow
     * @return array<string,mixed>
     */
    public function persistFlowRunQueueItem(array $flow): array
    {
        $companyId = (string) ($flow['company_id'] ?? 'unknown');
        $flowId = (string) ($flow['flow_id'] ?? 'unknown');
        $connectorRows = $this->hub->connectorActivationRows($companyId, $flowId);
        $operatingPackage = $this->hub->flowOperatingPackage($companyId, $flowId);
        $workloadTemplate = $this->hub->flowWorkloadAgentTemplate($companyId, $flowId);
        $durableEnvelope = $this->flowQueueDurableExecutionEnvelope($companyId, $flowId, $workloadTemplate, $operatingPackage, 'queue_register');
        $packageAttestation = $this->flowQueueOperatingPackageAttestation($companyId, $flowId, $operatingPackage, 'queue_register');
        $backlogRows = AiHoldingActivationBacklogItem::query()
            ->where('company_id', $companyId)
            ->where('flow_id', $flowId)
            ->get();
        $queueReceipt = [
            'schema' => 'atlas.ai.holding.enterprise_flow_queue_receipt.v1',
            'company_id' => $companyId,
            'flow_id' => $flowId,
            'activation_stage' => (string) ($flow['activation_stage'] ?? 'unknown'),
            'operating_package_hash' => (string) ($operatingPackage['package_hash'] ?? ''),
            'minimum_replay_cases_before_shadow' => (int) data_get($operatingPackage, 'quality_replay_cell.minimum_cases_before_shadow', 0),
            'operating_package_attestation_hash' => (string) ($packageAttestation['attestation_hash'] ?? ''),
            'durable_execution_envelope_hash' => (string) ($durableEnvelope['envelope_hash'] ?? ''),
            'durable_execution_envelope' => $durableEnvelope,
            'checkpoint_resume_bound' => (bool) data_get($durableEnvelope, 'checkpoint_contract.resume_token_required', false),
            'human_in_loop_bound' => (bool) data_get($durableEnvelope, 'human_in_loop_contract.operator_checkpoint_required', false),
            'trace_receipt_bound' => (bool) data_get($durableEnvelope, 'trace_contract.receipt_hash_required', false),
            'surface_binding_bound' => (bool) data_get($durableEnvelope, 'surface_binding.external_execution_allowed', true) === false,
            'fixture_smoke_bound' => (bool) data_get($durableEnvelope, 'fixture_smoke_contract.external_effects_allowed_during_smoke', true) === false,
            'connector_activation_count' => count($connectorRows),
            'work_package_count' => $backlogRows->count(),
            'external_execution_allowed' => false,
            'generated_at' => now()->toJSON(),
        ];
        $queueReceipt['receipt_hash'] = MissionCanonicalHash::sha256($queueReceipt);

        $record = AiHoldingEnterpriseFlowRunQueueItem::query()->updateOrCreate(
            [
                'company_id' => $companyId,
                'flow_id' => $flowId,
            ],
            [
                'status' => 'queued_for_internal_execution',
                'priority_score' => (int) ($flow['activation_priority_score'] ?? 0),
                'activation_stage' => (string) ($flow['activation_stage'] ?? 'unknown'),
                'mandate_packet_hash' => (string) ($flow['mandate_packet_hash'] ?? '') ?: null,
                'operating_package_hash' => (string) ($operatingPackage['package_hash'] ?? '') ?: null,
                'operating_package_json' => $operatingPackage,
                'replay_contract_json' => (array) ($operatingPackage['quality_replay_cell'] ?? []),
                'operating_package_attestations_json' => [$packageAttestation],
                'connector_activation_count' => count($connectorRows),
                'connector_probe_green_count' => count(array_filter($connectorRows, static fn (array $row): bool => (bool) $row['sandbox_probe_green'])),
                'work_package_count' => $backlogRows->count(),
                'completed_work_package_count' => $backlogRows->filter(static fn (AiHoldingActivationBacklogItem $item): bool => str_starts_with((string) $item->status, 'completed'))->count(),
                'blocked_work_package_count' => $backlogRows->filter(static fn (AiHoldingActivationBacklogItem $item): bool => str_starts_with((string) $item->status, 'blocked'))->count(),
                'attempt_count' => 0,
                'queue_receipts_json' => [$queueReceipt],
                'execution_receipts_json' => [],
                'last_execution_receipt_hash' => null,
                'dlq_reason' => null,
                'external_execution_allowed' => false,
                'external_side_effects_enabled' => false,
                'queued_at' => now(),
                'leased_at' => null,
                'last_executed_at' => null,
                'completed_at' => null,
                'dlq_at' => null,
            ],
        );

        return $this->flowRunQueuePayload($record);
    }

    /**
     * @return array<string,mixed>
     */
    public function executeFlowRunQueueItem(AiHoldingEnterpriseFlowRunQueueItem $item, bool $replay = false): array
    {
        $connectorRows = $this->hub->connectorActivationRows((string) $item->company_id, (string) $item->flow_id);
        $operatingPackage = (array) $item->operating_package_json;
        if ($operatingPackage === []) {
            $operatingPackage = $this->hub->flowOperatingPackage((string) $item->company_id, (string) $item->flow_id);
        }
        $workloadTemplate = $this->hub->flowWorkloadAgentTemplate((string) $item->company_id, (string) $item->flow_id);
        $durableEnvelope = $this->flowQueueDurableExecutionEnvelope(
            (string) $item->company_id,
            (string) $item->flow_id,
            $workloadTemplate,
            $operatingPackage,
            $replay ? 'dlq_replay' : 'queue_execute',
        );
        $packageAttestation = $this->flowQueueOperatingPackageAttestation(
            (string) $item->company_id,
            (string) $item->flow_id,
            $operatingPackage,
            $replay ? 'dlq_replay' : 'queue_execute',
        );
        $connectorGreen = count($connectorRows) === 0 || count(array_filter($connectorRows, static fn (array $row): bool => (bool) $row['sandbox_probe_green'])) === count($connectorRows);
        $backlogRows = AiHoldingActivationBacklogItem::query()
            ->where('company_id', $item->company_id)
            ->where('flow_id', $item->flow_id)
            ->get();
        $blockedWorkPackages = $backlogRows->filter(static fn (AiHoldingActivationBacklogItem $row): bool => str_starts_with((string) $row->status, 'blocked'))->count();
        $dlqReason = null;
        if (! $connectorGreen) {
            $dlqReason = 'connector_activation_probe_not_green';
        } elseif ($blockedWorkPackages > 0) {
            $dlqReason = 'blocked_work_packages_require_operator_or_owner_resolution';
        }

        $status = $dlqReason === null ? 'completed_internal_flow_execution' : 'dlq_blocked_internal_execution';
        $attempt = (int) $item->attempt_count + 1;
        $receipt = [
            'schema' => 'atlas.ai.holding.enterprise_flow_queue_execution_receipt.v1',
            'company_id' => (string) $item->company_id,
            'flow_id' => (string) $item->flow_id,
            'attempt' => $attempt,
            'mode' => $replay ? 'dlq_replay' : 'initial_or_direct_execution',
            'status' => $status,
            'operating_package_hash' => (string) ($operatingPackage['package_hash'] ?? $item->operating_package_hash ?? ''),
            'operating_package_attestation_hash' => (string) ($packageAttestation['attestation_hash'] ?? ''),
            'durable_execution_envelope_hash' => (string) ($durableEnvelope['envelope_hash'] ?? ''),
            'durable_execution_envelope' => $durableEnvelope,
            'checkpoint_resume_bound' => (bool) data_get($durableEnvelope, 'checkpoint_contract.resume_token_required', false),
            'human_in_loop_bound' => (bool) data_get($durableEnvelope, 'human_in_loop_contract.operator_checkpoint_required', false),
            'trace_receipt_bound' => (bool) data_get($durableEnvelope, 'trace_contract.receipt_hash_required', false),
            'surface_binding_bound' => (bool) data_get($durableEnvelope, 'surface_binding.external_execution_allowed', true) === false,
            'fixture_smoke_bound' => (bool) data_get($durableEnvelope, 'fixture_smoke_contract.external_effects_allowed_during_smoke', true) === false,
            'minimum_replay_cases_before_shadow' => (int) data_get($operatingPackage, 'quality_replay_cell.minimum_cases_before_shadow', 0),
            'replay_contract_bound' => data_get($operatingPackage, 'quality_replay_cell.dataset_id') !== null,
            'connector_activation_count' => count($connectorRows),
            'connector_probe_green_count' => count(array_filter($connectorRows, static fn (array $row): bool => (bool) $row['sandbox_probe_green'])),
            'work_package_count' => $backlogRows->count(),
            'completed_work_package_count' => $backlogRows->filter(static fn (AiHoldingActivationBacklogItem $row): bool => str_starts_with((string) $row->status, 'completed'))->count(),
            'blocked_work_package_count' => $blockedWorkPackages,
            'dlq_reason' => $dlqReason,
            'external_execution_allowed' => false,
            'external_side_effects_enabled' => false,
            'generated_at' => now()->toJSON(),
        ];
        $receipt['receipt_hash'] = MissionCanonicalHash::sha256($receipt);
        $receipts = array_values((array) $item->execution_receipts_json);
        $receipts[] = $receipt;
        $packageAttestations = array_values((array) $item->operating_package_attestations_json);
        $packageAttestations[] = $packageAttestation;

        $item->forceFill([
            'status' => $status,
            'operating_package_hash' => (string) ($operatingPackage['package_hash'] ?? $item->operating_package_hash ?? '') ?: null,
            'operating_package_json' => $operatingPackage,
            'replay_contract_json' => (array) ($operatingPackage['quality_replay_cell'] ?? []),
            'operating_package_attestations_json' => $packageAttestations,
            'connector_activation_count' => count($connectorRows),
            'connector_probe_green_count' => (int) $receipt['connector_probe_green_count'],
            'work_package_count' => $backlogRows->count(),
            'completed_work_package_count' => $backlogRows->filter(static fn (AiHoldingActivationBacklogItem $row): bool => str_starts_with((string) $row->status, 'completed'))->count(),
            'blocked_work_package_count' => $blockedWorkPackages,
            'attempt_count' => $attempt,
            'execution_receipts_json' => $receipts,
            'last_execution_receipt_hash' => (string) $receipt['receipt_hash'],
            'dlq_reason' => $dlqReason,
            'external_execution_allowed' => false,
            'external_side_effects_enabled' => false,
            'leased_at' => now(),
            'last_executed_at' => now(),
            'completed_at' => $dlqReason === null ? now() : null,
            'dlq_at' => $dlqReason !== null ? now() : null,
        ])->save();

        return $this->flowRunQueuePayload($item->refresh());
    }

    /**
     * @param array<string,mixed> $operatingPackage
     * @return array<string,mixed>
     */
    public function flowQueueOperatingPackageAttestation(string $companyId, string $flowId, array $operatingPackage, string $mode): array
    {
        $attestation = [
            'schema' => 'atlas.ai.holding.enterprise_flow_queue_operating_package_attestation.v1',
            'company_id' => $companyId,
            'flow_id' => $flowId,
            'mode' => $mode,
            'package_present' => $operatingPackage !== [],
            'package_id' => (string) ($operatingPackage['package_id'] ?? ''),
            'package_hash' => (string) ($operatingPackage['package_hash'] ?? ''),
            'premium_template_id' => (string) ($operatingPackage['premium_template_id'] ?? ''),
            'minimum_replay_cases_before_shadow' => (int) data_get($operatingPackage, 'quality_replay_cell.minimum_cases_before_shadow', 0),
            'replay_contract_bound' => data_get($operatingPackage, 'quality_replay_cell.dataset_id') !== null,
            'workbench_binding_count' => count((array) data_get($operatingPackage, 'tool_and_data_cell.connector_workbenches', [])),
            'runbook_drill_required_before_supervised_mode' => (bool) data_get($operatingPackage, 'operations_cell.runbook_drill_required_before_supervised_mode', false),
            'post_run_reconciliation_required' => (bool) data_get($operatingPackage, 'operations_cell.post_run_reconciliation_required', false),
            'calendar_wait_blocker_enabled' => (int) ($operatingPackage['buildout_wait_days_required'] ?? 30) > 0,
            'external_execution_allowed' => false,
            'external_side_effects_enabled' => false,
            'generated_at' => now()->toJSON(),
        ];
        $attestation['attestation_hash'] = MissionCanonicalHash::sha256($attestation);

        return $attestation;
    }

    /**
     * @param array<string,mixed> $workloadTemplate
     * @param array<string,mixed> $operatingPackage
     * @return array<string,mixed>
     */
    public function flowQueueDurableExecutionEnvelope(string $companyId, string $flowId, array $workloadTemplate, array $operatingPackage, string $mode): array
    {
        $distributionPackage = (array) ($workloadTemplate['distribution_package'] ?? []);
        $surfaceBinding = (array) ($distributionPackage['execution_surface_bindings'] ?? []);
        $fixtureSmokeContract = (array) ($distributionPackage['fixture_smoke_contract'] ?? []);
        $toolPermissionMatrix = array_values((array) data_get($distributionPackage, 'connector_permission_manifest.tool_permission_matrix', []));

        $envelope = [
            'schema' => 'atlas.ai.holding.enterprise_flow_queue_durable_execution_envelope.v1',
            'company_id' => $companyId,
            'flow_id' => $flowId,
            'mode' => $mode,
            'source_patterns' => [
                'openai_agents_sdk_guardrails_handoffs_tracing',
                'langgraph_checkpoint_human_in_the_loop',
                'temporal_durable_execution',
                'model_context_protocol_tool_connectors',
            ],
            'checkpoint_contract' => [
                'engine' => 'durable_checkpointed_graph',
                'state_store' => 'company_scoped_flow_state',
                'idempotency_key' => hash('sha256', $companyId.'|'.$flowId.'|'.$mode.'|idempotency'),
                'resume_token_required' => true,
                'resume_from_last_green_checkpoint' => true,
                'checkpoint_hash' => hash('sha256', $companyId.'|'.$flowId.'|'.$mode.'|checkpoint_contract'),
            ],
            'human_in_loop_contract' => [
                'operator_checkpoint_required' => true,
                'approval_surface' => (string) ($surfaceBinding['operator_review_surface'] ?? 'operator_review_queue'),
                'pause_before' => ['external_write', 'external_publish', 'real_spend', 'trade', 'deploy', 'delete', 'security_action', 'customer_commitment'],
                'resume_mode' => 'resume_from_signed_checkpoint',
                'human_in_loop_hash' => hash('sha256', $companyId.'|'.$flowId.'|'.$mode.'|human_in_loop_contract'),
            ],
            'trace_contract' => [
                'spans' => ['flow_received', 'template_loaded', 'skill_loaded', 'connector_called', 'subagent_called', 'artifact_created', 'policy_gate_evaluated', 'operator_checkpoint'],
                'tool_call_receipts_required' => true,
                'handoff_receipts_required' => true,
                'receipt_hash_required' => true,
                'trace_export' => 'evidence_ledger.flow_run_queue_trace',
                'trace_hash' => hash('sha256', $companyId.'|'.$flowId.'|'.$mode.'|trace_contract'),
            ],
            'surface_binding' => [
                'cli_action' => (string) ($surfaceBinding['cli_action'] ?? ''),
                'mcp_tool' => (string) ($surfaceBinding['mcp_tool'] ?? ''),
                'desktop_panel' => (string) ($surfaceBinding['desktop_panel'] ?? ''),
                'run_queue_contract' => (string) ($surfaceBinding['run_queue_contract'] ?? 'enterprise_flow_run_queue_item.v1'),
                'external_execution_allowed' => false,
                'surface_binding_hash' => (string) ($surfaceBinding['surface_binding_hash'] ?? hash('sha256', $companyId.'|'.$flowId.'|fallback_surface_binding')),
            ],
            'tool_permission_matrix' => $toolPermissionMatrix,
            'fixture_smoke_contract' => $fixtureSmokeContract,
            'operating_package_hash' => (string) ($operatingPackage['package_hash'] ?? ''),
            'template_hash' => (string) ($workloadTemplate['template_hash'] ?? ''),
            'external_execution_allowed' => false,
            'external_side_effects_enabled' => false,
            'generated_at' => now()->toJSON(),
        ];
        $envelope['envelope_hash'] = MissionCanonicalHash::sha256($envelope);

        return $envelope;
    }

    public function flowRunQueueQuery(?string $companyId, ?string $flowId)
    {
        $wantedCompany = $companyId !== null && trim($companyId) !== '' ? trim($companyId) : null;
        $wantedFlow = $flowId !== null && trim($flowId) !== '' ? trim($flowId) : null;

        return AiHoldingEnterpriseFlowRunQueueItem::query()
            ->when($wantedCompany !== null, static fn ($query) => $query->where('company_id', $wantedCompany))
            ->when($wantedFlow !== null, static fn ($query) => $query->where('flow_id', $wantedFlow));
    }

    /**
     * @param list<array<string,mixed>> $records
     * @return array<string,mixed>
     */
    public function flowRunQueueSummary(array $records): array
    {
        return [
            'company_count' => count(array_unique(array_map(static fn (array $record): string => (string) $record['company_id'], $records))),
            'flow_count' => count($records),
            'queued_count' => count(array_filter($records, static fn (array $record): bool => $record['status'] === 'queued_for_internal_execution')),
            'completed_count' => count(array_filter($records, static fn (array $record): bool => $record['status'] === 'completed_internal_flow_execution')),
            'dlq_count' => count(array_filter($records, static fn (array $record): bool => $record['status'] === 'dlq_blocked_internal_execution')),
            'queue_receipt_count' => array_sum(array_map(static fn (array $record): int => (int) ($record['queue_receipt_count'] ?? 0), $records)),
            'execution_receipt_count' => array_sum(array_map(static fn (array $record): int => (int) ($record['execution_receipt_count'] ?? 0), $records)),
            'last_execution_receipt_bound_count' => count(array_filter($records, static fn (array $record): bool => strlen((string) ($record['last_execution_receipt_hash'] ?? '')) === 64)),
            'completed_at_bound_count' => count(array_filter($records, static fn (array $record): bool => (string) ($record['completed_at'] ?? '') !== '')),
            'dlq_reason_bound_count' => count(array_filter($records, static fn (array $record): bool => (string) ($record['dlq_reason'] ?? '') !== '')),
            'connector_activation_count' => array_sum(array_map(static fn (array $record): int => (int) $record['connector_activation_count'], $records)),
            'connector_probe_green_count' => array_sum(array_map(static fn (array $record): int => (int) $record['connector_probe_green_count'], $records)),
            'operating_package_bound_count' => count(array_filter($records, static fn (array $record): bool => (string) ($record['operating_package_hash'] ?? '') !== '')),
            'replay_contract_bound_count' => count(array_filter($records, static fn (array $record): bool => (bool) data_get($record, 'replay_contract_bound'))),
            'operating_package_attestation_count' => array_sum(array_map(static fn (array $record): int => (int) ($record['operating_package_attestation_count'] ?? 0), $records)),
            'durable_execution_envelope_bound_count' => count(array_filter($records, static fn (array $record): bool => strlen((string) data_get($record, 'last_execution_receipt.durable_execution_envelope_hash', data_get($record, 'last_queue_receipt.durable_execution_envelope_hash', ''))) === 64)),
            'checkpoint_resume_bound_count' => count(array_filter($records, static fn (array $record): bool => (bool) data_get($record, 'last_execution_receipt.checkpoint_resume_bound', data_get($record, 'last_queue_receipt.checkpoint_resume_bound', false)))),
            'human_in_loop_bound_count' => count(array_filter($records, static fn (array $record): bool => (bool) data_get($record, 'last_execution_receipt.human_in_loop_bound', data_get($record, 'last_queue_receipt.human_in_loop_bound', false)))),
            'trace_receipt_bound_count' => count(array_filter($records, static fn (array $record): bool => (bool) data_get($record, 'last_execution_receipt.trace_receipt_bound', data_get($record, 'last_queue_receipt.trace_receipt_bound', false)))),
            'surface_binding_bound_count' => count(array_filter($records, static fn (array $record): bool => (bool) data_get($record, 'last_execution_receipt.surface_binding_bound', data_get($record, 'last_queue_receipt.surface_binding_bound', false)))),
            'fixture_smoke_bound_count' => count(array_filter($records, static fn (array $record): bool => (bool) data_get($record, 'last_execution_receipt.fixture_smoke_bound', data_get($record, 'last_queue_receipt.fixture_smoke_bound', false)))),
            'external_execution_allowed_count' => 0,
            'external_side_effects_enabled_count' => 0,
        ];
    }

    public function flowOperationsRunbookQuery(?string $companyId, ?string $flowId)
    {
        $wantedCompany = $companyId !== null && trim($companyId) !== '' ? trim($companyId) : null;
        $wantedFlow = $flowId !== null && trim($flowId) !== '' ? trim($flowId) : null;

        return AiHoldingEnterpriseFlowOperationsRunbook::query()
            ->when($wantedCompany !== null, static fn ($query) => $query->where('company_id', $wantedCompany))
            ->when($wantedFlow !== null, static fn ($query) => $query->where('flow_id', $wantedFlow));
    }

    /**
     * @param list<array<string,mixed>> $records
     * @return array<string,mixed>
     */
    public function flowOperationsRunbookSummary(array $records): array
    {
        return [
            'company_count' => count(array_unique(array_map(static fn (array $record): string => (string) $record['company_id'], $records))),
            'flow_count' => count($records),
            'registered_count' => count(array_filter($records, static fn (array $record): bool => $record['status'] === 'registered_needs_operations_drill')),
            'operations_green_count' => count(array_filter($records, static fn (array $record): bool => $record['status'] === 'operations_runbook_green_external_blocked')),
            'slo_green_count' => count(array_filter($records, static fn (array $record): bool => (bool) $record['slo_green'])),
            'incident_route_green_count' => count(array_filter($records, static fn (array $record): bool => (bool) $record['incident_route_green'])),
            'reconciliation_green_count' => count(array_filter($records, static fn (array $record): bool => (bool) $record['reconciliation_green'])),
            'promotion_gate_green_count' => count(array_filter($records, static fn (array $record): bool => (bool) $record['promotion_gate_green'])),
            'slo_contract_bound_count' => count(array_filter($records, static fn (array $record): bool => strlen((string) data_get($record, 'slo_contract.slo_hash', '')) === 64)),
            'incident_route_bound_count' => count(array_filter($records, static fn (array $record): bool => strlen((string) data_get($record, 'incident_route.route_hash', '')) === 64)),
            'reconciliation_contract_bound_count' => count(array_filter($records, static fn (array $record): bool => strlen((string) data_get($record, 'reconciliation_contract.reconciliation_hash', '')) === 64)),
            'dashboard_binding_bound_count' => count(array_filter($records, static fn (array $record): bool => count((array) ($record['dashboard_bindings'] ?? [])) >= 4)),
            'drill_receipt_count' => array_sum(array_map(static fn (array $record): int => (int) ($record['drill_receipt_count'] ?? 0), $records)),
            'last_drill_receipt_bound_count' => count(array_filter($records, static fn (array $record): bool => strlen((string) ($record['last_drill_receipt_hash'] ?? '')) === 64)),
            'activated_at_bound_count' => count(array_filter($records, static fn (array $record): bool => (string) ($record['activated_at'] ?? '') !== '')),
            'operating_package_bound_count' => count(array_filter($records, static fn (array $record): bool => (string) ($record['operating_package_hash'] ?? '') !== '')),
            'replay_contract_bound_count' => count(array_filter($records, static fn (array $record): bool => (bool) data_get($record, 'replay_contract_bound'))),
            'operating_package_attestation_count' => array_sum(array_map(static fn (array $record): int => (int) ($record['operating_package_attestation_count'] ?? 0), $records)),
            'external_execution_allowed_count' => 0,
            'external_side_effects_enabled_count' => 0,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function persistFlowOperationsRunbook(AiHoldingEnterpriseFlowRunQueueItem $item): array
    {
        $companyId = (string) $item->company_id;
        $flowId = (string) $item->flow_id;
        $connectorRows = $this->hub->connectorActivationRows($companyId, $flowId);
        $operatingPackage = (array) $item->operating_package_json;
        if ($operatingPackage === []) {
            $operatingPackage = $this->hub->flowOperatingPackage($companyId, $flowId);
        }
        $packageAttestation = $this->flowQueueOperatingPackageAttestation($companyId, $flowId, $operatingPackage, 'operations_runbook_register');
        $sloContract = [
            'schema' => 'atlas.ai.holding.flow_operations_slo_contract.v1',
            'availability_target' => 'internal_supervised_run_success_rate>=0.97',
            'latency_target' => 'operator_visible_packet_under_10_minutes_for_normal_priority',
            'quality_target' => 'flow_evaluation_green_and_package_replay_contract_bound_required',
            'blocked_run_budget' => 'dlq_growth_requires_operator_review_and_package_replay_diff',
            'connector_count' => count($connectorRows),
            'source_operating_package_hash' => (string) ($operatingPackage['package_hash'] ?? ''),
            'minimum_replay_cases_before_shadow' => (int) data_get($operatingPackage, 'quality_replay_cell.minimum_cases_before_shadow', 0),
            'slo_hash' => hash('sha256', 'flow_slo|'.$companyId.'|'.$flowId.'|'.count($connectorRows)),
        ];
        $incidentRoute = [
            'schema' => 'atlas.ai.holding.flow_operations_incident_route.v1',
            'queue' => $companyId.'.'.$flowId.'.operations_exception_queue',
            'severity_ladder' => ['sev3_quality_warning', 'sev2_blocked_delivery', 'sev1_external_side_effect_risk'],
            'escalation_roles' => [$companyId.'.company_manager_agent', 'portfolio_governor', 'operator_for_sev1'],
            'required_packet' => $flowId.'.incident_or_exception_packet.v1',
            'route_hash' => hash('sha256', 'flow_incident_route|'.$companyId.'|'.$flowId),
        ];
        $reconciliation = [
            'schema' => 'atlas.ai.holding.flow_operations_reconciliation_contract.v1',
            'post_run_checks' => ['receipt_present', 'work_product_hash_present', 'policy_result_present', 'connector_probe_state_recorded'],
            'reconciliation_job' => $companyId.'.'.$flowId.'.post_run_reconciliation',
            'external_mutation_reconciliation_required_before_manual_handoff' => true,
            'post_run_reconciliation_required_by_package' => (bool) data_get($operatingPackage, 'operations_cell.post_run_reconciliation_required', false),
            'reconciliation_hash' => hash('sha256', 'flow_reconciliation|'.$companyId.'|'.$flowId),
        ];
        $promotionGates = [
            'queue_registered',
            'enterprise_flow_operating_package_bound',
            'replay_contract_bound',
            'connector_probe_green_or_no_connector_scope',
            'activation_backlog_no_blockers_for_internal_run',
            'slo_incident_reconciliation_drill_green',
            'operator_mandate_required_for_external_side_effects',
        ];
        $dashboardBindings = [
            $companyId.'.run_queue',
            $companyId.'.slo_capacity_board',
            $companyId.'.incident_board',
            'portfolio.operations_control_tower',
        ];
        $runbookHash = MissionCanonicalHash::sha256([
            'company_id' => $companyId,
            'flow_id' => $flowId,
            'slo' => $sloContract,
            'incident' => $incidentRoute,
            'reconciliation' => $reconciliation,
            'promotion_gates' => $promotionGates,
            'operating_package_hash' => (string) ($operatingPackage['package_hash'] ?? ''),
        ]);

        $record = AiHoldingEnterpriseFlowOperationsRunbook::query()->updateOrCreate(
            [
                'company_id' => $companyId,
                'flow_id' => $flowId,
            ],
            [
                'status' => 'registered_needs_operations_drill',
                'source_queue_item_id' => $item->id,
                'runbook_hash' => $runbookHash,
                'operating_package_hash' => (string) ($operatingPackage['package_hash'] ?? '') ?: null,
                'operating_package_json' => $operatingPackage,
                'replay_contract_json' => (array) ($operatingPackage['quality_replay_cell'] ?? []),
                'operating_package_attestations_json' => [$packageAttestation],
                'slo_contract_json' => $sloContract,
                'incident_route_json' => $incidentRoute,
                'reconciliation_contract_json' => $reconciliation,
                'promotion_gates_json' => $promotionGates,
                'dashboard_bindings_json' => $dashboardBindings,
                'drill_receipts_json' => [],
                'last_drill_receipt_hash' => null,
                'slo_green' => false,
                'incident_route_green' => false,
                'reconciliation_green' => false,
                'promotion_gate_green' => false,
                'external_execution_allowed' => false,
                'external_side_effects_enabled' => false,
                'registered_at' => now(),
                'last_drilled_at' => null,
                'activated_at' => null,
            ],
        );

        return $this->flowOperationsRunbookPayload($record);
    }

    /**
     * @return array<string,mixed>
     */
    public function drillFlowOperationsRunbook(AiHoldingEnterpriseFlowOperationsRunbook $runbook): array
    {
        $connectorRows = $this->hub->connectorActivationRows((string) $runbook->company_id, (string) $runbook->flow_id);
        $queueItem = $this->flowRunQueueQuery((string) $runbook->company_id, (string) $runbook->flow_id)->first();
        $operatingPackage = (array) $runbook->operating_package_json;
        $packageAttestation = $this->flowQueueOperatingPackageAttestation(
            (string) $runbook->company_id,
            (string) $runbook->flow_id,
            $operatingPackage,
            'operations_runbook_drill',
        );
        $checks = [
            'runbook_hash_present' => strlen((string) $runbook->runbook_hash) === 64,
            'operating_package_hash_present' => strlen((string) $runbook->operating_package_hash) === 64,
            'replay_contract_bound' => data_get($runbook->replay_contract_json, 'dataset_id') !== null,
            'minimum_replay_cases_25' => (int) data_get($runbook->replay_contract_json, 'minimum_cases_before_shadow', 0) >= 25,
            'calendar_wait_blocker_disabled' => ! (bool) ($packageAttestation['calendar_wait_blocker_enabled'] ?? true),
            'slo_contract_present' => data_get($runbook->slo_contract_json, 'slo_hash') !== null,
            'incident_route_present' => data_get($runbook->incident_route_json, 'route_hash') !== null,
            'reconciliation_contract_present' => data_get($runbook->reconciliation_contract_json, 'reconciliation_hash') !== null,
            'queue_binding_present' => $queueItem instanceof AiHoldingEnterpriseFlowRunQueueItem,
            'connector_probe_green_or_empty' => count($connectorRows) === 0
                || count(array_filter($connectorRows, static fn (array $row): bool => (bool) $row['sandbox_probe_green'])) === count($connectorRows),
            'external_side_effects_blocked' => ! (bool) $runbook->external_side_effects_enabled,
        ];
        $green = ! in_array(false, $checks, true);
        $receipt = [
            'schema' => 'atlas.ai.holding.flow_operations_runbook_drill_receipt.v1',
            'company_id' => (string) $runbook->company_id,
            'flow_id' => (string) $runbook->flow_id,
            'status' => $green ? 'green' : 'blocked',
            'checks' => $checks,
            'operating_package_hash' => (string) $runbook->operating_package_hash,
            'operating_package_attestation_hash' => (string) ($packageAttestation['attestation_hash'] ?? ''),
            'drill_modes_completed' => ['slo_contract_check', 'incident_route_check', 'reconciliation_check', 'promotion_gate_check'],
            'external_execution_allowed' => false,
            'external_side_effects_enabled' => false,
            'generated_at' => now()->toJSON(),
        ];
        $receipt['receipt_hash'] = MissionCanonicalHash::sha256($receipt);
        $receipts = array_values((array) $runbook->drill_receipts_json);
        $receipts[] = $receipt;
        $packageAttestations = array_values((array) $runbook->operating_package_attestations_json);
        $packageAttestations[] = $packageAttestation;

        $runbook->forceFill([
            'status' => $green ? 'operations_runbook_green_external_blocked' : 'operations_runbook_blocked_needs_operator_review',
            'drill_receipts_json' => $receipts,
            'operating_package_attestations_json' => $packageAttestations,
            'last_drill_receipt_hash' => (string) $receipt['receipt_hash'],
            'slo_green' => $green,
            'incident_route_green' => $green,
            'reconciliation_green' => $green,
            'promotion_gate_green' => $green,
            'external_execution_allowed' => false,
            'external_side_effects_enabled' => false,
            'last_drilled_at' => now(),
            'activated_at' => $green ? now() : null,
        ])->save();

        return $this->flowOperationsRunbookPayload($runbook->refresh());
    }

    /**
     * @return array<string,mixed>
     */
    public function flowRunQueuePayload(AiHoldingEnterpriseFlowRunQueueItem $item): array
    {
        $queueReceipts = array_values((array) $item->queue_receipts_json);
        $executionReceipts = array_values((array) $item->execution_receipts_json);

        return [
            'id' => (string) $item->id,
            'company_id' => (string) $item->company_id,
            'flow_id' => (string) $item->flow_id,
            'status' => (string) $item->status,
            'priority_score' => (int) $item->priority_score,
            'activation_stage' => (string) $item->activation_stage,
            'mandate_packet_hash' => $item->mandate_packet_hash,
            'operating_package_hash' => $item->operating_package_hash,
            'operating_package' => (array) $item->operating_package_json,
            'replay_contract' => (array) $item->replay_contract_json,
            'replay_contract_bound' => data_get((array) $item->replay_contract_json, 'dataset_id') !== null,
            'operating_package_attestation_count' => count((array) $item->operating_package_attestations_json),
            'last_operating_package_attestation' => array_values((array) $item->operating_package_attestations_json) !== []
                ? array_values((array) $item->operating_package_attestations_json)[count((array) $item->operating_package_attestations_json) - 1]
                : null,
            'connector_activation_count' => (int) $item->connector_activation_count,
            'connector_probe_green_count' => (int) $item->connector_probe_green_count,
            'work_package_count' => (int) $item->work_package_count,
            'completed_work_package_count' => (int) $item->completed_work_package_count,
            'blocked_work_package_count' => (int) $item->blocked_work_package_count,
            'attempt_count' => (int) $item->attempt_count,
            'queue_receipt_count' => count($queueReceipts),
            'execution_receipt_count' => count($executionReceipts),
            'last_queue_receipt' => $queueReceipts !== [] ? $queueReceipts[count($queueReceipts) - 1] : null,
            'last_execution_receipt' => $executionReceipts !== [] ? $executionReceipts[count($executionReceipts) - 1] : null,
            'last_execution_receipt_hash' => $item->last_execution_receipt_hash,
            'dlq_reason' => $item->dlq_reason,
            'external_execution_allowed' => (bool) $item->external_execution_allowed,
            'external_side_effects_enabled' => (bool) $item->external_side_effects_enabled,
            'queued_at' => $item->queued_at?->toJSON(),
            'leased_at' => $item->leased_at?->toJSON(),
            'last_executed_at' => $item->last_executed_at?->toJSON(),
            'completed_at' => $item->completed_at?->toJSON(),
            'dlq_at' => $item->dlq_at?->toJSON(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function flowOperationsRunbookPayload(AiHoldingEnterpriseFlowOperationsRunbook $runbook): array
    {
        $drillReceipts = array_values((array) $runbook->drill_receipts_json);

        return [
            'id' => (string) $runbook->id,
            'company_id' => (string) $runbook->company_id,
            'flow_id' => (string) $runbook->flow_id,
            'status' => (string) $runbook->status,
            'source_queue_item_id' => $runbook->source_queue_item_id,
            'runbook_hash' => (string) $runbook->runbook_hash,
            'operating_package_hash' => $runbook->operating_package_hash,
            'operating_package' => (array) $runbook->operating_package_json,
            'replay_contract' => (array) $runbook->replay_contract_json,
            'replay_contract_bound' => data_get((array) $runbook->replay_contract_json, 'dataset_id') !== null,
            'operating_package_attestation_count' => count((array) $runbook->operating_package_attestations_json),
            'last_operating_package_attestation' => array_values((array) $runbook->operating_package_attestations_json) !== []
                ? array_values((array) $runbook->operating_package_attestations_json)[count((array) $runbook->operating_package_attestations_json) - 1]
                : null,
            'slo_contract' => (array) $runbook->slo_contract_json,
            'incident_route' => (array) $runbook->incident_route_json,
            'reconciliation_contract' => (array) $runbook->reconciliation_contract_json,
            'promotion_gates' => array_values((array) $runbook->promotion_gates_json),
            'dashboard_bindings' => array_values((array) $runbook->dashboard_bindings_json),
            'drill_receipt_count' => count($drillReceipts),
            'last_drill_receipt' => $drillReceipts !== [] ? $drillReceipts[count($drillReceipts) - 1] : null,
            'last_drill_receipt_hash' => $runbook->last_drill_receipt_hash,
            'slo_green' => (bool) $runbook->slo_green,
            'incident_route_green' => (bool) $runbook->incident_route_green,
            'reconciliation_green' => (bool) $runbook->reconciliation_green,
            'promotion_gate_green' => (bool) $runbook->promotion_gate_green,
            'external_execution_allowed' => (bool) $runbook->external_execution_allowed,
            'external_side_effects_enabled' => (bool) $runbook->external_side_effects_enabled,
            'registered_at' => $runbook->registered_at?->toJSON(),
            'last_drilled_at' => $runbook->last_drilled_at?->toJSON(),
            'activated_at' => $runbook->activated_at?->toJSON(),
        ];
    }
}
