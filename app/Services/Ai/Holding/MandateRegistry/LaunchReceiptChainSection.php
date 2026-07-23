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
class LaunchReceiptChainSection
{
    public function __construct(private readonly MandateRegistryHub $hub) {}

    /**
     * @return array<string,mixed>
     */
    public function externalWorkerDispatchPlanStatus(?string $companyId = null): array
    {
        return $this->externalWorkerDispatchPlanStatusFromPreflight($this->hub->realExecutionChain->externalWorkerPreflightStatus($companyId));
    }

    /**
     * @param array<string,mixed> $preflightStatus
     * @return array<string,mixed>
     */
    public function externalWorkerDispatchPlanStatusFromPreflight(array $preflightStatus): array
    {
        $companyRows = [];

        foreach ((array) ($preflightStatus['companies'] ?? []) as $company) {
            $plans = array_values(array_map(
                fn (array $preflight): array => $this->externalWorkerDispatchPlanForPreflight((array) $preflight),
                (array) ($company['flow_worker_preflights'] ?? []),
            ));

            $companyRows[] = [
                'schema' => 'atlas.ai.company.external_worker_dispatch_plan_status.v1',
                'company_id' => (string) ($company['company_id'] ?? 'unknown'),
                'flow_count' => count($plans),
                'dispatch_plan_ready_count' => count(array_filter($plans, static fn (array $plan): bool => (bool) ($plan['dispatch_plan_ready'] ?? false))),
                'operator_launch_sequence_bound_count' => count(array_filter($plans, static fn (array $plan): bool => (bool) data_get($plan, 'operator_launch_sequence.bound', false))),
                'signature_gate_bound_count' => count(array_filter($plans, static fn (array $plan): bool => (bool) data_get($plan, 'signature_gate.bound', false))),
                'vault_scope_gate_bound_count' => count(array_filter($plans, static fn (array $plan): bool => (bool) data_get($plan, 'vault_scope_gate.bound', false))),
                'agent_repository_execution_gate_bound_count' => count(array_filter($plans, static fn (array $plan): bool => (bool) data_get($plan, 'agent_repository_execution_gate.bound', false)
                    && (bool) data_get($plan, 'agent_repository_execution_gate.adoption_ready', false)
                    && (bool) data_get($plan, 'agent_repository_execution_gate.operating_catalog_ready', false)
                    && (bool) data_get($plan, 'agent_repository_execution_gate.toolchain_certified', false))),
                'execution_receipt_gate_bound_count' => count(array_filter($plans, static fn (array $plan): bool => (bool) data_get($plan, 'execution_receipt_gate.bound', false))),
                'dispatch_disabled_count' => count(array_filter($plans, static fn (array $plan): bool => (bool) ($plan['external_worker_dispatch_enabled'] ?? true) === false)),
                'external_execution_allowed_count' => 0,
                'flow_dispatch_plans' => $plans,
            ];
            $companyRows[array_key_last($companyRows)]['company_external_worker_dispatch_plan_hash'] = MissionCanonicalHash::sha256($companyRows[array_key_last($companyRows)]);
        }

        $plans = [];
        foreach ($companyRows as $company) {
            $plans = array_merge($plans, (array) ($company['flow_dispatch_plans'] ?? []));
        }

        $readyCount = count(array_filter($plans, static fn (array $plan): bool => (bool) ($plan['dispatch_plan_ready'] ?? false)));

        $payload = [
            'ok' => (bool) ($preflightStatus['ok'] ?? false) && $plans !== [] && $readyCount === count($plans),
            'schema' => ExternalActionMandateRegistryService::EXTERNAL_WORKER_DISPATCH_PLAN_STATUS_SCHEMA,
            'status' => $plans !== [] && $readyCount === count($plans)
                ? 'external_worker_dispatch_plan_ready_supervised_launch_disabled'
                : 'external_worker_dispatch_plan_attention_required',
            'generated_at' => now()->toJSON(),
            'source_external_worker_preflight_status_hash' => $preflightStatus['external_worker_preflight_status_hash'] ?? null,
            'summary' => [
                'company_count' => count($companyRows),
                'flow_count' => count($plans),
                'dispatch_plan_ready_count' => $readyCount,
                'operator_launch_sequence_bound_count' => count(array_filter($plans, static fn (array $plan): bool => (bool) data_get($plan, 'operator_launch_sequence.bound', false))),
                'signature_gate_bound_count' => count(array_filter($plans, static fn (array $plan): bool => (bool) data_get($plan, 'signature_gate.bound', false))),
                'vault_scope_gate_bound_count' => count(array_filter($plans, static fn (array $plan): bool => (bool) data_get($plan, 'vault_scope_gate.bound', false))),
                'agent_repository_execution_gate_bound_count' => count(array_filter($plans, static fn (array $plan): bool => (bool) data_get($plan, 'agent_repository_execution_gate.bound', false)
                    && (bool) data_get($plan, 'agent_repository_execution_gate.adoption_ready', false)
                    && (bool) data_get($plan, 'agent_repository_execution_gate.operating_catalog_ready', false)
                    && (bool) data_get($plan, 'agent_repository_execution_gate.toolchain_certified', false))),
                'execution_receipt_gate_bound_count' => count(array_filter($plans, static fn (array $plan): bool => (bool) data_get($plan, 'execution_receipt_gate.bound', false))),
                'dispatch_disabled_count' => count(array_filter($plans, static fn (array $plan): bool => (bool) ($plan['external_worker_dispatch_enabled'] ?? true) === false)),
                'external_execution_allowed_count' => 0,
                'external_side_effects_enabled_count' => 0,
            ],
            'policy' => [
                'external_execution_allowed' => false,
                'external_side_effects_enabled' => false,
                'external_worker_dispatch_enabled' => false,
                'dispatch_plan_is_not_execution_authority' => true,
                'calendar_wait_blocker_enabled' => false,
                'real_dispatch_requires_operator_second_reviewer_vault_scope_and_execution_receipt' => true,
                'launch_without_signed_receipts_allowed' => false,
                'blocked_operations' => ['auto_launch', 'auto_dispatch', 'write', 'publish', 'spend', 'trade', 'deploy', 'delete', 'offensive_security', 'admin', 'secret_export'],
            ],
            'companies' => $companyRows,
        ];
        $payload['external_worker_dispatch_plan_status_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function externalLaunchControlStatus(?string $companyId = null): array
    {
        return $this->externalLaunchControlStatusFromDispatchPlans($this->externalWorkerDispatchPlanStatus($companyId));
    }

    /**
     * @param array<string,mixed> $dispatchPlanStatus
     * @return array<string,mixed>
     */
    public function externalLaunchControlStatusFromDispatchPlans(array $dispatchPlanStatus): array
    {
        $companyRows = [];

        foreach ((array) ($dispatchPlanStatus['companies'] ?? []) as $company) {
            $controls = array_values(array_map(
                fn (array $plan): array => $this->externalLaunchControlForDispatchPlan((array) $plan),
                (array) ($company['flow_dispatch_plans'] ?? []),
            ));

            $companyRows[] = [
                'schema' => 'atlas.ai.company.external_launch_control_status.v1',
                'company_id' => (string) ($company['company_id'] ?? 'unknown'),
                'flow_count' => count($controls),
                'launch_control_ready_count' => count(array_filter($controls, static fn (array $control): bool => (bool) ($control['launch_control_ready'] ?? false))),
                'go_no_go_gate_bound_count' => count(array_filter($controls, static fn (array $control): bool => (bool) data_get($control, 'go_no_go_gate.bound', false))),
                'human_authority_gate_bound_count' => count(array_filter($controls, static fn (array $control): bool => (bool) data_get($control, 'human_authority_gate.bound', false))),
                'credential_release_gate_bound_count' => count(array_filter($controls, static fn (array $control): bool => (bool) data_get($control, 'credential_release_gate.bound', false))),
                'agent_repository_execution_gate_bound_count' => count(array_filter($controls, static fn (array $control): bool => (bool) data_get($control, 'agent_repository_execution_gate.bound', false)
                    && (bool) data_get($control, 'agent_repository_execution_gate.adoption_ready', false)
                    && (bool) data_get($control, 'agent_repository_execution_gate.operating_catalog_ready', false)
                    && (bool) data_get($control, 'agent_repository_execution_gate.toolchain_certified', false))),
                'reconciliation_sink_bound_count' => count(array_filter($controls, static fn (array $control): bool => (bool) data_get($control, 'reconciliation_sink.bound', false))),
                'launch_disabled_count' => count(array_filter($controls, static fn (array $control): bool => (bool) ($control['launch_enabled'] ?? true) === false)),
                'external_execution_allowed_count' => 0,
                'flow_launch_controls' => $controls,
            ];
            $companyRows[array_key_last($companyRows)]['company_external_launch_control_hash'] = MissionCanonicalHash::sha256($companyRows[array_key_last($companyRows)]);
        }

        $controls = [];
        foreach ($companyRows as $company) {
            $controls = array_merge($controls, (array) ($company['flow_launch_controls'] ?? []));
        }

        $readyCount = count(array_filter($controls, static fn (array $control): bool => (bool) ($control['launch_control_ready'] ?? false)));

        $payload = [
            'ok' => (bool) ($dispatchPlanStatus['ok'] ?? false) && $controls !== [] && $readyCount === count($controls),
            'schema' => ExternalActionMandateRegistryService::EXTERNAL_LAUNCH_CONTROL_STATUS_SCHEMA,
            'status' => $controls !== [] && $readyCount === count($controls)
                ? 'external_launch_control_ready_launch_disabled'
                : 'external_launch_control_attention_required',
            'generated_at' => now()->toJSON(),
            'source_external_worker_dispatch_plan_status_hash' => $dispatchPlanStatus['external_worker_dispatch_plan_status_hash'] ?? null,
            'summary' => [
                'company_count' => count($companyRows),
                'flow_count' => count($controls),
                'launch_control_ready_count' => $readyCount,
                'go_no_go_gate_bound_count' => count(array_filter($controls, static fn (array $control): bool => (bool) data_get($control, 'go_no_go_gate.bound', false))),
                'human_authority_gate_bound_count' => count(array_filter($controls, static fn (array $control): bool => (bool) data_get($control, 'human_authority_gate.bound', false))),
                'credential_release_gate_bound_count' => count(array_filter($controls, static fn (array $control): bool => (bool) data_get($control, 'credential_release_gate.bound', false))),
                'agent_repository_execution_gate_bound_count' => count(array_filter($controls, static fn (array $control): bool => (bool) data_get($control, 'agent_repository_execution_gate.bound', false)
                    && (bool) data_get($control, 'agent_repository_execution_gate.adoption_ready', false)
                    && (bool) data_get($control, 'agent_repository_execution_gate.operating_catalog_ready', false)
                    && (bool) data_get($control, 'agent_repository_execution_gate.toolchain_certified', false))),
                'reconciliation_sink_bound_count' => count(array_filter($controls, static fn (array $control): bool => (bool) data_get($control, 'reconciliation_sink.bound', false))),
                'launch_disabled_count' => count(array_filter($controls, static fn (array $control): bool => (bool) ($control['launch_enabled'] ?? true) === false)),
                'required_external_receipt_count' => array_sum(array_map(static fn (array $control): int => count((array) ($control['required_external_receipts'] ?? [])), $controls)),
                'missing_external_receipt_count' => array_sum(array_map(static fn (array $control): int => count((array) ($control['missing_external_receipts'] ?? [])), $controls)),
                'external_execution_allowed_count' => 0,
                'external_side_effects_enabled_count' => 0,
            ],
            'policy' => [
                'external_execution_allowed' => false,
                'external_side_effects_enabled' => false,
                'launch_enabled' => false,
                'launch_control_is_not_execution_authority' => true,
                'calendar_wait_blocker_enabled' => false,
                'calendar_wait_replaced_by' => 'receipt_bound_go_no_go_authority_vault_scope_reconciliation_and_operator_closeout',
                'human_authority_required' => true,
                'blocked_operations' => ['launch', 'auto_launch', 'auto_dispatch', 'write', 'publish', 'spend', 'trade', 'deploy', 'delete', 'offensive_security', 'admin', 'secret_export'],
            ],
            'companies' => $companyRows,
        ];
        $payload['external_launch_control_status_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function externalReceiptBindingStatus(?string $companyId = null): array
    {
        return $this->externalReceiptBindingStatusFromLaunchControl($this->externalLaunchControlStatus($companyId));
    }

    /**
     * @param array<string,mixed> $launchControlStatus
     * @return array<string,mixed>
     */
    public function externalReceiptBindingStatusFromLaunchControl(array $launchControlStatus): array
    {
        $companyRows = [];

        foreach ((array) ($launchControlStatus['companies'] ?? []) as $company) {
            $binders = array_values(array_map(
                fn (array $control): array => $this->externalReceiptBinderForLaunchControl((array) $control),
                (array) ($company['flow_launch_controls'] ?? []),
            ));

            $receiptSlots = [];
            foreach ($binders as $binder) {
                $receiptSlots = array_merge($receiptSlots, (array) ($binder['receipt_slots'] ?? []));
            }

            $companyRows[] = [
                'schema' => 'atlas.ai.company.external_receipt_binding_status.v1',
                'company_id' => (string) ($company['company_id'] ?? 'unknown'),
                'flow_count' => count($binders),
                'receipt_binder_ready_count' => count(array_filter($binders, static fn (array $binder): bool => (bool) ($binder['receipt_binder_ready'] ?? false))),
                'agent_repository_execution_gate_bound_count' => count(array_filter($binders, static fn (array $binder): bool => (bool) data_get($binder, 'agent_repository_execution_gate.bound', false)
                    && (bool) data_get($binder, 'agent_repository_execution_gate.adoption_ready', false)
                    && (bool) data_get($binder, 'agent_repository_execution_gate.operating_catalog_ready', false)
                    && (bool) data_get($binder, 'agent_repository_execution_gate.toolchain_certified', false))),
                'receipt_slot_count' => count($receiptSlots),
                'bound_receipt_count' => count(array_filter($receiptSlots, static fn (array $slot): bool => (bool) ($slot['bound'] ?? false))),
                'missing_receipt_count' => count(array_filter($receiptSlots, static fn (array $slot): bool => (bool) ($slot['required'] ?? false) && ! (bool) ($slot['bound'] ?? false))),
                'launch_enabled_count' => 0,
                'external_execution_allowed_count' => 0,
                'flow_receipt_binders' => $binders,
            ];
            $companyRows[array_key_last($companyRows)]['company_external_receipt_binding_hash'] = MissionCanonicalHash::sha256($companyRows[array_key_last($companyRows)]);
        }

        $binders = [];
        $receiptSlots = [];
        foreach ($companyRows as $company) {
            $binders = array_merge($binders, (array) ($company['flow_receipt_binders'] ?? []));
            foreach ((array) ($company['flow_receipt_binders'] ?? []) as $binder) {
                $receiptSlots = array_merge($receiptSlots, (array) ($binder['receipt_slots'] ?? []));
            }
        }

        $readyCount = count(array_filter($binders, static fn (array $binder): bool => (bool) ($binder['receipt_binder_ready'] ?? false)));

        $payload = [
            'ok' => (bool) ($launchControlStatus['ok'] ?? false) && $binders !== [] && $readyCount === count($binders),
            'schema' => ExternalActionMandateRegistryService::EXTERNAL_RECEIPT_BINDING_STATUS_SCHEMA,
            'status' => $binders !== [] && $readyCount === count($binders)
                ? 'external_receipt_binding_ready_launch_disabled'
                : 'external_receipt_binding_attention_required',
            'generated_at' => now()->toJSON(),
            'source_external_launch_control_status_hash' => $launchControlStatus['external_launch_control_status_hash'] ?? null,
            'summary' => [
                'company_count' => count($companyRows),
                'flow_count' => count($binders),
                'receipt_binder_ready_count' => $readyCount,
                'agent_repository_execution_gate_bound_count' => count(array_filter($binders, static fn (array $binder): bool => (bool) data_get($binder, 'agent_repository_execution_gate.bound', false)
                    && (bool) data_get($binder, 'agent_repository_execution_gate.adoption_ready', false)
                    && (bool) data_get($binder, 'agent_repository_execution_gate.operating_catalog_ready', false)
                    && (bool) data_get($binder, 'agent_repository_execution_gate.toolchain_certified', false))),
                'receipt_slot_count' => count($receiptSlots),
                'required_receipt_count' => count(array_filter($receiptSlots, static fn (array $slot): bool => (bool) ($slot['required'] ?? false))),
                'bound_receipt_count' => count(array_filter($receiptSlots, static fn (array $slot): bool => (bool) ($slot['bound'] ?? false))),
                'missing_receipt_count' => count(array_filter($receiptSlots, static fn (array $slot): bool => (bool) ($slot['required'] ?? false) && ! (bool) ($slot['bound'] ?? false))),
                'launch_enabled_count' => 0,
                'external_execution_allowed_count' => 0,
                'external_side_effects_enabled_count' => 0,
            ],
            'policy' => [
                'external_execution_allowed' => false,
                'external_side_effects_enabled' => false,
                'launch_enabled' => false,
                'receipt_binding_is_not_execution_authority' => true,
                'synthetic_receipts_count_as_real_external_authority' => false,
                'calendar_wait_blocker_enabled' => false,
                'blocked_operations' => ['bind_fake_receipt_as_real', 'launch', 'auto_launch', 'auto_dispatch', 'write', 'publish', 'spend', 'trade', 'deploy', 'delete', 'offensive_security', 'admin', 'secret_export'],
            ],
            'companies' => $companyRows,
        ];
        $payload['external_receipt_binding_status_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function externalSupervisedCutoverDossierStatus(?string $companyId = null): array
    {
        return $this->externalSupervisedCutoverDossierStatusFromReceiptBinding($this->externalReceiptBindingStatus($companyId));
    }

    /**
     * @param array<string,mixed> $receiptBindingStatus
     * @return array<string,mixed>
     */
    public function externalSupervisedCutoverDossierStatusFromReceiptBinding(array $receiptBindingStatus): array
    {
        $companyRows = [];

        foreach ((array) ($receiptBindingStatus['companies'] ?? []) as $company) {
            $dossiers = array_values(array_map(
                fn (array $binder): array => $this->externalSupervisedCutoverDossierForReceiptBinder((array) $binder),
                (array) ($company['flow_receipt_binders'] ?? []),
            ));

            $companyRows[] = [
                'schema' => 'atlas.ai.company.external_supervised_cutover_dossier_status.v1',
                'company_id' => (string) ($company['company_id'] ?? 'unknown'),
                'flow_count' => count($dossiers),
                'cutover_dossier_ready_count' => count(array_filter($dossiers, static fn (array $dossier): bool => (bool) ($dossier['cutover_dossier_ready'] ?? false))),
                'agent_repository_execution_gate_bound_count' => count(array_filter($dossiers, static fn (array $dossier): bool => (bool) data_get($dossier, 'readiness_evidence.agent_repository_execution_gate_bound', false))),
                'supervised_cutover_enabled_count' => 0,
                'receipt_slot_count' => array_sum(array_map(static fn (array $dossier): int => (int) data_get($dossier, 'readiness_evidence.receipt_slot_count', 0), $dossiers)),
                'missing_receipt_count' => array_sum(array_map(static fn (array $dossier): int => (int) data_get($dossier, 'readiness_evidence.missing_receipt_count', 0), $dossiers)),
                'bound_receipt_count' => array_sum(array_map(static fn (array $dossier): int => (int) data_get($dossier, 'readiness_evidence.bound_receipt_count', 0), $dossiers)),
                'flow_cutover_dossiers' => $dossiers,
            ];
            $companyRows[array_key_last($companyRows)]['company_external_supervised_cutover_dossier_hash'] = MissionCanonicalHash::sha256($companyRows[array_key_last($companyRows)]);
        }

        $dossiers = [];
        foreach ($companyRows as $company) {
            $dossiers = array_merge($dossiers, (array) ($company['flow_cutover_dossiers'] ?? []));
        }

        $readyCount = count(array_filter($dossiers, static fn (array $dossier): bool => (bool) ($dossier['cutover_dossier_ready'] ?? false)));

        $payload = [
            'ok' => (bool) ($receiptBindingStatus['ok'] ?? false) && $dossiers !== [] && $readyCount === count($dossiers),
            'schema' => ExternalActionMandateRegistryService::EXTERNAL_SUPERVISED_CUTOVER_DOSSIER_STATUS_SCHEMA,
            'status' => $dossiers !== [] && $readyCount === count($dossiers)
                ? 'external_supervised_cutover_dossier_ready_cutover_blocked'
                : 'external_supervised_cutover_dossier_attention_required',
            'generated_at' => now()->toJSON(),
            'source_external_receipt_binding_status_hash' => $receiptBindingStatus['external_receipt_binding_status_hash'] ?? null,
            'summary' => [
                'company_count' => count($companyRows),
                'flow_count' => count($dossiers),
                'cutover_dossier_ready_count' => $readyCount,
                'agent_repository_execution_gate_bound_count' => count(array_filter($dossiers, static fn (array $dossier): bool => (bool) data_get($dossier, 'readiness_evidence.agent_repository_execution_gate_bound', false))),
                'supervised_cutover_enabled_count' => 0,
                'external_execution_allowed_count' => 0,
                'external_side_effects_enabled_count' => 0,
                'receipt_slot_count' => array_sum(array_map(static fn (array $dossier): int => (int) data_get($dossier, 'readiness_evidence.receipt_slot_count', 0), $dossiers)),
                'required_receipt_count' => array_sum(array_map(static fn (array $dossier): int => (int) data_get($dossier, 'readiness_evidence.required_receipt_count', 0), $dossiers)),
                'bound_receipt_count' => array_sum(array_map(static fn (array $dossier): int => (int) data_get($dossier, 'readiness_evidence.bound_receipt_count', 0), $dossiers)),
                'missing_receipt_count' => array_sum(array_map(static fn (array $dossier): int => (int) data_get($dossier, 'readiness_evidence.missing_receipt_count', 0), $dossiers)),
            ],
            'policy' => [
                'external_execution_allowed' => false,
                'external_side_effects_enabled' => false,
                'supervised_cutover_enabled' => false,
                'cutover_dossier_is_not_execution_authority' => true,
                'calendar_wait_blocker_enabled' => false,
                'cutover_blocker' => 'missing_real_external_receipts',
                'required_before_cutover' => [
                    'bind_all_real_external_receipts',
                    'record_operator_go_no_go',
                    'record_second_reviewer_go_no_go',
                    'release_least_privilege_vault_scope',
                    'arm_kill_switch',
                    'confirm_reconciliation_sink',
                    'open_change_window',
                ],
                'blocked_operations' => ['cutover', 'launch', 'auto_launch', 'auto_dispatch', 'bind_fake_receipt_as_real', 'write', 'publish', 'spend', 'trade', 'deploy', 'delete', 'offensive_security', 'admin', 'secret_export'],
            ],
            'companies' => $companyRows,
        ];
        $payload['external_supervised_cutover_dossier_status_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function externalSupervisedCutoverWorkOrderStatus(?string $companyId = null): array
    {
        return $this->externalSupervisedCutoverWorkOrderStatusFromDossiers($this->externalSupervisedCutoverDossierStatus($companyId));
    }

    /**
     * @param array<string,mixed> $dossierStatus
     * @return array<string,mixed>
     */
    public function externalSupervisedCutoverWorkOrderStatusFromDossiers(array $dossierStatus): array
    {
        $companyRows = [];

        foreach ((array) ($dossierStatus['companies'] ?? []) as $company) {
            $workOrders = array_values(array_map(
                fn (array $dossier): array => $this->externalSupervisedCutoverWorkOrderForDossier((array) $dossier),
                (array) ($company['flow_cutover_dossiers'] ?? []),
            ));

            $workItems = [];
            foreach ($workOrders as $workOrder) {
                $workItems = array_merge($workItems, (array) ($workOrder['work_items'] ?? []));
            }

            $companyRows[] = [
                'schema' => 'atlas.ai.company.external_supervised_cutover_work_order_status.v1',
                'company_id' => (string) ($company['company_id'] ?? 'unknown'),
                'flow_count' => count($workOrders),
                'work_order_ready_count' => count(array_filter($workOrders, static fn (array $workOrder): bool => (bool) ($workOrder['work_order_ready'] ?? false))),
                'repository_toolchain_evidence_bound_count' => count(array_filter($workOrders, static fn (array $workOrder): bool => (bool) data_get($workOrder, 'repository_toolchain_evidence.agent_repository_execution_gate_bound', false)
                    && (int) data_get($workOrder, 'repository_toolchain_evidence.repository_tool_permission_manifest_count', 0) >= 1
                    && (int) data_get($workOrder, 'repository_toolchain_evidence.repository_eval_replay_recipe_count', 0) >= 1
                    && (int) data_get($workOrder, 'repository_toolchain_evidence.domain_certified_tool_contract_count', 0) >= 1)),
                'work_item_count' => count($workItems),
                'pending_real_receipt_item_count' => count(array_filter($workItems, static fn (array $item): bool => ($item['status'] ?? '') === 'pending_real_receipt_or_operator_action')),
                'executable_item_count' => 0,
                'supervised_cutover_enabled_count' => 0,
                'flow_cutover_work_orders' => $workOrders,
            ];
            $companyRows[array_key_last($companyRows)]['company_external_supervised_cutover_work_order_hash'] = MissionCanonicalHash::sha256($companyRows[array_key_last($companyRows)]);
        }

        $workOrders = [];
        $workItems = [];
        foreach ($companyRows as $company) {
            $workOrders = array_merge($workOrders, (array) ($company['flow_cutover_work_orders'] ?? []));
            foreach ((array) ($company['flow_cutover_work_orders'] ?? []) as $workOrder) {
                $workItems = array_merge($workItems, (array) ($workOrder['work_items'] ?? []));
            }
        }

        $readyCount = count(array_filter($workOrders, static fn (array $workOrder): bool => (bool) ($workOrder['work_order_ready'] ?? false)));

        $payload = [
            'ok' => (bool) ($dossierStatus['ok'] ?? false) && $workOrders !== [] && $readyCount === count($workOrders),
            'schema' => ExternalActionMandateRegistryService::EXTERNAL_SUPERVISED_CUTOVER_WORK_ORDER_STATUS_SCHEMA,
            'status' => $workOrders !== [] && $readyCount === count($workOrders)
                ? 'external_supervised_cutover_work_orders_ready_launch_blocked'
                : 'external_supervised_cutover_work_orders_attention_required',
            'generated_at' => now()->toJSON(),
            'source_external_supervised_cutover_dossier_status_hash' => $dossierStatus['external_supervised_cutover_dossier_status_hash'] ?? null,
            'summary' => [
                'company_count' => count($companyRows),
                'flow_count' => count($workOrders),
                'work_order_ready_count' => $readyCount,
                'repository_toolchain_evidence_bound_count' => count(array_filter($workOrders, static fn (array $workOrder): bool => (bool) data_get($workOrder, 'repository_toolchain_evidence.agent_repository_execution_gate_bound', false)
                    && (int) data_get($workOrder, 'repository_toolchain_evidence.repository_tool_permission_manifest_count', 0) >= 1
                    && (int) data_get($workOrder, 'repository_toolchain_evidence.repository_eval_replay_recipe_count', 0) >= 1
                    && (int) data_get($workOrder, 'repository_toolchain_evidence.domain_certified_tool_contract_count', 0) >= 1)),
                'work_item_count' => count($workItems),
                'pending_real_receipt_item_count' => count(array_filter($workItems, static fn (array $item): bool => ($item['status'] ?? '') === 'pending_real_receipt_or_operator_action')),
                'receipt_intake_item_count' => count(array_filter($workItems, static fn (array $item): bool => ($item['workstream'] ?? '') === 'receipt_intake')),
                'vault_scope_item_count' => count(array_filter($workItems, static fn (array $item): bool => ($item['workstream'] ?? '') === 'vault_scope')),
                'reconciliation_item_count' => count(array_filter($workItems, static fn (array $item): bool => ($item['workstream'] ?? '') === 'reconciliation')),
                'repository_toolchain_certification_item_count' => count(array_filter($workItems, static fn (array $item): bool => ($item['workstream'] ?? '') === 'repository_toolchain_certification')),
                'executable_item_count' => 0,
                'supervised_cutover_enabled_count' => 0,
                'external_execution_allowed_count' => 0,
                'external_side_effects_enabled_count' => 0,
            ],
            'policy' => [
                'external_execution_allowed' => false,
                'external_side_effects_enabled' => false,
                'supervised_cutover_enabled' => false,
                'work_order_is_not_execution_authority' => true,
                'calendar_wait_blocker_enabled' => false,
                'enterprise_pattern_generalized_from_financial_services' => [
                    'unified_data_and_tool_workbench_required' => true,
                    'source_link_verification_required' => true,
                    'prebuilt_connector_or_adapter_required' => true,
                    'audit_trail_required' => true,
                    'expert_operator_enablement_required' => true,
                ],
                'blocked_operations' => ['execute_work_order', 'cutover', 'launch', 'auto_launch', 'auto_dispatch', 'bind_fake_receipt_as_real', 'write', 'publish', 'spend', 'trade', 'deploy', 'delete', 'offensive_security', 'admin', 'secret_export'],
            ],
            'companies' => $companyRows,
        ];
        $payload['external_supervised_cutover_work_order_status_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @param array<string,mixed> $preflight
     * @return array<string,mixed>
     */
    public function externalWorkerDispatchPlanForPreflight(array $preflight): array
    {
        $companyId = (string) ($preflight['company_id'] ?? 'unknown');
        $flowId = (string) ($preflight['flow_id'] ?? 'unknown');
        $blockedOperations = array_values(array_unique(array_filter(array_map(
            'strval',
            (array) data_get($preflight, 'worker_plan.blocked_operations', []),
        ))));
        $dispatchBlockers = array_values(array_unique(array_merge(
            array_values(array_map('strval', (array) data_get($preflight, 'worker_plan.dispatch_blockers', []))),
            [
                'supervised_launch_disabled_by_policy',
                'signed_dispatch_receipt_missing',
                'vault_scope_receipt_missing',
                'execution_receipt_sink_not_bound_to_live_adapter',
            ],
        )));
        $launchChecklist = [
            'verify_preflight_hash',
            'bind_decision_receipt_hash',
            'bind_operator_signature_receipt',
            'bind_second_reviewer_signature_receipt',
            'bind_real_credential_vault_reference',
            'verify_connector_scope_matches_packet',
            'arm_kill_switch',
            'open_change_window',
            'run_final_no_secret_material_scan',
            'prepare_post_execution_reconciliation_job',
            'operator_confirms_launch_outside_autonomous_suite',
        ];

        $ready = (bool) ($preflight['worker_preflight_ready'] ?? false)
            && (bool) data_get($preflight, 'worker_plan.bound', false)
            && (bool) data_get($preflight, 'execution_envelope.bound', false)
            && (bool) data_get($preflight, 'execution_envelope.decision_receipt_hash_required', false)
            && (bool) data_get($preflight, 'execution_envelope.idempotency_key_required', false)
            && strlen((string) data_get($preflight, 'execution_envelope.idempotency_key', '')) === 64
            && (bool) data_get($preflight, 'agent_repository_gate.bound', false)
            && (bool) data_get($preflight, 'agent_repository_gate.adoption_ready', false)
            && (bool) data_get($preflight, 'agent_repository_gate.operating_catalog_ready', false)
            && (bool) data_get($preflight, 'agent_repository_gate.toolchain_certified', false)
            && (int) data_get($preflight, 'agent_repository_gate.tool_permission_manifest_count', 0) >= 1
            && (int) data_get($preflight, 'agent_repository_gate.eval_replay_recipe_count', 0) >= 1
            && (bool) data_get($preflight, 'agent_repository_gate.external_tool_side_effects_allowed', true) === false
            && (bool) data_get($preflight, 'credential_gate.bound', false)
            && (bool) data_get($preflight, 'credential_gate.vault_reference_required', false)
            && (bool) data_get($preflight, 'credential_gate.credential_material_in_packet_allowed', true) === false
            && (bool) data_get($preflight, 'worker_controls.external_worker_dispatch_enabled', true) === false
            && (bool) data_get($preflight, 'worker_controls.kill_switch_bound', false)
            && (bool) data_get($preflight, 'worker_controls.auto_retry_external_action_allowed', true) === false
            && (bool) data_get($preflight, 'post_execution_reconciliation.bound', false)
            && (bool) data_get($preflight, 'post_execution_reconciliation.operator_closeout_required', false)
            && (bool) ($preflight['external_execution_allowed'] ?? true) === false
            && (bool) ($preflight['external_side_effects_enabled'] ?? true) === false;

        $plan = [
            'schema' => 'atlas.ai.company.external_worker_dispatch_plan.v1',
            'company_id' => $companyId,
            'flow_id' => $flowId,
            'dispatch_plan_ready' => $ready,
            'launch_mode' => 'operator_supervised_launch_packet_prepared_dispatch_disabled',
            'external_worker_dispatch_enabled' => false,
            'external_execution_allowed' => false,
            'external_side_effects_enabled' => false,
            'operator_launch_sequence' => [
                'bound' => true,
                'checklist' => $launchChecklist,
                'launch_surface' => 'operator_console_or_manual_connector_adapter_after_real_signatures',
                'autonomous_suite_may_launch' => false,
                'calendar_wait_blocker_enabled' => false,
            ],
            'signature_gate' => [
                'bound' => true,
                'operator_signature_receipt_required' => true,
                'second_reviewer_signature_receipt_required' => true,
                'legal_risk_acceptance_receipt_required' => true,
                'budget_or_loss_cap_signature_receipt_required' => true,
                'missing_receipts_block_dispatch' => true,
            ],
            'vault_scope_gate' => [
                'bound' => true,
                'vault_reference_required' => (bool) data_get($preflight, 'credential_gate.vault_reference_required', true),
                'credential_material_in_packet_allowed' => false,
                'secret_export_allowed' => false,
                'scope_must_match_connector_readiness' => (bool) data_get($preflight, 'credential_gate.read_scope_must_match_live_read_connector_readiness', true),
            ],
            'agent_repository_execution_gate' => [
                'bound' => true,
                'adoption_ready' => (bool) data_get($preflight, 'agent_repository_gate.adoption_ready', false),
                'operating_catalog_ready' => (bool) data_get($preflight, 'agent_repository_gate.operating_catalog_ready', false),
                'toolchain_certified' => (bool) data_get($preflight, 'agent_repository_gate.toolchain_certified', false),
                'tool_permission_manifest_count' => (int) data_get($preflight, 'agent_repository_gate.tool_permission_manifest_count', 0),
                'eval_replay_recipe_count' => (int) data_get($preflight, 'agent_repository_gate.eval_replay_recipe_count', 0),
                'certified_tool_contract_count' => (int) data_get($preflight, 'agent_repository_gate.certified_tool_contract_count', 0),
                'external_tool_side_effects_allowed' => false,
            ],
            'execution_receipt_gate' => [
                'bound' => true,
                'decision_receipt_hash_required' => true,
                'idempotency_key' => (string) data_get($preflight, 'execution_envelope.idempotency_key', ''),
                'tool_receipts_required' => true,
                'post_execution_reconciliation_required' => true,
                'operator_closeout_required' => true,
            ],
            'worker_runtime_contract' => [
                'worker_family' => (string) data_get($preflight, 'worker_plan.worker_family', 'atlas_external_action_worker'),
                'allowed_runtime_mode' => 'dry_run_until_signed_operator_launch',
                'tool_use_mode' => (string) data_get($preflight, 'worker_plan.tool_use_mode', 'connector_scoped_with_tool_receipts'),
                'auto_retry_external_action_allowed' => false,
                'kill_switch_bound' => (bool) data_get($preflight, 'worker_controls.kill_switch_bound', false),
            ],
            'dispatch_blockers' => $dispatchBlockers,
            'blocked_operations' => $blockedOperations,
            'source_worker_preflight_hash' => (string) ($preflight['worker_preflight_hash'] ?? ''),
        ];
        $plan['dispatch_plan_hash'] = MissionCanonicalHash::sha256($plan);

        return $plan;
    }

    /**
     * @param array<string,mixed> $plan
     * @return array<string,mixed>
     */
    public function externalLaunchControlForDispatchPlan(array $plan): array
    {
        $companyId = (string) ($plan['company_id'] ?? 'unknown');
        $flowId = (string) ($plan['flow_id'] ?? 'unknown');
        $requiredReceipts = [
            'decision_receipt_hash',
            'operator_signature_receipt',
            'second_reviewer_signature_receipt',
            'legal_risk_acceptance_receipt',
            'budget_or_loss_cap_signature_receipt',
            'vault_scope_receipt',
            'connector_scope_receipt',
            'change_window_receipt',
            'kill_switch_armed_receipt',
            'tool_receipt_sink_receipt',
            'post_execution_reconciliation_job_receipt',
            'operator_closeout_receipt',
            'repository_tool_permission_manifest_receipt',
            'repository_eval_replay_recipe_receipt',
            'domain_toolchain_certification_receipt',
        ];
        $missingReceipts = $requiredReceipts;
        $goNoGoBlockers = array_values(array_unique(array_merge(
            array_values(array_map('strval', (array) ($plan['dispatch_blockers'] ?? []))),
            [
                'external_launch_disabled_by_policy',
                'required_external_receipts_not_bound',
                'operator_go_no_go_not_recorded',
                'second_reviewer_go_no_go_not_recorded',
                'vault_scope_not_released_to_runtime',
                'post_execution_reconciliation_not_live_confirmed',
            ],
        )));
        $ready = (bool) ($plan['dispatch_plan_ready'] ?? false)
            && (bool) data_get($plan, 'operator_launch_sequence.bound', false)
            && (bool) data_get($plan, 'operator_launch_sequence.autonomous_suite_may_launch', true) === false
            && (bool) data_get($plan, 'signature_gate.bound', false)
            && (bool) data_get($plan, 'signature_gate.missing_receipts_block_dispatch', false)
            && (bool) data_get($plan, 'vault_scope_gate.bound', false)
            && (bool) data_get($plan, 'vault_scope_gate.credential_material_in_packet_allowed', true) === false
            && (bool) data_get($plan, 'agent_repository_execution_gate.bound', false)
            && (bool) data_get($plan, 'agent_repository_execution_gate.adoption_ready', false)
            && (bool) data_get($plan, 'agent_repository_execution_gate.operating_catalog_ready', false)
            && (bool) data_get($plan, 'agent_repository_execution_gate.toolchain_certified', false)
            && (int) data_get($plan, 'agent_repository_execution_gate.tool_permission_manifest_count', 0) >= 1
            && (int) data_get($plan, 'agent_repository_execution_gate.eval_replay_recipe_count', 0) >= 1
            && (bool) data_get($plan, 'agent_repository_execution_gate.external_tool_side_effects_allowed', true) === false
            && (bool) data_get($plan, 'execution_receipt_gate.bound', false)
            && strlen((string) data_get($plan, 'execution_receipt_gate.idempotency_key', '')) === 64
            && (bool) data_get($plan, 'worker_runtime_contract.kill_switch_bound', false)
            && (bool) ($plan['external_worker_dispatch_enabled'] ?? true) === false
            && (bool) ($plan['external_execution_allowed'] ?? true) === false
            && (bool) ($plan['external_side_effects_enabled'] ?? true) === false;

        $control = [
            'schema' => 'atlas.ai.company.external_launch_control.v1',
            'company_id' => $companyId,
            'flow_id' => $flowId,
            'launch_control_ready' => $ready,
            'launch_enabled' => false,
            'launch_mode' => 'go_no_go_control_prepared_external_launch_disabled',
            'external_execution_allowed' => false,
            'external_side_effects_enabled' => false,
            'go_no_go_gate' => [
                'bound' => true,
                'decision' => 'no_go_until_real_receipts_bound',
                'required_decision_makers' => ['operator', 'second_reviewer', 'risk_owner_or_domain_owner'],
                'blockers' => $goNoGoBlockers,
                'calendar_wait_blocker_enabled' => false,
            ],
            'human_authority_gate' => [
                'bound' => true,
                'operator_go_required' => true,
                'second_reviewer_go_required' => true,
                'risk_owner_ack_required_for_finance_cyber_legal_or_external_customer_action' => true,
                'autonomous_override_allowed' => false,
            ],
            'credential_release_gate' => [
                'bound' => true,
                'vault_reference_required' => true,
                'runtime_secret_material_export_allowed' => false,
                'least_privilege_scope_required' => true,
                'release_without_go_no_go_allowed' => false,
            ],
            'agent_repository_execution_gate' => [
                'bound' => true,
                'adoption_ready' => (bool) data_get($plan, 'agent_repository_execution_gate.adoption_ready', false),
                'operating_catalog_ready' => (bool) data_get($plan, 'agent_repository_execution_gate.operating_catalog_ready', false),
                'toolchain_certified' => (bool) data_get($plan, 'agent_repository_execution_gate.toolchain_certified', false),
                'tool_permission_manifest_count' => (int) data_get($plan, 'agent_repository_execution_gate.tool_permission_manifest_count', 0),
                'eval_replay_recipe_count' => (int) data_get($plan, 'agent_repository_execution_gate.eval_replay_recipe_count', 0),
                'certified_tool_contract_count' => (int) data_get($plan, 'agent_repository_execution_gate.certified_tool_contract_count', 0),
                'external_tool_side_effects_allowed' => false,
            ],
            'reconciliation_sink' => [
                'bound' => true,
                'tool_receipts_required' => true,
                'external_result_receipt_required' => true,
                'ledger_update_required' => true,
                'metric_delta_required' => true,
                'operator_closeout_required' => true,
                'claim_without_receipt_allowed' => false,
            ],
            'required_external_receipts' => $requiredReceipts,
            'missing_external_receipts' => $missingReceipts,
            'source_dispatch_plan_hash' => (string) ($plan['dispatch_plan_hash'] ?? ''),
        ];
        $control['external_launch_control_hash'] = MissionCanonicalHash::sha256($control);

        return $control;
    }

    /**
     * @param array<string,mixed> $control
     * @return array<string,mixed>
     */
    public function externalReceiptBinderForLaunchControl(array $control): array
    {
        $companyId = (string) ($control['company_id'] ?? 'unknown');
        $flowId = (string) ($control['flow_id'] ?? 'unknown');
        $requiredReceipts = array_values(array_map('strval', (array) ($control['required_external_receipts'] ?? [])));
        $slots = array_values(array_map(
            fn (string $receiptId): array => $this->externalReceiptSlot($companyId, $flowId, $receiptId, $control),
            $requiredReceipts,
        ));

        $ready = (bool) ($control['launch_control_ready'] ?? false)
            && $slots !== []
            && count($slots) === count($requiredReceipts)
            && count(array_filter($slots, static fn (array $slot): bool => (bool) ($slot['required'] ?? false))) === count($slots)
            && count(array_filter($slots, static fn (array $slot): bool => (bool) ($slot['bound'] ?? true))) === 0
            && (bool) data_get($control, 'agent_repository_execution_gate.bound', false)
            && (bool) data_get($control, 'agent_repository_execution_gate.adoption_ready', false)
            && (bool) data_get($control, 'agent_repository_execution_gate.operating_catalog_ready', false)
            && (bool) data_get($control, 'agent_repository_execution_gate.toolchain_certified', false)
            && (bool) ($control['launch_enabled'] ?? true) === false
            && (bool) ($control['external_execution_allowed'] ?? true) === false
            && (bool) ($control['external_side_effects_enabled'] ?? true) === false;

        $binder = [
            'schema' => 'atlas.ai.company.external_receipt_binder.v1',
            'company_id' => $companyId,
            'flow_id' => $flowId,
            'receipt_binder_ready' => $ready,
            'binding_mode' => 'receipt_slots_prepared_no_external_receipts_bound',
            'launch_enabled' => false,
            'external_execution_allowed' => false,
            'external_side_effects_enabled' => false,
            'receipt_slots' => $slots,
            'binding_policy' => [
                'real_receipt_source_required' => true,
                'synthetic_receipts_allowed_for_external_authority' => false,
                'receipt_hash_required' => true,
                'receipt_source_must_match_go_no_go_gate' => true,
                'operator_closeout_required_before_claiming_external_result' => true,
                'repository_toolchain_certification_receipts_required' => true,
            ],
            'agent_repository_execution_gate' => [
                'bound' => (bool) data_get($control, 'agent_repository_execution_gate.bound', false),
                'adoption_ready' => (bool) data_get($control, 'agent_repository_execution_gate.adoption_ready', false),
                'operating_catalog_ready' => (bool) data_get($control, 'agent_repository_execution_gate.operating_catalog_ready', false),
                'toolchain_certified' => (bool) data_get($control, 'agent_repository_execution_gate.toolchain_certified', false),
                'tool_permission_manifest_count' => (int) data_get($control, 'agent_repository_execution_gate.tool_permission_manifest_count', 0),
                'eval_replay_recipe_count' => (int) data_get($control, 'agent_repository_execution_gate.eval_replay_recipe_count', 0),
                'certified_tool_contract_count' => (int) data_get($control, 'agent_repository_execution_gate.certified_tool_contract_count', 0),
                'external_tool_side_effects_allowed' => false,
            ],
            'source_external_launch_control_hash' => (string) ($control['external_launch_control_hash'] ?? ''),
        ];
        $binder['external_receipt_binder_hash'] = MissionCanonicalHash::sha256($binder);

        return $binder;
    }

    /**
     * @param array<string,mixed> $control
     * @return array<string,mixed>
     */
    public function externalReceiptSlot(string $companyId, string $flowId, string $receiptId, array $control): array
    {
        $source = match ($receiptId) {
            'decision_receipt_hash' => 'kernel_decide_receipt',
            'operator_signature_receipt', 'second_reviewer_signature_receipt', 'legal_risk_acceptance_receipt', 'budget_or_loss_cap_signature_receipt' => 'operator_approval_surface',
            'vault_scope_receipt' => 'credential_vault',
            'connector_scope_receipt' => 'connector_activation_record',
            'change_window_receipt' => 'operator_change_window',
            'kill_switch_armed_receipt' => 'runtime_control_plane',
            'tool_receipt_sink_receipt', 'post_execution_reconciliation_job_receipt', 'operator_closeout_receipt' => 'evidence_ledger_or_reconciliation_sink',
            'repository_tool_permission_manifest_receipt', 'repository_eval_replay_recipe_receipt', 'domain_toolchain_certification_receipt' => 'agent_repository_and_domain_toolchain_certification',
            default => 'external_authority_source',
        };

        $slot = [
            'schema' => 'atlas.ai.company.external_receipt_slot.v1',
            'receipt_id' => $receiptId,
            'company_id' => $companyId,
            'flow_id' => $flowId,
            'required' => true,
            'bound' => false,
            'receipt_hash' => null,
            'expected_source' => $source,
            'binding_preconditions' => [
                'launch_control_hash_present' => strlen((string) ($control['external_launch_control_hash'] ?? '')) === 64,
                'go_no_go_gate_bound' => (bool) data_get($control, 'go_no_go_gate.bound', false),
                'human_authority_gate_bound' => (bool) data_get($control, 'human_authority_gate.bound', false),
                'credential_release_gate_bound' => (bool) data_get($control, 'credential_release_gate.bound', false),
                'agent_repository_execution_gate_bound' => (bool) data_get($control, 'agent_repository_execution_gate.bound', false)
                    && (bool) data_get($control, 'agent_repository_execution_gate.adoption_ready', false)
                    && (bool) data_get($control, 'agent_repository_execution_gate.operating_catalog_ready', false)
                    && (bool) data_get($control, 'agent_repository_execution_gate.toolchain_certified', false),
                'reconciliation_sink_bound' => (bool) data_get($control, 'reconciliation_sink.bound', false),
            ],
            'binding_blocker' => 'real_external_receipt_not_bound',
            'fake_or_synthetic_receipt_allowed' => false,
        ];
        $slot['receipt_slot_hash'] = MissionCanonicalHash::sha256($slot);

        return $slot;
    }

    /**
     * @param array<string,mixed> $binder
     * @return array<string,mixed>
     */
    public function externalSupervisedCutoverDossierForReceiptBinder(array $binder): array
    {
        $companyId = (string) ($binder['company_id'] ?? 'unknown');
        $flowId = (string) ($binder['flow_id'] ?? 'unknown');
        $slots = array_values((array) ($binder['receipt_slots'] ?? []));
        $requiredSlots = array_values(array_filter($slots, static fn (array $slot): bool => (bool) ($slot['required'] ?? false)));
        $boundSlots = array_values(array_filter($slots, static fn (array $slot): bool => (bool) ($slot['bound'] ?? false)));
        $missingSlots = array_values(array_filter($requiredSlots, static fn (array $slot): bool => ! (bool) ($slot['bound'] ?? false)));
        $missingReceiptIds = array_values(array_map(static fn (array $slot): string => (string) ($slot['receipt_id'] ?? 'unknown_receipt'), $missingSlots));

        $ready = (bool) ($binder['receipt_binder_ready'] ?? false)
            && $slots !== []
            && count($requiredSlots) === count($slots)
            && $boundSlots === []
            && count($missingSlots) === count($requiredSlots)
            && strlen((string) ($binder['external_receipt_binder_hash'] ?? '')) === 64
            && strlen((string) ($binder['source_external_launch_control_hash'] ?? '')) === 64
            && (bool) data_get($binder, 'agent_repository_execution_gate.bound', false)
            && (bool) data_get($binder, 'agent_repository_execution_gate.adoption_ready', false)
            && (bool) data_get($binder, 'agent_repository_execution_gate.operating_catalog_ready', false)
            && (bool) data_get($binder, 'agent_repository_execution_gate.toolchain_certified', false)
            && (bool) ($binder['launch_enabled'] ?? true) === false
            && (bool) ($binder['external_execution_allowed'] ?? true) === false
            && (bool) ($binder['external_side_effects_enabled'] ?? true) === false;

        $dossier = [
            'schema' => 'atlas.ai.company.external_supervised_cutover_dossier.v1',
            'company_id' => $companyId,
            'flow_id' => $flowId,
            'cutover_dossier_ready' => $ready,
            'cutover_decision' => 'blocked_missing_real_external_receipts',
            'supervised_cutover_enabled' => false,
            'external_execution_allowed' => false,
            'external_side_effects_enabled' => false,
            'operator_cutover_packet' => [
                'required_decision_makers' => ['operator', 'second_reviewer', 'risk_owner_or_domain_owner'],
                'required_receipt_ids' => array_values(array_map(static fn (array $slot): string => (string) ($slot['receipt_id'] ?? 'unknown_receipt'), $requiredSlots)),
                'missing_receipt_ids' => $missingReceiptIds,
                'bound_receipt_count' => count($boundSlots),
                'change_window_required' => true,
                'no_fake_receipt_allowed' => true,
                'launch_surface' => 'operator_console_or_manual_connector_adapter_after_real_receipts',
                'cutover_runbook_steps' => [
                    'verify_cutover_dossier_hash',
                    'bind_all_real_external_receipts',
                    'record_operator_and_reviewer_go_no_go',
                    'release_least_privilege_vault_scope',
                    'arm_kill_switch_and_change_window',
                    'confirm_reconciliation_sink_before_launch',
                    'execute_only_from_supervised_operator_surface',
                    'bind_tool_receipts_and_operator_closeout',
                ],
            ],
            'readiness_evidence' => [
                'source_external_receipt_binder_hash' => (string) ($binder['external_receipt_binder_hash'] ?? ''),
                'source_external_launch_control_hash' => (string) ($binder['source_external_launch_control_hash'] ?? ''),
                'receipt_slot_count' => count($slots),
                'required_receipt_count' => count($requiredSlots),
                'bound_receipt_count' => count($boundSlots),
                'missing_receipt_count' => count($missingSlots),
                'all_receipt_slots_required' => count($requiredSlots) === count($slots) && $slots !== [],
                'agent_repository_execution_gate_bound' => (bool) data_get($binder, 'agent_repository_execution_gate.bound', false)
                    && (bool) data_get($binder, 'agent_repository_execution_gate.adoption_ready', false)
                    && (bool) data_get($binder, 'agent_repository_execution_gate.operating_catalog_ready', false)
                    && (bool) data_get($binder, 'agent_repository_execution_gate.toolchain_certified', false),
                'repository_tool_permission_manifest_count' => (int) data_get($binder, 'agent_repository_execution_gate.tool_permission_manifest_count', 0),
                'repository_eval_replay_recipe_count' => (int) data_get($binder, 'agent_repository_execution_gate.eval_replay_recipe_count', 0),
                'domain_certified_tool_contract_count' => (int) data_get($binder, 'agent_repository_execution_gate.certified_tool_contract_count', 0),
                'launch_disabled' => (bool) ($binder['launch_enabled'] ?? true) === false,
                'calendar_wait_blocker_enabled' => false,
            ],
            'promotion_blockers' => array_values(array_unique(array_merge(
                ['missing_real_external_receipts', 'operator_go_no_go_missing', 'second_reviewer_go_no_go_missing', 'vault_scope_receipt_missing', 'reconciliation_receipt_missing'],
                array_map(static fn (string $receiptId): string => $receiptId.'_missing', $missingReceiptIds),
            ))),
            'cutover_policy' => [
                'dossier_is_not_execution_authority' => true,
                'synthetic_receipts_allowed_for_external_authority' => false,
                'operator_can_cutover_without_all_receipts' => false,
                'autonomous_suite_may_cutover' => false,
                'external_result_claim_requires_tool_receipts_and_operator_closeout' => true,
            ],
        ];
        $dossier['external_supervised_cutover_dossier_hash'] = MissionCanonicalHash::sha256($dossier);

        return $dossier;
    }

    /**
     * @param array<string,mixed> $dossier
     * @return array<string,mixed>
     */
    public function externalSupervisedCutoverWorkOrderForDossier(array $dossier): array
    {
        $companyId = (string) ($dossier['company_id'] ?? 'unknown');
        $flowId = (string) ($dossier['flow_id'] ?? 'unknown');
        $missingReceiptIds = array_values(array_map(
            'strval',
            (array) data_get($dossier, 'operator_cutover_packet.missing_receipt_ids', []),
        ));
        $sourceDossierHash = (string) ($dossier['external_supervised_cutover_dossier_hash'] ?? '');
        $workOrderId = hash('sha256', 'external_supervised_cutover_work_order|'.$companyId.'|'.$flowId.'|'.$sourceDossierHash);

        $workItems = [
            $this->externalSupervisedCutoverWorkItem($workOrderId, $companyId, $flowId, 'verify_source_materials', 'source_verification', 'domain_operator', ['decision_receipt_hash'], $sourceDossierHash),
            $this->externalSupervisedCutoverWorkItem($workOrderId, $companyId, $flowId, 'verify_repository_and_toolchain_certification', 'repository_toolchain_certification', 'domain_operator', ['repository_tool_permission_manifest_receipt', 'repository_eval_replay_recipe_receipt', 'domain_toolchain_certification_receipt'], $sourceDossierHash),
            $this->externalSupervisedCutoverWorkItem($workOrderId, $companyId, $flowId, 'bind_decision_and_operator_receipts', 'receipt_intake', 'operator', ['decision_receipt_hash', 'operator_signature_receipt', 'second_reviewer_signature_receipt'], $sourceDossierHash),
            $this->externalSupervisedCutoverWorkItem($workOrderId, $companyId, $flowId, 'bind_risk_and_budget_receipts', 'risk_and_budget', 'risk_owner_or_domain_owner', ['legal_risk_acceptance_receipt', 'budget_or_loss_cap_signature_receipt'], $sourceDossierHash),
            $this->externalSupervisedCutoverWorkItem($workOrderId, $companyId, $flowId, 'bind_vault_and_connector_scope', 'vault_scope', 'credential_operator', ['vault_scope_receipt', 'connector_scope_receipt'], $sourceDossierHash),
            $this->externalSupervisedCutoverWorkItem($workOrderId, $companyId, $flowId, 'open_change_window_and_arm_controls', 'runtime_control', 'runtime_operator', ['change_window_receipt', 'kill_switch_armed_receipt'], $sourceDossierHash),
            $this->externalSupervisedCutoverWorkItem($workOrderId, $companyId, $flowId, 'prepare_tool_receipt_sink', 'reconciliation', 'evidence_operator', ['tool_receipt_sink_receipt', 'post_execution_reconciliation_job_receipt'], $sourceDossierHash),
            $this->externalSupervisedCutoverWorkItem($workOrderId, $companyId, $flowId, 'record_operator_closeout_plan', 'closeout', 'operator', ['operator_closeout_receipt'], $sourceDossierHash),
            $this->externalSupervisedCutoverWorkItem($workOrderId, $companyId, $flowId, 'final_go_no_go_review', 'go_no_go', 'operator_and_second_reviewer', $missingReceiptIds, $sourceDossierHash),
        ];

        $ready = (bool) ($dossier['cutover_dossier_ready'] ?? false)
            && strlen($sourceDossierHash) === 64
            && $missingReceiptIds !== []
            && count($workItems) === 9
            && (bool) data_get($dossier, 'readiness_evidence.agent_repository_execution_gate_bound', false)
            && (int) data_get($dossier, 'readiness_evidence.repository_tool_permission_manifest_count', 0) >= 1
            && (int) data_get($dossier, 'readiness_evidence.repository_eval_replay_recipe_count', 0) >= 1
            && (int) data_get($dossier, 'readiness_evidence.domain_certified_tool_contract_count', 0) >= 1
            && count(array_filter($workItems, static fn (array $item): bool => (bool) ($item['executable'] ?? true))) === 0
            && (bool) ($dossier['supervised_cutover_enabled'] ?? true) === false
            && (bool) ($dossier['external_execution_allowed'] ?? true) === false
            && (bool) ($dossier['external_side_effects_enabled'] ?? true) === false;

        $workOrder = [
            'schema' => 'atlas.ai.company.external_supervised_cutover_work_order.v1',
            'work_order_id' => $workOrderId,
            'company_id' => $companyId,
            'flow_id' => $flowId,
            'work_order_ready' => $ready,
            'phase' => 'receipt_intake_and_operator_cutover_preparation',
            'cutover_decision' => 'launch_blocked_until_work_items_have_real_receipts',
            'supervised_cutover_enabled' => false,
            'external_execution_allowed' => false,
            'external_side_effects_enabled' => false,
            'source_external_supervised_cutover_dossier_hash' => $sourceDossierHash,
            'required_receipt_ids' => array_values(array_map(
                'strval',
                (array) data_get($dossier, 'operator_cutover_packet.required_receipt_ids', []),
            )),
            'missing_receipt_ids' => $missingReceiptIds,
            'repository_toolchain_evidence' => [
                'agent_repository_execution_gate_bound' => (bool) data_get($dossier, 'readiness_evidence.agent_repository_execution_gate_bound', false),
                'repository_tool_permission_manifest_count' => (int) data_get($dossier, 'readiness_evidence.repository_tool_permission_manifest_count', 0),
                'repository_eval_replay_recipe_count' => (int) data_get($dossier, 'readiness_evidence.repository_eval_replay_recipe_count', 0),
                'domain_certified_tool_contract_count' => (int) data_get($dossier, 'readiness_evidence.domain_certified_tool_contract_count', 0),
                'source_external_receipt_binder_hash' => (string) data_get($dossier, 'readiness_evidence.source_external_receipt_binder_hash', ''),
                'source_external_launch_control_hash' => (string) data_get($dossier, 'readiness_evidence.source_external_launch_control_hash', ''),
            ],
            'work_items' => $workItems,
            'operator_enablement_pack' => [
                'unified_workbench_required' => true,
                'source_links_required_for_each_claim' => true,
                'prebuilt_connector_or_adapter_required' => true,
                'audit_trail_required' => true,
                'post_execution_reconciliation_required' => true,
                'manual_operator_training_required' => true,
            ],
            'launch_blockers' => [
                'work_items_pending_real_receipts',
                'repository_toolchain_certification_receipts_not_bound',
                'operator_go_no_go_missing',
                'second_reviewer_go_no_go_missing',
                'vault_scope_not_released',
                'reconciliation_sink_not_confirmed',
            ],
        ];
        $workOrder['external_supervised_cutover_work_order_hash'] = MissionCanonicalHash::sha256($workOrder);

        return $workOrder;
    }

    /**
     * @param list<string> $receiptIds
     * @return array<string,mixed>
     */
    public function externalSupervisedCutoverWorkItem(
        string $workOrderId,
        string $companyId,
        string $flowId,
        string $actionId,
        string $workstream,
        string $ownerRole,
        array $receiptIds,
        string $sourceDossierHash,
    ): array {
        $item = [
            'schema' => 'atlas.ai.company.external_supervised_cutover_work_item.v1',
            'work_item_id' => hash('sha256', $workOrderId.'|'.$actionId),
            'work_order_id' => $workOrderId,
            'company_id' => $companyId,
            'flow_id' => $flowId,
            'action_id' => $actionId,
            'workstream' => $workstream,
            'owner_role' => $ownerRole,
            'required_receipt_ids' => array_values($receiptIds),
            'status' => 'pending_real_receipt_or_operator_action',
            'executable' => false,
            'external_execution_allowed' => false,
            'external_side_effects_enabled' => false,
            'source_external_supervised_cutover_dossier_hash' => $sourceDossierHash,
            'completion_requires' => [
                'real_receipt_hash',
                'source_link_or_ledger_reference',
                'operator_attestation',
                'reconciliation_reference_when_applicable',
            ],
            'blocked_operations' => ['execute', 'launch', 'write', 'publish', 'spend', 'trade', 'deploy', 'delete', 'secret_export'],
        ];
        $item['external_supervised_cutover_work_item_hash'] = MissionCanonicalHash::sha256($item);

        return $item;
    }
}
