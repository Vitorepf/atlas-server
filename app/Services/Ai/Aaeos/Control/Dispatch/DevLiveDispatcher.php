<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Control\Dispatch;

use App\Services\Ai\Aaeos\Control\AaeosExecutorMode;
use App\Services\Ai\Programming\AtlasDev\Execution\ConfirmedDevRun;
use App\Services\Ai\Programming\AtlasDev\SeniorLoop\SeniorEngineerLoopExecutor;

/**
 * Dev native commissioning path. P1a never invokes a provider or mutation.
 */
final class DevLiveDispatcher implements AaeosModeLiveDispatcher
{
    public function __construct(
        private readonly ?SeniorEngineerLoopExecutor $seniorLoop = null,
    ) {}

    public function mode(): string
    {
        return AaeosExecutorMode::DEV;
    }

    public function liveDispatch(array $cyclePlan, array $options = []): array
    {
        $run = $options['confirmed_dev_run'] ?? null;
        if (! $run instanceof ConfirmedDevRun) {
            return $this->refused('confirmed_dev_run_required');
        }
        $workspace = trim((string) ($options['workspace'] ?? ''));
        if ($workspace !== '' && $workspace !== $run->intent->workspace) {
            return $this->refused('confirmed_dev_run_workspace_mismatch');
        }
        if ($run->operatorId === ''
            || $run->operatorId !== $run->intent->operatorId
            || ! hash_equals($run->intent->authorityHash, $run->authorityHash)
            || ! hash_equals($run->intent->intentHash, $run->intentHash)) {
            return $this->refused('confirmed_dev_run_binding_invalid');
        }

        if ((bool) ($options['execute_provider'] ?? false)) {
            return $this->refused('p1a_provider_forbidden', 'provider_execution_refused');
        }

        try {
            $owner = $this->seniorLoop ?? app(SeniorEngineerLoopExecutor::class);
            $commissioning = $owner->commissioningContract($run->intent, $run);
        } catch (\Throwable) {
            return $this->refused('native_dev_owner_unavailable');
        }

        return [
            'status' => 'commissioned',
            'effects' => [['kind' => 'native_dev_commissioned', 'commissioning' => $commissioning]],
            'commissioning' => $commissioning,
            'provider_calls' => 0,
            'mutation_performed' => false,
            'effect_level' => 'prepared',
        ];
    }

    /** @return array<string,mixed> */
    private function refused(string $reason, string $kind = 'native_dev_refused'): array
    {
        return [
            'status' => 'dispatch_refused',
            'effects' => [['kind' => $kind, 'reason' => $reason]],
            'provider_calls' => 0,
            'mutation_performed' => false,
            'effect_level' => 'blocked',
        ];
    }
}
