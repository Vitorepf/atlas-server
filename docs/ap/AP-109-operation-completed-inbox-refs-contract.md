# AP-109 — OperationCompleted Inbox Refs Contract

Status: implemented-terminal-run-link

## Objetivo

Preservar, no evento terminal do Self-Improvement, a lista de propostas emitidas
no Inbox durante o run.

## Contrato

`OPERATION_COMPLETED` emitido por `AtlasSelfImprovementRuntime` deve carregar:

```text
finding_count
emitted_count
emitted_inbox_item_ids
```

`emitted_inbox_item_ids` deve ser uma lista de IDs retornados pelo
`ProposalInboxEmitter`, na mesma semantica de `runtime.emitted_item_ids` e
`atlas_initiative_runs.emitted_inbox_item_ids`.

## Regra De Arquitetura

`LEARNING_PROPOSED` continua sendo o link fino por finding. `OPERATION_COMPLETED`
e o resumo terminal do run inteiro. Nenhum dos dois deve consultar o Inbox
depois; ambos preservam o que foi retornado no momento da emissao.

## Critérios De Aceite

- [x] `OPERATION_COMPLETED` grava `emitted_count`.
- [x] `OPERATION_COMPLETED` grava `emitted_inbox_item_ids`.
- [x] Teste feature cobre o link terminal para item emitido.
- [x] Architecture validate expoe
      `ap109_operation_completed_inbox_refs_contract`.
