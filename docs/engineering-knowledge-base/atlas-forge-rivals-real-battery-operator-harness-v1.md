---
id: atlas-forge-rivals-real-battery-operator-harness-v1
type: engineering_knowledge
title: Atlas Forge Rivals Real Battery Operator Harness v1
status: active
category: programming
priority: 100
summary: Harness operavel da bateria Rivals real do Atlas Forge. Define o fluxo end-to-end (worktrees -> preflight -> dry-run -> run quick -> evidence pack -> verify -> after-clean -> triage) com comandos copy-safe, state machine canonica de 15 estados, gates de seguranca em tres niveis, fingerprint-scoped triage e logs JSONL em streaming. Rivals e bateria de teste/operavel, nunca feature de produto.
tags:
  - atlas
  - forge
  - rivals
  - benchmark
  - operator
  - runbook
  - harness
capabilities:
  - forge_rivals_real_battery_operator_harness
  - forge_rivals_copy_safe_runbook
  - forge_rivals_state_machine_visibility
  - forge_rivals_fingerprint_scoped_triage
  - forge_rivals_evidence_streaming
  - forge_rivals_sonnet_vs_sonnet_run
decisions:
  - Rivals e bateria de teste operavel, NUNCA feature de produto novo.
  - Operator harness centraliza 11 acoes copy-safe; nada de comando improvisado.
  - Run real exige tres confirmacoes explicitas em uma mesma invocacao.
  - Triage e fingerprint-scoped: quarentena nao polui novo fingerprint.
  - Workspace dirty antes ou depois invalida a bateria sem excecao.
  - Resultado invalido NUNCA produz score ou claim; score=null.
  - external_rivals_certification permanece bloqueado por este harness.
maintenance:
  - Atualize este doc antes de alterar comandos, estados, gates de confirmacao, triage ou evidence pack do harness.
  - Mantenha o bloco de comandos copy-safe sincronizado com o que o operador roda no terminal.
  - Cada novo estado/verdict deve aparecer na state machine canonica.
related_paths:
  - app/Services/Ai/Programming/WorkspaceHygieneService.php
  - app/Services/Ai/Programming/RivalsForgeReadinessFingerprintService.php
  - app/Services/Ai/Programming/RivalsForgeRunLogStreamService.php
  - app/Services/Ai/Programming/AtlasRivalsRunOrchestrator.php
  - app/Services/Ai/Programming/AtlasRivalsInvalidBatteryTriageRegistry.php
  - app/Services/Ai/Programming/AtlasRivalsOperatorRunbookGenerator.php
  - app/Services/Ai/Programming/AtlasRivalsEvidencePackService.php
  - app/Services/Ai/Programming/AtlasRivalsEvidencePackVerifierService.php
  - app/Services/Ai/Kernel/Architecture/AtlasForgeRivalsRealBatteryOperatorHarnessCertification.php
  - scripts/rivals-harness-verify.sh
repo_paths:
  - docs/engineering-knowledge-base/atlas-forge-rivals-real-battery-operator-harness-v1.md
  - docs/engineering-knowledge-base/atlas-forge-rivals-reliability-lockdown-v1.md
  - app/Services/Ai/Programming/AtlasRivalsRunOrchestrator.php
  - app/Services/Ai/Programming/AtlasRivalsOperatorRunbookGenerator.php
  - app/Services/Ai/Programming/AtlasRivalsInvalidBatteryTriageRegistry.php
  - app/Services/Ai/Programming/WorkspaceHygieneService.php
  - app/Services/Ai/Kernel/Architecture/AtlasForgeRivalsRealBatteryOperatorHarnessCertification.php
  - scripts/rivals-harness-verify.sh
allowed_changes:
  - Ajustar texto dos comandos copy-safe desde que continuem rodando sem ambiguidade.
  - Adicionar estados/verdicts novos desde que apareçam na state machine canonica e sejam reconhecidos pelo evidence pack.
  - Adicionar troubleshooting para erros operacionais reais observados.
forbidden_changes:
  - Tornar Rivals uma feature de produto exposta a usuario final.
  - Remover qualquer um dos tres gates de confirmacao do run real.
  - Aceitar score/claim sem evidence pack real-run + replay + after-clean check.
  - Desbloquear external_rivals_certification por este harness.
  - Triage que reabilita fingerprint quarentenado sem novo fingerprint.
depends_on:
  - atlas-forge-rivals-reliability-lockdown-v1
  - atlas-forge-native-rivals-protocol-v1
  - atlas-programming-forge-flow
flows_to:
  - atlas:engineering:benchmark:rivals
  - atlas:programming:rivals-evidence-pack
  - atlas:programming:completion-audit
unlocks:
  - operator_sonnet_vs_sonnet_real_battery_with_audit_trail
  - fingerprint_scoped_quarantine_without_history_loss
governs:
  - forge_rivals_real_run_authorization
  - forge_rivals_state_machine_canonization
  - forge_rivals_operator_command_surface
evidence:
  - php artisan test --filter='Rivals|ForgeNativeRivals|AtlasForge|FairClaudePolicy'
  - php artisan atlas:programming:completion-audit --json
  - php artisan atlas:ai:architecture-validate --json
  - php artisan atlas:engineering:knowledge docs-health --json
  - bash scripts/rivals-harness-verify.sh
required_tests:
  - AtlasForgeRivalsReliabilityLockdownIntegrationTest
  - AtlasForgeNativeRivalsTest
  - AtlasRivalsRunOrchestratorTest
  - RivalsForgeReadinessFingerprintServiceTest
  - RivalsForgeRunLogStreamServiceTest
  - AtlasRivalsEvidencePackTest
requires_evidence: true
risk_level: high
next_actions:
  - Provisionar dois worktrees limpos (Atlas arm via Forge + baseline Claude Code isolado).
  - Rodar preflight Sonnet vs Sonnet e congelar fingerprint.
  - Rodar dry-run usando o mesmo fingerprint.
  - Rodar quick real com as tres confirmacoes obrigatorias.
  - Rodar verify do evidence pack + after-clean check.
  - Inscrever triage por fingerprint se a bateria for invalida.
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-forge-rivals-real-battery-operator-harness-v1
graph_title: Atlas Forge Rivals Real Battery Operator Harness v1
graph_world: atlas
graph_layer: system
graph_kind: runbook
graph_parent: atlas-forge-rivals-reliability-lockdown-v1
graph_status: active
graph_source: repo
owner: atlas-ai
---

# Atlas Forge Rivals Real Battery Operator Harness v1

## Resumo

Este harness e a interface operavel da bateria Rivals real do Atlas Forge. Ele garante que o operador consegue (i) preparar dois workspaces limpos, (ii) congelar fingerprint de readiness, (iii) rodar dry-run sem provider, (iv) executar a bateria real Sonnet vs Sonnet com tres gates de confirmacao, (v) verificar evidence + after-clean e (vi) decidir entre claim valido, blocker explicito ou triage scoped.

Rivals continua sendo **bateria de teste operavel**, nao feature de produto. Nenhum claim sai do harness sem evidence pack real-run completo, replay reproducivel e after-clean check verde.


## Papel no Atlas

O harness fecha o ciclo operacional do benchmark Rivals do Atlas Forge: provisiona worktrees isolados, calcula readiness fingerprint, planeja sem provider, gateia chamadas reais com tres confirmacoes, coleta evidence pack, replay manifest e decide se o resultado pode virar claim. NAO e feature do Atlas Code. NAO promove o Atlas como vencedor. NAO desbloqueia `external_rivals_certification`.

## Onde Se Encaixa

Filho de `atlas-forge-rivals-reliability-lockdown-v1` no graph canon. Consome os services existentes (`AtlasForgeNativeRivalsPreflightService`, `AtlasForgeNativeRivalsDryRunService`, `RivalsForgeReadinessFingerprintService`, `WorkspaceHygieneService`, `AtlasRivalsRunOrchestrator`, `AtlasRivalsEvidencePackService`, `AtlasRivalsEvidencePackVerifierService`, `RivalsForgeRunLogStreamService`, `AtlasRivalsInvalidBatteryTriageRegistry`) e expoe um comando unico `atlas:engineering:benchmark:rivals-harness`.

## Contratos

- Dry-run, preflight, doctor, replay, report, collect-evidence, setup-worktrees, reset-test-worktrees e full-smoke NUNCA chamam provider.
- `run-quick-real` so executa com `--confirm-runbook-reviewed --confirm-provider-cost --confirm-real-provider-call` simultaneamente presentes.
- Evidence pack incompleto, after_clean_check falho, replay manifest ausente ou tracked python bytecode invalidam o claim (`score=null`, `verdict=invalid`).
- Triage de invalid battery e fingerprint-scoped: marcar fingerprint X nao libera fingerprint Y; exige `--reviewer` e `--reason`.

## Fluxo

1. setup-worktrees → 2. doctor → 3. preflight → 4. dry-run → 5. quick-real-plan → 6. run-quick-real (com 3 confirmacoes) → 7. collect-evidence → 8. replay → 9. report. `full-smoke` encadeia 1-4 + 7-9 sem chamar provider; `reset-test-worktrees` apaga e re-provisiona com `--reason` obrigatorio.

## Regras para IA

- Nao transformar este harness em feature de produto.
- Nao mascarar workspace dirty, tracked `.pyc`, fingerprint mismatch, evidence faltante ou after_clean falho.
- Nao aceitar fallback silencioso de modelo (`opus`/`sonnet` allowlist explicito).
- Preferir blocker explicito + `commands_next` copy-safe a resultado parcial.
- Nao desbloquear `external_rivals_certification` ou promover Atlas como vencedor.

## Escopo de Implementacao

Entregue nesta camada: `AtlasRivalsHarnessCommand` (`atlas:engineering:benchmark:rivals-harness`), `AtlasRivalsBatteryStateMachine` (read-model atlas.rivals.battery_state.v1), `AtlasRivalsOperatorRunbookGenerator` (runbook copy-safe), `AtlasRivalsTestWorktreeProvisioner` (provision/reset/inspect), `AtlasForgeRivalsRealBatteryOperatorHarnessCertification` (audit invariantes), 18 testes feature (`AtlasRivalsHarnessOperatorTest`), `scripts/rivals-harness-verify.sh`. Fora de escopo: provider dispatch automatico, UI, Cartografia, Voice, Self-Improvement.

## Dependencias

- `atlas-forge-rivals-reliability-lockdown-v1`
- `atlas-forge-native-rivals-protocol-v1`
- Services Programming Rivals existentes (lockdown v1).
- `AtlasForgeRivalsRealBatteryOperatorHarnessCertification` audit.

## Evidencias

```bash
PYTHONDONTWRITEBYTECODE=1 /opt/homebrew/bin/php artisan test tests/Feature/Ai/Programming --filter='Rivals|ForgeNativeRivals|AtlasForge|FairClaudePolicy'
/opt/homebrew/bin/php artisan atlas:engineering:benchmark:rivals-harness doctor --json --strict
/opt/homebrew/bin/php artisan atlas:engineering:benchmark:rivals-harness full-smoke --json
/opt/homebrew/bin/php artisan atlas:engineering:benchmark:rivals-harness audit --json
bash /Users/vitorepf/develop/Atlas/atlas-server/scripts/rivals-harness-verify.sh
```

## Riscos

- Bateria real gasta tokens (Anthropic API · Atlas Forge + Claude Code CLI). Tres confirmacoes sao a unica salvaguarda · trate-as como aprovacao de gasto.
- Workspace principal dirty bloqueia `setup-worktrees` antes mesmo do provisionamento · sempre opere em worktree, nunca no repo principal.
- Tracked `.pyc`/`__pycache__` no indice git invalida toda execucao Python · remova via `git rm --cached -r '*.pyc' '*.pyo' '*__pycache__*'`.
- Triage de fingerprint historic invalido nao libera baterias futuras de outros fingerprints · cada caso tem seu receipt + reviewer + reason.

## Exemplos

Smoke (sem provider):
```bash
/opt/homebrew/bin/php artisan atlas:engineering:benchmark:rivals-harness full-smoke --json
```
Plano antes do gasto real:
```bash
/opt/homebrew/bin/php artisan atlas:engineering:benchmark:rivals-harness quick-real-plan --model=sonnet --baseline-model=sonnet --json
```
Run real Sonnet vs Sonnet (gasta tokens):
```bash
/opt/homebrew/bin/php artisan atlas:engineering:benchmark:rivals-harness run-quick-real \
  --atlas-worktree=/tmp/atlas-rivals/atlas \
  --baseline-worktree=/tmp/atlas-rivals/baseline \
  --model=sonnet --baseline-model=sonnet \
  --confirm-runbook-reviewed --confirm-provider-cost --confirm-real-provider-call \
  --json
```
## Matrix Runner v1 (8x5 corpus consumer)

Camada nova entregue 2026-05-16 sobre o multi-case v1: o runner agora **consome** o corpus 8 categorias × 5 dificuldades (40 cases) que o Claude 1 está formando, sem editar o corpus. Tudo dirigido por dados — qualquer expansão futura (10×6, 12×7, etc.) reaproveita o mesmo pipeline.

### Campos da matriz preservados por case

O adapter (`AtlasForgeRivalsRunRealService::adaptCorpusCase`) passa os campos canônicos da matriz para o runner e o BatteryStateService grava em `battery.json::cases[]`:

| Campo | Tipo | Origem | Fallback |
|---|---|---|---|
| `category` | string | corpus declarado | `''` |
| `task_category` | string | corpus declarado (legacy alias) | derivado de `category` |
| `difficulty` | string `easy\|medium\|hard` | corpus declarado | `''` |
| `difficulty_level` | string `L1`…`L5` | corpus declarado | `difficultyToLevel(difficulty)` |
| `difficulty_weight` | float | ladder L1-L5 (1.0 → 3.0) | mapeamento canon |
| `difficulty_score` | float | corpus declarado (Schema Contract Service) | `difficulty_weight` |
| `planning_weight` | float | corpus declarado | `difficulty_weight` |
| `execution_weight` | float | corpus declarado | `difficulty_weight` |

Tudo passa por `BatteryStateService::initialize` sem hardcode de N — `case_count` é sempre `count($cases)`. 40 ou 60 ou 100, o pipeline trata igual.

### Status canônico (counters)

`status` retorna o bloco `progress` para qualquer bateria (vazia ou cheia):

```json
"progress": {
  "total": 40,
  "passed": 7,
  "failed": 2,
  "invalid": 1,
  "running": 1,
  "pending": 28,
  "skipped": 1,
  "remaining": 29
}
```

`remaining = pending + running`. Quando não há bateria inicializada, todos voltam `0` — o cliente nunca precisa de `if (battery['exists'])` para ler counters.

### Action `next` battery-aware

`AtlasForgeRivalsNextService` agora checa `battery.json` antes do manifest legado:

- Bateria com `pending_case_count > 0` → `phase: battery_paused_pending_cases` + `command: php artisan atlas:forge:rivals resume --run-id=<id> --confirm-runbook-reviewed --confirm-provider-cost --confirm-real-provider-call --json` (sempre carrega as 3 confirmações como template; operador edita se quiser).
- Bateria com todos os cases terminal → `phase: battery_settled_all_cases_terminal` + `command: php artisan atlas:forge:rivals battery-report --run-id=<id> --json`.
- Sem bateria → cai no advisor de pipeline atual (run-real / setup / etc.).

`observations.battery_next_case_id` é o `case_id` exato do próximo case a rodar — `next` responde "qual é o próximo case?" e "qual é o comando seguro pra continuar?" no mesmo envelope.

### BatteryReport: 3 scores adicionais

`battery-report` (rota canônica `AtlasForgeRivalsBatteryReportService`) agora emite quatro blocos de score:

| Bloco | Pesos lidos | Significado |
|---|---|---|
| `weighted_score` | `difficulty_weight` (L1-L5) | score histórico, sempre populado |
| `planning_score` | `planning_weight` (matrix) | quanto a bateria pontuou no eixo de planejamento |
| `execution_score` | `execution_weight` (matrix) | quanto a bateria pontuou no eixo de execução |
| `difficulty_score` | `difficulty_score` (matrix) | índice de dificuldade agregado |

Quando o corpus ainda não declara um dos pesos (fase de transição do Claude 1), o adapter falha para `difficulty_weight` e o score continua honesto — nenhum 0 falso. `report.md` mostra os 4 blocos como linhas separadas no `Overview`.

### Como o runner processa N cases

```
RunRealService::run(input)
  └── resolveCaseContext(input, preset) ──→ N cases (40, 60, 100, …)
       ├── source=provider_arena_corpus  (case_set declarado OU preset=release)
       └── adaptCorpusCase × N           (passa-through todos os pesos da matriz)
  └── battery.initialize(runId, ctx, allCases) ──→ battery.json com N entries pending
  └── isResume? filter cases para apenas state ∈ {pending, running}
  └── foreach case:
       ├── battery.markCaseRunning(runId, caseId)
       ├── reset worktree (entre cases)
       ├── stage fixture + workspace_hash_before
       ├── runArm atlas / runArm rival   (em local_fake: zero subprocess)
       ├── after_clean_check
       ├── verdict por case (worst-of)
       ├── persistir per-case em cases/<case_id>/
       └── battery.markCaseFinished(runId, caseId, verdict, summary)
  └── battery.finalize(runId, aggregate)
       └── computeBatteryAggregateVerdict / computeBatteryClaimReady
```

### Como retoma falhas

```
operator: ^C ou exit 1 → bateria fica em battery_status=paused com N pending
operator runs:
  php artisan atlas:forge:rivals next --run-id=<id> --json
  └── retorna { phase: battery_paused_pending_cases, command: 'resume --run-id=<id> --confirm-* --json' }
operator copy-pastes resume:
  └── RunReal lê battery.json, encontra resume_count > 0, filtra cases→pendings
  └── battery.recordEvent('battery_resumed', { resume_count: 1, pending_case_count: N })
  └── continua iterando do próximo pending
```

Cases já terminais nunca re-rodam automaticamente. Para forçar re-execução, operador chama `markCaseRunning` via API ou usa `reset --run-id=<id> --reason=…` (apaga a bateria inteira).

### Como difficulty metadata é preservada end-to-end

```
corpus (Claude 1 v1)
  └── case manifest declara: difficulty, difficulty_level, difficulty_score,
                              planning_weight, execution_weight
       (Schema Contract Service valida no init do app)
adapter (adaptCorpusCase)
  └── persiste todos os campos no payload do case (com fallback para
       difficulty_weight quando o corpus ainda não declara)
battery.json (BatteryStateService)
  └── cada cases[] grava: difficulty / difficulty_level / difficulty_weight /
                          difficulty_score / planning_weight / execution_weight
events.jsonl
  └── case_started + case_finished carregam difficulty_level + task_category
report (BatteryReportService)
  └── 3 scores separados (planning, execution, difficulty) +
       breakdown por L1-L5 + breakdown por categoria
audit / replay
  └── difficulty_level continua disponível no manifest e na cases/<id>/*
```

Difficulty é preservada em TODA camada — sem regravar, sem perder, sem inferir.

## Multi-case Release Runner v1 (battery.json + L1-L5 + resume)

Camada nova entregue 2026-05-16 sobre o v2 single-button: cada run de bateria persiste um `battery.json` canônico e um `battery.jsonl` append-only no nível da bateria, com suporte explícito a resume seguro, estados por case e ladder de dificuldade L1-L5.

### Layout canônico

```
/Users/vitorepf/develop/Atlas-rivals/runs/<run_id>/
  ├── atlas/                              # worktree do braço Atlas Forge (git worktree add)
  ├── rival/                              # worktree do braço baseline (git worktree add)
  ├── battery.json                        # catálogo da bateria (schema v1)
  ├── battery.jsonl                       # eventos de bateria (battery_started/case_state_changed/...)
  ├── events.jsonl                        # eventos detalhados do run (provider_started/heartbeat/...)
  ├── intent.json                         # snapshot da intenção do run
  └── evidence/
      ├── manifest.json                   # manifest da sessão atual (multi → worst-of)
      ├── atlas_receipt.json              # receipt agregado (worst-of)
      ├── rival_receipt.json              # idem
      ├── workspace_hashes.json
      ├── battery_report.md               # report agregado por categoria + L1-L5
      ├── scorecard.json                  # produzido pelo adjudicator
      ├── report.md                       # report executivo do adjudicator (já existia)
      └── cases/
          ├── backend-pagination-off-by-one/
          │   ├── atlas_receipt.json      # per-case
          │   ├── rival_receipt.json
          │   ├── workspace_hashes.json
          │   ├── atlas_patch.diff
          │   ├── rival_patch.diff
          │   ├── atlas_test.log
          │   ├── rival_test.log
          │   ├── atlas_provider_stdout.log
          │   ├── atlas_provider_stderr.log
          │   ├── rival_provider_stdout.log
          │   └── rival_provider_stderr.log
          ├── frontend-form-validation-accessibility/...
          └── ...
```

### Schema `atlas.forge.rivals.battery.v1`

Exemplo enxuto (shape canônico produzido por `php artisan atlas:forge:rivals run-battery --case-set=quick --dry-run --json`):

```json
{
  "schema_version": "atlas.forge.rivals.battery.v1",
  "run_id": "battery-20260516-001242-wilufk",
  "preset": "smoke", "case_set": "quick", "mode": "fair",
  "atlas_model": "claude_sonnet", "rival_model": "claude_sonnet",
  "started_at": "2026-05-16T00:12:42+00:00", "updated_at": "...", "finished_at": null,
  "resume_count": 0, "case_count": 3,
  "cases": [
    { "case_id": "backend-pagination-off-by-one", "case_index": 0, "task_category": "bugfix",
      "difficulty": "easy", "difficulty_level": "L1", "difficulty_weight": 1.0,
      "state": "pending", "verdict": null, "attempts": 0,
      "evidence_dir": "cases/backend-pagination-off-by-one" },
    { "case_id": "frontend-form-validation-accessibility", "difficulty_level": "L3", "state": "pending", "...": "..." },
    { "case_id": "performance-n-plus-one-query", "difficulty_level": "L5", "state": "pending", "...": "..." }
  ],
  "battery_status": "running", "aggregate_verdict": null, "claim_ready": false,
  "external_provider_call": false, "provider_tokens_spent": false,
  "separated_from_external_rivals_certification": true
}
```

### Estados por case e por bateria

Per-case (`cases[].state`):

| Estado | Origem | Como sair |
| --- | --- | --- |
| `pending` | criado na inicialização | runner avança ao chamar `markCaseRunning` |
| `running` | runner começou o case (start ou retry) | termina via `markCaseFinished` |
| `completed` | `verdict=comparable` | terminal |
| `failed` | `verdict in {invalid_tests_failed, invalid_no_patch_diff, inconclusive, invalid_provider_timeout}` | terminal |
| `invalid` | `verdict in {invalid_workspace_after_run, invalid_fixture_blocked}` | terminal |
| `skipped` | `markCaseSkipped(reason)` chamado pelo operador | terminal |

Per-battery (`battery_status`):

| Estado | Significado |
| --- | --- |
| `pending` | inicializada mas runner ainda não chamou `finalize` |
| `running` | runner em execução |
| `paused` | `finalize` rodou mas ainda há cases pending (resume disponível) |
| `completed` | todos os cases em terminal e nenhum blocker |
| `blocked` | finalize chamado com `blocked=true` (ex.: todos cases fixture-blocked) |
| `stalled` | sem heartbeat há mais que o limite |

### Ladder L1-L5 e como dificuldade entra no score

`AtlasForgeRivalsProviderArenaCorpusService` é a única fonte de verdade do mapping (constantes `DIFFICULTY_LEVEL_L1..L5`, `DIFFICULTY_TO_LEVEL`, `DIFFICULTY_LEVEL_SCORE_WEIGHTS`):

| Bucket corpus | Level canon | Peso default no score |
| --- | --- | --- |
| `easy` | `L1` | 1.0 |
| — | `L2` (reservado) | 1.5 |
| `medium` | `L3` | 2.0 |
| — | `L4` (reservado) | 2.5 |
| `hard` | `L5` | 3.0 |

`AtlasForgeRivalsBatteryReportService` calcula `weighted_score_percent = sum(completed_weights) / sum(all_weights) * 100`. O score é **informativo** — `claim_ready=false` continua absoluto quando qualquer case está fora de `completed`, e `external_rivals_certification` permanece selado.

### CLI

```
# planeja sem provider (sem confirmações)
php artisan atlas:forge:rivals run-battery --case-set=quick --dry-run --json --strict

# bateria real release (12 cases). exige as 3 confirmações
php artisan atlas:forge:rivals run-battery \
  --preset=release --mode=fair \
  --atlas-model=sonnet --rival=claude_sonnet \
  --confirm-runbook-reviewed --confirm-provider-cost --confirm-real-provider-call \
  --json --strict

# inspeciona a bateria + heartbeat + battery snapshot
php artisan atlas:forge:rivals status --run-id=<run_id> --json

# devolve o próximo case pendente (ou null)
php artisan atlas:forge:rivals next --run-id=<run_id> --json

# retoma a bateria, executando só os cases ainda pending
php artisan atlas:forge:rivals resume --run-id=<run_id> \
  --confirm-runbook-reviewed --confirm-provider-cost --confirm-real-provider-call \
  --json --strict

# report agregado por categoria + L1-L5 (lê battery.json)
php artisan atlas:forge:rivals battery-report --run-id=<run_id> --json
```

### Resume seguro

`resume` (action) é equivalente a `run-battery --resume`. Em qualquer caso, o runner:

1. Lê `battery.json` (se existe). Bumps `resume_count`.
2. Filtra `cases` para apenas `pending`/`running` (cases terminal nunca re-rodam automaticamente).
3. Se nenhum case pending: retorna `note=all_cases_already_terminal_in_battery_resume` sem rodar nada.
4. Senão: executa apenas os cases pending, mantendo `runs/<run_id>/cases/<case_id>/` dos cases anteriores intocados.

`status` retorna `next_command` apontando para `resume` quando a bateria está paused e há cases pending.

### Contratos absolutos

- `claim_ready=false` enquanto qualquer case não estiver em `completed`.
- `external_provider_call=false` e `provider_tokens_spent=false` em todo response de dry-run / blocked / local_fake.
- `separated_from_external_rivals_certification=true` em todos os responses.
- Nenhum case `skipped`/`failed`/`invalid`/`pending` pode produzir score final.
- Per-case `attempts` incrementa a cada `markCaseRunning` — auditável.

## Release multi-case (v2 single-button)

A bateria release-ready usa o entrypoint canonico `atlas:forge:rivals run-battery`. Um unico comando, com tres confirmacoes obrigatorias, executa o corpus arena release inteiro (>=12 cases) em worktrees isolados, com streaming JSONL e per-case evidence:

```bash
/opt/homebrew/bin/php artisan atlas:forge:rivals run-battery \
  --preset=release \
  --mode=fair \
  --atlas-model=sonnet \
  --rival=claude_sonnet \
  --confirm-runbook-reviewed \
  --confirm-provider-cost \
  --confirm-real-provider-call \
  --json \
  --strict
```

Contrato:

- `--preset=release` sem `--case-set` mapeia automaticamente para o Provider Arena release corpus (todos os cases canonicos). Quick/smoke/full continuam apontando para a legacy single-case registry, sem regressao.
- Cada case e executado em sequencia no mesmo run_id. Entre cases, ambos os worktrees (atlas + baseline) sao resetados para HEAD via `git reset --hard HEAD` + `git clean -fdx`, garantindo que cada case parta de baseline deterministico.
- Per-case artifacts ficam em `runs/<run_id>/evidence/cases/<case_id>/{atlas_receipt.json, rival_receipt.json, workspace_hashes.json, atlas_patch.diff, rival_patch.diff, atlas_test.log, rival_test.log, atlas_provider_stdout.log, atlas_provider_stderr.log, rival_provider_stdout.log, rival_provider_stderr.log}`.
- Top-level `runs/<run_id>/evidence/{atlas_receipt.json, rival_receipt.json, workspace_hashes.json, manifest.json}` continua existindo como worst-of agregado (exit_code/test_exit_code = primeiro != 0, patch_diff_bytes = MIN, changed/oos/bytecode = UNION). Esse agregado e o que o adjudicator existente le sem precisar de mudancas: qualquer case que sangra fora do escopo, deixa bytecode, falha teste ou falha provider trip a hard gate.
- `manifest.json` ganha campos novos `case_count`, `is_multi_case`, `cases[]`. Para single-case o shape permanece **identico** ao v1 (zero shape drift).
- `events.jsonl` emite `case_started`/`case_finished` por case, alem dos eventos existentes (`provider_started`, `provider_stdout_chunk`, `heartbeat`, `after_clean_check`, `evidence_pack`, `final_report`). Cada `provider_started` carrega `case_id` e `case_subdir`.
- `claim_ready=true` exige TODOS os cases comparable, nenhum killed, e modo != local_fake. Qualquer caso invalido forca `claim_ready=false`. local_fake nunca claima.
- `verdict` agregado e worst-of: `invalid_workspace_after_run` > `invalid_provider_timeout` > `inconclusive` > `invalid_tests_failed` > `invalid_no_patch_diff` > `comparable`.
- `separated_from_external_rivals_certification=true` em todo response, sempre.

### Diferenca dry-run vs run real

- `dry-run`: provider NUNCA invocado. Planeja replay manifest, calcula fingerprint, valida case-set. Mesmo com `--preset=release` nao gasta token. Nao exige confirmacoes.
- `run-real` (e `run-battery` em modo fair/full_power): exige as tres confirmacoes simultaneas; sem qualquer uma o response e `status=blocked` com `missing_confirmation:<flag>`, `external_provider_call=false`, `provider_tokens_spent=false`.

### Fail-closed honesto

O response ja carrega tudo que adjudicator/Claude C/D/E precisam para decidir:

- `status` ∈ {ok, blocked}
- `verdict` ∈ {comparable, invalid_workspace_after_run, invalid_provider_timeout, inconclusive, invalid_tests_failed, invalid_no_patch_diff, invalid_fixture_blocked}
- `claim_ready` (sempre false ate adjudicator + report rodarem em pipeline verde)
- `external_provider_call`, `provider_tokens_spent` (nunca `true` sem as tres confirmacoes)
- `case_count`, `is_multi_case`, `cases[]` (matter-prima multi-case)
- `evidence_paths[]` (lista plana com top-level + per-case artifacts)

### Como Claude C/D/E consomem

- Claude C (adjudicator final): le `manifest.cases[]`, `manifest.score`, `manifest.verdict`, e os per-case receipts em `evidence/cases/<case_id>/`. Hard gates ja sao tripados pelo agregado worst-of, mas Claude C pode reabrir caso a caso para scoring premium.
- Claude D (report premium): le manifest agregado + per-case summaries; gera report.md por case e overview agregado.
- Claude E (continuum / regressao): le decide-signal + ledger entries; consome `manifest.case_count` e `cases[].verdict` para estatistica longitudinal.

Nunca: nenhum desses agentes deve revisar `external_rivals_certification`. O runner garante `separated_from_external_rivals_certification=true` e `claim_ready=false` sempre.

## Nao e feature de produto

Rivals nao tem UI publica, nao gera nota promocional automatica, nao desbloqueia external_rivals_certification. O harness existe para que o time tenha um meio confiavel de medir Atlas Forge contra um baseline externo, em condicoes comparaveis, sem inventar score. Qualquer tentacao de transformar isso em feature deve ser bloqueada na revisao.

## Fluxo correto (11 acoes)

```
1.  Provisionar worktrees limpos (Atlas arm + baseline arm)
2.  Limpar .pyc rastreado (uma vez, com commit explicito)
3.  Preflight Sonnet vs Sonnet
4.  Congelar readiness fingerprint
5.  Dry-run (planeja replay, nao chama provider)
6.  Confirmar runbook revisado, custo de provider e chamada real
7.  Run quick com tres confirmacoes
8.  Acompanhar JSONL streaming em tempo real
9.  Verify evidence pack (after-clean, replay, campos obrigatorios)
10. Decidir: claim valido | blocker | triage fingerprint-scoped
11. Registrar resultado + arquivar run_id
```

## Comandos copy-safe

Cada bloco e auto-contido e nao depende de variavel de ambiente externa. Substitua `<clean-atlas-worktree>` e `<clean-baseline-worktree>` pelos caminhos absolutos das worktrees.

```bash
# (1) Provisionar worktrees limpos
git -C /Users/vitorepf/develop/Atlas/atlas-server worktree add /tmp/rivals/atlas main
git -C /Users/vitorepf/develop/Atlas/atlas-server worktree add /tmp/rivals/baseline main

# (2) Limpar .pyc rastreado (uma unica vez, com commit explicito)
git -C /Users/vitorepf/develop/Atlas/atlas-server rm --cached -r 'runtimes/python/**/__pycache__' '*.pyc' '*.pyo'

# (3) Preflight Sonnet vs Sonnet
/opt/homebrew/bin/php artisan atlas:engineering:benchmark:rivals preflight \
  --workspace=<clean-atlas-worktree> \
  --claude-code-baseline-workspace=<clean-baseline-worktree> \
  --model=sonnet --baseline-model=sonnet \
  --json --strict

# (4) Congelar fingerprint (lido do payload preflight; estavel para dry-run + run)
# (sem comando separado: o fingerprint vive na saida JSON do preflight)

# (5) Dry-run
/opt/homebrew/bin/php artisan atlas:engineering:benchmark:rivals dry-run \
  --workspace=<clean-atlas-worktree> \
  --claude-code-baseline-workspace=<clean-baseline-worktree> \
  --model=sonnet --baseline-model=sonnet \
  --json --strict

# (6) Inspecionar runbook gerado
/opt/homebrew/bin/php artisan atlas:engineering:benchmark:rivals runbook \
  --workspace=<clean-atlas-worktree> \
  --claude-code-baseline-workspace=<clean-baseline-worktree> \
  --model=sonnet --baseline-model=sonnet \
  --markdown

# (7) Run quick real (tres confirmacoes obrigatorias)
/opt/homebrew/bin/php artisan atlas:engineering:benchmark:rivals run \
  --quick \
  --workspace=<clean-atlas-worktree> \
  --claude-code-baseline-workspace=<clean-baseline-worktree> \
  --model=sonnet --baseline-model=sonnet \
  --confirm-runbook-reviewed \
  --confirm-provider-cost \
  --json

# (8) Acompanhar logs streaming
tail -F /Users/vitorepf/develop/Atlas/atlas-server/storage/app/rivals-forge-runs/<run_id>/events.jsonl

# (9) Verify evidence pack
/opt/homebrew/bin/php artisan atlas:engineering:benchmark:rivals verify <run_id> --json

# (10) Replay (reproducir resultado sem novo provider call)
/opt/homebrew/bin/php artisan atlas:engineering:benchmark:rivals replay <run_id> --json

# (11) Triage de bateria invalida (fingerprint-scoped)
/opt/homebrew/bin/php artisan atlas:engineering:benchmark:rivals triage-invalid-battery <run_id> \
  --reviewer="<operator-handle>" \
  --reason="<motivo-objetivo>" \
  --confirm-invalid-battery-quarantine \
  --json
```

## Estados (state machine canonica)

A bateria transita por 15 estados/verdicts canonicos. Qualquer estado fora desta lista deve ser tratado como bug do harness.

1. `not_started` — operador ainda nao rodou preflight.
2. `preflight_blocked` — workspace dirty, fingerprint incompleto, baseline workspace invalido.
3. `preflight_ready` — fingerprint estavel, workspaces limpos.
4. `dry_run_planned` — replay planejado, sem provider.
5. `dry_run_blocked` — fingerprint divergente do preflight ou caso ausente.
6. `awaiting_confirmation` — confirmacoes pendentes antes do run real.
7. `run_in_progress` — runner emitindo JSONL, heartbeat ativo.
8. `run_stalled` — heartbeat parado alem do limite.
9. `run_complete_pending_verify` — runner terminou; verify ainda nao rodou.
10. `valid` — evidence pack ok, after-clean limpo, replay reproducivel.
11. `invalid_dirty_after_run` — workspace ficou dirty depois da execucao.
12. `invalid_evidence_missing` — campos obrigatorios ausentes no evidence pack.
13. `invalid_fingerprint_mismatch` — fingerprint do run != fingerprint congelado.
14. `quarantined_fingerprint_scoped` — triage registrou esse fingerprint como invalido; novo fingerprint pode rodar de novo.
15. `claimed_valid` — bateria virou claim auditavel apos verify + after-clean.

Resultado invalido (11, 12, 13) **nunca** vira score; o orchestrator forca `score=null`.

## Safety: tres gates de confirmacao

O run real exige na mesma invocacao:

- `--confirm-runbook-reviewed` — o operador leu o runbook gerado em (6) acima.
- `--confirm-provider-cost` — o operador aceita o gasto estimado em tokens.
- `--confirm-real-provider-call` — exigido somente quando o runner detecta provider live (preset full e cases sensiveis); o harness instrui o operador quando o terceiro gate e obrigatorio.

Faltar qualquer um dos tres bloqueia o run com motivo explicito. O harness nao infere consentimento.

## Triage fingerprint-scoped

Triage NUNCA invalida um suite inteiro. Ele invalida o fingerprint especifico daquela bateria. Mudou modelo, preset, workspace ou caso? Fingerprint muda, e o novo run pode rodar sem ser bloqueado pelo historico.

```bash
/opt/homebrew/bin/php artisan atlas:engineering:benchmark:rivals triage-invalid-battery <run_id> \
  --reviewer="atlas-operator-vitor" \
  --reason="baseline workspace dirty depois do run; nao reproducivel" \
  --confirm-invalid-battery-quarantine \
  --json
```

Cada triage exige `--reviewer`, `--reason` e `--confirm-invalid-battery-quarantine`. O registry guarda fingerprint, motivo, reviewer e timestamp ISO.

## Evidence + Logs

- Eventos JSONL em `storage/app/rivals-forge-runs/<run_id>/events.jsonl`.
- `run_id` ULID estavel desde o primeiro evento.
- `heartbeat` a cada 10s; ausencia por 60s gera `run_stalled`.
- `final_summary` carrega verdict, score (ou null), after_clean_check, replay_manifest, provider_receipt.
- `after_clean_check` re-roda hygiene apos o run e compara hash before/after.
- Evidence pack guarda before/after hash, diff, test log, quality log, provider receipt, replay manifest executado e timeline.

## Como rodar Sonnet vs Sonnet (passo a passo)

1. Provisionar `/tmp/rivals/atlas` e `/tmp/rivals/baseline` com `git worktree add`.
2. Garantir que ambos os worktrees estao em commit limpo (`git -C <path> status --porcelain` retorna vazio).
3. Rodar preflight Sonnet vs Sonnet com `--strict --json`.
4. Conferir `ready_for_provider_battery=true` e `readiness_fingerprint` no payload.
5. Rodar dry-run com os mesmos modelos e workspaces; conferir que o fingerprint nao mudou.
6. Gerar runbook em markdown e ler integralmente.
7. Rodar `run --quick` com `--confirm-runbook-reviewed --confirm-provider-cost`.
8. Acompanhar JSONL em outro terminal.
9. Apos termino, rodar verify e replay.
10. Se ambos retornarem ok, marcar como `claimed_valid`. Se algum falhar, abrir triage.

## Como interpretar resultado

- `verdict=valid` + `score>=0` + `after_clean=clean` + `replay=ok` -> claim valido.
- `verdict=invalid_*` -> score forcado para `null`. Nenhum claim sai daqui.
- `verdict=run_stalled` -> tratar como invalido; abrir triage com motivo `stalled_runner`.
- `verdict=quarantined_fingerprint_scoped` -> resultado nao publicavel; novo fingerprint pode tentar de novo.

## O que nunca vira claim

- Sem evidence pack real-run completo -> ZERO claim.
- Sem replay reproducivel -> ZERO claim.
- Sem after-clean check verde -> ZERO claim.
- Com fingerprint mismatch -> ZERO claim.
- Com qualquer gate de confirmacao faltando -> a bateria nem comeca.
- Mesmo com `score` numerico, se qualquer item acima falhar, score e descartado.

## Troubleshooting

| Sintoma | Causa provavel | Acao |
| --- | --- | --- |
| `workspace_dirty_before_run` | Mudancas nao commitadas no worktree | `git -C <worktree> status` e commitar/limpar |
| `tracked_python_bytecode_blocked` | `.pyc` rastreado no indice | Rodar o `git rm --cached` do passo (2) uma unica vez |
| `provider_unavailable` | CLI baseline nao encontrado ou key ausente | Conferir `--claude-code-baseline-binary` e env do provider |
| `fingerprint_mismatch` | Algum parametro mudou entre preflight e run | Re-rodar preflight + dry-run + run em sequencia, sem editar opcoes |
| `run_stalled` | Heartbeat parou; provider travou ou CLI bloqueou | Abrir triage com motivo `stalled_runner`; nao re-rodar sem novo fingerprint |
| `evidence_missing_after_clean_check` | Workspace ficou dirty depois do run | Marcar bateria como `invalid_dirty_after_run` e triage |

## Proximas Acoes

1. Provisionar dois worktrees limpos.
2. Rodar preflight + dry-run Sonnet vs Sonnet.
3. Executar quick real com as tres confirmacoes.
4. Rodar `bash scripts/rivals-harness-verify.sh` no fim de cada sessao.
5. Manter triage fingerprint-scoped — historico de invalidos nao deve poluir novo fingerprint.
6. Reabrir este doc antes de qualquer mudanca em comando, estado ou gate.
