<?php

namespace App\Services\Ai\Autonomy;

/**
 * Aggregates raw autonomy signals into the metric map consumed by
 * AtlasAutonomyLadderRuntimeService::evaluatePromotion. Deterministic and pure:
 * known scalar signals pass through; a few rates are derived from counts so the
 * ladder can be evaluated from raw cycle data. The wiring of real ledgers into
 * the raw signal feed is the next slice; this aggregator is the contract.
 *
 * @see docs/engineering-knowledge-base/atlas-autonomy-ladder-promotion-runbook.md
 */
class AtlasAutonomyMetricsAggregator
{
    /**
     * Scalar signals carried straight into the metric map.
     *
     * @var array<int,string>
     */
    private const PASSTHROUGH = [
        'assist_sessions', 'severe_hallucination_count',
        'consecutive_green_slices', 'scope_violation_count', 'repair_loop_count',
        'green_pair_obras', 'regression_catch_rate',
        'cert_green_features', 'blocker_in_review_per_feature',
        'consecutive_cert_green_obras', 'cert_phase_rollback_count', 'dual_signature_count',
        'days_without_intervention', 'department_maturity_level',
        'days_with_3plus_departments', 'cross_dept_blocker_resolution_p95_hours',
        'approved_self_construction_proposals', 'broken_invariant_count', 'trust_ledger_score',
    ];

    /**
     * @param  array<string,mixed>  $signals
     * @return array<string,float>
     */
    public function aggregate(array $signals): array
    {
        $metrics = [];
        foreach (self::PASSTHROUGH as $key) {
            if (array_key_exists($key, $signals) && is_numeric($signals[$key])) {
                $metrics[$key] = (float) $signals[$key];
            }
        }

        // Derived: acceptance_rate from accepted/total when not provided directly.
        if (! array_key_exists('acceptance_rate', $metrics)) {
            $accepted = (float) ($signals['accepted_sessions'] ?? 0);
            $total = (float) ($signals['total_sessions'] ?? 0);
            if ($total > 0) {
                $metrics['acceptance_rate'] = round($accepted / $total, 4);
            }
        } elseif (is_numeric($signals['acceptance_rate'] ?? null)) {
            $metrics['acceptance_rate'] = (float) $signals['acceptance_rate'];
        }

        return $metrics;
    }
}
