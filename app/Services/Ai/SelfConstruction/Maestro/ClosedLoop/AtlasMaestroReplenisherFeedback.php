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
    public function __construct(private readonly object $miner)
    {
    }

    public function renderFactsBlock(): string
    {
        if (! (bool) config('atlas.maestro.closed_loop.feedback_enabled', false)) {
            return '';
        }

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

        return $lines === [] ? '' : implode("\n", $lines);
    }
}
