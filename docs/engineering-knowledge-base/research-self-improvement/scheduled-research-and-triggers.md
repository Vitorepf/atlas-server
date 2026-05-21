---
id: atlas-ai-scheduled-research-triggers
type: engineering_knowledge
title: Atlas AI Scheduled Research And Triggers
status: active
category: automation
priority: 98
summary: Time-based and event-based trigger contract for automatic research jobs.
tags:
  - atlas-ai
  - scheduler
  - triggers
  - research-automation
capabilities:
  - scheduled_research
  - event_triggered_research
  - research_jobs
decisions:
  - Scheduled research starts read-only and proposal-only.
  - Event triggers create research jobs, not direct implementation.
  - High-criticality topics require stronger review before publication or promotion.
  - Scheduled task runs emit versioned receipt hashes so long-running work has replayable lineage.
maintenance:
  - Update when scheduler, queues, watchlists, source registry or automation are implemented.
related_paths:
  - docs/engineering-knowledge-base/research-self-improvement/research-operating-system.md
  - docs/engineering-knowledge-base/research-self-improvement/automation-runbook.md
  - app/Jobs/RunScheduledTaskJob.php
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-ai-scheduled-research-triggers

graph_title: Atlas AI Scheduled Research And Triggers

graph_world: atlas

graph_layer: module

graph_kind: module

graph_parent: atlas-ai-canonical-architecture-index

graph_status: active

graph_source: repo
human_name: Atlas AI Scheduled Research And Triggers
canonical_name: Atlas AI Scheduled Research And Triggers
technical_name: atlas-ai-scheduled-research-triggers
cartography_type: module
canonical_source: docs/engineering-knowledge-base/research-self-improvement/scheduled-research-and-triggers.md

owner: research-self-improvement

repo_paths:
  - docs/engineering-knowledge-base/research-self-improvement/scheduled-research-and-triggers.md

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
  - research-self-improvement

evidence:
  - docs/engineering-knowledge-base/research-self-improvement/scheduled-research-and-triggers.md

required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true

risk_level: low

visual_tags:
  - module
  - module
  - research-self-improvement

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
# Atlas AI Scheduled Research And Triggers

Research jobs can be created by time or by event.

## Time-Based Jobs

Examples:

- daily provider release sweep;
- weekly state-of-art research review;
- weekly GitHub/watchlist change review;
- monthly benchmark landscape review;
- post-arXiv-window academic scan;
- periodic memory/retrieval quality research.

## Event-Based Jobs

Events:

- new provider release;
- new paper in watched area;
- new GitHub release/tag/advisory;
- changed official documentation hash;
- new CVE/security advisory;
- benchmark result changed;
- YouTube/conference talk from watched source;
- social spike linking primary sources;
- repeated Atlas failure pattern.

## Research Job Packet

```json
{
  "schema_version": "atlas.research_job.v1",
  "job_id": "uuid",
  "trigger_type": "time|event|manual",
  "topic": "string",
  "objective": "string",
  "window": "string",
  "source_classes": [],
  "risk": "low|medium|high|critical",
  "output": "packet|report|proposal|promotion_preview",
  "write_allowed": false,
  "created_at": "datetime"
}
```

## Execution Policy

- `write_allowed=false` is the default.
- Event triggers may start research only.
- Research jobs may create evidence and proposals.
- Docs/code/memory promotion requires explicit gate.
- Critical areas require human review: security, legal, medical, finance,
  compliance, privacy, credentials and infrastructure.

## Scheduled Run Receipt

Recurring scheduled tasks use the existing `RunScheduledTaskJob` surface. The
job does not create a new autonomy runtime or grant additional tool authority.
Each completed run records a compact receipt in task metadata:

```json
{
  "last_run_schema_version": "atlas.scheduled_task_run_receipt.v1",
  "last_output_hash": "sha256",
  "last_run_receipt_hash": "sha256",
  "previous_run_receipt_hash": "sha256|null"
}
```

The receipt hash covers the scheduled task id, status, trace id, duration,
redacted output hash, redacted error hash and previous receipt hash. Wrapped
local output also prints the run receipt so an operator can tie the artifact on
disk back to the task metadata. This gives long-running scheduled work a small
audit chain without storing secrets or raw provider output in the receipt.

## Publication States

| State | Meaning |
|---|---|
| draft | Research collected, not verified. |
| verified_report | Claims checked and citations healthy. |
| proposal | Self-Improvement action suggested. |
| promoted_doc | Canonical doc updated and validated. |
| implemented | Scoped code/docs block validated. |

## Resumo

Time-based and event-based trigger contract for automatic research jobs.

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
