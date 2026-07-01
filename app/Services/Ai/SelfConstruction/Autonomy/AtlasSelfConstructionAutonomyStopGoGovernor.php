<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Autonomy;

/**
 * Pure autonomy governor. Decides what the 24/7 self-construction loop should
 * do next based on queue health, value trend, sprawl pressure and risk signals.
 *
 * Decision priority (first match):
 *   self_heal_queue          — malformed_risk=true OR give_back_repeated=true
 *   replenish_with_guardrails — dry_queue=true OR queue_health='dry'
 *   consolidate_or_burn_debt  — value_trend='low' OR sprawl_pressure='high'
 *   call_more_muscles         — healthy + high value + worker_capacity_available=true
 *   create_high_value_tasks   — healthy + high value
 *   pause_origination         — default
 *
 * AC3: self_heal_queue and replenish_with_guardrails prevent blind task creation
 *      when the queue is unhealthy or supply is exhausted.
 * AC4: pure PHP, no I/O, no provider calls, emits decision receipt only.
 *
 * stop_go_decision / stop_go_rationale: a SECOND, simplified 5-value classification layered
 * alongside the existing 7-value `decision` (which stays untouched) — go/slow_down/pause/
 * self_heal/consolidate, driven by 6 NEW optional signals (all default to a healthy/nominal value,
 * so every input that never declares them classifies as 'go', preserving all pre-existing behavior):
 *   - malformed_pressure ('low'|'high', or the existing malformed_risk bool) → self_heal
 *   - worker_drain_signal ('low'|'high')                                    → slow_down
 *   - give_back_drag / simplification_debt ('low'|'high')                   → consolidate
 *   - task_quality ('high'|'medium'|'low') / context_freshness ('fresh'|'stale') → pause
 *   - nominal                                                                → go
 * Priority order matches the existing `decision` field's philosophy: heal first, then throttle
 * (drain), then structural cleanup (consolidate), then caution (pause), then go.
 */
final class AtlasSelfConstructionAutonomyStopGoGovernor
{
    public const SCHEMA = 'atlas.self_construction.autonomy_stop_go_governor.v1';

    public const DECISION_SELF_HEAL            = 'self_heal_queue';
    public const DECISION_REPLENISH            = 'replenish_with_guardrails';
    public const DECISION_CONSOLIDATE          = 'consolidate_or_burn_debt';
    public const DECISION_CALL_MUSCLES         = 'call_more_muscles';
    public const DECISION_CREATE_HIGH_VALUE    = 'create_high_value_tasks';
    public const DECISION_PAUSE                = 'pause_origination';
    public const DECISION_GO_REPAIR_QUEUE      = 'go_repair_queue';

    public const STOP_GO_GO           = 'go';
    public const STOP_GO_SLOW_DOWN    = 'slow_down';
    public const STOP_GO_PAUSE        = 'pause';
    public const STOP_GO_SELF_HEAL    = 'self_heal';
    public const STOP_GO_CONSOLIDATE  = 'consolidate';

    /** claimable_per_active_worker at/below this is a worker-feed floor breach. */
    public const WORKER_FLOOR_THRESHOLD = 2.0;

    /** Decisions that mean "advance autonomy" — vetoed when the worker feed is below floor. */
    private const GO_AUTONOMOUS_DECISIONS = [self::DECISION_CALL_MUSCLES, self::DECISION_CREATE_HIGH_VALUE];

    /**
     * @param  array<string,mixed>  $input
     * @return array{schema:string, decision:string, rationale:list<string>, next_review_signal:string, provider_free:bool}
     */
    public function decide(array $input): array
    {
        $queueHealth             = (string) ($input['queue_health']              ?? 'healthy');
        $valueTrend              = (string) ($input['value_trend']               ?? 'medium');
        $sprawlPressure          = (string) ($input['sprawl_pressure']           ?? 'low');
        $malformedRisk           = (bool)   ($input['malformed_risk']            ?? false);
        $giveBackRepeated        = (bool)   ($input['give_back_repeated']        ?? false);
        $dryQueue                = (bool)   ($input['dry_queue']                 ?? false) || $queueHealth === 'dry';
        $workerCapacityAvailable = (bool)   ($input['worker_capacity_available'] ?? false);
        $claimablePerActiveWorker = array_key_exists('claimable_per_active_worker', $input) && $input['claimable_per_active_worker'] !== null
            ? (float) $input['claimable_per_active_worker']
            : null;
        $lowWorkerFloor = $claimablePerActiveWorker !== null && $claimablePerActiveWorker <= self::WORKER_FLOOR_THRESHOLD;

        $isHealthy   = $queueHealth === 'healthy';
        $isHighValue = $valueTrend === 'high';

        [$decision, $rationale] = $this->classify(
            $malformedRisk, $giveBackRepeated, $dryQueue,
            $valueTrend, $sprawlPressure, $isHealthy, $isHighValue,
            $workerCapacityAvailable,
        );

        if ($lowWorkerFloor && in_array($decision, self::GO_AUTONOMOUS_DECISIONS, true)) {
            $decision = self::DECISION_GO_REPAIR_QUEUE;
            $rationale = ['worker_feed_below_floor'];
        }

        [$stopGoDecision, $stopGoRationale] = $this->classifyStopGo($input, $malformedRisk);

        return [
            'schema'              => self::SCHEMA,
            'decision'            => $decision,
            'rationale'           => array_values($rationale),
            'next_review_signal'  => $this->nextSignal($decision),
            'provider_free'       => true,
            'stop_go_decision'    => $stopGoDecision,
            'stop_go_rationale'   => $stopGoRationale,
        ];
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array{string, list<string>}
     */
    private function classifyStopGo(array $input, bool $malformedRisk): array
    {
        $malformedPressure = (string) ($input['malformed_pressure'] ?? 'low');
        $workerDrainSignal = (string) ($input['worker_drain_signal'] ?? 'low');
        $giveBackDrag = (string) ($input['give_back_drag'] ?? 'low');
        $simplificationDebt = (string) ($input['simplification_debt'] ?? 'low');
        $taskQuality = (string) ($input['task_quality'] ?? 'medium');
        $contextFreshness = (string) ($input['context_freshness'] ?? 'fresh');

        if ($malformedRisk || $malformedPressure === 'high') {
            return [self::STOP_GO_SELF_HEAL, ['malformed:'.($malformedRisk ? 'true' : $malformedPressure)]];
        }
        if ($workerDrainSignal === 'high') {
            return [self::STOP_GO_SLOW_DOWN, ['drain:high']];
        }
        if ($giveBackDrag === 'high' || $simplificationDebt === 'high') {
            $reasons = [];
            if ($giveBackDrag === 'high') {
                $reasons[] = 'give_back:high';
            }
            if ($simplificationDebt === 'high') {
                $reasons[] = 'debt:high';
            }

            return [self::STOP_GO_CONSOLIDATE, $reasons];
        }
        if ($taskQuality === 'low' || $contextFreshness === 'stale') {
            $reasons = [];
            if ($taskQuality === 'low') {
                $reasons[] = 'quality:low';
            }
            if ($contextFreshness === 'stale') {
                $reasons[] = 'freshness:stale';
            }

            return [self::STOP_GO_PAUSE, $reasons];
        }

        return [self::STOP_GO_GO, ['all_signals_nominal']];
    }

    /** @return array{string, list<string>} */
    private function classify(
        bool   $malformedRisk,
        bool   $giveBackRepeated,
        bool   $dryQueue,
        string $valueTrend,
        string $sprawlPressure,
        bool   $isHealthy,
        bool   $isHighValue,
        bool   $workerCapacityAvailable,
    ): array {
        // AC3 — queue injury signals must heal before new work
        if ($malformedRisk || $giveBackRepeated) {
            $reasons = [];
            if ($malformedRisk) {
                $reasons[] = 'malformed_risk:true';
            }
            if ($giveBackRepeated) {
                $reasons[] = 'give_back_repeated:true';
            }
            return [self::DECISION_SELF_HEAL, $reasons];
        }

        // AC3 — dry queue needs supply before creation
        if ($dryQueue) {
            return [self::DECISION_REPLENISH, ['queue_supply_exhausted']];
        }

        // AC2 — low value or sprawl drives consolidation
        if ($valueTrend === 'low' || $sprawlPressure === 'high') {
            $reasons = [];
            if ($valueTrend === 'low') {
                $reasons[] = 'value_trend:low';
            }
            if ($sprawlPressure === 'high') {
                $reasons[] = 'sprawl_pressure:high';
            }
            return [self::DECISION_CONSOLIDATE, $reasons];
        }

        // AC2 — healthy + high value → expand
        if ($isHealthy && $isHighValue) {
            if ($workerCapacityAvailable) {
                return [self::DECISION_CALL_MUSCLES, ['queue_health:healthy', 'value_trend:high', 'worker_capacity_available:true']];
            }
            return [self::DECISION_CREATE_HIGH_VALUE, ['queue_health:healthy', 'value_trend:high']];
        }

        // Default — monitor
        return [self::DECISION_PAUSE, ['no_expansion_signal_detected']];
    }

    private function nextSignal(string $decision): string
    {
        return match ($decision) {
            self::DECISION_SELF_HEAL         => 'monitor:give_back_rate_and_malformed_packet_count',
            self::DECISION_REPLENISH         => 'monitor:queue_depth_and_origination_dry_probe',
            self::DECISION_CONSOLIDATE       => 'monitor:sprawl_pressure_and_value_trend_recovery',
            self::DECISION_CALL_MUSCLES      => 'monitor:worker_throughput_and_outcome_quality',
            self::DECISION_CREATE_HIGH_VALUE => 'monitor:task_value_trend_and_queue_depth',
            self::DECISION_GO_REPAIR_QUEUE   => 'monitor:claimable_per_active_worker_recovery',
            default                          => 'monitor:queue_health_and_value_signal',
        };
    }
}
