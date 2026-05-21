---
id: atlas-ai-self-improvement-runtime
type: engineering_knowledge
title: Atlas AI Self-Improvement Runtime
status: active
category: architecture
priority: 88
summary: Runtime, schedule, filters and replay contract for Self-Improvement domain.
tags:
  - atlas-ai
  - self-improvement
  - runtime
capabilities:
  - self_improvement_runtime
  - docs_drift_review
decisions:
  - Self-Improvement runtime consumes shared read models and emits reviewable proposals, not direct critical mutations.
maintenance:
  - Update when schedule, filters, replay surfaces or AP review signals change.
related_paths:
  - docs/engineering-knowledge-base/domains/self-improvement.md
  - app/Services/Ai/SelfImprovement/AtlasSelfImprovementRuntime.php
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-ai-self-improvement-runtime

graph_title: Atlas AI Self-Improvement Runtime

graph_world: atlas

graph_layer: system

graph_kind: module

graph_parent: atlas-ai-self-improvement-domain

graph_status: active

graph_source: repo
human_name: Atlas AI Self-Improvement Runtime
canonical_name: Atlas AI Self-Improvement Runtime
technical_name: atlas-ai-self-improvement-runtime
cartography_type: module
canonical_source: docs/engineering-knowledge-base/domains/self-improvement-runtime.md

owner: domains

repo_paths:
  - docs/engineering-knowledge-base/domains/self-improvement-runtime.md

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
  - docs/engineering-knowledge-base/domains/self-improvement-runtime.md

required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true

risk_level: high

visual_tags:
  - system
  - module
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
# Atlas AI Self-Improvement Runtime

## Runtime Sources

- `sloReportForWindow()`;
- `repairReportForWindow()`;
- `kernelPipelineReportForWindow()`;
- domain catalog scorecards;
- architecture validation;
- KB, Code Intelligence, tool evidence, memory signals, provider traces and benchmark corpus.

## Operational Surfaces

- `atlas:ai:self-improve --list-flows --json`;
- `atlas:ai:self-improve --schedule-plan --json`;
- `atlas:ai:self-improve --schedule-health --json`;
- `atlas:ai:self-improve --flow=... --plan-only --json`;
- `GET /ai/self-improvement/schedule`;
- `GET /ai/self-improvement/schedule/health`;
- `GET /ai/self-improvement/schedule/report`;
- Open Brain/MCP read-only schedule and report tools.

## Schedule Contract

The recurring plan is normalized by `AtlasSelfImprovementScheduleService`.
Unknown flows, invalid time or invalid timezone become visible warnings and do
not silently execute. `plan_hash` is stable over effective configuration and
health issues, not clock movement.

Default recurring flows now include `provider_release_review` between agent
behavior and voice realtime review. This keeps provider/lab launches under
Curator observation without crawler, direct provider channel, policy write or
routing mutation. The flow remains proposal-only and every release still needs
source gate, Provider Release Envelope, AP/Rivals/AP-99 evidence and human
review before any Decide signal can be promoted.

## Review Signals

AP42-AP56 ensure recurring architecture audit, per-flow cadence, per-command
`next_run_at`, schedule replay, review signals for schedule, kernel pipeline,
repair loop and SLO drift, plus surface parity across CLI, API, Observability
and MCP.

## Safety

Warnings and drift become findings/proposals. Runtime, adapter, provider,
schedule or policy changes still require the normal implementation/review path.

## Resumo

Runtime, schedule, filters and replay contract for Self-Improvement domain.

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
