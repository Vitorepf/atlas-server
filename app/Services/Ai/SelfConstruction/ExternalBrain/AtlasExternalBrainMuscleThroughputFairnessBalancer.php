<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Distributes work across multiple muscles (workers/local clients) without
 * starving slower-but-reliable workers or overloading one fast-but-risky
 * client. Pure: it only computes a recommended distribution and warnings —
 * it never claims, dispatches, or mutates any lease itself.
 *
 * Per-muscle adjusted capacity:
 *   adjusted_capacity = throughput_per_hour
 *                      * reliability_score
 *                      * (1 - give_back_rate)^2
 *                      / (1 + active_lease_count)
 *
 * The give_back_rate penalty is squared (not linear) so a fast-but-risky
 * muscle's raw throughput cannot dominate a slower, reliable one — a
 * moderate give_back_rate already collapses most of the throughput
 * advantage before normalization.
 *
 * recommended_distribution normalizes adjusted_capacity across all muscles
 * to shares that sum to 1.0 (0.0 for every muscle when total capacity is 0).
 *
 * fairness_score = 1 - (max_share - min_share), clamped to [0, 1]. A
 * perfectly even distribution scores 1.0; total starvation of one muscle
 * while another takes everything scores close to 0.0.
 *
 * OVERLOAD WARNINGS (per muscle, any may apply):
 *   overloaded_lease_count <- active_lease_count > 3
 *   overloaded_share       <- recommended_distribution share > 0.6
 *   high_give_back_rate    <- give_back_rate > 0.3
 *
 * PER-MUSCLE next_task_family preference:
 *   high_risk_eligible <- reliability_score >= 0.7 AND give_back_rate < 0.2 (proven)
 *   low_risk_only      <- otherwise (avoids assigning high-risk work to unproven muscles)
 *
 * INPUT:
 *   muscles: list<{
 *     muscle_id:               string
 *     throughput_per_hour?:    float (default 0.0)
 *     reliability_score?:      float (default 0.0)
 *     active_lease_count?:     int (default 0)
 *     give_back_rate?:         float (default 0.0)
 *   }>
 *
 * OUTPUT:
 *   { schema, recommended_distribution, fairness_score, overload_warnings,
 *     next_task_family_preferences }
 *
 * Pure: no I/O, no lease mutation, no side effects.
 */
final class AtlasExternalBrainMuscleThroughputFairnessBalancer
{
    public const SCHEMA = 'atlas.external_brain.muscle_throughput_fairness_balancer.v1';

    private const OVERLOADED_LEASE_COUNT = 3;

    private const OVERLOADED_SHARE = 0.6;

    private const HIGH_GIVE_BACK_RATE = 0.3;

    private const PROVEN_RELIABILITY_THRESHOLD = 0.7;

    private const PROVEN_GIVE_BACK_RATE_THRESHOLD = 0.2;

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function balance(array $input): array
    {
        $muscles = is_array($input['muscles'] ?? null) ? $input['muscles'] : [];

        $capacities = [];
        $facts = [];
        foreach ($muscles as $muscle) {
            if (! is_array($muscle) || ! isset($muscle['muscle_id'])) {
                continue;
            }
            $id = (string) $muscle['muscle_id'];
            $throughput = max(0.0, (float) ($muscle['throughput_per_hour'] ?? 0.0));
            $reliability = max(0.0, min(1.0, (float) ($muscle['reliability_score'] ?? 0.0)));
            $activeLeases = max(0, (int) ($muscle['active_lease_count'] ?? 0));
            $giveBackRate = max(0.0, min(1.0, (float) ($muscle['give_back_rate'] ?? 0.0)));

            $facts[$id] = [
                'throughput_per_hour' => $throughput,
                'reliability_score' => $reliability,
                'active_lease_count' => $activeLeases,
                'give_back_rate' => $giveBackRate,
            ];

            $capacities[$id] = $throughput * $reliability * (1 - $giveBackRate) ** 2 / (1 + $activeLeases);
        }

        $totalCapacity = array_sum($capacities);

        $distribution = [];
        foreach ($capacities as $id => $capacity) {
            $distribution[$id] = $totalCapacity > 0 ? round($capacity / $totalCapacity, 4) : 0.0;
        }

        $shares = array_values($distribution);
        $fairnessScore = $shares === [] ? 1.0 : round(max(0.0, 1.0 - (max($shares) - min($shares))), 4);

        $overloadWarnings = [];
        $nextTaskFamilyPreferences = [];
        foreach ($facts as $id => $fact) {
            if ($fact['active_lease_count'] > self::OVERLOADED_LEASE_COUNT) {
                $overloadWarnings[] = "overloaded_lease_count:{$id}";
            }
            if (($distribution[$id] ?? 0.0) > self::OVERLOADED_SHARE) {
                $overloadWarnings[] = "overloaded_share:{$id}";
            }
            if ($fact['give_back_rate'] > self::HIGH_GIVE_BACK_RATE) {
                $overloadWarnings[] = "high_give_back_rate:{$id}";
            }

            $proven = $fact['reliability_score'] >= self::PROVEN_RELIABILITY_THRESHOLD
                && $fact['give_back_rate'] < self::PROVEN_GIVE_BACK_RATE_THRESHOLD;
            $nextTaskFamilyPreferences[$id] = $proven ? 'high_risk_eligible' : 'low_risk_only';
        }

        return [
            'schema' => self::SCHEMA,
            'recommended_distribution' => $distribution,
            'fairness_score' => $fairnessScore,
            'overload_warnings' => $overloadWarnings,
            'next_task_family_preferences' => $nextTaskFamilyPreferences,
        ];
    }
}
