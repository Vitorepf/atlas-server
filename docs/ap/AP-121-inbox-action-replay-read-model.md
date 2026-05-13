# AP-121 — Inbox Action Replay Read Model

## Problema

AP-120 colocou actions humanas do Inbox no Evidence Ledger como
`INBOX_ACTION_RECORDED`, mas evidencia append-only so vira inteligencia quando
existe uma projecao de replay. Sem read model, Self-Improvement, auditoria e
observability precisariam consultar payload bruto e recriar regras locais.

## Contrato

`AtlasLedgerReplayService::inboxActionReportForWindow` e o read model canonico
para actions do Inbox. O evento fonte canonico e
`LedgerEventType::InboxActionRecorded`. Ele deve:

- ler somente eventos `INBOX_ACTION_RECORDED`;
- preservar schema `atlas.inbox_action.v1`;
- normalizar action, actor, item, categoria, severidade, source,
  `recommended_action`, status/severity do `review_signal` e contagem de refs;
- aceitar filtros por `action`, `actor_type`, `inbox_item_category`,
  `inbox_item_severity`, `recommended_action` e `source_type`;
- expor contagens por action, actor, categoria, severidade e recommended action;
- projetar action-specific safe fields para reviews governados, incluindo
  `record_rivals_review`, `configure_provider_cost_rates` e
  `review_retrieval_regression` e `review_retrieval_shadow_scope`, sem
  consultar payload bruto fora do replay;
- emitir review signal `review_patch_action_without_diff_refs` quando uma action
  `review_patch` nao trouxer `diff_refs`.
- emitir review signal `memory_retrieval_regression_review_recorded` quando
  `review_retrieval_regression` carregar marker `reviewed=true`; o replay deve
  projetar report hash, latest/previous snapshot hashes, decision receipt hash e
  `memory_write_allowed_now=false`; se o marker estiver ausente, sinalizar
  `review_retrieval_regression_action_without_review_marker`.
- emitir review signal `memory_retrieval_shadow_scope_review_recorded` quando
  `review_retrieval_shadow_scope` carregar marker `reviewed=true` e decision
  receipt hash; se o receipt estiver ausente, sinalizar
  `review_retrieval_shadow_scope_action_without_decision_receipt`; se qualquer
  evento declarar `shadow_execution_allowed_now=true`, sinalizar
  `retrieval_shadow_scope_review_allowed_runtime_execution`.

## Resultado

Actions como `review_patch` deixam de ser apenas resposta de UI e passam a ser
sinal operacional consultavel. Isso permite medir se revisoes humanas estao
preservando contexto suficiente para replay e para o dominio Self-Improvement.

## Testes

- `LedgerReplayServiceTest::test_inbox_action_window_report_projects_human_review_evidence`
- `LedgerReplayServiceTest::test_inbox_action_window_report_filters_and_warns_when_patch_review_lacks_diff_refs`
- `LedgerReplayServiceTest::test_inbox_action_window_report_projects_retrieval_regression_reviews`
- `LedgerReplayServiceTest::test_inbox_action_window_report_projects_retrieval_shadow_scope_decision_receipts`
- `LedgerReplayServiceTest::test_inbox_action_window_report_warns_when_shadow_scope_review_missing_receipt`
- `KernelArchitectureStaticScanner::scanInboxActionReplayReadModel`
