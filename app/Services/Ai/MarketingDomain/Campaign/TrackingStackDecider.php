<?php

namespace App\Services\Ai\MarketingDomain\Campaign;

use App\Models\AiMarketingWinningPattern;
use App\Services\Ai\MarketingDomain\Knowledge\MarketingPlaybook;

/**
 * TrackingStackDecider — the tracking-setup skill. Recommends the tracker tier (by click throughput),
 * the conversion-delay window for data exclusions, and the attribution approach by channel — from the
 * real Nivor volume. Deterministic.
 *
 * @unwired-until 2026-08-05 (Obra #7 W2: capability testada aguardando consumidor; triagem 2026-07-06)
 */
class TrackingStackDecider
{
    public function __construct(private readonly MarketingPlaybook $playbook = new MarketingPlaybook) {}

    /**
     * @return array<string,mixed>
     */
    public function decide(AiMarketingWinningPattern $pattern): array
    {
        $clicks = (int) $pattern->clicks_total;
        $monthlyClicks = $clicks; // pattern is already an aggregate snapshot

        $tracker = match (true) {
            $monthlyClicks >= 1_000_000 => 'Binom (self-hosted, ~10M cliques/dia) — maior throughput',
            $monthlyClicks >= 100_000 => 'Voluum / RedTrack — escala média, SaaS',
            default => 'ClickMagick / RedTrack — volume inicial',
        };

        $cp = $this->playbook->conversionPipeline();

        return [
            'skill' => 'tracking-setup',
            'niche' => $pattern->niche,
            'monthly_clicks' => $monthlyClicks,
            'recommended_tracker' => $tracker,
            'trackers_reference' => $cp['trackers_by_volume'],
            'conversion_delay_window_days' => 7, // estender as data-exclusions por esta janela
            'attribution_approach' => [
                'google' => 'OCI/GCLID + Enhanced Conversions (Data Manager API) — fonte de bid',
                'cross_channel' => 'CAPI + server-side (GTM SS) + Consent Mode v2',
                'ga4' => 'suplementar, NUNCA fonte de bid',
            ],
            'note' => 'Tracker por throughput + janela de conversion-delay pras data-exclusions quando o postback cai.',
        ];
    }
}
