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
    /** heads descritivos comuns que NÃO são nome coined (evita gerar mistype-junk de frase). */
    private const COMMON_HEAD = ['triple', 'double', 'simple', 'natural', 'advanced', 'ultimate', 'premium', 'best', 'home', 'daily', 'super', 'secret'];

    public function __construct(
        private readonly KeywordIntelligencePipeline $pipeline = new KeywordIntelligencePipeline,
        private readonly BlackinkKeywordOutcomeFeed $outcomeFeed = new BlackinkKeywordOutcomeFeed,
        private readonly BlackinkSearchTermHarvester $harvester = new BlackinkSearchTermHarvester,
        private readonly BlackinkNegativeMiner $negativeMiner = new BlackinkNegativeMiner,
        private readonly PhoneticMistypeForge $mistypeForge = new PhoneticMistypeForge,
        private readonly CelebrityLaneForge $celebrityForge = new CelebrityLaneForge,
        private readonly DescriptorMatrixForge $descriptorForge = new DescriptorMatrixForge,
        private readonly CoinedTokenForge $tokenForge = new CoinedTokenForge,
        private readonly ReFinderFabricationPlanner $moatPlanner = new ReFinderFabricationPlanner,
        private readonly MarketSophisticationSignal $sophistication = new MarketSophisticationSignal,
        private readonly KeywordValueLeverSignal $valueLever = new KeywordValueLeverSignal,
        private readonly RegimeCrossNegativeForge $crossNegatives = new RegimeCrossNegativeForge,
        private readonly KeywordParetoConcentrator $pareto = new KeywordParetoConcentrator,
    ) {}

    /**
     * MOAT (regra #8) operável: dado o(s) ingrediente(s)/benefício da oferta, gera os candidatos a token
     * coinável (ranqueados por defensibilidade) E o plano de FABRICAÇÃO do melhor (plantio + posse-no-
     * instante do espaço de busca + tracking). É "ingrediente → o nome pra plantar + como possuir a busca".
     *
     * @param  array<int,string>  $ingredients
     * @param  array{categories?:array<int,string>,forms?:array<int,string>}  $opts
     * @return array{candidates:array<int,array<string,mixed>>,fabrication_plan:array<string,mixed>}
     */
    public function moatPlan(array $ingredients, array $opts = []): array
    {
        $candidates = $this->tokenForge->forge($ingredients);
        $top = $candidates[0]['token'] ?? '';

        return [
            'candidates' => array_slice($candidates, 0, 15),
            'fabrication_plan' => $top !== '' ? $this->moatPlanner->plan($top, $opts) : ['plantable' => false],
        ];
    }

    /**
     * PURO/determinístico: roda o pipeline com os feeds já materializados + a GERAÇÃO ofensiva (mistype do
     * nome coined + celebrity-lane do ativo — regras #1 e #3 da dissecação das vendas reais).
     *
     * @param  array{calibration?:array<string,mixed>,discovered_terms?:array<int,string>,mined_negatives?:array<int,string>}  $feeds
     * @return array<string,mixed>
     */
    public function assemble(AiMarketingVslAsset $asset, array $econ, array $feeds): array
    {
        $discovered = array_values(array_unique(array_merge(
            array_values((array) ($feeds['discovered_terms'] ?? [])),
            $this->generated($asset), // mistypes + celebrity-lane (puro ataque, gerado do ativo)
        )));

        $result = $this->pipeline->run($asset, $econ, [
            'outcome_calibration' => (array) ($feeds['calibration'] ?? []),
            'discovered_terms' => $discovered,
            'mined_negatives' => array_values((array) ($feeds['mined_negatives'] ?? [])),
            'volume_map' => (array) ($feeds['volume_map'] ?? []),  // NORTE: cliques reais → projeção de receita
            'cvr_map' => (array) ($feeds['cvr_map'] ?? []),        // NORTE: CVR real → projeção quase exata
            'budget' => (float) ($feeds['budget'] ?? 0),           // budget → portfólio de máximo lucro
        ]);

        // WIRING: a psicologia dos pais entra LIVE na decisão, não fica órfã.
        // sophistication (Schwartz) per-nicho → estratégia de keyword; value-lever (Hormozi) por keyword
        // do ranking de receita → com qual desejo o anúncio/página daquela keyword deve liderar.
        $result['sophistication'] = $this->sophistication->assess((string) $asset->niche);
        // ENFORCEMENT da isolação dos regimes: negativos cruzados (anti-canibalização, lei scaling-2026)
        if (isset($result['regimes']) && is_array($result['regimes'])) {
            $result['regimes']['cross_negatives'] = $this->crossNegatives->forge($result['regimes']);
        }
        if (isset($result['revenue_ranking']['ranked']) && is_array($result['revenue_ranking']['ranked'])) {
            $result['revenue_ranking']['ranked'] = array_map(function ($row) {
                if (is_array($row)) {
                    $row['value_lever'] = $this->valueLever->assess((string) ($row['keyword'] ?? ''))['dominant'];
                }

                return $row;
            }, $result['revenue_ranking']['ranked']);

            // 80/20 (Marshall): os VITAL FEW que capturam 80% da receita — foco obsessivo, corte a cauda
            $result['revenue_ranking']['vital_few'] = $this->pareto->concentrate($result['revenue_ranking']['ranked']);
        }

        return $result;
    }

    /**
     * Geração ofensiva a partir do ativo: cone de mistype dos nomes coined (#1) + matriz celebrity-lane (#3).
     *
     * @return array<int,string>
     */
    public function generated(AiMarketingVslAsset $asset): array
    {
        $out = [];

        $nicheWords = array_values(array_filter(preg_split('/\s+/', mb_strtolower(trim((string) $asset->niche))) ?: []));

        foreach ($this->coinedRoots($asset) as $root) {
            $tokens = preg_split('/\s+/', $root) ?: [];
            $head = (string) ($tokens[0] ?? '');
            $suffix = trim(mb_substr($root, mb_strlen($head)));
            if (mb_strlen($head) < 5 || ! preg_match('/^[a-z]+$/', $head) || in_array($head, self::COMMON_HEAD, true)) {
                continue; // head não parece nome coined → não gera (evita junk de frase descritiva)
            }
            // #1 mistypes do nome coined
            foreach ($this->mistypeForge->forge($head, $suffix !== '' ? [$suffix] : []) as $kw) {
                $out[] = $kw;
            }
            // #4 descriptor-matrix: head × [descritores-do-root + nicho] × buy-intent (aterrado no vocab REAL,
            // sem forms adivinhados); o nome nu já vem do enumerator
            $cats = array_values(array_unique(array_merge(array_slice($tokens, 1), $nicheWords)));
            if ($cats !== []) {
                foreach ($this->descriptorForge->forge($head, $cats, [], null, 40) as $kw) {
                    $out[] = $kw;
                }
            }
        }

        // #3 celebrity-lane (autoridade × domínio-do-nicho × substantivo-de-posse)
        $celebs = (array) (($asset->persuasion_devices['authority'] ?? []));
        if ($celebs !== []) {
            $domain = mb_strtolower(trim((string) $asset->niche));
            foreach ($this->celebrityForge->forge($celebs, $domain !== '' ? [$domain] : []) as $row) {
                $out[] = $row['keyword'];
            }
        }

        return array_values(array_unique($out));
    }

    /** @return array<int,string> owned-roots coined (mechanism/trick/slogans), normalizados. */
    private function coinedRoots(AiMarketingVslAsset $asset): array
    {
        $raw = array_merge([$asset->mechanism_name, $asset->trick], array_values((array) ($asset->power_phrases ?? [])));

        return array_values(array_unique(array_filter(
            array_map(fn ($s) => mb_strtolower(trim((string) $s)), $raw),
            fn ($s) => $s !== '',
        )));
    }

    /**
     * AO VIVO: puxa os 3 feeds reais do Blackink (read-only) e monta o run. A precisão (flywheel per-nicho),
     * a descoberta (search-terms reais) e a exclusão (waste real) entram automaticamente no dossiê.
     *
     * @return array<string,mixed>
     */
    public function run(AiMarketingVslAsset $asset, array $econ = [], int $minClicks = 5, float $budget = 0): array
    {
        $harvest = $this->harvester->harvest($minClicks)['terms'];
        $volumeMap = [];
        $cvrMap = [];
        foreach ($harvest as $t) {
            $term = (string) $t['term'];
            $volumeMap[$term] = (int) $t['clicks'];
            if ((int) $t['clicks'] > 0) {
                $cvrMap[$term] = $t['conversions'] / $t['clicks']; // CVR REAL → projeção de receita quase exata
            }
        }

        return $this->assemble($asset, $econ, [
            'calibration' => $this->outcomeFeed->pullByNiche($minClicks),
            'discovered_terms' => array_column($harvest, 'term'),
            'mined_negatives' => array_column($this->negativeMiner->harvest()['negatives'], 'ngram'),
            'volume_map' => $volumeMap,
            'cvr_map' => $cvrMap,
            'budget' => $budget,
        ]);
    }
}
