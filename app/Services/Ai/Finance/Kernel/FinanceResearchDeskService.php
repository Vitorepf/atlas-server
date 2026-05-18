<?php

namespace App\Services\Ai\Finance\Kernel;

use App\Models\AiMission;
use App\Services\Ai\Mission\MissionCanonicalHash;

class FinanceResearchDeskService
{
    /**
     * Produce a research note for an asset/theme. Sources must be attributed and
     * non-empty. The note is never an order or a recommendation to execute.
     *
     * @param  array<string,mixed>  $args
     * @return array<string,mixed>
     */
    public function research(AiMission $mission, string $asset, array $args = []): array
    {
        if (trim($asset) === '') {
            throw FinanceDomainException::invalidAsset('asset cannot be empty');
        }
        $sources = (array) ($args['sources'] ?? ['internal:atlas-research:default-corpus']);
        $sources = array_values(array_filter(array_map('strval', $sources), fn (string $s): bool => $s !== ''));
        if ($sources === []) {
            throw FinanceDomainException::insufficientEvidence('at least one source_ref is required for research_desk');
        }

        $thesis = sprintf(
            'Atlas Finance research note for [%s]: review-only analysis. NO trade recommendation, NO live execution. Operator decision required for any action.',
            $asset,
        );

        $report = [
            'schema' => 'atlas.ai.finance.research_note.v1',
            'kind' => 'research_note',
            'mission_id' => $mission->id,
            'asset' => $asset,
            'thesis' => $thesis,
            'sources' => $sources,
            'assumptions' => $args['assumptions'] ?? ['no live data feed', 'corpus snapshot is point-in-time'],
            'risk_disclosure' => 'Past performance does not guarantee future results. Atlas Finance is review-only.',
            'next_actions' => ['operator_review', 'optional: finance.valuation', 'optional: finance.compliance'],
            'live_trade_blocked' => true,
        ];
        $report['receipt_hash'] = MissionCanonicalHash::sha256($report);

        return $report;
    }
}
