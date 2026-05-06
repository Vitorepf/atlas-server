# AP-105 — Open Brain Retrieval Self-Improvement Contract

Status: implemented-curator-input

## Objetivo

Transformar `review_signal` de retrieval do Open Brain em insumo real para
Self-Improvement/Curator. Uma fonte obrigatoria ausente nao deve morrer no
retorno imediato da injecao; ela deve virar finding deduplicado, auditavel e
revisavel.

## Contrato

`AtlasSelfImprovementRuntime` deve ler `atlas_open_brain_access_logs` na janela
configurada e detectar:

```text
summary.retrieval_plan.review_signal.status = blocking
warnings contains retrieval_required_source_unavailable
```

O finding gerado deve usar:

```text
dedupe_key: self-improvement:open-brain-retrieval:<hash>
metadata.schema_version: atlas.self_improvement.open_brain_retrieval.v1
metadata.review_signal.status: blocking
metadata.review_signal.severity: high
metadata.required_unavailable_source_counts
metadata.recommended_action_counts
source_refs[].type: open_brain_access_log
```

## Regra De Arquitetura

Self-Improvement nao recalcula disponibilidade de retrieval. Ele consome o
resumo auditado pelo Open Brain e apenas agrega evidencias recentes em proposta
revisavel. A fonte da verdade continua sendo o pipeline `Context Builder -> Open
Brain -> Evidence/Audit -> Learning`.

## Critérios De Aceite

- [x] `AtlasSelfImprovementRuntime` consome `AtlasOpenBrainAccessLog`.
- [x] `retrieval_required_source_unavailable` vira finding do Curator.
- [x] O finding preserva `recommended_action` e fontes obrigatorias ausentes.
- [x] O teste feature cobre o fluxo de `evidence_replay` ausente.
- [x] Architecture validate expoe
      `ap105_open_brain_retrieval_self_improvement_contract`.
