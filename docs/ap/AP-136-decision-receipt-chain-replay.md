# AP-136 — Decision Receipt Chain Replay

## Problema

Depois dos AP-134/AP-135, o runtime conseguia bloquear receipt adulterado, mas o
Evidence Ledger ainda nao oferecia um read model especifico para auditar a
cadeia de `DECISION_ISSUED`. Sem isso, replay humano/Curator precisaria abrir
eventos crus e recalcular hashes localmente.

## Contrato

- `DECISION_ISSUED` deve preservar `parent_receipt_id` e `parent_chain_hash`.
- `AtlasLedgerReplayService::decisionReceiptReportForEnvelope()` deve projetar
  os receipts de um envelope.
- O replay deve recalcular `receipt_hash` e `chain_hash` com
  `DecisionReceiptHash`.
- Divergencia deve aparecer como `decision_receipt_chain_hash_mismatch` ou
  `decision_receipt_hash_mismatch`.
- O read model deve publicar `review_signal` com recommended action
  `open_reviewable_decision_receipt_replay_proposal`.
- O scanner deve publicar `ap136_decision_receipt_chain_replay`.

## Evidencia

- `LedgerReplayServiceTest::test_decision_receipt_report_projects_chain_integrity_for_envelope`
- `LedgerReplayServiceTest::test_decision_receipt_report_flags_hash_mismatch_for_review`
- `atlas:ai:architecture-validate --json`

## Resultado

Replay de receipt deixa de ser leitura bruta de eventos. O Atlas agora consegue
auditar cadeia de decisoes por envelope, encontrar divergencia de hash e
transformar o problema em sinal revisavel para operador/Curator.
