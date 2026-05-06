# AP-122 — Inbox Action MCP Report

## Problema

Depois do AP-121, o Atlas tem `inboxActionReportForWindow`, mas agentes e outras
sessoes ainda precisariam acessar banco, API interna ou payload bruto para saber
se actions humanas do Inbox foram registradas corretamente.

## Contrato

Open Brain MCP deve expor `atlas_inbox_action_report` como tool read-only. O
tool deve:

- chamar `inboxActionReportForWindow`;
- retornar o payload em `inbox_actions`;
- aceitar `hours` com a janela canonica dos reports de replay;
- aceitar filtros escalares por `action`, `actor_type`, `inbox_item_category`,
  `inbox_item_severity`, `recommended_action` e `source_type`;
- preservar `writes=false`;
- preservar fallback `wait_for_inbox_action_evidence` quando o Ledger estiver
  indisponivel.

## Resultado

Actions como `review_patch` ficam consultaveis pelo Open Brain sem duplicar
parse de payload. Isso torna revisao humana do Inbox uma fonte de evidencia
para agentes, auditoria, Self-Improvement e futuras automacoes.

## Testes

- `AtlasOpenBrainMcpServiceTest::test_inbox_action_report_tool_exposes_replay_read_model`
- `AtlasOpenBrainMcpServiceTest::test_replay_report_tools_preserve_review_signal_when_ledger_is_unavailable`
- `AtlasOpenBrainMcpServiceTest::test_replay_report_tools_use_canonical_mcp_hours_window`
- `AtlasOpenBrainMcpServiceTest::test_replay_report_tools_use_canonical_scalar_filter_contract`
- `KernelArchitectureStaticScanner::scanInboxActionMcpReport`
