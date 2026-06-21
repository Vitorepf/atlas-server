---
id: agent-loop-patterns-catalog
type: engineering_knowledge
title: Agent Loop Patterns Catalog
status: active
category: research-self-improvement
priority: 95
summary: Indice canonico curto do catalogo vivo de padroes de loops agenticos para alimentar o LoopPatternRegistry.
human_summary: Repertorio governado de familias de loop patterns, com link para o catalogo completo em source material.
human_what: Indice operacional dos padroes de loops de agentes relevantes ao Atlas Loop.
human_purpose: Ajudar o LoopPatternRegistry a escolher estruturas de trabalho sem confundir autonomia real com proxy/cosmetica.
human_input: Papers, docs oficiais, repositorios publicos e aprendizados internos do Atlas Loop.
human_output: Familias normalizadas de padroes, regras de promocao e ponte para o catalogo completo.
human_change_when: Atualize quando um novo padrao relevante aparecer, quando um padrao falhar no Atlas, ou quando um challenger vencer o champion.
human_block_when: Bloqueie se alguem usar este catalogo para autorizar merge, provider auth, escopo maior ou memoria canonica sem gate Atlas.
tags:
  - atlas-loop
  - loop-patterns
  - agentic-systems
  - loop-pattern-registry
  - self-improvement
capabilities:
  - loop_pattern_registry_source
  - agent_loop_taxonomy
  - research_to_pattern_promotion
decisions:
  - O catalogo completo fica como source material; o doc ativo fica curto e governado.
  - Padroes externos sao material de pesquisa, nao autorizacao de runtime.
  - Padrao novo so vira runtime apos champion/challenger, gate fresco e verificacao independente.
maintenance:
  - Manter este doc abaixo do limite ativo do docs-health.
  - Atualizar o catalogo completo quando novas fontes forem pesquisadas.
  - Rodar docs-health apos alteracoes.
related_paths:
  - docs/loop-canonical-definition.md
  - docs/engineering-knowledge-base/archive/source-material/agent-loop-patterns-catalog-full.md
  - docs/engineering-knowledge-base/research-self-improvement/continuous-self-improvement-loop.md
  - docs/engineering-knowledge-base/research-self-improvement/metrics-and-evals.md
  - docs/engineering-knowledge-base/research-self-improvement/failure-modes.md
doc_schema: atlas_canonical_module_doc.v1

graph_id: agent-loop-patterns-catalog
graph_title: Agent Loop Patterns Catalog
graph_world: atlas
graph_layer: system
graph_kind: policy
graph_parent: atlas-ai-research-to-docs-promotion
graph_status: active
graph_source: repo
human_name: Agent Loop Patterns Catalog
canonical_name: Agent Loop Patterns Catalog
technical_name: agent-loop-patterns-catalog
cartography_type: policy
canonical_source: docs/engineering-knowledge-base/research-self-improvement/agent-loop-patterns-catalog.md

owner: atlas-loop
repo_paths:
  - docs/engineering-knowledge-base/research-self-improvement/agent-loop-patterns-catalog.md
  - docs/engineering-knowledge-base/archive/source-material/agent-loop-patterns-catalog-full.md
allowed_changes:
  - Adicionar padroes com fonte, gatilho, criterio de parada, riscos e evidencia.
  - Promover/demover padroes conforme resultados champion/challenger do Atlas Loop.
forbidden_changes:
  - Declarar que este catalogo e exaustivo no sentido matematico.
  - Copiar prompts externos como autoridade operacional direta.
  - Usar fonte externa para liberar merge, credencial, memoria canonica ou escopo sem gate Atlas.
depends_on:
  - atlas-ai-research-to-docs-promotion
flows_to:
  - loop-pattern-registry
  - atlas-loop
unlocks:
  - governed_loop_pattern_selection
governs:
  - loop-pattern-registry
evidence:
  - docs/engineering-knowledge-base/archive/source-material/agent-loop-patterns-catalog-full.md
evidence_refs:
  - source: https://arxiv.org/abs/2210.03629
  - source: https://arxiv.org/abs/2303.11366
  - source: https://arxiv.org/abs/2305.10601
  - source: https://arxiv.org/abs/2310.04406
  - source: https://www.anthropic.com/engineering/building-effective-agents
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
requires_evidence: true
risk_level: medium
visual_tags:
  - system
  - policy
  - atlas-loop
ai_entrypoints:
  - Leia o Contrato, Familias e Regras de Promocao antes de usar um padrao no LoopPatternRegistry.
ai_usage_notes:
  - Use o catalogo completo como fonte de pesquisa; implemente runtime somente apos gate Atlas.
quality_gates:
  - "php artisan atlas:engineering:knowledge docs-health --json"
failure_modes:
  - Padrao externo vira autoridade sem prova local.
  - Loop maximiza proxy mensuravel em vez de capacidade real.
  - Pattern novo entra no registry sem champion/challenger.
observability_signals:
  - tasks com pattern_id
  - real_work_scorecard sem proxy/cosmetica/unknown
  - champion_challenger_receipts
next_actions:
  - Converter os padroes candidatos em registros runtime do LoopPatternRegistry.
---
# Agent Loop Patterns Catalog

## Resumo

Este documento e o indice canonico curto. O catalogo completo, com 63 padroes
normalizados, fontes e mapping operacional, fica em
`docs/engineering-knowledge-base/archive/source-material/agent-loop-patterns-catalog-full.md`.

## Papel no Atlas

Este doc alimenta o LoopPatternRegistry com vocabulario governado de padroes. O
Loop usa esse repertorio para escolher como trabalhar, nao para declarar sucesso.

## Onde Se Encaixa

Fica em Research Self-Improvement e aponta para source material arquivado. O doc
canonico do Loop continua sendo `docs/loop-canonical-definition.md`.

## Contratos

O catalogo serve ao `LoopPatternRegistry`. Ele nao e prompt library e nao
autoriza merge, credenciais, memoria canonica, release ou ampliacao de escopo.

Regras:

- Padrao externo entra como source material.
- O registry escolhe a menor estrutura capaz de gerar ganho real comprovavel.
- Padrao novo so vira champion apos gate fresco, comparacao champion/challenger
  e verificacao independente.
- O agente que cria uma estrutura nao pode aprova-la.
- Padrao que produz proxy/cosmetica sem capacidade real deve ser rejeitado.

## Fluxo

Pesquisa externa entra no catalogo completo. Este indice resume familias. O
runtime so recebe padroes depois de gate local, champion/challenger e evidencia.

## Regras para IA

Use o catalogo para escolher estrutura, nao para copiar prompt externo. Se a
fonte for blog/repo opinativo, trate como secundario ate haver prova local.

## Escopo de Implementacao

Mudancas neste doc devem ficar em documentacao. Registro runtime, selectors e
gates do LoopPatternRegistry exigem tarefa propria e testes.

## Dependencias

Depende da governanca de conhecimento, do doc canonico do Loop e do processo de
research-to-docs promotion.

## Evidencias

Evidencias aceitas: URLs de fontes, catalogo completo, docs-health e futuros
receipts de champion/challenger.

## Riscos

Riscos principais: Goodhart por proxy mensuravel, padrao externo promovido sem
prova, self-approval e catalogo ficando maior que a capacidade de escolha.

## Exemplos

Exemplo: uma campanha 24h com supply fraco deve preferir task queue + watchdog +
circuit breaker, mas bloquear supply cosmetico mesmo que a fila esteja cheia.

## Familias

| Familia | Pergunta que responde | Padroes no catalogo completo |
|---|---|---|
| Workflow deterministico | O caminho e conhecido? | P00-P07 |
| Raciocinio/acao/busca | O caminho precisa de observacao ou exploracao? | P08-P16 |
| Reflexao/melhoria | A qualidade melhora com critica? | P17-P22 |
| Autonomia continua | O trabalho precisa se autoabastecer? | P23-P33 |
| Multi-agente | Papeis independentes reduzem erro? | P34-P39 |
| Memoria/contexto | O loop aprende e recupera contexto? | P40-P43 |
| Governanca/seguranca | Como impedir falso progresso? | P44-P54 |
| Otimizacao | Como melhorar seletores/prompts/padroes? | P55-P59 |
| Pesquisa/repos | Como absorver padroes externos? | P60-P62 |

## Padroes principais

- ReAct: reason -> act/tool -> observe -> repeat.
- Plan-and-execute/ReWOO/LLMCompiler: planejar antes de executar e replanejar
  por evidencia.
- Tree/Graph of Thoughts e LATS: explorar trajetorias, avaliar e backtrack.
- Reflexion/Self-Refine: feedback/reflexao antes de nova tentativa.
- Orchestrator-workers, specialist handoff e agents-as-tools: coordenacao de
  agentes sem perder owner.
- BabyAGI/AutoGPT/Voyager: filas autonomas, autoabastecimento e biblioteca de
  skills.
- Frozen judge, diff-earned, mutation, no-self-approval e quarantine-promote:
  padroes de seguranca para self-improvement.
- Champion/challenger, bandit e eval-driven optimization: padroes para melhorar
  o proprio registry sem Goodhart.

## Fontes base

Fontes primarias ou quase primarias usadas no catalogo completo:

- ReAct: https://arxiv.org/abs/2210.03629
- Reflexion: https://arxiv.org/abs/2303.11366
- Tree of Thoughts: https://arxiv.org/abs/2305.10601
- Self-Refine: https://arxiv.org/abs/2303.17651
- Generative Agents: https://arxiv.org/abs/2304.03442
- Voyager: https://arxiv.org/abs/2305.16291
- LATS: https://arxiv.org/abs/2310.04406
- Anthropic Building Effective Agents:
  https://www.anthropic.com/engineering/building-effective-agents
- OpenAI Agents SDK: https://openai.github.io/openai-agents-python/agents/
- LangGraph: https://docs.langchain.com/oss/python/langgraph/overview
- MCP: https://modelcontextprotocol.io/docs/getting-started/intro
- DSPy GEPA: https://dspy.ai/getting-started/gepa-optimization/
- BabyAGI: https://github.com/yoheinakajima/babyagi

## Mapping para o Atlas Loop

| Situacao | Padrao recomendado | Anti-pattern |
|---|---|---|
| Entender codigo desconhecido | ReAct + RAG + leitura direta | context stuffing |
| Escolher proxima evolucao | LATS ou champion/challenger | ranking por proxy unico |
| Projetar arquitetura | critic + debate + evaluator | one-shot spec |
| Implementar bug | test-repair + revert recheck | refactor preservador |
| Manter 24h vivo | task queue + watchdog + circuit breaker | retry storm |
| Evoluir registry | champion/challenger + promotion gate | pattern sem prova |
| Pesquisar internet/GitHub | web search + source trust ladder | blog como lei |
| Usar UI local | computer-use + guardrail sandwich | click sem confirmacao |

## Proximas acoes

1. Adicionar `pattern_id` nas tasks do Atlas Loop.
2. Converter padroes candidatos em registros runtime do `LoopPatternRegistry`.
3. Medir selector atual vs selector com catalogo.
4. Bloquear supply que produz proxy/cosmetica sob padroes autonomos.
