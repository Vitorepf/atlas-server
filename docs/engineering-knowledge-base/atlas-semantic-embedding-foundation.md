---
id: atlas-semantic-embedding-foundation
type: engineering_knowledge
title: Atlas Semantic Embedding Foundation
status: building
implementation_state: building_manifest_readiness_runtime_real_provider_adapter_no_vector_store
blocker: ASEF possui manifest/readiness Laravel para chunks, hashes, privacy e candidate sets; embeddings reais passam por adapter governado (`semantic_rag`/OpenAI) e vector indexing final, rerank e delete cascade persistente continuam bloqueados para runtime python_ai_data governado.
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
  - app/Services/Ai/Context/AtlasSemanticEmbeddingFoundationService.php
  - app/Console/Commands/AtlasSemanticEmbeddingFoundationCommand.php
  - tests/Feature/Ai/Context/SemanticEmbeddingFoundationTest.php
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
graph_status: building
graph_source: repo
human_name: Atlas Semantic Embedding Foundation
canonical_name: Atlas Semantic Embedding Foundation
technical_name: AtlasSemanticEmbeddingFoundationService
cartography_type: module
canonical_source: docs/engineering-knowledge-base/atlas-semantic-embedding-foundation.md
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
  - app/Services/Ai/Context/AtlasSemanticEmbeddingFoundationService.php
  - app/Console/Commands/AtlasSemanticEmbeddingFoundationCommand.php
  - tests/Feature/Ai/Context/SemanticEmbeddingFoundationTest.php
evidence_refs:
  - symbol: EmbeddingService
  - command: atlas:semantic:embedding-info
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
  - "php artisan atlas:ai:local-rag-readiness --json"
  - "php artisan atlas:context:semantic-foundation --json"
  - "php artisan test tests/Feature/Ai/Context/SemanticEmbeddingFoundationTest.php"
requires_evidence: true
risk_level: high
line_limit: 520
next_actions:
  - Conectar candidate_set ASEF ao AHRI como input governado.
  - Promover vector indexing/rerank persistente somente com runtime python_ai_data, Decision Receipt e AP-201 verde.
---
# Atlas Semantic Embedding Foundation

## Resumo

ASEF e o bloco 1 da AUCRI. Ele transforma documentos, memorias, anexos e
artefatos em candidatos recuperaveis, com privacidade, versionamento, hashes e
delete keys. Ele nao decide contexto final.

## Papel no Atlas

Fornecer candidatos para retrieval. AHRI coleta esses candidatos, ACRS ranqueia,
ACFQ valida freshness/qualidade e APCR/ACIE fazem handoff.

Estado atual: `AtlasSemanticEmbeddingFoundationService` entrega manifest
deterministico, chunking, privacy gate, lexical signature provider-safe,
`delete_cascade_key`, readiness e comando
`php artisan atlas:context:semantic-foundation --json`. Ele nao escreve em
vector store nem fabrica embedding por hash; embeddings reais ficam no adapter
governado `EmbeddingService`/`SemanticRagRuntimeClient` ou OpenAI configurado.

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
- `atlas.aucri.semantic_embedding_foundation.v1`

Campos: `source_ref`, `chunk_hash`, `embedding_provider`, `embedding_model`,
`privacy_class`, `authority_level`, `valid_from`, `delete_policy`,
`evidence_refs`.

## Fluxo

1. Classificar fonte e privacidade.
2. Chunking deterministico.
3. Enviar embedding apenas para adapter real governado quando permitido.
4. Falhar explicitamente quando nao existir provider real.
5. Persistir hash/model/version somente no fluxo aprovado.
6. Indexar apenas via runtime python_ai_data governado.
7. Consultar por similaridade.
8. Devolver candidatos, nao conclusoes.

## Regras para IA

- Nao embeddingar raw secret, PII ou conteudo privado sem redacao.
- Nao trocar provider sem migration/reindex plan.
- Nao remover delete cascade.
- Nao chamar ASEF de RAG completo.

## Escopo de Implementacao

Implementar em duas camadas. Camada atual Laravel: manifest, chunking,
privacidade, hashes, readiness, candidate set sem escrita e adapter para
embedding real. Camada Python governada: `semantic_rag` para embeddings reais
hoje, e rerank/vector indexing/index receipts persistidos somente atras de
Decision Receipt. Reusar `semantic_notes`,
`ai_attachment_index_entries` e stores existentes quando possivel.

## Dependencias

AUCRI, Memory Retrieval, privacy policy, Evidence Ledger, Local RAG readiness.

## Evidencias

Readiness local, candidate set hash, tests de privacy, command JSON, futuros
index receipts, benchmark de golden set e delete cascade persistente testado.

## Riscos

Vazamento de dados, stale embeddings, custo alto, falso contexto parecido.

## Exemplos

Uma nota sobre Forge vira chunks provider-safe; uma busca sobre "handoff Obra"
retorna candidatos semanticamente relevantes com hashes e fonte.

## Proximas Acoes

1. Conectar AHRI ao candidate set ASEF.
2. Definir AP/Decision Receipt para vector indexing/rerank persistente.
3. Criar cert ASEF antes de ativar external embeddings fora do adapter atual.
