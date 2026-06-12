# T009 — Judge de colisões hard-blocking do placement O-1

## Decisão

`decision: worker_ready_for_reuse_path_only`

As colisões apontadas pelo `atlas:ai:place-feature` são reais como sinais de dono/reuso, mas **não autorizam** criar superfície, rota, runtime, teste paralelo ou novo completion audit.

A liberação segura para o próximo pacote é: usar os owners existentes e executar o próximo blocker técnico canônico do audit, sem edição de código de produto.

## Comandos executados

### Bootstrap

```bash
/opt/homebrew/bin/php artisan atlas:ai:session-bootstrap --task="O-1 Certification Sweep da espinha de engenharia + Marco Zero" --json
```

Resultado relevante:

- `schema_version`: `atlas.session_bootstrap.v1`
- `status`: `ok`
- `session_gate.status`: `blocked`
- `session_gate.reason`: `feature_placement_gate_blocked`
- `blocked_when`: `ambiguous_placement_requires_more_specific_feature_or_hint`
- Code Intelligence: `status=ready`, `symbol_count=118099`, `test_count=23192`, `last_indexed_at=2026-06-11T19:15:55.000000Z`

### AOBG/Open Brain

```bash
bin/atlas open-brain context "O-1 Certification Sweep da espinha de engenharia + Marco Zero" --json
```

Resumo:

- exit `0`
- `provider_safe`: `true`
- `context_refs_count`: `13`
- `memory_refs_count`: `8`
- `semantic_count`: `5`
- `recall_count`: `9`

### Placement específico

```bash
/opt/homebrew/bin/php artisan atlas:ai:place-feature "O-1 certification sweep existing Atlas engineering spine: audit Dev pipe provider manager conductor workspace mutating providers, Forge gates, Loop stack drivers, compounding flywheel capture quality gate, and Marco Zero evidence integrity; no new runtime or surface" --json
```

Resultado:

- `status`: `ok`
- placement: `layer=evidence`, `domain=programming`, `flow=programming.dev`
- `gate_status`: `blocked`
- `blocked_when`: `high_overlap_duplicate_candidate_requires_reuse_or_explicit_supersede_decision`
- hard-blocking candidates:
  - `routes/api.php` score `788`
  - `tests/Feature/Ai/SelfConstruction/AtlasSelfConstructionOsCompletionAuditTest.php` score `448`

### Teste de audit existente

```bash
/opt/homebrew/bin/php artisan test tests/Feature/Ai/SelfConstruction/AtlasSelfConstructionOsCompletionAuditTest.php --stop-on-failure
```

Resultado:

- `PASS Tests\Feature\Ai\SelfConstruction\AtlasSelfConstructionOsCompletionAuditTest`
- `30 passed`, `581 assertions`
- duração `120.36s`

### Completion audit status

```bash
/opt/homebrew/bin/php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-audit-status --json
```

Resultado resumido:

- status: `incomplete`
- `completion_allowed=false`
- `completion_claim_allowed=false`
- `criteria_count=10`
- `passed_count=6`
- `failed_count=4`
- failed criteria:
  - `runtime_gap_matrix_all_runtime_y` — human blocker
  - `release_dossier_green` — technical blocker
  - `human_signed_os_complete_receipt_present` — human blocker
  - `end_to_end_real_provider_smoke_green` — real_provider blocker
- `certification_status_batch_status=passed`
- `certification_status_batch_checked_count=50`
- `terminal_loop_operational_proof_status=passed`
- `terminal_loop_operational_proof_supplied=true`
- `terminal_loop_operational_proof_passed=true`
- `release_dossier_green.evidence.closure_exact_next_command`:
  - `php artisan atlas:ai:self-construction --agent-control-plane-replay-snapshot-store-capture --json`

### Marco Zero artifact

Arquivo: `storage/app/atlas/evidence/marco-zero-fable-2026-06-11.json`

- exists: `true`
- size: `3207`
- sha256: `61acd7a60fb1399d64ea51287fa12424b3687653c208fc59ee3d06739e9b40d3`
- `schema_version`: `atlas.fable_campaign.marco_zero.v1`

## Classificação das colisões

### `routes/api.php`

Classificação: `real_owner_surface_reuse_required`.

Evidência:

- O arquivo já contém superfície extensa de Atlas Code/Forge/Programming/Self-Improvement.
- Anchors lidos:
  - `/atlas-code/certification` em `routes/api.php:800-801`
  - work packets, observed sessions, Forge fast-path/review/runtime-dispatch em `routes/api.php:771-823`
  - self-improvement endpoints em `routes/api.php:834-857`
  - programming governance read models em `routes/api.php:880-910`
- Não há necessidade nem permissão de nova rota para O-1. O sweep deve reusar comandos/rotas/read models existentes.

### `tests/Feature/Ai/SelfConstruction/AtlasSelfConstructionOsCompletionAuditTest.php`

Classificação: `canonical_completion_audit_test_reuse_required`.

Evidência:

- Teste existente prova o completion audit canônico do Self-Construction OS.
- Executou verde: `30 passed`, `581 assertions`.
- O teste cobre blockers humanos, real-provider blocker, release dossier, terminal loop, operator action packet, audit blocks, doc anchors e CLI quartet.
- Portanto, criar teste paralelo seria duplicação. O próximo passo deve reusar o audit e atacar o blocker técnico apontado pelo próprio audit.

## Próximo Worker aprovado

`worker_ready: true`, mas com escopo estreito e sem edição de código.

Objective:

> Executar o remediation command canônico do blocker técnico `release_dossier_green`, capturando replay snapshot/release dossier via comando existente, reexecutar completion audit e registrar receipt. Não alterar `routes/api.php`, não alterar `AtlasSelfConstructionOsCompletionAuditTest.php`, não criar nova surface/runtime/teste.

Allowed write scopes:

- `storage/app/private/atlas/self-construction/**` somente via comando canônico do Atlas
- `storage/app/atlas/**` somente se o comando canônico escrever evidência/snapshot
- `docs/goals/fable-campanha-obra-atual/notes/T004-worker-release-dossier.md`
- `docs/goals/fable-campanha-obra-atual/state.yaml`

Forbidden:

- `routes/api.php`
- `tests/Feature/Ai/SelfConstruction/AtlasSelfConstructionOsCompletionAuditTest.php`
- `app/**`
- `docs/engineering-knowledge-base/**`
- qualquer provider smoke real sem operador
- qualquer assinatura/supersede em nome do operador

Verify:

1. `/opt/homebrew/bin/php artisan atlas:ai:self-construction --agent-control-plane-replay-snapshot-store-capture --json`
2. `/opt/homebrew/bin/php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-audit-status --json`
3. `/opt/homebrew/bin/php artisan test tests/Feature/Ai/SelfConstruction/AtlasSelfConstructionOsCompletionAuditTest.php --stop-on-failure`
4. Confirmar se `release_dossier_green` passou ou se novo blocker permanece.

Stop if:

- Comando pedir artefato humano ou provider real.
- Comando tentar dispatch/provider/self-programming.
- Necessário editar arquivo fora de allowed write scopes.
- Completion audit trocar o blocker técnico por blocker humano/provider.
- Teste falhar.

## Resultado T009

`result: done`

`full_outcome_complete: false`

A obra O-1 ainda não está completa. O próximo passo seguro é T004 com Worker de execução/captura técnica, não implementação de feature nova.
