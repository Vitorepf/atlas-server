# AP-135 — Decision Receipt Determinism Test

## Problema

Depois do AP-134, o runtime passou a bloquear `DecisionReceipt` adulterado, mas
a prova de determinismo ainda estava distribuida em testes unitarios pequenos.
Isso deixava o contrato central do Decide menos visivel para revisao
arquitetural.

## Contrato

- `DecisionReceiptDeterminismTest` deve existir em `tests/Feature/Architecture`.
- Mesmo envelope e mesma decisao devem gerar `inputs_hash`, `receipt_hash` e
  `chain_hash` identicos.
- Mudanca no provider/modelo autorizado deve alterar os hashes do receipt.
- Replay com payload assinado mutado deve ser recusado por
  `DecisionReceiptRuntimeGuard` com `decision_receipt_hash_mismatch`.
- O scanner deve publicar `ap135_decision_receipt_determinism_test`.

## Evidencia

- `DecisionReceiptDeterminismTest::test_receipt_hashes_are_stable_for_same_envelope_and_decision_contract`
- `DecisionReceiptDeterminismTest::test_receipt_hash_changes_when_authorized_provider_changes`
- `DecisionReceiptDeterminismTest::test_runtime_guard_rejects_replayed_receipt_after_signed_payload_mutation`
- `atlas:ai:architecture-validate --json`

## Resultado

O contrato mais importante do Decide agora tem uma prova arquitetural direta:
determinismo para replay, sensibilidade a decisao relevante e bloqueio runtime
quando o payload assinado muda.
