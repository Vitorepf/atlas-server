<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Maestro\ClosedLoop;

/**
 * MAESTRO CLOSED-LOOP REPLENISHER FEEDBACK — renders the {@see AtlasMaestroOutcomePatternMiner} output into a
 * FACT-ONLY context block the AtlasTaskBrainReplenisher can read when structuring the next round. Every line is
 * a declarative FACT with its support count — e.g. "origin_kind=orphan: 14 delivered / 22 total (rate 0.64,
 * support>=8)". NEVER an imperative (no prefer/should/must/avoid/recommend), NEVER a threshold disguised as
 * advice — the operator/structurer draws the conclusion from the numbers.
 *
 * Flag-gated behind config('atlas.maestro.closed_loop.feedback_enabled', false): OFF (default) ⇒ '' so wiring
 * is byte-identical. Only buckets at or above the miner's MIN_SUPPORT are emitted; no qualifying bucket ⇒ ''.
 */
final class AtlasMaestroReplenisherFeedback
{
    public const MODE_WAIT = 'wait';

    public const MODE_REPLENISH = 'replenish';

    public const MODE_REFACTOR = 'refactor';

    public const MODE_SELF_HEAL = 'self_heal';

    public const MODE_ESCALATE_AMBITION = 'escalate_ambition';

    /** give_back_rate (0..1) at or above this is pressure the queue's existing tasks need refactor. */
    private const GIVE_BACK_PRESSURE_THRESHOLD = 0.3;

    /** task_quality_score (0..1) below this is quality pressure needing refactor, not more origination. */
    private const QUALITY_PRESSURE_THRESHOLD = 0.5;

    /** Worker-drain horizon (hours) at or below this is a self-heal-worthy infra emergency. */
    private const DRAIN_HORIZON_CRITICAL_HOURS = 2.0;

    /** claimable_depth at or above target_depth times this ratio is a deep surplus — escalate ambition. */
    private const ESCALATE_AMBITION_SURPLUS_RATIO = 2.0;

    private ?AtlasMaestroLearningPolicyGuard $policyGuard = null;

    public function __construct(
        private readonly object $miner,
        private readonly ?AtlasMaestroClosedLoopReceiptLedger $receiptLedger = null,
    ) {
    }

    public function renderFactsBlock(): string
    {
        $flagEnabled = (bool) config('atlas.maestro.closed_loop.feedback_enabled', false);
        if (! $flagEnabled) {
            $this->recordReceipt('', 0, 'reject', false, $flagEnabled);

            return '';
        }

        $supported = [];
        $scored    = [];
        foreach ((array) $this->miner->mine() as $dimension => $buckets) {
            foreach ((array) $buckets as $bucket => $entry) {
                if (! is_array($entry)) {
                    continue;
                }
                $total = (int) ($entry['total'] ?? 0);
                if (($entry['insufficient_support'] ?? true) === true || $total < AtlasMaestroOutcomePatternMiner::MIN_SUPPORT) {
                    continue; // not enough evidence ⇒ never emitted (no lucky-run facts)
                }

                if ((string) $dimension === 'queue_starvation') {
                    $noClaimableTask          = (int) ($entry['no_claimable_task_count'] ?? 0);
                    $claimablePerActiveWorker = (float) ($entry['claimable_per_active_worker'] ?? 0.0);
                    $workerFloorBreaches      = (int) ($entry['worker_floor_breach_count'] ?? 0);
                    $supported[]              = $entry;
                    $scored[]                 = [
                        'line' => sprintf(
                            '%s=%s: no_claimable_task=%d, claimable_per_active_worker=%.2f, worker_floor_breaches=%d (total %d, support>=%d)',
                            (string) $dimension,
                            (string) $bucket,
                            $noClaimableTask,
                            $claimablePerActiveWorker,
                            $workerFloorBreaches,
                            $total,
                            AtlasMaestroOutcomePatternMiner::MIN_SUPPORT,
                        ),
                        'yield_score' => 0.0,
                    ];

                    continue;
                }

                $delivered       = (int) ($entry['delivered'] ?? 0);
                $rate            = (float) ($entry['delivery_rate'] ?? ($total > 0 ? $delivered / $total : 0.0));
                $giveBackRate    = $total > 0 ? (int) ($entry['give_back_count'] ?? 0) / $total : 0.0;
                $blockedRate     = $total > 0 ? (int) ($entry['blocked_count'] ?? 0) / $total : 0.0;
                $yieldScore      = max(0.0, $rate - $giveBackRate - $blockedRate);
                $supported[]     = $entry;
                $scored[]        = [
                    'line' => sprintf(
                        '%s=%s: %d delivered / %d total (rate %.2f, give_back %.2f, blocked %.2f, yield %.2f, support>=%d)',
                        (string) $dimension,
                        (string) $bucket,
                        $delivered,
                        $total,
                        $rate,
                        $giveBackRate,
                        $blockedRate,
                        $yieldScore,
                        AtlasMaestroOutcomePatternMiner::MIN_SUPPORT,
                    ),
                    'yield_score' => $yieldScore,
                ];
            }
        }

        // Rank lanes by delivered yield descending so the Replenisher reads the most productive lane first.
        usort($scored, static fn (array $a, array $b): int => $b['yield_score'] <=> $a['yield_score']);
        $lines = array_column($scored, 'line');

        if ($lines === []) {
            $this->recordReceipt('', 0, 'reject', false, $flagEnabled);

            return '';
        }

        $block = implode("\n", $lines);
        // PÉTREO: every learning artifact passes the anti-Goodhart guard before it can reach the Replenisher.
        // A violating artifact throws here and NEVER reaches the rendered output.
        try {
            $this->guard()->assertSafe(['facts' => $supported, 'block' => $block]);
        } catch (\Throwable $exception) {
            $this->recordReceipt($block, count($supported), 'reject', false, $flagEnabled);

            throw $exception;
        }

        $this->recordReceipt($block, count($supported), 'pass', true, $flagEnabled);

        return $block;
    }

    /**
     * Distinct from renderFactsBlock() (delivery-pattern facts): tells the originator EXACTLY
     * what to do next when queue depth alone would otherwise look "healthy enough" — a sufficient
     * claimable supply must never be padded, and a lease-mismatch or other health-observability
     * flag must never drift silently just because the queue looks full.
     *
     * @param  array{
     *   claimable_supply_sufficient?:bool, lease_mismatch_count?:int, health_flags?:list<string>,
     *   batch_looks_like_padding?:bool,
     * }  $facts
     * @return array{feedback_reasons:list<string>, health_flag_details:list<string>, next_originator_focus:string, must_not_create_reason:?string}
     */
    public function evaluateOriginatorFeedback(array $facts): array
    {
        $claimableSupplySufficient = (bool) ($facts['claimable_supply_sufficient'] ?? false);
        $leaseMismatchCount = max(0, (int) ($facts['lease_mismatch_count'] ?? 0));
        $healthFlags = array_values(array_filter(array_map('strval', (array) ($facts['health_flags'] ?? []))));
        $batchLooksLikePadding = (bool) ($facts['batch_looks_like_padding'] ?? false);

        $healthFlagDetails = $healthFlags;
        if ($leaseMismatchCount > 0) {
            $healthFlagDetails[] = "lease_mismatch_count:{$leaseMismatchCount}";
        }
        $healthNeedsRepair = $healthFlagDetails !== [];

        $feedbackReasons = [];
        if ($claimableSupplySufficient) {
            $feedbackReasons[] = 'do_not_pad_queue';
        }
        if ($healthNeedsRepair) {
            $feedbackReasons[] = 'health_repair_needed';
        }

        $nextOriginatorFocus = match (true) {
            $healthNeedsRepair => 'repair_health_observability',
            $claimableSupplySufficient => 'diversify_or_deepen_existing_queue',
            default => 'originate_new_claimable_work',
        };

        $mustNotCreateReason = ($claimableSupplySufficient || $batchLooksLikePadding)
            ? 'queue_depth_already_sufficient_creating_more_would_be_padding'
            : null;

        return [
            'feedback_reasons' => $feedbackReasons,
            'health_flag_details' => $healthFlagDetails,
            'next_originator_focus' => $nextOriginatorFocus,
            'must_not_create_reason' => $mustNotCreateReason,
        ];
    }

    /**
     * Distinguishes wait / replenish / refactor / self_heal / escalate_ambition from real facts
     * — depth alone can never justify "sufficient strategic evolution": a deep-but-low-quality
     * queue needs refactor, a draining worker pool needs self_heal before anything else, and a
     * deep-and-healthy queue means the loop should escalate ambition rather than idle-wait.
     *
     * @param  array{
     *   claimable_depth?:int, target_depth?:int,
     *   task_quality_score?:float, give_back_rate?:float,
     *   worker_drain_rate?:float, health_flags?:list<string>, lease_mismatch_count?:int,
     * }  $facts
     * @return array{
     *   queue_delta:int, task_quality_pressure:float, give_back_pressure:float,
     *   worker_drain_horizon_hours:?float, recommended_originator_mode:string, mode_reasons:list<string>,
     * }
     */
    public function evaluateReplenishmentMode(array $facts): array
    {
        $claimableDepth = max(0, (int) ($facts['claimable_depth'] ?? 0));
        $targetDepth = max(0, (int) ($facts['target_depth'] ?? 0));
        $queueDelta = $claimableDepth - $targetDepth;

        $taskQualityScore = max(0.0, min(1.0, (float) ($facts['task_quality_score'] ?? 1.0)));
        $qualityPressure = max(0.0, self::QUALITY_PRESSURE_THRESHOLD - $taskQualityScore) / self::QUALITY_PRESSURE_THRESHOLD;

        $giveBackRate = max(0.0, min(1.0, (float) ($facts['give_back_rate'] ?? 0.0)));
        $giveBackPressure = $giveBackRate;

        $workerDrainRate = max(0.0, (float) ($facts['worker_drain_rate'] ?? 0.0));
        $drainHorizonHours = $workerDrainRate > 0.0 ? 1.0 / $workerDrainRate : null;

        $healthFlags = array_values(array_filter(array_map('strval', (array) ($facts['health_flags'] ?? []))));
        $leaseMismatchCount = max(0, (int) ($facts['lease_mismatch_count'] ?? 0));
        $healthNeedsRepair = $healthFlags !== [] || $leaseMismatchCount > 0;
        $drainCritical = $drainHorizonHours !== null && $drainHorizonHours <= self::DRAIN_HORIZON_CRITICAL_HOURS;

        $giveBackPressureHigh = $giveBackRate >= self::GIVE_BACK_PRESSURE_THRESHOLD;
        $qualityPressureHigh = $taskQualityScore < self::QUALITY_PRESSURE_THRESHOLD;
        $deepSurplus = $targetDepth > 0 && $claimableDepth >= $targetDepth * self::ESCALATE_AMBITION_SURPLUS_RATIO;

        $reasons = [];
        $mode = match (true) {
            $healthNeedsRepair || $drainCritical => self::MODE_SELF_HEAL,
            $giveBackPressureHigh || $qualityPressureHigh => self::MODE_REFACTOR,
            $queueDelta < 0 => self::MODE_REPLENISH,
            $deepSurplus => self::MODE_ESCALATE_AMBITION,
            default => self::MODE_WAIT,
        };

        if ($healthNeedsRepair) {
            $reasons[] = 'health_flags_or_lease_mismatch_present';
        }
        if ($drainCritical) {
            $reasons[] = 'worker_drain_horizon_critical:'.round($drainHorizonHours, 2).'h';
        }
        if ($giveBackPressureHigh) {
            $reasons[] = 'give_back_pressure_high:'.round($giveBackRate, 2);
        }
        if ($qualityPressureHigh) {
            $reasons[] = 'task_quality_pressure_high:'.round($qualityPressure, 2);
        }
        if ($queueDelta < 0) {
            $reasons[] = 'queue_delta_negative:'.$queueDelta;
        }
        if ($deepSurplus) {
            $reasons[] = 'deep_surplus_queue_delta:'.$queueDelta;
        }
        if ($reasons === []) {
            $reasons[] = 'queue_healthy_no_pressure_signals';
        }

        return [
            'queue_delta' => $queueDelta,
            'task_quality_pressure' => round($qualityPressure, 2),
            'give_back_pressure' => round($giveBackPressure, 2),
            'worker_drain_horizon_hours' => $drainHorizonHours !== null ? round($drainHorizonHours, 2) : null,
            'recommended_originator_mode' => $mode,
            'mode_reasons' => $reasons,
        ];
    }

    private function guard(): AtlasMaestroLearningPolicyGuard
    {
        return $this->policyGuard ??= new AtlasMaestroLearningPolicyGuard;
    }

    private function recordReceipt(string $block, int $minedBucketCount, string $guarded, bool $consumed, bool $flagEnabled): void
    {
        ($this->receiptLedger ?? new AtlasMaestroClosedLoopReceiptLedger)->recordCycle([
            'feedback_block' => $block,
            'mined_bucket_count' => $minedBucketCount,
            'guarded_pass_or_reject' => $guarded,
            'replenisher_consumed' => $consumed,
            'flag_enabled' => $flagEnabled,
        ]);
    }
}
