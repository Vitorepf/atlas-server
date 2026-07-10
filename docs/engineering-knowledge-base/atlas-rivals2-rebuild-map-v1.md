---
id: atlas-rivals2-rebuild-map-v1
type: engineering_knowledge
title: Atlas Rivals 2.0 — Rebuild Map v1 (kill-map do Rivals 1.0)
status: active
category: programming
priority: 100
summary: Kill-map historico do Rivals 1.0 (A/B/C/D) e ponteiro para o canon vivo 2.0. Produto e estrutura vivem em atlas-rivals-product-v1 e atlas-rivals-structure-v1; este doc nao e a fonte de verdade do produto.
tags:
  - atlas
  - rivals2
  - rebuild
  - kill-map
  - benchmark
capabilities:
  - rivals2_rebuild_map
  - rivals1_kill_map
decisions:
  - Rivals 1.0 fracassou e nao sera salvo; Rivals 2.0 e o benchmark interno canonico.
  - Nenhum import de ForgeRivals no namespace Rivals2.
  - As 5 tabelas do 1.0 sao dropadas; o 2.0 usa JSONL append-only com hash chain.
  - Avaliacao per-delivery segue oficial; Rivals 2.0 mede modelos e runtimes.
maintenance:
  - Manter apenas como kill-map A/B/C/D e ponteiro; mudancas de produto vao nos docs atlas-rivals-*-v1.
  - Atualizar contagem de suites externas quando config/atlas_rivals.php mudar.
related_paths:
  - docs/engineering-knowledge-base/atlas-rivals-product-v1.md
  - docs/engineering-knowledge-base/atlas-rivals-structure-v1.md
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
  - app/Services/Ai/Rivals/
  - app/Console/Commands/AtlasRivalsCommand.php
  - config/atlas_rivals.php
  - docs/acde-teto-closure.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-rivals2-rebuild-map-v1
graph_title: Atlas Rivals 2.0 Rebuild Map v1
graph_world: atlas
graph_layer: system
graph_kind: contract
graph_parent: atlas-programming-forge-flow
graph_status: active
graph_source: repo
owner: programming
repo_paths:
  - app/Services/Ai/Rivals/
  - config/atlas_rivals.php
allowed_changes:
  - Registrar progresso dos slices e ajustes de classificacao com justificativa.
forbidden_changes:
  - Reintroduzir codigo do ForgeRivals 1.0 no runtime 2.0.
  - Transformar o 2.0 em leaderboard/score unico colapsado.
depends_on:
  - atlas-rivals-product-v1
flows_to:
  - atlas-rivals-structure-v1
unlocks:
  - rivals2_runtime
governs:
  - rivals2_rebuild
evidence:
  - tests/Unit/Ai/Rivals
  - tests/Feature/Ai/Rivals
required_tests:
  - php artisan test --filter=Rivals
requires_evidence: true
risk_level: medium
next_actions:
  - Manter kill-map sincronizado com remocoes residuais do Slice 6; produto vive nos docs atlas-rivals-*-v1.
---

> Canon vivo do produto: [`atlas-rivals-product-v1.md`](./atlas-rivals-product-v1.md) e [`atlas-rivals-structure-v1.md`](./atlas-rivals-structure-v1.md). Este arquivo e o kill-map historico do 1.0.

## Contrato de nomenclatura (2026-07-03, decisão do operador)

Produto público: **Rivals**. Versão: **2.0**. Comando canônico: `atlas:rivals`
(`atlas:rivals2` é só alias temporário de compat). Config: `config/atlas_rivals.php`
com `version=2.0`. Namespace de código: `App\Services\Ai\Rivals`. Nenhuma
superfície pública nova pode usar "Rivals2"; schema ids `atlas.rivals2.*`, env
vars `ATLAS_RIVALS2_*` ficam como formato interno preservado (runs existentes
+ .env vivo). Storage público: `storage/atlas/rivals` (03/07; `atlas/rivals2`
é symlink de compat). Referências a `atlas:rivals2` abaixo
neste doc são históricas do rebuild.

Benchmark repos externos REAIS: registry em `config/atlas_rivals.php`
(`benchmarks.repos`, os 10 do operador — inclui SWE-Marathon
https://www.swe-marathon.org/), clones pinados em `tools/rivals/benchmarks/`,
smoke real via `atlas:rivals benchmark-smoke --repo=<id> --json` (receipts em
`storage/atlas/rivals/benchmarks/`). Adapter sem smoke verde = blocked, nunca done.
Catálogo: [`atlas-rivals-external-suites-v1.md`](./atlas-rivals-external-suites-v1.md).

## Resumo

Kill-map historico do Rivals 1.0 (classificacao A/B/C/D) e ponteiro para o canon vivo 2.0. Nao e a fonte de verdade do produto.

## Papel no Atlas

Governa o que morreu no 1.0 e o que foi reimplementado como conceito no 2.0. Produto/estrutura/claims vivem nos docs `atlas-rivals-*-v1`.

## Onde Se Encaixa

Camada de medicao (modelos x runtimes) do dominio Programming; separada da avaliacao per-delivery do Loop.

## Contratos

Invariantes fail-closed do 2.0 na secao 9; classificacao A/B/C/D nas secoes 1-7.

## Fluxo

Slices do rebuild: runtime 2.0 (Slices 1-5) -> remocao do 1.0 (Slice 6) conforme secoes 1-8.

## Regras para IA

Proibido importar ForgeRivals no namespace Rivals; proibido leaderboard/score unico; claims sempre escopados. Para produto, ler `atlas-rivals-product-v1` primeiro.

## Escopo de Implementacao

app/Services/Ai/Rivals/, AtlasRivalsCommand (`atlas:rivals`), config/atlas_rivals.php, storage/atlas/rivals/.

## Dependencias

Evidence Ledger proprio (JSONL + hash chain); suites externas apenas como adapters. Canon de produto: atlas-rivals-product-v1.

## Evidencias

Suite tests/{Unit,Feature}/Ai/Rivals; saldo do Slice 6 na secao 8.

## Riscos

Goodhart via corpus auto-autorado (mitigado: corpus fresh + juiz local); claim sem evidencia (mitigado: claim_allowed fail-closed).

## Exemplos

`php artisan atlas:rivals doctor --json` (entrypoint CLI do 2.0).

## Proximas Acoes

Ver next_actions do frontmatter e a secao 8 (saldo projetado do Slice 6).

# Atlas Rivals 2.0 — Rebuild Map v1 (kill-map do Rivals 1.0)

Status: kill-map historico (produto vive em atlas-rivals-product-v1). Autor: Fable 5. Data: 2026-07-02.
Decisão do operador: Rivals 1.0 fracassou e não será salvo. Rivals 2.0 é o **benchmark interno do Atlas**
(local, no Mac do operador, ledger/receipts/evidence/replay/adjudicação próprios, fail-closed).
Suites externas entram somente como adapters/fontes de tarefas. O juiz final é sempre o Rivals 2.0.

Escopo do 2.0 (único):
1. **Modelo vs modelo** por task_type real (custo, tempo, estabilidade, taxa de sucesso, evidência).
2. **Atlas+modelo vs modelo puro** (mesmo modelo/case/orçamento; única variável = runtime bare|atlas_dev|forge|loop).

Relação com per-delivery: a avaliação per-delivery (docs/acde-teto-closure.md) SEGUE sendo a forma oficial
de avaliar entregas do Loop. Rivals 2.0 mede modelos e runtimes, não entregas. Não há conflito.
`allow_rivals_programmatic` (governa o 1.0) permanece `false`; o 2.0 tem flags próprias em `config/atlas_rivals.php`.

Legenda:
- **A** Manter como PADRÃO, reimplementado pequeno no `app/Services/Ai/Rivals/` (proibido importar `ForgeRivals\*`)
- **B** Adaptar CONCEITO, não código
- **C** Aposentar (freeze read-only/deprecated; remoção no Slice 6)
- **D** Deletar no Slice 6

## 1. Serviços `app/Services/Ai/Programming/ForgeRivals/` (63 arquivos, 85.4k linhas)

### Raiz (53 arquivos)

| Arquivo | Linhas | Grupo | Justificativa |
|---|---|---|---|
| AtlasForgeRivalsActionDispatcher.php | 319 | D | Roteador do CLI 1.0; o 2.0 tem comando próprio pequeno |
| AtlasForgeRivalsResponseBuilder.php | 207 | B | Padrão envelope JSON versionado sobrevive como conceito |
| AtlasForgeRivalsSetupService.php | 465 | A | Worktrees isoladas via `git worktree add` — reimplementar pequeno |
| AtlasForgeRivalsDoctorService.php | 230 | A | Health gates read-only (git, disco, higiene) |
| AtlasForgeRivalsPreflightService.php | 297 | B | Conceito de readiness local antes de qualquer spend |
| AtlasForgeRivalsRunPathResolver.php | 124 | A | Fonte única de layout de paths de run |
| AtlasForgeRivalsResetService.php | 121 | B | Cleanup por run_id com audit reason |
| AtlasForgeRivalsCollectEvidenceService.php | 989 | A | Evidence pack com hash por artefato; `present:false + reason_missing` |
| AtlasForgeRivalsEvidencePackVerifierService.php | 704 | A | Re-verificação sha256 fail-closed |
| AtlasForgeRivalsBatteryReplayVerifierService.php | 354 | B | Verificação multi-case — conceito absorvido pelo ReplayVerifier 2.0 |
| AtlasForgeRivalsEvidenceBundleManifestService.php | 449 | B | Bundle portátil hash-pinned; só se transporte for necessário |
| AtlasForgeRivalsEvidencePolicy.php | 192 | B | Estágios/constantes de evidência |
| AtlasForgeRivalsProviderEvidenceDiskGuardService.php | 76 | B | Guard de disco fail-closed |
| AtlasForgeRivalsRunRealService.php | 4803 | D | Monólito de dispatch; gates sobrevivem como CONCEITO (3 confirmações, hash antes/depois, streaming) |
| AtlasForgeRivalsDryRunService.php | 215 | B | Plano sem provider |
| AtlasForgeRivalsPlanRealService.php | 206 | B | Estimativa de custo/blockers pré-run |
| AtlasForgeRivalsReplayService.php | 239 | B | Replay de comandos gravados vs artifacts |
| AtlasForgeRivalsAdjudicatorService.php | 2140 | D | 8 hard gates sobrevivem como LISTA; scoring de qualidade morre |
| AtlasForgeRivalsAdjudicatorV2Service.php | 2556 | D | Scoring per-category + triage = teatro; hard gates já extraídos |
| AtlasForgeRivalsAdjudicatorCalibrationService.php | 375 | D | Knobs de um scoring que morre |
| AtlasForgeRivalsReportService.php | 4759 | D | 4.7k linhas de agregação; ReportBuilder 2.0 é ~300 linhas |
| AtlasForgeRivalsBatteryReportService.php | 2694 | D | Idem |
| AtlasForgeRivalsMatrixReportService.php | 3192 | D | Heatmap/ranking = leaderboard interno, proibido no 2.0 |
| AtlasForgeRivalsBatteryEvidenceService.php | 769 | D | Absorvido pelo EvidencePackBuilder 2.0 |
| AtlasForgeRivalsProviderPerformanceLedgerService.php | 2184 | B | Ledger append-only por provider×model×categoria — conceito; 2.0 usa JSONL+hash chain |
| AtlasForgeRivalsExternalLearningGapService.php | 381 | B | Matriz de cobertura (o que falta medir) |
| AtlasForgeRivalsModeRegistry.php | 152 | D | Modos fair/full_power/topology morrem; 2.0 só tem arms |
| AtlasForgeRivalsModelMatrix.php | 125 | B | Aliases de modelos → ModelRegistry 2.0 config-driven |
| AtlasForgeRivalsProviderModelRegistryService.php | 411 | B | Idem |
| AtlasForgeRivalsInputNormalizer.php | 61 | D | Normalização de um CLI que morre |
| AtlasForgeRivalsBatteryStateService.php | 614 | A | State machine resume-safe (pending/running/completed/failed/invalid) |
| AtlasForgeRivalsCasesRegistry.php | 173 | D | Registry de presets do corpus 1.0 |
| AtlasForgeRivalsStatusService.php | 176 | B | Leitor de estado de run |
| AtlasForgeRivalsEventStream.php | 143 | A | events.jsonl append-only com heartbeat |
| AtlasForgeRivalsNextService.php | 448 | D | Recomendador acoplado ao ledger 1.0 |
| AtlasForgeRivalsDecideSignalProjectionService.php | 2152 | C | Sinal advisory p/ Atlas Decide; religa SÓ quando ledger 2.0 alimentar |
| AtlasForgeRivalsTrustedSignalGateService.php | 259 | B | Gate de claim honesto — conceito no Adjudicator 2.0 |
| AtlasForgeRivalsStatisticalRepeatDryRunService.php | 657 | B | Repetições N p/ variância — vira campo `repetitions` do RunPlan |
| AtlasForgeRivalsRunInventoryService.php | 253 | B | Scanner de runs |
| AtlasForgeRivalsRunBatteryService.php | 1217 | D | Orquestrador one-button do fluxo 1.0 |
| AtlasForgeRivalsFullSmokeService.php | 235 | B | Smoke offline fim-a-fim — vira teste Feature do 2.0 |
| AtlasForgeRivalsArenaRunService.php | 1089 | D | Arena 2-arm do design morto |
| AtlasForgeRivalsIndustrialBenchmarkSuiteService.php | 229 | D | Presets industrial-50/100/200 = corpus auto-autorado |
| AtlasForgeRivalsIndustrialExecutionSuiteService.php | 589 | D | Idem |
| AtlasForgeRivalsProviderArenaReadinessService.php | 724 | D | Readiness da arena morta |
| AtlasCodeProviderArenaSnapshotService.php | 270 | D | Snapshot da arena morta |
| EmptyPresetIsFatalHarnessBug.php | 19 | B | Padrão "preset vazio = bug fatal, nunca no-op" |

### Corpus/ (4 arquivos, 5.3k linhas)

| Arquivo | Linhas | Grupo | Justificativa |
|---|---|---|---|
| AtlasForgeRivalsProviderArenaCorpusService.php | 4469 | D | Corpus auto-autorado = Goodhart; 2.0 usa AtlasBench fresh + suites externas |
| AtlasForgeRivalsCorpusPlannerService.php | 373 | D | Planner do corpus morto |
| AtlasForgeRivalsCorpusFixtureRunnerService.php | 417 | B | Provisionamento de fixture por case — conceito p/ AtlasBench |
| AtlasForgeRivalsCorpusCasesActionService.php | 79 | D | Reader do corpus morto |

### Arms/ (2 arquivos), Schema/ (1), DeepSwe/ (3)

| Arquivo | Linhas | Grupo | Justificativa |
|---|---|---|---|
| Arms/AtlasForgeRivalsArmRegistryService.php | 588 | B | Braço = runner_type×modelo é A ABSTRAÇÃO CERTA; reimplementar como model_id×runtime |
| Arms/AtlasForgeRivalsArmContractService.php | 185 | B | Validação de contrato de braço |
| Schema/AtlasForgeRivalsSchemaContractService.php | 742 | A | Padrão validate()→violations[] fail-closed |
| DeepSwe/AtlasForgeRivalsDeepSweCompatibilityService.php | 386 | D | Especialista fora de escopo (Harbor entra via adapter 2.0) |
| DeepSwe/AtlasForgeRivalsDeepSweTaskParserService.php | 320 | D | Idem |
| DeepSwe/AtlasForgeRivalsDeepSweResultIngestService.php | 1317 | D | Idem |

## 2. Comandos (10)

| Comando | Grupo | Destino |
|---|---|---|
| AtlasForgeRivalsCommand.php (51 ações) | C | Freeze read-only + notice "superseded by atlas:rivals2"; remoção S6 |
| AtlasRivalsHarnessCommand.php | D | Wrapper legacy v1 |
| AtlasAiRivalsStrategyCommand.php | D | Strategy Rivals morto |
| AtlasProgrammingRivalsForgeDryRunCommand.php | D | Pré-dispatcher |
| AtlasProgrammingRivalsForgePreflightCommand.php | D | Pré-dispatcher |
| AtlasProgrammingRivalsEvidencePackCommand.php | D | Delegado ao 1.0 |
| AtlasProgrammingRivalsReadinessCommand.php | D | Delegado ao 1.0 |
| AtlasProgrammingRivalsOneShotEvaluateCommand.php | D | Legacy |
| (novo) AtlasRivals2Command — `atlas:rivals2` | — | Único entrypoint do 2.0 (Slice 1) |
| AtlasRivalsHarness* auxiliares se existirem | D | Varredura no S6 |

## 3. Rotas (19) — nenhuma sobrevive como está

| Rota(s) | Grupo | Justificativa |
|---|---|---|
| GET /ai/voice/rivals | D | Voice Rivals morto (0 turnos) |
| 10× /ai/hyperflow/rivals-battery* | C→D | Deprecar já; remover no S6 (serviço 1985L consome ledger 1.0) |
| 3× /ai/rivals-strategy* | D | Strategy morto (0 cases) |
| 2× /ai/vox/rivals/* | D | Vox morto (0 cases) |
| POST /engineering/benchmarks/suites/{suite}/rivals/battery-plan | D | Battery-plan do 1.0 |
| 2× /frontend/*rival-replay | D | Frontend replay não é benchmark central (0/15 runs) |

O 2.0 NÃO cria rotas HTTP até haver consumidor real; CLI `atlas:rivals2` é a superfície.

## 4. Tabelas/Models (5 tabelas) — nenhuma sobrevive

| Tabela | Model | Grupo | Destino |
|---|---|---|---|
| atlas_strategy_rivals_cases | AtlasStrategyRivalsCase | D | Drop via migration idempotente no S6 (0 rows úteis) |
| atlas_strategy_rivals_reviews | AtlasStrategyRivalsReview | D | Idem |
| atlas_vox_rivals_cases | AtlasVoxRivalsCase | D | Idem |
| ai_rivals_shadow_runs | AiRivalsShadowRun | D | Idem |
| ai_real_execution_rivals_benchmarks | AiRealExecutionRivalsBenchmark | D | Idem |

O 2.0 não cria DB: `storage/atlas/rivals2/` com JSONL append-only + hash chain (decisão: DB só com necessidade provada).

## 5. Controllers/Kernel/Router satélites

| Arquivo | Grupo |
|---|---|
| AtlasAiRivalsStrategyController.php | D |
| AtlasCodeProviderArenaController.php | D |
| AtlasAiHyperflowRivalsBatteryController.php (+Service 1985L) | C→D (S6) |
| Kernel/Architecture/AtlasRivalsStrategy{ReviewRecorder,CaseRegistrar,ReadModel}.php | D |
| Vox/Rivals/VoxRivalsRunner.php | D |
| Voice/AtlasVoiceRivalsRunner.php | D |
| Frontend/AtlasFrontendRivalReplayHarnessService.php (+command) | D |
| Kernel/Architecture certifications ForgeRivals* | C→D (S6, junto com alvos) |

## 6. Testes (69 arquivos, ~37k linhas)

Regra: teste segue o destino do alvo. Grupos D acima ⇒ seus testes são D (deletados no S6 junto com o código).
Testes de deprecation/canon-actions (AtlasRivalsCommandDeprecationTest etc.) ficam C até o S6 e morrem com o comando.
Nenhum teste 1.0 é migrado; o 2.0 nasce com suíte própria em `tests/{Unit,Feature}/Ai/Rivals2/`.

## 7. Docs (42) — destino

- **C/superseded (conteúdo preservado in-place):** operator-battery-v2, native-protocol-v1, evidence-pack-replay-manifest-v1, reliability-lockdown-v1 e demais atlas-forge-rivals-* (frontmatter `status: superseded`).
- **Vivos:** `atlas-rivals-product-v1`, `atlas-rivals-structure-v1`, suites/claims/internal/runbook, thesis/rivals-validation (tese 2.0), este kill-map.
- acde-teto-closure.md recebe nota de coexistência (per-delivery segue oficial; 2.0 = modelos/runtimes).

## 8. Saldo projetado do Slice 6

~85k (services) + ~30k (testes D) + satélites (~8k) ≈ **−110k a −120k linhas**, 19 rotas removidas, 5 tabelas dropadas.

## 9. Invariantes do 2.0 (fail-closed, pétreo)

1. `claim_allowed=false` default; true SÓ com: schema válido + receipts completos + artifacts verificados + repetitions≥min + custo/tempo presentes + replay pass + evidence completo + claim escopado + judge_config pinned (quando houver) + zero mistura de configs.
2. Todo claim escopado: (task_type, suite, cases, model, runtime, budget, environment, repetitions, judge_config?). Nunca "best overall", nunca score único colapsado.
3. Uplift exige mesmo modelo nos dois braços; Atlas não rodou ⇒ `uplift_supported=false`, nunca simulado.
4. Zero imports de `ForgeRivals\*` no namespace `Rivals2` (gate: `rg "ForgeRivals" app/Services/Ai/Rivals2` = vazio).
5. Provider spend só com `ATLAS_RIVALS2_PROVIDER_SPEND=true` + aprovação explícita por run.
6. Benchmark externo nunca é autoridade final; leaderboard privado externo nunca vira claim local.
