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
| **4. Quality Score engineering** | `MessageMatchAdForge` — gera RSA (≤15 HL/30char, ≤4 desc/90char) message-matched keyword=headline=H1; gates de keyword-coverage (≥3) + uniqueness + CTA + compliance lexicon (weight-loss sensitive) + ad-strength proxy. Maximiza os 3 levers do QS de uma vez | ✅ ciclo 57 (7 testes; OT169 mechanism → Excellent/mm 1.0) |
| **5. Estrutura agressiva** | STAG por família + match-type por tier + bidding faseado; **motor qualificado + Quality Index LIGADOS no `CampaignBlueprintService`** (bloco `qualified` no plano; launch_order = mechanism→slogan→celebrity, não mais marca-first; produto proibido; negativas mescladas com as eliminadas) | ✅ ciclo 54 |
| **6. Loop de aprendizado** | `KeywordLearningLoop` — outcomes reais (keyword→cost/conversions/revenue) → lift Bayesian-shrunk por family/root → realimenta o `KeywordQualityIndex` (vendeu sobe, gastou-sem-vender desce). **Flywheel FECHADO** | ✅ ciclo 58 (5 testes; slogan 83→100, mechanism 86→52) |

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

---

# 🔁 Loop Keyword Intelligence OS (nova volta — /goal Claude Code) — fonte de verdade: relatório `docs/affiliate-mastery/search-network-keyword-decision-report.md` + memória `search-keyword-os`

Métrica: TAXA DE ACERTO de keyword qualificada, provada cross-nicho (NÃO contagem). "Provado" hoje = painel brutal + decisão-matemática + backtest vs winners; calibração por VENDA real fica DORMANT até live (gated). Fila priorizada na memória `search-keyword-os`.

## Ciclo 1 ✅ — `IntentLadderClassifier` (escada de intenção composicional)
**Maior alavancagem provável agora:** o motor entender a INTENÇÃO (coração da meta "≥5 keywords de primeira"). Substituiu o token-spotter EN-only (`KeywordQualityIndex::intentClass` legado) por um modelo composicional **PT-BR + EN**: `intent_score = tier_base(T0~10..T4~92) + 14·dor + 8·especificidade`, 3 eixos (jornada × dor/urgência × especificidade), polaridade-negativa, campo `confidence` (materializa o teto de ~74% texto-only). Wirado no `KeywordQualityIndex` (owned-root≥0.78 mantém 100; `eliminate()` agora mata polaridade-negativa mesmo com owned-root). **Corrige os 3 bugs verificados:** `funciona`=comprador≠scam; "how to [ação]"≠informacional; sem o kill global cego. **527/527 verdes** (22 testes novos).
- **Painel brutal (3 lentes adversariais)** deu `fix_then_commit` ×3 (high) — estrutura generaliza cross-nicho CONFIRMADA (finanças/relacionamento via "como [ação]", sem léxico de saúde). 10 fixes aplicados: review/reviews=proof-buyer; qualificador→T2; prazo "em 7 dias"=dor; cortes de ação tier-sensíveis; tokens de segurança ambíguos fora do hard-negative; `eliminate` mata defensivo; `coinedMechanism` sem tokens genéricos; hasInfo→T0 antes de mecanismo; `confidence`; docblock honesto.
- **DEFERIDO (dormant até dado live / honesto, NÃO faxina):** dor como multiplicador-de-WTP separado do tier (recalibração); urgência=WTP validada por nicho; specificity além de token-count; polarity como escala 0-1; wiring do contexto owned-root completo no `eliminate`. Todos exigem ledger calibrado (venda real) ou são prior heurístico assumido — rotulados como prior, não verdade estrutural.

## Próximo alvo (ciclo 2, candidato): `MechanismExtractor` T4 da VSL dissecada
O `trick`/`mechanism_name` do asset (ex.: "at-home retatrutide protocol") deve fluir automático como keyword-herói T4 + alimentar o `mechanism_lexicon` do `IntentLadderClassifier` (hoje o classificador aceita o ctx mas ninguém passa). Fecha o elo VSL→keyword. (Alternativa de maior alavancagem: o gate de decisão estatístico — rule-of-three/EPC>CPC — se o operador priorizar prova-de-investimento sobre geração.)
