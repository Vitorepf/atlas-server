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
