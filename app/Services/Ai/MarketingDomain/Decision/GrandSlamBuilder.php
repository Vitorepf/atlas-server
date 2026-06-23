<?php

namespace App\Services\Ai\MarketingDomain\Decision;

use App\Models\AiMarketingVslAsset;
use App\Services\Ai\MarketingDomain\Knowledge\MarketingPlaybook;
use App\Services\Ai\MarketingDomain\Scoring\PricingPsychologyScorer;

/**
 * GrandSlamBuilder — the grand-slam-builder skill. From a real extracted offer (+ its objections
 * and the OfferDoctorScorer weakest term) it prescribes the stack that makes the offer "burro dizer
 * não": bonuses mapped 1:1 to objections, a stronger guarantee reframe, real scarcity angles, a MAGIC
 * naming formula, and a decoy pricing table. Deterministic; raises CVR without touching traffic.
 */
class GrandSlamBuilder
{
    public function __construct(
        private readonly MarketingPlaybook $playbook = new MarketingPlaybook,
        private readonly PricingPsychologyScorer $pricing = new PricingPsychologyScorer,
    ) {}

    /**
     * @param  array<int,string>  $objections
     * @param  array<string,mixed>|null  $offerScore  OfferDoctorScorer::score() output
     * @return array<string,mixed>
     */
    public function build(AiMarketingVslAsset $asset, array $objections = [], ?array $offerScore = null): array
    {
        $objections = $objections !== [] ? $objections : $this->playbook->commonObjectionsByVertical($asset->niche);
        $offer = is_array($asset->offer) ? $asset->offer : [];
        $price = (float) ($offer['price'] ?? 0) ?: 49.0;
        $gs = $this->playbook->grandSlam();

        $bonusStack = [];
        foreach ($objections as $i => $obj) {
            $bonusStack[] = [
                'removes_objection' => $obj,
                'bonus' => "Bônus #".($i + 1)." que elimina: \"{$obj}\"",
                'anchored_value' => '$'.(($i + 1) * 47),
            ];
        }

        $weakest = is_array($offerScore) ? (string) ($offerScore['weakest_term'] ?? '') : '';

        return [
            'vsl_id' => $asset->id,
            'bonus_stack' => $bonusStack,
            'total_anchored_value' => '$'.array_sum(array_map(static fn (int $i): int => ($i + 1) * 47, array_keys($objections))),
            'guarantee' => [
                'current_detected' => $this->detectGuarantee($asset),
                'reframe' => 'Subir pra incondicional/"melhor que dinheiro de volta" — inverte 100% do risco.',
                'rule' => $gs['stack']['guarantee'],
            ],
            'scarcity_angles' => ['quantidade limitada (estoque real)', 'prazo do bônus (some à meia-noite)', 'preço de lançamento por X dias'],
            'naming_formula' => [
                'mechanism' => trim((string) $asset->mechanism_name) ?: 'nomear o mecanismo (proprietário, memorável)',
                'rule' => $gs['stack']['naming'],
            ],
            'pricing_table' => $this->pricing->evaluate($asset, $price),
            'emphasis' => $weakest !== ''
                ? "Termo mais fraco do offer = {$weakest} → priorizar o elemento da stack que o levanta."
                : 'Stack completa — empilhar todos os elementos.',
        ];
    }

    private function detectGuarantee(AiMarketingVslAsset $asset): string
    {
        $offer = is_array($asset->offer) ? $asset->offer : [];
        if (trim((string) ($offer['guarantee'] ?? '')) !== '') {
            return (string) $offer['guarantee'];
        }
        $t = strtolower((string) $asset->transcript);
        foreach (['60-day', '90-day', '30-day', 'money-back', 'risk-free'] as $m) {
            if (str_contains($t, $m)) {
                return $m;
            }
        }

        return 'nenhuma detectada';
    }
}
