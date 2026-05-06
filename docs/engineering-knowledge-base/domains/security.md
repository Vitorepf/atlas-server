---
id: atlas-ai-security-domain
type: engineering_knowledge
title: Atlas AI Security Domain
status: active
category: architecture
priority: 95
summary: Spec canonica implemented/ready do dominio Security para revisao defensiva, privacidade, compliance e incidente sem exploit, segredo, scan ou acao operacional autonoma.
capabilities:
  - security_domain
  - threat_review
  - privacy_review
  - compliance_review
  - incident_review
decisions:
  - Security e dominio implemented/ready para revisao defensiva governada.
  - Security Domain nao substitui programming.security; programming.security usa harness de codigo e ferramentas.
  - Security Domain nao executa exploit, scan, acesso a segredo, desabilitacao de controle ou acao operacional autonoma.
maintenance:
  - Atualize quando flows, gates, privacy, compliance, incident review ou defensive review mudarem.
  - Rodar docs-health, sync, architecture-validate e testes de DomainProfileCompliance depois de alterar o contrato.
related_paths:
  - app/Services/Ai/Domain/AtlasSecurityOrchestrator.php
  - app/Services/Ai/Domain/SecurityReviewService.php
  - app/Services/Ai/AtlasDomainProfileRegistry.php
  - database/migrations/2026_05_06_154000_promote_security_domain_onboarding_contract.php
owner: atlas-ai
layer: domain
line_limit: 220
tags:
  - atlas-ai
  - security
  - defensive-review
  - privacy
  - compliance
related:
  - docs/engineering-knowledge-base/atlas-ai-core-vs-domain.md
  - docs/engineering-knowledge-base/memory-core-security-privacy.md
---

# Atlas AI Security Domain

Security is the defensive security review domain. It helps the operator inspect
assets, privacy boundaries, controls, compliance evidence and incidents. It is
not an offensive testing runtime.

## Status

Current status: implemented/ready.

Security emits deterministic defensive review packets with dry-run Decision
Receipts and Evidence Ledger events. Active scanning, code security tests, tool
execution or operational remediation require a separate runtime and receipt.

## Non-Confusion Rule

Security Domain is not `programming.security`.

`programming.security` belongs to Programming and can use the Engineering
Harness for code security tools. Security Domain is transversal and defensive:
it reviews risks and controls across domains without running exploits, scans,
secret access or destructive actions.

## Scope

Included:

1. threat review;
2. privacy review;
3. compliance review;
4. incident review;
5. control mapping;
6. evidence gaps;
7. postmortem recommendations.

Excluded:

1. exploit execution;
2. external network scanning;
3. printing or accessing secrets;
4. disabling security controls;
5. claiming compliance without evidence.

## Flows

1. `security.threat_review`
2. `security.privacy_review`
3. `security.compliance_review`
4. `security.incident_review`

## Required Gates

Every flow must preserve:

1. `security_scope`
2. `defensive_only`
3. `human_review_required`

High-risk work additionally requires:

1. `operator_approval`
2. `evidence_refs`
3. `redaction_review`

## Runtime Boundary

Runtime family: `security`.

Execution mode: `defensive_review_only`.

Security can prepare risk registers, control maps, privacy findings and
postmortem actions. It cannot scan, exploit, access secrets or alter controls.
If a tool run is needed, Atlas Decide must route to the correct runtime with a
separate Decision Receipt and explicit tool policy.

## Evidence Contract

Important outputs should become:

1. a dry-run Decision Receipt;
2. an Evidence Ledger `EVIDENCE_PACKED` event;
3. a security packet with asset, scope, controls, evidence refs and risk;
4. a reviewed finding before memory, policy or gate promotion.

## Ready Boundary

Security is ready because it has:

1. domain/profile/flow declarations;
2. implemented orchestrator SDK methods;
3. `SecurityReviewService` packet runtime;
4. dry-run Decision Receipt and Evidence Ledger audit path;
5. CLI/API/app/MCP surface declaration;
6. context, memory, gate and defensive-only policies;
7. tests proving defensive-only behavior and separation from `programming.security`.
