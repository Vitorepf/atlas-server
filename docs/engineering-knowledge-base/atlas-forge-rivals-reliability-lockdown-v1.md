---
id: atlas-forge-rivals-reliability-lockdown-v1
type: engineering_knowledge
title: Atlas Forge Rivals Reliability Lockdown v1
status: active
category: programming
priority: 100
summary: Lockdown do harness Rivals do Atlas Forge: workspace hygiene, fingerprint unico, Sonnet model lock, quick preset real, streaming JSONL, evidence pack real-run e triage por fingerprint. Rivals e benchmark/teste, nao feature de produto.
tags:
  - atlas
  - forge
  - rivals
  - benchmark
  - reliability
capabilities:
  - forge_rivals_preflight
  - forge_rivals_dry_run
  - forge_rivals_readiness_fingerprint
  - forge_rivals_streaming_runner
  - forge_rivals_evidence_pack
  - forge_rivals_sonnet_lock
unlocks:
  - safe_forge_rivals_provider_battery_preflight
  - sonnet_vs_sonnet_rivals_benchmark
  - real_run_evidence_pack_without_synthetic_claim
decisions:
  - Rivals e bateria de teste/benchmark, nao produto novo.
  - Atlas arm deve usar Forge; baseline deve usar workspace externo isolado.
  - Resultado invalido nunca vira score ou claim.
  - Workspace dirty antes/depois, .pyc rastreado, fingerprint divergente, evidence faltando ou stall invalidam a bateria.
maintenance:
  - Atualize este documento antes de alterar preflight, dry-run, runner, evidence pack, triage ou model lock do Rivals.
  - Mantenha comandos sem provider separados dos comandos com custo real.
related_paths:
  - app/Services/Ai/Programming/WorkspaceHygieneService.php
  - app/Services/Ai/Programming/RivalsForgeReadinessFingerprintService.php
  - app/Services/Ai/Programming/RivalsForgeRunLogStreamService.php
  - app/Services/Ai/Programming/AtlasRivalsRunOrchestrator.php
  - app/Services/Ai/Programming/AtlasRivalsInvalidBatteryTriageRegistry.php
  - app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsRunBatteryService.php
  - app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsCorpusPreValidationService.php
  - tests/Feature/Ai/Programming/AtlasForgeRivalsReliabilityLockdownIntegrationTest.php
  - tests/Feature/Ai/Programming/AtlasForgeRivalsRunBatteryReleaseTest.php
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
repo_paths:
  - docs/engineering-knowledge-base/atlas-forge-rivals-reliability-lockdown-v1.md
  - app/Services/Ai/Programming/WorkspaceHygieneService.php
  - app/Services/Ai/Programming/RivalsForgeReadinessFingerprintService.php
  - app/Services/Ai/Programming/RivalsForgeRunLogStreamService.php
  - app/Services/Ai/Programming/AtlasRivalsRunOrchestrator.php
  - app/Services/Ai/Programming/AtlasRivalsInvalidBatteryTriageRegistry.php
  - tests/Feature/Ai/Programming/AtlasForgeRivalsReliabilityLockdownIntegrationTest.php
allowed_changes:
  - Ajustar preset quick/full, fingerprints, evidence fields e mensagens de blocker.
  - Adicionar novos eventos JSONL desde que mantenham replay e compatibilidade.
forbidden_changes:
  - Chamar provider em preflight, dry-run ou testes automatizados.
  - Promover score/claim quando a bateria estiver invalida ou incompleta.
  - Desbloquear external_rivals_certification por este modulo.
depends_on:
  - atlas-forge-native-rivals-protocol-v1
  - atlas-programming-forge-flow
flows_to:
  - atlas:engineering:benchmark:rivals
  - atlas:programming:rivals-evidence-pack
governs:
  - forge_rivals_benchmark_validity
  - forge_rivals_workspace_cleanliness
  - forge_rivals_evidence_integrity
evidence:
  - php artisan test --filter='Rivals|ForgeNativeRivals|AtlasForge|FairClaudePolicy'
  - php artisan atlas:engineering:benchmark:rivals preflight --model=sonnet --baseline-model=sonnet --json --strict
required_tests:
  - AtlasForgeRivalsReliabilityLockdownIntegrationTest
  - AtlasForgeNativeRivalsTest
  - AtlasRivalsRunOrchestratorTest
  - RivalsForgeReadinessFingerprintServiceTest
  - RivalsForgeRunLogStreamServiceTest
requires_evidence: true
risk_level: high
next_actions:
  - Limpar .pyc rastreado com acao explicita do operador antes da primeira bateria real.
  - Rodar quick Sonnet vs Sonnet em worktrees limpos.
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-forge-rivals-reliability-lockdown-v1
graph_title: Atlas Forge Rivals Reliability Lockdown v1
graph_world: atlas
graph_layer: system
graph_kind: runbook
graph_parent: atlas-forge-native-rivals-protocol-v1
graph_status: active
graph_source: repo
human_name: Atlas Forge Rivals Reliability Lockdown v1
canonical_name: Atlas Forge Rivals Reliability Lockdown v1
technical_name: atlas-forge-rivals-reliability-lockdown-v1
cartography_type: runbook
canonical_source: docs/engineering-knowledge-base/atlas-forge-rivals-reliability-lockdown-v1.md
owner: atlas-ai
---
# Atlas Forge Rivals Reliability Lockdown v1

## Resumo

Esta doc define o lockdown do harness Rivals do Atlas Forge. O objetivo e fazer a bateria comparativa funcionar como teste confiavel: dois workspaces limpos, Atlas arm via Forge, baseline Claude Code isolado, evidence completo, replay possivel e score nulo sempre que a bateria for invalida.

## Papel no Atlas

Rivals mede o Atlas Forge contra um baseline externo em condicoes comparaveis. Ele nao implementa produto novo, nao cria UX nova e nao desbloqueia `external_rivals_certification`. Seu papel e impedir que uma bateria ruim vire narrativa de vitoria.

## Onde Se Encaixa

O fluxo fica abaixo de `atlas-forge-native-rivals-protocol-v1` e acima dos comandos de benchmark. Preflight e dry-run nunca chamam provider. O run real continua exigindo aprovacao explicita de runbook e custo.

## Contratos

- Atlas arm: `runtime=forge`, com comandos `atlas:code:forge-fast-path`, status e review.
- Baseline arm: Claude Code CLI em workspace separado.
- Model lock: `opus` ou `sonnet`, com `--model` e `--baseline-model` explicitos.
- Readiness fingerprint: hash deterministico de suite, preset, modelos, workspace hashes, case ids, gate profile e test command.
- Evidence real-run: before/after hash, diff, test log, quality log, provider receipt, replay manifest executado e timeline.

## Fluxo

1. `atlas:engineering:benchmark:rivals preflight` valida workspaces, `.pyc` rastreado, manifest e fingerprint.
2. `atlas:engineering:benchmark:rivals dry-run` planeja replay sem provider e propaga o mesmo fingerprint.
3. `atlas:engineering:benchmark:rivals run --quick|--full` roda somente com flags `--confirm-runbook-reviewed` e `--confirm-provider-cost`.
4. O runner emite JSONL incremental em `storage/app/rivals-forge-runs/<runId>/events.jsonl`.
5. O evidence pack verifica after-clean, replay e campos obrigatorios.
6. Resultado invalido gera `score=null` e blocker claro.

## Gate fail-closed adicional: Corpus Pre-Validation v1

Antes de qualquer chamada a provider (mesmo em `local_fake`), `run-battery`
roda `AtlasForgeRivalsCorpusPreValidationService` para todo case do case-set
resolvido (release/full ou `--case-set` explicito ou `--case=<corpus-id>`).
O gate bloqueia a bateria inteira (`status=blocked`, `score=null`,
`scorecard=null`, `provider_tokens_spent=false`, `external_provider_call=false`)
quando qualquer caso aparece com:

- `fixture_seed_dir_missing:<case_id>` — case_id sem `setup_fixture.seed_dir`.
- `fixture_seed_dir_not_found:<case_id>` — seed_dir nao existe no repo.
- `fixture_seed_empty:<case_id>` — seed contem apenas `README.md`.
- `fixture_seed_no_stageable_files:<case_id>` — arquivos existem mas nenhum
  cai em `allowed_files`/`expected_changed_files` seguros.
- `expected_changed_files_missing:<case_id>` — release case sem expected list.
- `unknown_case_set:<name>` / `empty_case_set:<name>` — case-set invalido.

Verdicts agregados no top-level (todos com `score=null` e
`human_review_required=true`):

- `invalid_corpus_contaminated` — pre-validation reprovou.
- `invalid_operator_confirmations_missing` — uma das tres confirmacoes faltou.
- `invalid_dirty_after_run` — workspace ficou dirty depois do run-real.
- `invalid_fingerprint_divergence` — fingerprint preflight/dry-run/run-real
  divergiu.
- `invalid_evidence_or_replay_failed` — evidence pack incompleto ou replay
  reprovado.
- `invalid_harness_blocked` — generico (cobertura final).

Score so existe quando todas as gates ficam verdes: corpus pre-validation
limpa, confirmacoes presentes, fingerprint consistente, evidence completo,
replay verificado e workspace limpo. Qualquer um falhando =>
`status=blocked`, `winner=null`, `scorecard=null`, `score=null`.

## Regras para IA

- Nao transformar Rivals em feature de produto.
- Nao chamar provider em preflight, dry-run ou teste automatizado.
- Nao mascarar workspace dirty, evidence faltando ou fingerprint mismatch.
- Nao aceitar fallback silencioso de modelo.
- Nao promover completion claim nem external rivals claim.
- Preferir blocker explicito a resultado parcial.

## Escopo de Implementacao

Entregue:

- `WorkspaceHygieneService` bloqueia `.pyc` rastreado e fornece env com `PYTHONDONTWRITEBYTECODE=1`.
- `RivalsForgeReadinessFingerprintService` unifica preflight, dry-run, runbook e runner.
- `RivalsForgeRunLogStreamService` grava eventos JSONL com heartbeat e stall detector.
- `AtlasRivalsRunOrchestrator` suporta dry-run, fake provider e real-provider guardado.
- `AtlasRivalsInvalidBatteryTriageRegistry` faz quarantine por fingerprint, nao por suite.
- `FairClaudePolicy` aceita `opus` e `sonnet` sob lock explicito.

Fora de escopo: provider dispatch automatico, UI geral, Self-Improvement, Voice e Cartografia.

## Dependencias

- `AtlasForgeNativeRivalsPreflightService`
- `AtlasForgeNativeRivalsDryRunService`
- `AtlasForgeNativeRivalsCaseManifestService`
- `AtlasRivalsEvidencePackService`
- `AtlasRivalsEvidencePackVerifierService`
- `AtlasRivalsOneShotEnterpriseEvaluationService`
- `FairClaudePolicy`

## Evidencias

Testes que precisam permanecer verdes:

- `AtlasForgeNativeRivalsTest::test_preflight_blocks_tracked_python_bytecode`
- `AtlasRivalsEvidencePackTest::test_evidence_pack_forces_pythondontwritebytecode_env`
- `AtlasRivalsRunOrchestratorTest::test_fake_provider_that_dirties_workspace_returns_invalid_dirty_after_run`
- `AtlasRivalsRunOrchestratorTest::test_fake_provider_that_stalls_returns_stalled_runner_verdict`
- `AtlasForgeRivalsReliabilityLockdownIntegrationTest::test_sonnet_model_lock_propagates_through_fingerprint`

Comando de verificacao local:

```bash
PYTHONDONTWRITEBYTECODE=1 php artisan test --filter='Rivals|ForgeNativeRivals|AtlasForge|FairClaudePolicy'
```

## Riscos

- `.pyc` rastreado ainda precisa ser removido do indice por acao explicita do operador.
- `quick` so e comparavel se ambos os arms usam o mesmo case e o mesmo preset.
- Provider real pode gastar tokens; run real sempre exige confirmacoes.
- Resultado historico invalido nao deve contaminar novo fingerprint.

## Exemplos

Preflight Sonnet vs Sonnet:

```bash
/opt/homebrew/bin/php artisan atlas:engineering:benchmark:rivals preflight \
  --workspace=<clean-atlas-worktree> \
  --claude-code-baseline-workspace=<clean-baseline-worktree> \
  --model=sonnet --baseline-model=sonnet \
  --json --strict
```

Run quick real:

```bash
/opt/homebrew/bin/php artisan atlas:engineering:benchmark:rivals run \
  --quick \
  --workspace=<clean-atlas-worktree> \
  --claude-code-baseline-workspace=<clean-baseline-worktree> \
  --model=sonnet --baseline-model=sonnet \
  --confirm-runbook-reviewed --confirm-provider-cost \
  --json
```

## Proximas Acoes

1. Remover `.pyc` rastreado com commit explicito:

```bash
git -C /Users/vitorepf/develop/Atlas/atlas-server rm --cached -r 'runtimes/python/**/__pycache__' '*.pyc' '*.pyo'
```

2. Criar dois worktrees limpos.
3. Rodar preflight e dry-run Sonnet vs Sonnet.
4. Rodar quick real somente se preflight estiver `ready_for_provider_battery`.
5. Aceitar score apenas com evidence pack real-run valido e after-clean clean.
