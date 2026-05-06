# AP-108 — LearningProposed Inbox Link Contract

Status: implemented-ledger-to-inbox-link

## Objetivo

Criar rastreabilidade direta entre um finding do Self-Improvement gravado no
Evidence Ledger e a proposta revisavel emitida no Inbox.

## Contrato

Quando `AtlasSelfImprovementRuntime::nightlyReview(..., emit: true)` emitir uma
proposta, o evento `LEARNING_PROPOSED` deve carregar:

```text
emitted: true
emitted_to_inbox: true
emitted_inbox_item_id: <ai_inbox_items.id>
finding.dedupe_key
```

Quando o Inbox estiver indisponivel ou o emitter retornar `null`, o evento deve
continuar sendo gravado com `emitted_to_inbox=false` e
`emitted_inbox_item_id=null`.

## Regra De Arquitetura

O mapeamento e feito por `dedupe_key`, porque o dedupe e o identificador estavel
do finding/proposta. O ledger nao deve consultar o Inbox depois; ele registra o
ID retornado no momento da emissao.

## Critérios De Aceite

- [x] Runtime preserva mapa `dedupe_key -> inbox_item_id`.
- [x] `LEARNING_PROPOSED` grava `emitted_to_inbox`.
- [x] `LEARNING_PROPOSED` grava `emitted_inbox_item_id`.
- [x] Teste feature cobre emissão real via `ProposalInboxEmitter` mockado.
- [x] Architecture validate expoe
      `ap108_learning_proposed_inbox_link_contract`.
