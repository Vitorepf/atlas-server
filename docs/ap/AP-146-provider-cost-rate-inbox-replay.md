# AP-146 — Provider Cost Rate Inbox Replay Contract

Status: implemented-operational-contract

## Problema

Findings de custo desconhecido podem ser resolvidos pelo operador no Inbox com
`configure_provider_cost_rates`, mas outra IA precisa enxergar pelo replay se a
pendencia foi realmente fechada ou se houve apenas preview/template.

Este AP pertence ao ciclo AP-99/model selection/cost governance: AP-99 detecta
`unknown_cost_count`, model selection nao inventa preco, o Curator abre acao
assistida, e o replay prova se a tabela de rates recebeu valores humanos.

## Contrato

`configure_provider_cost_rates` deve:

- aceitar provider/model e rates informados pelo operador;
- gravar `atlas.inbox_action.provider_cost_rates.v1` no item e no ledger;
- aplicar rates em `ai_provider_cost_rates` somente quando input/output existem;
- manter preview sem resolver o item quando os rates ainda estao ausentes;
- nunca consultar internet, calcular preco sozinho ou trocar provider/modelo.

O caminho operacional tambem deve ser descobrivel pelo
`AtlasArchitectureOperationsCatalog`, para que AP-99/cost governance nao vire
conhecimento tribal:

- `provider_cost_rates_missing`:
  `atlas ai telemetry cost-rates --missing --hours=168 --json`
- `provider_cost_rates_upsert`:
  `atlas ai telemetry cost-rates --provider=<provider> --model=<model> --input-microusd=<input> --output-microusd=<output> --json`

Essas operacoes sao surfaces de governanca/review. Elas nao escolhem provider,
nao alteram `DecisionReceipt` e nao bypassam Atlas Decide.

`AtlasLedgerReplayService::inboxActionReportForWindow` deve projetar:

- `provider_cost_rate_action_count`;
- `provider_cost_rate_applied_count`;
- `provider_cost_rate_provider_counts`;
- `provider_cost_rate_model_counts`;
- provider, model, currency, input/output microusd, rate id e applied por evento;
- `provider_cost_rates_configured` quando todos os eventos aplicaram rate;
- `configure_provider_cost_rates_action_without_applied_rate` quando houve
  preview sem rate aplicado.

`AtlasSelfImprovementRuntime` deve consumir somente
`inboxActionReportForWindow` para detectar
`configure_provider_cost_rates_action_without_applied_rate` e gerar finding
revisavel para o humano completar os rates via `configure_provider_cost_rates`.
O Curator nunca aplica rate automaticamente. A finding deve preservar
`atlas.self_improvement.inbox_action_replay_gap.v1`, dedupe estavel e
`source_refs` com event_id, inbox_item_id, provider, model, applied,
input/output microusd e occurred_at.

## Resultado

O loop de custo fica auditavel: Curator aponta o gap, Inbox permite a correcao
humana, Evidence Ledger registra a action e replay/CLI/API/MCP/Observability
mostram se a pendencia foi fechada de fato.

O output humano de `atlas:ai:inbox-action-report` tambem deve expor um resumo
condicional de Provider Cost Rates quando houver `configure_provider_cost_rates`,
incluindo actions totais, actions aplicadas, contagens por provider/model e o
review signal/severity/recommended action do replay. A visao humana tambem
expõe review_required, completion aplicada/total, pendencias abertas e lista os
eventos recentes de cost-rate com provider, model, applied, currency,
effective_from/effective_until, rate id e rates input/output ja projetados pelo
replay, sem alterar o contrato JSON.

## Testes

- `InboxLedgerProjectionActionTest::test_inbox_action_configures_provider_cost_rates_with_human_supplied_rates_and_ledger_evidence`
- `InboxLedgerProjectionActionTest::test_inbox_action_accepts_zero_provider_cost_rates_without_external_lookup`
- `InboxLedgerProjectionActionTest::test_inbox_action_rejects_negative_provider_cost_rates_as_preview_only`
- `InboxLedgerProjectionActionTest::test_inbox_action_previews_provider_cost_rate_template_without_resolving_item`
- `LedgerReplayServiceTest::test_inbox_action_window_report_projects_provider_cost_rate_actions`
- `LedgerReplayServiceTest::test_inbox_action_window_report_warns_when_provider_cost_rate_action_is_only_previewed`
- `LedgerReplayServiceTest::test_inbox_action_window_report_keeps_provider_cost_rate_warning_in_mixed_windows`
- `AtlasAiInboxActionReportCommandTest::test_command_human_output_includes_provider_cost_rate_summary_when_configured`
- `AtlasAiInboxActionReportCommandTest::test_command_human_output_flags_provider_cost_rate_preview_without_applied_rate`
- `AtlasAiInboxActionReportCommandTest::test_command_human_output_summarizes_mixed_provider_cost_rate_completion`
- `AtlasSelfImprovementRuntimeTest::test_self_improvement_detects_provider_cost_rate_action_without_applied_rate`
- `AtlasSelfImprovementRuntimeTest::test_self_improvement_does_not_flag_provider_cost_rate_action_when_rate_was_applied`
- `KernelArchitectureStaticScanner::scanProviderCostRateInboxReplay`
