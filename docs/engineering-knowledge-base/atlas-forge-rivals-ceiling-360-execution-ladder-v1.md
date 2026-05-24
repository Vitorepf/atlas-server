---
id: atlas-forge-rivals-ceiling-360-execution-ladder-v1
type: engineering_knowledge
title: Atlas Forge Rivals Ceiling 360 Execution Ladder v1
status: active
category: programming-forge
priority: 94
summary: Contract for the Rivals ceiling-360 L5 corpus, execution ladder, observed evidence coverage and next measurement commands.
tags:
  - atlas
  - forge
  - rivals
  - ceiling-360
  - provider-arena
capabilities:
  - rivals_ceiling_360
  - rivals_execution_ladder
  - rivals_capability_separation
unlocks:
  - rivals_hard_runner_differentiation
  - rivals_ceiling_360_readiness_ladder
  - rivals_observed_real_evidence_coverage
governs:
  - rivals_ceiling_360_case_set
  - rivals_provider_arena_readiness_ladder
  - rivals_provider_performance_signal
evidence:
  - "php artisan test --filter='AtlasForgeRivalsProviderArenaCoreTest|AtlasForgeRivalsProviderArenaCorpusServiceTest|AtlasForgeRivalsReportV3Test'"
  - "php artisan atlas:forge:rivals arena-readiness --json"
  - "php artisan atlas:forge:rivals report --run-id=<run_id> --json"
decisions:
  - ceiling-360 is the practical maximum-pressure corpus for next-generation runner mapping.
  - Ties in L5 cases are diagnostic signals, not claims.
  - Observed ladder coverage counts only real provider runs with comparable verdict, replay passing and no hard failures.
  - Rivals emits measured evidence; Atlas Decide decides model routing.
next_actions:
  - Use canary_8 before floor_24 or full_120 when spending provider tokens.
  - Treat L5 ties as diagnostic and inspect capability separation before declaring any qualitative winner.
  - Keep dry-run and local_fake out of observed real coverage.
maintenance:
  - Update when ceiling-360 size, canonical pairs, coverage policy or provider signal schema changes.
  - Keep in sync with next runner architecture, Provider Arena readiness and Report v3 tests.
related_paths:
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
  - docs/engineering-knowledge-base/atlas-forge-rivals-next-runner-architecture-v1.md
  - app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsProviderArenaReadinessService.php
  - app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsReportService.php
doc_schema: atlas_canonical_module_doc.v1
owner: programming_rivals
graph_id: atlas-forge-rivals-ceiling-360-execution-ladder-v1
graph_title: Atlas Forge Rivals Ceiling 360 Execution Ladder v1
graph_world: atlas
graph_layer: system
graph_kind: contract
graph_parent: atlas-forge-rivals-next-runner-architecture-v1
graph_status: active
graph_source: repo
canonical_source: docs/engineering-knowledge-base/atlas-forge-rivals-ceiling-360-execution-ladder-v1.md
repo_paths:
  - docs/engineering-knowledge-base/atlas-forge-rivals-ceiling-360-execution-ladder-v1.md
allowed_changes:
  - Add harder case-set coverage, canonical readiness pairs, observed coverage fields and focused tests.
forbidden_changes:
  - Counting dry-run, local_fake, generated specs or broken replay as observed real coverage.
  - Promoting an L5 tie to an external claim.
  - Letting Rivals change Atlas Decide provider topology.
depends_on:
  - atlas-forge-rivals-next-runner-architecture-v1
flows_to:
  - atlas-forge-rivals-intelligence-ledger-v1
  - atlas-decide
required_tests:
  - "php artisan test --filter='AtlasForgeRivalsProviderArenaCoreTest|AtlasForgeRivalsReportV3Test'"
requires_evidence: true
risk_level: high
---
# Atlas Forge Rivals Ceiling 360 Execution Ladder v1

## Papel no Atlas

Este documento governa a camada de teto pratico do Rivals: casos L5,
Provider Arena 360, escada de execucao real e cobertura observada. Ele existe
para responder "quem e bom em que" sem transformar evidencia parcial em claim.

Rivals emits measured evidence; Atlas Decide decides model routing.

## Onde Se Encaixa

Este contrato fica abaixo da arquitetura de runners e acima dos reports. Ele
detalha apenas como executar e medir o teto L5; registry, provider resolver e
command builders continuam governados pela doc de next runner architecture.

## Resumo

O Rivals precisa sair da matriz facil de 40 casos quando os runners empatam.
`ceiling-360` cria pressao L5 e a `execution_ladder` transforma essa pressao em
execucao gradual: canario, piso e sweep completo. Nenhuma camada muda Atlas
Decide ou promove claim sem evidence/replay.

## Contratos

Contratos obrigatorios:

- `ceiling-360` permanece L5, ambigua, longa e com replay/evidence matrix.
- Readiness planeja comandos e blockers sem spawnar provider.
- Cobertura observada conta somente runs reais comparaveis, replay-verdes e sem
  hard failures.
- L5 empatado e sinal diagnostico, nao claim.

## Fluxo

Fluxo canonico:

1. Readiness emite pares, dry-runs, comandos reais confirmados e disk/driver blockers.
2. Operador executa canario real quando ha espaco e confirmacoes.
3. Report valida evidence/replay/matrix e calcula separacao por capacidade.
4. Ladder reconta apenas evidence valida ja existente.
5. Ledger/Decide recebem apenas sinais advisory quando todos os floors passam.

## Regras para IA

Agentes nao devem interpretar empate L5 como ausencia de dificuldade. Primeiro
verificam se a amostra e pequena, se os modelos sao iguais, se a separacao por
capacidade e insuficiente e se o floor 360 ainda nao foi preenchido.

Nao executar provider real sem as tres confirmacoes e sem disk guard verde.

## Escopo de Implementacao

Escopo permitido:

- Ajustar matriz canônica de pares.
- Ajustar cobertura observada e blockers.
- Adicionar casos L5 e testes de corpus/readiness/report.

Fora de escopo:

- Mexer no Atlas Decide.
- Desbloquear certificacao externa.
- Contar dry-run ou local_fake como real run.

## Ceiling 360

`ceiling-360` e o preset de teto pratico para runners de proxima geracao:
120 casos L5 com perfil de pressao `L5+`. Todos declaram risco `critical`,
ambiguidade alta, contexto longo, planejamento dominante
(`planning_weight >= 0.70`) e cobertura explicita das capacidades obrigatorias
de 360:

- `long_context_retention`
- `multi_step_reasoning`
- `rollback_safety`
- `scope_boundary_discipline`
- `replayable_evidence_quality`
- `honest_blocker_behavior`
- `ambiguous_human_prompt_handling`

Um empate em `ceiling-360` nunca vira claim real sozinho. Ele exige separacao
estatistica, replay e revisao humana.

Cada caso `ceiling-360` tambem carrega
`ceiling_pressure_profile`
(`atlas.forge.rivals.ceiling_pressure_profile.v1`) para evitar que um teste
grande ainda seja facil. O floor minimo e:

- `pressure_level=L5+`
- `estimated_context_tokens >= 12000`
- `reasoning_depth >= 6`
- constraints parcialmente conflitantes;
- regressao nao obvia;
- invariantes de producao;
- rollback e replay matrix;
- fronteira honesta de incerteza;
- autoavaliacao por capacidade medida.

Esse perfil aumenta a dificuldade do prompt/corpus sem alterar o limite de
seguranca: dry-run continua sem provider, local_fake nao vira claim e real-run
segue bloqueado por disk/driver/confirmacoes quando necessario.

## Separacao de Capacidades

`capability_coverage.separation`
(`atlas.forge.rivals.capability_separation.v1`) diferencia capacidades que
realmente separaram runners, capacidades empatadas/human-review e capacidades
ainda subamostradas.

Empate e diagnostico operacional:

- `tie_is_diagnostic_not_claim=true`
- `difficulty_ceiling_reached=false` enquanto houver amostra insuficiente
- `requires_harder_followup=true` para L5 empatado ou amostra invalida

O signal nunca converte empate em claim. Ele aponta quais eixos precisam de
`ceiling-360`, `extreme-differentiator`, `meta-provider-stress` ou
`statistical-repeat`.

## Difficulty Pressure

`provider_performance_signal.difficulty_pressure`
(`atlas.forge.rivals.difficulty_pressure.v1`) detecta quando a matriz de 40
casos ou L5 ainda esta facil demais:

- sem amostra valida: `needs_valid_difficulty_sample`
- L5 valido empatado: `l5_tied_needs_extreme_pressure`

Ambos preservam advisory-only invariants e recomendam mais medicao, nao routing.

## Readiness Matrix

`arena-readiness --json` expoe a matriz operacional sem depender de report
anterior:

- `case_set=ceiling-360`
- `case_count=120`
- `ceiling_360_matrix=true`
- `pair_count=8`
- `dry_run_command` por par
- `next_command` real apenas com confirmacoes explicitas

Readiness nunca executa provider. Ela pode emitir
`real_run_ready_after_confirmations`, `plan_ready_driver_missing` ou
`plan_ready_evidence_disk_blocked`.

Pares canonicos minimos:

- `atlas_forge` vs `claude_code`
- `atlas_dev` vs `atlas_forge`
- `composer_2_5` vs `codex_cli`
- `cursor_cli` vs `claude_code`
- `claude_code` vs `codex_cli`
- `codex_cli` vs `gemini_cli`
- `claude_code sonnet` vs `claude_code opus`
- `atlas_forge full_power` vs baseline

## Execution Ladder

`execution_ladder`
(`atlas.forge.rivals.ceiling_360_execution_ladder.v1`) tem tres etapas:

- `canary_8`: 8 casos x 8 pares = 64 runs reais.
- `floor_24`: 24 casos x 8 pares = 192 runs reais.
- `full_120`: 120 casos x 8 pares = 960 runs reais.

A escada preserva dificuldade L5+ e permite coletar evidencia real em camadas
antes de gastar no sweep completo. Os comandos do primeiro caso de cada etapa
devem existir em dry-run e em template real com as tres confirmacoes.

## Cobertura Observada

A ladder mede cobertura observada a partir de manifests reais ja existentes:

- `observed_real_runs`
- `replay_verified_runs`
- `missing_real_runs`
- `completion_ratio`
- `coverage_status`
- `observed_runs`

So contam como evidencia valida os runs com:

- `external_provider_call=true`
- `provider_tokens_spent=true`
- `verdict=comparable`
- replay verde
- `hard_failures=[]`

Tentativas invalidas, dry-runs, local_fake e specs geradas ficam fora da
cobertura para impedir que um run quebrado preencha o piso 360.

## Complexity Profile Coverage

`provider_performance_signal` expoe
`complexity_profile_coverage`
(`atlas.forge.rivals.complexity_profile_coverage.v1`) com:

- cobertura de perfis;
- tokens de contexto estimados;
- profundidade de raciocinio;
- diversidade de dominios;
- distribuicao de risco/ambiguidade;
- rollback/multi-step;
- casos que exigem contexto longo;
- casos que exigem evidence matrix.

O summary expoe `multi_step_plan_cases`, `evidence_matrix_cases` e
`min/max_estimated_context_tokens`. Quando a cobertura e incompleta,
`do_not_use_when` emite condicoes como
`complexity_profile_coverage_incomplete`, `long_context_not_measured`,
`evidence_matrix_not_measured` e `multi_step_plan_not_measured`.

## Meta Provider Claim Floor

`meta_provider_claim_floor_met` so pode ser verdadeiro quando a bateria tem:

- pelo menos 16 casos;
- cobertura completa de `complexity_profile`;
- pelo menos 8 dominios;
- contexto longo, evidence matrix e multi-step em todos os casos;
- pelo menos um caso de alta ambiguidade;
- pelo menos um caso de risco alto/critico.

Quando esse floor falha em amostra grande, `do_not_use_when` adiciona
`meta_provider_stress_floor_not_met`. Isso impede que uma bateria facil seja
lida como prova de capacidade em programacao pesada ou meta-provider de
contexto longo.

## Ledger Boundary

`provider_performance_signal.can_feed_ledger` so pode ser verdadeiro quando a
confianca do run permite ledger e `do_not_use_when` esta vazio. Contradicoes
como `can_feed_ledger=true` com `ledger_blockers` nao vazios sao invalidas.

O schema exige `human_prompt_contract_coverage` e `complexity_profile_coverage`
dentro de `provider_performance_signal`, ambos advisory-only e com
`routing_effect=none`.

## Dependencias

`atlas-forge-rivals-next-runner-architecture-v1`,
`atlas-forge-rivals-provider-arena-v2`,
`atlas-forge-rivals-intelligence-ledger-v1` e
`atlas-canonical-glossary-and-naming`.

## Evidencias

Evidencias esperadas: testes de readiness ladder, corpus `ceiling-360`, Report
v3 difficulty/capability gates, `arena-readiness --json`, docs-health,
architecture-validate e `git diff --check`.

## Riscos

Riscos principais: bateria facil sendo lida como superioridade; empate
qualitativo virando claim; coverage contando tentativa quebrada; disk guard
ignorado antes de provider call; matriz real executada sem confirmacoes.

## Exemplos

- Canario seguro: dry-run de `atlas_forge` vs `claude_code` em
  `ceiling-360-001-industrial-005-incident_rollback`.
- Separacao por meta-provider: `composer_2_5 default` vs `codex_cli gpt-5.5`
  primeiro em dry-run e so depois com confirmacoes reais.
- Empate L5: report deve emitir human review e proxima medicao, nao claim.

## Proximas Acoes

Quando `capability_floor_not_met` ou empate L5 aparecer, o Report v3 deve emitir
matriz 360 de Provider Arena com dry-runs e templates reais confirmados. Todos
os dry-runs declaram `external_provider_call=false`, `provider_tokens_spent=false`
e `routing_effect=none`; templates reais exigem:

- `confirm-runbook-reviewed`
- `confirm-provider-cost`
- `confirm-real-provider-call`
