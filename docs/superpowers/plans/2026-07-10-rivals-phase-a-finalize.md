# Rivals Fase A — BUILD-READY Implementation Plan

> **For agentic workers:** On operator **Build** approval, start at **Wave 1 / Task 1** and proceed task-by-task. Use TDD (failing test → implement → green). REQUIRED: superpowers:executing-plans or subagent-driven-development. Checkboxes (`- [ ]`) track progress.

**Goal:** Fechar Fase A: **10 benches open-source → resultados Hermes+Verboo → relatório empresarial consolidado** com faces **modelo × modelo** e **Atlas × modelo**.

**Architecture:** Estender Rivals 2.0 vivo (`app/Services/Ai/Rivals`, `atlas:rivals`). Não reviver ForgeRivals 1.0 nem one-shot enterprise rubric. Juiz local; suites = adapters. Novo artefato: `atlas.rivals2.enterprise_report.v1`.

**Tech Stack:** PHP 8.4+, Laravel, PHPUnit, Hermes CLI + Verboo (só Mac para spend).

---

## BUILD GATE (leia antes de aprovar)

### Ao dizer **Build**, o agente DEVE:

1. Começar imediatamente em **Wave 1** (código + testes, **zero** provider spend).
2. Seguir a ordem das waves; não pular para Mac execute.
3. Commit/push por wave (ou por task grande) nesta branch.
4. **Nunca** rodar `battery --execute`, `rivals-native-runner` com spend, nem Hermes/Verboo neste cloud.
5. Parar e reportar se PHP/test harness não estiver disponível — instalar PHP 8.4 se possível; senão preparar código + testes e documentar comando de verificação.

### Fora do Build (só Mac do operador, depois):

- Wave 7: smoke real, battery execute, closure com spend.

### Invariantes pétreas

| Regra | Valor |
|---|---|
| Provider real | Só `verboo_kimi_k2_7` via Hermes |
| Credencial | `~/.hermes/.env` → `VERBOO_API_KEY` (nunca no report) |
| Agregado 10 suites | `claim_allowed=false` sempre |
| Média / best overall | Proibido como claim |
| Linhas no enterprise report | Sempre **10** (not_run se faltar) |
| Tokens/tempo ausentes | `missing_data` explícito — nunca 0/0 silencioso |

### North-star

```
10 benches → resultados → relatório empresarial consolidado
  face A: modelo × modelo
  face B: Atlas × modelo (bare vs atlas_dev, mesmo modelo)
```

### Definition of Done (produto)

- [ ] **DoD-1** Schema `atlas.rivals2.enterprise_report.v1` + doc canônico
- [ ] **DoD-2** `atlas:rivals report-enterprise` → JSON + MD + CSV
- [ ] **DoD-3** Capa + 10 suites + 2 faces + gaps honestos
- [ ] **DoD-4** Sem células críticas silenciosas
- [ ] **DoD-5** `atlas:rivals battery` dry-run (CI) / execute (Mac only)
- [ ] **DoD-6** 5 uplift families com proof paths distintos
- [ ] **DoD-7** Usage capture fail-closed nas 10 suites
- [ ] **DoD-8** Testes CI verdes (sem spend)
- [ ] **DoD-9** Runbook Mac copy-paste
- [ ] **DoD-10** Mac: battery real + report legível + closure honesto

---

## Waves (ordem de Build)

| Wave | Nome | Spend? | Entrega |
|---|---|---|---|
| **1** | Enterprise report (acabar com relatório horrível) | Não | Schema + builder + CLI + MD/CSV |
| **2** | Per-run report rico + config fase_a | Não | Markdown/CSV per-run + case packs config |
| **3** | Usage capture (matar 0/0) | Não | Normalizer + contract + testes |
| **4** | Battery orchestrator dry-run | Não | `battery` CLI dry-run/status |
| **5** | Faces model×model + Atlas wiring | Não | Dual-arm plan + uplift surface no report |
| **6** | Closure + docs + runbook | Não | Gates + docs canônicos |
| **7** | Mac execute (pós-código) | **Sim** | 10 bare + 5 uplift + closure |

**Build começa na Wave 1.** Waves 2–6 continuam no mesmo PR/branch até código pronto. Wave 7 espera Mac ligado.

---

## File map

### Create

| Path | Wave |
|---|---|
| `docs/engineering-knowledge-base/atlas-rivals-phase-a-enterprise-report-v1.md` | 1 |
| `app/Services/Ai/Rivals/Core/EnterpriseReportBuilder.php` | 1 |
| `tests/Unit/Ai/Rivals/EnterpriseReportBuilderTest.php` | 1 |
| `tests/Fixtures/Rivals/enterprise_report/` | 1 |
| `app/Services/Ai/Rivals/Support/UsageCaptureContract.php` | 3 |
| `tests/Unit/Ai/Rivals/UsageCaptureContractTest.php` | 3 |
| `app/Services/Ai/Rivals/Core/FaseABatteryOrchestrator.php` | 4 |
| `tests/Feature/Ai/Rivals/FaseABatteryOrchestratorTest.php` | 4 |

### Modify

| Path | Wave |
|---|---|
| `app/Services/Ai/Rivals/Support/SchemaContract.php` | 1 |
| `app/Services/Ai/Rivals/Support/RunPaths.php` | 1 |
| `app/Console/Commands/AtlasRivalsCommand.php` | 1, 4 |
| `app/Services/Ai/Rivals/Core/ReportBuilder.php` | 2 |
| `config/atlas_rivals.php` | 2, 4 |
| `app/Services/Ai/Rivals/Core/NativeResultNormalizer.php` | 3 |
| `app/Services/Ai/Rivals/Adapters/External/*` (conforme suite) | 3 |
| `scripts/rivals_lcb_verboo.py` + agents TB/Harbor conforme gaps | 3 |
| `app/Services/Ai/Rivals/Core/FaseAClosureReceipt.php` | 6 |
| Docs product/structure/claims/runbook/external-suites | 6 |
| `tests/Unit/Ai/Rivals/ReportBuilderV2Test.php` | 1–2 |
| `tests/Feature/Ai/Rivals/AtlasRivalsCommandTest.php` | 1, 4 |
| `tests/Unit/Ai/Rivals/NativeResultNormalizerTest.php` | 3 |
| `tests/Unit/Ai/Rivals/FaseAClosureReceiptTest.php` | 6 |

---

## False friends (não marcar done)

| Parece | Realidade |
|---|---|
| `ExternalTenSuitePipelineTest` verde | Fixture; nunca claim |
| `report-all` | JSON segments ≠ enterprise report |
| `$0` Verboo sem tokens | Mentira → `missing_data` |
| Smoke 10/10 no doc | Só válido no Mac com receipts |
| `atlas_dev` no config | Template ≠ bridge real no Mac |

---

# WAVE 1 — Enterprise report (START HERE ON BUILD)

Objetivo: `atlas:rivals report-enterprise` gera pacote consolidado **sempre com 10 linhas**, capa, faces (mesmo vazias/honestas), gaps, JSON+MD+CSV. Sem spend.

### Task 1.1 — Schema + RunPaths (TDD)

**Files:**
- Modify: `app/Services/Ai/Rivals/Support/SchemaContract.php`
- Modify: `app/Services/Ai/Rivals/Support/RunPaths.php`
- Modify: `tests/Unit/Ai/Rivals/SchemaContractTest.php` (ou criar asserts no EnterpriseReportBuilderTest)

- [ ] **Step 1: Write failing test** — `ENTERPRISE_REPORT` required fields; 9 suite_rows ⇒ violation; schema_version mismatch ⇒ violation

```php
// Expected schema id
'atlas.rivals2.enterprise_report.v1'

// Required top-level (mínimo):
schema_version, built_at, report_hash, claim_allowed, claim_blockers,
executive_summary, suite_rows, model_matrix, atlas_uplift, gaps,
included_run_ids, excluded_run_ids
```

- [ ] **Step 2: Run test — expect FAIL**

```bash
php artisan test --filter=SchemaContractTest
# or EnterpriseReportBuilderTest::test_schema_*
```

- [ ] **Step 3: Implement**
  - `SchemaContract::ENTERPRISE_REPORT = 'atlas.rivals2.enterprise_report.v1'`
  - `REQUIRED[...]` com campos acima
  - Validação extra: `count(suite_rows) === 10` (usar `SuiteRegistry` external count ou config repos count)
  - `claim_allowed` must be `false`
  - `RunPaths::enterpriseDir()`, `enterpriseReportPath()`, `enterpriseMarkdownPath()`, `enterpriseCsvPath()`

- [ ] **Step 4: Run test — expect PASS**

### Task 1.2 — EnterpriseReportBuilder (TDD)

**Files:**
- Create: `app/Services/Ai/Rivals/Core/EnterpriseReportBuilder.php`
- Create: `tests/Unit/Ai/Rivals/EnterpriseReportBuilderTest.php`
- Create: `tests/Fixtures/Rivals/enterprise_report/` (opcional golden)

**Comportamento:**

```text
build():
  1. List SuiteRegistry external suite ids (N=10, ordem estável)
  2. Scan runs; load adjudication+report+uplift when present
  3. For each suite_id: pick best qualifying run (pipeline_valid preferred)
     else status=not_run
  4. suite_rows[] always length 10 with:
     suite_id, status (ok|failed|blocked|missing_data|not_run),
     run_id?, success_rate_itt?, median_wall_ms?, tokens_in_avg?, tokens_out_avg?,
     cost_per_task?, cost_basis?, env_failure_rate?, missing_fields[],
     pipeline_valid, internal_claim_allowed
  5. model_matrix: compare bare arms with different model_ids on same suite freeze
     OR { mode: single_model_battery, model_id: verboo_kimi_k2_7 }
  6. atlas_uplift: for each uplift_families entry, read uplift.json or not_run
  7. gaps: list missing suites, missing_data, uplift missing, zero-token rows
  8. executive_summary: counts ok/failed/not_run, models, runtimes, hermes/verboo note
  9. claim_allowed=false, claim_blockers=['aggregate_view_claims_live_per_run']
  10. report_hash; validate SchemaContract; write JSON/MD/CSV
```

- [ ] **Step 1: Failing tests**
  1. `test_always_emits_ten_suite_rows_when_storage_empty`
  2. `test_marks_pipeline_valid_run_as_ok_and_others_not_run`
  3. `test_claim_allowed_is_always_false`
  4. `test_writes_json_markdown_csv`
  5. `test_missing_tokens_surface_as_missing_data_status`
  6. `test_uplift_section_lists_five_families`
  7. `test_single_model_battery_when_only_one_bare_model`

- [ ] **Step 2: Run — FAIL**

```bash
php artisan test --filter=EnterpriseReportBuilderTest
```

- [ ] **Step 3: Implement `EnterpriseReportBuilder`**
  - Reuse aggregation helpers from `ReportBuilder` where possible (não duplicar Wilson/cost logic às cegas — preferir ler `report.json` rows já built)
  - Markdown: capa executiva legível (título, modelo, tabela 10, faces, gaps) — **não** dump técnico mínimo
  - CSV: uma linha por suite + colunas de métricas

- [ ] **Step 4: Run — PASS**

### Task 1.3 — CLI `report-enterprise`

**Files:**
- Modify: `app/Console/Commands/AtlasRivalsCommand.php`
- Modify: `tests/Feature/Ai/Rivals/AtlasRivalsCommandTest.php`

- [ ] **Step 1: Failing test** — action `report-enterprise` retorna schema + paths; está em allowlist read-only (não exige `ATLAS_RIVALS2_ENABLED` mutating? — alinhar: report-all já é read-only; incluir `report-enterprise` na lista não-mutating L74)

- [ ] **Step 2: Implement** signature action + dispatch `(new EnterpriseReportBuilder)->build()`

- [ ] **Step 3: PASS**

```bash
php artisan test --filter=AtlasRivalsCommandTest
```

### Task 1.4 — Doc canônico (Wave 1)

**Files:**
- Create: `docs/engineering-knowledge-base/atlas-rivals-phase-a-enterprise-report-v1.md`
- Modify pointers em `atlas-rivals-product-v1.md` + `atlas-rivals-claims-and-reporting-v1.md` (1 parágrafo cada)

- [ ] **Step 1:** Doc com seções do schema, non-claim rule, Hermes/Verboo, exemplos CLI
- [ ] **Step 2:** Commit Wave 1

**Wave 1 exit criteria:** testes EnterpriseReport* + command verdes; `report-enterprise` documentado; report vazio ainda mostra 10× `not_run`.

---

# WAVE 2 — Per-run report rico + config

### Task 2.1 — Enriquecer `ReportBuilder::markdown` / `csv`

**Files:** `ReportBuilder.php`, `ReportBuilderV2Test.php`

- [ ] Markdown inclui seções: uplift (se houver), tokens_coverage, statistical_analysis summary, difficulty_flags, missing_data_policy
- [ ] CSV inclui tokens coverage + claim flags
- [ ] Test asserts `tokens_coverage` e `uplift` aparecem no MD quando presentes

### Task 2.2 — `config/atlas_rivals.php` → `fase_a`

```php
'fase_a' => [
    'primary_model' => 'verboo_kimi_k2_7',
    'allowed_providers' => ['hermes'],
    'default_repetitions' => 3,
    'min_distinct_cases' => 3,
    'case_packs' => [
        // suite_id => list of case ids from fixtures (claim pack ≥3)
    ],
],
```

- [ ] Preencher case_packs a partir de `tests/Fixtures/Rivals/cases/*` (≥3 onde existir; senão documentar gap)
- [ ] Test: config count suites == 10

---

# WAVE 3 — Usage capture (matar relatório 0/0)

### Task 3.1 — `UsageCaptureContract`

**Files:** create contract + test

- [ ] Para provider hermes/verboo + tier production: tokens_in/out must have `field_presence.present=true` e valores >0 para elegibilidade de proof/claim
- [ ] cost pode ser 0 com reason `verboo_subscription_marginal` **somente se** usage presente
- [ ] wall_ms present obrigatório

### Task 3.2 — Normalizer/adapter fixes (ordem)

| Priority | Suite | Fix |
|---|---|---|
| P0 | `live_code_bench` | `provider_usage.json` sempre; senão missing_data |
| P0 | `terminal_bench` | agent usage → normalizer |
| P0 | `inspect_evals` | tokens do `.eval` ou present=false |
| P1 | `tau2_bench` | field_presence explícito |
| P1 | Harbor senior/marathon | hermes-usage.json |
| P1 | `swe_bench_live` | predictions.usage.json |
| P2 | bfcl/aider/hal | regressão + testes |

- [ ] Cada suite: teste em `NativeResultNormalizerTest` com fixture “success sem usage” ⇒ present=false reason canônico
- [ ] **Não** inventar tokens

### Task 3.3 — Wire contract no import path

- [ ] Bundle importer / import-results: aplica contract; não promove internal claim path com usage missing (Adjudicator já bloqueia — garantir reason estável no enterprise gaps)

---

# WAVE 4 — Battery orchestrator (dry-run only in CI/cloud)

### Task 4.1 — `FaseABatteryOrchestrator`

**API:**

```text
planBareBattery(): list of per-suite planned actions (no execute)
planUpliftBattery(): 5 families dual-arm
status(): progress from disk
commandsFor(runId): native-runner argv list
```

- [ ] Recusa `execute` se model provider ∉ hermes
- [ ] Recusa default local_fake
- [ ] Dry-run: cria plans? **Decisão:** dry-run **não** persiste plans com spend; só emite manifesto de comandos + valida smoke/cases. Modo `--prepare` (sem spend) pode import-cases + plan **sem** approve se provider_spend false… **Fail-closed:** `--dry-run` = zero writes de run com approve; apenas JSON de intenção. `--prepare` = smoke check + import-cases + plan **sem** native execute (plan ainda exige approve-provider-spend se spend flag — seguir gates atuais do `plan()`).

**Recomendação pétrea para Build:**

1. `battery --dry-run` → JSON intenção (10 suites, commands preview), **no disk mutate**
2. `battery --prepare` → import-cases + plan + preflight (requer flags; ainda sem native runner)
3. `battery --execute` → native runner loop — **Mac only**; cloud agent nunca chama

### Task 4.2 — CLI wiring + tests

- [ ] Feature test: dry-run shape; execute blocked in testing without hermes mock OR execute not called in test
- [ ] Signature update em `AtlasRivalsCommand`

---

# WAVE 5 — Faces (wiring sem spend real)

### Task 5.1 — Model matrix no enterprise report

- [ ] Se ≥2 model_ids bare no mesmo suite com cases iguais → tabela comparativa
- [ ] Senão `single_model_battery`
- [ ] Test com 2 fake arms em storage temp

### Task 5.2 — Atlas uplift section

- [ ] Lê `config atlas_rivals.uplift_families` (5)
- [ ] Para cada: status from uplift.json (`real_uplift` / unsupported / not_run)
- [ ] Test com fixture uplift.json

### Task 5.3 — Plan dual-arm support verification

- [ ] Test/contrato: `--arms=verboo_kimi_k2_7@bare,verboo_kimi_k2_7@atlas_dev` gera manifest com solver paths distintos (já parcialmente em ExternalCommandContractTest — estender se faltar)
- [ ] Opcional segundo modelo Verboo: `verboo_qwen_3_6_27b@bare` só se operador pedir depois

---

# WAVE 6 — Closure + docs + runbook

### Task 6.1 — `FaseAClosureReceipt`

- [ ] Gate `enterprise_report_present`: arquivo existe, hash válido, suite_rows==10
- [ ] Atualizar test fail-closed default
- [ ] Corrigir drift em `atlas-rivals-structure-v1.md` (atlas_dev default existe; L3 native ainda não no Mac)

### Task 6.2 — Runbook Mac

Em `atlas-rivals-operator-runbook-v1.md`:

```bash
php artisan atlas:rivals doctor --json
php artisan atlas:rivals battery --dry-run --json
php artisan atlas:rivals battery --prepare --approve-provider-spend --json   # Mac
php artisan atlas:rivals battery --execute --approve-provider-spend --json  # Mac
php artisan atlas:rivals report-enterprise --json
php artisan atlas:rivals closure --json
```

### Task 6.3 — Sync pointers

- [ ] product / claims / external-suites / structure
- [ ] **Não** reativar one-shot enterprise evaluation doc

---

# WAVE 7 — Mac only (NÃO faz parte do Build cloud)

Ordem de execute:

1. `bfcl` → 2. `inspect_evals` → 3. `tau2_bench` → 4. `aider_polyglot` → 5. `live_code_bench` → 6. `terminal_bench` → 7. `swe_bench_live` → 8. `hal_harness` → 9. `senior_swe_bench` → 10. `swe_marathon`

Depois uplift 5 families → `report-enterprise` → `closure --verify`.

Go/no-go: suite #1 (`bfcl`) com tokens presentes antes de escalar.

---

## Test commands (CI / após cada wave)

```bash
# Wave 1
php artisan test --filter=EnterpriseReportBuilderTest
php artisan test --filter=SchemaContractTest
php artisan test --filter=AtlasRivalsCommandTest

# Wave 2
php artisan test --filter=ReportBuilderV2Test

# Wave 3
php artisan test --filter=UsageCaptureContractTest
php artisan test --filter=NativeResultNormalizerTest

# Wave 4
php artisan test --filter=FaseABatteryOrchestratorTest

# Wave 6
php artisan test --filter=FaseAClosureReceiptTest

# Smoke harness (sem spend)
php artisan test --filter=ExternalTenSuitePipelineTest
```

Se `php` ausente no ambiente: instalar PHP 8.4+ antes de marcar wave verde; não declarar PASS sem output de teste.

---

## Out of scope

- Fase B / AtlasBench peso
- Quality Foundry world_10x (plan 07)
- One-shot Forge rubric
- Public enterprise_claim_gate completo (pode ficar `not_implemented`)
- Uplift forge/loop/autonomous
- Modelos não-Hermes na bateria primary
- UI web
- Spend neste cloud agent

---

## Progress tracker

| Wave | Status |
|---|---|
| 1 Enterprise report | ✅ done |
| 2 Per-run + config | ✅ done (markdown/csv ricos + case_packs) |
| 3 Usage capture | ✅ done (UsageCaptureContract + tests; normalizer suite fixes continuam no Mac) |
| 4 Battery dry-run | ✅ done (`battery --mode=bare|uplift|status`) |
| 5 Faces wiring | ✅ partial (enterprise report já expõe model_matrix + atlas_uplift) |
| 6 Closure + docs | ✅ partial (runbook + enterprise doc; closure gate enterprise opcional próximo) |
| 7 Mac execute | ⬜ blocked (notebook/travel) |

---

## Aprovação

**Diga `Build` (ou “pode implementar”) para iniciar Wave 1 / Task 1.1.**

O agente implementa Waves 1–6 em código+testes nesta branch, sem rodar Rivals real. Wave 7 fica para quando o Mac + Hermes + Verboo estiverem disponíveis.
