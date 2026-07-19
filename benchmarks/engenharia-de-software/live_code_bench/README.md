# live_code_bench

**Intuito:** Engenharia de Software · **Suite id:** `live_code_bench`

## O que mede

Avaliação holística e livre de contaminação da capacidade de código de LLMs. O
LiveCodeBench coleta continuamente problemas novos de contests do LeetCode,
AtCoder e CodeForces (janela May 2023 – March 2024, ~400 problemas) e cobre um
leque maior que só gerar código — self-repair, execução e predição de saída de
teste. No perfil Atlas a suíte roda o cenário **codegeneration**: dado o
enunciado, o modelo escreve a solução e ela é executada contra os testes
oficiais do problema. Capacidades no perfil: **edição de código** (0.50) +
**raciocínio** (0.50).

## Repositório oficial

| campo | valor |
|---|---|
| URL | https://github.com/LiveCodeBench/LiveCodeBench.git |
| adapter (Atlas) | `LiveCodeBenchAdapter` (`app/Services/Ai/Rivals/Adapters/External/LiveCodeBenchAdapter.php`) |
| agente nativo (bare) | `lcb` |
| clone (gitignored) | `tools/rivals/benchmarks/live_code_bench/` |
| instalar | `uv venv --clear --python 3.12 .atlas-venv` → `uv pip install -p .atlas-venv/bin/python -e . "datasets<4" -q` |
| smoke (sem provider) | `python -m lcb_runner.runner.main --help` |
| timeout por unidade | 30 min |

Config canônica: `config/atlas_rivals.php` → `benchmarks.repos.live_code_bench`.
Casos no perfil: `1873_A`, `1873_B`, `1873_D`.

## Como o Atlas roda (os dois braços)

**Regra de ouro:** `with_atlas` = **cérebro Atlas** (`atlas:cli:dev`,
`execution = atlas_cli_dev_efficient`) com Hermes por dentro como runtime;
`bare` = `hermes -z` cru. Nunca se mede `hermes -z` e se chama de Atlas.

- **`bare`:** o runner nativo do LCB (`scripts/rivals_lcb_verboo.py`, cenário
  `codegeneration`, `--n 1`) pede a solução ao modelo cru (`kimi-k2.7` no
  provider Verboo) e a executa contra os testes. Linha de base do provider.
- **`with_atlas`:** mesmo harness e mesmo avaliador, mas a geração passa pelo
  **cérebro Atlas** via bridge governado (`scripts/rivals_lcb_atlas.py`) — esta é
  suíte de engenharia, então o braço roda o Atlas de verdade (plano, patch,
  gate), não o modelo cru. Prova no recibo: `runtime_bridge.execution =
  atlas_cli_dev_efficient`.

O verificador é o mesmo nos dois braços (testes oficiais do problema), então a
comparação é justa: só muda **quem** dirige o modelo.

## Corretor / o que conta como acerto

O harness do LCB executa a solução gerada contra os testes oficiais do problema
e devolve `graded_list`. O adapter lê `graded_list[0]`: `true` = `success`,
qualquer outra coisa = `failure` com `failure_class = model_failure` — erro de
modelo é medido, não escondido. Sem `graded_list` no resultado, o adapter
levanta `live_code_bench_graded_list_missing:<question_id>` (quebra dura, não
falso verde). O LCB não emite timing nem usage por questão; o recibo marca
`field_presence` como ausente com razão (`lcb_omits_per_question_timing`,
`lcb_omits_usage`) em vez de inventar zero.

## Estado atual

- **🟡 → provando ✅.** O overlay do braço with_atlas gravava a prova de execução
  num tempdir efêmero (apagado antes de o attacher ler) → gerava `env_failure`
  falso mesmo com o Atlas rodando de verdade.
- Fix `d48cd222fb`: a prova passa a ser persistida em
  `<scratch>/.rivals_atlas_dev_bridge.json`. Validação em curso na run `arq_f40e`.

Fonte viva do estado: `docs/rivals-warroom.md` §4.

## Relatórios

Relatórios de corrida (JSON) ficam em
`storage/atlas/rivals/runs/<run_id>/report.json` e os recibos por unidade em
`receipts.jsonl`. O resumo de confiabilidade consolidado vive no board do
war-room.
