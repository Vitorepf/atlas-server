---
id: atlas-ai-programming-agentic-rag-professional-spec
type: engineering_knowledge
title: Programming Agentic RAG Professional Spec
status: active
category: architecture
priority: 100
summary: Especificacao profissional para RAG e Agentic RAG em programacao, proibindo MVP fraco como criterio de conclusao e definindo arquitetura, gates, evals, contratos e DoD enterprise.
tags:
  - atlas-ai
  - programming
  - rag
  - agentic-rag
  - code-intelligence
capabilities:
  - programming_agentic_rag
  - semantic_code_graph
  - retrieval_quality
  - context_pack_governance
  - code_repair
decisions:
  - Programming Agentic RAG profissional e produto de confiabilidade, nao experimento de prompt.
  - MVP de RAG nao e criterio aceitavel para Atlas Programacao; o minimo aceitavel e retrieval governado, medido, replayable e integrado ao fluxo plan/review/patch/test/repair.
  - Retrieval deve ser multi-stage, hybrid, graph-aware, provider-safe e auditavel por receipts.
  - Nenhum patch deve depender de contexto nao rastreado, nao ranqueado ou sem justificativa de inclusao.
maintenance:
  - Atualize esta spec antes de alterar retrieval, context pack, code graph, embeddings, reranking ou repair context.
  - Nao declare Agentic RAG profissional sem benchmarks de retrieval e evidencias de uso real em programacao.
related_paths:
  - app/Services/Ai/Programming/ProgrammingRetrievalPlanner.php
  - app/Services/Ai/Programming/ProgrammingRetrievalExecutor.php
  - app/Services/Ai/Programming/ProgrammingPythonRuntimeContract.php
  - app/Services/Ai/Programming/ProgrammingPythonRuntimeExecutor.php
  - app/Services/Ai/Programming/ProgrammingPythonRuntimeGraphProjector.php
  - app/Services/Ai/Programming/ProgrammingRivalsReadinessService.php
  - app/Services/Ai/Programming/ProgrammingProfessionalCompletionAuditService.php
  - app/Services/Ai/Programming/ProgrammingSemanticCodeGraphService.php
  - app/Services/Ai/Programming/AtlasProgrammingOrchestrator.php
  - runtimes/python/programming_intelligence
  - app/Services/Ai/AtlasOpenBrainContextInjectionService.php
  - app/Services/Engineering/EngineeringCodeIntelligenceService.php
  - docs/engineering-knowledge-base/domains/programming-enterprise-implementation-plan.md
  - docs/engineering-knowledge-base/domains/programming.md
  - docs/engineering-knowledge-base/memory/retrieval-and-context.md
  - docs/engineering-knowledge-base/evolution/context-builder-roadmap.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-ai-programming-agentic-rag-professional-spec
graph_title: Programming Agentic RAG Professional Spec
graph_world: atlas
graph_layer: system
graph_kind: module
graph_parent: atlas-ai-programming-domain
graph_status: active
graph_source: repo
owner: domains
repo_paths:
  - docs/engineering-knowledge-base/domains/programming-agentic-rag-professional-spec.md
allowed_changes:
  - Atualizar arquitetura, gates, contratos, evals e DoD quando a implementacao evoluir.
forbidden_changes:
  - Chamar keyword search, context dump ou prompt stuffing de Agentic RAG profissional.
  - Marcar completo sem evals de retrieval, replay, receipts e casos reais.
  - Enviar codigo privado bruto para provider/vector store externo sem classificacao provider-safe.
depends_on:
  - atlas-ai-programming-domain
  - atlas-ai-memory-retrieval-and-context
  - atlas-code-intelligence
  - atlas-ai-context-builder-roadmap
flows_to:
  - programming-agentic-rag
  - semantic-code-graph
  - test-impact-analysis
  - repair-loop-executor
unlocks:
  - atlas-programming-professional-runtime
  - atlas-rivals-programming-quality
governs:
  - programming
evidence:
  - docs/engineering-knowledge-base/domains/programming-agentic-rag-professional-spec.md
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
  - "php artisan test tests/Unit/Ai/Programming/ProgrammingEnterpriseRuntimeTest.php"
  - "php artisan test tests/Unit/Ai/AtlasOpenBrainContextInjectionServiceTest.php"
  - "php artisan atlas:programming:rivals-readiness --json"
  - "php artisan atlas:programming:completion-audit --json"
next_actions:
  - Expandir uso do Python runtime aprovado para mais linguagens e benchmarks reais.
  - Ampliar os golden sets locais com mais tarefas reais e regressao historica.
  - Integrar Rivals-Programming para medir impacto do contexto por provider em tarefas reais.
requires_evidence: true
risk_level: high
---

# Programming Agentic RAG Professional Spec

## Resumo

Para Atlas Programacao, RAG fraco e perda de tempo. O alvo profissional nao e
"achar alguns arquivos e montar prompt". O alvo e uma camada de contexto que
melhora implementacao, debug, review, repair e teste com evidencia mensuravel.

O minimo aceitavel para chamar de profissional:

- retrieval hibrido com lexical, semantico, grafo e historico;
- planejamento agentico com iteracoes de busca, lacuna e verificacao;
- context pack provider-safe, ranqueado, com hash, fonte e motivo;
- Semantic Code Graph com simbolos, dependencias, testes, docs e owners;
- receipts para retrieval, context pack, stage e repair;
- evals de recall, precision, groundedness e impacto em patch/test;
- replay do contexto usado em uma decisao de codigo.

Qualquer coisa abaixo disso deve ser nomeada como `foundation`, `contract` ou
`preflight`, nunca como Agentic RAG completo.

## Barra Profissional

O nivel profissional nao e medido por existir uma feature de busca. Ele e medido
por reduzir erro real de programacao com contexto correto, verificavel e
reutilizavel. A implementacao precisa responder, antes de qualquer patch:

- quais fontes eram obrigatorias para esta tarefa;
- quais fontes foram encontradas, ranqueadas e descartadas;
- por que cada referencia entrou no context pack;
- quais lacunas permanecem e se elas bloqueiam execucao;
- quais testes ou gates provam que o contexto ajudou;
- como outro agente reconstroi o mesmo contexto pelo hash/receipt.

Se essas respostas nao existem, o sistema ainda pode ser util como ferramenta
de apoio, mas nao pode ser vendido internamente como Programming Agentic RAG
profissional.

## Anti-MVP

Nesta area, MVP pequeno e aceitavel apenas para instrumentacao, nao para claim
de inteligencia. Fica proibido chamar de RAG/Agentic RAG profissional qualquer
entrega que tenha somente:

- busca por palavra-chave;
- lista manual de arquivos;
- prompt grande com documentos colados;
- embedding sem source gate, privacy gate e eval;
- ranking sem metrica;
- contexto sem hash/replay;
- patch sem grounded verification;
- benchmark local usado como substituto de bateria real.

O menor corte aceitavel e uma fundacao governada: contratos, context pack,
receipts, gates, eval local e bloqueio honesto de claim externo. Isso nao e
MVP; e a base minima para nao criar uma camada que parece inteligente mas gera
ruido, custo e conclusoes falsas.

## Papel no Atlas

Esta spec governa o nivel profissional de RAG/Agentic RAG dentro do dominio
Programming. Ela complementa Memory/Open Brain, Code Intelligence, Tool Runtime
e Evidence Ledger, mas nao permite que qualquer uma dessas camadas seja usada
como atalho sem gates, ranking, receipts e evals.

## Onde Se Encaixa

O documento fica entre:

- `programming-enterprise-implementation-plan.md`, que governa os oito blocos;
- `memory/retrieval-and-context.md`, que governa retrieval geral;
- `context-builder-roadmap.md`, que governa Vector RAG, Graph RAG e routing;
- `code-intelligence.md`, que governa indice de codigo e docs.

## Fluxo

```text
Programming task
-> intent/flow/risk
-> retrieval objective decomposition
-> source planner
-> hybrid retrieval
   -> lexical/code search
   -> vector retrieval
   -> graph traversal
   -> prior receipts/history
   -> canonical docs/rules
-> reranking
-> gap critic
-> optional second retrieval pass
-> provider-safe context pack
-> context sufficiency gate
-> plan/review/patch/test/repair
-> retrieval eval + learning candidate
```

O orquestrador nao pode depender de memoria de sessao. Todo contexto importante
deve ser reconstruivel por `plan_id`, `parent_plan_id`, receipts e hashes.

## Regras para IA

- Nao chamar busca lexical unica de Agentic RAG.
- Nao declarar contexto suficiente sem source gate.
- Nao incluir arquivo no contexto sem motivo e hash.
- Nao usar embeddings externos sem classificacao provider-safe.
- Nao promover aprendizado de repair para memoria sem review.
- Nao comparar providers se eles receberam contextos diferentes sem registrar.

## Escopo de Implementacao

### Componentes

| Componente | Responsabilidade |
| --- | --- |
| Retrieval Planner | Decompor objetivo, definir fontes obrigatorias, budget e fail-closed. |
| Retrieval Executor | Executar buscas, dedupe, ranking inicial e context pack provider-safe. |
| Semantic Code Graph | Mapear arquivos, simbolos, dependencias, owners, testes e docs. |
| Vector Index | Recuperar similaridade semantica por chunks classificados e versionados. |
| Graph Traverser | Navegar impacto: arquivo -> simbolo -> dependencias -> testes -> docs. |
| Reranker | Reordenar refs por utilidade para a tarefa, risco e fase. |
| Gap Critic | Detectar contexto insuficiente, contraditorio, obsoleto ou sem teste. |
| Context Pack Assembler | Produzir pack final com fonte, motivo, score, hash, limite e redacao. |
| Retrieval Evaluator | Medir qualidade offline/online e bloquear regressao. |

### Fontes Obrigatorias

| Flow | Fontes obrigatorias |
| --- | --- |
| `programming.dev` | code symbols, canonical docs, related tests quando houver patch, prior decisions. |
| `programming.repair` | failure packet, failed test output, changed files, prior receipts, known failures, related tests. |
| `programming.review` | diff, owners, docs canonicos, risk rules, prior decisions. |
| `programming.frontend` | component tree, visual evidence, routes, assets, a11y/perf refs, design decisions. |
| `programming.security` | touched attack surface, secrets policy, auth/data-flow docs, tests and threat refs. |
| `programming.database` | migrations, models, queries, indexes, rollback path, data-risk docs. |
| `programming.forge` | all above as needed, plus stage receipts and sandbox evidence. |

Se uma fonte obrigatoria estiver ausente, o gate deve bloquear, degradar com
motivo explicito ou exigir `no_context_reason` revisavel.

## Contratos

```json
{
  "schema_version": "atlas.programming.agentic_rag.professional_plan.v1",
  "plan_id": "uuid",
  "flow": "programming.repair",
  "objective_hash": "sha256",
  "retrieval_strategy": "hybrid_graph_semantic",
  "required_sources": [],
  "source_queries": [],
  "iterations": [
    {
      "step": 1,
      "goal": "find touched symbols and tests",
      "sources": ["code_symbols", "related_tests"],
      "status": "passed|degraded|blocked",
      "gap_after_step": []
    }
  ],
  "context_sufficiency_gate": {
    "status": "passed|degraded|blocked",
    "reasons": []
  },
  "retrieval_receipt_id": "sha256"
}
```

```json
{
  "schema_version": "atlas.programming.context_pack.professional.v1",
  "context_pack_hash": "sha256",
  "provider_safe": true,
  "ranked_refs": [
    {
      "source": "code_symbols|canonical_docs|related_tests|stage_receipts|known_failures",
      "ref": "path-or-id",
      "score": 0.93,
      "reason": "why this reference is needed",
      "scope": "plan|review|patch|test|repair",
      "hash": "sha256",
      "freshness": "current|stale|unknown",
      "privacy": "provider_safe|local_only"
    }
  ],
  "excluded_refs": [
    {
      "ref": "path-or-id",
      "reason": "duplicate|unsafe|stale|low_relevance"
    }
  ],
  "budget": {
    "max_refs": 40,
    "max_chars": 20000,
    "used_chars": 0
  }
}
```

## Agentic Loop

O loop profissional tem no minimo quatro etapas:

1. **Plan retrieval**: transformar tarefa em perguntas de contexto.
2. **Retrieve and rank**: buscar em code, docs, memoria, receipts e grafo.
3. **Critic**: responder se contexto basta para agir sem chute.
4. **Repair retrieval**: se faltar fonte, buscar novamente ou bloquear.

O loop deve parar quando o gate passa, o budget esgota, fonte obrigatoria esta
ausente, existe contradicao entre docs/codigo/teste ou a tarefa exige operador.

## Dependencias

- Memory/Open Brain para provider-safe memory, prior decisions e recall geral.
- Code Intelligence para simbolos, owners, docs e testes relacionados.
- Tool Runtime para action manifests, dry-run, rollback e evidence.
- Evidence Ledger para receipts, replay e auditoria.
- Python runtime para embeddings, reranking, AST multi-linguagem e evals.
- Go runtime opcional para watchers, indexacao incremental e runners locais.

## Evals

Sem evals, nao existe RAG profissional.

| Metrica | Uso |
| --- | --- |
| Recall@k | O pack encontrou arquivos/testes/docs esperados? |
| Precision@k | Quantos refs incluidos eram realmente uteis? |
| Context waste ratio | Quanto budget foi gasto com refs irrelevantes? |
| Grounded patch rate | Patches citam contexto correto? |
| Test selection accuracy | Test Impact escolheu testes que pegam regressao? |
| Repair improvement rate | Repair com RAG reduz repeticao da mesma falha? |
| Replayability | Outro agente consegue reconstruir o mesmo contexto? |

Gates minimos para promocao:

- golden set de tarefas reais;
- diff entre retrieval atual e baseline anterior;
- regressao bloqueia promocao;
- resultados salvos com hashes, nunca raw private code em relatorio externo.

## Runtime Por Linguagem

| Runtime | Papel |
| --- | --- |
| Laravel/PHP | Orquestracao, API, storage, receipts, gates e produto. |
| Python | Embeddings, reranking, AST multi-linguagem, graph analytics e evals. |
| Go | Agente local, watchers, indexacao incremental, runners e concorrencia. |

Laravel continua dono do produto e da governanca. Python pode executar
inteligencia especializada. Go pode executar partes locais de alta performance.
Nenhum runtime alternativo pode bypassar receipts, provider safety ou gates.

## DoD Profissional

Programming Agentic RAG so pode ser chamado profissional quando todos forem
verdadeiros:

- `professional_plan` emitido e persistido;
- context pack final persistido, hashado e replayable;
- vector/hybrid retrieval disponivel ou explicitamente bloqueado por gate;
- Semantic Code Graph cobre simbolos, dependencias, testes e docs;
- reranking tem metrica e fallback;
- gap critic bloqueia contexto insuficiente;
- repair usa failure packet e historico real;
- Test Impact consome o grafo;
- Learning Loop registra acertos/falhas sem auto-promover memoria;
- benchmark de retrieval roda em corpus real;
- Rivals-Programming consegue comparar qualidade de contexto por provider.

## Evidencias

Comandos esperados:

```bash
php artisan atlas:engineering:knowledge docs-health --json
php artisan test tests/Unit/Ai/Programming/ProgrammingEnterpriseRuntimeTest.php
php artisan test tests/Unit/Ai/AtlasOpenBrainContextInjectionServiceTest.php
php artisan atlas:ai:local-rag-benchmark --json
php artisan atlas:programming:rivals-readiness --json
php artisan atlas:programming:completion-audit --json
php artisan atlas:engineering:benchmark:rivals report --json
```

O benchmark local mede retrieval. O Rivals mede impacto em programacao. Um nao
substitui o outro.

`atlas:programming:rivals-readiness` nao executa provider externo e nao cria
score sintetico. Ele consolida retrieval, test impact e patch verifier locais,
declara se a fundacao esta pronta e aponta a bateria real que exige operador,
runbook revisado e aceite explicito de custo. Claim comparavel so existe quando
`atlas:engineering:benchmark:rivals` tiver casos pareados reais e report
verificavel. O readiness tambem emite
`atlas.programming.rivals_operator_execution_packet.v1` com comando recomendado,
confirmacoes obrigatorias e `provider_dispatches_now=false`.

`atlas:programming:completion-audit` e o gate executavel de conclusao. Ele
retorna `blocked` enquanto a bateria real nao estiver `claim_ready`, mesmo que a
fundacao local esteja verde.

## Riscos

| Risco | Mitigacao |
| --- | --- |
| Prompt stuffing virar "RAG" | Proibicao explicita, context pack com score/motivo/hash e evals. |
| Contexto irrelevante consumir budget | Precision@k e context waste ratio. |
| Patch baseado em fonte errada | Grounded patch rate e replay de context pack. |
| Retrieval esconder teste importante | Test selection accuracy e Graph traversal. |
| Embedding vazar codigo privado | Provider-safe classification e local-only refs. |
| Agentic loop infinito | Budget, max iterations, gap critic e fail-closed. |

## Exemplos

### Repair profissional

1. Recebe failure packet e teste falho.
2. Busca simbolo, teste, docs, mudancas recentes e falhas similares.
3. Critic detecta se falta owner, contrato ou fixture.
4. Segunda busca ocorre apenas se houver lacuna concreta.
5. Context pack final recebe hash e refs ranqueadas.
6. Patch/test/repair usam o mesmo receipt.

### Review profissional

1. Recebe diff e arquivos alterados.
2. Graph traversal identifica dependencias, testes e docs canonicos.
3. Reranker prioriza risco, ownership e regras arquiteturais.
4. Patch Verifier bloqueia claim se contexto/teste/manifest estiver fraco.

## Roadmap Sem MVP

| Fase | Nome | Entrega |
| --- | --- | --- |
| 1 | Governed Foundation | Planner, context pack, code graph, receipts e gates. |
| 2 | Hybrid Retrieval | lexical + vector + graph + history com storage e replay. |
| 3 | Agentic Critic | multi-pass retrieval, gap critic e fail-closed por flow. |
| 4 | Retrieval Evals | golden set, recall/precision, regression gate e reports. |
| 5 | Repair Intelligence | falhas similares, patch/test history e no-progress learning. |
| 6 | Rivals-Programming | medir Atlas contra Claude/Codex/Gemini em tarefas reais. |

Fase 1 nao e MVP. E fundacao governada. O termo MVP nao deve ser usado como
desculpa para entregar retrieval sem medida, sem replay ou sem gate.

Status em 2026-05-13: a fundacao profissional local emite
`atlas.programming.agentic_rag.professional_plan.v1`, context pack profissional
persistido/replayable por hash, reranking deterministico, critic de lacunas,
eval online proxy, vector index local provider-safe, Python runtime governado
em `runtimes/python/programming_intelligence` para AST/simbolos/embedding local
deterministico, executor PHP com gate de approval/runtime boundary/Decision
Receipt, projector para fragmento do Semantic Code Graph, benchmark golden-set
de retrieval via
`atlas:programming:retrieval-benchmark`, benchmark golden-set de test selection
via `atlas:programming:test-impact-benchmark` e benchmark golden-set de
grounded patch via `atlas:programming:patch-verifier-benchmark`. O contrato
`atlas.programming.rivals_readiness.v1` esta disponivel via
`atlas:programming:rivals-readiness` para separar evidencia local de bateria
provider real. Ainda nao executa Rivals-Programming real automaticamente e nao
aceita score comparavel sem `atlas:engineering:benchmark:rivals` com providers.

## Proximas Acoes

1. Expandir uso do Python runtime aprovado para mais linguagens e benchmarks reais.
2. Expandir golden sets locais com casos reais de debug, review, repair e frontend.
3. Executar, com aceite de custo, Rivals-Programming real e verificar export bundle.
