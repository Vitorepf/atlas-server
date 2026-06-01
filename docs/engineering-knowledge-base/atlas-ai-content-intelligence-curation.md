---
id: atlas-ai-content-intelligence-curation
type: engineering_knowledge
title: Atlas AI Content Intelligence And Knowledge Curation
status: active
category: architecture
priority: 96
summary: Contrato canonico para ingestao, avaliacao, descarte, roteamento e promocao de conteudo externo ou humano sem poluir memoria, dominios ou Self-Improvement.
tags:
  - atlas-ai
  - content-intelligence
  - curation
  - knowledge-quality
  - memory
capabilities:
  - content_intelligence_curation
  - content_source_quality_triage
  - knowledge_destination_routing
  - youtube_global_ingestion
  - semantic_curation
decisions:
  - Curadoria de conteudo e capability horizontal de Core/Memory/Learning, nao dominio Blackink e nao substituto de Self-Improvement.
  - Blackink e apenas um possivel Business Context de destino; Atlas, Vitor, Finance, Programming, Marketing e outros destinos devem ser tratados com a mesma hierarquia.
  - Conteudo bruto pode ser capturado, mas so vira memoria operacional depois de quality, privacy, redundancy, truth/freshness e review gates.
  - Atlas pode propor aprendizado para si mesmo, mas nunca auto-refatora, auto-promove ou auto-aplica mudanca estrutural sem AP, evidence, gates e approval.
maintenance:
  - Manter abaixo de 260 linhas.
  - Atualizar antes de implementar ingestao YouTube, RSS, PDFs, feeds, scraping, source reputation, blacklist, transcricao/traducao automatica ou content quality gates.
  - Rodar docs-health, sync, index-code e architecture-validate depois de alterar.
related_paths:
  - config/atlas.php
  - app/Services/Semantic/CurationProposalService.php
  - app/Services/Semantic/CaptureSemanticClarifier.php
  - app/Jobs/ProcessAudioTranscription.php
  - app/Models/SemanticCurationProposal.php
  - app/Models/SemanticNote.php
  - database/migrations/2026_04_28_050000_create_semantic_memory_tables.php
  - tests/Feature/CaptureTranscriptionRetryTest.php
  - docs/engineering-knowledge-base/obsidian-atlas-vault.md
  - docs/engineering-knowledge-base/atlas-ai-local-performance-memory-strategy.md
  - docs/engineering-knowledge-base/domains/self-improvement.md
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-ai-content-intelligence-curation

graph_title: Atlas AI Content Intelligence And Knowledge Curation

graph_world: atlas

graph_layer: system

graph_kind: module

graph_parent: atlas-ai-canonical-architecture-index

graph_status: active

graph_source: repo
human_name: Atlas AI Content Intelligence And Knowledge Curation
canonical_name: Atlas AI Content Intelligence And Knowledge Curation
technical_name: atlas-ai-content-intelligence-curation
cartography_type: module
canonical_source: docs/engineering-knowledge-base/atlas-ai-content-intelligence-curation.md

owner: architecture

repo_paths:
  - docs/engineering-knowledge-base/atlas-ai-content-intelligence-curation.md

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
  - architecture

evidence:
  - docs/engineering-knowledge-base/atlas-ai-content-intelligence-curation.md
implementation_state: partial
evidence_refs:
  - symbol: CurationProposalService
  - command: atlas:semantic:curation-review

required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true

risk_level: medium

visual_tags:
  - system
  - module
  - architecture

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
# Atlas AI Content Intelligence And Knowledge Curation

Este documento define como o Atlas deve capturar, avaliar, descartar, rotear e
promover conteudo. O objetivo e impedir `Garbage In, Garbage Out`: qualidade de
entrada define qualidade cognitiva do Atlas e do operador.

## Regra Mae

Todo conteudo entra como candidato, nao como verdade.

```text
Capture -> Normalize -> Extract -> Quality Gate -> Redundancy Check
        -> Destination Routing -> Review/Proposal -> Memory/Vault/Domain/Archive/Discard
```

O Atlas deve preferir descartar conteudo raso a contaminar memoria, contexto ou
dominios com ruido.

## Status Real Atual

Existe base operacional para captura, clarificacao semantica, proposta
revisavel e promocao controlada:

- `captures`, `ProcessAudioTranscription`, `CaptureSemanticClarifier`,
  `semantic_curation_proposals`, `semantic_notes` e triagem para
  `semantic_note`, `task`, `project` ou `archive`;
- privacy/quarantine gates bloqueiam provider export, Open Brain context,
  embeddings e memory write ate review humano;
- `atlas.capture.content_intelligence.v1`,
  `atlas.capture.content_intelligence.proposal.v1`,
  `atlas.capture.resource_safety.v1` e
  `atlas.capture.ingest_replay_receipt.v1` preservam qualidade, lineage,
  dedupe posture e flags de quarentena sem expor raw content;
- `atlas:ai:capture-inbox-pipeline-report --hours=720 --json` valida o caminho
  Capture -> proposal -> memory delta -> inbox -> capture link como gate
  read-only antes de Memory/Open Brain;
- `atlas:ai:capture-inbox-pipeline-backfill-contracts --hours=720 --write --json`
  so repara metadata antiga: quarantine, content intelligence e proposal
  backlink. Nao copia raw content nem abre provider export, Open Brain,
  embeddings ou memory eligibility.

Ainda nao ha runtime completo para ingestao externa geral, YouTube global, PDFs,
feeds, source reputation e routing multi-dominio. Esses itens sao backlog
governado por este doc; nao sao autorizacao para IA criar fluxo paralelo.

## Tipos De Conteudo

Fontes aceitas como candidatas incluem video/audio, PDF/paper/livro,
GitHub/docs/changelog, RSS/newsletter/blog/X, screenshot/imagem,
reuniao/conversa, dados operacionais/logs e material de mercado/marketing.
Cada uma exige extractor, source refs, privacy metadata e risco explicito.

YouTube global e fonte P0 candidata porque concentra conhecimento oculto em
ingles, japones, russo e outros idiomas. O Atlas deve extrair segmentos
revisaveis com transcricao/traducao, nao salvar videos inteiros como memoria.

## Quality Gate De Curadoria

Cada candidato deve receber score explicado para densidade, novidade,
veracidade, atualidade, aplicabilidade, autoridade da fonte, custo cognitivo,
privacidade/permissao e risco de contaminar memoria com hype, conselho ruim ou
pseudociencia.

Conteudo de baixa densidade deve ir para `discard` ou `weak_archive`, nao para
Open Brain.

## Roteamento De Destino

Destinos validos: `discard`, `weak_archive`, `source_blacklist`,
`atlas_vault_note`, `semantic_note`, `atlas_memory_candidate`,
`domain_learning`, `self_improvement_proposal`, `task_or_project` e
`benchmark_case`. Uma mesma fonte pode gerar varios destinos, mas cada destino
precisa de evidence, source refs e privacy metadata.

## Vitor Vs Atlas Vs Dominios

O roteador deve separar aprendizado humano, aprendizado operacional do Atlas,
aprendizado de dominio e dado bruto. Vitor recebe AtlasVault/semantic
note/briefing humano; Atlas recebe proposal/AP/benchmark/doc canonico
candidato; dominios recebem domain learning com owner explicito; dado bruto vai
para baixa autoridade ou descarte.

Blackink nunca e default. Ela e um Business Context possivel; Marketing, Finance,
Programming, Personal Development e Strategic Decision sao Atlas AI Domains que
podem trabalhar sobre esse contexto.

## Autonomia Permitida

Permitido automaticamente: capturar fonte enviada, extrair texto/transcript,
metadata e hashes, criar proposta de curadoria, descartar candidato ruim com
auditoria minima e atualizar reputacao de fonte em modo conservador.

Criacao automatica de proposta deve continuar review-only: ela pode gravar
`semantic_curation.status=proposal_pending`, atualizar quarantine e expor acoes
de ratificacao/promocao no resource, mas nao pode promover memoria, escrever
contexto operacional nem resolver destino sem operador.

Legacy capture contract repair is allowed only as metadata backfill. The repair
may add `captures.metadata.cognitive_quarantine`,
`captures.metadata.content_intelligence` and
`captures.metadata.semantic_curation` backlink receipts, but must keep
`provider_export_allowed=false`, `open_brain_context_allowed=false`,
`embedding_allowed=false` and `memory_eligible=false`.

Exige review/approval:

1. promover memoria operacional;
2. alterar docs canonicos;
3. criar AP;
4. executar scraping recorrente;
5. usar captura passiva;
6. auto-melhorar codigo, prompts, policies ou tools.

## Roadmap Governado

CI-0 e CI-1 estao implemented: contrato canonico, semantic curation existente,
content source schema, quality score e destination enum em metadata/resource
safety. CI-2 a CI-6 sao `backlog_requires_ap`: YouTube URL ingest, source
reputation, redundancy check via Graph/Vector RAG, domain learning routing e
feeds/passive monitors. Uma IA deve promover por AP/owner decision, nao tratar
como runtime existente.

## Definition Of Done

Antes de implementar nova fonte ou curadoria, declarar content/source/destination
enum, passar por privacy/provider-safety, produzir quality score, registrar
source refs/hashes/idioma/timestamp/extractor version, deduplicar contra
memoria/Graph RAG, criar proposal revisavel antes de promover, provar que
Blackink nao e default implicito e atualizar docs, tests e Code Intelligence.

## Resumo

Content Intelligence governa captura, triagem, quarantine, proposta revisavel e
promocao controlada de conteudo sem transformar raw capture em verdade.

## Papel no Atlas

E uma capability horizontal de Core/Memory/Learning; nao e dominio Blackink,
nao substitui Self-Improvement e nao autoriza ingestion externa sem AP.

## Onde Se Encaixa

Fica entre Capture, Semantic Curation, Memory Registry, Open Brain, AtlasVault,
dominios e Self-Improvement, mantendo cada destino com owner explicito.

## Contratos

Raw capture nao e evidencia, memoria, contexto nem decisao. Propostas sao
review-only ate ratificacao humana e receipt de promocao.

## Fluxo

Capture normaliza conteudo, aplica quality/privacy/dedupe posture, cria proposta
revisavel e so promove para memoria/contexto depois de review e receipt.

## Regras para IA

1. Nunca promover raw capture diretamente para evidencia, memoria, contexto ou
   decisao.
2. Nunca tratar AtlasVault, archive/source-material ou proposta de backlog como
   runtime atual.
3. Nunca criar ingestion externa, scraping recorrente, YouTube global ou source reputation sem AP/owner decision, privacy gate e testes.
4. Antes de alterar esta area, rodar docs-health e testes de captura/curadoria
   que provem quarantine, proposal e promotion gates.

## Escopo de Implementacao

Mudancas pertencem aos paths de captura, curadoria semantica, metadata de
quarantine/content intelligence, resources de safety e comandos de pipeline.

## Dependencias

Depende de Memory/Open Brain contracts, AtlasVault como Human Knowledge Surface,
ADRS/docs-health e Code Reality para provar runtime antes de claims.

## Evidencias

Evidencia valida: testes de captura/curadoria, receipts de metadata, comandos de
pipeline, docs-health, Code Reality e related paths declarados no frontmatter.

## Riscos

Riscos principais: provider leak, memoria contaminada, Graph/Open Brain com raw
content, Blackink default implicito e backlog tratado como runtime.

## Exemplos

Uma captura de texto pode virar `semantic_curation_proposal`; so apos review
humano ela pode virar memoria operacional ou verbatim store.

## Proximas Acoes

Promover itens de backlog por AP, um source type por vez, com privacy gate,
dedupe posture, teste focado e update de Code Intelligence.
