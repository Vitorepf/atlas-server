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
        $lines = [];
        foreach ((array) $this->miner->mine() as $dimension => $buckets) {
            foreach ((array) $buckets as $bucket => $entry) {
                if (! is_array($entry)) {
                    continue;
                }
                $total = (int) ($entry['total'] ?? 0);
                if (($entry['insufficient_support'] ?? true) === true || $total < AtlasMaestroOutcomePatternMiner::MIN_SUPPORT) {
                    continue; // not enough evidence ⇒ never emitted (no lucky-run facts)
                }
                $delivered = (int) ($entry['delivered'] ?? 0);
                $rate = $entry['delivery_rate'] ?? ($total > 0 ? $delivered / $total : 0.0);
                $supported[] = $entry;
                $lines[] = sprintf(
                    '%s=%s: %d delivered / %d total (rate %.2f, support>=%d)',
                    (string) $dimension,
                    (string) $bucket,
                    $delivered,
                    $total,
                    (float) $rate,
                    AtlasMaestroOutcomePatternMiner::MIN_SUPPORT,
                );
            }
        }

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
