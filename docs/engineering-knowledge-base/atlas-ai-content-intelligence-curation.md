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
  - source_quality_gate
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

Ja existe base operacional:

1. `captures` para texto, audio, imagem e arquivos;
2. `ProcessAudioTranscription` com transcricao local via whisper.cpp;
3. `CaptureSemanticClarifier` para tese, ideias atomicas, densidade e destino;
4. `semantic_curation_proposals` para propostas revisaveis;
5. `semantic_notes` como memoria semantica humana/indexada;
6. triagem de captura para `semantic_note`, `task`, `project` ou `archive`;
7. privacy gate que bloqueia IA externa para conteudo privado/sensivel;
8. aceite de proposta com `promote_to_memory=true` promove delta ratificado para
   Memory Registry com receipt;
9. `captures.metadata.semantic_curation` liga proposta automatica ao
   `CaptureResource.review_workflow` sem marcar destino como resolvido;
10. raw capture quarantine keeps `provider_export_allowed=false`,
    `open_brain_context_allowed=false` and `raw_content_exposed=false` until
    human review promotes a safe memory/verbatim artifact.
11. duplicate capture ingest records `atlas.capture.ingest_replay_receipt.v1`
    audit evidence with hashes/quarantine flags and no raw content exposure.
12. `CaptureResource.capture_safety` exposes
    `atlas.capture.resource_safety.v1` so API consumers can distinguish raw
    authenticated payload visibility from provider/Open Brain/embedding/memory
    eligibility.
13. text/file capture now writes `atlas.capture.content_intelligence.v1` with
    content type, source refs, destination enum, deterministic quality score,
    dedupe posture and explicit no-provider/no-embedding/no-memory promotion
    gates before any Open Brain use.
14. semantic curation proposals project that contract as
    `atlas.capture.content_intelligence.proposal.v1`, preserving lineage,
    quality and destination metadata while keeping raw content quarantined and
    provider/Open Brain promotion blocked until review.
15. `atlas:ai:capture-inbox-pipeline-report --hours=720 --json` validates
    Capture -> proposal -> memory delta -> inbox -> capture link integrity as a
    read-only gate before Memory/Open Brain promotion. It also publishes
    `atlas.capture_inbox_pipeline.promotion_gate.v1`, keeping memory writes,
    context injection, embeddings, provider export and Open Brain context closed
    by report authority until operator review, lineage backlinks and promotion
    receipt hash exist.
16. `atlas:ai:capture-inbox-pipeline-backfill-contracts --hours=720 --write --json`
    repairs legacy capture metadata conservatively: quarantine, content
    intelligence and proposal backlink only; it copies no raw content and keeps
    provider export, Open Brain context, embeddings and memory eligibility
    closed.
17. AtlasVault como Human Knowledge Surface, nao fonte operacional crua.

Falta transformar isso em Content Intelligence completo para fontes externas,
source reputation, YouTube global, PDFs, feeds e routing multi-dominio.

## Tipos De Conteudo

| Tipo | Extracao correta | Risco principal |
|---|---|---|
| YouTube/video | baixar/transcrever/traduzir, timestamps, tese, momentos-chave | clickbait, enrolacao, idioma, baixa densidade |
| Podcast/audio | transcricao, speakers, segmentos, actionable ideas | divagacao e repeticao |
| PDF/paper/livro | metadata, claims, metodos, citacoes, limitações | falsa autoridade, obsolescencia |
| GitHub/docs/changelog | versoes, API changes, exemplos, breaking changes | tutorial desatualizado |
| RSS/newsletter/blog/X | tese, fonte, novidade, evidencias | ruido, hype, opiniao reciclada |
| Screenshot/imagem | OCR, elementos visuais, contexto explicitamente enviado | privacidade |
| Reuniao/conversa | resumo, decisoes, tasks, riscos | consentimento e sensibilidade |
| Dados operacionais/logs | sinais, anomalias, metricas | volume alto e falsa correlacao |
| Mercado/marketing | claims, copy, VSL, hooks, offer structure | dominio Marketing, compliance e scraping |

YouTube global e fonte P0 futura porque concentra muito conhecimento oculto em
ingles, japones, russo e outros idiomas. O Atlas deve extrair ouro com
transcricao/traducao, nao salvar videos inteiros como memoria.

## Quality Gate De Curadoria

Cada candidato deve receber score e explicacao:

1. densidade: ideias novas por tamanho/tempo;
2. novidade: o que ainda nao esta no Graph RAG/memoria;
3. veracidade: claims verificaveis, fontes, contraexemplos;
4. atualidade: versao/data/obsolescencia;
5. aplicabilidade: vira decisao, skill, modelo mental, AP, task ou benchmark;
6. autoridade da fonte: historico, reputacao, conflito de interesse;
7. custo cognitivo: tempo/tokens para aproveitar;
8. privacidade e permissao;
9. risco de contaminar memoria com hype, conselho ruim ou pseudociencia.

Conteudo de baixa densidade deve ir para `discard` ou `weak_archive`, nao para
Open Brain.

## Roteamento De Destino

| Destino | Quando usar |
|---|---|
| `discard` | lixo, clickbait, repeticao, erro claro |
| `weak_archive` | referencia fraca que talvez sirva como historico |
| `source_blacklist` | fonte recorrente de baixa qualidade ou enganosa |
| `atlas_vault_note` | aprendizado humano para leitura/revisao do Vitor |
| `semantic_note` | conhecimento humano estruturado e reutilizavel |
| `atlas_memory_candidate` | memoria operacional provider-safe apos review |
| `domain_learning` | aprendizado para Programming, Finance, Marketing etc. |
| `self_improvement_proposal` | melhoria do proprio Atlas via AP/proposal |
| `task_or_project` | acao concreta para operador ou dominio |
| `benchmark_case` | caso util para avaliar provider, tool ou fluxo |

Uma mesma fonte pode gerar varios destinos, mas cada destino precisa de
evidence, source refs e privacy metadata.

## Vitor Vs Atlas Vs Dominios

O roteador deve responder:

1. Isso ensina o Vitor? Vai para AtlasVault/semantic note/briefing humano.
2. Isso ensina o Atlas? Vira proposal, AP, benchmark ou doc canonico candidato.
3. Isso ensina um dominio? Vai para domain learning com owner explicito.
4. Isso e so dado bruto? Arquiva com baixa autoridade ou descarta.

Blackink nunca e default. Ela e um Business Context possivel; Marketing, Finance,
Programming, Personal Development e Strategic Decision sao Atlas AI Domains que
podem trabalhar sobre esse contexto.

## Autonomia Permitida

Permitido automaticamente:

1. capturar fonte explicitamente enviada;
2. extrair texto, transcript, metadata e hashes;
3. criar proposta de curadoria;
4. descartar candidato claramente ruim mantendo auditoria minima;
5. atualizar reputacao de fonte em modo conservador.

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

## Implementation Roadmap

| Fase | Status | Entrega |
|---|---|---|
| CI-0 | active | contrato canonico e ligacao com semantic curation existente |
| CI-1 | active | content source schema + quality score + destination enum in capture metadata/resource safety |
| CI-2 | future | YouTube URL ingest com transcript/traducao/timestamps |
| CI-3 | future | source reputation, blacklist e weak archive |
| CI-4 | future | redundancy check via Graph/Vector RAG |
| CI-5 | future | domain learning routing e self-improvement proposals |
| CI-6 | future | feeds/passive monitors opt-in com privacy gates |

## Definition Of Done

Antes de implementar uma nova fonte ou curadoria:

1. declarar content type, source type e destination enum;
2. passar por privacy/provider-safety;
3. produzir quality score explicado;
4. registrar source refs, hashes, idioma, timestamp e extractor version;
5. deduplicar contra memoria/Graph RAG;
6. criar proposal revisavel antes de promover;
7. provar que Blackink nao e default implicito;
8. atualizar docs, tests e Code Intelligence.
