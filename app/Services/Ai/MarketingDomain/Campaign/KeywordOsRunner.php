<?php

namespace App\Services\Ai\MarketingDomain\Campaign;

use App\Models\AiMarketingVslAsset;

/**
 * KeywordOsRunner (L12 — orquestração de ponta a ponta) — o capstone que torna o OS OPERÁVEL num passo:
 * monta os TRÊS feeds aterrados em dinheiro real (calibração per-nicho do flywheel + descoberta de search-
 * terms reais + negativos do waste real) e roda o pipeline determinístico sobre a oferta. Sem isto, o
 * operador teria que puxar e passar cada `opts` à mão; com isto, é "oferta → dossiê completo, com a venda
 * real já embutida na precisão, na descoberta e na exclusão".
 *
 * Separado em `assemble()` PURO/testável (feeds já puxados → run) e `run()` que puxa os feeds READ-ONLY.
 */
class KeywordOsRunner
{
    public function __construct(
        private readonly KeywordIntelligencePipeline $pipeline = new KeywordIntelligencePipeline,
        private readonly BlackinkKeywordOutcomeFeed $outcomeFeed = new BlackinkKeywordOutcomeFeed,
        private readonly BlackinkSearchTermHarvester $harvester = new BlackinkSearchTermHarvester,
        private readonly BlackinkNegativeMiner $negativeMiner = new BlackinkNegativeMiner,
    ) {}

    /**
     * PURO/determinístico: roda o pipeline com os feeds já materializados.
     *
     * @param  array{calibration?:array<string,mixed>,discovered_terms?:array<int,string>,mined_negatives?:array<int,string>}  $feeds
     * @return array<string,mixed>
     */
    public function assemble(AiMarketingVslAsset $asset, array $econ, array $feeds): array
    {
        return $this->pipeline->run($asset, $econ, [
            'outcome_calibration' => (array) ($feeds['calibration'] ?? []),
            'discovered_terms' => array_values((array) ($feeds['discovered_terms'] ?? [])),
            'mined_negatives' => array_values((array) ($feeds['mined_negatives'] ?? [])),
        ]);
    }

    /**
     * AO VIVO: puxa os 3 feeds reais do Blackink (read-only) e monta o run. A precisão (flywheel per-nicho),
     * a descoberta (search-terms reais) e a exclusão (waste real) entram automaticamente no dossiê.
     *
     * @return array<string,mixed>
     */
    public function run(AiMarketingVslAsset $asset, array $econ = [], int $minClicks = 5): array
    {
        return $this->assemble($asset, $econ, [
            'calibration' => $this->outcomeFeed->pullByNiche($minClicks),
            'discovered_terms' => array_column($this->harvester->harvest($minClicks)['terms'], 'term'),
            'mined_negatives' => array_column($this->negativeMiner->harvest()['negatives'], 'ngram'),
        ]);
    }
}
