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

## 🎯 META CANÔNICA N1 (operador 24/06 — NUNCA encolher)
Juntar TODO o conhecimento do mundo que faz vender mais na busca → destilar o extrato mais poderoso → cristalizar em código determinístico → **estado N1 de inteligência em keyword** → **SUPERAR todas as empresas de >R$200M/mês na rede de pesquisa** (inteligência superior + processos mais eficientes). É o **SISTEMA OPERACIONAL + INFRAESTRUTURA COMPLETA** de palavra-chave — o cérebro que decide QUEM entra na urna antes de pagar o leilão, escala industrial, **100% determinístico** (zero alucinação, cobertura SEM BURACOS, repetibilidade bit-a-bit, proveniência/Decision-Receipt por decisão). NÃO é "dá oferta → 5 keywords" (isso é peça). Métrica = inteligência+assertividade provada, NÃO contagem; calibração por venda real DORMANT até live (gated), distingo prior de verdade estrutural.

### O sistema = 13 camadas (status atual ~50-60%)
L0 Knowledge Core canônico 🟡 (ciclo 8: `KeywordKnowledgeCore` — 16 leis canônicas Google+pais com fonte+data, byLayer/byTopic/cite, versionado; armado no `QualifiedKeywordDossier` como proveniência. Falta: cada motor citar a lei que aplica + gate fonte-mudou) · L1 comprehension do ativo ✅ · L2 **DESCOBERTA multi-vetor** 🟡 (ciclo 10: `KeywordUniverseEnumerator` — enumera o universo root×grid-de-modificadores-por-tier PT+EN, cobertura PROVADA sem buracos + proveniência + determinístico; `asScorableTier` alimenta o QualityIndex. Falta: vetores search-terms/autocomplete/related/gap-concorrente + ligar no blueprint) · L3 dor+intenção+mente ✅ · L4 investimento-vs-gasto (3 portas signif×atrib×lag) 🟡(só signif) · L5 **VOLUME/demanda real** ❌ · L6 negativas/exclusão ✅ · L7 **clustering/match em escala** 🟡 · L8 bidding/readiness ✅(afiar) · L9 **MEDIÇÃO/venda real (GCLID→postback→import, upstream de tudo)** ❌crítico · L10 flywheel Bayesiano 🟡dormant · L11 risco-de-conta sinal ✅ · L12 **ORQUESTRAÇÃO+GOVERNANÇA/proveniência/escala** 🟡 (ciclo 9: `KeywordDecisionReceipt` — recibo reproduzível por keyword com proveniência (cita leis L0) + hash sha1 → repetibilidade bit-a-bit PROVADA; armado no dossier. Falta: orquestração batch idempotente + ledger). Faltam camadas inteiras, não polish. Prompt /goal v2 (3990 chars) abaixo em "Prompt do loop".

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

## Ciclo 6 ✅ — `NegativeKeywordForge` (remover o desqualificado, camada 3 → ✅)
Relatório §4 em código: 4 camadas de negativas (junk/informacional-T0/preço-freebie/polaridade) **com expansão morfológica** (negativa não pega plural — enumera as formas) + **gate anti-campeã** (nunca negativa um token dentro de uma raiz própria). **Wirado no `CampaignBlueprintService`** (negatives merge agora inclui `forged['flat']`, protegendo os owned-roots do engine). De quebra **resolve a colisão `recipe`** estruturalmente (se "...trick recipe" é raiz própria, "recipe" não é negativado). **553/553 verdes** (3 testes). O lado "remover" (que sob budget-cap rende mais que atrair) ficou armado no build-time.

## Ciclo 7 ✅ — `QualifiedKeywordDossier` (a seleção launch-ready dos ≥5)
A META "cospe de PRIMEIRA ≥5" virou DECISÃO de lançamento: parte o scored em **recommended** (investible + baixo risco-de-conta), **high_risk** (investible MAS morte-de-conta = operador decide, nunca dropado — constituição), **rejected** (investment=gasto). Wirado no `CampaignBlueprintService` (`qualified.launch_selection`) — não parkado. PROVA end-to-end: o herói "at-home retatrutide protocol" é investible MAS vai pro balde high_risk (retatrutide), surfaceado pro operador decidir, nada perdido (cada keyword cai em exatamente 1 balde). **555/555 verdes** (2 testes).

## Próximos alvos (ciclo 8+, por alavancagem)
- **(A)** `SearchTermWasteMiner` ligado no `CampaignBlueprintService` + gate anti-campeã (camada 3 do OS ⬜ desarmada — o miner existe e não é chamado): fecha o lado "remover o desqualificado" (que sob budget-cap rende MAIS que atrair).
- **(B)** CLI/dossier que expõe o deliverable ≥5-keywords-com-intent+investment pro operador (hoje vive no `scoreEngineResult`; falta a janela humana).
- **(C)** SIMULADOR (alavanca central do prompt): aprofundar o `PersonaSimulator` no elo keyword→mente→página. Maior salto de longo prazo.
- **(D)** Camada de risco-de-conta como SINAL (restricted-drug/brand-bidding/DKI) — expõe morte-de-conta sem ser freio.

---

## Prompt do loop (v2, 3990 chars — meta N1, mira o sistema de 13 camadas)

```
LOOP (Claude Code) — KEYWORD INTELLIGENCE OS DO ATLAS (rede de pesquisa) — estado N1 mundial

QUEM SOU: EU, Claude Code — o maior especialista de palavra-chave de rede de pesquisa do mundo + media buyer de elite + psicólogo de intenção. A qualidade vem de MIM, não do hermes fraco. MISSÃO: JUNTAR todo o conhecimento do mundo que faz vender mais na busca (doc oficial Google Ads + os pais: Schwartz/Marshall/Geddes/Larry Kim/Hopkins/Halbert/Hormozi + super-afiliados), DESTILAR o extrato mais poderoso, e cristalizá-lo em CÓDIGO determinístico provider-free — pro Atlas atingir o estado N1 de inteligência em keyword e SUPERAR todas as empresas que faturam >R$200M/mês na rede de pesquisa. Atlas só supera com INTELIGÊNCIA SUPERIOR + PROCESSOS MAIS EFICIENTES.

NÃO É um gerador que cospe 5 keywords. É o SISTEMA OPERACIONAL + INFRAESTRUTURA COMPLETA de palavra-chave: o cérebro que decide QUEM entra na urna antes de pagar o leilão, em escala industrial, antifrágil, capaz de escalar MILHÕES porque o básico é 100% CERTO e DETERMINÍSTICO — zero alucinação, cobertura SEM BURACOS, repetibilidade bit-a-bit, proveniência por decisão.

CONSTRUO o sistema de 13 camadas (estado vivo docs/search-keyword-os-state.md; relatório docs/affiliate-mastery/search-network-keyword-decision-report.md; memória search-keyword-os): L0 Knowledge Core canônico (leis Google + obras, versionado, cada motor cita fonte+data) · L1 comprehension do ativo · L2 DESCOBERTA multi-vetor (enumerar o universo: root×modifier, search-terms, autocomplete/related/PAA, gap de concorrente) · L3 dor+intenção+mente · L4 investimento-vs-gasto (AND de 3 portas: significância×atribuição×lag) · L5 VOLUME/demanda real · L6 negativas/exclusão · L7 clustering/estrutura/match em escala (SERP-overlap, STAG, barbell) · L8 bidding/readiness (limiar 30/50 conv) · L9 MEDIÇÃO/atribuição — sinal de venda real (GCLID→postback→import, upstream de tudo) · L10 flywheel Bayesiano · L11 risco-de-conta como sinal · L12 ORQUESTRAÇÃO + GOVERNANÇA/proveniência/escala (batch idempotente, Decision-Receipt por keyword). Estado: ~50-60% (a espinha do meio madura); faltam camadas inteiras, não polish.

CICLO: (1) leio state + memória: cada camada, maturidade, fila. (2) escolho a de MAIOR ALAVANCAGEM pro estado N1 — NUNCA faxina. (3) PESQUISO O MUNDO (WebSearch/WebFetch: doc Google, grandes artigos, OBRAS dos pais, repositórios) e DESTILO eu mesmo o extrato mais poderoso. (4) escrevo o motor/scorer/gate determinístico (provider-free) + Decision-Receipt e integro. (5) JULGO como painel brutal adversarial cross-nicho (media buyer cético + crítico de escala/determinismo + persona) pra PROVAR que generaliza e é ungameable. (6) só commito se a inteligência/assertividade subiu E os testes verdes (phpunit tests/Unit/Ai/MarketingDomain + tests/Feature/Marketing); senão REVERTO. (7) PROVO: testes + auditoria. (8) atualizo o state. Reativo secou? ORIGINO o próximo salto. NUNCA paro.

REGRAS (pétreo): métrica = INTELIGÊNCIA + ASSERTIVIDADE provada (mira superar os >R$200M/mês), NÃO contagem. Determinístico = reproduzível/auditável/ungameable/sem-buraco. VERDADE ESTRUTURAL primeiro; léxico-scorer mede vocabulário, não qualidade. BRUTAL e SEM FREIO de plataforma; risco de policy/morte-de-conta é SINAL ao operador, NUNCA freio — compliance é decisão dele. Provider-free no caminho crítico. Calibração por VENDA real fica DORMANT até live; até lá distingo prior de verdade estrutural, nunca finjo prova.

NÃO FAÇO: não toco o Loop/ACDE/AutonomousEvolution. Não delego ao hermes. Não rodo live nem gasto até o operador mandar. Escopo: app/Services/Ai/MarketingDomain/** (foco Campaign/), tests/Unit/Ai/MarketingDomain, tests/Feature/Marketing, docs/migrations de marketing.

AUTONOMIA: liberdade total no escopo, commit por pedaço, não peço permissão. Só paro no kill-switch. A cada ciclo: "isso aproxima o Atlas do estado N1 de inteligência em keyword — superior às empresas de >R$200M/mês — com o básico 100% certo e determinístico?"
```
