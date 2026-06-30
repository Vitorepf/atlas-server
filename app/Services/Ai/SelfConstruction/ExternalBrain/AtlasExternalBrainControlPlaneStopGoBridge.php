<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Bridges unified brain control-plane snapshot facts into one operator-free
 * stop/go decision. Translates snapshot signals into a single actionable verdict.
 *
 * Decision priority (first match):
 *   consolidate_or_self_heal — malformed_risk=true OR quality_trend='low' OR sprawl_pressure='high'
 *   drain_queue              — queue_pressure='high' AND quality_trend != 'high'
 *   escalate_ambition        — healthy + high value + maturity_gap_count>0
 *   create_more_tasks        — healthy + high value
 *   pause                    — default
 *
 * AC3: consolidate_or_self_heal always wins over create_more_tasks when
 *      quality_trend is low OR malformed_risk is true.
 * AC4: pure PHP, no side effects, no provider calls.
 */
final class AtlasExternalBrainControlPlaneStopGoBridge
{
    public const SCHEMA = 'atlas.external_brain.control_plane_stop_go_bridge.v1';

    public const DECISION_CONSOLIDATE_OR_SELF_HEAL = 'consolidate_or_self_heal';
    public const DECISION_DRAIN_QUEUE              = 'drain_queue';
    public const DECISION_ESCALATE_AMBITION        = 'escalate_ambition';
    public const DECISION_CREATE_MORE_TASKS        = 'create_more_tasks';
    public const DECISION_PAUSE                    = 'pause';

    /**
     * @param  array<string,mixed>  $input
     * @return array{schema:string, stop_go_decision:string, reasons:list<string>, next_action:string, provider_free:bool}
     */
    public function decide(array $input): array
    {
        $queuePressure   = (string) ($input['queue_pressure']   ?? 'low');
        $qualityTrend    = (string) ($input['quality_trend']    ?? 'medium');
        $sprawlPressure  = (string) ($input['sprawl_pressure']  ?? 'low');
        $malformedRisk   = (bool)   ($input['malformed_risk']   ?? false);
        $queueHealth     = (string) ($input['queue_health']     ?? 'healthy');
        $maturityGaps    = max(0,   (int) ($input['maturity_gap_count'] ?? 0));

        $isHealthy   = $queueHealth === 'healthy';
        $isHighValue = $qualityTrend === 'high';

        [$decision, $reasons] = $this->classify(
            $queuePressure, $qualityTrend, $sprawlPressure,
            $malformedRisk, $isHealthy, $isHighValue, $maturityGaps,
        );

        return [
            'schema'           => self::SCHEMA,
            'stop_go_decision' => $decision,
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
    ): array {
        // AC3 — consolidate/heal beats expansion when quality or safety is degraded
        $consolidateReasons = [];
        if ($malformedRisk) {
            $consolidateReasons[] = 'malformed_risk:true';
        }
        if ($qualityTrend === 'low') {
            $consolidateReasons[] = 'quality_trend:low';
        }
        if ($sprawlPressure === 'high') {
            $consolidateReasons[] = 'sprawl_pressure:high';
        }
        if ($consolidateReasons !== []) {
            return [self::DECISION_CONSOLIDATE_OR_SELF_HEAL, $consolidateReasons];
        }

        // High pressure but not high value → drain first
        if ($queuePressure === 'high' && ! $isHighValue) {
            return [self::DECISION_DRAIN_QUEUE, ['queue_pressure:high', "quality_trend:{$qualityTrend}"]];
        }

        // Healthy + high value → expand; pick escalate if gaps exist
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

    private function nextAction(string $decision): string
    {
        return match ($decision) {
            self::DECISION_CONSOLIDATE_OR_SELF_HEAL => 'run_organ_sprawl_reduction_and_repair_malformed_packets',
            self::DECISION_DRAIN_QUEUE              => 'assign_workers_to_drain_existing_queue_before_originating',
            self::DECISION_ESCALATE_AMBITION        => 'fill_maturity_gaps_with_higher_leverage_tasks',
            self::DECISION_CREATE_MORE_TASKS        => 'originate_next_batch_from_comprehension',
            default                                 => 'observe_and_wait_for_next_signal',
        };
    }
}
