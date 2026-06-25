# Atlas — Escada de Evolução Exponencial do Loop (acima da base das 450 tasks)

> Doc canônico (authoring source of truth). Registra a evolução exponencial do escopo **Loop + Cortex + serving**
> acima da base "450 tasks entregues e armadas" (~7.5-8/10 engenheiro autônomo governado de single-target).
> Síntese de 5 agentes especializados + leitura anti-Goodhart. Maturity honesta por degrau.

## 0. A maior evolução de todas

**O Cortex deixa de observar e vira um GÊMEO SIMULÁVEL do escopo — e o oráculo desse gêmeo é o
delta-de-comportamento ATRIBUÍDO por decisão, não um reward escalar.**

Transmuta a natureza do Loop: de *tenta → mede → corrige* (cada attempt ~14 min de provider, regressão
arriscada) para *projeta → atribui → confirma* (explora N edições pelo custo de materializar 1). É a fusão de
duas faces do mesmo órgão:
- **Gêmeo simulável** — o Loop aplica a edição num worktree-espelho disjunto, observa o efeito de comportamento,
  e só o vencedor materializa na fonte viva.
- **Capability-delta-attribution fechada** — liga o Δ de comportamento medido à DECISÃO que o causou
  (originador, shape, provider), para o Loop reforçar os próprios comportamentos que geram capacidade real.

**Por que não existe igual no mundo:** Darwin-Gödel Machine / CUDA Engineer (Sakana) mutam código e medem um
reward escalar → reward-hackeiam. O gêmeo do Atlas difere em 3 eixos que não coexistem em nenhum outro lugar:
(1) oráculo de **ground-truth re-computável** (caller-graph re-resolvido fresh do git — o Loop não escreve a
própria nota; só possível local-soberano); (2) governado por **piso pétreo provado contra a cascata** (o
simulador pode ser ousado *porque* a constituição que ele não pode afrouxar é checada por construção);
(3) **captura N× de provider** com o mesmo piso reversível.

## 1. A escada (base → topo, ordenada por dependência)

Cada degrau **multiplica** o anterior (fator, não parcela). O piso (R3-FLOOR) é o único componente que sobe
junto por construção, mantendo o produto seguro.

| Degrau | O que adiciona | Multiplicador | Maturity |
|---|---|---|---|
| **R0** FACTS + outcome-ledger causal | tupla `{originador, shape, net-diff, provider, gates, commit}` por entrega | eixo-x do tempo×entregas | mechanism-exists (base) |
| **R1** DELTA de comportamento REAL | snapshot caller-graph/API pai-vs-merge; anti-Goodhart vira NÚMERO (mata o proxy de fan-in) | exponencial — sem ele o composto amplifica ruído | partial |
| **R2** Grafo causal + intent morto | aresta com semântica de contrato + staleness → material novo em código maduro | ataca o material-fuel gap | partial |
| **R3** Predição pre-hoc + **attribution FECHADA** | descobre sozinho quais comportamentos seus geram capacidade e os reforça | **a inflexão** — gradiente sobre a própria capacidade | aspiration |
| **R3-FLOOR** Piso que ESCALA com a velocidade | leap maior ⇒ verificação proporcionalmente mais forte | torna o resto exponencial-SEGURO | partial (falta §3.6(ii)) |
| **R4** Gêmeo simulável executável **(a maior evolução)** | best-of-N seguro no espaço de comportamento; obra multi-file reversível | o salto ousado deixa de ser proibido | aspiration |
| **R5** Antifragilidade de outcome + meta-objective (V4) | caos vira sinal; o Loop origina evoluções da própria maquinaria | o composto mais alto (realimenta a TAXA) | aspiration |
| **R6** Federation autopoiética sob 1 piso global | evolui escopos que ninguém deu, N em paralelo herdando priors | N escopos × M profundidade × auction-capture | partial (skeleton) |

**Por que é exponencial:** R0=tempo; R1=sinal honesto; R3=auto-referência seletiva (o seletor melhora com o
resultado); R4=colapsa o custo de exploração; R5=auto-referência estrutural (a maquinaria melhora a si mesma);
R6=multiplica por largura.

## 2. Fronteira de implementação — workers vs operador (o que torna "sem problemas")

O serving recusa commit em arquivo **pétreo** (`AtlasLoopHarnessGuard::FORBIDDEN_SELF_TARGETS`: o juiz, o
merge, o produtor de origination, `AtlasLoopUtilityGradeService`, `AtlasLoopHeldOutDeltaCertifier`,
`AtlasLoopNextWorkDecider`, toda a `Constitution/`, `config/atlas.php`). Por isso a escada se divide:

- **WORKER-IMPLEMENTÁVEL (vira task na fila):** CRIAR os órgãos novos como **classes novas em paths livres +
  teste** (single-file-resolvável, self-provável). É o grosso da entrega.
- **OPERADOR-BUILT (NÃO vira task de worker — seria doomed):** ARMAR as classes novas dentro do juiz/produtor/
  certifier pétreo (ex.: o `UtilityGradeService` passar a chamar o `BehaviorDeltaComputer`; a perna §3.6(ii)
  judge-execution na `Constitution/`; o `OriginationProducer` consumir o prior de attribution). É o design: o
  réu nunca edita o juiz. Cada classe nova abaixo nomeia explicitamente o seu **ponto de armação operador**.

> Regra de ouro do shaping (garante "implementável sem problemas"): cada task = **1 classe nova concreta + 1
> teste em `tests/`**, objetivo auto-contido, acceptance + required_evidence, `allowed_files` exatos, ZERO path
> pétreo, `depends_on` declarado. Integração em sítio pétreo é follow-up do operador, nunca task de worker.

## 3. Ordem de entrega (waves) e o primeiro degrau

Sequência não-negociável (o piso antes do salto): **R1 → §3.6(ii) judge-execution (operador, em paralelo) →
R2 → R3 → R4 → R5 → R6.**

**Primeiro degrau a atacar: R1 (DELTA de comportamento real).** Menor risco (aditivo/observacional, re-resolvido
fresh do git → não vira teatro) e maior alavancagem (pré-requisito honesto de tudo acima; construir R3 sobre o
proxy de fan-in seria construir a faísca sobre Goodhart).

### Backlog worker-implementável (enfileirado, tag `expo-ladder`)

Cada item é uma classe nova + teste; `depends_on` entre parênteses; **[OP]** = ponto de armação do operador.

- **W1 (R1)** `AtlasLoopBehaviorDeltaSnapshotter` — snapshot determinístico do caller-graph + API-surface num
  commit. [OP: `UtilityGradeService` passa a chamá-lo no commit pai-vs-merge.]
- **W2 (R1)** `AtlasLoopBehaviorDeltaComputer` (dep W1) — diffa dois snapshots → Δ de comportamento tipado.
- **W3 (R2)** `AtlasLoopCausalEdgeClassifier` — enriquece a aresta de blast-radius com a classe de
  API-breaking-change (added/removed/signature). [OP: ligar no `BlastRadiusAnalyzer`.]
- **W4 (R2)** `AtlasLoopDeadIntentDetector` (dep W3) — intent reconstruído + staleness → intent morto colhível.
- **W5 (R3)** `AtlasLoopCapabilityDeltaAttributionService` (dep W2) — re-particiona o slope do
  `CapabilityTrendService` por shape com Wilson lower-bound + minSamples. **A peça de maior alavancagem.**
  [OP: `OriginationProducer`/`AmbitionDecider` consomem o prior.]
- **W6 (R3)** `AtlasLoopPreHocBlastRadiusPredictor` (dep W3) — projeta os findings que uma edição hipotética
  CAUSARIA, sem materializar o patch.
- **W7 (R4)** `AtlasLoopSimulableTwinOrchestrator` (dep W2, W6) — snapshot→aplica-no-espelho→trace→rollback,
  integrando a infra AAEL + a árvore contrafactual TEOS/AEMOR num laço único.
- **W8 (R4)** `AtlasLoopCounterfactualEditEvaluator` (dep W7) — best-of-N no espaço de comportamento; só o
  vencedor materializa.
- **W9 (R5)** `AtlasLoopChaosSignalLabeler` — rotula reject/crash/give-back/drift como sinal de 1ª classe.
- **W10 (R5)** `AtlasLoopMetaObjectiveProposer` (dep W5) — PROPÕE evoluções da própria maquinaria (gated;
  nunca é o `OriginationProducer` pétreo). [OP: gate frozen dispõe sobre a proposta meta.]
- **W11 (R6)** `AtlasLoopAttributionInheritanceChannel` (dep W5) — federa o prior de attribution entre ciclos
  `MultiCycle/*` mantendo disjunção de arquivos.
- **W12 (R6)** `AtlasLoopAutopoieticScopeGovernancePipeline` (dep W10, W11) — pipeline governado de
  scope-origination sobre os skeletons `ScopeOriginationProposer` + `AutopoieticBootstrapper`.

### Pontos de armação do operador (pétreo — fora da fila de worker)
1. `UtilityGradeService` consome `BehaviorDeltaComputer` (mata o proxy de fan-in que o código confessa).
2. §3.6(ii) judge-execution na `Constitution/` (a última porta do piso fail-closed → PASS dischargeable).
3. `OriginationProducer` + `AmbitionDecider` consomem o prior de `CapabilityDeltaAttributionService`.
4. `NextWorkDecider` consome a predição pre-hoc de blast-radius.

## R7 — Endogenous Muscle / Self-Model Evolution (o salto acima do topo)

**Atlas para de ALUGAR músculo e passa a CRIAR e EVOLUIR o próprio músculo.** A escada R0→R6 melhora o
cérebro (memória, atribuição, gêmeo, origação) e aluga o músculo (Claude/Codex/MiniMax). R7 fecha a recursão no
próprio modelo: um modelo self-hosted que o Loop fine-tuna/destila no **próprio ledger de outcome atribuído**
(os dados de R3), promovido por um gate held-out + recibo do operador. A antifragilidade composta deixa de ser
N×M e vira **N×M×(auto-modelo)** — Atlas gera o próprio N× em vez de só capturar o do provider. Remove de uma
vez a provider-dependência, o teto model-bound de greenfield, e (via oráculo plugável) o confinamento de
domínio: a MESMA maquinaria recursiva passa a rodar sobre qualquer escopo que forneça um oráculo de
ground-truth honesto (código=caller-graph/testes; marketing=conversão; trading=P&L).

**Fronteira worker vs operador (o que torna R7 "sem problemas"):** o **treino/serving real do modelo é infra do
operador** (GPU/ML, não unit-testável → nunca vira task de worker). Os workers constroem a **camada PHP
governada, determinística e unit-testável** em volta dele — corpus, oráculo, eval held-out, gate de promoção,
registry, rollback. Cada task = 1 classe nova + teste, FACT-only, zero chamada de provider, zero treino real
(o harness recebe saídas-candidato como input de fixture, não treina).

**O piso é MAIS load-bearing aqui, não menos:** um sistema que treina o próprio modelo na própria métrica é o
reward-hacking de livro-texto (o que matou o CUDA Engineer da Sakana). Por isso o `ModelPromotionGate` é
fail-closed: um modelo-candidato só entra no slot de músculo se bater o incumbente num **held-out que ele nunca
viu** E sem regressão de guardrail E com **recibo do operador**. Nenhum modelo se auto-promove.

### Backlog R7 worker-implementável (tag `expo-ladder` + `R7`)
- **W13** `AtlasLoopOutcomeCorpusBuilder` (dep w5) — ledger atribuído → corpus de treino FACT-only/provider-safe.
- **W14** `AtlasLoopCorpusProvenanceLedger` (dep W13) — toda build de corpus auditável (anti-poisoning).
- **W15** `AtlasLoopModelOutcomeOracle` (interface) + `AtlasLoopCodeDomainOutcomeOracle` — o oráculo honesto
  plugável (ground-truth re-computável, nunca self-report); adapter de código sobre o net-diff.
- **W16** `AtlasLoopHeldOutEvalHarness` (dep W13) — eval determinístico de saídas-candidato contra split held-out.
- **W17** `AtlasLoopModelPromotionGate` (dep W16) — gate fail-closed de promoção (held-out + sem regressão + recibo).
- **W18** `AtlasLoopModelRegistry` (dep W17) — ponteiro de versão ativa + prev-version, transições receipt-gated.
- **W19** `AtlasLoopMuscleRollbackController` (dep W18) — rollback atômico se o modelo promovido degradar no canary.
- **W20** `AtlasLoopSelfModelTrainingPlanComposer` (dep W14, W15) — compõe o PLANO de treino (spec) que a infra
  externa do operador consome; não treina.
- **W21** `AtlasLoopCrossDomainOracleRegistry` (dep W15) — registra domínio→oráculo (a maquinaria vira multi-domínio).

### Ponto de armação do operador (fora da fila — infra)
Treino/destilação real do modelo self-hosted (consome o plano de W20 + o corpus de W13/W14), serving local do
modelo promovido (consome W18), e o canary live que dispara W19. É o único pedaço de R7 que não é PHP
unit-testável — fica como infra do operador, alimentada pela camada governada que os workers entregam.

## R8 — Total Codebase Sovereignty (o Loop dono de TODA a base)

O salto acima do R7: o Loop deixa de evoluir só `AutonomousEvolution/` e vira o **único mantenedor autônomo do
Atlas inteiro** (todo o `app/`, 15 domínios, testes, docs) — implementa, melhora, evolui, corrige, **acha bugs**,
24/7, sem humano revisando. Fato verificado: o **alcance de escrita já existe** (`discovery_roots` é uma linha
de config); o que falta é a **PROVA** de que escrever em tudo é evolução e não regressão cega.

**A maior evolução do R8: Cérebro-de-Mundo Único com Prova Proporcional ao Raio.** A base inteira vira UM grafo
causal vivo **antes** de qualquer escrita, e o rigor da prova é função do blast-radius medido (doc → cert leve;
assinatura pública consumida por 40 callers em 3 domínios → twin + held-out + crítica cross-model + abstain).
"Saber tudo de tudo" vem **antes** de "mexer em tudo".

| # | Degrau | Mecanismo | Maturity |
|---|---|---|---|
| **R8.0** | Cortex repo-wide federado (world-model único) | federar `ScopeComprehensionModelBuilder` sobre todos os roots → 1 read-model; caller-oracle repo-wide | partial |
| **R8.1** | Blast-radius ARMADO + grafo reverso | ligar `BlastRadiusAnalyzer` ao `consumersOf` real de R8.0 + Twin + PreHocPredictor | partial |
| **R8.2** | Verificação proporcional ao raio *(pétreo)* | roteador `(net_delta × fan-out × sensibilidade) → tier`, monotônico | partial |
| **R8.3** | Falsification Gate (bug REAL provado) | bug só conta com RED reproduzível → fix verde → net-diff positivo; falso-positivo morre antes do worker | partial |
| **R8.4** | Oráculos de outcome POR DOMÍNIO *(o gargalo real)* | `Marketing/Trading/Finance OutcomeOracle` no `CrossDomainOracleRegistry`; sem oráculo honesto = só proxy = Goodhart | aspiration |
| **R8.5** | Território earned domínio-a-domínio | `TerritoryLadder::canPromote()` (já existe) — promove só com prova + frozen safety file sob o root; NUNCA big-bang | partial |
| **R8.6** | Piso pétreo de 2ª dimensão | `FORBIDDEN_SELF_TARGETS` ∪ fecho do `CertChainClosure` + production-live forbidden + per-domain oracle freeze | partial |
| **R8.7** | Sentinel + irreversibility veto cross-domínio | `MainHealthSentinel` cross-domínio, `git revert` do culpado (nunca reset --hard), veto de irreversível | aspiration |
| **R8.8** | R7 muscle aprende o Atlas inteiro | attribution + training-plan cross-domínio; modelo só PROPÕE, prova é do Falsification Gate | aspiration |
| **R8.9** | Soberania total provada (operador = só intenção) | composição R8.0–R8.8 em soak multi-semana; MultiCycle particiona domínios entre N workers | aspiration |

**Risco-mãe (escrita repo-wide):** (1) raio mal-medido, (2) falso-positivo de bug, (3) auto-imunização
(editar os oráculos por domínio). O piso escala em 2ª dimensão: fail-closed na incerteza de raio (erra sempre
para MAIS prova); bug só conta se falsificável-RED; **floor-as-property** (juiz + fecho cert-chain, cresce-nunca-
encolhe); production-live e todo oráculo entram em FORBIDDEN; território earned, nunca decretado. Regra dura: o
**operador define a ordem de release**; o Loop JAMAIS semeia a própria expansão de território.

### Fronteira worker-vs-operador (R8)
**WORKER (classe nova + teste, não-pétreo):** `RepoWideComprehensionModel` + `RepoWideCallerResolver` (R8.0);
`RepoWideConsumersProvider` (R8.1, adapter que o BlastRadiusAnalyzer consome — não edita o analyzer);
`DefectFalsificationGate` (R8.3); `Marketing/Trading/Finance OutcomeOracle` (R8.4, contra a definição
congelada); `CrossDomainTrainingSampler` (R8.8).
**OPERADOR-BUILT:** roteador de tier pétreo (R8.2); ordem de release dos domínios (R8.5); **seed da definição
de "correto" por domínio** (pré-requisito dos oráculos R8.4); piso de 2ª dimensão (R8.6); sentinel cross-domínio
+ MergeActuator (R8.7); soak de endurance (R8.9). Inventários operador-assinados obrigatórios: (1) paths
production-live, (2) definição de outcome por domínio, (3) ordem de release.

## 4. Leitura honesta
Mecanismo real hoje: R0 inteiro + as 3 camadas pétreas + infra AAEL trace/rollback + serving conflict-free +
skeletons de scope-origination. Aspiração: R3 (não existe `AtlasLoopCapabilityDeltaAttributionService`), R4
(laço), R5/R6. Gargalo: a faísca recursiva + o material-fuel gap + o wiring (muito construído ≫ armado).
Risco-mãe: a velocidade ultrapassar o piso — mitigado por construção (espelho efêmero, sandbox-floor,
monotonicity, `requiredVerification()` proporcional), **desde que R3-FLOOR preceda R4/R5**.
