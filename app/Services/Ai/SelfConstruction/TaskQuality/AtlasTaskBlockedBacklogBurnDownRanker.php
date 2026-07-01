<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\TaskQuality;

/**
 * Pure ranker — turns blocked-packet family aggregates (the output shape produced by classifying
 * blocked packets, e.g. {@see AtlasTaskBlockedUnknownFamilyExplainer}) into a PRIORITIZED burn-down
 * plan, so the autonomous loop acts on the highest-leverage blocked family first instead of staring
 * at a single flat `blocked_count` with no sense of what to do next.
 *
 * INPUT per family aggregate:
 *   { family:string, packet_count:int, give_back_total:int, recovered_field_confidence:'high'|'medium'|'low',
 *     can_submit_replacement:bool, target_criticality:'high'|'medium'|'low', implementation_risk:'high'|'medium'|'low' }
 *
 * ACTION derivation (per family):
 *   respec_and_resubmit — can_submit_replacement===true AND confidence is high|medium
 *   retire              — give_back_total >= POISON_THRESHOLD AND no usable replacement
 *   manual_review        — otherwise (low confidence, no replacement, low give_back)
 *
 * RANKING (best leverage first):
 *   1. action tier: respec_and_resubmit > retire > manual_review
 *   2. within tier: combined_score DESC, waste_reduction_score DESC, packet_count DESC
 *      combined_score = expected_unblocked (recovered_claimable_value) + leverage * LEVERAGE_WEIGHT
 *      — a small family with high leverage (unblocks a lot of downstream real work) can outrank
 *      a larger, low-leverage family within the same tier.
 *
 * This guarantees high-confidence replaceable families outrank manual-review unknowns, and
 * repeated-give-back poison outranks cosmetic low-impact repairs (within the retire tier).
 *
 * OUTPUT: { schema, ranked_actions:list<{action, family, packet_count, expected_unblocked,
 *   recovered_claimable_value, leverage, avoided_token_waste, waste_reduction_score,
 *   worker_waste_pressure, downstream_unlock_score, combined_score, risk, reason_codes}> }
 *
 * Pure: read-only, deterministic, no provider calls, no queue/git mutation.
 */
final class AtlasTaskBlockedBacklogBurnDownRanker
{
    public const SCHEMA = 'atlas.task_quality.blocked_backlog_burn_down_ranker.v1';

    public const ACTION_RESPEC_AND_RESUBMIT = 'respec_and_resubmit';

    public const ACTION_RETIRE = 'retire';

    public const ACTION_MANUAL_REVIEW = 'manual_review';

    private const ACTION_TIER = [
        self::ACTION_RESPEC_AND_RESUBMIT => 0,
        self::ACTION_RETIRE => 1,
        self::ACTION_MANUAL_REVIEW => 2,
    ];

    private const POISON_GIVE_BACK_THRESHOLD = 5;

    private const RISK_RANK = ['low' => 0, 'medium' => 1, 'high' => 2];

    /** Weight applied to leverage when ranking within a tier — a small family that unlocks a lot
     *  of downstream real work can outrank a larger, low-leverage family. */
    private const LEVERAGE_WEIGHT = 5.0;

    /** Weight applied to worker_waste_pressure — active workers stuck re-serving the same
     *  blocked family are burning real muscle time, so that pressure earns ranking priority
     *  even when the family itself is small. */
    private const WORKER_WASTE_WEIGHT = 3.0;

    /** Weight applied to downstream_unlocks — a family that gates many other packets is worth
     *  clearing before a larger family that unlocks nothing downstream. */
    private const DOWNSTREAM_UNLOCK_WEIGHT = 2.0;

    /** Rough tokens wasted per repeated give_back cycle; used only to produce a relative
     *  avoided_token_waste metric, not an absolute cost figure. */
    private const TOKEN_WASTE_PER_GIVE_BACK = 1500.0;

    /**
     * @param  list<array<string,mixed>>  $families
     * @param  array<string,mixed>  $context  optional live worker-floor facts:
     *         worker_floor_low?: bool  — the live worker floor is currently low; high-confidence
     *                                    respec families carry extra urgency (worker_feed_opportunity)
     *                                    over cosmetic retire/manual-review actions
     * @return array{schema:string, ranked_actions:list<array<string,mixed>>}
     */
    public function rank(array $families, array $context = []): array
    {
        $workerFloorLow = (bool) ($context['worker_floor_low'] ?? false);
        $rankedActions = [];

        foreach ($families as $f) {
            if (! is_array($f)) {
                continue;
            }
            $family = (string) ($f['family'] ?? '');
            if ($family === '') {
                continue;
            }
            $packetCount = max(0, (int) ($f['packet_count'] ?? 0));
            $giveBackTotal = max(0, (int) ($f['give_back_total'] ?? 0));
            $confidence = (string) ($f['recovered_field_confidence'] ?? 'low');
            $canSubmitReplacement = (bool) ($f['can_submit_replacement'] ?? false);
            $targetCriticality = (string) ($f['target_criticality'] ?? 'low');
            $implementationRisk = (string) ($f['implementation_risk'] ?? 'low');
            $leverage = max(0.0, (float) ($f['leverage'] ?? 0.0));
            $activeWorkersBlocked = max(0, (int) ($f['active_workers_blocked'] ?? 0));
            $reServeRate = max(0.0, (float) ($f['re_serve_rate'] ?? 0.0));
            $downstreamUnlocks = max(0, (int) ($f['downstream_unlocks'] ?? 0));

            $reasonCodes = [];
            if ($canSubmitReplacement && in_array($confidence, ['high', 'medium'], true)) {
                $action = self::ACTION_RESPEC_AND_RESUBMIT;
                $reasonCodes[] = 'can_submit_replacement_available';
                $reasonCodes[] = $confidence.'_confidence_classification';
                if ($workerFloorLow) {
                    $reasonCodes[] = 'worker_feed_opportunity';
                }
                $expectedUnblocked = $packetCount;
            } elseif ($giveBackTotal >= self::POISON_GIVE_BACK_THRESHOLD && ! $canSubmitReplacement) {
                $action = self::ACTION_RETIRE;
                $reasonCodes[] = 'repeated_give_back_waste';
                $reasonCodes[] = 'no_replacement_available';
                $expectedUnblocked = 0;
            } else {
                $action = self::ACTION_MANUAL_REVIEW;
                $reasonCodes[] = $confidence.'_confidence_classification';
                $reasonCodes[] = 'manual_review_required';
                $expectedUnblocked = 0;
            }

            // Retiring a critical target is riskier than its raw implementation_risk suggests.
            $risk = ($action === self::ACTION_RETIRE && $targetCriticality === 'high') ? 'high' : $implementationRisk;

            $avoidedTokenWaste = round($giveBackTotal * self::TOKEN_WASTE_PER_GIVE_BACK, 2);
            // Active workers stuck repeatedly re-serving the same blocked family are burning
            // real muscle time — that pressure grows with both how many workers are stuck AND
            // how often they keep re-serving it.
            $workerWastePressure = round($activeWorkersBlocked * (1.0 + $reServeRate), 4);
            // Downstream unlocks: how much other real work this family gates.
            $downstreamUnlockScore = round($downstreamUnlocks * self::DOWNSTREAM_UNLOCK_WEIGHT, 4);
            // Leverage/waste/unlock-weighted score: a small family that burns worker time or
            // unlocks a lot of downstream real work can outrank a larger, low-leverage family
            // within the same action tier.
            $combinedScore = round(
                $expectedUnblocked
                + $leverage * self::LEVERAGE_WEIGHT
                + $workerWastePressure * self::WORKER_WASTE_WEIGHT
                + $downstreamUnlockScore,
                4,
            );

            $rankedActions[] = [
                'action' => $action,
                'family' => $family,
                'packet_count' => $packetCount,
                'expected_unblocked' => $expectedUnblocked,
                'recovered_claimable_value' => $expectedUnblocked,
                'leverage' => $leverage,
                'avoided_token_waste' => $avoidedTokenWaste,
                'waste_reduction_score' => $giveBackTotal,
                'worker_waste_pressure' => $workerWastePressure,
                'downstream_unlock_score' => $downstreamUnlockScore,
                'combined_score' => $combinedScore,
                'risk' => $risk,
                'reason_codes' => $reasonCodes,
            ];
        }

        usort($rankedActions, static function (array $a, array $b): int {
            $ta = self::ACTION_TIER[$a['action']] ?? 99;
            $tb = self::ACTION_TIER[$b['action']] ?? 99;

            return $ta <=> $tb
                ?: $b['combined_score'] <=> $a['combined_score']
                ?: $b['waste_reduction_score'] <=> $a['waste_reduction_score']
                ?: $b['packet_count'] <=> $a['packet_count']
                ?: strcmp($a['family'], $b['family']);
        });

        return [
            'schema' => self::SCHEMA,
            'ranked_actions' => $rankedActions,
        ];
    }
}
