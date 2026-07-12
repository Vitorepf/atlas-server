<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\RuntimeDaemon;

use App\Services\Ai\EngineeringKernel\EliteExecutorKernel;
use App\Services\Ai\EngineeringKernel\ExecutionOrder;
use App\Services\Ai\EngineeringKernel\ProviderPort;
use App\Services\Ai\SelfConstruction\NativeImplementation\AtlasSelfConstructionHermeticSandboxApplyService;
use App\Services\Ai\SelfConstruction\NativeWorker\AtlasNativeWorkerProductionCallbacks;
use App\Services\Ai\SelfConstruction\NativeWorker\AtlasNativeWorkerProductionRuntime;
use App\Services\Ai\SelfConstruction\NativeWorker\AtlasNativeWorkerRecoverableProductionRuntime;
use App\Services\Ai\SelfConstruction\NativeWorker\AutonomosExecutionOrderBinding;

/**
 * Typed productive seam for daemon actions.
 *
 * The daemon receives this owner through constructor injection. Arbitrary
 * caller/container callbacks are deliberately outside the productive API.
 */
class AtlasSelfConstructionNativeActionExecutor
{
    public function __construct(
        private readonly ?AtlasNativeWorkerProductionRuntime $production = null,
        private readonly ?ProviderPort $provider = null,
        private readonly ?AtlasSelfConstructionHermeticSandboxApplyService $sandbox = null,
        private readonly ?EliteExecutorKernel $eliteKernel = null,
    ) {}

    /**
     * @param  array<string,mixed>  $action
     * @param  array<string,mixed>  $state
     * @return array<string,mixed>
     */
    public function execute(array $action, array $state): array
    {
        $kind = (string) ($action['kind'] ?? '');
        if ($kind === 'native_tick') {
            $providerKey = trim((string) ($action['provider'] ?? ''));
            $model = trim((string) ($action['model'] ?? ''));
            if ($providerKey === '' || $model === '') {
                return ['status' => 'held', 'reason' => 'provider_route_missing', 'retryable' => true];
            }
            $runtime = $this->production ?? app(AtlasNativeWorkerProductionCallbacks::class);
            $clientId = 'atlas-self-construction-runtime-daemon';
            $claim = null;
            if ($runtime instanceof AtlasNativeWorkerRecoverableProductionRuntime) {
                $claim = $runtime->resume($clientId);
                if (is_array($claim) && ! $runtime->renew(
                    $clientId,
                    (string) ($claim['task_packet_id'] ?? ''),
                    (string) ($claim['lease_id'] ?? ''),
                )) {
                    return ['status' => 'held', 'reason' => 'lease_recovery_failed', 'retryable' => true];
                }
            }
            $claim ??= $runtime->claim($clientId);
            if (! is_array($claim)) {
                return ['status' => 'held', 'reason' => 'no_claimable_task', 'retryable' => true];
            }
            $qualityFoundryBinding = null;
            if (($action['quality_foundry_required'] ?? false) === true) {
                try {
                    $qualityFoundryBinding = AutonomosExecutionOrderBinding::fromPayload($action + $claim);
                } catch (\Throwable $exception) {
                    return ['status' => 'held', 'reason' => $exception->getMessage(), 'retryable' => false];
                }
                if ($qualityFoundryBinding === null) {
                    return ['status' => 'held', 'reason' => 'quality_foundry_execution_order_missing', 'retryable' => false];
                }

                // Quality Foundry orders are owned by the shared Kernel. The
                // daemon may translate the claim into the canonical binding,
                // but it must not invoke a provider or apply a sandbox itself.
                // The legacy path below remains only for non-Quality-Foundry
                // compatibility while Packet 6 drains and removes v1.
                try {
                    $kernel = $this->eliteKernel ?? app(EliteExecutorKernel::class);
                    $outcome = $kernel->execute(ExecutionOrder::fromArray($qualityFoundryBinding['execution_order']));

                    $terminal = $this->reportTerminalKernelOutcome(
                        $runtime,
                        $clientId,
                        $claim,
                        $outcome->toArray(),
                    );
                    if ($terminal !== null) {
                        return $terminal;
                    }

                    return [
                        'status' => 'held',
                        'reason' => 'kernel_outcome_pending_release',
                        'retryable' => true,
                        'kernel_routed' => true,
                        'order_hash' => $qualityFoundryBinding['order_hash'],
                        'engineering_outcome' => $outcome->toArray(),
                    ];
                } catch (\Throwable $exception) {
                    return [
                        'status' => 'held',
                        'reason' => 'kernel_execution_failed',
                        'retryable' => true,
                        'kernel_routed' => true,
                        'order_hash' => $qualityFoundryBinding['order_hash'],
                        'exception' => $exception::class,
                    ];
                }
            }
            if (! $this->provider instanceof ProviderPort) {
                return ['status' => 'held', 'reason' => 'provider_port_unavailable', 'retryable' => true];
            }
            $idempotencyKey = hash('sha256', implode('|', [
                (string) ($claim['task_packet_id'] ?? ''),
                (string) ($claim['lease_id'] ?? ''),
                (string) ($claim['authority_hash'] ?? ''),
                (string) ($claim['envelope_hash'] ?? ''),
            ]));
            $sandbox = $this->sandbox ?? new AtlasSelfConstructionHermeticSandboxApplyService;
            $manifest = $sandbox->reconcile($idempotencyKey);
            if (($manifest['state'] ?? null) === 'applied') {
                return ['status' => 'held', 'reason' => 'governed_release_and_canary_pending', 'retryable' => true, 'replayed' => true, 'idempotency_key' => $idempotencyKey];
            }
            $providerReceipt = is_array($manifest['provider_receipt'] ?? null) ? $manifest['provider_receipt'] : null;
            try {
                $providerRequest = [
                    'execute_provider' => true,
                    'capability' => 'atlas.self_construction.native_patch_plan',
                    'provider' => $providerKey,
                    'model' => $model,
                    'prompt' => (string) json_encode([
                        'objective' => 'Produce only a governed JSON patch_plan for this claim. Never propose commands.',
                        'claim' => $claim,
                    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    'claim' => $claim,
                    'action' => $action,
                    'state_hash' => (string) ($state['state_hash'] ?? ''),
                ];
                $providerReceipt ??= $this->provider->invoke($providerRequest);
            } catch (\Throwable $e) {
                return [
                    'status' => 'held',
                    'reason' => str_contains(strtolower($e->getMessage()), 'timeout') ? 'provider_timeout' : 'provider_down',
                    'retryable' => true,
                ];
            }
            if (($providerReceipt['status'] ?? 'ok') !== 'ok' || ($providerReceipt['exhausted'] ?? false) === true) {
                return ['status' => 'held', 'reason' => 'provider_fallback_exhausted', 'retryable' => true];
            }
            if (! $sandbox->stageProvider($idempotencyKey, $providerReceipt, [
                'task_packet_id' => (string) ($claim['task_packet_id'] ?? ''),
                'lease_id' => (string) ($claim['lease_id'] ?? ''),
            ])) {
                return ['status' => 'held', 'reason' => 'provider_receipt_conflict', 'retryable' => false, 'idempotency_key' => $idempotencyKey];
            }
            $sandboxReceipt = $sandbox->execute([
                'idempotency_key' => $idempotencyKey,
                'allowed_files' => (array) ($claim['allowed_files'] ?? []),
                'patch_plan' => (array) ($providerReceipt['patch_plan'] ?? []),
                'provider_receipt' => $providerReceipt,
                'task_packet_id' => (string) ($claim['task_packet_id'] ?? ''),
                'lease_id' => (string) ($claim['lease_id'] ?? ''),
            ]);
            if (($sandboxReceipt['applied'] ?? false) !== true) {
                return [
                    'status' => 'held',
                    'reason' => (string) ($sandboxReceipt['reason'] ?? 'sandbox_apply_failed'),
                    'retryable' => true,
                    'idempotency_key' => $idempotencyKey,
                ];
            }

            // A native worker success is not a release. The daemon keeps the
            // action held until the shared Kernel/Governor supplies independent
            // acceptance, release and canary receipts.
            return [
                'status' => 'held',
                'reason' => 'governed_release_and_canary_pending',
                'retryable' => true,
                'sandbox_applied' => true,
                'sandbox_receipt_hash' => hash('sha256', (string) json_encode($sandboxReceipt, JSON_UNESCAPED_SLASHES)),
                'idempotency_key' => $idempotencyKey,
                'provider_receipt_hash' => hash('sha256', (string) json_encode($providerReceipt, JSON_UNESCAPED_SLASHES)),
            ];
        }

        if ($kind === 'atlas_native_brain_recovery') {
            return [
                'status' => 'held',
                'reason' => 'brain_recovery_requires_durable_task',
                'retryable' => true,
            ];
        }

        return [
            'status' => 'held',
            'reason' => 'unsupported_native_action_kind:'.$kind,
            'retryable' => true,
        ];
    }

    /**
     * Close the task lease only after the shared Kernel has produced a
     * terminal outcome. A pending canary remains open for polling; released
     * and blocked outcomes are reported exactly once through the canonical
     * serving contract instead of being misclassified as retryable holds.
     *
     * @param  array<string,mixed>  $claim
     * @param  array<string,mixed>  $outcome
     * @return array<string,mixed>|null
     */
    private function reportTerminalKernelOutcome(
        AtlasNativeWorkerProductionRuntime $runtime,
        string $clientId,
        array $claim,
        array $outcome,
    ): ?array {
        $status = (string) ($outcome['status'] ?? '');
        if (! in_array($status, ['released', 'blocked', 'completed_read_only'], true)) {
            return null;
        }

        $taskPacketId = (string) ($claim['task_packet_id'] ?? '');
        $leaseId = (string) ($claim['lease_id'] ?? '');
        $reportOutcome = in_array($status, ['released', 'completed_read_only'], true) ? 'success' : 'failed';
        $report = $runtime->report($clientId, [
            'task_packet_id' => $taskPacketId,
            'lease_id' => $leaseId,
            'outcome' => $reportOutcome,
            'commit' => false,
            'evidence' => [
                'engineering_outcome_hash' => (string) ($outcome['outcome_hash'] ?? ''),
                'engineering_outcome_status' => $status,
                'correlated_hashes' => (array) ($outcome['correlated_hashes'] ?? []),
            ],
            'error' => $status === 'blocked' ? (string) (($outcome['uncertainties'][0] ?? '') ?: 'kernel_outcome_blocked') : null,
        ]);

        return [
            'status' => $reportOutcome === 'success' ? 'resolved' : 'failed',
            'reason' => $status === 'blocked' ? 'kernel_outcome_blocked' : 'kernel_outcome_terminal',
            'retryable' => false,
            'kernel_routed' => true,
            'kernel_outcome_status' => $status,
            'engineering_outcome_hash' => (string) ($outcome['outcome_hash'] ?? ''),
            'report' => $report,
        ];
    }
}
