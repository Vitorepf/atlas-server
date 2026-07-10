# Rivals Fase A — Finalize de Vez (Implementation Plan)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fechar a Fase A do Rivals 2.0 de uma vez: rodar os **10** benchmarks open-source com **Hermes + Verboo**, coletar resultados honestos, e emitir um **relatório empresarial consolidado** que suporte as duas faces — **modelo × modelo** e **Atlas × modelo** — sem média global como claim e sem o relatório “horrível” atual (dados faltando, só bare, suites zeradas, tokens 0/0).

**Architecture:** Aprofundar o runtime vivo `app/Services/Ai/Rivals` + `atlas:rivals`. Não reviver ForgeRivals 1.0 nem `atlas-rivals-one-shot-enterprise-evaluation-v1` (deprecated / outra camada). O juiz continua local (Adjudicator + evidence + replay + ledger). Suites externas continuam adapters. O novo artefato é um **Enterprise Consolidated Report** (schema v2) gerado a partir de runs adjudicated + uplift, com markdown/csv empresariais.

**Tech Stack:** PHP 8.4+, Laravel, `config/atlas_rivals.php`, adapters External, `scripts/rivals-*`, Hermes CLI + Verboo (`~/.hermes/.env`), Docker/Harbor onde a suite exigir, PHPUnit.

---

## North-star (produto — simples e pétreo)

```
10 benches open-source
  → resultados nativos (Hermes + Verboo)
  → relatório empresarial consolidado com TODOS os dados
  → face A: modelo × modelo
  → face B: Atlas × modelo (mesmo modelo; só muda runtime bare vs atlas_dev)
```

### O que “finalizar de vez” significa

| Marco | Definição de pronto |
|---|---|
| **Fase A operacional** | 10/10 suites com run nativo `runner.mode=execute` + `pipeline_valid=true` + relatório consolidado completo (sem células “não capturado” / `0/0` silenciosos) |
| **Fase A faces** | Pelo menos 1 matriz modelo×modelo **e** 5/5 uplift families bare vs `atlas_dev` com proof real |
| **Fase A 100% (closure)** | `fase_a_100_percent_authorized=true` no receipt anti-tamper (`atlas:rivals closure`) |

Este plano entrega os três. O relatório consolidado é o entregável de produto; o closure é o carimbo de honestidade.

### O que NÃO é este plano

- Fase B (AtlasBench/Elite internalização) — fora.
- `world_10x_quality_proven` / Quality Foundry packet 07 — fora (plano irmão).
- One-shot enterprise rubric Forge (`atlas-rivals-one-shot-enterprise-evaluation-v1`) — **deprecated**, não misturar.
- Rodar Rivals a partir de cloud agent / notebook desligado — **proibido**. Execução real só no Mac do operador com Hermes+Verboo.
- Claim via média dos 10 / “best overall” — **proibido para sempre**.

### Regra de execução (operador)

- Provider real: **somente** `verboo_kimi_k2_7` via Hermes (`provider=hermes`, `cli_model=kimi-k2.7`).
- Credencial: `~/.hermes/.env` → `VERBOO_API_KEY` (nunca serializar em manifest/report/ledger).
- Flags: `ATLAS_RIVALS2_ENABLED=true` + `ATLAS_RIVALS2_PROVIDER_SPEND=true` + `--approve-provider-spend` por plan/runner.
- Agentes cloud **não** disparam native runner nem spend.

---

## Diagnóstico do relatório atual (por que é horrível)

Evidência do run operador (Hermes Kimi bare):

| Sintoma | Causa raiz no sistema |
|---|---|
| 9 suites, não 10 | Sem orquestração 10/10; suite faltante / falha silenciosa no consolidado |
| Só bare; **0 uplift Atlas** | Face Atlas não rodou; `uplift.json` ausente ou unsupported |
| 5 pesados zerados | Harbor/HAL/SWE/Marathon/TB sem bateria nativa verde |
| Tokens `0/0` ou “não capturado” (LCB) | Normalizer/adapters com `field_presence` fraco; usage não chega ao receipt |
| Custo sempre `$0` | Verboo marginal OK **se** usage/tempo existem; sem usage o `$0` é mentira |
| Relatório “técnico” | `ReportBuilder::markdown()` é tabela mínima; `buildAll()` é JSON-only, sem capa empresarial, sem faces, sem gaps honestos |

Código âncora:

- `app/Services/Ai/Rivals/Core/ReportBuilder.php` — `build()` rico em JSON; `markdown()`/`csv()` pobres; `buildAll()` sem export e `claim_allowed: false` (correto para agregado).
- `app/Services/Ai/Rivals/Support/RuntimeProofAttacher.php` — exige tokens > 0 para proof bare.
- `app/Services/Ai/Rivals/Core/NativeResultNormalizer.php` — LCB/TB/inspect/BFCL marcam ausência; várias suites ainda perdem usage.
- `app/Console/Commands/AtlasRivalsCommand.php` — `suite_runs_externally`; sem batch 10.

---

## Spec → task coverage (self-check)

| Promessa | Tasks |
|---|---|
| Relatório empresarial consolidado (capa + 10 linhas + dados) | 1–4, 18 |
| Modelo × modelo na mesma régua | 5–6, 15 |
| Atlas × modelo (5 families + proof) | 7–9, 16 |
| 10/10 native com Hermes+Verboo | 10–14, 17 |
| Captura tokens/tempo/custo honesta | 11–12 |
| Orquestração (não loop manual cego) | 13–14 |
| Closure Fase A 100% | 19–20 |
| Docs + testes + runbook Mac | 21–23 |
| Fora de escopo / anti-Goodhart | § Out of scope |

## False-friend traps (não tratar como done)

| Parece pronto | Na verdade |
|---|---|
| `ExternalTenSuitePipelineTest` verde | Fixture / `native_runner_mode_not_execute` — **nunca** claim |
| Smoke 10/10 no doc | Não há `storage/atlas/rivals` neste clone; smoke é do Mac |
| `runtime_commands.atlas_dev` no config | Template existe; bridge real + `atlas:cli:dev` no Mac ainda precisa provar |
| `report-all` | JSON segments; **não** é relatório empresarial |
| Custo `$0` Verboo | Só honesto com usage+tempo presentes e `field_presence` explícito |
| `fase_a_closure_receipt.json` histórico | `closure --verify` recalcula gates vivos |
| One-shot enterprise evaluation | Outra camada (Forge); não fecha Fase A externa |

---

## File map (create vs modify)

### Create

- `docs/engineering-knowledge-base/atlas-rivals-phase-a-enterprise-report-v1.md` — contrato do relatório consolidado (schema, seções, faces, non-claim agregado)
- `app/Services/Ai/Rivals/Core/EnterpriseReportBuilder.php` — monta pacote consolidado a partir de runs + uplift
- `app/Services/Ai/Rivals/Core/FaseABatteryOrchestrator.php` — orquestra plan→native→import→verify→adjudicate→report (e uplift) por suite
- `app/Services/Ai/Rivals/Support/UsageCaptureContract.php` (ou equivalente) — invariantes de usage por suite
- `tests/Unit/Ai/Rivals/EnterpriseReportBuilderTest.php`
- `tests/Feature/Ai/Rivals/FaseABatteryOrchestratorTest.php` (fake/hermes-mock; sem spend)
- `tests/Unit/Ai/Rivals/UsageCaptureContractTest.php`
- Golden fixtures: `tests/Fixtures/Rivals/enterprise_report/` (JSON + markdown esperado)

### Modify

- `app/Services/Ai/Rivals/Core/ReportBuilder.php` — enriquecer markdown/csv per-run; delegar/integrar consolidado
- `app/Services/Ai/Rivals/Core/NativeResultNormalizer.php` — fechar buracos LCB/TB/inspect/tau2/Harbor usage
- `app/Services/Ai/Rivals/Adapters/External/*` — `field_presence` + agents usage
- `app/Services/Ai/Rivals/Support/RuntimeProofAttacher.php` — alinhar com captura
- `app/Services/Ai/Rivals/Core/FaseAClosureReceipt.php` — aceitar enterprise report como evidência de consolidado
- `app/Console/Commands/AtlasRivalsCommand.php` — actions `report-enterprise`, `battery` (ou `fase-a-run`)
- `config/atlas_rivals.php` — battery defaults, enterprise report paths, sample packs
- `scripts/rivals_*` / agents — garantir usage files
- Docs: `atlas-rivals-product-v1.md`, `structure-v1`, `claims-and-reporting-v1`, `operator-runbook-v1`, `external-suites-v1`
- Tests existentes: `ReportBuilderV2Test`, `ExternalCommandContractTest`, `NativeResultNormalizerTest`

---

## Definition of Done (checklist final)

- [ ] **DoD-1** Schema `atlas.rivals2.enterprise_report.v1` documentado e validado.
- [ ] **DoD-2** `atlas:rivals report-enterprise` gera JSON + Markdown + CSV em `storage/atlas/rivals/enterprise/`.
- [ ] **DoD-3** Relatório tem: capa executiva, tabela 10/10 suites, face modelo×modelo, face Atlas×modelo, gaps honestos, readiness (pipeline/internal/public), **sem** best-overall claim.
- [ ] **DoD-4** Nenhuma célula crítica “silenciosa”: tokens/tempo ausentes ⇒ `missing_data` explícito + bloqueia claim interno daquele escopo.
- [ ] **DoD-5** Battery orchestrator cobre 10 suites bare `verboo_kimi_k2_7` (Mac-only; dry-run testável em CI).
- [ ] **DoD-6** 5 uplift families com solver paths distintos + proof bare/atlas.
- [ ] **DoD-7** Normalizers: LCB, TB, inspect, tau2, Harbor emitem usage ou `field_presence.present=false` com reason canônico.
- [ ] **DoD-8** Testes unit/feature verdes para report + orchestrator (sem spend).
- [ ] **DoD-9** Docs canônicos atualizados; runbook Mac com sequência copy-paste.
- [ ] **DoD-10** No Mac do operador: battery real → enterprise report legível → `closure` com blockers=0 **ou** blockers listados honestamente (nunca falso-verde).

---

## Phase 0 — Congelar o contrato (sem spend)

### Task 0: Congelar Definition of Done + anti-escopo

**Files:**
- Create: este plano (já)
- Modify: nenhum código ainda

- [ ] **Step 1:** Confirmar com operador (já alinhado nesta thread): Fase A = 10 benches → relatório empresarial; faces model×model + Atlas×model; só Hermes+Verboo; não rodar em cloud.
- [ ] **Step 2:** Marcar explicitamente fora: Fase B, world_10x, one-shot Forge rubric, média como claim.
- [ ] **Step 3:** Anotar baseline do relatório horrível (9 suites, 0 uplift, tokens faltando) como anti-regressão de produto.

---

## Phase 1 — Contrato do relatório empresarial

### Task 1: Doc canônico do enterprise report

**Files:**
- Create: `docs/engineering-knowledge-base/atlas-rivals-phase-a-enterprise-report-v1.md`
- Modify: `docs/engineering-knowledge-base/atlas-rivals-claims-and-reporting-v1.md` (pointer)
- Modify: `docs/engineering-knowledge-base/atlas-rivals-product-v1.md` (pointer)

- [ ] **Step 1:** Escrever contrato com seções obrigatórias:

```text
1. Capa executiva
   - modelo(s), runtime(s), data, spend policy, Verboo/Hermes binding
   - readiness: pipeline_valid counts, internal_claim counts, public always gated
2. Matriz 10 suites (sempre 10 linhas)
   - suite_id, status (ok|failed|blocked|missing_data|not_run)
   - success_itt, Wilson95, median_wall, tokens_in/out, cost_per_task
   - env_failure_rate, stability, missing_fields[]
3. Face modelo × modelo
   - mesma suite/cases/budget; arms model_a@bare vs model_b@bare (quando houver)
   - se só 1 modelo na bateria: seção "single_model_battery" explícita
4. Face Atlas × modelo
   - 5 families: bare vs atlas_dev; deltas + stop_the_line
5. Gaps & blockers (honestos)
6. Apêndice: run_ids, report_hashes, ledger tips, excluded_runs
```

- [ ] **Step 2:** Declarar: agregado **nunca** `claim_allowed=true`; claims vivem por run/escopo.
- [ ] **Step 3:** Declarar: custo Verboo `$0` só com `cost_basis=verboo_subscription_marginal` **e** usage presente.
- [ ] **Step 4:** Rodar docs-health quando PHP disponível no Mac.

### Task 2: Schema + SchemaContract

**Files:**
- Modify: `app/Services/Ai/Rivals/Support/SchemaContract.php`
- Create/Modify tests: `tests/Unit/Ai/Rivals/SchemaContractTest.php`

- [ ] **Step 1:** Adicionar constante `ENTERPRISE_REPORT = 'atlas.rivals2.enterprise_report.v1'`.
- [ ] **Step 2:** Validar campos obrigatórios (suite_rows length==10, faces, gaps, hashes).
- [ ] **Step 3:** Teste: payload mínimo válido / inválido (9 suites ⇒ violation).

### Task 3: EnterpriseReportBuilder (núcleo)

**Files:**
- Create: `app/Services/Ai/Rivals/Core/EnterpriseReportBuilder.php`
- Create: `tests/Unit/Ai/Rivals/EnterpriseReportBuilderTest.php`
- Create: `tests/Fixtures/Rivals/enterprise_report/*`

- [ ] **Step 1:** `build(): array` varre `storage/atlas/rivals/runs`, inclui só `pipeline_valid` (ou marca `not_run`/`invalid` para as 10).
- [ ] **Step 2:** Garantir **sempre 10 linhas** (suite registry order); suites sem run ⇒ `status=not_run`.
- [ ] **Step 3:** Montar face Atlas a partir de `uplift.json` das 5 families.
- [ ] **Step 4:** Montar face modelo×modelo a partir de runs com ≥2 model_ids bare no mesmo suite/cases freeze (ou declarar single-model).
- [ ] **Step 5:** Emitir `report_hash`; escrever JSON + Markdown + CSV via `AtomicWriter`.
- [ ] **Step 6:** Testes com fixtures sintéticas (sem provider).

### Task 4: CLI `report-enterprise` + enriquecer per-run markdown

**Files:**
- Modify: `app/Console/Commands/AtlasRivalsCommand.php`
- Modify: `app/Services/Ai/Rivals/Core/ReportBuilder.php` (`markdown`, `csv`)
- Modify: `tests/Unit/Ai/Rivals/ReportBuilderV2Test.php`
- Modify: `tests/Feature/Ai/Rivals/AtlasRivalsCommandTest.php`

- [ ] **Step 1:** Action `report-enterprise` (read-only; não exige spend).
- [ ] **Step 2:** Per-run markdown passa a incluir: uplift, tokens_coverage, statistical segments, missing_data_policy, difficulty_flags.
- [ ] **Step 3:** CSV per-run: colunas tokens_in/out coverage, pipeline_valid, internal_claim_allowed.
- [ ] **Step 4:** Testes de regressão do markdown “não-horrível” (asserts de seções).

---

## Phase 2 — Faces: modelo × modelo e Atlas × modelo

### Task 5: Contrato operacional modelo × modelo

**Files:**
- Modify: `docs/engineering-knowledge-base/atlas-rivals-phase-a-enterprise-report-v1.md`
- Modify: `config/atlas_rivals.php` (opcional: `fase_a.model_matrix`)

- [ ] **Step 1:** Definir matriz mínima Fase A:
  - Primary: `verboo_kimi_k2_7@bare` (obrigatório).
  - Optional second model: só se operador autorizar **outro** braço Hermes/Verboo (ex. `verboo_qwen_3_6_27b`) — **nunca** Claude/Codex/Gemini nesta fase sem decisão explícita.
- [ ] **Step 2:** Mesmos `case_ids`, `repetitions`, `budget`, `preregistration` revision.
- [ ] **Step 3:** Enterprise report seção model_matrix falha fechada se cases diferirem.

### Task 6: Plan dual-arm (mesmo suite, 2 models bare)

**Files:**
- Modify: `app/Services/Ai/Rivals/Core/RunPlan.php` / `ArmRegistry.php` (se necessário)
- Modify: `AtlasRivalsCommand::plan`
- Tests: feature plan com 2 arms

- [ ] **Step 1:** Suportar `--arms=verboo_kimi_k2_7@bare,verboo_qwen_3_6_27b@bare` de ponta a ponta no manifest.
- [ ] **Step 2:** Garantir native manifest 1 unit por case×arm×rep.
- [ ] **Step 3:** Teste de contrato de argv (sem executar provider).

### Task 7: Uplift 5/5 — prerequisites de proof

**Files:**
- Modify: `scripts/rivals-atlas-dev-bridge.php`
- Modify: `app/Services/Ai/Rivals/Support/RuntimeProofAttacher.php`
- Modify: `tests/Unit/Ai/Rivals/RuntimeProofAttacherTest.php`
- Modify: `tests/Feature/Ai/Rivals/ExternalUpliftIngestTest.php`

- [ ] **Step 1:** Bridge prova: `hermes_cli`, model exact, fair-mode, single-provider, Decide off, fallback off, usage present → `.rivals_atlas_dev_bridge.json`.
- [ ] **Step 2:** Bare proof: native receipt success + execute + tokens>0 + Verboo binding.
- [ ] **Step 3:** Rejeitar uplift se bare e atlas geram o mesmo argv/solver path (`commandsAreIndependent`).
- [ ] **Step 4:** Families: `hal_harness`, `swe_bench_live`, `terminal_bench`, `bfcl`, `aider_polyglot`.

### Task 8: Dual-arm plans bare+atlas_dev por family

**Files:**
- Modify: orchestrator (Task 13) + runbook
- Tests: `ExternalCommandContractTest` (já cobre paths; estender se faltar)

- [ ] **Step 1:** Para cada family: plan `--arms=verboo_kimi_k2_7@bare,verboo_kimi_k2_7@atlas_dev`.
- [ ] **Step 2:** Após adjudicate: `atlas:rivals uplift --model=verboo_kimi_k2_7`.
- [ ] **Step 3:** Enterprise report lê os 5 `uplift.json`.

### Task 9: Stop-the-line e honestidade de uplift

**Files:**
- Modify: `AtlasUpliftRunner.php` (já tem stop_the_line; garantir superfície no enterprise report)
- Tests: `AtlasUpliftRunnerTest.php`

- [ ] **Step 1:** Enterprise report expõe `stop_the_line`, multipliers, blockers por family.
- [ ] **Step 2:** Nunca inventar uplift positivo sem `real_uplift` + `internal_claim_allowed` no run.

---

## Phase 3 — Captura de dados (matar 0/0 e “não capturado”)

### Task 10: Inventário canônico de `field_presence` por suite

**Files:**
- Modify: `docs/engineering-knowledge-base/atlas-rivals-external-suites-v1.md`
- Create: tabela no enterprise report doc

- [ ] **Step 1:** Para cada suite, listar fonte de: tokens_in, tokens_out, wall_ms, cost_usd.
- [ ] **Step 2:** Reason codes canônicos (já parcialmente no normalizer): `lcb_omits_*`, `tb_agent_usage_not_reported`, `inspect_logs_omit_usage`, `verboo_subscription_marginal`, etc.

### Task 11: Fechar buracos no NativeResultNormalizer + adapters

**Files:**
- Modify: `NativeResultNormalizer.php`
- Modify: adapters External relevantes
- Modify: `scripts/rivals_lcb_verboo.py`, `rivals_tb_*`, Harbor agents, `rivals_hal_*`
- Modify: `tests/Unit/Ai/Rivals/NativeResultNormalizerTest.php`

Prioridade (do relatório horrível):

| Suite | Fix |
|---|---|
| `live_code_bench` | Garantir `provider_usage.json` sempre escrito; normalizer falha fechada se evaluate ok sem usage |
| `terminal_bench` | Agent Verboo/Atlas grava usage; regex/log fallback documentado |
| `inspect_evals` | Extrair tokens do `.eval`; senão `present=false` |
| `tau2_bench` | `field_presence` explícito; não default true cego |
| Harbor (`senior_swe`, `swe_marathon`) | `hermes-usage.json` obrigatório em success |
| `hal_harness` | Já throw se cost/latency missing — manter; garantir agent emite |
| `swe_bench_live` | `predictions.usage.json` obrigatório em success |
| `bfcl` / `aider` | Validar paths atuais; regressão de tokens |

- [ ] **Step 1:** Implementar fixes suite a suite com teste de fixture.
- [ ] **Step 2:** Adjudicator já bloqueia claim sem tokens — manter; enterprise report mostra `missing_data`.
- [ ] **Step 3:** RuntimeProofAttacher alinhado (tokens>0).

### Task 12: UsageCaptureContract (gate de import)

**Files:**
- Create: `app/Services/Ai/Rivals/Support/UsageCaptureContract.php`
- Wire em: `NativeExecutionBundleImporter` / import-results path
- Tests: `UsageCaptureContractTest.php`

- [ ] **Step 1:** Em tier production + Hermes: import recusa unit success sem usage (ou marca environment_failure / missing_data — decidir fail-closed: **recusar promote a claim**, permitir pipeline_valid só se policy explícita `allow_missing_usage_for_pipeline=false` default).
- [ ] **Step 2:** Decisão pétrea recomendada: **success nativo sem usage ⇒ não é `internal_claim_allowed`**; enterprise report status=`missing_data`.

---

## Phase 4 — Orquestração 10/10 (acabar com loop cego)

### Task 13: FaseABatteryOrchestrator

**Files:**
- Create: `app/Services/Ai/Rivals/Core/FaseABatteryOrchestrator.php`
- Create: `tests/Feature/Ai/Rivals/FaseABatteryOrchestratorTest.php`
- Modify: `config/atlas_rivals.php` → `fase_a.battery`

Config sugerida:

```php
'fase_a' => [
    'primary_model' => 'verboo_kimi_k2_7',
    'default_repetitions' => 3,
    'min_distinct_cases' => 3,
    'suites' => [ /* 10 ids na ordem do registry */ ],
    'uplift_families' => [ /* mirror uplift_families */ ],
    'case_packs' => [
        'tau2_bench' => [...],
        // ...
    ],
],
```

- [ ] **Step 1:** API: `planBattery(mode: bare|uplift|model_matrix)`, `status()`, `resume()`.
- [ ] **Step 2:** Por suite: ensure smoke running → import-cases pack → plan → preflight → emitir lista de native-runner commands (não gastar sozinho sem approve).
- [ ] **Step 3:** Modo `--execute` no Mac: chama `rivals-native-runner.php` unit a unit com spend approval; cloud/CI só `--dry-run` / `--commands-only`.
- [ ] **Step 4:** Após units: import-results → verify → adjudicate → report; uplift quando mode=uplift.
- [ ] **Step 5:** Ao final: `EnterpriseReportBuilder::build()`.

### Task 14: CLI `battery` / `fase-a-run`

**Files:**
- Modify: `AtlasRivalsCommand.php`
- Tests: command feature test

- [ ] **Step 1:** Actions:
  - `battery --mode=bare --dry-run`
  - `battery --mode=bare --execute --approve-provider-spend` (Mac only)
  - `battery --mode=uplift ...`
  - `battery --mode=model_matrix --arms=...`
  - `battery --status`
- [ ] **Step 2:** Fail-closed se model não-Hermes/Verboo em mode execute.
- [ ] **Step 3:** Nunca default `local_fake` em battery production.

### Task 15: Case packs canônicos (3+ cases × suite)

**Files:**
- Modify/expand: `tests/Fixtures/Rivals/cases/<suite>/`
- Modify: config `fase_a.case_packs`
- Modify: runbook

- [ ] **Step 1:** Garantir ≥3 cases distintos por suite para sample policy interna.
- [ ] **Step 2:** Packs “smoke-battery” (1 case) vs “claim-battery” (3+ cases × 3 reps) separados.
- [ ] **Step 3:** `senior_swe_bench`: judge_config path obrigatório no pack.

### Task 16: Uplift battery mode

**Files:**
- Orchestrator + CLI
- Tests dry-run

- [ ] **Step 1:** Só as 5 families.
- [ ] **Step 2:** Exigir bridge health check (`atlas:cli:dev` / hermes) antes de execute.
- [ ] **Step 3:** Enterprise report seção Atlas preenchida ou `not_run` explícito.

---

## Phase 5 — Endurecimento por suite (os 5 que zeram)

### Task 17: Playbook + fixes por suite pesada

Ordem recomendada de ataque no Mac (depois do harness verde):

| Ordem | Suite | Por quê | Prereqs |
|---|---|---|---|
| 1 | `bfcl` | Mais simples / Verboo wrapper | python venv |
| 2 | `inspect_evals` | Rápido; validar usage | inspect-ai |
| 3 | `tau2_bench` | Tool-use | tau2 CLI |
| 4 | `aider_polyglot` | Polyglot; Docker | AIDER_DOCKER |
| 5 | `live_code_bench` | Fechar usage gap | LCB + usage file |
| 6 | `terminal_bench` | Agent custom | Docker + tb |
| 7 | `swe_bench_live` | Clone+eval | network |
| 8 | `hal_harness` | Long-horizon; Miniforge Mac ARM | Mac |
| 9 | `senior_swe_bench` | Harbor + judge | Docker + harbor |
| 10 | `swe_marathon` | Mais longo | Docker/Modal |

Para cada suite:

- [ ] **Step A:** `benchmark-smoke --repo=<id> --strict`
- [ ] **Step B:** import-cases (claim pack)
- [ ] **Step C:** plan + preflight + native units (1 case × 1 rep smoke-real → depois 3×3)
- [ ] **Step D:** verify → adjudicate → report; checar tokens/tempo
- [ ] **Step E:** registrar blockers honestos no enterprise gaps se falhar

**Files típicos por suite:** adapter + script agent + normalizer branch + fixture case + ExternalCommandContractTest row.

### Task 18: Suite #10 sempre presente no report

**Files:**
- `EnterpriseReportBuilder` (Task 3)
- Tests

- [ ] **Step 1:** Mesmo com 0 runs, report lista 10 suites (`not_run`).
- [ ] **Step 2:** Anti-regressão: teste que proíbe consolidado com length≠10.

---

## Phase 6 — Closure + ledger + ops Mac

### Task 19: Alinhar FaseAClosureReceipt ao enterprise report

**Files:**
- Modify: `FaseAClosureReceipt.php`
- Modify: `FaseAClosureReceiptTest.php`
- Modify: `atlas-rivals-structure-v1.md` (estado L3/L4 + atlas_dev default drift)

- [ ] **Step 1:** Gate novo opcional/required: `enterprise_report_present` + hash válido + 10 rows.
- [ ] **Step 2:** Manter gates: smoke 10/10, native 10/10 claim-ready, uplift 5/5, ops prereqs, workspace clean, tests/docs.
- [ ] **Step 3:** Corrigir doc drift: `atlas_dev` agora tem default no config; blocker real = bridge/runtime no Mac, não “unset”.

### Task 20: Prerequisites operacionais (checklist Mac)

Não é código — gate humano antes de `--execute`:

- [ ] Homebrew PHP 8.4+, `uv`, Docker Desktop
- [ ] `hermes` no PATH; `~/.hermes/.env` com `VERBOO_API_KEY`
- [ ] `ATLAS_RIVALS2_ENABLED=true`, `ATLAS_RIVALS2_PROVIDER_SPEND=true`
- [ ] ~20GB+ livres; clones em `tools/rivals/benchmarks/` (nunca symlink vendor vivo)
- [ ] Harbor / Modal conforme suite
- [ ] `atlas:cli:dev` funcional para uplift

### Task 21: Runbook operador (copy-paste)

**Files:**
- Modify: `docs/engineering-knowledge-base/atlas-rivals-operator-runbook-v1.md`

- [ ] **Step 1:** Seção “Fase A finalize — Mac only” com:

```bash
# 0) doctor
php artisan atlas:rivals doctor --json

# 1) smoke all
php artisan atlas:rivals benchmark-smoke --json

# 2) battery bare (dry-run primeiro)
php artisan atlas:rivals battery --mode=bare --dry-run --json
php artisan atlas:rivals battery --mode=bare --execute --approve-provider-spend --json

# 3) uplift families
php artisan atlas:rivals battery --mode=uplift --execute --approve-provider-spend --json

# 4) relatório empresarial
php artisan atlas:rivals report-enterprise --json

# 5) closure
php artisan atlas:rivals closure --json
php artisan atlas:rivals closure --verify --json
```

- [ ] **Step 2:** Troubleshooting: tokens 0/0, smoke blocked, Harbor, Verboo creds missing.

### Task 22: Testes de aceitação (CI sem spend)

**Files:**
- Todos os tests criados acima
- Opcional: `scripts/rivals-harness-verify.sh` estender

- [ ] **Step 1:** CI prova: schema, enterprise builder fixtures, orchestrator dry-run, normalizer usage contracts, command wiring.
- [ ] **Step 2:** CI **não** chama Hermes/Verboo.
- [ ] **Step 3:** Label claro: “harness green ≠ Fase A 100%”.

### Task 23: Sync KB / projections (Mac pós-merge)

- [ ] `atlas engineering knowledge sync --prune` (quando Atlas CLI/PHP disponíveis)
- [ ] `atlas engineering knowledge index-code --prune`
- [ ] Re-projetar AGENTS/CLAUDE se memória mudar

---

## Phase 7 — Execução real no Mac (fora do cloud agent)

### Task 24: Go/no-go suite #1

- [ ] Rodar `bfcl` end-to-end claim-pack (3×3) com Hermes+Verboo.
- [ ] Conferir enterprise report parcial: 1 ok + 9 not_run, tokens presentes.
- [ ] Se falhar: parar e corrigir captura/adapter antes de escalar.

### Task 25: Escalar 2→10 bare

- [ ] Seguir ordem Task 17.
- [ ] Após cada suite: regenerar `report-enterprise`; gaps devem encolher.
- [ ] Não mascarar zero: status `failed` com failure_class.

### Task 26: Uplift 5 families

- [ ] Só depois de bare estável nas 5 families.
- [ ] Conferir proof metadata e deltas no enterprise report.

### Task 27: Closure final

- [ ] `atlas:rivals closure --strict`
- [ ] Só declarar “Fase A 100%” se `fase_a_100_percent_authorized=true`.
- [ ] Arquivar enterprise report + closure receipt como evidência.

---

## Ordem de implementação sugerida (agentes)

```text
Phase 0  Task 0
Phase 1  Tasks 1 → 2 → 3 → 4     # relatório deixa de ser horrível (mesmo com fixtures)
Phase 2  Tasks 5 → 6 → 7 → 8 → 9 # faces
Phase 3  Tasks 10 → 11 → 12      # dados honestos
Phase 4  Tasks 13 → 14 → 15 → 16 # orquestração
Phase 5  Task 17–18              # suites pesadas (código + Mac)
Phase 6  Tasks 19 → 23          # closure + docs + CI
Phase 7  Tasks 24 → 27          # só Mac operador
```

Paralelizável com segurança:

- Task 1 (doc) ∥ Task 2 (schema) depois de DoD freeze
- Task 11 suite fixes em PRs separados por suite
- Task 3 (builder) ∥ Task 13 (orchestrator) após schema estável

Não paralelizar: Task 11 LCB/TB com Task 12 (contrato de import) sem coordenação.

---

## Riscos e mitigações

| Risco | Mitigação |
|---|---|
| Cloud agent tenta gastar provider | Battery `--execute` recusa sem Hermes local + flag; docs + este plano |
| Relatório bonito com dados falsos | `field_presence` + UsageCaptureContract + DoD-4 |
| Uplift simulado | Proof attacher + bridge JSON + commands independents |
| Marathon/Harbor estouram tempo | Packs progressivos 1×1 → 3×3; max-minutes no plan |
| Doc one-shot confunde agentes | Pointers “deprecated / wrong layer” |
| Wiper/vendor symlink | Clones isolados + venv; nunca vendor vivo |
| Claim pela média dos 10 | Enterprise report `claim_allowed=false` no agregado |

---

## Out of scope (explícito)

1. Fase B corpus interno / ContaminationGuard refresh strategy além do necessário para não quebrar.
2. Quality Foundry `world_10x` / packet 07.
3. Public `enterprise_claim_gate` completo (`enterprise_claim_gate_not_implemented` no Adjudicator) — pode permanecer blocked; Fase A fecha **internal** + relatório consolidado.
4. Runtimes `forge` / `loop` / `autonomous` uplift.
5. Modelos não-Verboo na bateria primary.
6. UI/HTTP/dashboard web — CLI + artefatos markdown/csv/json only (terminal-first).
7. Execução neste cloud workspace sem Mac/Hermes.

---

## Evidências que este plano usa (já no repo)

- Product/structure/claims/runbook/external-suites docs
- `config/atlas_rivals.php` (10 repos, `verboo_kimi_k2_7`, uplift_families)
- `ReportBuilder`, `Adjudicator`, `FaseAClosureReceipt`, `AtlasUpliftRunner`, `RuntimeProofAttacher`, `NativeResultNormalizer`
- Scripts `rivals-native-runner.php`, `rivals-hermes-bare.php`, `rivals-atlas-dev-bridge.php`, agents TB/HAL/Harbor/BFCL/LCB
- Tests: ExternalTenSuitePipeline, ExternalCommandContract, ReportBuilderV2, FaseAClosureReceipt, RuntimeProofAttacher, NativeResultNormalizer

## Evidências que ainda faltam (só no Mac)

- `storage/atlas/rivals/benchmarks/*/latest.json` smoke 10/10
- `storage/atlas/rivals/runs/*` native execute receipts
- `uplift.json` × 5 families
- `storage/atlas/rivals/enterprise/*` report gerado
- `fase_a_closure_receipt.json` com `fase_a_100_percent_authorized=true`

---

## Acceptance demo (quando o Mac voltar)

1. `battery --mode=bare --dry-run` mostra 10 plans/commands.
2. Após execute: `report-enterprise` abre markdown com **10 linhas**, tokens/tempo preenchidos ou `missing_data` explícito, capa executiva legível.
3. `battery --mode=uplift` preenche seção Atlas (5 families) ou gaps honestos.
4. Opcional model_matrix com segundo modelo Verboo.
5. `closure --verify` reflete a realidade — verde só se blockers=[].

**Pronto de produto:** um humano lê o markdown consolidado e entende, em uma página, o que passou, o que falhou, quanto custou/tempo/tokens, e se Atlas ajudou ou não — sem score único mentiroso.
