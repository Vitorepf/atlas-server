<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure replay harness for the external-brain end-to-end autonomy cycle.
 *
 * Given pre-collected scenario facts (queue_facts, candidate_pool), deterministically
 * computes the brain_decision without any live providers, Artisan calls, or mutations.
 *
 * REQUIRED SECTIONS (AC3 — fail closed when absent):
 *   queue_facts      — queue state snapshot
 *   candidate_pool   — list of origination candidates
 *
 * DECISION PRIORITY (first match wins):
 *   1. evidence_missing      — any required section absent
 *   2. drain_poison          — queue_facts.poison_detected = true
 *   3. reduce_sprawl         — queue_facts.sprawl_pressure = true
 *   4. deprioritize_low_value — queue_facts.low_value_ratio > low_value_threshold (default 0.60)
 *   5. create_more_tasks     — steady-state, queue is healthy
 *
 * PURE / DETERMINISTIC / NO I/O.
 */
final class AtlasExternalBrainEndToEndAutonomyReplayHarness
{
    public const SCHEMA = 'atlas.external_brain.end_to_end_autonomy_replay_harness.v1';

    public const DECISION_CREATE_MORE_TASKS      = 'create_more_tasks';
    public const DECISION_DRAIN_POISON           = 'drain_poison';
    public const DECISION_DEPRIORITIZE_LOW_VALUE = 'deprioritize_low_value';
    public const DECISION_REDUCE_SPRAWL          = 'reduce_sprawl';
    public const DECISION_EVIDENCE_MISSING       = 'evidence_missing';

    private const REQUIRED_SECTIONS   = ['queue_facts', 'candidate_pool'];
    private const LOW_VALUE_THRESHOLD = 0.60;

    /**
     * @param  array<string,mixed>  $scenario
     * @return array<string,mixed>
     */
    public function replay(array $scenario): array
    {
        // AC3: fail closed when required evidence sections are missing.
        foreach (self::REQUIRED_SECTIONS as $section) {
            if (! array_key_exists($section, $scenario)) {
                return [
                    'schema'         => self::SCHEMA,
                    'brain_decision' => self::DECISION_EVIDENCE_MISSING,
                    'missing_section' => $section,
                ];
            }
        }

        $queueFacts    = (array) $scenario['queue_facts'];
        $candidatePool = is_array($scenario['candidate_pool']) ? $scenario['candidate_pool'] : [];
        $lowValueThreshold = (float) ($scenario['low_value_threshold'] ?? self::LOW_VALUE_THRESHOLD);

        $poisonDetected = (bool)  ($queueFacts['poison_detected'] ?? false);
        $sprawlPressure = (bool)  ($queueFacts['sprawl_pressure'] ?? false);
        $lowValueRatio  = (float) ($queueFacts['low_value_ratio'] ?? 0.0);

        $decision = match (true) {
            $poisonDetected                   => self::DECISION_DRAIN_POISON,
            $sprawlPressure                   => self::DECISION_REDUCE_SPRAWL,
            $lowValueRatio > $lowValueThreshold => self::DECISION_DEPRIORITIZE_LOW_VALUE,
            default                           => self::DECISION_CREATE_MORE_TASKS,
        };

        return [
            'schema'          => self::SCHEMA,
            'brain_decision'  => $decision,
            'queue_facts_seen' => $queueFacts,
            'candidate_count' => count($candidatePool),
        ];
    }
}
