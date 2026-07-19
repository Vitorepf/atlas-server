# bfcl

**Intuito:** Engenharia de Software · **Suite id:** `bfcl`

## O que mede

Berkeley Function Calling Leaderboard: a **primeira avaliação abrangente e
executável de chamada de função**, medindo se o modelo sabe invocar a função
certa, com os argumentos certos, no formato certo. Não é geração de texto — é o
modelo lendo um schema de ferramentas e emitindo a chamada correta (nome +
argumentos), checada por AST e por execução. No perfil do Atlas roda o
subconjunto de categorias `simple`, `multiple`, `parallel`. Capacidade no
perfil: **uso de ferramenta** (`tool_use`, peso 1.00).

## Repositório oficial

| campo | valor |
|---|---|
| URL | https://github.com/ShishirPatil/gorilla.git |
| adapter (Atlas) | `BfclAdapter` (`app/Services/Ai/Rivals/Adapters/External/BfclAdapter.php`) |
| agente nativo (bare) | `bfcl` (harness próprio do leaderboard) |
| clone (gitignored) | `tools/rivals/benchmarks/bfcl/` |
| instalar | `uv venv --clear --python 3.12 .atlas-venv` → `uv pip install -p .atlas-venv/bin/python -e ./berkeley-function-call-leaderboard soundfile -q` |
| smoke (sem provider) | `bfcl test-categories` |
| timeout por unidade | 15 min |

Config canônica: `config/atlas_rivals.php` → `benchmarks.repos.bfcl`.

## Como o Atlas roda (os dois braços)

**Regra de ouro:** `with_atlas` = **cérebro Atlas** (`atlas:cli:dev`,
`execution=atlas_cli_dev_efficient`) com **Hermes por dentro** como runtime;
`bare` = `hermes -z` cru. Nunca medir `hermes -z` e chamar de Atlas.

- **`bare`:** o harness oficial da BFCL (`scripts/rivals_bfcl_verboo.py generate`)
  dirige o modelo (`hermes -z`, default `kimi-k2.7-FC` no provider Verboo) contra
  cada categoria. Linha de base do provider cru.
- **`with_atlas`:** o **cérebro Atlas** (`atlas:cli:dev`, via
  `scripts/rivals-bfcl-atlas-unit.php`, `runtime=atlas_dev`) resolve o caso —
  plano, decisão de tool-call, formato — com Hermes como runtime interno. Prova
  no recibo: `runtime_bridge.execution = atlas_cli_dev_efficient`.

Esta é suíte de **engenharia**: o braço `with_atlas` roda o cérebro Atlas de
verdade, não um wrapper fino. O corretor é o mesmo nos dois braços (mesma checagem
AST/executável), então a comparação é justa: só muda **quem** produz a chamada.

## Corretor / o que conta como acerto

A BFCL checa a chamada de função por AST (nome da função + tipos/valores de
argumento) e, quando aplicável, por execução. O adapter (`mapResults`) trata
`accuracy >= 1.0` como `success`, caso contrário `failure`; sem status nem
accuracy no resultado, ele **lança erro** em vez de assumir acerto. A razão da
falha vira `failure_class` no recibo (`model_failure` ou `timeout`) — erro de
formato/argumento é medido, não escondido.

## Estado atual

- **Dois braços medem limpo.** ✅
- Roda; o déficit observado é **fricção de categoria** — function-calling
  resolvido via patch-plan no braço Atlas — com o **formato OK**. Não é
  artefato: é sinal honesto de onde o cérebro perde contra o harness nativo em
  chamada de função pura.

Fonte viva do estado: `docs/rivals-warroom.md` §4 (linha `bfcl`).

## Relatórios

Relatórios de corrida (JSON) ficam em
`storage/atlas/rivals/runs/<run_id>/report.json` e os recibos por unidade em
`receipts.jsonl`. O resumo de confiabilidade consolidado vive no board do
war-room (`docs/rivals-warroom.md` §4).
