---
id: atlas-forge-rivals-provider-arena-v2
type: engineering_knowledge
title: Atlas Forge Rivals · Provider Arena v2
status: active
category: programming-forge
priority: 95
summary: Contrato enterprise para comparar qualquer arm canonico contra qualquer outro arm canonico com registry central de arms, registry central de provider/modelo, resolver unico, command builder unico e saida advisory-only para Atlas Decide.
tags:
  - atlas
  - forge
  - rivals
  - provider-arena
  - model-registry
  - atlas-decide
capabilities:
  - provider_arena_v2
  - centralized_provider_model_registry
  - centralized_arm_command_builder
  - cross_provider_dry_run_plan
  - cross_provider_real_executor
  - provider_arena_readiness_matrix
  - advisory_only_decide_signal
decisions:
  - Arms, providers, modelos, aliases e command lines devem ficar centralizados.
  - `fair` bloqueia cross-provider; `provider_arena`, `provider_pure` e `full_power` com arms explicitos podem executar pelo executor v2 quando policy/driver permitirem.
  - `gpt-5.5` e outros modelos concretos entram pelo provider model registry, nao por strings espalhadas.
  - Gemini pode ser planejado, validado e executado quando `gemini` CLI/policy estiver configurado; sem driver, bloqueia honestamente antes de token spend.
  - `arena-readiness` lista duelos enterprise canonicos e informa se estao prontos para dry-run, prontos para execucao real apos confirmacoes, ou bloqueados por driver/modelo/policy.
  - Rivals emits measured evidence; Atlas Decide decides model routing.
maintenance:
  - Mudar model id real em ProviderModelRegistry/config, nao em runner/report/adjudicator.
  - Adicionar provider novo com model registry + command builder + testes.
  - Adicionar arm novo com arm registry + arm contract + testes.
related_paths:
  - app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsProviderModelRegistryService.php
  - app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsArmCommandBuilderService.php
  - app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsProviderArenaReadinessService.php
  - app/Services/Ai/Programming/ForgeRivals/Arms/AtlasForgeRivalsArmRegistryService.php
  - app/Services/Ai/Programming/ForgeRivals/Arms/AtlasForgeRivalsArmContractService.php
  - app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsArenaRunService.php
  - docs/engineering-knowledge-base/atlas-forge-rivals-benchmark-strategy-v1.md
doc_schema: atlas_canonical_module_doc.v1
owner: programming_rivals
graph_id: atlas-forge-rivals-provider-arena-v2
graph_title: Atlas Forge Rivals · Provider Arena v2
graph_world: atlas
graph_layer: system
graph_kind: contract
graph_parent: atlas-forge-rivals-benchmark-strategy-v1
graph_status: active
graph_source: repo
repo_paths:
  - app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsProviderModelRegistryService.php
  - app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsArmCommandBuilderService.php
  - app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsProviderArenaReadinessService.php
  - app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsArenaRunService.php
allowed_changes:
  - Adicionar modelos, providers e arms por registry central e testes.
  - Evoluir executor real v2 somente preservando evidence/replay/report para qualquer par de arms.
forbidden_changes:
  - Espalhar model ids em RunRealService, ArenaRunService, report ou tests sem registry.
  - Permitir cross-provider em `fair`.
  - Gerar score quando executor/evidence/replay/matrix nao estiverem completos.
  - Fazer Rivals atualizar provider topology do Atlas Decide.
depends_on:
  - atlas-forge-rivals-benchmark-strategy-v1
  - atlas-forge-rivals-battery-modes-and-human-prompts-v1
flows_to:
  - atlas-forge-rivals-intelligence-ledger-v1
  - atlas-decide
unlocks:
  - rivals_provider_arena_cross_provider_plans
  - rivals_provider_arena_readiness_matrix
  - rivals_provider_arena_cross_provider_real_runs
  - rivals_low_churn_model_provider_changes
governs:
  - provider_arena_arms
  - provider_model_registry
  - arm_command_builder
evidence:
  - tests/Feature/Ai/Programming/AtlasForgeRivalsProviderArenaCoreTest.php
  - tests/Feature/Ai/Programming/AtlasForgeRivalsProviderArenaCorpusTest.php
  - "php artisan atlas:forge:rivals models --json"
  - "php artisan atlas:forge:rivals arena-readiness --json"
  - "php artisan atlas:forge:rivals audit --json"
  - "php artisan atlas:forge:rivals run-arena --arm-a=claude_code --arm-a-model=opus --arm-b=codex_cli --arm-b-model=gpt-5.5 --mode=provider_arena --dry-run --json"
  - "AtlasForgeRivalsProviderArenaCoreTest::test_provider_arena_real_pipeline_runs_cross_provider_with_stubbed_binaries_and_replay"
  - "AtlasForgeRivalsProviderArenaCoreTest::test_provider_arena_real_pipeline_runs_atlas_dev_vs_atlas_forge_with_stubbed_claude"
  - "AtlasForgeRivalsProviderArenaCoreTest::test_provider_arena_real_pipeline_runs_codex_vs_gemini_when_driver_binaries_are_configured"
  - "AtlasForgeRivalsProviderArenaCoreTest::test_full_power_real_pipeline_uses_provider_arena_v2_for_atlas_system_vs_provider_pure"
required_tests:
  - "php artisan test --filter='AtlasForgeRivalsProviderArenaCoreTest'"
  - "php artisan atlas:engineering:knowledge docs-health --json"
requires_evidence: true
risk_level: high
next_actions:
  - Rodar bateria real cross-provider com confirmacoes explicitas do operador.
  - Validar gemini_cli real em ambiente com driver configurado.
  - Registrar outcomes no Intelligence Ledger por arm/provider/model/mode.
---

# Atlas Forge Rivals · Provider Arena v2

## Resumo

Provider Arena v2 e o contrato para comparar qualquer runner canonico contra
qualquer outro runner canonico com pouca mudanca quando modelos ou comandos
mudam. Modelo, provider e comando nao podem viver espalhados em services de
execucao.

## Papel no Atlas

O Atlas usa a arena para medir, nao para rotear. Ela responde perguntas como
Atlas Dev vs Atlas Forge, Claude Code Opus vs Codex GPT-5.5, Codex GPT-5.5 vs
Gemini, Sonnet vs Opus, e Atlas full_power vs provider puro.

O resultado alimenta Provider Performance Ledger e Intelligence Ledger como
sinal consultivo. Atlas Decide continua dono de model routing.

## Onde Se Encaixa

Camadas:

1. `ArmRegistry`: quais runners existem.
2. `ProviderModelRegistry`: provider, aliases, model ids reais, legacy ids e
   `binary_config_key` canonico.
3. `ArmContract`: resolve arm + modelo + categoria.
4. `ArmCommandBuilder`: monta o comando real por provider.
5. `ProviderArenaReadiness`: gera matriz local de duelos canonicos, blockers de
   binario/modelo/policy e comandos de execucao com confirmacoes.
6. `ArenaRun`: valida modo, fairness e plano de execucao.
7. Evidence/replay/report: so podem pontuar quando executor real completar
   evidence pack, replay final e report.
8. `audit --json`: prova 18 invariantes do Provider Arena Core/v2, incluindo
   model registry, command builder, modos v2, executor real e fluxo
   `arena_contracts` para manifest/report/signal.

## Fluxo

1. Operador escolhe dois arms, modelos e modo de arena.
2. `ArmRegistry` normaliza o arm canonico e aliases legados.
3. `ProviderModelRegistry` resolve provider, modelo canonico, aliases, model id
   real e binario configurado.
4. `ArmContract` valida categoria, policy, capacidade e blockers.
5. `ArmCommandBuilder` gera o plano de comando com modelo explicito quando suportado.
6. `ArenaRun` aplica regras do modo (`fair`, `provider_arena`, `provider_pure`, `full_power`).
7. Executor real so pode emitir score quando evidence pack, replay strict e matrix evidence lock estiverem completos.
8. Saida machine-readable segue advisory-only: Rivals emits measured evidence; Atlas Decide decides model routing.

## Prontidao Operacional

`arena-readiness` e a checagem local para operador antes de gastar tokens. Ela
nao roda provider, nao gera score e nao substitui replay. A saida lista os
duelos canonicos enterprise:

- `atlas_dev_vs_atlas_forge`
- `claude_opus_vs_codex_gpt55`
- `codex_gpt55_vs_gemini_pro`
- `claude_sonnet_vs_claude_opus`
- `atlas_forge_full_power_vs_claude_opus`

Cada duelo retorna `dry_run_ready`, `real_run_ready`, `blockers`,
`command_plan` redigido, provider/model/model_id resolvido por arm, e
`next_command` com `--confirm-runbook-reviewed`, `--confirm-provider-cost` e
`--confirm-real-provider-call`. Quando um driver esta ausente, o status do par
fica `plan_ready_driver_missing`, preservando o plano sem mascarar que a
execucao real ainda esta bloqueada.

## Contratos

- `fair`: exige mesmo provider e mesmo modelo canonico.
- `provider_arena`: permite cross-provider com arms declarados.
- `provider_pure`: compara runner puro contra runner puro.
- `full_power`: permite Atlas como sistema completo contra baseline declarado.
- `local_fake`: nunca chama provider e nunca gera claim real.

Runs reais com Claude Code tambem passam por
`AtlasCodeProviderGovernanceService::decideProgrammaticInvocation('rivals_baseline')`.
Se a policy estiver `interactive_only`, `blocked` ou `allow_rivals_programmatic=false`,
a Arena emite blocker antes de qualquer provider call.

Toda saida para Decide preserva `advisory_only=true`,
`should_update_provider_topology=false`,
`never_changes_atlas_decide_topology=true`,
`owner_of_model_routing=atlas_decide` e `routing_effect=none`.

## Regras para IA

- Nao adicionar modelo ou `binary_config_key` diretamente no CLI ou RunReal; use
  ProviderModelRegistry.
- Nao adicionar comando diretamente em ArenaRun; use ArmCommandBuilder.
- Nao permitir `fair` para Claude vs Codex ou Codex vs Gemini.
- Nao chamar dry-run de resultado medido.
- Nao esconder blocker de driver pendente.
- Nao invocar Claude programmatic se a provider governance bloquear
  `rivals_baseline`.

## Escopo de Implementacao

Entregue no v2:

- action `models`;
- action `arena-readiness`;
- `atlas_dev` canonico;
- aliases de `atlas_dev_light` aceitos para compatibilidade;
- `gpt-5.5` resolvido para Codex CLI;
- command builder com modelo explicito para Claude, Codex e Gemini;
- dry-run provider_arena para cross-provider;
- matriz local de prontidao para duelos enterprise sem provider spend;
- executor real `provider_arena`/`provider_pure`/`full_power` para arms executaveis usando
  setup, worktree isolation, RunReal, collect-evidence, replay, adjudicate e
  report;
- evidence manifest com `arena_contracts` por arm, incluindo provider,
  resolved_model e resolved_model_id;
- report v3 projeta nomes reais de arm/provider/model_id no topo, nos cases,
  no provider pair e no `provider_performance_signal`;
- `atlas_dev` e `gemini_cli` declarados como executaveis quando o driver/policy
  estiverem configurados, com blocker claro quando binario/policy faltar.

Fora do v2 inicial:

- claim externa automatica sem aprovacao do operador;
- routing automatico.

## Dependencias

- provider binaries (`claude`, `codex`, `gemini`);
- provider governance policy;
- worktree isolation;
- evidence pack;
- replay strict;
- matrix evidence lock;
- report v3/vNext.

## Evidencias

Comandos de prova:

```bash
php artisan atlas:forge:rivals models --json
php artisan atlas:forge:rivals arena-readiness --json
php artisan atlas:forge:rivals run-arena \
  --arm-a=claude_code --arm-a-model=opus \
  --arm-b=codex_cli --arm-b-model=gpt-5.5 \
  --task-category=bugfix --mode=provider_arena --dry-run --json
php artisan atlas:forge:rivals run-arena \
  --arm-a=codex_cli --arm-a-model=gpt-5.5 \
  --arm-b=gemini_cli --arm-b-model=gemini-pro \
  --task-category=architecture --mode=provider_arena --dry-run --json
```

## Riscos

Risco principal: confundir plano com medicao. Mitigacao: dry-run retorna
`winner=null`, `scorecard=null`, `external_provider_call=false`; execucao real
exige tres confirmacoes, arms executaveis, evidence pack, replay final e report.
Qualquer score com evidence/replay/matrix quebrado continua inutilizavel.

## Exemplos

Mudar o model id real do Codex GPT-5.5:

```env
ATLAS_AI_CODEX_PREMIUM_MODEL=gpt-5.5
```

Mudar o binario real do Codex CLI:

```env
ATLAS_AI_CODEX_BIN=/opt/codex/bin/codex
```

Mudar o model id real do Claude Opus:

```env
ATLAS_AI_CLAUDE_PREMIUM_MODEL=claude-opus-4-7
```

Adicionar provider novo:

1. adicionar provider/modelos no ProviderModelRegistry;
2. adicionar command family no ArmCommandBuilder;
3. adicionar arm no ArmRegistry;
4. cobrir resolve, command plan, blocker e advisory invariants em testes.

## Proximas Acoes

1. Rodar bateria real cross-provider com confirmacoes explicitas do operador.
2. Validar Gemini real com policy e receipt em ambiente com driver configurado.
3. Registrar resultados no Intelligence Ledger por arm/provider/model/mode.
