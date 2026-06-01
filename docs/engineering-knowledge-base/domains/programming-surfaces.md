---
id: atlas-ai-programming-surfaces
type: engineering_knowledge
title: Atlas AI Programming Surfaces
status: active
category: architecture
priority: 90
summary: Surface-to-flow contract for Programming domain.
tags:
  - atlas-ai
  - programming
  - surfaces
capabilities:
  - programming_domain
  - programming_orchestrator
decisions:
  - Programming surfaces are thin adapters into `programming.*` flows; none becomes a parallel product.
maintenance:
  - Update when Programming surfaces or surface contracts change.
related_paths:
  - docs/engineering-knowledge-base/domains/programming.md
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-ai-programming-surfaces

graph_title: Atlas AI Programming Surfaces

graph_world: atlas

graph_layer: module

graph_kind: surface

graph_parent: atlas-ai-canonical-architecture-index

graph_status: active

graph_source: repo
human_name: Atlas AI Programming Surfaces
canonical_name: Atlas AI Programming Surfaces
technical_name: atlas-ai-programming-surfaces
cartography_type: surface
canonical_source: docs/engineering-knowledge-base/domains/programming-surfaces.md

owner: domains

repo_paths:
  - docs/engineering-knowledge-base/domains/programming-surfaces.md

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
  - domains

evidence:
  - docs/engineering-knowledge-base/domains/programming-surfaces.md

evidence_refs:
  - symbol: AtlasDomainsProgrammingSurfacesService
  - command: atlas:aaeos:atlas-domains-programming-surfaces
  - test: AtlasDomainsProgrammingSurfacesTest

required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true

risk_level: medium

visual_tags:
  - module
  - surface
  - domains

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
# Atlas AI Programming Surfaces

| Surface | Flow contract |
|---|---|
| `Atlas Code` / `Atlas Code SCOR-1` | `atlas_code`, `programming.forge` only, `routing_task=forge`, `programming_profile=forge`, `obra_id` required, Forge Workspace binding, evidence required |
| `atlas dev` | `programming.dev` |
| `atlas forge` | `programming.forge`, `atlas_cli_forge`, `engineering_harness`, evidence required |
| `atlas fix` | thin alias of `atlas dev --repair`, `programming.repair`, `dev_repair_executor` |
| `atlas continue` | resume contract preserving profile, intent, model and Open Brain |
| `atlas:ai:chat --dev` | `programming.*` by mode/task with chat contract |
| App/API/MCP | `domain_id=programming`, `flow_id=programming.*` |

`ProgrammingSurfaceContractFactory` owns forge, fix, continue and chat contracts.
Manual model overrides are audited through `ModelSelectionContractFactory` with
`authority=atlas_decide`; surfaces do not own model selection.

## Resumo

Surface-to-flow contract for Programming domain.

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
