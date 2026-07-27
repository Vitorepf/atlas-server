<?php

namespace App\Services\Ai\Holding\MandateRegistry;

use App\Models\AiHoldingExternalCutoverRuntimeInvocation;
use App\Models\AiHoldingExternalCutoverWorkItem;
use App\Models\AiHoldingExternalCutoverWorkOrder;
use App\Services\Ai\Holding\ExternalActionMandateRegistryService;
use App\Services\Ai\Mission\MissionCanonicalHash;

/**
 * GOD-DEBULK method-family section extracted verbatim from
 * ExternalActionMandateRegistryService. Behavior frozen by
 * ExternalActionMandateRegistryServiceGoldenCharacterizationTest.
 */
class CutoverWorkOrderSection
{
    public function __construct(private readonly MandateRegistryHub $hub) {}

    /**
     * @return array<string,mixed>
     */
    public function registerExternalSupervisedCutoverWorkOrders(?string $companyId = null): array
    {
        $sourceStatus = $this->hub->launchReceiptChain->externalSupervisedCutoverWorkOrderStatus($companyId);
        $records = [];

        foreach ((array) ($sourceStatus['companies'] ?? []) as $company) {
            foreach ((array) ($company['flow_cutover_work_orders'] ?? []) as $workOrder) {
                $records[] = $this->persistExternalSupervisedCutoverWorkOrder((array) $workOrder);
            }
        }

        $payload = [
            'ok' => (bool) ($sourceStatus['ok'] ?? false) && $records !== [],
            'schema' => ExternalActionMandateRegistryService::EXTERNAL_SUPERVISED_CUTOVER_WORK_ORDER_REGISTRY_SCHEMA,
            'status' => $records === [] ? 'empty_cutover_work_order_registry' : 'external_supervised_cutover_work_orders_registered_launch_blocked',
            'generated_at' => now()->toJSON(),
            'source_external_supervised_cutover_work_order_status_hash' => $sourceStatus['external_supervised_cutover_work_order_status_hash'] ?? null,
            'summary' => $this->externalSupervisedCutoverPersistedSummary($records),
            'records' => $records,
            'registry_policy' => [
                'mode' => 'durable_supervised_cutover_work_order_registry',
                'external_execution_allowed' => false,
                'external_side_effects_enabled' => false,
                'registry_does_not_enable_launch' => true,
                'calendar_wait_blocker_enabled' => false,
                'required_before_item_completion' => ['real_receipt_hash', 'source_link_or_ledger_reference', 'operator_attestation'],
                'blocked_operations' => ['execute_work_order', 'cutover', 'launch', 'write', 'publish', 'spend', 'trade', 'deploy', 'delete', 'secret_export'],
            ],
        ];
        $payload['external_supervised_cutover_work_order_registry_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function externalSupervisedCutoverWorkOrderPersistedStatus(?string $companyId = null): array
    {
        $wantedCompany = $companyId !== null && trim($companyId) !== '' ? trim($companyId) : null;
        $orders = AiHoldingExternalCutoverWorkOrder::query()
            ->when($wantedCompany !== null, static fn ($query) => $query->where('company_id', $wantedCompany))
            ->orderBy('company_id')
            ->orderBy('flow_id')
            ->get()
            ->map(fn (AiHoldingExternalCutoverWorkOrder $order): array => $this->externalSupervisedCutoverWorkOrderPersistedPayload($order))
            ->all();

        $companyRows = [];
        foreach (collect($orders)->groupBy('company_id') as $company => $companyOrders) {
            $rows = $companyOrders->values()->all();
            $companyRows[] = [
                'schema' => 'atlas.ai.company.external_supervised_cutover_work_order_persisted_status.v1',
                'company_id' => (string) $company,
                'flow_count' => count($rows),
                'work_order_count' => count($rows),
                'work_order_ready_count' => count(array_filter($rows, static fn (array $row): bool => (bool) ($row['work_order_ready'] ?? false))),
                'work_item_count' => array_sum(array_map(static fn (array $row): int => (int) ($row['work_item_count'] ?? 0), $rows)),
                'pending_work_item_count' => array_sum(array_map(static fn (array $row): int => (int) ($row['pending_work_item_count'] ?? 0), $rows)),
                'executable_item_count' => array_sum(array_map(static fn (array $row): int => (int) ($row['executable_item_count'] ?? 0), $rows)),
                'supervised_cutover_enabled_count' => 0,
                'flow_cutover_work_orders' => $rows,
            ];
            $companyRows[array_key_last($companyRows)]['company_external_supervised_cutover_work_order_persisted_status_hash'] = MissionCanonicalHash::sha256($companyRows[array_key_last($companyRows)]);
        }

        $payload = [
            'ok' => true,
            'schema' => ExternalActionMandateRegistryService::EXTERNAL_SUPERVISED_CUTOVER_WORK_ORDER_PERSISTED_STATUS_SCHEMA,
            'status' => $orders === [] ? 'empty_cutover_work_order_registry' : 'external_supervised_cutover_work_orders_persisted_launch_blocked',
            'generated_at' => now()->toJSON(),
            'summary' => $this->externalSupervisedCutoverPersistedSummary($orders),
            'companies' => $companyRows,
            'policy' => [
                'external_execution_allowed' => false,
                'external_side_effects_enabled' => false,
                'persisted_status_does_not_enable_launch' => true,
                'calendar_wait_blocker_enabled' => false,
                'synthetic_receipts_count_as_real_external_authority' => false,
            ],
        ];
        $payload['external_supervised_cutover_work_order_persisted_status_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function bindExternalSupervisedCutoverWorkItemReceipt(
        ?string $workItemId,
        ?string $receiptHash,
        ?string $receiptSource = null,
        ?string $operator = null,
        ?string $note = null,
    ): array {
        $normalizedWorkItemId = strtolower(trim((string) $workItemId));
        $normalizedReceiptHash = strtolower(trim((string) $receiptHash));
        $normalizedSource = trim((string) $receiptSource);

        if (! preg_match('/\A[a-f0-9]{64}\z/', $normalizedWorkItemId)) {
            return $this->externalSupervisedCutoverWorkItemReceiptBindingRejected('invalid_work_item_id', $normalizedWorkItemId);
        }

        if (! preg_match('/\A[a-f0-9]{64}\z/', $normalizedReceiptHash)) {
            return $this->externalSupervisedCutoverWorkItemReceiptBindingRejected('invalid_receipt_hash', $normalizedWorkItemId);
        }

        if ($normalizedSource === '' || preg_match('/\A(?:fake|synthetic|test|fixture|mock|dummy)\z/i', $normalizedSource)) {
            return $this->externalSupervisedCutoverWorkItemReceiptBindingRejected('invalid_receipt_source', $normalizedWorkItemId);
        }

        $item = AiHoldingExternalCutoverWorkItem::query()
            ->where('work_item_id', $normalizedWorkItemId)
            ->first();

        if (! $item instanceof AiHoldingExternalCutoverWorkItem) {
            return $this->externalSupervisedCutoverWorkItemReceiptBindingRejected('work_item_not_found', $normalizedWorkItemId);
        }

        $binding = [
            'schema' => 'atlas.ai.company.external_supervised_cutover_work_item_receipt_binding.v1',
            'work_item_id' => (string) $item->work_item_id,
            'work_order_id' => (string) $item->work_order_id,
            'company_id' => (string) $item->company_id,
            'flow_id' => (string) $item->flow_id,
            'action_id' => (string) $item->action_id,
            'receipt_hash' => $normalizedReceiptHash,
            'receipt_source' => $normalizedSource,
            'operator' => trim((string) ($operator ?? 'atlas_operator')),
            'note' => trim((string) ($note ?? '')),
            'synthetic_receipt_allowed' => false,
            'receipt_binding_is_execution_authority' => false,
            'external_execution_allowed' => false,
            'external_side_effects_enabled' => false,
            'bound_at' => now()->toJSON(),
        ];
        $binding['receipt_binding_hash'] = MissionCanonicalHash::sha256($binding);

        $item->forceFill([
            'status' => 'receipt_bound_operator_action_completed',
            'bound_receipt_hash' => $normalizedReceiptHash,
            'receipt_binding_hash' => (string) $binding['receipt_binding_hash'],
            'receipt_binding_json' => $binding,
            'executable' => false,
            'external_execution_allowed' => false,
            'external_side_effects_enabled' => false,
            'completed_at' => now(),
        ])->save();

        $order = $this->refreshExternalSupervisedCutoverWorkOrderCounts((string) $item->work_order_id);
        $record = $this->externalSupervisedCutoverWorkItemPersistedPayload($item->refresh());
        $orderRecord = $order instanceof AiHoldingExternalCutoverWorkOrder
            ? $this->externalSupervisedCutoverWorkOrderPersistedPayload($order->refresh())
            : null;

        $payload = [
            'ok' => true,
            'schema' => ExternalActionMandateRegistryService::EXTERNAL_SUPERVISED_CUTOVER_WORK_ITEM_RECEIPT_BINDING_SCHEMA,
            'status' => 'external_supervised_cutover_work_item_receipt_bound_launch_blocked',
            'generated_at' => now()->toJSON(),
            'summary' => [
                'work_item_count' => 1,
                'bound_receipt_count' => 1,
                'pending_work_item_count' => $order instanceof AiHoldingExternalCutoverWorkOrder ? (int) $order->pending_work_item_count : 0,
                'external_execution_allowed_count' => 0,
                'external_side_effects_enabled_count' => 0,
            ],
            'work_item' => $record,
            'work_order' => $orderRecord,
            'policy' => [
                'receipt_binding_is_not_execution_authority' => true,
                'synthetic_receipts_count_as_real_external_authority' => false,
                'external_execution_allowed' => false,
                'external_side_effects_enabled' => false,
                'blocked_operations' => ['execute_work_order', 'cutover', 'launch', 'write', 'publish', 'spend', 'trade', 'deploy', 'delete', 'secret_export'],
            ],
        ];
        $payload['external_supervised_cutover_work_item_receipt_binding_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function externalSupervisedCutoverPromotionStatus(?string $companyId = null): array
    {
        $wantedCompany = $companyId !== null && trim($companyId) !== '' ? trim($companyId) : null;
        $orders = AiHoldingExternalCutoverWorkOrder::query()
            ->when($wantedCompany !== null, static fn ($query) => $query->where('company_id', $wantedCompany))
            ->orderBy('company_id')
            ->orderBy('flow_id')
            ->get();

        $records = [];
        foreach ($orders as $order) {
            $refreshed = $this->refreshExternalSupervisedCutoverWorkOrderCounts((string) $order->work_order_id);
            $records[] = $this->externalSupervisedCutoverPromotionRecord(($refreshed ?? $order)->refresh());
        }

        $companyRows = [];
        foreach (collect($records)->groupBy('company_id') as $company => $companyRecords) {
            $rows = $companyRecords->values()->all();
            $companyRows[] = [
                'schema' => 'atlas.ai.company.external_supervised_cutover_promotion_status.v1',
                'company_id' => (string) $company,
                'flow_count' => count($rows),
                'work_order_count' => count($rows),
                'promotion_review_ready_count' => count(array_filter($rows, static fn (array $row): bool => (bool) ($row['promotion_review_ready'] ?? false))),
                'repository_toolchain_evidence_bound_count' => count(array_filter($rows, static fn (array $row): bool => (bool) data_get($row, 'repository_toolchain_evidence.bound', false))),
                'receipt_incomplete_order_count' => count(array_filter($rows, static fn (array $row): bool => (bool) ($row['promotion_review_ready'] ?? false) === false)),
                'external_execution_allowed_count' => 0,
                'external_side_effects_enabled_count' => 0,
                'flow_promotion_packets' => $rows,
            ];
            $companyRows[array_key_last($companyRows)]['company_external_supervised_cutover_promotion_status_hash'] = MissionCanonicalHash::sha256($companyRows[array_key_last($companyRows)]);
        }

        $readyCount = count(array_filter($records, static fn (array $record): bool => (bool) ($record['promotion_review_ready'] ?? false)));
        $payload = [
            'ok' => true,
            'schema' => ExternalActionMandateRegistryService::EXTERNAL_SUPERVISED_CUTOVER_PROMOTION_STATUS_SCHEMA,
            'status' => $records === []
                ? 'empty_cutover_work_order_registry'
                : ($readyCount === count($records) ? 'external_supervised_cutover_receipts_complete_go_no_go_blocked' : 'external_supervised_cutover_promotion_waiting_on_receipts'),
            'generated_at' => now()->toJSON(),
            'summary' => [
                'company_count' => count($companyRows),
                'flow_count' => count($records),
                'work_order_count' => count($records),
                'promotion_review_ready_count' => $readyCount,
                'repository_toolchain_evidence_bound_count' => count(array_filter($records, static fn (array $record): bool => (bool) data_get($record, 'repository_toolchain_evidence.bound', false))),
                'receipt_incomplete_order_count' => count($records) - $readyCount,
                'work_item_count' => array_sum(array_map(static fn (array $record): int => (int) ($record['work_item_count'] ?? 0), $records)),
                'bound_receipt_count' => array_sum(array_map(static fn (array $record): int => (int) ($record['bound_receipt_count'] ?? 0), $records)),
                'pending_work_item_count' => array_sum(array_map(static fn (array $record): int => (int) ($record['pending_work_item_count'] ?? 0), $records)),
                'final_authority_binding_count' => array_sum(array_map(static fn (array $record): int => (int) ($record['final_authority_binding_count'] ?? 0), $records)),
                'missing_final_authority_count' => array_sum(array_map(static fn (array $record): int => (int) ($record['missing_final_authority_count'] ?? 0), $records)),
                'external_execution_allowed_count' => 0,
                'external_side_effects_enabled_count' => 0,
            ],
            'companies' => $companyRows,
            'policy' => [
                'promotion_status_is_not_execution_authority' => true,
                'operator_go_no_go_required' => true,
                'second_reviewer_go_no_go_required' => true,
                'vault_scope_release_required' => true,
                'reconciliation_sink_confirmation_required' => true,
                'external_execution_allowed' => false,
                'external_side_effects_enabled' => false,
                'calendar_wait_blocker_enabled' => false,
                'blocked_operations' => ['execute_work_order', 'cutover', 'launch', 'auto_launch', 'auto_dispatch', 'write', 'publish', 'spend', 'trade', 'deploy', 'delete', 'secret_export'],
            ],
        ];
        $payload['external_supervised_cutover_promotion_status_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function bindExternalSupervisedCutoverFinalAuthorityReceipt(
        ?string $workOrderId,
        ?string $authorityId,
        ?string $receiptHash,
        ?string $receiptSource = null,
        ?string $operator = null,
        ?string $note = null,
    ): array {
        $normalizedWorkOrderId = strtolower(trim((string) $workOrderId));
        $normalizedAuthorityId = trim((string) $authorityId);
        $normalizedReceiptHash = strtolower(trim((string) $receiptHash));
        $normalizedSource = trim((string) $receiptSource);
        $requiredAuthorities = $this->externalSupervisedCutoverRequiredFinalAuthorities();

        if (! preg_match('/\A[a-f0-9]{64}\z/', $normalizedWorkOrderId)) {
            return $this->externalSupervisedCutoverFinalAuthorityBindingRejected('invalid_work_order_id', $normalizedWorkOrderId);
        }

        if (! in_array($normalizedAuthorityId, $requiredAuthorities, true)) {
            return $this->externalSupervisedCutoverFinalAuthorityBindingRejected('invalid_final_authority', $normalizedWorkOrderId);
        }

        if (! preg_match('/\A[a-f0-9]{64}\z/', $normalizedReceiptHash)) {
            return $this->externalSupervisedCutoverFinalAuthorityBindingRejected('invalid_receipt_hash', $normalizedWorkOrderId);
        }

        if ($normalizedSource === '' || preg_match('/\A(?:fake|synthetic|test|fixture|mock|dummy)\z/i', $normalizedSource)) {
            return $this->externalSupervisedCutoverFinalAuthorityBindingRejected('invalid_receipt_source', $normalizedWorkOrderId);
        }

        $order = AiHoldingExternalCutoverWorkOrder::query()
            ->where('work_order_id', $normalizedWorkOrderId)
            ->first();

        if (! $order instanceof AiHoldingExternalCutoverWorkOrder) {
            return $this->externalSupervisedCutoverFinalAuthorityBindingRejected('work_order_not_found', $normalizedWorkOrderId);
        }

        $refreshed = $this->refreshExternalSupervisedCutoverWorkOrderCounts((string) $order->work_order_id) ?? $order;
        if ((int) $refreshed->pending_work_item_count > 0) {
            return $this->externalSupervisedCutoverFinalAuthorityBindingRejected('work_items_still_pending_receipts', $normalizedWorkOrderId);
        }

        $bindings = (array) $refreshed->final_authority_bindings_json;
        $binding = [
            'schema' => 'atlas.ai.company.external_supervised_cutover_final_authority_binding.v1',
            'work_order_id' => (string) $refreshed->work_order_id,
            'company_id' => (string) $refreshed->company_id,
            'flow_id' => (string) $refreshed->flow_id,
            'authority_id' => $normalizedAuthorityId,
            'receipt_hash' => $normalizedReceiptHash,
            'receipt_source' => $normalizedSource,
            'operator' => trim((string) ($operator ?? 'atlas_operator')),
            'note' => trim((string) ($note ?? '')),
            'synthetic_receipt_allowed' => false,
            'final_authority_binding_is_execution_authority' => false,
            'external_execution_allowed' => false,
            'external_side_effects_enabled' => false,
            'bound_at' => now()->toJSON(),
        ];
        $binding['final_authority_binding_hash'] = MissionCanonicalHash::sha256($binding);
        $bindings[$normalizedAuthorityId] = $binding;
        ksort($bindings);

        $bindingSetHash = MissionCanonicalHash::sha256([
            'schema' => 'atlas.ai.company.external_supervised_cutover_final_authority_binding_set.v1',
            'work_order_id' => (string) $refreshed->work_order_id,
            'bindings' => $bindings,
        ]);

        $refreshed->forceFill([
            'final_authority_bindings_json' => $bindings,
            'final_authority_binding_hash' => $bindingSetHash,
            'final_authority_binding_count' => count($bindings),
            'launch_blockers_json' => $this->externalSupervisedCutoverLaunchBlockersForAuthorityCount(count($bindings)),
            'status' => count($bindings) === count($requiredAuthorities)
                ? 'final_authorities_bound_manual_cutover_packet_ready_launch_still_blocked'
                : 'final_authority_receipts_partially_bound_launch_blocked',
            'cutover_decision' => count($bindings) === count($requiredAuthorities)
                ? 'manual_cutover_packet_ready_external_execution_still_requires_operator_runtime_invocation'
                : 'launch_blocked_until_all_final_authority_receipts_bound',
            'supervised_cutover_enabled' => false,
            'external_execution_allowed' => false,
            'external_side_effects_enabled' => false,
            'last_status_at' => now(),
        ])->save();

        $orderRecord = $this->externalSupervisedCutoverWorkOrderPersistedPayload($refreshed->refresh());
        $payload = [
            'ok' => true,
            'schema' => ExternalActionMandateRegistryService::EXTERNAL_SUPERVISED_CUTOVER_FINAL_AUTHORITY_BINDING_SCHEMA,
            'status' => 'external_supervised_cutover_final_authority_receipt_bound_launch_blocked',
            'generated_at' => now()->toJSON(),
            'summary' => [
                'work_order_count' => 1,
                'required_final_authority_count' => count($requiredAuthorities),
                'final_authority_binding_count' => count($bindings),
                'missing_final_authority_count' => count(array_diff($requiredAuthorities, array_keys($bindings))),
                'external_execution_allowed_count' => 0,
                'external_side_effects_enabled_count' => 0,
            ],
            'binding' => $binding,
            'work_order' => $orderRecord,
            'policy' => [
                'final_authority_binding_is_not_execution_authority' => true,
                'manual_runtime_invocation_required_after_authority_binding' => true,
                'synthetic_receipts_count_as_real_external_authority' => false,
                'external_execution_allowed' => false,
                'external_side_effects_enabled' => false,
                'blocked_operations' => ['execute_work_order', 'cutover', 'launch', 'auto_launch', 'auto_dispatch', 'write', 'publish', 'spend', 'trade', 'deploy', 'delete', 'secret_export'],
            ],
        ];
        $payload['external_supervised_cutover_final_authority_binding_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function registerExternalSupervisedCutoverRuntimeInvocation(?string $workOrderId): array
    {
        $normalizedWorkOrderId = strtolower(trim((string) $workOrderId));
        $requiredAuthorities = $this->externalSupervisedCutoverRequiredFinalAuthorities();

        if (! preg_match('/\A[a-f0-9]{64}\z/', $normalizedWorkOrderId)) {
            return $this->externalSupervisedCutoverRuntimeInvocationRejected('invalid_work_order_id', $normalizedWorkOrderId);
        }

        $order = AiHoldingExternalCutoverWorkOrder::query()
            ->where('work_order_id', $normalizedWorkOrderId)
            ->first();

        if (! $order instanceof AiHoldingExternalCutoverWorkOrder) {
            return $this->externalSupervisedCutoverRuntimeInvocationRejected('work_order_not_found', $normalizedWorkOrderId);
        }

        $refreshed = $this->refreshExternalSupervisedCutoverWorkOrderCounts((string) $order->work_order_id) ?? $order;
        $orderRecord = $this->externalSupervisedCutoverWorkOrderPersistedPayload($refreshed->refresh());
        $repositoryToolchainEvidence = (array) ($orderRecord['repository_toolchain_evidence'] ?? []);
        $bindings = (array) $refreshed->final_authority_bindings_json;
        $missingAuthorities = array_values(array_diff($requiredAuthorities, array_keys($bindings)));

        if ((int) $refreshed->pending_work_item_count > 0) {
            return $this->externalSupervisedCutoverRuntimeInvocationRejected('work_items_still_pending_receipts', $normalizedWorkOrderId);
        }

        if ($missingAuthorities !== []) {
            return $this->externalSupervisedCutoverRuntimeInvocationRejected('final_authority_receipts_missing', $normalizedWorkOrderId, $missingAuthorities);
        }

        if (! (bool) ($repositoryToolchainEvidence['bound'] ?? false)) {
            return $this->externalSupervisedCutoverRuntimeInvocationRejected('repository_toolchain_certification_receipts_missing', $normalizedWorkOrderId);
        }

        $packet = [
            'schema' => 'atlas.ai.company.external_supervised_cutover_runtime_invocation_packet.v1',
            'work_order_id' => (string) $refreshed->work_order_id,
            'company_id' => (string) $refreshed->company_id,
            'flow_id' => (string) $refreshed->flow_id,
            'mode' => 'manual_operator_runtime_invocation_packet',
            'source_work_order_hash' => (string) $refreshed->work_order_hash,
            'source_final_authority_binding_hash' => (string) $refreshed->final_authority_binding_hash,
            'required_final_authorities' => $requiredAuthorities,
            'final_authority_bindings' => $bindings,
            'operator_runtime_contract' => [
                'decision_receipt_required' => true,
                'runtime_invocation_contract_required' => true,
                'operator_present_required' => true,
                'second_reviewer_present_required' => true,
                'least_privilege_connector_scope_required' => true,
                'kill_switch_required' => true,
                'post_execution_reconciliation_required' => true,
                'rollback_plan_required' => true,
                'external_result_claim_requires_receipts' => true,
                'auto_launch_allowed' => false,
                'repository_toolchain_evidence' => $repositoryToolchainEvidence,
            ],
            'repository_toolchain_evidence' => $repositoryToolchainEvidence,
            'blocked_operations' => ['auto_launch', 'auto_dispatch', 'unattended_cutover', 'write_without_runtime_receipt', 'publish_without_reconciliation', 'spend', 'trade', 'deploy', 'delete', 'secret_export'],
            'external_execution_allowed' => false,
            'external_side_effects_enabled' => false,
            'registered_at' => now()->toJSON(),
        ];
        $packet['runtime_invocation_packet_hash'] = MissionCanonicalHash::sha256($packet);
        $invocationId = hash('sha256', 'external_supervised_cutover_runtime_invocation|'.$normalizedWorkOrderId.'|'.$packet['runtime_invocation_packet_hash']);

        $invocation = AiHoldingExternalCutoverRuntimeInvocation::query()->updateOrCreate(
            ['work_order_id' => (string) $refreshed->work_order_id],
            [
                'invocation_id' => $invocationId,
                'company_id' => (string) $refreshed->company_id,
                'flow_id' => (string) $refreshed->flow_id,
                'status' => 'registered_manual_runtime_invocation_packet_launch_blocked',
                'mode' => 'manual_operator_runtime_invocation_packet',
                'source_work_order_hash' => (string) $refreshed->work_order_hash,
                'source_final_authority_binding_hash' => (string) $refreshed->final_authority_binding_hash,
                'runtime_invocation_packet_hash' => (string) $packet['runtime_invocation_packet_hash'],
                'required_final_authorities_json' => $requiredAuthorities,
                'final_authority_bindings_json' => $bindings,
                'operator_runtime_contract_json' => (array) $packet['operator_runtime_contract'],
                'blocked_operations_json' => (array) $packet['blocked_operations'],
                'external_execution_allowed' => false,
                'external_side_effects_enabled' => false,
                'registered_at' => now(),
                'last_status_at' => now(),
            ],
        );

        $payload = [
            'ok' => true,
            'schema' => ExternalActionMandateRegistryService::EXTERNAL_SUPERVISED_CUTOVER_RUNTIME_INVOCATION_REGISTRY_SCHEMA,
            'status' => 'external_supervised_cutover_runtime_invocation_registered_launch_blocked',
            'generated_at' => now()->toJSON(),
            'summary' => [
                'runtime_invocation_count' => 1,
                'required_final_authority_count' => count($requiredAuthorities),
                'final_authority_binding_count' => count($bindings),
                'repository_toolchain_evidence_bound_count' => (bool) ($repositoryToolchainEvidence['bound'] ?? false) ? 1 : 0,
                'external_execution_allowed_count' => 0,
                'external_side_effects_enabled_count' => 0,
            ],
            'record' => $this->externalSupervisedCutoverRuntimeInvocationPayload($invocation->refresh()),
            'policy' => [
                'runtime_invocation_packet_is_not_execution_authority' => true,
                'operator_runtime_must_execute_separately_with_decision_receipt' => true,
                'external_execution_allowed' => false,
                'external_side_effects_enabled' => false,
                'blocked_operations' => (array) $packet['blocked_operations'],
            ],
        ];
        $payload['external_supervised_cutover_runtime_invocation_registry_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function externalSupervisedCutoverRuntimeInvocationStatus(?string $companyId = null): array
    {
        $wantedCompany = $companyId !== null && trim($companyId) !== '' ? trim($companyId) : null;
        $records = AiHoldingExternalCutoverRuntimeInvocation::query()
            ->when($wantedCompany !== null, static fn ($query) => $query->where('company_id', $wantedCompany))
            ->orderBy('company_id')
            ->orderBy('flow_id')
            ->get()
            ->map(fn (AiHoldingExternalCutoverRuntimeInvocation $invocation): array => $this->externalSupervisedCutoverRuntimeInvocationPayload($invocation))
            ->all();

        $payload = [
            'ok' => true,
            'schema' => ExternalActionMandateRegistryService::EXTERNAL_SUPERVISED_CUTOVER_RUNTIME_INVOCATION_STATUS_SCHEMA,
            'status' => $records === [] ? 'empty_cutover_runtime_invocation_registry' : 'external_supervised_cutover_runtime_invocations_registered_launch_blocked',
            'generated_at' => now()->toJSON(),
            'summary' => [
                'company_count' => count(array_unique(array_map(static fn (array $record): string => (string) ($record['company_id'] ?? ''), $records))),
                'flow_count' => count(array_unique(array_map(static fn (array $record): string => (string) ($record['company_id'] ?? '').'|'.(string) ($record['flow_id'] ?? ''), $records))),
                'runtime_invocation_count' => count($records),
                'repository_toolchain_evidence_bound_count' => count(array_filter($records, static fn (array $record): bool => (bool) data_get($record, 'repository_toolchain_evidence.bound', false))),
                'rehearsal_executed_count' => count(array_filter($records, static fn (array $record): bool => (int) ($record['execution_receipt_count'] ?? 0) > 0)),
                'execution_receipt_count' => array_sum(array_map(static fn (array $record): int => (int) ($record['execution_receipt_count'] ?? 0), $records)),
                'external_execution_allowed_count' => 0,
                'external_side_effects_enabled_count' => 0,
            ],
            'records' => $records,
            'policy' => [
                'runtime_invocation_packet_is_not_execution_authority' => true,
                'operator_runtime_must_execute_separately_with_decision_receipt' => true,
                'external_execution_allowed' => false,
                'external_side_effects_enabled' => false,
            ],
        ];
        $payload['external_supervised_cutover_runtime_invocation_status_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function executeExternalSupervisedCutoverRuntimeRehearsal(?string $invocationId, ?string $companyId = null): array
    {
        $normalizedInvocationId = strtolower(trim((string) $invocationId));
        $wantedCompany = $companyId !== null && trim($companyId) !== '' ? trim($companyId) : null;

        if ($normalizedInvocationId !== '' && ! preg_match('/\A[a-f0-9]{64}\z/', $normalizedInvocationId)) {
            return $this->externalSupervisedCutoverRuntimeRehearsalRejected('invalid_invocation_id', $normalizedInvocationId);
        }

        $invocations = AiHoldingExternalCutoverRuntimeInvocation::query()
            ->when($normalizedInvocationId !== '', static fn ($query) => $query->where('invocation_id', $normalizedInvocationId))
            ->when($wantedCompany !== null, static fn ($query) => $query->where('company_id', $wantedCompany))
            ->orderBy('company_id')
            ->orderBy('flow_id')
            ->get();

        if ($invocations->isEmpty()) {
            return $this->externalSupervisedCutoverRuntimeRehearsalRejected(
                $normalizedInvocationId !== '' ? 'invocation_not_found' : 'no_registered_runtime_invocations',
                $normalizedInvocationId,
            );
        }

        $receipts = [];
        $records = [];

        foreach ($invocations as $invocation) {
            $repositoryToolchainEvidence = (array) data_get($invocation->operator_runtime_contract_json, 'repository_toolchain_evidence', []);
            if ($repositoryToolchainEvidence === []) {
                $repositoryToolchainEvidence = $this->externalSupervisedCutoverRepositoryToolchainEvidenceForWorkOrderId((string) $invocation->work_order_id);
            }

            $receipt = [
                'schema' => 'atlas.ai.company.external_supervised_cutover_runtime_rehearsal_receipt.v1',
                'invocation_id' => (string) $invocation->invocation_id,
                'work_order_id' => (string) $invocation->work_order_id,
                'company_id' => (string) $invocation->company_id,
                'flow_id' => (string) $invocation->flow_id,
                'mode' => 'non_production_rehearsal',
                'source_runtime_invocation_packet_hash' => (string) $invocation->runtime_invocation_packet_hash,
                'repository_toolchain_evidence' => $repositoryToolchainEvidence,
                'decision_receipt_required_for_real_execution' => true,
                'external_execution_attempted' => false,
                'external_side_effects_attempted' => false,
                'connector_mutation_attempted' => false,
                'reconciliation' => [
                    'post_execution_reconciliation_required' => true,
                    'external_result_claim_allowed' => false,
                    'operator_closeout_required' => true,
                    'rollback_plan_confirmed' => true,
                ],
                'blocked_operations' => array_values((array) $invocation->blocked_operations_json),
                'executed_at' => now()->toJSON(),
            ];
            $receipt['execution_receipt_hash'] = MissionCanonicalHash::sha256($receipt);

            $invocationReceipts = array_values((array) $invocation->execution_receipts_json);
            $invocationReceipts[] = $receipt;

            $invocation->forceFill([
                'status' => 'rehearsal_executed_external_execution_blocked',
                'execution_receipts_json' => $invocationReceipts,
                'last_execution_receipt_hash' => (string) $receipt['execution_receipt_hash'],
                'execution_receipt_count' => count($invocationReceipts),
                'external_execution_allowed' => false,
                'external_side_effects_enabled' => false,
                'last_executed_at' => now(),
                'last_status_at' => now(),
            ])->save();

            $receipts[] = $receipt;
            $records[] = $this->externalSupervisedCutoverRuntimeInvocationPayload($invocation->refresh());
        }

        $payload = [
            'ok' => true,
            'schema' => ExternalActionMandateRegistryService::EXTERNAL_SUPERVISED_CUTOVER_RUNTIME_REHEARSAL_EXECUTION_SCHEMA,
            'status' => 'external_supervised_cutover_runtime_rehearsal_executed_external_execution_blocked',
            'generated_at' => now()->toJSON(),
            'summary' => [
                'runtime_invocation_count' => count($records),
                'execution_receipt_count' => count($receipts),
                'repository_toolchain_evidence_bound_count' => count(array_filter($records, static fn (array $record): bool => (bool) data_get($record, 'repository_toolchain_evidence.bound', false))),
                'external_execution_allowed_count' => 0,
                'external_side_effects_enabled_count' => 0,
            ],
            'receipts' => $receipts,
            'records' => $records,
            'receipt' => $receipts[0],
            'record' => $records[0],
            'policy' => [
                'rehearsal_is_not_real_external_execution' => true,
                'decision_receipt_required_for_real_execution' => true,
                'external_result_claim_allowed_without_receipt' => false,
                'external_execution_allowed' => false,
                'external_side_effects_enabled' => false,
            ],
        ];
        $payload['external_supervised_cutover_runtime_rehearsal_execution_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function externalSupervisedCutoverRehearsalPromotionStatus(?string $companyId = null): array
    {
        $promotion = $this->externalSupervisedCutoverPromotionStatus($companyId);
        $runtime = $this->externalSupervisedCutoverRuntimeInvocationStatus($companyId);

        $invocationsByWorkOrder = [];
        foreach ((array) ($runtime['records'] ?? []) as $invocation) {
            $invocationsByWorkOrder[(string) ($invocation['work_order_id'] ?? '')] = $invocation;
        }

        $companyRows = [];
        $allPackets = [];

        foreach ((array) ($promotion['companies'] ?? []) as $company) {
            $packets = [];
            foreach ((array) ($company['flow_promotion_packets'] ?? []) as $packet) {
                $workOrderId = (string) ($packet['work_order_id'] ?? '');
                $invocation = (array) ($invocationsByWorkOrder[$workOrderId] ?? []);
                $runtimeInvocationRegistered = $invocation !== [];
                $rehearsalReceiptCount = (int) ($invocation['execution_receipt_count'] ?? 0);
                $repositoryToolchainEvidence = (array) ($packet['repository_toolchain_evidence'] ?? []);
                $runtimeRepositoryToolchainEvidence = (array) ($invocation['repository_toolchain_evidence'] ?? []);
                $repositoryToolchainBound = (bool) ($repositoryToolchainEvidence['bound'] ?? false)
                    && (! $runtimeInvocationRegistered || (bool) ($runtimeRepositoryToolchainEvidence['bound'] ?? false));
                $finalAuthoritiesBound = (int) ($packet['missing_final_authority_count'] ?? 0) === 0
                    && (int) ($packet['final_authority_binding_count'] ?? 0) === count($this->externalSupervisedCutoverRequiredFinalAuthorities());
                $manualExecutionPacketReady = (bool) ($packet['promotion_review_ready'] ?? false)
                    && $repositoryToolchainBound
                    && $finalAuthoritiesBound
                    && $runtimeInvocationRegistered
                    && $rehearsalReceiptCount > 0;

                $blockers = [];
                if (! (bool) ($packet['promotion_review_ready'] ?? false)) {
                    $blockers[] = 'work_item_receipts_incomplete';
                }
                if (! $finalAuthoritiesBound) {
                    $blockers[] = 'final_authority_receipts_incomplete';
                }
                if (! $repositoryToolchainBound) {
                    $blockers[] = 'repository_toolchain_certification_receipts_incomplete';
                }
                if (! $runtimeInvocationRegistered) {
                    $blockers[] = 'runtime_invocation_packet_missing';
                }
                if ($runtimeInvocationRegistered && $rehearsalReceiptCount === 0) {
                    $blockers[] = 'runtime_rehearsal_receipt_missing';
                }

                $row = [
                    'schema' => 'atlas.ai.company.external_supervised_cutover_rehearsal_promotion_packet.v1',
                    'company_id' => (string) ($packet['company_id'] ?? ''),
                    'flow_id' => (string) ($packet['flow_id'] ?? ''),
                    'work_order_id' => $workOrderId,
                    'runtime_invocation_id' => $runtimeInvocationRegistered ? (string) ($invocation['invocation_id'] ?? '') : null,
                    'manual_execution_packet_ready' => $manualExecutionPacketReady,
                    'promotion_state' => $manualExecutionPacketReady
                        ? 'rehearsal_complete_manual_execution_packet_ready_external_launch_blocked'
                        : 'rehearsal_promotion_blocked',
                    'work_item_receipts_complete' => (bool) ($packet['promotion_review_ready'] ?? false),
                    'repository_toolchain_evidence_bound' => $repositoryToolchainBound,
                    'repository_toolchain_evidence' => $runtimeInvocationRegistered ? $runtimeRepositoryToolchainEvidence : $repositoryToolchainEvidence,
                    'final_authorities_bound' => $finalAuthoritiesBound,
                    'runtime_invocation_registered' => $runtimeInvocationRegistered,
                    'runtime_rehearsal_executed' => $rehearsalReceiptCount > 0,
                    'execution_receipt_count' => $rehearsalReceiptCount,
                    'last_execution_receipt_hash' => $invocation['last_execution_receipt_hash'] ?? null,
                    'promotion_blockers' => $blockers,
                    'operator_runtime_contract' => (array) ($invocation['operator_runtime_contract'] ?? []),
                    'blocked_operations' => array_values(array_unique(array_merge(
                        (array) ($packet['promotion_blockers'] ?? []),
                        (array) ($invocation['blocked_operations'] ?? []),
                        ['auto_launch', 'unattended_cutover', 'external_write_without_decision_receipt'],
                    ))),
                    'external_execution_allowed' => false,
                    'external_side_effects_enabled' => false,
                    'source_promotion_packet_hash' => (string) ($packet['external_supervised_cutover_promotion_packet_hash'] ?? ''),
                    'source_runtime_invocation_packet_hash' => $invocation['runtime_invocation_packet_hash'] ?? null,
                ];
                $row['external_supervised_cutover_rehearsal_promotion_packet_hash'] = MissionCanonicalHash::sha256($row);
                $packets[] = $row;
                $allPackets[] = $row;
            }

            $readyCount = count(array_filter($packets, static fn (array $packet): bool => (bool) ($packet['manual_execution_packet_ready'] ?? false)));
            $companyRow = [
                'schema' => 'atlas.ai.company.external_supervised_cutover_rehearsal_promotion_status.v1',
                'company_id' => (string) ($company['company_id'] ?? ''),
                'flow_count' => count($packets),
                'manual_execution_packet_ready_count' => $readyCount,
                'repository_toolchain_evidence_bound_count' => count(array_filter($packets, static fn (array $packet): bool => (bool) ($packet['repository_toolchain_evidence_bound'] ?? false))),
                'manual_execution_packet_blocked_count' => count($packets) - $readyCount,
                'execution_receipt_count' => array_sum(array_map(static fn (array $packet): int => (int) ($packet['execution_receipt_count'] ?? 0), $packets)),
                'flow_rehearsal_promotion_packets' => $packets,
            ];
            $companyRow['company_external_supervised_cutover_rehearsal_promotion_status_hash'] = MissionCanonicalHash::sha256($companyRow);
            $companyRows[] = $companyRow;
        }

        $readyCount = count(array_filter($allPackets, static fn (array $packet): bool => (bool) ($packet['manual_execution_packet_ready'] ?? false)));
        $payload = [
            'ok' => true,
            'schema' => ExternalActionMandateRegistryService::EXTERNAL_SUPERVISED_CUTOVER_REHEARSAL_PROMOTION_STATUS_SCHEMA,
            'status' => $allPackets === []
                ? 'empty_cutover_rehearsal_promotion_registry'
                : ($readyCount === count($allPackets)
                    ? 'external_supervised_cutover_rehearsal_complete_manual_execution_packet_ready_external_launch_blocked'
                    : 'external_supervised_cutover_rehearsal_promotion_waiting_on_evidence'),
            'generated_at' => now()->toJSON(),
            'summary' => [
                'company_count' => count($companyRows),
                'flow_count' => count($allPackets),
                'manual_execution_packet_ready_count' => $readyCount,
                'repository_toolchain_evidence_bound_count' => count(array_filter($allPackets, static fn (array $packet): bool => (bool) ($packet['repository_toolchain_evidence_bound'] ?? false))),
                'manual_execution_packet_blocked_count' => count($allPackets) - $readyCount,
                'runtime_invocation_count' => (int) data_get($runtime, 'summary.runtime_invocation_count', 0),
                'rehearsal_executed_count' => (int) data_get($runtime, 'summary.rehearsal_executed_count', 0),
                'execution_receipt_count' => (int) data_get($runtime, 'summary.execution_receipt_count', 0),
                'external_execution_allowed_count' => 0,
                'external_side_effects_enabled_count' => 0,
            ],
            'companies' => $companyRows,
            'source_status_hashes' => [
                'promotion_status_hash' => $promotion['external_supervised_cutover_promotion_status_hash'] ?? null,
                'runtime_invocation_status_hash' => $runtime['external_supervised_cutover_runtime_invocation_status_hash'] ?? null,
            ],
            'policy' => [
                'rehearsal_promotion_status_is_not_execution_authority' => true,
                'manual_execution_packet_ready_still_requires_decision_receipt' => true,
                'operator_runtime_must_execute_separately' => true,
                'post_execution_reconciliation_required' => true,
                'external_execution_allowed' => false,
                'external_side_effects_enabled' => false,
                'blocked_operations' => ['auto_launch', 'unattended_cutover', 'external_write_without_decision_receipt', 'claim_external_result_without_receipt'],
            ],
        ];
        $payload['external_supervised_cutover_rehearsal_promotion_status_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function externalSupervisedCutoverWorkItemReceiptBindingRejected(string $reason, string $workItemId): array
    {
        $payload = [
            'ok' => false,
            'schema' => ExternalActionMandateRegistryService::EXTERNAL_SUPERVISED_CUTOVER_WORK_ITEM_RECEIPT_BINDING_SCHEMA,
            'status' => 'external_supervised_cutover_work_item_receipt_binding_rejected',
            'generated_at' => now()->toJSON(),
            'reason' => $reason,
            'work_item_id' => $workItemId,
            'policy' => [
                'receipt_binding_is_not_execution_authority' => true,
                'synthetic_receipts_count_as_real_external_authority' => false,
                'external_execution_allowed' => false,
                'external_side_effects_enabled' => false,
            ],
        ];
        $payload['external_supervised_cutover_work_item_receipt_binding_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function externalSupervisedCutoverFinalAuthorityBindingRejected(string $reason, string $workOrderId): array
    {
        $payload = [
            'ok' => false,
            'schema' => ExternalActionMandateRegistryService::EXTERNAL_SUPERVISED_CUTOVER_FINAL_AUTHORITY_BINDING_SCHEMA,
            'status' => 'external_supervised_cutover_final_authority_binding_rejected',
            'generated_at' => now()->toJSON(),
            'reason' => $reason,
            'work_order_id' => $workOrderId,
            'policy' => [
                'final_authority_binding_is_not_execution_authority' => true,
                'synthetic_receipts_count_as_real_external_authority' => false,
                'external_execution_allowed' => false,
                'external_side_effects_enabled' => false,
            ],
        ];
        $payload['external_supervised_cutover_final_authority_binding_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @param list<string> $missingAuthorities
     * @return array<string,mixed>
     */
    public function externalSupervisedCutoverRuntimeInvocationRejected(string $reason, string $workOrderId, array $missingAuthorities = []): array
    {
        $payload = [
            'ok' => false,
            'schema' => ExternalActionMandateRegistryService::EXTERNAL_SUPERVISED_CUTOVER_RUNTIME_INVOCATION_REGISTRY_SCHEMA,
            'status' => 'external_supervised_cutover_runtime_invocation_rejected',
            'generated_at' => now()->toJSON(),
            'reason' => $reason,
            'work_order_id' => $workOrderId,
            'missing_final_authorities' => $missingAuthorities,
            'policy' => [
                'runtime_invocation_packet_is_not_execution_authority' => true,
                'external_execution_allowed' => false,
                'external_side_effects_enabled' => false,
            ],
        ];
        $payload['external_supervised_cutover_runtime_invocation_registry_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function externalSupervisedCutoverRuntimeRehearsalRejected(string $reason, string $invocationId): array
    {
        $payload = [
            'ok' => false,
            'schema' => ExternalActionMandateRegistryService::EXTERNAL_SUPERVISED_CUTOVER_RUNTIME_REHEARSAL_EXECUTION_SCHEMA,
            'status' => 'external_supervised_cutover_runtime_rehearsal_rejected',
            'generated_at' => now()->toJSON(),
            'reason' => $reason,
            'invocation_id' => $invocationId,
            'policy' => [
                'rehearsal_is_not_real_external_execution' => true,
                'external_execution_allowed' => false,
                'external_side_effects_enabled' => false,
            ],
        ];
        $payload['external_supervised_cutover_runtime_rehearsal_execution_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @param array<string,mixed> $workOrder
     * @return array<string,mixed>
     */
    public function persistExternalSupervisedCutoverWorkOrder(array $workOrder): array
    {
        $incomingWorkItems = array_values((array) ($workOrder['work_items'] ?? []));
        $companyId = (string) ($workOrder['company_id'] ?? 'unknown');
        $flowId = (string) ($workOrder['flow_id'] ?? 'unknown');
        $workOrderId = (string) ($workOrder['work_order_id'] ?? '');
        $existingOrder = AiHoldingExternalCutoverWorkOrder::query()
            ->where('work_order_id', $workOrderId)
            ->first();

        if (! $existingOrder instanceof AiHoldingExternalCutoverWorkOrder) {
            $existingOrder = AiHoldingExternalCutoverWorkOrder::query()
                ->where('company_id', $companyId)
                ->where('flow_id', $flowId)
                ->first();
        }

        $persistedWorkOrderId = $existingOrder instanceof AiHoldingExternalCutoverWorkOrder
            ? (string) $existingOrder->work_order_id
            : $workOrderId;
        $existingFinalAuthorityBindings = $existingOrder instanceof AiHoldingExternalCutoverWorkOrder
            ? (array) $existingOrder->final_authority_bindings_json
            : [];
        $existingWorkItems = $existingOrder instanceof AiHoldingExternalCutoverWorkOrder
            ? AiHoldingExternalCutoverWorkItem::query()
                ->where('work_order_id', (string) $existingOrder->work_order_id)
                ->get()
                ->map(fn (AiHoldingExternalCutoverWorkItem $item): array => $this->externalSupervisedCutoverWorkItemPersistedPayload($item))
                ->all()
            : [];
        $workItemsByAction = [];
        foreach ($existingWorkItems as $existingItem) {
            $workItemsByAction[(string) ($existingItem['action_id'] ?? '')] = $existingItem;
        }
        foreach ($incomingWorkItems as $incomingItem) {
            $workItemsByAction[(string) ($incomingItem['action_id'] ?? '')] = (array) $incomingItem;
        }
        $workItems = array_values($workItemsByAction);
        $pendingCount = count(array_filter($workItems, static fn (array $item): bool => ($item['status'] ?? '') === 'pending_real_receipt_or_operator_action'));
        $boundReceiptCount = count(array_filter($workItems, static fn (array $item): bool => ($item['bound_receipt_hash'] ?? null) !== null));
        $order = AiHoldingExternalCutoverWorkOrder::query()->updateOrCreate(
            ['work_order_id' => $persistedWorkOrderId],
            [
                'company_id' => $companyId,
                'flow_id' => $flowId,
                'status' => 'pending_real_receipts_launch_blocked',
                'phase' => (string) ($workOrder['phase'] ?? 'receipt_intake_and_operator_cutover_preparation'),
                'cutover_decision' => (string) ($workOrder['cutover_decision'] ?? 'launch_blocked_until_work_items_have_real_receipts'),
                'source_cutover_dossier_hash' => (string) ($workOrder['source_external_supervised_cutover_dossier_hash'] ?? ''),
                'work_order_hash' => (string) ($workOrder['external_supervised_cutover_work_order_hash'] ?? ''),
                'required_receipt_ids_json' => array_values((array) ($workOrder['required_receipt_ids'] ?? [])),
                'missing_receipt_ids_json' => array_values((array) ($workOrder['missing_receipt_ids'] ?? [])),
                'operator_enablement_pack_json' => (array) ($workOrder['operator_enablement_pack'] ?? []),
                'launch_blockers_json' => array_values((array) ($workOrder['launch_blockers'] ?? [])),
                'final_authority_bindings_json' => $existingFinalAuthorityBindings,
                'final_authority_binding_hash' => $existingOrder instanceof AiHoldingExternalCutoverWorkOrder ? $existingOrder->final_authority_binding_hash : null,
                'work_item_count' => count($workItems),
                'pending_work_item_count' => $pendingCount,
                'bound_receipt_count' => $boundReceiptCount,
                'final_authority_binding_count' => count($existingFinalAuthorityBindings),
                'work_order_ready' => (bool) ($workOrder['work_order_ready'] ?? false),
                'supervised_cutover_enabled' => false,
                'external_execution_allowed' => false,
                'external_side_effects_enabled' => false,
                'registered_at' => now(),
                'last_status_at' => now(),
            ],
        );

        foreach ($workItems as $item) {
            $item['work_order_id'] = $persistedWorkOrderId;
            $this->persistExternalSupervisedCutoverWorkItem((array) $item);
        }

        $refreshed = $this->refreshExternalSupervisedCutoverWorkOrderCounts((string) $order->work_order_id);

        return $this->externalSupervisedCutoverWorkOrderPersistedPayload(($refreshed ?? $order)->refresh());
    }

    /**
     * @param array<string,mixed> $item
     */
    public function persistExternalSupervisedCutoverWorkItem(array $item): AiHoldingExternalCutoverWorkItem
    {
        $workItemId = (string) ($item['work_item_id'] ?? '');
        $existing = AiHoldingExternalCutoverWorkItem::query()
            ->where('work_item_id', $workItemId)
            ->first();

        if (! $existing instanceof AiHoldingExternalCutoverWorkItem) {
            $existing = AiHoldingExternalCutoverWorkItem::query()
                ->where('work_order_id', (string) ($item['work_order_id'] ?? ''))
                ->where('action_id', (string) ($item['action_id'] ?? 'unknown_action'))
                ->first();
        }

        $persistedWorkItemId = $existing instanceof AiHoldingExternalCutoverWorkItem
            ? (string) $existing->work_item_id
            : $workItemId;
        $receiptBound = $existing instanceof AiHoldingExternalCutoverWorkItem && $existing->bound_receipt_hash !== null;

        return AiHoldingExternalCutoverWorkItem::query()->updateOrCreate(
            ['work_item_id' => $persistedWorkItemId],
            [
                'work_order_id' => (string) ($item['work_order_id'] ?? ''),
                'company_id' => (string) ($item['company_id'] ?? 'unknown'),
                'flow_id' => (string) ($item['flow_id'] ?? 'unknown'),
                'action_id' => (string) ($item['action_id'] ?? 'unknown_action'),
                'workstream' => (string) ($item['workstream'] ?? 'unknown_workstream'),
                'owner_role' => (string) ($item['owner_role'] ?? 'operator'),
                'status' => $receiptBound ? (string) $existing->status : (string) ($item['status'] ?? 'pending_real_receipt_or_operator_action'),
                'required_receipt_ids_json' => array_values((array) ($item['required_receipt_ids'] ?? [])),
                'completion_requires_json' => array_values((array) ($item['completion_requires'] ?? [])),
                'blocked_operations_json' => array_values((array) ($item['blocked_operations'] ?? [])),
                'source_cutover_dossier_hash' => (string) ($item['source_external_supervised_cutover_dossier_hash'] ?? ''),
                'work_item_hash' => (string) ($item['external_supervised_cutover_work_item_hash'] ?? ''),
                'bound_receipt_hash' => $receiptBound ? $existing->bound_receipt_hash : null,
                'receipt_binding_hash' => $receiptBound ? $existing->receipt_binding_hash : null,
                'receipt_binding_json' => $receiptBound ? (array) $existing->receipt_binding_json : null,
                'executable' => false,
                'external_execution_allowed' => false,
                'external_side_effects_enabled' => false,
                'registered_at' => now(),
                'completed_at' => $receiptBound ? $existing->completed_at : null,
            ],
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function externalSupervisedCutoverWorkOrderPersistedPayload(AiHoldingExternalCutoverWorkOrder $order): array
    {
        $items = AiHoldingExternalCutoverWorkItem::query()
            ->where('work_order_id', $order->work_order_id)
            ->orderBy('action_id')
            ->get()
            ->map(fn (AiHoldingExternalCutoverWorkItem $item): array => $this->externalSupervisedCutoverWorkItemPersistedPayload($item))
            ->all();

        $payload = [
            'schema' => 'atlas.ai.company.external_supervised_cutover_work_order_persisted.v1',
            'id' => (string) $order->id,
            'work_order_id' => (string) $order->work_order_id,
            'company_id' => (string) $order->company_id,
            'flow_id' => (string) $order->flow_id,
            'status' => (string) $order->status,
            'phase' => (string) $order->phase,
            'cutover_decision' => (string) $order->cutover_decision,
            'source_cutover_dossier_hash' => (string) $order->source_cutover_dossier_hash,
            'work_order_hash' => (string) $order->work_order_hash,
            'work_order_ready' => (bool) $order->work_order_ready,
            'work_item_count' => count($items),
            'pending_work_item_count' => count(array_filter($items, static fn (array $item): bool => ($item['status'] ?? '') === 'pending_real_receipt_or_operator_action')),
            'executable_item_count' => count(array_filter($items, static fn (array $item): bool => (bool) ($item['executable'] ?? false))),
            'bound_receipt_count' => (int) $order->bound_receipt_count,
            'final_authority_binding_count' => (int) $order->final_authority_binding_count,
            'final_authority_binding_hash' => $order->final_authority_binding_hash,
            'final_authority_bindings' => (array) $order->final_authority_bindings_json,
            'required_receipt_ids' => array_values((array) $order->required_receipt_ids_json),
            'missing_receipt_ids' => array_values((array) $order->missing_receipt_ids_json),
            'operator_enablement_pack' => (array) $order->operator_enablement_pack_json,
            'launch_blockers' => array_values((array) $order->launch_blockers_json),
            'supervised_cutover_enabled' => (bool) $order->supervised_cutover_enabled,
            'external_execution_allowed' => (bool) $order->external_execution_allowed,
            'external_side_effects_enabled' => (bool) $order->external_side_effects_enabled,
            'work_items' => $items,
            'registered_at' => $order->registered_at?->toJSON(),
            'last_status_at' => $order->last_status_at?->toJSON(),
        ];
        $payload['repository_toolchain_evidence'] = $this->externalSupervisedCutoverRepositoryToolchainEvidenceFromOrderRecord($payload);

        return $payload;
    }

    /**
     * @param array<string,mixed> $orderRecord
     * @return array<string,mixed>
     */
    public function externalSupervisedCutoverRepositoryToolchainEvidenceFromOrderRecord(array $orderRecord): array
    {
        $requiredReceiptIds = [
            'repository_tool_permission_manifest_receipt',
            'repository_eval_replay_recipe_receipt',
            'domain_toolchain_certification_receipt',
        ];
        $declaredReceiptIds = array_values(array_map('strval', (array) ($orderRecord['required_receipt_ids'] ?? [])));
        $items = array_values((array) ($orderRecord['work_items'] ?? []));
        $repositoryItems = array_values(array_filter(
            $items,
            static fn (array $item): bool => ($item['workstream'] ?? '') === 'repository_toolchain_certification'
                || count(array_intersect($requiredReceiptIds, array_values(array_map('strval', (array) ($item['required_receipt_ids'] ?? []))))) > 0,
        ));
        $boundReceiptIds = [];
        foreach ($repositoryItems as $item) {
            if (($item['bound_receipt_hash'] ?? null) === null) {
                continue;
            }
            foreach ((array) ($item['required_receipt_ids'] ?? []) as $receiptId) {
                $receiptId = (string) $receiptId;
                if (in_array($receiptId, $requiredReceiptIds, true)) {
                    $boundReceiptIds[] = $receiptId;
                }
            }
        }
        $boundReceiptIds = array_values(array_unique($boundReceiptIds));

        return [
            'schema' => 'atlas.ai.company.external_supervised_cutover_repository_toolchain_evidence.v1',
            'bound' => count(array_diff($requiredReceiptIds, $boundReceiptIds)) === 0,
            'required_receipt_ids' => $requiredReceiptIds,
            'declared_receipt_ids' => array_values(array_intersect($requiredReceiptIds, $declaredReceiptIds)),
            'bound_receipt_ids' => $boundReceiptIds,
            'missing_receipt_ids' => array_values(array_diff($requiredReceiptIds, $boundReceiptIds)),
            'repository_toolchain_work_item_count' => count($repositoryItems),
            'repository_toolchain_receipt_count' => count($boundReceiptIds),
            'source_cutover_dossier_hash' => (string) ($orderRecord['source_cutover_dossier_hash'] ?? ''),
            'source_work_order_hash' => (string) ($orderRecord['work_order_hash'] ?? ''),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function externalSupervisedCutoverWorkItemPersistedPayload(AiHoldingExternalCutoverWorkItem $item): array
    {
        return [
            'schema' => 'atlas.ai.company.external_supervised_cutover_work_item_persisted.v1',
            'id' => (string) $item->id,
            'work_item_id' => (string) $item->work_item_id,
            'work_order_id' => (string) $item->work_order_id,
            'company_id' => (string) $item->company_id,
            'flow_id' => (string) $item->flow_id,
            'action_id' => (string) $item->action_id,
            'workstream' => (string) $item->workstream,
            'owner_role' => (string) $item->owner_role,
            'status' => (string) $item->status,
            'required_receipt_ids' => array_values((array) $item->required_receipt_ids_json),
            'completion_requires' => array_values((array) $item->completion_requires_json),
            'blocked_operations' => array_values((array) $item->blocked_operations_json),
            'source_cutover_dossier_hash' => (string) $item->source_cutover_dossier_hash,
            'work_item_hash' => (string) $item->work_item_hash,
            'bound_receipt_hash' => $item->bound_receipt_hash,
            'receipt_binding_hash' => $item->receipt_binding_hash,
            'receipt_binding' => (array) $item->receipt_binding_json,
            'executable' => (bool) $item->executable,
            'external_execution_allowed' => (bool) $item->external_execution_allowed,
            'external_side_effects_enabled' => (bool) $item->external_side_effects_enabled,
            'registered_at' => $item->registered_at?->toJSON(),
            'completed_at' => $item->completed_at?->toJSON(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function externalSupervisedCutoverRuntimeInvocationPayload(AiHoldingExternalCutoverRuntimeInvocation $invocation): array
    {
        $invocation = $this->refreshExternalSupervisedCutoverRuntimeInvocationRepositoryToolchainEvidence($invocation);
        $repositoryToolchainEvidence = (array) data_get($invocation->operator_runtime_contract_json, 'repository_toolchain_evidence', []);

        return [
            'schema' => 'atlas.ai.company.external_supervised_cutover_runtime_invocation_persisted.v1',
            'id' => (string) $invocation->id,
            'invocation_id' => (string) $invocation->invocation_id,
            'work_order_id' => (string) $invocation->work_order_id,
            'company_id' => (string) $invocation->company_id,
            'flow_id' => (string) $invocation->flow_id,
            'status' => (string) $invocation->status,
            'mode' => (string) $invocation->mode,
            'source_work_order_hash' => (string) $invocation->source_work_order_hash,
            'source_final_authority_binding_hash' => (string) $invocation->source_final_authority_binding_hash,
            'runtime_invocation_packet_hash' => (string) $invocation->runtime_invocation_packet_hash,
            'required_final_authorities' => array_values((array) $invocation->required_final_authorities_json),
            'final_authority_bindings' => (array) $invocation->final_authority_bindings_json,
            'operator_runtime_contract' => (array) $invocation->operator_runtime_contract_json,
            'repository_toolchain_evidence' => $repositoryToolchainEvidence,
            'blocked_operations' => array_values((array) $invocation->blocked_operations_json),
            'execution_receipts' => array_values((array) $invocation->execution_receipts_json),
            'last_execution_receipt_hash' => $invocation->last_execution_receipt_hash,
            'execution_receipt_count' => (int) $invocation->execution_receipt_count,
            'manual_handoff_packet' => (array) $invocation->manual_handoff_packet_json,
            'manual_handoff_packet_hash' => $invocation->manual_handoff_packet_hash,
            'manual_closeout_receipts' => array_values((array) $invocation->manual_closeout_receipts_json),
            'last_manual_closeout_receipt_hash' => $invocation->last_manual_closeout_receipt_hash,
            'manual_closeout_receipt_count' => (int) $invocation->manual_closeout_receipt_count,
            'external_execution_allowed' => (bool) $invocation->external_execution_allowed,
            'external_side_effects_enabled' => (bool) $invocation->external_side_effects_enabled,
            'registered_at' => $invocation->registered_at?->toJSON(),
            'last_status_at' => $invocation->last_status_at?->toJSON(),
            'last_executed_at' => $invocation->last_executed_at?->toJSON(),
            'manual_handoff_registered_at' => $invocation->manual_handoff_registered_at?->toJSON(),
            'manual_closeout_registered_at' => $invocation->manual_closeout_registered_at?->toJSON(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function externalSupervisedCutoverRepositoryToolchainEvidenceForWorkOrderId(string $workOrderId): array
    {
        $order = AiHoldingExternalCutoverWorkOrder::query()
            ->where('work_order_id', $workOrderId)
            ->first();

        if (! $order instanceof AiHoldingExternalCutoverWorkOrder) {
            return [];
        }

        return (array) ($this->externalSupervisedCutoverWorkOrderPersistedPayload($order)['repository_toolchain_evidence'] ?? []);
    }

    public function refreshExternalSupervisedCutoverRuntimeInvocationRepositoryToolchainEvidence(
        AiHoldingExternalCutoverRuntimeInvocation $invocation,
    ): AiHoldingExternalCutoverRuntimeInvocation {
        $contract = (array) $invocation->operator_runtime_contract_json;
        if (data_get($contract, 'repository_toolchain_evidence.bound') === true) {
            return $invocation;
        }

        $repositoryToolchainEvidence = $this->externalSupervisedCutoverRepositoryToolchainEvidenceForWorkOrderId((string) $invocation->work_order_id);
        if (! (bool) ($repositoryToolchainEvidence['bound'] ?? false)) {
            return $invocation;
        }

        $contract['repository_toolchain_evidence'] = $repositoryToolchainEvidence;
        $invocation->forceFill([
            'operator_runtime_contract_json' => $contract,
            'last_status_at' => now(),
        ])->save();

        return $invocation->refresh();
    }

    /**
     * @param list<array<string,mixed>> $records
     * @return array<string,int>
     */
    public function externalSupervisedCutoverPersistedSummary(array $records): array
    {
        return [
            'company_count' => count(array_unique(array_map(static fn (array $record): string => (string) ($record['company_id'] ?? ''), $records))),
            'flow_count' => count(array_unique(array_map(static fn (array $record): string => (string) ($record['company_id'] ?? '').'|'.(string) ($record['flow_id'] ?? ''), $records))),
            'work_order_count' => count($records),
            'work_order_ready_count' => count(array_filter($records, static fn (array $record): bool => (bool) ($record['work_order_ready'] ?? false))),
            'work_item_count' => array_sum(array_map(static fn (array $record): int => (int) ($record['work_item_count'] ?? 0), $records)),
            'pending_work_item_count' => array_sum(array_map(static fn (array $record): int => (int) ($record['pending_work_item_count'] ?? 0), $records)),
            'executable_item_count' => array_sum(array_map(static fn (array $record): int => (int) ($record['executable_item_count'] ?? 0), $records)),
            'bound_receipt_count' => array_sum(array_map(static fn (array $record): int => (int) ($record['bound_receipt_count'] ?? 0), $records)),
            'supervised_cutover_enabled_count' => 0,
            'external_execution_allowed_count' => 0,
            'external_side_effects_enabled_count' => 0,
        ];
    }

    public function refreshExternalSupervisedCutoverWorkOrderCounts(string $workOrderId): ?AiHoldingExternalCutoverWorkOrder
    {
        $order = AiHoldingExternalCutoverWorkOrder::query()
            ->where('work_order_id', $workOrderId)
            ->first();

        if (! $order instanceof AiHoldingExternalCutoverWorkOrder) {
            return null;
        }

        $items = AiHoldingExternalCutoverWorkItem::query()
            ->where('work_order_id', $workOrderId)
            ->get();
        $pendingCount = $items
            ->filter(static fn (AiHoldingExternalCutoverWorkItem $item): bool => (string) $item->status === 'pending_real_receipt_or_operator_action')
            ->count();
        $boundCount = $items
            ->filter(static fn (AiHoldingExternalCutoverWorkItem $item): bool => $item->bound_receipt_hash !== null)
            ->count();
        $requiredAuthorities = $this->externalSupervisedCutoverRequiredFinalAuthorities();
        $finalAuthorityBindings = (array) $order->final_authority_bindings_json;
        $finalAuthorityCount = count($finalAuthorityBindings);
        $launchBlockers = $pendingCount > 0
            ? array_values(array_unique(array_merge(
                ['work_items_pending_real_receipts'],
                ['operator_go_no_go_missing', 'second_reviewer_go_no_go_missing', 'vault_scope_not_released', 'reconciliation_sink_not_confirmed'],
            )))
            : $this->externalSupervisedCutoverLaunchBlockersForAuthorityCount($finalAuthorityCount);
        $status = match (true) {
            $pendingCount > 0 => 'pending_real_receipts_launch_blocked',
            $finalAuthorityCount === count($requiredAuthorities) => 'final_authorities_bound_manual_cutover_packet_ready_launch_still_blocked',
            $finalAuthorityCount > 0 => 'final_authority_receipts_partially_bound_launch_blocked',
            default => 'all_work_item_receipts_bound_launch_still_blocked',
        };
        $cutoverDecision = match (true) {
            $pendingCount > 0 => 'launch_blocked_until_work_items_have_real_receipts',
            $finalAuthorityCount === count($requiredAuthorities) => 'manual_cutover_packet_ready_external_execution_still_requires_operator_runtime_invocation',
            $finalAuthorityCount > 0 => 'launch_blocked_until_all_final_authority_receipts_bound',
            default => 'launch_blocked_until_operator_go_no_go_and_vault_scope_release',
        };

        $order->forceFill([
            'status' => $status,
            'cutover_decision' => $cutoverDecision,
            'launch_blockers_json' => $launchBlockers,
            'work_item_count' => $items->count(),
            'pending_work_item_count' => $pendingCount,
            'bound_receipt_count' => $boundCount,
            'final_authority_binding_count' => $finalAuthorityCount,
            'supervised_cutover_enabled' => false,
            'external_execution_allowed' => false,
            'external_side_effects_enabled' => false,
            'last_status_at' => now(),
        ])->save();

        return $order->refresh();
    }

    /**
     * @return array<string,mixed>
     */
    public function externalSupervisedCutoverPromotionRecord(AiHoldingExternalCutoverWorkOrder $order): array
    {
        $orderRecord = $this->externalSupervisedCutoverWorkOrderPersistedPayload($order);
        $workItemCount = (int) ($orderRecord['work_item_count'] ?? 0);
        $pendingCount = (int) ($orderRecord['pending_work_item_count'] ?? 0);
        $boundCount = (int) ($orderRecord['bound_receipt_count'] ?? 0);
        $requiredFinalAuthorities = $this->externalSupervisedCutoverRequiredFinalAuthorities();
        $finalAuthorityBindings = (array) ($orderRecord['final_authority_bindings'] ?? []);
        $missingFinalAuthorities = array_values(array_diff($requiredFinalAuthorities, array_keys($finalAuthorityBindings)));
        $repositoryToolchainEvidence = (array) ($orderRecord['repository_toolchain_evidence'] ?? []);
        $repositoryToolchainBound = (bool) ($repositoryToolchainEvidence['bound'] ?? false);
        $promotionReady = $workItemCount > 0 && $pendingCount === 0 && $boundCount >= $workItemCount && $repositoryToolchainBound;
        $missingWorkItems = array_values(array_map(
            static fn (array $item): string => (string) ($item['work_item_id'] ?? ''),
            array_filter(
                (array) ($orderRecord['work_items'] ?? []),
                static fn (array $item): bool => ($item['bound_receipt_hash'] ?? null) === null,
            ),
        ));

        $record = [
            'schema' => 'atlas.ai.company.external_supervised_cutover_promotion_packet.v1',
            'company_id' => (string) $order->company_id,
            'flow_id' => (string) $order->flow_id,
            'work_order_id' => (string) $order->work_order_id,
            'promotion_review_ready' => $promotionReady,
            'promotion_state' => $promotionReady
                ? 'all_work_item_receipts_bound_operator_go_no_go_required'
                : 'receipt_intake_incomplete',
            'work_item_count' => $workItemCount,
            'pending_work_item_count' => $pendingCount,
            'bound_receipt_count' => $boundCount,
            'final_authority_binding_count' => count($finalAuthorityBindings),
            'missing_final_authority_count' => count($missingFinalAuthorities),
            'final_authority_bindings' => $finalAuthorityBindings,
            'missing_work_item_ids' => $missingWorkItems,
            'repository_toolchain_evidence' => $repositoryToolchainEvidence,
            'required_final_authorities' => $requiredFinalAuthorities,
            'missing_final_authorities' => $missingFinalAuthorities,
            'enterprise_operating_pattern' => [
                'skills_or_runbooks_bound' => true,
                'connector_scope_bound' => $promotionReady,
                'subagent_or_workstream_separation_bound' => true,
                'source_link_verification_bound' => $promotionReady,
                'guardrail_trace_required' => true,
                'post_execution_receipts_required' => true,
            ],
            'promotion_blockers' => $promotionReady
                ? array_values(array_map(static fn (string $authority): string => $authority.'_missing', $missingFinalAuthorities))
                : array_values(array_unique(array_merge(
                    ['pending_work_item_receipts', 'operator_go_no_go_missing', 'second_reviewer_go_no_go_missing'],
                    $repositoryToolchainBound ? [] : ['repository_toolchain_certification_receipts_missing'],
                ))),
            'external_execution_allowed' => false,
            'external_side_effects_enabled' => false,
            'source_work_order_hash' => (string) $order->work_order_hash,
        ];
        $record['external_supervised_cutover_promotion_packet_hash'] = MissionCanonicalHash::sha256($record);

        return $record;
    }

    /**
     * @return list<string>
     */
    public function externalSupervisedCutoverRequiredFinalAuthorities(): array
    {
        return [
            'operator_go_no_go_receipt',
            'second_reviewer_go_no_go_receipt',
            'vault_scope_release_receipt',
            'connector_scope_release_receipt',
            'change_window_open_receipt',
            'kill_switch_armed_receipt',
            'reconciliation_sink_confirmed_receipt',
            'rollback_plan_drill_receipt',
        ];
    }

    /**
     * @return list<string>
     */
    public function externalSupervisedCutoverLaunchBlockersForAuthorityCount(int $finalAuthorityCount): array
    {
        $requiredAuthorities = $this->externalSupervisedCutoverRequiredFinalAuthorities();

        if ($finalAuthorityCount >= count($requiredAuthorities)) {
            return ['manual_runtime_invocation_required', 'external_execution_adapter_not_auto_enabled'];
        }

        return array_values(array_merge(
            ['final_authority_receipts_missing'],
            array_map(static fn (string $authority): string => $authority.'_missing', $requiredAuthorities),
        ));
    }
}
