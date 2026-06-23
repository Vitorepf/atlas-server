<?php

namespace App\Services\Ai\MarketingDomain\Campaign;

use App\Services\Ai\MarketingDomain\Knowledge\MarketingPlaybook;

/**
 * FunnelSequenceDecider — the funnel-architect skill. Sequences the value-ladder stages by traffic
 * temperature and product economics (cold → advertorial first; warm → more direct; higher price →
 * add order bump / OTO). Deterministic.
 */
class FunnelSequenceDecider
{
    public function __construct(private readonly MarketingPlaybook $playbook = new MarketingPlaybook) {}

    /**
     * @param  array<string,mixed>  $offer  price
     * @return array<string,mixed>
     */
    public function recommendFunnelSequence(array $offer, string $trafficTemp = 'cold', string $niche = '', float $budget = 0): array
    {
        $ladder = $this->playbook->valueLadder();
        $price = (float) ($offer['price'] ?? 0);
        $temp = strtolower($trafficTemp);

        $stages = $temp === 'cold'
            ? ['advertorial', 'sales_page']
            : ['squeeze', 'sales_page'];

        // Higher-ticket offers earn an order bump + OTO.
        if ($price >= 60) {
            $stages[] = 'order_bump';
            $stages[] = 'oto_upsell';
        }

        $lift = $temp === 'cold' ? '+30-100% EPC vs direct-link (advertorial pré-aquece e passa em política)' : 'menor lift (tráfego já aquecido)';

        return [
            'skill' => 'funnel-architect',
            'traffic_temperature' => $temp,
            'stages' => array_map(static fn (string $s): array => ['stage' => $s, 'role' => $ladder[$s] ?? ''], $stages),
            'rationale' => $temp === 'cold'
                ? 'Tráfego frio está em modo consumir → liderar com advertorial antes da sales page.'
                : 'Tráfego quente/remarketing → squeeze/direto, menos pré-venda.',
            'estimated_cvr_lift_vs_direct' => $lift,
            'build_priority' => $stages,
        ];
    }
}
