<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ContinuousRuntime;

/**
 * Bounded continuous Self-Construction cycle runner.
 *
 * Executes ONE cycle over injected collaborators (duck-typed):
 *   - healthInspector      ->inspect():     {queue_health:{...}, safety_stop:bool, claimable_packet:?array}
 *   - replenisher          ->replenish($facts): array (native replenisher integration verdict)
 *   - workerIntegration    ->integrate($packet):  {accepted:bool, request:?array, blockers:list}
 *   - verifier             ->verify($request):    {verified:bool, reasons:list}
 *   - mergeDecider         ->decide($verification): {decision:string, ...}
 *   - learner              ->record($facts):       {learning:array, ...}
 *   - unattendedSupervisor ->tick($facts): array (optional — supervisor cycle snapshot for the receipt)
 *
 * STOP conditions (cycle ends with stopped=true, stop_reason set):
 *   - safety_stop                — inspector reported safety_stop
 *   - repair_first               — malformed packets present (after replenish call)
 *   - no_claimable_task          — no packet claimable (after replenish call)
 *   - worker_request_rejected    — worker integration refused the packet
 *   - verification_failed        — verifier returned verified=false
 *
 * Pure: NEVER calls a provider, spawns a process, or writes to disk.
 */
final class AtlasSelfConstructionContinuousRuntimeCycleRunner
{
    public const SCHEMA = 'atlas.continuous_runtime.cycle_runner.v1';

    public const STOP_SAFETY = 'safety_stop';
    public const STOP_REPAIR_FIRST = 'repair_first';
    public const STOP_NO_CLAIMABLE = 'no_claimable_task';
    public const STOP_WORKER_REJECTED = 'worker_request_rejected';
    public const STOP_VERIFICATION_FAILED = 'verification_failed';

    public function __construct(
        private object $healthInspector,
        private object $replenisher,
        private object $workerIntegration,
        private object $verifier,
        private object $mergeDecider,
        private object $learner,
        private ?object $unattendedSupervisor = null,
    ) {}

    /**
     * @param  array<string,mixed>  $scopeExpansion {facts?:array, options?:array{apply?:bool,action_callbacks?:array}}
     *                              When `facts` is empty the runner skips scope expansion entirely (back-compat).
     * @return array<string,mixed>
     */
    public function run(string $cycleId, array $scopeExpansion = []): array
    {
        $health = (array) $this->healthInspector->inspect();
        $supervisorResult = $this->runUnattendedSupervisor($health);

        if ((bool) ($health['safety_stop'] ?? false)) {
            return $this->stop($cycleId, self::STOP_SAFETY, [
                'health' => $health,
                'safety_reasons' => array_values((array) ($health['safety_reasons'] ?? [])),
                'unattended_supervisor' => $supervisorResult,
            ]);
        }

        $queueHealth = is_array($health['queue_health'] ?? null) ? $health['queue_health'] : [];

        if (((int) ($queueHealth['malformed_count'] ?? 0)) > 0) {
            $repl = (array) $this->replenisher->replenish($health);

            return $this->stop($cycleId, self::STOP_REPAIR_FIRST, [
                'health' => $health,
                'replenish' => $repl,
                'unattended_supervisor' => $supervisorResult,
            ]);
        }

        $packet = is_array($health['claimable_packet'] ?? null) ? $health['claimable_packet'] : null;

        if ($packet === null) {
            $repl = (array) $this->replenisher->replenish($health);

            $extra = [
                'health' => $health,
                'replenish' => $repl,
                'unattended_supervisor' => $supervisorResult,
            ];

            if (((int) ($queueHealth['claimable_depth'] ?? -1)) === 0) {
                $replAction = (string) ($repl['action'] ?? 'unknown');
                $extra['queue_low_autotopup_reason'] = 'claimable_depth_zero:replenisher_action:'.$replAction;
                $extra['replenisher_request_digest'] = 'replenish_'.substr(
                    hash('sha256', (string) json_encode($repl, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
                    0, 24
                );
            }

            return $this->stop($cycleId, self::STOP_NO_CLAIMABLE, $extra);
        }

        $worker = (array) $this->workerIntegration->integrate($packet);
        if (! (bool) ($worker['accepted'] ?? false)) {
            return $this->stop($cycleId, self::STOP_WORKER_REJECTED, [
                'health' => $health,
                'worker' => $worker,
                'unattended_supervisor' => $supervisorResult,
            ]);
        }

        $request = is_array($worker['request'] ?? null) ? $worker['request'] : [];
        $verification = (array) $this->verifier->verify($request);
        if (! (bool) ($verification['verified'] ?? false)) {
            return $this->stop($cycleId, self::STOP_VERIFICATION_FAILED, [
                'health' => $health,
                'worker' => $worker,
                'verify_merge' => ['verification' => $verification, 'merge' => null],
                'unattended_supervisor' => $supervisorResult,
            ]);
        }

        $merge = (array) $this->mergeDecider->decide($verification);
        $learn = (array) $this->learner->record([
            'cycle_id' => $cycleId,
            'verification' => $verification,
            'merge' => $merge,
        ]);

        $verifyMerge = ['verification' => $verification, 'merge' => $merge];
        $scopeExpansionResult = $this->runScopeExpansion($scopeExpansion);

        $receiptHash = $this->cycleReceiptHash(
            $cycleId, false, '', [], $worker, $verifyMerge, $learn, $supervisorResult,
        );

        return [
            'schema_version' => self::SCHEMA,
            'cycle_id' => $cycleId,
            'stopped' => false,
            'stop_reason' => null,
            'health' => $health,
            'replenish' => null,
            'worker' => $worker,
            'verify_merge' => $verifyMerge,
            'learn' => $learn,
            'unattended_supervisor' => $supervisorResult,
            'scope_expansion' => $scopeExpansionResult,
            'cycle_receipt_hash' => $receiptHash,
        ];
    }

    /**
     * @param  array<string,mixed>  $scopeExpansion
     * @return array<string,mixed>
     */
    private function runScopeExpansion(array $scopeExpansion): array
    {
        $facts = is_array($scopeExpansion['facts'] ?? null) ? $scopeExpansion['facts'] : [];
        if ($facts === []) {
            return [
                'status' => 'skipped',
                'reason' => 'no_scope_expansion_facts_supplied',
            ];
        }

        $options = is_array($scopeExpansion['options'] ?? null) ? $scopeExpansion['options'] : [];
        $governor = new \App\Services\Ai\SelfConstruction\ScopeExpansion\AtlasSelfConstructionScopeExpansionGovernorCycle();
        $verdict = $governor->run($facts, $options);

        $blockerActions = array_values((array) ($verdict['blocked_actions'] ?? []));
        $withheldActions = array_values((array) ($verdict['withheld_actions'] ?? []));
        $admitted = (int) ($verdict['admitted_count'] ?? 0);
        $withheld = (int) ($verdict['withheld_count'] ?? 0);

        $requiresExternal = false;
        foreach ($withheldActions as $action) {
            if (! is_array($action)) {
                continue;
            }
            $reason = (string) ($action['reason'] ?? '');
            if (str_starts_with($reason, 'forbidden_action_kind:')) {
                $requiresExternal = true;
                break;
            }
        }
        foreach ((array) ($verdict['ranked_candidates']['rejected_candidates'] ?? []) as $rej) {
            foreach ((array) ($rej['reasons'] ?? []) as $r) {
                if (in_array((string) $r, ['operator_dependency', 'human_dependency', 'external_provider_dependency'], true)) {
                    $requiresExternal = true;
                    break 2;
                }
            }
        }

        $status = 'ok';
        $blockers = [];
        if ($requiresExternal) {
            $status = 'hold';
            $blockers[] = 'scope_expansion_requires_non_atlas_actor';
        } elseif ($admitted === 0 && ($withheld > 0 || $blockerActions !== [])) {
            $status = 'hold';
            $blockers[] = 'scope_expansion_held_by_governor';
        }

        return [
            'status' => $status,
            'admitted_count' => $admitted,
            'withheld_count' => $withheld,
            'blockers' => $blockers,
            'governor_cycle_hash' => (string) ($verdict['governor_cycle_hash'] ?? ''),
            'dry_run' => (bool) ($verdict['dry_run'] ?? true),
            'governor_verdict' => $verdict,
        ];
    }

    /**
     * @param  array<string,mixed>  $extra
     * @return array<string,mixed>
     */
    private function stop(string $cycleId, string $reason, array $extra): array
    {
        $base = [
            'schema_version' => self::SCHEMA,
            'cycle_id' => $cycleId,
            'stopped' => true,
            'stop_reason' => $reason,
        ];
        $combined = $base + $extra;
        $combined['cycle_receipt_hash'] = $this->cycleReceiptHash(
            $cycleId,
            true,
            $reason,
            (array) ($combined['replenish'] ?? []),
            (array) ($combined['worker'] ?? []),
            (array) ($combined['verify_merge'] ?? []),
            (array) ($combined['learn'] ?? []),
            (array) ($combined['unattended_supervisor'] ?? []),
        );

        return $combined;
    }

    /** @return array<string,mixed> */
    private function runUnattendedSupervisor(array $health): array
    {
        if ($this->unattendedSupervisor === null) {
            return ['status' => 'skipped', 'reason' => 'no_supervisor_injected'];
        }

        return (array) $this->unattendedSupervisor->tick($health);
    }

    private function cycleReceiptHash(
        string $cycleId,
        bool $stopped,
        string $stopReason,
        array $replenish,
        array $worker,
        array $verifyMerge,
        array $learn,
        array $unattendedSupervisor,
    ): string {
        $canonical = json_encode([
            'cycle_id' => $cycleId,
            'stopped' => $stopped,
            'stop_reason' => $stopReason,
            'replenish' => $replenish,
            'worker' => $worker,
            'verify_merge' => $verifyMerge,
            'learn' => $learn,
            'unattended_supervisor' => $unattendedSupervisor,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return 'cycle_receipt_'.substr(hash('sha256', (string) $canonical), 0, 32);
    }
}
