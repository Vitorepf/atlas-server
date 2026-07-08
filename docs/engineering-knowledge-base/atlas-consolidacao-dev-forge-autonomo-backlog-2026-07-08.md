---
title: "Atlas — Backlog de Consolidação Dev · Forge · Autônomo (substrato compartilhado)"
date: 2026-07-08
status: active
priority: 95
scope: "Onde Dev, Forge e Autônomo/ACDE fazem A MESMA coisa de 3 formas diferentes — e qual contrato único devem compartilhar. Ranqueado por VALOR REAL (muda comportamento/endurece), não por linhas removidas."
method: "Rastreamento ponta-a-ponta no código (arquivo:linha). Código vence doc. Verificado por 3 investigações independentes em 2026-07-08."
supersedes_note: "Complementa atlas-fluxos-acos-dev-forge-autonomo-2026-07-07.md (mapa de fluxos). Este doc é o BACKLOG acionável de unificação."
---

# Backlog de Consolidação — Dev · Forge · Autônomo

## Tese (o que justifica cada item)

Os três subsistemas **são a mesma fábrica de engenharia de software**. Diferem em **duas** dimensões apenas:

1. **Propósito** — Dev: engenharia de elite quase-autônoma (operador dono da intenção); Forge: fábrica de obra grande (operador comissiona); Autônomo: vida própria 24/7 (operador ausente).
2. **Presença do operador** — presente-opcional → comissiona → ausente.

**Tudo o mais deve ser IGUAL** — e o código já reconhece isso: o invariante `bar(dev)=bar(forge)=bar(autonomos)` (`AtlasLoopAutoMergeService.php:617-618`) diz que o `TrustLevel` troca só a *testemunha*, não a *régua*. Onde os três têm **cada um o seu** de algo que deveria ser único, há: (a) risco de drift (a régua diverge sem ninguém ver), (b) evolução 3× mais lenta (corrige-se o mesmo bug em 3 lugares), (c) código e complexidade a mais.

## Filtro anti-Goodhart (OBRIGATÓRIO antes de tocar qualquer item)

O doc canônico `atlas-orchestrator-canon.md:175` avisa: rename/unificação-por-unificação rende **"~zero de capacidade"**. Portanto cada item abaixo é classificado:

- **🔴 ENDURECE** — colapsar a duplicação **muda comportamento**: fecha um buraco, remove drift real entre cópias que já divergiram, ou dá a um subsistema uma proteção que ele não tinha. **Vale fazer.**
- **🟡 DEDUP** — as cópias fazem o mesmo hoje; colapsar só reduz linhas. **Baixo valor isolado** — só fazer como higiene acoplada a um item 🔴, nunca como fim.
- **⚫ NÃO TOCAR** — existe decisão provada de manter separado (ex.: forks de provider com veredito keep-separate).

## Substrato que os três DEVEM compartilhar (os 5 órgãos do Orchestrator canon)

| # | Órgão | Classe canônica | Compartilhado hoje? |
|---|---|---|---|
| 1 | Hyperflow (routing) | `AtlasAiRouterService` | parcial |
| 2 | Decision Core | `AtlasDecideService` | sim |
| 3 | Workcell Fabric (AAWR) | `AtlasAgenticWorkcellRuntimeService` | **sim** (os três dependem dele) |
| 4 | **Proof Loop** | `OutcomeProofGate` + `FalseClaimInvariant` (`EngineeringKernel/`) | **Dev+Forge+Product sim; Autônomo NÃO** ← gap |
| 5 | Learning Loop | `OutcomeMemory` + `AtlasDecideMetaLearningService` | Dev/Forge/Product; loop tem trilha própria |

Estado do programa existente (não recomeçar do zero — ESTENDER):
- **Goal 1** (implícito): canon dos 5 órgãos + Proof endurecido no Dev.
- **Goal 2**: `OutcomeProofGate` cross-surface (Dev→Forge→Product) + Learning Loop só pesa `proven_real`.
- **Goal 3**: rename Hermes→Workcell (contrato `WorkcellAdapter`, provider-neutro). SLICE 1 feito; SLICE 2 (aposentar aliases `mesh.*`) pendente.

---

## O BACKLOG (ranqueado por valor)

### C1 — 🔴 `OutcomeProofGate` no Autônomo/ACDE  ·  **RECOMENDADO #1**
- **Duplicação/gap:** o Proof Loop (órgão #4) é o gate compartilhado que separa `proven_real` de `fake_green`. Está wirado em Dev (`DevOutcomeMemoryService.php:20,38,57-58`), Forge (`ForgeOutcomeMemoryService.php:17,34,55-56`) e Product (`AtlasProductDeliveryOutcomeMemoryService.php:18,34`). **Zero call-sites em `app/Services/Ai/AutonomousEvolution/`.**
- **Pior ainda — semântica de falha invertida:** o ledger de evidência do Dev é **fail-closed** (`ProgrammingEvidenceLedger.php:42,89-93` lança `RuntimeException`), mas os ledgers do loop são **fail-open**: `AtlasLoopGoodhartReceiptLedger.php:41-43` dropa a linha em silêncio (documentado como "best-effort by design" `:20-22`) e `AtlasLoopProjectionOutcomeLedger.php:58-60` engole toda exceção (`catch (Throwable) {}`).
- **Por que ENDURECE:** o sistema que roda **24/7 sem humano** é hoje o **mais fraco em prova** — exatamente o inverso do telos. Wirar o gate fecha um buraco real de fake-green (muda comportamento), não é rename.
- **Contrato único:** o mesmo `OutcomeProofGate::assess()` do `EngineeringKernel`, agindo no `fake_green` (suprime promoção / marca review) como Dev/Forge já fazem.
- **Alinhamento:** é literalmente a superfície que faltou no Goal 2 (Proof cross-surface). Estende o programa, não conflita.
- **Risco:** MÉDIO — precisa achar o write-path de outcome→learning do loop e agir no veredito no lugar certo. Investigação focada antes de codar.

### C2 — 🔴 Invariante "no silent green" unificado no `FalseClaimInvariant`
- **Duplicação:** três cópias mecanicamente diferentes do mesmo princípio ("passou" não coexiste com "dúvida"):
  - Dev: `CompletionDecision.php:33-36` **e** `CompletionSummary.php:55` (duplo-throw, duas classes, mesma regra).
  - Autônomo: `AtlasEngineeringHonestyGate.php:44` (holdout próprio) + inline `WorkspaceProviderLoopExecutionDriver.php:161` (`green = passed && !rejected`) + `PlanDrivenLoopRunnerService.php:438`.
  - Forge: família `no_silent_*` em `AtlasForgeContinuumCertificationService.php:53,63,205,325,349` + `ForgeWorkPacketExecutionCycleService.php:299`.
- **Por que ENDURECE:** as três já **divergiram** (regras diferentes para o mesmo invariante) → drift real. O `FalseClaimInvariant` já existe compartilhado no `EngineeringKernel` (usado pelo `OutcomeProofGate`); rotear as três por ele elimina o drift.
- **Contrato único:** `EngineeringKernel/FalseClaimInvariant` como fonte única da regra; cada superfície chama, ninguém reimplementa.
- **Risco:** ALTO — toca o gate de aceitação de três sistemas. Fatiar com muito cuidado, uma superfície por commit.

### C3 — 🟡/🔴 `EscalationPacket`: 4 builders → 1 factory
- **Duplicação:** schema unificado (`EscalationPacket.php:41-43`), mas **4 construtores** independentes, todos vivos: `DevToForgeEscalationPacketFactory.php:78`, `DevToForgePromotionService.php:270` (inline), `AtlasForgeHandoffAdapter.php:167,206` (inline), `DevRepairLoopService.php:460` (inline). Um só consumidor (`ForgeIntakeService.php:86`).
- **Por que parte é 🔴:** cada builder tem seu **próprio mapeamento trigger/mode** (`:307-344`, `:509-534`, etc.) — se divergirem, o Forge recebe intake inconsistente. Colapsar o *mapeamento* nesse ponto é 🔴; colapsar o boilerplate de montagem é 🟡.
- **Nota:** `ProgrammingRuntimeReadinessService.php:616` **reconhece e mantém** o dual-emit de propósito — confirmar que colapsar não quebra um contrato intencional antes de agir.
- **Risco:** MÉDIO.

### C4 — 🔴 `route_decision.v1`: dois formatos com o MESMO schema id
- **Duplicação:** mesmo `atlas.dual_core.route_decision.v1` em duas formas incompatíveis: Shape A (`CanonicalRouteDecisionEnvelope.php:36-44`, chave `schema_version`, 4 campos, ~40 controllers, stubs) vs Shape B/B′ (`AtlasDualCoreEngineeringSystemService.php:211-227` e `AiDualCoreRouteDecision.php:88-118`, chave `schema`, 13–27 campos). Um consumidor lendo `schema_version` não lê nada do produtor de engenharia, e vice-versa.
- **Por que ENDURECE:** mesmo id com formas incompatíveis é uma armadilha de contrato silenciosa. Ou A vira subset real de B, ou A para de reivindicar o mesmo id.
- **Risco:** MÉDIO — ~40 call-sites de A, mas todos são stubs "to be wired".

### C5 — ⚫/🟡 Stacks de provider-invocation (NÃO TOCAR sem prova)
- **Estado:** `AiProviderManager` (central) + Forge CLI router (`AtlasForgeProviderInvocationDriverRouter`) + fork Claude-only do Dev (`SonnetClaudeCliAdapter.php:26`). O loop **já reusa** o router do Forge (`WorkspaceProviderLoopExecutionDriver.php:31-34,57,80` — "does NOT fork a new execution stack"). Hermes já une (a)+(b).
- **Veredito:** o fork do Dev para Claude e a separação codex/gemini/cursor têm **decisão keep-separate provada** (comentário `PipelineRunExecutor.php:168`). **⚫ Não colapsar.**
- **🟡 Higiene legítima:** as ~100 classes órfãs `SelfConstruction/Agent*RealInvoker*` (dead-wired, todas retornam capability=`false`) são o over-scaffolding que o próprio `CLAUDE.md` cataloga como patologia. Remoção é redução pura — item de higiene separado, baixa prioridade.

### C6 — 🟡 Goal 3 SLICE 2 (aposentar aliases `mesh.*`)
- Config `mesh.*` (`config/atlas.php:1651-1672`) + `HermesExecutiveMeshService` compat + leitura ainda no alias em `HermesWorkcellAdapter.php:274`. Fechar o rename já começado. Baixo valor isolado (dedup), mas fecha um item já em curso.

---

## Sequência recomendada

1. **C1** (Proof no Autônomo) — maior valor, estende Goal 2, fecha o gap de telos. Começar aqui.
2. **C2** (no-silent-green no `FalseClaimInvariant`) — endurece os três, mas alto risco; fatiar por superfície.
3. **C4** (route_decision.v1) — remove armadilha de contrato.
4. **C3** (EscalationPacket) — colapsar mapeamento trigger/mode.
5. **C6 / higiene C5** — fechar rename + remover órfãos, como faxina acoplada.

**Disciplina em todos:** commit-por-fatia reversível, teste que prova a mudança de comportamento, uma superfície por vez. Nunca one-shot.
