<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Control\Dispatch;

use App\Services\Ai\Aaeos\Control\AaeosExecutorMode;
use App\Services\Ai\Aaeos\Control\AaeosSelfEvolutionQualityLoop;
use App\Services\Ai\SelfConstruction\RuntimeDaemon\AtlasSelfConstructionRuntimeDaemon;

/**
 * Live Autônomos path: a typed native task claim through the shared daemon.
 * It never executes the worker or invokes an external provider.
 */
final class AutonomosLiveDispatcher implements AaeosModeLiveDispatcher
{
    public function __construct(
        private readonly ?AtlasSelfConstructionRuntimeDaemon $daemon = null,
    ) {}

    public function mode(): string
    {
        return AaeosExecutorMode::AUTONOMOS;
    }

    public function liveDispatch(array $cyclePlan, array $options = []): array
    {
        if (($reason = $this->prohibitedRequestReason($options)) !== null) {
            return $this->refused($reason);
        }

        try {
            $daemon = $this->daemon ?? $this->resolveDaemon();
        } catch (\Throwable) {
            return $this->refused('native_runtime_daemon_unavailable');
        }
        $intent = trim((string) ($cyclePlan['objective']['objective'] ?? $cyclePlan['objective']['raw'] ?? ''));
        $facts = $intent === '' ? [] : ['intent' => $intent];
        $workspace = trim((string) ($options['workspace'] ?? ''));
        if ($workspace !== '') {
            $facts['workspace'] = $workspace;
        }
        $dryRun = (bool) ($options['dry_run'] ?? false);
        $claim = $daemon->run('claim', $facts, $dryRun);
        $claimed = (string) ($claim['status'] ?? '') === 'claimed';
        $planned = (string) ($claim['status'] ?? '') === 'planned';

        $result = [
            'status' => $claimed ? 'claimed' : ($planned ? 'plan_only' : 'dispatch_refused'),
            'effects' => [[
                'kind' => $claimed ? 'native_task_claimed' : ($planned ? 'native_task_claim_planned' : 'native_task_claim_refused'),
                'result' => $claim,
            ]],
            'provider_calls' => 0,
            'seed_gate_required' => true,
            'scoped_commit_required' => true,
            'effect_level' => $claimed ? 'claimed' : ($planned ? 'none' : 'blocked'),
            'native_journey_ref' => $claim['native_journey_ref'] ?? null,
            'native_cycle_refs' => is_array($claim['native_cycle_refs'] ?? null) ? $claim['native_cycle_refs'] : [],
            'native_task_refs' => is_array($claim['native_task_refs'] ?? null) ? $claim['native_task_refs'] : [],
            'native_lease_refs' => is_array($claim['native_lease_refs'] ?? null) ? $claim['native_lease_refs'] : [],
            'task' => is_array($claim['task'] ?? null) ? $claim['task'] : [
                'status' => $claimed ? 'claimed' : 'not_claimed',
                'task_packet_id' => $claim['task_packet_id'] ?? null,
                'lease_id' => $claim['lease_id'] ?? null,
                'worker_executed' => false,
            ],
            'mutation_performed' => false,
            ...($claimed || $planned ? [] : ['error' => (string) ($claim['reason'] ?? 'native_claim_refused')]),
        ];

        // P2g-EVOL: optional measure ingress → structured quality evolution plan (pure, no enqueue).
        $measure = $cyclePlan['excellence_measure']
            ?? $options['excellence_measure']
            ?? null;
        if (is_array($measure) && $measure !== []) {
            $loopContext = is_array($cyclePlan['self_evolution_context'] ?? null)
                ? $cyclePlan['self_evolution_context']
                : (is_array($options['self_evolution_context'] ?? null) ? $options['self_evolution_context'] : []);
            $result['self_evolution_plan'] = AaeosSelfEvolutionQualityLoop::planFromMeasure($measure, $loopContext);
        }

        return $result;
    }

    /** @param array<string,mixed> $options */
    private function prohibitedRequestReason(array $options): ?string
    {
        if ((bool) ($options['execute_provider'] ?? false)) {
            return 'p1a_execute_provider_forbidden';
        }
        if ((bool) ($options['run_worker_once'] ?? false)) {
            return 'p1a_run_worker_once_forbidden';
        }
        if ((int) ($options['max_seeds'] ?? 0) > 0) {
            return 'p1a_max_seeds_forbidden';
        }
        if (trim((string) ($options['scope'] ?? '')) !== '') {
            return 'p1a_scope_forbidden';
        }

        return null;
    }

    private function resolveDaemon(): AtlasSelfConstructionRuntimeDaemon
    {
        // In Laravel, use the declared composition owner. The direct
        // constructor remains only for cold/no-container contract tests.
        if (function_exists('app')) {
            return app(AtlasSelfConstructionRuntimeDaemon::class);
        }

        return new AtlasSelfConstructionRuntimeDaemon;
    }

    /** @return array<string,mixed> */
    private function refused(string $reason): array
    {
        return [
            'status' => 'dispatch_refused',
            'effects' => [['kind' => 'native_task_claim_refused', 'reason' => $reason]],
            'provider_calls' => 0,
            'seed_gate_required' => true,
            'scoped_commit_required' => true,
            'effect_level' => 'blocked',
            'native_journey_ref' => null,
            'native_cycle_refs' => [],
            'native_task_refs' => [],
            'native_lease_refs' => [],
            'task' => [
                'status' => 'not_claimed',
                'task_packet_id' => null,
                'lease_id' => null,
                'worker_executed' => false,
            ],
            'mutation_performed' => false,
            'error' => $reason,
        ];
    }
}
