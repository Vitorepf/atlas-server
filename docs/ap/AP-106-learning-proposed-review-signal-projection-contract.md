# AP-106 — LearningProposed Review Signal Projection Contract

Status: implemented-ledger-projection

## Objetivo

Preservar os sinais de revisao dos findings do Self-Improvement dentro do
Evidence Ledger. O evento `LEARNING_PROPOSED` nao deve reduzir um finding rico a
titulo, dedupe e confidence quando o Curator precisa de `review_signal` para
replay, auditoria e priorizacao.

## Contrato

`ledgerFindingProjection()` deve gravar:

```text
finding.schema_version
finding.review_signal
finding.source_types
finding.source_ref_count
finding.dedupe_key
finding.confidence
```

O ledger continua recebendo uma projection compacta, nao o payload inteiro do
finding. A regra e preservar somente campos necessarios para replay/auditoria e
decisao posterior.

## Critérios De Aceite

- [x] `LEARNING_PROPOSED` preserva `schema_version` quando existir em metadata.
- [x] `LEARNING_PROPOSED` preserva `review_signal` quando existir em metadata.
- [x] `LEARNING_PROPOSED` preserva tipos de fonte deduplicados.
- [x] O teste feature cobre o caso Open Brain retrieval -> Self-Improvement ->
      Ledger.
- [x] Architecture validate expoe
      `ap106_learning_proposed_review_signal_projection_contract`.
