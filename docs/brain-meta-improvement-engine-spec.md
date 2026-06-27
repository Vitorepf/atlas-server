# Build Spec — Motor de Meta-Melhoramento do Cérebro

> Aterrado (26/06/2026) no código real após comprehender o suite `app/Services/Ai/AutonomousEvolution/Pattern/`.
> Catálogo de métodos: [`brain-self-improvement-method-catalog.md`](brain-self-improvement-method-catalog.md). Arquitetura: memória [[brain-meta-improvement-engine]]. Fontes de pesquisa: [`brain-research-source-registry.md`](brain-research-source-registry.md).

## 0. A DESCOBERTA que define o spec (ponytail)

A espinha do motor **já existe ~85% construída** como o suite `Pattern/` ("LOOP-PATTERN-REGISTRY Slice 1 + A0"). É um sistema de portfólio governado, puro, anti-Goodhart, fail-closed. **Não construímos a espinha — reusamos.** O que falta é: seed dos PATHS, o upgrade CAUSAL do ledger, o stream de reflexão semântica, o originador brain-as-author, e o split de altitude do prompt.

### Mapa de reuso (conceito do operador → organ que JÁ existe)

| Conceito (motor) | Organ existente | Estado |
|---|---|---|
| **PathRegistry** (paths como dado) | `AtlasLoopPatternRegistry` | pronto — read-model puro, lanes de governança, keyed `id@version` |
| **PathSpec** (schema de um path) | `AtlasLoopPatternSpec` | pronto — VO fail-closed: provenance, success_gates, terminal_states, sandbox deny-by-default, lane-policy (self_approval:false) |
| **MetaSelector** (path de maior alavanca / estado) | `AtlasLoopPatternSelector` | pronto — rankeia `selectable()` por verificação+impacto+evidência−risco−custo; **refusa cosmético como HARD GATE** |
| **Gate de admissão de path** (author≠judge) | `AtlasLoopPatternChampionGate` | pronto — promove só challenger que bate champion em eval FRESCO, sem self-approval, sem regressão de guardrail |
| **Harvest da pesquisa** (frontier-harvest path) | `AtlasLoopPatternSourceIntake` | pronto — quarentena: material externo nasce un-selectable (source_material/candidate); promoção é do ChampionGate. **É exatamente a disciplina de harvest que especificamos** |
| **MetaLearningLedger** (`{path→resultado}`) | `AtlasLoopPatternLearningLedger` | parcial — append-only outcome {pattern, objective_class, result, gates, measured_outcome}; `stats()` dá success_rate/mean. **CORRELACIONAL — falta a camada causal** |
| **Decider pré-originação** (veta proxy antes de originar) | `AtlasLoopPatternDecisionDriver` | pronto — escolhe o 1º candidato que o selector aceita, veta cosmético com receipt |

### A distinção de altitude (por que reusa, não duplica)

Os **paths** do operador são ESTRATÉGIAS de *como o cérebro decide o próximo auto-melhoramento*; os patterns atuais são ESTRUTURAS de *como uma unidade de trabalho é executada*. O motor senta ACIMA: decide QUAL melhoria + QUAL path → o pattern registry estrutura a execução. Mas a **maquinária é genérica** (Spec/Selector/ChampionGate/Ledger/Intake/Driver) — serve paths idêntica. Decisão: **uma só maquinária, os paths são um SEED SET novo** (entries com `intent='self_improvement'` / objective_kind próprio). Nada de registry paralelo.

## 1. Espinha — reuso + delta

1. **PathRegistry** = `AtlasLoopPatternRegistry`. **Delta:** seedar os 7 paths ([[brain-meta-improvement-engine]]: frontier-harvest, metrics-optimization, pattern-design, simulation/gêmeos, comprehension-deepening, adversarial-critique, compounding) como `AtlasLoopPatternSpec` entries, cada um apontando pro organ que o executa (gêmeos→`Twin/`, harvest→`SourceIntake`+research-registry, etc.). Zero código novo de registry.
2. **MetaSelector** = `AtlasLoopPatternSelector`. **Delta:** trocar o ranking de `mean_outcome`/`success_rate` (correlacional) por **efeito CAUSAL** do ledger (keystone #4). O hard-gate anti-cosmético fica.
3. **MetaLearningLedger** = `AtlasLoopPatternLearningLedger` + **camada causal nova** (keystone #4). O ledger já grava a tupla `(pattern_id [ação], objective_class [contexto], result/measured_outcome [resultado])` = exatamente o que inferência causal precisa.

## 2. Os 4 keystones → organs (reuso marcado)

**Keystone #1 — Cérebro como AUTHOR (Self-Questioning + Challenger/Solver).** Organ NOVO: originador que, em escopo verde/ocioso, sintetiza o próprio task-set da comprehension; reward = borda-da-capacidade (anti-Goodhart por construção). **Reusa** `AtlasLoopComprehensionOriginator` + `AtlasLoopFrontierGapModel` + `AtlasLoopPatternDecisionDriver` (veto de proxy) + o piso abstain.
- *Joiner security-as-origination:* nova SOURCE de material — threat-tree/fuzz a superfície do Atlas → emite teste que FALHA = material unfakeável. Registra como path (`source=atlas_native`). **Caveat pétreo:** self-play só com o piso de árbitro.
- *Joiner formal-spec adequacy:* upgrade do `AtlasTaskPacketQualityInspector` de acceptance-PRESENÇA → ADEQUAÇÃO (mutation-mata-mutante + Daikon invariant-baseline + golden-master pra packets refactor/dedup/split — casa os commits desta branch).

**Keystone #2 — Memória que evolui (Reflexion stream). Organ NOVO (sibling do `AtlasLoopLearningAppendService`).** O AppendService hoje grava só HASHES + `terminal_reason` (contrato no-scalar anti-Goodhart). O novo stream grava **post-mortem SEMÂNTICO keyed por escopo** (texto + recall por recência/relevância/importância), **preservando o no-scalar** (reflexão é FATO/texto, nunca um score de aprendizado). Curadoria delta-op (ACE, anti context-collapse) + janelas bi-temporais. Injetado na fase de comprehension.

**Keystone #3 — Context-engineering. Edit barato:** split de altitude no `AtlasBrainWorkerPromptCommand::originatePrompt` — bloco de constraint DURO (piso pétreo) separado do bloco de AMBIÇÃO (heurístico). + disciplina minimal-signal-token nos context packs. É a inversão 70/20 já apontada em [[brain-design-dominance-70-20]].

**Keystone #4 — Causal credit + invariance. Organ NOVO (serviço puro sobre o ledger):**
- **DML-as-gate** — CI no efeito de cada path; o ChampionGate só promove / o selector só prioriza quando o CI exclui zero (o "sem sinal de compounding fabricado" tornado rigoroso).
- **CCA / hindsight credit** — atribui o outcome ao PASSO causal, não ao vizinho temporal.
- **IRM / invariância** — mantém só paths cujo efeito é invariante entre `objective_class`/escopos → compounding GENERALIZA.
- **HTE / uplift** — efeito por-contexto τ(x); prioriza path só onde τ>0.

## 3. O que é GENUINAMENTE novo (a lista curta de build)

1. **Camada causal** sobre `AtlasLoopPatternLearningLedger` (DML+CCA+IRM+HTE) — serviço puro novo.
2. **Stream de reflexão semântica** keyed por escopo (sibling do AppendService, no-scalar preservado).
3. **Originador brain-as-author** (Self-Questioning/Challenger) — reusa ComprehensionOriginator+FrontierGapModel.
4. **Source security-as-origination** — threat-tree/fuzz → testes falhos como material.
5. **Seed dos 7 paths** como `AtlasLoopPatternSpec` entries (dado, não código).
6. **Split de altitude do prompt** (edit) + **adequacy upgrade do PacketQualityInspector** (edit).

Todo o resto (Registry/Selector/ChampionGate/Intake/Driver/Twin) é **reuso + wiring**.

## 4. Backlog keystone-first (ordem + reusa-vs-constrói)

| # | Slice | Reusa | Constrói |
|---|---|---|---|
| 0 (LEAD/pétreo) | Seed dos 7 paths no registry + config + adicionar organs-de-decisão novos ao FORBIDDEN_SELF_TARGETS | Registry/Spec | seed entries |
| 1 | **Keystone #2** reflexão semântica (substrato) | AppendService (padrão) | stream novo |
| 2 | **Keystone #4** camada causal (DML-gate sobre o LearningLedger) | LearningLedger | serviço causal |
| 3 | MetaSelector causal — wira #2/#4 no `AtlasLoopPatternSelector` | Selector/ChampionGate | ranking causal |
| 4 | **Keystone #1** originador brain-as-author + source security-as-origination | ComprehensionOriginator/FrontierGapModel/DecisionDriver | originador + source |
| 5 | **Keystone #3** split de altitude do prompt (barato, qualquer hora) | — | edit prompt |
| 6 | Joiner adequacy: PacketQualityInspector presença→adequação | PacketQualityInspector | mutation+Daikon+golden-master |

## 5. Piso / segurança (não-negociável)

- **Pétreo:** os organs de DECISÃO/PERCEPÇÃO novos (camada causal, reflexão stream, MetaSelector wiring) entram no `AtlasLoopHarnessGuard::FORBIDDEN_SELF_TARGETS` na slice 0 — o cérebro não pode editar o próprio selecionador/juiz/memória. (Registry/Selector/ChampionGate já são pétreo-elegíveis.)
- **Flag-gated default OFF**, OFF = byte-identical (padrão de todo organ do loop).
- **author≠judge** já é estrutural: ChampionGate proíbe self-approval; Selector é puro; o gate de admissão de path é o ChampionGate com eval fresco.
- **no-scalar preservado:** a reflexão guarda fato/texto, nunca um score; a camada causal emite efeito+CI como EVIDÊNCIA pro gate, não um ranking auto-aplicado.
- **self-play refereed:** o red/blue de security-as-origination só roda com o piso pétreo de árbitro — senão vira proxy-farm (caveat honesto do crítico).
- **Não rodar:** implement-and-prove com doubles; sem soak/live até o operador mandar ([[loop-implement-only-defer-running]]).
