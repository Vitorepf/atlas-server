---
id: atlas-ai-self-improvement-flows
type: engineering_knowledge
title: Atlas AI Self-Improvement Flows
status: active
category: architecture
priority: 88
summary: Focused flow catalog for the Self-Improvement domain.
tags:
  - atlas-ai
  - self-improvement
  - domains
capabilities:
  - self_improvement_flows
  - self_improvement_flow_proposal_generation
decisions:
  - Self-Improvement flows create findings and proposals; they do not auto-apply critical behavior changes.
maintenance:
  - Update when flows are added, renamed or retired.
related_paths:
  - docs/engineering-knowledge-base/domains/self-improvement.md
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-ai-self-improvement-flows

graph_title: Atlas AI Self-Improvement Flows

graph_world: atlas

graph_layer: flow

graph_kind: flow

graph_parent: atlas-ai-self-improvement-domain

graph_status: active

graph_source: repo
human_name: Atlas AI Self-Improvement Flows
canonical_name: Atlas AI Self-Improvement Flows
technical_name: atlas-ai-self-improvement-flows
cartography_type: flow
canonical_source: docs/engineering-knowledge-base/domains/self-improvement-flows.md

owner: domains

repo_paths:
  - docs/engineering-knowledge-base/domains/self-improvement-flows.md

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
  - docs/engineering-knowledge-base/domains/self-improvement-flows.md

required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true

risk_level: medium

visual_tags:
  - flow
  - flow
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
# Atlas AI Self-Improvement Flows

| Flow | Purpose |
|---|---|
| `self_improvement.nightly_review` | Recurring review of failures, drift, regressions and small proposals. |
| `self_improvement.weekly_architecture_audit` | Weekly architecture, docs, duplication and coverage audit. |
| `self_improvement.capability_gap_scan` | Gaps between declared capabilities, surfaces, tools and observed behavior. |
| `self_improvement.benchmark_review` | Baselines, benchmark corpus and quality suite review. |
| `self_improvement.memory_quality_review` | Recall, redundancy, stale memory, provider-safety and learning review. |
| `self_improvement.tool_runtime_review` | Tool failures, normalizers, recipes, gates and recovery. |
| `self_improvement.repair_loop_review` | Repair loop human review, blocked/exhausted repairs and repeated strategies. |
| `self_improvement.kernel_pipeline_review` | `atlas.run` accepted/rejected contracts and adapter drift. |
| `self_improvement.domain_learning_review` | Feedback/traces into domain/profile/policy/gate proposals. |
| `self_improvement.docs_drift_review` | Drift between KB, code, catalog, migrations and historical docs. |
| `self_improvement.provider_performance_review` | Cost, latency, quality, fallback, SLO and provider compliance. |
| `self_improvement.agent_behavior_review` | Agent behavior, missing verification, drift and repeated findings. |
| `self_improvement.proposal_generation` | Consolidates findings into provider-safe reviewable proposals. |

## Resumo

Focused flow catalog for the Self-Improvement domain.

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
