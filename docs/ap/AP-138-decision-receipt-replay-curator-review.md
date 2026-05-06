# AP-138 — Decision Receipt Replay Curator Review

## Problema

AP-137 tornou o replay de `DecisionReceipt` visivel por CLI/API/MCP, mas ainda
faltava fechar o loop de aprendizado. Uma divergencia de `receipt_hash` ou
`chain_hash` podia aparecer no relatorio e nao virar proposta revisavel no
Self-Improvement/Curator.

## Contrato

- `AtlasSelfImprovementRuntime` deve consumir
  `AtlasLedgerReplayService::decisionReceiptReportForEnvelope()`.
- `weekly_architecture_audit` e o fluxo default devem chamar
  `decisionReceiptReplayFindings`.
- Divergencias devem gerar finding com schema
  `atlas.self_improvement.decision_receipt_replay_gap.v1`.
- O finding deve propagar `review_signal.recommended_action =
  open_reviewable_decision_receipt_replay_proposal`.
- O scanner deve publicar `ap138_decision_receipt_replay_curator_review`.

## Evidencia

- `AtlasSelfImprovementRuntimeTest::test_self_improvement_detects_decision_receipt_replay_hash_gap`
- `atlas:ai:architecture-validate --json`

## Resultado

DecisionReceipt adulterado agora sai do relatorio passivo e entra no ciclo de
Self-Improvement. O Curator consegue detectar a quebra, preservar envelope,
receipt, status de hash/cadeia e abrir proposta revisavel sem recalcular hashes
com uma regra paralela.
