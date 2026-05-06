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
- emitir review signal `review_patch_action_without_diff_refs` quando uma action
  `review_patch` nao trouxer `diff_refs`.

## Resultado

Actions como `review_patch` deixam de ser apenas resposta de UI e passam a ser
sinal operacional consultavel. Isso permite medir se revisoes humanas estao
preservando contexto suficiente para replay e para o dominio Self-Improvement.

## Testes

- `LedgerReplayServiceTest::test_inbox_action_window_report_projects_human_review_evidence`
- `LedgerReplayServiceTest::test_inbox_action_window_report_filters_and_warns_when_patch_review_lacks_diff_refs`
- `KernelArchitectureStaticScanner::scanInboxActionReplayReadModel`
