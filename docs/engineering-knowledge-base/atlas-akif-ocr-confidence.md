---
title: Atlas AKIF OCR Confidence-Scored Ingestion
slug: atlas-akif-ocr-confidence
status: building
risk_level: medium
graph_parent: atlas-cognition-operating-system
depends_on:
  - atlas-cognition-operating-system
authority_class: ingestion
forbidden_changes:
  - confidence_outside_0_1_range
  - promote_low_confidence_silently
schema:
  - atlas.akif.ocr_confidence_artifact.v1
---

# Atlas AKIF OCR Confidence-Scored Ingestion

Wrapper canônico de confidence para artifacts OCR / transcrição. Não embute engine OCR — recebe `content + confidence` do engine que o operador usar (whisper.cpp, Tesseract, etc) e produz envelope auditável.

## Confidence buckets
- `high` ≥ 0.85
- `medium` ≥ 0.6
- `low` < 0.6

## Source kinds
`ocr | audio_transcription | video_transcription | image_caption | pdf_extraction`

## Invariantes
- confidence ∈ [0,1]
- Content > 280 chars → preview truncado + content_hash (auditable mas bounded)
- promotable = confidence ≥ medium

## API
```php
record(array $input): array
listArtifacts(int $limit=100): array
listPromotable(int $limit=100): array
```

## CLI
`php artisan atlas:akif:ocr --action=record --input-json='{...}'`
