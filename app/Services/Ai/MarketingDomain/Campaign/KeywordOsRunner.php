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
    ) {}

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

        return $this->pipeline->run($asset, $econ, [
            'outcome_calibration' => (array) ($feeds['calibration'] ?? []),
            'discovered_terms' => $discovered,
            'mined_negatives' => array_values((array) ($feeds['mined_negatives'] ?? [])),
        ]);
    }

    /**
     * Geração ofensiva a partir do ativo: cone de mistype dos nomes coined (#1) + matriz celebrity-lane (#3).
     *
     * @return array<int,string>
     */
    public function generated(AiMarketingVslAsset $asset): array
    {
        $out = [];

        // #1 mistypes do nome coined (head do owned-root), só quando o head parece coined (não frase comum)
        foreach ($this->coinedRoots($asset) as $root) {
            $tokens = preg_split('/\s+/', $root) ?: [];
            $head = (string) ($tokens[0] ?? '');
            $suffix = trim(mb_substr($root, mb_strlen($head)));
            if (mb_strlen($head) >= 5 && preg_match('/^[a-z]+$/', $head) && ! in_array($head, self::COMMON_HEAD, true)) {
                foreach ($this->mistypeForge->forge($head, $suffix !== '' ? [$suffix] : []) as $kw) {
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
    public function run(AiMarketingVslAsset $asset, array $econ = [], int $minClicks = 5): array
    {
        return $this->assemble($asset, $econ, [
            'calibration' => $this->outcomeFeed->pullByNiche($minClicks),
            'discovered_terms' => array_column($this->harvester->harvest($minClicks)['terms'], 'term'),
            'mined_negatives' => array_column($this->negativeMiner->harvest()['negatives'], 'ngram'),
        ]);
    }
}
