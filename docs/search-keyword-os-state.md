# Atlas Search Keyword OS — estado vivo

> Meta (operador, /goal): Atlas **elite em rede de pesquisa**. Não só um path de qualidade de keyword — um **sistema operacional** de keyword: a fórmula mais brutal e agressiva de Search, máximo de gente qualificada, eliminando keyword ruim, com índices de qualidade. Loop contínuo multi-agente (pesquisa Google Ads docs + best practices + cristaliza no Atlas).

## Princípio-mãe (do operador)
**Keyword do nome do produto = proibida.** O tráfego de maior acerto = **re-finders pós-exposição** (viram a VSL / ouviram falar) googlando o que LEMBRAM: mecanismo/truque coined, slogan plantado, celebridade+claim. `keyword = {raiz própria} × {modificador}`. Quanto mais impossível digitar sem ter visto o anúncio → mais qualificado. Especificidade = qualificação.

## Camadas do Keyword OS

| Camada | Componente | Status |
|---|---|---|
| **1. Geração qualificada** | `QualifiedKeywordPatternEngine` — colhe 6 artefatos do asset → 6 tiers (mechanism/slogan/celebrity/power-phrase/category/objection) por exposure-exclusivity; KEEP/KILL law; nega produto/genérico/droga-bare | ✅ ciclo 52 (7 testes) |
| **2. Índice de qualidade** | `KeywordQualityIndex` — pontua 0-100 (6 componentes: owned-root-provenance .24 / intent-class .20 / predicted-QS-proxy .22 / profit-headroom .14 / specificity-match .10 / signal-fit .05) + elimina lixo ANTES do gasto + banda de ação (scale/launch/test/kill). Grounded em pesquisa real do Google QS (expected CTR/ad relevance/LP experience) | ✅ ciclo 53 (5 testes; CLI mostra score+banda) |
| **3. Mineração de desperdício** | search-term/n-gram miner → negativas automáticas (waste elimination contínua) | ⬜ |
| **4. Quality Score engineering** | maximizar os 3 levers reais do Google (expected CTR / ad relevance / LP experience) via message-match keyword=ad=H1 | ⬜ (parcial no MessageMatchScorer) |
| **5. Estrutura agressiva** | STAG por família + match-type por tier + bidding faseado; **motor qualificado + Quality Index LIGADOS no `CampaignBlueprintService`** (bloco `qualified` no plano; launch_order = mechanism→slogan→celebrity, não mais marca-first; produto proibido; negativas mescladas com as eliminadas) | ✅ ciclo 54 |
| **6. Loop de aprendizado** | ledger keyword→venda real (Nivor) realimenta os pesos do índice | 🟡 NivorWinningPatternMiner existe; falta fechar o loop no índice |

## O que existe hoje (namespace `Campaign/`)
KeywordIntentMapper · SearchNetworkPlanner · CampaignBlueprintService · CampaignEconomicsCalculator · BidStrategyDecider · NegativeListMiner · BroadMatchStrategist · AccountStructurer · RSAWriter · KeywordRelevanceGate · SmartBiddingReadinessDiagnostic · ConversionPipelineValidator · TrackingStackDecider · **QualifiedKeywordPatternEngine** (novo).

## Gaps conhecidos (honestos)
1. Sem sinal de **volume de busca real** (Keyword Planner API) — ranqueia intenção, não demanda.
2. `KeywordIntentMapper` antigo rankeava **marca-first** (errado p/ afiliado) — superado pelo `QualifiedKeywordPatternEngine`, falta **ligar no blueprint**.
3. Dados Nivor (keywords que venderam) não carregados p/ todo nicho.

## Roadmap do loop (ordenado)
1. **KeywordQualityIndex** (índice 0-100 + eliminação) ← agora, grounded na pesquisa do Google Ads
2. Ligar `QualifiedKeywordPatternEngine` + índice no `CampaignBlueprintService` (substituir rank genérico)
3. Search-term/n-gram waste miner → negativas automáticas
4. QS-engineering: gerar o trio keyword=RSA-headline=advertorial-H1 por família (message match máximo)
5. CLI `atlas:ai:marketing:keywords` (rodar o OS inteiro sobre um asset)
6. Fechar o loop de aprendizado (keyword→venda real recalibra pesos)
