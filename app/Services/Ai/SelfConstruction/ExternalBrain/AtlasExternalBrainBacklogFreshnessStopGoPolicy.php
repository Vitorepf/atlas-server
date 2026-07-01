<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure stop/go policy. Decides whether the External Brain should create_more, pause_origination,
 * drain_existing, repair_queue, or consolidate, based on backlog freshness and worker consumption
 * — so raw quota pressure never creates more tasks when the actual bottleneck is stale claimable
 * work sitting undrained.
 *
 * Input shape: {health_snapshot:{dry_queue?:bool, malformed_rate?:float, give_back_rate?:float},
 *               queue_age_histogram:{claimable_depth?:int, oldest_age_p95_seconds?:int, stale_threshold_seconds?:int},
 *               worker_idle_prediction:{observed_consumption_count?:int},
 *               replenish_urgency:{urgency_score?:float},
 *               proposed_batch_leverage:{fixes_bottleneck?:bool}}
 *
 * Pure deterministic policy — no queue writes, no provider calls, no process spawning, no
 * filesystem writes, no git.
 */
final class AtlasExternalBrainBacklogFreshnessStopGoPolicy
{
    public const SCHEMA = 'atlas.self_construction.external_brain.backlog_freshness_stop_go_policy.v1';

    public const DECISION_CREATE_MORE = 'create_more';

    public const DECISION_PAUSE_ORIGINATION = 'pause_origination';

    public const DECISION_DRAIN_EXISTING = 'drain_existing';

    public const DECISION_REPAIR_QUEUE = 'repair_queue';

    public const DECISION_CONSOLIDATE = 'consolidate';

    /** @var list<string> */
    private const ALL_ACTIONS = [
        self::DECISION_CREATE_MORE,
        self::DECISION_PAUSE_ORIGINATION,
        self::DECISION_DRAIN_EXISTING,
        self::DECISION_REPAIR_QUEUE,
        self::DECISION_CONSOLIDATE,
    ];

    private const HIGH_MALFORMED_THRESHOLD = 0.15;

    private const HIGH_GIVE_BACK_THRESHOLD = 0.25;

    private const HIGH_URGENCY_THRESHOLD = 0.5;

    /**
     * @param  array<string,mixed>  $facts
     * @return array{schema:string, decision:string, confidence:float, reasons:list<string>, allowed_next_actions:list<string>, blocked_next_actions:list<string>}
     */
    public function decide(array $facts): array
    {
        $health = (array) ($facts['health_snapshot'] ?? []);
        $ageHistogram = (array) ($facts['queue_age_histogram'] ?? []);
        $workerPrediction = (array) ($facts['worker_idle_prediction'] ?? []);
        $urgency = (array) ($facts['replenish_urgency'] ?? []);
        $batch = (array) ($facts['proposed_batch_leverage'] ?? []);

        $dryQueue = (bool) ($health['dry_queue'] ?? false);
        $malformedRate = max(0.0, min(1.0, (float) ($health['malformed_rate'] ?? 0.0)));
        $giveBackRate = max(0.0, min(1.0, (float) ($health['give_back_rate'] ?? 0.0)));

        $claimableDepth = max(0, (int) ($ageHistogram['claimable_depth'] ?? 0));
        $oldestAgeP95 = max(0, (int) ($ageHistogram['oldest_age_p95_seconds'] ?? 0));
        $staleThreshold = max(1, (int) ($ageHistogram['stale_threshold_seconds'] ?? 3600));

        $consumptionProvided = array_key_exists('observed_consumption_count', $workerPrediction);
        $observedConsumption = $consumptionProvided ? max(0, (int) $workerPrediction['observed_consumption_count']) : null;

        $urgencyScore = max(0.0, min(1.0, (float) ($urgency['urgency_score'] ?? 0.0)));
        $batchFixesBottleneck = (bool) ($batch['fixes_bottleneck'] ?? false);

        // A batch that directly improves Task Fabric, Queue Self-Healing, Outcome Learning,
        // Simplification, or autonomy continuity is high-leverage enough to override a stale-depth
        // wait — raw sufficient_depth alone must never be the sole justification to stop.
        $highLeverageCategories = ['task_fabric', 'queue_self_healing', 'outcome_learning', 'simplification', 'autonomy_continuity'];
        $batchImproves = is_array($batch['improves'] ?? null) ? array_map('strval', $batch['improves']) : [];
        $batchTargetsHighLeverage = array_intersect($batchImproves, $highLeverageCategories) !== [];

        $isStale = $oldestAgeP95 >= $staleThreshold;
        $hasDeepBacklog = $claimableDepth > 0;
        $bottleneckShape = ! $dryQueue && $hasDeepBacklog && $isStale && $observedConsumption === 0;

        // Blocked/quarantined debt is NOT usable claimable depth — a large blocked backlog must
        // never masquerade as healthy depth when deciding whether to wait (consolidate).
        $backlogComposition = (array) ($facts['backlog_composition'] ?? []);
        $blockedCount = max(0, (int) ($backlogComposition['blocked_count'] ?? 0));
        $quarantinedCount = max(0, (int) ($backlogComposition['quarantined_count'] ?? 0));
        $blockedDebtHigh = ($blockedCount + $quarantinedCount) > 0;

        $workerFeed = (array) ($facts['worker_feed'] ?? []);
        $activeWorkerCount = max(0, (int) ($workerFeed['active_worker_count'] ?? 0));
        $claimablePerActiveWorker = array_key_exists('claimable_per_active_worker', $workerFeed)
            ? (float) $workerFeed['claimable_per_active_worker']
            : null;
        $workerFloorThreshold = (float) ($workerFeed['floor'] ?? 2.0);
        $belowWorkerFloor = $activeWorkerCount > 0 && $claimablePerActiveWorker !== null && $claimablePerActiveWorker <= $workerFloorThreshold;

        $waitRefusedByBlockedDebt = $blockedDebtHigh && $belowWorkerFloor;
        $waitRefusedByStaleness = $blockedDebtHigh && $isStale;

        $reasons = [];
        $requiredEvidence = [];

        if ($malformedRate >= self::HIGH_MALFORMED_THRESHOLD || $giveBackRate >= self::HIGH_GIVE_BACK_THRESHOLD) {
            $decision = self::DECISION_REPAIR_QUEUE;
            $confidence = 0.9;
            $reasons[] = sprintf('malformed_rate=%.2f give_back_rate=%.2f above the repair threshold', $malformedRate, $giveBackRate);
            $requiredEvidence[] = 'health_snapshot.malformed_rate';
            $requiredEvidence[] = 'health_snapshot.give_back_rate';
        } elseif ($bottleneckShape && ($batchFixesBottleneck || $batchTargetsHighLeverage)) {
            $decision = self::DECISION_CREATE_MORE;
            $confidence = 0.7;
            $reasons[] = 'high claimable depth and stale p95 age, but the proposed batch directly fixes the bottleneck';
            $requiredEvidence[] = 'proposed_batch_leverage.fixes_bottleneck';
        } elseif ($hasDeepBacklog && $isStale && $batchTargetsHighLeverage) {
            $decision = self::DECISION_CREATE_MORE;
            $confidence = 0.65;
            $reasons[] = sprintf(
                'stale/low-value backlog present, but proposed batch directly improves a high-leverage category (%s); raw sufficient_depth alone never justifies waiting',
                implode(',', array_intersect($batchImproves, $highLeverageCategories)),
            );
            $requiredEvidence[] = 'proposed_batch_leverage.improves';
        } elseif ($bottleneckShape) {
            $decision = self::DECISION_DRAIN_EXISTING;
            $confidence = 0.85;
            $reasons[] = 'high claimable depth, stale p95 age, queue not dry, and no observed consumption — bottleneck is drain, not creation';
            $requiredEvidence[] = 'queue_age_histogram.oldest_age_p95_seconds';
            $requiredEvidence[] = 'worker_idle_prediction.observed_consumption_count';
        } elseif ($hasDeepBacklog && ! $consumptionProvided) {
            $decision = self::DECISION_PAUSE_ORIGINATION;
            $confidence = 0.5;
            $reasons[] = 'claimable backlog exists but worker consumption evidence is missing; pause origination rather than guess';
            $reasons[] = 'wait_is_not_progress: pausing here is a missing-evidence hold, not a claim that sufficient_depth alone justifies stopping';
            $requiredEvidence[] = 'worker_idle_prediction.observed_consumption_count';
        } elseif ($dryQueue && $urgencyScore > self::HIGH_URGENCY_THRESHOLD) {
            $decision = self::DECISION_CREATE_MORE;
            $confidence = 0.8;
            $reasons[] = 'queue is dry and replenish urgency is high';
            $requiredEvidence[] = 'replenish_urgency.urgency_score';
        } elseif (! $hasDeepBacklog && $observedConsumption === 0 && ! $dryQueue && $waitRefusedByBlockedDebt) {
            $decision = self::DECISION_REPAIR_QUEUE;
            $confidence = 0.8;
            $reasons[] = sprintf(
                'blocked/quarantined debt (%d) is not usable claimable depth and claimable_per_active_worker=%.2f is at/below the worker floor; wait would starve active workers',
                $blockedCount + $quarantinedCount,
                (float) $claimablePerActiveWorker,
            );
            $requiredEvidence[] = 'backlog_composition.blocked_count';
            $requiredEvidence[] = 'worker_feed.claimable_per_active_worker';
        } elseif (! $hasDeepBacklog && $observedConsumption === 0 && ! $dryQueue && $waitRefusedByStaleness) {
            $decision = self::DECISION_REPAIR_QUEUE;
            $confidence = 0.7;
            $reasons[] = sprintf('blocked/quarantined debt (%d) is stale (p95=%ds >= threshold=%ds); wait is refused until the debt is repaired', $blockedCount + $quarantinedCount, $oldestAgeP95, $staleThreshold);
            $requiredEvidence[] = 'backlog_composition.blocked_count';
            $requiredEvidence[] = 'queue_age_histogram.oldest_age_p95_seconds';
        } elseif (! $hasDeepBacklog && $observedConsumption === 0 && ! $dryQueue) {
            $decision = self::DECISION_CONSOLIDATE;
            $confidence = 0.5;
            $reasons[] = 'no claimable backlog and no consumption signal; consolidate before originating more';
            $reasons[] = 'wait_is_not_progress: consolidating here is a lack-of-signal hold, not a claim that sufficient_depth alone justifies stopping';
            $requiredEvidence[] = 'worker_idle_prediction.observed_consumption_count';
        } else {
            $decision = self::DECISION_CREATE_MORE;
            $confidence = 0.75;
            $reasons[] = 'no sickness and no stale-backlog bottleneck; conditions support origination';
        }

        $blockedNextActions = in_array($decision, [self::DECISION_REPAIR_QUEUE, self::DECISION_DRAIN_EXISTING, self::DECISION_PAUSE_ORIGINATION], true)
            ? [self::DECISION_CREATE_MORE]
            : [];
        $allowedNextActions = array_values(array_diff(self::ALL_ACTIONS, $blockedNextActions));

        // stale_reasons/refresh_required: names WHY the evidence read as stale, so a decision other
        // than create_more never looks like an unexplained refusal — queue depth alone never proves
        // freshness.
        $staleReasons = [];
        if ($isStale) {
            $staleReasons[] = sprintf('oldest_age_p95_seconds=%d>=stale_threshold_seconds=%d', $oldestAgeP95, $staleThreshold);
        }
        if ($waitRefusedByStaleness) {
            $staleReasons[] = sprintf('blocked_or_quarantined_debt_stale:count=%d', $blockedCount + $quarantinedCount);
        }
        $refreshRequired = $decision !== self::DECISION_CREATE_MORE && $staleReasons !== [];

        return [
            'schema' => self::SCHEMA,
            'decision' => $decision,
            'confidence' => $confidence,
            'reasons' => $reasons,
            'stale_reasons' => $staleReasons,
            'refresh_required' => $refreshRequired,
            'allowed_next_actions' => $allowedNextActions,
            'blocked_next_actions' => $blockedNextActions,
            'required_evidence' => $requiredEvidence,
        ];
    }
}
