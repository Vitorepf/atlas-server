---
id: atlas-ai-programming-professional-rag-operating-standard
type: engineering_knowledge
title: Programming Professional RAG Operating Standard
status: active
category: architecture
priority: 100
summary: Padrao operacional profissional para Atlas Programacao com RAG, Agentic RAG, Semantic Code Graph, receipts, gates, evals e integridade de claim.
tags:
  - atlas-ai
  - programming
  - rag
  - agentic-rag
  - enterprise
capabilities:
  - programming_agentic_rag_standard
  - programming_semantic_code_graph_standard
  - programming_context_pack
  - programming_quality_gates
  - programming_rivals_readiness
decisions:
  - MVP de RAG em programacao e erro operacional quando usado como criterio de conclusao.
  - O minimo aceitavel e uma fundacao profissional com retrieval governado, contexto replayable, grafo semantico, receipts, evals e gates.
  - Agentic RAG deve decidir fontes, iterar busca, criticar lacunas e bloquear quando o contexto nao sustenta uma mudanca de codigo.
  - Nenhum score comparavel de Rivals-Programming pode ser declarado sem bateria real pareada, provider auditado, protocolo valido e export bundle verificado.
maintenance:
  - Atualize este standard antes de alterar arquitetura de RAG, code graph, context pack, verifier, test impact, sandbox, repair ou learning.
  - Nao reduza a barra profissional para acomodar entrega incompleta; registre pendencia e bloqueio explicito.
related_paths:
  - docs/engineering-knowledge-base/atlas-programming-forge-flow.md
  - docs/engineering-knowledge-base/domains/programming-agentic-rag-professional-spec.md
  - docs/engineering-knowledge-base/domains/programming-enterprise-implementation-plan.md
  - docs/engineering-knowledge-base/domains/programming-professional-completion-audit.md
  - docs/engineering-knowledge-base/domains/programming.md
  - docs/engineering-knowledge-base/memory/retrieval-and-context.md
  - docs/engineering-knowledge-base/code-intelligence.md
  - app/Services/Ai/Programming/ProgrammingRetrievalPlanner.php
  - app/Services/Ai/Programming/ProgrammingRetrievalExecutor.php
  - app/Services/Ai/Programming/ProgrammingSemanticCodeGraphService.php
  - app/Services/Ai/Programming/ProgrammingProfessionalCompletionAuditService.php
  - app/Services/Ai/Programming/ProgrammingRivalsReadinessService.php
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-ai-programming-professional-rag-operating-standard
graph_title: Programming Professional RAG Operating Standard
graph_world: atlas
graph_layer: system
graph_kind: module
graph_parent: atlas-ai-programming-domain
graph_status: active
graph_source: repo
human_name: Programming Professional RAG Operating Standard
canonical_name: Programming Professional RAG Operating Standard
technical_name: atlas-ai-programming-professional-rag-operating-standard
cartography_type: module
canonical_source: docs/engineering-knowledge-base/domains/programming-professional-rag-operating-standard.md
owner: domains
repo_paths:
  - docs/engineering-knowledge-base/domains/programming-professional-rag-operating-standard.md
allowed_changes:
  - Atualizar criterios, gates, comandos e contratos quando a implementacao evoluir.
forbidden_changes:
  - Tratar busca lexical, prompt stuffing, lista manual de arquivos ou embedding sem eval como RAG profissional.
  - Marcar a frente completa sem evidence local verde e claim externo corretamente separado.
  - Autorizar gasto de provider quando ha blocker atual, workspace sujo, baseline contaminado ou bateria historica invalida sem triagem.
next_actions:
  - Manter o completion audit bloqueado ate existir bateria Rivals real, pareada, verificavel e com provider spend aprovado.
  - Usar a timeline historica e o export bundle para investigar regressao antes de qualquer claim comparavel.
depends_on:
  - atlas-ai-programming-domain
  - atlas-ai-programming-agentic-rag-professional-spec
  - atlas-ai-programming-enterprise-implementation-plan
flows_to:
  - programming-agentic-rag
  - semantic-code-graph
  - programming-stage-receipts
  - programming-rivals-readiness
unlocks:
  - atlas-programming-enterprise-runtime
  - atlas-rivals-programming-quality
governs:
  - programming
evidence:
  - docs/engineering-knowledge-base/domains/programming-professional-rag-operating-standard.md
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
  - "php artisan test tests/Unit/Ai/Programming/ProgrammingEnterpriseRuntimeTest.php"
  - "php artisan atlas:programming:rivals-readiness --json"
  - "php artisan atlas:programming:completion-audit --json"
next_actions:
  - Manter este standard sincronizado com a spec profissional e o audit command.
  - Expandir parsers do Semantic Code Graph para linguagens priorizadas.
  - Expandir golden sets com tarefas reais de debug, review, repair e frontend.
  - Executar Rivals-Programming real somente com worktrees limpos, custo aprovado e protocolo valido.
requires_evidence: true
risk_level: high
---
# Programming Professional RAG Operating Standard

## Resumo

Este documento define a barra operacional para Atlas Programacao com RAG e
Agentic RAG. A decisao e simples: nesta altura do projeto, MVP fraco de RAG nao serve.
Ele cria contexto falso, relatorio bonito sem valor, custo de provider e decisoes
de codigo sem base verificavel; em producao, isso vira perda de tempo.

O nivel profissional exige que contexto seja tratado como infraestrutura de
confiabilidade: planejado, buscado, ranqueado, criticado, persistido, testado e
replayable.

## Papel no Atlas

Este standard e a regra curta para operadores, agentes e revisores. A spec
`programming-agentic-rag-professional-spec.md` detalha arquitetura. O plano
`programming-enterprise-implementation-plan.md` organiza a implementacao. A
auditoria `programming-professional-completion-audit.md` decide se a frente
pode ser considerada concluida.

No fluxo pesado, este standard e consumido por `atlas-programming-forge-flow.md`.
Ele define Agentic RAG, Semantic Code Graph, context pack, stage receipts,
patch verifier, test impact, sandbox, repair e learning; ele nao substitui
Forge OS, Forge Workspace ou Engineering Harness Runner.

## Onde Se Encaixa

Programming profissional fica sobre cinco camadas:

| Camada | Responsabilidade |
| --- | --- |
| Memory/Open Brain | Decisoes anteriores, docs canonicos, historico e contexto provider-safe. |
| Code Intelligence | Indice de codigo, simbolos, arquivos, docs e relacoes tecnicas. |
| Semantic Code Graph | Dependencias, chamadas, owners, testes, riscos e impacto. |
| Agentic RAG | Planejar busca, iterar, criticar lacunas e montar context pack. |
| Tool Runtime | Executar teste, lint, scan, visual smoke, diff e repair com receipts. |

Nenhuma camada pode substituir as outras por atalho. Busca sem grafo vira
contexto raso. Grafo sem receipts vira conhecimento nao auditavel. Tool Runtime
sem RAG vira execucao cega. RAG sem eval vira opiniao.

## Contratos

### Nivel Aceitavel

Para uma entrega ser chamada de Programming RAG profissional, todos os itens
abaixo precisam existir ou bloquear explicitamente:

| Area | Exigencia |
| --- | --- |
| Retrieval plan | Fontes obrigatorias, queries, budget, risco e politica fail-closed. |
| Context pack | Refs ranqueadas com fonte, score, motivo, hash, privacidade e escopo. |
| Agentic critic | Lacunas, contradicoes, fontes ausentes e decisao de continuar ou parar. |
| Semantic graph | Relacao entre arquivos, simbolos, dependencias, testes e docs. |
| Stage receipts | Plan, review, patch, test e repair reconstruiveis por `plan_id`. |
| Action manifests | Cada acao relevante registra dry-run, rollback, evidencia e gate effect. |
| Patch verifier | Diff so e aceito com teste/razao/manifesto e contexto coerente. |
| Test impact | Testes escolhidos por impacto, nao por chute ou conveniencia. |
| Sandbox | Mudancas de maior risco usam isolamento, checkpoint e rollback. |
| Learning | Aprendizado vira candidato curado, nunca memoria automatica sem review. |

### Anti-MVP

Fica proibido vender como profissional:

- keyword search isolado;
- lista manual de arquivos sugerida pelo agente;
- prompt gigante com documentos colados;
- embedding sem source gate, privacy gate e eval;
- ranking sem metrica;
- contexto sem hash e sem replay;
- patch sem grounded verification;
- benchmark local usado como score contra Claude, Codex ou Gemini;
- tela ou relatorio que mistura readiness, score real e diagnostico historico.

Quando algo ainda nao atende a barra, o nome correto e `foundation`,
`preflight`, `readiness` ou `diagnostic`. Nao e `complete`.

## Fluxo

Fluxo profissional obrigatorio:

```text
task
-> classify flow/risk
-> retrieval objective
-> required source plan
-> hybrid retrieval
-> semantic graph traversal
-> reranking
-> gap critic
-> context pack provider-safe
-> stage plan receipt
-> review/patch/test with action manifests
-> patch verifier
-> repair loop when needed
-> final receipt
-> learning candidate
```

O agente deve parar antes de gastar provider tokens quando qualquer precondicao
atual estiver vermelha: workspace sujo para bateria, baseline contaminado,
rechecks locais ausentes, bateria invalida sem triagem, custo nao aprovado ou
protocolo sem integridade.

## Regras para IA

- Leia este standard antes de implementar mudanca em Programming RAG.
- Nao dependa de memoria da sessao para contexto tecnico importante.
- Nao inclua referencia no context pack sem motivo auditavel.
- Nao avance patch quando fonte obrigatoria esta ausente em flow strict.
- Nao confunda benchmark local com evidencia externa comparavel.
- Nao rode provider pago sem aprovacao explicita e preflight limpo.
- Nao apague historico invalido para "desbloquear"; quarentene com fingerprint.
- Nao promova learning sem review humano ou policy explicita.

## Escopo de Implementacao

### 1. Programming Agentic RAG

Responsavel por decidir o que buscar e se o contexto basta. Deve produzir
`atlas.programming.agentic_rag.professional_plan.v1` e context pack
profissional.

Aceite:

- required sources por flow;
- busca multi-stage;
- critic de lacunas;
- pack hashado;
- benchmark de retrieval.

### 2. Semantic Code Graph

Responsavel por transformar codigo em relacoes consultaveis.

Aceite:

- arquivos, simbolos, imports/dependencias;
- relacao com testes e docs;
- fallback explicito quando indice profundo nao existe;
- uso obrigatorio por Test Impact e retrieval.

### 3. Stage Receipts + Resume

Responsavel por retomada sem depender da conversa atual.

Aceite:

- receipts por etapa;
- validator de ordem, hash e campos obrigatorios;
- resume por `plan_id` e `parent_plan_id`;
- ultimo blocker e proxima acao reconstruiveis.

### 4. Patch Verifier

Responsavel por impedir "done" falso.

Aceite:

- diff revisado por escopo;
- action manifests presentes;
- teste ou razao verificavel;
- bloqueio para arquivos sensiveis sem evidencia adequada.

### 5. Test Impact Analysis

Responsavel por escolher testes proporcionais ao risco.

Aceite:

- selecao por grafo, convencao e historico;
- motivo por teste recomendado;
- golden set local com recall/precision;
- fallback honesto quando nao encontra teste alvo.

### 6. Execution Sandbox Forte

Responsavel por reduzir risco operacional.

Aceite:

- politica por risco;
- worktree/checkpoint quando necessario;
- rollback receipt;
- proibicao de mutacao perigosa sem autorizacao.

### 7. Repair Loop Executor

Responsavel por corrigir falhas com tentativas rastreaveis.

Aceite:

- failure packet;
- max attempts;
- no-progress blocker;
- receipts por tentativa;
- retorno claro para review humano quando a reparacao nao converge.

### 8. Learning Loop De Programacao

Responsavel por aprender sem poluir memoria.

Aceite:

- candidatos deduplicados;
- evidencia e expiry;
- review/promotion gate;
- reversibilidade.

### 9. Rivals-Programming

Responsavel por medir qualidade real contra providers.

Aceite:

- casos pareados;
- mesmo prompt/caso/estado inicial equivalente;
- workspace Atlas limpo;
- baseline separado e limpo;
- provider/model auditados;
- replay/export verificados;
- score admitido apenas quando o protocolo e valido;
- **Atlas arm = runtime Forge obrigatorio**: qualquer run Atlas fora do Forge e invalido para score Rivals. Canon: `atlas-forge-native-rivals-protocol-v1.md` (schema `atlas.programming.forge_native_rivals_protocol.v1`). Atlas Code Fast Path (`atlas:code:forge-fast-path`) e o gateway canonico do Atlas arm.

## Dependencias

| Runtime | Uso correto |
| --- | --- |
| Laravel/PHP | Produto, API, storage, comandos, gates, receipts, orchestration e policy. |
| Python | AST, embeddings locais, reranking, graph analytics, evals e ferramentas de inteligencia. |
| Go | Watchers, indexacao incremental, runners locais e concorrencia quando performance justificar. |

A escolha de runtime nao e gosto. Laravel governa produto e auditabilidade.
Python entra onde a inteligencia tecnica fica melhor. Go entra quando processo
local, concorrencia e performance forem o problema. Nenhum deles pode bypassar
os gates.

## Evidencias

Comandos minimos para sustentar esta frente:

```bash
php artisan atlas:engineering:knowledge docs-health --json
php artisan atlas:ai:architecture-validate --json
php artisan test tests/Unit/Ai/Programming/ProgrammingEnterpriseRuntimeTest.php
php artisan atlas:programming:retrieval-benchmark --json
php artisan atlas:programming:test-impact-benchmark --json
php artisan atlas:programming:patch-verifier-benchmark --json
php artisan atlas:programming:rivals-readiness --json
php artisan atlas:programming:rivals-forge-preflight --json --strict
php artisan atlas:programming:rivals-forge-dry-run --json --strict
php artisan atlas:programming:completion-audit --json
```

Evidencia local verde prova fundacao. Nao prova que Atlas venceu outro provider.
Para claim comparavel, exige `atlas:engineering:benchmark:rivals` com bateria
real, custo aprovado, protocolo valido e export bundle verificado.

## Riscos

| Risco | Bloqueio correto |
| --- | --- |
| RAG virar ruido | Ranking, budget, required sources, precision e critic. |
| Contexto privado vazar | Provider-safe classification e refs local-only. |
| Patch parecer certo mas estar sem base | Patch Verifier e grounded patch rate. |
| Teste errado validar mudanca errada | Test Impact e graph traversal. |
| Repair gastar tentativas sem progresso | Max attempts, no-progress blocker e human review. |
| Rivals gerar placar falso | Score admission gate, timeline historica e export verifier. |

## Exemplos

### Debug profissional

1. Falha vira failure packet.
2. Agentic RAG busca teste, simbolo, docs, diffs recentes e falhas parecidas.
3. Semantic graph aponta dependencias e teste de impacto.
4. Patch muda menor superficie possivel.
5. Test Impact roda teste alvo.
6. Patch Verifier decide se o claim e aceitavel.
7. Receipt final registra contexto, diff, teste, blocker ou learning candidate.

### Review profissional

1. Diff entra como fonte primaria.
2. Graph traversal encontra owners, dependencias, docs e testes.
3. Reranker prioriza risco e contrato arquitetural.
4. O review retorna achados acionaveis, nao opiniao generica.
5. Se evidencia faltar, o review declara lacuna e nao inventa certeza.

## Proximas Acoes

1. Manter este standard sincronizado com a spec profissional e o audit command.
2. Expandir parsers do Semantic Code Graph para linguagens priorizadas.
3. Expandir golden sets com tarefas reais de debug, review, repair e frontend.
4. Executar Rivals-Programming real somente com worktrees limpos, custo aprovado e protocolo valido.
