# AP-134 — Decision Receipt Hash Runtime Guard

## Problema

`DecisionReceipt v2` ja possuia `inputs_hash`, `receipt_hash` e `chain_hash`,
mas o runtime ainda usava esses campos principalmente como evidencia. Isso
permitia que um payload de receipt completo fosse adulterado entre Decide e Data
Plane sem uma recusa explicita antes do provider.

## Contrato

- `DecisionReceiptHash` e o algoritmo canonico compartilhado de hash.
- `DecisionReceiptIssuer` deve usar `DecisionReceiptHash` e persistir hints de
  verificacao em `metadata.envelope_input_hash` e, quando existir,
  `metadata.parent_chain_hash`.
- `DecisionReceiptRuntimeGuard` deve validar `inputs_hash`, `receipt_hash` e
  `chain_hash` quando presentes no receipt v2.
- Divergencia de integridade deve retornar `decision_receipt_hash_mismatch`.
- `AiWorker` deve tratar `decision_receipt_hash_mismatch` como bloqueio
  pre-provider.
- O scanner deve publicar `ap134_decision_receipt_hash_runtime_guard`.

## Evidencia

- `DecisionReceiptRuntimeGuardTest::test_accepts_issued_receipt_with_matching_hashes`
- `DecisionReceiptRuntimeGuardTest::test_blocks_issued_receipt_when_signed_payload_is_tampered`
- `DecisionReceiptIssuerTest`
- `atlas:ai:architecture-validate --json`

## Resultado

Receipt v2 completo agora e contrato runtime, nao so log. O Data Plane recusa
payload assinado que mudou depois da emissao do Decide, mantendo provider,
modelo, budget, gates, repair policy e cadeia de replay presos ao mesmo hash
canonico.
