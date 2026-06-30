<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Bridges unified brain control-plane snapshot facts into one operator-free
 * stop/go decision. Translates snapshot signals into a single actionable verdict.
 *
 * Decision priority (first match wins):
 *   self_heal_queue      — malformed_risk=true OR queue_health≠healthy OR give_back_pressure=high
 *   run_consolidation    — quality_trend=low OR sprawl_pressure=high
 *   drain_existing_queue — saturation guard: claimable_depth=high AND value_density=falling
 *                         (overrides create/escalate even when quota_pressure is high)
 *   drain_existing_queue — queue_pressure=high AND quality_trend≠high
 *   escalate_ambition    — healthy + quality_trend=high + maturity_gap_count>0
 *   create_more_tasks    — healthy + quality_trend=high
 *   pause                — default
 *
 * Pure: no I/O, no provider calls, no side effects.
 */
final class AtlasExternalBrainControlPlaneStopGoBridge
{
    public const SCHEMA = 'atlas.external_brain.control_plane_stop_go_bridge.v1';

    public const DECISION_SELF_HEAL_QUEUE      = 'self_heal_queue';
    public const DECISION_RUN_CONSOLIDATION    = 'run_consolidation';
    public const DECISION_DRAIN_EXISTING_QUEUE = 'drain_existing_queue';
    public const DECISION_ESCALATE_AMBITION    = 'escalate_ambition';
    public const DECISION_CREATE_MORE_TASKS    = 'create_more_tasks';
    public const DECISION_PAUSE                = 'pause';

    /**
     * @param  array<string,mixed>  $input
     * @return array{schema:string, stop_go_decision:string, reasons:list<string>, next_action:string, provider_free:bool}
     */
    public function decide(array $input): array
    {
        $queuePressure    = (string) ($input['queue_pressure']     ?? 'low');
        $qualityTrend     = (string) ($input['quality_trend']      ?? 'medium');
        $sprawlPressure   = (string) ($input['sprawl_pressure']    ?? 'low');
        $malformedRisk    = (bool)   ($input['malformed_risk']     ?? false);
        $queueHealth      = (string) ($input['queue_health']       ?? 'healthy');
        $maturityGaps     = max(0,   (int) ($input['maturity_gap_count']  ?? 0));
        $claimableDepth   = (string) ($input['claimable_depth']    ?? 'low');
        $valueDensity     = (string) ($input['value_density']      ?? 'stable');
        $giveBackPressure = (string) ($input['give_back_pressure'] ?? 'low');

        $isHealthy   = $queueHealth === 'healthy';
        $isHighValue = $qualityTrend === 'high';

        [$decision, $reasons] = $this->classify(
            $queuePressure, $qualityTrend, $sprawlPressure,
            $malformedRisk, $isHealthy, $isHighValue, $maturityGaps,
            $claimableDepth, $valueDensity, $giveBackPressure, $queueHealth,
        );

        return [
            'schema'           => self::SCHEMA,
            'stop_go_decision' => $decision,
            'stop_go_signal'   => $this->stopGoSignal($decision),
            'reasons'          => array_values($reasons),
            'next_action'      => $this->nextAction($decision),
            'provider_free'    => true,
        ];
    }

    /** @return array{string, list<string>} */
    private function classify(
        string $queuePressure,
        string $qualityTrend,
        string $sprawlPressure,
        bool   $malformedRisk,
        bool   $isHealthy,
        bool   $isHighValue,
        int    $maturityGaps,
        string $claimableDepth,
        string $valueDensity,
        string $giveBackPressure,
        string $queueHealth,
    ): array {
        // 1. SELF_HEAL_QUEUE — safety net first
        $healReasons = [];
        if ($malformedRisk) {
            $healReasons[] = 'malformed_risk:true';
        }
        if (! $isHealthy) {
            $healReasons[] = "queue_health:{$queueHealth}";
        }
        if ($giveBackPressure === 'high') {
            $healReasons[] = 'give_back_pressure:high';
        }
        if ($healReasons !== []) {
            return [self::DECISION_SELF_HEAL_QUEUE, $healReasons];
        }

        // 2. RUN_CONSOLIDATION — quality or sprawl degradation
        $consolidateReasons = [];
        if ($qualityTrend === 'low') {
            $consolidateReasons[] = 'quality_trend:low';
        }
        if ($sprawlPressure === 'high') {
            $consolidateReasons[] = 'sprawl_pressure:high';
        }
        if ($consolidateReasons !== []) {
            return [self::DECISION_RUN_CONSOLIDATION, $consolidateReasons];
        }

        // 3. Saturation guard — claimable_depth=high AND value_density=falling
        //    Blocks task creation even when quota_pressure demands more
        if ($claimableDepth === 'high' && $valueDensity === 'falling') {
            return [self::DECISION_DRAIN_EXISTING_QUEUE, [
                'claimable_depth:high',
                'value_density:falling',
                'saturation_guard:blocks_creation',
            ]];
        }

        // 4. DRAIN_EXISTING_QUEUE — high queue pressure with non-high quality
        if ($queuePressure === 'high' && ! $isHighValue) {
            return [self::DECISION_DRAIN_EXISTING_QUEUE, [
                'queue_pressure:high',
                "quality_trend:{$qualityTrend}",
            ]];
        }

        // 5. ESCALATE / CREATE — healthy + high value
        if ($isHealthy && $isHighValue) {
            if ($maturityGaps > 0) {
                return [self::DECISION_ESCALATE_AMBITION, [
                    'queue_health:healthy',
                    'quality_trend:high',
                    "maturity_gap_count:{$maturityGaps}",
                ]];
            }
            return [self::DECISION_CREATE_MORE_TASKS, ['queue_health:healthy', 'quality_trend:high']];
        }

        return [self::DECISION_PAUSE, ['no_expansion_signal_detected']];
    }

    private function stopGoSignal(string $decision): string
    {
        return match ($decision) {
            self::DECISION_SELF_HEAL_QUEUE,
            self::DECISION_RUN_CONSOLIDATION    => 'stop',
            self::DECISION_DRAIN_EXISTING_QUEUE => 'watch',
            self::DECISION_ESCALATE_AMBITION,
            self::DECISION_CREATE_MORE_TASKS    => 'go',
            default                             => 'hold',
        };
    }

    private function nextAction(string $decision): string
    {
        return match ($decision) {
            self::DECISION_SELF_HEAL_QUEUE      => 'self_heal_queue_before_creating',
            self::DECISION_RUN_CONSOLIDATION    => 'consolidate_existing_tasks',
            self::DECISION_DRAIN_EXISTING_QUEUE => 'consolidate_existing_tasks',
            self::DECISION_ESCALATE_AMBITION,
            self::DECISION_CREATE_MORE_TASKS    => 'create_high_leverage_batch',
            default                             => 'observe_and_wait',
        };
    }
}
