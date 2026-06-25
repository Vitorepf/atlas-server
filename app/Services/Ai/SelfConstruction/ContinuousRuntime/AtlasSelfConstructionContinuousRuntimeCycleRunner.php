<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ContinuousRuntime;

/**
 * Bounded continuous Self-Construction cycle runner.
 *
 * Executes ONE cycle over injected collaborators (duck-typed):
 *   - healthInspector   ->inspect():     {queue_health:{...}, safety_stop:bool, claimable_packet:?array}
 *   - replenisher       ->replenish($facts): array (native replenisher integration verdict)
 *   - workerIntegration ->integrate($packet):  {accepted:bool, request:?array, blockers:list}
 *   - verifier          ->verify($request):    {verified:bool, reasons:list}
 *   - mergeDecider      ->decide($verification): {decision:string, ...}
 *   - learner           ->record($facts):       {learning:array, ...}
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
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function run(string $cycleId): array
    {
        $health = (array) $this->healthInspector->inspect();

        if ((bool) ($health['safety_stop'] ?? false)) {
            return $this->stop($cycleId, self::STOP_SAFETY, [
                'health' => $health,
                'safety_reasons' => array_values((array) ($health['safety_reasons'] ?? [])),
            ]);
        }

        $queueHealth = is_array($health['queue_health'] ?? null) ? $health['queue_health'] : [];

        if (((int) ($queueHealth['malformed_count'] ?? 0)) > 0) {
            $repl = (array) $this->replenisher->replenish($health);

            return $this->stop($cycleId, self::STOP_REPAIR_FIRST, [
                'health' => $health,
                'replenisher' => $repl,
            ]);
        }

        $packet = is_array($health['claimable_packet'] ?? null) ? $health['claimable_packet'] : null;

        if ($packet === null) {
            $repl = (array) $this->replenisher->replenish($health);

            return $this->stop($cycleId, self::STOP_NO_CLAIMABLE, [
                'health' => $health,
                'replenisher' => $repl,
            ]);
        }

        $workerVerdict = (array) $this->workerIntegration->integrate($packet);
        if (! (bool) ($workerVerdict['accepted'] ?? false)) {
            return $this->stop($cycleId, self::STOP_WORKER_REJECTED, [
                'health' => $health,
                'worker_integration' => $workerVerdict,
            ]);
        }

        $request = is_array($workerVerdict['request'] ?? null) ? $workerVerdict['request'] : [];
        $verification = (array) $this->verifier->verify($request);
        if (! (bool) ($verification['verified'] ?? false)) {
            return $this->stop($cycleId, self::STOP_VERIFICATION_FAILED, [
                'health' => $health,
                'worker_integration' => $workerVerdict,
                'verification' => $verification,
            ]);
        }

        $merge = (array) $this->mergeDecider->decide($verification);
        $learning = (array) $this->learner->record([
            'cycle_id' => $cycleId,
            'verification' => $verification,
            'merge' => $merge,
        ]);

        return [
            'schema_version' => self::SCHEMA,
            'cycle_id' => $cycleId,
            'stopped' => false,
            'stop_reason' => null,
            'health' => $health,
            'worker_integration' => $workerVerdict,
            'verification' => $verification,
            'merge' => $merge,
            'learning' => $learning,
        ];
    }

    /**
     * @param  array<string,mixed>  $extra
     * @return array<string,mixed>
     */
    private function stop(string $cycleId, string $reason, array $extra): array
    {
        return [
            'schema_version' => self::SCHEMA,
            'cycle_id' => $cycleId,
            'stopped' => true,
            'stop_reason' => $reason,
        ] + $extra;
    }
}
