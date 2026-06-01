---
id: atlas-forge-rivals-next-runner-architecture-v1
type: engineering_knowledge
title: Atlas Forge Rivals Next Runner Architecture v1
status: source_material
category: programming-forge
priority: 95
summary: Canon para adicionar runners modernos ao Forge Rivals Provider Arena com ArmRegistry, ProviderModelRegistry, capability contracts e command builders centralizados, mantendo evidence/replay obrigatorios e Atlas Decide advisory-only.
tags:
  - atlas
  - forge
  - rivals
  - provider-arena
  - runners
  - cursor-cli
  - composer
capabilities:
  - rivals_next_runner_architecture
  - rivals_cursor_cli_runner
  - rivals_composer_2_5_runner
  - rivals_capability_contracts
  - rivals_central_command_builders
  - rivals_cursor_meta_provider_contract
  - rivals_meta_provider_stress_corpus
unlocks:
  - rivals_cursor_cli_provider_arena_plans
  - rivals_composer_2_5_provider_arena_plans
  - rivals_next_runner_low_churn_onboarding
  - rivals_meta_provider_long_context_measurement
governs:
  - rivals_arm_registry
  - rivals_provider_model_registry
  - rivals_arm_command_builder
  - rivals_capability_contracts
  - rivals_meta_provider_metadata
evidence:
  - "php artisan test --filter='AtlasForgeRivalsProviderArenaCoreTest'"
  - "php artisan test --filter='AtlasForgeRivalsProviderArenaCorpusTest|AtlasForgeRivalsProviderArenaCorpusServiceTest'"
  - "php artisan atlas:forge:rivals arms --json"
  - "php artisan atlas:forge:rivals models --json"
  - "php artisan atlas:forge:rivals run-arena --dry-run --json"
next_actions:
  - Keep new runner onboarding constrained to registry, model registry, command builder and tests.
  - Add provider-specific evidence pack contracts before allowing real scored runs.
  - Keep dry-run/local_fake out of external claims and Atlas Decide topology.
decisions:
  - Todo runner oficial entra pelo ArmRegistry e declara capabilities antes de aparecer em Provider Arena.
  - Todo provider/modelo/alias/model_id real entra pelo ProviderModelRegistry.
  - Cursor entra como meta-provider governado; Composer 2.5 e uma superficie runner/model do meta-provider Cursor.
  - Cursor CLI e Composer 2.5 usam Cursor Agent CLI, mas sao runners separados quando o operador quer comparar a superficie Composer.
  - Casos industriais expoem human_prompt e context_profile para medir prompts humanos ambiguos, contexto longo e disciplina de escopo.
  - Command builders sao o unico lugar para flags de CLI usadas pelo Rivals.
  - Dry-run/readiness nunca spawnam provider nem gastam tokens.
  - Rivals emits measured evidence; Atlas Decide decides model routing.
maintenance:
  - Atualizar quando novo runner, provider, modelo, alias, capability ou builder entrar no Rivals.
  - Manter em sincronia com Provider Arena v2, benchmark strategy, battery modes e Intelligence Ledger.
related_paths:
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
  - docs/engineering-knowledge-base/atlas-forge-rivals-provider-arena-v2.md
  - docs/engineering-knowledge-base/atlas-forge-rivals-benchmark-strategy-v1.md
  - docs/engineering-knowledge-base/atlas-forge-rivals-battery-modes-and-human-prompts-v1.md
  - docs/engineering-knowledge-base/atlas-forge-rivals-intelligence-ledger-v1.md
  - app/Services/Ai/Programming/ForgeRivals/Arms/AtlasForgeRivalsArmRegistryService.php
  - app/Services/Ai/Programming/ForgeRivals/Arms/AtlasForgeRivalsArmContractService.php
  - app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsProviderModelRegistryService.php
  - app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsArmCommandBuilderService.php
  - docs/engineering-knowledge-base/atlas-forge-rivals-ceiling-360-execution-ladder-v1.md
doc_schema: atlas_canonical_module_doc.v1
owner: programming_rivals
graph_id: atlas-forge-rivals-next-runner-architecture-v1
graph_title: Atlas Forge Rivals Next Runner Architecture v1
graph_world: atlas
graph_layer: system
graph_kind: contract
graph_parent: atlas-forge-rivals-provider-arena-v2
graph_status: active
graph_source: repo
canonical_source: docs/engineering-knowledge-base/atlas-forge-rivals-next-runner-architecture-v1.md
repo_paths:
  - docs/engineering-knowledge-base/atlas-forge-rivals-next-runner-architecture-v1.md
allowed_changes:
  - Adicionar runners/providers/modelos somente mantendo registry, builder, tests e evidence/replay.
forbidden_changes:
  - Espalhar model ids ou CLI flags em RunBatteryService, RunRealService, report ou adjudicator.
  - Chamar provider real em dry-run/readiness.
  - Promover local_fake, dry-run ou generated specs como claim real.
  - Fazer Rivals alterar provider topology do Atlas Decide.
depends_on:
  - atlas-forge-rivals-provider-arena-v2
  - atlas-forge-rivals-benchmark-strategy-v1
flows_to:
  - atlas-forge-rivals-intelligence-ledger-v1
  - atlas-decide
required_tests:
  - "php artisan test --filter='AtlasForgeRivalsProviderArenaCoreTest'"
  - "php artisan atlas:forge:rivals arms --json"
  - "php artisan atlas:forge:rivals models --json"
  - "php artisan atlas:forge:rivals arena-readiness --json"
  - "php artisan atlas:engineering:knowledge docs-health --json"
requires_evidence: true
risk_level: high
---
# Atlas Forge Rivals Next Runner Architecture v1

## Papel no Atlas

Este documento governa como o Atlas Forge Rivals adiciona runners, providers e
modelos mutaveis sem quebrar evidence/replay, fairness ou o limite advisory-only
do Atlas Decide.

Rivals emits measured evidence; Atlas Decide decides model routing.

## Onde Se Encaixa

O contrato fica abaixo do Provider Arena e acima dos executores reais. Ele
orienta `ArmRegistry`, `ProviderModelRegistry`, `ArmContract`,
`ArmCommandBuilder`, readiness, dry-run, reports e manifests.

Ele nao substitui Atlas Decide, nao altera provider topology e nao autoriza
certificacao externa.

## Resumo

Este contrato define como o Forge Rivals recebe novos runners sem criar
arquitetura paralela. O caminho canonico e:

1. `ArmRegistry` declara o runner, provider, modes, safety e capabilities.
2. `ProviderModelRegistry` declara provider, aliases e model ids reais.
3. `ArmContract` resolve `arm + model + category` e retorna blockers claros.
4. `ArmCommandBuilder` monta argv centralizado para o provider.
5. `ProviderArenaReadiness` e `run-arena --dry-run` planejam sem spawnar provider.
6. Execucao real so pode pontuar depois de evidence pack, replay e matrix lock.

## Contratos

Contratos obrigatorios:

- Todo arm oficial existe no `ArmRegistry`.
- Todo arm oficial declara provider, categoria, safety, capabilities e modes.
- Todo provider/modelo/alias/model id real existe no `ProviderModelRegistry`.
- Cursor/Composer declaram `provider_kind`, `meta_provider`, owner de routing
  interno e bucket/config de billing sem conceder poder de routing ao Rivals.
- Todo argv de provider vem de `ArmCommandBuilder`.
- Todo dry-run/readiness retorna blockers explicitos e nao chama provider real.
- Todo report/manifest inclui provider, model alias, model id resolvido, command
  builder e capabilities.
- Todo run que envolva meta-provider inclui contrato de evidencias para receipts
  e contexto humano antes de qualquer scoring real.
- Setup de worktree deve checar capacidade de disco antes de `git worktree add`;
  quando faltar espaco, emite `worktree_disk_space_insufficient` sem checkout
  parcial, sem provider call e sem token spend.

As saidas machine-readable preservam `advisory_only=true`,
`should_update_provider_topology=false`,
`never_changes_atlas_decide_topology=true`,
`owner_of_model_routing=atlas_decide` e `routing_effect=none`.

## Fluxo

Fluxo canonico:

1. CLI recebe arm, modelo, mode e categoria.
2. `ArmContract` valida arm, model alias e mode.
3. `ProviderModelRegistry` resolve provider/model/model id ou blocker.
4. Metadados de provider indicam se o runner e provider direto ou meta-provider.
5. `ArmCommandBuilder` monta command plan redigido quando aplicavel.
6. `ProviderArenaReadiness` ou `run-arena --dry-run` retorna plano sem spawn.
7. Execucao real permanece bloqueada sem confirmacao humana, evidence pack,
   replay e matrix evidence lock.

## Regras para IA

Agentes devem reaproveitar os services existentes e nao criar arquitetura
paralela. Nao espalhar strings de modelos, flags de CLI ou regras de provider em
`RunRealService`, `ArenaRunService`, `RunBatteryService`, report ou adjudicator.

Dry-run, local_fake e specs geradas nunca viram claim real. Provider real nao
pode ser chamado para readiness, listagem, resolver ou planejamento.

## Escopo de Implementacao

Escopo permitido:

- Declarar runner no `ArmRegistry`.
- Declarar provider/modelo/alias/model id no `ProviderModelRegistry`.
- Declarar builder centralizado no `ArmCommandBuilder`.
- Expor capabilities e blockers em readiness, dry-run, manifest e report.
- Adicionar testes focados de resolver, fairness, dry-run e advisory-only.

Fora de escopo:

- Alterar Atlas Decide provider topology.
- Desbloquear `external_rivals_certification`.
- Transformar evidence incompleta em score ou claim.

## Runners Oficiais

Runners canonicos:

- `atlas_forge`
- `atlas_dev`
- `claude_code`
- `codex_cli`
- `gemini_cli`
- `cursor_cli`
- `composer_2_5`
- `manual_runner`
- `scripted_runner`
- `future_runner`

`cursor_cli` mede o Cursor Agent CLI configurado. `composer_2_5` mede a
superficie executora Composer 2.5 quando o operador quer compara-la como runner
proprio. Ambos usam o binario `atlas.ai.providers.cursor_cli.binary`, mas seus
modelos/aliases sao resolvidos pelo registry, nao por strings nos services.

## Provider/Model Registry

Providers canonicos atuais:

- `claude`: Sonnet e Opus por config.
- `codex`: default, GPT-Codex e GPT-5.5 por config.
- `gemini`: Gemini Pro e Flash por config.
- `cursor`: default/configured e auto.
- `composer`: Composer 2.5.

O model id real do Composer 2.5 vive em
`atlas.ai.providers.cursor_cli.composer_2_5_model`. Se estiver vazio, o resolver
retorna blocker `provider_model_id_missing`, sem fallback mentiroso.

Cursor e Composer sao tratados como meta-provider surfaces. Isso significa:

- Cursor pode escolher/subdelegar runtime interno dentro da propria conta Cursor.
- Atlas Decide continua dono de model routing do Atlas.
- Rivals so mede evidencias emitidas; nao promove topology nem claims.
- O registry expoe `provider_kind`, `meta_provider`, `model_routing_owner`,
  `billing_mode_config_key`, `quota_bucket_config_key` e `tool_event_stream`.

## Capability Contract

Cada arm declara:

- `supports_explicit_model`
- `supports_non_interactive`
- `supports_json_output`
- `supports_workspace_path`
- `supports_timeout`
- `supports_resume`
- `supports_streaming_logs`
- `supports_cost_receipts`
- `supports_provider_receipts`
- `supports_evidence_pack`
- `supports_local_fake`
- `requires_human_confirmation_for_real_call`
- `allowed_modes`

Essas capabilities aparecem em dry-run, manifest/report e readiness para que
UI, operadores e testes nao infiram suporte por nome de runner.

## Command Builders

Builders centralizados:

- Claude Code: `claude --model <resolved_model_id> ...`
- Codex CLI: `codex exec ... -m <resolved_model_id> ...`
- Gemini CLI: `gemini --model <resolved_model_id> ...`
- Cursor CLI: `cursor-agent --print --output-format stream-json --model <resolved_model_id>`
- Composer 2.5: mesmo binario Cursor CLI, `command_family=composer_2_5`.

Readiness e dry-run podem exibir command plans redigidos, mas nunca executam.
Os envelopes machine-readable usam nomes estaveis para auditoria:
`resolved_arm`, `provider`, `model_alias`, `resolved_model`,
`resolved_model_id`, `command_builder` e `capabilities`. Os aliases antigos
`arm_id`, `model` e `model_id` podem continuar presentes por compatibilidade,
mas consumidores novos devem preferir os campos `resolved_*`.

`external-execution-preflight` emite `runner_bridge_contract`; `external-execution-plan`
emite a matriz runner/modelo/categoria/dificuldade que ainda precisa de
evidencia. Rivals aceita CLI/runtime/API se todos entram pelo mesmo
evidence/replay/scorecard/matrix. Claude, Codex, Gemini, Cursor e Composer sao
`cli_runner`; DeepSWE/Pier pode ficar externo e ser consumido por plan/result
	ingest. Com `--output-path`, o plan escreve
	`external_execution_runbook_manifest.v1` com fingerprint, batches e gates.
	O ingest pode receber `--plan-manifest` e bloquear resultado externo cujo
	`plan_fingerprint` nao bate. O batch ingest agrega a mesma verificacao em
	`external_execution_plan_binding_summary`, exigindo um unico fingerprint comum
	quando manifesto e fornecido. Esses actions nunca spawnam provider nem
	transformam bridge em claim.

As flags Cursor seguem a referencia oficial do Cursor Agent CLI: `--print`,
`--output-format stream-json` e `--model <model>`. Rivals proibe `--force`,
mesmo que a CLI oficial suporte o flag, porque ele pode relaxar aprovacao de
comandos e enfraquecer a evidencia do runner. `--resume` tambem nao entra em
comandos de arena novos; retomada pertence ao estado da battery/run do Rivals,
nao a uma sessao externa do meta-provider.

Cursor/Composer transportam o prompt por stdin, nao como argumento posicional
no command plan. O manifest/receipt guarda `stdin_prompt_hash` e tamanho, mas
nao o prompt bruto no argv. Isso reduz vazamento em process list, command hash,
dry-run JSON e artifacts de evidencia.

## Corpus Enterprise

O corpus industrial inclui o preset `meta-provider-stress` com 50 casos. Ele
seleciona dominios ambiguos, multi-dia, incidentes, security, produto,
integracao e performance para medir:

- retencao de contexto longo;
- prompt humano ambiguo;
- separacao de fatos, suposicoes e decisoes reversiveis;
- evidencia de tool events/stream-json para Cursor;
- fail-closed sem synthetic score ou external claim.

Cada caso exposto pelo planner carrega `human_prompt`, `context_profile`,
`measurement_tags`, `human_prompt_probe` e, no preset dedicado,
`meta_provider_stress`.

`context_profile` e `human_prompt_probe` tambem carregam
`complexity_profile` (`atlas.forge.rivals.case_complexity_profile.v1`). Esse
perfil e o ponto canonico para medir runners mutaveis em contexto longo e
prompt humano ambiguo:

- `estimated_context_tokens`
- `reasoning_depth`
- `ambiguity_score`
- `risk_score`
- `scope_surface_count`
- `requires_multi_step_plan`
- `requires_rollback_plan`
- `requires_evidence_matrix`

O preset `meta-provider-stress` replica esse perfil em
`meta_provider_stress.complexity_profile` e declara `measurement_floor` por
caso. Esse floor exige replay matrix, caminho de blocker honesto e
`synthetic_claim_allowed=false`; portanto um runner pode planejar/dry-run sem
provider call, mas nao pode gerar score ou claim real sem evidence/replay
compatível.

O snapshot do corpus tambem expoe
`meta_provider_stress_coverage`
(`atlas.forge.rivals.meta_provider_stress_coverage.v1`) com distribuicao de
dominios, risco, ambiguidade e profundidade de raciocinio. O floor do preset
exige pelo menos 50 casos, 8 dominios, risco critico/alto, ambiguidade alta,
contexto longo em todos os casos e rollback plan em casos suficientes. Esse
resumo torna auditavel que a bateria mede programacao pesada e contexto longo,
em vez de apenas listar muitos casos.

## Meta-Provider Evidence

Cursor e Composer exigem contrato adicional de evidence quando saem do dry-run:

- `arena_contracts` no manifest preserva provider, model alias, model id,
  command builder, capabilities e metadados de meta-provider por arm.
- `meta_provider_evidence_contract` no evidence pack declara quando o run
  envolve meta-provider e quais receipts/campos sao obrigatorios.
- receipts de meta-provider em run real devem expor `model`, `command_hash`,
  `prompt_hash`, `stdout_hash` e `exit_code`.
- receipts Cursor/Composer com `tool_event_stream=stream-json` tambem precisam
  provar `output_format=stream-json`, NDJSON parseavel, evento `system/init`
  com `apiKeySource`, `cwd` absoluto, `model` e `permissionMode`, evento
  `user`, ao menos um tool event, evento terminal `result/subtype=success`,
  `is_error=false` e `session_id` consistente, alinhado ao Cursor Agent CLI
  oficial. O evidence pack guarda apenas sinais booleanos/hash de sessao, nao
  credenciais.
- casos `meta-provider-stress` devem preservar `human_prompt_hash`,
  `context_profile`, `measurement_tags`, `human_prompt_probe` e
  `complexity_profile` no summary de evidencia.
- falta de receipt, hash de prompt humano, context profile ou complexity
  profile bloqueia score e claim; dry-run continua permitido sem provider
  call.
- Reports v3 expoem `capability_results` e
  `provider_performance_signal.capability_fit`. Esses eixos agregam
  `complexity_profile.measured_dimensions` e `measurement_tags`, permitindo
  diagnosticar empates por capacidade medida (`long_context_retention`,
  `multi_step_reasoning`, `rollback_safety`, `scope_boundary_discipline`,
  `honest_blocker_behavior`, etc.) sem transformar o sinal em routing.
- `provider_performance_signal.capability_coverage`
  (`atlas.forge.rivals.capability_coverage.v1`) declara o piso 360:
  capacidades obrigatorias, minimo de casos por capacidade, capacidades
  ausentes, capacidades subamostradas e `floor_met`. `rollback_safety`,
  `multi_step_reasoning`, `replayable_evidence_quality` e
  `long_context_retention` tambem podem ser derivadas de booleans do
  `complexity_profile` quando o caso exige rollback, plano multi-step, matrix
  de evidencia ou contexto longo. Quando o floor nao e atingido,
  `do_not_use_when` adiciona `capability_floor_not_met`.
  O mesmo bloco deve incluir `next_measurement_plan` com
  `additional_cases_needed` por capacidade e `recommended_case_sets` para guiar
  a proxima bateria real/estatistica sem promover claim parcial.
- O mesmo `next_measurement_plan` tambem expoe `recommended_commands` e o
  signal expoe `next_measurement_commands`. Esses comandos sao separados entre
  inspecao/dry-run sem provider call e template real com confirmacoes explicitas
  (`confirm-runbook-reviewed`, `confirm-provider-cost`,
  `confirm-real-provider-call`). Todos carregam `advisory_only=true` e
  `routing_effect=none`; dry-run/local_fake declara
  `external_provider_call=false` e `provider_tokens_spent=false`, enquanto
  template real declara esses campos como true para impedir execucao acidental.
- `capability_coverage.separation`
  (`atlas.forge.rivals.capability_separation.v1`) diferencia capacidades que
  realmente separaram runners, capacidades empatadas/human-review e capacidades
  ainda subamostradas. Empate passa a ser diagnostico operacional:
  `tie_is_diagnostic_not_claim=true`; o signal nunca converte empate em claim,
  mas mostra quais eixos precisam de `ceiling-360`,
  `extreme-differentiator`, `meta-provider-stress` ou `statistical-repeat`
  para responder "quem e bom em que".
- `provider_performance_signal.difficulty_pressure`
  (`atlas.forge.rivals.difficulty_pressure.v1`) detecta quando a matriz de 40
  casos ou qualquer faixa L1-L5 ainda esta facil demais: sem amostra valida
  emite `needs_valid_difficulty_sample`; faixa com empate tecnico acima de 55%
  emite `per_level_tie_escalation_cancel_and_increase_complexity`; L5 valido
  empatado emite `l5_tied_needs_extreme_pressure`. Ambos preservam
  advisory-only invariants, `requires_harder_followup=true`,
  `difficulty_ceiling_reached=false` e recomendam `ceiling-360`,
  `extreme-differentiator`, `meta-provider-stress` e `statistical-repeat`.
  A cada 10 empates agregados, ou acima de 55% em qualquer L1, L2, L3, L4 ou
  L5, o report deve marcar `should_cancel_current_battery=true`, listar
  `levels_to_reinforce` e exigir mais capacidades simultaneas no proximo piso.
- `cost_time_efficiency` e dimensoes equivalentes sao telemetria somente:
  aparecem no scorecard/report, mas `winner_decision_weights` deve manter peso
  `0.0` e `winner_decision_excluded_dimensions` deve impedir que custo, tokens
  ou latencia decidam vencedor. Atlas normalmente nao ganha por custo; Rivals
  mede esse dado para auditoria, nao para ranking. Esse contrato vale no
  adjudicator v1 e no adjudicator v2: mesmo se um `case_manifest` tentar
  atribuir peso positivo para `cost_time`, o resolver zera essa dimensao e
  renormaliza apenas as dimensoes tecnicas restantes.
- Heuristicas de formato do patch tambem nao podem virar falso positivo de
  qualidade: patch menor, menos arquivos, diff medio menor ou menor
  complexidade aparente sao diagnostico/review-risk, nao prova de qualidade.
  Vencedor deve depender de evidencias fortes como oracle deterministico,
  replay, scope correto, testes semanticos, rollback, invariantes de producao,
  compatibilidade e cobertura `ceiling_360_contract`.
- O corpus release tambem materializa `anti_tie_pressure` por caso:
  `max_technical_tie_rate=0.55`, `must_cancel_and_reinforce_when_exceeded=true`,
  `winner_excludes_cost_time_efficiency=true` e dimensoes minimas por nivel.
  Esse contrato faz o limite anti-empate existir antes do report: L1 ja mede
  acceptance deterministica, regressao nao obvia, escopo, evidencia e prompt
  humano; L2-L5 adicionam estado, contratos, data-flow, compatibilidade,
  observabilidade, rollback, blast radius e estrategia faseada.
- `ceiling-360`, perfil `L5+`, `execution_ladder`, cobertura observada,
  complexity coverage e meta-provider claim floor sao detalhados em
  `atlas-forge-rivals-ceiling-360-execution-ladder-v1.md`. A arquitetura aqui
  apenas fixa o limite: empates L5 sao diagnosticos, dry-run/local_fake nao
  contam como evidencia real, e somente manifests reais com replay verde,
  `verdict=comparable` e `hard_failures=[]` entram na cobertura.

`human_prompt_probe` e o checklist replayavel para medir prompt humano ambiguo.
Ele exige secoes como fatos observados, suposicoes, decisoes reversiveis,
limites de escopo, plano de evidencia, tradeoffs e blockers honestos. Runners
que respondem com certeza falsa, ignoram limites de arquivo ou pulam assumptions
ficam mensuraveis sem transformar dry-run em claim real.

Esse contrato mede a superficie Cursor/Composer sem transformar routing interno
do Cursor em routing do Atlas. Billing/quota/tool events sao metadados de
evidencia, nao permissoes para Atlas Decide topology.

## Fairness

`fair` bloqueia provider ou modelo diferente. `provider_arena` permite
cross-provider. `provider_pure` mede baseline puro. `full_power` permite Atlas
completo contra baseline declarado. `local_fake` nunca vira claim real.

## Atlas Decide Boundary

Toda saida machine-readable permanece:

```json
{
  "advisory_only": true,
  "should_update_provider_topology": false,
  "never_changes_atlas_decide_topology": true,
  "owner_of_model_routing": "atlas_decide",
  "routing_effect": "none"
}
```

Rivals emits measured evidence; Atlas Decide decides model routing.

## Como Adicionar

Novo runner: adicionar arm no `ArmRegistry`, capabilities, allowed modes,
builder ou familia existente, readiness pair se virar duelo canonico, e teste de
dry-run/blocker.

Novo provider/modelo: adicionar no `ProviderModelRegistry`, aliases,
`binary_config_key`, config/env, resolver tests e command builder se nao existir.

Novo modelo de provider existente: adicionar um modelo no registry e teste de
alias/model id. Services de run/report/adjudicator nao devem mudar.

## Dependencias

`atlas-forge-rivals-provider-arena-v2`,
`atlas-forge-rivals-benchmark-strategy-v1`,
`atlas-forge-rivals-battery-modes-and-human-prompts-v1`,
`atlas-forge-rivals-intelligence-ledger-v1`,
`atlas-canonical-glossary-and-naming`.

## Evidencias

Testes de registry/provider resolver, command builder sem provider real,
fairness cross-provider/modelo, dry-runs Cursor/Composer contra
Claude/Codex/Gemini, report/manifest com provider/model/builder/capabilities,
`docs-health`, `architecture-validate`, Pint e `git diff --check`.

## Riscos

Model id hardcoded fora do registry; builder duplicado em service de run;
readiness que tenta executar provider; fair mode permissivo demais; report
pontuando sem replay/matrix/evidence lock; dry-run/local_fake promovido como
claim externo.

## Exemplos

- `cursor_cli default` contra `claude_code sonnet` em `provider_arena`.
- `composer_2_5 default` contra `codex_cli gpt-5.5` em `provider_arena`.
- `cursor_cli default` contra `composer_2_5 default` em `provider_arena` com
  `--case-set=meta-provider-stress --dry-run`.
- `claude_code sonnet` contra `claude_code opus` em fairness quando a politica
  aceitar variacao controlada do mesmo provider.

Exemplo bloqueado:

- `cursor_cli composer_2_5` contra `codex_cli gpt-5.5` em `fair` quando a
  politica exige mesmo provider e mesmo modelo.

## Proximas Acoes

Adicionar novos runners somente por registry, builder e testes; promover real
scored runs somente depois de evidence pack, replay e matrix evidence lock;
manter claims externos bloqueados ate certificacao explicita; revisar aliases
quando vendors mudarem nomes comerciais.
