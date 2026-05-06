# AP-143 — Ledger Projection Curator Action Emission

## Problema

AP-142 criou a action assistida `run_ledger_projection`, mas o Curator ainda
podia abrir proposta de projection drift sem incluir o botao acionavel. Isso
mantinha o operador preso em leitura manual: entender o problema no Inbox e sair
para outro comando.

## Contrato

- `AtlasSelfImprovementRuntime::ledgerProjectionDriftFindings` deve incluir
  `available_actions[]=run_ledger_projection` no finding de drift.
- O finding deve carregar payload operacional `projection_health` e
  `ledger_projection` com janela/limite default.
- `ProposalInboxEmitter` deve preservar `available_actions` customizados.
- `ProposalInboxEmitter` deve preservar payload customizado no Inbox e no raw
  payload do Context Bundle.
- As actions padrao `review_patch`, `discuss` e `discard` devem continuar
  presentes como fallback.
- O caminho final esperado e:
  `review_signal -> ProposalInboxEmitter -> Inbox run_ledger_projection ->
  LedgerProjectionWorker -> INBOX_ACTION_RECORDED`.

## Enforcement

- Scanner: `ap143_ledger_projection_curator_action_emission`.
- Teste feature:
  `AtlasSelfImprovementRuntimeTest::test_self_improvement_emits_ledger_projection_drift_proposal_with_assisted_action`.
- Teste unit:
  `ProposalInboxEmitterTest::test_proposal_preserves_custom_actions_and_payload_for_assisted_operations`.
