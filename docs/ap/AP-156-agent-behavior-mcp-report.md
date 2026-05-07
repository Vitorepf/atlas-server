# AP-156 — Agent Behavior MCP Report

Status: implemented-mcp-report

## Problema

AP-155 criou o read model de comportamento do agente, mas providers e novas
sessoes de IA precisam consultar esse estado pelo Open Brain MCP sem acesso
direto ao banco.

## Contrato

`AtlasOpenBrainMcpService` deve expor a tool read-only
`atlas_agent_behavior_report`.

A tool deve aceitar:

- `hours`;
- `status`;
- `provider`;
- `model`;
- `agent_slug`;
- `finding_code`;
- `contract_id`.

A resposta deve conter:

- `tool = atlas_agent_behavior_report`;
- `writes = false`;
- `filters`;
- `agent_behavior` com o payload de
  `AtlasLedgerReplayService::agentBehaviorReportForWindow()`.

## Nao Objetivos

- Nao criar action ou proposta automaticamente.
- Nao acionar provider.
- Nao substituir `atlas_kernel_slo_report` ou `atlas_inbox_action_report`.

## Verificacao

- `AtlasOpenBrainMcpServiceTest::test_agent_behavior_report_summarizes_gate_findings`
- `KernelArchitectureStaticScanner::scanAgentBehaviorMcpReport`
