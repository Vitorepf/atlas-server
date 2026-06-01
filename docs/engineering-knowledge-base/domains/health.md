---
id: atlas-ai-health-domain
type: engineering_knowledge
title: Atlas AI Health Domain
status: active
category: architecture
priority: 92
summary: Spec canonica implemented/ready do dominio Health para review nao clinico de bem-estar, rotina, recuperacao e seguranca sem diagnostico, tratamento, dose ou emergencia.
capabilities:
  - health_domain
  - wellness_review
  - routine_review
  - safety_review
decisions:
  - Health e dominio implemented/ready para review nao clinico e planejamento de bem-estar.
  - Health nao diagnostica, nao prescreve, nao altera medicacao, nao interpreta exames como diagnostico e nao substitui cuidado profissional.
  - Risk flags exigem aviso de revisao profissional e nao podem virar auto-tratamento.
maintenance:
  - Atualize quando flows, gates, privacy, risk flags ou safety boundary mudarem.
  - Rodar docs-health, sync, architecture-validate e testes de DomainProfileCompliance depois de alterar o contrato.
related_paths:
  - app/Services/Ai/Domain/AtlasHealthOrchestrator.php
  - app/Services/Ai/Domain/HealthReviewService.php
  - app/Services/Ai/AtlasDomainProfileRegistry.php
  - database/migrations/2026_05_06_162000_promote_health_domain_onboarding_contract.php
owner: atlas-ai
layer: domain
line_limit: 220
tags:
  - atlas-ai
  - health
  - wellness
  - safety
related:
  - docs/engineering-knowledge-base/domains/personal-development.md
  - docs/engineering-knowledge-base/atlas-ai-business-contexts.md
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-ai-health-domain

graph_title: Atlas AI Health Domain

graph_world: atlas

graph_layer: system

graph_kind: module

graph_parent: atlas-ai-canonical-architecture-index

graph_status: active

graph_source: repo
human_name: Atlas AI Health Domain
canonical_name: Atlas AI Health Domain
technical_name: atlas-ai-health-domain
cartography_type: module
canonical_source: docs/engineering-knowledge-base/domains/health.md

repo_paths:
  - docs/engineering-knowledge-base/domains/health.md

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
  - docs/engineering-knowledge-base/domains/health.md

evidence_refs:
  - symbol: AtlasHealthOrchestrator
  - test: AtlasHealthOrchestratorTest
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
# Atlas AI Health Domain

Health is the non-clinical wellness review domain. It helps the operator reason
about routines, recovery, constraints, questions for professionals and safety
boundaries.

## Status

Current status: implemented/ready.

Health emits deterministic packets with dry-run Decision Receipts and Evidence
Ledger events. It is safety-first and non-clinical.

## Non-Confusion Rule

Health is not a doctor, clinician, emergency service or treatment engine.

It cannot diagnose, prescribe, change medication, interpret labs as diagnosis,
handle emergencies or replace professional care.

## Scope

Included:

1. non-clinical wellness review;
2. routine review;
3. recovery considerations;
4. safety review;
5. risk-flag escalation notice;
6. questions to ask a qualified professional.

Excluded:

1. diagnosis;
2. treatment plan;
3. dosage change;
4. emergency decision;
5. lab interpretation as diagnosis;
6. replacement of professional care.

## Flows

1. `health.review`
2. `health.routine_review`
3. `health.recovery_review`
4. `health.safety_review`

## Required Gates

Every flow must preserve:

1. `health_scope`
2. `non_clinical_boundary`
3. `professional_review_notice`

Risk flags additionally require:

1. `do_not_delay_care`
2. `no_self_treatment`
3. professional review notice

## Runtime Boundary

Runtime family: `health`.

Execution mode: `non_clinical_review_only`.

Health can produce wellness summaries, routine observations, recovery
considerations and safety boundaries. It cannot make medical decisions.

## Evidence Contract

Important outputs should become:

1. a dry-run Decision Receipt;
2. an Evidence Ledger `EVIDENCE_PACKED` event;
3. a health packet with topic, goal, signals, constraints and risk flags;
4. reviewed wellness patterns only, never medical decisions.

## Ready Boundary

Health is ready because it has:

1. domain/profile/flow declarations;
2. implemented orchestrator SDK methods;
3. `HealthReviewService` packet runtime;
4. dry-run Decision Receipt and Evidence Ledger audit path;
5. CLI/API/app/MCP surface declaration;
6. context, memory, gate and non-clinical policies;
7. tests proving no diagnosis, treatment, dosage change or emergency decision.

## Resumo

Spec canonica implemented/ready do dominio Health para review nao clinico de bem-estar, rotina, recuperacao e seguranca sem diagnostico, tratamento, dose ou emergencia.

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
