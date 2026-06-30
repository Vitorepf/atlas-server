<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure, facts-only replay verifier for ONE end-to-end autonomy cycle: the brain chose a lever, Task Fabric
 * emitted a packet, a muscle produced an outcome (success or give_back), gates judged it, outcome learning
 * recorded it, and the NEXT decision actually changed because of that outcome. This is the proof the external
 * brain is genuinely learning from outcomes rather than emitting isolated, unconnected tasks.
 *
 * Input is an ordered list of stage facts: each carries a `stage` name (one of REQUIRED_STAGES) and a
 * `sequence` integer marking its real arrival order. `lever_chosen` and `next_decision` additionally carry a
 * `lever` string — the cycle only counts the decision as having genuinely changed when those two levers
 * differ, regardless of which lever it changed TO (a give_back routing to repair/respec still counts).
 *
 * CAUSALITY proof (beyond mere stage presence): packet_emitted, muscle_outcome, gates_judged, and
 * outcome_learning must all share the same identity — a `task_packet_id` or `correlation_id` field — so
 * the cycle is provably about ONE task, not four unrelated events that happen to share stage names.
 * gates_judged must also carry a non-empty `gate_verdict`, and outcome_learning a non-empty
 * `outcome_learning_ref`, so a stage cannot count as "judged" or "learned from" with no actual evidence.
 *
 * Never executes, dispatches, or mutates anything — it only replays the fact stream it is handed.
 */
final class AtlasExternalBrainAutonomyCycleReplayVerifier
{
    public const SCHEMA = 'atlas.self_construction.external_brain.autonomy_cycle_replay_verifier.v1';

    public const REQUIRED_STAGES = [
        'lever_chosen',
        'packet_emitted',
        'muscle_outcome',
        'gates_judged',
        'outcome_learning',
        'next_decision',
    ];

    /** Stages whose identity (task_packet_id / correlation_id) must all agree. */
    private const CORRELATED_STAGES = ['packet_emitted', 'muscle_outcome', 'gates_judged', 'outcome_learning'];

    /**
     * @param  list<array<string, mixed>>  $facts
     * @return array<string, mixed>
     */
    public function verify(array $facts): array
    {
        $byStage = [];
        foreach ($facts as $fact) {
            $stage = (string) ($fact['stage'] ?? '');
            if ($stage === '' || isset($byStage[$stage])) {
                continue;
            }
            $byStage[$stage] = $fact;
        }

        $decisionChanged = isset($byStage['lever_chosen'], $byStage['next_decision'])
            && (string) ($byStage['lever_chosen']['lever'] ?? '') !== (string) ($byStage['next_decision']['lever'] ?? '');

        $stagesObserved = [];
        $missingStage = null;
        foreach (self::REQUIRED_STAGES as $stage) {
            $fulfilled = $stage === 'next_decision'
                ? isset($byStage[$stage]) && $decisionChanged
                : isset($byStage[$stage]);

            if ($fulfilled) {
                $stagesObserved[] = $stage;

                continue;
            }

            if ($missingStage === null) {
                $missingStage = $stage;
            }
        }

        $orderingViolations = $this->orderingViolations($byStage);
        $causalityViolations = $this->causalityViolations($byStage);

        return [
            'schema' => self::SCHEMA,
            'cycle_complete' => $missingStage === null && $orderingViolations === [] && $causalityViolations === [],
            'stages_observed' => $stagesObserved,
            'missing_stage' => $missingStage,
            'decision_changed' => $decisionChanged,
            'ordering_violations' => $orderingViolations,
            'causality_violations' => $causalityViolations,
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $byStage
     * @return list<array<string, mixed>>
     */
    private function causalityViolations(array $byStage): array
    {
        $violations = [];

        $identityByStage = [];
        foreach (self::CORRELATED_STAGES as $stage) {
            if (! isset($byStage[$stage])) {
                continue;
            }
            $identity = (string) ($byStage[$stage]['task_packet_id'] ?? $byStage[$stage]['correlation_id'] ?? '');
            if ($identity === '') {
                $violations[] = ['code' => 'missing_correlation_id', 'stage' => $stage];

                continue;
            }
            $identityByStage[$stage] = $identity;
        }

        if ($identityByStage !== []) {
            $distinctIdentities = array_unique($identityByStage);
            if (count($distinctIdentities) > 1) {
                $violations[] = [
                    'code' => 'correlation_mismatch',
                    'identities_by_stage' => $identityByStage,
                ];
            }
        }

        if (isset($byStage['gates_judged']) && (string) ($byStage['gates_judged']['gate_verdict'] ?? '') === '') {
            $violations[] = ['code' => 'missing_gate_verdict', 'stage' => 'gates_judged'];
        }

        if (isset($byStage['outcome_learning']) && (string) ($byStage['outcome_learning']['outcome_learning_ref'] ?? '') === '') {
            $violations[] = ['code' => 'missing_outcome_learning_ref', 'stage' => 'outcome_learning'];
        }

        return $violations;
    }

    /**
     * @param  array<string, array<string, mixed>>  $byStage
     * @return list<array<string, mixed>>
     */
    private function orderingViolations(array $byStage): array
    {
        $violations = [];
        $prevStage = null;
        $prevSequence = null;

        foreach (self::REQUIRED_STAGES as $stage) {
            if (! isset($byStage[$stage])) {
                continue;
            }
            $sequence = (int) ($byStage[$stage]['sequence'] ?? 0);

            if ($prevSequence !== null && $sequence < $prevSequence) {
                $violations[] = [
                    'stage' => $stage,
                    'sequence' => $sequence,
                    'expected_after' => $prevStage,
                    'after_sequence' => $prevSequence,
                ];
            }

            $prevStage = $stage;
            $prevSequence = $sequence;
        }

        return $violations;
    }
}
