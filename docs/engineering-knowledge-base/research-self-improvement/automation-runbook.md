---
id: atlas-ai-research-self-improvement-automation-runbook
type: engineering_knowledge
title: Atlas AI Research Self-Improvement Automation Runbook
status: active
category: maintenance
priority: 98
summary: Runbook for implementing research and self-improvement automation safely, starting read-only and proposal-only.
tags:
  - atlas-ai
  - automation
  - runbook
  - self-improvement
capabilities:
  - research_automation_runbook
  - fail_closed_automation
decisions:
  - Automation must start as read-only packet generation.
  - Background research requires source registry, rate limits, audit and review before activation.
maintenance:
  - Update when commands, jobs, schedulers or API surfaces are implemented.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-research-self-improvement-runtime.md
  - docs/engineering-knowledge-base/research-self-improvement/schemas-and-packets.md
  - docs/engineering-knowledge-base/research-self-improvement/metrics-and-evals.md
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-ai-research-self-improvement-automation-runbook

graph_title: Atlas AI Research Self-Improvement Automation Runbook

graph_world: atlas

graph_layer: module

graph_kind: runbook

graph_parent: atlas-ai-canonical-architecture-index

graph_status: active

graph_source: repo
human_name: Atlas AI Research Self-Improvement Automation Runbook
canonical_name: Atlas AI Research Self-Improvement Automation Runbook
technical_name: atlas-ai-research-self-improvement-automation-runbook
cartography_type: runbook
canonical_source: docs/engineering-knowledge-base/research-self-improvement/automation-runbook.md

owner: research-self-improvement

repo_paths:
  - docs/engineering-knowledge-base/research-self-improvement/automation-runbook.md

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
  - docs/engineering-knowledge-base/research-self-improvement/automation-runbook.md

required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true

risk_level: low

visual_tags:
  - module
  - runbook
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
# Atlas AI Research Self-Improvement Automation Runbook

## Activation Order

1. Read-only schema/packet renderer.
2. Source quality scorer with no network side effects.
3. Manual research packet import.
4. Docs promotion preview.
5. AP/plan generator.
6. Self-Improvement proposal emission.
7. Scheduled read-only review.
8. Background source discovery with dedicated AP.
9. Approved apply for low-risk docs only, after repeated evidence.

## Required Guards Before Scheduler

- source registry;
- allowed source list;
- rate limits;
- canonical URL/hash;
- duplicate suppression;
- Evidence Ledger event;
- proposal inbox integration;
- docs-health validation;
- architecture validation for structural proposals;
- human review for medium/high risk.

## Forbidden First Versions

- crawler that writes docs directly;
- scheduler that changes code;
- provider release that changes Decide routing;
- memory write from unverified research;
- autonomous deletion of docs or source records;
- hidden background daemon without observability.

## Future Command Shape

```bash
php artisan atlas:ai:research-review --topic="<topic>" --plan-only --json
php artisan atlas:ai:research-review --source="<url>" --classify-only --json
php artisan atlas:ai:research-review --packet="<id>" --promotion-preview --json
php artisan atlas:ai:self-improve --flow=research_quality_review --plan-only --json
```

First implementation must return packets and review signals only.

## Validation Set

```bash
php artisan atlas:engineering:knowledge docs-health --json
php artisan atlas:engineering:knowledge sync --prune --json
php artisan atlas:ai:architecture-validate --json
git diff --check
```

If automation changes runtime code, add focused tests and architecture scanner
coverage before promotion.

## Resumo

Runbook for implementing research and self-improvement automation safely, starting read-only and proposal-only.

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
