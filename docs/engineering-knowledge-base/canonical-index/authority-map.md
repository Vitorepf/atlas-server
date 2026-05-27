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
| Reality Outcome Gates (15 gates feeding Evidence, ASRE, Mission Control and Holding scorecards; not a local L7->L8 promotion authority) | `atlas-reality-outcome-gates.md` |
| Domain Runtime Creation Gate (strict extension of existing Domain Routing Governance + Domain Runtime Contract; no parallel registry, manifest, maturity or department authority) | `atlas-domain-runtime-creation-gate.md` |
| Architecture Evolution Proposal Runtime (Atlas-proposed structural self-redesign with dual signature, 100-Obra replay, sovereignty layer protection) | `atlas-architecture-evolution-proposal-runtime.md` |
| Autonomous Software Company Night Shift (overnight software-company loop; v1 must prove itself on Atlas before v2 can operate external companies such as BlackInk) | `atlas-autonomous-software-company-night-shift.md` |
| Autonomous Software Company Night Shift Product Mode (final product target: cockpit, onboarding, autonomy tiers, continuous loop, budget, kill switch and trust surfaces) | `atlas-autonomous-software-company-night-shift-product-mode.md` |
| Atlas Area Stewardship Layer (next layer above Area Focus Loop; Atlas owns area health, roadmap, prioritization, Dev/Forge routing, evidence and operator inbox) | `atlas-area-stewardship-layer.md` |
| Atlas Stewardship Evolution Ladder (future ladder: Portfolio Stewardship, Autonomous Executive and Self-Expanding Software Company) | `atlas-stewardship-evolution-ladder.md` |

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
