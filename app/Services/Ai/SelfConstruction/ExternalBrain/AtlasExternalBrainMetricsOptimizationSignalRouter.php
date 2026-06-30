<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Converts brain/queue/worker/value metrics into prioritised optimization signals for the next batch.
 * Metrics that have no downstream capability, risk-reduction, or worker-efficiency gain are classified
 * as vanity and skipped rather than optimised.
 *
 * KNOWN ACTIONABLE METRICS (each has a threshold, a signal, and a batch constraint):
 *   give_back_rate     → reduce_give_back   (priority 1 — most wasteful)
 *   queue_saturation   → drain_queue        (priority 2 — blocks new work)
 *   evidence_freshness → refresh_evidence   (priority 3 — stale knowledge)
 *   hint_entropy       → diversify_hints    (priority 4 — exploration deficit)
 *   compounding_score  → boost_compounding  (priority 5 — low value output)
 *
 * Any other key supplied in `metrics` has no known downstream gain and is recorded as a skipped vanity metric.
 *
 * INPUT:
 *   metrics:    map<string, float|int|null>
 *   thresholds: optional override map for each actionable metric key
 *
 * OUTPUT:
 *   { schema, ordered_signals, skipped_vanity_metrics, recommended_next_batch_constraints }
 *
 *   ordered_signals: list<{ signal_id, priority, rationale, batch_constraint }>
 *   skipped_vanity_metrics: list<string>
 *   recommended_next_batch_constraints: list<string>  (union of all triggered batch_constraints)
 *
 * PURE / DETERMINISTIC / NO I/O.
 */
final class AtlasExternalBrainMetricsOptimizationSignalRouter
{
    public const SCHEMA = 'atlas.external_brain.metrics_optimization_signal_router.v1';

    // Signal IDs.
    public const SIGNAL_REDUCE_GIVE_BACK   = 'reduce_give_back';
    public const SIGNAL_DRAIN_QUEUE        = 'drain_queue';
    public const SIGNAL_REFRESH_EVIDENCE   = 'refresh_evidence';
    public const SIGNAL_DIVERSIFY_HINTS    = 'diversify_hints';
    public const SIGNAL_BOOST_COMPOUNDING  = 'boost_compounding';

    /**
     * Ordered rule definitions: [metric_key, default_threshold, direction, signal_id, rationale_template, batch_constraint]
     * direction: 'gte' means signal fires when metric >= threshold (bad when high)
     *            'lt'  means signal fires when metric <  threshold (bad when low)
     */
    private const RULES = [
        [
            'key'        => 'give_back_rate',
            'default'    => 0.30,
            'direction'  => 'gte',
            'signal_id'  => self::SIGNAL_REDUCE_GIVE_BACK,
            'rationale'  => 'give_back_rate=%.2f >= threshold=%.2f; repeated give_backs waste worker capacity',
            'constraint' => 'max_batch_size=5',
            'priority'   => 1,
        ],
        [
            'key'        => 'queue_saturation',
            'default'    => 0.80,
            'direction'  => 'gte',
            'signal_id'  => self::SIGNAL_DRAIN_QUEUE,
            'rationale'  => 'queue_saturation=%.2f >= threshold=%.2f; saturated queue blocks new origination',
            'constraint' => 'pause_new_origination',
            'priority'   => 2,
        ],
        [
            'key'        => 'evidence_freshness',
            'default'    => 0.50,
            'direction'  => 'lt',
            'signal_id'  => self::SIGNAL_REFRESH_EVIDENCE,
            'rationale'  => 'evidence_freshness=%.2f < threshold=%.2f; stale evidence degrades origination quality',
            'constraint' => 'evidence_first=true',
            'priority'   => 3,
        ],
        [
            'key'        => 'hint_entropy',
            'default'    => 0.40,
            'direction'  => 'lt',
            'signal_id'  => self::SIGNAL_DIVERSIFY_HINTS,
            'rationale'  => 'hint_entropy=%.2f < threshold=%.2f; low entropy means hints cluster in one area',
            'constraint' => 'require_hint_diversity=true',
            'priority'   => 4,
        ],
        [
            'key'        => 'compounding_score',
            'default'    => 0.50,
            'direction'  => 'lt',
            'signal_id'  => self::SIGNAL_BOOST_COMPOUNDING,
            'rationale'  => 'compounding_score=%.2f < threshold=%.2f; low value output reduces compounding leverage',
            'constraint' => 'min_compounding_value=0.5',
            'priority'   => 5,
        ],
    ];

    private static function actionableKeys(): array
    {
        return array_column(self::RULES, 'key');
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function route(array $input): array
    {
        $metrics    = is_array($input['metrics'] ?? null) ? $input['metrics'] : [];
        $thresholds = is_array($input['thresholds'] ?? null) ? $input['thresholds'] : [];

        $actionableKeys   = self::actionableKeys();
        $signals          = [];
        $skippedVanity    = [];

        foreach ($metrics as $key => $_) {
            if (! in_array($key, $actionableKeys, true)) {
                $skippedVanity[] = (string) $key;
            }
        }
        sort($skippedVanity);

        foreach (self::RULES as $rule) {
            $key = $rule['key'];

            if (! array_key_exists($key, $metrics)) {
                continue;
            }

            $value     = (float) $metrics[$key];
            $threshold = isset($thresholds[$key]) ? (float) $thresholds[$key] : (float) $rule['default'];

            $triggered = $rule['direction'] === 'gte'
                ? $value >= $threshold
                : $value < $threshold;

            if (! $triggered) {
                continue;
            }

            $signals[] = [
                'signal_id'        => $rule['signal_id'],
                'priority'         => $rule['priority'],
                'rationale'        => sprintf($rule['rationale'], $value, $threshold),
                'batch_constraint' => $rule['constraint'],
            ];
        }

        $constraints = array_values(array_unique(array_column($signals, 'batch_constraint')));

        return [
            'schema'                              => self::SCHEMA,
            'ordered_signals'                     => $signals,
            'skipped_vanity_metrics'              => $skippedVanity,
            'recommended_next_batch_constraints'  => $constraints,
        ];
    }
}
