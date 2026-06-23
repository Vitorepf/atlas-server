<?php

namespace App\Services\Ai\MarketingDomain\Campaign;

use App\Services\Ai\MarketingDomain\Knowledge\MarketingPlaybook;

/**
 * RSAWriter — the rsa-writer skill. For an ad group, lays out the RSA skeleton (15 headlines / 4
 * descriptions per the spec), a pin map (only pin what's structurally/legally required), and a
 * Quality-Score lever score. Reuses the extracted ad_assets when present. Deterministic.
 */
class RSAWriter
{
    public function __construct(private readonly MarketingPlaybook $playbook = new MarketingPlaybook) {}

    /**
     * @param  array<string,mixed>  $adGroup   name, theme, terms[]
     * @param  array<string,mixed>  $adAssets  extracted headlines[]/descriptions[]
     * @return array<string,mixed>
     */
    public function write(array $adGroup, array $adAssets = []): array
    {
        $spec = $this->playbook->rsaSpec();
        $headlines = array_values(array_filter((array) ($adAssets['headlines'] ?? []), 'is_string'));
        $descriptions = array_values(array_filter((array) ($adAssets['descriptions'] ?? []), 'is_string'));
        $terms = array_values(array_filter((array) ($adGroup['terms'] ?? []), 'is_string'));

        // Fill toward 15 headlines using the ad-group terms (then generic seeds) as message-match seeds.
        $hSeeds = [];
        foreach ($terms as $t) {
            $hSeeds[] = "[seed] headline ecoando a keyword: {$t}";
        }
        $allHeadlines = array_merge($headlines, $hSeeds);
        for ($i = count($allHeadlines); $i < 15; $i++) {
            $allHeadlines[] = '[seed] headline '.($i + 1).' (benefício/prova/CTA)';
        }
        $allHeadlines = array_slice($allHeadlines, 0, 15);

        // Always emit exactly 4 descriptions.
        $dSeeds = ['[seed] description com a promessa + prova', '[seed] description com CTA + risk-reversal', '[seed] description com escassez/urgência', '[seed] description com message-match da keyword'];
        $allDescriptions = array_merge($descriptions, $dSeeds);
        $allDescriptions = array_slice($allDescriptions, 0, 4);

        return [
            'skill' => 'rsa-writer',
            'ad_group' => (string) ($adGroup['name'] ?? 'ad_group'),
            'spec' => $spec,
            'headlines' => $allHeadlines,
            'descriptions' => $allDescriptions,
            'real_headline_count' => count($headlines),
            'pin_map' => ['pin apenas o obrigatório por estrutura/política — deixar o resto rotacionar'],
            'quality_score_levers' => $spec['quality_score'],
            'message_match_rule' => 'cada headline deve ecoar a keyword do ad group e a promessa da VSL',
            'needs_review' => count($headlines) < 15 || count($descriptions) < 4,
        ];
    }
}
