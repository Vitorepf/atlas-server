# senior_swe_bench

**Intuito:** Engenharia de Software · **Suite id:** `senior_swe_bench`

## O que mede

Senior SWE-Bench (Snorkel AI, versão `v2026.06`): tarefas reais de repositórios
GitHub — uma **feature sub-especificada** ou uma **investigação de bug** — que um
agente + modelo precisa resolver dentro do harness **Harbor**. Cada task carrega
dimensões de julgamento (`verdicts`) que um judge (LLM-as-judge, dimensões que
**nunca colapsam** num único número) somado ao verificador usa para decidir se a
task foi resolvida. O Atlas mede o **subset público de 50 tasks** (`public_50_only`).
Capacidades no perfil: **edição de código** (0.60) + **correção de bug** (0.40).

## Repositório oficial

| campo | valor |
|---|---|
| URL | https://github.com/snorkel-ai/senior-swe-bench-v2026.06.git |
| adapter (Atlas) | `SeniorSweBenchAdapter` (`app/Services/Ai/Rivals/Adapters/External/SeniorSweBenchAdapter.php`) |
| agente nativo (bare) | `claude-code` (default do repo); override por modelo → `hermes` (agente Harbor `rivals_harbor_hermes_agent:VerbooHermes`) |
| clone (gitignored) | `tools/rivals/benchmarks/senior_swe_bench/` |
| instalar | `uv venv --clear --python 3.12 .atlas-venv` → `uv pip install -p .atlas-venv/bin/python harbor -q` |
| smoke (sem provider) | `harbor --help` |
| timeout por unidade | 120 min |

Config canônica: `config/atlas_rivals.php` → `benchmarks.repos.senior_swe_bench`.

## Como o Atlas roda (os dois braços)

Os dois braços rodam o **mesmo** `harbor run` (mesmo dataset, mesmo verificador,
mesmo judge); só troca **quem** dirige o modelo, via o agente importado pelo Harbor:

- **`bare`:** agente `rivals_harbor_hermes_agent:VerbooHermes` — `hermes -z` cru no
  provider Verboo/kimi resolve a task. Linha de base do provider cru.
- **`with_atlas`:** agente espelho `rivals_harbor_atlas_agent:AtlasDev`
  (`scripts/rivals_harbor_atlas_agent.py`), que espelha host↔ambiente e roda o
  bridge governado (`scripts/rivals-atlas-dev-bridge.php`): o **cérebro Atlas**
  (`atlas:cli:dev`) — plano, patch, gate. Prova no recibo:
  `runtime_bridge.execution = atlas_cli_dev_efficient`.

**Regra de ouro:** "com Atlas" = **cérebro Atlas** (`atlas:cli:dev`,
`execution=atlas_cli_dev_efficient`) com Hermes por dentro; "bare" = `hermes -z`
cru. Nunca medir `hermes -z` e chamar de Atlas. Esta é suíte de **engenharia** — o
braço `with_atlas` roda o cérebro Atlas de verdade, não um wrapper.

## Corretor / o que conta como acerto

O harness Harbor roda o judge por dimensões + o verificador oficial e escreve
`resolved` por task. O adapter mapeia o veredito honestamente:

- `resolved = true` → **success** (a task foi resolvida = acerto).
- `resolved = false` → **model_failure** (`verifier_did_not_resolve_task`).
- `resolved` ausente → **invalid_result** (`result_missing_resolved_verdict`).
- `exception_info` presente → **environment_failure** (a razão nativa é copiada
  para o recibo; nunca confundida com erro de modelo).

Guardas duros: o adapter **falha** com `senior_swe_bench_judge_config_missing` se o
`judge_config` não vier, e com `senior_swe_bench_coverage_mismatch` se a cobertura
não for `public_50_only` — sem judge ou fora do subset público não há medição. O
tipo da task também é validado: `feature` → `feature_under_specified`, `bug` →
`bug_investigation`. Falha de modelo é **medida**, não escondida.

## Estado atual

- **⏳ Atlas ainda não rodou limpo nesta leva.**
- Diagnóstico fechou (24/24 receipts), mas a última corrida é **não-claimável**:
  contaminação por hot reload + caso manual inválido `ssb_0034`. Bateria limpa
  ainda pendente. Dono para consertar: **Codex**.

Fonte viva do estado: `docs/rivals-warroom.md` §4.

## Relatórios

Relatórios de corrida (JSON) ficam em
`storage/atlas/rivals/runs/<run_id>/report.json` e os recibos por unidade em
`receipts.jsonl`. O resumo de confiabilidade consolidado vive no board do
war-room.
