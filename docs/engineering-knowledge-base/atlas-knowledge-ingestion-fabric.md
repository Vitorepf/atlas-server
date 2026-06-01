---
id: atlas-knowledge-ingestion-fabric
type: engineering_knowledge
doc_schema: atlas_canonical_module_doc.v1
title: Atlas Knowledge Ingestion Fabric
status: active
implementation_state: runtime_surface_source_packet_ready
blocker: Ingestion persistente/jobs reais ainda dependem de ACMF/ACCR/ATER; runtime AKIF read-only ja normaliza source packet, lineage, privacy gate e receipts.
category: context_retrieval_intelligence
priority: 95
summary: "Fabric de ingestao para transformar documentos, videos, repos, planilhas, imagens e dados externos em fontes normalizadas, versionadas e auditaveis."
tags: [atlas-ai, aucri, akif, knowledge-ingestion, source-packet, lineage]
capabilities: [knowledge_ingestion, source_packet, lineage, normalization, dedupe]
decisions:
  - Ingestion sem lineage nao pode alimentar AUCRI.
  - OCR/transcricao precisam de confidence e fonte original.
  - AKIF normaliza fonte antes de embedding, graph e retrieval.
maintenance:
  - Atualizar antes de adicionar novo adapter de fonte, parser, OCR ou transcript flow.
product_name: Atlas Knowledge Ingestion Fabric
runtime_acronym: AKIF
internal_product_name: Atlas Knowledge Intake
technical_runtime: AtlasKnowledgeIngestionFabricService
macro_layer: true
graph_id: atlas-knowledge-ingestion-fabric
graph_title: Atlas Knowledge Ingestion Fabric
graph_world: atlas
graph_parent: atlas-unified-context-retrieval-intelligence
graph_layer: module
graph_kind: module
graph_status: planned
graph_source: repo
owner: atlas-ai
repo_paths:
  - docs/engineering-knowledge-base/atlas-unified-context-retrieval-intelligence.md
  - docs/engineering-knowledge-base/atlas-retrieval-privacy-trust-layer.md
related_paths:
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
  - app/Services/Ai/Context/AtlasKnowledgeIngestionFabricService.php
  - app/Console/Commands/AtlasKnowledgeIngestionFabricCommand.php
  - tests/Feature/Ai/Context/KnowledgeIngestionFabricTest.php
allowed_changes:
  - Criar adapters de ingestion por tipo de fonte.
  - Normalizar source packets com lineage, hash e privacy status.
forbidden_changes:
  - Indexar fonte sem lineage.
  - Tratar OCR/transcricao como verdade sem confidence.
depends_on:
  - atlas-retrieval-privacy-trust-layer
  - atlas-semantic-embedding-foundation
flows_to:
  - atlas-hybrid-retrieval-infrastructure
  - atlas-unified-reality-graph
unlocks: [normalized_knowledge_ingestion, source_lineage]
governs: [ingestion_jobs, source_packets, normalization_receipts]
evidence:
  - docs/engineering-knowledge-base/atlas-knowledge-ingestion-fabric.md
evidence_refs:
  - symbol: AtlasKnowledgeIngestionFabricService
  - command: atlas:context:knowledge-ingestion
  - test: AtlasKnowledgeIngestionFabricServiceTest
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
  - "php artisan atlas:context:knowledge-ingestion --json"
  - "php artisan test tests/Feature/Ai/Context/KnowledgeIngestionFabricTest.php"
requires_evidence: true
risk_level: high
line_limit: 520
next_actions:
  - Mapear ingestion existente e definir source packet canonico.
---

# Atlas Knowledge Ingestion Fabric

## Resumo

AKIF e a porta de entrada de conhecimento para AUCRI. Ele transforma arquivos,
links, videos, imagens, repositorios, planilhas e dados externos em pacotes de
fonte normalizados, versionados, deduplicados e auditaveis.

## Papel no Atlas

Retrieval bom depende de ingestion boa. Se o Atlas ingere mal um PDF, YouTube,
repo ou planilha, todos os flows posteriores recebem contexto errado.

## Onde Se Encaixa

Fica antes de ASEF, AHRI e AURG. ARPTL avalia privacidade antes de indexacao;
AKIF cria source packets para chunking, embedding, graph e retrieval.

## Contratos

- `atlas.knowledge.source_packet.v1`
- `atlas.knowledge.ingestion_job.v1`
- `atlas.knowledge.normalization_receipt.v1`
- `atlas.knowledge.lineage_ref.v1`

Campos minimos: `source_type`, `origin_uri`, `source_hash`, `version_hash`,
`normalized_text_ref`, `media_refs`, `language`, `confidence`, `lineage`,
`privacy_status`, `ingestion_status`, `receipt_hash`.

## Fluxo

1. Receber fonte de rich input, sync, repo, API ou operador.
2. Detectar tipo e idioma.
3. Aplicar ARPTL antes de persistir/indexar.
4. Extrair texto, imagens, tabelas, transcricao ou metadados.
5. Deduplicar por hash e lineage.
6. Emitir source packet para ASEF/AHRI/AURG.

## Regras para IA

- Nao indexar sem `source_hash`.
- Nao misturar transcricao automatica com documento oficial sem confidence.
- Nao perder idioma original.
- Nao apagar lineage ao resumir.

## Escopo de Implementacao

Implementado em `AtlasKnowledgeIngestionFabricService` como runtime read-only:
adapters canonicos para text, PDF, image/OCR, YouTube/transcript, repo file,
spreadsheet e URL; source packet, lineage refs, receipt, privacy gate e command.

## Dependencias

Depende de ARPTL para trust gate e de ASEF para chunking/index posterior.

## Evidencias

Evidencia atual: `atlas:context:knowledge-ingestion --json`, source packet com
lineage, normalization receipt, confidence, privacy status e tests de text,
YouTube, repo/url e segredo bloqueado por ARPTL.

## Riscos

- OCR ruim virar memoria falsa.
- YouTube sem legenda virar resumo inventado.
- Dedupe agressivo remover versao nova.
- Fonte externa mudar sem freshness update.

## Exemplos

Um video do YouTube em idioma estrangeiro deve preservar idioma original,
transcricao, traducao se houver, confidence e link para o video. O retrieval
deve saber se a fonte e transcricao oficial, ASR local ou fallback.

## Proximas Acoes

1. Enforcar AKIF no rich input, YouTube e ingestion jobs reais.
2. Conectar source packets com ASEF/AHRI/AURG.
3. Persistir job/receipt quando ACMF/ACCR estiverem prontos.
4. Manter fixtures de idioma, confidence, lineage e privacy verdes.
