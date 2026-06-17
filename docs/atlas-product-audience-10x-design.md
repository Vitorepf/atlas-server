# Atlas — Upgrade de Desenho: Produto + Público a 10/10

> Os dois maiores pilares de qualquer empresa. Desenho derivado de um painel multi-agente (16 agentes: 2 ground + 3 abordagens × 2 pilares + críticas adversariais + síntese por pilar), grounded no código. 2026-06-17.
> **Nota honesta se construído: ~9,3/10** — que É o "10/10 para o espaço bounded/reversível/medível". Os 0,7 que faltam NÃO são fixáveis por desenho: são a fronteira de soberania (mandato do operador) + teto de capacidade (originação greenfield). Reivindicar 10/10 absoluto seria Goodhart — exatamente o autoengano que torna o "conhecimento de público" da Polsia um lixo.
> Relacionado: `atlas-autonomous-company-architecture-v2.md`, `atlas-v2-sufficiency-review.md`.

---

## A ideia-chave (por que isto é 10/10 de verdade, não fingido)

Ser 10/10 em produto e público **não é o sistema "saber tudo"** — é o sistema **nunca afirmar o que não observou, e virar todo "não-sei" em "respondível no instante em que uma fonte real for ligada"**. A qualidade vem de quatro invariantes de honestidade, não de mais LLM:

1. **Seam de fonte externa:** todas as ~18 perguntas hoje `blocked_external` passam a `answerable-quando-ligado` — sem mockar nunca. Sem fonte → continua honestamente bloqueado.
2. **Fronteira observed-vs-inferred (pétrea):** `observed` só de linha de métrica real OU trecho literal verificado de corpus; **toda inferência de LLM é forçada a `inferred` + confiança ≤0,5** e vira blind-spot. (Não dá pra "achar que sabe" o público.)
3. **Verificação de span:** citação verbatim do cliente só sobrevive se for substring literal de um registro de corpus real; provenance tier de uma allow-list congelada pelo operador (página de preço do concorrente > review de app-store > blog).
4. **Falsificação antes do build:** demanda tem que cruzar um sinal real barato antes de gastar esforço (o Polsia-killer).

Isto é o oposto exato da Polsia: ela afirma público/produto sem ground-truth e shippa junk; aqui, o que não foi medido fica marcado como não-medido.

---

## Pilar 1 — Criação de produto (componentes do upgrade)

| Componente | Reuso/Novo | Papel | Guarda anti-Goodhart |
|---|---|---|---|
| **VentureOperationRuntime** (Layer ②) | NOVO fino (reusa FocusDecider.decide + Assessment + MissionFactory) | O despachante que faltava: por ciclo lê estado → pega a ação nº1 do decision_queue → emite UMA ação pela pilha de gates. É o seam onde ④⑤⑥① plugam. | default-OFF = comportamento suggest-only de hoje (byte-identical) |
| **LiquidatedRevenueReconciler** (keystone) | wiring sobre VentureSuccessEvaluator + `ai_venture_metric_observations` (coluna source já existe) + adapter Stripe/banco NOVO | Amarra sucesso a **caixa liquidado verificado** (líquido de reembolso), rejeita `source='operator'`, grava a **entidade legal de registro**. Gate multi-sinal: margem de contribuição, churn/NRR, concentração, legalidade. Estados: succeeded \| failed-by-insolvency \| failed-by-legal \| not_yet \| insufficient_data. | sem caixa liquidado → nunca `succeeded` |
| **DemandSignalGate** (Polsia-killer) | NOVO gate | Falsificador pré-build: hipótese JTBD precisa de sinal real (CTR de landing / waitlist / micro-ad) vs threshold → `demand_confirmed \| weak \| no_demand`. no_demand = build nunca dispara (~$20 mata vs queimar build). | só roda com canal real sob mandato; nunca em mock — senão `blocked_external` |
| **VentureJobToBeDoneMap** | NOVO objeto + 3 perguntas no catálogo existente | Decompõe a oferta em jobs-contratados: circunstância gatilho, alternativa que o usuário usa hoje, critério de sucesso pelo qual ele julga, job_severity. A diferença entre "quem é o usuário" e "qual job, em que circunstância, ele nos contrata". | fica `STATUS_PARTIAL` até um sinal aterrissar |
| **ProductQualityOracle** (Layer ④) | NOVO (NÃO reusar AiQualityEvaluator/só-higiene); reusa o PADRÃO ADEP iterate-to-green + frozen-judge + guarda de falha-correlacionada | Gate de qualidade por ação para output não-código (copy/oferta/onboarding/preço/ad): rubrica-frozen + LLM-judge contra o critério de sucesso do JTBD → `pass \| iterate \| block`. | advisory até existir holdout humano-frozen; não gateia o irreversível só no juiz |
| **ProductTelemetryIngest + UsageBehaviorDetector** (Layer ① olhos) | wiring sobre `ai_venture_metric_observations` + pipeline de Comprehension | Liga analytics real (activation, funnel-drop, feature-adoption, dau/mau) como `source='analytics'`; detector minera o maior drop-off/menor-ativação/maior-churn e emite findings leverage-scored citando a métrica. O órgão "minera onde o usuário cai de verdade". | cita a métrica, não uma linha inventada |

---

## Pilar 2 — Público + inteligência de produto (componentes do upgrade)

| Componente | Reuso/Novo | Papel | Guarda |
|---|---|---|---|
| **ExternalSourceResolverRegistry** (o seam load-bearing) | NOVO minúsculo (1 interface + 1 registry) | Consultado pelo QuestionEngine ANTES do `blockedExternal()` default. Converte as ~18 fontes externas de beco-sem-saída → respondível-quando-ligado, **zero mudança em catálogo/persist/FocusDecider**. | resolver devolve a forma de answer exata ou `null` (mantém blocked honesto) |
| **ObservedVsInferredBoundary** | NOVO (guarda no boundary) | `observed` só de métrica real ou span verificado; qualquer inferência (segmento, objeção, leitura de concorrente, WTP declarada) é **forçada `inferred` + confiança ≤0,5** e vira blind-spot. | não-bypassável |
| **DeterministicSpanVerifier + ProvenanceTier** | NOVO (string determinística, zero-spend) | Para quotes verbatim: dropa qualquer um que não seja substring literal de um registro de corpus real; cada span carrega tier de provenance da allow-list congelada. | abstrações nunca são "span-verified" |
| **DemandSignalValidator** | NOVO (orquestrador) | Trava dura pré-build no S0→S1: demanda precisa cruzar sinal real contra um **contrato de poder estatístico** (min impressões, efeito mínimo detectável, correção de comparações múltiplas) → `demand_validated \| no_demand \| insufficient_signal`. | abaixo do threshold OU sub-powered → venture parkada |
| **VentureVoiceOfCustomer ICP + Objection organ** | 2 NOVAS ComprehensionCapabilities (reusa Recorder/FindingDraft/BLIND_SPOT + skill cro-methodology) | Quando um corpus é ligado (reviews primeiro): minera o **ICP Vivo** — segmentos, jobs, dores ranqueadas por severidade, a **linguagem exata** do cliente, **ledger de Objeção/Contra-Objeção** + bandas de WTP declarada. | verbatim span-verified; segmentos/dores/objeções/WTP forçados inferred+baixa-confiança; down-weight de corpus auto-selecionado |
| **FalsifiedFocusWeighting** | REUSE + edit mínimo flag-gated | (a) score() da Ideation desconta o termo market até corroborado por sinal externo — e marca que pain/urgency/founder/sovereignty (75 de 100) seguem operator-asserted, então um pain=5 digitado à mão não rankeia nº1 sozinho; (b) FocusDecider eleva DIM_COMPETITION/PRODUCT quando suas perguntas viram answered-from-observed. | default = comportamento exato de hoje |
| **MarketIntelFreshnessLedger** | NOVO governor leve (reusa timestamps/confidence + NightShift) | Carimba cada finding externo com fetched_at + meia-vida + provenance; na obsolescência **re-bloqueia automático** (answered→blocked_external) e reaparece no roadmap de dataGaps. Refresh advisory agendado por materialidade. | intel velho nunca se passa por verdade atual |
| **VentureActionTrustLadder + ContainmentGate** | mirror do AtlasChangeClassTrustLadder (wiring) + spec de reversibilidade | Governança por classe de ação que toca dinheiro/cliente real (review-fetch / landing-test / ad-spend / price-change): gradua suggest→approve→auto só com evidência de outcome; spend-cap + circuit-breaker por venture. | nenhum gasto/preço-live arma sem mandato |

---

## O que o operador precisa LIGAR para o 10/10 armar (data inputs)

O desenho fica honestamente bloqueado até estas fontes existirem (por ordem de gettability local-first):
1. **Reconciliação de pagamento/banco** (Stripe/processador + extrato, líquido de reembolso) — o ground-truth nº1. Sem isso, `succeeded` fica `insufficient_data`, nunca operator-asserted.
2. **Canal de teste de demanda sob mandato** (conta de ad + deploy de landing + waitlist) — MarketingDomainActuator é sealed propose-only, então o sinal é operator-executado e ingerido como `source!=self`.
3. **Corpus de Voice-of-Customer** — **reviews públicos (app-store/G2) primeiro**: o mais atingível (read-only, sem auth, sem PII), serve net-new E existente.
4. **Analytics de produto** (funnel/activation/feature-adoption/churn) emitido pelo produto criado.
5. **Allow-list de provenance congelada pelo operador** (quais fontes valem) + **spend caps / circuit-breaker / classe de reversibilidade** por ação + **contrato de poder estatístico** + **metadata de representatividade** por corpus (anti-Goodhart do "5% vocal").

---

## O teto residual (0,7 — honesto, não fixável por desenho)
1. **Soberania:** formar entidade, abrir banco/merchant, assinar contrato, mover dinheiro, comprar ad-spend = mandato do operador. O desenho ARMA quando o mandato vem; não se auto-manufatura.
2. **Capacidade:** originar uma tese de mercado **ainda-não-verdadeira** (demanda greenfield que nenhum corpus reflete) é julgamento irredutível do modelo. Construímos **falsificação** (kill-gate barato real), **não** um escolhedor-de-vencedor onisciente.

---

## Ordem de construção (deriva pra implementação)
1. `LiquidatedRevenueReconciler` (ground-truth) + `VentureOperationRuntime` (o seam ②) — sem os dois, nada acima arma.
2. `ExternalSourceResolverRegistry` + `ObservedVsInferredBoundary` + `DeterministicSpanVerifier` — a espinha de honestidade (converte blocked→answerable sem mentir).
3. `VentureVoiceOfCustomer` (reviews) + `VentureJobToBeDoneMap` — a profundidade de público + produto.
4. `DemandSignalValidator`/`DemandSignalGate` (pré-build) + `ProductQualityOracle` (④) + `ProductTelemetryIngest` (① olhos).
5. `FalsifiedFocusWeighting` + `MarketIntelFreshnessLedger` + `VentureActionTrustLadder/ContainmentGate`.
