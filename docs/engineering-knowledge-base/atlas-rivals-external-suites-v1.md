---
id: atlas-rivals-external-suites-v1
type: engineering_knowledge
title: Atlas Rivals — External Suites Catalog v1
status: active
category: programming
priority: 98
summary: Catalogo dos 10 benchmark repos externos do Rivals 2.0. Espelho de config/atlas_rivals.php benchmarks.repos. Adapters sao fonte de tarefa; nunca juiz final.
tags:
  - atlas
  - rivals
  - external-suites
  - adapters
capabilities:
  - rivals_external_suites_catalog
decisions:
  - A lista canonica de suites externas e config/atlas_rivals.php; este doc espelha e explica.
  - Adapter sem smoke verde permanece blocked.
  - SWE-Marathon (https://www.swe-marathon.org/) integra o registry como swe_marathon.
maintenance:
  - Atualizar este catalogo no mesmo PR que alterar benchmarks.repos.
related_paths:
  - docs/engineering-knowledge-base/atlas-rivals-curriculum-ladder-and-anti-ceiling-fallacy.md
  - config/atlas_rivals.php
  - app/Services/Ai/Rivals/Adapters/External/
  - app/Services/Ai/Rivals/Benchmarks/BenchmarkRepoManager.php
  - docs/engineering-knowledge-base/atlas-rivals-structure-v1.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-rivals-external-suites-v1
graph_title: Atlas Rivals External Suites v1
graph_world: atlas
graph_layer: system
graph_kind: contract
graph_parent: atlas-rivals-structure-v1
graph_status: active
graph_source: repo
owner: programming
repo_paths:
  - config/atlas_rivals.php
  - app/Services/Ai/Rivals/Adapters/External/
allowed_changes:
  - Adicionar/remover suite no config e neste catalogo juntos.
forbidden_changes:
  - Declarar suite externa como autoridade final de claim.
depends_on:
  - atlas-rivals-structure-v1
flows_to:
  - atlas-rivals-claims-and-reporting-v1
unlocks:
  - rivals_external_batteries
governs:
  - rivals_external_suites
evidence:
  - php artisan atlas:rivals benchmarks --json
required_tests:
  - php artisan test --filter=ExternalAdaptersIngest
requires_evidence: true
risk_level: medium
next_actions:
  - Manter smoke receipts verdes; N do catalogo = count(config repos).
---

# Atlas Rivals — External Suites Catalog v1

## PÉTRO — Curriculum ladder / anti-ceiling fallacy

**Canon:** `docs/engineering-knowledge-base/atlas-rivals-curriculum-ladder-and-anti-ceiling-fallacy.md`

- **80–100% num teste = aprovação de NÍVEL escolar**, nunca teto do modelo, do Atlas, nem “inteligência suprema”.
- Suite saturada → **S_sanity** (regressão/paridade). Multiplicador e claim forte → só em **S_frontier** não saturado.
- Raw ou Atlas dominou o frontier → **promover L_{k+1}** (próximo livro). **Proibido** dizer “50× / N×M impossível para sempre porque já faz 80%”.
- **2026 ≠ 2030 ≠ 2040 ≠ 2050**; o currículo **sobe**. Atlas 80% em teste medíocre = **inútil como meta final** — elevar o nível.
- Essência Rivals: medir com/sem Atlas e **sempre melhorar o Atlas no frontier**, não congelar tabuada.



## Resumo

Os **10** repos externos registrados em `config/atlas_rivals.php` → `benchmarks.repos`. Clones em `tools/rivals/benchmarks/` (gitignored). Smoke via `atlas:rivals benchmark-smoke`.

## Papel no Atlas

Fonte de tarefas e formatos nativos de resultado. O Rivals importa/ingere; o Adjudicator local decide claim.

## Onde Se Encaixa

Fase A de `atlas-rivals-structure-v1`. Codigo: `BenchmarkRepoManager` + `Adapters/External/*`.

## Contratos

### Regras

- Install/smoke rodam **dentro** do clone com `.atlas-venv` (nunca vendor vivo — licao wiper).
- Sem receipt de smoke exit 0 ⇒ `status=blocked` (nunca "pronto por presuncao").
- Nenhum smoke gasta provider.
- Website/leaderboard externo nunca vira claim local.
- Bateria provider real Fase A usa `verboo_kimi_k2_7`; chave vem do Hermes e
  nunca entra em manifest, argv, report ou ledger.

### Catalogo (espelho do config)

| repo_id (= suite_id) | Origem | adapter | Papel tipico |
|---|---|---|---|
| tau2_bench | sierra-research/tau2-bench | tau2_bench | tool-use / agent dialog |
| bfcl | ShishirPatil/gorilla (BFCL) | bfcl | function calling (adapter proprio; nunca parser tau2) |
| terminal_bench | laude-institute/terminal-bench | terminal_bench | terminal agent |
| senior_swe_bench | snorkel-ai/senior-swe-bench | senior_swe_bench | SWE senior (Harbor) |
| swe_bench_live | microsoft/SWE-bench-Live | swe_bench_live | issue fix fresco |
| live_code_bench | LiveCodeBench/LiveCodeBench | live_code_bench | coding contests vivos |
| inspect_evals | UKGovernmentBEIS/inspect_evals | inspect_evals | evals diversas |
| hal_harness | princeton-pli/hal-harness | hal_harness | long-horizon harness |
| aider_polyglot | Aider-AI/aider | aider_polyglot | polyglot coding |
| swe_marathon | abundant-ai/swe-marathon ([site](https://www.swe-marathon.org/)) | swe_marathon | ultra-long-horizon SWE |

Aliases legados (somente leitura de runs antigas): `tau2_bfcl` → `tau2_bench`, `harbor_terminal_bench` → `terminal_bench`. Plans novos usam `suite_id = repo_id`.

Smoke Marathon: presenca de `tasks/slack-clone` + `harbor --help` (trials reais exigem Modal/provider — fora do smoke).

### Contrato nativo por unit

Todo plan externo produz `native_execution_manifest.json` com uma unidade por
`case×arm×rep`, argv e output unicos. O runner executa a CLI nativa e o
`NativeResultNormalizer` projeta os artifacts oficiais para um unit JSON
hash-pinado; fixture agregada e somente harness/legado.

Cada unidade também produz `native_execution_receipt.v2` com `failure_reason`
obrigatório em status não-success e caminhos relativos hash-pinados para
`native_execution_receipts/logs/<execution_id>.stdout.log` e `.stderr.log`.
O resultado normalizado vira `run_receipt.v3`, que preserva a razão legível;
relatórios agregam o histograma de razões em vez de mostrar apenas a classe.
Bundles só importam logs presentes cujo hash confere.

- tau2: `--save-to`, `--num-trials 1`, seed por rep; Results nativo.
- BFCL: generate por category + evaluate; result/score JSONL.
- terminal_bench: binary `tb`, `--output-path`, run id e attempt unicos;
  agente Verboo preserva `openai/kimi-k2.7` + base URL e captura usage.
- senior_swe_bench / swe_marathon: Harbor jobs-dir/job-name e TrialResult.
- swe_bench_live: predictions + evaluation (`--patch_dir`/`--output_dir`).
- live_code_bench: `--evaluate --n 1`; extracao do `_eval_all.json`.
- inspect_evals: `--sample-id --epochs 1 --log-dir`; provider
  `openai-api/verboo/...` e leitura `.eval` (não forçar `responses_api=false`).
- hal_harness: agent dir/function/name, task id e `_UPLOAD.json`.
- aider_polyglot: reps sao runs `--new`; `--tries` continua interno ao aider.

### Solver swap para uplift

As dez suítes externas têm rota distinta `bare`/`atlas_dev` e preservam o
grader nativo, trocando somente o solver. `uplift_families` continua sendo a
taxonomia analítica de cinco famílias no relatório; não limita a cobertura de
execução da bateria 10×2.

| runtime | prova obrigatoria |
|---|---|
| `bare` | native receipt `success`/`mode=execute`, modelo binding exato e usage non-zero; projeta `metadata.direct_provider` |
| `atlas_dev` | bridge Atlas Dev real no workspace, `hermes_cli` + Kimi exato + fair-mode + usage; projeta `metadata.runtime_bridge` |

O manifest path (nao o model string) vincula cada unit result ao arm canonico.
Isso evita que dois arms com o mesmo native model sejam remapeados para o
mesmo runtime. Se bare e Atlas gerarem o mesmo argv/solver path, o contrato e
invalido e closure nao conta o uplift.

## Fluxo

`benchmarks` (status) → `benchmark-smoke --repo=<id>` → clone/install/smoke → receipt em `storage/atlas/rivals/benchmarks/<id>/`.

## Regras para IA

Ao adicionar suite: atualizar config + este doc + adapter External + fixture de ingest + teste. N = count(repos).

## Escopo de Implementacao

`config/atlas_rivals.php`, `Adapters/External/`, `Benchmarks/BenchmarkRepoManager.php`, fixtures `tests/Fixtures/Rivals/`.

## Dependencias

structure-v1, product-v1.

## Evidencias

`atlas:rivals benchmarks --json` com `total` igual a este catalogo; ingest tests verdes.

## Riscos

Drift doc↔config; smoke falso-verde; tratar leaderboard externo como verdade Atlas.

## Exemplos

```bash
php artisan atlas:rivals benchmarks --json
php artisan atlas:rivals benchmark-smoke --repo=swe_marathon --json
```

## Proximas Acoes

Manter N sincronizado; promover blocked→running so com smoke real.
