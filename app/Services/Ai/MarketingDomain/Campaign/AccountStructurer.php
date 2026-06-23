<?php

namespace App\Services\Ai\MarketingDomain\Campaign;

use App\Services\Ai\MarketingDomain\Knowledge\MarketingPlaybook;

/**
 * AccountStructurer — the account-structurer skill. Picks the Search architecture by data volume so
 * Smart Bidding gets enough signal: few keywords → broad + Smart (Hagakure); many → STAG by theme;
 * winners graduate to an Alpha campaign. Deterministic.
 */
class AccountStructurer
{
    public function __construct(private readonly MarketingPlaybook $playbook = new MarketingPlaybook) {}

    /**
     * @param  array<int,array<string,mixed>>  $clusters
     * @return array<string,mixed>
     */
    public function structure(array $clusters, ?int $monthlyConversions = null): array
    {
        $kwCount = 0;
        foreach ($clusters as $c) {
            $kwCount += count((array) ($c['terms'] ?? []));
        }
        $structures = $this->playbook->accountStructures();

        [$architecture, $reason] = match (true) {
            $kwCount <= 50 => ['hagakure_broad_smart', $structures['meta_today']],
            $kwCount <= 200 => ['stag_by_theme', $structures['stag']],
            default => ['alpha_beta', $structures['alpha_beta']],
        };

        return [
            'skill' => 'account-structurer',
            'keyword_count' => $kwCount,
            'architecture' => $architecture,
            'reason' => $reason,
            'ad_groups' => array_map(static fn (array $c, int $i): array => [
                'name' => (string) ($c['name'] ?? ('theme_'.($i + 1))),
                'theme' => (string) ($c['intent'] ?? $c['awareness'] ?? 'tema'),
                'terms' => array_values(array_filter((array) ($c['terms'] ?? []), 'is_string')),
            ], $clusters, array_keys($clusters)),
            'audience_signals' => $this->playbook->audienceSignals(),
            'consolidation_rule' => $structures['hagakure'],
            'smart_bidding_ready' => ($monthlyConversions ?? 0) >= 30,
        ];
    }
}
