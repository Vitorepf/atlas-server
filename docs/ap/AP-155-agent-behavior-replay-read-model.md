# AP-155 — Agent Behavior Replay Read Model

Status: implemented-read-model

## Problema

AP-154 grava findings comportamentais no Evidence Ledger, mas sem read model uma
IA ou humano teria que consultar eventos crus para enxergar recorrencia,
providers afetados e necessidade de review.

## Contrato

`AtlasLedgerReplayService::agentBehaviorReportForWindow()` deve projetar eventos
`GATE_EVALUATED` emitidos por `atlas.agent_behavior_quality_gate`.

O read model deve expor:

- `agent_behavior_event_count`;
- `finding_count`;
- `finding_code_counts`;
- `finding_severity_counts`;
- `provider_counts`;
- `agent_slug_counts`;
- `average_score`;
- `review_signal`;
- `recent_events`;
- filtros por `status`, `provider`, `model`, `agent_slug`, `finding_code` e
  `contract_id`.

Findings recorrentes devem produzir `review_signal.recommended_action =
open_reviewable_agent_behavior_quality_proposal`.

## Nao Objetivos

- Nao criar proposta automaticamente.
- Nao acionar provider.
- Nao substituir `AiQualityEvaluator` ou `AgentBehaviorQualityGate`.

## Verificacao

- `LedgerReplayServiceTest::test_agent_behavior_window_report_projects_gate_findings`
- `KernelArchitectureStaticScanner::scanAgentBehaviorReplayReadModel`
