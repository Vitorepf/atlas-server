---
id: atlas-ai-programming-repair-contract
type: engineering_knowledge
title: Atlas AI Programming Repair Contract
status: active
category: architecture
priority: 90
summary: Repair Loop contract for Programming flows, Dev, Forge, Fix and worker repair.
tags:
  - atlas-ai
  - programming
  - repair
capabilities:
  - programming_domain
  - dev_repair_executor
decisions:
  - Programming repair converges on Kernel Repair contracts and Evidence Ledger events.
maintenance:
  - Update when Kernel Repair or Programming repair metadata changes.
related_paths:
  - docs/engineering-knowledge-base/domains/programming.md
  - app/Services/Ai/Kernel/Repair
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-ai-programming-repair-contract

graph_title: Atlas AI Programming Repair Contract

graph_world: atlas

graph_layer: system

graph_kind: contract

graph_parent: atlas-ai-canonical-architecture-index

graph_status: active

graph_source: repo
human_name: Atlas AI Programming Repair Contract
canonical_name: Atlas AI Programming Repair Contract
technical_name: atlas-ai-programming-repair-contract
cartography_type: contract
canonical_source: docs/engineering-knowledge-base/domains/programming-repair-contract.md

owner: domains

repo_paths:
  - docs/engineering-knowledge-base/domains/programming-repair-contract.md

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
  - docs/engineering-knowledge-base/domains/programming-repair-contract.md

required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true

risk_level: medium

visual_tags:
  - system
  - contract
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
# Atlas AI Programming Repair Contract

## Target Use

- classify operational failure as `FailureClassification`;
- preserve `envelope_id` and `receipt_id`;
- fill `evidence_refs` with ledger events, harness runs, diffs, logs,
  screenshots or test output;
- call `AtlasRepairOrchestrator::plan()` before attempts;
- block heavy repair without evidence;
- require human review for policy, privacy, security, compliance, unknown and
  terminal states.

## Current Integration

- `AiWorker` plans through Kernel Repair before queuing repair jobs.
- `AtlasProgrammingOrchestrator::sessionPlan()` publishes
  `repair_execution_contract`.
- Programming plans also publish `agent_behavior_contract`.
- Forge/Harness propagates the same repair and behavior contracts.
- `atlas:ai:repair` and `POST /ai/repair` remain planning/scaffold surfaces.
- Ledger projections expose repair summaries by envelope.

## Resumo

Repair Loop contract for Programming flows, Dev, Forge, Fix and worker repair.

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
