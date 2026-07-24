<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\RuntimeDaemon;

use App\Services\Ai\EngineeringKernel\Adapters\AgentExecutionProviderPortAdapter;
use App\Services\Ai\SelfConstruction\NativeWorker\AtlasNativeWorkerProductionRuntime;
use Throwable;

/**
 * Single native composition owner for runtime-daemon command and AAEOS.
 *
 * The service owns the productive cycle. Console and control-plane surfaces
 * translate input only; they do not rebuild a second daemon pipeline.
 */
final class AtlasSelfConstructionRuntimeDaemon
{
    public const FINAL_RUNTIME_OWNER = 'atlas_native';

    public const STEADY_STATE_RUNTIME_OWNER = 'atlas_server';

    public function __construct(
        private readonly ?AtlasSelfConstructionNativeActionExecutor $actionExecutor = null,
        private readonly ?AtlasNativeWorkerProductionRuntime $nativeWorker = null,
    ) {}

    /**
     * @param  array<string,mixed>  $facts
     * @return array<string,mixed>
     */
    public function run(
        string $action,
        array $facts = [],
        bool $dryRun = false,
        int $maxCycles = 1,
    ): array {
        $apply = in_array($action, ['tick', 'run-once'], true) && ! $dryRun;

        try {
            return match ($action) {
                'status' => $this->statusAction($facts),
                'plan' => $this->planAction($facts),
                'claim' => $this->claimAction($facts, $dryRun),
                'tick' => $this->tickAction($facts, $apply),
                'run-once' => $this->runOnceAction($facts, $apply, max(1, $maxCycles)),
                'pause' => $this->controlAction($facts, ['type' => 'pause_requested']),
                'resume' => $this->controlAction($facts, ['type' => 'resume']),
                'stop' => $this->controlAction($facts, ['type' => 'stop_requested']),
                default => ['status' => 'unknown_action', 'action' => $action],
            };
        } catch (Throwable $exception) {
            return ['status' => 'error', 'error' => $exception->getMessage()];
        }
    }

    /** @param array<string,mixed> $facts @return array<string,mixed> */
    private function statusAction(array $facts): array
    {
        $state = is_array($facts['daemon_state'] ?? null) ? $facts['daemon_state'] : [];

        return $this->withOwnership([
            'status' => 'ok',
            'action' => 'status',
            'dry_run' => true,
            'daemon_state' => $state,
            'safety_stop' => (bool) ($state['safety_stop'] ?? false),
            'next_tick_allowed' => (bool) ($state['next_tick_allowed'] ?? false),
            'planned_actions' => array_values((array) ($facts['planned_actions'] ?? [])),
            'applied_actions' => [],
            'evidence_obligations' => $this->evidenceObligations(),
        ]);
    }

    /** @param array<string,mixed> $facts @return array<string,mixed> */
    private function planAction(array $facts): array
    {
        $verdict = (new AtlasSelfConstructionRuntimeDaemonCycle)->tick($facts);

        return $this->withOwnership([
            'status' => 'ok',
            'action' => 'plan',
            'dry_run' => true,
            'daemon_status' => (string) $verdict['daemon_status'],
            'safety_stop' => (bool) ($verdict['next_state']['safety_stop'] ?? false),
            'next_tick_allowed' => (bool) ($verdict['next_state']['next_tick_allowed'] ?? false),
            'planned_actions' => $verdict['planned_actions'],
            'applied_actions' => $verdict['applied_actions'],
            'withheld_actions' => $verdict['withheld_actions'],
            'cycle_blocked_reasons' => $verdict['cycle_blocked_reasons'],
            'daemon_cycle_hash' => $verdict['daemon_cycle_hash'],
            'evidence_obligations' => $this->evidenceObligations(),
        ]);
    }

    /** @param array<string,mixed> $facts @return array<string,mixed> */
    private function tickAction(array $facts, bool $apply): array
    {
        $facts = $this->withProductiveNativeTick($facts, $apply);
        $verdict = $this->productiveCycle()->tick($facts, ['apply' => $apply]);

        return $this->withOwnership([
            'status' => 'ok',
            'action' => 'tick',
            'dry_run' => $verdict['dry_run'],
            'daemon_status' => $verdict['daemon_status'],
            'safety_stop' => (bool) ($verdict['next_state']['safety_stop'] ?? false),
            'next_tick_allowed' => (bool) ($verdict['next_state']['next_tick_allowed'] ?? false),
            'planned_actions' => $verdict['planned_actions'],
            'applied_actions' => $verdict['applied_actions'],
            'withheld_actions' => $verdict['withheld_actions'],
            'blocked_actions' => $verdict['blocked_actions'],
            'cycle_blocked_reasons' => $verdict['cycle_blocked_reasons'],
            'daemon_cycle_hash' => $verdict['daemon_cycle_hash'],
            'evidence_obligations' => $this->evidenceObligations(),
        ]);
    }

    /** @param array<string,mixed> $facts @return array<string,mixed> */
    private function runOnceAction(array $facts, bool $apply, int $maxCycles): array
    {
        $ticks = [];
        $state = is_array($facts['daemon_state'] ?? null) ? $facts['daemon_state'] : [];
        $cycle = $this->productiveCycle();

        for ($i = 0; $i < $maxCycles; $i++) {
            $tickFacts = array_replace($facts, ['daemon_state' => $state]);
            $tickFacts = $this->withProductiveNativeTick($tickFacts, $apply);
            $verdict = $cycle->tick($tickFacts, ['apply' => $apply]);
            $ticks[] = $verdict;
            $state = $verdict['next_state'];
            if (! (bool) ($state['next_tick_allowed'] ?? false)) {
                break;
            }
        }

        return $this->withOwnership([
            'status' => 'ok',
            'action' => 'run-once',
            'dry_run' => ! $apply,
            'max_cycles' => $maxCycles,
            'cycle_count' => count($ticks),
            'ticks' => $ticks,
            'final_state' => $state,
            'daemon_status' => (string) ($state['status'] ?? 'unknown'),
            'native_journey_ref' => $ticks === [] ? null : 'daemon-cycle:'.(string) ($ticks[0]['daemon_cycle_hash'] ?? ''),
            'native_cycle_refs' => array_values(array_filter(array_map(
                static fn (array $tick): ?string => isset($tick['daemon_cycle_hash'])
                    ? 'daemon-cycle:'.(string) $tick['daemon_cycle_hash']
                    : null,
                $ticks,
            ))),
            'native_task_refs' => $this->nativeTaskRefs($ticks),
            'evidence_obligations' => $this->evidenceObligations(),
        ]);
    }

    /** @param array<string,mixed> $facts @param array<string,mixed> $event @return array<string,mixed> */
    private function controlAction(array $facts, array $event): array
    {
        $state = is_array($facts['daemon_state'] ?? null) ? $facts['daemon_state'] : [];
        $next = (new AtlasSelfConstructionRuntimeDaemonState)->reduce($state, $event);

        return $this->withOwnership([
            'status' => 'ok',
            'action' => (string) $event['type'],
            'dry_run' => true,
            'daemon_status' => (string) $next['status'],
            'safety_stop' => (bool) ($next['safety_stop'] ?? false),
            'next_tick_allowed' => (bool) ($next['next_tick_allowed'] ?? false),
            'next_state' => $next,
            'evidence_obligations' => $this->evidenceObligations(),
        ]);
    }

    /**
     * Claim one governed native task without executing, materializing or reporting it.
     *
     * @param  array<string,mixed>  $facts
     * @return array<string,mixed>
     */
    private function claimAction(array $facts, bool $dryRun): array
    {
        // Claim must not initialize the productive executor: it only
        // transports the native task-serving envelope (or withholds it dry).
        return $this->withOwnership(
            (new AtlasSelfConstructionRuntimeDaemonCycle(nativeWorker: $this->nativeWorker))->claim($facts, $dryRun),
        );
    }

    public function productiveCycle(): AtlasSelfConstructionRuntimeDaemonCycle
    {
        $executor = $this->actionExecutor;
        if (! $executor instanceof AtlasSelfConstructionNativeActionExecutor) {
            $executor = app()->bound(AtlasSelfConstructionNativeActionExecutor::class)
                ? app(AtlasSelfConstructionNativeActionExecutor::class)
                : new AtlasSelfConstructionNativeActionExecutor(provider: app(AgentExecutionProviderPortAdapter::class));
        }

        return new AtlasSelfConstructionRuntimeDaemonCycle(
            actionExecutor: $executor,
            nativeWorker: $this->nativeWorker,
        );
    }

    /** @param array<string,mixed> $facts @return array<string,mixed> */
    private function withProductiveNativeTick(array $facts, bool $apply): array
    {
        if (! $apply) {
            return $facts;
        }
        $provider = trim((string) config('atlas.ai.default_provider', ''));
        $model = trim((string) config("atlas.ai.providers.{$provider}.model", ''));
        if ($provider === '' || $model === '') {
            return $facts;
        }
        $actions = (array) ($facts['planned_actions'] ?? []);
        if ($actions === []) {
            $actions[] = ['kind' => 'native_tick'];
        }
        $facts['planned_actions'] = array_map(static function (mixed $action) use ($provider, $model): mixed {
            if (! is_array($action) || ($action['kind'] ?? null) !== 'native_tick') {
                return $action;
            }
            $action['provider'] = trim((string) ($action['provider'] ?? '')) ?: $provider;
            $action['model'] = trim((string) ($action['model'] ?? '')) ?: $model;
            $action['source'] ??= 'governed_default_route';

            return $action;
        }, $actions);

        return $facts;
    }

    /** @return list<string> */
    private function evidenceObligations(): array
    {
        return ['daemon_cycle_hash', 'cycle_receipt_hash', 'state_hash'];
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    private function withOwnership(array $payload): array
    {
        return array_replace([
            'final_runtime_owner' => self::FINAL_RUNTIME_OWNER,
            'steady_state_runtime_owner' => self::STEADY_STATE_RUNTIME_OWNER,
        ], $payload);
    }

    /** @param list<array<string,mixed>> $ticks @return list<string> */
    private function nativeTaskRefs(array $ticks): array
    {
        $refs = [];
        foreach ($ticks as $tick) {
            foreach ((array) ($tick['action_feedback'] ?? []) as $feedback) {
                if (! is_array($feedback)) {
                    continue;
                }
                foreach ((array) ($feedback['receipt_refs'] ?? []) as $ref) {
                    if (is_string($ref) && $ref !== '') {
                        $refs[] = $ref;
                    }
                }
            }
        }

        return array_values(array_unique($refs));
    }
}
