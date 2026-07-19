# swe_marathon

**Intuito:** Engenharia de Software · **Suite id:** `swe_marathon`

## O que mede

Trabalho de engenharia de software **ultra-longo-horizonte**: o agente recebe um
objetivo grande (construir um app inteiro — `slack-clone`, reescrever um front
com `nextjs-vite-rewrite`, implementar um `zstd-decoder`) e trabalha por horas
até um verificador oficial rodar e decidir se a tarefa está resolvida. A pergunta
da suíte é literal: *"agentes conseguem completar trabalho de software
ultra-longo-horizonte de forma autônoma?"* Capacidades no perfil: **trabalho de
longo prazo** (`long_horizon`, 0.70) + **edição de código** (`code_editing`,
0.30). Peso no composto público: `0.10`.

## Repositório oficial

| campo | valor |
|---|---|
| URL | https://github.com/abundant-ai/swe-marathon.git |
| site | https://www.swe-marathon.org/ |
| adapter (Atlas) | `SweMarathonAdapter` (`app/Services/Ai/Rivals/Adapters/External/SweMarathonAdapter.php`) |
| runner | Harbor + Modal (env `docker` p/ tasks CPU; `modal` só quando `ATLAS_RIVALS2_MARATHON_ENV=modal`, ex.: GPU) |
| agente nativo (bare) | `claude-code` por default; a corrida medida usa o agente Harbor `VerbooHermes` (Hermes/kimi via Verboo) |
| clone (gitignored) | `tools/rivals/benchmarks/swe_marathon/` |
| instalar | `uv venv --clear --python 3.12 .atlas-venv` → `uv pip install -p .atlas-venv/bin/python "harbor==0.17.1" modal -q` |
| smoke (sem provider) | `test -d tasks/slack-clone && harbor --help && modal --version` |
| timeout por unidade | 360 min (6 h) |

Config canônica: `config/atlas_rivals.php` → `benchmarks.repos.swe_marathon`.

## Como o Atlas roda (os dois braços)

Os dois braços rodam **o mesmo `harbor run`**, na mesma task e no mesmo
verificador — só troca **quem dirige o modelo** (o `--agent-import-path`):

- **`bare`:** agente Harbor `rivals_harbor_hermes_agent:VerbooHermes` — Hermes
  dirige `kimi-k2.7` via Verboo (`code.verboo.ai`) contra o ambiente. Linha de
  base do provider cru.
- **`with_atlas`:** o **cérebro Atlas** (`atlas:cli:dev`) roda dentro do mesmo
  Harbor via o agente espelho `rivals_harbor_atlas_agent:AtlasDev`
  (`scripts/rivals_harbor_atlas_agent.py`), que executa o bridge governado com
  Hermes como runtime interno — plano, patch, gate. Prova no recibo:
  `execution = atlas_cli_dev_efficient`.

**Regra de ouro:** *"com Atlas"* = **cérebro Atlas** (`atlas:cli:dev`,
`execution=atlas_cli_dev_efficient`) **com Hermes por dentro**; *"bare"* =
`hermes -z` cru. Nunca se mede `hermes -z` e se chama de Atlas. Esta é suíte de
engenharia — o braço `with_atlas` roda o cérebro Atlas de verdade, não um wrapper
fino.

O verificador é idêntico nos dois braços, então a comparação é justa.

## Corretor / o que conta como acerto

O verificador oficial do Harbor roda ao fim da tentativa e devolve `resolved`
(bool): `resolved=true` → **success**. Quando não resolve, o adapter classifica
a falha honestamente em vez de esconder:

- `environment_failure` — `exception_info` não vazio (build/rede/coleta quebrou
  antes de medir modelo);
- `invalid_result` — resultado sem verdict `resolved`
  (`swe_marathon:result_missing_resolved_verdict`);
- `model_failure` — verificador rodou e **não** resolveu
  (`swe_marathon:verifier_did_not_resolve_task`).

Tokens/custo/duração vêm do agente Harbor; `field_presence` marca quando o
Hermes não reportou usage (`swe_marathon_hermes_usage_not_reported`) — ausência é
registrada, não maquiada.

## Estado atual

- **⏳ Na fila.** O pipeline usa o mesmo agente Harbor das outras suítes; a
  corrida 1×1 real (patch aplicado? reward computado? tokens?) ainda não fechou.
  Dono: **Codex** (auditar ao fechar).
- **Pack corrigido para o ambiente declarado** (`d0e3950fc`): o default `docker`
  incluía `embedding-eval`, que exige T4/Modal — sem credencial Modal isso geraria
  6 falhas de ambiente determinísticas. Foi trocado pelo task oficial CPU
  `zstd-decoder` (`gpus=0`). Prova: Fase A **12 passed / 176 assertions** (1 skip
  em Darwin esperado).
- Handoff aberto (**Claude → Codex**): ao fechar, auditar o report do agente
  Harbor com-Atlas — se `environment_failure`, achar a causa no Harbor (rede,
  build ou coleta), não celebrar worker verde.

Fonte viva do estado: `docs/rivals-warroom.md` §4 (board) e §5 (log).

## Relatórios

Relatórios de corrida (JSON) ficam em
`storage/atlas/rivals/runs/<run_id>/report.json` e os recibos por unidade em
`receipts.jsonl`. O resumo de confiabilidade consolidado vive no board do
war-room.
