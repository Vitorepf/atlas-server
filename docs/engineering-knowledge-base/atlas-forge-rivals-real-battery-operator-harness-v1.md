---
id: atlas-forge-rivals-real-battery-operator-harness-v1
type: engineering_knowledge
title: Atlas Forge Rivals Real Battery Operator Harness v1
status: source_material
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
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
  - docs/engineering-knowledge-base/atlas-forge-rivals-real-battery-operator-harness-v1-part-01.md
  - docs/engineering-knowledge-base/atlas-forge-rivals-real-battery-operator-harness-v1-part-02.md
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
human_name: Atlas Forge Rivals Real Battery Operator Harness v1
canonical_name: Atlas Forge Rivals Real Battery Operator Harness v1
technical_name: atlas-forge-rivals-real-battery-operator-harness-v1
cartography_type: runbook
canonical_source: docs/engineering-knowledge-base/atlas-forge-rivals-real-battery-operator-harness-v1.md
owner: atlas-ai
---
line_limit: 520

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

## Regras Para IA

- Nao transformar este harness em feature de produto.
- Nao mascarar workspace dirty, tracked `.pyc`, fingerprint mismatch, evidence faltante ou after_clean falho.
- Nao aceitar fallback silencioso de modelo (`opus`/`sonnet` allowlist explicito).
- Preferir blocker explicito + `commands_next` copy-safe a resultado parcial.
- Nao desbloquear `external_rivals_certification` ou promover Atlas como vencedor.

## Escopo De Implementacao

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

## Proximas Acoes

- Manter os recortes filhos sincronizados com mudanças em comandos, estados,
  gates, triage, evidence pack e troubleshooting.
- Rodar docs-health depois de qualquer alteração neste harness.
- Nunca promover claim Rivals sem evidence pack real-run, replay e after-clean
  check verdes.

## Detalhes Extraidos

O runbook detalhado de matrix runner, release multi-case, comandos, estados, triage e troubleshooting foi movido para recortes filhos para manter este harness legível.

- `docs/engineering-knowledge-base/atlas-forge-rivals-real-battery-operator-harness-v1-part-01.md` — Matrix Runner v1 (8x5 corpus consumer) ate Multi-case Release Runner v1 (battery.json + L1-L5 + resume).
- `docs/engineering-knowledge-base/atlas-forge-rivals-real-battery-operator-harness-v1-part-02.md` — Release multi-case (v2 single-button) ate Proximas Acoes.
