<?php

namespace App\Services\Ai\MarketingDomain\Campaign;

use App\Models\AiMarketingVslAsset;
use App\Models\AiMarketingWinningPattern;

/**
 * SearchNetworkPlanner — the missing wiring: it turns a DISSECTED VSL into a complete Google Search
 * plan by feeding the VSL's own keyword clusters into the existing engine pieces (KeywordIntentMapper,
 * AccountStructurer, BroadMatchStrategist, NegativeListMiner) and adding a curated negative list that
 * blocks the clicks that never buy (free/recipe/diy/jobs/side-effects/reviews). Before this, those
 * pieces existed but nothing connected them to the VSL — so the engine "knew nothing". Deterministic.
 */
class SearchNetworkPlanner
{
    public function __construct(
        private readonly KeywordIntentMapper $intent = new KeywordIntentMapper,
        private readonly AccountStructurer $structurer = new AccountStructurer,
        private readonly BroadMatchStrategist $broad = new BroadMatchStrategist,
        private readonly NegativeListMiner $negatives = new NegativeListMiner,
    ) {}

    /**
     * @param  array<string,mixed>  $opts  pattern (AiMarketingWinningPattern), daily_conversions (int)
     * @return array<string,mixed>
     */
    public function plan(AiMarketingVslAsset $asset, array $opts = []): array
    {
        $pattern = ($opts['pattern'] ?? null) instanceof AiMarketingWinningPattern ? $opts['pattern'] : null;
        $dailyConv = (int) ($opts['daily_conversions'] ?? 0);
        $clusters = $this->clusters($asset);

        $adGroups = $this->intent->map($clusters, $pattern);
        $structure = $this->structurer->structure($clusters, $dailyConv > 0 ? $dailyConv * 30 : null);
        $matchMix = $this->broad->matchMix($dailyConv, ($opts['conversion_loop_complete'] ?? false) === true);

        return [
            'ad_groups' => $adGroups,
            'match_mix' => $matchMix,
            'negatives' => $this->mergedNegatives($asset, $pattern),
            'account_structure' => $structure,
            'note' => 'Plano de rede de pesquisa derivado DA VSL (clusters próprios) + negativas curadas anti-tráfego-ruim.',
        ];
    }

    /**
     * VSL's own keyword clusters (the extractor stored {name, awareness, intent, match_type, terms}).
     *
     * @return array<int,array<string,mixed>>
     */
    private function clusters(AiMarketingVslAsset $asset): array
    {
        $kw = is_array($asset->keywords) ? $asset->keywords : [];
        $clusters = is_array($kw['clusters'] ?? null) ? $kw['clusters'] : [];

        return array_values(array_filter($clusters, 'is_array'));
    }

    /**
     * Pattern-mined negatives + a curated universal list of "clicks that never buy" for VSL offers.
     *
     * @return array<string,mixed>
     */
    private function mergedNegatives(AiMarketingVslAsset $asset, ?AiMarketingWinningPattern $pattern): array
    {
        $mined = $this->negatives->mine($pattern);
        $minedTerms = [];
        array_walk_recursive($mined, static function ($v) use (&$minedTerms): void {
            if (is_string($v) && str_word_count($v) >= 1 && mb_strlen($v) <= 30) {
                $minedTerms[] = mb_strtolower($v);
            }
        });

        $pt = $this->lang($asset) === 'pt';
        $curated = $pt ? [
            'grátis', 'receita', 'como fazer', 'caseiro', 'faça você mesmo', 'bula', 'efeitos colaterais',
            'é golpe', 'reclame aqui', 'processo', 'recall', 'emprego', 'salário', 'pdf', 'download',
            'amazon', 'mercado livre', 'farmácia', 'cupom', 'wikipedia', 'preço', 'genérico',
        ] : [
            'free', 'recipe', 'how to make', 'diy', 'homemade', 'side effects', 'dangers', 'scam',
            'complaints', 'lawsuit', 'recall', 'jobs', 'salary', 'pdf', 'download', 'wikipedia',
            'amazon', 'walmart', 'gnc', 'ebay', 'coupon', 'near me', 'reviews bbb', 'is it safe',
        ];

        $all = array_values(array_unique(array_merge($minedTerms, $curated)));

        return [
            'campaign_negatives' => $all,
            'count' => count($all),
            'rationale' => $pt
                ? 'Bloqueia curiosos/pesquisadores/caça-grátis/preocupados-com-efeito que clicam e não compram.'
                : 'Blocks freebie-seekers, researchers, recipe/DIY, side-effect worriers and bargain hunters that click but never buy.',
        ];
    }

    private function lang(AiMarketingVslAsset $asset): string
    {
        $geo = mb_strtolower((string) $asset->target_geo.' '.$asset->language);

        return (str_contains($geo, 'pt') || str_contains($geo, 'br') || str_contains($geo, 'portug')) ? 'pt' : 'en';
    }
}
