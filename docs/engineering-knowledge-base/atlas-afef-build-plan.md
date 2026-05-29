---
id: atlas-afef-build-plan
type: engineering_knowledge
doc_schema: atlas_canonical_module_doc.v1
title: Atlas AFEF Build Plan (Ordered Slices AP-A..AP-E)
slug: atlas-afef-build-plan
status: future
implementation_state: future_spec_no_runtime_yet
category: agentic-engineering
priority: 94
summary: >
  Plano de build ORDENADO que decompoe o Atlas Frontier Evolution Foundry (AFEF)
  em cinco fatias seguras e reversiveis (AP-A..AP-E), cada uma com criterio de
  aceite e o invariante de armadura que satisfaz. Sequenciado EXPLICITAMENTE
  DEPOIS de #1 (anti-inercia / honest-stop endurecido) e #2 (gates provider-proof
  + no-scaffold), porque ligar geracao de backlog antes desses gates apenas
  multiplica backlog inerte. AFEF so pode emitir findings que passem nos gates
  no-scaffold (FinalDeliveryQualityGateService), provider-proof
  (Ap786OwnerFlowExecutor) e anti-inercia (AP-806 honest stop). Proposal-only;
  nao cria OS, runtime nem provider novo.
tags: [atlas-ai, software-company, frontier-evolution-foundry, build-plan, anti-inertia, provider-proof, no-scaffold]
capabilities: [afef_build_sequencing, evolution_proposal_generation_gated, measured_or_reverted_evolution]
decisions:
  - AFEF e construido SOMENTE depois de #1 (anti-inercia) e #2 (provider-proof + no-scaffold) estarem verdes; senao multiplica backlog inerte.
  - Cada AP entrega um corte vertical reversivel; AP-A nao gera nada, so prova coleta+verificacao de evidencia real.
  - Toda finding emitida por AFEF DEVE ser construida para passar nos tres gates ja vigentes (no-scaffold, provider-proof, honest-stop); finding que nao passaria e dropada na origem.
  - Ordem AP-A -> AP-B -> AP-C -> AP-D -> AP-E e dura; pular fatia e proibido.
maintenance:
  - Atualizar antes de mudar a ordem das fatias, o criterio de aceite ou o mapeamento fatia->invariante.
  - Bloquear quando IA tentar iniciar AP-B+ sem #1 e #2 verdes, ou emitir finding que nao atravessa os gates vigentes.
risk_level: high
owner: agentic_engineering_os/dev_forge
graph_id: atlas-afef-build-plan
graph_title: Atlas AFEF Build Plan
graph_world: atlas
graph_layer: module
graph_kind: module
graph_parent: atlas-frontier-evolution-foundry
graph_status: future
graph_source: repo
depends_on: [atlas-frontier-evolution-foundry, atlas-software-company-stewardship-stack, atlas-autonomy-admission]
flows_to: [atlas-frontier-evolution-foundry]
unlocks: [self_generated_evolution_backlog_gated, measured_compounding_self_improvement]
governs: [afef_build_order, evolution_proposal_acceptance_gates]
authority_class: planner
related_paths:
  - docs/engineering-knowledge-base/atlas-frontier-evolution-foundry.md
  - docs/ap/AP-806-loop-autonomy-certification-contract.md
  - app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AutonomousEvolutionSessionService.php
  - app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AreaFocusFactoryMaxCanonicalBacklogService.php
  - app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AreaFocusSelfConstructionAdmissionBridgeService.php
repo_paths:
  - docs/engineering-knowledge-base/atlas-afef-build-plan.md
evidence:
  - docs/engineering-knowledge-base/atlas-frontier-evolution-foundry.md
  - app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AutonomousEvolutionSessionService.php
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
next_actions:
  - Confirmar #1 (anti-inercia) e #2 (provider-proof + no-scaffold) verdes antes de iniciar AP-A.
allowed_changes:
  - Refinar criterio de aceite, mapeamento fatia->invariante e dependencias mantendo a ordem e o proposal-only.
forbidden_changes:
  - start_afef_generation_before_anti_inertia_and_provider_proof_green
  - emit_finding_that_fails_no_scaffold_or_provider_proof_gate
  - reorder_or_skip_slices
  - auto_canonize_proposal
requires_evidence: false
line_limit: 520
schema:
  - atlas.foundry.evolution_proposal.v1
  - atlas.foundry.proposal_verdict.v1
  - atlas.foundry.evolution_outcome.v1
  - atlas.foundry.roadmap.v1
---

# Atlas AFEF Build Plan (Ordered Slices AP-A..AP-E)

## Resumo

Este doc NAO redefine o AFEF; ele ORDENA a construcao do AFEF
(`atlas-frontier-evolution-foundry.md`) em cinco fatias verticais, reversiveis e
independentes — AP-A ate AP-E — cada uma com criterio de aceite e o invariante de
armadura (I1..I9) que ela satisfaz.

A condicao de entrada e dura e vem antes de qualquer linha de AFEF: **AFEF so
comeca depois de #1 e #2 estarem verdes.** #1 = endurecimento anti-inercia
(honest stop do AP-806 nao fabrica filler quando o backlog esgota). #2 = gates
provider-proof (`Ap786OwnerFlowExecutor`, sem merge de scaffold sem provider) +
no-scaffold (`FinalDeliveryQualityGateService` bloqueia "Step N of M"/mock/TODO).
Ligar geracao de backlog antes desses dois gates so produz um efeito: **multiplica
backlog inerte** — propostas bonitas que nunca atravessam a entrega real. Por isso
a sequencia e #1 -> #2 -> AFEF, nunca paralela.

> Regra de entrada inviolavel: nenhuma fatia AP-B+ (qualquer geracao) inicia
> enquanto #1 e #2 nao estiverem verdes e provados em run real.

## Papel no Atlas

Este plano e o contrato de ordem que impede o AFEF de virar uma fabrica de docs
bonitas. Ele garante que cada incremento de capacidade de geracao so e ligado
quando a malha de gates que separa "proposta" de "entrega provada" ja existe e ja
e enforced. AFEF e o ultimo elo da escada de stewardship; este doc define em que
ordem esse elo e soldado, sem nunca remover os fusiveis abaixo dele.

## Onde Se Encaixa

O ponto de entrada continua sendo `backlog_exhausted` em
`AutonomousEvolutionSessionService` (ramo do honest stop por volta de
`AutonomousEvolutionSessionService.php:1280-1292`, `blockedCycle(... ['backlog_exhausted'] ...)`).
AFEF intercepta esse ponto ATRAS de flag default-off, e so depois de #1 garantir
que o honest stop ja e o comportamento correto e medido.

Dependencia explicita (anti-inercia):

```
#1 anti-inertia honest stop (AP-806) VERDE
        |
        v
#2 provider-proof (Ap786OwnerFlowExecutor) + no-scaffold (FinalDeliveryQualityGateService) VERDE
        |
        v
AP-A -> AP-B -> AP-C -> AP-D -> AP-E   (AFEF, ordem dura)
```

Se #1 e #2 nao estao verdes, este plano para em "blocked: prerequisites_not_green"
e nao escreve uma linha de AP-A.

## Contratos

Os schemas sao os do doc-mae (`atlas.foundry.evolution_proposal.v1`,
`atlas.foundry.proposal_verdict.v1`, `atlas.foundry.evolution_outcome.v1`,
`atlas.foundry.roadmap.v1`). Este plano adiciona UM contrato de ordem, nao um
schema novo:

- **gate_compat_contract** (regra de codigo, nao schema persistido) — toda
  `evolution_proposal.v1` emitida deve declarar, em `proposed_packets[]`, apenas
  pacotes cujos `allowed_files[]` + `required_tests[]` permitam ao executor
  produzir um diff que passe no `FinalDeliveryQualityGateService` (no-scaffold) e
  cuja owner-flow seja `atlas_dev` senior-loop real (provider-proof). Pacote que
  so poderia virar "Step N of M" e rejeitado na decomposicao (I7), nao depois.

## Fluxo

A ordem de build e um funil de capacidade crescente; cada fatia adiciona poder so
depois que a fatia anterior provou seguranca.

1. **AP-A** coleta+verifica evidencia (zero geracao).
2. **AP-B** liga o gate de exaustao/raridade (decide QUANDO, ainda sem gerar).
3. **AP-C** liga geracao + armadura adversarial (gera, mas proposal-only no inbox).
4. **AP-D** liga promocao do operador -> backlog compiler (proposta vira packets gated).
5. **AP-E** liga medido-ou-revertido + roadmap vivo (fecha o loop de prova).

Cada fatia e mergeavel sozinha e deixa o sistema em estado honesto se as
seguintes nunca vierem.

## Regras para IA

- Nao iniciar AP-B+ sem #1 e #2 verdes. Geracao antes dos gates = backlog inerte.
- Toda finding emitida por AFEF e construida para PASSAR nos gates ja vigentes; se
  uma proposta so produziria scaffold/Step-N-of-M, ela e dropada na decomposicao
  (I7), com motivo registrado (evidencia negativa).
- Owner de toda packet emitida e `atlas_dev` senior-loop (real merge); `forge`
  permanece plan-only (`owner_flow_forge_planned`) sem Obra+topology+decision+AWIS.
- Ordem AP-A..AP-E e dura; proibido reordenar ou pular.
- Nada auto-canoniza (I4); juiz roda em modelo != gerador (I3).

## Escopo de Implementacao

Sequencia ordenada. Cada fatia: objetivo, aceite, invariante satisfeito.

### AP-A — Evidence Harvester + Verifier + schemas `foundry.*` (sem geracao)
- **Objetivo:** materializar os quatro schemas `foundry.*` e provar que da para
  COLETAR (Harvester, barato) e RE-VERIFICAR (Verifier, deterministico) evidencia
  real do Evidence Ledger / commits / blocker counts — sem nenhuma geracao.
- **Aceite:** Harvester produz dossie a partir de cycle_ids/commits/blockers reais;
  Verifier rejeita ancora inexistente (cycle_id falso, commit ausente, repro que
  nao reproduz) com motivo; testes cobrem ancora valida e ancora falsa.
- **Invariante satisfeito:** **I1 Evidence-Bound** (parcial: o lado de verificacao).

### AP-B — Exhaustion & Rarity Gate (decide QUANDO)
- **Objetivo:** interceptar `backlog_exhausted` em `AutonomousEvolutionSessionService`
  atras de flag default-off; disparar Frontier Mode SOMENTE em exaustao medida (0
  packets admissiveis por N ciclos + metricas estaveis) com teto de gasto premium
  por janela. Ainda NAO gera nada — so decide elegibilidade.
- **Aceite:** com backlog disponivel, gate retorna `not_eligible`; com 0 admissiveis
  por N ciclos + budget ok, retorna `eligible` (e ainda assim o honest stop de #1
  permanece o fallback se a flag estiver off); estouro de budget retorna `blocked`.
- **Invariante satisfeito:** **I8 Budget & Rarity Gate**.

### AP-C — Frontier Generator + Armor Pipeline (gera, proposal-only)
- **Objetivo:** Generator (premium) recebe SO o dossie do Harvester e emite N
  propostas multi-horizonte; pipeline adversarial roda Verifier (I1), Dedup/Prior-Art
  (I6), Judge Panel modelo != gerador (I3), Decomposer 3-12 packets bounded (I7),
  Drift Mapper para propriedade canonica medida (I9). Sobreviventes vao para o inbox.
  Nada auto-canoniza. Decomposer aplica o gate_compat_contract: packet que so viraria
  scaffold = drop.
- **Aceite:** proposta sem ancora citada = auto-reject (I1); duplicata vs codigo/docs/
  propostas passadas = reject (I6); juiz em modelo != gerador, default refutar, exige
  maioria (I3); proposta nao decomponivel em 3-12 packets bounded = `needs_operator_spec`
  (I7); proposta que nao mapeia propriedade canonica = reject (I9); todo drop registra
  motivo; ZERO escrita em producao ou doc canonico.
- **Invariante satisfeito:** **I1 (geracao), I3, I6, I7, I9** + I2 (proposta declara
  metrica falsificavel + rollback) verificado na entrada do inbox.

### AP-D — Operator Promotion -> Backlog Compiler (proposta vira packets gated)
- **Objetivo:** operador aceita/rejeita/defere proposta com receipt
  (`AreaFocusOperatorDecisionService`); aceite NAO executa — gera finding canonica
  via `AreaFocusFactoryMaxCanonicalBacklogService` + admission bridge, e os packets
  herdam owner=`atlas_dev` senior-loop e passam pelo no-scaffold/provider-proof como
  qualquer outro finding.
- **Aceite:** sem receipt humano, proposta nunca vira finding (I4); finding gerada
  atravessa o admission bridge e os gates #2 igual a qualquer outra; packet que falha
  no-scaffold/provider-proof e bloqueado com o mesmo motivo canonico, sem excecao
  para origem AFEF.
- **Invariante satisfeito:** **I4 No Self-Canonization**.

### AP-E — Measured-or-Reverted + Roadmap.v1 (fecha o loop de prova)
- **Objetivo:** apos merge da finding implementada, medir a `success_metric` (I2)
  via `measure_cmd`; se melhorou na tolerancia -> `evolution_outcome.v1 action=consolidate`
  e roadmap marca `proven`; se nao -> git revert automatico + proposta vira
  `refuted_by_reality`. Roadmap.v1 vivo e versionado.
- **Aceite:** outcome `consolidate` so existe quando `measure_cmd` real provou melhoria
  na propriedade canonica; nao-melhoria dispara git revert e marca `refuted_by_reality`;
  roadmap distingue `implemented` (mergeado) de `proven` (medido).
- **Invariante satisfeito:** **I5 Measured-or-Reverted** (+ I9 na medicao).

Fronteira dura: nenhuma fatia escreve producao ou canonico fora do caminho
operador->compiler->gates. AP-A..AP-C sao proposal/evidence-only.

## Dependencias

- **Pre-requisitos de ordem (nao do AFEF):** #1 anti-inercia (honest stop AP-806) +
  #2 provider-proof (`Ap786OwnerFlowExecutor`) + no-scaffold
  (`FinalDeliveryQualityGateService`) VERDES antes de AP-A.
- Entry de exaustao = `backlog_exhausted` em `AutonomousEvolutionSessionService.php:1280`.
- Compiler = `AreaFocusFactoryMaxCanonicalBacklogService` +
  `AreaFocusSelfConstructionAdmissionBridgeService` + `FindingSlicePlannerService`.
- Promocao (I4) = `AreaFocusOperatorDecisionService`.
- Evidencia = Evidence Ledger + receipts do loop.
- Novo de verdade: Frontier Generator (AP-C), Judge Panel (AP-C) e os schemas
  `foundry.*`. O resto e wiring sobre servicos existentes.

## Evidencias

A prova de que o build esta correto NAO e "AP-C gera propostas"; e:

- AP-A: Verifier rejeitando ancora falsa em teste real do ledger.
- AP-B: gate retornando `eligible` so em exaustao medida, com #1 honest stop intacto
  quando a flag esta off.
- AP-C: todo drop do pipeline com motivo registrado; zero escrita canonica.
- AP-D: packet de origem AFEF bloqueado pelo no-scaffold/provider-proof com o MESMO
  motivo de qualquer finding (sem excecao).
- AP-E: `evolution_outcome.v1 action=consolidate` so com `measure_cmd` real verde;
  roadmap com capacidade em `proven` (medida), nao `implemented`.

## Riscos

- **Multiplicar backlog inerte (risco central deste plano):** ligar AP-B+ antes de
  #1+#2 enche o inbox de propostas que nunca atravessam a entrega. Mitigado pela
  condicao de entrada dura e pelo gate_compat_contract em I7.
- **Excecao de origem:** tentar dar a findings AFEF um caminho "mais facil" pelos
  gates. Proibido — AP-D exige que findings AFEF passem pelos MESMOS gates #2.
- **Auto-ilusao macro:** docs bonitas em vez de capacidade. Mitigado por AP-A (I1),
  AP-C (I2/I3) e AP-E (I5).
- **Reordenar/pular fatia:** quebra a propriedade "estado honesto se o resto nunca
  vier". Proibido por `forbidden_changes`.
- **Escritor externo concorrente** no worktree base recria `base_worktree_dirty` e
  trava merges durante geracao/implementacao; base dedicada ao loop nessas janelas.

## Exemplos

Sequencia de um build honesto:

1. Operador confirma #1 (honest stop AP-806) e #2 (provider-proof + no-scaffold)
   verdes em run real. So entao AP-A inicia.
2. AP-A entrega Harvester+Verifier+schemas; teste prova rejeicao de cycle_id falso.
3. AP-B liga o gate atras de flag off; em backlog cheio retorna `not_eligible`.
4. AP-C, com flag on em exaustao medida, gera proposta "Unify review-lock with
   quarantine retry window"; Decomposer recusa qualquer packet que so viraria
   scaffold; sobrevivente vai ao inbox.
5. AP-D: operador aceita com receipt; finding canonica entra no admission bridge e
   passa pelo no-scaffold/provider-proof como qualquer outra.
6. AP-E: apos merge via owner=atlas_dev senior-loop, `measure_cmd` mede a propriedade;
   melhorou -> roadmap `proven`; nao melhorou -> git revert + `refuted_by_reality`.

## Proximas Acoes

- Confirmar #1 e #2 verdes em run real ANTES de qualquer AP-A; documentar a prova.
- Entregar AP-A (schemas + Harvester + Verifier) com evidencia real do ledger, sem
  geracao.
- So depois habilitar AP-B (gate, flag off) e AP-C (geracao proposal-only).
- Manter este doc como contrato de ordem; nao reordenar nem pular fatias.
- Sucesso so quando, em run longo apos esgotar o backlog humano, toda evolucao
  retida tem outcome `proven` medido e zero finding AFEF burlou os gates #2.
