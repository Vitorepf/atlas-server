<?php

namespace App\Services\Ai\MarketingDomain\Offer;

use App\Models\AiMarketingVslAsset;
use App\Models\AiMarketingWinningPattern;
use App\Services\Ai\MarketingDomain\Campaign\CampaignEconomicsCalculator;

/**
 * OfferScoutScorer — the offer-scout skill (80% of the result is selection). Scores a KNOWN offer on
 * the king metric EPC (grounded in real Nivor CVR), derives the unit economics, checks whether the
 * proven keyword angle aligns with the VSL mechanism, and returns a verdict (hot|feasible|tight|skip).
 * Diagnostic only — never a gate. Deterministic.
 */
class OfferScoutScorer
{
    public function __construct(
        private readonly OfferEpcCalculator $epc = new OfferEpcCalculator,
        private readonly CampaignEconomicsCalculator $economics = new CampaignEconomicsCalculator,
    ) {}

    /**
     * @param  array<string,mixed>  $offer  payout, network, refund_rate(optional)
     * @return array<string,mixed>
     */
    public function score(array $offer, AiMarketingVslAsset $vsl, ?AiMarketingWinningPattern $pattern): array
    {
        $epc = $this->epc->epcProjection($offer, $pattern);
        $econ = $this->economics->compute([
            'payout' => (float) ($offer['payout'] ?? 0),
            'cvr' => $epc['cvr_used'] > 0 ? $epc['cvr_used'] : 0.01,
            'refund_rate' => $epc['refund_rate'],
        ]);

        $alignment = $this->keywordMechanismAlignment($vsl, $pattern);
        $verdict = $this->verdict((float) $epc['projected_epc'], (string) $epc['confidence'], $alignment['aligned']);

        return [
            'estimated_epc' => $epc['projected_epc'],
            'epc_detail' => $epc,
            'max_cpa' => $econ['max_cpa'],
            'breakeven_cpa' => $econ['breakeven_cpa'],
            'target_cpc' => $econ['target_cpc'],
            'kill_spend_no_sale' => $econ['kill_spend_no_sale'],
            'keyword_mechanism_alignment' => $alignment,
            'confidence' => $epc['confidence'],
            'verdict' => $verdict,
            'note' => 'Score de seleção de oferta (EPC ancorado no CVR real). Diagnóstico, não veto.',
        ];
    }

    /**
     * Does the proven keyword angle (what already sold) match the VSL's mechanism/solution?
     *
     * @return array<string,mixed>
     */
    private function keywordMechanismAlignment(AiMarketingVslAsset $vsl, ?AiMarketingWinningPattern $pattern): array
    {
        $mechanism = strtolower(trim((string) $vsl->mechanism_name.' '.(string) $vsl->solution_mechanism));
        if ($mechanism === '' || $pattern === null) {
            return ['aligned' => false, 'overlap' => 0, 'reason' => 'sem mecanismo extraído ou sem padrão'];
        }

        $mechWords = array_filter(preg_split('/\s+/', $mechanism) ?: [], static fn (string $w): bool => strlen($w) > 3);
        $overlap = 0;
        foreach ((array) $pattern->converting_keywords as $row) {
            $term = strtolower((string) (is_array($row) ? ($row['term'] ?? '') : $row));
            foreach ($mechWords as $w) {
                if ($w !== '' && str_contains($term, $w)) {
                    $overlap++;
                    break;
                }
            }
        }

        return [
            'aligned' => $overlap > 0,
            'overlap' => $overlap,
            'reason' => $overlap > 0 ? 'o ângulo que vendeu cita o mecanismo da VSL' : 'keywords vencedoras não citam o mecanismo — risco de message-match',
        ];
    }

    private function verdict(float $epc, string $confidence, bool $aligned): string
    {
        return match (true) {
            $epc >= 1.0 && $confidence === 'high' && $aligned => 'hot',
            $epc >= 0.5 => 'feasible',
            $epc >= 0.2 => 'tight',
            default => 'skip',
        };
    }
}
