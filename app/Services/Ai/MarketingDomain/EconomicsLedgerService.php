<?php

namespace App\Services\Ai\MarketingDomain;

use App\Models\AiMarketingEconomicsLedgerEntry;
use Illuminate\Support\Carbon;

/**
 * EconomicsLedgerService — records the ECONOMIC outcome of a campaign and serves per-niche priors so
 * CampaignEconomicsCalculator can start from what ACTUALLY happened in a niche (median CVR/ROAS/margin)
 * instead of generic assumptions. This compounds the whole stack once real data accumulates.
 */
class EconomicsLedgerService
{
    /**
     * @param  array<string,mixed>  $economics  payout/cvr_actual/refund_rate_actual/max_cpa_set/
     *                                           max_cpa_achieved/roas_actual/margin_actual/epc/revenue/cost
     */
    public function recordOutcome(string $campaignRef, string $niche, array $economics): AiMarketingEconomicsLedgerEntry
    {
        return AiMarketingEconomicsLedgerEntry::query()->create([
            'campaign_ref' => $campaignRef,
            'niche' => $niche,
            'offer_type' => $economics['offer_type'] ?? null,
            'payout' => $this->num($economics['payout'] ?? null),
            'cvr_actual' => $this->num($economics['cvr_actual'] ?? null),
            'refund_rate_actual' => $this->num($economics['refund_rate_actual'] ?? null),
            'max_cpa_set' => $this->num($economics['max_cpa_set'] ?? null),
            'max_cpa_achieved' => $this->num($economics['max_cpa_achieved'] ?? null),
            'roas_actual' => $this->num($economics['roas_actual'] ?? null),
            'margin_actual' => $this->num($economics['margin_actual'] ?? null),
            'epc' => $this->num($economics['epc'] ?? null),
            'revenue' => $this->num($economics['revenue'] ?? null),
            'cost' => $this->num($economics['cost'] ?? null),
            'recorded_at' => Carbon::now(),
        ]);
    }

    /**
     * Niche priors to feed CampaignEconomicsCalculator (caller passes these as inputs — the calculator
     * stays pure). Returns null fields when there is no history yet (→ fall back to assumptions).
     *
     * @return array<string,mixed>
     */
    public function getFloorsByNiche(string $niche): array
    {
        $rows = AiMarketingEconomicsLedgerEntry::query()->where('niche', $niche)->get();
        $sample = $rows->count();

        if ($sample === 0) {
            return ['niche' => $niche, 'sample_size' => 0, 'has_priors' => false];
        }

        return [
            'niche' => $niche,
            'sample_size' => $sample,
            'has_priors' => true,
            'avg_max_cpa' => $this->avg($rows->pluck('max_cpa_achieved')->all()),
            'median_cvr' => $this->median($rows->pluck('cvr_actual')->all()),
            'median_roas' => $this->median($rows->pluck('roas_actual')->all()),
            'median_margin' => $this->median($rows->pluck('margin_actual')->all()),
            'median_refund' => $this->median($rows->pluck('refund_rate_actual')->all()),
        ];
    }

    /**
     * @param  array<int,mixed>  $values
     */
    private function avg(array $values): ?float
    {
        $nums = array_values(array_filter(array_map(fn ($v) => $this->num($v), $values), static fn ($v): bool => $v !== null));

        return $nums === [] ? null : round(array_sum($nums) / count($nums), 4);
    }

    /**
     * @param  array<int,mixed>  $values
     */
    private function median(array $values): ?float
    {
        $nums = array_values(array_filter(array_map(fn ($v) => $this->num($v), $values), static fn ($v): bool => $v !== null));
        if ($nums === []) {
            return null;
        }
        sort($nums);
        $n = count($nums);
        $mid = (int) floor($n / 2);

        return round($n % 2 ? $nums[$mid] : ($nums[$mid - 1] + $nums[$mid]) / 2, 5);
    }

    private function num(mixed $v): ?float
    {
        return ($v !== null && $v !== '' && is_numeric($v)) ? (float) $v : null;
    }
}
