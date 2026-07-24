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
 *   escalate_ambition / create_more_tasks — NO DUMB PAUSE: unexplored_surface_count>0,
 *                         high_value_candidate_count>0, or exhausted_surface_proof≠true
 *   pause                — only when exhausted_surface_proof=true and no surface/candidates remain
 *
 * UPSTREAM POLICY INTEROP: accepts an optional `upstream_decision` (vocabulary from queue saturation /
 * backlog cost / originator stop policies — consolidate_or_audit, pause_creation_and_consolidate, drain,
 * unblock, self_heal_before_more_volume, monitor) and normalizes it into this bridge's own facts:
 *   self_heal_before_more_volume, unblock → forces self_heal_queue (beats create/escalate, tier 1)
 *   consolidate_or_audit, pause_creation_and_consolidate → forces run_consolidation, even when
 *     quality_trend is high
 *   drain → forces drain_existing_queue, even when quality_trend is high
 *   monitor → no override; healthy + high-quality facts can still escalate/create normally
 *
 * Pure: no I/O, no provider calls, no side effects.
 */
final class AtlasExternalBrainControlPlaneStopGoBridge
{
    public const SCHEMA = 'atlas.external_brain.control_plane_stop_go_bridge.v1';

    public const DECISION_SELF_HEAL_QUEUE      = 'self_heal_queue';
    public const DECISION_REPAIR_REGRESSION    = 'repair_regression';
    public const DECISION_HOLD_FOR_EVIDENCE    = 'hold_for_evidence';
    public const DECISION_RUN_CONSOLIDATION    = 'run_consolidation';
    public const DECISION_DRAIN_EXISTING_QUEUE = 'drain_existing_queue';
    public const DECISION_ESCALATE_AMBITION    = 'escalate_ambition';
    public const DECISION_CREATE_MORE_TASKS    = 'create_more_tasks';
    public const DECISION_PAUSE                = 'pause';

    /** integration_coverage_percent below this reads as weak — mirrors the unified snapshot's floor. */
    private const WEAK_INTEGRATION_COVERAGE_FLOOR = 50.0;

    /**
     * @param  array<string,mixed>  $input
     * @return array{schema:string, stop_go_decision:string, reasons:list<string>, next_action:string, provider_free:bool}
     */
    public function decide(array $input): array
    {
        $input = $this->mergeUpstreamDecision($input);

        $queuePressure    = (string) ($input['queue_pressure']     ?? 'low');
        $qualityTrend     = (string) ($input['quality_trend']      ?? 'medium');
        $sprawlPressure   = (string) ($input['sprawl_pressure']    ?? 'low');
        $malformedRisk    = (bool)   ($input['malformed_risk']     ?? false);
        $queueHealth      = (string) ($input['queue_health']       ?? 'healthy');
        $maturityGaps     = max(0,   (int) ($input['maturity_gap_count']  ?? 0));
        $claimableDepth   = (string) ($input['claimable_depth']    ?? 'low');
        $valueDensity     = (string) ($input['value_density']      ?? 'stable');
        $giveBackPressure = (string) ($input['give_back_pressure'] ?? 'low');
        $forceConsolidate = (bool)   ($input['force_consolidation'] ?? false);
        $forceDrain       = (bool)   ($input['force_drain']         ?? false);
        $regressionDetected = (bool) ($input['regression_detected']  ?? false);
        $lowValueBacklog  = (bool)   ($input['low_value_backlog']   ?? false);
        $leaseLeak        = (bool)   ($input['lease_leak']          ?? false);

        $finalReadinessPercent    = max(0.0, min(100.0, (float) ($input['final_readiness_percent']    ?? 100.0)));
        $integrationCoverage      = max(0.0, min(100.0, (float) ($input['integration_coverage_percent'] ?? 100.0)));
        $evidenceFreshnessStatus  = (string) ($input['evidence_freshness_status'] ?? 'fresh');
        $final95GapCount          = max(0, (int) ($input['final95_gap_count'] ?? 0));

        $unexploredSurfaceCount   = max(0, (int) ($input['unexplored_surface_count'] ?? 0));
        $highValueCandidateCount = max(0, (int) ($input['high_value_candidate_count'] ?? 0));
        $exhaustedSurfaceProof    = (bool) ($input['exhausted_surface_proof'] ?? false);

        $isHealthy   = $queueHealth === 'healthy';
        $isHighValue = $qualityTrend === 'high';

        [$decision, $reasons] = $this->classify(
            $queuePressure, $qualityTrend, $sprawlPressure,
            $malformedRisk, $isHealthy, $isHighValue, $maturityGaps,
            $claimableDepth, $valueDensity, $giveBackPressure, $queueHealth,
            $forceConsolidate, $forceDrain,
            $evidenceFreshnessStatus, $integrationCoverage, $final95GapCount,
            $unexploredSurfaceCount, $highValueCandidateCount, $exhaustedSurfaceProof,
            $regressionDetected, $lowValueBacklog, $leaseLeak,
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
        bool   $forceConsolidate,
        bool   $forceDrain,
        string $evidenceFreshnessStatus,
        float  $integrationCoverage,
        int    $final95GapCount,
        int    $unexploredSurfaceCount,
        int    $highValueCandidateCount,
        bool   $exhaustedSurfaceProof,
        bool   $regressionDetected,
        bool   $lowValueBacklog,
        bool   $leaseLeak,
    ): array {
        // 0. REPAIR_REGRESSION — regressions are fixed before anything else
        if ($regressionDetected) {
            return [self::DECISION_REPAIR_REGRESSION, ['regression_detected:true']];
        }

        // 0a. LEASE_LEAK — treat like malformed risk (self-heal)
        if ($leaseLeak) {
            return [self::DECISION_SELF_HEAL_QUEUE, ['lease_leak:true']];
        }

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

        // 1a. Upstream-forced consolidation/drain — beats quality_trend=high (overrides escalate/create).
        if ($forceConsolidate) {
            return [self::DECISION_RUN_CONSOLIDATION, ['upstream_decision:consolidate_or_pause']];
        }
        if ($forceDrain) {
            return [self::DECISION_DRAIN_EXISTING_QUEUE, ['upstream_decision:drain']];
        }

        // 1b. HOLD_FOR_EVIDENCE — stale evidence or low integration coverage
        if ($evidenceFreshnessStatus === 'stale') {
            return [self::DECISION_HOLD_FOR_EVIDENCE, ['evidence_freshness_status:stale']];
        }
        if ($integrationCoverage < self::WEAK_INTEGRATION_COVERAGE_FLOOR) {
            return [self::DECISION_HOLD_FOR_EVIDENCE, [sprintf('integration_coverage_percent:%.2f<%.2f', $integrationCoverage, self::WEAK_INTEGRATION_COVERAGE_FLOOR)]];
        }

        // 1c. LOW_VALUE_BACKLOG — drain when backlog is low-value
        if ($lowValueBacklog) {
            return [self::DECISION_DRAIN_EXISTING_QUEUE, ['low_value_backlog:true']];
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
            if ($final95GapCount > 0) {
                return [self::DECISION_ESCALATE_AMBITION, [
                    'queue_health:healthy',
                    'quality_trend:high',
                    "final95_gap_count:{$final95GapCount}",
                ]];
            }
            return [self::DECISION_CREATE_MORE_TASKS, ['queue_health:healthy', 'quality_trend:high']];
        }

        // 6. NO DUMB PAUSE — pause is only allowed with explicit exhausted-surface proof.
        //    Any unexplored surface, remaining high-value candidate, or absence of proof
        //    forbids pausing; escalate when a high-value candidate remains, else keep creating.
        $noDumbPauseReasons = [];
        if ($unexploredSurfaceCount > 0) {
            $noDumbPauseReasons[] = "unexplored_surface_count:{$unexploredSurfaceCount}";
        }
        if ($highValueCandidateCount > 0) {
            $noDumbPauseReasons[] = "high_value_candidate_count:{$highValueCandidateCount}";
        }
        if (! $exhaustedSurfaceProof) {
            $noDumbPauseReasons[] = 'exhausted_surface_proof:false';
        }
        if ($noDumbPauseReasons !== []) {
            $noDumbPauseReasons[] = 'no_dumb_pause';

            return [
                $highValueCandidateCount > 0 ? self::DECISION_ESCALATE_AMBITION : self::DECISION_CREATE_MORE_TASKS,
                $noDumbPauseReasons,
            ];
        }

        return [self::DECISION_PAUSE, ['exhausted_surface_proof:true', 'no_expansion_signal_detected']];
    }

    /**
     * Normalizes an optional `upstream_decision` (queue saturation / backlog cost / originator stop
     * policy vocabulary) into this bridge's own facts. Additive only — never weakens an explicit
     * caller-supplied fact; 'monitor' sets no override (passthrough).
     */
    private function mergeUpstreamDecision(array $input): array
    {
        $upstream = (string) ($input['upstream_decision'] ?? '');
        if ($upstream === '') {
            return $input;
        }

        if (str_contains($upstream, 'self_heal') || $upstream === 'unblock') {
            $input['malformed_risk'] = true;
        } elseif (str_contains($upstream, 'consolidate') || str_contains($upstream, 'pause')) {
            $input['force_consolidation'] = true;
        } elseif ($upstream === 'drain') {
            $input['force_drain'] = true;
        }
        // 'monitor' sets no override — healthy + high-quality facts can still escalate/create.

        return $input;
    }

    private function stopGoSignal(string $decision): string
    {
        return match ($decision) {
            self::DECISION_SELF_HEAL_QUEUE,
            self::DECISION_REPAIR_REGRESSION,
            self::DECISION_HOLD_FOR_EVIDENCE,
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
            self::DECISION_REPAIR_REGRESSION    => 'repair_regression_before_proceeding',
            self::DECISION_HOLD_FOR_EVIDENCE    => 'hold_for_fresh_evidence_before_go',
            self::DECISION_RUN_CONSOLIDATION    => 'consolidate_existing_tasks',
            self::DECISION_DRAIN_EXISTING_QUEUE => 'consolidate_existing_tasks',
            self::DECISION_ESCALATE_AMBITION,
            self::DECISION_CREATE_MORE_TASKS    => 'create_high_leverage_batch',
            default                             => 'observe_and_wait',
        };
    }
}
// P4-AUTONOMOS-REAL-OK-20260724215752
