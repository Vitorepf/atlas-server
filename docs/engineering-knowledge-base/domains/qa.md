---
id: atlas-ai-qa-domain
type: engineering_knowledge
title: Atlas AI QA Domain
status: active
category: architecture
priority: 94
summary: Spec canonica implemented/ready do dominio QA para revisao transversal, aceite, auditoria de evidencia e release readiness sem executar testes nem burlar gates de dominio.
capabilities:
  - qa_domain
  - cross_domain_review
  - evidence_audit
  - release_readiness
decisions:
  - QA e dominio implemented/ready para revisao transversal governada.
  - QA Domain nao substitui programming.qa; programming.qa executa QA operacional de codigo via harness.
  - QA Domain nao executa testes, nao faz deploy e nao sobrepoe gates de dominio.
maintenance:
  - Atualize quando flows, gates, runtime, evidence audit ou release readiness mudarem.
  - Rodar docs-health, sync, architecture-validate e testes de DomainProfileCompliance depois de alterar o contrato.
related_paths:
  - app/Services/Ai/Domain/AtlasQaOrchestrator.php
  - app/Services/Ai/Domain/QaReviewService.php
  - app/Services/Ai/AtlasDomainProfileRegistry.php
  - database/migrations/2026_05_06_153000_promote_qa_domain_onboarding_contract.php
owner: atlas-ai
layer: domain
line_limit: 220
tags:
  - atlas-ai
  - qa
  - evidence-audit
  - release-readiness
related:
  - docs/engineering-knowledge-base/atlas-ai-core-vs-domain.md
  - docs/engineering-knowledge-base/atlas-ai-flow-visual-map.md
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-ai-qa-domain

graph_title: Atlas AI QA Domain

graph_world: atlas

graph_layer: system

graph_kind: module

graph_parent: atlas-ai-canonical-architecture-index

graph_status: active

graph_source: repo
human_name: Atlas AI QA Domain
canonical_name: Atlas AI QA Domain
technical_name: atlas-ai-qa-domain
cartography_type: module
canonical_source: docs/engineering-knowledge-base/domains/qa.md

repo_paths:
  - docs/engineering-knowledge-base/domains/qa.md

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
  - docs/engineering-knowledge-base/domains/qa.md

evidence_refs:
  - symbol: AtlasQaOrchestrator
  - test: AtlasQaOrchestratorTest
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
# Atlas AI QA Domain

QA is the cross-domain quality assurance domain. It reviews whether an
operation, artifact, release candidate or decision has enough evidence to be
trusted. It is a governance and review domain, not a test runner.

## Status

Current status: implemented/ready.

QA emits deterministic review packets with dry-run Decision Receipts and
Evidence Ledger events. Runtime execution remains a later stage and must carry
its own receipt. This keeps review separate from side effects.

## Non-Confusion Rule

QA Domain is not `programming.qa`.

`programming.qa` is the Programming Domain flow that can use the Engineering
Harness for code tests and regression evidence. QA Domain is transversal: it
can review programming, finance, marketing, operations or any other domain, but
it cannot execute tests, deploy, or override the reviewed domain's gates.

## Scope

Included:

1. regression review;
2. acceptance review;
3. evidence audit;
4. release readiness review;
5. traceability from claim to evidence;
6. visible uncertainty and risk;
7. go/no-go recommendation for human review.

Excluded:

1. executing tests directly;
2. deploying or publishing;
3. marking releases ready without evidence;
4. overriding domain gates;
5. hiding uncertainty to make a result look stronger.

## Flows

1. `qa.regression_review`
2. `qa.acceptance_review`
3. `qa.evidence_audit`
4. `qa.release_readiness`

## Required Gates

Every flow must preserve:

1. `qa_scope`
2. `acceptance_criteria`
3. `human_review_required`

Release readiness additionally requires:

1. `release_risk`
2. `blocking_findings`
3. `go_no_go_recommendation`

Evidence audit additionally requires:

1. `evidence_refs`
2. `traceability_map`
3. `uncertainty_statement`

## Runtime Boundary

Runtime family: `qa`.

Execution mode: `review_only`.

QA can prepare packets, risk maps, evidence gap lists and recommendations. It
cannot run test commands or mark operational work complete. If an actual test
run is needed, Atlas Decide must route to the owning domain/runtime and issue a
separate Decision Receipt.

## Evidence Contract

Important outputs should become:

1. a dry-run Decision Receipt;
2. an Evidence Ledger `EVIDENCE_PACKED` event;
3. a QA packet with subject, scope, acceptance criteria, evidence refs and risk;
4. a human-reviewed finding before memory or gate promotion.

## Ready Boundary

QA is ready because it has:

1. domain/profile/flow declarations;
2. implemented orchestrator SDK methods;
3. `QaReviewService` packet runtime;
4. dry-run Decision Receipt and Evidence Ledger audit path;
5. CLI/API/app/MCP surface declaration;
6. context, memory, gate and no-side-effect policies;
7. tests proving review-only behavior and separation from `programming.qa`.

## Resumo

Spec canonica implemented/ready do dominio QA para revisao transversal, aceite, auditoria de evidencia e release readiness sem executar testes nem burlar gates de dominio.

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
