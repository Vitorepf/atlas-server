# AP-124 — Observability Inbox Action Replay

## Problema

Actions humanas do Inbox ja entram no Evidence Ledger, possuem read model,
aparecem no MCP e sao consumidas pelo Self-Improvement. O App/API de
observability ainda nao mostrava esse sinal no payload operacional principal.

## Contrato

`/ai/observability` deve expor `inbox_actions` usando
`inboxActionReportForWindow($since)`. O payload deve preservar:

- disponibilidade do Ledger;
- contagens de actions, actors, categorias e recommended actions;
- `reviewed_patch_count` e `with_diff_refs_count`;
- `recent_events`;
- review signal `open_reviewable_inbox_action_evidence_proposal` quando existir
  `review_patch_action_without_diff_refs`.

## Resultado

O App e qualquer dashboard que usa observability enxergam o mesmo sinal de
revisao humana que MCP, replay e Curator. Isso evita duplicacao de leitura do
Inbox e mantem o Evidence Ledger como fonte unica.

## Testes

- `AiObservabilityKernelSloTest::test_observability_payload_includes_inbox_action_replay_summary`
- `KernelArchitectureStaticScanner::scanObservabilityInboxActionReplay`
