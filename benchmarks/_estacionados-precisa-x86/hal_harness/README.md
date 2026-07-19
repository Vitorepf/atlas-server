# hal_harness

**Intuito:** Engenharia de Software · **Suite id:** `hal_harness`

## O que mede

HAL — *Holistic Agent Leaderboard*, do Princeton PLI — é um harness padronizado
que roda avaliações reproduzíveis de agentes através de vários benchmarks (SWE-bench
Verified, USACO, AppWorld, CORE-bench, tau-bench) sob uma CLI única `hal-eval`, com
isolamento por conda/Docker e captura de custo/latência por tarefa. O upstream está
arquivado (o time migrou o foco para confiabilidade de agentes), mas o harness segue
executando local. Neste pack o benchmark exercido é **SWE-bench Verified** nos casos
django (`django__django-11790`, `-11815`, `-11848`): horizonte longo de engenharia —
ler o repositório, produzir um patch e passar os testes oficiais. Capacidade no
perfil: **long_horizon** (1.00); peso no composite da arena: 0.10; família de uplift:
`long_horizon`.

## Repositório oficial

| campo | valor |
|---|---|
| URL | https://github.com/princeton-pli/hal-harness.git |
| adapter (Atlas) | `HalHarnessAdapter` (`app/Services/Ai/Rivals/Adapters/External/HalHarnessAdapter.php`) |
| agente nativo default | `hal_generalist_agent` |
| clone (gitignored) | `tools/rivals/benchmarks/hal_harness/` |
| instalar | `uv venv --clear --python 3.12 .atlas-venv` → `uv pip install -p .atlas-venv/bin/python -e . -q` → `uv pip install -p .atlas-venv/bin/python -r agents/hal_generalist_agent/requirements.txt -q` → provisionar Miniforge local (`.miniforge/bin/conda`) se ausente |
| smoke (sem provider) | `hal-eval --help` |
| timeout por unidade | 120 min |

Config canônica: `config/atlas_rivals.php` → `benchmarks.repos.hal_harness`.

## Como o Atlas roda (os dois braços)

**Regra de ouro:** "com Atlas" = **cérebro Atlas** (`atlas:cli:dev`, execução
`atlas_cli_dev_efficient`) com Hermes por dentro; "bare" = `hermes -z` cru. Nunca se
mede `hermes -z` e se chama de Atlas. Esta é suíte de engenharia — o braço
`with_atlas` roda o cérebro Atlas de verdade.

- **`bare`:** o Atlas usa o próprio pacote de agente HAL com `run_bare`
  (`scripts/rivals_hal_agent` → `rivals_hal_atlas_agent.run_bare`), que dirige
  `hermes -z` cru contra o modelo (`kimi-k2.7` no Verboo, custo marginal ~0). O
  generalist de estoque não é usado no bare porque tem dependência dura de SERPAPI;
  o shell do Atlas evita isso sem mudar quem dirige o modelo. Linha de base do
  provider cru.
- **`with_atlas`:** o mesmo pacote com `run` (`rivals_hal_atlas_agent.run`) chama
  `scripts/rivals-atlas-dev-bridge.php` → **cérebro Atlas** (`atlas:cli:dev`) numa
  worktree isolada, com Hermes como runtime interno — plano, patch, gate. Prova no
  recibo: `runtime_bridge.execution = atlas_cli_dev_efficient`.

O harness e os testes oficiais são os mesmos nos dois braços (HAL roda SWE-bench
Verified e checa os testes), então a comparação é justa: só muda **quem** dirige o
modelo.

## Corretor / o que conta como acerto

Cada tarefa passa pelo harness oficial da HAL, que reporta `success` por task
(patch aplicado + testes oficiais passando = resolveu). O adapter mapeia
`run.success` → `success`/`failure`; `cost_usd` e `latency_sec` são **obrigatórios**
(o `mapResults` lança `hal_harness_cost_or_latency_missing` se faltarem — sem número
não há recibo). Falha do modelo entra como `failure_class=model_failure`, e o
`failure_reason` projeta os `provider_call.error_codes` do `runtime_bridge` quando o
braço Atlas responde mas não resolve — a causa da falha é medida, não escondida.

## Estado atual

- **Dois braços medem limpo.** ✅ (board war-room, 2026-07-19)
- Casos-fantasma removidos (não existiam no dataset → env_failure determinística):
  `hal_task_001`, `hal_task_002`.
- Em corrida 1×1 real recente (`20260719_152300_4f5e13c8`, native 2/2 success): o
  **bare** resolveu `django__django-11790` (81.933/4.585 tokens, 222.485 ms); o
  **with_atlas** respondeu de verdade mas **não** resolveu (80.056/15.389 tokens,
  315.177 ms) — proof v2 `passed`, `task_ok=false`, `patch_applied=0`, com a causa no
  próprio proof: `candidate_preparation_blocked:provider_invalid_provider_contract`.
  O adapter perdia essa causa no resumo; fix TDD passou a projetar
  `provider_call.error_codes` em `failure_reason`. Prova: ExternalAdaptersIngest
  19/139 verde. Sinal honesto — o braço Atlas travou na preparação do candidato —,
  não artefato disfarçado.

Fonte viva do estado: `docs/rivals-warroom.md` §4.

## Relatórios

Relatórios de corrida (JSON) ficam em
`storage/atlas/rivals/runs/<run_id>/report.json` e os recibos por unidade em
`receipts.jsonl`. O resumo de confiabilidade consolidado vive no board do war-room.
