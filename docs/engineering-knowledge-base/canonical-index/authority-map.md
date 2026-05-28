---
id: atlas-ai-canonical-authority-map
type: engineering_knowledge
title: Atlas AI Canonical Authority Map
status: active
category: architecture
priority: 96
summary: Detailed subject-to-document authority map for Atlas AI.
tags:
  - atlas-ai
  - architecture-index
  - authority
capabilities:
  - canonical_architecture_index
decisions:
  - Subject authority must be explicit to prevent duplicate docs and duplicate flows.
maintenance:
  - Update when a subject owner changes.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-canonical-architecture-index.md
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-ai-canonical-authority-map

graph_title: Atlas AI Canonical Authority Map

graph_world: atlas

graph_layer: system

graph_kind: index

graph_parent: atlas-ai-canonical-architecture-index

graph_status: active

graph_source: repo
human_name: Atlas AI Canonical Authority Map
canonical_name: Atlas AI Canonical Authority Map
technical_name: atlas-ai-canonical-authority-map
cartography_type: index
canonical_source: docs/engineering-knowledge-base/canonical-index/authority-map.md

owner: canonical-index

repo_paths:
  - docs/engineering-knowledge-base/canonical-index/authority-map.md

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
  - canonical-index

evidence:
  - docs/engineering-knowledge-base/canonical-index/authority-map.md

required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true

risk_level: medium

visual_tags:
  - system
  - index
  - canonical-index

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
# Atlas AI Canonical Authority Map

| Subject | Authority |
|---|---|
| Thesis/provider antifragility | `atlas-ai-thesis-multiplier-channel.md` + `thesis/*.md` |
| Session bootstrap | `atlas-ai-session-bootstrap.md` |
| Documentation governance | `atlas-ai-documentation-operating-system.md` |
| Knowledge governance | `atlas-ai-knowledge-governance-system.md` |
| Runtime languages | `atlas-ai-runtime-language-boundaries.md` |
| Kernel contracts | `atlas-ai-kernel-architecture.md` |
| Master product architecture | `atlas-ai-master-architecture.md` |
| Pipeline and topology | `atlas-ai-pipeline.md`, `atlas-ai-core-vs-domain.md`, `atlas-ai-operating-system.md` |
| Model selection and AP-99 | `atlas-ai-model-selection-strategy.md`, telemetry/performance docs, AP-146/AP-147 |
| **Cognition Operating System (ACOS) — macro authority over memory/context/RAG/graph/retrieval/embedding/ranking/freshness/compounding/learning** | `atlas-cognition-operating-system.md` |
| Memory/Open Brain (ACOS subsystem) | `atlas-ai-memory-context-core-open-brain.md` + `memory/*.md` |
| Memory noise immunity, capture quarantine and promotion gates (ACOS subsystem) | `memory/cognitive-immune-learning-kernel.md` |
| External pattern absorption roadmap (claude-mem/engram/mem0, feeds ACOS) | `atlas-external-memory-pattern-absorptions-v1.md` |
| Code Intelligence and external graph candidates | `code-intelligence.md` + `code-intelligence/external-graph-harness.md` |
| AtlasVault/Obsidian | `obsidian-atlas-vault.md` + `vault/*.md` |
| Mobile | `atlas-ai-mobile-surface-gateway.md` |
| Voice realtime | `atlas-ai-voice-realtime-surface.md` |
| CLI multimodal | `atlas-ai-cli-multimodal.md` |
| Programming | `domains/programming.md` + specialist docs |
| Self-Improvement | `domains/self-improvement.md` |
| Finance | `domains/finance.md` |
| Personal Development | `domains/personal-development.md` |
| Cognitive Development Plane | `cognitive/README.md` + cognitive APs |
| Business contexts | `atlas-ai-business-contexts.md` |
| Scenario simulation | `atlas-ai-scenario-simulation-harness.md` |
| Legacy/resolver corpus | `atlas-ai-resolver-corpus-audit.md`, `legacy-documentation-cleanup-report.md` |
| **Holding To World Action Hardening Initiative — corrected compatibility bridge; Genesis is not an OS and does not supersede Autonomous Holding or Domain Company Runtimes** | `atlas-autonomous-company-os-genesis-initiative.md` |
| Self-Directed Evolution Layer (Atlas detects canonical gaps, writes proposal docs/specs, forecasts roadmaps and routes domain/architecture/reality feedback for operator curation) | `atlas-self-directed-evolution-layer.md` |
| AAEOS Implementation Reality (doc maturity is not runtime state; required before AAEOS readiness, autonomy or 24h loop claims) | `atlas-agentic-engineering-os-implementation-reality.md` |
| AAEOS Runtime Gap Matrix (code-plus-doc snapshot for Dev/Forge/Stewardship gaps that block real autonomous software-factory claims) | `atlas-agentic-engineering-os-runtime-gap-matrix.md` |
| Reality Outcome Gates (15 gates feeding Evidence, ASRE, Mission Control and Holding scorecards; not a local L7->L8 promotion authority) | `atlas-reality-outcome-gates.md` |
| Domain Runtime Creation Gate (strict extension of existing Domain Routing Governance + Domain Runtime Contract; no parallel registry, manifest, maturity or department authority) | `atlas-domain-runtime-creation-gate.md` |
| Architecture Evolution Proposal Runtime (Atlas-proposed structural self-redesign with dual signature, 100-Obra replay, sovereignty layer protection) | `atlas-architecture-evolution-proposal-runtime.md` |
| Autonomous Software Company Night Shift (overnight software-company loop; v1 must prove itself on Atlas before v2 can operate external companies such as BlackInk) | `atlas-autonomous-software-company-night-shift.md` |
| Atlas Software Company Stewardship Stack (canonical umbrella for Night Shift, Product Mode, Atlas Continuous Stewardship Loop, Area Focus Loop, Area Stewardship, Portfolio, Executive and Self-Expanding layers; AP-738 adds Self-Expanding v0; AP-739 exposes upper review; AP-740/AP-748 bridge outcomes; AP-741 creates gated Domain Runtime Creation handoff packets; AP-742 exposes AP-740/AP-741 history in Product Mode/Cockpit; AP-743 creates Area Stewardship active handoff packets; AP-744 runs the first active operating slice; AP-745 wraps AP-744 in a scheduler-safe tick; AP-746 adds recurring scheduler admission; AP-756 materializes AP-726 branch sandboxes into isolated local git worktrees by operator receipt; AP-757 binds AP-749 owner consumption to that sandbox; AP-747 releases AP-726 handoffs to Dev/Forge queues by operator receipt; AP-749 gates owner consumption; AP-758 adapts ready consumption into AP-750-compatible owner results; AP-759 executes approved owner CLI commands inside AP-756 sandbox; AP-760 exposes AP-759 in Product Mode/Cockpit; AP-761 renders the end-to-end Product Mode pipeline in Atlas Desktop; AP-762 certifies the live cycle end-to-end before 100% claims; AP-763 audits the 29 practical requirements before any 29/29 claim; AP-750 bridges owner runtime results back to Evidence/Morning Inbox/Portfolio; AP-751 feeds those results into Portfolio health/risk/rebalance; AP-752 turns accepted executive recommendations into owner allocation handoffs; AP-753 exposes those handoffs in Product Mode/Cockpit; AP-754 exposes Product Mode operational controls read-only; AP-755 makes those controls receipt-backed through AP-731) | `atlas-software-company-stewardship-stack.md` |
| Autonomous Software Company Night Shift Product Mode (final product target: cockpit, onboarding, autonomy tiers, Atlas Continuous Stewardship Loop, budget, kill switch and trust surfaces) | `atlas-autonomous-software-company-night-shift-product-mode.md` |
| Atlas Area Stewardship Layer (next layer above Area Focus Loop; Atlas owns area health, roadmap, prioritization, Dev/Forge routing, evidence and operator inbox; AP-743 adds active handoff packets after AP-732 readiness; AP-744 consumes them for active operation; AP-745 makes that operation scheduler-safe; AP-746 makes it recurring-scheduler-safe; AP-756 materializes AP-726 branch sandboxes by operator receipt; AP-757 binds AP-749 to that sandbox; AP-747 releases AP-726 handoffs to Dev/Forge queues, AP-748 feeds outcomes to Evidence/Portfolio, AP-749 gates owner consumption, AP-758 adapts ready owner consumption, AP-759 executes approved owner CLI commands inside the sandbox, AP-760 exposes that run in Product Mode/Cockpit, AP-761 renders the desktop end-to-end review console, AP-750 bridges owner runtime results, AP-751 projects them into Portfolio health and AP-752 may route accepted executive allocations back as review packets) | `atlas-area-stewardship-layer.md` |
| Atlas Stewardship Evolution Ladder (ladder from Continuous Loop motor to Area, Portfolio, Executive and Self-Expanding Software Company stewardship; AP-738 composes review state; AP-739 renders it; AP-740/AP-748 record outcomes; AP-741 hands off accepted/evidenced proposals without creating domains; AP-742 makes AP-740/AP-741 history visible in the same cockpit; AP-743 adds Area Stewardship active handoff packets; AP-744 adds active operation; AP-745 adds scheduler-safe admission; AP-746 adds recurring scheduler admission; AP-756 adds operator-receipted branch sandbox materialization; AP-757 binds owner consumption to the sandbox; AP-747 adds operator-owned Dev/Forge release queue; AP-749 gates owner-specific consumption; AP-758 adapts ready consumption into AP-750-compatible owner results; AP-759 executes approved owner CLI commands inside AP-756 sandbox; AP-760 makes AP-759 cockpit-visible; AP-761 makes the desktop cockpit show the full operating pipeline; AP-762 certifies the live cycle before 100% claims; AP-763 audits the practical 29/29 completion list; AP-750 closes the owner result feedback loop; AP-751 closes the Portfolio intake for that result; AP-752 gates accepted executive allocations into owner handoffs; AP-753 makes them cockpit-visible; AP-754 makes Product Mode controls cockpit-visible; AP-755 records Product Mode controls as AP-731 receipts) | `atlas-stewardship-evolution-ladder.md` |

## Rule

If the subject is not here, find the closest owner README/doc before creating a
new authority surface.

## Resumo

Detailed subject-to-document authority map for Atlas AI.

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
