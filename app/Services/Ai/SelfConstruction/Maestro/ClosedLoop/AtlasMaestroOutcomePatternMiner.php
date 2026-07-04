<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Maestro\ClosedLoop;

final class AtlasMaestroOutcomePatternMiner
{
    public const MIN_SUPPORT = 8;

    /** Minimum delivered successes required for a dimension/bucket to qualify as a reusable strategy pattern. */
    public const MIN_STRATEGY_OCCURRENCES = 5;

    private const OUTCOMES = ['delivered', 'give_back', 'rejected', 'stale'];

    /** Rows with occurred_at age at or below this are full-weight "recent" evidence. */
    private const RECENT_WINDOW_SECONDS = 14 * 86400;

    /** Rows older than this are heavily decayed "stale" evidence — obsolete patterns must not steer Maestro. */
    private const STALE_WINDOW_SECONDS = 60 * 86400;

    private const RECENT_WEIGHT = 1.0;

    private const AGING_WEIGHT = 0.5;

    private const STALE_WEIGHT = 0.15;

    /** Maps a mined dimension to the downstream policy a strategy signal should steer. */
    private const DIMENSION_POLICY = [
        'origin_kind' => 'routing_policy',
        'allowed_files_count_bucket' => 'respec_policy',
        'acceptance_criteria_count_bucket' => 'respec_policy',
        'has_tests_path' => 'respec_policy',
        'file_family' => 'respec_policy',
        'task_shape' => 'originator_prompt_policy',
        'worker_id' => 'routing_policy',
        'proof_command_class' => 'routing_policy',
    ];

    public function __construct(private readonly AtlasMaestroOutcomeShapeLedger $ledger)
    {
    }

    /**
     * @return array<string,array<string,array<string,mixed>>>
     */
    public function mine(): array
    {
        $facts = [
            'origin_kind' => [],
            'allowed_files_count_bucket' => [],
            'acceptance_criteria_count_bucket' => [],
            'has_tests_path' => [],
            'file_family' => [],
            'task_shape' => [],
            'worker_id' => [],
            'proof_command_class' => [],
        ];

        foreach ($this->ledger->stream() as $row) {
            $outcome = (string) ($row['outcome'] ?? '');
            if (! in_array($outcome, self::OUTCOMES, true)) {
                continue;
            }

            $buckets = [
                'origin_kind' => (string) ($row['origin_kind'] ?? 'unknown'),
                'allowed_files_count_bucket' => $this->countBucket((int) ($row['allowed_files_count'] ?? 0)),
                'acceptance_criteria_count_bucket' => $this->countBucket((int) ($row['acceptance_criteria_count'] ?? 0)),
                'has_tests_path' => (bool) ($row['has_tests_path'] ?? false) ? 'true' : 'false',
                'file_family' => (string) ($row['file_family'] ?? 'unknown'),
                'task_shape' => (string) ($row['task_shape'] ?? 'unknown'),
                'worker_id' => (string) ($row['worker_id'] ?? 'unknown'),
                'proof_command_class' => (string) ($row['proof_command_class'] ?? 'unknown'),
            ];

            $weight = $this->recencyWeight($row);

            foreach ($buckets as $dimension => $bucket) {
                $facts[$dimension][$bucket] ??= $this->emptyBucket($dimension, $bucket);
                $facts[$dimension][$bucket][$outcome]++;
                $facts[$dimension][$bucket]['total']++;
                $facts[$dimension][$bucket]['weighted_total'] += $weight['weight'];
                if ($outcome === 'delivered') {
                    $facts[$dimension][$bucket]['weighted_delivered'] += $weight['weight'];
                } else {
                    $facts[$dimension][$bucket]['weighted_failure'] += $weight['weight'];
                }
                if ($weight['recent']) {
                    $facts[$dimension][$bucket]['recent_count']++;
                }
                if ($weight['stale']) {
                    $facts[$dimension][$bucket]['stale_count']++;
                }
            }
        }

        foreach ($facts as $dimension => $buckets) {
            ksort($buckets);
            foreach ($buckets as $bucket => $entry) {
                $total = (int) $entry['total'];
                $weightedTotal = (float) $entry['weighted_total'];
                $entry['insufficient_support'] = $total < self::MIN_SUPPORT;
                $entry['delivery_rate'] = $total >= self::MIN_SUPPORT
                    ? (float) (((int) $entry['delivered']) / $total)
                    : null;
                $qualifies = $total >= self::MIN_SUPPORT && $weightedTotal > 0.0;
                // Recency-weighted rates: recent evidence outweighs stale evidence, so a delivered
                // burst that recently contradicts an old failure trend can flip the reading without
                // waiting for the raw failure rows to age out of the dataset entirely.
                $entry['recency_weighted_delivery_rate'] = $qualifies
                    ? (float) ($entry['weighted_delivered'] / $weightedTotal)
                    : null;
                $entry['recency_weighted_failure_rate'] = $qualifies
                    ? (float) ($entry['weighted_failure'] / $weightedTotal)
                    : null;
                $buckets[$bucket] = $entry;
            }
            $facts[$dimension] = $buckets;
        }

        return $facts;
    }

    /**
     * @param  array<string,mixed>  $row
     * @return array{weight:float, recent:bool, stale:bool}
     */
    private function recencyWeight(array $row): array
    {
        $occurredAt = $row['occurred_at'] ?? null;
        if ($occurredAt === null) {
            // No timestamp recorded ⇒ treat as current evidence (backward compatible with rows
            // that predate recency tracking).
            return ['weight' => self::RECENT_WEIGHT, 'recent' => true, 'stale' => false];
        }

        $timestamp = is_numeric($occurredAt) ? (int) $occurredAt : strtotime((string) $occurredAt);
        if ($timestamp === false) {
            return ['weight' => self::RECENT_WEIGHT, 'recent' => true, 'stale' => false];
        }

        $age = max(0, time() - $timestamp);

        return match (true) {
            $age <= self::RECENT_WINDOW_SECONDS => ['weight' => self::RECENT_WEIGHT, 'recent' => true, 'stale' => false],
            $age <= self::STALE_WINDOW_SECONDS => ['weight' => self::AGING_WEIGHT, 'recent' => false, 'stale' => false],
            default => ['weight' => self::STALE_WEIGHT, 'recent' => false, 'stale' => true],
        };
    }

    /**
     * Return strategy signals — dimension/bucket pairs with proven delivery_rate >= 0.5 —
     * for final-brain lane selection. Sorted by delivery_rate DESC, then support DESC.
     *
     * @return list<array<string,mixed>>
     */
    public function strategySignals(): array
    {
        $signals = [];

        foreach ($this->mine() as $dimension => $buckets) {
            foreach ($buckets as $bucket => $entry) {
                if ($entry['delivery_rate'] === null || $entry['delivery_rate'] < 0.5) {
                    continue;
                }
                $signals[] = [
                    'dimension' => $dimension,
                    'bucket' => $bucket,
                    'delivery_rate' => $entry['delivery_rate'],
                    'support' => $entry['total'],
                ];
            }
        }

        usort($signals, static function (array $a, array $b): int {
            $r = $b['delivery_rate'] <=> $a['delivery_rate'];

            return $r !== 0 ? $r : $b['support'] <=> $a['support'];
        });

        return $signals;
    }

    /**
     * Return reusable strategy patterns — a subset of strategySignals() where delivered successes
     * are at or above MIN_STRATEGY_OCCURRENCES. One-off successes that merely satisfy
     * delivery_rate >= 0.5 at minimum total support remain observations only and are excluded.
     *
     * Sorted by delivery_rate DESC, then by delivered DESC (most evidence first).
     *
     * @return list<array<string,mixed>>
     */
    public function strategyPatterns(): array
    {
        $patterns = [];

        foreach ($this->mine() as $dimension => $buckets) {
            foreach ($buckets as $bucket => $entry) {
                $weightedRate = $entry['recency_weighted_delivery_rate'];
                if ($weightedRate === null || $weightedRate < 0.5) {
                    continue;
                }
                if ((int) $entry['delivered'] < self::MIN_STRATEGY_OCCURRENCES) {
                    continue; // one-off success: observation only, not a reusable pattern
                }
                $patterns[] = [
                    'dimension'     => $dimension,
                    'bucket'        => $bucket,
                    'delivery_rate' => $weightedRate,
                    'support'       => $entry['total'],
                    'delivered'     => (int) $entry['delivered'],
                    'recent_count'  => (int) $entry['recent_count'],
                    'stale_count'   => (int) $entry['stale_count'],
                    'policy_hint'   => $this->computePolicyHint($dimension, $bucket, $entry),
                ];
            }
        }

        usort($patterns, static function (array $a, array $b): int {
            $r = $b['delivery_rate'] <=> $a['delivery_rate'];

            return $r !== 0 ? $r : $b['delivered'] <=> $a['delivered'];
        });

        return $patterns;
    }

    /**
     * Return negative patterns — dimension/bucket pairs at or above MIN_SUPPORT where
     * give_back + rejected + stale outnumber delivered. Sorted by failure_rate DESC,
     * then support DESC, so the brain can avoid the most-proven-bad task shapes first.
     *
     * @return list<array<string,mixed>>
     */
    public function negativePatterns(): array
    {
        $patterns = [];

        foreach ($this->mine() as $dimension => $buckets) {
            foreach ($buckets as $bucket => $entry) {
                if ((bool) $entry['insufficient_support']) {
                    continue;
                }
                if ((float) $entry['weighted_failure'] <= (float) $entry['weighted_delivered']) {
                    continue;
                }
                $policyHint = $this->computePolicyHint($dimension, $bucket, $entry);
                $patterns[] = [
                    'dimension'     => $dimension,
                    'bucket'        => $bucket,
                    'failure_rate'  => (float) $entry['recency_weighted_failure_rate'],
                    'support'       => (int) $entry['total'],
                    'give_back'     => (int) $entry['give_back'],
                    'rejected'      => (int) $entry['rejected'],
                    'stale'         => (int) $entry['stale'],
                    'delivered'     => (int) $entry['delivered'],
                    'recent_count'  => (int) $entry['recent_count'],
                    'stale_count'   => (int) $entry['stale_count'],
                    'policy_hint'   => $policyHint,
                ];
            }
        }

        usort($patterns, static function (array $a, array $b): int {
            $r = $b['failure_rate'] <=> $a['failure_rate'];

            return $r !== 0 ? $r : $b['support'] <=> $a['support'];
        });

        return $patterns;
    }

    /**
     * Compute worker-family policy hint for a negative pattern.
     *
     * @param  array<string, mixed>  $entry
     * @return string
     */
    private function computePolicyHint(string $dimension, string $bucket, array $entry): string
    {
        // worker_id dimension with high failure = worker fit problem → reroute
        if ($dimension === 'worker_id') {
            return 'reroute_worker_fit';
        }

        // file_family or task_shape dimension with high failure = packet shape problem → respec
        if ($dimension === 'file_family' || $dimension === 'task_shape') {
            return 'respec_packet_shape';
        }

        // origin_kind or proof_command_class = routing problem
        if ($dimension === 'origin_kind' || $dimension === 'proof_command_class') {
            return 'reroute_origin_policy';
        }

        // Default: avoid this shape
        return 'avoid_shape';
    }

    /**
     * Turns strategyPatterns() (positive) and negativePatterns() (negative) into concrete strategy
     * signals for routing, respec, and originator prompt policy — each carrying affected_policy,
     * confidence, sample_size, and next_action so a downstream policy can act without re-deriving
     * the mined statistics. Buckets below MIN_SUPPORT/MIN_STRATEGY_OCCURRENCES never appear here
     * (inherited from strategyPatterns()/negativePatterns()'s own gating), and a bucket with mixed
     * (neither decisively good nor bad) outcomes produces no signal at all.
     *
     * Ordered: all positive signals (by confidence DESC) before all negative signals (by
     * confidence DESC) — deterministic given the underlying sorted sources.
     *
     * @return list<array{polarity:string, dimension:string, bucket:string, affected_policy:string, confidence:float, sample_size:int, next_action:string}>
     */
    public function strategyPolicySignals(): array
    {
        $signals = [];

        foreach ($this->strategyPatterns() as $pattern) {
            $policy = self::DIMENSION_POLICY[$pattern['dimension']] ?? 'routing_policy';
            $signals[] = [
                'polarity' => 'positive',
                'dimension' => $pattern['dimension'],
                'bucket' => $pattern['bucket'],
                'affected_policy' => $policy,
                'confidence' => $pattern['delivery_rate'],
                'sample_size' => $pattern['support'],
                'next_action' => sprintf('prefer_%s_for_%s_in_%s', $pattern['bucket'], $pattern['dimension'], $policy),
                'evidence_window' => [
                    'recent_count' => $pattern['recent_count'],
                    'stale_count' => $pattern['stale_count'],
                    'recency_weighted_confidence' => $pattern['delivery_rate'],
                ],
            ];
        }

        foreach ($this->negativePatterns() as $pattern) {
            $policy = self::DIMENSION_POLICY[$pattern['dimension']] ?? 'routing_policy';
            $signals[] = [
                'polarity' => 'negative',
                'dimension' => $pattern['dimension'],
                'bucket' => $pattern['bucket'],
                'affected_policy' => $policy,
                'confidence' => $pattern['failure_rate'],
                'sample_size' => $pattern['support'],
                'next_action' => sprintf('avoid_%s_for_%s_in_%s', $pattern['bucket'], $pattern['dimension'], $policy),
                'evidence_window' => [
                    'recent_count' => $pattern['recent_count'],
                    'stale_count' => $pattern['stale_count'],
                    'recency_weighted_confidence' => $pattern['failure_rate'],
                ],
            ];
        }

        return $signals;
    }

    /**
     * @return array<string,mixed>
     */
    private function emptyBucket(string $dimension, string $bucket): array
    {
        return [
            'dimension' => $dimension,
            'bucket' => $bucket,
            'delivered' => 0,
            'give_back' => 0,
            'rejected' => 0,
            'stale' => 0,
            'total' => 0,
            'insufficient_support' => true,
            'delivery_rate' => null,
            'weighted_delivered' => 0.0,
            'weighted_failure' => 0.0,
            'weighted_total' => 0.0,
            'recent_count' => 0,
            'stale_count' => 0,
            'recency_weighted_delivery_rate' => null,
            'recency_weighted_failure_rate' => null,
        ];
    }

    private function countBucket(int $count): string
    {
        return match (true) {
            $count <= 1 => '0-1',
            $count <= 3 => '2-3',
            default => '4+',
        };
    }
}
