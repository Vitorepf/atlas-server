<?php

declare(strict_types=1);

namespace App\Services\Ai\Finance\StrategyLoop\Campaign;

/**
 * Turns a raw search ledger into a scientific verdict. A null is not "nothing";
 * it is a research conclusion with strength, caveats, and next action.
 */
final class StrategyCampaignReporter
{
    /**
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    public function summarizeLedger(string $ledgerPath, array $context = []): array
    {
        $lines = $this->readRows($ledgerPath);
        $certified = 0;
        $promoted = 0;
        $bestDsr = null;
        $bestDsrRow = null;
        $bestHoldout = null;
        $bestHoldoutRow = null;
        $reasonCounts = [];

        foreach ($lines as $row) {
            if ((bool) ($row['certified'] ?? false)) {
                $certified++;
            }
            if ((bool) ($row['promoted'] ?? false)) {
                $promoted++;
            }
            $dsr = $row['deflated_sharpe'] ?? null;
            if (is_numeric($dsr) && ($bestDsr === null || (float) $dsr > $bestDsr)) {
                $bestDsr = (float) $dsr;
                $bestDsrRow = $row;
            }
            $holdout = $row['holdout_sharpe'] ?? null;
            if (is_numeric($holdout) && ($bestHoldout === null || (float) $holdout > $bestHoldout)) {
                $bestHoldout = (float) $holdout;
                $bestHoldoutRow = $row;
            }
            foreach (($row['reasons'] ?? []) as $reason) {
                $key = is_string($reason) ? preg_replace('/\(.*$/', '', $reason) : 'unknown';
                $reasonCounts[$key] = ($reasonCounts[$key] ?? 0) + 1;
            }
        }

        arsort($reasonCounts);
        $rounds = count($lines);
        $candidatesPerRound = (int) ($context['candidates_per_round'] ?? ($lines[0]['candidates'] ?? 0));
        $totalCandidates = $rounds * max(0, $candidatesPerRound);
        $holdoutStatus = (string) ($context['holdout_status'] ?? ($lines !== [] ? (string) ($lines[count($lines) - 1]['holdout_status'] ?? '') : ''));
        $verdict = $this->verdict($rounds, $certified, $promoted, $holdoutStatus, (int) ($context['strong_null_min_rounds'] ?? 100));

        return [
            'schema_version' => 'atlas.finance.strategy_campaign_report.v1',
            'generated_at' => gmdate('c'),
            'campaign_id' => $context['campaign_id'] ?? null,
            'symbol' => $context['symbol'] ?? null,
            'interval' => $context['interval'] ?? null,
            'strategy_family' => $context['strategy_family'] ?? null,
            'verdict' => $verdict,
            'summary' => [
                'rounds' => $rounds,
                'candidates_per_round' => $candidatesPerRound,
                'total_candidates' => $totalCandidates,
                'certified' => $certified,
                'promoted' => $promoted,
                'best_dsr' => $bestDsr,
                'best_dsr_round' => $bestDsrRow['round'] ?? null,
                'best_holdout_sharpe' => $bestHoldout,
                'best_holdout_round' => $bestHoldoutRow['round'] ?? null,
                'holdout_reuse_count' => $context['holdout_reuse_count'] ?? $rounds,
                'holdout_status' => $holdoutStatus !== '' ? $holdoutStatus : null,
            ],
            'failure_distribution' => $reasonCounts,
            'best_candidates_observed' => [
                'best_dsr_row' => $bestDsrRow,
                'best_holdout_row' => $bestHoldoutRow,
            ],
            'regime_summary' => [
                'best_dsr_validation_holdout' => is_array($bestDsrRow) ? ($bestDsrRow['holdout_regime_metrics'] ?? null) : null,
                'best_dsr_confirmation_holdout' => is_array($bestDsrRow) ? ($bestDsrRow['confirmation_holdout_regime_metrics'] ?? null) : null,
                'best_holdout_validation_holdout' => is_array($bestHoldoutRow) ? ($bestHoldoutRow['holdout_regime_metrics'] ?? null) : null,
                'scenario_note' => 'Regimes explain scenario fit; they do not weaken or replace the certification gate.',
            ],
            'negative_conclusion' => $this->negativeConclusion($verdict, $context, $rounds, $totalCandidates),
            'next_decision' => $this->nextDecision($verdict),
            'propose_only' => true,
            'live_trading' => 'forbidden',
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function readRows(string $ledgerPath): array
    {
        if (! is_file($ledgerPath)) {
            return [];
        }

        $rows = [];
        foreach (file($ledgerPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $rows[] = $decoded;
            }
        }

        return $rows;
    }

    private function verdict(int $rounds, int $certified, int $promoted, string $holdoutStatus, int $strongNullMinRounds): string
    {
        if ($certified > 0) {
            return 'CERTIFIED';
        }
        if ($holdoutStatus === StrategyCampaignStore::HOLDOUT_EXHAUSTED) {
            return 'NULL_HOLDOUT_EXHAUSTED';
        }
        if ($promoted > 0) {
            return 'INCONCLUSIVE';
        }
        if ($rounds >= $strongNullMinRounds) {
            return 'NULL_STRONG';
        }
        if ($rounds > 0) {
            return 'NULL_WEAK';
        }

        return 'INCONCLUSIVE';
    }

    /**
     * @param  array<string,mixed>  $context
     */
    private function negativeConclusion(string $verdict, array $context, int $rounds, int $totalCandidates): string
    {
        $family = (string) ($context['strategy_family'] ?? 'unknown-family');
        $symbol = (string) ($context['symbol'] ?? 'unknown-symbol');
        $interval = (string) ($context['interval'] ?? 'unknown-interval');

        return match ($verdict) {
            'CERTIFIED' => 'A candidate survived all configured review gates; human review is still required and no trade is emitted.',
            'NULL_HOLDOUT_EXHAUSTED' => "After {$rounds} rounds / {$totalCandidates} candidates, {$family} on {$symbol}-{$interval} did not produce a certified proposal before the holdout was exhausted.",
            'NULL_STRONG' => "After {$rounds} rounds / {$totalCandidates} candidates, {$family} on {$symbol}-{$interval} did not demonstrate robust edge under the configured gates.",
            'NULL_WEAK' => "{$family} on {$symbol}-{$interval} has not certified yet, but the campaign budget is too small for a strong negative conclusion.",
            default => "The campaign is inconclusive; do not promote any strategy without fresh validation.",
        };
    }

    private function nextDecision(string $verdict): string
    {
        return match ($verdict) {
            'NULL_HOLDOUT_EXHAUSTED' => 'Retest only with a fresh holdout or close the family as exhausted for this market/timeframe.',
            'NULL_STRONG' => 'Record the negative finding, then expand to a new market/timeframe/family under a new campaign.',
            'NULL_WEAK' => 'Continue only if the holdout budget remains healthy and the campaign was pre-registered.',
            'CERTIFIED' => 'Keep proposal review-only; run quarantine and independent validation before any paper-forward observation.',
            default => 'Resolve data, budget, or validation gaps before continuing.',
        };
    }
}
