# Atlas Forge Rivals Reliability Lockdown v1

**Schema namespace:** `atlas.programming.rivals_forge_*`
**Status:** canon · 2026-05-14
**Owns:** preflight, dry-run, evidence pack, run orchestrator, triage registry, replay
**Does NOT own:** real provider dispatch (governed by `atlas:engineering:benchmark:claude-fair`), `external_rivals_certification` (continues separately blocked by design)

---

## 1. Por que o Rivals falhava

Antes desta lockdown, mesmo com preflight `ready_for_dry_run` e dry-run `dry_run_passed`, a bateria real do Atlas Forge sempre saía como `invalid_battery_no_comparable_score` ou era invalidada em silêncio. Sete bugs raiz mapeados, cada um agora protegido por código + teste:

| # | Bug | Localização original | Fix |
|---|---|---|---|
| 1 | `.pyc` rastreados em `runtimes/python/**/__pycache__/` faziam `git status` mostrar dirty depois de qualquer execução Python | `atlas-server/runtimes/python/**` (137 arquivos `cpython-*.pyc` no índice) | `WorkspaceHygieneService::trackedPythonBytecode()` + preflight blocker `tracked_python_bytecode_in_workspace` |
| 2 | Nenhum `after_clean_check` no evidence pack — workspace dirty pós-run virava `invalid` sem evidência | `AtlasRivalsEvidencePackService::generate()` | Snapshot before, snapshot after, campo `workspace.after_clean_check.{ran,clean,hash_before,hash_after,dirty_files}` |
| 3 | `PYTHONDONTWRITEBYTECODE` não setado → cada PHPUnit/Python regenerava bytecode | `AtlasRivalsEvidencePackService::runShellCommand()` | `WorkspaceHygieneService::forceBytecodeDisabledEnv()` injetado em todo subprocess do pack |
| 4 | `dirty_workspace_after_run` não era hard fail global | `AtlasRivalsOneShotEnterpriseRubricService` | Constante `GLOBAL_HARD_FAIL_CONDITIONS` ganhou `dirty_workspace_after_run` + `tracked_python_bytecode_in_workspace` |
| 5 | Preflight/dry-run/runbook/runner divergiam silenciosamente — quick runbook ready, quick run blocked | múltiplos serviços | Novo `RivalsForgeReadinessFingerprintService`: sha256 determinístico sobre `(suite, preset, atlas_model, baseline_model, atlas_workspace_hash, baseline_workspace_hash, case_ids, gate_profile, test_command)`. Mismatch → blocker `fingerprint_mismatch_runbook_vs_run` com lista field-by-field |
| 6 | Sonnet bloqueado por política, não por driver | `FairClaudePolicy::MODEL_LOCK = 'opus'` | `FairClaudePolicy::MODEL_LOCK_ALLOWLIST = ['opus', 'sonnet']` + `MODEL_NOT_AVAILABLE_ERROR` sub_error. Comando aceita `--model=sonnet --baseline-model=sonnet`. Dashboard mostra o par real |
| 7 | Quick preset não era quick — rodava suite cheia | `AtlasRivalsCommand::PRESETS['quick']` | Case manifest publica `quick_test_command` (canary fixture) e `full_test_command`. Evidence grava `tests.command_origin = preset_default|operator_explicit`. Timeout do quick caiu de 1200s → 300s |
| (extra) | Sem streaming — log 0B por 40 min | `AtlasForgeProviderProcessRunner` capturava só ao final | Novo `RivalsForgeRunLogStreamService` (JSONL) + `AtlasRivalsRunOrchestrator` que emite eventos canon a cada etapa, com heartbeat 1s e stall budget |
| (extra) | Triage por suite, não por fingerprint — opus quarantinado bloqueava sonnet | `EngineeringBenchmarkService::historicalInvalidFairBatteryRequiresTriage()` | Novo `AtlasRivalsInvalidBatteryTriageRegistry` keyed por fingerprint |
| (extra) | Evidence pack do runner real não capturava `before/after hash`, diff per arm, provider receipt, timeline, replay manifest executado | só existia `generate()` local | `AtlasRivalsEvidencePackService::generateForRealRun()` + verifier `MODE_REAL_RUN` com `REQUIRED_FIELDS_FOR_REAL_RUN` |

---

## 2. Novo fluxo confiável (slices A → J)

```
operator
  │
  ▼
atlas rivals preflight       ── canon (slice H)
atlas rivals dry-run         ── canon (slice H)
atlas rivals run --quick     ── ainda governado por atlas:engineering:benchmark:claude-fair
atlas rivals replay [--run-id]
atlas rivals triage-invalid-battery --fingerprint=<h>
                  │
                  ▼
   ┌──────────────────────────────────────────────────────────────┐
   │ Preflight (AtlasForgeNativeRivalsPreflightService)            │
   │   - tracked_python_bytecode_in_workspace                      │ slice A
   │   - workspace_dirty_or_not_git                                │
   │   - readiness_fingerprint (slice B)                            │
   └──────────────────────────────────────────────────────────────┘
                  │
                  ▼
   ┌──────────────────────────────────────────────────────────────┐
   │ Dry-run (AtlasForgeNativeRivalsDryRunService)                  │
   │   - replay_manifest.state = planned                            │
   │   - same fingerprint as preflight                              │
   └──────────────────────────────────────────────────────────────┘
                  │
                  ▼
   ┌──────────────────────────────────────────────────────────────┐
   │ AtlasRivalsRunOrchestrator (slice E)                           │
   │   modes: dry_run | fake_provider | real_provider               │
   │   emite eventos JSONL canon em                                 │
   │   storage/app/rivals-forge-runs/<runId>/events.jsonl           │
   │   stall budget + heartbeat                                     │
   └──────────────────────────────────────────────────────────────┘
                  │
                  ▼
   ┌──────────────────────────────────────────────────────────────┐
   │ Evidence pack real run (slice F)                               │
   │   generateForRealRun → REQUIRED_FIELDS_FOR_REAL_RUN            │
   │   verifier MODE_REAL_RUN → invalid_missing_evidence            │
   │   evaluator → dirty_workspace_after_run hard fail              │
   └──────────────────────────────────────────────────────────────┘
                  │
                  ▼
   AtlasRivalsInvalidBatteryTriageRegistry (slice G)
   por fingerprint, não por suite
```

`external_rivals_certification` permanece **bloqueado** durante todo este fluxo. Nenhum caminho deste código desbloqueia. Auditável via `grep -rn 'external_rivals_certification' atlas-server/app` (deve continuar zero unlocks).

---

## 3. Comandos canon

### 3.1 Sem provider (sempre seguros, sem cobrança)

```bash
# Preflight: valida workspace, baseline, manifest, fingerprint, pyc tracked
/opt/homebrew/bin/php artisan atlas:engineering:benchmark:rivals preflight \
  --workspace=<clean-atlas-worktree> \
  --claude-code-baseline-workspace=<clean-baseline-worktree> \
  --model=sonnet --baseline-model=sonnet \
  --json --strict

# Dry-run: planeja replay manifest, NÃO chama provider
/opt/homebrew/bin/php artisan atlas:engineering:benchmark:rivals dry-run \
  --workspace=<clean-atlas-worktree> \
  --case=atlas-fair-claude-baseline-case-01 \
  --model=sonnet \
  --json --strict

# Replay do último run salvo em disco (JSONL events)
/opt/homebrew/bin/php artisan atlas:engineering:benchmark:rivals replay --json
/opt/homebrew/bin/php artisan atlas:engineering:benchmark:rivals replay --run-id=<id> --json

# Readiness (cumulativo)
/opt/homebrew/bin/php artisan atlas:programming:rivals-readiness --json

# Evaluator local (sem provider)
/opt/homebrew/bin/php artisan atlas:programming:rivals-one-shot-evaluate \
  --case=atlas-fair-claude-baseline-case-01 --workspace=<atlas> --json --strict
```

### 3.2 Com provider (cobrança real — exige flags explícitas)

```bash
# Atlas Forge Sonnet vs Claude Code Sonnet, quick preset
/opt/homebrew/bin/php artisan atlas:engineering:benchmark:rivals run \
  --quick \
  --workspace=<clean-atlas-worktree> \
  --claude-code-baseline-workspace=<clean-baseline-worktree> \
  --model=sonnet --baseline-model=sonnet \
  --confirm-runbook-reviewed --confirm-provider-cost \
  --json

# Full battery (mais caro, mais robusto)
/opt/homebrew/bin/php artisan atlas:engineering:benchmark:rivals run \
  --full \
  --workspace=<clean-atlas-worktree> \
  --claude-code-baseline-workspace=<clean-baseline-worktree> \
  --model=sonnet --baseline-model=sonnet \
  --confirm-runbook-reviewed --confirm-provider-cost \
  --json
```

### 3.3 Triage de bateria inválida histórica

```bash
# A registry distingue fingerprints (slice G); triagem de quick+opus NÃO bloqueia quick+sonnet
/opt/homebrew/bin/php artisan atlas:engineering:benchmark:rivals triage-invalid-battery \
  --fingerprint=<sha256 hex> \
  --confirm-invalid-battery-quarantine \
  --reason="explicação humana do quarantine"
```

---

## 4. Interpretando o resultado

| `status`/`verdict` | Significa | Score | Ação |
|---|---|---|---|
| `ready_for_dry_run` | Preflight verde, pode dry-run | n/a | Rode dry-run |
| `ready_for_provider_battery` | Preflight + aprovação operator OK | n/a | Pode dispatch real |
| `blocked_dirty_workspace` | Working tree não-git ou com arquivos modificados | n/a | Commit/stash |
| `blocked_tracked_python_bytecode` | `.pyc` ou `__pycache__` no índice — rodar `resolution_command` | n/a | `git rm --cached -r ...` |
| `blocked_protocol_invalid` | Case manifest inválido | n/a | Verificar `atlas_arm.runtime` |
| `blocked_missing_baseline_workspace` | Baseline ausente, igual ao Atlas, dirty ou não-git | n/a | `git worktree add` separado |
| `blocked_requires_operator_approval` | `intends_provider_battery=true` sem `--confirm-*` | n/a | Operator review |
| `dry_run_passed` | Dry-run OK, replay manifest válido | n/a | Pode considerar real run |
| `dry_run_blocked` | Algum invariante falhou no plano | n/a | Olhar `blocking_reasons` |
| `passed` (orchestrator) | Fake provider rodou, after-clean OK, evidence verificada | **null** (sem provider real → claim_ready=false) | n/a |
| `invalid_dirty_after_run` | Workspace ficou dirty depois — pode ser `.pyc`, lockfile, fixture | null | Olhar `after_clean_check.dirty_files`, ver doc seção 6 |
| `invalid_missing_evidence` | Pack real_run sem campos obrigatórios | null | Ver `missing_real_run_fields` |
| `stalled_runner_no_heartbeat` | Subprocess ficou >budget sem output | null | Aumentar `stall_budget_seconds`, ou debugar provider |
| `blocked_fingerprint_mismatch` | Run e runbook diferentes (modelo? workspace?) | null | Re-rodar runbook com mesmas flags |

`claim_ready=true` **só** pode ser concedido por `external_rivals_certification`, que permanece bloqueado por desenho. Score = `null` em qualquer caminho deste módulo enquanto não houver real provider validada + cert externa.

---

## 5. Atlas Forge Sonnet vs Claude Code Sonnet

Receita completa em workspaces isolados:

```bash
# Crie worktrees clean isolados (operador faz uma vez)
git worktree add /tmp/atlas-rivals-atlas HEAD
git worktree add /tmp/atlas-rivals-baseline HEAD

# Preflight Sonnet vs Sonnet
/opt/homebrew/bin/php artisan atlas:engineering:benchmark:rivals preflight \
  --workspace=/tmp/atlas-rivals-atlas \
  --claude-code-baseline-workspace=/tmp/atlas-rivals-baseline \
  --model=sonnet --baseline-model=sonnet \
  --json --strict
# Esperado: status=ready_for_dry_run, fingerprint computed

# Dry-run
/opt/homebrew/bin/php artisan atlas:engineering:benchmark:rivals dry-run \
  --workspace=/tmp/atlas-rivals-atlas \
  --model=sonnet \
  --json --strict
# Esperado: status=dry_run_passed, replay_manifest.state=planned

# Run real (cobra provider)
/opt/homebrew/bin/php artisan atlas:engineering:benchmark:rivals run \
  --quick \
  --workspace=/tmp/atlas-rivals-atlas \
  --claude-code-baseline-workspace=/tmp/atlas-rivals-baseline \
  --model=sonnet --baseline-model=sonnet \
  --confirm-runbook-reviewed --confirm-provider-cost \
  --json
# Esperado: dashboard mostra "Atlas Forge / sonnet" vs "Claude Code CLI / sonnet"

# Replay (sem cobrança)
/opt/homebrew/bin/php artisan atlas:engineering:benchmark:rivals replay --json
# Esperado: events.jsonl com kinds {preflight, provider_start, provider_done, after_clean_check, evidence_pack, final_report}
```

---

## 6. Investigando dirty workspace

Quando a preflight ou orchestrator retorna `blocked_tracked_python_bytecode` ou `invalid_dirty_after_run`:

1. `git -C <workspace> ls-files -- '*.pyc' '*.pyo' '*__pycache__*' | wc -l` — quantos artefatos Python tracked?
2. Se >0, rode o `resolution_command` que o preflight devolveu:
   ```
   git -C <workspace> rm --cached -r 'runtimes/python/**/__pycache__' '*.pyc' '*.pyo'
   git -C <workspace> commit -m "chore: untrack python bytecode"
   ```
3. Confirme: `git -C <workspace> ls-files -- '*.pyc' '*.pyo' '*__pycache__*' | wc -l` deve ser `0`.
4. Re-rode preflight com `--strict`.

Quando o `invalid_dirty_after_run` veio mesmo com pyc untracked: olhe `evidence_pack.workspace.after_clean_check.dirty_files`. Causas comuns:
- Lockfiles regenerados (composer/yarn) — registrar no `.gitignore`
- Logs/cache do Laravel (storage/) — geralmente já gitignored, mas pode haver custom path
- Fixtures de teste mal isoladas — usar `tmp_dir` ao invés de `tests/_fixtures/`

---

## 7. Replay de uma run

```bash
# Latest
/opt/homebrew/bin/php artisan atlas:engineering:benchmark:rivals replay --json

# Específica
/opt/homebrew/bin/php artisan atlas:engineering:benchmark:rivals replay \
  --run-id=rivals-forge-01HXYZ... --json
```

O JSON inclui:
- `event_count`
- `kinds` (lista única de eventos vistos)
- `final_report` (verdict + score + readiness_fingerprint)
- `events` (lista completa JSONL com timestamps)

Storage: `storage/app/rivals-forge-runs/<runId>/{events.jsonl, run.log, intent.json}`. Política de rotação: últimos 20 runs (configurável em `RivalsForgeRunLogStreamService::RETENTION_RUNS`).

---

## 8. Tabela "cada bug → onde foi consertado → teste que prova"

| Bug raiz | Arquivo do fix | Teste que prova |
|---|---|---|
| `.pyc` tracked | `WorkspaceHygieneService` + preflight `checkWorkspace()` | `AtlasForgeNativeRivalsTest::test_preflight_blocks_tracked_python_bytecode` |
| `after_clean_check` ausente | `AtlasRivalsEvidencePackService::buildAfterCleanCheck()` | `AtlasRivalsEvidencePackTest::test_evidence_pack_records_after_clean_check_when_tests_run` + `test_evidence_pack_marks_dirty_when_command_writes_file` |
| `PYTHONDONTWRITEBYTECODE` não setado | `WorkspaceHygieneService::forceBytecodeDisabledEnv()` + `runShellCommand()` | `AtlasRivalsEvidencePackTest::test_evidence_pack_forces_pythondontwritebytecode_env` |
| `dirty_workspace_after_run` não hard fail | `AtlasRivalsOneShotEnterpriseRubricService::GLOBAL_HARD_FAIL_CONDITIONS` | `AtlasRivalsOneShotEnterpriseEvaluationTest::test_evaluation_hard_fails_when_workspace_dirty_after_run` |
| Preflight/dry-run/runner divergiam | `RivalsForgeReadinessFingerprintService` | `RivalsForgeReadinessFingerprintServiceTest` (6 testes) + `AtlasForgeNativeRivalsTest::test_dry_run_fingerprint_matches_preflight_for_same_intent` |
| Sonnet bloqueado | `FairClaudePolicy::MODEL_LOCK_ALLOWLIST` + `MODEL_NOT_AVAILABLE_ERROR` | `FairClaudePolicyTest::test_accepts_claude_cli_with_sonnet_premium_selection` + `AtlasEngineeringBenchmarkFairCommandModelLockTest` (5 testes) |
| Quick não era quick | Case manifest `quick_test_command`/`full_test_command` + evidence `tests.command_origin` | `AtlasForgeNativeRivalsTest::test_case_manifest_publishes_quick_and_full_test_commands` + `AtlasRivalsEvidencePackTest::test_quick_preset_uses_case_quick_test_command_by_default` |
| Sem streaming/logs | `RivalsForgeRunLogStreamService` + `AtlasRivalsRunOrchestrator` | `RivalsForgeRunLogStreamServiceTest` (7 testes) + `AtlasRivalsRunOrchestratorTest::test_fake_provider_clean_run_passes_and_records_after_clean_check_clean` |
| Stall sem heartbeat | Orchestrator `runFakeProvider()` loop + stall budget | `AtlasRivalsRunOrchestratorTest::test_fake_provider_that_stalls_returns_stalled_runner_verdict` |
| Evidence pack real_run incompleto | `AtlasRivalsEvidencePackService::generateForRealRun()` + verifier `MODE_REAL_RUN` + `REQUIRED_FIELDS_FOR_REAL_RUN` | `AtlasRivalsEvidencePackTest::test_verifier_real_run_mode_blocks_when_provider_receipt_missing` |
| Triage por suite, não por fingerprint | `AtlasRivalsInvalidBatteryTriageRegistry` | `AtlasRivalsInvalidBatteryTriageRegistryTest::test_triage_for_one_fingerprint_does_not_block_another` |

---

## 9. O que esta entrega NÃO faz

- **Não desbloqueia `external_rivals_certification`.** Continua governado em outro lugar; nenhum caminho deste módulo unlock-a.
- **Não dispara providers em testes.** `AtlasRivalsRunOrchestrator::MODE_REAL_PROVIDER` está deliberadamente refusing dispatch com `VERDICT_REAL_PROVIDER_REQUIRES_OPERATOR` — o dispatch real continua via `atlas:engineering:benchmark:rivals run` com flags `--confirm-*`.
- **Não move o score acima de `null`** enquanto o `external_rivals_certification` estiver bloqueado. Score é sempre `null` neste módulo, mesmo com `verdict='passed'`.
- **Não roda `git rm --cached`** do bytecode automaticamente. O preflight devolve o comando no campo `resolution_command`; o operador roda destrutivo.

---

## 10. Auditabilidade rápida

```bash
# Lista artefatos Python ainda tracked (deve ser 0 depois do cleanup)
git -C atlas-server ls-files -- '*.pyc' '*.pyo' '*__pycache__*' | wc -l

# Confere que nenhum unlock automático de external_rivals_certification existe
grep -rn 'external_rivals_certification' atlas-server/app | grep -v 'separated_from\|blocked' | wc -l   # deve ser 0

# Lista tudo que esta lockdown adicionou ao GLOBAL_HARD_FAIL_CONDITIONS
grep -A 30 'GLOBAL_HARD_FAIL_CONDITIONS' atlas-server/app/Services/Ai/Programming/AtlasRivalsOneShotEnterpriseRubricService.php

# Confirma a allowlist Sonnet
grep MODEL_LOCK_ALLOWLIST atlas-server/app/Services/Ai/FairClaudePolicy.php
```

---

## 11. Schemas adicionados

- `atlas.programming.workspace_hygiene.v1` (`WorkspaceHygieneService`)
- `atlas.programming.rivals_forge_readiness_fingerprint.v1` (`RivalsForgeReadinessFingerprintService`)
- `atlas.programming.rivals_forge_run_log_stream.v1` (`RivalsForgeRunLogStreamService`)
- `atlas.programming.rivals_forge_run_orchestrator.v1` (`AtlasRivalsRunOrchestrator`)
- `atlas.programming.rivals_invalid_battery_triage_registry.v1` (`AtlasRivalsInvalidBatteryTriageRegistry`)
- Atualizado: `AtlasRivalsOneShotEnterpriseRubricService` (mais 2 hard fails), `AtlasRivalsEvidencePackVerifierService` (`MODE_REAL_RUN`)
