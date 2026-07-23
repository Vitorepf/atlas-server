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
 * CONTEXTUAL VOLUME METRICS (raw counts that are vanity UNLESS paired with quality/outcome/risk
 * context — task_count, green_commits, queue_depth, model_lift never trigger a signal on their
 * own; each requires a companion context field to prove the movement is real, not gamed):
 *   task_count    + context.task_quality_rate < threshold → stop_volume_growth (outcome_quality)
 *   green_commits + metrics.give_back_rate    >= threshold → repair_queue       (outcome_quality)
 *   queue_depth   + metrics.queue_saturation  >= threshold → simplify           (risk)
 *   model_lift    + context.evidence_type not in ACCEPTED_LIFT_EVIDENCE_TYPES  → calibrate_model (risk)
 *   Missing companion context → the raw count is recorded as a skipped vanity metric, never acted on.
 *
 * INPUT:
 *   metrics:    map<string, float|int|null>
 *   context:    optional map<string, mixed> — quality/outcome companion signals (task_quality_rate,
 *               evidence_type) that are NOT thresholds and are never actioned on their own
 *   thresholds: optional override map for each actionable metric key
 *
 * OUTPUT:
 *   { schema, ordered_signals, skipped_vanity_metrics, recommended_next_batch_constraints }
 *
 *   ordered_signals: list<{ signal_id, priority, rationale, batch_constraint,
 *     signal_type, action, confidence, anti_goodhart_reason }>
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
    public const SIGNAL_STOP_VOLUME_GROWTH = 'stop_volume_growth';
    public const SIGNAL_REPAIR_QUEUE_DEPTH = 'repair_queue_from_depth';
    public const SIGNAL_SIMPLIFY_QUEUE     = 'simplify_queue';
    public const SIGNAL_CALIBRATE_MODEL    = 'calibrate_model_lift';

    // Actions (AC3 vocabulary).
    public const ACTION_IMPROVE_TASK_FABRIC = 'improve_task_fabric';
    public const ACTION_REPAIR_QUEUE        = 'repair_queue';
    public const ACTION_SIMPLIFY            = 'simplify';
    public const ACTION_CALIBRATE_MODEL     = 'calibrate_model';
    public const ACTION_STOP_VOLUME_GROWTH  = 'stop_volume_growth';

    // Signal types.
    public const SIGNAL_TYPE_OUTCOME_QUALITY = 'outcome_quality';
    public const SIGNAL_TYPE_RISK            = 'risk';
    public const SIGNAL_TYPE_QUALITY         = 'quality';

    public const ACCEPTED_LIFT_EVIDENCE_TYPES = [
        'before_after_benchmark',
        'heldout_case_delta',
        'repeated_outcome_improvement',
    ];

    private const DEFAULT_TASK_QUALITY_THRESHOLD = 0.50;
    private const DEFAULT_GREEN_COMMIT_GIVE_BACK_THRESHOLD = 0.30;
    private const DEFAULT_QUEUE_DEPTH_SATURATION_THRESHOLD = 0.80;

    // Classification vocabulary (AC2): every metric gets exactly one of these four labels.
    public const CLASSIFICATION_OPTIMIZE = 'optimize';
    public const CLASSIFICATION_GUARDRAIL = 'guardrail';
    public const CLASSIFICATION_INVESTIGATE = 'investigate';
    public const CLASSIFICATION_REJECT_PROXY_METRIC = 'reject_proxy_metric';

    /** a metric older than this (seconds) is never trusted at face value — route to investigate. */
    private const STALE_METRIC_AGE_CEILING_SECONDS = 3600;

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
            'signal_type' => self::SIGNAL_TYPE_OUTCOME_QUALITY,
            'action'      => self::ACTION_REPAIR_QUEUE,
            'anti_goodhart_reason' => 'measures actual rework cost (tasks bounced back), not raw output volume',
        ],
        [
            'key'        => 'queue_saturation',
            'default'    => 0.80,
            'direction'  => 'gte',
            'signal_id'  => self::SIGNAL_DRAIN_QUEUE,
            'rationale'  => 'queue_saturation=%.2f >= threshold=%.2f; saturated queue blocks new origination',
            'constraint' => 'pause_new_origination',
            'priority'   => 2,
            'signal_type' => self::SIGNAL_TYPE_RISK,
            'action'      => self::ACTION_SIMPLIFY,
            'anti_goodhart_reason' => 'measures capacity headroom, not how many items were merely enqueued',
        ],
        [
            'key'        => 'evidence_freshness',
            'default'    => 0.50,
            'direction'  => 'lt',
            'signal_id'  => self::SIGNAL_REFRESH_EVIDENCE,
            'rationale'  => 'evidence_freshness=%.2f < threshold=%.2f; stale evidence degrades origination quality',
            'constraint' => 'evidence_first=true',
            'priority'   => 3,
            'signal_type' => self::SIGNAL_TYPE_QUALITY,
            'action'      => self::ACTION_IMPROVE_TASK_FABRIC,
            'anti_goodhart_reason' => 'measures how current the underlying evidence is, not how many tasks cite it',
        ],
        [
            'key'        => 'hint_entropy',
            'default'    => 0.40,
            'direction'  => 'lt',
            'signal_id'  => self::SIGNAL_DIVERSIFY_HINTS,
            'rationale'  => 'hint_entropy=%.2f < threshold=%.2f; low entropy means hints cluster in one area',
            'constraint' => 'require_hint_diversity=true',
            'priority'   => 4,
            'signal_type' => self::SIGNAL_TYPE_QUALITY,
            'action'      => self::ACTION_IMPROVE_TASK_FABRIC,
            'anti_goodhart_reason' => 'measures topic diversity of origination, not raw hint count',
        ],
        [
            'key'        => 'compounding_score',
            'default'    => 0.50,
            'direction'  => 'lt',
            'signal_id'  => self::SIGNAL_BOOST_COMPOUNDING,
            'rationale'  => 'compounding_score=%.2f < threshold=%.2f; low value output reduces compounding leverage',
            'constraint' => 'min_compounding_value=0.5',
            'priority'   => 5,
            'signal_type' => self::SIGNAL_TYPE_OUTCOME_QUALITY,
            'action'      => self::ACTION_IMPROVE_TASK_FABRIC,
            'anti_goodhart_reason' => 'measures downstream leverage of delivered work, not how many tasks were closed',
        ],
    ];

    /** @var list<string> */
    private const CONTEXTUAL_METRIC_KEYS = ['task_count', 'green_commits', 'queue_depth', 'model_lift'];

    private static function actionableKeys(): array
    {
        return array_merge(array_column(self::RULES, 'key'), self::CONTEXTUAL_METRIC_KEYS);
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function route(array $input): array
    {
        $metrics    = is_array($input['metrics'] ?? null) ? $input['metrics'] : [];
        $thresholds = is_array($input['thresholds'] ?? null) ? $input['thresholds'] : [];
        $context    = is_array($input['context'] ?? null) ? $input['context'] : [];
        $metricAges = is_array($input['metric_ages_seconds'] ?? null) ? $input['metric_ages_seconds'] : [];

        $actionableKeys   = self::actionableKeys();
        $signals          = [];
        $skippedVanity    = [];
        $classifications  = [];
        $staleMetrics     = [];

        foreach ($metrics as $key => $_) {
            if (! in_array($key, $actionableKeys, true)) {
                $skippedVanity[] = (string) $key;
                $classifications[(string) $key] = self::CLASSIFICATION_REJECT_PROXY_METRIC;
            }
        }

        foreach (self::RULES as $rule) {
            $key = $rule['key'];

            if (! array_key_exists($key, $metrics)) {
                continue;
            }

            // A metric older than the staleness ceiling is never trusted at face value — it is
            // routed to investigate and never fires an optimize/guardrail signal on its own.
            if ($this->isMetricStale($key, $metricAges)) {
                $staleMetrics[] = $key;
                $classifications[$key] = self::CLASSIFICATION_INVESTIGATE;

                continue;
            }

            $value     = (float) $metrics[$key];
            $threshold = isset($thresholds[$key]) ? (float) $thresholds[$key] : (float) $rule['default'];

            $triggered = $rule['direction'] === 'gte'
                ? $value >= $threshold
                : $value < $threshold;

            if (! $triggered) {
                $classifications[$key] = self::CLASSIFICATION_OPTIMIZE;

                continue;
            }

            $classifications[$key] = $rule['signal_type'] === self::SIGNAL_TYPE_RISK
                ? self::CLASSIFICATION_GUARDRAIL
                : self::CLASSIFICATION_OPTIMIZE;

            $signals[] = [
                'signal_id'            => $rule['signal_id'],
                'priority'             => $rule['priority'],
                'rationale'            => sprintf($rule['rationale'], $value, $threshold),
                'batch_constraint'     => $rule['constraint'],
                'signal_type'          => $rule['signal_type'],
                'action'               => $rule['action'],
                'confidence'           => $this->confidence($value, $threshold, $rule['direction']),
                'anti_goodhart_reason' => $rule['anti_goodhart_reason'],
                'classification'       => $classifications[$key],
            ];
        }

        // Contextual volume metrics: a raw count never triggers a signal on its own — it only
        // becomes actionable when its quality/outcome/risk companion context proves the movement
        // is real. Without that companion, the raw count is vanity, not an actionable signal.
        $this->routeTaskCount($metrics, $context, $thresholds, $signals, $skippedVanity, $classifications);
        $this->routeGreenCommits($metrics, $thresholds, $signals, $skippedVanity, $classifications);
        $this->routeQueueDepth($metrics, $thresholds, $signals, $skippedVanity, $classifications);
        $this->routeModelLift($metrics, $context, $signals, $skippedVanity, $classifications);

        sort($skippedVanity);
        sort($staleMetrics);
        usort($signals, static fn (array $a, array $b): int => $a['priority'] <=> $b['priority']);

        $constraints = array_values(array_unique(array_column($signals, 'batch_constraint')));

        return [
            'schema'                              => self::SCHEMA,
            'ordered_signals'                     => $signals,
            'skipped_vanity_metrics'              => $skippedVanity,
            'recommended_next_batch_constraints'  => $constraints,
            'metric_classifications'              => $classifications,
            'stale_metrics'                        => $staleMetrics,
        ];
    }

    /** @param  array<string,mixed>  $metricAges */
    private function isMetricStale(string $key, array $metricAges): bool
    {
        if (! array_key_exists($key, $metricAges)) {
            return false;
        }

        return (int) $metricAges[$key] > self::STALE_METRIC_AGE_CEILING_SECONDS;
    }

    private function confidence(float $value, float $threshold, string $direction): string
    {
        $distance = $direction === 'gte' ? ($value - $threshold) : ($threshold - $value);

        return $distance >= 0.20 ? 'high' : 'medium';
    }

    private function routeTaskCount(array $metrics, array $context, array $thresholds, array &$signals, array &$skippedVanity, array &$classifications): void
    {
        if (! array_key_exists('task_count', $metrics)) {
            return;
        }

        if (! array_key_exists('task_quality_rate', $context)) {
            $skippedVanity[] = 'task_count';
            $classifications['task_count'] = self::CLASSIFICATION_REJECT_PROXY_METRIC;

            return;
        }

        $qualityRate = (float) $context['task_quality_rate'];
        $threshold   = isset($thresholds['task_quality_rate']) ? (float) $thresholds['task_quality_rate'] : self::DEFAULT_TASK_QUALITY_THRESHOLD;

        if ($qualityRate >= $threshold) {
            $skippedVanity[] = 'task_count';
            $classifications['task_count'] = self::CLASSIFICATION_REJECT_PROXY_METRIC;

            return;
        }

        $classifications['task_count'] = self::CLASSIFICATION_OPTIMIZE;
        $signals[] = [
            'signal_id'            => self::SIGNAL_STOP_VOLUME_GROWTH,
            'priority'             => 6,
            'rationale'            => sprintf(
                'task_count=%d rising while task_quality_rate=%.2f < threshold=%.2f; volume growth without quality is vanity',
                (int) $metrics['task_count'],
                $qualityRate,
                $threshold,
            ),
            'batch_constraint'     => 'pause_new_task_volume',
            'signal_type'          => self::SIGNAL_TYPE_OUTCOME_QUALITY,
            'action'               => self::ACTION_STOP_VOLUME_GROWTH,
            'confidence'           => $this->confidence($qualityRate, $threshold, 'lt'),
            'anti_goodhart_reason' => 'raw task_count is never actioned alone; it requires task_quality_rate context proving the throughput is real, not gamed',
            'classification'       => self::CLASSIFICATION_OPTIMIZE,
        ];
    }

    private function routeGreenCommits(array $metrics, array $thresholds, array &$signals, array &$skippedVanity, array &$classifications): void
    {
        if (! array_key_exists('green_commits', $metrics)) {
            return;
        }

        if (! array_key_exists('give_back_rate', $metrics)) {
            $skippedVanity[] = 'green_commits';
            $classifications['green_commits'] = self::CLASSIFICATION_REJECT_PROXY_METRIC;

            return;
        }

        $giveBackRate = (float) $metrics['give_back_rate'];
        $threshold    = isset($thresholds['give_back_rate']) ? (float) $thresholds['give_back_rate'] : self::DEFAULT_GREEN_COMMIT_GIVE_BACK_THRESHOLD;

        if ($giveBackRate < $threshold) {
            $skippedVanity[] = 'green_commits';
            $classifications['green_commits'] = self::CLASSIFICATION_REJECT_PROXY_METRIC;

            return;
        }

        $classifications['green_commits'] = self::CLASSIFICATION_OPTIMIZE;
        $signals[] = [
            'signal_id'            => self::SIGNAL_REPAIR_QUEUE_DEPTH,
            'priority'             => 1,
            'rationale'            => sprintf(
                'green_commits=%d is vanity when give_back_rate=%.2f >= threshold=%.2f; green count hides expensive rework',
                (int) $metrics['green_commits'],
                $giveBackRate,
                $threshold,
            ),
            'batch_constraint'     => 'max_batch_size=5',
            'signal_type'          => self::SIGNAL_TYPE_OUTCOME_QUALITY,
            'action'               => self::ACTION_REPAIR_QUEUE,
            'confidence'           => $this->confidence($giveBackRate, $threshold, 'gte'),
            'anti_goodhart_reason' => 'raw green_commits count is never actioned alone; it requires give_back_rate context proving commits are not masking rework',
            'classification'       => self::CLASSIFICATION_OPTIMIZE,
        ];
    }

    private function routeQueueDepth(array $metrics, array $thresholds, array &$signals, array &$skippedVanity, array &$classifications): void
    {
        if (! array_key_exists('queue_depth', $metrics)) {
            return;
        }

        if (! array_key_exists('queue_saturation', $metrics)) {
            $skippedVanity[] = 'queue_depth';
            $classifications['queue_depth'] = self::CLASSIFICATION_REJECT_PROXY_METRIC;

            return;
        }

        $saturation = (float) $metrics['queue_saturation'];
        $threshold  = isset($thresholds['queue_saturation']) ? (float) $thresholds['queue_saturation'] : self::DEFAULT_QUEUE_DEPTH_SATURATION_THRESHOLD;

        if ($saturation < $threshold) {
            $skippedVanity[] = 'queue_depth';
            $classifications['queue_depth'] = self::CLASSIFICATION_REJECT_PROXY_METRIC;

            return;
        }

        $classifications['queue_depth'] = self::CLASSIFICATION_GUARDRAIL;
        $signals[] = [
            'signal_id'            => self::SIGNAL_SIMPLIFY_QUEUE,
            'priority'             => 2,
            'rationale'            => sprintf(
                'queue_depth=%d is vanity unless queue_saturation=%.2f >= threshold=%.2f confirms real capacity pressure',
                (int) $metrics['queue_depth'],
                $saturation,
                $threshold,
            ),
            'batch_constraint'     => 'pause_new_origination',
            'signal_type'          => self::SIGNAL_TYPE_RISK,
            'action'               => self::ACTION_SIMPLIFY,
            'confidence'           => $this->confidence($saturation, $threshold, 'gte'),
            'anti_goodhart_reason' => 'raw queue_depth is never actioned alone; it requires queue_saturation context proving the backlog is a real capacity risk',
            'classification'       => self::CLASSIFICATION_GUARDRAIL,
        ];
    }

    private function routeModelLift(array $metrics, array $context, array &$signals, array &$skippedVanity, array &$classifications): void
    {
        if (! array_key_exists('model_lift', $metrics)) {
            return;
        }

        $evidenceType = isset($context['evidence_type']) ? (string) $context['evidence_type'] : null;
        $accepted = $evidenceType !== null && in_array($evidenceType, self::ACCEPTED_LIFT_EVIDENCE_TYPES, true);

        // Conflicting metric: the evidence type is legitimate, but an independent prior result
        // contradicts the claimed lift — never blindly trust it, and never blindly reject it either;
        // it needs a human/deeper look before either optimizing or calibrating on it.
        if ($accepted && (bool) ($context['contradicting_evidence'] ?? false)) {
            $classifications['model_lift'] = self::CLASSIFICATION_INVESTIGATE;

            return;
        }

        if ($accepted) {
            $skippedVanity[] = 'model_lift';
            $classifications['model_lift'] = self::CLASSIFICATION_OPTIMIZE;

            return;
        }

        $classifications['model_lift'] = self::CLASSIFICATION_GUARDRAIL;
        $signals[] = [
            'signal_id'            => self::SIGNAL_CALIBRATE_MODEL,
            'priority'             => 3,
            'rationale'            => sprintf(
                "model_lift=%.2f claimed via evidence_type='%s', which is not an accepted lift evidence source; calibrate against a real benchmark before trusting it",
                (float) $metrics['model_lift'],
                $evidenceType ?? 'none',
            ),
            'batch_constraint'     => 'evidence_first=true',
            'signal_type'          => self::SIGNAL_TYPE_RISK,
            'action'               => self::ACTION_CALIBRATE_MODEL,
            'confidence'           => 'high',
            'anti_goodhart_reason' => 'raw model_lift is never trusted on a prompt-asserted claim; it requires accepted benchmark evidence_type before being actioned',
            'classification'       => self::CLASSIFICATION_GUARDRAIL,
        ];
    }
}
