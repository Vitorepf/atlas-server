---
id: atlas-ai-general-domain
type: engineering_knowledge
title: Atlas AI General Domain
status: active
category: architecture
priority: 91
summary: Spec canonica implemented/ready do dominio General para resposta simples e triagem governada sem substituir dominios especializados.
capabilities:
  - general_domain
  - answer_triage
  - domain_handoff
decisions:
  - General e dominio implemented/ready para perguntas simples, explicacao curta e triagem.
  - General nao executa trabalho especializado; deve encaminhar para Programming, Finance, Marketing, Operations, Health ou outro dominio dono.
  - General nao chama tools, nao altera arquivos, nao escolhe provider manualmente e nao burla Atlas Decide.
maintenance:
  - Atualize quando fallback, intent routing, answer triage ou domain handoff mudarem.
  - Rodar docs-health, sync, architecture-validate e testes de DomainProfileCompliance depois de alterar o contrato.
related_paths:
  - app/Services/Ai/Domain/StandardResponseOrchestrator.php
  - app/Services/Ai/Domain/GeneralAnswerService.php
  - app/Services/Ai/AtlasDomainProfileRegistry.php
  - database/migrations/2026_05_06_161000_promote_general_domain_onboarding_contract.php
owner: atlas-ai
layer: domain
line_limit: 180
tags:
  - atlas-ai
  - general
  - routing
  - handoff
related:
  - docs/engineering-knowledge-base/atlas-ai-pipeline.md
  - docs/engineering-knowledge-base/atlas-ai-core-vs-domain.md
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-ai-general-domain

graph_title: Atlas AI General Domain

graph_world: atlas

graph_layer: system

graph_kind: module

graph_parent: atlas-ai-canonical-architecture-index

graph_status: active

graph_source: repo
human_name: Atlas AI General Domain
canonical_name: Atlas AI General Domain
technical_name: atlas-ai-general-domain
cartography_type: module
canonical_source: docs/engineering-knowledge-base/domains/general.md

repo_paths:
  - docs/engineering-knowledge-base/domains/general.md

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
  - docs/engineering-knowledge-base/domains/general.md

evidence_refs:
  - symbol: StandardResponseOrchestrator
  - test: StandardResponseOrchestratorTest
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
# Atlas AI General Domain

General is the controlled answer and triage domain. It handles simple questions
and explains where a request belongs when the right answer is a specialized
Atlas domain.

## Status

Current status: implemented/ready.

General emits deterministic packets with dry-run Decision Receipts and Evidence
Ledger events. It is intentionally narrow.

## Non-Confusion Rule

General is not the "do anything" domain.

It cannot replace Programming, Finance, Marketing, Operations, Security,
Personal Development, Research, Learning, Background Safety or Health. If the
request needs specialized context, tools, gates or risk policy, General must
recommend handoff through Atlas Decide.

## Scope

Included:

1. simple answer;
2. clarification;
3. architecture navigation;
4. domain handoff recommendation;
5. uncertainty statement.

Excluded:

1. code changes;
2. financial action;
3. operational mutation;
4. health claim;
5. background job start;
6. provider override.

## Flow

1. `general.answer`

## Required Gates

Every flow must preserve:

1. `question_present`
2. `answer_or_triage_only`
3. `domain_handoff_review`
4. `no_policy_bypass`
5. `no_destructive_action`

## Runtime Boundary

Runtime family: `conversation`.

Execution mode: `answer_or_triage_only`.

General can answer or route. It cannot execute domain work. Specialized work
must go through Atlas Decide, Domain Profile, Flow Profile, Policy/Profile and
the owning runtime.

## Evidence Contract

Important outputs should become:

1. a dry-run Decision Receipt;
2. an Evidence Ledger `EVIDENCE_PACKED` event;
3. a general packet with question, context, suspected domain and handoff rule;
4. reviewed handoff misses before intent-router learning.

## Ready Boundary

General is ready because it has:

1. domain/profile/flow declarations;
2. implemented orchestrator SDK methods;
3. `GeneralAnswerService` packet runtime;
4. dry-run Decision Receipt and Evidence Ledger audit path;
5. CLI/API/app/MCP surface declaration;
6. context, memory, gate and triage-only policies;
7. tests proving no specialized work, tool execution or provider override.

## Resumo

Spec canonica implemented/ready do dominio General para resposta simples e triagem governada sem substituir dominios especializados.

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
