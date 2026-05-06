# AP-144 — Rivals Review Inbox Action Contract

Status: ativo

## Problema

Revisitas do Rivals Strategy podem chegar ao Inbox como proposta acionavel, mas
sem contrato executavel elas viram lembrete solto. Isso quebra o ciclo de
evidencia que sustenta P4+: decisao estrategica, revisita humana, score de
arrependimento, alinhamento, agencia e replay auditavel.

## Contrato

- Self-Improvement deve emitir `available_actions[]=record_rivals_review` com
  `due_reviews[]` e comando seguro de registro.
- `InboxActionRegistry` deve aceitar `record_rivals_review` somente quando a
  action estiver disponivel no item.
- A action deve chamar `AtlasRivalsStrategyReviewRecorder` com scores humanos.
- A action deve gravar `atlas.inbox_action.rivals_review.v1`.
- A action deve declarar `operator_scored=true` e `no_external_action=true`.
- A action deve registrar `INBOX_ACTION_RECORDED`.
- `inboxActionReportForWindow` deve projetar review id, case id, horizonte e
  scores.
- CLI, API, MCP e Observability devem herdar a projeção pelo read model
  canonico, sem parsing local.

## Evidencia

- `InboxLedgerProjectionActionTest::test_inbox_action_records_rivals_review_with_human_scores_and_ledger_evidence`
- `LedgerReplayServiceTest::test_inbox_action_window_report_projects_rivals_review_scores`
- `AtlasAiInboxActionReportCommandTest::test_command_exposes_rivals_review_scores_as_json`
- `AtlasAiInboxActionReportApiTest::test_inbox_action_report_api_exposes_rivals_review_scores`
- `AiObservabilityKernelSloTest::test_observability_payload_exposes_rivals_review_inbox_action_scores`
- `AtlasOpenBrainMcpServiceTest::test_inbox_action_report_tool_exposes_rivals_review_scores`

## Review Signal

Quando os tres scores existem, o replay publica
`rivals_strategy_human_scores_recorded` e `recommended_action=none`. Se a action
existir sem scores, o replay deve exigir proposta revisavel.
