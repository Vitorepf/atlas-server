<?php

namespace App\Services\Ai\MarketingDomain\Scoring;

use App\Models\AiMarketingVslAsset;
use App\Services\Ai\MarketingDomain\Knowledge\MarketingPlaybook;

/**
 * PricingPsychologyScorer — scores the 4 pricing levers (decoy, charm, anchoring, risk-reversal)
 * present in a real extracted offer and emits a recommended 3-tier decoy table with the decoy math.
 * Deterministic; no DB. The decoy (asymmetric dominance) makes the BEST tier the obvious pick.
 */
class PricingPsychologyScorer
{
    public function __construct(private readonly MarketingPlaybook $playbook = new MarketingPlaybook) {}

    /**
     * @return array<string,mixed>
     */
    public function evaluate(AiMarketingVslAsset $asset, float $currentPrice): array
    {
        $offer = is_array($asset->offer) ? $asset->offer : [];
        $transcript = strtolower((string) $asset->transcript);
        $tiers = is_array($offer['tiers'] ?? null) ? $offer['tiers'] : [];

        $levers = [
            'decoy' => count($tiers) >= 3 ? 10 : (count($tiers) === 2 ? 5 : 0),
            'charm' => $this->isCharm($currentPrice) ? 10 : 0,
            'anchoring' => ($this->filled($offer['anchor_price'] ?? $offer['retail'] ?? null) || $this->hasAny($transcript, ['normally', 'retail', 'value of', 'worth', 'normalmente', 'valor de'])) ? 10 : 0,
            'risk_reversal' => ($this->filled($offer['guarantee'] ?? null) || $this->hasAny($transcript, ['guarantee', 'money-back', 'refund', 'risk-free', 'garantia', 'reembolso'])) ? 10 : 0,
        ];

        $score = (int) round(array_sum($levers) / (count($levers) * 10) * 100);

        return [
            'pricing_score' => $score,
            'lever_scores' => $levers,
            'weakest_lever' => $this->weakest($levers),
            'recommended_table' => $this->decoyTable($currentPrice),
            'knowledge' => $this->playbook->grandSlam()['pricing_psychology'],
            'note' => 'Tabela 3-tier com decoy: o tier do meio (BEST) ancora o premium e domina o básico.',
        ];
    }

    /**
     * Build a 3-tier table where the middle "BEST" tier is the asymmetric-dominance pick.
     *
     * @return array<int,array<string,mixed>>
     */
    public function decoyTable(float $price): array
    {
        $price = $price > 0 ? $price : 49.0;
        $basic = round($this->charmify($price), 2);
        $best = round($this->charmify($price * 2.2), 2);   // most value-per-dollar (the target)
        $premium = round($this->charmify($price * 2.5), 2); // small premium over BEST = the decoy anchor

        return [
            ['tier' => 'basic', 'price' => $basic, 'role' => 'entrada', 'units' => '1 unidade'],
            ['tier' => 'best_value', 'price' => $best, 'role' => 'ALVO (decoy-dominante)', 'units' => '3 unidades + bônus', 'highlight' => true],
            ['tier' => 'premium', 'price' => $premium, 'role' => 'âncora (faz o BEST parecer barato)', 'units' => '6 unidades'],
        ];
    }

    private function isCharm(float $p): bool
    {
        $cents = (int) round(($p - floor($p)) * 100);

        return in_array($cents, [99, 97, 95], true) || (int) $p % 10 === 9;
    }

    private function charmify(float $p): float
    {
        return ceil($p) - 0.01; // charm pricing: sit just under the round number
    }

    /**
     * @param  array<string,int>  $levers
     */
    private function weakest(array $levers): string
    {
        asort($levers);

        return (string) array_key_first($levers);
    }

    private function filled(mixed $v): bool
    {
        return is_array($v) ? $v !== [] : trim((string) $v) !== '';
    }

    /**
     * @param  array<int,string>  $needles
     */
    private function hasAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $n) {
            if ($n !== '' && str_contains($haystack, $n)) {
                return true;
            }
        }

        return false;
    }
}
