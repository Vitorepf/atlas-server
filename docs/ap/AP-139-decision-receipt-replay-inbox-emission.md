# AP-139 — Decision Receipt Replay Inbox Emission

## Problema

AP-138 fez o Curator detectar divergencias criptograficas em `DECISION_ISSUED`,
mas a arquitetura mae precisa provar tambem o caminho operacional completo:
finding critico deve virar proposta revisavel no Inbox quando o operador permite
`emit=true`.

Sem esse contrato, o Atlas poderia conhecer uma falha de DecisionReceipt e ainda
assim deixa-la enterrada em telemetria, sem item revisavel, sem dedupe key e sem
correlacao no Evidence Ledger.

## Contrato

Quando `AtlasSelfImprovementRuntime::nightlyReview()` roda com:

- `flow = self_improvement.weekly_architecture_audit` ou fluxo default;
- `emit = true`;
- evento `DECISION_ISSUED` com `receipt_hash` ou `chain_hash` divergente;

o runtime deve:

1. Gerar finding `atlas.self_improvement.decision_receipt_replay_gap.v1`.
2. Preservar `review_signal.recommended_action =
   open_reviewable_decision_receipt_replay_proposal`.
3. Chamar `ProposalInboxEmitter` com a mesma `dedupe_key` do finding.
4. Preservar `source_refs` com `envelope_id`, `receipt_id` e status de
   integridade.
5. Registrar `LEARNING_PROPOSED` com `emitted_to_inbox = true` e
   `emitted_inbox_item_id`.
6. Registrar `OPERATION_COMPLETED` com `emitted_inbox_item_ids`.

## Enforcement

O teste
`test_self_improvement_emits_decision_receipt_replay_hash_gap_proposal` cria um
evento `DECISION_ISSUED` adulterado, mocka `ProposalInboxEmitter`, roda
`nightlyReview(..., emit: true)` e valida:

- schema `atlas.self_improvement.decision_receipt_replay_gap.v1`;
- action `open_reviewable_decision_receipt_replay_proposal`;
- source refs do envelope/receipt afetado;
- `emitted_to_inbox` e `emitted_inbox_item_id` no `LEARNING_PROPOSED`;
- `emitted_inbox_item_ids` no `OPERATION_COMPLETED`.

O scanner `ap139_decision_receipt_replay_inbox_emission` bloqueia regressao se o
teste, a documentacao ou os tokens essenciais de emissao forem removidos.

## Status

Implementado. Este AP fecha o caminho Evidence → Curator → Proposal Inbox para
DecisionReceipt adulterado.
