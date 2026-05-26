---
id: atlas-akif-ocr-confidence
type: engineering_knowledge
doc_schema: atlas_canonical_module_doc.v1
title: Atlas AKIF OCR Confidence-Scored Ingestion
slug: atlas-akif-ocr-confidence
status: building
implementation_state: runtime_available_wrapper
category: cognition
priority: 87
summary: Wrapper de confidence para artifacts OCR/transcricao que produz envelopes auditaveis sem embutir motor OCR ou promover baixa confianca silenciosamente.
tags: [atlas-ai, akif, ingestion, ocr, confidence]
capabilities: [ocr_confidence_artifact, confidence_bucket_gate, promotable_ingestion_filter]
decisions:
  - AKIF-OCR nao embute OCR engine; recebe content e confidence do engine escolhido pelo operador.
  - Conteudo grande fica limitado por preview e hash.
  - Promocao automatica exige confidence media ou alta.
maintenance:
  - Atualizar antes de mudar confidence buckets, source kinds, preview cap ou storage.
  - Manter confidence dentro de [0,1] coberto por teste.
risk_level: medium
owner: atlas-ai
graph_id: atlas-akif-ocr-confidence
graph_title: Atlas AKIF OCR Confidence-Scored Ingestion
graph_world: atlas
graph_layer: module
graph_kind: module
graph_parent: atlas-cognition-operating-system
graph_status: building
graph_source: repo
depends_on: [atlas-cognition-operating-system, atlas-knowledge-ingestion-fabric]
flows_to: [atlas-knowledge-ingestion-fabric]
unlocks: [confidence_scored_ocr_ingestion, bounded_transcription_artifact]
governs: [akif_ocr_confidence_artifacts]
authority_class: ingestion
related_paths:
  - docs/engineering-knowledge-base/atlas-akif-ocr-confidence.md
  - docs/engineering-knowledge-base/atlas-knowledge-ingestion-fabric.md
  - app/Services/Ai/Knowledge/AtlasKnowledgeIngestionFabricOcrConfidenceService.php
  - app/Console/Commands/AtlasAkifOcrCommand.php
  - tests/Unit/Ai/Knowledge/AtlasKnowledgeIngestionFabricOcrConfidenceServiceTest.php
repo_paths:
  - docs/engineering-knowledge-base/atlas-akif-ocr-confidence.md
  - app/Services/Ai/Knowledge/AtlasKnowledgeIngestionFabricOcrConfidenceService.php
evidence:
  - app/Services/Ai/Knowledge/AtlasKnowledgeIngestionFabricOcrConfidenceService.php
  - app/Console/Commands/AtlasAkifOcrCommand.php
  - tests/Unit/Ai/Knowledge/AtlasKnowledgeIngestionFabricOcrConfidenceServiceTest.php
required_tests:
  - "php artisan test tests/Unit/Ai/Knowledge/AtlasKnowledgeIngestionFabricOcrConfidenceServiceTest.php"
  - "php artisan atlas:engineering:knowledge docs-health --json"
next_actions:
  - Integrar consumidores de promocao AKIF sem mover ownership do ingestion fabric.
allowed_changes:
  - Add source adapters that submit content and confidence into this wrapper.
forbidden_changes:
  - confidence_outside_0_1_range
  - promote_low_confidence_silently
requires_evidence: true
line_limit: 520
schema:
  - atlas.akif.ocr_confidence_artifact.v1
---

# Atlas AKIF OCR Confidence-Scored Ingestion

## Resumo

Wrapper canonico de confidence para OCR, transcricao, captions e extracao de PDF.

## Papel no Atlas

Registrar artifact de ingestion com confidence e hash para evitar que texto incerto vire conhecimento confiavel.

## Onde Se Encaixa

Fica antes da promocao no Knowledge Ingestion Fabric.

## Contratos

Schema `atlas.akif.ocr_confidence_artifact.v1`.

## Fluxo

Engine externo produz content/confidence -> `record()` valida -> artifact append-only -> `listPromotable()` filtra confidence media/alta.

## Regras para IA

Nao declarar OCR como confiavel sem confidence. Nao embutir engine OCR neste service.

## Escopo de Implementacao

Service, comando e teste unitario existem.

## Dependencias

ACOS e AKIF.

## Evidencias

`AtlasKnowledgeIngestionFabricOcrConfidenceService`, `AtlasAkifOcrCommand` e teste unitario.

## Riscos

Promover baixa confianca para memoria/contexto como se fosse fato.

## Exemplos

`php artisan atlas:akif:ocr --action=record --input-json='{"source_kind":"ocr","content":"...","confidence":0.91}' --json`

## Proximas Acoes

Conectar adapters reais de OCR/transcricao preservando confidence e preview bounded.

## Confidence Buckets

- `high` quando confidence >= 0.85
- `medium` quando confidence >= 0.6
- `low` quando confidence < 0.6
