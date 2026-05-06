# AP-142 — Ledger Projection Inbox Action

## Problema

`ledger_projection_health.review_signal` conseguia dizer que havia drift ou
atraso entre o Evidence Ledger e os read models, mas o operador ainda precisava
sair do fluxo do Inbox para rodar `atlas:ai:ledger-project`.

## Contrato

- `InboxActionRegistry` deve implementar `run_ledger_projection`.
- A action so pode executar quando o item declarar
  `available_actions[].id=run_ledger_projection`.
- A action deve aceitar janela, limite e `dry_run` por input ou payload.
- A action deve chamar `LedgerProjectionWorker`, nao duplicar projection logic.
- A action deve gravar `payload.ledger_projection_action` com schema
  `atlas.inbox_action.ledger_projection.v1`.
- A action so resolve o item quando o backfill real aplicar ao menos um read
  model; `dry_run` apenas marca o item como lido.
- Toda execucao deve gravar `INBOX_ACTION_RECORDED`, preservando resultado,
  comando, `review_signal` e `recommended_action`.
- `atlas:cli:inbox respond` deve expor `--projection-hours`,
  `--projection-limit` e `--dry-run`.

## Enforcement

- Scanner: `ap142_ledger_projection_inbox_action`.
- Teste de execucao real:
  `tests/Feature/Ai/InboxLedgerProjectionActionTest.php`.
- Doc canonico:
  `docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md`.
