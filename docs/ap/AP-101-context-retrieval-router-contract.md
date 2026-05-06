# AP-101 — Context Retrieval Router Contract

Status: implemented-routing-plan

## Objetivo

Formalizar a decisao de fontes do Context Builder. O Atlas nao deve misturar
memoria, KB, code intelligence, replay e futuro Graph RAG de forma implicita. A
primeira etapa correta e emitir um plano auditavel de retrieval antes de montar
o Context Pack.

## Nao Objetivo

Nao implementar:

- Graph RAG completo;
- Vector DB novo;
- novo Evidence Ledger;
- nova memoria paralela;
- fanout de especialistas;
- autoalteracao de comportamento critico.

## Fluxo

```text
Atlas Input
-> Intent / Routing
-> ContextRetrievalRouter
-> atlas.context.retrieval_plan.v1
-> AiContextPack.retrieval
-> Open Brain prompt: Retrieval Router Plan
-> Self-Reflection Gate
-> Atlas Decide / Runtime
```

## Fontes Canonicas

```text
vector_retrieval  -> similaridade textual e evidencias rapidas
memory_signals    -> preferencias, decisoes, verbatim e memoria operacional
code_intelligence -> codigo, testes, modulos, rotas, ownership
evidence_replay   -> ledger, replay, auditoria e historico de execucao
graph_retrieval   -> relacoes, causas, dependencias e impacto
```

## Contrato

`ContextRetrievalRouter` deve emitir:

```text
schema_version: atlas.context.retrieval_plan.v1
query_hash
mode: balanced | deep | audit_heavy
selected_sources[]
skipped_sources[]
budgets
policy.provider_safe_only
policy.do_not_create_parallel_memory
policy.router_decides_sources_only
```

Cada fonte deve carregar:

```text
type
enabled
reason
priority
limit
required
unavailable_action
```

## Critérios De Aceite

- [x] `ContextRetrievalRouter` existe como contrato unico de decisao de fontes.
- [x] O plano cobre `vector_retrieval`, `graph_retrieval`,
      `evidence_replay`, `code_intelligence` e `memory_signals`.
- [x] `AiContextPackBuilder` anexa o plano em `retrieval`.
- [x] `AiContextPack::toPromptSection()` expoe `Retrieval Router Plan`.
- [x] Evidencia/replay fica required em contexto de alto risco.
- [x] Graph retrieval aparece como plano declarativo para arquitetura,
      dependencia, causa, impacto, pesquisa e decisao.
- [x] Architecture validate expoe
      `ap101_context_retrieval_router_contract`.

## Arquivos

```text
app/Services/Ai/Context/ContextRetrievalRouter.php
app/Services/Ai/AiContextPackBuilder.php
app/Services/Ai/ValueObjects/AiContextPack.php
app/Services/Ai/Kernel/Architecture/KernelArchitectureStaticScanner.php
app/Services/Ai/Kernel/Architecture/AtlasAiArchitectureValidationService.php
tests/Unit/Ai/Context/ContextRetrievalRouterTest.php
tests/Feature/Ai/AtlasAiArchitectureValidateCommandTest.php
tests/Feature/Ai/AtlasAiArchitectureValidateApiTest.php
docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md
```
