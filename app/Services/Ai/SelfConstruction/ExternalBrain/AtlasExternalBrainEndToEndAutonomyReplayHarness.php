<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure replay harness for the external-brain end-to-end autonomy cycle.
 *
 * Replays one FULL cycle through five named steps, in order, and FAILS
 * CLOSED the moment any step is missing or depends on a human/operator or a
 * provider-specific steady-state assumption — a 24/7 autonomous loop cannot
 * have either on its critical path.
 *
 * CYCLE STEPS (in order):
 *   intake            — context/evidence ingestion
 *   admission         — candidate pool admitted into the queue
 *   enqueue_decision  — queue-pressure decision (poison/sprawl/low-value/create-more)
 *   outcome_learning  — muscle outcomes recorded back into learning
 *   next_action       — what the brain does on the next tick
 *
 * Each step's facts may declare:
 *   requires_operator?:               bool (default false)
 *   requires_provider_steady_state?:  bool (default false)
 * Either flag true on ANY step fails the whole replay at that step.
 *
 * autonomy_replay_status:
 *   cycle_complete                       — all five steps passed.
 *   evidence_missing                     — a required step section is absent.
 *   human_or_provider_dependency_detected — a step declared an operator/provider
 *                                          steady-state dependency.
 *
 * failed_step / next_repair_hint are null only when autonomy_replay_status is
 * cycle_complete.
 *
 * enqueue_decision facts (when the step passes) drive brain_decision using the
 * same queue-pressure priority as before:
 *   1. drain_poison           — queue_facts.poison_detected = true
 *   2. reduce_sprawl          — queue_facts.sprawl_pressure = true
 *   3. deprioritize_low_value — queue_facts.low_value_ratio > low_value_threshold (default 0.60)
 *   4. create_more_tasks      — steady-state, queue is healthy
 *
 * PURE / DETERMINISTIC / NO I/O.
 */
final class AtlasExternalBrainEndToEndAutonomyReplayHarness
{
    public const SCHEMA = 'atlas.external_brain.end_to_end_autonomy_replay_harness.v1';

    public const STATUS_CYCLE_COMPLETE = 'cycle_complete';
    public const STATUS_EVIDENCE_MISSING = 'evidence_missing';
    public const STATUS_HUMAN_OR_PROVIDER_DEPENDENCY_DETECTED = 'human_or_provider_dependency_detected';

    public const DECISION_CREATE_MORE_TASKS = 'create_more_tasks';
    public const DECISION_DRAIN_POISON = 'drain_poison';
    public const DECISION_DEPRIORITIZE_LOW_VALUE = 'deprioritize_low_value';
    public const DECISION_REDUCE_SPRAWL = 'reduce_sprawl';

    public const CYCLE_STEPS = ['intake', 'admission', 'enqueue_decision', 'outcome_learning', 'next_action'];

    private const LOW_VALUE_THRESHOLD = 0.60;

    private const REPAIR_HINTS = [
        'intake' => 'Supply intake facts (context/evidence ingestion) before replaying the cycle.',
        'admission' => 'Supply admission facts (candidate_pool) before replaying the cycle.',
        'enqueue_decision' => 'Supply enqueue_decision facts (queue_facts) before replaying the cycle.',
        'outcome_learning' => 'Supply outcome_learning facts (recorded muscle outcomes) before replaying the cycle.',
        'next_action' => 'Supply next_action facts before replaying the cycle.',
    ];

    /**
     * @param  array<string,mixed>  $scenario
     * @return array<string,mixed>
     */
    public function replay(array $scenario): array
    {
        foreach (self::CYCLE_STEPS as $step) {
            if (! array_key_exists($step, $scenario) || ! is_array($scenario[$step])) {
                return [
                    'schema' => self::SCHEMA,
                    'autonomy_replay_status' => self::STATUS_EVIDENCE_MISSING,
                    'failed_step' => $step,
                    'next_repair_hint' => self::REPAIR_HINTS[$step] ?? "Supply {$step} facts before replaying the cycle.",
                    'completed_steps' => [],
                ];
            }

            $stepFacts = $scenario[$step];
            $requiresOperator = (bool) ($stepFacts['requires_operator'] ?? false);
            $requiresProviderSteadyState = (bool) ($stepFacts['requires_provider_steady_state'] ?? false);

            if ($requiresOperator || $requiresProviderSteadyState) {
                $reason = $requiresOperator ? 'requires_operator' : 'requires_provider_steady_state';

                return [
                    'schema' => self::SCHEMA,
                    'autonomy_replay_status' => self::STATUS_HUMAN_OR_PROVIDER_DEPENDENCY_DETECTED,
                    'failed_step' => $step,
                    'next_repair_hint' => "Remove the {$reason} dependency from {$step} and replace it with an Atlas-native autonomous path.",
                    'completed_steps' => array_slice(self::CYCLE_STEPS, 0, array_search($step, self::CYCLE_STEPS, true)),
                ];
            }
        }

        $queueFacts = (array) ($scenario['enqueue_decision']['queue_facts'] ?? []);
        $candidatePool = is_array($scenario['admission']['candidate_pool'] ?? null) ? $scenario['admission']['candidate_pool'] : [];
        $lowValueThreshold = (float) ($scenario['enqueue_decision']['low_value_threshold'] ?? self::LOW_VALUE_THRESHOLD);

        $poisonDetected = (bool) ($queueFacts['poison_detected'] ?? false);
        $sprawlPressure = (bool) ($queueFacts['sprawl_pressure'] ?? false);
        $lowValueRatio = (float) ($queueFacts['low_value_ratio'] ?? 0.0);

        $brainDecision = match (true) {
            $poisonDetected => self::DECISION_DRAIN_POISON,
            $sprawlPressure => self::DECISION_REDUCE_SPRAWL,
            $lowValueRatio > $lowValueThreshold => self::DECISION_DEPRIORITIZE_LOW_VALUE,
            default => self::DECISION_CREATE_MORE_TASKS,
        };

        return [
            'schema' => self::SCHEMA,
            'autonomy_replay_status' => self::STATUS_CYCLE_COMPLETE,
            'failed_step' => null,
            'next_repair_hint' => null,
            'completed_steps' => self::CYCLE_STEPS,
            'brain_decision' => $brainDecision,
            'queue_facts_seen' => $queueFacts,
            'candidate_count' => count($candidatePool),
        ];
    }
}
