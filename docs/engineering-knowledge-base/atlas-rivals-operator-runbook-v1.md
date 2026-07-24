---
id: atlas-rivals-operator-runbook-v1
type: engineering_knowledge
title: Atlas Rivals — Operator Runbook v1
status: active
category: programming
priority: 97
summary: Runbook operacional do Rivals 2.0 — doctor, benchmarks, smoke, plan, run, verify, adjudicate, report, uplift, ledger. Flags ATLAS_RIVALS2_* e storage.
tags:
  - atlas
  - rivals
  - runbook
  - operator
capabilities:
  - rivals_operator_runbook
decisions:
  - Unico entrypoint CLI e atlas:rivals.
  - Provider spend exige ATLAS_RIVALS2_PROVIDER_SPEND=true e aprovacao por run.
  - Storage canonico e storage/atlas/rivals.
maintenance:
  - Atualizar quando a signature do AtlasRivalsCommand mudar.
related_paths:
  - docs/engineering-knowledge-base/atlas-rivals-curriculum-ladder-and-anti-ceiling-fallacy.md
  - app/Console/Commands/AtlasRivalsCommand.php
  - config/atlas_rivals.php
  - docs/engineering-knowledge-base/atlas-rivals-product-v1.md
  - docs/engineering-knowledge-base/atlas-rivals-external-suites-v1.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-rivals-operator-runbook-v1
graph_title: Atlas Rivals Operator Runbook v1
graph_world: atlas
graph_layer: system
graph_kind: runbook
graph_parent: atlas-rivals-product-v1
graph_status: active
graph_source: repo
owner: programming
repo_paths:
  - app/Console/Commands/AtlasRivalsCommand.php
  - config/atlas_rivals.php
allowed_changes:
  - Documentar novas actions CLI e flags com exemplos.
forbidden_changes:
  - Documentar atlas:forge:rivals como comando vivo.
depends_on:
  - atlas-rivals-product-v1
flows_to:
  - atlas-rivals-claims-and-reporting-v1
unlocks:
  - rivals_operator_use
governs:
  - rivals_cli
evidence:
  - app/Console/Commands/AtlasRivalsCommand.php
required_tests:
  - php artisan test --filter=AtlasRivalsCommand
requires_evidence: true
risk_level: medium
next_actions:
  - Manter exemplos alinhados ao --help do comando.
---

# Atlas Rivals — Operator Runbook v1

## PÉTRO — Curriculum ladder / anti-ceiling fallacy

**Canon:** `docs/engineering-knowledge-base/atlas-rivals-curriculum-ladder-and-anti-ceiling-fallacy.md`

- **80–100% num teste = aprovação de NÍVEL escolar**, nunca teto do modelo, do Atlas, nem “inteligência suprema”.
- Suite saturada → **S_sanity** (regressão/paridade). Multiplicador e claim forte → só em **S_frontier** não saturado.
- Raw ou Atlas dominou o frontier → **promover L_{k+1}** (próximo livro). **Proibido** dizer “50× / N×M impossível para sempre porque já faz 80%”.
- **2026 ≠ 2030 ≠ 2040 ≠ 2050**; o currículo **sobe**. Atlas 80% em teste medíocre = **inútil como meta final** — elevar o nível.
- Essência Rivals: medir com/sem Atlas e **sempre melhorar o Atlas no frontier**, não congelar tabuada.



## Resumo

Como operar o Rivals 2.0 no Mac do operador, fail-closed, sem gastar provider por acidente.

## Papel no Atlas

Superficie CLI unica: `php artisan atlas:rivals` (alias `atlas:rivals2`).

## Onde Se Encaixa

`AtlasRivalsCommand` + `config/atlas_rivals.php` + `storage/atlas/rivals`.

## Contratos

### Flags

| Env | Default | Papel |
|---|---|---|
| `ATLAS_RIVALS2_ENABLED` | false | Liga o produto |
| `ATLAS_RIVALS2_PROVIDER_SPEND` | false | Permite spend (ainda exige aprovacao por run no adapter) |
| `ATLAS_RIVALS2_STORAGE` | `storage/atlas/rivals` | Root de runs/ledger |
| `ATLAS_RIVALS2_*_CMD` | — | Templates CLI de modelos / runtimes |

Schemas internos `atlas.rivals2.*` e env `ATLAS_RIVALS2_*` sao formato preservado; produto publico chama-se Rivals.

### Actions

| Action | Uso |
|---|---|
| `doctor` | Config, storage, ledger chain, registry 10/10, resumo benchmarks |
| `benchmarks` | Status dos 10 repos (`suite_id`, `adapter_resolves`; `--strict` falha se blocked>0) |
| `benchmark-smoke` | Clone/install/smoke (`--repo=` ou todos; `--strict`) |
| `models` / `arms` | Registry |
| `mine` | AtlasBench mine (`--limit=`) |
| `import-cases` | Importa cases externos (`--suite=` canônico, `--file=`, `--source-repo=`) |
| `import-results` | Importa resultado nativo (`--run=`, `--file=`; `--replace-import` para reimport) |
| `plan` | Plano sem spend (`--suite=` canônico; `--cases=` exato; `--budget`, `--max-minutes` + `--approve-provider-spend`) |
| `preflight` | Valida manifest, smoke, commit e storage antes da execução |
| `status` / `resume` / `cancel` | Lifecycle explícito por run (`cancel --reason=` obrigatório) |
| `run` / `run-fake` / `run-bench` | Execucao interna (fake = sem provider). Suites externas: `suite_runs_externally` |
| `verify` | ReplayVerifier |
| `adjudicate` | Hard gates + append ledger |
| `report` / `report-all` | Relatorio escopado (report-all nunca claim_allowed) |
| `report-enterprise` | Relatorio empresarial consolidado Fase A (sempre 10 suites; agregado never claim) |
| `battery` | Dry-run da bateria Fase A (`--mode=bare|uplift|status`); prepare/execute so no Mac |
| `uplift` | bare vs atlas_* (`--model=` obrigatorio; `--strict` falha se unsupported) |
| `bundle` / `verify-bundle` | Bundle portatil e verificacao read-only |
| `closure` | Reexecuta gates e gera closure receipt; `--strict` exige 100%; `--verify` detecta edicao |
| `ledger` | Hash chain (`--verify`) e estado atual (`--verify --semantic`); `--repair-semantic` re-append epoch limpo |
| `autopsy` | Flight recorder: events + receipts + native mode + blockers + uplift exclusions (`--run=`) |

### Contrato de confiança (operador)

Só trate como **fato Atlas** o que passar claim interno + `events_complete` +
native `execute`. Use `atlas:rivals autopsy --run=<id> --json` antes de
confiar em qualquer % do enterprise. Battery execute aborta em env sistematico;
`normalize_only` nunca promove. Ledger com `entry_hash_mismatch` historico →
quarantine epoch + `--repair-semantic` no epoch atual (nao reescrever bytes).

Claim-grade flags:
- `--require-clean-worktree` em `plan` / `battery --mode=prepare|execute`
- `status --run=` reporta `heartbeat_age_seconds` + `stall`
- Doctor inclui `checks.harbor_preflight` para `senior_swe_bench` / `swe_marathon` / `terminal_bench`

### Claim-grade re-battery (sem `--fast`)

```bash
# dry (sem spend)
DRY_RUN=1 scripts/rivals-claim-grade-rebattery.sh bare

# solid bare → uplift (gasto real; worktree limpo)
scripts/rivals-claim-grade-rebattery.sh bare
scripts/rivals-claim-grade-rebattery.sh uplift
```

Critério: `closure --verify` → `authorized=true` **ou** só blockers externos
documentados (`modal_auth`, Docker/Harbor ausente, etc.). `internal_claim_allowed`
por run ≠ `fase_a_100_percent_authorized` no closure.

Bloqueadores operacionais típicos antes do solid spend:
- worktree dirty (use `--require-clean-worktree` / commit ou stash)
- ledger epoch sujo → `ledger --quarantine-epoch` depois `--repair-semantic --run=`
- sample_ci_too_wide → aumentar cases×reps (não mentir adequacy)

O dry-run do script (`DRY_RUN=1`) valida prepare+argv sem spend. Solid bare+uplift
é campanha multi-hora no Mac com Hermes+Verboo.

### BFCL autopsy (produto)

Δ Atlas negativo em BFCL **permanece fato** se for regressão de modelo/solver —
não cosmética. Dissecção:

```bash
# ache o run_id bare / atlas_dev no enterprise ou:
php artisan atlas:rivals autopsy --run=<bfcl_run> --md
```

Classificar cada unit: (a) bug de infra/bridge/env → patch harness;
(b) evaluate/score honesto pior com Atlas → manter no report como regressão.
Nunca apagar Δ negativo para “ficar bonito”.

**Classificação viva (2026-07-12 uplift `20260712_195649_89170b86`):**
- bare ITT = 100%; atlas_dev ITT = 33.33%; Δ = **−66.67 pp**; `outcome=confirmed_negative`; `stop_the_line=true`.
- `excluded_pair_keys` vazio → não é gap de proof; é regressão de produto medida.
- Ação: **não patchar** score; manter fato negativo no enterprise até solver Atlas melhorar.

Loop externo tipico: `import-cases` → `plan` → executar comandos nativos fora do PHP → `import-results` → `verify` → `adjudicate` → `report`.

`plan` tambem congela `preregistration.json` e
`native_execution_manifest.json`. Production import aceita somente bundle
manifest-bound com unit result + native execution receipt por
`case×arm×rep`; JSON agregado permanece harness/legado non-claim.
O native receipt v2 aponta para stdout/stderr legíveis por unidade e exige
`failure_reason` em timeout/environment failure. O RunReceipt v3 carrega essa
razão até autopsy, CSV, Markdown e relatório enterprise.

Modelo real homologado para as baterias Fase A: `verboo_kimi_k2_7`
(`cli_model=kimi-k2.7`). O runner carrega `VERBOO_API_KEY` do Hermes sem
serializar a credencial e registra `runner.mode=execute`. `--dry-run`,
`--normalize-only`, receipt sem tokens e native receipt non-success podem
provar/debugar o harness, mas nunca autorizam claim.

Execucao de uma unidade:

```bash
php scripts/rivals-native-runner.php \
  --manifest=storage/atlas/rivals/runs/<run>/native_execution_manifest.json \
  --cwd=tools/rivals/benchmarks/<suite> \
  --execution-id=<id> \
  --approve-provider-spend
```

### Isolamento

- Worktrees / clones em `tools/rivals/benchmarks/` com venv proprio.
- Nunca symlinkar `vendor/` do repo vivo (incidente wiper).

### Uplift

`runtime_commands` (`atlas_dev`, `forge`, `loop`, `autonomous`) precisam estar setados; senao uplift reporta `uplift_supported=false`.
Nas dez suítes, bare e `atlas_dev` usam o mesmo Kimi/Verboo e **solver paths
diferentes**. As cinco `uplift_families` permanecem um recorte analítico do
enterprise report, não um filtro da bateria. O braço Atlas só é aceito com
`.rivals_atlas_dev_bridge.json` provando `hermes_cli`, modelo exato,
single-provider, Decide desabilitado, fallback desabilitado e usage real.

`local_fake` / `mockllm` so produzem `harness_uplift` / receipts `harness_only=true` — nunca claim de mercado.

Readiness no JSON:
- `pipeline_valid=true`: bytes/receipts/evidence coerentes;
- `internal_claim_allowed=true`: claim escopado interno passou os gates;
- `public_claim_allowed=true`: promocao enterprise/thesis separada;
- `claim_allowed`: alias temporario de `internal_claim_allowed`.

### Closure Fase A

Receipt vivo: `storage/atlas/rivals/fase_a_closure_receipt.json`. Smoke 10/10 e harness CI nao bastam; native batteries reais + uplift por familia + blockers=0 sao obrigatorios para autorizar "Fase A 100%".

O receipt e gerado exclusivamente por `atlas:rivals closure`; a verificacao
recalcula `closure_hash`. Closure exige 10 bundles nativos, cinco
`real_uplift` claim-ready, ledger semantico, tests/docs, workspace clean e
prerequisitos Docker/Modal/CLIs. Cada suite contada precisa de claim interno
vivo, sample policy adequada (default: >=3 cases distintos × >=3 reps),
replay/report/bundle validos, native receipts `success`, modo `execute`, usage
non-zero para provider remoto e zero environment failures. `closure` retorna
erro enquanto nao autorizado; `closure --verify` revalida os gates atuais,
nao apenas o hash historico.

## Fluxo

```bash
php artisan atlas:rivals doctor --json
php artisan atlas:rivals benchmarks --json
php artisan atlas:rivals benchmark-smoke --repo=tau2_bench --json
php artisan atlas:rivals run-fake --json
php artisan atlas:rivals adjudicate --json
php artisan atlas:rivals report --json
php artisan atlas:rivals report-enterprise --json
php artisan atlas:rivals battery --mode=bare --json
php artisan atlas:rivals ledger --verify --json
```

### Fase A — o produto em 4 comandos (Mac + Hermes + Verboo)

Os 10 benches já têm repo + adapter + docs. Atlas só precisa **rodar** e **consolidar**.

```bash
# 0) flags no .env do atlas-server
# ATLAS_RIVALS2_ENABLED=true
# ATLAS_RIVALS2_PROVIDER_SPEND=true
# (opcional se não for Darwin) ATLAS_RIVALS2_FASE_A_ALLOW_EXECUTE=true

# 1) smoke dos 10 (clone/install/smoke — sem gastar provider)
php artisan atlas:rivals benchmark-smoke --json

# 2) dry-run do execute (valida prepare + argv do native-runner — sem spend)
php artisan atlas:rivals battery --mode=execute --kind=bare --approve-provider-spend --dry-run --json

# 3) EXECUTE real nos 10 (Hermes+Verboo) + pipeline + enterprise report
php artisan atlas:rivals battery --mode=execute --kind=bare --approve-provider-spend --json

# 4) executar os dois braços nas 10 suítes; consolidar as 5 famílias analíticas
php artisan atlas:rivals battery --mode=execute --kind=uplift --approve-provider-spend --json
php artisan atlas:rivals report-enterprise --json
php artisan atlas:rivals closure --verify --json
```

Saída do passo 3: `suite_results[]` por bench + `enterprise_report` em
`storage/atlas/rivals/enterprise/`. Agregado dos 10 **nunca** é claim.

Cloud agents **nao** chamam execute sem `--dry-run`.

### Fase A finalize (legado / detalhe)

```bash
php artisan atlas:rivals battery --mode=bare --json
php artisan atlas:rivals battery --mode=prepare --kind=bare --approve-provider-spend --json
php artisan atlas:rivals battery --mode=prepare --kind=uplift --approve-provider-spend --json
```

Cloud agents **nao** chamam `--mode=execute` sem `--dry-run` / native spend.

## Regras para IA

Nao rodar spend sem flag + aprovacao. Preferir `run-fake` para validar harness. Interpretar `blocked` em benchmarks como estado do mundo, nao bug do doctor.

## Escopo de Implementacao

Command + config + storage paths.

## Dependencias

product-v1, external-suites-v1, claims-and-reporting-v1.

## Evidencias

Doctor ok; ledger verified; Command tests.

## Riscos

Spend com flag aberta; clone falho em sandbox; runtime_commands NULL e claim de uplift inventado.

## Exemplos

Ver tabela de actions. Help: `php artisan atlas:rivals --help`.

## Proximas Acoes

Quando novos models/runtimes entrarem no config, documentar o template de comando aqui.
