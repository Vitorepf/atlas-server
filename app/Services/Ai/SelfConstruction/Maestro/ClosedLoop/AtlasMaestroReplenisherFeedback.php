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
