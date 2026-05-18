<?php

namespace App\Services\Ai\Finance\Kernel;

use App\Models\AiMission;
use App\Services\Ai\Mission\MissionCanonicalHash;

class FinanceReportingService
{
    /**
     * Aggregate research/valuation/portfolio/risk/compliance/paper_trading
     * outputs into a versioned investment brief artifact. The brief is
     * advisory only and never instructs execution.
     *
     * @param  array<string,array<string,mixed>>  $parts  map of {research, valuation, portfolio, risk, compliance, paper_trading}
     * @return array<string,mixed>
     */
    public function brief(AiMission $mission, array $parts): array
    {
        $report = [
            'schema' => 'atlas.ai.finance.investment_brief.v1',
            'kind' => 'investment_brief',
            'mission_id' => $mission->id,
            'mission_uuid' => $mission->uuid,
            'sections' => [
                'research' => $this->summarize('research', $parts['research'] ?? null),
                'valuation' => $this->summarize('valuation', $parts['valuation'] ?? null),
                'portfolio' => $this->summarize('portfolio', $parts['portfolio'] ?? null),
                'risk' => $this->summarize('risk', $parts['risk'] ?? null),
                'compliance' => $this->summarize('compliance', $parts['compliance'] ?? null),
                'paper_trading' => $this->summarize('paper_trading', $parts['paper_trading'] ?? null),
            ],
            'live_trade_blocked' => true,
            'auto_rebalance_blocked' => true,
            'operator_review_required' => true,
            'next_actions' => ['operator_review', 'optional: handoff to research/strategy'],
        ];
        $report['receipt_hash'] = MissionCanonicalHash::sha256($report);

        return $report;
    }

    /**
     * @param  array<string,mixed>|null  $payload
     * @return array<string,mixed>|null
     */
    private function summarize(string $kind, ?array $payload): ?array
    {
        if ($payload === null) {
            return ['present' => false, 'kind' => $kind];
        }

        return [
            'present' => true,
            'kind' => $kind,
            'schema' => $payload['schema'] ?? null,
            'receipt_hash' => $payload['receipt_hash'] ?? null,
        ];
    }
}
