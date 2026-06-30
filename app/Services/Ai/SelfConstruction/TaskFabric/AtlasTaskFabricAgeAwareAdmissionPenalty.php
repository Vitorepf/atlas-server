<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\TaskFabric;

/**
 * Pure admission-penalty gate — Task Fabric consults this BEFORE admitting a proposed task batch so the
 * external brain cannot answer a deep, stale claimable backlog with MORE backlog unless the new batch is
 * provably higher leverage or directly repairs the stale condition itself.
 *
 * INPUT:
 *   $batch = { batch_id, repairs_queue_health?:bool, repairs_blocked_backlog?:bool,
 *              repairs_lease_parity?:bool, repairs_stale_drain?:bool, leverage_score:float (0..10) }
 *   $queueFacts = { queue_age:{p95_age_hours:float}, claimable_depth:int, servable_now:int,
 *                   worker_consumption?:{active_workers?:int} }
 *
 * DECISION (first match wins):
 *   admit              — batch directly repairs queue_health/blocked_backlog/lease_parity/stale_drain,
 *                         OR backlog is not deep+stale to begin with.
 *   admit_with_penalty — backlog is deep+stale, but leverage_score is HIGH (>= 8) — still let it in,
 *                         carrying a penalty_score for downstream prioritization.
 *   defer              — backlog is deep+stale, leverage_score is MEDIUM (4..8) — wait for backlog to drain.
 *   reject_padding      — backlog is deep+stale, leverage_score is LOW (< 4) — this is quota-farming.
 *
 * DEEP+STALE: claimable_depth >= DEEP_BACKLOG_THRESHOLD AND queue_age.p95_age_hours >= STALE_AGE_HOURS.
 *
 * OUTPUT: { schema, decision, penalty_score:float, reasons:list<string> }
 *
 * Pure: no enqueue, no queue mutation, no provider call, no command, no file write, no git.
 */
final class AtlasTaskFabricAgeAwareAdmissionPenalty
{
    public const SCHEMA = 'atlas.self_construction.task_fabric.age_aware_admission_penalty.v1';

    public const DECISION_ADMIT = 'admit';

    public const DECISION_ADMIT_WITH_PENALTY = 'admit_with_penalty';

    public const DECISION_DEFER = 'defer';

    public const DECISION_REJECT_PADDING = 'reject_padding';

    private const DEEP_BACKLOG_THRESHOLD = 30;

    private const STALE_AGE_HOURS = 24.0;

    private const HIGH_LEVERAGE_THRESHOLD = 8.0;

    private const MEDIUM_LEVERAGE_THRESHOLD = 4.0;

    /**
     * @param  array<string,mixed>  $batch
     * @param  array<string,mixed>  $queueFacts
     * @return array{schema:string, decision:string, penalty_score:float, reasons:list<string>}
     */
    public function evaluate(array $batch, array $queueFacts): array
    {
        $leverageScore = max(0.0, (float) ($batch['leverage_score'] ?? 0.0));
        $repairsStaleBacklog = (bool) ($batch['repairs_queue_health'] ?? false)
            || (bool) ($batch['repairs_blocked_backlog'] ?? false)
            || (bool) ($batch['repairs_lease_parity'] ?? false)
            || (bool) ($batch['repairs_stale_drain'] ?? false);

        $claimableDepth = max(0, (int) ($queueFacts['claimable_depth'] ?? 0));
        $p95AgeHours = max(0.0, (float) (is_array($queueFacts['queue_age'] ?? null) ? ($queueFacts['queue_age']['p95_age_hours'] ?? 0.0) : 0.0));

        $isDeepAndStale = $claimableDepth >= self::DEEP_BACKLOG_THRESHOLD && $p95AgeHours >= self::STALE_AGE_HOURS;

        if ($repairsStaleBacklog) {
            return $this->result(self::DECISION_ADMIT, 0.0, ['repairs_stale_backlog_directly']);
        }

        if (! $isDeepAndStale) {
            return $this->result(self::DECISION_ADMIT, 0.0, ['backlog_not_deep_or_stale']);
        }

        $penaltyScore = $this->penaltyScore($claimableDepth, $p95AgeHours, $leverageScore);

        if ($leverageScore >= self::HIGH_LEVERAGE_THRESHOLD) {
            return $this->result(self::DECISION_ADMIT_WITH_PENALTY, $penaltyScore, [
                'deep_stale_backlog_detected',
                sprintf('high_leverage_score=%.1f_overrides_penalty', $leverageScore),
            ]);
        }

        if ($leverageScore >= self::MEDIUM_LEVERAGE_THRESHOLD) {
            return $this->result(self::DECISION_DEFER, $penaltyScore, [
                'deep_stale_backlog_detected',
                sprintf('medium_leverage_score=%.1f_deferred_until_backlog_drains', $leverageScore),
            ]);
        }

        return $this->result(self::DECISION_REJECT_PADDING, $penaltyScore, [
            'deep_stale_backlog_detected',
            sprintf('low_leverage_score=%.1f_treated_as_padding', $leverageScore),
        ]);
    }

    private function penaltyScore(int $claimableDepth, float $p95AgeHours, float $leverageScore): float
    {
        $backlogPressure = min(1.0, $claimableDepth / (self::DEEP_BACKLOG_THRESHOLD * 2));
        $agePressure = min(1.0, $p95AgeHours / (self::STALE_AGE_HOURS * 2));
        $leverageOffset = max(0.0, 1.0 - $leverageScore / 10.0);

        return round((($backlogPressure + $agePressure) / 2) * $leverageOffset, 2);
    }

    /**
     * @param  list<string>  $reasons
     * @return array{schema:string, decision:string, penalty_score:float, reasons:list<string>}
     */
    private function result(string $decision, float $penaltyScore, array $reasons): array
    {
        return [
            'schema' => self::SCHEMA,
            'decision' => $decision,
            'penalty_score' => $penaltyScore,
            'reasons' => $reasons,
        ];
    }
}
