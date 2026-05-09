---
id: atlas-ai-cognitive-pipeline-overlay
type: engineering_knowledge
title: Atlas AI Cognitive Plane - Pipeline Overlay
status: active
category: architecture
priority: 94
summary: Overlay cognitivo sobre o pipeline canonico de 17 etapas. Catalogo de flows do `learning` v2 (22 implementados/planejados), hooks por etapa, memory artifacts, Evidence Ledger events cognitivos, surfaces educacionais (CLI/App/Mobile/Voice/StackChan/Constelacao), loops temporais.
tags:
  - atlas-ai
  - cognitive
  - pipeline
  - flows
  - memory
  - ledger-events
  - surfaces
  - loops
capabilities:
  - cognitive_pipeline_overlay
  - learning_flows_v2
  - cognitive_memory_artifacts
  - cognitive_ledger_events
  - cognitive_surfaces
decisions:
  - Mesmo pipeline canonico de 17 etapas; cada etapa ganha hook cognitivo aditivo. Etapas inalteradas.
  - Domain `learning` expande de 4 flows base para catalogo versionado; AP status e fonte de verdade.
  - Knowledge Graph e projection do Memory Core, recomputavel; verdade vive em Memory Registry + Verbatim Store.
  - Curriculum e artefato persistente revisavel.
  - Surfaces canonicas (CLI/App/Mobile/Voice/StackChan/Constelacao) - sem produto paralelo.
  - 6 loops temporais empilhados (Reflex/Reaction/Deliberation/Contemplation/Heartbeat/Cycle), inspirados em Embodiment.
maintenance:
  - Manter abaixo de 260 linhas.
  - Atualizar quando flow novo entrar, ledger event mudar schema, ou surface ganhar capability cognitiva.
  - Status `active` significa overlay canonico do pipeline; flows/APs individuais podem continuar scaffold, partial ou future.
related_paths:
  - docs/engineering-knowledge-base/cognitive/implementation-briefing.md
  - docs/engineering-knowledge-base/cognitive/README.md
  - docs/engineering-knowledge-base/cognitive/overview.md
  - docs/engineering-knowledge-base/cognitive/capabilities-core.md
  - docs/engineering-knowledge-base/atlas-ai-pipeline.md
  - docs/engineering-knowledge-base/atlas-ai-flow-visual-map.md
  - docs/engineering-knowledge-base/domains/learning.md
owner: atlas-ai
layer: 2-and-3
line_limit: 260
---

# Atlas AI Cognitive Plane — Pipeline Overlay

Overlay cognitivo sobre o pipeline canonico. Mesmas 17 etapas; hooks aditivos.

## Authority

Em conflito: `atlas-ai-pipeline.md` (Layer 3, pipeline canonico) > `atlas-ai-flow-visual-map.md` > este doc > `domains/learning.md`.

## Domain `learning` v2 — catalogo de flows

AP status e fonte de verdade. Um flow listado aqui pode estar `implemented-operational-read-model`, `implemented-runtime` ou `scaffold`.

| Flow | Cadencia | Maturidade | Output |
|---|---|---|---|
| `learning.objective_design` | sob demanda | base | objetivo + criterio + horizonte |
| `learning.curriculum_design` | sob demanda | base | trilha do cume com pre-requisitos |
| `learning.predictive_curriculum` | semanal (Curator) | scaffold | propostas antecipativas |
| `learning.daily_plan` | diario | base | bloco do dia: prioridade, modo, duracao |
| `learning.deep_work` | sob demanda | base | sessao longa com gates de saida |
| `learning.micro_session` | sob demanda | base | 5-15min em vao do dia |
| `learning.active_recall` | dentro de sessao | scaffold | perguntas tipadas |
| `learning.feynman_explain` | sob demanda | scaffold | explicacao + scoring |
| `learning.case_study` | sob demanda | scaffold | caso real do dominio |
| `learning.game_session` | sob demanda | scaffold | desafio gamificado |
| `learning.spaced_review` | diario | scaffold | fila SRS (FSRS) |
| `learning.consolidation` | pos-sessao | scaffold | sintese para Knowledge Graph |
| `learning.gap_detection` | semanal (Curator) | base | lacunas vs. objetivo |
| `learning.transfer_test` | quinzenal | scaffold | aplicacao em contexto novo |
| `learning.mastery_review` | mensal | scaffold | rubrica formal |
| `learning.knowledge_graph_review` | quinzenal | scaffold | analise do grafo |
| `learning.forgetting_review` | mensal | scaffold | descontinuacao auditada |
| `learning.forge` | sob demanda + approval | scaffold | sessao multi-flow pesada com Harness |
| `learning.worked_example` | sob demanda + automatico em deep_work | AP-164 read-model | exemplo trabalhado com fading conforme dreyfus_stage; eixo operacional |
| `learning.process_optimization` | sob demanda | scaffold | flow especifico para alta performance operacional (eixo Tim Cook) |
| `learning.pattern_extraction` | semanal (Curator) | AP-165 read-model | varre ledger; propoe padroes humanos emergentes para Process Pattern Catalog |
| `learning.failure_review` | semanal | AP-166 read-model | revisa `failure_signature`; alerta repeticao; valida diversificacao |
| `learning.srl_episode` | por sessao opt-in | AP-167 read-model | forethought, performance observation e reflection |
| `learning.productive_failure` | sob demanda | AP-168 implemented_partial | erro produtivo em 3 fases: generation, comparison, integration; runtime minimo + CLI; transfer test proposal-only |

### Gates obrigatorios

`learning_objective`, `practice_loop`, `mastery_rubric` (existentes) + `cognitive_load_check`, `non_clinical_safety`, `provider_safety_redaction`, `evidence_attribution`, `transfer_proof`, `forgetting_curve_respected`, `pedagogy_matches_stage` (Dreyfus).

### Forbidden actions

Diagnosticar deficit cognitivo. Prescrever medicacao. Mutar calendario. Marcar dominado sem `transfer_proof`. Promover learning result a Core Memory sem review.

## Pipeline Cognitivo — overlay sobre 17 etapas canonicas

| # | Etapa canonica | Hook cognitivo |
|---|---|---|
| 1 | Surface Plane | comandos canonicos locais `php artisan atlas:*`; wrappers de produto `atlas ...` sao aliases futuros |
| 2 | Surface Adapter | canoniza alias |
| 3 | Atlas Input | objetivo, topico, duracao, tecnica, fonte, foto, audio |
| 4 | Operation Envelope | `input_kind=cognitive`; carrega `study_session_id` + `cognitive_load_snapshot` + `dreyfus_stage_target` |
| 5 | Intent / Routing | classifica entre estudo/revisao/explicar/propor/etc |
| 6 | Business Context | `personal` ou `professional` (estudo para projeto X) |
| 7 | Domain / Profile / Flow | `learning` + flow + specialist_profile |
| 8 | Context Builder | Currículo ativo, Mastery overlay, ultimas N sessions, Knowledge Graph local, Research, sinal de load |
| 9 | Policy / Profile | thresholds, redaction, non_clinical, plan_only, budget, dreyfus_pedagogy |
| 10 | Atlas Decide | provider/modelo por specialist_profile + AP-99 + sinal cognitivo |
| 11 | Decision Receipt v2 | inclui snapshot de load, tecnica, mastery target, engine usada, dreyfus_stage |
| 12 | Runtime / Executor | Laravel orquestra; Python roda engines (SRS/Recall/KG); Provider faz Feynman |
| 13 | Quality Gates | gates do `learning` + Core |
| 14 | Repair / Escalation | load alto -> reagendar; transfer fail -> bloco novo com probe |
| 15 | Evidence Ledger | eventos cognitivos (lista abaixo) |
| 16 | Learning / Proposals | mastery delta, gap, proximo nó - nunca auto-aplica |
| 17 | Output Renderer | resposta + cards + grafo atualizado + push + face fisica |

## Cognitive Memory Core

### Memory types novos

`learning_objective`, `mastery_profile`, `learning_artifact`, `study_session`, `cognitive_signal`, `transfer_evidence`, `forgotten_concept`, `learning_proposal`, `confidence_profile`, `dreyfus_overlay_snapshot`, **`process_pattern`** (entrada do Process Pattern Catalog: name, category, intent, problem_context, solution, personal_evidence, consequences, anti_patterns, related_patterns), **`failure_signature`** (categoria + sub-causa + contexto + similarity_to_previous), **`worked_example_personal`** (exemplo trabalhado derivado do ledger pessoal com fading_level).

### Knowledge Graph como projection

Nao e tabela primaria. E projecao recomputavel a partir de Memory Registry + Verbatim Store + ledger.

```
- nos (conceitos com embedding + tags)
- arestas (pre-requisito, contraexemplo, aplicacao, contradicao, generalizacao)
- mastery overlay (1-5 por nó)
- decay overlay (curva de esquecimento ativa)
- predictive overlay (sugeridos pelo Curator)
- pareto overlay (peso do nó na piramide do dominio)
- dreyfus overlay (nivel 1-5 derivado de evidencia)
- latticework overlay (conexoes cross-domain detectadas)
```

### Curriculum como artefato persistente

`pareto_summary` (cume_nodes, coverage, estimated_compression), `nodes`, `cadence`, `gates`, `status` (active/paused/completed/archived), `created_via` (operator/predictive_curriculum). Revisavel, nao imutavel.

## Evidence Ledger — eventos cognitivos canonicos

Eventos podem estar implementados ou planejados; AP status e fonte de verdade.

`LEARNING_OBJECTIVE_DEFINED`, `CURRICULUM_PROPOSED`, `CURRICULUM_ACCEPTED`, `CURRICULUM_REJECTED`, `STUDY_SESSION_STARTED`, `STUDY_SESSION_COMPLETED`, `STUDY_SESSION_INTERRUPTED`, `ACTIVE_RECALL_QUESTION_GENERATED`, `ACTIVE_RECALL_ANSWERED`, `SPACED_REVIEW_TRIGGERED`, `SPACED_REVIEW_PASSED`, `SPACED_REVIEW_FAILED`, `FEYNMAN_EVALUATION_SCORED`, `MASTERY_DELTA_RECORDED`, `TRANSFER_TEST_PASSED`, `TRANSFER_TEST_FAILED`, `KNOWLEDGE_GAP_DETECTED`, `KNOWLEDGE_NODE_ADDED`, `KNOWLEDGE_EDGE_ADDED`, `KNOWLEDGE_GRAPH_PROJECTED`, `COGNITIVE_LOAD_ALERT`, `COGNITIVE_LOAD_RECOVERY`, `CASE_COMPLETED`, `GAME_SESSION_RESULT`, `FORGETTING_INTENT_RECORDED`, `PREDICTIVE_CURRICULUM_PROPOSED`, `PARETO_CURVE_MAPPED`, `EXTERNAL_INPUT_FILTERED`, `RIVALS_LEARNING_RUN`, `DREYFUS_LEVEL_DELTA_RECORDED`, `MASTERY_EVIDENCE_AGGREGATED`, `LATTICEWORK_CONNECTION_DETECTED`, `DEBATE_SESSION_COMPLETED`, `DEBATE_DISCORD_DETECTED`, `SOCRATIC_SESSION_COMPLETED`, `EVIDENCE_ROUTING_PASS_COMPLETED`, `COMPRESSION_METRIC_COMPUTED`, `COMPRESSION_DEGRADATION_ALERT`, **`WORKED_EXAMPLE_DELIVERED`**, **`WORKED_EXAMPLE_FADING_PROGRESSED`**, **`PERSONAL_WORKED_EXAMPLE_EXTRACTION_STARTED`**, **`PERSONAL_WORKED_EXAMPLE_EXTRACTED`**, **`PERSONAL_WORKED_EXAMPLE_DISCARDED_QUALITY`**, **`PERSONAL_WORKED_EXAMPLE_DISCARDED_PRIVACY`**, **`PERSONAL_WORKED_EXAMPLE_DISCARDED_DUPLICATE`**, **`PERSONAL_EXTRACTION_BATCH_COMPLETED`**, **`PROCESS_PATTERN_CANDIDATE_DETECTED`**, **`PROCESS_PATTERN_CATALOGED`**, **`PROCESS_PATTERN_APPLIED`**, **`FAILURE_SIGNATURE_RECORDED`**, **`FAILURE_REPETITION_ALERT`**, **`FAILURE_DIVERSITY_INDEX_COMPUTED`**, **`SRL_OVERLAY_TOGGLED`**, **`SRL_FORETHOUGHT_RECORDED`**, **`SRL_PERFORMANCE_OBSERVATION`**, **`SRL_REFLECTION_RECORDED`**, **`PRODUCTIVE_FAILURE_PHASE_1_STARTED`**, **`PRODUCTIVE_FAILURE_PHASE_1_ATTEMPT_RECORDED`**, **`PRODUCTIVE_FAILURE_PHASE_2_STARTED`**, **`PRODUCTIVE_FAILURE_PHASE_2_COMPARISON_RECORDED`**, **`PRODUCTIVE_FAILURE_PHASE_3_STARTED`**, **`PRODUCTIVE_FAILURE_ARTICULATION_RECORDED`**, **`PRODUCTIVE_FAILURE_COMPLETED`**, **`PRODUCTIVE_FAILURE_ABANDONED`**, **`PREDICTIVE_FAILURE_INSERTED`**.

Todos `LedgerEventType` aditivos, taxonomia fechada. Replay reconstroi: trajetoria de meses, mastery por nó por dia, padrao de quando rende, regressao por area.

## Surfaces Educacionais

Canonicas. Especializadas em conteudo, sem produto paralelo.

| Surface | Comandos / Interacao tipica |
|---|---|
| CLI | Canonico local: `php artisan atlas:*`. Product aliases: `atlas study/review/explain/...` podem existir como wrappers, mas nao sao contrato de runtime. |
| App / Mobile | Daily Plan card, Inbox com Curator preditivo, Knowledge Graph view, push governado |
| Voice Realtime | Feynman por voz, recall em movimento, Atlas-Vitor Socratico verbal |
| StackChan (futuro) | tutor presencial, leitura de expressao, reflex local, continuidade afetiva, mute fisico class-3 |
| Constelacao Surface | serendipidade governada; latticework cross-domain |

## Loops Temporais Cognitivos

| Loop | Janela | O que acontece |
|---|---|---|
| Reflex | <30s | flashcard relampago em ocioso |
| Reaction | 5-15min | micro_session: 1 pergunta + 1 explicacao + 1 card |
| Deliberation | 60-120min | deep_work com Cognitive Forge se pesado |
| Contemplation | semanal | gap_detection + knowledge_graph_review + Curator preditivo |
| Heartbeat | diario | daily_plan + spaced_review + load forecast |
| Cycle | mensal | mastery_review + forgetting_review + recalibration |

## Continuidade

Schema de cada memory type, migration, indexes e SLOs concretos vivem em APs (`docs/ap/AP-###-cognitive-*.md`). Briefing operacional em `implementation-briefing.md`; roadmap em `roadmap.md`.
