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
