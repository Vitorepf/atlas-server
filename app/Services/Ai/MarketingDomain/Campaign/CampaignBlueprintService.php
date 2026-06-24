<?php

namespace App\Services\Ai\MarketingDomain\Campaign;

use App\Models\AiMarketingCampaignBlueprint;
use App\Models\AiMarketingVslAsset;
use App\Models\AiMarketingWinningPattern;
use InvalidArgumentException;

/**
 * Deterministic Google-Search launch blueprint. Combines the VSL intelligence
 * (Pillar 1, already extracted) with the unit-economics math and bid rules into
 * a complete, reproducible "how to run it" plan aimed at hitting the first test.
 * No LLM here — the creative is pulled from the VSL asset; everything else is rules.
 */
class CampaignBlueprintService
{
    /** Lower number = launch earlier (cheaper, higher-intent validation first). */
    private const AWARENESS_PRIORITY = [
        'brand' => 1,
        'product_aware' => 2,
        'solution_aware' => 3,
        'problem_aware' => 4,
    ];

    public function __construct(
        private readonly CampaignEconomicsCalculator $economics,
        private readonly BidStrategyDecider $bids,
        private readonly KeywordIntentMapper $intentMapper = new KeywordIntentMapper,
        private readonly NegativeListMiner $negativeMiner = new NegativeListMiner,
        private readonly QualifiedKeywordPatternEngine $qualifiedEngine = new QualifiedKeywordPatternEngine,
        private readonly KeywordQualityIndex $qualityIndex = new KeywordQualityIndex,
        private readonly NegativeKeywordForge $negativeForge = new NegativeKeywordForge,
    ) {}

    /**
     * @param  array<string,mixed>  $inputs
     */
    public function generate(AiMarketingVslAsset $vsl, array $inputs): AiMarketingCampaignBlueprint
    {
        if ((float) ($inputs['payout'] ?? 0) <= 0) {
            throw new InvalidArgumentException('payout (CPA por venda) é obrigatório e deve ser > 0.');
        }

        // Ground the CVR + keywords in real winning data when a Nivor-mined pattern exists.
        $patternNiche = $this->str($inputs['pattern_niche'] ?? null);
        $pattern = $patternNiche !== null
            ? AiMarketingWinningPattern::query()->where('niche', $patternNiche)->first()
            : null;

        $cvrSource = 'assumption_default';
        if (isset($inputs['cvr'])) {
            $cvrSource = 'operator_input';
        } elseif ($pattern !== null && (float) $pattern->real_cvr > 0) {
            $inputs['cvr'] = (float) $pattern->real_cvr;
            $cvrSource = 'nivor_winning_pattern:'.$patternNiche;
        }

        $geo = $this->str($inputs['geo'] ?? null) ?? $this->str($vsl->target_geo) ?? 'US';
        $language = $this->str($vsl->language) ?: 'en';

        $economics = $this->economics->compute($inputs);
        $bidPlan = $this->bids->plan($economics);
        $keywordsPlan = $this->buildKeywordsPlan($vsl, $pattern);
        $structure = $this->buildStructure($geo, $language, $keywordsPlan);
        $angle = $this->buildAngle($vsl, $pattern);
        $adPlan = $this->buildAdPlan($vsl);
        $firstTest = $this->buildFirstTestPlan($economics);

        return AiMarketingCampaignBlueprint::query()->create([
            'vsl_asset_id' => $vsl->id,
            'campaign_ref' => $vsl->campaign_ref,
            'channel' => 'google_search',
            'geo' => $geo,
            'language' => $language,
            'inputs' => array_merge($inputs, ['cvr_source' => $cvrSource]),
            'economics' => $economics,
            'bid_plan' => $bidPlan,
            'structure' => $structure,
            'keywords_plan' => $keywordsPlan,
            'angle' => $angle,
            'ad_plan' => $adPlan,
            'first_test_plan' => $firstTest,
            'status' => 'draft',
            'notes' => $cvrSource === 'assumption_default'
                ? 'Blueprint determinístico. CVR é premissa — confirmar com dado real.'
                : 'Blueprint determinístico, CVR/keywords ancorados em dado real ('.$cvrSource.').',
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function buildKeywordsPlan(AiMarketingVslAsset $vsl, ?AiMarketingWinningPattern $pattern = null): array
    {
        $kw = is_array($vsl->keywords) ? $vsl->keywords : [];
        $clusters = is_array($kw['clusters'] ?? null) ? $kw['clusters'] : [];

        $adGroups = [];
        foreach ($clusters as $i => $c) {
            if (! is_array($c)) {
                continue;
            }
            $awareness = strtolower((string) ($c['awareness'] ?? ''));
            $priority = self::AWARENESS_PRIORITY[$awareness] ?? 5;
            $adGroups[] = [
                'name' => (string) ($c['name'] ?? ('ad_group_'.($i + 1))),
                'awareness' => $awareness,
                'intent' => (string) ($c['intent'] ?? ''),
                'match_type' => (string) ($c['match_type'] ?? 'phrase'),
                'terms' => array_values(array_filter((array) ($c['terms'] ?? []), 'is_string')),
                'priority' => $priority,
                'launch_phase' => $priority <= 3 ? 1 : 2, // problem_aware/unknown defer to phase 2
            ];
        }
        usort($adGroups, static fn (array $a, array $b): int => $a['priority'] <=> $b['priority']);

        // Intent mapping (proven sellers first) + negatives mined from real Nivor performance.
        $intentMapped = $this->intentMapper->map($clusters, $pattern);
        $mined = $this->negativeMiner->mine($pattern);

        // ELITE LAYER — memory-recall arbitrage: harvest owned-root keywords (mechanism/slogan/
        // celebrity/…), score each on the Keyword Quality Index, eliminate waste before spend. This
        // is the RECOMMENDED keyword source; the cluster ad_groups above stay for back-compat.
        $qualifiedRaw = $this->qualifiedEngine->build($vsl);
        $quality = $this->qualityIndex->scoreEngineResult($qualifiedRaw, $vsl);
        $qualified = [
            'tiers' => $qualifiedRaw['tiers'],
            'scored' => $quality['scored'],
            'bands' => $quality['bands'],
            'avg_score' => $quality['avg_score'],
            'eliminated' => $quality['killed'],
            'launch_order' => array_map(static fn (array $t): string => $t['family'], $qualifiedRaw['tiers']),
            'forbidden_product_name' => $qualifiedRaw['artifacts']['product_name'] ?? null,
            'note' => 'Fonte recomendada: re-finders pos-exposicao (raiz propria x modificador). Nome do produto NUNCA bidado. Pontuado/eliminado pelo Quality Index.',
        ];

        // Layered negative set (junk/informational/price/polarity) with morphological variants, protecting
        // the owned roots from the anti-campeã gate — the "remover o desqualificado" infrastructure.
        $ownedRoots = [];
        foreach ((array) ($qualifiedRaw['tiers'] ?? []) as $t) {
            foreach ((array) ($t['roots'] ?? []) as $root) {
                $ownedRoots[] = (string) $root;
            }
        }
        $forged = $this->negativeForge->forge(['protect' => $ownedRoots]);

        $negatives = array_values(array_unique(array_merge(
            $this->standardNegatives(),
            $forged['flat'],
            (array) ($mined['combined'] ?? []),
            (array) ($qualifiedRaw['negatives'] ?? []),
            array_map(static fn (array $k): string => (string) ($k['keyword'] ?? ''), $quality['killed']),
            array_filter((array) ($kw['negatives'] ?? []), 'is_string'),
        )));

        return [
            'qualified' => $qualified,
            'ad_groups' => $adGroups,
            'launch_order' => array_map(static fn (array $g): string => $g['name'], $adGroups),
            'intent_mapped' => $intentMapped,
            'negatives' => $negatives,
            'mined_negatives' => $mined,
            'high_intent_from_vsl' => array_values(array_filter((array) ($kw['high_intent_from_vsl'] ?? []), 'is_string')),
            'proven_keywords' => $pattern !== null ? array_slice((array) $pattern->converting_keywords, 0, 30) : [],
            'proven_keywords_note' => $pattern !== null
                ? 'Keywords que JÁ venderam neste nicho (Nivor) — priorizar no lançamento.'
                : null,
            'notes' => (string) ($kw['notes'] ?? 'Confirmar volume/competição no Keyword Planner antes de subir.'),
        ];
    }

    /**
     * @param  array<string,mixed>  $keywordsPlan
     * @return array<string,mixed>
     */
    private function buildStructure(string $geo, string $language, array $keywordsPlan): array
    {
        return [
            'channel' => 'google_search',
            'network' => 'Search apenas — DESLIGAR Search Partners e Display Network no 1º teste.',
            'consolidation' => 'Hagakure: 1 campanha consolidada por oferta; ad groups por tema (não SKAG) pra alimentar o Smart Bidding.',
            'locations' => $geo,
            'languages' => $language,
            'devices' => 'Todos (monitorar split mobile/desktop; cortar o pior depois de dados).',
            'ad_groups' => array_map(static fn (array $g): array => [
                'name' => $g['name'],
                'awareness' => $g['awareness'],
                'match_type' => $g['match_type'],
                'launch_phase' => $g['launch_phase'],
            ], $keywordsPlan['ad_groups'] ?? []),
            'quality_score_levers' => 'CTR esperado + relevância do anúncio + experiência da landing — manter os 3 altos baixa o CPC.',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function buildAngle(AiMarketingVslAsset $vsl, ?AiMarketingWinningPattern $pattern = null): array
    {
        $brief = is_array($vsl->advertorial_brief) ? $vsl->advertorial_brief : [];
        $funnel = is_array($vsl->funnel_kit) ? $vsl->funnel_kit : [];
        $titles = array_values(array_filter((array) ($brief['title_options'] ?? []), 'is_string'));

        return [
            'recommended_angle' => $this->str($funnel['recommended_presell_angle'] ?? null)
                ?? $this->str($brief['congruency_bridge'] ?? null)
                ?? 'Definir ângulo a partir do mecanismo do problema da VSL.',
            'awareness_entry_point' => $this->str($funnel['awareness_entry_point'] ?? null),
            'funnel_stages' => ['Anúncio (Search)', 'Advertorial/Bridge (domínio próprio)', 'VSL', 'Checkout'],
            'page_types' => [
                'cold_traffic' => 'Listicle advertorial (conteúdo original, próprio domínio) — maior CTR e passa em política.',
                'bridge_format' => $this->str($brief['format'] ?? null) ?: 'listicle',
            ],
            'advertorial_title_options' => $titles,
            'message_match' => $this->str($funnel['message_match_notes'] ?? null)
                ?? $this->str($brief['congruency_bridge'] ?? null),
            'vsl_terms_to_weave' => array_values(array_filter((array) ($brief['vsl_terms_to_weave'] ?? []), 'is_string')),
            'proven_funnels' => $pattern !== null ? array_slice((array) $pattern->winning_funnels, 0, 8) : [],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function buildAdPlan(AiMarketingVslAsset $vsl): array
    {
        $assets = is_array($vsl->ad_assets) ? $vsl->ad_assets : [];

        return [
            'format' => 'RSA (Responsive Search Ad) — 1 por ad group no 1º teste.',
            'headlines' => array_values(array_filter((array) ($assets['headlines'] ?? []), 'is_string')),
            'descriptions' => array_values(array_filter((array) ($assets['descriptions'] ?? []), 'is_string')),
            'sitelinks' => array_values(array_filter((array) ($assets['sitelinks'] ?? []), 'is_string')),
            'callouts' => array_values(array_filter((array) ($assets['callouts'] ?? []), 'is_string')),
            'structured_snippets' => array_values(array_filter((array) ($assets['structured_snippets'] ?? []), 'is_string')),
            'rules' => [
                'Headlines ≤ 30 caracteres; descriptions ≤ 90 — revisar antes de subir (o modelo às vezes estoura).',
                'Pinar só o que for obrigatório por estrutura/política.',
                'Message-match: a headline tem que ecoar a keyword do ad group e a promessa da VSL.',
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $economics
     * @return array<string,mixed>
     */
    private function buildFirstTestPlan(array $economics): array
    {
        $cur = (string) ($economics['currency'] ?? 'USD');

        return [
            'daily_budget' => $economics['daily_budget'],
            'currency' => $cur,
            'decision_spend' => $economics['test_decision_spend'],
            'clicks_to_decision' => $economics['clicks_to_decision'],
            'launch_scope' => 'Subir primeiro os ad groups de fase 1 (brand + product_aware + solution_aware); problem_aware entra na fase 2.',
            'kill_rules' => [
                "Matar keyword/ad group ao gastar ~{$economics['kill_spend_no_sale']} {$cur} com 0 vendas.",
                "Julgar o teste ao atingir ~{$economics['test_decision_spend']} {$cur} de gasto (≈{$economics['clicks_to_decision']} cliques).",
                "CPA real acima do Max CPA ({$economics['max_cpa']} {$cur}) de forma sustentada → consertar funil ou matar.",
            ],
            'scale_rules' => [
                'Escalar só +10–20% de budget por passo, espaçado 7–14 dias (jump grande reseta aprendizado).',
                'Escalar horizontal (novos geos/temas/criativos) em vez de chocar o vencedor.',
            ],
            'watch_funnel' => [
                'CTR do anúncio → ângulo/headline/intenção da keyword',
                'bridge → VSL → congruência do advertorial com a VSL',
                'chegada no pitch → gancho/retenção da VSL',
                'checkout → oferta/fechamento/preço',
            ],
        ];
    }

    /**
     * @return array<int,string>
     */
    private function standardNegatives(): array
    {
        return ['free', 'gratis', 'grátis', 'cheap', 'recipe', 'receita', 'diy', 'job', 'jobs', 'salary', 'download', 'torrent', 'sample'];
    }

    private function str(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
