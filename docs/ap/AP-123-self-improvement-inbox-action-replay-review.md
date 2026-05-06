# AP-123 — Self-Improvement Inbox Action Replay Review

## Problema

AP-120, AP-121 e AP-122 tornam actions humanas do Inbox auditaveis, projetaveis
e consultaveis. Ainda faltava o Curator consumir esse sinal para abrir melhoria
quando a propria revisao humana perde contexto operacional.

## Contrato

`AtlasSelfImprovementRuntime::inboxActionReplayFindings` deve:

- chamar `inboxActionReportForWindow`;
- respeitar filtros canonicos de Inbox action;
- ignorar Ledger indisponivel ou review signal sem `review_required`;
- criar finding quando o replay detectar `review_patch_action_without_diff_refs`;
- usar schema `atlas.self_improvement.inbox_action_replay_gap.v1`;
- preservar source refs dos eventos `INBOX_ACTION_RECORDED`;
- recomendar `open_reviewable_inbox_action_evidence_proposal`.

## Resultado

O fluxo fica fechado:

`review_patch` -> `INBOX_ACTION_RECORDED` -> `inboxActionReportForWindow` ->
`inboxActionReplayFindings` -> proposal revisavel.

Assim o Atlas aprende quando a revisao humana aconteceu sem contexto suficiente
de patch, em vez de deixar esse problema escondido em payload bruto.

## Testes

- `AtlasSelfImprovementRuntimeTest::test_self_improvement_detects_inbox_action_replay_patch_review_gap`
- `KernelArchitectureStaticScanner::scanSelfImprovementInboxActionReplayReview`
