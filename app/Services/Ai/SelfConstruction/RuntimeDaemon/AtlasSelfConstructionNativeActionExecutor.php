<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\RuntimeDaemon;

use App\Services\Ai\EngineeringKernel\ProviderPort;
use App\Services\Ai\SelfConstruction\NativeImplementation\AtlasSelfConstructionHermeticSandboxApplyService;
use App\Services\Ai\SelfConstruction\NativeWorker\AtlasNativeWorkerProductionCallbacks;
use App\Services\Ai\SelfConstruction\NativeWorker\AtlasNativeWorkerProductionRuntime;

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
            $claim = $runtime->claim('atlas-self-construction-runtime-daemon');
            if (! is_array($claim)) {
                return ['status' => 'held', 'reason' => 'no_claimable_task', 'retryable' => true];
            }
            if (! $this->provider instanceof ProviderPort) {
                return ['status' => 'held', 'reason' => 'provider_port_unavailable', 'retryable' => true];
            }
            try {
                $providerReceipt = $this->provider->invoke([
                    'execute_provider' => true,
                    'capability' => 'atlas.self_construction.native_patch_plan',
                    'provider' => $providerKey,
                    'model' => $model,
                    'prompt' => (string) json_encode([
                        'objective' => 'Produce only a governed JSON patch_plan and command_plan for this claim.',
                        'claim' => $claim,
                    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    'claim' => $claim,
                    'action' => $action,
                    'state_hash' => (string) ($state['state_hash'] ?? ''),
                ]);
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
            $idempotencyKey = hash('sha256', (string) ($claim['task_packet_id'] ?? '').'|'.(string) ($claim['lease_id'] ?? ''));
            $sandboxReceipt = ($this->sandbox ?? new AtlasSelfConstructionHermeticSandboxApplyService)->execute([
                'idempotency_key' => $idempotencyKey,
                'allowed_files' => (array) ($claim['allowed_files'] ?? []),
                'patch_plan' => (array) ($providerReceipt['patch_plan'] ?? []),
                'command_plan' => (array) ($providerReceipt['command_plan'] ?? []),
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
}
