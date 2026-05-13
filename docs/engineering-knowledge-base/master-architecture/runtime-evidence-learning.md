---
id: atlas-ai-master-runtime-evidence-learning
type: engineering_knowledge
title: Master Runtime Evidence Learning
status: active
category: architecture
priority: 98
summary: Defines how Runtime, Evidence and Learning planes interact in the Atlas AI final product architecture.
tags:
  - atlas-ai
  - runtime
  - evidence
  - learning
capabilities:
  - evidence_driven_execution
  - super_tool_runtime
  - self_improvement_evolution
decisions:
  - Runtime executes receipts; Evidence records outcomes; Learning proposes improvements.
  - Critical learning changes require proposal review.
  - Language runtimes are divided by scope, not fashion.
maintenance:
  - Keep aligned with runtime language boundaries and telemetry docs.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-runtime-language-boundaries.md
  - docs/engineering-knowledge-base/atlas-ai-telemetry-evidence-performance.md
  - docs/engineering-knowledge-base/atlas-ai-master-architecture.md
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-ai-master-runtime-evidence-learning

graph_title: Master Runtime Evidence Learning

graph_world: atlas

graph_layer: system

graph_kind: module

graph_parent: atlas-ai-canonical-architecture-index

graph_status: active

graph_source: repo

owner: master-architecture

repo_paths:
  - docs/engineering-knowledge-base/master-architecture/runtime-evidence-learning.md

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
  - master-architecture

evidence:
  - docs/engineering-knowledge-base/master-architecture/runtime-evidence-learning.md

required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true

risk_level: high

visual_tags:
  - system
  - module
  - master-architecture

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
# Runtime Evidence Learning

## Runtime Roles

| Runtime | Role |
|---|---|
| Laravel | Kernel, API, auth, policy, receipts, ledger, orchestration |
| Python | AI/data runtime, Graph RAG, embeddings, ML, agents, simulations |
| Go | edge ingestion, concurrency, streaming, webhooks, LiveKit server layer |
| Swift | mobile and Apple-native edge, sensors, Secure Enclave, local UX, Mac/iOS integration |
| Providers | reasoning engines behind Provider Drivers |
| Super Tool Runtime | tools, recipes, normalizers, gates and evidence |

## Evidence Plane

Every meaningful runtime action emits Evidence: decision, provider call, tool
run, gate result, repair attempt, output rendered, proposal created and human
review outcome.

## Learning Plane

Learning consumes Evidence and produces:

1. memory signals;
2. provider performance changes;
3. retrieval improvements;
4. repair heuristics;
5. Curator proposals;
6. documentation health findings.

Learning does not silently mutate critical behavior.

## Resumo

Defines how Runtime, Evidence and Learning planes interact in the Atlas AI final product architecture.

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
