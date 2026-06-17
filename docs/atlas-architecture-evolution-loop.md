# Atlas — Loop de Evolução de Arquitetura (ledger de iterações)

> Goal do operador (2026-06-17): loop contínuo melhorando estrutura/arquitetura/desenho do fluxo Atlas até consolidar como a solução mais poderosa do mercado. Cada iteração: painel de design adversarial → síntese grounded → registro aqui. Honestidade pétrea: nenhum design reivindica o que não fecha.
> Nota de estado: o rascunho de código Fase-0 foi revertido — **fase de DESENHO pura**; as classes `VentureFoundry/Success/*` NÃO existem (confirmado por grounding). Os componentes abaixo são alvos de desenho, não código.

---

## Iteração 1 — Layer ① (loop de aprendizado / compounding keystone)

**Insight central (honesto):** o moat não é velocidade de aprendizado — é **compor honestamente**. Embarca-se um substrato **inerte, reversível, byte-idêntico-quando-vazio** que só ganha crédito de **caixa externamente reconciliado** (nunca self-report) e **reverte na hora** em regressão. Ele fica **corretamente DORMENTE** até existir (a) ingestão de caixa real e (b) um atuador de venture wired — e **recusa fabricar gradiente de self-report só pra parecer vivo**. Essa é a forma da "compor honestamente" que torna Atlas mais poderoso que qualquer rival: é o único que não mente o próprio progresso.

### Componentes (ordem de build embutida)
| # | Componente | Reuso/Novo | Papel + salvaguarda |
|---|---|---|---|
| 0 | **VentureTerminalRewardEvaluator + VentureRewardScorecard** | NOVO (não existe — premissa falsa das lentes) | O rótulo terminal de sucesso (N meses de MRR reconciliado ≥ threshold, →failed assimétrico). Nada calibra/reverte sem ele. Idempotência: versiona o veredito (a série cresce → re-eval pode virar succeeded→failed). |
| 1 | **VentureRewardReconciler** | NOVO (reusa `AiVentureMetricObservation.source`) | **A correção ship-now de maior valor:** allow-list de fonte no boundary de QUERY — só `source ∈ {payment_processor, bank, external_reconciled}` conta como crédito; `operator/loop/self` = zero, display-only. Fecha o buraco "escreve e se auto-avalia" (VentureGrowthLadderService.php:184). Fail-closed: sem caixa reconciliado → sem reward. |
| 2 | **VenturePolicyWeightStore + FocusDecider config-overlay** | NOVO store + EDIT mínimo | Torna os ganhos do controlador mutáveis-sob-governança (hoje SEVERITY_WEIGHT/dimensionStageWeight são const hardcoded → flywheel fisicamente aberto). **Defaults-vazio ⇒ byte-idêntico a hoje** (a propriedade de segurança que deixa tudo embarcar no escuro). Mantém a fórmula linear glass-box, nunca black-box. |
| 3 | **VenturePolicyWeightApplier** | EDIT (clona applyHarnessConfig) | Fecha o único apply-path faltante: `kind='policy'` cai em `kind_applier_pending` hoje. Só age em `status='approved'` (policy é CRITICAL_KIND → nunca auto-aplica). Reversível com receipt. |
| 4 | **VentureMemoryScope ('venture') + recall fail-closed** | NOVO helper | Isolamento cross-venture (anti-exploit-da-Polsia). **Correção crítica:** `scopeForScope(type,null)` hoje retorna GLOBAL (não fail-closed) — o helper novo lança em query não-escopada. |
| 5 | **VentureLearningCockpitView + liveness** | NOVO | Mostra o PORQUÊ (peso efetivo vs default, sample vs n_min, streak, último revert) e prova que o atribuidor casa com outcomes reais. Resolve a "falha invisível": "não aprende nada" e "funciona perfeito" hoje têm telemetria idêntica. |
| 6 | **VenturePolicyLearningProposer + VenturePolicyTrustLadder + VenturePlaybookPromotionGuard** | NOVO (reusa nightlyReview + AtlasChangeClassTrustLadder + LongHorizonMemoryPromotionGuard) | Update damped, proposal-only, com anti-windup + histerese + Wilson lower-bound + **floor de ≥2 ventures distintas** (uma venture não move o global). Promoção a playbook global só com ≥2 corroborações reconciliadas + de-identificação + review. **Atuador HARD-DISABLED** até a liveness provar atribuição real. |
| 7 | **VentureLeadingIndicatorReward (Smith-predictor)** | NOVO, **GATED-OFF** | Proxy [0,1] dos indicadores que precedem o MRR (ativação, trial→paid) pra cobrir o dead-time de meses. Sozinho nunca cunha mudança; o veredito reconciliado lento sempre vence. Deferido até haver plant. |
| — | **Net-of-cost reward + VentureMultiSignalScalarizer** | REUSE telemetria + NOVO scalarizer (reporting-only) | Reward = uplift de caixa reconciliado MENOS custo medido (otimiza margem, não vaidade). Scalarizer min-and-multiply (legality·min(margem,retenção)·receita/custo) é o gate de aprovação do operador — nunca o gradiente. |

### Garantias de convergência (por construção)
1. **Monotone-ou-reverte:** vazio ⇒ byte-idêntico; o que sobrevive tem evidência reconciliada/atribuída/≥n_min/net-cost; o que regride o veredito versionado **snapa pro prior pinado** e zera o streak. Ganho bom acumula; ruim é transiente e auto-apaga.
2. **Não persegue ruído:** Wilson lower-bound + floor de ventures-distintas + dead-band + EWMA + cap de learning-rate + cooldown (anti-windup textbook) → abstém abaixo do contrato de poder.
3. **Não se auto-avalia:** só caixa reconciliado conta (fecha :184).
4. **Não auto-aplica:** `kind=policy` é CRITICAL_KIND → default-deny human/earned-autonomy; pior caso = 1 célula volta ao comportamento atual, nunca drift.
5. **Sem contaminação cross-venture:** memória scope-particionada fail-closed + promoção global exige ≥2 ventures distintas reconciliadas + review.
6. **Dead-time honesto:** durante os meses de atraso o loop fica quiescente em vez de agir em crédito velho.

### Anti-Goodhart (4 camadas)
Reward = caixa-reconciliado-líquido-de-custo, gated por floor de saúde multi-sinal, **severado do canal de self-report**: (1) margem não MRR bruto; (2) self-report fisicamente cortado pela allow-list; (3) scorecard min-and-multiply (qualquer termo colapsado hard-floora a saúde — só reporting); (4) trust-ladder assimétrico reverte ex-post em regressão de margem/retenção/legalidade. O movimento anti-Goodhart mais profundo: **honestidade sobre o plant** — como não há feed de caixa nem atuador wired, o canal de reward é estruturalmente VAZIO hoje, então embarca em shadow/observe-only e se recusa a fingir gradiente.

### Teto residual (3 limites irredutíveis, nomeados)
1. **Sem plant hoje:** feed de caixa não existe + FocusDecider é puro advisory (sem atuador price/spend/post/charge wired) → o steady-state correto é **DORMENTE**; compõe nada até existir ingestão de caixa E atuador. A honestidade é embarcar o aparato inerte/reversível e não falsear gradiente.
2. **Crédito confundido sob dead-time de meses:** um peso é um de muitos inputs num outcome de meses, sem holdout → atribuição sub-determinada com poucas ventures. O stack Wilson+distinct+abstain **corretamente recusa aprender** em vez de aprender ruído → movimento quase-zero em horizonte realista (anos), e isso é o esperado honesto, não defeito.
3. **Revert assíncrono (elo mais fraco):** a assimetria provada do trust-ladder foi feita pra canary síncrono; regressão de venture é sinal lento/ruidoso/parcialmente-inobservável → o gatilho de revert (o que faz convergir em vez de driftar) é a parte NÃO herdada. Mitigado (flip de veredito versionado + debounce), não determinístico.

**Veredito da iteração:** torna Atlas a solução mais poderosa por ser a única que **compõe honestamente** — substrato provadamente-seguro, byte-idêntico-quando-vazio, totalmente reversível, que ganha devagar de caixa real e reverte na hora; e recusa reivindicar um moat de compounding que ainda não consegue alimentar.

---

## Iteração 2 — Motor de portfólio / alocação de capital (o conglomerado de um operador)

**Insight central:** a camada onde Atlas **honestamente bate qualquer humano** — reallocação evidence-gated em paralelo máquina sobre N empresas — e fica **honestamente dormente até não poder fingir**. Aloca capital **só sobre caixa reconciliado imposto no SCHEMA** (não string-compare), estagia na escada S0-S5, **pausa (nunca auto-fecha)** zumbis, e reserva um piso de exploração.

### Componentes
| Componente | Reuso/Novo | Papel + salvaguarda |
|---|---|---|
| **ReconciledCashEventStore + tabela `ai_reconciled_cash_events`** | NOVO (keystone) | A fronteira de confiança feita **invariante de schema**: coluna `source` com **DB-CHECK** ∈ {payment_processor, bank, external_reconciled}, single-writer. Fecha o gaming no write-boundary (hoje `source` é string livre default 'operator', sem CHECK). Teste frozen: write operator/loop/self **rejeitado no DB**. |
| **VentureCostAttributionLedger** | NOVO read-model (reusa AiTelemetryEvent + Scorecard) | O denominador: burn medido por venture (metered vs operational segregado). **Valor no dia 1** mesmo sem feed de caixa. Fail-honest: trace sem atribuição vai p/ bucket 'unattributed', **nunca espalhado** (espalhar deixa venture cara se esconder atrás de barata). |
| **PortfolioBoardCockpitView** | NOVO + 1 ação no HoldingCommand | Cockpit do operador útil já: ARR-reconciliado-vs-north-star, burn, prontidão de gate, score (flat enquanto dormente), backlog, flags de zumbi, heatmap de correlação, drawdown-vs-teto, **prova de liveness**. Read-only, **by-exception** (não N linhas pra carimbar). |
| **PortfolioAllocationScorer** (puro) | NOVO (reusa GrowthLadder.evaluate) | `value_i = max(0, margem_reconciliada_delta) × concentração × prontidão-de-gate × health_veto`. Anti-over-prune: peso de concentração convexo **encolhido por certeza** (a 1ª venture a postar caixa não ganha o budget inteiro). NaN/INF→0. |
| **AtlasLoopBudgetScheduler** | **REUSE verbatim** (1º caller de produção) | Knapsack guloso value/cost; provider mais barato; preenche até budget OU WIP; defere o resto (backlog, nunca dropa). Correção: estado dormente passa **custo uniforme** (senão o knapsack ordena por mais-barato, não flat). |
| **PortfolioCorrelationCap** | NOVO classificador determinístico | Anti-falha-correlacionada: tagueia mercado/canal/tese/provider; recusa o (N+1)º dólar num cluster já no teto. Fail-closed: venture não-tagueável é seu próprio cluster singleton (nunca auto-mesclado). |
| **PortfolioKillFloor** | NOVO (reusa transition + KillAuthority) | Zumbi = consome a tranche por N **janelas-de-reconciliação** com margem flat E nenhum gate novo → **PAUSE-only** (active→paused reversível; CLOSE é terminal, proibido auto). Capital liberado volta ao pool (aposentar-zumbi → financiar-vencedor é mecânico). |
| **VenturePortfolioTrustGate + revert assíncrono de saúde** | NOVO (reusa SHAPE do trust-ladder; arma via KillAuthority) | Default = fricção-máxima: toda realocação/kill é SUGGEST. Ganha autonomia só com **streak de caixa reconciliado** > threshold do operador. Honesto sobre o elo fraco: o canary do trust-ladder é síncrono; saúde de venture é assíncrona (mitigado com flip de veredito versionado + debounce, não determinístico). |
| **VenturePortfolioGovernorService + PortfolioDecisionReceipt** | NOVO orquestrador (posture recommendation-only) | O cérebro do conglomerado por ciclo NightShift: lista ativas → score → caps + teto de drawdown → aloca → kill-floor → **receipt recommendation-only**. Mutação (pause/dispatch) atrás do TrustGate + KillAuthority. |

### Lei de alocação (glass-box, por ciclo NightShift; defaults = dormente/flat)
Capital `C` = budget de token (real, de AiTelemetryEvent) + `W` slots de WIP. Caixa-crescimento só aloca se `ReconciledCashEventStore` não-vazio; senão, cash-track fica SHADOW. Por venture ativa no cluster k: `value = max(0, margem_reconciliada_delta) × concentração(encolhida-por-certeza) × stage_gate × health_veto(legality·min(margem,retenção))`. Passos: (1) elegibilidade (dropa kill-floor/max-friction), (2) **piso de exploração carved-off-the-top** (fração + contagem mínima de SLOTS, round-robin pré-caixa — UCB degenera com certeza=0), (3) knapsack guloso, (4) cap de correlação durante o walk, (5) **teto de drawdown** halt + alerta (circuit-breaker de falha correlacionada), (6) governança proposal-only. **Nenhuma alocação em forecast.**

### Anti-Goodhart (4 camadas)
(1) evidence-gated no **SCHEMA** (DB-CHECK, não query); (2) value não é gradiente — health-veto multiplicativo só zera, nunca infla; (3) aloca em caixa-reconciliado-menos-burn-medido, não vaidade; (4) trust-gate assimétrico reverte ex-post em regressão.

### Teto residual (honesto)
1. **Capital-dormente até feed de caixa reconciliado real existir** — disciplina de VC correta, não defeito. Ledger + Cockpit dão valor JÁ; scorer/governor/kill-floor são corretamente inertes até o caixa chegar.
2. **Revert assíncrono de saúde não-determinístico** (o elo fraco herdado, mitigado não eliminado).
3. **Atribuição de custo medida ≠ custo operacional real** (ad-spend/COGS/infra) até existir feed de custo real — net-of-cost é piso, rotulado como tal.

**Veredito:** a forma máxima (conglomerado) fica desenhada e honesta — **shipa burn-ledger + cockpit agora, gatea o alocador atrás de caixa reconciliado real**, e bate o humano por paralelismo + reallocação evidence-gated, sem nunca alocar em forecast.

---

## Iteração 3 — Layer ③ orquestração cross-domínio (a cadeia de valor)

**Insight central:** prova **UM dólar reconciliado no transporte existente (DomainHandoffService) ANTES de construir qualquer engine** — depois um state-machine **linear fino** (não BPM pesado; os críticos pegaram over-engineering → template-engine versionado **deferido até uma 2ª cadeia com branching existir**). A cadeia avança só com artefato tipado que passa **occurrence** (não só shape).

### Componentes
| Componente | Reuso/Novo | Papel + salvaguarda |
|---|---|---|
| **ProcessOutcomeReconciler + system-of-record de invoice/pagamento** | NOVO pequeno (reusa hash + distinct-ref) | A oráculo de caixa-verdade: tabela `process_cash_receipts` + `wasCashReceived(instance, ref)` re-checável contra fonte externa. **Reward só acrua com ref externo distinto dereferenciável**; "sold=true" ou amount no payload **nunca paga**. |
| **AiProcessInstance** (NOVA tabela) | NOVO aditivo (não alarga ai_domain_handoffs) | O saga-log/state-machine: uma jornada por cliente sobre cadeia **linear hard-coded** (lead→qualified→won→onboarded→active→billed). Idempotency key **por-instância-e-stage** checada na MESMA transação do stage-write. |
| **ValueChainProcessOrchestratorService** (fino) | NOVO (reusa DomainHandoffService.emit como transporte só) | `advance(instance)`: rejeita replay → valida artefato (**shape + occurrence**: dereferencia todo ref) → gate de transição de negócio → trust-ladder. **Fail-closed cada hop**; stages do meio têm occurrence-check (fecha o "advance-only theater"). |
| **5 contratos HandoffArtifact tipados + ArtifactValidator** | NOVO value-objects (dentro das colunas JSON existentes — anti-refrag) | QualifiedLead/WonDeal/OnboardingPackage/ActivatedAccount/Invoice: type+version, evidence_refs re-checáveis, `reconciled_value_ref` (null até caixa). `validate()` = shape + **proof_of_occurrence**. Fecha o "valida shape não ocorrência" e o "sha256 faz alucinação parecer legítima". |
| **ProcessSlaSweeper** | NOVO (reusa scheduler tick + claim/lease) | Sweep de deadline step-type-aware: reversível → retry/escalate/dead-letter; **irreversível/value-bearing → SEM retry**, compensação materializada + **piso humano**. Lease evita double-fire. |
| **Business-transition gate + reconciliação de namespace** | NOVO gate + seed frozen (HARD GATE) | Gate que sabe que lead→qualified→won é válido e won→lead não (o **privacy mesh é no-op no value-chain** — verificado). Seed dos 6 domínios de cadeia + teste frozen "both-registries-agree". **Nenhum código de orquestração mergeia antes desse teste verde.** |

### Contrato da cadeia de valor
Um handoff avança só se, **numa transação DB**, em ordem: (1) idempotency key por-instância ausente do stage_history; (2) artefato passa **shape E occurrence** (todo evidence_ref dereferencia pra linha real); (3) gate de transição de negócio aprova a aresta (mesh só pra privacy, side-effect-free); (4) trust-ladder permite.

### Anti-Goodhart (3 dentes)
(1) **caixa é o único accrual** — `wasCashReceived()` com ref externo distinto; null = zero reward, surfaced não escondido. (2) o alocador da iter-2 é alimentado por sinal **ponderado-por-reconciliação (caixa que pingou)**, NÃO advance-density/time-in-stage — mata a vaidade de mover cards. (3) trust-ladder zera streak em revert.

### Teto residual
1. `wasCashReceived()` é tão honesto quanto sua fonte — até webhook de payment-provider real, a linha confirmada pelo operador é a raiz de confiança (operador mentiroso a derrota). Move o boundary pro lugar mais barato de auditar, não o elimina.
2. Occurrence prova que o ref resolve, não que a linha reflete a realidade se o próprio CRM/billing for alimentado pelo mesmo LLM (garbage-in).

### Build order
GATE 0: reconciliar o fork de namespace + seed dos 6 domínios + teste frozen. → SLICE 1: oráculo de caixa. → SLICE 2: provar 1 jornada no transporte existente até 1 dólar reconciliado. → SLICE 3: idempotency + artefatos occurrence + gate de transição. → SLICE 4: SlaSweeper + compensação + piso humano. → SLICE 5: outbox + sinal ponderado-reconciliação + analytics rotulada. → DEFER: template-engine versionado até 2ª cadeia com branching.

**Veredito:** o unlock do B2B (Tier 3) fica desenhado **sem over-engineering** — o mais simples que funciona (state-machine linear + transporte existente), occurrence-gated, prova 1 dólar antes do engine, e só conta avanço que vira caixa.

---

## Iteração 4 — Kernel de segurança unificado (Layer ⑤ escada de autonomia + Layer ⑥ contenção)

**Insight central:** **um único boundary fail-closed de pré-emissão** por onde TODA ação externa passa; autonomia **ganha devagar, revoga na hora, nunca fail-open**. Resolve o "revert assíncrono" e o "death-by-a-thousand-cuts" que apareceram como elo-fraco nas iters 1-3.

### Componentes
| Componente | Papel + salvaguarda |
|---|---|
| **VentureActionClass** (enum fechado, code-const) | Taxonomia fechada {marketing_post, email, price_change, ad_spend, charge, contract, outreach, refund} com metadata frozen (reversibilidade/blast/custo). Classe fabricada → **BLOCK fail-closed** + fila de alerta do operador. |
| **VentureActionReversibilityClassifier** | (action_class, payload, sinais verificados pela AUTORIDADE) → tier de reversibilidade. Sibling do ReversalCostTierClassifier, mas **fail-close também em payload incompleto/conflitante** (o pai só fechava em vazio) e lê magnitudes (amount, recipient_count) da AUTORIDADE/connector, **nunca do payload**. |
| **ExternalCaixaReconciler** (lynchpin, build 1º) | Aceita receita como 'reconciliada' **só de ingest out-of-band que o loop NÃO tem write-grant** (líquido de reembolso). Provenance NÃO é label de string (rejeitar `source='operator'` é evadido passando `source='stripe_reconciled'`) → é a fonte de escrita separada. |
| **AggregateVentureSpendEnvelope** (windowed, atômico) | Teto agregado por venture **através de TODAS as classes**, janelas rolantes (hora/dia/semana) + janela de portfólio. `check()+consume()` **single atomic compare-and-set** (o BudgetEnvelope atual é read-modify-write não-atômico). Fecha death-by-a-thousand-cuts. |
| **VentureKillAuthorityService** (por venture, heartbeat do operador) | Off-switch por venture (mirror do KillAuthority, dead-man 900s). **O fix de fail-open mais importante:** o heartbeat é escrito **só pela via de atestação do operador, NUNCA pelo processo venture/loop** — senão o loop se mantém vivo sozinho. |
| **AsyncActionHealthMonitorService** (veredito versionado debounced) | Rastreia outcomes pós-ação (janela 3-7d) por action_id; veredito com **versão estrita-monotônica** (a ladder só age em flip de versão maior; 'healthy' velho/visto é dropado); debounce + N_min; revert **através do controller**. |
| **VentureActionMandateRegistry** (receipt payload-bound) | Preflight por classe (post→brand/legality, email→CAN-SPAM/GDPR, price→margem, spend→vendor, charge→fraude, contract→assinatura). Receipt **binda o payload_hash** (assinatura prova que o operador aprovou ESTE payload, não um genérico) + anti-fatigue (digest em lote, pre-auth com standing-cap). |
| **VentureActionManifestService** (espinha de auditoria) | Ledger append-only: queued→preflighted→approved→executed→completed/reverted. action_id distinto = chave de join p/ atribuição de outcome + anti-double-count. Entry existe (queued) ANTES do connector disparar. |
| **VentureHealthGate** (multi-sinal, caixa reconciliado) | Superset do success: solvência/runway, piso churn/NRR, teto de concentração, legalidade, deliverability. 'succeeded' **só com MRR reconciliado** (processor/bank); `operator`/`loop` rejeitado. |
| **VentureActionDispatchController** (o ÚNICO boundary, build por último) | O único checkpoint na frente de TODA ação externa (orquestrador iter-3 + alocador iter-2 vão por ele, nunca em volta). **Double kill re-check** (no passo 2 E re-assertado dentro do MESMO lock do consume, imediatamente antes do connector disparar). |

### Lei do gate (fail-closed, ordem fixa)
Ação executa SEM decisão fresca do operador **sse**: (0) classifica (desconhecida→BLOCK); (1) reversibilidade não-irreversível OU mandato vivo payload-bound; (2) kill/dead-man não ativo (re-checado dentro do lock de emissão); (3) spend dentro do envelope agregado windowed (atomic reserve); (4) trust-ladder da (venture, classe) em `auto` por **streak de outcome reconciliado**. Qualquer sinal ambíguo/erro → **BLOCK ou route-to-operator**; nenhum passo relaxa uma negação anterior.

### Anti-Goodhart
Autonomia gradua **só em evidência que o ator não pode autorar**: (1) confiança acumula só de outcomes RECONCILIADOS, nunca recibos de execução — mata "subir pra autônomo no volume de ações disparadas"; (2) provenance de reconciliação é a fonte de escrita separada, não label; (3) heartbeat do kill é do operador; (4) receipt binda payload_hash.

### Teto residual
1. **Dano lento/inobservável em classes irreversíveis** (email enviado, charge liquidado, contrato assinado, post propagado): nenhum revert assíncrono desfaz — a única contenção real é o **piso humano de pré-emissão** (irreversível nunca auto-executa). Por isso irreversível fica operator-mandated; o residual é a qualidade da review do operador (mitigada por receipt payload-bound, não eliminada).
2. Heartbeat-do-operador troca disponibilidade por segurança (se o operador some 900s, a venture pausa — fail-safe correto).

**Veredito:** a autonomia fica **confiável por construção** — fail-closed sempre, ganha devagar em caixa reconciliado, revoga na hora, irreversível sempre com humano. Ship inteiro **default-OFF / byte-identical-OFF** (toda flag ausente ⇒ SUGGEST everywhere, thresholds PHP_INT_MAX).

---

## Iteração 5 — Crítico de completude vs fronteira de mercado (re-fundamentação)

**Veredito (duro e honesto):** **~6,5/10 contra "a mais poderosa do mercado"** — NÃO os 9,3 (que eram escopados a correção/segurança no espaço bounded). **Honestidade é propriedade de SEGURANÇA, não de PODER.** Atlas é o design mais honesto que existe e está no teto concebível de correção/segurança — mas está *brevemente-à-frente-na-verdade, não duravelmente-à-frente-em-valor*.

**A assimetria com a Polsia:** Atlas vence decisivamente na *qualidade do crédito* (todo dólar reconciliado, toda inferência ≤0.5, todo atuador fail-closed) e esmagaria os 10%-fazem-$1 / 48%-churn dela na RAZÃO que otimiza. Mas a Polsia vence no eixo que Atlas **estruturalmente nem mede: dólares-criados-por-mês** (+1.146 empresas/dia dela). O design otimiza "nunca mentir sobre progresso" e **não tem motor para "maximizar progresso por dia"**.

**O que JÁ é world-class (manter):** caixa-reconciliado como invariante de SCHEMA (DB-CHECK), o kernel fail-closed de boundary único, a fronteira observed-vs-inferred, a **dormência honesta** (byte-identical-OFF, recusa fabricar gradiente), o mecanismo do ≥70% (pré-filtra o denominador), e a disciplina anti-refragmentação.

**A metade que falta** = sem mãos (connectors), ~12 órgãos operacionais ausentes, sem espinha de throughput/velocidade, sem teoria de durabilidade — **mas quase tudo é WIRING de primitivos que o Atlas já tem.**

### Backlog re-priorizado (iterações 6+, maior alavancagem primeiro)
| # | Iteração | Por quê (alavancagem) |
|---|---|---|
| **6** | **Camada de connectors + domínio de falha externa + modelo de dispute/chargeback** (as mãos + verdade-de-caixa provisória) | **Destrava o stack dormente inteiro** (a única pré-condição) E fecha o buraco mais perigoso do estado-armado (alocador dobrando aposta em caixa sujeito a clawback). Tudo das iters 1-4 termina num muro selado: o DispatchController → um connector que não existe. Precisa: contrato de connector tipado (retry/idempotência/webhook-signature), sinal de liveness do feed (congela consumidores em feed stale/down — distingue zero-real de cano-quebrado), e tipo de evento negativo/reversão no ReconciledCashEventStore com horizonte de settlement que pode virar succeeded→failed. |
| **7** | **Espinha de throughput/velocidade gated** (métrica time-to-first-dollar + WIP de experimentos baratos em paralelo + biblioteca de arquétipos monetizáveis) | Converte uma máquina de razão-de-qualidade numa **máquina de criação-de-valor** — a resposta direta a perder-pra-Polsia-em-dólares. Registry de arquétipos tipado (cada forma declara seu JTBD/topologia-de-cadeia/rubrica-de-qualidade/teste-de-demanda) **mantém o MESMO gate de admissão** e só torna a cadeia polimórfica. Pós-#6 (precisa de plant pra agir). |
| **8** | **Órgãos operacionais faltantes** (deliverability, customer-success/retenção, ledger financeiro/runway real, back-office/obrigação-regulatória) | Cada um é órgão sem o qual uma empresa real não roda 24/7. Fatais-e-silenciosos primeiro: **deliverability** (1ª campanha autônoma pode torrar o domínio de envio irreversivelmente) + **accounting/runway**; retenção + back-office depois. Mais invenção-flavored → abaixo de #6/#7. |
| **9** | **Loop de self-construction por venture** (plugar o Self-Construction OS — o ativo carro-chefe — nas ventures) | Wiring puro (substrato existe e é provado), não invenção. **Multiplicador de breadth:** quando uma venture bate numa capability faltante (landing, Stripe, churn-model, scraper de nicho), hoje roteia pra 4 Domain Runtimes FIXOS e nunca constrói a própria. `build_capability` como classe de ação governada (detect-gap→build→sandbox→certify→wire) sob o kernel. **Blind-spot genuíno** (a sufficiency review nem levantou). |
| **10** | **Flywheel de transferência cross-venture + teoria de durabilidade/moat** | O ÚNICO lugar onde o compounding é **incopiável** — mas downstream de #6 (caixa real) + liveness de atribuição. Hoje o portfólio é N aprendizes lineares independentes, não um portfólio que fica super-linearmente-mais-esperto. Specar a drivetrain agora, armar após liveness. |
| **11** | **Cockpit de operador em linguagem natural + gramática de mandato-condicional permanente** | Mostly parser de predicado + NL sobre primitivos existentes — ataca o gargalo de throughput humano (o eixo que a tese canônica reivindica supremacia). Leverage-on-leverage: mais valioso depois de #6-#9 darem muitas ventures/mandatos pra triar. |
| **12** | **Modelo de adversário externo + órgão de aquisição CAC-payback + runtime de criação-de-demanda** | Real no Tier 2/3 mas por último: a governança de demanda já está desenhada (iter-4), o adversário externo é parcialmente mudo no escopo reversível micro-SaaS/content-SEO do Tier-2, e o substrato de CAC já existe. Tier polir-o-motor-de-crescimento. |

### Fronteira irredutível (nenhum design fecha)
1. **Soberania** (escolha, não defeito): formação de entidade/banco/contrato/dinheiro/regulatório/hiring/seguro ficam atrás de mandato — o job do design é ARMAR limpo quando o mandato chega.
2. **Capacidade do modelo:** originação greenfield genuína (escolher vencedor antes de evidência) tem teto — construímos falsificação, não onisciência.

**Veredito da iteração:** o loop pivota agora da metade "correção/honestidade" (world-class, mantida) **para a metade "valor/throughput/mãos"** — que é o que move a nota de 6,5 → mais perto de 10, e é majoritariamente wiring. Próximo: iteração 6 (as mãos).
