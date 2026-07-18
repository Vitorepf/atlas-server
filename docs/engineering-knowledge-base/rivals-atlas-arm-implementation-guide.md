# Rivals — braço com-Atlas: estado real e guia de implementação

> Owner: lane do servidor (Arena/Rivals). Escrito 2026-07-18 durante o resgate
> do Rivals (sessão Fable). Fonte de verdade sobre QUAIS suítes têm braço
> com-Atlas executável e COMO implementar as que faltam, com o arquivo-modelo
> de cada padrão.

## Estado por suíte (censo verificado em código, 2026-07-18)

| Suíte | Braço com-Atlas | Mecanismo | Modelo a seguir |
|---|---|---|---|
| terminal_bench | ✅ | agente TB com espelho de workspace host↔container | `scripts/rivals_tb_atlas_agent.py` |
| hal_harness | ✅ | agente HAL → bridge no host | `scripts/rivals_hal_atlas_agent.py` |
| bfcl | ✅ | unit-script PHP → bridge | `scripts/rivals-bfcl-atlas-unit.php` |
| aider_polyglot | ✅ | override no adapter | `AiderBenchAdapter::commandTemplateForArm` |
| swe_bench_live | ✅ | unit-script com `--runtime={runtime}` | `scripts/rivals-swe-live-unit.php` |
| senior_swe_bench | ❌ | — | ver §Harbor abaixo |
| swe_marathon | ❌ | — | ver §Harbor abaixo |
| live_code_bench | ❌ | — | ver §Unit-script abaixo |
| inspect_evals | ❌ | — | ver §Endpoint abaixo |
| tau2_bench | ❌ | — | ver §Endpoint abaixo |

A guarda `AbstractExternalSuiteAdapter::planCommands` (fail-fast pré-spend)
lança `<suite>_runtime_unsupported:atlas_dev` para as 5 sem braço — correto:
antes disso o braço Atlas rodava **bare disfarçado** (medição falsa).
O drain degrada DITO desde `b6452aaed6`: braço vira `failed` com motivo humano
e o baseline mede.

## A peça central: o bridge

`scripts/rivals-atlas-dev-bridge.php` — recebe `--workspace` + `--prompt-file`
+ `--model`, executa o Atlas governado sobre o workspace e grava prova em
`.rivals_atlas_dev_bridge.json` (`real_provider`, `usage`, `wall_ms`).
TODOS os braços com-Atlas convergem para ele. Implementar uma suíte nova =
montar o caso num workspace, chamar o bridge, converter a resposta para o
formato nativo da suíte.

## §Harbor (senior_swe_bench, swe_marathon)

Bare usa `harbor run --agent-import-path rivals_harbor_hermes_agent:VerbooHermes`.
O braço Atlas precisa de um agente harbor que espelhe o workspace do ambiente
para o host (padrão do `rivals_tb_atlas_agent.py`: `get_archive` → temp dir →
bridge → `put_archive` de volta) e devolva o resultado ao verificador do
harbor. Criar `scripts/rivals_harbor_atlas_agent.py` (classe `AtlasDev`) e nos
dois adapters um `commandTemplateForArm` trocando o `--agent-import-path`
(espelho do que `HalHarnessAdapter` faz). Estimativa: ~150 linhas + 2×8 linhas.

## §Unit-script (live_code_bench)

Bare: `scripts/rivals_lcb_verboo.py` gera e avalia (codegeneration, 1 questão).
Braço Atlas: `scripts/rivals-lcb-atlas-unit.php` no padrão EXATO do
`rivals-bfcl-atlas-unit.php` — carrega a questão, prompt de geração de código,
bridge, grava o arquivo de resultado no layout que o `--evaluate --continue_existing_with_eval`
do LCB espera, reaproveitando o avaliador nativo. Estimativa: ~120 linhas + override.

## §Endpoint modelo-compatível (inspect_evals, tau2_bench)

Esses frameworks dirigem a API DO MODELO diretamente (`inspect eval --model`,
`tau2 run --agent-llm`): não há workspace para o bridge. O braço Atlas exige
expor o Atlas como endpoint OpenAI-compatível (`/v1/chat/completions` na frente
do runtime governado) e apontar `{cli_model}` do braço para ele. É a peça que
NÃO existe hoje — decisão de arquitetura da lane do servidor (AP próprio).
Até lá: baseline mede, braço degrada dito.

## Operação (o que estava desligando o Rivals inteiro)

1. `ATLAS_ARENA_WORKER_ENABLED=true` no `.env` — ligado 2026-07-18; sem ele o
   agendador (`routes/console.php`) NUNCA drena e a fila do app apodrece.
2. As ferramentas das suítes (tb/harbor/tau2/inspect/venvs) vivem no HOST
   (`tools/rivals/benchmarks`); o drain agendado roda no container atlas-queue.
   Unidades que exigem binários do host falham lá. Estado atual: o drain
   manual no host funciona (provado); a casa permanente do worker (host
   launchd vs tooling no container) é decisão aberta da lane do servidor.
3. `mockllm` é BANIDO de payload público (regra pétrea do operador) — 4
   entradas expurgadas da fila em 2026-07-18 com motivo gravado.

## Prova viva (2026-07-18)

Run `20260718_172505_a31ca2f2` — terminal_bench × verboo_kimi_k2_7, 12
unidades pareadas (6 casos × bare/atlas_dev): braço atlas_dev com `success ·
exit 0` em casos reais (tb_fix_git, tb_git-multibranch, tb_hello-world);
falhas simétricas nos dois braços = problema do caso, nunca do braço. Os
zeros do scoreboard pós-15/jul eram rodadas pré-conserto + fila parada.
