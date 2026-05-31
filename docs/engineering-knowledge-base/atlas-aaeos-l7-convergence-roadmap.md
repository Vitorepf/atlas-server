---
id: atlas-aaeos-l7-convergence-roadmap
type: engineering_knowledge
title: AAEOS L7 Convergence Roadmap — ordered path from runtime reality to L7 Self-Evolving
doc_schema: atlas_canonical_module_doc.v1
status: planned
implementation_state: roadmap_only_no_runtime
authority_class: index
category: agentic-engineering
priority: 99
summary: Roadmap canonico ordenado que projeta sobre os docs AAEOS para sequenciar, por dependencia, tudo que precisa virar codigo-runtime para o Atlas subir a Autonomy Ladder ate L7 Self-Evolving. Cruza o nivel-alvo (documentacao) com o nivel-real (runtime gap matrix) por area e ordena o trabalho em fases. Nao e runtime, nao governa as fontes; apenas ordena o caminho e aponta para os specs canonicos onde cada passo vive.
owner: operator (Vitor)
risk_level: medium
tags:
  - atlas-ai
  - aaeos
  - autonomy-ladder
  - l7-self-evolving
  - convergence-roadmap
  - stewardship-loop
capabilities:
  - l7_convergence_ordering
  - target_vs_real_by_area
  - ladder_rung_unlock_map
decisions:
  - Este doc ordena o caminho; ele nao prova entrega e nao substitui os specs canonicos.
  - L7 e o topo da Autonomy Ladder (autonomia para agir), nao a matriz de maturidade dos departamentos.
  - Cada fase so conta como feita apos codigo, teste, evidencia e merge honesto (main_before != main_after).
maintenance:
  - Reordenar fases apenas quando um blocker raiz mudar de estado real (ex.: S49 deixar de ser scan-only).
  - Manter os IDs de slice e nomes de servico alinhados aos docs-fonte linkados.
  - Atualizar a coluna nivel-real a partir da runtime-gap-matrix e da department-maturity-matrix, nunca por auto-declaracao.
related_paths:
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
  - docs/engineering-knowledge-base/atlas-autonomy-ladder-promotion-runbook.md
  - docs/engineering-knowledge-base/atlas-agentic-engineering-os.md
  - docs/engineering-knowledge-base/atlas-agentic-engineering-os-runtime-gap-matrix.md
  - docs/engineering-knowledge-base/atlas-aaeos-department-maturity-matrix.md
  - docs/engineering-knowledge-base/atlas-aaeos-department-quality-bar-matrix.md
  - docs/engineering-knowledge-base/atlas-aaeos-evolution-backlog-index.md
  - docs/engineering-knowledge-base/atlas-aaeos-loop-evolution-backlog.md
  - docs/engineering-knowledge-base/atlas-aaeos-http-path-integration-spec.md
  - docs/engineering-knowledge-base/atlas-aaeos-l8-transcendence-map.md
  - docs/engineering-knowledge-base/atlas-aaeos-l9-sovereign-engineering-map.md
  - docs/engineering-knowledge-base/atlas-aaeos-l10-generative-engineering-map.md
  - docs/engineering-knowledge-base/atlas-agentic-engineering-os-runbook.md
graph_id: atlas-aaeos-l7-convergence-roadmap
graph_title: AAEOS L7 Convergence Roadmap
graph_world: atlas
graph_layer: module
graph_kind: index
graph_parent: atlas-agentic-engineering-os
graph_status: planned
graph_source: repo
repo_paths:
  - docs/engineering-knowledge-base/atlas-aaeos-l7-convergence-roadmap.md
allowed_changes:
  - Atualizar estado real de cada fase quando os docs-fonte ou a gap matrix mudarem.
  - Adicionar passos apenas com ID de slice, servico-alvo ou comando concreto e criterio de aceite.
forbidden_changes:
  - Do NOT treat roadmap rows as delivered runtime.
  - This roadmap orders existing canonical specs and never overrides them.
  - Do NOT mark a phase done without code, test, evidence and honest merge.
  - Do NOT use the words Jarvis, Rivals, benchmark, superiority, concurrent in this doc.
depends_on:
  - atlas-autonomy-ladder-promotion-runbook
  - atlas-agentic-engineering-os
  - atlas-agentic-engineering-os-runtime-gap-matrix
  - atlas-aaeos-department-maturity-matrix
  - atlas-aaeos-evolution-backlog-index
  - atlas-aaeos-loop-evolution-backlog
  - atlas-aaeos-http-path-integration-spec
  - atlas-agentic-engineering-os-runbook
flows_to:
  - atlas-autonomy-ladder-promotion-runbook
unlocks:
  - l7_convergence_visibility
  - ordered_implementation_path_to_self_evolving
governs:
  - aaeos.l7_roadmap_ordering
evidence:
  - docs/engineering-knowledge-base/atlas-aaeos-l7-convergence-roadmap.md
required_tests:
  - php artisan atlas:engineering:knowledge docs-health --json
requires_evidence: true
next_actions:
  - Executar a Fase 0 (destravar execucao) antes de qualquer rung acima de L1.
---
# AAEOS L7 Convergence Roadmap

## Resumo

Este documento responde a uma pergunta unica: **o que precisa virar codigo, e em que ordem, para o Atlas chegar ao L7 Self-Evolving.**

L7 e o topo da **Autonomy Ladder** (`atlas-autonomy-ladder-promotion-runbook`): o nivel em que o Atlas **propoe e implementa o proprio refator, com gate**, e o humano apenas **governa soberania**. "Chegar ao L7" = fazer o runtime alcancar o que a documentacao ja descreve, subindo os 8 degraus da escada (L0 -> L7), cada um com criterio de saida medivel.

O roadmap cruza, por area, o **nivel-alvo** (o que os docs descrevem) com o **nivel-real** (o que a `runtime-gap-matrix` prova hoje) e ordena o trabalho em **fases por dependencia**. A regra-mae do AAEOS vale em cada linha: `doc_maturity != implementation_state`; **DOC L4 nao significa runtime pronto**; *claim de pronto sem evidence e falso completo*. Para nomes de cluster (Dev, Forge, AtlasForge, AtlasDev) a fonte e `atlas-canonical-glossary-and-naming.md`.

## Papel no Atlas

Este doc **ordena o caminho**; ele nao prova entrega, nao executa nada e nao governa os specs-fonte. Cada passo aponta para o doc canonico onde vive (`src=`). Um passo so vira valor apos codigo + teste + judge + evidencia + **merge honesto** (`main_before != main_after`).

## Onde Se Encaixa

```text
atlas-agentic-engineering-os                      (a mae conceitual)
  +-- atlas-autonomy-ladder-promotion-runbook     (define os 8 niveis + criterios)
  +-- atlas-aaeos-l7-convergence-roadmap          (este doc: ordena o caminho ate L7)
        +-- atlas-aaeos-evolution-backlog-index   (os 149 slices que o loop implementa)
        +-- atlas-agentic-engineering-os-runtime-gap-matrix  (o nivel-real)
        +-- atlas-aaeos-department-maturity-matrix           (maturidade por depto)
```

## Contratos

### O destino: os 8 niveis (L0 -> L7)

A Autonomy Ladder tem **8 niveis canonicos**. Subir e a definicao operacional de "chegar ao nivel da documentacao". Schema de promocao: `atlas.autonomy.promotion_request.v1`.

| Nivel | Nome | A IA faz | O humano faz | Criterio de saida (medivel) |
|---|---|---|---|---|
| **L0** | Assist | Sugere e completa codigo | Faz tudo | 50 sessoes >=80% acceptance, 0 alucinacao grave |
| **L1** | Slice Co-Pilot | Edita 1-3 arquivos, escopo declarado | Aprova e valida | 20 slices verdes seguidos, scope_violation=0, repair_loop<=1 |
| **L2** | Multi-Slice Pair | 3-10 arquivos, par com reviewer | Decide risco | 30 obras-par verdes, regression_catch_rate>=0.9 |
| **L3** | Feature Owner | Feature R3 completa com gates universais | Supervisiona | 15 features cert-verde, blocker_in_review<=1 |
| **L4** | Obra Owner | Obra de ~1 semana no Forge, paralelismo | Aprova milestones | 5 Obras seguidas cert-verde, 0 rollback no cert, dual_signature=5 |
| **L5** | Department Owner | Opera 1 departamento sozinho | Define prioridade | 90 dias sem intervencao humana, depto.maturity>=L4 |
| **L6** | Multi-Department Conductor | Conduz 3+ departamentos | Atua como gestor | 30 dias com 3+ deptos ativos, cross_dept_blocker_resolution_p95<=2h |
| **L7** | **Self-Evolving** | **Propoe e implementa o proprio refator (com gate)** | **Governa soberania** | **10 propostas de self-construction aprovadas, 0 invariante quebrada, Trust Ledger >=0.95** |

**Governanca do L7** (a mais blindada): promocao L6->L7 exige criterio + **dupla assinatura** (Operador + Architect) + **review humano do Architect** + **Trust Ledger >=0.95**. Demote e automatico: 2 ciclos com qualquer metrica abaixo do threshold -> cai L7->L6 sem assinatura.

### Ponto de partida: onde o runtime esta hoje

- **So o esqueleto AAEOS e `solid_runtime`.** Todo o resto core (Dev, Forge, HTTP path, gates, Mission Control, Stewardship 24h) e `partial_runtime`; os 149 leap slices sao `backlog_only_no_runtime`.
- **O loop esta Tier-0 (scan-only).** `autonomy_tier_active` esta **hardcoded em 0**. *"Sem S49 o loop fica scan-only e nada executa."*
- **O Atlas nao consegue medir o proprio nivel.** Os comandos `atlas:aaeos:maturity --json` e `atlas:aaeos:quality-bar --json` **nao existem** (estao em next_actions). O motor da ladder (`AtlasAutonomyLadderRuntimeService`) tambem nao foi construido ("Escopo de Implementacao").
- **Auditoria de codigo (memory, 31/05):** ~80% wired / ~38% delivers; Forge prod path = fixture.

**Conclusao:** o runtime real esta **efetivamente abaixo do L1** — nao executa nem o slice autonomo de 1-3 arquivos sem o recibo do operador (S49). A distancia ate L7 e grande em degraus, mas **concentrada em poucos blockers de execucao**.

### Sintese ordenada: nivel-alvo vs nivel-real por area

A sintese da varredura, **reordenada pela sequencia de implementacao** (de "destrava tudo" para "ultimo degrau") e mapeada para a fase.

| Ordem | Area | Nivel-alvo (codigo == doc) | Nivel-real hoje | Fase | Gap |
|---|---|---|---|---|---|
| 1 | **Loop (evoluir ao usar)** | 10 ciclos, replay, util 96%, TEOS | 🔴 Tier-0 scan-only, nao executa | 0,1,2,3 | 🔴 |
| 2 | **Reliability / Test-OS** | QA L4+ (>=30 contract tests) | L2, spec-only | 2 | 🟠 |
| 3 | **Aprendizado (outcome julgado)** | Gate anti-false-learning sempre | 🟢 implemented_local_runtime (mais maduro) | 3,5 | 🟢 |
| 4 | **Contexto** | Workspace-gated, composer governado | Composer implementado; resto spec | 4 | 🟡 |
| 5 | **Recuperacao de contexto** | Recall mandatorio + fan-out + AARF gate | AARF building (read-only); resto spec | 4 | 🟠 |
| 6 | **Qualidade de contexto** | Gates numericos certificados | Servico-fonte existe; gates spec | 4 | 🟠 |
| 7 | **Memoria** | Immune kernel + L4+ (p95 <=400ms) | Substrato implementado; immune kernel pending; L3 | 5 | 🟡 |
| 8 | **Deep cores** | Paridade com kernel-fonte | Servicos existem; classes puras nao | 5 | 🟠 |
| 9 | **Integracao / orquestracao** | 17 fases costuradas | Servicos existem; costura nao implementada | 6 | 🔴 |
| 10 | **Dev** | L4+ (p95 <=45s) | 🔴 L1 (A2/HTTP/parity) | 7 | 🔴 |
| 11 | **Forge** | L5+ (obra >=0.97) | L4 doc / partial_runtime; AAWR planning-only | 7 | 🟠 |

Legenda: 🟢 executa · 🟡 parcial wired · 🟠 spec sobre servico existente · 🔴 blocker estrutural.

### Mapa: rung da Autonomy Ladder -> fases que destravam

| Rung | Destravado por |
|---|---|
| **L1** Slice Co-Pilot | Fase 0 (execucao) + Fase 1 (ciclo real) |
| **L2** Multi-Slice Pair | Fase 2 (confiabilidade + reviewer) |
| **L3** Feature Owner | Fase 6 (HTTP path + gates universais) |
| **L4** Obra Owner | Fase 6 (Forge real + Mission Control) + Fase 7 (Forge L4) |
| **L5** Department Owner | Fase 7 (todos deptos >=L4) + Fase 2/3 (90 dias sem intervencao) |
| **L6** Multi-Dept Conductor | Fase 6 (choreography) + multi-depto ativo |
| **L7** Self-Evolving | Fase 3 (flywheel) + Fase 8 (self-construction + Trust Ledger) |

## Fluxo

### Principio de ordenacao

1. **Nao se pula degrau.** A ladder e sequencial (L0->L1->...->L7); cada promocao precisa do criterio do degrau anterior comprovado.
2. **Primeiro o que destrava execucao**, depois confiabilidade, depois o flywheel de auto-melhoria, depois substrato (contexto/memoria), depois a costura (HTTP/orquestracao), depois maturidade dos departamentos, e por fim self-construction.
3. **Medir antes de promover.** Sem os comandos de maturidade/quality-bar, nenhuma promocao e provavel — por isso a instrumentacao entra na Fase 0.
4. **Kernel puro != runtime wired.** Muitos slices ja existem como logica pura espelhando servicos reais; o valor so aparece quando o **wiring** (controllers, providers, git, merge) for feito.

### O roadmap ordenado (Fases 0 -> 8)

> Convencao: cada passo traz `[src=...]` para o doc/slice canonico. **Rung** = qual degrau da ladder a fase destrava.

#### FASE 0 — Destravar execucao (precondicao de TUDO) — raiz
**Objetivo:** sair do Tier-0 scan-only e poder estar na ladder. **Rung: L0 -> L1 mecanicamente possivel.**

1. **S49 — Autonomy Tier Promotion.** Recibo `operator_decision_receipt.v1` assinado sobe `autonomy_tier_active` de 0->1 por area; kill-switch derruba para 0 instantaneo. *RAIZ DE EXECUCAO; nada executa sem este recibo.* `[src=atlas-aaeos-loop-evolution-backlog.md:S49]`
2. **Motor da ladder.** Construir `AtlasAutonomyLadderRuntimeService` + `AtlasAutonomyMetricsAggregator` + `AtlasAutonomyDemoteWatchdog` (hoje "Escopo de Implementacao"). `[src=atlas-autonomy-ladder-promotion-runbook.md]`
3. **Instrumentacao de nivel.** Implementar `atlas:aaeos:maturity --json` e `atlas:aaeos:quality-bar --json` + telemetria por departamento. Sem medir, nenhuma promocao e provavel. `[src=atlas-aaeos-department-maturity-matrix.md / atlas-aaeos-department-quality-bar-matrix.md]`
4. **Ponte de decisao (kernels puros, baixo risco, paralelos):** S301 (= S49, decide promocao sem efeito colateral) e S302 (= S50, gate execute/fixture_only/blocked). `[src=atlas-aaeos-factory-runtime-bridge-backlog.md:S301,S302]`

**Gate da fase:** recibo de tier assinado existe; ladder service responde promote/demote; comando de maturidade retorna JSON real.

#### FASE 1 — Execucao produtiva real (fixture -> real) — maior alavancagem
**Objetivo:** trocar fixture por execucao de provider real e provar o primeiro ciclo merged. **Rung: L1 Slice Co-Pilot provavel.**

1. **S50 — Wire `AtlasForgeProviderInvocationService` execute mode no caminho de producao.** Hoje `AtlasCodeForgeExecutionController` chama o fixture `AtlasForgeLiveExecutionService`; rotear obras reais via ProviderInvocation atras dos 6 confirm-gates. `[src=atlas-aaeos-loop-evolution-backlog.md:S50]`
2. **S39 — Alimentar a Gap Matrix como fonte de finding.** `[src=atlas-aaeos-loop-evolution-backlog.md:S39]`
3. **S55 — Primeiro ciclo merged real (keystone / acid test).** Depende de S49+S50+S39. Exige provider executando em worktree, commits em branch, `git merge --ff-only`, ledger receipt, replay deterministico. Prova: `external_provider_call=true` + changed_files + `main_before != main_after`. `[src=atlas-aaeos-loop-evolution-backlog.md:S55]`

**Gate da fase:** 1 ciclo real merged com evidencia; 20 slices verdes seguidos (criterio L1).

#### FASE 2 — Confiabilidade do loop (rodar sem supervisao)
**Objetivo:** o loop sobrevive 24/7 e se protege antes de gastar provider. **Rung: L2 + base dos "X dias sem intervencao".**

1. **AP-790 Reliable 24h Loop Runner** (hardening) + **AP-805 Ten-Cycle Readiness Governor**: 10 ciclos consecutivos sem crash, audit AP-763 **29/29**, 0 budget violation, replay deterministico. *(`Reliable24hLoopRunnerService.php` ja esta em modificacao no working tree.)*
2. **S40 — 24/7 failover/recovery + budgets.** `[src=atlas-aaeos-loop-evolution-backlog.md:S40]`
3. **Self-protection pre-spend (firewall) — wire S261-S266:** feasibility scorer, destructive-diff classifier, breadth scorer, destructive-test-coverage e balance contracts. `[src=atlas-aaeos-loop-self-protection-leap-backlog.md:S261-S266]`
4. **Reliability / Test-OS — wire S161-S180:** circuit-breaker (clamps anti-flap), quarentena de blocker transiente, governanca de merge-conflict/retry, criticos de "teste de verdade", derivadores de classe de equivalencia. `[src=atlas-aaeos-reliability-testos-leap-backlog.md:S161-S180]`

**Gate da fase:** 10 ciclos verdes seguidos com replay; firewall bloqueia trabalho destrutivo com razao auditavel; 30 obras-par verdes (L2).

#### FASE 3 — Flywheel composto (aprender e auto-melhorar)
**Objetivo:** o loop mede o proprio lift, aplica melhoria governada e reverte regressao — a metade "evolve" do L7. **Rung: pre-requisito de L7.**

1. **S56 medir lift -> S57 auto-apply governado (gate `rsi_meta_judge`) -> S58 auto-revert em regressao (`git revert`, NUNCA `reset --hard`) -> S59 provar flywheel sobre K ciclos.** `[src=atlas-aaeos-loop-evolution-backlog.md:S56-S59]`
2. **Certificacao de compounding — wire S307-S311:** real-cycle evidence completeness, learning-lift attribution, measured-learning apply gate, regression-revert decision, compounding-flywheel certification (so certifica com lift agregado positivo e **zero auto_apply nao medido**). `[src=atlas-aaeos-factory-runtime-bridge-backlog.md:S307-S311]`

**Gate da fase:** flywheel provado sobre K ciclos reais com deltas medidos e revert automatico funcionando.

#### FASE 4 — Plano cognitivo: contexto / recuperacao / qualidade
**Objetivo:** elevar a qualidade do substrato que toda decisao consome. **Rung: levanta a maturidade de todos os departamentos (insumo de L3+).**

1. **AARF vira gate obrigatorio** (hoje read-only sobre AHRI) + persistir receipts em todos os flows. `[src=atlas-agentic-rag-framework.md]`
2. **Cognitive plane — wire S126-S158:** gates de qualidade (irrelevant-ratio <=0.05, hallucination bands 0.05/0.15), coerencia de claim, recall triggers + fan-out (nunca zero retrievers), staleness ladder, learning-packet quality. `[src=atlas-aaeos-cognitive-plane-leap-backlog.md:S126-S158]`
3. **Cognitive Immune Learning Kernel** (hoje "contract active, implementation pending"): o gate que filtra captura -> memoria. `[src=atlas-ai-memory-context-core-open-brain.md]`

**Gate da fase:** AARF bloqueia/degrada contexto insuficiente em runtime, com receipt.

#### FASE 5 — Memoria (AEMOR / DeepVein) + Deep Cores
**Objetivo:** memoria governada e julgada chega a Memory L4. **Rung: Memory depto L3 -> L4 (insumo de L5).**

1. **Promover o AEMOR Judgment & Learning Guard** de `implemented_local_runtime` para wired/governed no caminho produtivo (area mais madura — *"outcome precisa ser julgado antes de virar memoria"*). `[src=atlas-aemor-judgment-learning-guard.md]`
2. **Deep-Vein — wire S221-S231:** conflict-axis resolver (authority>evidence>freshness), memory-health composite (7 dimensoes), scope-contradiction. `[src=atlas-aaeos-aemor-deepvein-leap-backlog.md:S221-S231]`
3. **Deep cores — wire S201-S209:** memory quality-band, compaction loss-risk, AUCRI token-quality (must_keep_coverage=1.0 veto), token-economy local-prereasoning, false-learning gate, receipt provider authorization. `[src=atlas-aaeos-deep-cores-leap-backlog.md:S201-S209]`

**Gate da fase (Quality Bar Memory L4+):** promotion accuracy >=0.95, quarantine <=0.05, retrieval p95 <=400ms.

#### FASE 6 — A costura: HTTP path + orquestracao (o "single biggest unlock")
**Objetivo:** ligar o caminho HTTP real ao kernel canonico e costurar os departamentos. **Rung: L3 Feature Owner + L4 Obra Owner.**

1. **HTTP path — migracao de 4 fases** (feature flag `http_path_phase`), hoje *"pula da fase 0 direto para a fase 10, ignorando 8 fases de governance"*:
   - **F1:** Mission opcional + Place Feature obrigatorio.
   - **F2:** AI Router + Policy gate obrigatorios.
   - **F3:** AAWR + Decide obrigatorios para R3+.
   - **F4:** Company Runtime obrigatorio + AiWorker vira thin delegator + Decision Receipt v2 antes do provider call.
   `[src=atlas-aaeos-http-path-integration-spec.md]`
2. **Cross-department choreography service** (`AtlasCrossDepartmentChoreographyService` + veto watchdog + repair-loop guard): veto pausa downstream <=10s, repair max 3 iteracoes. `[src=atlas-aaeos-cross-department-choreography.md]`
3. **Department registry service** (`AtlasAaeosDepartmentRegistryService`) + enforcement do schema de 12 campos. `[src=atlas-agentic-engineering-os-department-contract.md]`
4. **Phase handoff service** (`AaeosPhaseHandoffService`) + comandos das 17 fases (P0-P16). `[src=atlas-agentic-engineering-os-runbook.md]`
5. **Mission Control Cockpit (P14 — hoje "nao existe")** — necessario para o human review do L4. `[src=atlas-mission-control-cockpit-spec.md]`
6. **Obra replay service** (`AtlasObraReplayService`, modos audit/simulation/what_if). `[src=atlas-aaeos-obra-replay-spec.md]`

**Gate da fase:** request HTTP atravessa as 17 fases com handoff e evidence; 15 features cert-verde (L3); 5 Obras cert-verde com Mission Control review (L4).

#### FASE 7 — Maturidade dos departamentos (subir cada um a L4)
**Objetivo:** todo departamento >=L4 (criterio de L5). **Rung: L5 Department Owner -> L6 Multi-Department Conductor.**

1. **Dev L1 -> L4 (o piso):** resolver **A2 Plan-Visible incompleto** + aposentar **HTTP path legado** + **parity**. Quality Bar Dev L4+: latency p95 <=45s, tests pass >=0.98, scope_violation=0, repair_loop <=0.5.
2. **Forge L4 -> L5:** merge-review promotion gate em R5; usar **owner runtime real, nao prompt simples**. Quality Bar Forge L5+: obra_completion >=0.97, cert_pass >=0.99, rollback <=0.005, multi_agent_collision=0. `[src=atlas-aaeos-loop-evolution-backlog.md:S29]`
3. **QA L2 -> L4** (>=30 contract tests, regression_catch >=0.97), **Review / Debug / Delivery / Research L2 -> L4**, **Product / Architect / Security L3 -> L4**.

**Gate da fase:** todos os 11 deptos com maturity >=L4 medido por comando; 90 dias de 1 depto autonomo sem intervencao (L5); 30 dias com 3+ deptos ativos (L6).

#### FASE 8 — Self-Construction + soberania (L7 Self-Evolving)
**Objetivo:** o Atlas propoe e implementa o proprio refator sob governanca. **Rung: L7.**

1. **Self-Construction Capability Ladder L0 -> L8** (`strategic_self_construction`). `[src=atlas-aaeos-forge-dev-leap-backlog.md:S109]`
2. **Architecture Evolution Proposal Runtime**: governanca de redesign estrutural — impede que a auto-evolucao reescreva camadas canonicas como se fosse feature comum. `[src=atlas-architecture-evolution-proposal-runtime.md]`
3. **Trust Ledger >=0.95** estavel. `[src=atlas-trust-ledger-canonical.md]`
4. **Criterio de promocao L6 -> L7:** 10 propostas de self-construction aprovadas, **0 invariante quebrada**, dupla assinatura + review humano do Architect.

**Gate da fase = chegada ao L7:** ver Evidencias.

## Regras para IA

1. **Nao confundir backlog/doc com runtime.** `doc_maturity != implementation_state`. DOC L4 nao prova execucao.
2. **Um passo so conta apos** codigo + teste + judge + evidencia + `main_before != main_after`.
3. **Nao se pula degrau** na ladder; promocao L4+ exige dupla assinatura; L6+ exige Architect humano.
4. **Demote e automatico** por metrica; nunca exige assinatura.
5. **Auto-evolucao (L7) e governada:** revert via `git revert`, nunca `reset --hard`; trabalho humano sujo bloqueia revert; redesign estrutural passa pelo Architecture Evolution Proposal Runtime.
6. Este roadmap **ordena**; os specs-fonte **mandam**.

## Escopo de Implementacao

Servicos/comandos a construir, agrupados por fase (o backlog atomico que os alimenta vive nos docs linkados):

- **Fase 0:** `AtlasAutonomyLadderRuntimeService`, `AtlasAutonomyMetricsAggregator`, `AtlasAutonomyDemoteWatchdog`; comandos `atlas:aaeos:maturity --json`, `atlas:aaeos:quality-bar --json`; slices S49, S301, S302.
- **Fase 1:** wiring de `AtlasForgeProviderInvocationService` execute mode; slices S50, S39, S55.
- **Fase 2:** AP-790 hardening, AP-805; slices S40, S261-S266, S161-S180.
- **Fase 3:** slices S56-S59, S307-S311; gate `rsi_meta_judge`.
- **Fase 4:** AARF como gate; Cognitive Immune Learning Kernel; slices S126-S158.
- **Fase 5:** wiring AEMOR judgment guard; slices S221-S231, S201-S209.
- **Fase 6:** `AtlasAaeosHttpPathFacadeService` (4 fases), `AtlasCrossDepartmentChoreographyService`, `AtlasAaeosDepartmentRegistryService`, `AaeosPhaseHandoffService`, Mission Control Cockpit, `AtlasObraReplayService`.
- **Fase 7:** correcoes Dev (A2/HTTP/parity); Forge merge-review R5; promocoes de maturidade por depto.
- **Fase 8:** Self-Construction Capability Ladder, Architecture Evolution Proposal Runtime, Trust Ledger.

## Dependencias

- `atlas-autonomy-ladder-promotion-runbook` — define os 8 niveis e criterios de promocao.
- `atlas-agentic-engineering-os` (+ `-runtime-gap-matrix`, `-runbook`, `-department-contract`) — a mae e o estado real.
- `atlas-aaeos-department-maturity-matrix` / `-quality-bar-matrix` — niveis e thresholds por depto.
- `atlas-aaeos-evolution-backlog-index` — os 149 slices loop-ready (S49-S318).
- `atlas-aaeos-loop-evolution-backlog` — a espinha do loop (S1-S82) e o DAG de execucao.
- `atlas-aaeos-http-path-integration-spec` — a migracao de 4 fases da Fase 6.

## Evidencias

### Como saber que chegamos ao L7 (checklist de chegada)

- [ ] Loop executa de verdade (Tier>=1), nao scan-only — S49/S50/S55 verdes com evidencia.
- [ ] 10 ciclos consecutivos sem crash, audit 29/29, replay deterministico.
- [ ] Flywheel composto provado: lift medido, auto-apply governado, auto-revert em regressao.
- [ ] Todos os 11 departamentos com maturity >=L4 medida por comando (nao auto-declarada).
- [ ] HTTP path nas 17 fases; Mission Control Cockpit operando o human review.
- [ ] 10 propostas de self-construction aprovadas, **0 invariante quebrada**.
- [ ] **Trust Ledger >=0.95** estavel.
- [ ] Promocao L6->L7 registrada com dupla assinatura + review humano do Architect.

Quando todos verdes **com evidencia e merge honesto**, o runtime alcancou o nivel da documentacao: **L7 Self-Evolving**.

## Riscos

- **Tratar status `ready`/DOC L4 como entrega** sem runtime real (o risco mais citado nos docs-fonte).
- **Auto-evolucao sem governanca** reescrevendo camadas canonicas — mitigado pelo Architecture Evolution Proposal Runtime e pela dupla assinatura no L7.
- **Promocao prematura** por auto-avaliacao tendenciosa — mitigado exigindo medicao por comando antes de promover.
- **Over-atomizacao** do backlog reduzindo retorno composto (trabalho pequeno demais).
- **Demote silencioso** por metrica nao instrumentada — por isso a instrumentacao e Fase 0.

## Exemplos

**Subida minima L0 -> L1 (o primeiro degrau real):** hoje o loop e scan-only. Assinar o recibo S49 (tier 0->1) + construir o ladder service + instrumentar maturidade (Fase 0) torna a promocao mecanicamente possivel. Wire S50 (provider real) e provar S55 (1 ciclo merged) (Fase 1) gera o primeiro slice autonomo verde. Apos 20 slices verdes seguidos com `scope_violation=0`, o criterio de saida de L1 e atingido e o Atlas promove L0->L1 com assinatura unica do operador.

**Caminho critico (a sequencia minima inegociavel):** `S49 (destrava) -> S50 (fixture->real) -> S55 (1o merge real) -> AP-805 (10 ciclos) -> flywheel S56-S59 (auto-melhora) -> ... -> Self-Construction + Trust>=0.95 (L7)`. Tudo o mais e aditivo e pode correr em paralelo.

## Proximas Acoes

- Executar a **Fase 0** (S49 + ladder service + comandos de maturidade) antes de qualquer rung acima de L1.
- Manter a coluna **nivel-real** desta sintese sincronizada com a `runtime-gap-matrix` e a `department-maturity-matrix` a cada ciclo.
- Atualizar o **checklist de chegada** somente com evidencia e merge honesto — nunca por auto-declaracao.
