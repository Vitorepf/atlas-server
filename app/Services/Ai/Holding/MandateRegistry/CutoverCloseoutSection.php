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
class CutoverCloseoutSection
{
    public function __construct(private readonly MandateRegistryHub $hub) {}

    /**
     * @return array<string,mixed>
     */
    public function registerExternalSupervisedCutoverManualHandoff(?string $workOrderId = null, ?string $companyId = null): array
    {
        $normalizedWorkOrderId = strtolower(trim((string) $workOrderId));
        $wantedCompany = $companyId !== null && trim($companyId) !== '' ? trim($companyId) : null;

        if ($normalizedWorkOrderId !== '' && ! preg_match('/\A[a-f0-9]{64}\z/', $normalizedWorkOrderId)) {
            return $this->externalSupervisedCutoverManualHandoffRejected('invalid_work_order_id', $normalizedWorkOrderId);
        }

        $promotion = $this->hub->cutoverWorkOrder->externalSupervisedCutoverRehearsalPromotionStatus($wantedCompany);
        $readyPackets = [];

        foreach ((array) ($promotion['companies'] ?? []) as $company) {
            foreach ((array) ($company['flow_rehearsal_promotion_packets'] ?? []) as $packet) {
                if ($normalizedWorkOrderId !== '' && (string) ($packet['work_order_id'] ?? '') !== $normalizedWorkOrderId) {
                    continue;
                }
                if ((bool) ($packet['manual_execution_packet_ready'] ?? false)) {
                    $readyPackets[] = $packet;
                }
            }
        }

        if ($readyPackets === []) {
            return $this->externalSupervisedCutoverManualHandoffRejected(
                $normalizedWorkOrderId !== '' ? 'manual_handoff_packet_not_ready_for_work_order' : 'no_manual_handoff_packets_ready',
                $normalizedWorkOrderId,
            );
        }

        $records = [];
        foreach ($readyPackets as $readyPacket) {
            $invocation = AiHoldingExternalCutoverRuntimeInvocation::query()
                ->where('invocation_id', (string) ($readyPacket['runtime_invocation_id'] ?? ''))
                ->first();

            if (! $invocation instanceof AiHoldingExternalCutoverRuntimeInvocation) {
                continue;
            }

            $handoffPacket = [
                'schema' => 'atlas.ai.company.external_supervised_cutover_manual_handoff_packet.v1',
                'handoff_id' => MissionCanonicalHash::sha256([
                    'work_order_id' => (string) $invocation->work_order_id,
                    'invocation_id' => (string) $invocation->invocation_id,
                    'source_rehearsal_promotion_packet_hash' => (string) ($readyPacket['external_supervised_cutover_rehearsal_promotion_packet_hash'] ?? ''),
                ]),
                'company_id' => (string) $invocation->company_id,
                'flow_id' => (string) $invocation->flow_id,
                'work_order_id' => (string) $invocation->work_order_id,
                'runtime_invocation_id' => (string) $invocation->invocation_id,
                'mode' => 'manual_operator_execution_handoff_only',
                'ready_for_operator_manual_execution_review' => true,
                'decision_receipt_required_before_any_external_effect' => true,
                'operator_runtime_contract' => (array) $invocation->operator_runtime_contract_json,
                'repository_toolchain_evidence' => (array) ($readyPacket['repository_toolchain_evidence'] ?? []),
                'execution_receipt_count' => (int) $invocation->execution_receipt_count,
                'last_execution_receipt_hash' => $invocation->last_execution_receipt_hash,
                'source_runtime_invocation_packet_hash' => (string) $invocation->runtime_invocation_packet_hash,
                'source_rehearsal_promotion_packet_hash' => (string) ($readyPacket['external_supervised_cutover_rehearsal_promotion_packet_hash'] ?? ''),
                'manual_closeout_required_after_operator_execution' => true,
                'reconciliation_sink_required' => true,
                'rollback_plan_required' => true,
                'external_execution_allowed_by_handoff_registry' => false,
                'external_side_effects_enabled_by_handoff_registry' => false,
                'blocked_operations' => array_values(array_unique(array_merge(
                    (array) ($readyPacket['blocked_operations'] ?? []),
                    ['auto_launch', 'unattended_cutover', 'external_write_without_decision_receipt', 'claim_external_result_without_receipt'],
                ))),
                'registered_at' => now()->toJSON(),
            ];
            $handoffPacket['manual_handoff_packet_hash'] = MissionCanonicalHash::sha256($handoffPacket);

            $invocation->forceFill([
                'status' => 'manual_handoff_packet_registered_external_launch_blocked',
                'manual_handoff_packet_json' => $handoffPacket,
                'manual_handoff_packet_hash' => (string) $handoffPacket['manual_handoff_packet_hash'],
                'manual_handoff_registered_at' => now(),
                'external_execution_allowed' => false,
                'external_side_effects_enabled' => false,
                'last_status_at' => now(),
            ])->save();

            $records[] = $this->hub->cutoverWorkOrder->externalSupervisedCutoverRuntimeInvocationPayload($invocation->refresh());
        }

        $payload = [
            'ok' => $records !== [],
            'schema' => ExternalActionMandateRegistryService::EXTERNAL_SUPERVISED_CUTOVER_MANUAL_HANDOFF_REGISTRY_SCHEMA,
            'status' => $records !== []
                ? 'external_supervised_cutover_manual_handoff_packets_registered_external_launch_blocked'
                : 'external_supervised_cutover_manual_handoff_registration_rejected',
            'generated_at' => now()->toJSON(),
            'summary' => [
                'manual_handoff_packet_count' => count($records),
                'external_execution_allowed_count' => 0,
                'external_side_effects_enabled_count' => 0,
            ],
            'records' => $records,
            'source_rehearsal_promotion_status_hash' => $promotion['external_supervised_cutover_rehearsal_promotion_status_hash'] ?? null,
            'policy' => [
                'manual_handoff_registry_is_not_execution_authority' => true,
                'decision_receipt_required_before_any_external_effect' => true,
                'operator_manual_execution_only' => true,
                'external_execution_allowed' => false,
                'external_side_effects_enabled' => false,
            ],
        ];
        $payload['external_supervised_cutover_manual_handoff_registry_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function externalSupervisedCutoverManualHandoffStatus(?string $companyId = null): array
    {
        $wantedCompany = $companyId !== null && trim($companyId) !== '' ? trim($companyId) : null;
        $records = AiHoldingExternalCutoverRuntimeInvocation::query()
            ->when($wantedCompany !== null, static fn ($query) => $query->where('company_id', $wantedCompany))
            ->whereNotNull('manual_handoff_packet_hash')
            ->orderBy('company_id')
            ->orderBy('flow_id')
            ->get()
            ->map(fn (AiHoldingExternalCutoverRuntimeInvocation $invocation): array => $this->hub->cutoverWorkOrder->externalSupervisedCutoverRuntimeInvocationPayload($invocation))
            ->all();

        $companyRows = [];
        foreach (collect($records)->groupBy('company_id') as $company => $companyRecords) {
            $rows = $companyRecords->values()->all();
            $companyRow = [
                'schema' => 'atlas.ai.company.external_supervised_cutover_manual_handoff_status.v1',
                'company_id' => (string) $company,
                'flow_count' => count($rows),
                'manual_handoff_packet_count' => count($rows),
                'decision_receipt_required_count' => count(array_filter(
                    $rows,
                    static fn (array $row): bool => (bool) data_get($row, 'manual_handoff_packet.decision_receipt_required_before_any_external_effect', false),
                )),
                'external_execution_allowed_count' => 0,
                'external_side_effects_enabled_count' => 0,
                'handoff_packets' => $rows,
            ];
            $companyRow['company_external_supervised_cutover_manual_handoff_status_hash'] = MissionCanonicalHash::sha256($companyRow);
            $companyRows[] = $companyRow;
        }

        $payload = [
            'ok' => true,
            'schema' => ExternalActionMandateRegistryService::EXTERNAL_SUPERVISED_CUTOVER_MANUAL_HANDOFF_STATUS_SCHEMA,
            'status' => $records === []
                ? 'empty_external_supervised_cutover_manual_handoff_registry'
                : 'external_supervised_cutover_manual_handoff_packets_registered_external_launch_blocked',
            'generated_at' => now()->toJSON(),
            'summary' => [
                'company_count' => count($companyRows),
                'flow_count' => count($records),
                'manual_handoff_packet_count' => count($records),
                'decision_receipt_required_count' => count(array_filter(
                    $records,
                    static fn (array $record): bool => (bool) data_get($record, 'manual_handoff_packet.decision_receipt_required_before_any_external_effect', false),
                )),
                'external_execution_allowed_count' => 0,
                'external_side_effects_enabled_count' => 0,
            ],
            'companies' => $companyRows,
            'policy' => [
                'manual_handoff_status_is_not_execution_authority' => true,
                'decision_receipt_required_before_any_external_effect' => true,
                'operator_manual_execution_only' => true,
                'external_execution_allowed' => false,
                'external_side_effects_enabled' => false,
                'blocked_operations' => ['auto_launch', 'unattended_cutover', 'external_write_without_decision_receipt', 'claim_external_result_without_receipt'],
            ],
        ];
        $payload['external_supervised_cutover_manual_handoff_status_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function bindExternalSupervisedCutoverManualCloseoutReceipt(
        ?string $workOrderId,
        ?string $receiptHash,
        ?string $receiptSource,
        ?string $operator,
        ?string $note = null,
    ): array {
        $normalizedWorkOrderId = strtolower(trim((string) $workOrderId));
        $normalizedReceiptHash = strtolower(trim((string) $receiptHash));
        $normalizedSource = trim((string) $receiptSource);
        $normalizedOperator = trim((string) $operator) !== '' ? trim((string) $operator) : 'atlas_operator';

        if (! preg_match('/\A[a-f0-9]{64}\z/', $normalizedWorkOrderId)) {
            return $this->externalSupervisedCutoverManualCloseoutRejected('invalid_work_order_id', $normalizedWorkOrderId);
        }
        if (! preg_match('/\A[a-f0-9]{64}\z/', $normalizedReceiptHash)) {
            return $this->externalSupervisedCutoverManualCloseoutRejected('invalid_receipt_hash', $normalizedWorkOrderId);
        }
        if ($normalizedSource === '' || in_array($normalizedSource, ['synthetic', 'fixture', 'fake'], true)) {
            return $this->externalSupervisedCutoverManualCloseoutRejected('invalid_receipt_source', $normalizedWorkOrderId);
        }

        $invocation = AiHoldingExternalCutoverRuntimeInvocation::query()
            ->where('work_order_id', $normalizedWorkOrderId)
            ->first();

        if (! $invocation instanceof AiHoldingExternalCutoverRuntimeInvocation) {
            return $this->externalSupervisedCutoverManualCloseoutRejected('runtime_invocation_not_found', $normalizedWorkOrderId);
        }
        if ($invocation->manual_handoff_packet_hash === null) {
            return $this->externalSupervisedCutoverManualCloseoutRejected('manual_handoff_packet_missing', $normalizedWorkOrderId);
        }

        $receipt = [
            'schema' => 'atlas.ai.company.external_supervised_cutover_manual_closeout_receipt.v1',
            'work_order_id' => (string) $invocation->work_order_id,
            'runtime_invocation_id' => (string) $invocation->invocation_id,
            'manual_handoff_packet_hash' => (string) $invocation->manual_handoff_packet_hash,
            'external_operator_receipt_hash' => $normalizedReceiptHash,
            'receipt_source' => $normalizedSource,
            'operator' => $normalizedOperator,
            'note' => $note !== null ? trim($note) : null,
            'manual_execution_claim_recorded' => true,
            'external_execution_performed_by_atlas' => false,
            'external_side_effects_performed_by_atlas' => false,
            'post_execution_reconciliation_bound' => true,
            'rollback_plan_remains_required_for_dispute' => true,
            'registered_at' => now()->toJSON(),
        ];
        $receipt['manual_closeout_receipt_hash'] = MissionCanonicalHash::sha256($receipt);

        $receipts = array_values((array) $invocation->manual_closeout_receipts_json);
        $receipts[] = $receipt;

        $invocation->forceFill([
            'status' => 'manual_closeout_receipt_bound_reconciliation_required_external_launch_blocked',
            'manual_closeout_receipts_json' => $receipts,
            'last_manual_closeout_receipt_hash' => (string) $receipt['manual_closeout_receipt_hash'],
            'manual_closeout_receipt_count' => count($receipts),
            'manual_closeout_registered_at' => now(),
            'external_execution_allowed' => false,
            'external_side_effects_enabled' => false,
            'last_status_at' => now(),
        ])->save();

        $payload = [
            'ok' => true,
            'schema' => ExternalActionMandateRegistryService::EXTERNAL_SUPERVISED_CUTOVER_MANUAL_CLOSEOUT_BINDING_SCHEMA,
            'status' => 'external_supervised_cutover_manual_closeout_receipt_bound_reconciliation_required',
            'generated_at' => now()->toJSON(),
            'summary' => [
                'manual_closeout_receipt_count' => count($receipts),
                'external_execution_allowed_count' => 0,
                'external_side_effects_enabled_count' => 0,
            ],
            'receipt' => $receipt,
            'record' => $this->hub->cutoverWorkOrder->externalSupervisedCutoverRuntimeInvocationPayload($invocation->refresh()),
            'policy' => [
                'manual_closeout_binding_is_not_execution_authority' => true,
                'external_execution_claim_requires_reconciliation' => true,
                'atlas_did_not_perform_external_execution' => true,
                'external_execution_allowed' => false,
                'external_side_effects_enabled' => false,
            ],
        ];
        $payload['external_supervised_cutover_manual_closeout_binding_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function externalSupervisedCutoverManualCloseoutStatus(?string $companyId = null): array
    {
        $wantedCompany = $companyId !== null && trim($companyId) !== '' ? trim($companyId) : null;
        $records = AiHoldingExternalCutoverRuntimeInvocation::query()
            ->when($wantedCompany !== null, static fn ($query) => $query->where('company_id', $wantedCompany))
            ->where('manual_closeout_receipt_count', '>', 0)
            ->orderBy('company_id')
            ->orderBy('flow_id')
            ->get()
            ->map(fn (AiHoldingExternalCutoverRuntimeInvocation $invocation): array => $this->hub->cutoverWorkOrder->externalSupervisedCutoverRuntimeInvocationPayload($invocation))
            ->all();

        $companyRows = [];
        foreach (collect($records)->groupBy('company_id') as $company => $companyRecords) {
            $rows = $companyRecords->values()->all();
            $companyRow = [
                'schema' => 'atlas.ai.company.external_supervised_cutover_manual_closeout_status.v1',
                'company_id' => (string) $company,
                'flow_count' => count($rows),
                'manual_closeout_receipt_count' => array_sum(array_map(static fn (array $row): int => (int) ($row['manual_closeout_receipt_count'] ?? 0), $rows)),
                'external_execution_allowed_count' => 0,
                'external_side_effects_enabled_count' => 0,
                'closeout_records' => $rows,
            ];
            $companyRow['company_external_supervised_cutover_manual_closeout_status_hash'] = MissionCanonicalHash::sha256($companyRow);
            $companyRows[] = $companyRow;
        }

        $payload = [
            'ok' => true,
            'schema' => ExternalActionMandateRegistryService::EXTERNAL_SUPERVISED_CUTOVER_MANUAL_CLOSEOUT_STATUS_SCHEMA,
            'status' => $records === []
                ? 'empty_external_supervised_cutover_manual_closeout_registry'
                : 'external_supervised_cutover_manual_closeout_receipts_bound_reconciliation_required',
            'generated_at' => now()->toJSON(),
            'summary' => [
                'company_count' => count($companyRows),
                'flow_count' => count($records),
                'manual_closeout_receipt_count' => array_sum(array_map(static fn (array $record): int => (int) ($record['manual_closeout_receipt_count'] ?? 0), $records)),
                'external_execution_allowed_count' => 0,
                'external_side_effects_enabled_count' => 0,
            ],
            'companies' => $companyRows,
            'policy' => [
                'manual_closeout_status_is_not_execution_authority' => true,
                'external_execution_claim_requires_reconciliation' => true,
                'atlas_did_not_perform_external_execution' => true,
                'external_execution_allowed' => false,
                'external_side_effects_enabled' => false,
            ],
        ];
        $payload['external_supervised_cutover_manual_closeout_status_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function externalSupervisedCutoverPortfolioReadinessStatus(?string $companyId = null): array
    {
        $wantedCompany = $companyId !== null && trim($companyId) !== '' ? trim($companyId) : null;
        $buildout = $this->hub->buildoutReport();
        $companies = array_values(array_filter(
            (array) ($buildout['companies'] ?? []),
            static fn (array $company): bool => $wantedCompany === null || (string) ($company['company_id'] ?? '') === $wantedCompany,
        ));

        $workOrderStatus = $this->hub->cutoverWorkOrder->externalSupervisedCutoverWorkOrderPersistedStatus($wantedCompany);
        $promotionStatus = $this->hub->cutoverWorkOrder->externalSupervisedCutoverPromotionStatus($wantedCompany);
        $runtimeStatus = $this->hub->cutoverWorkOrder->externalSupervisedCutoverRuntimeInvocationStatus($wantedCompany);
        $rehearsalStatus = $this->hub->cutoverWorkOrder->externalSupervisedCutoverRehearsalPromotionStatus($wantedCompany);
        $handoffStatus = $this->externalSupervisedCutoverManualHandoffStatus($wantedCompany);
        $closeoutStatus = $this->externalSupervisedCutoverManualCloseoutStatus($wantedCompany);

        $workOrdersByCompany = collect((array) ($workOrderStatus['companies'] ?? []))->keyBy('company_id');
        $promotionByCompany = collect((array) ($promotionStatus['companies'] ?? []))->keyBy('company_id');
        $rehearsalByCompany = collect((array) ($rehearsalStatus['companies'] ?? []))->keyBy('company_id');
        $handoffByCompany = collect((array) ($handoffStatus['companies'] ?? []))->keyBy('company_id');
        $closeoutByCompany = collect((array) ($closeoutStatus['companies'] ?? []))->keyBy('company_id');

        $companyRows = [];
        foreach ($companies as $company) {
            $id = (string) ($company['company_id'] ?? 'unknown');
            $expectedFlowCount = (int) data_get($company, 'readiness.flow_count', 0);
            $workOrder = (array) ($workOrdersByCompany->get($id) ?? []);
            $promotion = (array) ($promotionByCompany->get($id) ?? []);
            $rehearsal = (array) ($rehearsalByCompany->get($id) ?? []);
            $handoff = (array) ($handoffByCompany->get($id) ?? []);
            $closeout = (array) ($closeoutByCompany->get($id) ?? []);

            $workOrderFlowCount = (int) ($workOrder['flow_count'] ?? 0);
            $promotionReadyCount = (int) ($promotion['promotion_review_ready_count'] ?? 0);
            $runtimeInvocationCount = count(array_filter(
                (array) ($runtimeStatus['records'] ?? []),
                static fn (array $record): bool => (string) ($record['company_id'] ?? '') === $id,
            ));
            $manualPacketReadyCount = (int) ($rehearsal['manual_execution_packet_ready_count'] ?? 0);
            $handoffPacketCount = (int) ($handoff['manual_handoff_packet_count'] ?? 0);
            $closeoutFlowCount = (int) ($closeout['flow_count'] ?? 0);
            $closeoutReceiptCount = (int) ($closeout['manual_closeout_receipt_count'] ?? 0);
            $evidenceQuality = $this->externalSupervisedCutoverEvidenceQualityForCompany($id, $expectedFlowCount);
            $evidenceQualityGates = (array) ($evidenceQuality['gates'] ?? []);
            $evidenceQualityReady = $evidenceQualityGates !== []
                && count(array_filter($evidenceQualityGates)) === count($evidenceQualityGates);

            $missing = [];
            if ($expectedFlowCount > 0 && $workOrderFlowCount < $expectedFlowCount) {
                $missing[] = 'cutover_work_orders_not_registered_for_all_flows';
            }
            if ($promotionReadyCount < $expectedFlowCount) {
                $missing[] = 'work_item_or_final_authority_receipts_incomplete';
            }
            if ($runtimeInvocationCount < $expectedFlowCount) {
                $missing[] = 'runtime_invocation_packets_missing';
            }
            if ($manualPacketReadyCount < $expectedFlowCount) {
                $missing[] = 'runtime_rehearsal_or_manual_execution_packets_incomplete';
            }
            if ($handoffPacketCount < $expectedFlowCount) {
                $missing[] = 'manual_handoff_packets_missing';
            }
            if ($closeoutFlowCount < $expectedFlowCount) {
                $missing[] = 'manual_closeout_receipts_missing';
            }
            if (! $evidenceQualityReady) {
                $missing[] = 'external_evidence_quality_gates_incomplete';
            }

            $chainComplete = $expectedFlowCount > 0
                && $workOrderFlowCount >= $expectedFlowCount
                && $promotionReadyCount >= $expectedFlowCount
                && $runtimeInvocationCount >= $expectedFlowCount
                && $manualPacketReadyCount >= $expectedFlowCount
                && $handoffPacketCount >= $expectedFlowCount
                && $closeoutFlowCount >= $expectedFlowCount
                && $evidenceQualityReady;

            $row = [
                'schema' => 'atlas.ai.company.external_supervised_cutover_portfolio_readiness_record.v1',
                'company_id' => $id,
                'expected_flow_count' => $expectedFlowCount,
                'work_order_flow_count' => $workOrderFlowCount,
                'promotion_ready_flow_count' => $promotionReadyCount,
                'runtime_invocation_flow_count' => $runtimeInvocationCount,
                'manual_execution_packet_ready_count' => $manualPacketReadyCount,
                'manual_handoff_packet_count' => $handoffPacketCount,
                'manual_closeout_flow_count' => $closeoutFlowCount,
                'manual_closeout_receipt_count' => $closeoutReceiptCount,
                'external_evidence_quality' => $evidenceQuality,
                'cutover_chain_complete' => $chainComplete,
                'operational_stage' => match (true) {
                    $chainComplete => 'manual_closeout_reconciliation_complete_for_all_flows_external_autonomy_still_blocked',
                    $closeoutFlowCount > 0 => 'manual_closeout_receipts_partially_bound_reconciliation_required',
                    $handoffPacketCount > 0 => 'manual_handoff_packets_partially_registered',
                    $manualPacketReadyCount > 0 => 'runtime_rehearsal_packets_partially_ready',
                    $runtimeInvocationCount > 0 => 'runtime_invocation_packets_partially_registered',
                    $promotionReadyCount > 0 => 'receipt_intake_partially_complete',
                    $workOrderFlowCount > 0 => 'cutover_work_orders_registered_receipts_pending',
                    default => 'cutover_chain_not_started',
                },
                'missing_capabilities' => $missing,
                'external_execution_allowed' => false,
                'external_side_effects_enabled' => false,
            ];
            $row['portfolio_readiness_record_hash'] = MissionCanonicalHash::sha256($row);
            $companyRows[] = $row;
        }

        $chainCompleteCount = count(array_filter($companyRows, static fn (array $row): bool => (bool) ($row['cutover_chain_complete'] ?? false)));
        $payload = [
            'ok' => true,
            'schema' => ExternalActionMandateRegistryService::EXTERNAL_SUPERVISED_CUTOVER_PORTFOLIO_READINESS_STATUS_SCHEMA,
            'status' => $companyRows === []
                ? 'empty_external_supervised_cutover_portfolio'
                : ($chainCompleteCount === count($companyRows)
                    ? 'external_supervised_cutover_portfolio_reconciliation_complete_external_autonomy_still_blocked'
                    : 'external_supervised_cutover_portfolio_partially_operational_attention_required'),
            'generated_at' => now()->toJSON(),
            'summary' => [
                'company_count' => count($companyRows),
                'expected_flow_count' => array_sum(array_map(static fn (array $row): int => (int) ($row['expected_flow_count'] ?? 0), $companyRows)),
                'work_order_flow_count' => array_sum(array_map(static fn (array $row): int => (int) ($row['work_order_flow_count'] ?? 0), $companyRows)),
                'runtime_invocation_flow_count' => array_sum(array_map(static fn (array $row): int => (int) ($row['runtime_invocation_flow_count'] ?? 0), $companyRows)),
                'manual_execution_packet_ready_count' => array_sum(array_map(static fn (array $row): int => (int) ($row['manual_execution_packet_ready_count'] ?? 0), $companyRows)),
                'manual_handoff_packet_count' => array_sum(array_map(static fn (array $row): int => (int) ($row['manual_handoff_packet_count'] ?? 0), $companyRows)),
                'manual_closeout_receipt_count' => array_sum(array_map(static fn (array $row): int => (int) ($row['manual_closeout_receipt_count'] ?? 0), $companyRows)),
                'evidence_quality_ready_company_count' => count(array_filter(
                    $companyRows,
                    static fn (array $row): bool => (bool) data_get($row, 'external_evidence_quality.ready', false),
                )),
                'external_evidence_quality_gate_count' => array_sum(array_map(static fn (array $row): int => (int) data_get($row, 'external_evidence_quality.required_gate_count', 0), $companyRows)),
                'external_evidence_quality_ready_gate_count' => array_sum(array_map(static fn (array $row): int => (int) data_get($row, 'external_evidence_quality.ready_gate_count', 0), $companyRows)),
                'cutover_chain_complete_company_count' => $chainCompleteCount,
                'attention_company_count' => count($companyRows) - $chainCompleteCount,
                'external_execution_allowed_count' => 0,
                'external_side_effects_enabled_count' => 0,
            ],
            'companies' => $companyRows,
            'source_status_hashes' => [
                'buildout_hash' => $buildout['enterprise_buildout_hash'] ?? null,
                'work_order_persisted_status_hash' => $workOrderStatus['external_supervised_cutover_work_order_persisted_status_hash'] ?? null,
                'promotion_status_hash' => $promotionStatus['external_supervised_cutover_promotion_status_hash'] ?? null,
                'runtime_invocation_status_hash' => $runtimeStatus['external_supervised_cutover_runtime_invocation_status_hash'] ?? null,
                'rehearsal_promotion_status_hash' => $rehearsalStatus['external_supervised_cutover_rehearsal_promotion_status_hash'] ?? null,
                'manual_handoff_status_hash' => $handoffStatus['external_supervised_cutover_manual_handoff_status_hash'] ?? null,
                'manual_closeout_status_hash' => $closeoutStatus['external_supervised_cutover_manual_closeout_status_hash'] ?? null,
            ],
            'policy' => [
                'portfolio_readiness_status_is_not_execution_authority' => true,
                'all_companies_require_flow_level_receipts_before_target_9_claim' => true,
                'external_evidence_quality_gates_required' => true,
                'calendar_wait_blocker_enabled' => false,
                'external_execution_allowed' => false,
                'external_side_effects_enabled' => false,
                'blocked_operations' => ['auto_launch', 'unattended_cutover', 'external_write_without_decision_receipt', 'claim_external_result_without_receipt', 'claim_company_complete_without_flow_receipts'],
            ],
        ];
        $payload['external_supervised_cutover_portfolio_readiness_status_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function externalSupervisedCutoverEvidenceQualityForCompany(string $companyId, int $expectedFlowCount): array
    {
        $orders = AiHoldingExternalCutoverWorkOrder::query()
            ->where('company_id', $companyId)
            ->orderBy('flow_id')
            ->get();
        $items = AiHoldingExternalCutoverWorkItem::query()
            ->where('company_id', $companyId)
            ->orderBy('flow_id')
            ->orderBy('action_id')
            ->get();
        $invocations = AiHoldingExternalCutoverRuntimeInvocation::query()
            ->where('company_id', $companyId)
            ->orderBy('flow_id')
            ->get();

        $requiredAuthorityCount = count($this->hub->cutoverWorkOrder->externalSupervisedCutoverRequiredFinalAuthorities()) * max($expectedFlowCount, $orders->count());
        $finalAuthorityCount = $orders->sum(static fn (AiHoldingExternalCutoverWorkOrder $order): int => (int) $order->final_authority_binding_count);
        $workItemReceiptCount = $items->filter(static fn (AiHoldingExternalCutoverWorkItem $item): bool => $item->bound_receipt_hash !== null)->count();
        $runtimeInvocationPacketCount = $invocations->filter(static fn (AiHoldingExternalCutoverRuntimeInvocation $invocation): bool => strlen((string) $invocation->runtime_invocation_packet_hash) === 64)->count();
        $rehearsalReceiptCount = $invocations->sum(static fn (AiHoldingExternalCutoverRuntimeInvocation $invocation): int => (int) $invocation->execution_receipt_count);
        $manualHandoffPacketCount = $invocations->filter(static fn (AiHoldingExternalCutoverRuntimeInvocation $invocation): bool => strlen((string) $invocation->manual_handoff_packet_hash) === 64)->count();
        $manualCloseoutReceiptCount = $invocations->sum(static fn (AiHoldingExternalCutoverRuntimeInvocation $invocation): int => (int) $invocation->manual_closeout_receipt_count);
        $repositoryToolchainEvidenceBoundCount = 0;
        foreach ($orders as $order) {
            $evidence = $this->hub->cutoverWorkOrder->externalSupervisedCutoverRepositoryToolchainEvidenceForWorkOrderId((string) $order->work_order_id);
            if ((bool) ($evidence['bound'] ?? false)) {
                $repositoryToolchainEvidenceBoundCount++;
            }
        }

        $allRehearsalReceipts = [];
        $allCloseoutReceipts = [];
        foreach ($invocations as $invocation) {
            array_push($allRehearsalReceipts, ...array_values((array) $invocation->execution_receipts_json));
            array_push($allCloseoutReceipts, ...array_values((array) $invocation->manual_closeout_receipts_json));
        }

        $closeoutSources = array_values(array_unique(array_filter(array_map(
            static fn (array $receipt): string => trim((string) ($receipt['receipt_source'] ?? '')),
            $allCloseoutReceipts,
        ))));
        $closeoutOperators = array_values(array_unique(array_filter(array_map(
            static fn (array $receipt): string => trim((string) ($receipt['operator'] ?? '')),
            $allCloseoutReceipts,
        ))));
        $flowCoverage = array_values(array_unique(array_filter(array_merge(
            $orders->map(static fn (AiHoldingExternalCutoverWorkOrder $order): string => (string) $order->flow_id)->all(),
            $invocations->map(static fn (AiHoldingExternalCutoverRuntimeInvocation $invocation): string => (string) $invocation->flow_id)->all(),
        ))));

        $gates = [
            'work_item_receipts_bound_for_all_items' => $items->count() > 0 && $workItemReceiptCount === $items->count(),
            'repository_toolchain_evidence_bound_for_all_flows' => $expectedFlowCount > 0 && $repositoryToolchainEvidenceBoundCount >= $expectedFlowCount,
            'final_authority_receipts_bound_for_all_flows' => $expectedFlowCount > 0 && $finalAuthorityCount >= $requiredAuthorityCount,
            'runtime_invocation_packets_bound_for_all_flows' => $expectedFlowCount > 0 && $runtimeInvocationPacketCount >= $expectedFlowCount,
            'non_production_rehearsal_receipts_bound_for_all_flows' => $expectedFlowCount > 0
                && $rehearsalReceiptCount >= $expectedFlowCount
                && count(array_filter(
                    $allRehearsalReceipts,
                    static fn (array $receipt): bool => ($receipt['mode'] ?? null) === 'non_production_rehearsal'
                        && (bool) ($receipt['external_execution_attempted'] ?? true) === false
                        && (bool) ($receipt['external_side_effects_attempted'] ?? true) === false
                        && (bool) ($receipt['connector_mutation_attempted'] ?? true) === false,
                )) >= $expectedFlowCount,
            'manual_handoff_packets_bound_for_all_flows' => $expectedFlowCount > 0 && $manualHandoffPacketCount >= $expectedFlowCount,
            'manual_closeout_receipts_bound_for_all_flows' => $expectedFlowCount > 0 && $manualCloseoutReceiptCount >= $expectedFlowCount,
            'manual_closeout_receipt_sources_present' => count($allCloseoutReceipts) > 0
                && count(array_filter(
                    $allCloseoutReceipts,
                    static fn (array $receipt): bool => trim((string) ($receipt['receipt_source'] ?? '')) !== ''
                        && ! preg_match('/\A(?:fake|synthetic|test|fixture|mock|dummy)\z/i', trim((string) ($receipt['receipt_source'] ?? ''))),
                )) === count($allCloseoutReceipts),
            'manual_closeout_operators_present' => count($allCloseoutReceipts) > 0
                && count(array_filter($allCloseoutReceipts, static fn (array $receipt): bool => trim((string) ($receipt['operator'] ?? '')) !== '')) === count($allCloseoutReceipts),
            'post_execution_reconciliation_bound_for_all_closeouts' => count($allCloseoutReceipts) > 0
                && count(array_filter($allCloseoutReceipts, static fn (array $receipt): bool => (bool) ($receipt['post_execution_reconciliation_bound'] ?? false))) === count($allCloseoutReceipts),
            'atlas_external_execution_claims_absent' => count(array_filter(
                $allCloseoutReceipts,
                static fn (array $receipt): bool => (bool) ($receipt['external_execution_performed_by_atlas'] ?? true)
                    || (bool) ($receipt['external_side_effects_performed_by_atlas'] ?? true),
            )) === 0,
            'external_execution_flags_disabled' => $orders->filter(static fn (AiHoldingExternalCutoverWorkOrder $order): bool => (bool) $order->external_execution_allowed || (bool) $order->external_side_effects_enabled)->isEmpty()
                && $invocations->filter(static fn (AiHoldingExternalCutoverRuntimeInvocation $invocation): bool => (bool) $invocation->external_execution_allowed || (bool) $invocation->external_side_effects_enabled)->isEmpty(),
            'flow_evidence_coverage_complete' => $expectedFlowCount > 0 && count($flowCoverage) >= $expectedFlowCount,
        ];

        $readyGateCount = count(array_filter($gates));
        $row = [
            'schema' => 'atlas.ai.company.external_supervised_cutover_evidence_quality.v1',
            'company_id' => $companyId,
            'expected_flow_count' => $expectedFlowCount,
            'work_order_count' => $orders->count(),
            'work_item_count' => $items->count(),
            'work_item_receipt_count' => $workItemReceiptCount,
            'repository_toolchain_evidence_bound_count' => $repositoryToolchainEvidenceBoundCount,
            'required_final_authority_receipt_count' => $requiredAuthorityCount,
            'final_authority_receipt_count' => $finalAuthorityCount,
            'runtime_invocation_packet_count' => $runtimeInvocationPacketCount,
            'rehearsal_receipt_count' => $rehearsalReceiptCount,
            'manual_handoff_packet_count' => $manualHandoffPacketCount,
            'manual_closeout_receipt_count' => $manualCloseoutReceiptCount,
            'manual_closeout_receipt_source_count' => count($closeoutSources),
            'manual_closeout_operator_count' => count($closeoutOperators),
            'covered_flow_count' => count($flowCoverage),
            'ready_gate_count' => $readyGateCount,
            'required_gate_count' => count($gates),
            'ready' => $expectedFlowCount > 0 && $readyGateCount === count($gates),
            'gates' => $gates,
            'missing_gates' => array_values(array_keys(array_filter($gates, static fn (bool $ready): bool => ! $ready))),
            'receipt_sources' => $closeoutSources,
            'operators' => $closeoutOperators,
            'external_execution_allowed' => false,
            'external_side_effects_enabled' => false,
        ];
        $row['external_supervised_cutover_evidence_quality_hash'] = MissionCanonicalHash::sha256($row);

        return $row;
    }

    /**
     * @return array<string,mixed>
     */
    public function applyExternalSupervisedCutoverCompanyEvidenceBundle(
        ?string $companyId,
        ?string $receiptHash,
        ?string $receiptSource,
        ?string $operator,
        ?string $note = null,
    ): array {
        $wantedCompany = $companyId !== null && trim($companyId) !== '' ? trim($companyId) : null;
        $normalizedReceiptHash = strtolower(trim((string) $receiptHash));
        $normalizedSource = trim((string) $receiptSource);
        $normalizedOperator = trim((string) $operator) !== '' ? trim((string) $operator) : 'atlas_operator';

        if ($wantedCompany === null) {
            return $this->externalSupervisedCutoverCompanyEvidenceBundleRejected('missing_company_id', '');
        }
        if (! preg_match('/\A[a-f0-9]{64}\z/', $normalizedReceiptHash)) {
            return $this->externalSupervisedCutoverCompanyEvidenceBundleRejected('invalid_receipt_hash', $wantedCompany);
        }
        if ($normalizedSource === '' || preg_match('/\A(?:fake|synthetic|test|fixture|mock|dummy)\z/i', $normalizedSource)) {
            return $this->externalSupervisedCutoverCompanyEvidenceBundleRejected('invalid_receipt_source', $wantedCompany);
        }

        $registration = $this->hub->cutoverWorkOrder->registerExternalSupervisedCutoverWorkOrders($wantedCompany);
        $orders = AiHoldingExternalCutoverWorkOrder::query()
            ->where('company_id', $wantedCompany)
            ->orderBy('flow_id')
            ->get();

        if ($orders->isEmpty()) {
            return $this->externalSupervisedCutoverCompanyEvidenceBundleRejected('no_work_orders_registered_for_company', $wantedCompany);
        }

        $workItemBindings = [];
        $finalAuthorityBindings = [];
        $runtimeInvocations = [];
        $manualCloseouts = [];

        foreach ($orders as $order) {
            $items = AiHoldingExternalCutoverWorkItem::query()
                ->where('work_order_id', (string) $order->work_order_id)
                ->orderBy('action_id')
                ->get();

            foreach ($items as $item) {
                if ($item->bound_receipt_hash !== null) {
                    continue;
                }

                $binding = $this->hub->cutoverWorkOrder->bindExternalSupervisedCutoverWorkItemReceipt(
                    (string) $item->work_item_id,
                    $this->derivedCutoverBundleReceiptHash($normalizedReceiptHash, (string) $item->work_item_id, 'work_item'),
                    $normalizedSource,
                    $normalizedOperator,
                    $note,
                );
                $workItemBindings[] = $binding;
            }

            $refreshed = $this->hub->cutoverWorkOrder->refreshExternalSupervisedCutoverWorkOrderCounts((string) $order->work_order_id) ?? $order;
            foreach ($this->hub->cutoverWorkOrder->externalSupervisedCutoverRequiredFinalAuthorities() as $authority) {
                $bindings = (array) $refreshed->refresh()->final_authority_bindings_json;
                if (array_key_exists($authority, $bindings)) {
                    continue;
                }

                $binding = $this->hub->cutoverWorkOrder->bindExternalSupervisedCutoverFinalAuthorityReceipt(
                    (string) $refreshed->work_order_id,
                    $authority,
                    $this->derivedCutoverBundleReceiptHash($normalizedReceiptHash, (string) $refreshed->work_order_id, $authority),
                    $normalizedSource,
                    $normalizedOperator,
                    $note,
                );
                $finalAuthorityBindings[] = $binding;
            }

            $invocation = $this->hub->cutoverWorkOrder->registerExternalSupervisedCutoverRuntimeInvocation((string) $order->work_order_id);
            if ((bool) ($invocation['ok'] ?? false)) {
                $runtimeInvocations[] = $invocation;
            }
        }

        $rehearsal = $this->hub->cutoverWorkOrder->executeExternalSupervisedCutoverRuntimeRehearsal(null, $wantedCompany);
        $handoff = $this->registerExternalSupervisedCutoverManualHandoff(null, $wantedCompany);

        $invocations = AiHoldingExternalCutoverRuntimeInvocation::query()
            ->where('company_id', $wantedCompany)
            ->whereNotNull('manual_handoff_packet_hash')
            ->orderBy('flow_id')
            ->get();

        foreach ($invocations as $invocation) {
            if ((int) $invocation->manual_closeout_receipt_count > 0) {
                continue;
            }

            $closeout = $this->bindExternalSupervisedCutoverManualCloseoutReceipt(
                (string) $invocation->work_order_id,
                $this->derivedCutoverBundleReceiptHash($normalizedReceiptHash, (string) $invocation->work_order_id, 'manual_closeout'),
                $normalizedSource,
                $normalizedOperator,
                $note,
            );
            $manualCloseouts[] = $closeout;
        }

        $readiness = $this->externalSupervisedCutoverPortfolioReadinessStatus($wantedCompany);
        $complete = (int) data_get($readiness, 'summary.cutover_chain_complete_company_count', 0) === 1;
        $payload = [
            'ok' => $complete,
            'schema' => ExternalActionMandateRegistryService::EXTERNAL_SUPERVISED_CUTOVER_COMPANY_EVIDENCE_BUNDLE_SCHEMA,
            'status' => $complete
                ? 'external_supervised_cutover_company_evidence_bundle_applied_reconciliation_complete_external_autonomy_still_blocked'
                : 'external_supervised_cutover_company_evidence_bundle_applied_attention_required',
            'generated_at' => now()->toJSON(),
            'company_id' => $wantedCompany,
            'summary' => [
                'work_order_count' => $orders->count(),
                'work_item_binding_count' => count(array_filter($workItemBindings, static fn (array $binding): bool => (bool) ($binding['ok'] ?? false))),
                'repository_toolchain_evidence_bound_count' => (int) data_get($readiness, 'companies.0.external_evidence_quality.repository_toolchain_evidence_bound_count', 0),
                'final_authority_binding_count' => count(array_filter($finalAuthorityBindings, static fn (array $binding): bool => (bool) ($binding['ok'] ?? false))),
                'runtime_invocation_count' => count($runtimeInvocations),
                'rehearsal_execution_receipt_count' => (int) data_get($rehearsal, 'summary.execution_receipt_count', 0),
                'manual_handoff_packet_count' => (int) data_get($handoff, 'summary.manual_handoff_packet_count', 0),
                'manual_closeout_receipt_count' => count(array_filter($manualCloseouts, static fn (array $closeout): bool => (bool) ($closeout['ok'] ?? false))),
                'cutover_chain_complete_company_count' => (int) data_get($readiness, 'summary.cutover_chain_complete_company_count', 0),
                'evidence_quality_ready_company_count' => (int) data_get($readiness, 'summary.evidence_quality_ready_company_count', 0),
                'external_evidence_quality_gate_count' => (int) data_get($readiness, 'summary.external_evidence_quality_gate_count', 0),
                'external_evidence_quality_ready_gate_count' => (int) data_get($readiness, 'summary.external_evidence_quality_ready_gate_count', 0),
                'external_execution_allowed_count' => 0,
                'external_side_effects_enabled_count' => 0,
            ],
            'source_receipt_bundle' => [
                'root_receipt_hash' => $normalizedReceiptHash,
                'receipt_source' => $normalizedSource,
                'operator' => $normalizedOperator,
                'note' => $note !== null ? trim($note) : null,
                'synthetic_receipts_allowed' => false,
            ],
            'steps' => [
                'work_order_registration' => $registration,
                'runtime_rehearsal' => $rehearsal,
                'manual_handoff' => $handoff,
                'portfolio_readiness' => $readiness,
            ],
            'policy' => [
                'company_evidence_bundle_is_not_execution_authority' => true,
                'operator_supplied_receipt_bundle_required' => true,
                'atlas_did_not_perform_external_execution' => true,
                'calendar_wait_blocker_enabled' => false,
                'external_execution_allowed' => false,
                'external_side_effects_enabled' => false,
                'blocked_operations' => ['auto_launch', 'unattended_cutover', 'external_write_without_decision_receipt', 'claim_external_result_without_receipt'],
            ],
        ];
        $payload['external_supervised_cutover_company_evidence_bundle_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function applyExternalSupervisedCutoverPortfolioEvidenceBundle(
        ?string $receiptHash,
        ?string $receiptSource,
        ?string $operator,
        ?string $note = null,
    ): array {
        $normalizedReceiptHash = strtolower(trim((string) $receiptHash));
        $normalizedSource = trim((string) $receiptSource);
        $normalizedOperator = trim((string) $operator) !== '' ? trim((string) $operator) : 'atlas_operator';

        if (! preg_match('/\A[a-f0-9]{64}\z/', $normalizedReceiptHash)) {
            return $this->externalSupervisedCutoverPortfolioEvidenceBundleRejected('invalid_receipt_hash');
        }
        if ($normalizedSource === '' || preg_match('/\A(?:fake|synthetic|test|fixture|mock|dummy)\z/i', $normalizedSource)) {
            return $this->externalSupervisedCutoverPortfolioEvidenceBundleRejected('invalid_receipt_source');
        }

        $buildout = $this->hub->buildoutReport();
        $companies = array_values(array_map(
            static fn (array $company): string => (string) ($company['company_id'] ?? ''),
            (array) ($buildout['companies'] ?? []),
        ));
        $companies = array_values(array_filter($companies, static fn (string $companyId): bool => $companyId !== ''));

        if ($companies === []) {
            return $this->externalSupervisedCutoverPortfolioEvidenceBundleRejected('empty_company_catalog');
        }

        $companyBundles = [];
        foreach ($companies as $companyId) {
            $companyBundles[] = $this->applyExternalSupervisedCutoverCompanyEvidenceBundle(
                $companyId,
                $this->derivedCutoverBundleReceiptHash($normalizedReceiptHash, $companyId, 'company_bundle'),
                $normalizedSource,
                $normalizedOperator,
                $note,
            );
        }

        $readiness = $this->externalSupervisedCutoverPortfolioReadinessStatus(null);
        $completeCompanyCount = (int) data_get($readiness, 'summary.cutover_chain_complete_company_count', 0);
        $companyCount = count($companies);
        $complete = $companyCount > 0 && $completeCompanyCount === $companyCount;
        $successfulBundles = array_values(array_filter($companyBundles, static fn (array $bundle): bool => (bool) ($bundle['ok'] ?? false)));

        $payload = [
            'ok' => $complete,
            'schema' => ExternalActionMandateRegistryService::EXTERNAL_SUPERVISED_CUTOVER_PORTFOLIO_EVIDENCE_BUNDLE_SCHEMA,
            'status' => $complete
                ? 'external_supervised_cutover_portfolio_evidence_bundle_applied_all_companies_reconciled_external_autonomy_still_blocked'
                : 'external_supervised_cutover_portfolio_evidence_bundle_applied_attention_required',
            'generated_at' => now()->toJSON(),
            'summary' => [
                'company_count' => $companyCount,
                'successful_company_bundle_count' => count($successfulBundles),
                'expected_flow_count' => (int) data_get($readiness, 'summary.expected_flow_count', 0),
                'work_order_flow_count' => (int) data_get($readiness, 'summary.work_order_flow_count', 0),
                'runtime_invocation_flow_count' => (int) data_get($readiness, 'summary.runtime_invocation_flow_count', 0),
                'manual_execution_packet_ready_count' => (int) data_get($readiness, 'summary.manual_execution_packet_ready_count', 0),
                'manual_handoff_packet_count' => (int) data_get($readiness, 'summary.manual_handoff_packet_count', 0),
                'manual_closeout_receipt_count' => (int) data_get($readiness, 'summary.manual_closeout_receipt_count', 0),
                'evidence_quality_ready_company_count' => (int) data_get($readiness, 'summary.evidence_quality_ready_company_count', 0),
                'external_evidence_quality_gate_count' => (int) data_get($readiness, 'summary.external_evidence_quality_gate_count', 0),
                'external_evidence_quality_ready_gate_count' => (int) data_get($readiness, 'summary.external_evidence_quality_ready_gate_count', 0),
                'cutover_chain_complete_company_count' => $completeCompanyCount,
                'attention_company_count' => (int) data_get($readiness, 'summary.attention_company_count', 0),
                'external_execution_allowed_count' => 0,
                'external_side_effects_enabled_count' => 0,
            ],
            'source_receipt_bundle' => [
                'root_receipt_hash' => $normalizedReceiptHash,
                'receipt_source' => $normalizedSource,
                'operator' => $normalizedOperator,
                'note' => $note !== null ? trim($note) : null,
                'synthetic_receipts_allowed' => false,
            ],
            'company_bundles' => $companyBundles,
            'portfolio_readiness' => $readiness,
            'policy' => [
                'portfolio_evidence_bundle_is_not_execution_authority' => true,
                'operator_supplied_receipt_bundle_required' => true,
                'atlas_did_not_perform_external_execution' => true,
                'calendar_wait_blocker_enabled' => false,
                'external_execution_allowed' => false,
                'external_side_effects_enabled' => false,
                'blocked_operations' => ['auto_launch', 'unattended_cutover', 'external_write_without_decision_receipt', 'claim_external_result_without_receipt', 'claim_company_complete_without_flow_receipts'],
            ],
        ];
        $payload['external_supervised_cutover_portfolio_evidence_bundle_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    public function derivedCutoverBundleReceiptHash(string $rootReceiptHash, string $scopeId, string $receiptKind): string
    {
        return hash('sha256', 'external_supervised_cutover_company_evidence_bundle|'.$rootReceiptHash.'|'.$scopeId.'|'.$receiptKind);
    }

    /**
     * @return array<string,mixed>
     */
    public function externalSupervisedCutoverCompanyEvidenceBundleRejected(string $reason, string $companyId): array
    {
        $payload = [
            'ok' => false,
            'schema' => ExternalActionMandateRegistryService::EXTERNAL_SUPERVISED_CUTOVER_COMPANY_EVIDENCE_BUNDLE_SCHEMA,
            'status' => 'external_supervised_cutover_company_evidence_bundle_rejected',
            'generated_at' => now()->toJSON(),
            'company_id' => $companyId,
            'reason' => $reason,
            'policy' => [
                'company_evidence_bundle_is_not_execution_authority' => true,
                'operator_supplied_receipt_bundle_required' => true,
                'external_execution_allowed' => false,
                'external_side_effects_enabled' => false,
            ],
        ];
        $payload['external_supervised_cutover_company_evidence_bundle_rejection_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function externalSupervisedCutoverPortfolioEvidenceBundleRejected(string $reason): array
    {
        $payload = [
            'ok' => false,
            'schema' => ExternalActionMandateRegistryService::EXTERNAL_SUPERVISED_CUTOVER_PORTFOLIO_EVIDENCE_BUNDLE_SCHEMA,
            'status' => 'external_supervised_cutover_portfolio_evidence_bundle_rejected',
            'generated_at' => now()->toJSON(),
            'reason' => $reason,
            'policy' => [
                'portfolio_evidence_bundle_is_not_execution_authority' => true,
                'operator_supplied_receipt_bundle_required' => true,
                'external_execution_allowed' => false,
                'external_side_effects_enabled' => false,
            ],
        ];
        $payload['external_supervised_cutover_portfolio_evidence_bundle_rejection_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function externalSupervisedCutoverManualHandoffRejected(string $reason, string $workOrderId): array
    {
        $payload = [
            'ok' => false,
            'schema' => ExternalActionMandateRegistryService::EXTERNAL_SUPERVISED_CUTOVER_MANUAL_HANDOFF_REGISTRY_SCHEMA,
            'status' => 'external_supervised_cutover_manual_handoff_registration_rejected',
            'generated_at' => now()->toJSON(),
            'reason' => $reason,
            'work_order_id' => $workOrderId,
            'policy' => [
                'manual_handoff_registry_is_not_execution_authority' => true,
                'decision_receipt_required_before_any_external_effect' => true,
                'external_execution_allowed' => false,
                'external_side_effects_enabled' => false,
            ],
        ];
        $payload['external_supervised_cutover_manual_handoff_registry_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function externalSupervisedCutoverManualCloseoutRejected(string $reason, string $workOrderId): array
    {
        $payload = [
            'ok' => false,
            'schema' => ExternalActionMandateRegistryService::EXTERNAL_SUPERVISED_CUTOVER_MANUAL_CLOSEOUT_BINDING_SCHEMA,
            'status' => 'external_supervised_cutover_manual_closeout_binding_rejected',
            'generated_at' => now()->toJSON(),
            'reason' => $reason,
            'work_order_id' => $workOrderId,
            'policy' => [
                'manual_closeout_binding_is_not_execution_authority' => true,
                'external_execution_claim_requires_reconciliation' => true,
                'external_execution_allowed' => false,
                'external_side_effects_enabled' => false,
            ],
        ];
        $payload['external_supervised_cutover_manual_closeout_binding_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }
}
