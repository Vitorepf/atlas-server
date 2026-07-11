<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\RuntimeDaemon;

use App\Services\Ai\SelfConstruction\NativeWorker\AtlasNativeWorkerClaimExecuteReportCycle;
use App\Services\Ai\SelfConstruction\NativeWorker\AtlasNativeWorkerProductionCallbacks;

/**
 * Typed productive seam for daemon actions.
 *
 * The daemon receives this owner through constructor injection. Arbitrary
 * caller/container callbacks are deliberately outside the productive API.
 */
class AtlasSelfConstructionNativeActionExecutor
{
    public function __construct(
        private readonly ?AtlasNativeWorkerClaimExecuteReportCycle $workerCycle = null,
        private readonly ?AtlasNativeWorkerProductionCallbacks $production = null,
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
            $cycle = $this->workerCycle ?? app(AtlasNativeWorkerClaimExecuteReportCycle::class);
            $callbacks = ($this->production ?? app(AtlasNativeWorkerProductionCallbacks::class))
                ->forClient('atlas-self-construction-runtime-daemon');
            $receipt = $cycle->run(array_replace($callbacks, [
                'dry_run' => false,
                'client_id' => 'atlas-self-construction-runtime-daemon',
            ]));

            // A native worker success is not a release. The daemon keeps the
            // action held until the shared Kernel/Governor supplies independent
            // acceptance, release and canary receipts.
            return [
                'status' => 'held',
                'reason' => ($receipt['report_outcome'] ?? '') === 'success'
                    ? 'governed_release_and_canary_pending'
                    : (string) ($receipt['step_retry_contract']['reportable_outcome_reason'] ?? 'native_worker_incomplete'),
                'retryable' => true,
                'native_worker_cycle_hash' => (string) ($receipt['cycle_hash'] ?? ''),
                'native_worker_status' => (string) ($receipt['status'] ?? 'unknown'),
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
