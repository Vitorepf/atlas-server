# RIVALS WARROOM — coordenação Claude ⇄ Codex

> **Este arquivo é o canal de comunicação entre Claude e Codex.** Antes de tocar
> qualquer coisa do Rivals: LEIA este arquivo inteiro. Antes de editar um alvo:
> RESERVE na tabela de Claims. Ao achar/consertar algo: REGISTRE no Log. Nunca
> edite um alvo que o outro reservou. Commits: branch local `main`, escopados,
> sem merge (regra pétrea do atlas-server).

## 0. Missão (ordem do operador, 2026-07-19)

Confiabilidade TOTAL do Rivals. Revisar TODOS os testes das 10 suítes, entender
por que cada braço quebra, investigar, resolver. **Lei suprema: nada quebra em
silêncio** — toda unidade DEVE deixar log legível (stdout+stderr capturados,
razão de falha nomeada no recibo). Ao final: compreensão completa e confiança de
que o número que o Rivals mostra é verdade.

## 1. Protocolo de colaboração

1. **Ler** este arquivo antes de agir. **Reservar** na §3 antes de editar.
   **Registrar** na §5 ao descobrir/consertar. **Liberar** o claim ao terminar.
2. Um alvo (arquivo/suíte) tem UM dono por vez. Se precisa de algo que o outro
   segura, escreva em §6 (Handoffs) e siga em outro alvo.
3. Toda anomalia real vira entrada em §5 com: sintoma → causa-raiz PROVADA (log
   citado, não deduzida) → fix → prova (comando + saída). Dedução sem log = não
   é causa-raiz (lição desta sessão: "single-shot" foi deduzido e estava errado).
4. Commit escopado por fix, mensagem explica o porquê. Atualizar §4 (board) e §5.

## 2. Divisão de trabalho (evita colisão; a tabela de Claims manda)

- **Claude**: adapters + bridge + endpoint (camada do braço com-Atlas) e a
  honestidade do dado (measurements/capabilities/app nativo). Suítes foco:
  bfcl, aider, terminal_bench, swe_bench_live, hal_harness, inspect, tau2, lcb
  (o braço com-Atlas e a coleta de resultado).
- **Codex**: pipeline de execução + normalizador + report + drain + o harness
  harbor (senior_swe, swe_marathon) e a **garantia de logging** (todo runner
  captura stdout/stderr, todo env_failure nomeia a razão no recibo). Cross-check
  do trabalho do Claude.
- **Ambos**: qualquer fix que o outro não reservou, com claim primeiro.

## 3. CLAIMS ATIVOS (edite antes de tocar o alvo; libere ao terminar)

| dono | alvo (arquivo/suíte) | desde | status |
|---|---|---|---|
| Claude | (nenhum no momento — sessão em pausa de vigília) | — | livre |
| Codex | — | — | — |

## 4. BOARD DE CONFIABILIDADE POR SUÍTE (verdade atual, 2026-07-19 ~10h)

Legenda: ✅ 2 braços medem limpo · 🟡 artefato no braço Atlas (causa conhecida) ·
⏳ Atlas ainda não rodou limpo nesta leva.

| suíte | estado | braço Atlas — causa-raiz do que quebra | dono p/ consertar |
|---|---|---|---|
| bfcl | ✅ | roda; déficit é fricção de categoria (function-calling via patch-plan), formato OK | — |
| aider_polyglot | ✅ | empata o bare (0.11=0.11), dado limpo | — |
| terminal_bench | ✅ | roda; fantasmas git-bisect/kernel-config já removidos → git-workflow-hack/fix-pandas | Claude (feito) |
| swe_bench_live | ✅ | roda; fantasma swe_live_001(astropy-31337) removido; tokens capturam | Claude (feito) |
| hal_harness | ✅ | roda; fantasmas hal_task_001/002 removidos | Claude (feito) |
| inspect_evals | 🟡 | Atlas via endpoint responde, mas o corretor de **formato exato** rejeita a resposta do proxy → tudo environment_failure | **em aberto** |
| tau2_bench | 🟡 | endpoint respondeu 12 chamadas (multi-turno OK), diálogo completou, mas **reward=N/A** e results não coletado → env_failure. Suspeita: tool-call por **prompt-JSON** (não nativo) degrada o loop de ferramentas + coleta do save-to no lugar errado | **em aberto** |
| live_code_bench | 🟡 | Atlas gera (tokens capturam) mas dá **error** na execução/coleta onde bare passa | **em aberto** |
| senior_swe_bench | ⏳ | RODANDO AGORA (1ª corrida do agente harbor com-Atlas `rivals_harbor_atlas_agent.py`); aguardar report | Codex (auditar ao fechar) |
| swe_marathon | ⏳ | na fila; mesmo agente harbor | Codex (auditar ao fechar) |

## 5. LOG (append-only; sintoma → causa PROVADA → fix → prova)

- 2026-07-19 · Claude · **tokens do braço Atlas não capturados** → todo relatório
  travava em `provider_usage_empty`. Causa: provider_call do fast-path não carrega
  tokens no path hermes (só o adaptador Sonnet); kernel roda hermes CLI-chat sem
  --usage-file. Fix `9e80947a7e`: adaptador espelha metadata[hermes_usage] num
  sink que o bridge lê; one-shot -z no modo rivals. Prova: bridge usage
  present=false→true (in=43994 out=5975); unit BFCL input_token_count=42133.
- 2026-07-19 · Claude · **environment_failure contado como falha do modelo** (score
  0 falso, "-10" no app). Causa: filtro `=== 'environment'` nunca casava com o
  texto real `environment_failure`. Fix `fbe701a015`: ignora ambos → artefato vira
  NÃO MEDIDO (null). Guarda: test_environment_failures_are_unmeasured.
- 2026-07-19 · Claude · **app mostrava 100% com 12/24** (double-count). Causa:
  casesDone somava tipos de evento (unit_finished + native_execution_finished).
  Fix `4a887dc052`: dedupe por execution_id. Guarda: test_live_counts_each_unit_once.
- 2026-07-19 · Claude · **endpoint OpenAI morria em pergunta >30s** (php -S
  max_execution_time). Fix `a63357d38e`: set_time_limit(0).
- 2026-07-19 · Claude · **aba Capacidades do app congelava no dado velho** (só
  recarrega no reload completo, não no poll). Fix `7b7bdf53`: .task re-busca ao
  abrir a aba.
- 2026-07-19 · Claude · fantasmas removidos: tb git-bisect/kernel-config,
  hal_task_001/002, swe_live_001, lcb_001 (casos que não existem no dataset →
  env_failure determinística nos 2 braços).
- 2026-07-19 · Claude · **CORREÇÃO de erro meu**: afirmei "endpoint single-shot não
  faz multi-turno" — ERRADO. Log prova 12 chamadas multi-turno OK no tau2. Lição:
  ler o log, não deduzir. A causa real do tau2 é reward=N/A + coleta (em aberto).

## 6. HANDOFFS / PERGUNTAS ABERTAS

- **Claude → Codex**: quando senior_swe/marathon fecharem, audite o report do
  agente harbor com-Atlas (patch aplicado? reward computado? tokens?). Se
  environment_failure, ache a causa no harbor (rede? build? coleta?).
- **Aberto (quem pegar, reserve)**: (a) endpoint precisa de **tool-calling nativo**
  (não prompt-JSON) pro tau2 fechar reward; (b) inspect: alinhar formato de resposta
  ao corretor exato; (c) lcb: erro de execução/coleta do braço Atlas; (d) garantir
  que TODO runner grava stdout+stderr e TODO env_failure nomeia a razão no recibo
  (hoje vários vêm com reason vazio — lei "nada quebra em silêncio").

## 7. DECISÕES

- 2026-07-19 · Claude · Suítes de **conhecimento puro** (inspect Q&A, tau2 diálogo)
  não medem valor do Atlas (agente de CÓDIGO) — o braço lá é proxy. Enquanto o
  proxy não fecha, elas aparecem "não medido" no app, nunca "-10". Rever com o
  operador se devem ter braço Atlas ou virar "modelo cru" rotulado.
