---
id: atlas-ai-sdd-spec-graph-traceability
type: engineering_knowledge
title: Atlas SDD Spec Graph And Traceability
status: active
category: architecture
priority: 99
summary: Traceability graph from user intent to requirements, tasks, files, tests and evidence.
tags:
  - atlas-ai
  - sdd
  - spec-graph
  - traceability
capabilities:
  - spec_graph
  - spec_traceability
  - requirement_to_evidence
decisions:
  - Markdown alone is insufficient for enterprise SDD.
  - Every important requirement must trace to task, file, test and evidence.
maintenance:
  - Update when Spec Graph tables/read models are implemented.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-spec-operating-system.md
  - docs/engineering-knowledge-base/engineering-blueprint-contracts.md
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-ai-sdd-spec-graph-traceability

graph_title: Atlas SDD Spec Graph And Traceability

graph_world: atlas

graph_layer: gear

graph_kind: module

graph_parent: atlas-ai-self-construction-os

graph_status: active

graph_source: repo
human_name: Atlas SDD Spec Graph And Traceability
canonical_name: Atlas SDD Spec Graph And Traceability
technical_name: atlas-ai-sdd-spec-graph-traceability
cartography_type: module
canonical_source: docs/engineering-knowledge-base/spec-operating-system/spec-graph-and-traceability.md

owner: spec-operating-system

repo_paths:
  - docs/engineering-knowledge-base/spec-operating-system/spec-graph-and-traceability.md

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
  - spec-operating-system

evidence:
  - docs/engineering-knowledge-base/spec-operating-system/spec-graph-and-traceability.md

required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true

risk_level: medium

visual_tags:
  - gear
  - module
  - spec-operating-system

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
# Atlas SDD Spec Graph And Traceability

Spec Graph links:

```text
user_intent
-> interpreted_goal
-> requirement
-> acceptance_criteria
-> plan
-> task
-> file
-> test
-> evidence_event
-> decision
-> learning_proposal
```

## Required Questions

Atlas must be able to answer:

- Which test proves this requirement?
- Which file implements this acceptance criterion?
- Which evidence proves the gate passed?
- Did the patch modify files outside receipt?
- Did code change without matching spec?
- Did spec change without test/evidence?

## Minimum Traceability Row

```json
{
  "spec_id": "SPEC-...",
  "requirement_id": "R1",
  "acceptance_criteria_id": "AC1",
  "task_id": "T2",
  "file_path": "ProfileForm.tsx",
  "test_path": "ProfileForm.test.tsx",
  "evidence_event_id": "EVT-..."
}
```

## Promotion Rule

A feature is not enterprise-complete until critical requirements have traceable
evidence.

## Resumo

Traceability graph from user intent to requirements, tasks, files, tests and evidence.

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
