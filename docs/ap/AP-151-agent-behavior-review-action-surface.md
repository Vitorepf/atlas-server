# AP-151 — Agent Behavior Review Action Surface

Status: implemented-initial-review-surface

## Problema

AP-150 emitia findings `agent.*`, mas o operador e as surfaces de review ainda
podiam enxergar apenas o flag legado `verification_missing`. Isso escondia o
contrato, hash e evidencia especifica da violacao.

## Contrato

Quando `AiQualityActionService` criar acoes humanas relacionadas a qualidade, a
payload deve carregar `agent_behavior_findings` vindos de
`AiQualityEvaluation.metadata.evidence.agent_behavior_findings`.

Esse payload e somente review surface:

- nao reexecuta provider;
- nao altera routing;
- nao aplica patch;
- nao substitui Evidence Ledger;
- preserva findings `agent.*` estruturados para UI, CLI ou Inbox.

## Implementacao Inicial

`request_verification` e `operator_review` passam a carregar
`agent_behavior_findings` quando existirem. Isso permite Review Mode e Inbox
mostrar o checklist comportamental sem reparsear texto livre.

## Testes

- `AiQualityActionServiceTest::test_verification_action_carries_agent_behavior_findings_for_review`
- `KernelArchitectureStaticScanner::scanAgentBehaviorReviewActionSurface`
