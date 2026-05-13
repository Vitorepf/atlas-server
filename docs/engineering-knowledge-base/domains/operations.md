---
id: atlas-ai-operations-domain
type: engineering_knowledge
title: Atlas AI Operations Domain
status: active
category: architecture
priority: 94
summary: Spec canonica implemented/ready do dominio Operations para diagnostico, runbook, incidente e readiness sem deploy, restart, mutacao de infraestrutura ou delecao de dados.
capabilities:
  - operations_domain
  - operational_diagnostic
  - runbook_planning
  - incident_review
  - readiness_review
decisions:
  - Operations e dominio implemented/ready para diagnostico operacional governado.
  - Operations Domain nao executa mudancas operacionais; acoes reais exigem runtime/tool e Decision Receipt separado.
  - Operations Domain nao faz deploy, restart, mutacao de infraestrutura, delecao de dados ou alteracao de DNS/segredos.
maintenance:
  - Atualize quando flows, gates, runbook, incident review ou readiness review mudarem.
  - Rodar docs-health, sync, architecture-validate e testes de DomainProfileCompliance depois de alterar o contrato.
related_paths:
  - app/Services/Ai/Domain/AtlasOperationsOrchestrator.php
  - app/Services/Ai/Domain/OperationsDiagnosticService.php
  - app/Services/Ai/AtlasDomainProfileRegistry.php
  - database/migrations/2026_05_06_155000_promote_operations_domain_onboarding_contract.php
owner: atlas-ai
layer: domain
line_limit: 220
tags:
  - atlas-ai
  - operations
  - runbook
  - incident-review
  - readiness
related:
  - docs/engineering-knowledge-base/atlas-ai-business-contexts.md
  - docs/engineering-knowledge-base/atlas-ai-flow-visual-map.md
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-ai-operations-domain

graph_title: Atlas AI Operations Domain

graph_world: atlas

graph_layer: system

graph_kind: module

graph_parent: atlas-ai-canonical-architecture-index

graph_status: active

graph_source: repo

repo_paths:
  - docs/engineering-knowledge-base/domains/operations.md

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
  - docs/engineering-knowledge-base/domains/operations.md

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
# Atlas AI Operations Domain

Operations is the operational diagnostic domain. It helps the operator inspect
systems, symptoms, signals, runbooks, incidents and readiness without mutating
production or infrastructure.

## Status

Current status: implemented/ready.

Operations emits deterministic diagnostic packets with dry-run Decision
Receipts and Evidence Ledger events. Real operational execution requires a
separate runtime, tool policy, approval and receipt.

## Non-Confusion Rule

Operations Domain is not an infra executor.

It can reason about a production issue, draft a runbook, prepare a readiness
review and identify safe next checks. It cannot restart services, deploy,
delete data, alter DNS, change secrets or mutate infrastructure.

## Scope

Included:

1. operational diagnostic;
2. runbook planning;
3. incident review;
4. readiness review;
5. signal mapping;
6. rollback questions;
7. postmortem recommendations.

Excluded:

1. deploy;
2. service restart;
3. infrastructure mutation;
4. data deletion;
5. DNS or secret changes.

## Flows

1. `operations.diagnostic`
2. `operations.runbook`
3. `operations.incident_review`
4. `operations.readiness_review`

## Required Gates

Every flow must preserve:

1. `operations_scope`
2. `diagnostic_only`
3. `human_review_required`

Operational action additionally requires:

1. `separate_decision_receipt`
2. `operator_approval`
3. `rollback_plan`

## Runtime Boundary

Runtime family: `operations`.

Execution mode: `diagnostic_only`.

Operations can prepare triage trees, signal maps, runbook steps and readiness
recommendations. It cannot operate the system. If a real command is needed,
Atlas Decide must route to the owning runtime with explicit approval and a
separate Decision Receipt.

## Evidence Contract

Important outputs should become:

1. a dry-run Decision Receipt;
2. an Evidence Ledger `EVIDENCE_PACKED` event;
3. an operations packet with system, scope, symptoms, signals and evidence;
4. a reviewed finding before memory, runbook or policy promotion.

## Ready Boundary

Operations is ready because it has:

1. domain/profile/flow declarations;
2. implemented orchestrator SDK methods;
3. `OperationsDiagnosticService` packet runtime;
4. dry-run Decision Receipt and Evidence Ledger audit path;
5. CLI/API/app/MCP surface declaration;
6. context, memory, gate and diagnostic-only policies;
7. tests proving no deploy, restart, infra mutation or data deletion.

## Resumo

Spec canonica implemented/ready do dominio Operations para diagnostico, runbook, incidente e readiness sem deploy, restart, mutacao de infraestrutura ou delecao de dados.

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
