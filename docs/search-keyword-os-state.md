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

## Ciclo 2 ✅ — intent OFFER-AWARE + racionalização anexada (arma o ciclo 1)
Fechou o gap BUILT-not-ARMED: (a) `KeywordQualityIndex` agora monta `offerContext` (mechanism_lexicon de `mechanism_name`+`trick`, brand_lexicon de `product_name`) e passa pro `IntentLadderClassifier` — intent grading virou **offer-aware** (antes o classificador aceitava ctx mas ninguém passava). (b) Cada keyword pontuada carrega `intent` = {tier, journey, pain, polarity, confidence, action, intent_score} — o "entende a intenção e POR QUE é investimento" virou deliverable auditável, não só um número. Owned-root mantém o override most-aware (100/T4). **529/529 verdes** (+2 testes). Verificação inline proporcional ao risco (compõe componente panel-vetted do ciclo 1; sem nova calibração).

## Ciclo 3 ✅ — `KeywordInvestmentGate` (a math investimento-vs-gasto) + META demonstrada END-TO-END
Materializou a verdade estrutural do relatório §5: **breakeven CVR=CPC/net, rule-of-three cut=ceil(3/breakeven), EPC>CPC=investimento**, com basis honesto `forecast_prior` (dormant até venda) vs `proven` (dado live real). Wirado no `KeywordQualityIndex` (cada keyword sai com `investment`={verdict,basis,reason,breakeven_cvr,cut_after_clicks,epc}; `headroom` expõe o CPC previsto sem duplicar a math). **`KeywordOsPipelineTest` PROVA a META end-to-end:** oferta dissecada real → `QualifiedKeywordPatternEngine` → `KeywordQualityIndex` → **≥5 keywords qualificadas, cada uma com intenção entendida (tier/polaridade/confidence) + veredito investimento-vs-gasto**. Hero = owned-root; ≥1 investible. **538/538 verdes** (9 testes novos). Escopo honesto: só a porta SIGNIFICÂNCIA; ATRIBUIÇÃO (DDA/postback) e LAG ficam dormant até live.

### Estado da META: ✅ DEMONSTRADA (forecast-prior). Falta só a calibração por venda real (DORMANT, gated pelo operador).
O pipeline asset→≥5 keywords-qualificadas-com-intenção-e-veredito existe e está provado provider-free, cross-nicho. O que sobe de "prior" pra "proven" é o operador rodar campanha → offline-conversion alimenta o gate (basis vira `proven`) + o `KeywordLearningLoop` recalibra. Tudo já cabeado pra acender sozinho.

## Ciclo 4 ✅ — `KeywordMindState` (o elo keyword→MENTE — alavanca central do prompt)
Projeta a intenção de cada keyword no ESTADO MENTAL do buscador: **awareness de Schwartz** (T4→most_aware … T0→unaware) + **driver emocional** (urgency_relief / desire_confirmation / distrust / problem_tension / curiosity) + **page_angle** que fisga aquela mente (direct_offer_reminder / mechanism_proof_and_close / mechanism_differentiation / agitate_then_reveal / curiosity_hook_educate) + heat. Provider-free, niche-agnóstico por construção (lê só o intent estrutural). Anexado em cada keyword pontuada (`mind_state`). Primeiro elo do `keyword→mente→página→venda`; o page_angle é o que o construtor de bridge/VSL deve mirar; a reação simulada profunda fica pro `PersonaSimulator` downstream. **545/545 verdes** (7 testes, incl. end-to-end cross-nicho saúde/finanças/relacionamento). Honesto: projeção do prior (teto ~74% texto), não leitura provada de mente.

## Ciclo 5 ✅ — `KeywordAccountRiskSignal` (morte-de-conta como SINAL, nunca freio)
Relatório §6 ("conta morta = CPA infinito") em código: por keyword, flaga **restricted-drug** (retatrutide/ozempic/glp-1/… sem LegitScript = suspensão — pega o que a eliminação NÃO pega: o fármaco EMBUTIDO num owned-root que sobrevive, ex.: o próprio herói "at-home retatrutide protocol"), **brand-bidding** (clawback de contrato), **celebridade** (trademark/direito de imagem). Anexado por keyword (`account_risk`={risk_level, flags, decision}). **Constituição respeitada:** é SINAL ao operador, o motor constrói/pontua normal, compliance é decisão dele por cima — NUNCA freio. Celebrity_lexicon extraído do `persuasion_devices.authority` do asset. **550/550 verdes** (5 testes).

> **Deliverable por keyword agora carrega:** score+band+family · `intent` (tier/jornada/dor/polaridade/confidence/action) · `investment` (breakeven/rule-of-three/EPC, prior-vs-proven) · `mind_state` (awareness/driver/page_angle/heat) · `account_risk` (morte-de-conta como sinal). O "entende perfeitamente o que está fazendo" virou auditável ponta-a-ponta.

## Próximos alvos (ciclo 6+, por alavancagem)
- **(A)** `SearchTermWasteMiner` ligado no `CampaignBlueprintService` + gate anti-campeã (camada 3 do OS ⬜ desarmada — o miner existe e não é chamado): fecha o lado "remover o desqualificado" (que sob budget-cap rende MAIS que atrair).
- **(B)** CLI/dossier que expõe o deliverable ≥5-keywords-com-intent+investment pro operador (hoje vive no `scoreEngineResult`; falta a janela humana).
- **(C)** SIMULADOR (alavanca central do prompt): aprofundar o `PersonaSimulator` no elo keyword→mente→página. Maior salto de longo prazo.
- **(D)** Camada de risco-de-conta como SINAL (restricted-drug/brand-bidding/DKI) — expõe morte-de-conta sem ser freio.
