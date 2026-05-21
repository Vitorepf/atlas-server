---
id: atlas-ai-architecture-audit-programming-pipeline-target
type: engineering_knowledge
title: Atlas AI Architecture Audit Programming Pipeline Target
status: active
category: architecture
priority: 87
summary: Target programming pipeline from the architecture audit for unifying Atlas Dev, Forge, Fix, Continue, app and workers.
tags:
  - atlas-ai
  - architecture
  - programming
capabilities:
  - programming_pipeline_target
  - flow_consolidation_target
decisions:
  - Atlas Dev, Forge, Fix and Continue are surfaces/flows of Programming, not separate products.
  - Programming must use one context, quality, repair and evidence model.
maintenance:
  - Keep aligned with domains/programming.md and programming specialist docs.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-architecture-audit.md
  - docs/engineering-knowledge-base/domains/programming.md
  - docs/engineering-knowledge-base/engineering-blueprint.md
  - docs/engineering-knowledge-base/super-tool-runtime-core.md
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-ai-architecture-audit-programming-pipeline-target

graph_title: Atlas AI Architecture Audit Programming Pipeline Target

graph_world: atlas

graph_layer: flow

graph_kind: flow

graph_parent: atlas-ai-pipeline

graph_status: active

graph_source: repo
human_name: Atlas AI Architecture Audit Programming Pipeline Target
canonical_name: Atlas AI Architecture Audit Programming Pipeline Target
technical_name: atlas-ai-architecture-audit-programming-pipeline-target
cartography_type: flow
canonical_source: docs/engineering-knowledge-base/architecture-audit/programming-pipeline-target.md

owner: architecture-audit

repo_paths:
  - docs/engineering-knowledge-base/architecture-audit/programming-pipeline-target.md

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
  - architecture-audit

evidence:
  - docs/engineering-knowledge-base/architecture-audit/programming-pipeline-target.md

required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true

risk_level: medium

visual_tags:
  - flow
  - flow
  - architecture-audit

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
# Atlas AI Architecture Audit Programming Pipeline Target

## Target Flow

```text
raw input
-> ProgrammingIntentClassifier
-> ProgrammingTaskContractBuilder
-> ProgrammingContextCompiler
-> Atlas Decide policy/profile/model receipt
-> ProgrammingExecutorSelector
-> executor: simple_provider | dev_repair_executor | engineering_harness
-> ProgrammingQualityMatrix
-> ProgrammingRepairLoop
-> ProgrammingEvidencePacket
-> MemoryDelta / EngineeringLearning
```

## Proposed Components

| Component | Responsibility |
|---|---|
| `AtlasProgrammingPipeline` | Orchestrates the complete programming flow. |
| `ProgrammingIntentClassifier` | Detects implementation, bugfix, repair, review, refactor, DB, UI, security and harness needs. |
| `ProgrammingTaskContractBuilder` | Converts loose prompt into task contract or consumes an existing contract. |
| `ProgrammingContextCompiler` | Combines repo profile, Open Brain, Engineering Context Pack, code refs, likely files and tests. |
| `ProgrammingExecutorSelector` | Selects simple, dev-repair or harness execution using policy, risk and intent. |
| `ProgrammingQualityMatrix` | Selects and evaluates gates by task type. |
| `ProgrammingRepairLoop` | Controls repair capsule and iteration limits. |
| `ProgrammingEvidencePacketBuilder` | Produces auditable final packet. |

## Priority Sequence

| Priority | Target |
|---|---|
| P0 | Stop adding business logic inside commands. |
| P1 | Create or finish `AtlasProgrammingPipeline`. |
| P2 | Create `ProgrammingContextCompiler`. |
| P3 | Create `ProgrammingQualityMatrix`. |
| P4 | Create `ProgrammingRepairCapsule`. |
| P5 | Enforce horizontal Capability Registry. |

## Success Criteria

- `atlas dev` interactive and one-shot share the same internal pipeline.
- `atlas forge` is a heavier Programming intensity, not a second programming product.
- `atlas fix` is a repair intent/flow inside Programming.
- Context, gates, repair and final evidence are consistent across surfaces.
- Architecture validation can detect a new surface bypass.

## Resumo

Target programming pipeline from the architecture audit for unifying Atlas Dev, Forge, Fix, Continue, app and workers.

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
