<?php

namespace App\Services\Ai\MarketingDomain\Offer;

use App\Models\AiMarketingWinningPattern;
use App\Services\Ai\MarketingDomain\Knowledge\AffiliateNetworksReference;

/**
 * OfferEpcCalculator — projects the king metric (EPC, earnings-per-click) for a known offer using
 * the REAL Nivor click→sale CVR + the network refund baseline. EPC = CVR × payout × (1 − refund).
 * Deterministic. This closes the loop CampaignEconomicsCalculator left open (it assumed a CVR input).
 */
class OfferEpcCalculator
{
    public const REFUND_RISK = 0.15;

    /**
     * @param  array<string,mixed>  $offer  payout, network, refund_rate(optional)
     * @return array<string,mixed>
     */
    public function epcProjection(array $offer, ?AiMarketingWinningPattern $pattern): array
    {
        $payout = max(0.0, (float) ($offer['payout'] ?? 0));
        $network = $offer['network'] ?? null;
        $refund = isset($offer['refund_rate']) && is_numeric($offer['refund_rate'])
            ? max(0.0, min(1.0, (float) $offer['refund_rate']))
            : AffiliateNetworksReference::defaultRefundRate($network);

        $cvr = $pattern !== null ? (float) $pattern->real_cvr : 0.0;
        $refundAdjustedPayout = $payout * (1 - $refund);
        $projectedEpc = $cvr * $refundAdjustedPayout;

        return [
            'projected_epc' => round($projectedEpc, 4),
            'cvr_used' => $cvr,
            'cvr_source' => $pattern !== null ? 'nivor:'.$pattern->niche : 'none',
            'refund_rate' => round($refund, 4),
            'refund_adjusted_payout' => round($refundAdjustedPayout, 2),
            'refund_risk_flag' => $refund >= self::REFUND_RISK,
            'confidence' => $this->confidence($pattern),
        ];
    }

    private function confidence(?AiMarketingWinningPattern $pattern): string
    {
        if ($pattern === null || (float) $pattern->real_cvr <= 0) {
            return 'none';
        }
        $sales = (int) $pattern->sales_total;

        return match (true) {
            $sales >= 200 => 'high',
            $sales >= 30 => 'medium',
            default => 'low',
        };
    }
}
