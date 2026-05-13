---
id: atlas-ai-research-implementation-planning-rollout
type: engineering_knowledge
title: Atlas AI Research Implementation Planning And Rollout
status: active
category: delivery-governance
priority: 98
summary: Rules for converting source-backed documentation into small, reversible implementation blocks.
tags:
  - atlas-ai
  - planning
  - rollout
  - validation
capabilities:
  - implementation_planning
  - rollout_governance
  - evidence_based_delivery
decisions:
  - Implementation must be decomposed into small reversible blocks.
  - Every block must have validation before promotion.
maintenance:
  - Update when Atlas adds planning evaluator, task contract generator or rollout autopilot.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-research-self-improvement-runtime.md
  - docs/engineering-knowledge-base/engineering-blueprint.md
  - docs/engineering-knowledge-base/engineering-blueprint-quality-gates.md
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-ai-research-implementation-planning-rollout

graph_title: Atlas AI Research Implementation Planning And Rollout

graph_world: atlas

graph_layer: system

graph_kind: policy

graph_parent: atlas-ai-canonical-architecture-index

graph_status: active

graph_source: repo

owner: research-self-improvement

repo_paths:
  - docs/engineering-knowledge-base/research-self-improvement/implementation-planning-and-rollout.md

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
  - docs/engineering-knowledge-base/research-self-improvement/implementation-planning-and-rollout.md

required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true

risk_level: medium

visual_tags:
  - system
  - policy
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
# Atlas AI Research Implementation Planning And Rollout

Implementation begins after source-backed documentation or a focused AP exists.

## Block Shape

Every block must declare:

- objective;
- owner files;
- hot files to avoid;
- allowed files;
- forbidden changes;
- test command;
- architecture/doc command when applicable;
- rollback plan;
- success metric.

## Rollout Order

1. Cold tests and guardrails.
2. Read-only scanners and reports.
3. DTO/schema contracts.
4. Runtime adapters behind fail-closed gates.
5. Surface exposure.
6. Promotion metrics.
7. Automation.

This order avoids making autonomy powerful before it is observable and
reversible.

## Stop Conditions

Stop and report when:

- implementation touches hotter ownership than planned;
- source basis is weaker than expected;
- validation would require forbidden temporary files;
- runtime change would bypass Kernel/Policy/Receipt/Ledger;
- docs and code disagree;
- failure is in a hot file owned by another active worker.

## Done Definition

A block is done only when:

- code/doc delta is scoped;
- tests pass or failure is explained with cause;
- `git diff --check` passes;
- docs-health passes if docs changed;
- architecture validation passes when structural contracts changed.

## Resumo

Rules for converting source-backed documentation into small, reversible implementation blocks.

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
