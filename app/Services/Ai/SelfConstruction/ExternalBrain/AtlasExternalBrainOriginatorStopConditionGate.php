<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure stop-condition gate for brain-originator runs. Determines whether a
 * stop is honest (evidence-backed) or premature (cutting short too early).
 *
 * Honest stop conditions (first match wins):
 *   quality_target_reached   — target reached with non-empty quality evidence
 *   exhausted_with_evidence  — all escalation modes tried with non-empty evidence
 *   queue_pressure_deferral  — queue pressure is high AND task is non-urgent
 *   surface_saturated        — no non-duplicate high-leverage surface remains,
 *                              confirmed by deduplication
 *
 * Premature stop if any of these hold and no honest condition is met:
 *   - remaining_escalation_modes > 0
 *   - open_surfaces_remaining > 0
 *   - first_pass_only = true
 *
 * AC1: premature_stop when escalation modes or open surfaces remain.
 * AC2: honest_stop only with evidence.
 *
 * Pure: no I/O, no side effects.
 */
final class AtlasExternalBrainOriginatorStopConditionGate
{
    public const SCHEMA = 'atlas.external_brain.originator_stop_condition_gate.v1';

    public const VERDICT_HONEST_STOP   = 'honest_stop';
    public const VERDICT_PREMATURE_STOP = 'premature_stop';

    public const REASON_QUALITY_TARGET   = 'quality_target_reached';
    public const REASON_EXHAUSTED        = 'exhausted_with_evidence';
    public const REASON_QUEUE_PRESSURE   = 'queue_pressure_deferral';
    public const REASON_SURFACE_SATURATED = 'surface_saturated';

    /**
     * @param  array{
     *   quality_target_reached?: bool,
     *   quality_target_evidence?: list<string>,
     *   all_escalation_modes_tried?: bool,
     *   escalation_evidence?: list<string>,
     *   queue_pressure_high?: bool,
     *   task_urgency?: string,
     *   no_high_leverage_surface_remaining?: bool,
     *   deduplication_confirmed?: bool,
     *   first_pass_only?: bool,
     *   open_surfaces_remaining?: int,
     *   remaining_escalation_modes?: int,
     * }  $input
     * @return array{schema:string, verdict:string, stop_reason:string|null, blocking_reasons:list<string>, evidence_cited:list<string>}
     */
    public function evaluate(array $input): array
    {
        $qualityReached     = (bool)  ($input['quality_target_reached']          ?? false);
        $qualityEvidence    = (array) ($input['quality_target_evidence']          ?? []);
        $allEscalationTried = (bool)  ($input['all_escalation_modes_tried']       ?? false);
        $escalationEvidence = (array) ($input['escalation_evidence']              ?? []);
        $queueHigh          = (bool)  ($input['queue_pressure_high']              ?? false);
        $urgency            = (string)($input['task_urgency']                     ?? 'non_urgent');
        $noSurface          = (bool)  ($input['no_high_leverage_surface_remaining'] ?? false);
        $dedupConfirmed     = (bool)  ($input['deduplication_confirmed']           ?? false);
        $firstPassOnly      = (bool)  ($input['first_pass_only']                  ?? false);
        $openSurfaces       = max(0,  (int) ($input['open_surfaces_remaining']    ?? 0));
        $remainingModes     = max(0,  (int) ($input['remaining_escalation_modes'] ?? 0));

        // --- Check honest stop conditions (first match) ---

        if ($qualityReached && $qualityEvidence !== []) {
            return $this->result(
                self::VERDICT_HONEST_STOP,
                self::REASON_QUALITY_TARGET,
                [],
                $qualityEvidence,
            );
        }

        if ($allEscalationTried && $escalationEvidence !== []) {
            return $this->result(
                self::VERDICT_HONEST_STOP,
                self::REASON_EXHAUSTED,
                [],
                $escalationEvidence,
            );
        }

        if ($queueHigh && $urgency === 'non_urgent') {
            return $this->result(
                self::VERDICT_HONEST_STOP,
                self::REASON_QUEUE_PRESSURE,
                [],
                ['queue_pressure_high', 'task_urgency:non_urgent'],
            );
        }

        if ($noSurface && $dedupConfirmed) {
            return $this->result(
                self::VERDICT_HONEST_STOP,
                self::REASON_SURFACE_SATURATED,
                [],
                ['no_high_leverage_surface_remaining', 'deduplication_confirmed'],
            );
        }

        // --- Build premature_stop reasons ---

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

        // Even if no explicit premature marker is present, no honest condition was met.
        if ($blocking === []) {
            $blocking[] = 'no_honest_stop_condition_met';
        }

        return $this->result(self::VERDICT_PREMATURE_STOP, null, $blocking, []);
    }

    /** @param list<string> $blocking @param list<string> $evidence */
    private function result(string $verdict, ?string $reason, array $blocking, array $evidence): array
    {
        return [
            'schema'           => self::SCHEMA,
            'verdict'          => $verdict,
            'stop_reason'      => $reason,
            'blocking_reasons' => $blocking,
            'evidence_cited'   => $evidence,
        ];
    }
}
