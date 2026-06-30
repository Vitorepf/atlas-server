<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * 24/7 autonomy stop/go gate for brain-originator runs.
 *
 * Verdicts (first-match):
 *   repair_first      — gate regression detected; fix before anything else
 *   honest_stop       — quality target reached, exhausted, or surface saturated (all with evidence)
 *   drain_first       — queue pressure is high + task is non-urgent
 *   consolidate_first — consolidation pressure is high
 *   continue_search   — remaining modes/surfaces/first_pass_only guard
 *   escalate_ambition — evidence exists but quality not met and nothing left to try
 *   premature_stop    — no evidence at all
 *
 * AC1: refuses premature stop when remaining_escalation_modes > 0,
 *      open_surfaces_remaining > 0, first_pass_only=true, or evidence missing.
 * AC2: always includes blocking_reasons and evidence_cited.
 *
 * LIVE SIGNAL NORMALIZATION: accepts an optional `next_action` (Maestro/queue-policy vocabulary —
 * wait, monitor, drain_existing_queue, consolidate_existing_tasks, self_heal_queue,
 * create_high_leverage_batch, or the longer real bridge variants like
 * `self_heal_queue_before_creating`/`observe_and_wait`) and normalizes it into the SAME boolean facts
 * the verdict ladder already reads (gate_regression_detected, queue_pressure_high,
 * consolidation_pressure_high). The normalization is PURELY ADDITIVE (OR-merge) — it can only turn a
 * fact ON, never weaken an explicit caller-supplied true into false, and `create_high_leverage_batch`
 * sets NO override at all (it must still pass through the existing quality/evidence/surface rules,
 * never forcing an evidence-free honest_stop).
 */
final class AtlasExternalBrainOriginatorStopConditionGate
{
    public const SCHEMA = 'atlas.external_brain.originator_stop_condition_gate.v1';

    public const VERDICT_HONEST_STOP       = 'honest_stop';
    public const VERDICT_CONTINUE_SEARCH   = 'continue_search';
    public const VERDICT_DRAIN_FIRST       = 'drain_first';
    public const VERDICT_CONSOLIDATE_FIRST = 'consolidate_first';
    public const VERDICT_REPAIR_FIRST      = 'repair_first';
    public const VERDICT_ESCALATE_AMBITION = 'escalate_ambition';
    public const VERDICT_PREMATURE_STOP    = 'premature_stop';
    public const VERDICT_REDUCE_SCOPE      = 'reduce_scope';

    public const REASON_QUALITY_TARGET          = 'quality_target_reached';
    public const REASON_EXHAUSTED               = 'exhausted_with_evidence';
    public const REASON_QUEUE_PRESSURE          = 'queue_pressure_deferral';
    public const REASON_SURFACE_SATURATED       = 'surface_saturated';
    public const REASON_HIGH_SATURATION_LOW_YIELD = 'high_saturation_low_value_yield';

    /** Thresholds for yield-aware and saturation-aware decisions. */
    private const DEFAULT_MIN_VALUE_YIELD           = 0.40; // below → yield problem
    private const DEFAULT_SATURATION_STOP_THRESHOLD = 0.80; // at/above → stop new seeds
    private const DEFAULT_MAX_GIVE_BACK_RATE        = 0.30; // above → scope concern

    public function evaluate(array $input): array
    {
        $input = $this->mergeLiveSignal($input);

        $qualityReached        = (bool)  ($input['quality_target_reached']            ?? false);
        $qualityEvidence       = (array) ($input['quality_target_evidence']            ?? []);
        $allEscalationTried    = (bool)  ($input['all_escalation_modes_tried']         ?? false);
        $escalationEvidence    = (array) ($input['escalation_evidence']                ?? []);
        $queueHigh             = (bool)  ($input['queue_pressure_high']                ?? false);
        $urgency               = (string)($input['task_urgency']                       ?? 'non_urgent');
        $noSurface             = (bool)  ($input['no_high_leverage_surface_remaining'] ?? false);
        $dedupConfirmed        = (bool)  ($input['deduplication_confirmed']             ?? false);
        $firstPassOnly         = (bool)  ($input['first_pass_only']                    ?? false);
        $openSurfaces          = max(0,  (int)($input['open_surfaces_remaining']        ?? 0));
        $remainingModes        = max(0,  (int)($input['remaining_escalation_modes']     ?? 0));
        $gateRegression        = (bool)  ($input['gate_regression_detected']            ?? false);
        $consolidationPressure = (bool)  ($input['consolidation_pressure_high']         ?? false);

        // Yield-aware / saturation-aware inputs.
        $valueYield        = max(0.0, min(1.0, (float) ($input['value_yield_score']       ?? 1.0)));
        $saturationLevel   = max(0.0, min(1.0, (float) ($input['saturation_level']        ?? 0.0)));
        $dupPressureHigh   = (bool)  ($input['duplicate_pressure_high'] ?? false);
        $giveBackRate      = max(0.0, min(1.0, (float) ($input['give_back_rate']          ?? 0.0)));
        $minYield          = (float) ($input['min_value_yield']              ?? self::DEFAULT_MIN_VALUE_YIELD);
        $satStopThresh     = (float) ($input['saturation_stop_threshold']    ?? self::DEFAULT_SATURATION_STOP_THRESHOLD);

        // 1. repair_first — most urgent
        if ($gateRegression) {
            return $this->result(self::VERDICT_REPAIR_FIRST, null,
                ['gate_regression_detected'],
                ['gate_regression_detected:true'],
            );
        }

        // 2. honest_stop — quality target reached with evidence
        if ($qualityReached && $qualityEvidence !== []) {
            return $this->result(self::VERDICT_HONEST_STOP, self::REASON_QUALITY_TARGET,
                [], $qualityEvidence,
            );
        }

        // 3. honest_stop — exhausted all modes with evidence
        if ($allEscalationTried && $escalationEvidence !== []) {
            return $this->result(self::VERDICT_HONEST_STOP, self::REASON_EXHAUSTED,
                [], $escalationEvidence,
            );
        }

        // 4. honest_stop — surface saturated with dedup proof
        if ($noSurface && $dedupConfirmed) {
            return $this->result(self::VERDICT_HONEST_STOP, self::REASON_SURFACE_SATURATED,
                [], ['no_high_leverage_surface_remaining', 'deduplication_confirmed'],
            );
        }

        // 5. drain_first — queue pressure blocks new origination
        if ($queueHigh && $urgency === 'non_urgent') {
            return $this->result(self::VERDICT_DRAIN_FIRST, null,
                ['queue_pressure_high:drain_before_new_origination'],
                ['queue_pressure_high', 'task_urgency:non_urgent'],
            );
        }

        // 5a. stop new seeds — high saturation + low value yield
        if ($saturationLevel >= $satStopThresh && $valueYield < $minYield) {
            $sat = round($saturationLevel, 4);
            $val = round($valueYield,      4);
            $gbr = round($giveBackRate,    4);
            return $this->result(self::VERDICT_HONEST_STOP, self::REASON_HIGH_SATURATION_LOW_YIELD,
                ["saturation_level:{$sat}:>=:threshold:{$satStopThresh}", "value_yield_score:{$val}:<:min:{$minYield}"],
                ["saturation_level:{$sat}", "value_yield_score:{$val}", "give_back_rate:{$gbr}"],
            );
        }

        // 6. consolidate_first — trim before adding more
        if ($consolidationPressure) {
            return $this->result(self::VERDICT_CONSOLIDATE_FIRST, null,
                ['consolidation_pressure_high:merge_before_origination'],
                ['consolidation_pressure_high:true'],
            );
        }

        // 6a. reduce_scope — duplicate pressure + low value yield
        if ($dupPressureHigh && $valueYield < $minYield) {
            $val = round($valueYield,   4);
            $gbr = round($giveBackRate, 4);
            return $this->result(self::VERDICT_REDUCE_SCOPE, null,
                ["duplicate_pressure_high:true", "value_yield_score:{$val}:<:min:{$minYield}"],
                ["duplicate_pressure_high:true", "value_yield_score:{$val}", "give_back_rate:{$gbr}"],
            );
        }

        // 7. continue_search — modes or surfaces still available
        if ($remainingModes > 0 || $openSurfaces > 0 || $firstPassOnly) {
            $blocking = [];
            if ($remainingModes > 0) {
                $blocking[] = "remaining_escalation_modes:{$remainingModes}";
            }
            if ($openSurfaces > 0) {
                $blocking[] = "open_surfaces_remaining:{$openSurfaces}";
            }
            if ($firstPassOnly) {
                $blocking[] = 'first_pass_only:insufficient_exploration';
            }
            return $this->result(self::VERDICT_CONTINUE_SEARCH, null, $blocking, []);
        }

        // 8. escalate_ambition — evidence exists but quality not met, nothing left to try
        $allEvidence = array_merge($qualityEvidence, $escalationEvidence);
        if ($allEvidence !== []) {
            return $this->result(self::VERDICT_ESCALATE_AMBITION, null,
                ['quality_target_not_met', 'no_remaining_modes_or_surfaces'],
                $allEvidence,
            );
        }

        // 9. premature_stop — no evidence at all
        return $this->result(self::VERDICT_PREMATURE_STOP, null,
            ['no_honest_stop_condition_met', 'no_evidence_cited'],
            [],
        );
    }

    /**
     * Normalizes an optional `next_action` live signal into the existing boolean facts. Additive
     * OR-merge only — never turns an explicit caller-supplied true into false, and
     * create_high_leverage_batch sets no override (must still earn its verdict honestly).
     */
    private function mergeLiveSignal(array $input): array
    {
        $nextAction = (string) ($input['next_action'] ?? '');
        if ($nextAction === '') {
            return $input;
        }

        if (str_contains($nextAction, 'self_heal_queue')) {
            $input['gate_regression_detected'] = true;
        } elseif (str_contains($nextAction, 'drain_existing_queue') || $nextAction === 'wait') {
            $input['queue_pressure_high'] = true;
        } elseif (str_contains($nextAction, 'consolidate_existing_tasks')) {
            $input['consolidation_pressure_high'] = true;
        }
        // 'monitor' and 'create_high_leverage_batch' (and 'observe_and_wait') set no override —
        // they must pass through the existing quality/evidence/surface rules honestly.

        return $input;
    }

    private function result(string $verdict, ?string $stopReason, array $blocking, array $evidence): array
    {
        return [
            'schema'           => self::SCHEMA,
            'verdict'          => $verdict,
            'stop_reason'      => $stopReason,
            'blocking_reasons' => $blocking,
            'evidence_cited'   => $evidence,
        ];
    }
}
