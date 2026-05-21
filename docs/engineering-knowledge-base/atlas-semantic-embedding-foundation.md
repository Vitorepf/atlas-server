---
id: atlas-semantic-embedding-foundation
type: engineering_knowledge
title: Atlas Semantic Embedding Foundation
status: planned
implementation_state: planned_child_architecture_not_current_runtime
blocker: ASEF ainda nao possui runtime proprio, migrations/jobs de indexacao final, tests ou certificacao; e o bloco 1 da AUCRI.
category: intelligence-runtime
priority: 99
summary: Doc filha AUCRI para embeddings semanticos, chunking, versionamento, privacy, delete cascade, provider-safe policy e golden sets. Embeddings sao candidatos de retrieval, nao fonte de verdade.
tags: [atlas-ai, aucri, asef, embeddings, vector-search, retrieval]
capabilities: [semantic_embeddings, chunking, vector_indexing, privacy_gate, delete_cascade]
decisions:
  - ASEF e a fundacao de embeddings da AUCRI.
  - Embeddings nunca sao autoridade; entram como candidatos para AHRI/ACRS.
  - External embedding exige privacy review, retention/delete policy e rollback.
maintenance:
  - Atualizar antes de mudar embedding provider, chunking, indexacao ou delete cascade.
related_paths:
  - docs/engineering-knowledge-base/atlas-unified-context-retrieval-intelligence.md
  - docs/engineering-knowledge-base/atlas-rag-graph-python-context-handoff.md
  - docs/engineering-knowledge-base/memory/retrieval-and-context.md
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
  - config/atlas.php
  - app/Services/Semantic/EmbeddingService.php
doc_schema: atlas_canonical_module_doc.v1
macro_layer: true
product_name: Atlas Semantic Embedding Foundation
runtime_acronym: ASEF
internal_product_name: Atlas Vector Seed
technical_runtime: AtlasSemanticEmbeddingFoundationService
graph_id: atlas-semantic-embedding-foundation
graph_title: Atlas Semantic Embedding Foundation
graph_world: atlas
graph_layer: module
graph_kind: module
graph_parent: atlas-unified-context-retrieval-intelligence
graph_status: planned
graph_source: repo
owner: atlas-ai
repo_paths:
  - docs/engineering-knowledge-base/atlas-semantic-embedding-foundation.md
allowed_changes:
  - Definir schemas, jobs, services, models e tests de embedding governado.
forbidden_changes:
  - Enviar dados sensiveis para embeddings externos sem review.
  - Tratar similaridade vetorial como fato.
  - Criar store paralelo sem ADR.
depends_on: [atlas-unified-context-retrieval-intelligence, atlas-ai-memory-retrieval-and-context]
flows_to: [atlas-hybrid-retrieval-infrastructure, atlas-context-ranking-system]
unlocks: [semantic_candidate_retrieval, governed_vector_indexing]
governs: [embeddings, vector_candidates]
evidence:
  - docs/engineering-knowledge-base/atlas-semantic-embedding-foundation.md
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
  - "php artisan atlas:ai:local-rag-readiness --json"
requires_evidence: true
risk_level: high
line_limit: 520
next_actions:
  - Criar AUCRI-I2 slice ASEF com inventory de tabelas, providers e policy.
---

# Atlas Semantic Embedding Foundation

## Resumo

ASEF e o bloco 1 da AUCRI. Ele transforma documentos, memorias, anexos e
artefatos em candidatos vetoriais recuperaveis, com privacidade, versionamento
e delete cascade. Ele nao decide contexto final.

## Papel no Atlas

Fornecer candidatos semanticos para retrieval. AHRI coleta esses candidatos,
ACRS ranqueia, ACFQ valida freshness/qualidade e APCR/ACIE fazem handoff.

## Onde Se Encaixa

```text
sources -> chunking -> embedding -> vector candidate set -> AHRI -> ACRS
```

## Contratos

- `atlas.aucri.embedding_document.v1`
- `atlas.aucri.embedding_chunk.v1`
- `atlas.aucri.embedding_candidate_set.v1`
- `atlas.aucri.embedding_index_receipt.v1`
- `atlas.aucri.embedding_delete_cascade.v1`

Campos: `source_ref`, `chunk_hash`, `embedding_provider`, `embedding_model`,
`privacy_class`, `authority_level`, `valid_from`, `delete_policy`,
`evidence_refs`.

## Fluxo

1. Classificar fonte e privacidade.
2. Chunking deterministico.
3. Gerar embedding local ou provider aprovado.
4. Persistir hash/model/version.
5. Indexar.
6. Consultar por similaridade.
7. Devolver candidatos, nao conclusoes.

## Regras para IA

- Nao embeddingar raw secret, PII ou conteudo privado sem redacao.
- Nao trocar provider sem migration/reindex plan.
- Nao remover delete cascade.
- Nao chamar ASEF de RAG completo.

## Escopo de Implementacao

Implementar service, index jobs, adapters, schema, tests, readiness e
certification. Reusar `semantic_notes`, `ai_attachment_index_entries` e stores
existentes quando possivel.

## Dependencias

AUCRI, Memory Retrieval, privacy policy, Evidence Ledger, Local RAG readiness.

## Evidencias

Readiness local, index receipts, tests de privacy, benchmark de golden set,
delete cascade testado.

## Riscos

Vazamento de dados, stale embeddings, custo alto, falso contexto parecido.

## Exemplos

Uma nota sobre Forge vira chunks provider-safe; uma busca sobre "handoff Obra"
retorna candidatos semanticamente relevantes com hashes e fonte.

## Proximas Acoes

1. Inventariar tabelas e providers existentes.
2. Definir chunking canonico.
3. Criar cert ASEF antes de ativar external embeddings.
