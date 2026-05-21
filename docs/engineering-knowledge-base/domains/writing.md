---
id: atlas-ai-writing-domain
type: engineering_knowledge
title: Atlas AI Writing Domain
status: active
category: architecture
priority: 94
summary: Spec canonica implemented/ready do dominio Writing para rascunho, edicao, voice review e publicacao revisada sem auto-publicacao.
capabilities:
  - writing_domain
  - governed_drafting
  - voice_alignment
  - publication_review
decisions:
  - Writing e dominio implemented/ready, mas draft-and-review por padrao.
  - O dominio pode produzir pacotes de escrita, edicao e revisao, mas nunca publica automaticamente.
  - Claims publicos, voz do operador e material sensivel exigem gates e revisao humana.
maintenance:
  - Atualize quando flows, gates, runtime, policy de voz ou publication review mudarem.
  - Rodar docs-health, sync, index-code e architecture-validate depois de alterar o contrato do dominio.
related_paths:
  - app/Services/Ai/Domain/AtlasWritingOrchestrator.php
  - app/Services/Ai/Domain/WritingDraftService.php
  - app/Services/Ai/AtlasDomainProfileRegistry.php
  - database/migrations/2026_05_06_151000_promote_writing_domain_onboarding_contract.php
owner: atlas-ai
layer: domain
line_limit: 220
tags:
  - atlas-ai
  - writing
  - voice
  - publication-review
related:
  - docs/engineering-knowledge-base/atlas-ai-canonical-architecture-index.md
  - docs/engineering-knowledge-base/atlas-ai-flow-visual-map.md
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-ai-writing-domain

graph_title: Atlas AI Writing Domain

graph_world: atlas

graph_layer: system

graph_kind: module

graph_parent: atlas-ai-canonical-architecture-index

graph_status: active

graph_source: repo
human_name: Atlas AI Writing Domain
canonical_name: Atlas AI Writing Domain
technical_name: atlas-ai-writing-domain
cartography_type: module
canonical_source: docs/engineering-knowledge-base/domains/writing.md

repo_paths:
  - docs/engineering-knowledge-base/domains/writing.md

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
  - docs/engineering-knowledge-base/domains/writing.md

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
# Atlas AI Writing Domain

Writing is the governed domain for drafting, editing, voice alignment, and
publication review. It turns writing into an auditable Atlas flow instead of a
generic chat response.

## Status

Current status: implemented/ready.

Writing emits deterministic writing packets with dry-run Decision Receipts and
Evidence Ledger events. Provider generation remains a later runtime stage; the
current implemented contract defines the safe packet, gates, outputs and review
rules.

## Scope

Included:

1. rascunhos de documento, proposta, briefing, narrativa e explicacao;
2. edicao estrutural e melhoria de clareza;
3. revisao de voz do operador;
4. revisao pre-publicacao;
5. controle de claims, material sensivel e fontes;
6. aprendizado revisado de voz e estilo.

Excluded:

1. auto-publicacao;
2. inventar fontes;
3. substituir voz do operador sem review;
4. publicar conteudo sensivel sem aprovacao;
5. executar campanhas ou gasto de midia, que pertencem ao dominio Marketing.

## Flows

1. `writing.draft`
2. `writing.edit`
3. `writing.voice_review`
4. `writing.publish_review`

## Required Gates

Every flow must preserve:

1. `brief_clarity`
2. `voice_alignment`
3. `human_review_required`

Publication review additionally requires:

1. `publication_review`
2. `claim_review`
3. `sensitive_disclosure_review`

## Runtime Boundary

Runtime family: `writing`.

Execution mode: `draft_and_review`.

Writing can prepare packets, plans and review outputs. It cannot publish,
contact an audience, mutate external platforms, or store new voice rules without
review. Accepted voice feedback may become memory after review.

## Evidence Contract

Important outputs should become:

1. a dry-run Decision Receipt;
2. an Evidence Ledger `EVIDENCE_PACKED` event;
3. a writing packet with brief, source material, output contract and gates;
4. memory proposal only after accepted voice or publication outcome feedback.

## Ready Boundary

Writing is ready because it has:

1. domain/profile/flow declarations;
2. implemented orchestrator SDK methods;
3. `WritingDraftService` packet runtime;
4. dry-run Decision Receipt and Evidence Ledger audit path;
5. CLI/API/app/MCP surface declaration;
6. context, memory, gate and publication policies;
7. tests proving packet behavior, review-only publication and evidence emission.

## Resumo

Spec canonica implemented/ready do dominio Writing para rascunho, edicao, voice review e publicacao revisada sem auto-publicacao.

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
