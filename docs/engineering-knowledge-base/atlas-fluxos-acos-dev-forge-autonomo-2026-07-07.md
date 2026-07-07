# Atlas — Todos os Fluxos: ACOS · Dev · Forge · Autônomo (ACDE)

> **Data:** 2026-07-07 · **Escopo:** mapeamento exaustivo de fluxos de runtime dos quatro subsistemas.
> **Método:** rastreamento de cada fluxo ponta-a-ponta a partir do entrypoint real (comando / rota HTTP / job / schedule) seguindo a cadeia de chamadas no **código**, com `arquivo:linha`. Docs canônicos foram usados só como pista; **onde doc e código divergem, este documento reporta o código** e sinaliza o drift com ⚠️.
> **Limitação deste checkout:** ambiente efêmero **sem `.env`** — flags de operador (ex.: `ATLAS_LOOP_AUTO_MERGE_TO_MAIN`) resolvem para o default. Portanto o que está documentado é o **maquinário e os gates** (que são reais e verificáveis no código); o que o *runtime de produção do operador* tem *armado* não é observável daqui. Isso é declarado explicitamente onde importa (ver §Correções).

---

## Sumário executivo — o que cada subsistema é, em uma frase

| Sistema | O que é (verificado no código) | Superfície de entrada |
|---|---|---|
| **ACOS** (Atlas Cognition OS) | Camada **read-only** de scoring/gating cognitivo: mede se o Atlas está evoluindo e converte em nota `x/10` **resolvida de evidência**, nunca fabricada; bloqueia claims sem janela real de dados. | ~5 comandos + 3 schedules; **sem rotas HTTP** |
| **Dev** (Atlas Dev) | Núcleo de programação **leve/média** *workspace-bound*: plan (custo-zero-token) + run (fast-path com gates fail-closed) + escalação honesta para Forge. Nunca cria Obra. | HTTP `/ai/interactions/atlas-dev/*` + dezenas de comandos |
| **Forge** | Fábrica de **execução autônoma governada**: provider-invocation com ~18 blockers, work-packet lifecycle, shadow-patch, review/completion gate, Rivals arena. `dry_run` default; `execute` exige aprovação+budget+receipt. | Superfície HTTP densa `/atlas-code/works/{project}/forge/*` + job + comandos |
| **Autônomo / ACDE** | O "Loop": mói um escopo 24/7 propondo evoluções certificadas (`evolve`, propose-only) **e** um **drain separado** que aterrissa propostas na `main` sozinho (re-prova→apply→lint→boot-smoke→canário→commit→receipt), gated e reversível. | 21 schedules 24/7 + 449 comandos `atlas:loop:*` + job |

---

## Mapa cross-system — como os fluxos se conectam

```
                    ┌──────────────────── ACOS (transversal, read-only) ────────────────────┐
                    │  scorecard 73 subsistemas → evolution-score x/10 → long-horizon gate    │
                    │  bloqueia o CLAIM "Atlas evoluiu" até haver janela real ≥30d de dados    │
                    └──────────────────────────────────────────────────────────────────────┘
                                       ▲ mede/gateia (não executa)
                                       │
  operador / IA ──HTTP──► ┌────────┐  escalação    ┌──────────┐  intake/obra  ┌───────────────────────┐
  (workspace)            │  DEV   │ ─────────────► │  FORGE   │ ◄──────────── │  AUTÔNOMO (ACDE Loop)  │
                          │ plan+run│ escalation_    │ execução │  candidatas   │  24/7: propõe + certifica│
                          │ gates   │ packet.v1 +    │ governada│               │  + DRAIN → main sozinho  │
                          │ FAIL-   │ route_         │ FAIL-    │               │  (gated, reversível)     │
                          │ CLOSED  │ decision.v1    │ CLOSED   │               │                          │
                          └────────┘ (humano no loop)└──────────┘               └───────────────────────┘
       Dev ≠ Forge: Forge NÃO importa o runtime do Dev; lê só o contrato EscalationPacket.
       Obra só nasce em tier `forge_obra` EXPLÍCITO — nenhum run/thread auto-promove.
```

**A espinha de execução** (Dev → Forge → Autônomo) é uma escada de autoridade crescente com o **humano no loop** na promoção Dev→Forge; o Autônomo é o único que fecha o ciclo sozinho, e só sob flag de operador armada + cadeia de gates. **ACOS é ortogonal**: não executa nada — mede e *veta o direito de dizer que evoluiu*.

---

## Entrypoints consolidados (fonte de verdade: `routes/`, `app/Console/Commands/`, `app/Jobs/`)

| Sistema | Schedules 24/7 | HTTP | Comandos | Jobs |
|---|---|---|---|---|
| ACOS | 3 (`mint-pipeline-receipts` 06:30, `acos-long-horizon-gate` 06:55, `predictive-code-intelligence-gate` 07:25) | — | 5 (`atlas:cognition:*`, `atlas:acos:*`) | — |
| Dev | — (sob demanda) | `atlas-dev/{readiness,plan,run,runs,stream,cancel}` + `dev-to-forge/*` | dezenas (`atlas:cli:dev*`, `atlas:dev:*`, senior-loop `:run`/`:audit`) | — |
| Forge | — (sob demanda) | `works/{project}/forge/{live-executions,fast-path,reviews,intake,provider-topology,runtime-dispatch,...}` | ~26 (`atlas:forge:*`, `atlas:code:forge:*`, `atlas:rivals`) | `AtlasCodeForgeLiveExecutionJob` (fila `atlas-code-forge`) |
| Autônomo | 21 (`automerge` 15min, `keepalive` 2min, `backlog-feed`, `perpetual-sweep`, `mutation-gate`, `judge-calibration`, `strategy-bandit`, `auto-architecture`, `coverage-gaps`, `loss-observer`, `morning-digest`, `weekly-agenda`, `meta-harness-ab-lift`, `cross-file-consumer-gate`, `formal-invariant-gate`, `taxa2-dials`, `confidence-calibrate`, `cortex:cadence`, …) | — | 449 (`atlas:loop:*`) | `SoftwareCompanyLoopRunJob` |

---

## Correções e drifts consolidados (código vence a narrativa)

Esta seção registra, com honestidade, pontos onde a narrativa dos docs (ou de análises anteriores) diverge do código verificado. **É o núcleo do valor deste documento.**

### C0 — O Autônomo **não é propose-only**, mas a prova de *execução* neste checkout é indireta
- **Design:** existe um caminho de merge-para-main autônomo completo — `AtlasLoopAutoMergeService::drain()` acionado por `atlas:loop:automerge` (agendado a cada 15 min, `routes/console.php:139`), gated por `AtlasLoopMasterSwitch::enabled()` **E** `config('atlas.ai.loop.auto_merge_to_main')`. O comando `atlas:loop:evolve` é de fato propose-only; o **drain** é o consumidor que fecha o ciclo. Logo, "propose-only end-to-end" é **falso**.
- ⚠️ **Correção da correção:** os **47 commits `atlas-task land-*`** na `main` **NÃO** são do drain ACDE. São do comando `atlas:land` (`AtlasLandCommand` → `AtlasTaskScopedCommitter`), todos autorados por `Vitor Freire <vitordsny@gmail.com>`. O drain autoraria como `-c user.email=loop@atlas -c user.name=atlas-loop` com mensagem `atlas loop auto-merge:` (`AtlasLoopAutoMergeService.php:650-652`) — e desses há **zero** neste histórico. Neste checkout (sem `.env`, master switch fail-closed OFF) o drain **não** produziu commits; o que está provado é (a) o maquinário do drain, real e rigorosamente gated, e (b) uma cadência real de *landing de sessões de agente* via `atlas:land`. Se o drain está ativo no ambiente de produção do operador, isso depende da flag armada lá — não observável daqui.

### C1 — Boundary **Dev ≠ Forge** é nuançado, não absoluto
Forge não importa o runtime/orquestração do Dev, mas importa 3 símbolos legítimos de contrato: o schema `EscalationPacket`, o utilitário `IntentActionExtractor`, e `AtlasDevGateAdapter` (que vive em `EngineeringKernel/Adapters`, não em Forge). Direção correta: **Dev escreve o pacote, Forge lê**.

### C2 — Risco é **R0..R5** (seis níveis), não R1..R6; gatilho de escalação Forge = `riskIndex>=4`
Não existe R6. (Corrige textos que citam "risk≥R4" implicando escala 1-6.)

### C3 — "Master switch" do ACOS (`atlas.acos.cadence_enabled`) **não existe em config**
Não há chave `'acos' => [...]` em `config/atlas.php`; as 5 leituras usam default `true` — o switch é **efetivamente sempre ligado**, sem caminho operável para o OFF, apesar do comentário em `routes/console.php` prometer "OFF ⇒ byte-idêntico". ⚠️ drift comentário-vs-config.

### C4 — Vários "fluxos" são pares de **subsistemas distintos**
No Forge: **intake** (7A HTTP-metadata vs 7B motor canônico `ForgeIntakeService`/`ai_forge_intakes`), **work-packet execution cycle** (duas implementações; o gate `complete()` real está no Service `:318-351`), e **Rivals** (Arena core `atlas:rivals` + Forge-Native Rivals + shadow DB). ⚠️ Rivals está atualmente **DEPRECATED/OFF** por decisão do operador.

### C5 — Fatias canônicas planejadas mas **órfãs** (sem chamador vivo, só testes)
`ForgePromotionPreviewBuilder` e `DevToForgeEscalationPacketFactory` (Dev); `ForgeFallbackCapableEntryDetector` dead-wired (Forge). O caminho vivo monta o pacote direto em `DevToForgePromotionService`.

### C6 — `SeniorLoop` **não** é iteração executor↔auditor
Auditor = audit estático one-shot do plano (dentro do `planOnly`); Executor = 1 plan + 1 execute (`attempts_executed=1` fixo). O loop de repair-to-green real vive no `PipelineRunExecutor`. Não existem `senior-loop:readiness` nem `:smoke` (só `:run` e `:audit`).

### C7 — A camada `SelfConstruction/CodexRealInvoker*` é **scaffolding dormente**
As ~64-98 nano-classes `AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvoker*` retornam todos os flags de capacidade hardcoded `false`, não têm primitivas de spawn e nada agendado. É o anti-padrão de over-scaffolding que o próprio `CLAUDE.md` cataloga — dentro do subsistema que deveria combatê-lo.

### C8 — Honestidade estrutural real (não é retórica) onde existe
`CompletionDecision.__construct` lança exceção se `passed` coexistir com flag de dúvida (verde-silencioso impossível por construção); `GitWorkspaceInspector` retorna blocker estruturado em vez de mock em todos os ramos; Adjudicator do Rivals é `claim_allowed=false` por default; o `AtlasLoopNextWorkDecider` é `FORBIDDEN_SELF_TARGET` e re-resolve sinais frescos (ungameable). Esses são gates **executáveis e testados**.

---

# PARTE I — ACOS · Fluxos detalhados

---

## ACOS — Todos os Fluxos

> **Escopo**: subsistema **ACOS (Atlas Cognition Operating System)** do `atlas-server` (Laravel/PHP).
> **Método**: código lido diretamente e cadeia de chamadas seguida símbolo-a-símbolo. Onde doc e código divergem, o código prevalece e o drift está sinalizado com `⚠ DRIFT`.
> **Regra de ouro aplicada**: nenhuma afirmação vem de doc; toda linha abaixo tem `arquivo:linha` verificado.

### Mapa dos artefatos

| Papel | Classe | Arquivo |
|---|---|---|
| Scorecard agregador (73 subsistemas × 3 dimensões) | `AtlasCognitionScoreCardService` | `app/Services/Ai/Cognition/AtlasCognitionScoreCardService.php` |
| Resolver de evidência (doc_status + pipeline_status) | `AtlasCognitionEvidenceResolver` | `app/Services/Ai/Cognition/AtlasCognitionEvidenceResolver.php` |
| Evolution Score (3 notas da evolução) | `AtlasAcosEvolutionScoreService` | `app/Services/Ai/Cognition/AtlasAcosEvolutionScoreService.php` |
| Long-Horizon Gate (janela ≥30d) | `AtlasAcosLongHorizonGateService` | `app/Services/Ai/Cognition/AtlasAcosLongHorizonGateService.php` |
| Window Gates D3/D4/D5 | `AtlasAcosWindowGatesService` | `app/Services/Ai/Cognition/AtlasAcosWindowGatesService.php` |
| Predictive Code-Intelligence Gate | `PredictiveCodeIntelligenceCorrelationGateService` | `app/Services/Ai/Cognitive/PredictiveFailure/PredictiveCodeIntelligenceCorrelationGateService.php` |
| Feeder append-only (série delta) | `AtlasFableDeltaSeriesCommand` | `app/Console/Commands/AtlasFableDeltaSeriesCommand.php` |
| Mint de pipeline receipts | `AtlasCognitionMintPipelineReceiptsCommand` | `app/Console/Commands/AtlasCognitionMintPipelineReceiptsCommand.php` |

Comandos (todos em `app/Console/Commands/`):
- `atlas:cognition:scorecard` → `AtlasCognitionScorecardCommand`
- `atlas:cognition:evolution-score` → `AtlasCognitionEvolutionScoreCommand`
- `atlas:cognition:acos-long-horizon-gate` → `AtlasAcosLongHorizonGateCommand`
- `atlas:acos:window-gates` → `AtlasAcosWindowGatesCommand`
- `atlas:cognition:mint-pipeline-receipts` → `AtlasCognitionMintPipelineReceiptsCommand`
- `atlas:cognition:predictive-code-intelligence-gate` → `AtlasPredictiveCodeIntelligenceGateCommand`

Diretório de evidência (Evidence Ledger em disco): `storage/app/atlas/evidence/`
- `fable-delta-series.jsonl` — série append-only (input dos gates de janela)
- `acos-long-horizon-gate.json` — receipt do long-horizon gate
- `predictive-code-intelligence-gate.json` — receipt do predictive gate
- `*-gate.json` — qualquer receipt de gate (varrido pelo Window Gates panel)

---

## Fluxo 0 — Scorecard agregador (o coração do ACOS)

É o núcleo do qual quase todos os outros fluxos derivam. Certifica a **estrutura** dos 73 subsistemas canônicos, nunca volume de uso.

### Trigger/entrypoint
- CLI manual: `php artisan atlas:cognition:scorecard [--strict] [--json]` → `AtlasCognitionScorecardCommand::handle` (`AtlasCognitionScorecardCommand.php:29`).
- **Não tem schedule próprio** — é consumido por outros fluxos (evolution-score, long-horizon-gate, mint, fixed-N gate, delta-series).

### Cadeia de chamadas
1. `AtlasCognitionScorecardCommand::handle` (`:29`) → `AtlasCognitionScoreCardService::build()` (`AtlasCognitionScoreCardService.php:254`).
2. `build()` itera a constante `SUBSYSTEMS` (73 tuplas `[acronym,name,group,service_class]`, `:156-249`). Para cada subsistema resolve 3 dimensões (`:257-271`):
   - `code_status` ← `probeCodeStatus($serviceClass)` (`:293`): `class_exists` → `ready`; classe ausente → `blocked`; classe null → `building`.
   - `doc_status` ← `$this->evidence->resolveDocStatus($serviceClass)` (`AtlasCognitionEvidenceResolver.php:96`).
   - `pipeline_status` ← `$this->evidence->resolvePipelineStatus($serviceClass)` (`AtlasCognitionEvidenceResolver.php:123`).
3. `aggregateScore($rows)` (`:309`): pontos por status `ready=10, partial=6, building=3, blocked=0` (`STATUS_POINTS`, `:114-119`). Cada dimensão = `sum/max*10`; overall = média das 3 (`:327-332`).
4. `scorecard_hash` = `sha256` sobre `{acronym, code_status, doc_status, pipeline_status}` ordenados + overall (`hash()`, `:359-376`).

### Resolver de evidência (anti-over-claim) — `AtlasCognitionEvidenceResolver`
- `resolveDocStatus` (`:96`): `ready` **só** se algum doc canônico declara `symbol: <ref>` no frontmatter E `AtlasAaeosImplementationEvidenceResolver::resolve('symbol',<ref>)` casa **exatamente** com o FQN do service_class (FQN-bind em `matchedBindsFqn`, `:252`). Menção do nome curto no corpo do doc NÃO conta. Sem owner → `building`; FQN null/blank → `blocked`.
- `resolvePipelineStatus` (`:123`): monta `candidateTestRefs` = `<Short>Test` + todos os `test:` refs dos owner docs (`:136-145`). Se um símbolo *Test* existe no índice (`testSymbolExists`, `:266`) E há **green-run receipt fresco** (`AtlasAaeosTestExecutionService::hasGreenReceipt` keyed no `capability_id` do owner doc, com freshness hashes de `truth->freshnessHashes`, `:162-174`) → `ready`. Símbolo existe mas sem receipt verde fresco → `partial` (`existence_only_unrun`). Nenhum símbolo Test → `building`. FQN null → `blocked`.
- **Ownership index** (`ownershipIndex`, `:298`) é memoizado a partir de **uma** varredura `truth->docsWithEvidence()`; qualquer falha → índice vazio (withhold honesto, nunca crash).

### Gates/guards (anti-Goodhart estrutural)
- `doc_status` e `pipeline_status` **nunca** lidos da tupla `SUBSYSTEMS` — são função de evidência resolvida em runtime (`build():264-269` + comentário `:150-155`). Remover o doc owner ou o receipt **move** o status e o overall — um literal hardcoded não poderia mover.
- `pipeline_status=ready` exige receipt **FRESCO**: editar impl/test muda a freshness-hash e derruba `ready`→`partial` (`AtlasCognitionEvidenceResolver.php:120-122`).
- `hasGreenReceipt` é degrade-safe: tabela de receipts ausente → `false` (nunca fabrica ready).

### Persistência / efeitos colaterais
- **Nenhum** — `build()` é read-only puro (probes de classe/índice/receipts). Não escreve tabela nem arquivo.

### Resultado / saída
- Envelope `atlas.cognition.scorecard.v3` (`SCHEMA_VERSION`, `:103`): `subsystem_count=73`, `subsystems[]`, `score{overall_out_of_10, dimensions{code,doc,pipeline}}`, `claim_policy` (benchmark/rivals/superiority sempre `false`, `claimPolicy():343-354`), `scorecard_hash`.
- `--strict` sai com exit code **3** se `overall < 10.0` (`AtlasCognitionScorecardCommand.php:41-43`).

### Modos de falha / fail-closed
- Exceção em `build()` → command imprime erro e retorna exit **1** (`AtlasCognitionScorecardCommand.php:33-37`).
- Probe individual que falha nunca vira `ready` falso: `code`→`blocked`, `doc`→`building`, `pipeline`→`building`/`partial`.

⚠ **DRIFT (descrição do command)**: `$description` diz "73 subsistemas" e o código confirma 73 (`canonicalSubsystemCount():381` conta `count(SUBSYSTEMS)`), mas o `EvolutionScoreCommand` e docs às vezes falam "50+ deps" — a contagem real de tuplas é **73**.

---

## Fluxo A — Evolution Score (as 3 notas da evolução do ACOS)

### Trigger/entrypoint
- CLI: `php artisan atlas:cognition:evolution-score [--json]` → `AtlasCognitionEvolutionScoreCommand::handle` (`AtlasCognitionEvolutionScoreCommand.php:21`).
- **Sem schedule** — read-only, invocado sob demanda.

### Cadeia de chamadas
`Command::handle` → `AtlasAcosEvolutionScoreService::build()` (`AtlasAcosEvolutionScoreService.php:68`):
1. `$card = $this->scorecard->build()` (`:70`) — reusa o Fluxo 0.
2. `execucaoProvada($card)` (`:104`): **espelha** a dimensão `pipeline` do scorecard (`data_get($card,'score.dimensions.pipeline.score_out_of_10')`, `:106`). Score = pipeline; 1 signal `pipeline_green_run_receipts`.
3. `inteligenciaEntregue()` (`:123`) — 4 signals de 2.5 cada (teto 10):
   - `cadencia_viva` (`:129`): `1.25` se heartbeat do scheduler fresco (`heartbeatFresh`, arquivo `storage/atlas/scheduler/heartbeat.jsonl` ≤ **7200s**, `:226-229`,`:42`) + `1.25 × organs_scheduled/4`. Órgãos exigidos (`SCHEDULED_ORGANS`, `:48-53`): `mint-pipeline-receipts`, `fable:delta-series`, `refactor-census`, `acos-harvest-obra-lessons`, contados varrendo `app(Schedule::class)->events()` (`scheduledOrganCount`, `:240`).
   - `pack_anti_lixo` (`:137`): `2.5` se `unmarkedSessionEchoCount()==0` (`:261`) — conta nós `atlas_aurg_nodes` `source_kind=mission` cujo `meta.origin` ≠ `MISSION_ORIGIN_SESSION_CAPTURE` e que têm `provider` + `source_id` UUID (echo de sessão não-marcado). `-1` = store ausente → conta como 0 honesto.
   - `feedback_loop_vivo` (`:146`): `1.25` se `ai_rag_feedback_events` teve linhas nos últimos 7 dias + `1.25` se `atlas.ai.context_feedback.global_hints_enabled`.
   - `licoes_geridas` (`:158`): `1.25` se há `ai_learning_candidates.decision=hold` + `1.25` se há `=promote`.
4. `autonomia()` (`:171`) — 4 signals de 2.5:
   - `motor_vivo` (`:176`): `2.5` se heartbeat fresco.
   - `gates_auditados` (`:185`): `1.25` se `acos-long-horizon-gate.json` fresco (≤ **172800s** = 2 dias, `GATE_FRESH_SECONDS`, `:45`) + `1.25` se `fable-delta-series.jsonl` fresco.
   - `cadeia_tier_implementada` (`:192`): probe `class_exists('AtlasLoopTierPromotionChainService')` + `readiness()` (`tierChainReadiness`, `:309`); `1.25` implemented + `1.25` audited.
   - `execucao_governada` (`:207`): `0.5` master-switch legível (`loopMasterSwitchReadable`, `:348`) + `1.0` tier_exposed + `autonomousGovernance().points` (`:367`): `0.5` reversibilidade viva (`.git` presente E `AtlasBrainReplayCommand` registrado) + `0.5` Diário de Evolução íntegro (`AtlasEvolutionDiary::verifyChain()` ok e count>0).
5. `overall_out_of_10` = média das 3 dimensões (`:76`). `score_hash` = sha256 dos 4 números (`:93-95`).

### Gates/guards
- **Degrade-safe pétreo** (`:34-36`): toda probe que falha (tabela/arquivo/classe ausente) pontua **0** com evidência textual do porquê — nunca ready falso.
- Nenhum literal auto-declarado: toda parcela é função de DB/arquivo/agenda/classe (`notes.method`, `:89`).
- **Carta de Autonomia (Regra 4)**: a antiga parcela `operator_signed` (que travava o teto em 9.0) foi **revogada** e substituída pela medição real de reversibilidade + Diário (`:200-217`,`:360-398`).

### Persistência / efeitos colaterais
- **Nenhum** — read-only. O command só imprime (não escreve receipt).

### Resultado / saída
- `atlas.cognition.evolution_score.v1` (`:39`): `overall_out_of_10`, `dimensions{execucao_provada, inteligencia_entregue, autonomia}` cada com `score/max/signals[]`, `acos_scorecard_overall`, `score_hash`.

### Modos de falha
- Não há try/catch no `handle`; erro do `build()` propaga. Mas cada probe interna é isolada por try/catch retornando 0/`-1`.

---

## Fluxo B — Long-Horizon Gate (claim "ACOS 10/10 real" só com janela ≥30d)

### Trigger/entrypoint
- **Schedule** (`routes/console.php:180-185`): `atlas:cognition:acos-long-horizon-gate --write-receipt --json`, `dailyAt(config('atlas.cognition.acos_long_horizon_gate.schedule_time','06:55'))`, `withoutOverlapping()`.
- **Guard do schedule**: `->when(enabled && schedule_enabled)` — ambos default `true` (`config/atlas.php:1793-1794`).
- CLI: `atlas:cognition:acos-long-horizon-gate {--fixture=live|mature|short-window} {--series=} {--receipt=} {--write-receipt} {--strict} {--json}` (`AtlasAcosLongHorizonGateCommand.php:13-19`).

### Cadeia de chamadas
1. `AtlasAcosLongHorizonGateCommand::handle` (`:23`) → `AtlasAcosLongHorizonGateService::evaluate($options)` (`AtlasAcosLongHorizonGateService.php:26`).
2. Resolve config (`:28`) e floors: `min_days=30`, `min_overall=9.5`, `min_pipeline=9.5`, `max_latest_stale_days=2` (`:43-46`).
3. Seleciona fontes por fixture (`:54-61`):
   - `mature` → scorecard fixo 9.72/9.68 + série sintética 31 dias ancorada em "hoje".
   - `short-window` → 8.05/5.77 + 2 dias.
   - `live` → `liveScorecard()` (`:170` chama `AtlasCognitionScoreCardService::build()`) + `readSeries($seriesPath)` (`:182`) lendo `fable-delta-series.jsonl`.
4. `assess()` (`:82`) calcula: overall, pipeline, `scorecard_hash`, datas ordenadas, `calendarSpanDays`, `seriesDayCount`, `latestSeriesOverall`, `resolvedEvidenceRows`, `futureDatedRows`, `latestStalenessDays`.

### Gates/guards (os 9 blockers — `:114-141`)
```
overall < 9.5                                  → scorecard_overall_below_floor
pipeline < 9.5                                 → pipeline_score_below_floor
hash vazio / sem prefixo sha256:               → scorecard_hash_missing
seriesDayCount < 30                            → series_day_count_below_floor
calendarSpanDays < 30                          → calendar_span_below_floor
latestSeriesOverall < 9.5                      → latest_delta_series_score_below_floor
resolvedEvidenceRows < seriesDayCount          → delta_series_resolved_evidence_source_missing
futureDatedRows > 0                            → delta_series_future_dated_rows   (anti-backfill)
latestDate null OU staleness > 2 dias          → delta_series_window_stale
```
- **`does_not_backfill_time`** mecânico: qualquer linha datada `> hoje` (`todayKey`, `:104-108`) bloqueia. "Hoje" é injetável (`today()`, `:231`) p/ teste determinístico; em produção é o dia UTC real.
- **`resolvedEvidenceRows`** (`:289`) só conta linhas cujo `sources.scorecard_overall` contém `resolved-evidence` — série auto-narrada não passa.
- `certified = (blockers === [])` (`:65`).

### Persistência / efeitos colaterais
- **Não** cunha receipts, **não** edita scorecard, **não** backfilla tempo (doc-classe `:12-16`; `claim_policy` `:384-395`).
- **Único write**: se `--write-receipt`/`--receipt`, o **command** grava o payload em `acos-long-horizon-gate.json` (`AtlasAcosLongHorizonGateCommand.php:31-36`) — overwrite atômico, não append.

### Resultado / saída
- `atlas.cognition.acos_long_horizon_gate.v1` (`:20`): `status` (`acos_long_horizon_ready` | `insufficient_long_horizon_evidence` | `disabled` | `blocked`), `certified`, `completion_claim_allowed`, `assessment{...}`, `blockers[]`, `receipt_hash`.
- `--strict` + não-certified → exit `FAILURE` (`AtlasAcosLongHorizonGateCommand.php:38-40`).

### Modos de falha
- `liveScorecard()` em exceção → `[]` (`:174`) ⇒ overall=0 ⇒ bloqueia (fail-closed, nunca certifica).
- Fixture não-suportada → `blocked/unsupported_fixture` (`:36`). `enabled=false` → `disabled` (`:40`).

Diagrama:
```
delta-series.jsonl (append-only, 1 linha/dia, resolved-evidence)
        │  (≥30 dias, fresco ≤2d, sem futuro)
        ▼
scorecard.build() ── overall≥9.5, pipeline≥9.5, hash sha256 ──┐
        │                                                     ▼
        └──────────────► assess() ── 0 blockers? ── certified=true
                                          │
                                   receipt (overwrite) acos-long-horizon-gate.json
```

---

## Fluxo C — Window Gates D3/D4/D5 (painel honesto de janela)

### Trigger/entrypoint
- CLI: `php artisan atlas:acos:window-gates [--json]` → `AtlasAcosWindowGatesCommand::handle` (`AtlasAcosWindowGatesCommand.php:21`).
- **Sem schedule** — é painel/instrumentação sob demanda (doc-classe `:10-14`).

### Cadeia de chamadas
`Command::handle` → `AtlasAcosWindowGatesService::status()` (`AtlasAcosWindowGatesService.php:45`):
1. `liveDimensions()` (`:63`) → `AtlasMemoryQualityService::scorecard()` (`:66`), extrai `components`:
   - `D3_relation_density` (key `relation_density`, target `>=70`, **assert** → `met`|`aguardando_janela`).
   - `D5_structural_honesty` (`structural_honesty`, `>=70`, assert).
   - `D5_rationale` (`rationale`, `>=70`, assert).
   - `D4_D5_feedback` (`feedback`, target "ver doc (janela)", **NÃO assert** → sempre `reported`, pois a direção do alvo é contestada nos docs, `:82-84`).
   - `dimension()` (`:91`): key ausente → `sem_dados`; assert+numérico → compara threshold; senão → `reported`.
2. `windowReceipts()` (`:113`): varre `storage/app/atlas/evidence/*-gate.json` (`:124`), ordena por nome; para cada, `receiptStatus()` (`:135`) lê JSON, e status = `certified` **se** `certified===true` E fresco (≤ **604800s** = 7 dias, `RECEIPT_FRESH_SECONDS`, `:31`,`:148`); senão `aguardando_janela`.

### Gates/guards (anti-Goodhart por construção)
- **Nunca fabrica número nem veredito** (doc-classe `:22-24`): reporta valor medido + ecoa o `certified` da própria fonte. Receipt que diz `insufficient_*_evidence` aparece como `aguardando_janela` até a janela encher na cadência.
- D4/D5 feedback: alvo ambíguo ⇒ `reported`, jamais um pass adivinhado.

### Persistência / efeitos colaterais
- **Nenhum** — read-only puro.

### Resultado / saída
- `atlas.cognition.window_gates.v1` (`:28`): `live_dimensions[]`, `window_receipts[]`, `note`. Labels honestos: `met` / `aguardando_janela` / `reported` / `sem_dados` / `certified`.

### Modos de falha
- `AtlasMemoryQualityService::scorecard()` lança (tabelas ausentes) → `[{gate:memory_quality, status:sem_dados}]` (`:67-73`).
- Diretório de evidência ausente → `window_receipts=[]` (`:119`). Receipt ilegível/não-objeto → `sem_dados` (`:140-145`).

---

## Fluxo D — Mint Pipeline Receipts (sobe a dimensão pipeline por evidência real)

### Trigger/entrypoint
- **Schedule** (`routes/console.php:165-169`): `atlas:cognition:mint-pipeline-receipts --limit=30 --json`, `dailyAt('06:30')`, `withoutOverlapping()`, `->when(config('atlas.cognition.mint_pipeline_receipts_enabled', true))` (`config/atlas.php:1787`).
- **Ordem crítica** (comentário `routes/console.php:162-164`): 06:30 é **sempre depois** do `index-code` (06:05) — mint concorrente com reindex gravaria receipts vermelhos falsos (filtros resolvem vazio com índice em rebuild).
- CLI: `atlas:cognition:mint-pipeline-receipts {--limit=5} {--json}` (`AtlasCognitionMintPipelineReceiptsCommand.php:22-24`).

### Cadeia de chamadas
`Command::handle` (`:28`, DI: `AtlasAaeosImplementationTruthService`, `AtlasAaeosTestExecutionService`, `AtlasCognitionScoreCardService`, `AtlasCognitionEvidenceResolver`):
1. `limit = clamp(1..73, --limit)` (`:34`). `before` = pipeline score atual do scorecard (`:35-36`).
2. **Passe direcionado** (`:56-106`): para cada subsistema com `pipeline_status===partial`, pega `ownerCapabilityIdsForFqn($fqn)` (`AtlasCognitionEvidenceResolver.php:199`) e monta `candidateRefs = [<Short>Test] + test_refs index_resolved`. Para cada ref ainda não cunhado neste passe: `truth->freshnessHashes` → `execution->runAndRecord(capabilityId, ref, null, test_hash, impl_hash)` (`:81`) — **roda PHPUnit real, FQN-anchored**. `tests_run==0` → pula (símbolo stale). Conta `green` se `passed`. Um receipt por (subsistema, owner) por passe (`:103`).
3. **Sobra de orçamento** (`:110-143`): varre `capabilities` declaradas (comportamento original) só com refs `index_resolved`, 1 teste por capability por passe.
4. `after` = pipeline score recomputado (`scorecard->build()` de novo, `:145`).

### Gates/guards
- **Nunca fabrica receipt** (doc-classe `:18`): verde reprovado = receipt não-verde honesto (`runAndRecord` grava o resultado real do PHPUnit).
- Só cunha refs que **resolvem no índice** (`index_resolved===true`, `:72`,`:116`) — ref que não resolve nunca vira verde.
- Bounded por `--limit` (cada teste custa segundos) — uso incremental durante soak.
- Dedupe por `capabilityId|ref` (`mintedKeys`, `:77`,`:120`) evita re-cunhar em docs multi-subsistema.

### Persistência / efeitos colaterais
- **Escreve green-run receipts reais** via `AtlasAaeosTestExecutionService::runAndRecord` (tabela de receipts AAEOS — o mesmo store que o Evidence Resolver lê em `hasGreenReceipt`). É o **único fluxo ACOS que muta estado de evidência** (e só com PHPUnit real passando).
- Efeito de segunda ordem: cada receipt verde novo flipa um subsistema `partial→ready` no próximo `scorecard.build()`, subindo a dimensão pipeline.

### Resultado / saída
- `atlas.cognition.mint_pipeline_receipts.v1` (`:148`): `capabilities_processed`, `green_receipts_minted`, `pipeline_score_before`, `pipeline_score_after`, `pipeline_lift`, `minted[]`.

### Modos de falha
- Sem partials nem refs resolvíveis → 0 processados, lift 0 (passe inócuo, não erra).
- Índice em rebuild → filtros vazios (mitigado pela ordem 06:05→06:30).

---

## Fluxo E — Feeder append-only da série (base dos gates de janela)

### Trigger/entrypoint
- **Schedule** (`routes/console.php:174-177`): `atlas:fable:delta-series --json`, `dailyAt('05:10')`, `withoutOverlapping()`, `->when(config('atlas.fable.delta_series_enabled', true))` (`config/atlas.php:1781`).
- CLI: `atlas:fable:delta-series {--date=} {--series=} {--baseline=} {--report} {--json}`.

### Cadeia de chamadas
`AtlasFableDeltaSeriesCommand::handle`:
1. `readBaseline` do Marco Zero (`marco-zero-fable-2026-06-11.json`, `:44`,`:98`); ausente → `blocked/no_baseline` (`:49-51`).
2. `resolveDate()` (`:112`) = `--date` válido ou `date('Y-m-d')`.
3. `snapshot($date,$baseline)` (`:128`): `currentState()` resolve `scorecard_overall` de `AtlasCognitionScoreCardService::build()` (fonte `resolved-evidence`, `:151`), + métricas de loop (merges, impact receipts, capture gate, semantic recall).
4. `appendSnapshot($seriesPath,$snapshot)` (`:206`): **idempotente por data** — remove a linha do mesmo `date` e reinsere (re-medição substitui, nunca duplica, `:215-219`), reordena por data (`:221`), reescreve o JSONL inteiro (`:229`).

### Gates/guards
- Idempotência por data (`:200-201`); série ilegível (não vazia) → `null` → `blocked/series_unreadable` (`:60-61`,`:249`).
- Cada snapshot marca `sources.scorecard_overall = '...build() (resolved-evidence)'` (`:151`) — é exatamente a string que o Long-Horizon Gate exige em `resolvedEvidenceRows`.

### Persistência / efeitos colaterais
- **Append-only** em `storage/app/atlas/evidence/fable-delta-series.jsonl` (1 linha JSON por dia). Este arquivo é o **input** dos Fluxos B (Long-Horizon) e do Evolution Score (`gates_auditados`).

### Resultado / saída
- `atlas.fable.delta_series.v1`: `today` (snapshot), `series_length`, `trend` (primeiro-vs-último).

---

## Fluxo F — Predictive Code-Intelligence Gate (L6-11)

### Trigger/entrypoint
- **Schedule** (`routes/console.php:196-200`): `atlas:cognition:predictive-code-intelligence-gate --write-receipt --json`, `dailyAt(config(...schedule_time,'07:25'))`, `withoutOverlapping()`, `->when(enabled && schedule_enabled)` (`config/atlas.php:1811-1812`, ambos default true).
- CLI: `{--fixture=live|mature|zero-outcomes|stale-code} {--domain=learning} {--window=60} {--auto-refresh} {--receipt=} {--write-receipt} {--strict} {--json}` (`AtlasPredictiveCodeIntelligenceGateCommand.php:13-21`).

### Cadeia de chamadas
`Command::handle` (`:25`) → `PredictiveCodeIntelligenceCorrelationGateService::evaluate($options)` (`:34`, DI: `AtlasCodeIntelligenceAutomaticGateService`, `PredictiveFailureCalibrationMetricsService`, `PredictiveFailureRepository`):
1. Fixture `live` (`:67-77`): `codeGate->evaluate(mode=readiness, strict_freshness=true, max_age_minutes=1440)` + `metrics->compute($domain,$windowDays)` (normalizado por `normaliseMetric`, `:219`) + `repository->history($domain,$windowDays)`.
2. `assess()` (`:126`): calcula `outcomes_recorded`, `avg_calibration_error`, `brier_score`, `failureSignatureOutcomeCount` (`:199`), `resolvedOutcomeRows`.

### Gates/guards (blockers — `:144-172`)
```
codeGate.status != 'ready'                         → code_intelligence_gate_not_ready
cada blocker do code gate                          → code_gate:<blocker>
metrics.status != 'computed'                       → predictive_metrics_not_computed
outcomes < min_outcomes(3)                         → predictive_outcomes_below_floor
resolvedRows < outcomes                            → predictive_history_missing_resolved_rows
signatureOutcomes < min(1)                         → failure_signature_outcomes_below_floor
avgError null / > 0.35                             → avg_calibration_error_(missing|above_floor)
brier null / > 0.25                                → brier_score_(missing|above_floor)
```
- Junta o **code-intelligence gate** (freshness do índice ≤1440min) com **outcomes resolvidos** de predictive-failure — só certifica quando os dois concordam.

### Persistência / efeitos colaterais
- **Não** cunha predições, **não** backfilla outcomes, **não** muta código (`claim_policy` `:357-365`).
- Único write: **command** grava receipt `predictive-code-intelligence-gate.json` se `--write-receipt` (`AtlasPredictiveCodeIntelligenceGateCommand.php:34-38`).

### Resultado / saída
- `atlas.cognitive.predictive_code_intelligence_correlation_gate.v1` (`:22`): `status` (`predictive_code_intelligence_correlated` | `insufficient_predictive_failure_correlation` | `disabled` | `blocked`), `certified`, `assessment`, `code_gate{}`, `predictive_metrics{}`, `blockers[]`, `receipt_hash`.
- `--strict` + não-certified → exit FAILURE.

### Modos de falha
- Fixture não-suportada / reports não-array → `blocked/unsupported_fixture` (`:82-86`). `enabled=false` → `disabled`. Code gate blocked (fixture `stale-code`) propaga blockers com prefixo `code_gate:`.

---

## Fluxo G — Órgãos de cadência H2.1 (master switch `atlas.acos.cadence_enabled`)

Bloco "Obra #14 H2.1 — órgãos da inteligência em cadência" (`routes/console.php:513-570`). **Master switch único: `config('atlas.acos.cadence_enabled', true)`** — OFF ⇒ byte-idêntico (nenhum órgão roda). Todos read-only/append-only; **nunca promovem ou aplicam**.

| Órgão | Comando/callback | Cadência | Guard | Efeito |
|---|---|---|---|---|
| H2.1a Refactor Intelligence | `atlas:engineering:refactor-census app/Services/Ai --json` (`:522`) | `weeklyOn(1,'05:30')` | cadence_enabled | aponta refatoração por medição; log `acos-refactor-census.log` |
| H2.1b Obra Lesson Harvester | `Schedule::call` → `AtlasObraLessonHarvester::harvest($doc, dryRun:false)` sobre `docs/obra*.md` (`:532-544`) | `dailyAt('06:40')` | early-return se `!cadence_enabled` (`:533`) | colhe refutações/NÃO-FAZER p/ quarentena G0; dedupe por claim hash; **nunca auto-promove** |
| H2.5b KB re-sync | `atlas:engineering:knowledge sync --prune` (`:553`) | `dailyAt('05:50')` | cadence_enabled | read model KB fresco; log `acos-knowledge-sync.log` |
| H2.5b Code index | `atlas:engineering:knowledge index-code --prune --workspace=<base>` (`:558`) | `dailyAt('06:05')` | cadence_enabled | índice de código fresco (a montante do mint 06:30) |

- **fail-open** no harvester (`:539-542`): um doc malformado não derruba a colheita dos demais.

⚠ **DRIFT / achado**: **`atlas.acos.cadence_enabled` NÃO existe em `config/atlas.php`.** Não há chave `'acos' => [...]` no arquivo de config (confirmado por grep). Todas as 5 leituras usam o default `true` no segundo argumento de `config()`. Consequência real: o master switch **só pode ser desligado** adicionando a chave ao config ou via cache de config com env explícito — hoje ele é efetivamente sempre `true`. O comentário em `routes/console.php:515` afirma "default TRUE; OFF ⇒ byte-idêntico", o que é honesto, mas não há env/config key wired para virar OFF. Sinalizar ao operador se o objetivo era um kill-switch operável.

---

## Fluxo H — Como o ACOS gateia claims de outros subsistemas

O scorecard/gates são **consumidos** como fonte de verdade por outros fluxos (todos read-only sobre `AtlasCognitionScoreCardService::build()`):

1. **Fixed-N Capability-per-Dollar Gate** (`app/Services/Ai/Compounding/FixedNCapabilityDollarSeriesGateService.php:119`,`:460`): lê `score.overall_out_of_10` do scorecard para compor sua série/veredito; `completion_claim_allowed = certified` (`:521`); `provider_benchmark_claim_allowed=false` (`:533`). Schedule próprio em `07:35` (`config/atlas.php:1843`).
2. **Fable Final Report** (`app/Services/Ai/Programming/AtlasFableFinalReportService.php:367-370`): consome `metrics.scorecard_overall` da delta-series (Fluxo E) para baseline/current/delta/trend; `l4_13_completion_claim_allowed` gated por packet verification (`:114`).
3. **Long-Horizon Gate (Fluxo B)** e **Evolution Score (Fluxo A)**: consumidores diretos do `build()`.
4. **Mint (Fluxo D)**: consumidor + único mutador (via receipts reais).
5. Outros importadores do scorecard (grep): `AtlasLoopBacklogAutoFeederService(+Support)`, `AtlasSelfConstructionSubsystemBuilderService`, `AtlasSelfDivergenceModelService`, `AtlasAntifragilityCompositionMetricService`, `AtlasCognitiveFunctionAtlasService`, `CognitiveImmuneCheckContract`, `AreaFocusDeepFindingEngineService`.

**Política de claim pétrea** (`claimPolicy()`, `AtlasCognitionScoreCardService.php:343-354`): `benchmark_claim_allowed`, `rivals_claim_allowed`, `superiority_claim_allowed` sempre `false`; `external_rivals_certification` permanece BLOCKED. Nenhum consumidor pode emitir claim de superioridade externa a partir do ACOS — o score reflete só maturidade interna.

---

## Síntese — invariantes pétreos do ACOS (verificados no código)

1. **Anti-over-claim**: `doc_status`/`pipeline_status` são função de evidência resolvida (FQN-bound doc + receipt verde fresco), nunca literais — remover evidência move o score.
2. **Anti-backfill de tempo**: Long-Horizon Gate rejeita linhas future-dated e janela stale >2d; exige `resolved-evidence` em cada linha.
3. **Append-only**: a série `fable-delta-series.jsonl` é idempotente por data (substitui, nunca duplica).
4. **Degrade-safe / fail-closed**: toda probe que falha pontua 0 / withhold honesto; nenhum caminho fabrica `ready`/`certified`.
5. **Não-mint por gate**: apenas o Fluxo D (mint) escreve evidência, e só via PHPUnit real passando; os gates B/F apenas leem + escrevem seu próprio receipt (overwrite) pelo command.
6. **Claim policy imutável**: benchmark/rivals/superiority sempre bloqueados.

### Achados de drift a reportar
- ⚠ **`atlas.acos.cadence_enabled` sem entry em config** — master switch efetivamente sempre `true` (não desligável sem editar config). Fluxo G.
- ⚠ Contagem canônica é **73** subsistemas (`SUBSYSTEMS`), não "50+"/"52" citados em textos legados.

---

# PARTE II — Atlas Dev · Fluxos detalhados

## Atlas Dev — Todos os Fluxos

> **Regra de ouro aplicada:** cada afirmação abaixo foi verificada lendo o código real e seguindo a cadeia de chamadas. Onde doc canônico e código divergem, o **código prevalece** e o drift é sinalizado com ⚠️. Todas as referências são `arquivo:linha` (caminhos absolutos a partir de `/home/user/atlas-server`).

---

### 0. Mapa geral e boundary Dev ≠ Forge

**Entrypoints reais confirmados** (`routes/api.php`):
- `GET /ai/interactions/atlas-dev/readiness` → `ReadinessController` (linha ~533)
- `POST /ai/interactions/atlas-dev/plan` → `PlanController` (~535)
- `POST /ai/interactions/atlas-dev/run` → `RunController` (~537)
- `GET /ai/interactions/atlas-dev/runs` → `AtlasDevIndexController` (`IndexController`) (~539)
- `POST /ai/interactions/atlas-dev/runs/{runId}/cancel` → `CancelController` (~541)
- `GET /ai/interactions/atlas-dev/runs/{runId}/stream` → `StreamController` (~544)
- `GET /ai/interactions/atlas-dev/runs/{runId}` → `ShowController` (~547)
- `GET /dev-to-forge/threads/{thread}/promotion-preview` + `POST .../promote` + `GET /dev-to-forge/candidates[...]` + `POST .../dismiss` → `AtlasCodeDevToForgePromotionController` (montados sob `/atlas-code/...`, ~744-750)

**Comandos CLI** (`app/Console/Commands/`): `AtlasCliDevCommand`, `AtlasCliDevPlanCommand`, `AtlasDevReadinessCommand`, `AtlasDevRunCertifyCommand`, `AtlasDevRunSnapshotCommand`, `AtlasDevPolicyCommand`, `AtlasDevRunWorkerCommand`, `AtlasDevSeniorLoopRunCommand`, `AtlasDevSeniorLoopAuditCommand`, `AtlasDevSmokeCommand`, além de uma família grande de comandos de evidência/certificação (`AtlasDevDesktop*`, `AtlasDevEfficientProgrammingFlow*`, `AtlasDevFlowMap*`, etc.).

**Orquestração central:** `AtlasDevFastPathOrchestrator` (plan-only, determinístico, PROIBIDO chamar provider) → o Run é executado por `PipelineRunExecutor` (implementação do contrato `RunExecutor`).

#### Boundary Dev ≠ Forge — ⚠️ verdade nuançada (não é "Forge nunca importa AtlasDev")

`grep` prova que **Forge NÃO importa** o orquestrador, pipeline, gates ou runtime do Dev, mas **importa três coisas do namespace `AtlasDev`**, todas na fronteira de handoff:

1. **Schema `EscalationPacket`** (o DTO de handoff Dev→Forge). É o contrato que atravessa a fronteira:
   - `app/Services/Ai/Programming/Forge/ForgeIntakeService.php:9`
   - `app/Services/Ai/Programming/Forge/ForgeWorkPacketComposer.php:8`
   - `app/Services/Ai/Programming/Forge/ForgeIntakeCanon.php:5`
2. **`IntentActionExtractor`** (utilitário puro do Dev, reusado): `app/Services/Ai/Programming/Forge/Qa/ForgeObraCertificationService.php:15`
3. **`AtlasDevGateAdapter`** (adapter de nível **EngineeringKernel**, não Forge): `app/Services/Ai/Programming/Forge/ForgeWorkPacketExecutionCycleService.php:10`. Vive em `app/Services/Ai/EngineeringKernel/Adapters/AtlasDevGateAdapter.php` — é um "strangler adapter" que mapeia saídas reais do Dev (`MutationScoreVerdict`, scan de segurança) para um `AcceptanceBundle` e delega a decisão ao `SovereignHonestyFloor`.

**Conclusão:** o boundary é assimétrico e limpo no que importa — Forge consome o **contrato de escalação (EscalationPacket)** e alguns utilitários/adapters puros, mas **nunca** o motor de execução do Dev (`PipelineRunExecutor`, `AtlasDevFastPathOrchestrator`, gates de execução). Dizer "Forge não importa AtlasDev" é impreciso; o correto é "Forge não importa o **runtime/orquestração** do Dev — só o schema de handoff e utilitários".

---

### 1. Fluxo READINESS

**Trigger:** `GET /ai/interactions/atlas-dev/readiness?strict=<bool>` → `ReadinessController::__invoke` (`app/Http/Controllers/AtlasDev/ReadinessController.php:16`). CLI equivalente: `atlas:dev:readiness` (`AtlasDevReadinessCommand`).

**Cadeia:**
`ReadinessController:21` → `AtlasDevReadinessService::inspect(strict, providerSafe: true)` (`app/Services/Ai/Programming/AtlasDev/Runtime/AtlasDevReadinessService.php:47`).

**Checks executados** (`AtlasDevReadinessService.php:49-59`), cada um retorna `passed|warning|failed`:
1. `config.plan_enabled` (`atlas_dev.efficient.plan_enabled`) — `:50,:84`
2. `config.run_enabled` (`atlas_dev.efficient.run_enabled`) — `:51`
3. `config.desktop_enabled` — `:52`
4. `config.run_dispatch_mode` — valida `process|inline|after_response`, `process` = passed, resto warning (`:99-122`)
5. `security.app_key` — HMAC do confirmation_token exige ≥32 bytes de material (`:127-144`)
6. `storage.receipts_path` — cria e testa escrita em `atlas_dev.receipts_path` (`:149-168`)
7. `http.routes` — confirma que as 7 rotas nomeadas do Dev estão registradas (`REQUIRED_HTTP_ROUTES` `:19-27`, check `:173-185`)
8. `worker.runtime` — em modo `process`, exige `proc_open` + `artisan` (`:190-223`)
9. `provider.runtime` — o mais rígido (`:228-286`): exige binding **de produção** `SymfonyClaudeCliGateway` para `ClaudeCliGateway`; binding `SymfonyProcessCommandRunner`; binário `claude`/`claude-code`/`claude.exe`; **tools read-only** (aceita SOMENTE `--allowedTools Read`, `:291-305`); binário resolvível no PATH; e `--version` retornando exit 0.

**Resultado** (`:67-78`): `status = passed` se nenhum check `failed` (em `strict`, warnings também bloqueiam); `schema_version=atlas.dev.readiness.v1` + `summary{passed,warnings,failed}`. `providerSafe=true` redige paths (usa `basename`/labels).

**Fail-closed:** provider.runtime falha se a tool não for exatamente `Read` (garante que o gateway de readiness nunca escreve no workspace); paths redigidos por `AtlasSecurity::redactString` + regex de path.

---

### 2. Fluxo PLAN (mini-spec, determinístico, custo-zero de token)

**Trigger:** `POST /ai/interactions/atlas-dev/plan` → `PlanController::__invoke(PlanRequest)` (`app/Http/Controllers/AtlasDev/PlanController.php:49`). CLI: `atlas:cli:dev:plan` (`AtlasCliDevPlanCommand`).

**Invariantes declarados no header** (`PlanController.php:24-37`): nunca chama provider; nunca aplica patch; custo de token = 0; persiste todos os artefatos via ReceiptStorage; devolve `task_contract_hash`; emite `confirmation_token` de uso único **apenas** se routing = `atlas_dev_fast_path`.

**Cadeia passo-a-passo:**
1. Guardas de flag: `plan_enabled` off → 503 `ATLAS_DEV_PLAN_DISABLED` (`:51`); surface `atlas_desktop_ai` com `desktop_enabled` off → 503 (`:61`).
2. `resolveWorkspaceForSurface` (`:164-186`): resolve path direto (`is_dir`), ou slug via `AtlasCodeWorkspaceProfileService::findBySlug` (`:171`); Desktop sem path acessível → `RuntimeException`.
3. **`AtlasDevFastPathOrchestrator::planOnly(surfaceId, workspace, rawIntent, userConstraints, surfaceHints)`** (`:76` → orquestrador `app/Services/Ai/Programming/AtlasDev/Pipeline/AtlasDevFastPathOrchestrator.php:87`).
4. `runIndex->upsertFromPlan(...)` grava a linha do run no índice (`:93`).
5. Se `routing->kind === ATLAS_DEV_FAST_PATH` (`:104`): `ConfirmationTokenService::issue(runId, taskContractHash, surfaceId, compactSddHash)` (`:106-114`) — token DB+HMAC, plaintext mostrado UMA vez, TTL de `atlas_dev.confirmation_token.ttl_seconds` (default 300s). Chave ausente → 500 `ATLAS_DEV_KEY_MISSING` (fail-closed F-09, `:115-124`).
6. Resposta (`:133-161`): `compute_effort_contract`, `thread_id`, `confirmation{token,expires_at,task_contract_hash}`, `routing{kind,reasons,blockers,is_executable}`, `senior_engineer_loop` (audit provider-safe). `HttpResponseRedactor::redactWorkspaceIn` remove prefixos absolutos (F-04, `:159`).

**O pipeline determinístico `planOnly` (`AtlasDevFastPathOrchestrator.php:87-297`):**
1. `IntakeNormalizer::normalize` → `OperationEnvelope` (`:94`)
2. `TaskClassifier::classify` → `TaskClassification` (task_kind, intent_clarity, write_implied) (`:95`)
3. `RiskLevelScorer::score` **preliminar** (sem discovery) (`:97`)
4. `SpecComposer::composeCompactSdd` preliminar (`:98`)
5. `DocContextTierSelector::select` → `ContextRetrievalPlan` (`:100`)
6. `CodeDiscoveryEngine::discover` → `CodeDiscoveryManifest` (`:101`)
7. `RiskLevelScorer::score` **final** (com discovery — só sobe risco, nunca baixa) (`:104`); recompõe CompactSdd/contextPlan se risco mudou (`:105-112`)
8. `OpenBrainProjectionAdapter::projectFor` (`:114`)
9. `SpecComposer::composeMiniSpec` + `composeTaskContract` (`:116-117`)
10. **`RoutingDecisionEngine::decide`** decide ANTES da projeção do prompt (`:124`)
11. Stage pipeline ordenado `runStages` (`:158,:308-362`), cada stage `array→array`, com timing por stage persistido em `fast_path_stage_timings.json`; **fail-open** (exceção de stage é registrada, nunca mata o plano, `:333-340`):
    - `discovery_enrichment` — enriquece manifest com `likely_callers` via `SymbolLookup` (`:370`)
    - `aemor_outcome_bridge` — dobra fatos de outcomes AEMOR recentes (`:404`)
    - `context_budget_distillation` — `DevContextBudgetDistiller` corta ruído ao budget do CompactSdd (`:438`)
    - `exemplar_retrieval` — `DevGreenRunExemplarRetriever` busca exemplos green-run provados (`:467`)
    - **`verification_receipts`** (`:488`): **Mandatory RAG Gate** (fail-closed) + **Spec Adversary** (fail-closed estrutural) — ver §3
    - `workcell_decomposition` — `DevWorkcellDecomposer` + instruções por workcell + `workspace_origin.json` (`:585`)
12. `promptIsSendable = routing->kind === ATLAS_DEV_FAST_PATH` (`:169`) — projeção não-sendable para qualquer rota que não seja fast path
13. `DevFailureCapsulePromptInjector::injectFor` injeta modos de falha conhecidos da área (M5, workspace+area-scoped, `:193`)
14. `ProviderPromptBuilder::build(..., providerSafe: $promptIsSendable, ...)` (`:196`)
15. `persistArtifacts` grava todos os JSON (`:208,:795-850`) + audit do SeniorLoop (`SeniorEngineerLoopAuditor::audit`, `:251`) + MandatoryRagGate receipt (`:258`) + SpecialistFlowRouter decision (`:272`)

**Persistência (Plan):** um JSON por artefato em `storage/atlas-dev/receipts/<run_id>/` (config `atlas_dev.receipts_path`). Nomes canônicos em `ArtifactNames`: `operation_envelope.json`, `compact_sdd.json`, `context_retrieval_plan.json`, `code_discovery_manifest.json`, `open_brain_projection.json`, `mini_programming_spec.json`, `task_contract.json`, `prompt_projection.json`, `routing_decision.json`, `senior_engineer_loop_audit.json`, `mandatory_rag_gate.json`, `specialist_flow_decision.json`, `workcell_decomposition.json`, `workcell_instructions.json`, `workspace_origin.json`, `fast_path_stage_timings.json`.

**Rotas de decisão (`RoutingDecision::kind`):** `atlas_dev_fast_path` (executável), `forge_promotion_preview`, `delegate_to_other_flow`, `read_only_answer`, `blocked`.

---

### 3. Fluxo RUN (fast-path: intake → plan → execução provider → gates → completion)

**Trigger:** `POST /ai/interactions/atlas-dev/run` → `RunController::__invoke(RunRequest)` (`app/Http/Controllers/AtlasDev/RunController.php:59`).

**Guardas de entrada (ordem exata):**
1. `run_enabled` off → 503 `ATLAS_DEV_RUN_DISABLED` (`:61`)
2. `operator_confirmed !== true` (literal boolean) → 400 `OPERATOR_NOT_CONFIRMED` (`:70`) — **gate de consentimento do operador**
3. Lê 3 artefatos persistidos do Plan (`operation_envelope`, `task_contract`, `prompt_projection`); ausência → 404 `PLAN_NOT_FOUND` (`:83-94`)
4. Desktop surface com flag off → 503 (`:97`)
5. `task_contract_hash` deve bater (`hash_equals`) com o persistido → 422 `TASK_CONTRACT_HASH_MISMATCH` (`:106-114`)
6. **AWIS execution gate** (`AtlasWorkspaceIntelligenceExecutionGateService::gate(workspace, mode:'dev', task)`): se não `allowed` → 422 `ATLAS_DEV_AWIS_EXECUTION_BLOCKED` (`:116-129`) — exige workspace AWIS pronto antes de gastar provider
7. **`ConfirmationTokenService::validateAndConsume(runId, hash, token)`** (`:131`): valida + consome (uso único). Falha → `tokenFailureResponse` (`:467-486`): 403 `CONFIRMATION_TOKEN_INVALID/EXPIRED/ALREADY_CONSUMED/CONTRACT_MISMATCH`, ou 500 se `REASON_KEY_MISSING`. Retorna `expectedCompactSddHash` (pin F-03).

**Modos de dispatch (`atlas_dev.efficient.run_dispatch_mode`, default `process`):**
- **`inline`** (`:150-187`): executa `executeProviderRun` na hora, devolve 200 com o resultado completo. Usado por testes/smoke.
- **`process`** (default, `:195-230`): grava state `queued`, chama `RunWorkerDispatcher::dispatch(runId, hash, expectedCompactSddHash)` → **`ProcOpenRunWorkerDispatcher`** (`app/Services/Ai/Programming/AtlasDev/Runtime/ProcOpenRunWorkerDispatcher.php:14`) faz `proc_open` de `php artisan atlas:dev:run-worker <runId> --task-contract-hash=... --expected-compact-sdd-hash=...` (`:19-39`), log em `run_worker.log`, devolve PID. Resposta **202** imediata `{state: queued, worker_pid}`. Falha de dispatch → state `failed` + 500 `ATLAS_DEV_RUN_DISPATCH_FAILED`.
- **`after_response`** (fallback, `:232-278`): registra `app()->terminating(...)` para executar após a resposta HTTP; devolve 202.

**Worker isolado — `AtlasDevRunWorkerCommand` (`app/Console/Commands/AtlasDevRunWorkerCommand.php:41`):**
- `isolateProcessGroup` via `posix_setsid` (`:203`); `installTerminationHandler` capta SIGTERM → grava state `cancelled` e sai (`:222-250`)
- Checa cancelamento antes de começar e antes do provider (`:51,:71`) — cancelamento é artefato persistido (`run_cancellation.json`), não flag transiente
- Re-roda o AWIS gate (`:94`); bloqueado → state `blocked` (`:100-111`)
- Chama `RunExecutor::execute(...)` (`:113`) → persiste SeniorLoop execution + atualiza índice + grava state `complete`
- `CompactSddUnavailableException` → state `failed` (fail-closed F-03); qualquer `Throwable` → state `failed` com mensagem redigida

**Execução core — `PipelineRunExecutor::execute` (`app/Http/Controllers/AtlasDev/Support/PipelineRunExecutor.php:106`):**
1. **F-03 fail-closed**: `resolveTaskKindAndRiskLevel(runId, expectedCompactSddHash)` (`:118`) — deriva task_kind/risk do `compact_sdd.json` persistido; se ausente/inválido/hash divergente do pin do token → `CompactSddUnavailableException` → 422, **provider NÃO é invocado** (não desperdiça call num run que não pode ser atestado honestamente).
2. **AUCRI enforcement antes do provider** (`enforceAucriBeforeProvider`, `:120,:3560`): monta segmentos (decision/constraint/evidence) e chama `AtlasAucriRuntimeEnforcementService::enforce`; status != `passed` → `blockedDueToAucri` (completion `blocked`, `:3630`). Persiste `aucri_runtime_enforcement.json`.
3. Resolve `VerificationCommandRunner`; **ausente → `blockedDueToUnwiredDrivers`** (completion `blocked`, scope/verification `skipped`, `:137,:3519`) — fail-closed: surface trata run como "ready-but-not-runnable".
4. Fast-path determinístico opcional: se `atlas_dev.efficient.deterministic_fast_path_enabled` (default true), `tryDeterministicPatch` para fixtures pequenas (`:133,:3178`).
5. **E5 baseline pré-patch** capturado UMA vez na árvore limpa (antes do provider/repair), reusado byte-idêntico entre iterações (`:206-218`).
6. **Best-of-N** (M4, só hermes_cli, `atlas_dev.best_of_n.candidate_count`, default 1): N candidatos, cada um pelo floor+gate completo, vence o primeiro PASSING em ordem determinística (`:252-284`).
7. **Loop de execução + repair-to-green (M2)** (`:286-509`):
   - `executeLockedProvider` despacha pelo `provider_lock.provider` (`:1274-1311`): `claude_cli`→SonnetClaudeCliAdapter, `codex`→AtlasForgeCodexCliInvocationDriver, `cursor`→Cursor, `minimax_m27_cli`→Minimax, `hermes_cli`→executeHermesProvider; provider desconhecido → blocked `unsupported_provider_lock`. Projeção não-sendable → blocked `prompt_projection_not_sendable` (`:1280`).
   - `DiffParser::parse` → `ScopeGuard::check` (§4) → `applyPatchIfSafe` → `VerificationGate::run` (§4)
   - `repairCap = max(0, min(3, maxAttempts))` (`:153`); repair dispara em gate RED **ou** weak-green (placeholder TODO/FIXME em diff verde) **ou** `no_patch` em task de escrita (`:367-398`)
   - Anti-spin: `FailureSignatureHasher` — mesma assinatura 2× → abort `same_signature_twice` (`:430-441`); cap esgotado → `validation_failed_after_max_repairs` (`:443`)
   - Reverte workspace entre iterações (`revertWorkspaceChanges`, `:504,:2841`)
8. **Bloco pós-gate — probes de elevação E1-E6** (rodam para TODO provider, cobrem o vencedor best-of-N):
   - **E2 IntentCoverage** (`:570-578`): intent de escrita sem AC comportamental com verification_ref → flag `intent_not_tested`
   - **E1 IntentFalsification** (`:629-640`): nenhuma linha ADICIONADA implementa os `intentVerbs` → flag `intent_likely_not_addressed`
   - **W1 WeakOutput** no diff final aplicado (`:659-701`): placeholder → flag `weak_output_detected` + persiste failure capsule (`failure_class=weak_output`) para injeção futura
   - **E3 MutationScore** (`:730-744`): `MutationTestingAdapter` roda infection **escopado** (só testes tocados + fonte coberta), MSI real vs threshold → flag `mutation_score_below_threshold`
   - **E5 RegressionBaseline** (`:772-786`): diff pós-patch vs baseline it-0; teste passava→falha = regressão → flag `regression_detected`
   - **E4 ShadowDiff** (`:851+`): funções puras old vs new nos mesmos inputs; divergência → flag `shadow_diff_regression`. **Isenção repair-witness**: run convergido via repair que viu gate RED não é re-flagado (`:851-853`)
   - **E6 SpecConstitution** (`:914-933`): checa diff contra a "constituição" da MiniProgrammingSpec (forbidden/non-goals/acceptance); violação/inevaluável → flag. Semântico, distinto do ScopeGuard mecânico.
   - **Exceções de testemunha** (doutrina anti-falso-positivo do teste de fogo 03/07): `transformationWitnessed` (refactor/simplificação com gate verde) isenta E1/E2; `redToGreenWitnessed` (task_kind=repair com baseline pré-patch vermelho→verde) isenta E1/E4.
9. **Critic sênior M3** (`:963-996`): SÓ quando `aggregateStatus === STATUS_PASSED`, invoca `ReviewIntelligenceService::analyse`. Blocker/critical → bloqueia mesmo com testes verdes; exceção do critic degrada para non-passed (nunca engolida para verde).
10. **`CompletionStateGate::decide`** (`:998`, §4) → `CompletionDecision`
11. **`ReceiptComposer::compose`** → `verification_receipt.json` (`:1026`)
12. Persiste `provider_call_result.json`, `diff_parse_result.json`, `patch_apply_result.json`, `scope_guard_receipt.json`, `verification_receipt.json`, `patch_intelligence_receipt.json`, `test_selection_receipt.json`.

**RunController pós-execução (inline/after):** `persistSeniorLoopExecution` (`RunController.php:428`), `runIndex->updateCompletion`, `recordCompoundingLearningSignal` (bridge para `AtlasCompoundingRuntimeService` — só se as 5 tabelas de compounding existem, `:416-423`), redação F-04 dos paths de receipt.

---

### 4. GATES / GUARDS (lógica real, hard vs advisory)

#### 4.1 ScopeGuard (mecânico, puro — sem I/O) — `Gate/ScopeGuard.php:39`
Compara diff observado vs `LightTaskContract` (allowed/watched/forbidden/max_files) e emite `ScopeGuardReceipt`. Regras (`:65-142`):
- `forbidden_files` casado → `KIND_FORBIDDEN_TOUCH` (**failed**, mais forte, mesmo se também allowed)
- `watched_files` → `KIND_WATCHED_TOUCH` (**needs_review**)
- fora de allowed/watched/forbidden → `KIND_UNEXPECTED_TOUCH` (**needs_review**)
- `count > max_files_changed` → `KIND_EXCEEDED_MAX_FILES` (**failed**)
- mudança pré-existente do usuário não preservada → `KIND_PRE_EXISTING_CHANGE` (**failed**)
- `classifyStatus` (`:222`): failing kinds ou unpreserved → `STATUS_FAILED`; senão com violações → `STATUS_NEEDS_REVIEW`; vazio → `STATUS_PASSED`.

`applyPatchIfSafe` (`:3374`): sem patch → skipped; provider já mutou workspace (`WorkspaceMutatingProviders::includes`) → skipped `provider_mutated_workspace`; **scope != PASSED → FAILED `scope_guard_not_passed`** (patch nunca aplicado sobre scope reprovado); senão `PatchApplier::apply`.

#### 4.2 VerificationGate — `Gate/VerificationGate.php:70`
Roda `validation_commands` + **floor mandatório** computado do diff (`computeFloorCommands`, `:311`): testes impactados via `ProgrammingTestImpactAnalyzer` (+ E5 caller-tests do codeGraph), `php -l` por .php tocado, `pint --test` **só nos arquivos do diff** (não repo inteiro — evita quebrar por débito de estilo herdado, `:356-368`). Agregação (`:255-265`): qualquer failed/rejected → `STATUS_FAILED`; zero passed → `STATUS_NEEDS_REVIEW`; senão `STATUS_PASSED`. Sem comandos + `no_test_reason` → passed (profile generic_no_test); sem comandos e sem reason → **needs_review** flag `test_skipped_no_reason` (`:207-253`). Emite ao kernel evidence ledger via `DevGateLedgerEmitter::emit` (funil único, `:181-202`).

#### 4.3 CompletionStateGate / CompletionDecision — a regra que PROÍBE `passed` com flag de dúvida
- **`CompletionDecision.__construct`** (`Gate/CompletionDecision.php:21`): **invariante mecânica** — se `status === passed` e `honestyFlags !== []` → `InvalidArgumentException` ("status=passed forbids honesty_flags", `:33`). É a garantia estrutural de que **não existe verde silencioso com dúvida**.
- **`CompletionStateGate::decide`** (`Gate/CompletionStateGate.php:42`), ordem de precedência (`:55-243`):
  1. diff `blocked` → `STATUS_BLOCKED`
  2. diff `no_patch_needed` → `STATUS_NO_PATCH_NEEDED`
  3. provider not-ok → `STATUS_FAILED`
  4. diff inválido → `STATUS_FAILED`
  5. scope `STATUS_FAILED` → `STATUS_FAILED`
  6. verification `STATUS_FAILED` → `STATUS_FAILED` (flags de elevação hard nomeiam a condição exata nos reasons, `:130-132`)
  7. scope/verification `needs_review` → `STATUS_NEEDS_REVIEW`
  8. **`honestyFlags !== []` → downgrade forçado `passed→needs_review`** (`:164-174`) `passed_downgraded_due_to_honesty_flags`
  9. critic exception → `needs_review` + flag `critic_failed`
  10. critic `STATUS_ESCALATE` → `STATUS_BLOCKED`; `BLOCKED_INSUFFICIENT_CONTEXT`/`REVIEWED` → `needs_review`
  11. tudo verde + zero flags + critic no-concerns → **`STATUS_PASSED`**

#### 4.4 Elevações E1-E6 — hard vs advisory (`Support/Elevations/ElevationConfig.php`)
Tri-state por elevação (`config/atlas_dev.php` `elevations.eN.mode`): **`off`** (no-op byte-idêntico), **`advisory`** (só honesty flag → CompletionStateGate downgrada passed→needs_review), **`hard`** (bloqueia via canal sancionado: `VerificationGateResult::STATUS_FAILED` no gate ou `ReviewReceipt::STATUS_ESCALATE` no critic). Default seguro = **advisory** (`ElevationMode::SAFE_DEFAULT`); valor ausente/inválido resolve para advisory sem crashar (`ElevationConfig::for:71-80`). **Só existem 2 canais** — não há terceiro nem verde silencioso; o invariante do `CompletionDecision` ctor é a garantia mecânica.
- E1 Intent-falsification probe + critic detector (⚠️ já promovido a **hard** por default no config, `m2-e1-hard`)
- E2 Intent coverage (behavioral-AC backing)
- E3 Mutation score gate (MSI real vs threshold)
- E4 Shadow-diff de funções puras
- E5 Pre-patch regression baseline
- E6 Spec-driven constitution gate (semântico)
- W1 Weak-output detector (canal igual, não numerado)

#### 4.5 MandatoryRagGate (fail-closed) — `Gate/MandatoryRagGate.php:59`
Roda no stage `verification_receipts` do plan (`Orchestrator:504`). `classifyTask` (`:174`): write kinds (`patch/repair/frontend/risky` ou `write_implied`) → non-trivial; risco R2-R5 → non-trivial; question/review read-only low-risk → trivial; **default fail-closed → non-trivial** (`:205`). Para non-trivial, bloqueia (`STATUS_BLOCKED` + blocker) se: `selectedTiers` vazio, `planHash` vazio, `compactSddHash` vazio, ou fontes requeridas faltando (`:110-160`). **Bypass** só com constraint auditável explícito (`mandatory_rag_gate:bypass` + `bypass_reason=`) **E** flag `bypass_enabled` **E** surface permitido (`tryBypass:211-261`); todo bypass grava audit trail. Delegação não se aplica (passed `delegation_no_gate`). Quando bloqueia, o orquestrador reescreve `RoutingDecision` para `BLOCKED` (`Orchestrator:507-520`).

#### 4.6 Spec Adversary (Obra #2, fail-closed estrutural) — `Orchestrator:533-574`
Só quando routing ainda é fast-path. `SpecAdversary::contest(SpecDraft, IntentEnvelope, TrustLevel::Dev)`. Gaps estruturais (verbo de escrita reconhecido com ZERO acceptance criteria, ou spec não-testemunhada) → `BLOCKED`. **Deferidos** (não bloqueiam aqui): `oracle_adequacy` (nenhum teste escrito no plan-time — descarregado no certify via mutation_kill_ratio) e `ambiguity_resolved` (vai pra fila de clarificação).

#### 4.7 RoutingDecisionEngine — `Pipeline/RoutingDecisionEngine.php:31`
Função pura sobre sinais observáveis (nunca pergunta a modelo). Precedência:
- delegação out-of-scope (research/conversation/debug/explain/forge) → `DELEGATE_TO_OTHER_FLOW` (`:43`)
- workspace não resolvido / intent clarity blocking / discovery blocking ambiguity (só bloqueia ESCRITA — read-only o provider explora sozinho, `:54-76`)
- **R4/R5 → `FORGE_PROMOTION_PREVIEW`** (nunca patcha no fast path, `:80-98`), EXCETO surface `atlas_forge_rivals` com constraint `rivals_runtime_execution=true` → fast_path (`:81`)
- question/review → `READ_ONLY_ANSWER` (ou `BLOCKED` se há blockers)
- write com blockers → `BLOCKED`
- clarity low + write_implied → `READ_ONLY_ANSWER`
- discovery hypothesis + write (sem allowed_files explícito) → `READ_ONLY_ANSWER`
- default: write + discovery forte + intent claro → **`ATLAS_DEV_FAST_PATH`**

**RiskLevelScorer (R0-R5)** — `Pipeline/RiskLevelScorer.php:70`: risky+multiagent tokens → R5; risky → R4; question/review → R0; frontend → R3; typo/docs → R1; patch/repair → R2. `raiseWithDiscovery` (`:112`) sobe: ≥6 arquivos ou ≥3 layers (db/api/ui/service) → R4; ≥3 arquivos → R3 (nunca baixa). `allowed_files=` explícito é autoritativo sobre a largura da discovery (`:198-236`).

---

### 5. STREAM, CANCEL, SHOW, INDEX

**StreamController** (`app/Http/Controllers/AtlasDev/StreamController.php:64`): ⚠️ **NÃO é SSE ao vivo** — é *snapshot-replay-then-close* (F-01: sob PHP-FPM cada stream prende um worker do pool). Lê o diretório de receipts na conexão, emite um evento `phase` por artefato existente (`PHASE_LOOKUP:40-56`), um `receipt` com o verification receipt (redigido F-04 via `HttpResponseRedactor`), `senior_loop_execution`, e `stream_closed` com `fallback: poll_rest_show_endpoint`. Config `atlas_dev.stream.*` está reservada para runtime async futuro (Octane/Reverb).

**CancelController** (`CancelController.php:31`): grava `run_cancellation.json` idempotente (`:56-70`); `terminateWorker` manda SIGTERM best-effort ao PID/process-group via `posix_kill` (`:113-148`); grava state monotônico `cancelled`; atualiza índice. Cancelamento é **artefato persistido** que o worker checa antes de gastar tokens.

**ShowController / IndexController**: REST fallback (Show é o que Desktop/CLI pollam) e listagem de runs (`AtlasDevRunIndexRepository`, limites em `atlas_dev.run_index.*`).

---

### 6. Persistência / efeitos colaterais (resumo)

- **Receipts JSON**: `storage/atlas-dev/receipts/<run_id>/*.json` (config `atlas_dev.receipts_path`), via `ReceiptStorage::writeAtomic` / `writeMonotonic` (state versionado). Nomes em `ArtifactNames`.
- **Run index**: `AtlasDevRunIndexRepository` (`upsertFromPlan`, `updateCompletion`).
- **Compounding learning**: `AtlasCompoundingRuntimeService::recordExecution` (só se 5 tabelas presentes: `ai_run_outcomes`, `ai_learning_candidates`, `ai_compounding_memories`, `ai_rag_feedback_events`, `ai_temporal_certifications`).
- **Kernel evidence ledger**: `DevGateLedgerEmitter::emit` a cada VerificationGate.
- **Failure capsules**: `DevFailureCapsuleRuntimeService` + `DevTaskPacketRuntimeService` (weak_output → memória para injeção futura).
- **Escalação Dev→Forge**: grava `route_decision.v1` + `escalation_packet.v1` + tabela `AiDualCoreRouteDecision` (ver §8).

---

### 7. Modos de falha / fail-closed (consolidado)

| Situação | Comportamento |
|---|---|
| CompactSDD ausente/adulterado (hash != pin do token) | `CompactSddUnavailableException` → 422, **provider não chamado** (F-03) |
| Gateway/CommandRunner não bound | `blockedDueToUnwiredDrivers` → completion `blocked` (não crasha) |
| AUCRI enforcement não-passed | `blockedDueToAucri` → completion `blocked` |
| AWIS workspace não pronto | 422 `ATLAS_DEV_AWIS_EXECUTION_BLOCKED` |
| APP_KEY ausente (HMAC) | 500 `ATLAS_DEV_KEY_MISSING` (F-09) |
| token inválido/expirado/consumido | 403 (mapeado por `errorCodeFor`) |
| scope != passed | patch nunca aplicado (`scope_guard_not_passed`) |
| MandatoryRagGate non-trivial sem contexto | routing → `BLOCKED` (fail-closed) |
| provider driver lança exceção | `blockedProviderCallResult`, loop bounded pelo cap (VAL-M1-017) |
| `passed` com honesty flag | **impossível por construção** (`CompletionDecision` ctor lança) |
| stage do plan pipeline lança | fail-open (registrado, plano continua) — plan é advisory-additivo |

---

### 8. Fluxo de ESCALAÇÃO Dev→Forge (cadeia completa)

⚠️ **Descoberta importante (código vs. narrativa):** existem **DUAS cadeias de escalação paralelas** com **DOIS scorers distintos** que convergem no mesmo registro de candidatos e na mesma fila de Atenção:

- **Cadeia A — Run/Senior-Loop (determinística, tempo real):** `EscalationSignalScorer` (0..10) → `EscalationDecisionEngine` → `EscalationDecision` (alvos `forge`/`obra_candidate`) → `SeniorEngineerLoopExecutor` → ponte `candidateFromRunEscalation` → `DevToForgePromotionService`.
- **Cadeia B — Thread/Conversa (heurística, operador):** HTTP controller → `DevToForgePromotionService::previewForThread` → `PromotionSignalDetector::analyse` (score heurístico) → tiers → `promote()`.

Os **3 tiers** (`quick_intervention`/`obra_candidate`/`forge_obra`) vivem SÓ na Cadeia B (`PromotionSignalDetector.php:39-43`). Os **2 alvos** (`forge`/`obra_candidate`) vivem SÓ na Cadeia A (`EscalationDecision`). O `ForgePromotionPreviewBuilder` mapeia `forge→forge_obra` (`ForgePromotionPreviewBuilder.php:73-77`).

#### 8.1 Cadeia A — signal scorer → decision engine → packet → promotion

`EscalationSignalScorer::score` (`Escalation/EscalationSignalScorer.php:74-131`) — aditivo, clamp `[0,10]`, determinístico. Pesos reais:
- `risk_r4_or_r5` (R4/R5) **+5** (`:78`); `file_count>5` +2 (`:82`); `file_count>3` +1 (`:84`); `layers>=3` +1 (`:88`); `same_signature_twice` +2 (`:92`); `diff_growth` +1; `test_coverage_gap` +1; `prior_failure_in_area` +1; `risk_keywords` +1/kw cap +2 (`:105`); `context>=40k` +1; `thread>=24 msgs` +1; `prior_failure_count>=2` +1.

`EscalationDecisionEngine::decide` (`Escalation/EscalationDecisionEngine.php:34-72`), thresholds `THRESHOLD_FORGE=7`, `THRESHOLD_OBRA=4` (`:26-28`):
- `forceForge = riskIndex >= 4` (**R4/R5**) (`:45`)
- `score < 4 && !forceForge` → **null** (não escala — default seguro, `:48`)
- `score >= 7 || forceForge` → **`TARGET_FORGE`** (+`humanActionRequired=true`, `:52,:57`)
- `4 <= score < 7` → **`TARGET_OBRA_CANDIDATE`** (`:54`)

`EscalationDecision::issue` valida invariantes que LANÇAM se inconsistente (`Escalation/EscalationDecision.php:90-109`): forge exige `score>=7 OR riskIndex>=4`; obra exige `score>=4`; forge exige `human_action_required=true`. Fail-closed por construção.

⚠️ **Risco é R0..R5 (seis níveis), NÃO R1..R6** — `EscalationSignalsInput::ALLOWED_RISK_LEVELS` (`EscalationSignalsInput.php:20`). Gatilho forge = `riskIndex>=4` = R4/R5. **Não existe R6.**

**Ponte para o candidato:** `SeniorEngineerLoopExecutor.php:174-180` chama `DevToForgePromotionService::candidateFromRunEscalation(...)` que FIXA `target=obra_candidate` (`DevToForgePromotionService.php:536`) — garante que **nenhum run auto-promove a Obra** (a decisão `forge` original fica preservada embutida no `escalation_decision`).

⚠️ `ForgePromotionPreviewBuilder` + `DevToForgeEscalationPacketFactory` **NÃO têm chamador vivo em `app/`** (só testes) — são a fatia canônica planejada mas ainda não fiada ao run; o caminho vivo monta o pacote direto em `DevToForgePromotionService::attachCanonicalEscalationPacket` (`:227-308`). Lacuna documentada em `ForgePromotionPreviewBuilder.php:23-30`.

#### 8.2 Cadeia B — PromotionSignalDetector + os 3 tiers

`PromotionSignalDetector::analyse` (`AtlasCode/PromotionSignalDetector.php:203-277`), score aditivo por eixo (message_density, context_length, file_breadth, architecture kw, risk kw, recurring_failure, +4 se `operator_promotion_request`). Mapeamento tier (`:262-269`): `score>=7→forge_obra`; `>=4→obra_candidate`; `>=2→quick_intervention`; senão `none`. Veto `thin_small_bug` rebaixa quick→none (`:274`).

Efeitos por tier em `promote()` (`DevToForgePromotionService.php:119-215`):
- **forge_obra**: cria Obra real `AtlasProject::create` (`:182-204`) + `escalation_packet_v1` + `route_decision.v1`
- **obra_candidate**: NÃO cria Obra; anexa pacote + route_decision; fica pendente na Atenção
- **quick_intervention**: SEM pacote, SEM route_decision (`recordCanonicalRouteDecision` retorna `recorded:false`), NÃO entra na Atenção — resolvido inline pelo operador

#### 8.3 Persistência da escalação

- **`route_decision.v1`** (`atlas.dual_core.route_decision.v1`) via `DualCoreRouteDecisionService::record` → **tabela `ai_dual_core_route_decisions`** (model `AiDualCoreRouteDecision`, migração `2026_05_18_040000_...`). Sempre `route=dev_to_forge`. `decision_hash` único. Sem tabela → `recorded:false` (fail-closed sem gravar).
- **`escalation_packet.v1`** (`atlas.dev_to_forge.escalation_packet.v1`, `Schemas/EscalationPacket.php:43`): `source_core=atlas_dev`, `target_core=atlas_forge`. Campos com invariantes (`original_user_intent` verbatim não-vazio, `promotion_triggers>=1`, `recommended_forge_mode` enum, `suggested_work_packets>=1`, 6 slots de evidence_refs). É **o único símbolo AtlasDev que o Forge importa** (contrato de handoff).
- **`escalation_decision.json`** (`atlas.dev.escalation_decision.v1`) persistido pelo senior loop (`SeniorEngineerLoopExecutor.php:160`).
- **Candidato** (`atlas.code.dev_to_forge.promotion_candidate.v1`): **arquivo JSON** em `storage/app/atlas-code/promotion-candidates/{workspace_slug}/{pc_ULID}.json` (`persist()` `:928-947`). Fail-closed: id vazio → `RuntimeException`; write falha → `RuntimeException`.

#### 8.4 Fila de Atenção / humano no loop

Regra invariante: **Dev NUNCA cria Obra automaticamente** (`EscalationDecisionEngine.php:22`, `DevToForgePromotionService.php:29,519`). `AtlasCodeAttentionControlPlaneService::promotion()` (`:288-298`) lista candidatos; `promotionCandidateItem` (`:122-167`) filtra só `pending_decision` E `target != quick_intervention`; gera item `KIND_INTAKE_NEEDED` com `human_question` "Promover esta thread a Obra Forge?/Candidato?" e `allowed_actions` OPEN_OBRA/APPROVE/REJECT/DISMISS_WITH_REASON. Operador fecha o loop via `POST .../promote` (confirma/cria Obra) ou `POST .../candidates/{id}/dismiss` (`candidate_status='dismissed'`).

#### 8.5 Rotas HTTP `/atlas-code/dev-to-forge/*` (`AtlasCodeDevToForgePromotionController`)
| Rota | Método | Chama |
|---|---|---|
| GET `threads/{thread}/promotion-preview` | `preview` (`:38`) | `previewForThread` → 404 em erro |
| POST `threads/{thread}/promote` | `promote` (`:52`) | `promote(threadId, target∈{quick,obra,forge}, overrides, ws)` → 201 / 422 |
| GET `candidates` | `index` (`:85`) | `listCandidates` |
| GET `candidates/{candidate}` | `show` (`:102`) | `findCandidate` → 404 se null |
| POST `candidates/{candidate}/dismiss` | `dismiss` (`:112`) | `dismiss(candidate, reason)` |

#### 8.6 Diagrama ASCII (escalação)
```
CADEIA A (run)                                   CADEIA B (thread/operador)
Run falha (SeniorLoop:100)                        POST /promote (api:747)
  → EscalationSignalsInput                          → Controller::promote
  → EscalationSignalScorer.score() {0..10}          → Service::promote
  → EscalationDecisionEngine.decide()                 → previewForThread
     score<4 & !R4/R5 → null (não escala)               → PromotionSignalDetector.analyse
     score>=7 || R4/R5 → forge (+human)                    score>=7→forge_obra
     4..6 → obra_candidate                                 >=4→obra_candidate
  → EscalationDecision::issue (invariantes)              >=2→quick / else none
  → escalation_decision.json (SeniorLoop:160)         → forge_obra? AtlasProject::create
  → candidateFromRunEscalation [FIXA obra_candidate]  → attachCanonicalEscalationPacket
        │                                             → recordCanonicalRouteDecision
        └──────────────┬──────────────────────────────────────┘
                       ▼
   persist(): promotion-candidates/{ws}/{pc_ULID}.json
   + ai_dual_core_route_decisions (route_decision.v1)
                       ▼
   AtlasCodeAttentionControlPlaneService (fila humana; exclui quick_intervention)
                       ▼
   DECISÃO HUMANA: APPROVE / OPEN_OBRA / REJECT / DISMISS
```

---

### 9. Fluxo SENIOR LOOP (executor / auditor)

⚠️ **Correções de escopo (código = verdade):**
- Existem **apenas 2 comandos** senior-loop: `atlas:dev:senior-loop:run` (`AtlasDevSeniorLoopRunCommand.php:14`) e `atlas:dev:senior-loop:audit` (`AtlasDevSeniorLoopAuditCommand.php:14`). **NÃO existe `:readiness` nem `:smoke`** (grep zero). `AtlasDevSmokeCommand` é `atlas:dev:debug:smoke` (não é senior-loop).
- O "Senior Engineer Loop" **NÃO é uma iteração apertada executor↔auditor**. É: (1) **Auditor** = audit de projeção de capacidade do PLANO (estático, one-shot, roda dentro do `planOnly`); (2) **Executor** = UM `planOnly` + UMA chamada `RunExecutor::execute` (reporta `attempts_executed=1` fixo, `SeniorEngineerLoopExecutor.php:248`). O **loop de repair real (M2 loop-to-green)** vive DENTRO do `PipelineRunExecutor::execute`, não nas classes SeniorLoop.

#### 9.1 `SeniorEngineerLoopExecutor::run` (`SeniorLoop/SeniorEngineerLoopExecutor.php:45-282`)
1. `orchestrator->planOnly(...)` (`:52`)
2. **Gate fail-closed de routing**: se `!isFastPath()` → status `blocked`, `debug_loop.mode=not_started`, `reason=routing_not_executable`, **sem chamada a provider** (`:57-80`)
3. `runExecutor->execute(..., expectedCompactSddHash: compactSddHash)` (`:82-88`) — pin de CompactSDD (tamper → `CompactSddUnavailableException` antes do provider)
4. **Predicado de aprovação (conjuntivo)**: `completionState==passed && scopeGuardStatus==passed && verificationStatus==passed` (`:91-93`)
5. Em falha (`:100-222`): append `error_ledger`, build+persist `failure_capsule`, `EscalationDecisionEngine::decide` → escalação (§8), persist DevTaskPacket + DevFailureCapsule (failure memory)
6. Build `SeniorEngineerLoopExecution` (`attempts_executed=1`; `attempts_allowed = RepairAttemptLimits::attemptsAllowed(risk, maxAttempts)`) → `withHash()` → persist `senior_engineer_loop_execution.json`

#### 9.2 Auditor (`SeniorLoop/SeniorEngineerLoopAuditor.php:14-47`) — 7 gates de capacidade do plano
Roda dentro do `planOnly` (`Orchestrator:251`), audita o `PlanOnlyResult` (não a execução). 7 booleans; qualquer false → audit `blocked`:
1. `ambiguity_resolution_engine`; 2. `multi_step_work_planner`; 3. `autonomous_debug_loop` (=`validationCommands != []`); 4. `architecture_aware_editing` (write-implied exige allowed+forbidden não-vazios, `maxFilesChanged>=count(allowedFiles)`, projeção sendable); 5. `desktop_engineer_cockpit` (painéis + cancel/retry/resume); 6. `learning_error_ledger_curator_flow` (`auto_apply=false`, `curator=programming_curator`, `proposal_inbox_required=true`); 7. `enterprise_hardening` (artefatos persistidos, projeção provider-safe, **`fallbackAllowed===false`**). Status `passed` só se todos passam.

#### 9.3 Onde a iteração realmente acontece
`PipelineRunExecutor::execute` loop M2 (§3 acima). O SeniorLoopExecutor **nunca itera**. Rota alternativa: `SeniorEngineerLoopExecutionReporter` (usado por `RunController.php:437` e `AtlasDevRunWorkerCommand.php:162`) constrói o MESMO schema a partir de um `RunExecutionResult` já produzido — é a rota HTTP/worker para o mesmo recibo.

#### 9.4 Fail-modes senior loop
- Routing não-executável → blocked, sem provider (fail-closed)
- CompactSDD tamper → exceção antes do provider
- Repair sub-loop: cap `min(3,maxAttempts)`; **R4/R5 recebem 0 tentativas** (`RepairAttemptLimits.php:26,44`); abort `same_signature_twice`/`validation_failed_after_max_repairs`
- Escalação é **fail-OPEN** (telemetria sobre run já falho, try/catch degrada para "sem decisão", nunca crasha o loop, `:129-188`)
- Learning **nunca auto-aplicado** (`auto_apply=false`, `curator_required=true` em todo caminho)
- `--strict` → exit FAILURE se status != passed

---

### 10. Fluxo WORKSPACE (profile + intelligence assembly + observed session)

Todos em `app/Services/AtlasCode/`.

#### 10.1 Workspace Profile — `AtlasCodeWorkspaceProfileService.php`
Read-model do "que é este workspace" (nunca executa comando, `:31`). Schema `atlas.code.workspace_profile.v1`. Inputs: `config/atlas_projects.php` + tabela `atlas_workspace_profiles` (DB sobrepõe config por slug, com merge protetor de `code_index_roots`, `:57-64`). `shape()` (`:281-325`) monta `slug`, `workspace_path`, `code_index_roots`, `surfaces_enabled`, e bloco **`safety`** anti-mock (`:316-323`): `execution_allowed = @is_dir(path)` real (`:317`); ausente → `execution_blocked_reason='workspace_path ausente...'` (`:318`); `risk_floor=high` em produção. Resolução: `findBySlug`/`findByReference` (aceita slug, path exato, ou path dentro de workspace registrado, `:121-179`); `resolveActiveSlug` fixa o workspace ativo. Persistência via `upsertPersistedProfile` (exige tabela, valida slug regex).

#### 10.2 On-link indexing — `WorkspaceIntelligenceAssemblyService.php`
**Regra dura:** NUNCA roda inline em request HTTP/chat — só via job de fila (`AssembleWorkspaceFolderIntelligenceJob`) ou CLI; lock W-10 por workspace; re-link fresco = no-op (`:33-41`). É GLUE sobre o substrato AP-815 (não duplica indexador).
- **Disparo** `queueAssembly()` (`:99-117`): guard de path → `identity->resolve()` → `AssembleWorkspaceFolderIntelligenceJob::dispatch` → `markQueued` (status `assembly_pending`).
- **Execução** `assemble()` → `assembleClassified()` (`:164-204`): `folderIntelligence->inspect` classifica git_repository / umbrella (árvore + filhos com profile próprio) / skipped_not_indexable. Repos processados **em série** (W-11).
- **Por-repo** `assembleRepo()` (`:237-333`): no-op por frescor (`skipped_fresh`); `canonicalIndexPath` D-4 anti-destruição (prune só no dono do wid); **lock W-10** `withLock(ttl:1800)`; dentro: **G-5 secret-prescan ANTES de ingerir** (bounded 4000 arquivos), `codeIntelligence->index(prune:true)` → tabelas `atlas_engineering_code_modules`/`atlas_engineering_code_symbols`, `symbolBuilder->build(wid)`. Sucesso → evidence `completed`; falha → `status:failed` + `markFailed` (nunca lança).
- **Status read-back** `WorkspaceIntelligenceStatusReader::status()` (`:43-82`): query em `atlas_engineering_code_modules` → `intelligence_status` ∈ {indexing, assembly_pending, failed, indexed, portrait_only}. Fail-safe: DB down → `portrait_only` honesto. `needsAssembly()` W-9 (modules==0, schema bump, ou índice > `fresh_minutes` default 30).
- **Retrato barato** `WorkspaceFolderIntelligenceService` (Fase 1): classifica pasta read-only, git timeout 3s, `withIntelligenceV2` aditivo flag-gated injeta o status.

#### 10.3 GitWorkspaceInspector — FAIL-CLOSED (crítico)
`AtlasCode/GitWorkspaceInspector.php`. Snapshot read-only via git (array args, sem shell string; sempre timeout). `captureSnapshot()` (`:58-128`): `$base` nasce `success=false`. **Três portões devolvem blocker estruturado em vez de mock**:
1. path ausente/ilegível → `blocker_reason='workspace_path_missing_or_unreadable'` (`:77-79`)
2. não é repo git → `blocker_reason='not_a_git_repository'` (`:82-84`)
3. comando git falhou → `blocker_reason='git_command_failed:...'` (`:97-101`)

Só no caminho feliz retorna `success=true` com head_sha/branch/files_changed/diff_excerpt/diff_hash. **Em nenhum ramo há mock/fake** — ausência é sempre `success=false` + `blocker_reason`. Variante `captureProviderSafeSnapshot` (para memória AWIS) nunca materializa diff; mesmos três portões.

#### 10.4 Observed Session — `AtlasCodeObservedSessionService.php`
Execução interativa de provider dentro de uma Obra: operador roda `claude`/`codex`/`gemini` no terminal, cola prompt, clica "Import Result". **O provider NUNCA declara conclusão** — completion é decisão do Atlas após gates + aceitação humana (`:37-38`). Máquina de estados de 12 estados com whitelist de transições (`:45-78`, `accepted`/`rejected` terminais). Persistência dual: DB `atlas_code_observed_sessions` ou FS `storage/app/atlas-code/observed-sessions/{obra}/{session}.json`.
- `open()`: blockers honestos (`workspace_missing`/`workspace_not_writable`) ANTES do export do packet
- `importResult()` (`:332-402`): auto-captura git via `GitWorkspaceInspector::captureSnapshot` só se `success` (fail-closed garante ausência ≠ mock); **scope guard** via `AtlasCodeScopeGuardMatcher` (forbidden sempre ganha) → violação = estado `blocked`; sucesso → `review_required`
- `runGates()`: 5 gates advisory (verification_commands NÃO executados pelo Atlas — operador roda)
- `decide()`: accept **exige `result_imported_at`** (nunca aceita por texto do provider) + recibo assinado Ed25519 via `HumanDecisionReceiptSigner`

#### 10.5 Quem chama (tie-in com Dev runs)
`PlanController.php:171` (`findBySlug`), `AtlasWorkspaceIntelligenceRuntimeService` (`findByReference` + `captureProviderSafeSnapshot`, blockers `workspace_not_registered`/`git_workspace_not_found`), `CodeGraphWorkspaceIdentity:191`, `EngineeringCodeIntelligenceService`, `AtlasCodeWorkspaceController.upsert()` (`queueAssembly` on-link, `:163`), `AtlasCodeObservedSessionController`, `AtlasOpenBrainContextPackService` (`contextScopeIds`).

---

### 11. Diferença LEGACY ↔ EFFICIENT sob `atlas:cli:dev`

Só o comando `atlas:cli:dev` (`AtlasCliDevCommand.php`) escolhe entre os dois mundos. Os outros comandos vivem inteiramente no mundo EFFICIENT.

**Gate de decisão** (`AtlasCliDevCommand.php:105-113`):
```
$useEfficient = (bool)--efficient
    || (config('atlas_dev.efficient.default_path')=='efficient' && !--legacy);
```
Precedência: `--efficient` vence sempre; senão `--legacy` força legacy; senão `ATLAS_DEV_DEFAULT_PATH` (default `efficient`, `config/atlas_dev.php:66`).

- **EFFICIENT** `runEfficient()` (`:856`) → `AtlasCliDevEfficientHandler::run` (`app/Services/Ai/Cli/AtlasCliDevEfficientHandler.php`): `plan_enabled` gate → `orchestrator->planOnly` → `formatPlanOnly` → **gate de consentimento `--yes`/`operator_confirmed`** (sem `--yes` para em `confirmation_required`, exit 0, **zero provider**, `:126-131`) → `run_enabled` gate → `ConfirmationTokenService::issue`+`validateAndConsume` (HMAC TTL 300s) → `RunExecutor::execute(...expectedCompactSddHash)`. **Exit honesto (VAL-M2-030):** `failed`/`blocked`→exit 1; `passed`/soft→exit 0; erros 64/66/70.
- **LEGACY** (fall-through `:115-452`): `AtlasCliDevWorkflowService::preflight` + `AtlasProgrammingOrchestrator` (`sessionPlan`/`executeWithHarness`) → forge usa `executeWithHarness`, senão shell `atlas:ai:chat`. Sem máquina de token; `--plan-only` só imprime e para. Consome todo o surface de flags legadas (`--provider`, `--model`, `--effort`, Fair-Claude, `--forge`, Open Brain, harness).

```
        atlas:cli:dev handle()  (:105-113)
   --efficient || (default_path=='efficient' && !--legacy)
     ┌──────── true ─────────┐        ┌──────── false ────────┐
     ▼ EFFICIENT             │        ▼ LEGACY                 │
  AtlasCliDevEfficientHandler │      AtlasCliDevWorkflowService │
   planOnly → --yes gate →    │       preflight + AtlasProgramming
   token(HMAC 300s) →         │       Orchestrator(sessionPlan/
   RunExecutor::execute       │       executeWithHarness) →
   (ScopeGuard/Verification/  │       forge:executeWithHarness
   CompletionState/E1-E6)     │       else: atlas:ai:chat subproc
   exit 0/1/64/66/70          │       exit = subprocess code
```

**Outros comandos EFFICIENT-world:** `atlas:cli:dev:plan` (Plan Visible A2 — actions project/approve/reject/inspect/telemetry sobre `AtlasProgrammingWorkItem`, provider nunca chamado); `atlas:dev:readiness` (9 checks, §1); `atlas:dev:run-certify` (read-only reporter da tabela `atlas_dev_run_certifications`); `atlas:dev:run-snapshot` (read-only via `DevRunControlSnapshotService`); `atlas:aaeos:atlas-dev-policy` (decider read-only dos 17 invariantes P1-P17, verdict allow/needs_decision_receipt/policy_wins/delegate — NUNCA executa); `atlas:dev:run-worker` (worker isolado do dispatch `process`, §3).

---

### 12. Índice de arquivos-chave (caminhos absolutos)

- Controllers: `/home/user/atlas-server/app/Http/Controllers/AtlasDev/{ReadinessController,PlanController,RunController,StreamController,CancelController,ShowController,IndexController}.php`; `/home/user/atlas-server/app/Http/Controllers/AtlasCodeDevToForgePromotionController.php`
- Run support: `/home/user/atlas-server/app/Http/Controllers/AtlasDev/Support/{RunExecutor.php,PipelineRunExecutor.php,RunExecutionResult.php,CompactSddUnavailableException.php}`
- Orquestração/pipeline: `/home/user/atlas-server/app/Services/Ai/Programming/AtlasDev/Pipeline/{AtlasDevFastPathOrchestrator,RoutingDecisionEngine,RiskLevelScorer,TaskClassifier,SpecComposer}.php`
- Gates: `/home/user/atlas-server/app/Services/Ai/Programming/AtlasDev/Gate/{ScopeGuard,VerificationGate,CompletionStateGate,CompletionDecision,MandatoryRagGate,ReceiptComposer}.php`; elevações `.../Support/Elevations/ElevationConfig.php`
- Escalação: `/home/user/atlas-server/app/Services/Ai/Programming/AtlasDev/Escalation/{EscalationSignalScorer,EscalationDecisionEngine,EscalationDecision,DevToForgeEscalationPacketFactory,ForgePromotionPreviewBuilder}.php`; `/home/user/atlas-server/app/Services/AtlasCode/{DevToForgePromotionService,PromotionSignalDetector}.php`
- SeniorLoop: `/home/user/atlas-server/app/Services/Ai/Programming/AtlasDev/SeniorLoop/{SeniorEngineerLoopExecutor,SeniorEngineerLoopAuditor,SeniorEngineerLoopExecution,SeniorEngineerLoopExecutionReporter}.php`
- Workspace glue: `/home/user/atlas-server/app/Services/AtlasCode/{AtlasCodeWorkspaceProfileService,WorkspaceIntelligenceAssemblyService,WorkspaceIntelligenceStatusReader,GitWorkspaceInspector,AtlasCodeObservedSessionService,AtlasCodeScopeGuardMatcher}.php`
- Runtime/worker: `/home/user/atlas-server/app/Services/Ai/Programming/AtlasDev/Runtime/{AtlasDevReadinessService,ProcOpenRunWorkerDispatcher}.php`; `/home/user/atlas-server/app/Console/Commands/AtlasDevRunWorkerCommand.php`
- Persistência: `/home/user/atlas-server/app/Services/Ai/Programming/AtlasDev/Persistence/{ArtifactNames,ReceiptStorage}.php`
- Config: `/home/user/atlas-server/config/atlas_dev.php`; boundary adapter `/home/user/atlas-server/app/Services/Ai/EngineeringKernel/Adapters/AtlasDevGateAdapter.php`

---

# PARTE III — Atlas Forge · Fluxos detalhados

## Atlas Forge — Todos os Fluxos

> **Regra de ouro deste mapa:** o código é a verdade. Cada afirmação abaixo foi
> verificada lendo o código real e seguindo a cadeia de chamadas. Referências no
> formato `Arquivo.php:linha`. Caminhos absolutos a partir de `/home/user/atlas-server`.

O **Atlas Forge** é o subsistema de execução governada de engenharia do Atlas
(a "fábrica" que pega uma Obra e a executa com providers reais, sob gates
pétreos). Vive quase todo sob o namespace `App\Services\Ai\Programming` (+
`...\Programming\Forge`) e é exposto via rotas `/atlas-code/works/{project}/forge/*`
e comandos `atlas:forge:*` / `atlas:code:forge-*`.

### Invariantes pétreos (valem em TODOS os fluxos)

Estes aparecem hardcoded em quase toda saída — são a "constituição" do Forge:

- **`external_provider_call = false`** em toda camada de plano/leitura/dispatch.
  Só a `AtlasForgeProviderInvocationService` em `mode=execute` com todas as
  confirmações chega a tocar um provider externo.
- **`completion_claim_promoted = false`** — nenhum fluxo auto-promove conclusão.
- **`review_completion_gate_preserved = true`** — nenhum fluxo bypassa o gate de review humano.
- **`separated_from = external_rivals_certification`** — Forge (entrega governada)
  e Rivals (benchmark de avaliação) são universos separados.
- **Fail-closed sem Obra**: todo fluxo governado exige `obra_id`; sem ele → `blocked`.
- **Provider-proof**: um diff sem prova de autoria de provider (`provider_called=false`)
  nunca conta como execução real.

### Mapa macro (Continuum OS)

```
Atlas Code / Atlas Dev
        │  (promoção)                          (fast-path orquestra tudo)
        ▼                                                   │
  Dev→Forge Promotion ──► Obra (AtlasProject) ◄─────────────┘
        │  emite escalation_packet.v1                        │
        ▼                                                    ▼
  Work Intake ──► [ atlas:decide ] ──► Decision Receipt (live_atlas_decide)
   (AiForgeIntake)          │
        │                   ▼
        │           Provider Topology (read-model: roles+fallback+capacity)
        │                   │
        │                   ▼
        │           Runtime Dispatch (exige receipt live + topology + capacity)
        │                   │  status=dispatch_planned
        │                   ▼
        │           Provider Invocation (18 blockers; dry_run|execute)
        │                   │  driver router → CLI real (allowlist + safe runner)
        ▼                   ▼
  Work Packet Cycle    Live Execution (sync/async/job) ──► Evidence Pack
   (select→plan→               │                                │
    start→complete)            ▼                                ▼
        │            Review/Completion Gate (humano) ──► Promotion ──► workspace vivo
        │                   (evidence_refs + gate passed)      │
        ▼                                                       ▼
  Long-Horizon State                                       Rollback (backup+hash)
```

### Persistência (visão geral)

Duas famílias de persistência coexistem:

1. **Tabelas `ai_forge_*`** (motor canônico do Forge, engine B):
   - `ai_forge_intakes` (`AiForgeIntake`) — intake canônico, schema `atlas.forge.intake.v1`.
   - `ai_forge_work_packets` (`AiForgeWorkPacket`) — packets filhos do intake.
   - `ai_forge_work_packet_execution_cycles` (`AiForgeWorkPacketExecutionCycle`) — ciclos.
   - `ai_forge_long_horizon_states` (`AiForgeLongHorizonState`) — estado de obra longa.
   - `ai_forge_milestones` (`AiForgeMilestone`), `ai_forge_multi_agent_schedules`
     (`AiForgeMultiAgentSchedule`), `ai_forge_outcome_memories` (`AiForgeOutcomeMemory`).
2. **`atlas_projects.metadata` (JSON)** — a maioria dos fluxos HTTP/cockpit
   (fast-path, live execution, provider invocation/dispatch/topology/capacity,
   reviews) projeta seus read-models e histories aqui, NÃO em tabela dedicada.
   Também: `atlas_engineering_runs`, `atlas_engineering_evidence`,
   `atlas_ledger_events` (Evidence Ledger), `ai_dual_core_route_decisions`.

Storage em disco: `storage/app/forge-live-exec-tmp` (sandbox fixture),
`forge-governed-exec-tmp` (shadow), `forge-governed-exec-artifacts/<execId>`
(patch/promotion artifacts), `forge-governed-promotions/<id>/before` (rollback
backups), `storage/app/atlas-code/promotion-candidates/**` (candidatos Dev→Forge),
`storage/atlas/rivals` (Rivals).

Todas as rotas vivem no grupo `Route::prefix('atlas-code')->group(...)`
(`routes/api.php:680`) — **este grupo não declara middleware de auth**; o
fail-closed acontece na camada de serviço via obra-binding, não por token.

---

## Fluxo 1 — Live Execution SÍNCRONA

**Trigger:** `POST /atlas-code/works/{project}/forge/live-executions` →
`AtlasCodeForgeExecutionController::store` (`app/Http/Controllers/AtlasCodeForgeExecutionController.php:32-83`).

**Validação** (`:38-46`): `simulate_failure`, `role`(≤60), `execute`,
`confirm_provider_call`, `confirm_budget`, `confirm_runtime_dispatch`,
`timeout_seconds`(1..3600) — todos nullable.

Há **dois caminhos**, decididos em `:74`:

### 1a. Caminho FIXTURE (default / test-double)
1. Grava route decision via `ForgeIntakeRouteDecisionRecorder::record('forge', ...)`
   (`:54-65` → `app/Services/Ai/DualCore/ForgeIntakeRouteDecisionRecorder.php:51-121`),
   tabela `ai_dual_core_route_decisions`; risk=high, duration=days.
2. `executeAndPersist($project, $service, $simulateFailure)` (`:80`/`:328-363`).
3. Dentro: `AtlasForgeLiveExecutionService::execute(['obra_id'=>..., 'simulate_test_failure'=>...])`
   (`app/Services/Ai/Programming/AtlasForgeLiveExecutionService.php:99-212`) — pipeline de **13 stages**:
   `obra_binding` (fail-closed `obra_required` `:112-123`) → `workspace_execution_gate`
   (AWIS gate, blocked `awis_execution_gate_blocked` `:130-134`) → `sandbox_provision`
   (cria dir em `forge-live-exec-tmp`) → `context_pack` (12 refs canônicas hasheadas) →
   `aucri_runtime_enforcement` → `patch_apply` (escreve fixture) → `action_manifest` →
   `patch_verifier` → `test_run` (roda `Symfony\Process` real, timeout 30s) →
   `stage_receipts` (2 receipts) → `repair_loop` → `evidence_ledger` (4 eventos
   em `atlas_ledger_events`) → `sandbox_rollback` (`File::deleteDirectory`).
   `finalize` (`:788-836`): status agregado `passed`/`degraded`/`blocked`;
   **`external_provider_call=false` hardcoded (`:833`)**; `EXECUTION_MODE='simulate_only_test_double'`
   (`:40`) — o docblock (`:31-39`) declara EXPLICITAMENTE que é TEST-DOUBLE.
4. Sidecar governado: `governedExecutionForProject` (`:338`/`:493-518`) roda
   `AtlasForgeGovernedExecutionService` só se houver WorkItem com `tasks_json` (ver Fluxo 3).
5. `snapshotFromReport` → `persistRun` (`atlas_engineering_runs`) → `persistEvidence`
   (`atlas_engineering_evidence`) → `rememberProjectSnapshot` (grava
   `latest_forge_live_execution` + `atlas_code_forge_live_execution_history` cap 20).

### 1b. Caminho REAL GOVERNED CHAIN (`executeViaRealChain`, `:95-143`)
Ativado quando `!simulate_failure && config('atlas.forge.cockpit_real_invocation_enabled')===true`.
1. `AtlasForgeRuntimeDispatchService::dispatch([...'execution_mode'=>'prepare_dispatch_plan'])`.
   **Fail-closed** (`:107-118`): se `dispatch['status'] !== 'dispatch_planned'` → `blocked`,
   `external_provider_call=false`, antes de qualquer provider.
2. `AtlasForgeProviderInvocationService::invoke([...])` com `mode=execute` se `data['execute']`,
   senão `dry_run` (ver Fluxo 8). Response schema `atlas.code.forge_cockpit_real_invocation.v1`.

**Resultado:** HTTP 201 (ok) / 409 (`report.forge_live_execution_status==='blocked'`).
Response schema `atlas.code.forge_live_execution_response.v1` com `report`, `snapshot`,
`persistence{engineering_run_id, engineering_evidence_id}`.

**Persistência:** `ai_dual_core_route_decisions`, `atlas_engineering_runs`,
`atlas_engineering_evidence`, `atlas_ledger_events`, `AtlasProject.metadata`,
`atlas_programming_work_items` (via governance feedback).

**Fail-closed:** obra ausente → blocked; AWIS gate/sandbox → retorno cedo blocked;
real chain sem `dispatch_planned` → blocked antes de provider; tabelas ausentes →
persistência retorna null mas fluxo segue (tolerante).

---

## Fluxo 2 — Live Execution ASSÍNCRONA (rota → job na fila → service)

**Triggers:**
- `POST .../forge/live-executions/async` → `startAsync` (`AtlasCodeForgeExecutionController.php:145-195`)
- `GET .../forge/live-executions/{executionId}` → `showAsync` (`:197-215`)
- `GET .../forge/live-executions/history/{historyId}` → `showHistory` (`:217-278`)
- **Job:** `app/Jobs/AtlasCodeForgeLiveExecutionJob.php` (fila `atlas-code-forge`, `:31`; `tries=1`, `:24`).

**Cadeia `startAsync`:**
1. Route decision recorder (entry `.../async`).
2. `rememberAsyncExecution` (`:171-186`/`:883-932`): cria registro
   `atlas.code.forge_live_execution.async.v1`, `execution_id`=ULID, status `queued`;
   persiste `metadata.latest_forge_live_execution_async` + history (cap 20).
3. `AtlasCodeForgeLiveExecutionJob::dispatch(projectKey, simulateFailure, execution_id)` (`:188`).
4. Retorno **202** schema `atlas.code.forge_live_execution_async_response.v1`.

**Job** (`AtlasCodeForgeLiveExecutionJob.php:34-44`): acha o projeto (inexistente →
no-op silencioso `:38-41`), chama `controller->executeAsyncJob($project, $service, ...)`
(`AtlasCodeForgeExecutionController.php:280-313`):
1. Async record → `running` + `started_at`.
2. `executeAndPersist(...)` — **mesmo pipeline de 13 stages do Fluxo 1a**.
3. Sucesso: async record → `completed`/status do snapshot; grava `run_id`,
   `evidence_id`, `remaining_blockers`, `completion_claim_allowed`.
4. Exceção: `markAsyncFailed` (status `failed`) e **re-lança** → `Job::failed()`
   (`:46-58`) chama `markAsyncFailed` de novo (idempotente por execution_id). Sem retry.

**`showAsync`:** `asyncExecutionFor` (busca latest/history por id). Não achou → **404**.
Achou → 200 com `execution` + snapshot.

**`showHistory`:** `historyEntryFor`. Não achou → **404** `forge_history_entry_not_found`.
**Guard de obra-binding** (`:230-240`): `entry.obra_id != project` → **403**
`forge_history_obra_mismatch`. Monta replay read-only (`external_provider_call=false`).

**Fail-closed:** projeto inexistente no job → no-op; exceção → `failed` + re-throw; sem retry;
guard de obra-binding no history (403).

---

## Fluxo 3 — Governed Execution (shadow patch, sidecar estrito)

**Trigger:** chamado internamente por `store`/`executeAndPersist` do Fluxo 1
quando há WorkItem com `tasks_json` não-vazio.
Serviço: `app/Services/Ai/Programming/AtlasForgeGovernedExecutionService.php`
(`SCHEMA_VERSION='atlas.forge_governed_execution.v1'`, `EXECUTION_MODE='governed_shadow_patch'`).

**Cadeia `execute(AtlasProject, AtlasProgrammingWorkItem)` (`:82-217`)** — 15 stages,
cada uma fail-closed com retorno cedo:
`stageBinding` → `selectTask`+`stageTaskContract` (task com `allowed_files`; vazio →
`allowed_files_required`) → `stageWorkspaceExecutionGate` (AWIS, `awis_execution_gate_blocked`) →
`stageWorkspace` (`realpath`, `workspace_path_required`) → `firstExistingAllowedFile`
(`AiPathMatcher::isProviderSafeRelativePath`, `no_existing_allowed_file_in_workspace`) →
`stageSandboxShadow` (**copia o arquivo alvo p/ shadow em `forge-governed-exec-tmp`;
NUNCA muta o workspace vivo**) → `stagePatchDryRun` (`dry_run=true`,
`live_workspace_mutated=false`; gera `governed.patch` + `promotion-artifact.json`
schema `atlas.forge_governed_execution.patch_artifact.v1` em `forge-governed-exec-artifacts/`,
com hashes before/after/diff) → `stageActionManifest` → `stageTestImpact` →
`stageValidationRun` (só aceita `php artisan test --filter=X` ou `vendor/bin/{phpunit,pest}
--filter=X`; `php -r` só se flag `atlas.forge.validation_test_runner_only` OFF;
`operator_validation_command_required` se nada runnable) → `stagePatchVerifier` →
`stageReceipts` → `stageGovernanceEvidence` (ledger + `governance->verify(['evidence-required',
'scope-guard'])`; passed só se `gate_summary.all_green`, senão `governance_gates_not_green`) →
`stagePromotionGate` (`status='requires_human_approval'`, `live_workspace_mutated=false`) →
`stageSandboxRollback` (deleta shadow).

**`finalize` (`:710-761`):** `promotion_status='requires_human_approval'`,
`external_provider_call=false`, `live_workspace_mutated=false` SEMPRE (`:757-759`).

**Persistência/efeitos:** shadow (nunca o vivo), artifacts em `forge-governed-exec-artifacts/`,
Programming Governance ledger, receipts. O `promotion-artifact.json` é o insumo que o
Fluxo 5 (promotion) consome para aplicar ao workspace vivo.

---

## Fluxo 4 — Fast-Path (orquestrador de 8 stages)

**Trigger:** `POST /atlas-code/works/{project}/forge/fast-path` →
`AtlasCodeForgeFastPathController::store` (`app/Http/Controllers/AtlasCodeForgeFastPathController.php:26-64`).
Serviço: `app/Services/Ai/Programming/AtlasCodeForgeFastPathService.php` (`run()` em `:120`).

**Validação** (`:31-43`): `mode ∈ {prepare_only, execute_async, execute_sync}` (default
`execute_async`); `intent`(≤2000); flags `auto_create_work_item`/`auto_compile_spec_plan`/
`start_execution` (default true); `create_checkpoint`(default false); `operator_id`(≤120).

**8 stages canônicas** (`CANONICAL_STAGES` `:46-55`; cada uma faz early-return via
`finalize()` se não `passed`):
1. **obra_binding** (`:346-373`): `obra_required` / `obra_not_found`.
2. **workspace_binding** (`:378-426`): guard de domain (`atlas`/`programming`/`''`) +
   **AWIS Execution Gate** (`AtlasWorkspaceIntelligenceExecutionGateService::gate(mode:'forge')`);
   fail-closed `awis_execution_gate_blocked`.
3. **work_item_resolution** (`:431-518`): reusa `metadata.programming_work_item_id`
   ou cria via sub-request interna a `AtlasCodeProgrammingWorkItemController::store`;
   sem intent → `work_item_intent_required`; sem auto-create → `auto_create_disabled`.
4. **spec_plan_resolution** (`:523-592`): sub-request a `compileSpecPlan`
   (spec/plan/task compilers); hidrata do work intake se `ready`.
5. **task_queue_resolution** (`:274-282`): `degraded`/`no_tasks_compiled` se 0 tasks.
6. **execution_dispatch** (`:597-688`): `prepare_only`→passed sem dispatch;
   `execute_sync`→sub-request a `AtlasCodeForgeExecutionController::store` (Fluxo 1);
   `execute_async`→sub-request a `startAsync` (Fluxo 2). Erros viram `*_threw`.
7. **state_projection** (`:694-710`): lê `latest_forge_live_execution` + certification.
8. **operator_next_action** (`:749-766`).
   (+ stage opcional **checkpoint** via `AtlasCodeCheckpointController::store`, fail-soft.)

**`finalize` (`:775-849`)** calcula status: `blocked` (obra/stage blocked) | `prepared`
(prepare_only) | `queued` (async com execution_id) | `degraded`/`passed` (sync).
**`external_provider_call=false` sempre.** `progress_percent` = (#stages passed|degraded /8)*100.

**Persistência — `rememberFastPath` (`:891-920`):** tudo em `AtlasProject.metadata`
(NÃO há tabela dedicada): `latest_atlas_code_forge_fast_path`(+history 10),
`latest_atlas_code_forge_fast_path_run` + `atlas_code_forge_fast_path_run_history`
(cap 25, dedup por `fast_path_run_id`) — **este run projection é a fonte que Status e
Review leem depois**. Efeitos reais (WorkItem, live execution, evidence, checkpoint)
via os controllers canônicos reusados (zero runtime novo).

**HTTP:** blocked→409, queued→202, passed/prepared/degraded→201.

**CLI:** `atlas:code:forge-fast-path` (`AtlasCodeForgeFastPathCommand.php`), mesmo serviço.

### 4b. Status / Resume (read-only)
- `GET .../fast-path/{run}/status` → `AtlasCodeForgeFastPathStatusController::show`
  → `AtlasCodeForgeFastPathStatusService::status` (`:32-121`, schema
  `atlas.code.forge_fast_path_run_status.v1`). **Read-only, NÃO executa, NÃO sintetiza sucesso.**
  Fail-closed: run não encontrado → **404**; `run.obra_id != project` → **403**.
  Correlaciona async execution + forge live (validando `obra_id`) + review.
  `resolveLifecycleStatus` (`:333-375`): forge `passed` + review `pending` →
  **`review_required`** (gate humano); `+ approved` → `completed`; sem review → `passed`.
- `POST .../fast-path/{run}/resume` → `resume` = **alias idempotente de `status()`**
  (`:129-132`); NÃO muda estado, NÃO recomeça.

---

## Fluxo 5 — Review + Promotion + Rollback (rota `forge/reviews`)

**Triggers:**
- `POST /atlas-code/works/{project}/forge/reviews` → `AtlasCodeForgeReviewController::store`
  (`app/Http/Controllers/AtlasCodeForgeReviewController.php:26-77`).
- `POST .../forge/promotions/{promotionId}/rollback` → `rollback` (`:79-141`).

**`store` (gate de review + promoção):**
1. Valida `decision ∈ {approved, rejected}`, `comment`(≤500), `history_id`(≤120).
2. `historyEntry` acha a entrada em `atlas_code_forge_live_execution_history`.
3. **`reviewFor` (`:146-206`) — O GATE:**
   ```php
   $completionAllowed = (bool)($latest['completion_claim_allowed'] ?? false);   // :151
   $runPassed = (string)($latest['status'] ?? 'missing') === 'passed';          // :152
   if (! $runPassed)         $blockers[] = 'forge_run_not_passed';              // :157
   if (! $completionAllowed) $blockers[] = 'completion_gate_not_allowed';       // :160
   $approvalEffective = $decision === 'approved' && $blockers === [];           // :164
   'final_completion_allowed' => $approvalEffective && $completionAllowed,      // :186
   ```
4. Se `approval_effective` → `AtlasForgeGovernedPromotionService::promote(...)` (`:42-50`).
   Se promoção era exigida (governed_shadow_patch) e falhou → `blockReviewForPromotion`
   rebaixa o review para `blocked` (`:48-49`, `:231-251`).
5. Persiste `AtlasEngineeringEvidence` (`evidence_type='atlas_code_forge_review'`) e
   `rememberReview` (`latest_atlas_code_forge_review` + history 20; merge da promotion no
   execution history).
6. HTTP: `blocked`→409, senão 201.

**`AtlasForgeGovernedPromotionService::promote` (`app/Services/Ai/Programming/AtlasForgeGovernedPromotionService.php:46-159`)** —
aplica o patch governado (do Fluxo 3) ao **workspace vivo** após aprovação humana:
- Exige `governed_execution.promotion_artifact` (`promotion_artifact_missing`), WorkItem, workspace.
- **AWIS gate** de promoção (`awis_execution_gate_blocked`).
- **Adaptive control-plane guard** (`adaptiveControlPlaneGuard` `:606-645`): bloqueia se
  `forge_multi_agent_control_v3.status==='blocked'`.
- `readArtifact` (`:291-319`): valida path dentro de `storage/app/forge-governed-exec-artifacts`,
  hash sha256, schema, operation `append_line`.
- `assertInScope` (`:347-360`): arquivo alvo tem que estar em `diff_scope.files` como
  `in_scope` E `completion_claim_allowed===true`.
- **Drift check**: `expected_before_hash` vs hash atual; divergiu → `workspace_drift_since_governed_execution`.
- Escreve backup em `storage/app/forge-governed-promotions/<id>/before/`, aplica patch,
  reconfere `expected_after_hash` (mismatch → reverte automaticamente).
- Grava evidence `atlas.code.forge_workspace_promotion_evidence.v1` + governance verify.
- `promotion_status='promoted_to_workspace'`, `rollback.available=true`.

**`rollback` (`:166-243`):** exige `promotion_status='promoted_to_workspace'`, workspace,
AWIS gate, backup dentro do root (`insideRollbackRoot`), `backup_hash` bate, e drift check
(`workspace_drift_since_promotion`). Restaura o backup e reconfere hash. Grava evidence
`atlas.code.forge_workspace_rollback_evidence.v1`.

**Fail-closed:** cada guard retorna `blocked` com blocker nomeado; hash mismatch reverte;
`external_provider_call=false` em promote e rollback.

---

## Fluxo 6 — Fast-Path Review Completion Gate (evidence_refs + gate passed)

**Triggers** (`app/Http/Controllers/AtlasCodeForgeReviewCompletionController.php`):
- `GET .../fast-path/{run}/review` → `show` (packet + completionClaim).
- `POST .../fast-path/{run}/review/approve` → `approve` (`:45-59`).
- `POST .../fast-path/{run}/review/reject` → `reject` (`:61-75`).
- `POST .../fast-path/{run}/review/rollback` → `rollback` (`:77-91`).

Serviço: `app/Services/Ai/Programming/AtlasCodeForgeReviewCompletionService.php`.

**`packet` (`:69-158`, schema `atlas.code.forge_review_packet.v1`):** fail-closed
run inválido→400, não encontrado→404, obra mismatch→403. Correlaciona forge live +
review OBRIGATORIAMENTE por `execution_id`/`history_id`/`run_id`/`evidence_id`.
`completion_claim_allowed_before_review` sempre `false`; `approval_requires_human=true`.

**`approve` (`:215-229`) — O GATE PÉTREO (código real):**
```php
$packet = $this->packet($project, $runId);
if (($packet['http_status'] ?? 200) !== 200)          return ['status'=>'blocked', ...];  // :218
if ((string)$packet['runtime_status'] !== 'passed')    return ['status'=>'blocked',
                                                          'blocker'=>'forge_runtime_not_passed'];  // :221
if (empty($packet['evidence_pack_digest']))            return ['status'=>'blocked',
                                                          'blocker'=>'evidence_pack_missing'];     // :224
return $this->decide($project, $packet, 'approved', $options);                            // :228
```
**Três guards inegociáveis: (1) packet válido, (2) runtime forge `passed`, (3)
`evidence_pack_digest` não-vazio (= evidence_refs obrigatório).** Sem evidência → nunca aprova.

`decide` (`:298-334`) delega ao review controller canônico (Fluxo 5) via sub-request,
que aplica o SEGUNDO gate (`completion_claim_allowed` no live execution) e dispara promotion.
Persiste completion history (`atlas_code_forge_completion_history` com `decision_hash` sha256),
`latest_atlas_code_forge_completion_claim`, packet binding.

**`completionClaim` (`:163-209`, schema `atlas.code.forge_completion_claim.v1`):**
`final_allowed = reviewStatus==='approved' && completion_claim_allowed_after_review===true`.
`evidence_pack_verified = !empty(evidence_pack_digest)`.

**`reject`:** só guard de packet, decision `rejected`, promotion `not_requested`.
**`rollback`:** guard `rollback_available` + `promotion_id`; delega ao review controller rollback.

**CLI:** `atlas:code:forge-review` (approve/reject/rollback/packet+claim), guard
`multiple_decision_flags` se >1 flag.

---

## Fluxo 7 — Work Intake

**Achado estrutural:** existem DOIS subsistemas de intake distintos, sem edge direto:

| | **7A · HTTP/CLI operator intake** | **7B · motor canônico Forge** |
|---|---|---|
| Classe | `AtlasCodeForgeWorkIntakeService` | `Forge\ForgeIntakeService` |
| Schema | `atlas.code.forge_work_intake.v1` | `atlas.forge.intake.v1` |
| Persist | `atlas_projects.metadata` (sem tabela) | tabela `ai_forge_intakes` (+ packets, milestones) |
| Model `AiForgeIntake` | NÃO | SIM |

### 7A — HTTP/CLI operator intake
**Triggers:** `GET/POST /atlas-code/works/{project}/forge/intake` →
`AtlasCodeForgeWorkIntakeController::show/store`; CLI `atlas:code:forge-intake`.
`store` valida `objective`/`business_rule`(≤2000), arrays(≤50), `risk_level ∈
{low,medium,high,critical}` → `AtlasCodeForgeWorkIntakeService::save` (`:51-97`).

**Gate `withReadiness` (`:196-228`):** acumula blockers `blocked_no_obra`,
`blocked_missing_objective`, `blocked_missing_business_rule`,
`blocked_missing_acceptance_criteria`, `blocked_missing_canonical_docs`,
`blocked_aedpds_<x>` (do gate AEDPDS via `AtlasExecutionDoctrineGateService`).
`readiness_status = blockers===[] ? 'ready' : 'blocked'`. HTTP 201 se ready, senão 200.
`external_provider_call=false` (`:88`). Persiste `latest_atlas_code_forge_work_intake` +
history (cap 25). Nunca toca `ai_forge_*`.

### 7B — Motor canônico `ForgeIntakeService`
**Triggers (nível de serviço, sem rota HTTP):** `intakeFromPrompt` (`:64-81`),
`intakeFromEscalationPacket` (`:86-128`) — este último é o consumidor do
`escalation_packet.v1` produzido pelo Fluxo 14.

**`persistIntake` (`:133-255`):** guards `guardRecommendedForgeMode`/`guardRiskBand`
(throw se inválido); DoD + required-evidence de `ForgeIntakeCanon`;
`workspaceExecutionGateForIntake` (AWIS `mode:'forge'`); hash canônico
`MissionCanonicalHash::sha256`; `AiForgeIntake::create(...)`.

**Gate `detectIntakeBlocker` (`:417-452`):** `awis_workspace_required_for_forge_intake`
se gate null; `awis_execution_gate_blocked:<reasons>` se não allowed;
`insufficient_prompt_signal:prompt_too_short` (<12 chars);
`insufficient_prompt_signal:no_action_or_object_markers`.
`status = blocker===null ? READY : BLOCKED`.

**`materializeChildren` (`:374-412`):** `ForgeMilestonePlanner` sempre planeja os 5
milestones (mesmo blocked, p/ auditoria). **Se `status===BLOCKED` → retorna ZERO work
packets** (`:385-389`) — nenhum executor pega uma obra bloqueada. Senão
`ForgeWorkPacketComposer::composeForIntake` (packets `status=proposed`, cap 12).

**Persistência:** `ai_forge_intakes` (com `blocker_reason`, `intake_hash`, `evidence_refs`,
`sdd_spec`, `workspace_execution_gate`), `ai_forge_milestones` (5), `ai_forge_work_packets` (0 se blocked).

**Fail-closed:** prompt vazio/mode/band inválidos → throw; intake blocked persiste row
`status=blocked` (auditável) com zero packets (fail-closed real: existe mas é inexecutável).

---

## Fluxo 8 — Provider Invocation GOVERNADA (os ~18 blockers)

**Trigger:** `POST /atlas-code/works/{project}/forge/provider-invocations` →
`AtlasCodeForgeProviderInvocationController::store` (`app/Http/Controllers/AtlasCodeForgeProviderInvocationController.php:30-62`);
CLI `atlas:forge:provider-invoke` (`app/Console/Commands/AtlasForgeProviderInvokeCommand.php`) —
**plan-by-default; execute exige `--confirm-provider-call --confirm-budget --confirm-runtime-dispatch`**.
Serviço: `app/Services/Ai/Programming/AtlasForgeProviderInvocationService.php` (`invoke()` `:170-383`).

Modos: `dry_run` (default) | `execute`. `confirm_provider_call/confirm_budget/
confirm_runtime_dispatch` default false. Timeout default 120s.

### Os 16 blockers canônicos (`canonicalBlockerCodes` `:105-125`) + sequência de gates (`:187-299`)
```
mode_invalid                (:187) modeRaw != dry_run|execute
timeout_invalid             (:190) fora de 1..3600
obra_required               (:195) sem obra_id                       → finalizeBlocked
obra_not_found              (:217) AtlasProject não existe            → finalizeBlocked
runtime_dispatch_required   (:242) resolveDispatchPlan()===null
live_decide_dispatch_required (:255) decision_source != 'live_atlas_decide'
decision_receipt_required   (:258) falta receipt id OU hash
runtime_dispatch_not_allowed (:261) !runtime_dispatch_allowed OU status != dispatch_planned
role_invalid                (:264) dispatch.role != role pedido
awis_execution_gate_required (:267) workspace_execution_gate ausente
awis_execution_gate_blocked  (:269) gate.allowed !== true
provider_capacity_exhausted (:272) dispatch trouxe capacity_exhausted
── EXECUTE-mode gates (:281-299) ──
runtime_dispatch_confirmation_required (:282) !confirm_runtime_dispatch
operator_provider_approval_required    (:285) !confirm_provider_call
budget_approval_required               (:288) !confirm_budget E driver chama provider externo
                                              (atlas-local NÃO bloqueia budget)
provider_driver_missing     (:294) !supports OU !hasRuntimeDriver OU !isConfigured
awis_execution_gate_blocked  ── (também via dispatch)
```
Qualquer blocker → `finalizeBlocked` status `blocked` (HTTP 409). Sem blockers:
- `MODE_DRY_RUN` → `finalizePlanned` status `planned` (HTTP 200): monta `driver_plan`
  (plan-only, nenhum binary spawnado), receipt `atlas.forge.provider_invocation_receipt.v1`.
- `MODE_EXECUTE` (todos verdes) → `finalizeExecuted` (`:531-662`): chama
  `driverRouter->invoke(...)` (`:568`).

### Provider-proof / classificação do resultado executado (`:596-655`)
- **SEC-002 (`:634-643`):** `changed_files != []` mas `provider_called=false` → status
  `failed`, blocker `unattributed_diff` ("exit 0 alone is not proof of authorship").
- timeout → `timed_out`; driver blocker/exit≠0 → `failed`; exceção do driver →
  `failed`/`driver_threw_exception` (`:578-594`).
- Sucesso real → `executed`, `next_action='open_review_gate_for_invocation_output'`.
  Nunca promove completion (`completion_claim_promoted=false` sempre, `:738`).

### Driver Router (providers canônicos → drivers CLI reais)
`app/Services/Ai/Programming/AtlasForgeProviderInvocationDriverRouter.php`.
`CANONICAL_DRIVERS` (`:62-74`): `atlas-local`, `claude_cli`, `codex_cli`, `gemini_cli`,
`antigravity_sdk`, `cursor_sdk`, `cursor_cli`, `claude_codex`, `minimax_m27(_cli)`, `hermes_cli`.
- `supports` = provider canônico; `hasRuntimeDriver` = driver registrado;
  `isConfigured` = driver diz que roda (binary + auth); `callsExternalProvider` =
  provider != `atlas-local`.
- **`atlas-local`** (`invokeAtlasLocal` `:467-505`): executor **determinístico**, plan-only,
  `provider_called=false`, `external_provider_call=false`, exit 0, output sumário hasheado —
  nunca gasta token, nunca chama externo.
- `claude_codex` council → bloqueado no router (`provider_invocation_not_configured`),
  execução via AiGateway dual-review, não CLI.
- Drivers CLI reais estendem `AtlasForgeBaseCliInvocationDriver`
  (`app/Services/Ai/Programming/AtlasForgeBaseCliInvocationDriver.php`): `configured()`
  resolve binary no PATH + auth env; `invoke()` monta argv, valida allowlist, snapshota
  worktree **antes** (SEC-003), roda via safe runner, computa `changed_files` como delta
  before/after (nunca porcelain crua). Ex.: `AtlasForgeCodexCliInvocationDriver` (provider
  `codex_cli`, binary `codex`, auth `OPENAI_API_KEY`/`CODEX_API_KEY`, argv
  `codex exec --skip-git-repo-check --model X --sandbox read-only -c model_reasoning_effort -`).

### Allowlist + Safe Runner
- `AtlasForgeProviderCommandAllowlistService` (`app/Services/Ai/Programming/AtlasForgeProviderCommandAllowlistService.php`):
  `ALLOWED_BINARIES = [claude, claude-code, codex, gemini, cursor-agent]`;
  `FORBIDDEN_BINARIES` (rm, curl, wget, ssh, sudo, bash, python, node, php...);
  bloqueia metacaracteres de shell (`| ; & ` $ < >`), subshells, `..` traversal.
  **Nunca transforma string em comando shell — argv array explícito.**
- `AtlasForgeProviderProcessRunner` (`app/Services/Ai/Programming/AtlasForgeProviderProcessRunner.php`):
  `Symfony\Process` com argv array (sem shell parsing); prompt via **stdin**; timeout
  mata o processo (`status=timed_out`); sha256 de stdout/stderr; **redação de secrets**
  (`sk-*`, `api_key`, `bearer`, `authorization`) antes do excerpt; cap de output.

### Persistência / efeitos (Fluxo 8)
Evidence Ledger (`recordEvent`, best-effort try/catch), receipt canônico,
`latest_atlas_forge_provider_invocation`(+receipt) + histories (cap 25) em
`AtlasProject.metadata`.

### Fail-closed (Fluxo 8)
`provider_driver_missing` bloqueia sem stdout fake; `atlas-local` determinístico não gasta
token; `budget_approval_required` só quando driver chamaria externo; provider-proof rejeita
diff não-atribuído; nenhum caminho seta `completion_claim_promoted=true`.

---

## Fluxo 9 — Provider Topology & Fallback (read-model)

**Triggers:**
- `GET .../forge/provider-topology` → `AtlasCodeForgeProviderTopologyController::show`
- `GET .../forge/continuum-certification` → `::certification`
- CLI `atlas:forge:provider-topology` (sem obra → sempre `blocked_obra_required`).

Serviço: `app/Services/Ai/Programming/AtlasForgeProviderTopologyService.php` (`topology()` `:75-337`).
**Read-model puro** (`external_provider_call=false`, `is_read_model=true`).

**Cadeia:** resolve obra → `receiptTopology` (precedência: decision_receipt do options →
`metadata.latest_atlas_forge_provider_topology` → `...decision_receipt`); se achou receipt
live, `decision_source='live_atlas_decide'`, senão `static_policy` (dispatch impossível).
5 roles canônicas (`primary_builder` claude_cli selecionado, `critical_reviewer` codex_cli,
`context_scout` gemini_cli, `repair_agent` claude_cli, `local_tool_runner` atlas-local) +
fallback chain de 3 entradas. **Overlay de capacidade** (Fluxo 10): rebaixa roles cujo
provider está `unavailable`/`degraded`.

**Gate fail-closed (`:318`):** `runtime_dispatch_allowed = runtimeDispatchAllowed && blockers===[]`
— **ambos** têm que valer; qualquer blocker força false (gate by-construction).
Ladder: sem obra → todas roles `blocked` (409 `blocked_obra_required`); capacity exhausted →
409; simulate_failure → classificação (block / reroute com child receipt / retry_later).

**5 validadores de topologia** (`app/Services/Ai/Programming/ForgeTopology/`, chamados em
`:281-296`, **observe-only** — envolvidos em `observedValidation` que engole Throwable,
nunca mutam status/blockers):
- `ForgeTopologyRoleCoverageValidator`: `missing_canonical_role`, `duplicate_role_assignment`,
  `no_selected_primary_builder`, `unknown_role_present`.
- `ForgeTopologyFallbackChainCoherenceValidator`: `empty_fallback_chain`,
  `duplicate_order_value`, `non_contiguous_order`, `duplicate_role_in_chain`,
  `no_capable_fallback`.
- `ForgeTopologyCapacityAlignmentValidator`: `role_provider_absent_from_capacity`,
  `selected_role_on_exhausted_capacity`.
- `ForgeTopologyRoleRedundancyValidator`: `primary_builder_missing`, `critical_reviewer_missing`,
  `reviewer_equals_builder_provider` (review sem redundância real).
- `ForgeFallbackCapableEntryDetector`: **dead-wired** (`@unwired-until 2026-08-05`, sem consumidor).

**Fallback policy** (`AtlasForgeProviderFallbackPolicyService::classify` `:128-242`):
tabela failure→action (rate_limit/quota/context/model_unavailable/insufficient → `reroute`;
timeout/provider_error → `retry_later`; auth_failed/capacity_exhausted → `block`).
`pickFallback` varre chain (pula `capable=false` e a tupla que falhou). Sem candidato capaz →
`block` reescrito para `provider_capacity_exhausted`. **Nunca silencioso**: emite evidence
event com `silent=false`, `reduces_quality_gates=false`, `bypasses_review_completion_gate=false`,
`auto_completes_work=false`. Grava failure memory (best-effort).

---

## Fluxo 10 — Provider Capacity + Failure Memory

**Triggers:**
- `GET /atlas-code/forge/provider-capacity` → `global` (sem obra).
- `GET .../works/{project}/forge/provider-capacity` → `show`.
- `POST .../works/{project}/forge/provider-failures` → `recordFailure`.
- CLI `atlas:forge:provider-capacity`, `atlas:forge:provider-failure-record`.

**`AtlasForgeProviderCapacityService::snapshot` (`:86-174`):** read-model de **sinais
LOCAIS apenas** sobre 5 runtimes (`claude_cli, codex_cli, gemini_cli, claude_codex,
atlas-local`). Nunca chama provider. Sinais: failure memory (metadata), `AiProviderHealthSnapshot`,
`AiWorkerEvent` (24h). `deriveStatus` (`:511-556`) é fail-closed: sem config/runtime →
`unavailable`; auth faltando → `unavailable`; capacity/quota exhausted → `unavailable`;
limited/cooldown → `degraded`; **sinal ausente → `unknown` (nunca otimista `available`)**.
`runtime_dispatch_allowed = availableCount>0 && !provider_capacity_exhausted`. Top status
`blocked` quando 0 available e 0 degraded.

**`recordFailure`:** `provider` null → 422; `failure_type` fora de `KNOWN_FAILURES` → 422;
sucesso → 201. Delega a `AtlasForgeProviderFailureMemoryService::record` (`:80-162`):
valida failure_type, `evidence_hash` sha256, **dedupe** (mesma tupla em 60s pula append),
cap `MAX_EVENTS=50`, persiste `project.metadata.atlas_forge_provider_failure_memory`,
ledger side-effect `PROVIDER_FAILURE_RECORDED` (best-effort). Cooldowns por failure
(rate_limit 60s ... capacity_exhausted 900s).

**Fail-closed:** sinais ausentes → `unknown`; all-unavailable → top `blocked` +
`runtime_dispatch_allowed=false`; recordFailure 422/500 conforme erro.

---

## Fluxo 11 — Runtime Dispatch

**Triggers:**
- `POST .../forge/runtime-dispatch` → `AtlasCodeForgeRuntimeDispatchController::store`
  (validação restringe `execution_mode` a **só** `prepare_dispatch_plan` — não há modo
  "executar de verdade" aqui).
- `GET .../forge/runtime-dispatch` → `show` (lê `latest_atlas_forge_runtime_dispatch`).
- CLI `atlas:forge:runtime-dispatch`.

Serviço: `app/Services/Ai/Programming/AtlasForgeRuntimeDispatchService.php` (`dispatch()` `:141-356`).
**Nunca chama provider, nunca gasta token, nunca promove completion.**

**Ladder de gates (ordem importa):** `obra_required` → `obra_not_found` →
`awis_execution_gate_blocked` (AWIS) → `workspace_handoff_pack_blocked` →
artifact-agent-packet blockers (workspace_mismatch / route_not_forge / raw_conversation /
body_included) → carrega topology (Fluxo 9) → `live_decide_receipt_required` (decision_source
null ou static_policy) → `decision_receipt_required` (falta id/hash) → herda blockers da
topology → `role_invalid`/`role_missing_provider_or_model` → `runtime_dispatch_not_allowed`.

**Simulate failure** (`:257-322`): `fallbackPolicy->classify` → `ACTION_BLOCK`
(capacity_exhausted) | `ACTION_REROUTE` (exige `--create-child-receipt` → `createChildReceipt`
via `AtlasDecideService::operationalDecision` gera **child Decision Receipt** real, recarrega
topology; senão `fallback_child_receipt_required`).

**Status resolution (`:324-332`):** `provider_capacity_exhausted` | `fallback_child_receipt_required`
(único blocker) | `blocked` | `dispatch_planned` (zero blockers).
`runtime_dispatch_allowed = (status===dispatch_planned)` (`:529`) — by-construction.

**Persistência:** `latest_atlas_forge_runtime_dispatch` (plano completo) +
`atlas_forge_runtime_dispatch_history` (cap 25); child receipts em
`atlas_forge_child_decision_receipt(_history)`. HTTP: `dispatch_planned`→201, resto→409.

**Fail-closed:** sem Decision Receipt live (static_policy) → cadeia
`live_decide_receipt_required`+`decision_receipt_required`+`runtime_dispatch_not_allowed`;
reroute sem child receipt → 409; capacity exhausted → 409 terminal.

### 11b. Certificações (dois serviços distintos)
- **`AtlasForgeRuntimeCertificationService`** (CLI `atlas:forge:runtime-certify`):
  prova a cadeia Atlas Code→Obra→Forge→programming.forge→Decide→Governance→Evidence via 5
  stages; `external_rivals_status=blocked_requires_operator_approval` (Rivals isolado).
- **`AtlasForgeContinuumCertificationService`** (CLI `atlas:forge:continuum-certify` +
  endpoint `continuum-certification`): avalia ~38 invariantes por class_exists/is_file/
  fileContains; `verifyNoSilentFallback` (`:401-418`) **prova o contrato no-silent
  executando** `classify(rate_limit)` e assertando `event.silent===false`.

---

## Fluxo 12 — Work Packet Execution Cycle

**Achado:** duas implementações. A que tem o gate `complete()` (evidence + gate passed) é
o **Service** (12A).

### 12A — `ForgeWorkPacketExecutionCycleService` (persiste rows de ciclo)
`app/Services/Ai/Programming/Forge/ForgeWorkPacketExecutionCycleService.php`.

- **`selectPacket` (`:90-147`):** packets `∈ {proposed, ready, claimed}` por
  `packet_position`; exclui os com blocker de escopo `packet` não resolvido; prefere
  `state.active_work_packets`.
- **`planExecution` (`:167-230`):** puro (sem DB). Mode default `safe_simulation`; inválido →
  `invalidMode`. Deriva `expected_artifacts`, `evidence_kinds_required`, capacidades nativas.
- **`startCycle` (`:237-292`):** persiste `AiForgeWorkPacketExecutionCycle` (`status=running`
  ou `blocked`), `cycle_hash`, materializa multi-agent schedule + workcell route.
- **`complete` (`:310-386`) — O GATE (código real):**
  ```php
  guardNotTerminal($cycle);                                             // :316 (throw se já terminal)
  if ($cycle->execution_mode === MODE_BLOCKED) throw cycleAlreadyTerminal; // :318
  $cleanEvidence = array_filter($evidenceRefs, fn($r)=> is_array($r)
      && isset($r['kind']) && (string)$r['kind'] !== '');
  if ($cleanEvidence === []) throw completionWithoutEvidence($cycle->uuid);  // :322-328
  if (!isset($gateResult['gates']) && !isset($gateResult['all_passed']))
      throw completionWithoutGateResult($cycle->uuid);                       // :329-331
  // precisa de >=1 gate status='passed' OU all_passed===true:
  if (!$anyPassed && ($gateResult['all_passed'] ?? false) !== true)
      throw completionWithoutPassedGate($cycle->uuid);                       // :332-341
  // Sovereign floor (só em ENFORCE): AtlasDevGateAdapter::certify(...TrustLevel::Forge);
  //   promoted !== true → block($cycle,'sovereign_engineering_gate_not_promoted')  // :343-351
  ```
  **Exige evidence_refs (com `kind`) + gate_result presente + ao menos um gate `passed`.**
  Sucesso: `status=success`, flip do packet para `done` (`:373-375`),
  `longHorizon->recordCycle`, outcome memory, O-1 learning bridge (fail-open).
- **`fail` (`:451-527`):** `status=failed`, monta `repair_hook` (failure intelligence),
  registra em `FailureBrainCorpus`; **NÃO** flipa o packet (só o ciclo é terminal).
- **`block` (`:534-568`):** `status=blocked`, registra blocker de escopo packet no state.

**Persistência:** `ai_forge_work_packet_execution_cycles`; flip `ai_forge_work_packets.status=done`;
mutações de `ai_forge_long_horizon_states` (recordCycle); schedules/workcell rows; outcome memory.

### 12B — `Execution/ForgeWorkPacketExecutionCycle::run()` (single-pass, sem row de ciclo)
Orquestrador que dirige select→plan→mode→evidence→gate→outcome→repair→state e retorna
payload `atlas.forge.work_packet_execution_cycle.v1`. Fail-closed pré-checks:
`obra_already_completed`, `intake_blocked:<reason>`, `no_eligible_work_packet`,
`packet_already_done`. **Invariante:** execução `real` exige `allow_real=true` E
`work_packet_receipts` do operador; senão downgrade para `safe_simulation` (que sintetiza
receipts tagueados `source: safe_simulation` — nunca confundidos com run real).
`packetRequiresRealRun` (required contém `real_execution_only` OU risk_band=critical) em
safe_simulation → gate `inconclusive`. Flip: succeeded→`done`, failed/inconclusive→`claimed`,
blocked→`blocked`.

**Ciclos de vida:** packet `proposed→ready→claimed→done|blocked`; ciclo
`planned/running→success|failed|blocked` (terminal guardado por `guardNotTerminal`,
cada write recomputa `cycle_hash`). `ForgeLongHorizonStateService::recordCycle` flipa o state
para `blocked` se houver blocker não resolvido, senão `active`.

---

## Fluxo 13 — Dev→Forge Promotion (produz o escalation_packet)

**Nota:** este subsistema é o **PRODUTOR** do contrato `atlas.dev_to_forge.escalation_packet.v1`
(quem o consome é o Fluxo 7B).

**Triggers** (`app/Http/Controllers/AtlasCodeDevToForgePromotionController.php`):
- `GET /atlas-code/dev-to-forge/threads/{thread}/promotion-preview` → `preview` (read-only).
- `POST .../threads/{thread}/promote` → `promote`.
- `GET .../candidates` → `index`; `GET .../candidates/{candidate}` → `show`;
  `POST .../candidates/{candidate}/dismiss` → `dismiss`.

Serviço: `app/Services/AtlasCode/DevToForgePromotionService.php`.

**`preview`:** `previewForThread` carrega thread/mensagens/traces →
`PromotionSignalDetector::analyse` (heurístico 7-eixos: message_density, context_length,
file_breadth, architecture, risk, recurring_failure, operator_request). Score→target:
`>=7 forge_obra`, `>=4 obra_candidate`, `>=2 quick_intervention`, senão `none` (veto
`thin_small_bug`). Read-only, não persiste.

**`promote` (`:119-215`):** valida `promotion_target ∈ {quick_intervention, obra_candidate,
forge_obra}`. Reconstrói preview, merge cauteloso de 8 overrides allow-list
(**`workspace_slug` NUNCA mutável**). Idempotência por thread
(`findActiveCandidateForThread`). **Cria Obra (`AtlasProject::create`) SÓ para `forge_obra`
E !alreadyHasObra** (`:180-204`) — invariante "NUNCA auto-cria Obra" (quick_intervention e
obra_candidate não criam). Emite `escalation_packet_v1` (`attachCanonicalEscalationPacket`
`:227-308`, só forge_obra/obra_candidate; `EscalationPacket::issue` com invariantes
fail-closed: intent verbatim, promotion_reason obrigatório, ≥1 trigger, ≥1 work packet,
hash determinístico; fail-soft se lançar). Grava `route_decision.v1`
(route=`dev_to_forge`) em `ai_dual_core_route_decisions` (fail-soft).

**Persistência:** candidatos em **filesystem JSON**
(`storage/app/atlas-code/promotion-candidates/{workspace}/{id}.json`, schema
`atlas.code.dev_to_forge.promotion_candidate.v1`); Obra em `atlas_projects`; route decision
em `ai_dual_core_route_decisions`; escalation packet embutido no JSON do candidato.

**Fail-closed / fail-soft:** preview→404, promote→422 (exception → erro HTTP, nada persiste);
target inválido; thread ausente; id perigoso em persist. **Fail-soft** (não derruba a
promoção): `escalation_packet_v1_error`, `route_decision_v1.recorded=false`. **Ponto de
auditoria:** a Obra é criada ANTES do packet/route_decision (fail-soft), então uma Obra pode
nascer sem packet válido — aditivo, intencional. **Segurança:** grupo `atlas-code` sem
middleware de auth nas rotas de mutação.

**Bridge alternativo:** `candidateFromRunEscalation` (SeniorLoop → candidato,
`promotion_target` SEMPRE `obra_candidate`, fail-open best-effort).

**Mecanismo 4 (SEPARADO):** `ForgeIntakeRouteDecisionRecorder` (route=`forge`) grava route
decision ANTES do dispatch em `POST .../forge/live-executions[/async]` — é execução
governada HTTP-direct, não promoção.

---
## Fluxo 14 — Rivals Arena (benchmark de avaliação, isolado do Forge)

**Contexto/governança:** Rivals é o benchmark model-vs-model + Atlas-uplift, marcado
como **DEPRECATED/OFF por decisão do operador** (`config/atlas_rivals.php:49-56`:
avaliação agora é per-delivery, não head-to-head; runtime off-switch
`ATLAS_ALLOW_RIVALS_PROGRAMMATIC=false`; `enabled` default false `:19`;
`provider_spend_allowed` default false `:22`). O código-default fica vivo só para os
testes legados não ficarem vermelhos. Em toda a stack, **Rivals é `separated_from =
external_rivals_certification`** — nunca faz parte da entrega governada do Forge.

**Achado:** existem TRÊS subsistemas Rivals distintos:

### 14A — Rivals Arena core (`atlas:rivals`, produto "Rivals 2.0")
`app/Services/Ai/Rivals/**`, entrypoint `app/Console/Commands/AtlasRivalsCommand.php`
(alias `atlas:rivals2`). Pipeline 100% file-backed sob `storage/atlas/rivals`
(`RunPaths::root()`). Ações (`match` `:49-87`): `doctor, benchmarks, benchmark-smoke,
run, models, arms, mine, import-cases, import-results, plan, run-fake, run-bench, verify,
adjudicate, report, report-all, uplift, ledger`. Exit FAILURE se `status==error` OU
`verified===false` OU `verdict==='invalid'` (`:89-99`).

**Pipeline canônico:** `mine` (`AtlasBenchSuiteAdapter::mineCases` — só commits que tocam
`tests/*.php` E `app/*.php`, pisos sênior de diff, captura symptom rodando o teste oculto,
**ContaminationGuard dropa o case se violar**) → `plan` (`RunPlan::make`, `plan.json`) →
`run/run-fake/run-bench` (adapters; suites externas retornam `suite_runs_externally`) →
`verify` (`ReplayVerifier`) → `adjudicate` (`Adjudicator`) → `report` → `uplift`
(`AtlasUpliftRunner`) → `ledger` (`ResultLedger`).

**Gates fail-closed (código real):**
- **`Adjudicator`** (`app/Services/Ai/Rivals/Core/Adjudicator.php:19-124`): **`claim_allowed
  = ($verdict === 'valid')` é o default pétreo** (`:103`), e `verdict='valid'` só se
  `$blockers===[]` (`:94`). Blockers: `missing_receipt:*` (1 receipt por case×arm×rep),
  `repetitions_below_min` (default 3), custo/tempo não-numéricos, `judge_config_mismatch`
  (judge pinado), `evidence_incomplete:*`, `replay_failed:*` (re-verifica replay AGORA, não
  confia no verify antigo). `claim_scope` null sem plano completo — **scoped-only, nunca "best overall"**.
- **`ReplayVerifier`** (`ReplayVerifier.php:14-49`): recomputa sha256 de `plan.json`,
  `receipts.jsonl` e cada artifact; **qualquer drift de 1 byte → `verified=false`**.
- **`ContaminationGuard`** (`ContaminationGuard.php:14-65`): case é prova só se fresh +
  snapshot-pinado + hidden-proof + recipe-free. Violações: `repo_snapshot_missing`,
  `no_hidden_check`, `case_stale:{age}>{max}` (default 30 dias), leaks de recipe/symptom.
  Re-auditado em TODO `listCases()` — corpus velho cai silenciosamente.
- **`EvidencePackBuilder`** (`EvidencePackBuilder.php:15-66`): cada campo é
  `{present:true, sha256}` ou `{present:false, reason_missing}` — nunca inventado.
- **`ResultLedger`** (`ResultLedger.php`): append-only hash chain sob `flock(LOCK_EX)`;
  `entry_hash`/`prev_hash`; `verifyChain` detecta `chain_broken_prev_hash`, `entry_hash_mismatch`.
- **Provider-spend gate:** `AtlasBenchSuiteAdapter::runCliArm` (`:408-441`): provider não-local
  + `provider_spend_allowed !== true` → `atlasbench_provider_spend_not_allowed` (flag default false).
- **`AtlasUpliftRunner::compare`** (bare vs atlas, `AtlasUpliftRunner.php:16-77`): leitor puro
  agrupando `model@bare` vs `model@atlas_dev`; **se qualquer braço sem receipts →
  `uplift_supported=false`, `claim_allowed=false`, `arm_did_not_run:*` — NUNCA simula**;
  uplift herda o `claim_allowed` da adjudicação. `runtime_commands` default null →
  uplift honestamente bloqueado até ter wrapper.
- **`DifficultyCalibrator`** (anti-Goodhart na SUITE): `>35% success no braço bare →
  `suite_too_easy_for:*` (sucesso alto = suite fácil/contaminada, não vitória).
- **PISO PÉTREO anti-wipe:** solver roda em **git worktree isolado detached**;
  `test_tampering_detected` se tocar testes ocultos; `provisionVendor` clona via APFS
  copy-on-write (nunca symlink → não reescreve autoload vivo); `provisionDatabaseFloor`
  pina `DB=sqlite :memory:`, `ATLAS_ALLOW_LIVE_DB_TESTS=0` (protege pgsql vivo).

**8 adapters externos** (`Adapters/External/`, estendem `AbstractExternalSuiteAdapter`):
`SweBenchLive, SeniorSweBench, AiderBench, LiveCodeBench, InspectEvals, HalHarness,
HarborTerminalBench, Tau2Bfcl`. Cases só existem se importados (nunca inventados);
comandos documentados, não executados; `ingestResults` hash-pina o JSON nativo e
fail-closa em campo faltante. `benchmark-smoke` clona/instala/smoke os 9 repos em
`.atlas-venv` isolado — status `running` só com receipt exit-0 real, senão `blocked`.

### 14B — Forge-Native Rivals (braço Atlas OBRIGATORIAMENTE via Forge)
`app/Services/Ai/Programming/AtlasRivals*` + `AtlasForgeNativeRivals*`. Canon: **o braço
Atlas TEM que rodar através do Forge; um run Atlas não-Forge é inválido para scoring**
(`AtlasForgeNativeRivalsProtocolService.php:22-124`).

- **Entrypoint:** `atlas:engineering:benchmark:claude-fair`
  (`AtlasEngineeringBenchmarkFairCommand.php`) — guards antes de dispatch:
  `guardProviderExecutionConfirmation` + `guardProviderExecutionPreflight` + model-lock
  Fair (só opus/sonnet). Flags `--confirm-runbook-reviewed`, `--confirm-provider-cost`,
  `--confirm-invalid-battery-quarantine`.
- **`AtlasRivalsRunOrchestrator::run`** (`:81-259`, modos DRY_RUN|FAKE_PROVIDER|REAL_PROVIDER):
  fingerprint gate (`blocked_fingerprint_mismatch`) → preflight (`blocked_preflight`) →
  dry-run. **`MODE_REAL_PROVIDER` é RECUSADO dentro do orquestrador →
  `real_provider_requires_operator_runbook`** (só a Fair command governa dispatch real).
  FAKE: snapshot → subprocess com heartbeat (`stalled_runner_no_heartbeat`) →
  `invalid_dirty_after_run` / `invalid_missing_evidence`. `finalize` sempre `claim_ready=false`,
  `external_provider_call=false`.
- **Preflight** (`AtlasForgeNativeRivalsPreflightService.php`): workspace git+limpo+sem
  `.pyc` trackado; baseline separado; forge runtime/commands existem; docs canônicos;
  manifest válido com **Atlas arm runtime == forge**; approval do operador
  (`provider_cost_approved` + `runbook_reviewed`).
- **Case manifest** (`AtlasForgeNativeRivalsCaseManifestService`): `atlas_not_forge`,
  `atlas_arm_command_template_not_forge` (template tem que conter `atlas:code:forge-fast-path`),
  `rival_arm_not_isolated_from_atlas_workspace`. Só suite `atlas-fair-claude-v1` registrada.
- **Evidence pack + verifier** (`AtlasRivalsEvidencePackService/VerifierService`): present=true
  exige source + hash 64-hex; blockers `provider_call_flag_not_false`,
  `claim_ready_flag_not_false`, `synthetic_scores_flag_not_false`,
  `dirty_workspace_after_run`, `replay_manifest_not_promoted_to_executed`.
- **`AtlasRivalsBatteryStateMachine`** (read-model, nunca dispara): `decideState` — colapso
  de serviço vence tudo (`blocked_provider_unavailable`); dry-run passou mas falta 1 das 3
  confirmações → `blocked_operator_confirmation_required`; só all-green → `ready_for_real_provider`.
- **Invalid-battery triage** (`AtlasRivalsInvalidBatteryTriageRegistry`): bateria inválida
  vira `pending_triage` (runner recusa dispatch) até `triaged_quarantined` (quarentena sem
  admitir o score). **`AtlasRivalsTestWorktreeProvisioner`**: recusa se source dirty;
  `reset` exige `reason` humano; `removeWorktree` compara `realpath` e retorna
  `refused_would_delete_source`.

### 14C — DB-backed rivals shadow (SovereignHonestyFloor)
Models `AiRealExecutionRivalsBenchmark`, `AiRivalsShadowRun`, `AiRealExecutionForgeHandoff`
(tabelas `ai_real_execution_rivals_benchmarks`, `ai_rivals_shadow_runs`,
`ai_real_execution_forge_handoffs`). Escritos por
`AtlasRealEngineeringExecutionKernelService`: benchmark nasce `false_claim_blocked=true`,
`comparison_protocol.external_provider_call=false, claim_allowed=false,
requires_real_rerun_for_claim=true`. Só um **import de run externo real com
`claim_allowed=true`** flipa `false_claim_blocked` para false. A certificação do kernel
só é honesta se o último benchmark tem `external_provider_call===false &&
claim_allowed===false` (`:784-793`) — codifica "um stub não certifica verde".

**Invariantes cross-Rivals:** (1) `claim_allowed=false`/`false_claim_blocked=true` é o
default pétreo; (2) nenhum adapter se auto-julga (o core Rivals é o único juiz;
orquestrador Forge recusa dispatch real); (3) sem provider-spend sem flags explícitas;
(4) dado faltante é declarado (`{present:false, reason_missing}`), nunca inventado;
(5) sem score agregado "vencedor" — RealityScoreCard é vetor, ReportBuilder nunca emite
best-overall, rubrica one-shot é diagnóstica e invalidada por hard-fail.

---

## Apêndice A — Persistência consolidada

| Store | Fluxos | Conteúdo |
|---|---|---|
| `ai_forge_intakes` | 7B | intake canônico (`atlas.forge.intake.v1`), blocker_reason, hashes |
| `ai_forge_work_packets` | 7B, 12 | packets (`proposed→ready→claimed→done\|blocked`) |
| `ai_forge_work_packet_execution_cycles` | 12A | ciclos (`planned/running→success\|failed\|blocked`) |
| `ai_forge_long_horizon_states` | 12 | estado de obra longa, milestones, blockers |
| `ai_forge_milestones` / `..._multi_agent_schedules` / `..._outcome_memories` | 7B, 12 | planos/schedules/memória |
| `atlas_projects.metadata` (JSON) | 1,2,4,5,6,7A,8,9,10,11 | read-models + histories (fast-path, live exec, invocation, dispatch, topology, capacity, review) |
| `atlas_engineering_runs` / `atlas_engineering_evidence` | 1,2,3,5 | runs + evidence de execução/review/promotion |
| `atlas_ledger_events` (Evidence Ledger) | 1,2,8,10 | eventos de auditoria |
| `ai_dual_core_route_decisions` | 1,2,13 | route decisions (`atlas.dual_core.route_decision.v1`) |
| `atlas_projects` | 13 | Obra criada na promoção forge_obra |
| filesystem `storage/app/atlas-code/promotion-candidates/**` | 13 | candidatos Dev→Forge (JSON) |
| filesystem `storage/app/forge-governed-exec-artifacts/**`, `forge-governed-promotions/**` | 3,5 | patch artifacts + rollback backups |
| filesystem `storage/atlas/rivals/**`, `storage/app/rivals-forge-*/**` | 14A,14B | runs/ledger/evidence packs Rivals |
| `ai_real_execution_rivals_benchmarks` / `ai_rivals_shadow_runs` / `ai_real_execution_forge_handoffs` | 14C | shadow benchmarks (false_claim_blocked) |

## Apêndice B — Onde vive cada gate pétreo (resumo file:line)

- **18 blockers da provider invocation:** `AtlasForgeProviderInvocationService.php:187-299`;
  provider-proof SEC-002 `:634-643`; drivers reais SEC-003 (delta before/after)
  `AtlasForgeBaseCliInvocationDriver.php:154-189`.
- **Allowlist CLI:** `AtlasForgeProviderCommandAllowlistService.php:46-63` (allowed/forbidden).
- **Safe runner (argv-only, redação, timeout):** `AtlasForgeProviderProcessRunner.php:51-125,181-199`.
- **Gate de dispatch (by-construction):** `AtlasForgeRuntimeDispatchService.php:230-332,529`.
- **Gate de topology (`allowed && blockers===[]`):** `AtlasForgeProviderTopologyService.php:318`.
- **Capacity fail-closed (sinal ausente → unknown):** `AtlasForgeProviderCapacityService.php:511-556`.
- **Review completion gate (evidence_pack + runtime passed):** `AtlasCodeForgeReviewCompletionService.php:218-226`;
  segundo gate `AtlasCodeForgeReviewController.php:151-186`.
- **complete() do work-packet (evidence_refs + gate passed):**
  `ForgeWorkPacketExecutionCycleService.php:318-351`.
- **Promotion drift/hash + rollback:** `AtlasForgeGovernedPromotionService.php:102-139,196-235`.
- **Adjudicator claim_allowed=false default:** `app/Services/Ai/Rivals/Core/Adjudicator.php:94,103`.
- **Invocation `execute` só com 3 confirmações + driver configurado:**
  `AtlasForgeProviderInvocationService.php:281-299`.


---

# PARTE IV — Autônomo / Loop de Evolução (ACDE) · Fluxos detalhados

## Autônomo / Loop de Evolução (ACDE) — Todos os Fluxos

> **Método:** código é a verdade; docstrings/comentários foram cruzados com o código executável e com o `git log`. Toda afirmação carrega `arquivo:linha`. Onde o código contradiz a narrativa (inclusive a do próprio briefing desta análise), a contradição está documentada explicitamente — ver **§0.3 (os DOIS caminhos de merge)** e **§14 (scaffolding CodexRealInvoker)**.
>
> Escopo medido: `app/Services/Ai/AutonomousEvolution/` = **878 arquivos**; `app/Services/Ai/SelfConstruction/` = **1.159 arquivos**; superfície de comandos `atlas:loop:*` = **332 strings de comando** distintas; **21 schedules** de loop em `routes/console.php`.

---

### §0 · Mapa executivo e a verdade honesta sobre "delivery"

#### 0.1 O que o subsistema É
Um **campaign supervisor 24/7** (`AtlasLoopCampaignSupervisor`) que se auto-alimenta de trabalho, mói tarefas em modo **propose-only** (explora N cenários por task, o **frozen judge** escolhe o melhor, certifica e persiste uma *proposta* em `atlas_loop_proposals` — NUNCA mergeia), e um **drain separado** (`AtlasLoopAutoMergeService::drain`, agendado a cada 15 min) que re-prova cada proposta certificada e a **commita em main de verdade**. À volta disso há ~20 cadências de gates/observabilidade/calibração e uma malha densa de guards anti-Goodhart.

#### 0.2 As duas metades do ciclo (correto)
- **Metade PROPOSE-ONLY** (`atlas:loop:evolve`, `atlas:loop:campaign`, `AtlasEvolutionLoopRunner::run`): produz propostas `status=certified_for_review`, `merged_to_main=false`. Invariante duro afirmado no runner (`AtlasEvolutionLoopRunner.php:94-95` → `'merged_to_main' => false`).
- **Metade DRAIN-TO-MAIN** (`atlas:loop:automerge` → `AtlasLoopAutoMergeService::drain`): fecha o ciclo commitando em main sob gates. É REAL e está WIRED + AGENDADA (`routes/console.php:139`).

#### 0.3 HONESTIDADE CRÍTICA — existem DOIS caminhos distintos de escrita em main, e não devem ser confundidos

O briefing afirmou que os **47 commits `atlas-task land-*`** provam merges autônomos do drain. **O código refuta essa atribuição.** São dois mecanismos diferentes:

| | **Caminho A — Drain ACDE** | **Caminho B — `atlas:land` (os 47 commits)** |
|---|---|---|
| Origem | `AtlasLoopAutoMergeService::drain()` | `AtlasLandCommand` (`atlas:land`) → `AtlasTaskScopedCommitter::commitScope()` |
| Subsistema | `AutonomousEvolution` (o Loop ACDE) | `SelfConstruction` / Agent Control Plane |
| Autor do commit | **`atlas-loop <loop@atlas>`** (setado em `AtlasLoopAutoMergeService.php:652`: `-c user.email=loop@atlas -c user.name=atlas-loop`) | git config ambiente = **`Vitor Freire <vitordsny@gmail.com>`** (o committer NÃO seta `-c user.*`) |
| Mensagem | `atlas loop auto-merge: <path> [<hash12>]` (`:650`) | `atlas-task <taskid>: <summary>` (`AtlasTaskScopedCommitter.php:107`), taskid default `land-<ulid>` (`AtlasLandCommand.php:58`) |
| Provado no `git log` deste checkout | **ZERO commits.** `git log --all --author=loop@atlas` → vazio; `git log --grep='atlas loop auto-merge'` → vazio | **47 commits** `atlas-task land-*`, todos autor `Vitor Freire` |

**Conclusão honesta:** os 47 `atlas-task land-*` são commits da porta de sessão de agente/operador (`atlas:land`), do subsistema SelfConstruction — **não** do drain do Loop ACDE. O drain ACDE é um caminho totalmente construído, agendado e gated, mas **não há evidência no histórico git deste checkout de que ele já tenha commitado** (ver §0.4). Isso não invalida o drain — invalida a *atribuição* dos 47 commits a ele.

#### 0.4 Por que o drain não tem commits neste checkout
O `.env` não está presente neste checkout (`grep ATLAS_LOOP_MASTER_ENABLED .env` → arquivo ausente). O **master switch é fail-closed** (`AtlasLoopMasterSwitch::enabled()` retorna `false` quando o `.env` não é legível — `AtlasLoopMasterSwitch.php:42-48`), e o schedule do automerge exige `MasterSwitch::enabled() && config('atlas.ai.loop.auto_merge_to_main')` (`routes/console.php:145-146`), sendo `auto_merge_to_main` default `false` (`config/atlas.php:971`). Ou seja: **neste ambiente o Loop inteiro está desligado por construção.** Os comentários no código (`AtlasLoopAutoMergeService.php:44`) dizem que o operador ligou a porta em 12/06 no ambiente de produção; aqui ela está OFF.

**Veredito de delivery:** o mecanismo de merge-para-main é REAL, completo e rigorosamente gated (§9). Se ele "roda em produção" é um fato operacional que o histórico deste checkout **não** comprova; o que este checkout comprova é o *maquinário* e uma cadência de landing de sessões de agente (Caminho B).

---

### §1 · Entrypoints 24/7 — todas as cadências `atlas:loop:*` (`routes/console.php`)

Todas usam `->withoutOverlapping()` (mutex; expiry curto onde independência 24h exige auto-destravamento). "§0 MS" = gated por `AtlasLoopMasterSwitch::enabled()` (defense-in-depth: o comando também no-opa sozinho).

| Linha | Comando | Cadência | Guard/flag | O que faz |
|---|---|---|---|---|
| `139` | `atlas:loop:automerge --limit=10` | `everyFifteenMinutes`, `withoutOverlapping(10)` | **§0 MS** + `atlas.ai.loop.auto_merge_to_main` (def **false**) | **O drain que fecha o ciclo** — dreta propostas certificadas p/ main (§9). Log em `storage/logs/loop-automerge.log`. |
| `152` | `atlas:loop:coverage-gaps --hours=24 --feed` | `everyThirtyMinutes`, `wo(20)` | **§0 MS** + `atlas.loop.characterization_test_lane_enabled` (def false) | Alimenta 1 task `characterization_test` por gap real de cobertura ao supervisor vivo; pré-valida que o gap é real na árvore atual. |
| `261` | `atlas:loop:loss-observer` | `dailyAt 05:20` | `atlas.loop.loss_observer.enabled` (def true) | Autópsia do ledger: razões/gates dominantes de rejeição → backlog intents dedupados. |
| `268` | `atlas:loop:backlog-feed` | `dailyAt 05:25` | **§0 MS** + `atlas.loop.backlog_auto_feed.enabled` | Converte loss-observer + corpus de falhas + residuais de campanha + scorecard fraco + sweep em intents (§6). |
| `278` | `atlas:loop:morning-digest` | `dailyAt 05:35` | `atlas.loop.morning_digest.enabled` | Painel read-only "o que Atlas fez sozinho ontem" (funil, merges, canários, custo, keepalive, fila parked). Não chama provider. |
| `286` | `atlas:loop:weekly-agenda [--create-proposal]` | `weeklyOn(1, 05:45)` | `atlas.loop.weekly_agenda.enabled` | Propõe a pauta da semana a partir de evidência resolvida; cria draft no backlog, nunca aprova/executa. |
| `335` | `atlas:loop:meta-harness-ab-lift` | `dailyAt 06:15` | `...meta_harness_ab_lift.enabled` | Read-model A/B do lift do harness sobre outcomes reais. Não edita harness. |
| `343` | `atlas:loop:judge-calibration --write` | `dailyAt 06:20` | `...judge_self_calibration.enabled` | Auto-calibra o juiz a partir de casos históricos de canário-RED fix-forward. Só grava artefatos de evidência; não muda gate. |
| `351` | `atlas:loop:strategy-bandit --write-receipt` | `dailyAt 06:25` | `...explorer_strategy_bandit.enabled` | Mede certificação-por-token por tipo de alvo; escreve receipt de roteamento (o grinder só aplica lift provado). |
| `360` | `atlas:loop:auto-architecture --write-receipt [--create-proposal]` | `dailyAt 06:30` | **§0 MS** + `...auto_architecture_proposals.enabled` + `.schedule_enabled` | Propostas de arquitetura do code-graph. Draft parqueado; sem provider, sem Obra, sem apply. |
| `373` | `atlas:loop:mutation-gate --fixture=strong --write-receipt` | `dailyAt 06:35` | `...mutation_adequacy_gate.enabled` | Prova que o gate de adequação mutacional ainda rejeita testes fracos (gera NaN/INF/overflow). |
| `382` | `atlas:loop:cross-file-consumer-gate --fixture=safe` | `dailyAt 06:40` | `...cross_file_consumer_gate.enabled` | Prova que contratos de consumidores (code-graph) são reproduzidos, sem tocar source. |
| `399` | `atlas:loop:formal-invariant-gate --fixture=safe` | `dailyAt 06:50` | `...formal_invariant_gate.enabled` | Verifica envelopes de prova reproduzíveis p/ pisos de kernel sensível. Receipt-only. |
| `408` | `atlas:loop:perpetual-sweep --write` | `weeklyOn(6, 06:05)`, quinzenal por paridade ISO-week | `...perpetual_sweep.enabled` + paridade | Sweep adversarial perpétuo: LOW → backlog intent governado; HIGH → parked p/ operador. |
| `417` | `atlas:loop:taxa2-dials --write-receipt` | `dailyAt 05:55` | `...taxa2_dials.enabled` (def **false**) | Receipt diário do overlay raise-only/clamped que o supervisor consome no boot. |
| `426` | `atlas:loop:keepalive --stale-minutes=2` | `everyFiveMinutes`, `wo(5)` | **§0 MS** + `atlas.loop.keepalive_enabled` | **A rede de segurança** — relança supervisor morto (§7.3). Expiry 5min = auto-destrava. |
| `455` | `atlas:loop:confidence-calibrate` | `daily`, `wo()` | `atlas.loop.confidence_calibration.enabled` (def false) | Ajusta o arm-threshold de confiança a partir de amostras `{predicted,correct}` pós-merge. Report-only (NUNCA arma o gate). |
| `499` | `atlas:loop:cortex:cadence` | (bloco cortex) | flag cortex | Cadência do cortex de projeção/roles. |

Fora da tabela mas no mesmo bloco: `atlas:acp:reap-leases` (`everyMinute`, **§0 MS** + `acp_reaper_enabled`, `:462`) libera leases expirados do Agent Control Plane; `atlas:agents:reconcile` (`everyMinute`, `reconciler_enabled` def false, `:441`) é "a babá" que converge a frota ao desired-state. O **Sunday digest** (única notificação semanal) é agendado UMA vez em `bootstrap/app.php` (nota em `routes/console.php:445-447`).

---

### §2 · Master Switch — o gate fail-closed defense-in-depth (§0 de tudo)

`AtlasLoopMasterSwitch` (`app/Services/Ai/AutonomousEvolution/AtlasLoopMasterSwitch.php`, 122 linhas):

- **Fonte única de verdade:** a linha `ATLAS_LOOP_MASTER_ENABLED` do `.env` (`KEY`, `:29`). **Lê o `.env` DIRETAMENTE** (`rawValue()`, `:75-98`), NÃO via `config()/env()` — porque `env()` retorna null sob `config:cache` (a armadilha que tornaria uma flag "segura" silenciosamente stale). Os watchdogs bash grepam a MESMA linha.
- **Fail-closed absoluto** (`enabled()`, `:40-49`): ausência de flag, `.env` ilegível, parse error, QUALQUER `Throwable` → `false`. "Silêncio = off."
- **Pétreo:** a própria classe está em `AtlasLoopHarnessGuard::FORBIDDEN_SELF_TARGETS` (`AtlasLoopHarnessGuard.php:46`) — o loop **nunca** pode editar seu próprio switch (nunca se re-liga). Só o operador via `atlas:loop:on`/`atlas:loop:off` (`AtlasLoopOnCommand`/`AtlasLoopOffCommand`) grava (`on()`/`off()`, `:58-67`).
- **Defense-in-depth:** cada vetor de auto-start (schedule, keepalive, campaign launch, watchdog) checa `enabled()` ANTES de agir. OFF ⇒ cada um é um no-op byte-idêntico. Exemplos: `AtlasLoopCampaignCommand.php:49-53` (recusa lançar, sai SUCCESS p/ não gerar retry-storm); `AtlasLoopKeepaliveCommand.php:88-93` (respawn nada). O keepalive tem inclusive um **runaway-respawn sentinel** que chama `MasterSwitch::off()` se respawns excederem `keepalive_runaway_threshold` (30) na janela (subagente confirmou `AtlasLoopKeepaliveCommand.php:545-562`).

---

### §3 · O LOOP COMPLETO end-to-end (diagrama)

```
                         ┌─────────────────────────────────────────────────────┐
                         │  §0 MASTER SWITCH (.env, fail-closed, pétreo)        │
                         │  OFF ⇒ TODO vetor abaixo é no-op byte-idêntico       │
                         └───────────────────────────┬─────────────────────────┘
                                                     │ ON
   ┌─────────────────┐   backlog-feed / loss-observer / coverage-gaps
   │ SCOPE / GOAL    │──────────────┐  (cadências §1 → intents dedupados)
   └─────────────────┘              ▼
                          ┌───────────────────────┐
                          │ BACKLOG manifest       │ storage/app/atlas/loop/
                          │ (backlog-intents.json) │ backlog-intents.json
                          └──────────┬────────────┘
                                     │ refiller.refill() acima do watermark
                                     ▼
   atlas:loop:campaign ──► ┌──────────────────────────────────────────────┐
   (supervisor 24/7,       │ AtlasLoopCampaignSupervisor::run()  (loop)    │
    resume by campaign-id) │  refill → claim(atomic) → grind → persist →   │
                           │  loopBack.reflect (results viram sources)     │
                           └───────────────┬──────────────────────────────┘
                                           │ claimNextTask (prioridade = BANDA+OFFSET, §5)
                                           ▼
                           ┌──────────────────────────────────────────────┐
                           │ AtlasLoopTaskGrinder::grind (propose-only)    │
                           │  explore N cenários → FROZEN JUDGE escolhe    │
                           │  → honesty gate / grounding / grader ≥9 (§11) │
                           │  → materializa PROPOSTA                        │
                           └───────────────┬──────────────────────────────┘
                                           ▼
                    ┌────────────────────────────────────────────┐
                    │ atlas_loop_proposals                        │
                    │ status=certified_for_review                 │
                    │ merged_to_main=FALSE (forçado no model, §8) │
                    └───────────────┬────────────────────────────┘
                                    │  a cada 15min (§0 MS + auto_merge_to_main)
                                    ▼
   atlas:loop:automerge ──► ╔═══════════════════════════════════════════════════════╗
                            ║ AtlasLoopAutoMergeService::drain → mergeOneCritical    ║
                            ║  [lock main-merge único] §9                            ║
                            ║  0  authorize repo + TOCTOU re-resolve caminho          ║
                            ║  0b forbidden-self-target / self-improvement → PARK     ║
                            ║  DG1/DG2 confidence + change-class trust (opt)          ║
                            ║  1  REPROVE (frozen judge, até 3× se transiente)        ║
                            ║  2  git apply --check + git apply (REAL)                ║
                            ║  3  php -l  |  3b boot-smoke  |  3c value-gate          ║
                            ║  4  snapshot tag  4b CANÁRIO PRÉ-COMMIT  4c broader     ║
                            ║  4d PORTÃO SOBERANO (AcceptanceGate)                    ║
                            ║  5  git commit REAL em main (loop@atlas)  ← DURÁVEL     ║
                            ║  6  canário pós  + impact receipt + trust ladder        ║
                            ║  → receipt no Evidence Ledger                           ║
                            ╚═══════════════════════════════════════════════════════╝
                                    │ (guard de saldo líquido §10 pode throttle)
                                    ▼
                            main atualizada  +  NetDirectionGuard mede canary fail-rate
                                    │
                                    └──► keepalive (§7.3) mantém o supervisor vivo 24h
```

Rede de segurança em paralelo: **keepalive** (5min) relança supervisor morto; **acp:reap-leases** (1min) devolve tasks órfãs; **canários/attribution/sentinels** medem realidade.

---

### §4 · A metade PROPOSE-ONLY (evolve / campaign / runner / grinder)

#### 4.1 `atlas:loop:evolve` — `AtlasEvolutionLoopCommand`
`app/Console/Commands/AtlasEvolutionLoopCommand.php`. Signature `:25-33` (`--fixture`, `--task-file`, `--generate-from`, `--scenarios`, `--max-tasks`, `--max-seconds`, `--json`). `handle()` chama `AtlasEvolutionLoopRunner::run()` (`:59`). Renderiza inclusive uma linha "Merged to main (must be no)" com "YES — INVARIANT BROKEN" se algo quebrar o invariante (`:68`). `--generate-from` é o ciclo autônomo completo: o loop GERA sua própria task metric-shaped a partir de um arquivo self-contained (Sources→Hypotheses), e só um teste gerado genuinamente RED vira task (`:112-120`).

#### 4.2 `AtlasEvolutionLoopRunner::run()`
`app/Services/Ai/AutonomousEvolution/AtlasEvolutionLoopRunner.php:45-103`. Para cada task: explora cenários (`$this->explorer->explore`, `:74`), aplica transfer-gate opcional (`:76-79`), e se há `winner` monta `toProposal()` (`:82-85`). Retorna estrutura `atlas.evolution.loop.vN` com **`'merged_to_main' => false`** codificado como invariante duro (`:94-95`), `proposals_certified_for_review` = contagem (`:97`). `stop_reason` ∈ {`queue_exhausted`, `max_tasks_reached`, `time_budget_reached`}. `propose_only` default vem de `config('atlas.loop.propose_only', true)` (`:47`).

#### 4.3 `atlas:loop:campaign` — o grind pesado (`AtlasLoopCampaignCommand`)
`app/Console/Commands/AtlasLoopCampaignCommand.php`. Signature `:25-42` (`--campaign-id` resume, `--goal`, `--base-workspace`, `--max-seconds` clamp 1..86400, `--max-proposals`, `--max-tasks`, `--max-usd-cents`, `--scenarios`, `--workers`, `--provider`, `--idle-on-starvation`, `--no-shadow`). `handle()`:
1. **Master switch** (`:49-53`) — OFF ⇒ `{master:off, launched:false}` + SUCCESS limpo (sem retry-storm).
2. Base-staleness guard opcional (flag `base_staleness_guard_enabled`, `AtlasLoopCycleGitContract::commitsBehindMain` — o incidente "579 commits atrás").
3. **Uma chamada:** `$supervisor->run($input)` — este comando é um driver fino; o supervisor é o motor.

Este comando **É** o processo foreground de longa duração. Quem detacha é o soak/keepalive (passando `--campaign-id`).

#### 4.4 `AtlasLoopCampaignSupervisor::run()` — o motor
`app/Services/Ai/AutonomousEvolution/Campaign/AtlasLoopCampaignSupervisor.php` (1.884 linhas). Deps no ctor `:87-113` (incl. `AtlasLoopStore`, **`AtlasLoopTaskGrinder`**, `AtlasLoopRefillerPort`, `AtlasLoopBackService`, `AtlasLoopResourceGate`, `LoopWorkerPool`, `AtlasLoopFleetGovernor` via admit). Shape (`:224-778`):
- Setup: resolve/abre campanha, worker count, **fleet-cap admit** (`:244-247`), `acquireLock` (`:277`), boot git HEAD.
- Crash-recovery: `store->rebuildInFlight`, `resourceGate->sweepOrphans` (`:311-330`).
- `while(true)` tick (`:333-746`): budget-stop (`isOverBudget`, `:343`); **code-drift restart** se arquivos de pipeline mudaram (`:353-387`, defere enquanto há grinds in-flight); circuit-breaker de provider (`:398-407`); pause; **self-feed refill** acima do watermark (`refiller->refill`, `:433`); starvation → territory-ladder / originate / idle / stop (`:452-511`).
  - **Serial:** `store->claimNextTask` → `grinder->grind(...)` (`:688`) → `beat` → `loopBack->reflect(...)` (`:703`, resultados viram sources: fila auto-sustentável) → append ledger.
  - **Paralelo** (flag `atlas.loop.parallel.enabled`): `LoopWorkerPool::tick(...)` (`:518-636`), cada worker `claimNextTask` + grind.
- Finish (`:749-777`): drena pool, reclama tasks, `releaseLock`, `finish()`.

#### 4.5 `AtlasLoopTaskGrinder::grind()`
`app/Services/Ai/AutonomousEvolution/AtlasLoopTaskGrinder.php:72`. Por task: resource-admit gate (`:79-89`, devolve claim como backpressure se disco baixo), roteamento de provider (`:101-112`), roteamento multi-file→Obra (`:120-125`), skip por-alvo hopeless (`:144-190`), depois best-of-N (explora/materializa) **propose-only**. Embrulha o `AtlasEvolutionLoopRunner` provado e **nunca mergeia**.

#### 4.6 `atlas:loop:soak` — `AtlasLoopSoakCommand`
`app/Console/Commands/AtlasLoopSoakCommand.php`. Preflight + 1-comando launcher. `plan()` computa o **freio** (TTL/spend/grind-cap) e o **arm-check** (`AtlasLoopSoakPlanService`, `:35-40`); sem `--confirm` só imprime o plano; com `--confirm` é **fail-closed** — recusa se `arm['ready'] !== true` (`:59-63`). Default **detacha**: `dispatchDetached()` (`:84-90`) roda `nohup <php> ... artisan atlas:loop:campaign <freio-args> >> <log> 2>&1 &` (`detachedCommandLine`, `:98-116`). Um soak é um `atlas:loop:campaign` detachado carregando os args de freio.

---

### §5 · Next-work decider — a prioridade UNGAMEABLE em bandas

`app/Services/Ai/AutonomousEvolution/Discovery/AtlasLoopNextWorkDecider.php` (245 linhas). É um `FORBIDDEN_SELF_TARGET` (o loop não edita seu próprio priorizador, `:39`).

**Problema que resolve:** o loop antes ordenava por `priority = round(target.score*100)` — um escalar STORED e WRITABLE, confiado cego em claim-time. Anti-pattern Goodhart. A régua nova:

```
priority = BAND(shape) + OFFSET(leverage RE-RESOLVIDO fresco da árvore)
```

- **Bandas estritas não-sobrepostas** (`:49-57`): `BAND_WIDTH=1000`; `SKIP=0`, `EDGE_FIX=2000`, `REFACTOR=4000`, `OBRA=6000`. O offset é capado em `BAND_WIDTH-1=999` — **abaixo do gap entre bandas**, então SHAPE sempre domina leverage: nenhum score forjado/stale cruza uma banda (`bandFor`, `:132-142`).
- **Whitelist de hint** (`SHAPE_ALLOW`, `:60-69`; `decide`, `:94-96`): um hint desconhecido/forjado é RE-DERIVADO pelo router, nunca cai numa banda cego.
- **Promoção RAISE-ONLY de banda a partir de ground-truth** (`promoteBandFromGroundTruth`, `:148-159`): um hub genuinamente wired+complexo que a shape grosseira subclassificou é elevado à banda refactor por callers+ciclomática RE-MEDIDOS. **Nunca rebaixa** (fail-open). Threshold `decision_min_refactor_cyclomatic` (def 10) e `callers>=1`.
- **OFFSET = leverage re-resolvido FRESCO** (`reResolvedOffset`, `:173-223`): caller count real (tri-state via `AtlasLoopWiredCallerService::callerCounts`, `:181`) + ciclomática do pior método (AST fresca via `AtlasLoopSignalAnalyzer::fileComplexity`, `:195`). **NÃO** o `target.score` stored. `leverage = 0.65*callerComp + 0.35*cxComp` (`:218`), ambos saturam (20+ callers, cx 30+).
- **Fallback capado (o "199")** (`:207-211`): quando AMBAS as leituras frescas falham, cai no stored score mas **capado em `decision_unmeasured_offset_ceiling` (default 199)** — muito abaixo de qualquer alvo medido na mesma banda, então uma leitura degradada/forjada **nunca out-sorta** trabalho genuinamente medido. Recibo carrega `unmeasured_fallback=true` (`:124-126`).
- **Ancoragem crítica:** o caller resolver é construído FRESCO por chamada, ancorado ao `$repoRoot` da campanha (`callers()`, `:236-239`) — callers e ciclomática lidos da MESMA árvore, nunca de `base_path()`. Puro, fail-open, sem provider/DB/write; roda em ENQUEUE-time, nunca dentro do `claimNextTask` atômico.

---

### §6 · Backlog auto-feed

`app/Console/Commands/AtlasLoopBacklogAutoFeedCommand.php` (signature `:16-23`) → **§0 MS** + `atlas.loop.backlog_auto_feed.enabled` (`:32-39`) → `AtlasLoopBacklogAutoFeederService::feed()`.

`app/Services/Ai/AutonomousEvolution/AtlasLoopBacklogAutoFeederService.php:30-80`. Coleta fontes via `AtlasLoopBacklogAutoFeederServiceSupport::collectSources` (`:43`): loss-observer dry-runs, corpus de failure-signatures, residuais de campanha, receipts fracos do scorecard ACOS (`AtlasCognitionScoreCardService`), sweep notes que nomeiam arquivos reais. `rankCandidates` (`:54`) → `AtlasLoopBacklogManifestService::append` por candidato (`:58`, dedup). Grava em `storage/app/atlas/loop/backlog-intents.json`. `--dry-run` ⇒ `write=false`. Reporta `enqueued_count`/`dry_run_count`. Parâmetros: `window_hours` (24), `min_signal_count` (≥2), `max_items` (8).

---

### §7 · Campaign supervisor / Keepalive / Fleet — a rede de segurança 24h

#### 7.1 Heartbeat DUAL
1. **DB `heartbeat_at`** (coluna indexada, migration `2026_06_02_000100_create_atlas_loop_runtime_tables.php:50`), escrita por `AtlasLoopCampaign::beat()` (`app/Models/AtlasLoopCampaign.php:111`). **É o que o keepalive lê** p/ staleness.
2. **File heartbeat** (`storageDir/heartbeat`, epoch), via `AtlasLoopCampaignFileStore::writeHeartbeat` (supervisor `:1854-1857`), lido pelo status command.

#### 7.2 Tabelas
- **`atlas_loop_campaigns`**: `status` (running|paused|completed|aborted|killed, indexado `:30`), `max_seconds` (0=sem cap `:35`), `elapsed_seconds`, `stop_reason` (`:46`), `heartbeat_at` (`:50`), `kill_switch`, `lock_token`/`lock_expires_at`.
- **`atlas_loop_tasks`**: fila; `status` pending|claimed|running|done|failed|deferred (`:61`); `campaign_id` FK. Fleet governor conta `status=running` aqui.
- **`atlas_loop_proposals`**: `status` default `certified_for_review`, invariante nunca 'merged' (`:89`); `merged_to_main`.

#### 7.3 `atlas:loop:keepalive` — respawn detached (`AtlasLoopKeepaliveCommand`)
`app/Console/Commands/AtlasLoopKeepaliveCommand.php`. Signature `:37-41` (`--stale-minutes=10`, `--workers`, `--scenarios=3`). `handle()`/`runKeepalive` (`:108-349`):
- **§0 MS** OFF ⇒ respawn nada (`:88-93`).
- Candidatos: `atlas_loop_campaigns where status=running and kill_switch=false` com budget restante OU soak unbounded `max_seconds<=0` (`:122-135`).
- **Evidência DUPLA para respawn** (`:283-294`): `stale = heartbeat_at < now - staleMinutes*60` (`:141-142`, `staleMinutes=max(2,opt)`) **E** processo ausente (`supervisorAlive` via `pgrep -f 'atlas:loop:campaign.*<id>'`, `:681-684`). Respawn só quando heartbeat velho **E** sem processo. Nunca mata nada no caminho normal.
- **Desired-state gate** (`:151-153`): `AtlasAgentDesiredStateStore::authorizesCampaign` — autoridade de respawn vem do que o operador ligou, NÃO de `status=running`. Rows não-autorizadas: no-op (`:277-281`).
- Outras lanes: **code-drift recycle** (`:164-221`, mata+respawna supervisor cujo start precede o commit mais novo do engine; defere se grinds in-flight; debounce por `AtlasLoopDriftRestartDebounce`); **frozen-alive kill** (`:230-239`, heartbeat velho além de `keepalive_frozen_kill_minutes=15`); **reap órfãos** (`:248-272`, dead row além de `keepalive_reap_after_minutes` def 1440 → marca `completed/reaped_orphan_no_process`); **revive starved** (`:303-340`, `completed` com `stop_reason IN (queue_starved_no_refill, queue_exhausted)` + budget + tocada em 48h → volta a `running`).
- **Respawn detached** (`respawn`, `:924-940`): `nohup <php> -d memory_limit=4096M <artisan> atlas:loop:campaign --campaign-id=<id> --workers=N --scenarios=M --sleep-seconds=5 >> storage/logs/loop-keepalive-respawn.log 2>&1 &` executado via `bash -lc`. **Resume por `--campaign-id`** — o supervisor reclama tasks in-flight; nada se perde.
- **Auto-segurança:** `pcntl_alarm` self-deadline (~90s) SIGTERMa o próprio keepalive se travar (`:567-587`); runaway-respawn sentinel flipa o master OFF acima de 30 respawns/janela (`:545-562`); **fleet cap** bloqueia respawn quando `AtlasLoopFleetGovernor::fleetInFlight()` ≥ `fleet_global_worker_cap`.

#### 7.4 Fleet governor
`app/Services/Ai/AutonomousEvolution/AtlasLoopFleetGovernor.php`: `fleetInFlight()` = `AtlasLoopTask where status=running count` (`:24-31`); `admit($req,$cap)` = `min(req, cap-inFlight)` (`:38-57`). Cap **global** sobre TODAS as campanhas. Aplicado pelo supervisor (`:244-247`) e pelo keepalive.

> **Nota honesta (organs inertes):** `AtlasLoopBudgetScheduler` (knapsack valor/custo multi-obra) é keystone-only — **sem caller de produção** (docstring `:20-21`). `AtlasUnifiedLoopSupervisorService` é um assessor read-only do *file-based unified loop*, NÃO o campaign supervisor, e nunca reinicia nada (`:11-19`).

#### 7.5 Job de fila (superfície separada)
`app/Jobs/SoftwareCompanyLoopRunJob.php` — launcher enfileirado do **AP-790 reliable 24h loop** do subsistema `SoftwareCompanyStewardship` (NÃO o ACDE). Chama `Reliable24hLoopRunnerService::run()` com o mesmo input do CLI. `dry_run` é default; `execute=true` só com confirmação do operador. Mencionado por completude — é um loop irmão, não o Loop ACDE.

---

### §8 · Persistência — a porta governada no DB (never-merge estrutural)

`app/Models/AtlasLoopProposal.php`:
- `STATUS_CERTIFIED = 'certified_for_review'` (`:21`).
- **Guard estrutural** (`booted()::saving`, `:53-63`): **todo** save fora do escopo governado força `merged_to_main=false` (`:59-60`) e força `status=STATUS_CERTIFIED` (`:62`). "Nenhum writer perdido pode fingir um merge."
- A única exceção: a flag estática `$governedMergeInProgress` (`:51`), setada só por `AtlasLoopAutoMergeService::governedSave()` (`:1193-1206`, dentro de `try/finally` + `DB::transaction` + `SET LOCAL atlas.governed_merge='on'` em pgsql). Logo: **o mero SUCESSO de flipar `merged_to_main=true` prova que passou pela única porta sancionada.**

---

### §9 · O DRAIN AUTOMERGE — a joia da coroa, passo a passo

`app/Services/Ai/AutonomousEvolution/AtlasLoopAutoMergeService.php` (1.654 linhas). Comando fino: `AtlasLoopAutoMergeCommand::handle` → `$merger->drain($repo, --limit)` (`AtlasLoopAutoMergeCommand.php:27`).

#### 9.0 `drain()` (`:75-134`) — seleção + guards de entrada
1. **Autoridade por-repo** (`AtlasLoopMultiRepoMergeAuthority::authorize`, `:80-91`): home repo gated por `auto_merge_to_main`; qualquer repo estrangeiro é never-merge default (só atravessa com multi-repo ON + allow-list). Fail-closed por construção.
2. Repo tem `.git`? senão `blocked` (`:93-95`).
3. **Guard de saldo líquido** (`AtlasLoopNetDirectionGuard::verdict`, `:99-105`): se `throttled`, retorna `throttled` sem mergear (§10).
4. Seleção: `AtlasLoopProposal where status=CERTIFIED and merged_to_main=false and reviewed_at IS NULL order by created_at limit(1..50)` (`:107-113`).
5. **Captura o canônico AUTORIZADO** p/ TOCTOU (`:119-121`), passa a cada `mergeOne`.

#### 9.1 `mergeOne()` — lock único de main-merge (`:219-237`)
Todo o span apply→floors→canary→commit→attribution roda sob o **lock path-stable único** `AtlasLoopMergeActuator::withMainMergeLock` (`:227-231`, timeout `main_merge_lock_timeout_seconds` def 8.0). Nunca interleava com outro writer de main. Lock miss → `handleMainMergeLockMiss` (`:248-280`): `lock_timeout` é DEFERRAL (row fica drenável), contador `_lock_deferrals` PARKea após K=`main_merge_lock_max_deferrals` (def 5) — nunca spin infinito.

#### 9.2 `mergeOneCritical()` — o pipeline (`:285-794`)
Rastreia `$commitLanded`/`$commit` p/ reconciliação honesta (`:308-309`).

| Passo | Linhas | Ação | Falha ⇒ |
|---|---|---|---|
| **TOCTOU re-resolve** | `:319-322` | Re-resolve caminho canônico AGORA; aborta se mudou desde authorize (symlink/mount trocado). Fail-closed. | `canonical_path_changed_toctou` |
| **0 · forbidden-self-target** | `:328-332` | `AtlasLoopHarnessGuard::isForbiddenSelfTarget(target)` && !operatorApproved → **PARK** `parked_for_operator_review`. Defense-in-depth do meta-loop (o soak certificou edição no PRÓPRIO painel-juiz em 12/06). | park |
| **S1 · self-improvement** | `:349-354` | `is_self_improvement` && !`self_improvement_auto_merge_enabled`(def false) → PARK. Operator directive: quando ON, self-improvement legítima flui pelos MESMOS gates de máquina. | park |
| **DG1 · confidence** | `:362-373` | (opt, `calibrated_confidence_gate_enabled` def false) abstém se confidence < banda calibrada empírica. Fail-OPEN. | park |
| **DG2 · change-class trust** | `:380-390` | (opt) abstém se a classe não ganhou streak autônomo (`AtlasChangeClassTrustLadder`). Fail-OPEN. | park |
| **1 · REPROVE** | `:399-427` | `AtlasLoopProposalPromotionGate::reprove` — re-roda frozen judge contra o contrato congelado em **workspace isolado**. `reproof_failed` é TRANSIENTE (retry até 3×, `:400-402`) — carga não mata merges. Estruturais (`git_apply_failed`/`no_acceptance_contract`) NÃO retêm: `retireStale` (aposenta com razão auditável). | skip/retire |
| **2 · git apply REAL** | `:429-454` | Normaliza diff→target (`rewriteDiffToTarget`), `git apply --check` depois `git apply` no repo real. Conflito (árvore andou) → `retireStale('retired_stale_tree_moved')`. | apply_conflict/apply_failed |
| **3 · php -l** | `:456-471` | `phpLintOk` (`:1548-1557`) em cada .php do patch (escopado ao patch via `scopeToPatch` p/ não pegar edições do supervisor concorrente). Sintaxe quebrada NUNCA entra; `git checkout --` desfaz o apply. | `php_lint_failed:<file>` |
| **3b · boot-smoke** | `:480-486` | (flag `boot_smoke_guard` def true) `bootSmokeOk` (`:1567-1581`): boota `vendor/autoload`+`bootstrap/app.php`+kernel bootstrap com a mudança aplicada; se um provider/singleton lança no boot, DESFAZ o apply e rejeita (evita crash-loop do supervisor). Degrade-safe (sem vendor ⇒ true). | `boot_smoke_failed` |
| **3c · value-gate** | `:494-522` | (opt `value_gate_enabled`) recusa merge de baixo impacto (órfão/dead-scaffolding) medindo callers reais (`targetCallerCount`); retira com status auditável. Só recusa impacto, nunca relaxa segurança. Fail-open (callers null passa). | `value_gate_blocked` |
| **4 · snapshot tag** | `:527-528` | `git tag -f atlas-snap-<hash12> HEAD` — âncora endereçável p/ fix-forward barato. | — |
| **4b · CANÁRIO PRÉ-COMMIT** | `:540-575` | (flag `precommit_canary_gate` def **true**) roda teste-irmão contra a árvore APLICADA-mas-não-commitada (`canary`, `:1351`). `block` ⇒ DESFAZ o apply (main intocado), APOSENTA a proposta, enfileira fix-forward (`enqueueFixForward`), e **alimenta o trust-ladder um revert** (streak reseta, `:568`). Semântica fix-forward preservada mas **a partir de main LIMPO** — regressão nunca transita por main. | `canary_red_precommit_gate` |
| **4c · broader-regression** | `:584-614` | (opt `broader_regression_gate_live` def false) prova módulos afetados por subtree, não só o irmão. RED ⇒ mesmo tratamento do canário-red. | `broader_regression_gate` |
| **4d · PORTÃO SOBERANO** | `:627-647` | `sovereignGateVerdict` (`:861-903`) → `AtlasAutonomosGateAdapter::certifyAutonomosDelivery`. bar(dev)=bar(forge)=bar(autonomos); TrustLevel só troca a testemunha (`frozen_judge_clean_checkout_reproof`), nunca a régua. Evidência = o que o drain REALMENTE tem (contrato congelado + reprove verde + canário real + envelope `_sovereign_evidence`). **Nada fabricado** — evidência ausente PARKea com invariante nomeado. Fail-closed (throw ⇒ refuse). | park `sovereign_gate_refused` |
| **5 · COMMIT REAL** | `:649-658` | `git add -- <changed>` + `git -c user.email=loop@atlas -c user.name=atlas-loop commit -q -m 'atlas loop auto-merge: <path> [<hash12>]' --no-gpg-sign`. Falha ⇒ `commit_failed`. **`$commitLanded=true`** — a mudança é DURÁVEL em main. | commit_failed |
| **5b · marcação governada** | `:660-673` | `governedSave` (§8) força `merged_to_main=true, reviewed_at=now`. Única porta. | — |
| **6 · canário pós / receipts** | `:678-759` | Com gate ON o GREEN já rodou pré-commit. Impact receipt (`AtlasLoopImpactReceiptService::build`); confidence sample `{predicted,correct}` (`:704-718`); accrue compounding (learn→recall, `:732`); delivery-contract recorder (opt); **feed trust-ladder** com o commit real (`:759`). Todos best-effort pós-commit: um throw NUNCA desfaz o commit. | — |
| **receipt** | `:727`, `:1604-1634` | `AtlasEvidenceLedger::record(DecisionIssued, ...)` com `policy=merge_livre_v2_operator_2026_06_12`. Best-effort. | — |

**Reconciliação honesta de atribuição** (`catch`, `:770-793` + `reconcileMergedAttribution`, `:809-842`): se o commit já landou mas um passo POSTERIOR lançou (o próprio save, impact-receipt, canário), reconcilia a row p/ `merged_to_main=true` via `governedSave` — senão a row mentiria "não-mergeado" enquanto main tem o commit (e nenhum drain futuro corrigiria; um re-apply stale só APOSENTA). Idempotente.

---

### §10 · NetDirectionGuard — o dial que aperta sozinho

`app/Services/Ai/AutonomousEvolution/AtlasLoopNetDirectionGuard.php` (121 linhas). É `FORBIDDEN_SELF_TARGET`. `verdict()` (`:39-120`):
- Mede a **taxa de falha do canário** sobre a janela dos últimos `WINDOW=10` merges (`_canary` persistido em `quality`). Puro leitor, fail-open (sem tabela ⇒ merges livres, `:59-61`).
- **Recency bound** (`:82-90`): janela = últimos N merges DENTRO de `net_direction_recency_hours` (def 6). Sem isso, uma janela velha envenenada (canários vermelhos de campanha anterior) congelaria p/ sempre — o throttle bloquearia os próprios merges que a refrescariam (deadlock). Com bound, quebra velha envelhece; abaixo de `MIN_RAN=4` o gate de amostra fail-opa e merges retomam.
- **Throttle** (`:110-117`): com amostra ≥ `MIN_RAN=4`, se `failed/ran >= FAIL_RATE_THRESHOLD=0.5` → `throttled=true` ("fix-forward perdendo a corrida"). Raise-only: apertar é automático; afrouxar acontece quando a janela medida melhora (dado, não decisão). L4-3 acrescenta saldo de impacto (`_impact_receipt`) como sinal consultivo.

---

### §11 · Certificação + gates anti-Goodhart (como o código impede trabalho fake)

Padrão transversal: **(a)** FACTS-only / sem escalar único a otimizar; **(b)** re-deriva a evidência em vez de confiar em veredito passado pelo caller; **(c)** fail-CLOSED em ambiguidade nos gates de merge/promoção, fail-OPEN só no flag barato de comprehension.

#### 11.1 `AtlasLoopQualityGrader` — a barra ≥9 ungameable
`app/Services/Ai/AutonomousEvolution/AtlasLoopQualityGrader.php`. `DEFAULT_BAR=9.0` (`:28`), config `atlas.loop.quality_bar` (def 9.0, `config/atlas.php:2624`). É receipt por default; vira GATE só com `quality_bar_gate_enabled` (def false, `:2623`).
- **Refactor** (`grade`, `:34-113`): 3 HARD GATES — `behavior_preserved=false ⇒ score 0` (`:50-52`); `scope_clean=false ⇒ ≤2` (`:54-56`); **anti-laundering** `complexity_gaming_reasons != [] ⇒ 2` (encoding de branches booleanos em literais de dados = metric laundering, `:60-67`). Base 6.0 + até +3 pela queda relativa de ciclomática (`:73-75`) + anti-inflação de branches (`net_branches_ok` senão −2, `:81-85`) + drop absoluto de branches (compounding, `:90-92`) + bônus cobertura (`:95-97`).
- **Feature** (`gradeFeature`, `:137-197`): mesmos hard gates + `adversarial_refuted_count>0 ⇒ 2` (`:159-161`); +1.5 `diff_earned` (falhou-antes/passa-depois) + 0..2 escalado por `mutation_kill_ratio` + bônus cobertura. Uma feature fina (earned, kill 0) = 7.5 < 9 → rejeitada.

#### 11.2 `AtlasAutonomousEvolutionCertificationService` — harness de auto-atestação do AAEL
`app/Services/Ai/AutonomousEvolution/AtlasAutonomousEvolutionCertificationService.php`. `certify()` (`:20-58`) encadeia ~14 checks: `fileCheck` (presença de tokens canônicos) + smokes que rodam `runCycle(...)` num sandbox `DB::beginTransaction()/rollBack()` (nunca persiste). Smokes NEGATIVOS load-bearing: `highRiskGateSmoke` exige `human_signature_required` (`:114-115`); `doctrineBlockerSmoke` exige `STATUS_BLOCKED` p/ objetivo "criar runtime paralelo/bypass evidence" (`:175-176`); `claimPolicy` hard-asserta `autonomous_core_mutation_allowed=false && provider_invoked_directly=false && requires_sandbox_before_promotion=true` (`:191-194`). Saída `passed` iff `failed===[]` (`:41`) + `certification_hash` tamper-anchor. **Nota:** este serviço emite `passed`/`failed`, NÃO o estado `certified_for_review` (esse vive no store/orchestrator).

#### 11.3 `AtlasLoopProposalPromotionGate` — o "reprove"
`app/Services/Ai/AutonomousEvolution/AtlasLoopProposalPromotionGate.php`. `reprove()` (`:116-164`) re-roda o **frozen judge contra o contrato de acceptance PERSISTIDO** (`_acceptance_contract` no `quality`), não o métrico. Fail-closed: sem contrato ⇒ `no_acceptance_contract` (`:121-123`); **contract-swap guard** (flag `reprove_hash_assert_enabled`) ⇒ `acceptance_hash_mismatch` via `hash_equals` contra `AtlasEvolutionFrozenJudge::acceptanceHash` (`:132-137`); contratos snippet-shaped são reprovados reconstruindo o workspace da task, `git apply` do diff, e re-rodando o juiz lá (`:144-146`, `reproveSnippet`). `defaultReprove` re-verifica characterization re-rodando o MESMO mutante e exigindo que o teste ainda o mate (`:272-289`). Qualquer exceção ⇒ `false` (fail-closed). `promote()` (`:44-88`) é estruturalmente incapaz de escrever main: commit em branch NOVA, `'merged_to_main'=>false, 'never_main'=>true` (`:85-86`).

#### 11.4 `AtlasEngineeringHonestyGate` — o holdout pós-frozen-judge
`app/Services/Ai/AutonomousEvolution/Verify/AtlasEngineeringHonestyGate.php`. Espelho do TradingHonestyGate. Tudo raciocina sobre **AST PhpParser, nunca regex** (`:54-57`) — um splice de comentário `/**/` não esconde membro.
- **Dead-code removal** (`evaluateDeadCodeRemoval`, `:67-217`), cada regra append uma razão nomeada: **FLAGGED-ACTUALLY-DEAD** (nunca confia na lista `$deadMembers` do caller; re-deriva deadness fresca, `:89-98` — fecha TOCTOU de finding stale); **PARSES+RE-PROVED** (`:100-111`); **REMOVED-ONLY** (nada adicionado, `:113-131`); **SURVIVORS-UNCHANGED** (cada sobrevivente byte-idêntico no AST — nenhum const/threshold/boolean/URL flipado sob cover de remoção, `:133-147`); **PURE-DELETION** (subsequência estrita; rejeita `eval()`/side-effects globais, `:149-157`); **NO-DANGLING-REFERENCE** (`:159-171`); **REPO-CLEAN** (membro não-privado referenciado em lugar nenhum, `grep -rlw`, `:173-190`). `certified = reasons===[]` (`:192`). Parse fail ⇒ sentinela `__unparseable__` (fail-CLOSED).
- **Detecção de test-tautology / weakened-test** (`evaluateImplementation`, `:273-349`): força `revert_recheck=true` — reverter o diff DEVE tornar o teste-alvo RED (**DIFF-EARNED**, `:292-306`); holdouts selados rodam, falha ⇒ `sealed_holdout_failed(n)`. **Shrink-to-pass** em doc-edit: `< 60%` do corpo original sobrevivendo ⇒ `suspicious_content_loss` (`:237-245`).

#### 11.5 `AtlasLoopComprehensionGroundingGate`
`app/Services/Ai/AutonomousEvolution/Verify/AtlasLoopComprehensionGroundingGate.php`. O modelo deve CITAR os símbolos/consumidores em que seu objetivo declarado se apoia; o gate checa que cada um EXISTE (OR sobre 3 oráculos: `class_exists` → file-scan PSR-4 (cap 60000) → índice `atlas_engineering_code_symbols`). Dois modos de posture oposta: `ground()` é **FAIL-OPEN** (só FLAGS, nunca BLOCKS; citação vazia/oráculo ausente ⇒ grounded=true, `:101-108`); `groundAgainstInventory()` é o **veto FAIL-CLOSED** contra o inventário do brain — `grounded = resolvedCount>=1 && ratio>=minRatio` (def 0.5), citação vazia ⇒ REFUTED (`:214-220`).

#### 11.6 `AtlasLoopDefectFalsificationGate`
`app/Services/Ai/AutonomousEvolution/Defect/AtlasLoopDefectFalsificationGate.php`. `admit()` (`:25-44`): um defeito vira task SÓ se falsifiable-RED. 3 condições fail-closed: `no_red_command` (sem reprodução ⇒ nunca task), `not_reproduced` (`red_observed!==true`), `no_positive_delta` (`behavior_delta_after_fix<=0`). FACTS-only. Mata falso-positivo "plausível-mas-não-reproduzido" antes de gastar um worker.

#### 11.7 `AtlasLoopAntiFarmFloor`
`app/Services/Ai/AutonomousEvolution/AtlasLoopAntiFarmFloor.php`. `eligibleToMerge()` (`:33-62`): dois pisos que um cosmético nunca clara ambos. **(1) BITES** — ≥1 prova load-bearing de `{diff_earned, method_kills, earned_red, count_drop}` (`:36-42`). **(2) PRODUCTION-PATH-PROVEN** — item behavior-adding (`wired_proof`) exige `production_caller` real, não um `new X()` em teste (`:44-53`). `eligible = bites && productionProven`. Mandato: "**NUNCA afrouxar — uma bite-proof pode ser ADICIONADA, nunca removida**" (`:25`).

#### 11.8 `AtlasLoopAntiGoodhartUnifiedRefusal` + `GoodhartReceiptLedger`
`AtlasLoopAntiGoodhartUnifiedRefusal.php`: veredito **FACT-only por construção** — 4 fontes (`proxy, farm, paraphrase, constitution`), cada razão `{source, pattern_id, fact, severity(enum), evidence_refs}`; **NENHUM campo escalar; callers NÃO PODEM somar severities num score** (`:11-16`). `AtlasLoopAntiGoodhartRefusalVerdict` imutável expõe só `refused()/reasons()/evidenceRefs()`. `evaluate()` roda painel de 3 críticos (nenhum voto silenciado) + refusal template-farm quando um `templateFingerprint` repete ≥ `farmThreshold` (def 3). `AtlasLoopGoodhartReceiptLedger::record()` (`:39-66`) é audit **append-only** tamper-evident: TODO veredito (refused OU allowed) flui por ele; INSERT-only (sem update/delete exposto), stampa `judge_commit_sha` de `.git/HEAD` sem shell.

---

### §12 · Attribution + Sentinels/canários

#### 12.1 `AtlasLoopCapabilityDeltaAttributionService` — o spark recursivo honesto
`app/Services/Ai/AutonomousEvolution/Attribution/AtlasLoopCapabilityDeltaAttributionService.php`. Atribui behavior-Δ MEDIDO de volta à SHAPE de origem. Lê **SÓ** `net_behavior_delta` (nunca um campo `credited`/grade do caller — "um caller não se dá uma nota"). Crédito = piso anti-small-sample: `credited = samples>=minSamples(5) && wilson>0` (`:69`), onde `wilson` é o **lower-bound do intervalo de Wilson 95%** sobre a taxa de Δ-positivo. **Honestidade causal:** `counterfactual_baseline` = taxa de Δ-positivo de todas as OUTRAS shapes; `false_causality_warning = credited && wilson<=baseline` (`:77`) flaga uma shape estatisticamente indistinguível do ruído de fundo (correlação-como-causação). `confidence` é banda de sample-size determinística, nunca LLM score.

#### 12.2 Sentinels/ (`app/Services/Ai/AutonomousEvolution/Sentinels/`)
FACTS-only observers (veredito + offenders, sem auto-ação). `Wave19SentinelWiringCanary::runAll` (`:39-77`, pétreo no-op quando `sentinels.wave19_enabled` OFF); `ServedQueueInspectorSweepSentinel::sweep` (fecha "18 packets quebrados servidos na fila viva"; `clean = offenders===[]`); `ServingQueueDiskConformanceSentinel` (drift disk esperado vs resolvido); `ReplenisherDocGapOracleCoverageSentinel` (o oráculo resolve o bom E ainda dispara no ruim — não virou no-op); `ReplenisherSiblingRoleCoherenceSentinel` (recusa pareamentos que compartilham só sufixo de role `{gate,service,bridge,ledger}`).
- **RED→fix-forward** vive em organs irmãos: `AtlasLoopRegressionSentinel::triage` (`:31-97`) — quando um check verde vira RED em main, atribui ao merge recente do loop por overlap de arquivos e emite objetivo "*Restore the check to GREEN without reverting — fix forward*" (`:82`); falha sem overlap fica `unattributed` ("o loop nunca se culpa por quebra externa"). O canário pós-land `AtlasTaskPostLandCanarySentinel::observe` só OBSERVA + receipt (`canary_pass`/`canary_fail_attributed`/`canary_inconclusive`), nunca executa revert — isso fica com o operador/actuator. O kill-switch completo é `AtlasExternalBrainAmplifierCanaryKillSwitch::evaluate` (`ACTION_ROLLBACK` com tetos anti-fake `FALSE_GREEN_CEILING=0.15`, `PROXY_LEAK_CEILING=0.15`; `RECOVERY_MIN_SAMPLE_SIZE=30` anti-oscilação).

---

### §13 · Multi-agent loop certification / TerminalLoopProof — provas, não agentes reais

`app/Services/Ai/SelfConstruction/MultiAgentLoopCertification/AtlasMultiAgentLoopCertificationRunner.php`. `run()` (`:49-111`) compõe 6 organs estáticos puros (canonicalize→hash estável, digest, readiness matrix, invariant matrix, cert hash, health-digest probe). `certified = hashMatch && healthDigest.present` (`:99`).

**Matriz de invariantes** (`AgentControlPlaneMultiAgentLoopCanonicalInvariantMatrixBuilder`): 6 invariantes de segurança cada com `proof_source`/`pass_condition`/`failure_action`/`blocks_autonomous_continuation` (`allowed_files_isolation`, `one_task_per_worker`, `lease_report_matching`, `no_malformed_packets`, `lane_isolation`; `no_recoverable_backlog` non-blocking). **Recusa** com `missing_proof_source_for_blocking_invariant` se um blocking row não nomeia como foi provado (`:83-99`). `autonomous_continuation_allowed = blockingViolations===[]`.

**Rodam agentes reais? NÃO.** `AgentControlPlaneMultiAgentLoopCertificationService` docblock (`:10-27`) é explícito: exercita o orquestrador de fila + claim/lease com **N agentes sintéticos e K ciclos**, e é "runtime-safe: **nunca inicia processos, nunca chama Codex CLI/app, nunca spawna subprocess, nunca invoca adapters, nunca dispatcha, nunca gasta token, nunca escreve o evidence ledger**". `certify()` default `dry_run_only=true`. O invariante `runtime_safety_all_false` exige TODAS as flags runtime false. `TerminalLoopProof/*` são helpers puros de hashing/interpretação sobre payloads injetados. **São provas/fixtures sobre um substrato de fila real mas sintético (não-executante), não execução de agente real.**

---

### §14 · SelfConstruction / Maestro / CodexRealInvoker — SCAFFOLDING DORMENTE (honestidade brutal)

A pedido: a cadeia `AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvoker*` retorna todos os flags de capacidade `false` e **nunca dispara processo**. Confirmado com evidência:

- **Escala:** `app/Services/Ai/SelfConstruction/` = **1.159 arquivos**, 72 subdirs (nomes grandiosos: `AutonomousRuntime`, `ContinuousRuntime`, `UnattendedRuntime`, `RuntimeDaemon`, `WorkerSwarm`, `Quaternity`, `Autopoiesis`, `TerminalLoopProof`, `FinalOperatorClosureCorridor`, ...).
- **A cadeia:** **98 arquivos** `Agent*RealInvoker*` — 48 `AgentCodexRealInvoker*` (gates/executors) + 48 wrappers `AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvoker*` + 2 signed-release variants. Cada um 300–430 linhas de validação/hash/metadata, quase nenhuma lógica de execução.
- **Todos os flags hardcoded `false`:**
  - `AgentCodexRealInvokerExecutorEnablementGate.php:98-101` e `:373-376`: `'external_process_started'=>false, 'token_spend_allowed'=>false, 'provider_started'=>false, 'dispatch_allowed'=>false`.
  - `AgentCodexRealInvokerGuardedProcessStartExecutor.php:113-117`: `'actual_process_start_allowed'=>false` + os 4 acima; summary "*...actual process start remains disabled.*" (`:124`).
  - `AgentCodexRealInvokerActualProcessStartRehearsalExecutor.php:85-89`: rehearsal-only, todos false.
  - Os wrappers OneShotTick adicionam ainda mais false (`...StartExecutionGateInvoker.php:40-46`: `adapter_execution_allowed`, `self_programming_allowed`, ...). Cada wrapper só aponta p/ o `next_required_slice` — uma lista ligada de gates cujo único output é "o próximo gate que você também precisa passar".
- **Zero primitivas de spawn:** busca por `proc_open|shell_exec|exec(|Symfony...Process|new Process|->run()|pcntl_` nos 98 arquivos = **zero hits**. A única execução real de processo em `SelfConstruction/` está em utilitários NÃO relacionados (`NativeWorker/AtlasNativeWorkerCommandPlanRunner.php:96`, os actuators git/`php -l` em `Governance/`), não alcançáveis por essa cadeia.
- **Nada agenda:** grep de `routes/console.php` por `OneShotTick|RealInvoker|AutomaticDispatchScheduler` = nada. A única superfície viva que os referencia é `AtlasAiSelfConstructionMotherCommand`, auto-descrito como "*read-only advisory projections (Phase 2 gap report)*" — cujo `handle()` chama métodos de PROJEÇÃO (`AtlasSelfConstructionReadinessService`), não os invokers. Os métodos reais dos gates só são invocados pelo wrapper OneShotTick correspondente e pelos `PostStart*` irmãos — um cluster fechado auto-referencial sem caller externo/agendado.
- **Maestro/ClosedLoop** (9 arquivos): bookkeeping de DB/ledger e pattern-mining sobre "outcome shapes" — telemetria, não um orquestrador que dirige a cadeia RealInvoker. Nenhum arquivo sob `Maestro/` chama `enableExecutor`/`authorizeStartExecution`.

**VEREDITO:** scaffolding dormente, não dispatch autônomo real. ~98 classes (~30k linhas) formam uma cerimônia de gates multi-estágio (enablement → fresh-release → plan → boundary → signed-release → preflight → dry-run → ... → **rehearsal** → start-execution → readiness → mirror PostStart) onde, sem exceção, a capacidade terminal (`external_process_started`, `provider_started`, `token_spend_allowed`, `dispatch_allowed`, `self_programming_allowed`) é `false`. O "Real" no nome é aspiracional. Onde o Atlas de fato roda processos: `NativeWorker/...CommandPlanRunner.php:96`, os actuators git de `Governance/`, e a porta `atlas:land` (§0.3, Caminho B).

---

### §15 · Prova git dos merges (amostra)

`git log --grep='atlas-task land' | wc -l` = **47**. Todos autor **`Vitor Freire <vitordsny@gmail.com>`** (`git log --grep='atlas-task land' --format='%an' | sort | uniq -c` → `47 Vitor Freire`). Amostra (todos 2026-07-07), com diffstat de código REAL (não docs-only):

```
4d855854 Goal 3 SLICE 1 (rename Hermes->Workcell Adapter): contrato WorkcellAdapter...
          7 files, +390/-288  (WorkcellAdapter.php novo, HermesExecutiveMeshService -273, +Test)
b44f8fe1 Goal 2 SLICE 2 (Learning Loop AMPLO): ADML pesa SÓ outcome proven_real...
5fe0666f Goal 2 SLICE 1 (Proof CROSS-SURFACE): MESMO OutcomeProofGate wirado...
0eb193f9 SLICE 2 (Proof Loop DURO): OutcomeProofGate no write-path do Dev Outcome...
ae2c243e T4-S7 (Obra #17): guard de não-regressão do re-ranker — precision@k...
```

**Interpretação honesta (repete §0.3):** estes 47 são commits da porta `atlas:land` (`AtlasLandCommand` → `AtlasTaskScopedCommitter`, mensagem `atlas-task <taskid>:`), do subsistema SelfConstruction/Agent Control Plane, autorados pela identidade git do operador. **NÃO são** os commits do drain ACDE (`AtlasLoopAutoMergeService`), que autoraria como `loop@atlas` com mensagem `atlas loop auto-merge:` — **e desses NÃO há nenhum** neste histórico (`git log --all --author=loop@atlas` = vazio; `--grep='atlas loop auto-merge'` = vazio), consistente com o master switch estar fail-closed OFF neste checkout (`.env` ausente).

Portanto: **merges/lands autônomos-de-sessão aconteceram (47, via `atlas:land`)**; o **drain ACDE está construído+agendado+gated mas sem evidência de commit neste checkout**. Ambos escrevem em `main` local; são portas distintas com autoria e governança distintas.

---

### §16 · Modos de falha / fail-closed (resumo)

| Camada | Postura | Mecanismo |
|---|---|---|
| Master switch | **fail-CLOSED** | `.env` ilegível/ausente/parse-error/throw ⇒ OFF (`AtlasLoopMasterSwitch.php:46-48`) |
| Campaign launch | fail-closed limpo | MS OFF ⇒ SUCCESS no-op (sem retry-storm), `AtlasLoopCampaignCommand.php:49-53` |
| Never-merge (DB) | **fail-CLOSED estrutural** | `saving` força `merged_to_main=false` fora do escopo governado (`AtlasLoopProposal.php:59-60`) |
| Repo authority (drain) | fail-closed | home OFF ⇒ disabled; foreign não-registrado ⇒ blocked (`:80-91`) |
| TOCTOU | fail-closed | canônico mudou ⇒ aborta (`:319-322`) |
| Reprove | transiente retry / estrutural aposenta | `reproof_failed` 3× retry; `git_apply_failed`/`no_acceptance_contract` retire (`:399-427`) |
| php -l / boot-smoke | fail-closed pré-commit | desfaz apply (`checkout --`), rejeita (`:456-486`) |
| Canário pré-commit | fail-closed + fix-forward | RED ⇒ desfaz apply, aposenta, enfileira fix-forward, reseta trust (`:540-575`) |
| Portão soberano | **fail-closed** | throw do gate ⇒ refuse (banda de segurança, `:891-902`) |
| NetDirectionGuard | fail-OPEN (com recency bound anti-deadlock) | sem dados ⇒ merges livres; throttle só com amostra ≥4 e fail-rate ≥50% |
| Confidence/change-class DG1/DG2 | fail-OPEN | erro de calibração/trust nunca bloqueia merge (`:370-372`, `:387-389`) |
| Honesty/grounding-inventory/defect | fail-CLOSED | parse-fail ⇒ sentinela `__unparseable__` / veto / `no_red_command` |
| Comprehension `ground()` | fail-OPEN | só FLAGS, nunca bloqueia |
| Keepalive | fail-safe (nunca mata no caminho normal) | respawn só com evidência dupla; self-deadline + runaway sentinel flipa MS OFF |
| Receipts/attribution/compounding pós-commit | best-effort | throw NUNCA desfaz o commit durável; reconciliação honesta se preciso |

---

### §17 · Referência de config (`config/atlas.php`)

| Chave | Default | Efeito |
|---|---|---|
| `ATLAS_LOOP_MASTER_ENABLED` (.env) | ausente ⇒ **OFF** | §0 master switch, fail-closed |
| `atlas.ai.loop.auto_merge_to_main` | `false` (`:971`) | porta do drain p/ home repo |
| `atlas.loop.self_improvement_auto_merge_enabled` | `false` (`:3110`) | self-improvement auto-mergeia sem park humano |
| `atlas.ai.loop.boot_smoke_guard` | `true` (`:1004`) | boot-smoke pré-commit |
| `atlas.ai.loop.precommit_canary_gate` | `true` (`:1010`) | canário pré-commit (revert-clean) vs pós-commit legado |
| `atlas.ai.loop.value_gate_enabled` | `true` (`:1040`) | recusa merge de baixo-impacto (órfão) |
| `atlas.loop.quality_bar` / `..._gate_enabled` | `9.0` / `false` (`:2624`,`:2623`) | barra ≥9 (receipt vs gate) |
| `atlas.loop.scenarios_per_task` / `max_...` | `3` / `12` (`:2009`,`:2014`) | best-of-N |
| `atlas.loop.propose_only` | `true` | invariante propose-only do runner |
| `atlas.loop.decision_unmeasured_offset_ceiling` | `199` | cap do fallback do decider (§5) |
| `atlas.loop.decision_min_refactor_cyclomatic` | `10` | promoção raise-only de banda (§5) |
| `atlas.loop.net_direction_recency_hours` | `6` | janela do NetDirectionGuard (§10) |
| `atlas.loop.keepalive_enabled` / `_frozen_kill_minutes` / `_reap_after_minutes` | `true` / `15` / `1440` | keepalive (§7.3) |
| `atlas.loop.main_merge_lock_timeout_seconds` / `_max_deferrals` | `8.0` / `5` | lock único de main-merge (§9.1) |
| `atlas.ai.loop.canary_full_coverage` / `canary_require_coverage` | `false` / `false` | canário multi-irmão fail-closed |

---

#### Apêndice · classes/arquivos-âncora citados
`AtlasLoopMasterSwitch.php` · `AtlasLoopHarnessGuard.php` (FORBIDDEN_SELF_TARGETS `:33-`) · `AtlasEvolutionLoopRunner.php` · `AtlasLoopCampaignSupervisor.php` (Campaign/) · `AtlasLoopTaskGrinder.php` · `Discovery/AtlasLoopNextWorkDecider.php` · `AtlasLoopBacklogAutoFeederService.php` · `AtlasLoopAutoMergeService.php` · `AtlasLoopNetDirectionGuard.php` · `AtlasLoopQualityGrader.php` · `AtlasLoopProposalPromotionGate.php` · `Verify/AtlasEngineeringHonestyGate.php` · `Verify/AtlasLoopComprehensionGroundingGate.php` · `Defect/AtlasLoopDefectFalsificationGate.php` · `Attribution/AtlasLoopCapabilityDeltaAttributionService.php` · `AtlasLoopAntiFarmFloor.php` · `AtlasLoopAntiGoodhartUnifiedRefusal.php` · `AtlasLoopGoodhartReceiptLedger.php` · `Sentinels/*` · `AtlasLoopRegressionSentinel.php` · `Models/AtlasLoopProposal.php` · `Models/AtlasLoopCampaign.php` · Commands: `AtlasEvolutionLoopCommand`, `AtlasLoopCampaignCommand`, `AtlasLoopSoakCommand`, `AtlasLoopKeepaliveCommand`, `AtlasLoopAutoMergeCommand`, `AtlasLoopBacklogAutoFeedCommand`, `AtlasLandCommand` · SelfConstruction: `AtlasTaskScopedCommitter.php`, `MultiAgentLoopCertification/*`, `AgentCodexRealInvoker*` (scaffolding).

---

> _Documento gerado por rastreamento de código (file:line) em 2026-07-07. Verifique afirmações contra o código vivo; docs de status podem envelhecer — só receipts e testes provam runtime._
