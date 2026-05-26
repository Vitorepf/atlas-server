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
| Memory/Open Brain | `atlas-ai-memory-context-core-open-brain.md` + `memory/*.md` |
| Memory noise immunity, capture quarantine and promotion gates | `memory/cognitive-immune-learning-kernel.md` |
| External pattern absorption roadmap (claude-mem/engram/mem0) | `atlas-external-memory-pattern-absorptions-v1.md` |
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
