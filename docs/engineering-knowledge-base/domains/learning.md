---
id: atlas-ai-learning-domain
type: engineering_knowledge
title: Atlas AI Learning Domain
status: active
category: architecture
priority: 94
summary: Spec canonica implemented/ready do dominio Learning para aprendizado humano, pratica deliberada, revisao e spaced review sem alterar o Learning Plane do Core.
capabilities:
  - learning_domain
  - deliberate_practice
  - spaced_review
  - mastery_evidence
decisions:
  - Learning e dominio implemented/ready para aprendizado humano.
  - Learning Domain nao e o Learning Plane do Core; ele nao promove memoria nem altera comportamento critico sem review.
  - Planos de estudo nao mutam calendario, tarefas ou rotina automaticamente.
maintenance:
  - Atualize quando flows, gates, runtime, spaced review ou mastery evidence mudarem.
  - Rodar docs-health, sync, architecture-validate e testes de DomainProfileCompliance depois de alterar o contrato.
related_paths:
  - app/Services/Ai/Domain/AtlasLearningOrchestrator.php
  - app/Services/Ai/Domain/LearningPlanService.php
  - app/Services/Ai/AtlasDomainProfileRegistry.php
  - database/migrations/2026_05_06_152000_promote_learning_domain_onboarding_contract.php
owner: atlas-ai
layer: domain
line_limit: 220
tags:
  - atlas-ai
  - learning
  - deliberate-practice
  - spaced-review
related:
  - docs/engineering-knowledge-base/atlas-ai-core-vs-domain.md
  - docs/engineering-knowledge-base/atlas-ai-flow-visual-map.md
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-ai-learning-domain

graph_title: Atlas AI Learning Domain

graph_world: atlas

graph_layer: system

graph_kind: module

graph_parent: atlas-ai-canonical-architecture-index

graph_status: active

graph_source: repo

repo_paths:
  - docs/engineering-knowledge-base/domains/learning.md

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
  - docs/engineering-knowledge-base/domains/learning.md

required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true

risk_level: medium

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
# Atlas AI Learning Domain

Learning is the human learning domain. It helps the operator learn a topic,
skill, workflow or mental model through structured plans, practice loops,
review, retrieval and spaced repetition.

## Status

Current status: implemented/ready.

Learning emits deterministic learning packets with dry-run Decision Receipts and
Evidence Ledger events. Provider synthesis remains a later runtime stage; the
implemented contract defines safe packets, gates, outputs and memory policy.

## Non-Confusion Rule

Learning Domain is not the Core Learning Plane.

Core Learning transforms evidence into memory, metrics, proposals and process
updates. Learning Domain plans human learning. It can propose learning artifacts,
but it cannot mutate core memory, policy, calendar, tasks or behavior without
review.

## Scope

Included:

1. learning plans;
2. deliberate practice;
3. review after practice;
4. spaced review;
5. mastery rubric;
6. mistake patterns;
7. reviewed evidence of progress.

Excluded:

1. automatic calendar or task mutation;
2. claiming mastery without evidence;
3. promoting learning results to memory without review;
4. modifying Core Learning Plane behavior;
5. replacing Personal Development routines.

## Flows

1. `learning.plan`
2. `learning.practice`
3. `learning.review`
4. `learning.spaced_review`

## Required Gates

Every flow must preserve:

1. `learning_objective`
2. `practice_loop`
3. `mastery_rubric`

Review additionally requires:

1. `outcome_evidence`
2. `gap_map`
3. `next_iteration`

## Runtime Boundary

Runtime family: `learning`.

Execution mode: `plan_only`.

Learning can prepare packets, practice loops and review proposals. It cannot
write to calendar, tasks, Core Learning, or long-term memory without human
review.

## Evidence Contract

Important outputs should become:

1. a dry-run Decision Receipt;
2. an Evidence Ledger `EVIDENCE_PACKED` event;
3. a learning packet with objective, topic, target level, practice loop and
   mastery rubric;
4. memory proposal only after reviewed mastery evidence.

## Ready Boundary

Learning is ready because it has:

1. domain/profile/flow declarations;
2. implemented orchestrator SDK methods;
3. `LearningPlanService` packet runtime;
4. dry-run Decision Receipt and Evidence Ledger audit path;
5. CLI/API/app/MCP surface declaration;
6. context, memory, gate and no-mutation policies;
7. tests proving human-learning boundary and Core Learning Plane non-mutation.

## Resumo

Spec canonica implemented/ready do dominio Learning para aprendizado humano, pratica deliberada, revisao e spaced review sem alterar o Learning Plane do Core.

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
