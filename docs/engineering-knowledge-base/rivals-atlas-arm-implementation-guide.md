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
| senior_swe_bench | ✅ (2026-07-18, prova pendente) | agente harbor espelho host↔ambiente | `scripts/rivals_harbor_atlas_agent.py` |
| swe_marathon | ✅ (2026-07-18, prova pendente) | agente harbor espelho host↔ambiente | `scripts/rivals_harbor_atlas_agent.py` |
| live_code_bench | ✅ (2026-07-18, prova pendente) | overlay bare com client-bridge | `scripts/rivals_lcb_atlas.py` |
| inspect_evals | ✅ (2026-07-18, PROVADO: gsm8k 1.000) | endpoint OpenAI-compat local :8791 | `scripts/rivals-atlas-openai-endpoint.php` |
| tau2_bench | ✅ (2026-07-18, tool-calls smoked) | endpoint OpenAI-compat local :8791 | `scripts/rivals-atlas-openai-endpoint.php` |

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

## Estado pós-fix do kernel (2026-07-18, tarde — commit 48ad9658d3)

O assassino determinístico morreu: escopo vazio sob rivals isolado adota a
proposta do provider (3 toques no kernel/adapter, flag-gated). Prova em
benchmark REAL (run 20260718_175624, terminal_bench): unidade `tb_hello`
braço atlas_dev com `atlas_runtime: true · patch_applied: 1` — primeira vez
na história que o braço com-Atlas gera E aplica patch dentro do harness.

Dois problemas REMANESCENTES, ambos fora do fix e com dono a definir:
1. **Instabilidade do cli:dev sob o harness**: 3 de 4 unidades atlas do mesmo
   run morrem em ~1,4s sem emitir JSON (`model_matches_request: false`,
   provider_calls 0, stderr só em sha). Não é o gate de escopo (erros vazios).
   Suspeitos: lock/bootstrap concorrente do artisan, estado do workspace
   espelhado. Diagnóstico: rodar bridge com stderr persistido no recibo.
2. **Verificação do terminal_bench quebrada SIMETRICAMENTE** desde ~15-16/jul:
   o aider (bare) escreve `hello.txt` ("Applied edit") e o teste 0,03s depois
   diz que `/app/hello.txt` não existe — mesmo container. TODAS as tabulações
   recentes de tb saem failure nos dois braços (histórico: sem-Atlas 0.22 em
   13/jul → 0 depois). Como é simétrico, não é braço — é harness/verificação.
   Este é o campo ativo da lane Arena (árvore com mudanças não-commitadas em
   ArenaRunController/ArenaCompositeService/ArenaReportService durante esta
   sessão).

## Prova viva (2026-07-18)

Run `20260718_172505_a31ca2f2` — terminal_bench × verboo_kimi_k2_7, 12
unidades pareadas (6 casos × bare/atlas_dev): braço atlas_dev com `success ·
exit 0` em casos reais (tb_fix_git, tb_git-multibranch, tb_hello-world);
falhas simétricas nos dois braços = problema do caso, nunca do braço. Os
zeros do scoreboard pós-15/jul eram rodadas pré-conserto + fila parada.


## Worker autônomo + endpoint (2026-07-18, noite — commits f4fc710031/224b39cd3e)

Os 10/10 braços com-Atlas existem em código. Peças novas:

- **com.atlas.arena-drain** (LaunchAgent, KeepAlive + loop 60s em
  `scripts/run-arena-drain.sh`): botão "Rodar medição" no app → fila →
  execução SEM intervenção. StartInterval de launchd NÃO dispara neste Mac
  (provado: runs=1 em 10min); o padrão da casa é KeepAlive, e bootstrap não
  auto-inicia — sempre `launchctl kickstart` após bootstrap.
- **com.atlas.rivals-openai-endpoint** (LaunchAgent, KeepAlive):
  `php -S 127.0.0.1:8791 scripts/rivals-atlas-openai-endpoint.php`,
  PHP_CLI_SERVER_WORKERS=4. Cada /v1/chat/completions = hermes one-shot
  (--safe-mode, --ignore-user-config, HERMES_HOME isolado) → Verboo; recibos
  em `storage/atlas/rivals/endpoint_receipts.jsonl`. Tool-calls bridgeados
  por prompt-JSON (smoked com get_reservation). PROVA inspect: gsm8k_af9bef9a
  accuracy 1.000 via endpoint (13.170 tokens in — overhead do runtime visível
  e medido).
- **Drain com repetições ≥3** (`worker_repetitions`, env
  ATLAS_ARENA_WORKER_REPETITIONS): 1 rep carimbava repetitions_below_min em
  todo relatório do app.
- **Casos fantasma do terminal_bench**: tb_git-bisect e tb_kernel-config
  apontavam para tasks INEXISTENTES no dataset (ValueError: No tasks found
  matching pattern) — eram os "2 env-quebrados" com falha simétrica.
  Substituídos no registry (`external/terminal_bench/cases/`) por
  tb_git-leak-recovery e tb_broken-python (tasks reais, medium/easy).
- Teto conhecido (tau2): o user-sim TAMBÉM passa pelo endpoint governado
  (litellm resolve base por env, sem split por papel). Simétrico e dito;
  separar exige binding de modelo por-braço no plano.
