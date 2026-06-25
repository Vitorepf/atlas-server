<?php

namespace App\Services\Ai\MarketingDomain\Campaign;

use App\Models\AiMarketingVslAsset;

/**
 * KeywordIntelligencePipeline (L12 — orquestração) — costura os motores soltos em UM SISTEMA determinístico
 * que roda o fluxo inteiro sobre uma oferta: comprehension (L1) → DESCOBERTA do universo (L2) → grade de
 * intenção/qualidade/investimento/risco (L3/L4/L11) → negativas (L6) → seleção launch-ready + Decision-
 * Receipt por keyword (L12). É o que transforma "12 peças" em "INFRAESTRUTURA completa" capaz de escalar.
 *
 * Idempotente e provider-free: mesma oferta + mesma economia → MESMA saída (run_hash bit-a-bit), com
 * proveniência por keyword. Determinismo por construção — sem I/O, clock ou randomness no caminho.
 */
class KeywordIntelligencePipeline
{
    public function __construct(
        private readonly KeywordUniverseEnumerator $enumerator = new KeywordUniverseEnumerator,
        private readonly KeywordQualityIndex $qualityIndex = new KeywordQualityIndex,
        private readonly NegativeKeywordForge $negativeForge = new NegativeKeywordForge,
        private readonly QualifiedKeywordDossier $dossier = new QualifiedKeywordDossier,
        private readonly KeywordClusterer $clusterer = new KeywordClusterer,
        private readonly KeywordVolumeSignal $volumeSignal = new KeywordVolumeSignal,
        private readonly KeywordRegimeClassifier $regimes = new KeywordRegimeClassifier,
        private readonly KeywordRevenueProjector $revenue = new KeywordRevenueProjector,
        private readonly KeywordBudgetAllocator $allocator = new KeywordBudgetAllocator,
    ) {}

    /**
     * @param  array<string,mixed>  $econ  payout, refund, margin, cvr (enables the investment gate)
     * @return array{offer_fingerprint:string,universe:array<string,mixed>,scored:array<int,array<string,mixed>>,launch_selection:array<string,mixed>,negatives:array<string,mixed>,knowledge_version:string,run_hash:string}
     */
    public function run(AiMarketingVslAsset $asset, array $econ = [], array $opts = []): array
    {
        $roots = $this->ownedRoots($asset);
        $product = $this->product($asset);

        // L2 — origina o universo (sem buracos, determinístico)
        $universe = $this->enumerator->enumerate($roots);

        // L2 — junta o grid sintético com os search-terms REAIS descobertos (harvester), deduplicados
        $scorable = $this->enumerator->asScorableTier($universe, $product);
        $discovered = $this->discoveredTier($roots, (array) ($opts['discovered_terms'] ?? []), $universe);
        if ($discovered !== null) {
            $scorable['tiers'][] = $discovered;
        }

        // L3/L4/L11 — pontua + intenção + investimento + mente + risco-de-conta (elimina lixo antes)
        $quality = $this->qualityIndex->scoreEngineResult(
            $scorable,
            $asset,
            array_merge($econ, ['outcome_calibration' => (array) ($opts['outcome_calibration'] ?? [])]), // L10 flywheel real
        );

        // L6 — negativas (protege os owned roots: anti-campeã) + negativos aterrados no waste real (miner)
        $negatives = $this->negativeForge->forge([
            'protect' => $roots,
            'mined' => (array) ($opts['mined_negatives'] ?? []),
        ]);

        // L12 — seleção launch-ready + Decision-Receipt por keyword. compliance_mode default OFF: o motor
        // recomenda a malícia agressiva; quarentena de risco-de-conta só quando o operador pede o fluxo.
        $launch = $this->dossier->select($quality['scored'], 5, ['compliance_mode' => (bool) ($opts['compliance_mode'] ?? false)]);

        $fingerprint = $this->fingerprint($asset, $econ);
        $revenueRanking = $this->revenue->rank($quality['scored'], $econ, (array) ($opts['volume_map'] ?? []), (array) ($opts['cvr_map'] ?? []));
        $budget = (float) ($opts['budget'] ?? 0);

        return [
            'offer_fingerprint' => $fingerprint,
            'universe' => ['count' => $universe['count'], 'complete' => $universe['complete'], 'roots' => $universe['roots']],
            'discovered_count' => $discovered === null ? 0 : count($discovered['keywords']), // L2: termos reais novos mesclados
            'clusters' => $this->clusterer->cluster((array) ($universe['keywords'] ?? [])), // L7 STAG ad groups
            'volume_priority' => $this->volumeSignal->prioritize($quality['scored'], (array) ($opts['volume_map'] ?? [])), // L5 demanda×intenção
            'regimes' => $this->regimes->partition($quality['scored'], $this->ownedRoots($asset)), // #6: colheita/semeadura/sonda isolados (alavanca de escala); owned-roots → nome próprio nu = harvest
            'revenue_ranking' => $revenueRanking, // NORTE: ordena por LUCRO esperado (volume×CVR-real×payout − custo)
            'budget_portfolio' => $budget > 0 ? $this->allocator->allocate($revenueRanking['ranked'], $budget) : null, // como gastar R$budget pra MÁXIMO lucro
            'scored' => $quality['scored'],
            'launch_selection' => $launch,
            'negatives' => $negatives,
            'knowledge_version' => KeywordKnowledgeCore::VERSION,
            'run_hash' => sha1($fingerprint.'|'.KeywordKnowledgeCore::VERSION),
        ];
    }

    /**
     * @return array<int,string> owned roots = artefatos coined pós-exposição-VSL (re-finder roots). Inclui
     * mechanism/trick E os SLOGANS coined (power_phrases) — alinhado com a tese e o QualifiedKeywordPattern-
     * Engine; antes só mechanism+trick deixava oferta coined-por-slogan com universo VAZIO (buraco achado no
     * painel adversarial, ciclo 36).
     */
    private function ownedRoots(AiMarketingVslAsset $asset): array
    {
        $raw = array_merge(
            [$asset->mechanism_name, $asset->trick],
            array_values((array) ($asset->power_phrases ?? [])),
        );

        return array_values(array_unique(array_filter(
            array_map(fn ($s) => mb_strtolower(trim((string) $s)), $raw),
            fn ($s) => $s !== '',
        )));
    }

    /**
     * L2 — transforma os search-terms REAIS colhidos (BlackinkSearchTermHarvester) num tier pontuável,
     * deduplicado contra o grid sintético pra nada contar em dobro. Passam pelos mesmos gates (intenção/
     * investimento/risco) + recibos. Determinístico: mesmos termos → mesma saída.
     *
     * @param  array<int,string>  $roots
     * @param  array<int,string>  $terms
     * @param  array<string,mixed>  $universe
     * @return array<string,mixed>|null
     */
    private function discoveredTier(array $roots, array $terms, array $universe): ?array
    {
        $existing = [];
        foreach ((array) ($universe['keywords'] ?? []) as $row) {
            $existing[mb_strtolower(trim((string) ($row['keyword'] ?? '')))] = true;
        }
        $fresh = [];
        foreach ($terms as $t) {
            $t = mb_strtolower(trim((string) $t));
            if ($t === '' || isset($existing[$t]) || isset($fresh[$t])) {
                continue;
            }
            $fresh[$t] = true;
        }
        if ($fresh === []) {
            return null;
        }

        return [
            'family' => 'discovered_real', // veio de busca real, não do grid sintético
            'qualification' => 'high',
            'match_type' => 'phrase',
            'roots' => $roots,
            'keywords' => array_keys($fresh),
            'tier_hint' => 'discovered',
        ];
    }

    private function product(AiMarketingVslAsset $asset): string
    {
        $offer = (array) ($asset->offer ?? []);

        return (string) ($offer['product_name'] ?? '');
    }

    /** Canonical, deterministic fingerprint of the offer + economics → idempotency key. */
    private function fingerprint(AiMarketingVslAsset $asset, array $econ): string
    {
        ksort($econ);
        $parts = [
            'mechanism:'.mb_strtolower((string) $asset->mechanism_name),
            'trick:'.mb_strtolower((string) $asset->trick),
            'product:'.mb_strtolower($this->product($asset)),
        ];
        foreach ($econ as $k => $v) {
            $parts[] = $k.'='.(is_scalar($v) ? (string) $v : '');
        }

        return sha1(implode('|', $parts));
    }
}
