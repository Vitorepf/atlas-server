---
id: atlas-engineering-blueprint-lifecycle-runbook
type: engineering_knowledge
title: Atlas Engineering Blueprint Lifecycle Runbook
status: active
category: runbook
priority: 88
summary: End-to-end lifecycle for project blueprint, task generation, harness execution, QA, review, Postgres gate and memory delta.
tags:
  - atlas
  - engineering
  - lifecycle
capabilities:
  - engineering_blueprint_lifecycle_runbook
  - blueprint_lifecycle_qa_evidence
  - blueprint_lifecycle_review_gates
decisions:
  - Blueprint lifecycle runs from project intent to evidence and memory delta through governed gates.
maintenance:
  - Update when lifecycle steps change.
related_paths:
  - docs/engineering-knowledge-base/engineering-blueprint-runbook.md
  - docs/engineering-knowledge-base/engineering-blueprint-quality-gates.md
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-engineering-blueprint-lifecycle-runbook

graph_title: Atlas Engineering Blueprint Lifecycle Runbook

graph_world: atlas

graph_layer: module

graph_kind: runbook

graph_parent: atlas-ai-canonical-architecture-index

graph_status: active

graph_source: repo
human_name: Atlas Engineering Blueprint Lifecycle Runbook
canonical_name: Atlas Engineering Blueprint Lifecycle Runbook
technical_name: atlas-engineering-blueprint-lifecycle-runbook
cartography_type: runbook
canonical_source: docs/engineering-knowledge-base/engineering-blueprint/lifecycle-runbook.md

owner: engineering-blueprint

repo_paths:
  - docs/engineering-knowledge-base/engineering-blueprint/lifecycle-runbook.md

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
  - engineering-blueprint

evidence:
  - docs/engineering-knowledge-base/engineering-blueprint/lifecycle-runbook.md
evidence_refs:
  - symbol: AtlasLifecycleRunbookService
  - command: atlas:aaeos:lifecycle-runbook
  - test: AtlasLifecycleRunbookTest

required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true

risk_level: low

visual_tags:
  - module
  - runbook
  - engineering-blueprint

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
# Atlas Engineering Blueprint Lifecycle Runbook

## Product Lifecycle

| Step | Purpose |
|---|---|
| Prepare project blueprint | Draft objective, context, repos, risks and constraints. |
| Create project blueprint | Build deterministic project-level plan from intent and project state. |
| Validate coverage | Block if inventory, scenarios, phase plan or gates are incomplete. |
| Freeze blueprint | Hash and freeze immutable version. |
| Generate tasks | Convert phases into strong task contracts. |
| Run task | Execute through Programming/Harness with context and gates. |
| QA manual | Attach human or runtime evidence. |
| Deep review | Add categorized findings and confidence. |
| Postgres review | Validate database risk and query/lock concerns. |
| Promote to Atlas-Bench | Use run evidence for benchmark/calibration. |
| Memory delta | Promote reusable learning through Memory Core policy. |

## AI Start Checklist

- Read task contract and frozen blueprint.
- Compile context from Open Brain and Engineering Context.
- Confirm gates and expected evidence.
- Execute through the selected runtime.
- Produce evidence packet and memory delta proposal.

## Failure Rule

Missing contract, stale blueprint, absent evidence or blocking gate means the
operation should pause or repair, not claim completion.

## Resumo

End-to-end lifecycle for project blueprint, task generation, harness execution, QA, review, Postgres gate and memory delta.

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
