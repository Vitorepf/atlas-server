# T004 — Worker release dossier / replay snapshot wiring + capture

## Resultado

`result: done`

O blocker técnico `release_dossier_green` foi removido.

Antes do slice, o completion audit retornava:

- `status`: `incomplete`
- `passed_count`: `6`
- `failed_count`: `4`
- `technical_blockers`: [`release_dossier_green`]

Após o slice, o completion audit retornou:

- `status`: `incomplete`
- `passed_count`: `7`
- `failed_count`: `3`
- `technical_blockers`: `[]`
- `human_blockers`: [`runtime_gap_matrix_all_runtime_y`, `human_signed_os_complete_receipt_present`]
- `real_provider_blockers`: [`end_to_end_real_provider_smoke_green`]
- `current_required_operator_artifact`: `runtime_promotion_receipt`

A obra O-1 **não está completa**. Ela agora está bloqueada por evidência humana/runtime/provider, não por blocker técnico automático.

## Causa raiz encontrada

O runbook/código/tests já apontavam o comando canônico:

```bash
php artisan atlas:ai:self-construction --agent-control-plane-replay-snapshot-store-capture --json
```

mas a CLI real falhava com:

```text
The "--agent-control-plane-replay-snapshot-store-capture" option does not exist.
```

A causa era falta de wiring no `FLAG_METHOD` do mother command:

- métodos já existiam em `AtlasSelfConstructionReadinessService`
- testes usavam `Artisan::call(...)` e não cobriam a invocação CLI real
- o runbook emitia comando não executável via terminal

## Arquivos modificados

- `app/Console/Commands/AtlasAiSelfConstructionMotherCommand.php`
  - adicionado wiring para:
    - `--agent-control-plane-replay-snapshot-store-capture`
    - `--agent-control-plane-replay-snapshot-store-{contract,preflight,implementation-packet,status}`
    - `--agent-control-plane-replay-diff-{contract,preflight,implementation-packet,status}`
    - `--agent-control-plane-release-dossier-{contract,preflight,implementation-packet}`

## Comandos executados

### Falha inicial

```bash
/opt/homebrew/bin/php artisan atlas:ai:self-construction --agent-control-plane-replay-snapshot-store-capture --json
```

Resultado: `exit 1`, opção inexistente.

### Diagnóstico

```bash
/opt/homebrew/bin/php artisan atlas:ai:self-construction --help
```

Confirmou ausência da opção.

```bash
/opt/homebrew/bin/php artisan optimize:clear
```

Cache limpo; retry continuou falhando, descartando cache stale.

### Sintaxe

```bash
/opt/homebrew/bin/php -l app/Console/Commands/AtlasAiSelfConstructionMotherCommand.php
```

Resultado:

```text
No syntax errors detected in app/Console/Commands/AtlasAiSelfConstructionMotherCommand.php
```

### Validação CLI real após patch

```bash
/opt/homebrew/bin/php artisan atlas:ai:self-construction --agent-control-plane-replay-snapshot-store-status --json
```

Resumo:

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

Resumo:

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

Resumo:

```json
{
  "schema_version": "atlas.self_construction_agent_control_plane_replay_diff_status.v1",
  "status": "changed",
  "mode": "read_only_agent_control_plane_replay_diff_status",
  "diff_status": "changed"
}
```

### Capture executado

```bash
/opt/homebrew/bin/php artisan atlas:ai:self-construction --agent-control-plane-replay-snapshot-store-capture --json
```

Resumo:

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

### Release dossier após capture

```bash
/opt/homebrew/bin/php artisan atlas:ai:self-construction --agent-control-plane-release-dossier-status --json
```

Resumo:

```json
{
  "schema_version": "atlas.self_construction_agent_control_plane_release_dossier_status.v1",
  "status": "available",
  "dossier_status": "available",
  "baseline_snapshot_capture_required": false,
  "baseline_snapshot_can_capture": true,
  "baseline_snapshot_state": "current",
  "release_dossier_hash": "f59443bb35549c20e2e65124f527a4db3eef9608b4c750c19696812bcb45b7c8"
}
```

### Completion audit após capture

```bash
/opt/homebrew/bin/php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-audit-status --json
```

Resumo:

```json
{
  "status": "incomplete",
  "audit_status": "incomplete",
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
  "current_required_operator_artifact": "runtime_promotion_receipt",
  "completion_audit_hash": "0614d3e5bd99579fb365bf5801e5f02e23b592e633d9d1840b7b3e67a8fbe56c",
  "status_hash": "f7ec53e0dce587321e0c1464b12dcd458cda51bc74e63a818c34cdb843bea2ea"
}
```

## Testes

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

## Estado final do slice

- `technical_blockers`: resolvidos.
- O-1 ainda incompleta por blockers não automáticos:
  - operador/runtime: `runtime_gap_matrix_all_runtime_y`
  - operador/humano: `human_signed_os_complete_receipt_present`
  - provider real: `end_to_end_real_provider_smoke_green`
- Não houve execução runtime, dispatch, ledger write, provider call, token spend ou self-programming pelo comando de capture.

## Próxima ação segura

Ativar Judge/PM para classificar os blockers restantes e decidir se:

1. deve solicitar artefato `[OPERADOR] runtime_promotion_receipt`, assinatura humana e smoke real provider; ou
2. existe outro slice técnico seguro antes de pedir operador.
