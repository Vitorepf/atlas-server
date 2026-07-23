<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure executive proof report: composes fitness, risk, proof-debt, outcome and queue FACTS
 * into a go/hold/repair recommendation an operator or future brain session can act on without
 * reading every organ's raw output — and, critically, WHY (exact blockers) and what to do next
 * (next_batch_focus), never just a metrics summary.
 *
 * DECISION (first matching rule wins):
 *   1. repair — proof_debt.missing_proof_count > 0 OR risk.high_risk_open_count > 0. Never GO
 *      while proof is owed or a high-risk item sits open — those must close first.
 *   2. hold   — outcomes.give_back_rate >= GIVE_BACK_RATE_HOLD_THRESHOLD, OR
 *               fitness.trend === 'declining', OR queue.claimable_count === 0.
 *   3. go     — every section is clean.
 *
 * Pure. No I/O, no provider calls, deterministic.
 */
final class AtlasExternalBrainSimplificationOperatorReport
{
    public const SCHEMA = 'atlas.external_brain.simplification_operator_report.v1';

    public const RECOMMENDATION_GO = 'go';

    public const RECOMMENDATION_HOLD = 'hold';

    public const RECOMMENDATION_REPAIR = 'repair';

    private const GIVE_BACK_RATE_HOLD_THRESHOLD = 0.30;

    /**
     * @param  array{
     *   fitness?: array{score?:float, trend?:string},
     *   risk?: array{high_risk_open_count?:int, blockers?:list<string>},
     *   proof_debt?: array{missing_proof_count?:int, items?:list<string>},
     *   outcomes?: array{give_back_rate?:float, recent_failures?:list<string>},
     *   queue?: array{claimable_count?:int, stale_count?:int},
     * }  $input
     * @return array<string,mixed>
     */
    public function compose(array $input): array
    {
        $fitness = is_array($input['fitness'] ?? null) ? $input['fitness'] : [];
        $risk = is_array($input['risk'] ?? null) ? $input['risk'] : [];
        $proofDebt = is_array($input['proof_debt'] ?? null) ? $input['proof_debt'] : [];
        $outcomes = is_array($input['outcomes'] ?? null) ? $input['outcomes'] : [];
        $queue = is_array($input['queue'] ?? null) ? $input['queue'] : [];

        $fitnessScore = max(0.0, min(1.0, (float) ($fitness['score'] ?? 0.0)));
        $fitnessTrend = strtolower(trim((string) ($fitness['trend'] ?? 'flat')));

        $highRiskOpenCount = max(0, (int) ($risk['high_risk_open_count'] ?? 0));
        $riskBlockers = array_values(array_map('strval', (array) ($risk['blockers'] ?? [])));

        $missingProofCount = max(0, (int) ($proofDebt['missing_proof_count'] ?? 0));
        $proofDebtItems = array_values(array_map('strval', (array) ($proofDebt['items'] ?? [])));

        $giveBackRate = max(0.0, min(1.0, (float) ($outcomes['give_back_rate'] ?? 0.0)));
        $recentFailures = array_values(array_map('strval', (array) ($outcomes['recent_failures'] ?? [])));

        $claimableCount = max(0, (int) ($queue['claimable_count'] ?? 0));
        $staleCount = max(0, (int) ($queue['stale_count'] ?? 0));

        $blockers = [];
        if ($missingProofCount > 0) {
            $blockers[] = 'missing_proof:'.$missingProofCount;
            foreach ($proofDebtItems as $item) {
                $blockers[] = 'missing_proof_item:'.$item;
            }
        }
        if ($highRiskOpenCount > 0) {
            $blockers[] = 'high_risk_open:'.$highRiskOpenCount;
            foreach ($riskBlockers as $b) {
                $blockers[] = 'risk_blocker:'.$b;
            }
        }
        if ($giveBackRate >= self::GIVE_BACK_RATE_HOLD_THRESHOLD) {
            $blockers[] = 'give_back_rate_high:'.$giveBackRate;
            foreach ($recentFailures as $f) {
                $blockers[] = 'recent_failure:'.$f;
            }
        }
        if ($fitnessTrend === 'declining') {
            $blockers[] = 'fitness_declining';
        }
        if ($claimableCount === 0) {
            $blockers[] = 'queue_empty';
        }
        if ($staleCount > 0) {
            $blockers[] = 'queue_stale:'.$staleCount;
        }

        $recommendation = match (true) {
            $missingProofCount > 0 || $highRiskOpenCount > 0 => self::RECOMMENDATION_REPAIR,
            $giveBackRate >= self::GIVE_BACK_RATE_HOLD_THRESHOLD || $fitnessTrend === 'declining' || $claimableCount === 0 => self::RECOMMENDATION_HOLD,
            default => self::RECOMMENDATION_GO,
        };

        $nextBatchFocus = $this->nextBatchFocus($missingProofCount, $highRiskOpenCount, $giveBackRate, $fitnessTrend, $claimableCount);

        return [
            'schema' => self::SCHEMA,
            'recommendation' => $recommendation,
            'blockers' => $blockers,
            'next_batch_focus' => $nextBatchFocus,
            'fitness' => ['score' => $fitnessScore, 'trend' => $fitnessTrend],
            'risk' => ['high_risk_open_count' => $highRiskOpenCount, 'blockers' => $riskBlockers],
            'proof_debt' => ['missing_proof_count' => $missingProofCount, 'items' => $proofDebtItems],
            'outcomes' => ['give_back_rate' => $giveBackRate, 'recent_failures' => $recentFailures],
            'queue' => ['claimable_count' => $claimableCount, 'stale_count' => $staleCount],
        ];
    }

    private function nextBatchFocus(int $missingProofCount, int $highRiskOpenCount, float $giveBackRate, string $fitnessTrend, int $claimableCount): string
    {
        return match (true) {
            $missingProofCount > 0 => 'close_proof_debt_before_next_batch',
            $highRiskOpenCount > 0 => 'resolve_high_risk_blockers_before_next_batch',
            $giveBackRate >= self::GIVE_BACK_RATE_HOLD_THRESHOLD => 'reduce_give_back_rate_before_accelerating',
            $fitnessTrend === 'declining' => 'investigate_fitness_decline_before_accelerating',
            $claimableCount === 0 => 'replenish_queue_before_accelerating',
            default => 'accelerate_next_batch',
        };
    }
}
