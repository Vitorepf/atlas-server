# aider_polyglot

**Intuito:** Engenharia de Software · **Suite id:** `aider_polyglot`

## O que mede

O benchmark polyglot do Aider (ferramenta de pair programming por linha de
comando). Cada caso é um exercício de código estilo Exercism em uma de várias
linguagens: o agente recebe o enunciado mais os arquivos-fonte e precisa editar
o código para passar uma bateria de testes unitários escondidos. Não é
conversa nem terminal livre — é **edição de código** que ou passa os testes ou
não. Capacidade no perfil: **edição de código** (1.00).

## Repositório oficial

| campo | valor |
|---|---|
| URL | https://github.com/Aider-AI/aider.git |
| adapter (Atlas) | `AiderBenchAdapter` (`app/Services/Ai/Rivals/Adapters/External/AiderBenchAdapter.php`) |
| agente nativo (bare) | `aider` |
| clone (gitignored) | `tools/rivals/benchmarks/aider_polyglot/` |
| instalar | `uv venv --clear --python 3.12 .atlas-venv` → `uv pip install -p .atlas-venv/bin/python -e . -q` → `uv pip install -p .atlas-venv/bin/python -r requirements/requirements-dev.txt -q` → clonar `polyglot-benchmark` em `tmp.benchmarks/polyglot-benchmark` |
| smoke (sem provider) | `python benchmark/benchmark.py --help` |
| timeout por unidade | 45 min |

Config canônica: `config/atlas_rivals.php` → `benchmarks.repos.aider_polyglot`.

Os exercícios não moram no repo do Aider: o último passo do install clona o
dataset `Aider-AI/polyglot-benchmark` para dentro de
`tmp.benchmarks/polyglot-benchmark`, e é lá que o harness roda os casos.

## Como o Atlas roda (os dois braços)

- **`bare`:** o agente nativo `aider` dirige o modelo
  (`benchmark/benchmark.py … --model openai/kimi-k2.7 --edit-format diff
  --tries 2 --num-tests 1`) contra o exercício. Linha de base do provider cru.
- **`with_atlas`:** o **cérebro Atlas** (`atlas:cli:dev`), via
  `scripts/rivals-aider-polyglot-unit.php` → `scripts/rivals-atlas-dev-bridge.php`,
  executa numa worktree isolada com Hermes como runtime interno — plano, patch,
  gate. Prova no recibo: `runtime_bridge.execution = atlas_cli_dev_efficient`.

**Regra de ouro:** `with_atlas` = cérebro Atlas de verdade
(`atlas:cli:dev`, `execution = atlas_cli_dev_efficient`) com Hermes por dentro;
`bare` = runtime cru (`hermes -z` / agente nativo). Nunca medir `hermes -z` e
chamar de Atlas — se o recibo não trouxer `atlas_cli_dev_efficient`, o braço não
é Atlas. Esta é suíte de engenharia: o braço `with_atlas` roda o cérebro Atlas,
não um wrapper.

O corretor é o mesmo nos dois braços (a mesma bateria de testes oficiais roda
sobre o código final), então a comparação é justa: só muda **quem** dirige o
modelo.

## Corretor / o que conta como acerto

Cada exercício traz seus próprios testes unitários. O harness roda os testes
sobre o código depois da tentativa e reporta `tests_outcomes` (um resultado por
rodada de teste). O adapter olha o **último** resultado: `true` = passou =
sucesso; qualquer outra coisa = `model_failure` — o modelo/agente errou e isso é
medido, não escondido.

`tests_outcomes` vazio é tratado à parte: significa que o agente/harness abortou
**antes** dos testes rodarem, então vira `environment_failure` (falha honesta de
ambiente, não campo faltando). Nesse caso o recibo também marca métricas de
tokens/custo/tempo como ausentes com a razão (`aider_aborted_before_usage`) em
vez de fingir número.

## Estado atual

- **Dois braços medem limpo.** ✅
- Em corrida recente o `with_atlas` **empatou o `bare`** (0.11 = 0.11), com dado
  limpo — sem casos-fantasma, sem `environment_failure` mascarando derrota de
  modelo. Empate é sinal honesto: nesta amostra o cérebro Atlas não abriu
  vantagem sobre o `aider` cru dirigindo o mesmo modelo.

Fonte viva do estado: `docs/rivals-warroom.md` §4.

## Relatórios

Relatórios de corrida (JSON) ficam em
`storage/atlas/rivals/runs/<run_id>/report.json` e os recibos por unidade em
`receipts.jsonl`. O resumo de confiabilidade consolidado vive no board do
war-room.
