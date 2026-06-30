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
 *   2. within tier: expected_unblocked DESC, waste_reduction_score DESC, packet_count DESC
 *
 * This guarantees high-confidence replaceable families outrank manual-review unknowns, and
 * repeated-give-back poison outranks cosmetic low-impact repairs (within the retire tier).
 *
 * OUTPUT: { schema, ranked_actions:list<{action, family, packet_count, expected_unblocked,
 *   waste_reduction_score, risk, reason_codes}> }
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

    /**
     * @param  list<array<string,mixed>>  $families
     * @return array{schema:string, ranked_actions:list<array<string,mixed>>}
     */
    public function rank(array $families): array
    {
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

            $reasonCodes = [];
            if ($canSubmitReplacement && in_array($confidence, ['high', 'medium'], true)) {
                $action = self::ACTION_RESPEC_AND_RESUBMIT;
                $reasonCodes[] = 'can_submit_replacement_available';
                $reasonCodes[] = $confidence.'_confidence_classification';
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

            $rankedActions[] = [
                'action' => $action,
                'family' => $family,
                'packet_count' => $packetCount,
                'expected_unblocked' => $expectedUnblocked,
                'waste_reduction_score' => $giveBackTotal,
                'risk' => $risk,
                'reason_codes' => $reasonCodes,
            ];
        }

        usort($rankedActions, static function (array $a, array $b): int {
            $ta = self::ACTION_TIER[$a['action']] ?? 99;
            $tb = self::ACTION_TIER[$b['action']] ?? 99;

            return $ta <=> $tb
                ?: $b['expected_unblocked'] <=> $a['expected_unblocked']
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
