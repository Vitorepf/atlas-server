# swe_bench_live

**Intuito:** Engenharia de Software · **Suite id:** `swe_bench_live`

## O que mede

Correção de bugs em repositórios reais. SWE-bench-Live é o primeiro conjunto de
tarefas SWE **auto-atualizável, multi-linguagem e multi-OS**, gerado por um
pipeline automatizado de curadoria — a cada mês entram ~50 issues verificadas
novas no split de teste. Cada caso é uma issue real com um sandbox Docker
executável: o agente recebe o repositório na versão quebrada e precisa produzir
um patch que faça os testes `FAIL_TO_PASS` oficiais passarem sem quebrar os
demais. Capacidade única no perfil: **correção de bugs** (`bug_fixing`, 1.00).

Pack claimável local (1×1): `geopandas__geopandas-3132`,
`reata__sqllineage-524`, `conan-io__conan-15377`.

## Repositório oficial

| campo | valor |
|---|---|
| URL | https://github.com/microsoft/SWE-bench-Live.git |
| adapter (Atlas) | `SweBenchLiveAdapter` (`app/Services/Ai/Rivals/Adapters/External/SweBenchLiveAdapter.php`) |
| agente nativo (bare) | `swe_bench_live` (harness oficial de avaliação) |
| clone (gitignored) | `tools/rivals/benchmarks/swe_bench_live/` |
| instalar | `uv venv --clear --python 3.12 .atlas-venv` → `git submodule update --init --depth 1 launch` → `uv pip install -p .atlas-venv/bin/python -e . -q` |
| smoke (sem provider) | `python -m evaluation.evaluation --help` |
| timeout por unidade | 90 min |

Config canônica: `config/atlas_rivals.php` → `benchmarks.repos.swe_bench_live`.

## Como o Atlas roda (os dois braços)

- **`bare`:** o harness nativo `swe_bench_live` dirige o modelo (`kimi-k2.7` no
  provider Verboo) contra o sandbox Docker do caso e roda a avaliação oficial.
  Linha de base do provider cru.
- **`with_atlas`:** o **cérebro Atlas** (`atlas:cli:dev`, via
  `scripts/rivals-swe-live-unit.php` acionando o bridge de dev) executa numa
  worktree isolada, com Hermes como runtime interno — plano, patch, gate. Prova
  no recibo: `runtime_bridge.execution = atlas_cli_dev_efficient`.

**Regra de ouro:** "com Atlas" = cérebro Atlas (`atlas:cli:dev`,
`execution=atlas_cli_dev_efficient`) com Hermes **por dentro**; "bare" =
`hermes -z` cru. Nunca medir `hermes -z` e chamar de Atlas. Esta é suíte de
engenharia, então o braço `with_atlas` roda o cérebro Atlas de verdade, não o
runtime cru rotulado.

A avaliação é a mesma nos dois braços (o harness oficial roda os testes
`FAIL_TO_PASS`/`PASS_TO_PASS` no sandbox e checa o resultado), então a
comparação é justa: só muda **quem** produz o patch.

## Corretor / o que conta como acerto

O harness oficial aplica o patch no sandbox e roda os testes; o adapter lê o
campo `resolved` do resultado nativo:

- `resolved === true` → `success` (o patch fez os testes passarem).
- `resolved === false` → `failure` (`failure_class = model_failure`).
- `eval_status === 'error'` ou ausência de veredito → `error`
  (`environment_failure`).

Quando não resolve, o recibo nomeia a razão: `swe_bench_live:failed_checks=<checks>`
com os testes que ficaram vermelhos, ou o `verdict_reason` nativo, ou o genérico
`swe_bench_live:benchmark_verdict_not_resolved` só quando não há causa melhor —
falha de modelo é medida, não escondida. Tokens/custo do braço bare são
capturados quando o harness os expõe (`field_presence` marca ausência com
`eval_harness_omits_agent_usage`).

## Estado atual

- **Dois braços medem limpo.** ✅ (board do war-room §4: "roda; fantasma
  `swe_live_001`/`astropy-31337` removido; tokens capturam").
- Em corrida real recente (`20260719_154029_99b17bba`), native 2/2 success com
  quatro logs legíveis: o `bare` consumiu 186.141/15.028 tokens e **falhou** os
  dois testes `FAIL_TO_PASS` oficiais
  (`test_geodataframe_geojson_no_bbox`|`test_geodataframe_geojson_bbox`); o
  `with_atlas` consumiu 86.971/5.529, proof v2 válido, mas gerou **patch vazio**
  porque o bridge bloqueou em
  `candidate_preparation_blocked:sandbox_sandbox_git_clone_failed`. Sinal
  honesto (o modelo/bridge errou a tarefa), não artefato — antes ambos viravam o
  genérico `benchmark_verdict_not_resolved`; o fix TDD agora preserva os failed
  checks nativos e projeta `provider_call.error_codes` como causa no braço Atlas.

Fonte viva do estado: `docs/rivals-warroom.md` §4.

## Relatórios

Relatórios de corrida (JSON) ficam em
`storage/atlas/rivals/runs/<run_id>/report.json` e os recibos por unidade em
`receipts.jsonl`. O resumo de confiabilidade consolidado vive no board do
war-room.
