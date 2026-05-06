# AP-137 — Decision Receipt Replay Surfaces

## Problema

AP-136 criou o read model de replay da cadeia de `DecisionReceipt`, mas o
operador ainda precisava consumir o serviço por código. Isso deixava auditoria
de receipt fora do mesmo padrão profissional usado por SLO, Repair Loop,
Provider Performance e Inbox Action.

## Contrato

- `atlas:ai:decision-receipt-report --envelope=<id> --json` deve expor
  `AtlasLedgerReplayService::decisionReceiptReportForEnvelope()`.
- `/ai/decision-receipts/report?envelope=<id>` deve expor o mesmo payload com
  autenticação `atlas.token`.
- Open Brain MCP deve publicar `atlas_decision_receipt_report` read-only.
- `AtlasArchitectureOperationsCatalog` deve listar `decision_receipt_report`
  como operacao `evidence_report`.
- O scanner deve publicar `ap137_decision_receipt_replay_surfaces`.

## Evidencia

- `AtlasAiDecisionReceiptReportCommandTest`
- `AtlasAiDecisionReceiptReportApiTest`
- `AtlasOpenBrainMcpServiceTest::test_decision_receipt_report_replays_receipt_chain_for_envelope`
- `atlas:ai:architecture-validate --json`

## Resultado

Replay de DecisionReceipt virou surface operacional de primeira classe. CLI,
API e MCP agora enxergam o mesmo read model, com `receipt_hash`, `chain_hash`,
`review_signal` e cadeia de receipts auditavel por envelope.
