<?php

namespace App\Services\Ai\SelfConstruction;



use App\Services\Ai\SelfConstruction\Concerns\RecursivelyKsortsArrays;
use App\Services\Ai\SelfConstruction\Support\KsortsArraysByReference;
use Carbon\CarbonImmutable;

final class AtlasSelfConstructionDispatchSchedulerReceiptRuntimeReentryClosureService
{
    use RecursivelyKsortsArrays { recursivelyKsort as ksortRecursive; }

    use KsortsArraysByReference;


    /**
     * @param  array<string,mixed>  $value
     * @return array<string,mixed>
     */
    public const SCHEMA_VERSION = 'atlas.self_construction.dispatch_scheduler_receipt_runtime_reentry_closure.v1';

    public const MODE = 'read_only_dispatch_scheduler_receipt_runtime_reentry_closure';

    public function __construct(
        private readonly AtlasSelfConstructionReadinessService $readiness,
    ) {}

    /** @return array<string, mixed> */
    public function certify(array $options = []): array
    {
        $methods = [
            'post_start_receipt_contract' => 'agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartReceiptContractStatus',
            'post_start_evidence_receipt' => 'agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartEvidenceReceiptStatus',
            'post_start_evidence_acceptance_bridge' => 'agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartEvidenceAcceptanceBridgeStatus',
            'post_start_liveness_monitor' => 'agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartLivenessMonitorStatus',
            'post_start_dispatch_release_gate' => 'agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartDispatchReleaseGateStatus',
            'post_start_signed_dispatch_authorization_gate' => 'agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartSignedDispatchAuthorizationGateStatus',
            'post_start_dispatch_executor_handoff' => 'agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartDispatchExecutorHandoffStatus',
            'post_start_dispatch_receipt_use_executor' => 'agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartDispatchReceiptUseExecutorStatus',
            'post_start_provider_start_driver_gate' => 'agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProviderStartDriverGateStatus',
        ];
        $corridor = [];
        $violations = [];

        foreach ($methods as $slice => $method) {
            if (! method_exists($this->readiness, $method)) {
                $corridor[$slice] = ['method' => $method, 'status' => 'method_missing', 'ready' => false];
                $violations[] = ['code' => 'corridor_status_method_missing', 'slice' => $slice];

                continue;
            }

            try {
                $status = $this->readiness->{$method}($options);
            } catch (\Throwable $e) {
                $corridor[$slice] = ['method' => $method, 'status' => 'exception', 'ready' => false, 'error' => $e->getMessage()];
                $violations[] = ['code' => 'corridor_status_exception', 'slice' => $slice];

                continue;
            }

            $statusValue = (string) ($status['status'] ?? '');
            $ready = str_ends_with($statusValue, '_service_ready');
            $corridor[$slice] = [
                'method' => $method,
                'status' => $statusValue,
                'ready' => $ready,
                'status_hash' => $this->stableHash($status),
            ];
            if (! $ready) {
                $violations[] = ['code' => 'corridor_slice_not_ready', 'slice' => $slice, 'status' => $statusValue];
            }
        }

        foreach (['actual_process_start_allowed', 'provider_process_call_allowed', 'adapter_invocation_allowed', 'adapter_execution_allowed', 'token_spend_allowed', 'dispatch_allowed', 'self_programming_allowed', 'external_process_started', 'provider_started', 'process_started'] as $flag) {
            if (($options[$flag] ?? false) === true) {
                $violations[] = ['code' => 'runtime_flag_true', 'flag' => $flag];
            }
        }

        $candidate = $violations === [];
        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'status' => $candidate ? 'available' : 'blocked',
            'certified_at' => CarbonImmutable::now()->toIso8601String(),
            'runtime_gap' => 'automatic_dispatch_scheduler_codex_real_invoker_post_start_receipt_contract_runtime',
            'runtime_y_candidate' => $candidate,
            'runtime_enabled' => false,
            'corridor_status' => $corridor,
            'corridor_ready_count' => count(array_filter($corridor, static fn (array $row): bool => (bool) ($row['ready'] ?? false))),
            'corridor_slice_count' => count($corridor),
            'actual_process_start_allowed' => false,
            'provider_process_call_allowed' => false,
            'adapter_invocation_allowed' => false,
            'adapter_execution_allowed' => false,
            'token_spend_allowed' => false,
            'dispatch_allowed' => false,
            'self_programming_allowed' => false,
            'external_process_started' => false,
            'provider_started' => false,
            'process_started' => false,
            'violations' => $violations,
            'violation_count' => count($violations),
            'next_action' => $candidate
                ? 'eligible_for_signed_dispatch_scheduler_receipt_runtime_promotion_receipt'
                : 'repair_dispatch_scheduler_receipt_runtime_reentry_blockers',
        ];
        $payload['certification_hash'] = $this->stableHash($payload);

        return $payload;
    }

    /** @param array<string, mixed> $payload */
    private function stableHash(array $payload): string
    {
        unset($payload['certified_at'], $payload['certification_hash']);

        return hash('sha256', (string) json_encode($this->ksortRecursive($payload), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /** @param array<string, mixed> $value */
}
