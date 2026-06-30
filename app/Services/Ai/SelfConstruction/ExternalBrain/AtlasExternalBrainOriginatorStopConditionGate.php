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

    public const REASON_QUALITY_TARGET    = 'quality_target_reached';
    public const REASON_EXHAUSTED         = 'exhausted_with_evidence';
    public const REASON_QUEUE_PRESSURE    = 'queue_pressure_deferral';
    public const REASON_SURFACE_SATURATED = 'surface_saturated';

    public function evaluate(array $input): array
    {
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

        // 6. consolidate_first — trim before adding more
        if ($consolidationPressure) {
            return $this->result(self::VERDICT_CONSOLIDATE_FIRST, null,
                ['consolidation_pressure_high:merge_before_origination'],
                ['consolidation_pressure_high:true'],
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
