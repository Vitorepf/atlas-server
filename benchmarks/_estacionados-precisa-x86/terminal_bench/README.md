# terminal_bench

**Intuito:** Engenharia de Software · **Suite id:** `terminal_bench`

## O que mede

Tarefas de terminal ponta-a-ponta: o agente recebe um ambiente de shell e um
objetivo (consertar um script, resolver um problema de ambiente, editar arquivos)
e é avaliado por um verificador determinístico que roda comandos e checa o estado
final. Capacidades no perfil: **operação de terminal** (0.70) + **edição de
código** (0.30).

## Repositório oficial

| campo | valor |
|---|---|
| URL | https://github.com/laude-institute/terminal-bench.git |
| adapter (Atlas) | `TerminalBenchAdapter` (`app/Services/Ai/Rivals/Adapters/External/TerminalBenchAdapter.php`) |
| agente nativo (bare) | `terminus-2` |
| clone (gitignored) | `tools/rivals/benchmarks/terminal_bench/` |
| instalar | `uv venv --clear --python 3.12 .atlas-venv` → `uv pip install -p .atlas-venv/bin/python -e . -q` |
| smoke (sem provider) | `tb datasets list` |
| timeout por unidade | 60 min |

Config canônica: `config/atlas_rivals.php` → `benchmarks.repos.terminal_bench`.

## Como o Atlas roda (os dois braços)

- **`bare`:** o agente nativo `terminus-2` dirige o modelo (`hermes -z` no
  provider Verboo/kimi) contra o ambiente. Linha de base do provider cru.
- **`with_atlas`:** o **cérebro Atlas** (`atlas:cli:dev`, via
  `scripts/rivals-atlas-dev-bridge.php`) executa numa worktree isolada, com
  Hermes como runtime interno — plano, patch, gate. Prova no recibo:
  `runtime_bridge.execution = atlas_cli_dev_efficient`.

O verificador é o mesmo nos dois braços (a suíte roda o teste oficial e checa o
estado final), então a comparação é justa: só muda **quem** dirige o modelo.

## Corretor / o que conta como acerto

Cada caso tem um teste próprio (`tests/…`) que o harness roda no ambiente após a
tentativa; passa = resolveu. O recibo nomeia a razão da falha
(`terminal_bench:failed_checks=<checks>`) quando o teste não passa — falha de
modelo é medida, não escondida.

## Estado atual

- **Dois braços medem limpo.** ✅
- Casos-fantasma removidos (não existiam no dataset → env_failure determinística):
  `git-bisect`, `kernel-config` → substituídos por `git-workflow-hack`,
  `fix-pandas-version`.
- Em corrida real recente (`20260719_151747`), os dois braços mediram **derrota
  do modelo**: `bare` criou `Hello, world!` com um newline a mais; `with_atlas`
  não criou `hello.txt` (patch vazio). Isso é sinal honesto — o modelo/agente
  errou a tarefa —, não artefato.

Fonte viva do estado: `docs/rivals-warroom.md` §4.

## Relatórios

Relatórios de corrida (JSON) ficam em
`storage/atlas/rivals/runs/<run_id>/report.json` e os recibos por unidade em
`receipts.jsonl`. O resumo de confiabilidade consolidado vive no board do
war-room.
