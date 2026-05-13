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
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-ai-security-domain

graph_title: Atlas AI Security Domain

graph_world: atlas

graph_layer: system

graph_kind: module

graph_parent: atlas-ai-canonical-architecture-index

graph_status: active

graph_source: repo

repo_paths:
  - docs/engineering-knowledge-base/domains/security.md

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
  - docs/engineering-knowledge-base/domains/security.md

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

## Resumo

Spec canonica implemented/ready do dominio Security para revisao defensiva, privacidade, compliance e incidente sem exploit, segredo, scan ou acao operacional autonoma.

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
