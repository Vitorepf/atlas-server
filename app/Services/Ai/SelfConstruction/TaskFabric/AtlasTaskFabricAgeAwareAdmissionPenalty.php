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
 *              repairs_lease_parity?:bool, repairs_stale_drain?:bool, leverage_score:float (0..10),
 *              context_age_hours?:float, queued_sibling_count?:int, requeue_count?:int,
 *              evidence_age_hours?:float, freshly_revalidated?:bool, has_current_proof?:bool,
 *              has_collision?:bool }
 *   $queueFacts = { queue_age:{p95_age_hours:float}, claimable_depth:int, servable_now:int,
 *                   worker_consumption?:{active_workers?:int, drain_slope?:float},
 *                   claimable_per_active_worker?:float }
 *
 * DECISION (first match wins):
 *   admit              — batch directly repairs queue_health/blocked_backlog/lease_parity/stale_drain,
 *                         OR backlog is not deep+stale AND no age penalty factors.
 *   admit_with_penalty — backlog is deep+stale, but leverage_score is HIGH (>= 8) — still let it in,
 *                         carrying a penalty_score for downstream prioritization.
 *                         Also used when age penalty factors are present without deep+stale backlog.
 *   defer              — backlog is deep+stale, leverage_score is MEDIUM (4..8) — wait for backlog to drain.
 *   reject_padding      — backlog is deep+stale, leverage_score is LOW (< 4) — this is quota-farming.
 *
 * DEEP+STALE: claimable_depth >= DEEP_BACKLOG_THRESHOLD AND queue_age.p95_age_hours >= STALE_AGE_HOURS.
 *
 * AGE PENALTY FACTORS (evaluated independently of deep+stale backlog):
 *   stale_context          — context_age_hours > STALE_CONTEXT_HOURS
 *   old_queued_siblings    — queued_sibling_count > OLD_SIBLING_THRESHOLD
 *   repeated_requeues      — requeue_count > REQUEUE_REPEAT_THRESHOLD
 *   stale_evidence         — evidence_age_hours > STALE_EVIDENCE_HOURS
 *   low_claimable_per_worker — saturation signal from worker_consumption (only when deep+stale)
 *
 * REVALIDATION BYPASS: when freshly_revalidated=true AND has_current_proof=true AND has_collision=false,
 * the age penalty factors (stale_context, old_queued_siblings, repeated_requeues, stale_evidence) are
 * bypassed — an old candidate that was just re-proven does not get penalized for its age. Saturation
 * is never bypassed (it reflects current worker capacity, not candidate age).
 *
 * OUTPUT: { schema, decision, penalty_score:float, reasons:list<string>, required_refresh_action:string }
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

    /** Below this drain slope, workers are consuming claimable work slower than it accumulates. */
    private const SATURATION_DRAIN_SLOPE_THRESHOLD = 0.5;

    /** Above this claimable-per-worker ratio, the existing workforce cannot realistically absorb the backlog. */
    private const SATURATION_CLAIMABLE_PER_WORKER_THRESHOLD = 5.0;

    /** Context older than this (hours) is stale and penalized. */
    private const STALE_CONTEXT_HOURS = 48.0;

    /** Evidence older than this (hours) is stale and penalized. */
    private const STALE_EVIDENCE_HOURS = 48.0;

    /** More than this many queued siblings makes a batch "old / crowded". */
    private const OLD_SIBLING_THRESHOLD = 5;

    /** More than this many requeues indicates a stuck/repeating batch. */
    private const REQUEUE_REPEAT_THRESHOLD = 2;

    private const FACTOR_STALE_CONTEXT = 'stale_context';

    private const FACTOR_OLD_QUEUED_SIBLINGS = 'old_queued_siblings';

    private const FACTOR_REPEATED_REQUEUES = 'repeated_requeues';

    private const FACTOR_STALE_EVIDENCE = 'stale_evidence';

    private const FACTOR_LOW_CLAIMABLE_PER_WORKER = 'low_claimable_per_worker';

    /** Age factors that the revalidation bypass can suppress. */
    private const REVALIDATION_BYPASSABLE_FACTORS = [
        self::FACTOR_STALE_CONTEXT,
        self::FACTOR_OLD_QUEUED_SIBLINGS,
        self::FACTOR_REPEATED_REQUEUES,
        self::FACTOR_STALE_EVIDENCE,
    ];

    private const REFRESH_NONE = 'none';

    private const REFRESH_CONTEXT = 'refresh_context_before_admission';

    private const REFRESH_EVIDENCE = 'refresh_evidence_before_admission';

    private const REFRESH_SIBLINGS = 'drain_or_consolidate_queued_siblings';

    private const REFRESH_REQUEUE_ROOT_CAUSE = 'resolve_root_cause_of_repeated_requeues';

    private const REFRESH_WORKER_CAPACITY = 'add_workers_or_reduce_backlog_depth';

    /**
     * @param  array<string,mixed>  $batch
     * @param  array<string,mixed>  $queueFacts
     * @return array{schema:string, decision:string, penalty_score:float, reasons:list<string>, required_refresh_action:string}
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

        $ageFactors = $this->agePenaltyFactors($batch);

        if ($repairsStaleBacklog) {
            return $this->result(self::DECISION_ADMIT, 0.0, ['repairs_stale_backlog_directly'], self::REFRESH_NONE);
        }

        // Age penalty factors present without deep+stale backlog still carry a penalty signal.
        if (! $isDeepAndStale) {
            if ($ageFactors === []) {
                return $this->result(self::DECISION_ADMIT, 0.0, ['backlog_not_deep_or_stale'], self::REFRESH_NONE);
            }

            // Only age factors are active — admit with penalty + refresh action.
            $penaltyScore = $this->penaltyScore($claimableDepth, $p95AgeHours, $leverageScore, $ageFactors, false);
            $reasons = $this->allReasons(false, $ageFactors, [], $batch);
            $refreshAction = $this->requiredRefreshAction($ageFactors, false, false);

            return $this->result(self::DECISION_ADMIT_WITH_PENALTY, $penaltyScore, $reasons, $refreshAction);
        }

        // Deep+stale backlog: compute saturation reasons + full penalty.
        $saturationReasons = $this->saturationReasons($queueFacts, $claimableDepth);
        $hasSaturation = $saturationReasons !== [];

        $penaltyScore = $this->penaltyScore($claimableDepth, $p95AgeHours, $leverageScore, $ageFactors, $hasSaturation);
        $reasons = $this->allReasons(true, $ageFactors, $saturationReasons, $batch);
        $refreshAction = $this->requiredRefreshAction($ageFactors, $hasSaturation, true);

        if ($leverageScore >= self::HIGH_LEVERAGE_THRESHOLD) {
            $reasons[] = sprintf('high_leverage_score=%.1f_overrides_penalty', $leverageScore);

            return $this->result(self::DECISION_ADMIT_WITH_PENALTY, $penaltyScore, $reasons, $refreshAction);
        }

        if ($leverageScore >= self::MEDIUM_LEVERAGE_THRESHOLD) {
            $reasons[] = sprintf('medium_leverage_score=%.1f_deferred_until_backlog_drains', $leverageScore);

            return $this->result(self::DECISION_DEFER, $penaltyScore, $reasons, $refreshAction);
        }

        $reasons[] = sprintf('low_leverage_score=%.1f_treated_as_padding', $leverageScore);

        return $this->result(self::DECISION_REJECT_PADDING, $penaltyScore, $reasons, $refreshAction);
    }

    /**
     * Compute age-related penalty factors, applying the revalidation bypass.
     *
     * @param  array<string,mixed>  $batch
     * @return list<string>
     */
    private function agePenaltyFactors(array $batch): array
    {
        $freshlyRevalidated = (bool) ($batch['freshly_revalidated'] ?? false);
        $hasCurrentProof = (bool) ($batch['has_current_proof'] ?? false);
        $hasCollision = (bool) ($batch['has_collision'] ?? false);
        $bypassApplies = $freshlyRevalidated && $hasCurrentProof && ! $hasCollision;

        $factors = [];

        $contextAge = max(0.0, (float) ($batch['context_age_hours'] ?? 0.0));
        if ($contextAge > self::STALE_CONTEXT_HOURS) {
            $factors[] = self::FACTOR_STALE_CONTEXT;
        }

        $queuedSiblings = max(0, (int) ($batch['queued_sibling_count'] ?? 0));
        if ($queuedSiblings > self::OLD_SIBLING_THRESHOLD) {
            $factors[] = self::FACTOR_OLD_QUEUED_SIBLINGS;
        }

        $requeueCount = max(0, (int) ($batch['requeue_count'] ?? 0));
        if ($requeueCount > self::REQUEUE_REPEAT_THRESHOLD) {
            $factors[] = self::FACTOR_REPEATED_REQUEUES;
        }

        $evidenceAge = max(0.0, (float) ($batch['evidence_age_hours'] ?? 0.0));
        if ($evidenceAge > self::STALE_EVIDENCE_HOURS) {
            $factors[] = self::FACTOR_STALE_EVIDENCE;
        }

        if ($bypassApplies) {
            $factors = array_values(array_filter(
                $factors,
                fn (string $f): bool => ! in_array($f, self::REVALIDATION_BYPASSABLE_FACTORS, true),
            ));
        }

        return $factors;
    }

    /**
     * @param  array<string,mixed>  $queueFacts
     * @return list<string>
     */
    private function saturationReasons(array $queueFacts, int $claimableDepth): array
    {
        $workerConsumption = is_array($queueFacts['worker_consumption'] ?? null) ? $queueFacts['worker_consumption'] : [];
        $activeWorkers = max(0, (int) ($workerConsumption['active_workers'] ?? 0));
        $reasons = [];

        if (array_key_exists('drain_slope', $workerConsumption)) {
            $drainSlope = (float) $workerConsumption['drain_slope'];
            if ($drainSlope <= self::SATURATION_DRAIN_SLOPE_THRESHOLD) {
                $reasons[] = sprintf('worker_drain_slope=%.2f_indicates_saturation', $drainSlope);
            }
        }

        $claimablePerActiveWorker = array_key_exists('claimable_per_active_worker', $queueFacts)
            ? (float) $queueFacts['claimable_per_active_worker']
            : ($activeWorkers > 0 ? $claimableDepth / $activeWorkers : ($claimableDepth > 0 ? INF : null));
        if ($claimablePerActiveWorker !== null && $claimablePerActiveWorker >= self::SATURATION_CLAIMABLE_PER_WORKER_THRESHOLD) {
            $reasons[] = sprintf('claimable_per_active_worker=%.2f_indicates_saturation', $claimablePerActiveWorker);
        }

        return $reasons;
    }

    private function penaltyScore(
        int $claimableDepth,
        float $p95AgeHours,
        float $leverageScore,
        array $ageFactors,
        bool $hasSaturation,
    ): float {
        $backlogPressure = min(1.0, $claimableDepth / (self::DEEP_BACKLOG_THRESHOLD * 2));
        $agePressure = min(1.0, $p95AgeHours / (self::STALE_AGE_HOURS * 2));
        $leverageOffset = max(0.0, 1.0 - $leverageScore / 10.0);

        $factorPressure = min(1.0, count($ageFactors) / 4.0);
        $saturationPressure = $hasSaturation ? 0.3 : 0.0;

        $raw = (($backlogPressure + $agePressure) / 2) * $leverageOffset;
        $raw += ($factorPressure + $saturationPressure) * $leverageOffset * 0.5;

        return round(min(1.0, $raw), 2);
    }

    /**
     * @param  list<string>  $ageFactors
     * @param  list<string>  $saturationReasons
     * @param  array<string,mixed>  $batch
     * @return list<string>
     */
    private function allReasons(bool $isDeepAndStale, array $ageFactors, array $saturationReasons, array $batch): array
    {
        $reasons = [];
        if ($isDeepAndStale) {
            $reasons[] = 'deep_stale_backlog_detected';
        }

        $contextAge = max(0.0, (float) ($batch['context_age_hours'] ?? 0.0));
        $queuedSiblings = max(0, (int) ($batch['queued_sibling_count'] ?? 0));
        $requeueCount = max(0, (int) ($batch['requeue_count'] ?? 0));
        $evidenceAge = max(0.0, (float) ($batch['evidence_age_hours'] ?? 0.0));

        foreach ($ageFactors as $factor) {
            $reasons[] = match ($factor) {
                self::FACTOR_STALE_CONTEXT => sprintf('stale_context_age=%.1fh', $contextAge),
                self::FACTOR_OLD_QUEUED_SIBLINGS => sprintf('old_queued_siblings=%d', $queuedSiblings),
                self::FACTOR_REPEATED_REQUEUES => sprintf('repeated_requeues=%d', $requeueCount),
                self::FACTOR_STALE_EVIDENCE => sprintf('stale_evidence_age=%.1fh', $evidenceAge),
                default => $factor,
            };
        }

        foreach ($saturationReasons as $sr) {
            $reasons[] = $sr;
        }

        return $reasons;
    }

    /**
     * Derive the required refresh action from the active penalty factors.
     *
     * @param  list<string>  $ageFactors
     */
    private function requiredRefreshAction(array $ageFactors, bool $hasSaturation, bool $isDeepAndStale): string
    {
        // Priority: requeue root cause > stale context > stale evidence > siblings > saturation > backlog > none.
        if (in_array(self::FACTOR_REPEATED_REQUEUES, $ageFactors, true)) {
            return self::REFRESH_REQUEUE_ROOT_CAUSE;
        }
        if (in_array(self::FACTOR_STALE_CONTEXT, $ageFactors, true)) {
            return self::REFRESH_CONTEXT;
        }
        if (in_array(self::FACTOR_STALE_EVIDENCE, $ageFactors, true)) {
            return self::REFRESH_EVIDENCE;
        }
        if (in_array(self::FACTOR_OLD_QUEUED_SIBLINGS, $ageFactors, true)) {
            return self::REFRESH_SIBLINGS;
        }
        if ($hasSaturation || $isDeepAndStale) {
            return self::REFRESH_WORKER_CAPACITY;
        }

        return self::REFRESH_NONE;
    }

    /**
     * @param  list<string>  $reasons
     * @return array{schema:string, decision:string, penalty_score:float, reasons:list<string>, required_refresh_action:string}
     */
    private function result(string $decision, float $penaltyScore, array $reasons, string $refreshAction): array
    {
        return [
            'schema' => self::SCHEMA,
            'decision' => $decision,
            'penalty_score' => $penaltyScore,
            'reasons' => $reasons,
            'required_refresh_action' => $refreshAction,
        ];
    }
}
