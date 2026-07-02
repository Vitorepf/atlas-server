# Atlas Engineering Multiplier — auditoria de alavancas (2026-07-02)

Objetivo: maximizar M = capacidade_do_modelo_com_Atlas / capacidade_do_modelo_puro.
Método: verificação por leitura direta + probes no DB vivo (não claims de agente sem prova).
Regra aplicada: alavanca só conta se o sinal chega no ponto de decisão do fluxo vivo.

## Achado dominante

O DB vivo (`atlas`, pgsql) divergiu do schema declarado: **30 migrações constam "Ran" na
tabela `migrations` mas ~40 tabelas que elas criam NÃO existem** (provado por probe
`pg_tables` × `Schema::create` em 2026-07-02). Entre as mortas: `atlas_aemor_*` (6),
`atlas_aver_*` (6), `atlas_aael_*` (5), `atlas_dev_task_packets` / `atlas_dev_context_gates` /
`atlas_dev_failure_capsules` / `atlas_dev_outcome_memories` / `atlas_dev_run_certifications`,
`ai_programming_runtime_telemetry_events`, `ai_learning_proposals`, `failure_signatures`,
`atlas_loop_pipeline_state`, `atlas_loop_test_coverage_edges`.

Consequência: todo write-side "armado" nessas mesas é fail-open silencioso (ex.: persist de
failure capsule M5 do `SeniorEngineerLoopExecutor:212` escreve no vazio) e todo read-side
retorna vazio. Uma fatia grande do aprendizado "fechado" nas campanhas w9–w20 roda no escuro.

Também encontrado e já corrigido nesta sessão: classmap do Composer envenenado com paths de
worktree rivals2 deletado dentro de `storage/` (quebrava `artisan migrate:status`) —
`composer dump-autoload` regenerou.

## Ranking top-10 (efeito em M ÷ risco)

| # | Alavanca | Gargalo real | Arquivos | Efeito em M | Risco | Menor implementação |
|---|---|---|---|---|---|---|
| 1 | Restaurar schema de aprendizado | 30 migrações "Ran" sem tabela; learning spine morto no storage | `database/migrations/*` (30), DB vivo | Revive M5 capsules, AEMOR (recentOutcomeFacts), telemetria, learning proposals — tudo já fiado | Baixo (migrações idempotentes com guard hasTable) | des-carimbar as 30 + `artisan migrate` real + prova de write vivo |
| 2 | M5 capsules no task packet servido | `AtlasTaskServingService::next()` serve packet sem known_failure_modes; Dev/Forge/FastPath já injetam, worker externo (modelo pequeno) não recebe | `AtlasTaskServingService.php:179-186`, `DevFailureCapsulePromptInjector` | Small-model uplift direto: worker externo herda memória de falha da área antes de tocar | Baixo | injectFor(allowed_files, workspace) no projectTask, fail-open |
| 3 | weak_output → memória | Sinal `weak_output_detected` (W1/w4) morre no run_summary; próxima run não vê | `PipelineRunExecutor.php:602-608` | Verde-fraco vira capsule/outcome — repara recorrência | Baixo | persist capsule com failure_class=weak_output no bloco do verdict |
| 4 | give_back → classificação/respec | give_back só libera lease + contador; nenhum aprendizado/respec automático | `AtlasTaskServingService::report()` ~420, `AtlasTaskRepairBlockedCommand` | Menos give_back repetido; packet re-especificado sem humano | Médio (não criar spin de respec) | classificar give_back e agendar respec p/ classes scope_gap |
| 5 | ADML live outcome feedback | Router w25 consulta ADML que sempre responde free_to_choose (feeder Rivals 1.0 aposentado); consultas logadas, outcomes não voltam | `AtlasDecideMetaLearningService.php:143-158`, `SpecComposer.php:332-402` | Roteamento por evidência real (route correctness) | Médio | alimentar outcome real do run Dev no canal live já existente |
| 6 | Dev run → atlas_context_feedback | AOBG pede feedback (used/noise/missed) e nenhum fluxo interno grava | `AtlasOpenBrainContextPackService.php:224`, MCP service | Context pack aprende o que foi ruído (stale/irrelevant rate) | Médio | gravar feedback pós-verification no senior loop |
| 7 | Distiller truncando no meio | `DevContextBudgetDistiller` corta `substr($content,0,$remaining)` no meio de unidade lógica | `DevContextBudgetDistiller.php:108` | Compaction sem perder fato crítico | Baixo | cortar na fronteira de item/linha, nunca mid-entry |
| 8 | repair-blocked automático | `atlas:task:repair-blocked` só manual | `AtlasTaskRepairBlockedCommand` | Fila desentope sozinha (esteira é a meta) | Baixo | scheduler flag-gated |
| 9 | GC/índice do receipt store | 6.275 receipts/35MB, scandir cap 300, sem prune | `DevGreenRunExemplarRetriever.php:34-40` | Latência/estabilidade do retrieve de exemplares | Baixo | retention policy + comando agendado |
| 10 | Forge→compounding bridge (O-1) | `complete()` do Forge não alimenta compounding via conductor | WIP NÃO-COMMITADO já existe nos 3 arquivos (conductor+cycle+config) | Forge entra no loop composto | — | **em andamento por outra sessão — não tocar** |

## Refutados / não re-propor (desta auditoria)

- "Tabelas de outcome do Dev existem e são escritas em preview" (claim de agente): FALSO no DB
  vivo — tabelas ausentes; o write em preview também falha silencioso.
- `escalation_decision.json` órfão: JÁ FECHADO (ponte w13-w19 → Attention via
  `DevToForgePromotionService::candidateFromRunEscalation`).
- `delegation.target_flow_id` executor: claim de agente com file:line inverificável; w30 já
  classificou delegação pós-w23 como sinal fraco/ambíguo — não perseguir sem organ novo.

## Incidente #1 executado (S1) — wiper vivo + restauração

Durante a execução da alavanca #1 foi flagrado um **wiper ativo**: processo externo emitindo
`drop table if exists` um-a-um contra o DB vivo (ondas 18:11 UTC e 20:51 UTC de 02/07, provadas
no log do Postgres; a segunda derrubou `atlas_memory_entries` + 8 satélites DEPOIS da primeira
restauração desta sessão). Suspeito principal: suite `php artisan test --parallel` detached
(PID 37990, órfã, rodando desde 15:15 local sobre o repo vivo) e/ou braços de bateria em
worktrees sob `storage/atlas/rivals2/` que compartilham `.env` e `vendor/` do repo vivo (o
`vendor/composer/autoload_static.php` chegou a apontar 13k classes para dentro de um worktree,
provando dump-autoload cruzado). A suite foi morta; `log_statement=ddl` foi ativado no Postgres
para atribuição definitiva da próxima tentativa.

Restauração executada (procedimento: des-carimbar migração idempotente + `migrate` real):
- 88 tabelas da espinha de aprendizado (AEMOR, AVER, AAEL, compounding, dev runtime
  intelligence M5, code intelligence, missions, telemetry, learning proposals, loop state).
- 10 tabelas core (registro canônico de memória + satélites, audit_events,
  atlas_engineering_runs, open_brain_access_logs) — re-derrubadas pelo wiper e re-restauradas.
- 4 tabelas do gateway async (ai_jobs, ai_job_attempts, ai_worker_events, ai_stream_events) —
  mortas desde ~15/06 (traces async pararam nessa data), invisíveis até o auditor aprender DDL cru.
- 3 migrações endurecidas para idempotência real (runner tables, gateway, stream events).

**BLOCKER (operador): os DADOS de `atlas_memory_entries` (registro canônico) e satélites foram
destruídos pelo wiper.** Schema restaurado vazio. Rotas de recuperação: Time Machine/snapshot do
volume Postgres do OrbStack (requer Full Disk Access), ou reconstrução parcial via provider
projections no git (subset provider-safe) + AOBG session captures. `migrations_backup_20260702`
guarda o estado pré-intervenção da tabela `migrations`.

Gate novo no fluxo vivo: `atlas:ai:doctor` agora audita drift declarado-vs-vivo
(`SchemaDriftAuditor`, cobre Schema::create E DDL cru) e **retorna exit 1** com drift — o modo
de falha que ficou invisível por semanas agora bloqueia.

## Métricas honestas para antes/depois

- Contagem de linhas nas tabelas revividas (0 → N com runs reais) — prova de write vivo.
- Task packet servido contém `known_failure_modes` quando existem capsules da área (teste funcional).
- give_back rate por packet família (já contado no serving stack) — baseline hoje, comparar pós-#4.

## Execução S2–S5 (02/07/2026)

- **S2 (entregue):** `known_failure_modes` viaja com o packet servido
  (`AtlasTaskServingService::knownFailureModesFor` → `DevFailureCapsulePromptInjector`,
  workspace-scoped, fail-open). Teste funcional `AtlasTaskServingFailureCapsuleTest` (área
  estrangeira não injeta; sem capsule = lista vazia honesta). Junto: **kill-switch de DB vivo**
  em `Tests\TestCase` (RuntimeException se a suite apontar para fora do sqlite :memory:;
  opt-in `ATLAS_ALLOW_LIVE_DB_TESTS=1`) + pins `force="true"` no phpunit.xml + guardião
  `TestDatabaseIsolationGuardTest`. Provado sob env hostil (DB_CONNECTION=pgsql exportado):
  recusa alto em vez de wipar produção.
- **S3 (entregue):** `atlas:engineering:m-scorecard` — fatos reais apenas. Baseline 02/07:
  127 runs do senior loop, pass_rate **0.346**, repair conversion **0.44**; esteira 159
  completed / 9 cancelled / 32 claimable; learning liveness capturada antes (tudo 0) e depois
  do backfill (capsules 56). Histórico em `storage/atlas/m_scorecard/history.jsonl`.
- **S4 (fixture honesta + blocker):** 56 failure capsules REAIS (backfill dos receipts
  `failure_capsule.*.json` de runs falhados reais) persistidas na tabela restaurada; injector
  provado devolvendo a memória para a área exata do run que falhou; canal serve→worker provado
  pelo teste de S2. **BLOCKER explícito: run real de modelo pequeno cru vs com-Atlas NÃO foi
  executado (proibição de provider spend sem aprovação).** O uplift está provado no nível do
  canal: o braço cru recebe spec seco; o braço Atlas recebe known_failure_modes +
  known_lessons + sibling_tests + exemplares da mesma área.
- **S5:** KB sync + index-code re-executados sobre as tabelas de code intelligence restauradas
  (0 → 167.637 símbolos / 170.479 doc links / 927 knowledge items — os context packs do AOBG
  voltaram a ter code graph). Falhas do `quality-scan --profile=fast` são PRÉ-EXISTENTES e não
  desta campanha: composer.lock desatualizado (require-dev `composer-unused`/`scribe` sem lock,
  commit ea2194b6cb), pint timeout 300s repo-wide, phpstan exit 255 repo-wide, shellcheck em
  scripts bin/. Wiring morto deliberadamente NÃO tocado: models Rivals v1
  (`AiRivalsShadowRun`/`AiRealExecutionRivalsBenchmark`, aposentadoria é do dono do Rivals2) e
  `ForgeOutcomeMemoryService` learning_candidates (coberto pelo WIP O-1 em andamento).

## Próxima maior alavanca (identificada, não iniciada)

**Ranking #3 — weak_output → memória:** o sinal `weak_output_detected`
(`PipelineRunExecutor:602-608`) morre no run_summary; persistir capsule com
`failure_class=weak_output` no bloco do verdict fecharia verde-fraco → memória → prompt da
próxima run na mesma área (leitor já existe e agora tem storage vivo). Depois: #4 give_back →
classificação/respec automático.
