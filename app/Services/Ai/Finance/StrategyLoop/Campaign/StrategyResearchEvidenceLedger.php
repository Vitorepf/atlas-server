<?php

declare(strict_types=1);

namespace App\Services\Ai\Finance\StrategyLoop\Campaign;

/**
 * Durable research memory for strategy campaigns. Campaign reports are not just
 * files; they become governed evidence the next campaign can learn from.
 */
final class StrategyResearchEvidenceLedger
{
    public function __construct(private readonly string $path) {}

    public static function default(bool $dryRun = false): self
    {
        return new self($dryRun
            ? storage_path('framework/atlas/finance/research-evidence-ledger.jsonl')
            : storage_path('atlas/finance/research-evidence-ledger.jsonl'));
    }

    /**
     * @param  array<string,mixed>  $report
     */
    public function recordReport(array $report): void
    {
        $verdict = (string) ($report['verdict'] ?? 'INCONCLUSIVE');
        $event = [
            'schema_version' => 'atlas.finance.strategy_research_evidence.v1',
            'recorded_at' => gmdate('c'),
            'campaign_id' => $report['campaign_id'] ?? null,
            'symbol' => $report['symbol'] ?? null,
            'interval' => $report['interval'] ?? null,
            'strategy_family' => $report['strategy_family'] ?? null,
            'verdict' => $verdict,
            'knowledge_kind' => str_starts_with($verdict, 'NULL_') ? 'negative_finding' : ($verdict === 'CERTIFIED' ? 'positive_candidate' : 'inconclusive'),
            'summary' => $report['summary'] ?? [],
            'data_manifest' => $report['data_manifest'] ?? [],
            'cost_profile' => $report['cost_profile'] ?? [],
            'holdout' => $report['holdout'] ?? [],
            'confirmation_holdout' => $report['confirmation_holdout'] ?? [],
            'failure_distribution' => $report['failure_distribution'] ?? [],
            'scenario_profile' => $report['scenario_profile'] ?? [],
            'regime_summary' => $report['regime_summary'] ?? [],
            'negative_conclusion' => $report['negative_conclusion'] ?? null,
            'next_decision' => $report['next_decision'] ?? null,
            'propose_only' => true,
            'live_trading' => 'forbidden',
        ];

        @mkdir(dirname($this->path), 0o755, true);
        file_put_contents($this->path, json_encode($event, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION)."\n", FILE_APPEND | LOCK_EX);
    }
}
