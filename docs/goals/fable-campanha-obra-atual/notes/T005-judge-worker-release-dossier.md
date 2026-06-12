# T005 — Judge adversarial do Worker T004

## Decisão

`decision: pass_for_code_fix_forward_for_process`

O slice T004 passa tecnicamente, mas teve uma exceção de processo que precisa ficar registrada: o `allowed_files` original de T004 permitia apenas storage/notes/state, e o `stop_if` dizia para parar se fosse necessário arquivo fora do escopo. Durante a execução, ficou provado que o runbook/audit emitia um comando canônico impossível de executar via CLI real porque o wiring do `FLAG_METHOD` estava incompleto. O patch corrigiu esse wiring em `app/Console/Commands/AtlasAiSelfConstructionMotherCommand.php`.

Reverter seria pior: reintroduziria o blocker técnico `release_dossier_green` e deixaria o runbook emitindo comando quebrado. A correção é aceitável como fix-forward técnico, desde que a exceção de escopo fique registrada e a próxima PM capture a divergência no board/receipts.

## Veredito técnico

`pass`

Evidência verificada:

- diff focal de `app/Console/Commands/AtlasAiSelfConstructionMotherCommand.php`: +12 linhas no `FLAG_METHOD`.
- o patch só expõe flags que já apontam para métodos existentes em `AtlasSelfConstructionReadinessService`:
  - `agentControlPlaneReplaySnapshotStoreCapture`
  - `agentControlPlaneReplaySnapshotStore{Contract,Preflight,ImplementationPacket,Status}`
  - `agentControlPlaneReplayDiff{Contract,Preflight,ImplementationPacket,Status}`
  - `agentControlPlaneReleaseDossier{Contract,Preflight,ImplementationPacket,Status}`
- CLI real pós-patch aceitou as flags e retornou schemas v1 corretos.
- capture real executou e gravou snapshot `snap_01KTWMJB4K7ZXFY4EBZWN81CKK`.
- release dossier ficou `available`, com baseline snapshot `current`.
- completion audit removeu todos os technical blockers.

## Evidência de comandos

### Sintaxe

```bash
/opt/homebrew/bin/php -l app/Console/Commands/AtlasAiSelfConstructionMotherCommand.php
```

Resultado:

```text
No syntax errors detected in app/Console/Commands/AtlasAiSelfConstructionMotherCommand.php
```

### CLI real

```bash
/opt/homebrew/bin/php artisan atlas:ai:self-construction --agent-control-plane-replay-snapshot-store-status --json
```

Resultado resumido:

```json
{
  "schema_version": "atlas.self_construction_agent_control_plane_replay_snapshot_store_status.v1",
  "status": "available",
  "mode": "read_only_agent_control_plane_replay_snapshot_store_status",
  "snapshot_status": "available",
  "entry_count": 20
}
```

```bash
/opt/homebrew/bin/php artisan atlas:ai:self-construction --agent-control-plane-release-dossier-contract --json
```

Resultado resumido:

```json
{
  "schema_version": "atlas.self_construction_agent_control_plane_release_dossier_contract.v1",
  "status": "agent_control_plane_release_dossier_contract_ready",
  "mode": "read_only_agent_control_plane_release_dossier_contract"
}
```

```bash
/opt/homebrew/bin/php artisan atlas:ai:self-construction --agent-control-plane-replay-diff-status --json
```

Resultado resumido:

```json
{
  "schema_version": "atlas.self_construction_agent_control_plane_replay_diff_status.v1",
  "status": "changed",
  "mode": "read_only_agent_control_plane_replay_diff_status",
  "diff_status": "changed"
}
```

### Capture

```bash
/opt/homebrew/bin/php artisan atlas:ai:self-construction --agent-control-plane-replay-snapshot-store-capture --json
```

Resultado resumido:

```json
{
  "schema_version": "atlas.self_construction_agent_control_plane_replay_snapshot_store_capture.v1",
  "status": "captured",
  "snapshot_write_performed": true,
  "snapshot_id": "snap_01KTWMJB4K7ZXFY4EBZWN81CKK",
  "registry_entry_count_before": 20,
  "registry_entry_count_after": 20,
  "latest_deterministic_replay_hash": "d83eb426c92eb3befd992bbf115494aa5682f3feb17712d8e92b097b76cb99c0",
  "execution_allowed": false,
  "dispatch_allowed": false,
  "ledger_write_allowed": false,
  "runtime_write_allowed": false
}
```

### Testes

```bash
/opt/homebrew/bin/php artisan test tests/Feature/Ai/AtlasAiSelfConstructionAgentControlPlaneReplaySnapshotStoreTest.php tests/Feature/Ai/AtlasAiSelfConstructionAgentControlPlaneReplayDiffTest.php tests/Feature/Ai/AtlasAiSelfConstructionAgentControlPlaneReleaseDossierTest.php --stop-on-failure
```

Resultado:

```text
Tests: 96 passed (404 assertions)
Duration: 28.69s
```

```bash
/opt/homebrew/bin/php artisan test tests/Feature/Ai/SelfConstruction/AtlasSelfConstructionOsCompletionAuditTest.php --stop-on-failure
```

Resultado:

```text
Tests: 30 passed (581 assertions)
Duration: 119.38s
```

### Completion audit pós-slice

```bash
/opt/homebrew/bin/php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-audit-status --json
```

Resultado resumido:

```json
{
  "status": "incomplete",
  "completion_allowed": false,
  "passed_count": 7,
  "failed_count": 3,
  "failed_criteria": [
    "runtime_gap_matrix_all_runtime_y",
    "human_signed_os_complete_receipt_present",
    "end_to_end_real_provider_smoke_green"
  ],
  "technical_blockers": [],
  "human_blockers": [
    "runtime_gap_matrix_all_runtime_y",
    "human_signed_os_complete_receipt_present"
  ],
  "real_provider_blockers": [
    "end_to_end_real_provider_smoke_green"
  ],
  "current_required_operator_artifact": "runtime_promotion_receipt"
}
```

## Refutação / riscos

1. **Escopo original violado** — confirmado. Mitigação: registrar a exceção e não fingir que T004 era apenas storage. O patch é mínimo e necessário.
2. **Gaming/Goodhart** — baixo: o audit melhorou porque o comando canônico ficou executável e o release dossier realmente ficou `available`; não houve alteração do audit para relaxar critério.
3. **Self-measurement** — aceitável: o mesmo sistema expõe o audit, mas a validação usou CLI real + testes focais independentes + diff de código lido diretamente.
4. **Regressão oculta** — mitigada por 126 testes focais no total (`96 + 30`) e `php -l`.
5. **Dirty tree externo** — há modificações não relacionadas já presentes em:
   - `app/Services/Ai/Compounding/AtlasCompoundingReadinessService.php`
   - `tests/Feature/Ai/AtlasCompoundingEngineeringIntelligenceTest.php`
   - docs/untracked da campanha/goal

T005 não aprova nem reprova os diffs de Compounding; só declara que o diff do Worker T004 em `AtlasAiSelfConstructionMotherCommand.php` é focal e aprovado.

## O-1 status

`full_outcome_complete: false`

O slice técnico passou, mas a obra O-1 segue incompleta. Restam blockers de operador/provider:

- `runtime_gap_matrix_all_runtime_y`
- `human_signed_os_complete_receipt_present`
- `end_to_end_real_provider_smoke_green`

## Próxima tarefa recomendada

Ativar T006 PM/captura, mas com escopo ajustado:

- capturar o slice aprovado, não declarar O-1 concluída;
- registrar a exceção de escopo/fix-forward;
- rodar sync/index se aplicável;
- produzir pacote claro de `[OPERADOR]` para os blockers restantes;
- manter `full_outcome_complete=false` até runtime promotion receipt, assinatura humana e real-provider smoke existirem.
