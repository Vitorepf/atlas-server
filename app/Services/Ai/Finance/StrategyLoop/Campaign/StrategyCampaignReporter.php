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
        $bestAnnSharpe = null;
        $bestAnnSharpeRow = null;
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
            $annSharpe = $row['best_ann_sharpe'] ?? null;
            if (is_numeric($annSharpe) && ($bestAnnSharpe === null || (float) $annSharpe > $bestAnnSharpe)) {
                $bestAnnSharpe = (float) $annSharpe;
                $bestAnnSharpeRow = $row;
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
        $budgetComplete = (bool) ($context['pre_registered_budget_complete'] ?? false);
        $stopReason = (string) ($context['stop_reason'] ?? '');
        $latestRow = $lines !== [] ? $lines[count($lines) - 1] : [];
        $dataManifest = is_array($context['data_manifest'] ?? null) ? $context['data_manifest'] : [];
        $dataSha = (string) ($context['data_sha'] ?? ($dataManifest['sha256'] ?? ($latestRow['data_sha'] ?? '')));
        $costProfile = is_array($context['cost_profile'] ?? null) ? $context['cost_profile'] : [];
        $costProfileHash = (string) ($context['cost_profile_hash'] ?? ($costProfile['cost_profile_hash'] ?? ($latestRow['cost_profile_hash'] ?? '')));
        $verdict = $this->verdict(
            $rounds,
            $certified,
            $promoted,
            $holdoutStatus,
            (int) ($context['strong_null_min_rounds'] ?? 100),
            $budgetComplete,
            $stopReason,
        );

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
                'best_ann_sharpe' => $bestAnnSharpe,
                'best_ann_sharpe_round' => $bestAnnSharpeRow['round'] ?? null,
                'best_dsr' => $bestDsr,
                'best_dsr_round' => $bestDsrRow['round'] ?? null,
                'best_holdout_sharpe' => $bestHoldout,
                'best_holdout_round' => $bestHoldoutRow['round'] ?? null,
                'holdout_reuse_count' => $context['holdout_reuse_count'] ?? $rounds,
                'holdout_status' => $holdoutStatus !== '' ? $holdoutStatus : null,
                'stop_reason' => $stopReason !== '' ? $stopReason : null,
                'pre_registered_budget_complete' => $budgetComplete,
                'data_sha' => $dataSha !== '' ? $dataSha : null,
                'cost_profile_hash' => $costProfileHash !== '' ? $costProfileHash : null,
            ],
            'data_manifest' => $dataManifest !== [] ? $dataManifest : ['sha256' => $dataSha !== '' ? $dataSha : null],
            'cost_profile' => $costProfile !== [] ? $costProfile : ['cost_profile_hash' => $costProfileHash !== '' ? $costProfileHash : null],
            'holdout' => [
                'holdout_id' => $context['holdout_id'] ?? ($latestRow['holdout_id'] ?? null),
                'status' => $holdoutStatus !== '' ? $holdoutStatus : null,
                'reuse_count' => $context['holdout_reuse_count'] ?? $rounds,
            ],
            'confirmation_holdout' => [
                'holdout_id' => $context['confirmation_holdout_id'] ?? ($latestRow['confirmation_holdout_id'] ?? null),
                'used' => (bool) ($latestRow['confirmation_holdout_used'] ?? false),
            ],
            'failure_distribution' => $reasonCounts,
            'best_candidates_observed' => [
                'best_ann_sharpe_row' => $bestAnnSharpeRow,
                'best_dsr_row' => $bestDsrRow,
                'best_holdout_row' => $bestHoldoutRow,
            ],
            'scenario_profile' => [
                'scenario_key' => $this->scenarioKey($context),
                'best_observed' => [
                    'ann_sharpe' => $this->candidateProfile($bestAnnSharpeRow),
                    'deflated_sharpe' => $this->candidateProfile($bestDsrRow),
                    'holdout_sharpe' => $this->candidateProfile($bestHoldoutRow),
                ],
                'data_sha' => $dataSha !== '' ? $dataSha : null,
                'cost_profile_hash' => $costProfileHash !== '' ? $costProfileHash : null,
                'knowledge_note' => 'Best observed candidates are research evidence, not executable signals.',
            ],
            'regime_summary' => [
                'best_ann_validation_holdout' => is_array($bestAnnSharpeRow) ? ($bestAnnSharpeRow['holdout_regime_metrics'] ?? null) : null,
                'best_ann_confirmation_holdout' => is_array($bestAnnSharpeRow) ? ($bestAnnSharpeRow['confirmation_holdout_regime_metrics'] ?? null) : null,
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

    /**
     * @param  array<string,mixed>  $context
     */
    private function scenarioKey(array $context): string
    {
        return sprintf(
            '%s-%s-%s',
            strtoupper((string) ($context['symbol'] ?? 'UNKNOWN')),
            (string) ($context['interval'] ?? 'unknown-interval'),
            (string) ($context['strategy_family'] ?? 'unknown-family'),
        );
    }

    /**
     * @param  array<string,mixed>|null  $row
     * @return array<string,mixed>|null
     */
    private function candidateProfile(?array $row): ?array
    {
        if ($row === null) {
            return null;
        }

        return [
            'round' => $row['round'] ?? null,
            'winner_island' => $row['winner_island'] ?? null,
            'best_ann_sharpe' => $row['best_ann_sharpe'] ?? null,
            'deflated_sharpe' => $row['deflated_sharpe'] ?? null,
            'campaign_deflated_sharpe' => $row['campaign_deflated_sharpe'] ?? null,
            'pbo' => $row['pbo'] ?? null,
            'holdout_sharpe' => $row['holdout_sharpe'] ?? null,
            'holdout_total_return' => $row['holdout_total_return'] ?? null,
            'holdout_exposure' => $row['holdout_exposure'] ?? null,
            'holdout_equity_curve_sample' => is_array($row['holdout_equity_curve_sample'] ?? null) ? array_values(array_map('floatval', $row['holdout_equity_curve_sample'])) : [],
            'winner_signature' => $row['winner_signature'] ?? null,
            'winner_strategy' => $row['winner_strategy'] ?? null,
            'reasons' => $row['reasons'] ?? [],
        ];
    }

    private function verdict(int $rounds, int $certified, int $promoted, string $holdoutStatus, int $strongNullMinRounds, bool $preRegisteredBudgetComplete, string $stopReason): string
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
        if (! $preRegisteredBudgetComplete && in_array($stopReason, ['kill_switch', 'invocation_round_limit', 'unknown'], true)) {
            return 'INCONCLUSIVE';
        }
        if ($preRegisteredBudgetComplete && $stopReason === 'campaign_round_budget_complete' && $rounds >= $strongNullMinRounds) {
            return 'NULL_FAMILY_EXHAUSTED';
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
            'NULL_FAMILY_EXHAUSTED' => "After the pre-registered budget of {$rounds} rounds / {$totalCandidates} candidates, {$family} on {$symbol}-{$interval} is exhausted for this campaign scope without robust edge.",
            'NULL_STRONG' => "After {$rounds} rounds / {$totalCandidates} candidates, {$family} on {$symbol}-{$interval} did not demonstrate robust edge under the configured gates.",
            'NULL_WEAK' => "{$family} on {$symbol}-{$interval} has not certified yet, but the campaign budget is too small for a strong negative conclusion.",
            default => "The campaign is inconclusive; do not promote any strategy without fresh validation.",
        };
    }

    private function nextDecision(string $verdict): string
    {
        return match ($verdict) {
            'NULL_HOLDOUT_EXHAUSTED' => 'Retest only with a fresh holdout or close the family as exhausted for this market/timeframe.',
            'NULL_FAMILY_EXHAUSTED' => 'Record the family-level negative finding for this market/timeframe, then move sequentially to the next scenario or a new family.',
            'NULL_STRONG' => 'Record the negative finding, then expand to a new market/timeframe/family under a new campaign.',
            'NULL_WEAK' => 'Continue only if the holdout budget remains healthy and the campaign was pre-registered.',
            'CERTIFIED' => 'Keep proposal review-only; run quarantine and independent validation before any paper-forward observation.',
            default => 'Resolve data, budget, or validation gaps before continuing.',
        };
    }
}
