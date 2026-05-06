# AP-107 — Proposal Inbox Review Signal Contract

Status: implemented-inbox-projection

## Objetivo

Preservar o contrato de revisao quando um finding do Curator vira proposta no
Inbox. A proposta precisa carregar o mesmo `review_signal` que foi gravado no
Ledger, para que App, thread de discussao e operador entendam por que aquilo
exige revisao.

## Contrato

`ProposalInboxEmitter` deve gravar em `ai_inbox_items.payload` e em
`ai_context_bundles.raw_payload`:

```text
proposal_contract.schema_version
proposal_contract.review_signal
proposal_contract.source_refs
proposal_contract.trace_refs
proposal_contract.job_refs
proposal_contract.file_refs
proposal_contract.diff_refs
```

## Regra De Arquitetura

O Inbox nao recalcula severidade, acao recomendada ou fonte ausente. Ele apenas
preserva o contrato recebido do Curator/Self-Improvement e continua exigindo
revisao humana antes de qualquer commit, merge ou mudanca critica.

## Critérios De Aceite

- [x] Payload do Inbox preserva `proposal_contract.review_signal`.
- [x] Raw payload do Context Bundle preserva `proposal_contract.review_signal`.
- [x] Refs de origem ficam disponiveis para discussao/replay.
- [x] Teste unitario cobre schema, review signal e source refs.
- [x] Architecture validate expoe
      `ap107_proposal_inbox_review_signal_contract`.
