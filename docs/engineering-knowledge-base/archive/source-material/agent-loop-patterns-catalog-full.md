---
id: agent-loop-patterns-catalog
type: engineering_knowledge
title: Agent Loop Patterns Catalog
status: active
category: research-self-improvement
priority: 95
summary: Catalogo vivo de padroes de loops agenticos para alimentar o LoopPatternRegistry e impedir que o Atlas Loop confunda autonomia real com proxy/cosmetica.
human_summary: Repertorio governado de padroes de loops de agentes, com gatilhos, riscos, evidencias e encaixe no Atlas Loop.
human_what: Taxonomia operacional de loop patterns de pesquisa, GitHub e frameworks de agentes.
human_purpose: Dar ao Atlas Loop uma biblioteca de estruturas para escolher a menor forma capaz de produzir o maior avanco real no escopo.
human_input: Papers, docs oficiais, repositorios publicos e aprendizados internos do Atlas Loop.
human_output: Padroes normalizados para consulta pelo LoopPatternRegistry, com guardrails e criterio de uso.
human_change_when: Atualize quando um novo padrao publico relevante aparecer, quando um padrao falhar no Atlas, ou quando um challenger vencer o champion.
human_block_when: Bloqueie se alguem usar este catalogo para autorizar merge, provider auth, escopo maior ou memoria canonica sem gate Atlas.
tags:
  - atlas-loop
  - loop-patterns
  - agentic-systems
  - loop-pattern-registry
  - self-improvement
  - research
related_paths:
  - docs/loop-canonical-definition.md
  - docs/engineering-knowledge-base/research-self-improvement/continuous-self-improvement-loop.md
  - docs/engineering-knowledge-base/research-self-improvement/metrics-and-evals.md
  - docs/engineering-knowledge-base/research-self-improvement/failure-modes.md
  - docs/engineering-knowledge-base/atlas-ai-knowledge-governance-system.md
owner: atlas-loop
canonical_source: docs/engineering-knowledge-base/research-self-improvement/agent-loop-patterns-catalog.md
allowed_changes:
  - Adicionar padroes com fonte, gatilho, criterio de parada, riscos e evidencia.
  - Promover/demover padroes conforme resultados champion/challenger do Atlas Loop.
forbidden_changes:
  - Declarar que este catalogo e exaustivo no sentido matematico.
  - Copiar prompts externos como autoridade operacional direta.
  - Usar fonte externa para liberar merge, credencial, memoria canonica ou escopo sem gate Atlas.
quality_gates:
  - "php artisan atlas:engineering:knowledge docs-health --json"
requires_evidence: true
risk_level: medium
---
# Agent Loop Patterns Catalog

Este documento e um catalogo vivo para o `LoopPatternRegistry`. Ele nao e um
prompt library e nao e uma autorizacao de runtime. A funcao dele e dar ao Atlas
Loop um repertorio governado de formas de trabalho: quando usar, como medir,
quando parar, onde falha, e qual evidencia torna o uso aceitavel.

## Contrato de uso

1. Todo padrao entra como `source material`, nao como autoridade.
2. O registry escolhe o menor padrao capaz de gerar ganho real comprovavel.
3. Padrao novo so vira champion apos comparacao contra baseline/challenger,
   verificacao independente e evidencias reproduziveis.
4. O mesmo agente que cria um padrao nao aprova o padrao.
5. Padroes que maximizam proxy local sem aumentar capacidade do Atlas devem ser
   rejeitados.

## Fonte e escopo

Fontes principais consultadas:

- ReAct: https://arxiv.org/abs/2210.03629
- Reflexion: https://arxiv.org/abs/2303.11366
- Tree of Thoughts: https://arxiv.org/abs/2305.10601
- Self-Refine: https://arxiv.org/abs/2303.17651
- Generative Agents: https://arxiv.org/abs/2304.03442
- Voyager: https://arxiv.org/abs/2305.16291
- LATS: https://arxiv.org/abs/2310.04406
- Anthropic, Building Effective Agents: https://www.anthropic.com/engineering/building-effective-agents
- OpenAI Agents SDK docs: https://openai.github.io/openai-agents-python/agents/
- LangGraph overview: https://docs.langchain.com/oss/python/langgraph/overview
- LangChain planning agents: https://www.langchain.com/blog/planning-agents
- MCP intro: https://modelcontextprotocol.io/docs/getting-started/intro
- DSPy GEPA optimization: https://dspy.ai/getting-started/gepa-optimization/
- BabyAGI repo: https://github.com/yoheinakajima/babyagi
- all-agentic-architectures: https://github.com/FareedKhan-dev/all-agentic-architectures
- awesome-agentic-patterns: https://github.com/nibzard/awesome-agentic-patterns

Escopo honesto: "todos os padroes existentes" nao e um conjunto fechado. O
catalogo abaixo cobre os padroes publicos recorrentes e as familias que permitem
classificar novos padroes sem duplicar conceitos.

## Taxonomia rapida

| Familia | Pergunta que responde | Exemplo |
|---|---|---|
| Workflow deterministico | O caminho e conhecido? | prompt chain, router, parallel map |
| Agent loop | O caminho precisa ser escolhido em runtime? | ReAct, tool-use, computer-use |
| Search/deliberation | Ha varias trajetorias possiveis? | ToT, LATS, beam/vote |
| Reflection/evaluation | A qualidade melhora com critica? | Reflexion, Self-Refine |
| Autonomia continua | O trabalho precisa se autoabastecer? | BabyAGI, Voyager |
| Multi-agent | Ha papeis independentes que reduzem erro? | orchestrator-workers, debate |
| Governance/safety | Como impedir falso progresso? | guardrails, frozen judge, no-self-approval |
| Optimization | Como melhorar o proprio sistema por metrica? | DSPy/GEPA, champion/challenger |

## Padroes fundamentais

### P00 - Augmented LLM

**Loop:** input -> LLM com tools/retrieval/memory -> resposta/tool result ->
continua se necessario.

**Use quando:** uma chamada com ferramentas bem descritas resolve a maior parte
do trabalho.

**Evite quando:** o objetivo exige busca aberta, planejamento longo, ou
verificacao independente.

**Atlas:** base de qualquer padrao. O ACI/tool interface precisa ser claro,
testado e fail-closed.

### P01 - Prompt Chain

**Loop:** step A -> gate -> step B -> gate -> step C.

**Use quando:** a decomposicao e fixa e cada etapa reduz incerteza.

**Stop:** todos os gates passam ou um gate bloqueia.

**Falha tipica:** cadeia longa aumenta latencia e mascara erro inicial.

**Atlas:** bom para docs promotion, intake -> placement -> spec -> validation.

### P02 - Router

**Loop:** classificar entrada -> escolher lane/modelo/prompt -> executar lane.

**Use quando:** tipos de trabalho diferentes exigem especialistas diferentes.

**Stop:** rota escolhida com confianca suficiente; abstain se ambigua.

**Atlas:** essencial para separar bug, feature, verificacao, docs, refactor real,
proxy/cosmetica e forbidden scope.

### P03 - Parallel Sectioning

**Loop:** quebrar em partes independentes -> executar em paralelo -> juntar.

**Use quando:** subproblemas sao independentes e agregaveis.

**Risco:** conflitos de arquivo, conclusoes contraditorias, custo alto.

**Atlas:** pesquisa, auditoria de modulos, varredura de bugs, leitura de docs.

### P04 - Parallel Voting / Self-Consistency

**Loop:** N tentativas independentes -> voto/ranking -> resposta escolhida.

**Use quando:** diversidade de raciocinio melhora confianca.

**Risco:** maioria pode estar errada; exige criterio externo.

**Atlas:** bom para triagem e critica; ruim como prova final sem verifier.

### P05 - Orchestrator-Workers

**Loop:** orquestrador entende objetivo -> cria subtarefas -> workers executam ->
orquestrador sintetiza -> verifica.

**Use quando:** numero/natureza dos subtasks nao e previsivel.

**Stop:** sintese satisfaz DoD e verificadores.

**Atlas:** padrao default para mudancas multi-arquivo e campanhas de pesquisa.

### P06 - Evaluator-Optimizer

**Loop:** gerador produz -> avaliador critica -> gerador melhora -> repete.

**Use quando:** criterios de avaliacao sao claros e critica melhora output.

**Stop:** score atinge threshold, delta marginal cai, ou budget acaba.

**Atlas:** bom para spec/projecao, docs de alto valor e design review; precisa
de no-self-approval.

### P07 - Human-in-the-Loop Checkpoint

**Loop:** agente trabalha ate decisao sensivel -> prepara opcoes/evidencia ->
humano decide -> agente continua.

**Use quando:** ha credencial, dinheiro, escopo pessoal, release, ou julgamento
sem oracle confiavel.

**Atlas:** no teto final isso vira "abstain-and-ask" minimo; no presente, e
gate obrigatorio para risco alto.

## Padroes de raciocinio, acao e busca

### P08 - ReAct

**Loop:** reason -> act/tool -> observe -> reason -> ... -> final.

**Use quando:** a resposta depende de observacoes externas.

**Stop:** objetivo atendido, ferramenta prova estado, ou bloqueio explicito.

**Falha:** pensamento sem verificacao vira narrativa; toolset ruim cria ciclos.

**Atlas:** padrao para debugging, pesquisa, terminal, browser e inspeção de
codigo com feedback real.

### P09 - Plan-and-Execute

**Loop:** planner cria plano -> executor roda passos -> replaneja se necessario.

**Use quando:** ha objetivo multi-etapa e plano inicial reduz custo.

**Risco:** plano envelhece; executor segue passos ruins.

**Atlas:** bom para campaigns com milestones; deve ter replan por evidencia.

### P10 - ReWOO / Variable-Bound Planning

**Loop:** planner cria passos com variaveis intermediarias -> workers preenchem
evidencias -> solver sintetiza.

**Use quando:** o plano pode evitar chamadas LLM repetidas e capturar
dependencias explicitas.

**Atlas:** util para pesquisa/docs e auditorias com evidencias nomeadas.

### P11 - LLMCompiler / DAG Execution

**Loop:** compilar plano em DAG -> executar nos paralelizaveis -> unir resultados
-> reexecutar falhas.

**Use quando:** dependencias sao conhecidas apos planejamento.

**Atlas:** bom para campanhas multi-agente com dependencias entre modulos.

### P12 - Chain of Thought as Private Scratchpad

**Loop:** decompor internamente -> responder com evidencia externa.

**Use quando:** raciocinio ajuda, mas nao deve ser a prova.

**Atlas:** nunca aceitar raciocinio como evidencia; exigir comandos, testes,
diffs, logs ou ledger.

### P13 - Tree of Thoughts

**Loop:** gerar pensamentos candidatos -> avaliar -> expandir/backtrack ->
escolher caminho.

**Use quando:** busca global importa e escolhas iniciais podem travar o problema.

**Stop:** solucao com score/verifier, limite de expansao, ou impossibilidade.

**Atlas:** bom para arquitetura e escolha de estrategia, caro demais para cada
task pequena.

### P14 - Graph of Thoughts

**Loop:** representar raciocinios como grafo, combinar e revisar nos.

**Use quando:** caminhos podem se recombinar em vez de formar arvore simples.

**Atlas:** util para architecture maps, dependency repair e knowledge graph.

### P15 - LATS / MCTS Agent Search

**Loop:** selecionar estado -> expandir acao -> simular/avaliar/refletir ->
backpropagar valor -> escolher.

**Use quando:** decisao sequencial precisa de exploracao deliberada.

**Risco:** reward fraco gera Goodhart; custo alto.

**Atlas:** candidato para LoopPatternRegistry quando EV/selectors precisam
comparar estrategias, nao apenas tarefas.

### P16 - Beam Search / Best-of-N

**Loop:** gerar N candidatos -> manter top K -> expandir ate criterio.

**Use quando:** diversidade local e suficiente.

**Atlas:** aceitavel para prompts/specs; nao confundir com loop 24/7.

## Padroes de reflexao e melhoria

### P17 - Reflexion

**Loop:** tentar tarefa -> receber feedback -> escrever reflexao em memoria
episodica -> repetir tarefa melhor.

**Use quando:** feedback de ambiente e forte o bastante para ensinar tentativas.

**Risco:** memoria reflexiva falsa contamina proximas execucoes.

**Atlas:** reflexoes entram em quarentena e so viram memoria canonica por gate.

### P18 - Self-Refine

**Loop:** gerar -> auto-feedback -> refinar -> repetir.

**Use quando:** output textual/estrutural pode melhorar por critica iterativa.

**Stop:** criterio objetivo, estabilidade, ou limite de iteracoes.

**Atlas:** bom para docs/specs; insuficiente para codigo sem teste externo.

### P19 - Critic / Devil's Advocate

**Loop:** proponente cria -> critico tenta quebrar -> proponente corrige ->
verificador independente decide.

**Use quando:** risco de overclaim, desenho fraco ou proposta arquitetural.

**Atlas:** obrigatorio na fase de projecao dominante do Loop.

### P20 - Debate

**Loop:** agentes defendem alternativas -> juiz compara evidencias -> decisao.

**Use quando:** existem alternativas legitimas e tradeoffs reais.

**Risco:** retorica vence evidencia; juiz precisa de criterio.

**Atlas:** bom para arquitetura; ruim para fatos verificaveis por comando.

### P21 - Red Team / Blue Team

**Loop:** builder cria -> attacker explora falhas -> defender corrige -> repeat.

**Use quando:** seguranca, robustness, prompts, gates ou merge policies importam.

**Atlas:** padrao forte para petreo, forbidden scopes, verifier bypass e policy.

### P22 - Failure-Harvest Learning

**Loop:** coletar falhas reais -> agrupar assinatura -> criar regressao/task ->
verificar fix -> gravar aprendizado.

**Use quando:** ha historico de falhas repetidas.

**Atlas:** essencial para o loop deixar de reagir e passar a compor aprendizado.

## Padroes autonomos e continuos

### P23 - AutoGPT-Style Goal Loop

**Loop:** goal -> plan/subtasks -> execute tools -> store result -> next task.

**Use quando:** objetivo aberto precisa de autonomia, mas com sandbox forte.

**Risco:** rabbit holes, loops logicos, custo sem progresso.

**Atlas:** somente com EV gates, stop conditions, proxy rejection e watchdog.

### P24 - BabyAGI Task Queue

**Loop:** executar task atual -> criar novas tasks -> priorizar fila -> repetir.

**Use quando:** supply de trabalho e parte do problema.

**Risco:** fila cresce com tarefas irrelevantes ou repetidas.

**Atlas:** base para backlog/refill, mas precisa classificar real/proxy/cosmetica.

### P25 - Voyager Lifelong Skill Library

**Loop:** curriculum automatico -> executar/explorar -> corrigir por feedback ->
salvar skill reutilizavel -> usar skill em tarefas futuras.

**Use quando:** habilidades compostas podem acumular vantagem.

**Atlas:** modelo forte para LoopPatternRegistry e skill library: skills so entram
apos prova, versionamento e recuperacao contextual.

### P26 - Generative Agents Memory-Plan-Reflect

**Loop:** observar -> gravar memoria -> refletir -> planejar -> agir/socializar.

**Use quando:** comportamento de longo prazo depende de memoria dinamica.

**Atlas:** util para operador/organismo cognitivo; perigoso se memoria nao for
governada.

### P27 - Continuous Research Loop

**Loop:** detectar lacuna -> pesquisar fontes -> sintetizar -> promover docs ->
criar tarefas/experimentos -> atualizar corpus.

**Use quando:** area muda rapido ou precisa de inteligencia externa constante.

**Atlas:** padrao direto deste documento; exige source trust ladder e citacoes.

### P28 - Web Search Agent Loop

**Loop:** decidir se busca e necessaria -> gerar queries -> buscar -> ler fontes
-> julgar suficiencia -> buscar de novo ou sintetizar.

**Use quando:** conhecimento e recente, externo ou disputado.

**Atlas:** sempre separar fonte primaria, fonte catalogo e opiniao.

### P29 - Browser / Computer-Use Loop

**Loop:** observar tela -> decidir acao UI -> clicar/digitar -> observar efeito
-> repetir.

**Use quando:** a tarefa so existe via UI local/remota.

**Risco:** side effects; requer confirmacoes para acoes sensiveis.

**Atlas:** bom para smoke de UX e ferramentas sem API; ruim para pesquisa quando
browsing/API da fonte e mais rastreavel.

### P30 - Coding Agent Test-Repair Loop

**Loop:** entender bug -> editar -> rodar teste -> ler falha -> corrigir ->
repetir ate verde.

**Use quando:** existe oracle mecanico: teste, lint, typecheck, verifier.

**Atlas:** padrao principal para bug fix; precisa diff-earned e revert recheck.

### P31 - SWE Issue-to-PR Loop

**Loop:** ler issue -> localizar codigo -> patch -> tests -> review -> PR notes.

**Use quando:** objetivo e transformar pedido em mudanca revisavel.

**Atlas:** hoje deve produzir proposta/certificacao; auto-merge so em territorio
liberado e com gates.

### P32 - CI Failure Triage Loop

**Loop:** coletar falhas -> agrupar causa -> reproduzir local -> patch -> rerun.

**Use quando:** regressao e detectada por CI.

**Atlas:** deve criar failure signatures e impedir fixes cosmeticos.

### P33 - Documentation Reality Loop

**Loop:** comparar doc/codigo/testes -> achar drift -> corrigir doc ou codigo ->
sync/index -> docs-health.

**Use quando:** conhecimento canonico e read models divergem.

**Atlas:** padrao para manter o loop explicavel e auditavel.

## Padroes multi-agente

### P34 - Specialist Handoff

**Loop:** triage -> especialista -> resultado -> volta ao orquestrador.

**Use quando:** dominio exige instrucao/toolset proprio.

**Risco:** perda de contexto e handoff loops.

**Atlas:** handoff deve levar contrato, arquivos permitidos, DoD e evidencias.

### P35 - Agents-as-Tools

**Loop:** orquestrador chama subagentes como ferramentas limitadas -> sintetiza.

**Use quando:** subagente precisa responder uma pergunta delimitada, nao tomar
controle do fluxo.

**Atlas:** preferivel para revisores, pesquisadores e auditores.

### P36 - Blackboard Coordination

**Loop:** agentes publicam claims/estado/evidencias em quadro comum -> outros
leem -> evitam conflito -> atualizam.

**Use quando:** varias sessoes mexem em mesmo projeto.

**Atlas:** AOBG blackboard e claims implementam essa familia; nao e bloqueio
absoluto, e coordenacao provider-safe.

### P37 - Swarm / Ensemble Exploration

**Loop:** varios agentes tentam estrategias -> ranking/score -> promote winner.

**Use quando:** diversidade de abordagem tem valor e ha score confiavel.

**Risco:** custo alto, duplicacao, winner por proxy.

**Atlas:** usar com champion/challenger e metricas de capacidade, nao contagem de
linhas/testes.

### P38 - Hierarchical Manager-Worker

**Loop:** manager define estrategia -> leads dividem -> workers executam ->
manager integra.

**Use quando:** projeto tem varios eixos e precisa ownership por area.

**Atlas:** util para campanhas longas do loop; exige branch/claim/gate por area.

### P39 - Cross-Model Review Panel

**Loop:** implementador entrega -> modelo diferente revisa -> terceiro/verifier
julga -> merge/proposta.

**Use quando:** risco de autoaprovacao e alto.

**Atlas:** requisito para substituir revisao humana em territorio liberado.

## Padroes de memoria, recuperacao e contexto

### P40 - RAG Loop

**Loop:** query -> retrieve -> answer -> check sufficiency -> refine query.

**Use quando:** resposta depende de corpus externo/local.

**Risco:** retrieved context irrelevante vira ruido convincente.

**Atlas:** usar context packs top-K como ponto de partida, verificar por arquivo.

### P41 - Memory Write-Back Loop

**Loop:** executar -> extrair learning candidato -> quarentena -> gate -> promote.

**Use quando:** runs geram conhecimento reutilizavel.

**Atlas:** nunca promover memoria direta de provider; registrar outcome candidato.

### P42 - Context Compaction / Resume Loop

**Loop:** resumir estado -> preservar invariantes/evidencia -> retomar -> validar.

**Use quando:** sessao longa passa limite de contexto.

**Atlas:** essencial para 24/7; resumo deve conter comandos, PIDs, campanha,
arquivos tocados e riscos.

### P43 - Retrieval Feedback Loop

**Loop:** usar contexto -> medir utilidade/ruido/missing -> ajustar retrieval.

**Use quando:** context packs trazem contexto demais ou errado.

**Atlas:** usar `atlas_context_feedback` apos execucao relevante.

## Padroes de governanca e seguranca

### P44 - Guardrail Sandwich

**Loop:** input guard -> core agent -> output guard -> action gate.

**Use quando:** existem acoes sensiveis, policy ou escopo proibido.

**Atlas:** default para provider use, browser/computer-use, merge, memory write.

### P45 - Frozen Judge

**Loop:** candidato -> juiz congelado/out-of-process -> accept/reject.

**Use quando:** e preciso impedir que o loop altere a propria prova.

**Atlas:** pilar para self-improvement do loop.

### P46 - Diff-Earned / Revert Recheck

**Loop:** aplicar diff -> testar -> reverter diff -> teste deve falhar ou mudar
evidencia -> reaplicar -> aceitar.

**Use quando:** patch pode ser falso positivo ou teste nao cobrir comportamento.

**Atlas:** obrigatorio para claims de melhoria comportamental.

### P47 - Mutation Adequacy Loop

**Loop:** gerar mutante -> rodar suite -> exigir kill -> adicionar teste se vivo.

**Use quando:** cobertura superficial mascara ausencia de assert real.

**Atlas:** muito util para tasks de caracterizacao, mas nao deve dominar a fila
para sempre.

### P48 - Canary / Sentinel Loop

**Loop:** inserir/verificar canario conhecido -> gate deve detectar -> congelar.

**Use quando:** validar se o sistema de qualidade ainda funciona.

**Atlas:** bom para merge gates e self-edit gates.

### P49 - No-Self-Approval Loop

**Loop:** criador entrega -> outro ator/verifier avalia -> criador nao decide.

**Use quando:** qualquer self-improvement, policy, verifier ou merge esta em jogo.

**Atlas:** regra petrea.

### P50 - Quarantine-and-Promote

**Loop:** capturar informacao externa -> quarentena -> redacao/provider safety ->
review/gate -> promover.

**Use quando:** fonte externa pode conter prompt injection, segredo ou ruido.

**Atlas:** padrao para skills, repos externos, browser content e memoria.

### P51 - Watchdog / Heartbeat

**Loop:** processo escreve heartbeat -> watchdog mede idade/process tree ->
reinicia/para/alerta conforme politica.

**Use quando:** run longa pode morrer ou ficar falso-running.

**Atlas:** essencial para 24h; interpretar heartbeat junto com filhos vivos.

### P52 - Circuit Breaker

**Loop:** medir falhas consecutivas/custo/risco -> abrir circuito -> parar rota
ou trocar provider.

**Use quando:** provider, verifier ou lane entra em falha repetida.

**Atlas:** evita gastar 24h em alvo quebrado.

### P53 - Budget Governor

**Loop:** medir custo/tempo/tokens -> ajustar fanout/profundidade -> parar.

**Use quando:** exploracao aberta pode crescer sem limite.

**Atlas:** necessario, mas nao pode virar one-shot finite-budget no objetivo
24/7; deve governar cadencia, nao matar ambicao.

### P54 - Scope/Territory Ladder

**Loop:** provar qualidade em escopo pequeno -> promover territorio -> repetir.

**Use quando:** autonomia deve crescer sem liberar tudo de uma vez.

**Atlas:** regra central: comecar pelo proprio loop, depois engenharia, memoria,
contexto e demais territorios.

## Padroes de otimizacao

### P55 - Champion/Challenger

**Loop:** champion atual -> challenger novo -> rodar ambos em benchmark/eval ->
promover somente se vence por criterio predefinido.

**Use quando:** prompt, selector, verifier, pattern ou modelo pode melhorar.

**Atlas:** caminho correto para evoluir o LoopPatternRegistry.

### P56 - Bandit / Portfolio Selection

**Loop:** alocar tentativas entre estrategias -> observar recompensa -> realocar.

**Use quando:** ha varias lanes com incerteza e feedback rapido.

**Risco:** recompensa proxy domina.

**Atlas:** usar recompensa de capacidade real: bugs corrigidos, gates novos,
falhas impossibilitadas, docs-code drift reduzido.

### P57 - Eval-Driven Prompt Optimization

**Loop:** gerar variantes -> rodar train/dev set -> escolher por metrica ->
congelar artefato.

**Use quando:** prompt/instruction e gargalo e ha dataset/metric.

**Atlas:** inspiracao DSPy/GEPA; sempre separar dev/test e evitar overfit.

### P58 - Quality Scorecard Loop

**Loop:** medir run -> classificar falhas -> priorizar maior ganho -> implementar
-> medir novamente.

**Use quando:** sistema precisa evoluir continuamente sem perder comparabilidade.

**Atlas:** base para relatorio 24h e melhoria de supply/throughput.

### P59 - Capability Trend Loop

**Loop:** registrar capacidades entregues -> medir tendencia -> escolher proxima
capacidade que aumenta alavancagem.

**Use quando:** objetivo e evolucao de sistema, nao ticket isolado.

**Atlas:** mais alinhado ao proposito do Loop do que metricas locais.

## Padroes especificos de pesquisa e repositorios

### P60 - Fresh Clone Audit

**Loop:** clonar repo externo -> inventariar arquitetura -> extrair padroes ->
quarentena -> adaptar ao Atlas.

**Use quando:** repos como MachinaOS, DeerFlow ou agent-script trazem estrutura
relevante.

**Atlas:** nunca copiar vendor auth/memoria/prompt cru; extrair design value.

### P61 - Source Trust Ladder

**Loop:** classificar fonte -> preferir paper/doc oficial/codigo -> marcar blog
como secundario -> citar.

**Use quando:** pesquisa web influencia arquitetura.

**Atlas:** obrigatorio para Provider Evolution Intelligence e loop research.

### P62 - Research-to-Pattern Promotion

**Loop:** fonte externa -> resumo -> padrao candidato -> experimento local ->
champion/challenger -> registry.

**Use quando:** padrao novo quer entrar no LoopPatternRegistry.

**Atlas:** este documento e entrada; runtime registry precisa de prova adicional.

## Padroes anti-patterns que o Atlas deve detectar

| Anti-pattern | Sintoma | Resposta Atlas |
|---|---|---|
| Proxy treadmill | melhora metrica facil sem capacidade nova | rejeitar como proxy |
| Cosmetic cleanup loop | renomeia, formata, move sem efeito | bloquear |
| Infinite rabbit hole | pesquisa/planeja sem entrega verificavel | checkpoint + scope cut |
| Self-approved verifier | agente muda regra e se aprova | forbidden/no-self-approval |
| Context stuffing | joga docs demais no prompt | retrieval feedback |
| Handoff ping-pong | agentes passam controle sem finalizar | owner unico + stop |
| Retry storm | mesma task falha sem nova informacao | circuit breaker |
| Green without behavior | teste verde nao prova diff | revert recheck |
| Memory poisoning | reflexao externa vira verdade | quarantine-and-promote |
| One-shot collapse | finite best-of-N substitui 24/7 loop | usar como subpadrao, nao como objetivo |

## Contrato para o LoopPatternRegistry

Cada pattern elegivel deve virar registro com campos minimos:

```json
{
  "id": "P08",
  "name": "ReAct",
  "family": "agent_loop",
  "trigger": "task requires external observations or tool feedback",
  "expected_gain": "reduces hallucination and enables environment-grounded progress",
  "required_evidence": ["tool_observation", "final_verifier"],
  "stop_conditions": ["objective_proven", "blocked_with_evidence", "budget_governor"],
  "failure_modes": ["tool_loop", "unverified_reasoning"],
  "atlas_guards": ["provider_safe_context", "action_gate", "no_self_approval_when_applicable"],
  "source_refs": ["https://arxiv.org/abs/2210.03629"]
}
```

## Mapping inicial para o Atlas Loop

| Situacao do loop | Padrao recomendado | Padrao proibido/ruim |
|---|---|---|
| Precisa entender codigo desconhecido | P08 ReAct + P40 RAG + leitura direta | context stuffing |
| Precisa escolher proxima evolucao | P15 LATS ou P55 champion/challenger | ranking por proxy unico |
| Precisa projetar arquitetura | P19 critic + P20 debate + P06 evaluator | one-shot spec |
| Precisa implementar bug com teste | P30 test-repair + P46 revert recheck | refactor preserva comportamento |
| Precisa manter 24h vivo | P24 task queue + P51 watchdog + P52 circuit breaker | retry storm |
| Precisa evoluir registry | P55 champion/challenger + P62 promotion | pattern novo sem prova |
| Precisa pesquisar internet/GitHub | P28 web search + P61 trust ladder | blog como fonte canonica |
| Precisa usar UI local | P29 computer-use + guardrail sandwich | click automation sem confirmacao |
| Precisa aprender com falha | P22 failure harvest + P41 memory write-back | memoria direta de provider |
| Precisa ampliar escopo | P54 territory ladder | liberar repo inteiro por confianca |

## Como medir se um padrao serviu

Um padrao so conta se melhorar pelo menos um eixo real:

- Capacidade nova verificavel.
- Bug reproduzido e impossibilitado por regressao.
- Gate que impede uma classe real de falha.
- Reducao de drift doc-codigo com sync/index.
- Throughput maior sem queda de qualidade.
- Menos proxy/cosmetica/unknown na fila.
- Mais autonomia com mesmo ou menor risco.

Nao conta:

- Mais arquivos alterados.
- Mais testes se eles nao matam comportamento.
- Mais prompts ou agentes sem criterio de decisao.
- Mais planejamento sem entrega.
- Mais complexidade sem resultado mensurado.

## Proximas acoes recomendadas

1. Converter esta taxonomia em registros runtime do `LoopPatternRegistry`.
2. Adicionar campo `pattern_id` nas tasks do Atlas Loop e exigir motivo de
   selecao.
3. Medir champion/challenger: selector atual vs selector com catalogo.
4. Criar gate anti-proxy que bloqueia P23/P24 quando supply produz apenas
   cosmetica ou refactor preservador de comportamento.
5. Fazer o loop propor novos patterns somente via P62.
