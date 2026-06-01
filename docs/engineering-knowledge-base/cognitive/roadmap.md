---
id: atlas-ai-cognitive-roadmap
type: engineering_knowledge
title: Atlas AI Cognitive Plane - Roadmap
status: active
category: roadmap
priority: 92
summary: Roadmap unificado do Cognitive Plane combinando 14 fases do Cognitive Development Plane (C0-C13) e 6 fases do Multiplier Edge. Dreyfus first como regra de ouro. Cada fase entrega valor isolado com codigo+teste+doc+evidence+architecture-validate.
tags:
  - atlas-ai
  - cognitive
  - roadmap
  - phases
  - dreyfus-first
capabilities:
  - cognitive_roadmap
  - phased_delivery_plan
decisions:
  - Roadmap dominante governa execucao; lista de capabilities e mapa de design.
  - Dreyfus first (Fase 1 do Multiplier Edge) sai antes de qualquer outra discussao do Territorio 2.
  - Cada fase entrega valor isolado com codigo + teste + doc + Evidence + architecture-validate.
  - Capabilities `contested`/`speculative` (C11/C12 do Cognitive Plane) so viram default apos Rivals validation positivo.
maintenance:
  - Manter abaixo de 200 linhas.
  - Atualizar quando fase mudar na taxonomia de status (`scaffold`, `implemented-operational-read-model`, `implemented-runtime`, `implemented-surface-integrated`, `implemented-self-improving`) ou quando ordem precisar mudar por evidencia nova.
  - Status `active` significa roadmap canonico; itens internos continuam governados por fase/AP.
related_paths:
  - docs/engineering-knowledge-base/cognitive/implementation-briefing.md
  - docs/engineering-knowledge-base/cognitive/README.md
  - docs/engineering-knowledge-base/cognitive/overview.md
  - docs/engineering-knowledge-base/cognitive/capabilities-core.md
  - docs/engineering-knowledge-base/cognitive/multiplier-edge.md
  - docs/ap/AP-163-cognitive-dreyfus-dynamic-pedagogy.md
  - docs/ap/AP-164-cognitive-worked-example-engine.md
  - docs/ap/AP-165-cognitive-process-pattern-catalog.md
  - docs/ap/AP-166-cognitive-failure-signature-tracker.md
  - docs/ap/AP-167-cognitive-self-regulated-learning-orchestrator.md
  - docs/ap/AP-168-cognitive-productive-failure-flow.md
  - docs/ap/AP-169-cognitive-personal-worked-examples-generator.md
  - docs/ap/AP-170-cognitive-predictive-failure-insertion.md
owner: atlas-ai
layer: 2
line_limit: 200
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-ai-cognitive-roadmap

graph_title: Atlas AI Cognitive Plane - Roadmap

graph_world: atlas

graph_layer: flow

graph_kind: module

graph_parent: atlas-ai-pipeline

graph_status: active

graph_source: repo
human_name: Atlas AI Cognitive Plane - Roadmap
canonical_name: Atlas AI Cognitive Plane - Roadmap
technical_name: atlas-ai-cognitive-roadmap
cartography_type: module
canonical_source: docs/engineering-knowledge-base/cognitive/roadmap.md

repo_paths:
  - docs/engineering-knowledge-base/cognitive/roadmap.md

allowed_changes:
  - Atualizar este doc quando codigo, arquitetura, fluxo, evidencia ou decisao canonica mudar.

forbidden_changes:
  - Declarar runtime, maturidade ou prontidao sem evidencia verificavel e gates verdes.

depends_on:
  - atlas-ai-documentation-operating-system

flows_to:
  - atlas-cartography
  - atlas-code

unlocks:
  - ai-safe-implementation-context

governs:
  - cognitive

evidence:
  - docs/engineering-knowledge-base/cognitive/roadmap.md
evidence_refs:
  - symbol: AtlasCognitiveRoadmapService
  - command: atlas:aaeos:cognitive-roadmap
  - test: AtlasCognitiveRoadmapTest

required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true

risk_level: low

visual_tags:
  - flow
  - module
  - cognitive

ai_entrypoints:
  - Leia Resumo, Contratos, Regras para IA, Evidencias e Riscos antes de implementar.

ai_usage_notes:
  - Use repo_paths, allowed_changes, forbidden_changes e required_tests como limites operacionais.

quality_gates:
  - "php artisan atlas:engineering:knowledge docs-health --json"

failure_modes:
  - Contexto desatualizado entre doc, codigo, teste e evidencia.

observability_signals:
  - docs-health status ok

next_actions:
  - Manter este doc sincronizado com codigo, testes, evidencias e Cartografia.
---
# Atlas AI Cognitive Plane — Roadmap

Plano de entrega faseado. Dreyfus first.

## Authority

Em conflito: Tese central > Kernel > este doc > spec individual.

## Regra de ouro

> Fase 1 (Dreyfus Dynamic Pedagogy) sai antes de qualquer outra. Sem Dreyfus, todas as outras capabilities operam sobre suposicoes erradas de nivel.

## Roadmap unificado

### Eixo A — Cognitive Plane base (C0-C13)

| Fase | Escopo | Capabilities-chave | Pre-req |
|---|---|---|---|
| C0 Espelho cognitivo | `learning` v2 com `objective_design`, `daily_plan`, `consolidation`, `gap_detection`. Memory types base. CLI minimo | Curriculum Engine | flows learning v2 |
| C1 SRS + Recall + Generation | Spaced Repetition Engine (FSRS), Active Recall Generator, Generation Engine/Pretest. Inversao "Gerar Erro antes de Praticar" | FSRS, Active Recall, Generation | C0 |
| C2 Knowledge Graph | Builder + projecao + view CLI/App. Mastery overlay | Knowledge Graph Builder | C0 + C1 |
| C3 Feynman + Transfer + Construcionismo | Feynman Validator + flow `transfer_test` + gate `transfer_proof` + voz. Artefato deployavel obrigatorio (C16) | Feynman Validator, Deep Transfer Probe | C2 |
| C4 Cognitive Load + Flow + DMN/NSDR | Cognitive Load Monitor + Classifier + integracao energia/recovery; pause automatico. Flow Trigger Engine; DMN Oscillator; NSDR Engine | 4 capabilities cognitivas-bio | C1 |
| C5 Interleaving + Micro-Skill + Hemingway | Interleaving Scheduler intercala dominios; Micro-Skill Isolator decompoe meta; Hemingway Checkpoint forca open loop | Bjork + Ericsson + Zeigarnik | C1 |
| C6 Adversarial + First Principles | Adversarial Validator (Red Team) como papel canonico cardinal; First Principles Probe; flow `multi_perspective_case` | Kahneman + Spiro + reducao ontologica | C2 |
| C7 Curator preditivo | flow `predictive_curriculum` + Advisor shadow + Inbox proposals. Filtro critico para fontes externas operante | Predictive Curriculum Advisor | C2 + Self-Improvement |
| C8 Pareto Discovery + Incremental Reading | Pareto Discovery Engine mapeia cume; Incremental Reading Engine fragmenta fontes; flow `case_study`/`game_session` | Pareto Discovery, Incremental Reading | C2 + C7 |
| C9 Atlas-Vitor Cognitivo | fine-tune local; pre/pos-processador; privacy filter | modelo proprio local | Evidence Ledger maduro |
| C10 Rivals-Learning | suite empirica + scorecard + gates por release. Compressao temporal mensurada (meta 3-10x) | Rivals-Learning | C0 a C9 |
| C11 Perceptual Drill + Dual N-Back opcional | Perceptual Drill Engine para dominios visuais; Dual N-Back gamificado opcional **sem promessa de QI** | PLMs + Dual N-Back (contested) | C1 |
| C12 StackChan tutor + TMR (se hardware) | output renderer fisico + reflex layer educacional. TMR como proposta requires_rivals_validation | embodiment + TMR future | StackChan/wearable |
| **C13 Worked Examples + Process Pattern Catalog + Failure Tracker + SRL** | Worked Example Engine + Process Fading Scheduler (**AP-164 feito**); Process Pattern Catalog estrutura GoF (**AP-165 feito**); Failure Signature Classifier + Bayesian Tracker implementa C20 (**AP-166 feito: read model, CLI, gates, SLOs, events, learning.failure_review**). Self-Regulated Learning Orchestrator (**AP-167 feito: opt-in, episodes, CLI, gates, events**). Self-Explanation Generator segue scaffold. | Worked Example + Pattern + Failure + SRL | C2 Knowledge Graph |

Cada fase: codigo + teste + doc + Evidence + architecture-validate. Capabilities `contested`/`speculative` (C11/C12) so viram default apos Rivals validation positivo.

### Eixo B — Multiplier Edge (Fases 1-6)

| Fase | Capability | Justificativa | Pre-req | AP |
|---|---|---|---|---|
| **1 (done)** | **Dreyfus Dynamic Pedagogy** | impacto na primeira sessao; bloqueia desperdicio imediato; pre-req minimo | flows learning v2 (C0) | AP-163 |
| 2 | Cross-Domain Evidence Routing + Evidence-driven Self-Assessment | maximiza Evidence Ledger acumulado | Evidence Ledger ~30-60d | AP-COG-EDGE-02, 06 |
| 3 | Cross-Domain Latticework | depende de Knowledge Graph maduro | Knowledge Graph ~500+ nós (C2) | AP-COG-EDGE-03 |
| 4 | Multi-Provider Discord Detector (opt-in) | depende de pipeline cognitivo estavel | C0-C7 | AP-COG-EDGE-04 |
| 5 | Atlas-Vitor Socratic Tutor | depende de Atlas-Vitor Cognitivo treinado | C9 | AP-COG-EDGE-05 |
| 6 | Temporal Compression Validation | depende de Rivals-Learning maduro | C10 | AP-COG-EDGE-07 |
| 2-bis | **Personal Worked Examples Generator** (eixo Tim Cook / alta performance operacional) | depende de Worked Example Engine + Evidence Ledger ~30-60d | C13 + ledger | AP-169 |
| 3-bis | **Process Pattern Personal Detector** | depende de Evidence Ledger maduro + Process Pattern Catalog | C13 + ledger maduro | AP-COG-EDGE-10 |
| 4-bis | **Predictive Failure Insertion** | runtime minimo ativo; KG/daily-plan/UX ainda futuros | C2 + C13 maduro | AP-170 |

## Caminho minimo viavel ate primeira sessao funcional

Para humano novo + Codex chegarem em "Atlas study laravel-queues funciona com pedagogia Dreyfus correta":

| Etapa | Entregaveis | Resultado |
|---|---|---|
| E1 | AP-163 + schema `dreyfus_overlay` + migration + SLOs `cognitive.dreyfus.*` + gate `pedagogy_matches_stage` | **feito:** Dreyfus em CLI + Learning flow |
| E2 | Schemas estruturais (`learning_objective`, `study_session`, `mastery_profile`, `knowledge_node`, `knowledge_edge`) + cognitive Operation Envelope `input_kind=cognitive` | Pipeline cognitivo opera ponta a ponta |
| E3 | Onboarding contract: `atlas curriculum start <area>` end-to-end com Pareto Discovery scaffold + bootstrap do grafo vazio | Operador declara primeira dominancia |
| E4 | Surfaces basicas (CLI canonico: `php artisan atlas:study`, `php artisan atlas:dreyfus <node>`, `php artisan atlas:srl status`; wrappers `atlas ...` sao produto futuro) | Daily plan operacional |
| E5 | `self_improvement.cognitive_review` flow + 3 findings canonicos | Sistema imune liga |
| E6 | APs subsequentes (Cross-Domain Evidence Routing, Evidence-driven SA) | Multiplier Edge expande para Fase 2 |

Cada etapa e entregavel funcional, nao fase de planejamento.

## Definition Of Done por fase

Uma fase sai de `scaffold` quando:

1. Tem AP correspondente em `docs/ap/AP-###-cognitive-*.md`
2. Codigo + migration + tests passam localmente
3. `php artisan atlas:ai:architecture-validate --json` continua verde
4. `docs-health` continua verde
5. Pelo menos 1 fluxo CLI ou API end-to-end demonstra a capability
6. Evidence Ledger emite os events declarados
7. SLO targets declarados sao mensurados (mesmo que ainda nao validados)
8. Doc da capability em `cognitive/` aponta para o AP e marca status exato da taxonomia

## Anti-patterns de roadmap

| Anti-pattern | Por que evitar |
|---|---|
| Pular Fase 1 (Dreyfus) para "fazer logo Latticework" | Latticework opera sobre nivel detectado errado se Dreyfus nao existe |
| Implementar 7 capabilities cardinais em paralelo | Vaporware classico |
| Promover capability `contested`/`speculative` sem Rivals validation | Viola C15 |
| Mudar ordem do roadmap por opiniao sem evidencia | Roadmap muda por AP-99 ou Evidence, nao por preferencia |

## Continuidade

Detalhes operacionais por fase vivem em APs em `docs/ap/`. Cada AP e ~280 linhas, tem schema concreto, migration, services, gates, tests, validation commands.

APs cognitivos com status canonico:

| AP | Capability | Status | Fase |
|---|---|---|---|
| AP-163 | Dreyfus Dynamic Pedagogy | `implemented-operational-read-model` | Multiplier Edge Fase 1 |
| AP-164 | Worked Example Engine + Process Fading Scheduler | `implemented-operational-read-model` | C13 Core |
| AP-165 | Process Pattern Catalog | `implemented-operational-read-model` | C13 Core + base Latticework |
| AP-166 | Failure Signature Classifier + Bayesian Tracker | `implemented-operational-read-model` | C13 Core; proposal emission futura |
| AP-167 | Self-Regulated Learning Orchestrator | `implemented-operational-read-model` | C13 Core; hooks de UI/surface sao consumers futuros |
| AP-168 | Productive Failure Flow | `implemented_partial` | C13/C14 bridge; runtime minimo + CLI + ledger; transfer proposal-only |
| AP-169 | Personal Worked Examples Generator | `implemented_partial` | Multiplier Edge 2-bis; extract/personal CLI + ledger; scheduler/review UI futuros |
| AP-170 | Predictive Failure Insertion | `implemented_partial` | Multiplier Edge 4-bis; CLI/gates/metrics ativos |

APs futuros que dependem dos acima:

| AP futuro | Depende de |
|---|---|
| AP-COG-EDGE-10 (Process Pattern Personal Detector) | AP-165 + Evidence Ledger maduro |

APs adicionais escritos (8 total no Cognitive Plane):

| AP | Capability | Categoria |
|---|---|---|
| AP-167 | Self-Regulated Learning Orchestrator | Core transversal (overlay metacognitivo Zimmerman) |
| AP-168 | Productive Failure Flow | Core (flow Kapur 3 fases integrado) |
| AP-169 | Personal Worked Examples Generator | Multiplier Edge cardinal (Atlas-unico) |
| AP-170 | Predictive Failure Insertion | Multiplier Edge cardinal (Atlas-unico) |

## Resumo

Roadmap unificado do Cognitive Plane combinando 14 fases do Cognitive Development Plane (C0-C13) e 6 fases do Multiplier Edge. Dreyfus first como regra de ouro. Cada fase entrega valor isolado com codigo+teste+doc+evidence+architecture-validate.

## Papel no Atlas

Define a responsabilidade desta peca dentro da arquitetura Atlas.

## Onde Se Encaixa

Relaciona esta peca com seu sistema, camada, fluxo ou modulo pai.

## Contratos

Declara invariantes, entradas, saidas, limites e obrigacoes relevantes.

## Fluxo

Descreve o caminho operacional ou a sequencia de uso quando aplicavel.

## Regras para IA

Agentes devem respeitar escopo, evidencias, testes e proibicoes antes de alterar codigo.

## Escopo de Implementacao

Mudancas devem permanecer nos caminhos e limites declarados no frontmatter.

## Dependencias

Dependencias canonicas vivem em frontmatter e no corpo deste documento.

## Evidencias

Evidencias aceitas incluem docs, comandos, testes, receipts, reports e paths verificaveis.

## Riscos

Riscos principais devem ser tratados antes de promover status, runtime ou claims de prontidao.

## Exemplos

Exemplos concretos devem ser adicionados quando reduzirem ambiguidade para humanos ou IAs.

## Proximas Acoes

Proximas acoes devem ser concretas, verificaveis e ligadas a gates de qualidade.
