<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Control\Dispatch;

use App\Services\Ai\Aaeos\Control\AaeosExecutorMode;
use App\Services\Ai\Programming\Forge\Execution\ForgeCommissioning;
use App\Services\Ai\Programming\Forge\Execution\ForgeObraRuntime;

/**
 * Forge native commissioning path. Execution authority remains closed in P1a.
 */
final class ForgeLiveDispatcher implements AaeosModeLiveDispatcher
{
    public function __construct(
        private readonly ?ForgeObraRuntime $runtime = null,
    ) {}

    public function mode(): string
    {
        return AaeosExecutorMode::FORGE;
    }

    public function liveDispatch(array $cyclePlan, array $options = []): array
    {
        $commissioningInput = $options['forge_commissioning'] ?? null;
        if (! $commissioningInput instanceof ForgeCommissioning) {
            return $this->refused('forge_commissioning_required');
        }
        $workspace = trim((string) ($options['workspace'] ?? ''));
        if ($workspace !== '' && $workspace !== $commissioningInput->workspace) {
            return $this->refused('forge_commissioning_workspace_mismatch');
        }
        if (trim($commissioningInput->commissioningHash) === ''
            || trim($commissioningInput->authorityHash) === ''
            || $commissioningInput->releasePolicy !== ForgeCommissioning::RELEASE_POLICY_CANONICAL_COMMIT_WITH_CANARY
            || $commissioningInput->interruptionPolicy !== ForgeCommissioning::INTERRUPTION_POLICY_PAUSE_DRAIN_RESUME) {
            return $this->refused('forge_commissioning_binding_invalid');
        }

        if ((bool) ($options['execute_provider'] ?? false)) {
            return $this->refused('p1b_authority_not_green', 'provider_execution_refused');
        }

        try {
            $runtime = $this->runtime ?? app(ForgeObraRuntime::class);
            $commissioning = $runtime->commissioningContract($commissioningInput);
        } catch (\Throwable) {
            return $this->refused('native_forge_owner_unavailable');
        }

        return [
            'status' => 'commissioned',
            'effects' => [['kind' => 'native_forge_commissioned', 'commissioning' => $commissioning]],
            'commissioning' => $commissioning,
            'provider_calls' => 0,
            'mutation_performed' => false,
            'human_in_planning' => true,
            'effect_level' => 'prepared',
        ];
    }

    /** @return array<string,mixed> */
    private function refused(string $reason, string $kind = 'native_forge_refused'): array
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
