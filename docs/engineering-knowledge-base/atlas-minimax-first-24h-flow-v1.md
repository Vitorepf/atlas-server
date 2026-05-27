---
id: atlas-minimax-first-24h-flow-v1
type: engineering_knowledge
title: Atlas MiniMax-First 24h Agentic Engineering Flow v1
status: active
implementation_state: canonical_policy_only_no_runtime_change
category: programming-forge
priority: 98
summary: Contrato canonico para otimizar o loop 24/7 do Atlas primeiro para workers MiniMax M2.7 baratos e limitados por request, transformando contexto gigante, paralelismo e outputs longos em fluxo fatiado, verificavel e escalavel antes de qualquer DeepSeek escalation.
human_summary: Define por que o Atlas deve comecar com MiniMax como worker barato e usar DeepSeek depois como escala, nao muleta.
human_what: Politica de fluxo para agentes 24/7, sharding, context packs, worker caps, small patches e escalonamento DeepSeek.
human_purpose: Fazer o Atlas evoluir codigo com menor custo previsivel sem depender de dumps gigantes, muitos writers ou output massivo.
human_input: Recebe repos, docs, code intelligence, provider budgets, quotas, filas de agentes, specs, patches, tests e evidence.
human_output: Entrega work plans fatiados, summaries verificaveis, ownership maps, context packs, patch scopes, receipts e criterios de escalonamento.
human_change_when: Mexa quando MiniMax, DeepSeek, Cursor/Composer, Atlas Decide, AQPES, ATER ou Forge worker scheduling mudarem.
human_block_when: Bloqueie quando uma IA quiser usar contexto bruto gigante, output gigante, muitos writers simultaneos ou provider paygo sem policy explicita.
tags:
  - atlas-ai
  - minimax
  - deepseek
  - agentic-engineering
  - token-economy
  - provider-routing
  - forge
capabilities:
  - minimax_first_agentic_flow
  - bounded_llm_worker_pool
  - hierarchical_repo_analysis
  - queue_based_multi_repo_scan
  - small_patch_output_contract
  - deepseek_scale_escalation
decisions:
  - MiniMax-first e a politica inicial de menor custo previsivel para loop 24/7 quando o objetivo for aprender fluxo, reduzir desperdicio e preservar qualidade.
  - A cobranca por request do MiniMax Token Plan nao autoriza contexto bruto inutil; o Atlas deve continuar compilando contexto minimo suficiente.
  - DeepSeek nao deve ser dependencia inicial do loop; ele entra como escala para contexto 500k-1M, alta concorrencia ou auditoria multi-repo quando AP-99 provar necessidade.
  - Otimizar para os limites do MiniMax melhora o Atlas: index, shards, summaries, ownership map, context pack, fila, tests reais, receipts e repair pequeno.
  - Mesmo quando DeepSeek estiver disponivel, o Atlas nao deve trocar engenharia de fluxo por dump gigante de contexto.
  - Muitos agentes logicos nao significam muitos LLM workers simultaneos; escritor simultaneo e sempre recurso escasso e governado.
  - Output gigante e anti-padrao para codigo; Forge deve exigir spec pequena, patch pequeno, diff verificavel e evidence antes de novo ciclo.
  - Multi-repo brutal deve ser fila priorizada com evidence, nao prompt monolitico com varios repos.
maintenance:
  - Atualizar antes de implementar MiniMax-first worker scheduling, DeepSeek escalation, provider budget policy ou AP-99 comparativo.
  - Revalidar docs oficiais de provider antes de transformar limites ou precos em config.
  - Rodar docs-health, sync e index-code apos alteracoes canonicas.
related_paths:
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
  - docs/engineering-knowledge-base/atlas-current-provider-stack-v1.md
  - docs/engineering-knowledge-base/atlas-quality-preserving-efficiency-system.md
  - docs/engineering-knowledge-base/atlas-token-economy-runtime.md
  - docs/engineering-knowledge-base/atlas-context-compiler-runtime.md
  - docs/engineering-knowledge-base/atlas-context-ranking-system.md
  - docs/engineering-knowledge-base/atlas-programming-self-construction-forge-map-v1.md
  - docs/engineering-knowledge-base/atlas-agentic-engineering-os.md
  - docs/engineering-knowledge-base/self-construction/multi-provider-agent-orchestration-contract.md
  - docs/engineering-knowledge-base/atlas-forge-provider-capacity-continuity-v1.md
  - docs/engineering-knowledge-base/atlas-forge-rivals-provider-arena-v2.md
  - docs/engineering-knowledge-base/atlas-forge-rivals-provider-performance-ledger-v1.md
  - docs/engineering-knowledge-base/atlas-cursor-cli-governed-executor-v1.md
  - docs/engineering-knowledge-base/atlas-cursor-antigravity-meta-provider-dossier-v1.md
external_references:
  - https://platform.minimax.io/docs/token-plan/intro
  - https://platform.minimax.io/docs/token-plan/faq
  - https://platform.minimax.io/docs/guides/text-generation
  - https://api-docs.deepseek.com/quick_start/pricing
  - https://api-docs.deepseek.com/quick_start/rate_limit
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-minimax-first-24h-flow-v1
graph_title: Atlas MiniMax-First 24h Agentic Engineering Flow v1
graph_world: atlas
graph_layer: flow
graph_kind: policy
graph_parent: atlas-quality-preserving-efficiency-system
graph_status: active
graph_source: repo
human_name: MiniMax-First 24h Agentic Engineering Flow
canonical_name: Atlas MiniMax-First 24h Agentic Engineering Flow v1
technical_name: atlas-minimax-first-24h-flow-v1
cartography_type: policy
canonical_source: docs/engineering-knowledge-base/atlas-minimax-first-24h-flow-v1.md
owner: atlas-ai
repo_paths:
  - docs/engineering-knowledge-base/atlas-minimax-first-24h-flow-v1.md
allowed_changes:
  - Refinar thresholds, escalation criteria, receipts, provider roles e work-plan schemas quando houver AP-99, Rivals ou telemetry real.
forbidden_changes:
  - Declarar MiniMax superior a DeepSeek, Composer, Codex ou Claude sem medicao real no Atlas.
  - Usar MiniMax Token Plan como desculpa para enviar contexto bruto, output gigante ou retries sem utility score.
  - Permitir DeepSeek paygo, MiniMax Credits overflow ou Cursor on-demand sem policy explicita e cap.
  - Permitir multiplos agentes escrevendo no mesmo ownership scope sem lease, allowed_files e gate.
depends_on:
  - atlas-quality-preserving-efficiency-system
  - atlas-token-economy-runtime
  - atlas-context-compiler-runtime
  - atlas-ai-self-construction-multi-provider-agent-orchestration-contract
flows_to:
  - atlas-forge-rivals-provider-arena-v2
  - atlas-forge-provider-capacity-continuity-v1
  - atlas-code-provider-arena-ui-v1
unlocks:
  - minimax-first-24h-worker-loop
  - deepseek-as-scale-after-discipline
  - bounded-logical-agent-factory
governs:
  - atlas.provider_strategy.minimax_first
  - atlas.agentic_engineering.worker_pool_policy
  - atlas.forge.deepseek_escalation_policy
evidence:
  - docs/engineering-knowledge-base/atlas-minimax-first-24h-flow-v1.md
  - https://platform.minimax.io/docs/token-plan/intro
  - https://platform.minimax.io/docs/token-plan/faq
  - https://api-docs.deepseek.com/quick_start/pricing
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
  - "php artisan atlas:ai:architecture-validate --json"
  - "git diff --check"
requires_evidence: true
risk_level: high
visual_tags:
  - minimax
  - deepseek
  - 24h-loop
  - token-economy
ai_entrypoints:
  - Leia Resumo, Decisao Canonica, Deficit Translation Table, Worker Model e DeepSeek Escalation antes de implementar worker scheduling ou provider routing 24/7.
ai_usage_notes:
  - Use este doc como politica de fluxo. Ele nao promove provider automatico nem substitui AP-99/Rivals.
quality_gates:
  - docs-health status ok
  - AP-99 before auto-routing
  - provider spend caps enforced
  - no unbounded writer concurrency
failure_modes:
  - Atlas troca sharding e evidence por prompt gigante.
  - MiniMax fica saturado por agentes concorrentes sem fila.
  - Composer ou Codex viram worker infinito e queimam plano premium.
  - DeepSeek entra cedo demais e mascara fluxo ruim.
  - Output gigante vira patch quebrado ou spec impossivel de verificar.
observability_signals:
  - minimax_request_window_remaining
  - logical_agent_count
  - active_llm_worker_count
  - active_writer_lease_count
  - shard_summary_quality_score
  - escalation_to_deepseek_reason
  - wrong_file_rate
  - broken_patch_rate
  - wasted_context_ratio
next_actions:
  - Criar AP-99/Rivals battery MiniMax M2.7 vs DeepSeek V4 vs Composer 2.5 nos mesmos work packets.
  - Implementar receipts de work plan, shard summary, cross-check e DeepSeek escalation antes de auto-routing.
---
# Atlas MiniMax-First 24h Agentic Engineering Flow v1

## Resumo

Este contrato fixa uma decisao de arquitetura: o loop 24/7 do Atlas deve ser
otimizado primeiro para MiniMax M2.7 como worker barato e limitado por request.
DeepSeek e escala posterior, nao muleta inicial.

O ganho esperado nao vem de MiniMax ser magicamente melhor que DeepSeek em
contexto bruto. O ganho vem de obrigar o Atlas a operar com disciplina:
contexto compilado, trabalho fatiado, summaries verificaveis, poucos writers,
testes reais e receipts.

## Papel no Atlas

Este doc e uma policy filha de AQPES/ATER para Atlas Dev, Atlas Forge,
Self-Construction e AAEOS. Ele governa como transformar deficits de contexto,
concorrencia e output em fluxo verificavel antes de escolher provider mais
caro ou API elastica.

Ele nao implementa driver, scheduler ou provider routing. Ele define o contrato
que futuras implementacoes devem seguir.

## Onde Se Encaixa

```text
AQPES / ATER / Context Compiler
-> MiniMax-first 24h Flow
-> Atlas Decide / Forge worker scheduling
-> AP-99 / Rivals provider measurement
-> DeepSeek escalation only when evidence requires scale
```

Atlas Agentic Engineering OS usa este doc quando o objetivo for operar agentes
24/7 com menor custo previsivel. Forge usa este doc quando uma Obra precisa de
trabalho continuo sem desperdicar Codex, Composer ou API tokenizada.

## Decisao Canonica

```text
MiniMax primeiro = disciplina de fluxo.
DeepSeek depois = escala sobre fluxo ja disciplinado.
```

O Atlas deve vencer pelo processo, nao por jogar 1M tokens em toda duvida.

## Deficit Translation Table

| Deficit vs DeepSeek | Implementacao canonica no Atlas |
|---|---|
| Repo enorme em uma chamada | Fazer index, shards, summaries, ownership map e context pack. |
| Analise com 500k-1M tokens | Fazer analise hierarquica: map -> reduce -> cross-check -> final. |
| Muitos agentes paralelos | Ter muitos agentes logicos, mas poucos LLM workers simultaneos. |
| Long output gigantesco | Proibir output gigante; exigir patch pequeno, spec pequena, diff verificavel. |
| Varredura multi-repo brutal | Varrer por filas, evidencia e prioridades, nao por dump gigante. |

Esta tabela e regra de implementacao. Quando uma tarefa pedir um destes padroes,
o Atlas deve criar work plan fatiado antes de provider call.

## Contratos

Contratos minimos que uma implementacao deve materializar antes de auto-routing:

- `atlas.minimax_first.work_plan.v1`;
- `atlas.minimax_first.shard_summary.v1`;
- `atlas.minimax_first.cross_check.v1`;
- `atlas.minimax_first.writer_lease.v1`;
- `atlas.minimax_first.deepseek_escalation.v1`.

Todos os contratos precisam de source refs, owner, budget, evidence e rollback
quando houver escrita.

## Worker Model

Tres coisas nunca podem ser confundidas:

| Conceito | Significado | Regra |
|---|---|---|
| Agente logico | Unidade de trabalho no Atlas: scout, spec, test, repair, reviewer | Pode existir em grande quantidade. |
| LLM worker | Chamada ativa a provider/modelo | Limitado por provider budget, quota e utility score. |
| Writer | Agente com permissao de alterar arquivos | Sempre escasso, com lease, allowed_files e gates. |

MiniMax-first significa rodar muitos agentes logicos em fila, com poucos LLM
workers ativos. Nao significa abrir dezenas de chamadas MiniMax concorrentes.

### Work Plan Obrigatorio

Todo ciclo 24/7 que tocar codigo, doc canonico ou multi-repo deve produzir:

```text
atlas.minimax_first.work_plan.v1
fields: objective, repo_scope, source_refs, shards, ownership_map,
must_keep, context_pack_hash, logical_agents, llm_worker_budget,
writer_lease_plan, output_contract, tests_required,
deepseek_escalation_conditions, rollback_ref
```

Sem `ownership_map`, `allowed_files` e `output_contract`, nenhum writer pode
editar.

## Fluxo

```text
1. Intake: objetivo, area, risco, repos e budget.
2. Index: Code Intelligence, docs canonicos, ownership map e hot scopes.
3. Shard: dividir por owner, feature, path, test boundary ou evidence source.
4. Map: MiniMax/Gemini/local scouts geram summaries pequenos com source refs.
5. Reduce: um worker cruza summaries e cria spec curta.
6. Cross-check: outro worker valida lacunas, conflitos e arquivo errado.
7. Write lease: somente writer autorizado recebe patch pequeno.
8. Verify: CPU local roda tests, lint, diff scope e failure capsule.
9. Repair: MiniMax/Composer repara pequeno; Codex revisa se risco alto.
10. Evidence: receipts, metrics e learning voltam ao Atlas Decide/Rivals.
```

## Output Contract

Para codigo, output grande e falha de arquitetura.

Padrao:

- spec deve caber em uma pagina operacional;
- patch deve tocar poucos arquivos relacionados;
- diff precisa ser revisavel por humano e por teste;
- resposta final deve apontar evidence, tests e riscos;
- relatorio longo deve virar artifact fatiado, nao resposta monolitica.

Se uma tarefa exigir output muito longo, ela deve ser dividida em artifacts:
`spec`, `patch_plan`, `test_plan`, `risk_review`, `evidence_summary`.

## Regras para IA

- Preferir shard + summary + cross-check antes de pedir contexto gigante.
- Tratar MiniMax como worker barato e limitado, nao como permissao para ruido.
- Tratar DeepSeek como escala com motivo, nao como default.
- Separar agente logico, LLM worker e writer.
- Proibir writer sem `allowed_files`, lease e tests.
- Reusar context pack e ownership map antes de nova provider call.
- Encerrar retry quando a mesma falha reaparecer sem nova evidencia.

## Escopo de Implementacao

Permitido:

- criar receipts read-only;
- criar scheduler de LLM workers separado de agentes logicos;
- criar AP-99/Rivals battery para comparar providers;
- expor metricas de worker, quota, writer lease e escalation reason.

Proibido:

- promover MiniMax, DeepSeek ou Composer automaticamente sem AP-99;
- abrir paygo/credits/on-demand sem cap;
- ampliar escopo de writer por conveniencia de provider;
- substituir tests reais por confianca do modelo.

## DeepSeek Escalation

DeepSeek entra somente quando houver uma razao tecnica explicita:

- must-keep real acima do contexto pratico do MiniMax;
- analise exige 500k-1M tokens em uma unica janela;
- muitos shards independentes precisam rodar ao mesmo tempo;
- auditoria multi-repo precisa de paralelismo bruto;
- AP-99 mostra que MiniMax falhou por contexto/concorrencia, nao por prompt ruim.

Mesmo nesses casos, DeepSeek deve consumir summaries, ownership map e context
packs do Atlas. Ele nao deve receber dump bruto como padrao.

## Provider Roles

| Provider | Papel neste fluxo |
|---|---|
| MiniMax M2.7 | Worker 24/7 barato: scout, spec, docs, triage, repair pequeno. |
| Cursor Composer 2.5 | Executor de patch real quando MiniMax nao for suficiente. |
| Gemini gratis | Contexto longo e cobertura ampla enquanto disponivel. |
| DeepSeek V4 | Escala para contexto gigante, concorrencia e auditoria multi-repo. |
| Codex GPT-5.5 | Juiz premium, arquitetura, review final e repair critico. |

Provider nenhum decide sozinho. Atlas Decide usa receipts, budget, AP-99 e
risco.

## Dependencias

- AQPES para qualidade preservada.
- ATER para token budget, output contract e provider cost.
- Context Compiler para context pack e must-keep.
- Code Intelligence para ownership map e shards.
- Multi-Provider Orchestration para packet/adapter/evidence.
- Forge Rivals/AP-99 para promocao por medicao real.

## Regras De Billing

- MiniMax Token Plan deve ser tratado como quota por request, nao como recurso
  infinito.
- MiniMax Credits overflow deve ficar bloqueado ate policy explicita.
- DeepSeek deve ter cap duro antes de qualquer uso API.
- Cursor on-demand/background spend deve ter cap e receipt.
- Codex e Claude premium nao sao workers 24/7.

## Evidencias

Evidencia minima para promover qualquer parte deste fluxo:

- receipt `atlas.minimax_first.work_plan.v1`;
- source refs por shard;
- context pack hash;
- writer lease;
- tests reais ou blocker explicito;
- AP-99 comparando MiniMax, DeepSeek e Composer no mesmo packet;
- cost/quality ledger com accepted patch rate.

## Por Que Isso Melhora O DeepSeek Depois

Se o Atlas for bom com MiniMax, ele ja tera:

- contexto limpo;
- summaries auditaveis;
- fila de trabalho;
- ownership map;
- patches pequenos;
- tests reais;
- receipts de qualidade;
- metricas de erro por provider.

Quando DeepSeek entrar, ele ganha um sistema ja eficiente. O salto vira escala,
nao compensacao de bagunca.

## Metricas AP-99 Obrigatorias

Comparar MiniMax, DeepSeek, Composer e Codex pelos mesmos work packets:

- wrong file rate;
- broken patch rate;
- hallucination by missing repo context;
- wasted context ratio;
- real test pass rate;
- repair precision;
- reviewer rejection rate;
- cost per accepted patch;
- time to verified evidence.

Sem essas metricas, nenhuma promocao automatica de provider e canonica.

## Riscos

- MiniMax barato mascarar prompt/contexto ruim.
- DeepSeek entrar cedo demais e esconder falta de ownership map.
- Varios writers paralelos quebrarem escopo.
- Output gigante virar patch quebrado.
- Cota/credits/on-demand escapar sem budget policy.
- Gemini gratis acabar e o Atlas descobrir tarde que dependia de contexto 1M.

## Exemplos

Correto:

```text
Atlas cria 20 agentes logicos, mas so 2 LLM workers MiniMax ativos,
1 writer lease por scope, summaries por shard e Codex como reviewer final.
```

Errado:

```text
Atlas manda cinco repos inteiros para um provider, pede relatorio gigante,
deixa tres writers editarem o mesmo modulo e chama isso de 24/7.
```

## Hard Stops

Bloquear o ciclo se:

- um provider tentar editar fora de `allowed_files`;
- o plano pedir output monolitico gigante;
- mais de um writer tentar o mesmo ownership scope;
- contexto obrigatorio nao couber e nao houver shard plan;
- retry repetir a mesma falha sem mudar evidence;
- MiniMax quota/credits/on-demand entrar em overflow nao autorizado;
- DeepSeek for usado porque "parece mais facil", sem escalation reason.

## Proximas Acoes

1. Implementar `atlas.minimax_first.work_plan.v1` como receipt read-only.
2. Adicionar scheduler de LLM workers separado de agentes logicos.
3. Criar AP-99 battery MiniMax M2.7 vs DeepSeek V4 vs Composer 2.5.
4. Promover DeepSeek somente como escalation path com cap duro.
5. Expor no Atlas Code: agentes logicos, LLM workers ativos e writer leases.
