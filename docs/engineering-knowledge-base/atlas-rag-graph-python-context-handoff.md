---
id: atlas-rag-graph-python-context-handoff
type: engineering_knowledge
title: Atlas RAG Graph Python Context Handoff
status: active
category: intelligence-runtime
priority: 96
summary: Handoff operacional para IA recuperar rapidamente o estado real de RAG, Agentic RAG, Graph/World Model, contexto persistente, memoria, compounding e runtimes Python/data no Atlas sem depender da conversa atual.
tags:
  - atlas-ai
  - rag
  - agentic-rag
  - graph-rag
  - world-model
  - python-runtime
  - context-handoff
capabilities:
  - rag_context_bootstrap
  - agentic_rag_map
  - graph_rag_boundary
  - python_data_runtime_boundary
  - persistent_context_handoff
  - retrieval_claim_integrity
decisions:
  - Este arquivo e handoff de contexto, nao fonte primaria de arquitetura.
  - Programming Agentic RAG ja possui spec e standard ativos; este handoff aponta para eles.
  - Graph RAG global/external vector RAG ainda nao deve ser promovido sem AP, review humano, decision receipt e benchmark/golden set.
  - Python runtime de programacao existe como runtime local governado por contrato; nao e provider, nao decide e nao pode chamar rede/provider/shell arbitrario.
  - Contexto deve ser provider-safe, hashado, com evidence refs, sufficiency gate e claim policy.
maintenance:
  - Atualizar quando RAG, ACIE, APCR, World Model, Graph RAG, Python runtime ou Local RAG readiness mudarem.
  - Manter este handoff curto; detalhes pertencem aos docs e services referenciados.
related_paths:
  - docs/engineering-knowledge-base/domains/programming-agentic-rag-professional-spec.md
  - docs/engineering-knowledge-base/domains/programming-professional-rag-operating-standard.md
  - docs/engineering-knowledge-base/atlas-context-intelligence-engine.md
  - docs/engineering-knowledge-base/atlas-persistent-context-runtime.md
  - docs/engineering-knowledge-base/memory/retrieval-and-context.md
  - docs/engineering-knowledge-base/atlas-world-model.md
  - docs/engineering-knowledge-base/atlas-semantic-graph.md
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
  - app/Services/Ai/Programming/ProgrammingRetrievalPlanner.php
  - app/Services/Ai/Programming/ProgrammingGraphRagRuntime.php
  - app/Services/Ai/Programming/ProgrammingPythonRuntimeContract.php
  - app/Services/Ai/Context/LocalRagReadinessService.php
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-rag-graph-python-context-handoff
graph_title: Atlas RAG Graph Python Context Handoff
graph_world: atlas
graph_layer: system
graph_kind: module
graph_parent: atlas-context-intelligence-engine
graph_status: active
graph_source: repo
owner: atlas-ai
repo_paths:
  - docs/engineering-knowledge-base/atlas-rag-graph-python-context-handoff.md
allowed_changes:
  - Atualizar evidencias, comandos e gaps quando a implementacao real mudar.
forbidden_changes:
  - Declarar Graph RAG global, external vector RAG ou benchmark externo como pronto sem evidencia real.
  - Tratar Python runtime como decisor ou provider.
  - Ignorar APCR/ACIE/Mandatory RAG Gate ao implementar fluxo de contexto.
depends_on:
  - atlas-context-intelligence-engine
  - atlas-persistent-context-runtime
  - atlas-ai-programming-agentic-rag-professional-spec
flows_to:
  - atlas-dev
  - atlas-forge
  - atlas-ai-router-runtime
  - atlas-unified-reality-graph
unlocks:
  - rag_graph_python_fast_context_bootstrap
  - safer_future_graph_rag_implementation
governs:
  - rag_context_handoff
  - graph_rag_claim_integrity
  - python_runtime_context_boundary
evidence:
  - docs/engineering-knowledge-base/atlas-rag-graph-python-context-handoff.md
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
requires_evidence: true
risk_level: high
line_limit: 520
next_actions:
  - Usar este handoff antes de desenhar AURG ou qualquer nova camada de Graph/Reality Graph.
  - Rodar readiness/certifications listadas antes de dizer que RAG/Graph/Python esta pronto.
---

# Atlas RAG Graph Python Context Handoff

## Resumo

Este handoff existe para uma IA entrar no Atlas e entender rapidamente o estado
real de RAG, Agentic RAG, Graph/World Model e Python/data runtime. Ele nao
substitui as specs; ele diz onde esta a verdade e quais claims podem ou nao ser
feitos.

Resumo operacional:

- RAG de programacao tem spec profissional ativa e implementacao em services.
- Atlas Dev possui Mandatory RAG Gate fail-closed para tarefas nao triviais.
- ACIE e APCR sao as camadas transversais de contexto persistente, retrieval,
  compaction, sufficiency, handoff e certificacao.
- Graph RAG existe de forma bounded para Programming, via grafo local +
  semantic local index; Graph RAG global/external vector RAG segue governado e
  bloqueado ate AP/review.
- World Model existe em variantes: World Model geral documentado, Codebase World
  Model persistido/rankeavel e Time-Aware World Model em TEOS.
- Python/data runtime existe para analise local de programacao, protegido por
  invocation contract e gate; nao e decisor e nao chama provider.

## Papel no Atlas

Use este arquivo como handoff inicial antes de implementar qualquer coisa em:

- RAG ou Agentic RAG;
- Context Pack, APCR, ACIE ou compaction;
- Semantic Code Graph, World Model ou Reality Graph;
- Python runtime para analise de codigo/dados;
- futuro AURG/Atlas Unified Reality Graph.

Ele evita dois erros recorrentes:

1. tratar docs/servicos existentes como se fossem greenfield;
2. declarar Graph RAG/Python/external vector pronto sem os gates reais.

## Onde Se Encaixa

Hierarquia atual simplificada:

```text
Atlas AI / Hyperflow
  -> APCR: contexto persistente obrigatorio
  -> ACIE: qualidade, retrieval, compaction, freshness, handoff
  -> Router / Domain / Flow
  -> Atlas Dev ou Atlas Forge
     -> Programming Agentic RAG
     -> Semantic Code Graph / Graph RAG bounded
     -> Mandatory RAG Gate
     -> Python runtime local quando aprovado
     -> receipts / tests / repair / learning
```

Camadas relacionadas:

| Camada | Estado | Evidencia principal |
| --- | --- | --- |
| APCR | ativo | `AtlasPersistentContextRuntimeService`, doc APCR, certify command |
| ACIE | ativo | `AtlasContextIntelligenceService`, doc ACIE, certify command |
| Programming Agentic RAG | ativo | `ProgrammingRetrievalPlanner`, professional spec/standard |
| Mandatory RAG Gate | ativo | `AtlasDev/Gate/MandatoryRagGate.php`, `config/atlas_dev.php` |
| Graph RAG Programming | bounded/local | `ProgrammingGraphRagRuntime.php` |
| Semantic Code Graph | ativo com fallback | `ProgrammingSemanticCodeGraphService.php` |
| Local RAG readiness | ativo | `LocalRagReadinessService.php`, command |
| Python Programming Runtime | governado | `ProgrammingPythonRuntimeContract/Executor/GraphProjector` |
| Global external Graph/vector RAG | futuro governado | Local RAG benchmark/review contracts |

## Contratos

Contratos e schemas importantes:

| Schema | Dono | Uso |
| --- | --- | --- |
| `atlas.programming.agentic_rag.plan.v1` | Programming RAG | Plano de retrieval por flow |
| `atlas.programming.context_pack.professional.v1` | Programming RAG | Pack provider-safe ranqueado |
| `atlas.programming.graph_rag_runtime.v1` | Programming | Graph RAG bounded/local |
| `atlas.programming.retrieval_receipt.v1` | Programming | Hash/replay do retrieval |
| `atlas.programming.python_runtime.invocation_contract.v1` | Python runtime | Contrato de execucao local |
| `atlas.programming.python_runtime.execution_receipt.v1` | Python runtime | Receipt de execucao local |
| `atlas.programming.python_runtime.graph_fragment.v1` | Python runtime | Fragmento de grafo gerado por Python |
| `atlas.context_intelligence.certification.v1` | ACIE | Certificacao da camada de contexto |
| `atlas.persistent_context.certification.v1` | APCR | Certificacao de contexto persistente |
| `atlas.local_rag_readiness.v1` | Local RAG | Readiness local |
| `atlas.ai.codebase_world_model.ranking.v1` | World Model | Ranking graph-aware |

Invariantes:

- Provider nao decide fonte de contexto.
- Python runtime nao decide; Kernel decide e runtime executa.
- Context pack precisa ter refs, motivos, score, hash e privacy/provider-safe.
- Tarefa de engenharia nao trivial deve passar por Mandatory RAG Gate ou bloquear.
- External vector/Graph RAG nao pode auto-promover.

## Fluxo

Fluxo de retrieval de programacao:

```text
objective + flow
-> ProgrammingRetrievalPlanner
-> required sources por flow
-> ProgrammingSemanticCodeGraphService
-> ProgrammingGraphRagRuntime
-> ProgrammingRetrievalExecutor
-> ProgrammingProfessionalReranker
-> ProgrammingGapCritic
-> ProgrammingRetrievalEvaluator
-> ProgrammingContextPackStore
-> context sufficiency gate
-> retrieval receipt
```

Fluxo APCR/ACIE:

```text
prompt
-> APCR build
-> context pack + must-know ledger + sufficiency
-> ACIE assess/context operations
-> Hyperflow/Router/Flow
-> Dev/Forge/flow specialist
-> outcome memory candidate, not automatic durable memory
```

Fluxo Python/data runtime:

```text
retrieval plan selects files
-> ProgrammingPythonRuntimeContract::manifest()
-> decision receipt + runtime boundary + approval required
-> ProgrammingPythonRuntimeExecutor::execute()
-> ProgrammingPythonRuntimeGraphProjector::project()
-> graph fragment can inform retrieval/world model
```

## Regras para IA

- Leia primeiro os docs de RAG profissional e ACIE/APCR antes de propor nova
  arquitetura de contexto.
- Nao chame busca lexical, prompt stuffing ou lista manual de arquivos de RAG
  profissional.
- Nao crie store paralelo de memoria, context pack, graph ou evidence sem ADR.
- Nao habilite external vector RAG sem review humano, AP, privacy retention,
  rollback e golden-set benchmark.
- Nao use Python runtime para chamar rede, provider, shell arbitrario ou gravar
  memoria.
- Nao declare “AURG pronto” usando apenas World Model atual; World Model atual e
  insumo, nao grafo unificado de realidade final.
- Quando o contexto for insuficiente, retorne degraded/blocked com missing
  sources, nao invente fonte.

## Escopo de Implementacao

Se a proxima meta for melhorar RAG/Graph:

1. Reusar `ProgrammingRetrievalPlanner` e `ProgrammingRetrievalExecutor`.
2. Conectar `WorldModelGraphRanker` ao reranking profissional, se ainda nao
   estiver ligado no flow desejado.
3. Melhorar `ProgrammingSemanticCodeGraphService` sem quebrar fallback
   filesystem/indexed tables.
4. Rodar `atlas:programming:retrieval-benchmark --json`.
5. Rodar `atlas:ai:local-rag-readiness --json`.
6. Manter external vector/Graph global como proposal-only ate aprovacao.

Se a proxima meta for AURG:

1. Tratar AURG como grafo unificado acima de APCR/ACIE/AEMOR/ASRE/AARS.
2. Reusar World Model, Semantic Graph, Codebase World Model e Evidence Ledger.
3. Nao fundir tudo em uma tabela sem plano de lineage, freshness, authority e
   privacy.
4. Criar readers/projections primeiro; escrita/correcoes depois.

## Dependencias

Docs primarios:

- `domains/programming-agentic-rag-professional-spec.md`
- `domains/programming-professional-rag-operating-standard.md`
- `atlas-context-intelligence-engine.md`
- `atlas-persistent-context-runtime.md`
- `memory/retrieval-and-context.md`
- `atlas-world-model.md`
- `atlas-semantic-graph.md`

Services primarios:

- `ProgrammingRetrievalPlanner`
- `ProgrammingRetrievalExecutor`
- `ProgrammingGraphRagRuntime`
- `ProgrammingSemanticCodeGraphService`
- `MandatoryRagGate`
- `LocalRagReadinessService`
- `LocalRagBenchmarkService`
- `WorldModelGraphRanker`
- `TimeAwareWorldModelService`
- `ProgrammingPythonRuntimeContract`
- `ProgrammingPythonRuntimeExecutor`
- `ProgrammingPythonRuntimeGraphProjector`

## Evidencias

Evidencia de leitura feita para este handoff:

- RAG profissional: docs `programming-agentic-rag-professional-spec.md` e
  `programming-professional-rag-operating-standard.md`.
- ACIE/APCR: docs canonicos e certification services.
- Mandatory RAG: `MandatoryRagGate.php` e `config/atlas_dev.php`.
- Graph bounded: `ProgrammingGraphRagRuntime.php`.
- Semantic graph/code graph: `ProgrammingSemanticCodeGraphService.php`,
  `atlas-semantic-graph.md`, `WorldModelGraphRanker.php`.
- Python/data: `ProgrammingPythonRuntimeContract.php`,
  `ProgrammingPythonRuntimeExecutor.php`, `ProgrammingPythonRuntimeGraphProjector.php`,
  `runtimes/python/programming_intelligence/main.py`.

Estado verificado em 2026-05-21:

| Gate | Status | Evidencia |
| --- | --- | --- |
| Docs health | `ok` | `php artisan atlas:engineering:knowledge docs-health --json` |
| ACIE | `passed` 12/12 | `php artisan atlas:context-intelligence:certify --json --strict` |
| APCR | `passed` 14/14 | `php artisan atlas:persistent-context:certify --json --strict` |
| Local RAG | `ready` | `php artisan atlas:ai:local-rag-readiness --json` |
| Graph retrieval global | `future_governed` | Local RAG readiness seleciona `graph_retrieval`, mas `available=false` |

Leitura correta desse estado: RAG local e contexto persistente estao prontos
para uso governado; Graph RAG global/external vector ainda e proposta governada,
nao runtime promovido.

Comandos de verificacao recomendados:

```bash
php artisan atlas:engineering:knowledge docs-health --json
php artisan atlas:context-intelligence:certify --json --strict
php artisan atlas:persistent-context:certify --json --strict
php artisan atlas:ai:local-rag-readiness --json
php artisan atlas:programming:retrieval-benchmark --json
php artisan test tests/Unit/Ai/Programming/AtlasDev/Gate/MandatoryRagGateTest.php
php artisan test tests/Feature/Ai/ContextIntelligence tests/Feature/Ai/PersistentContext
```

## Riscos

Riscos reais:

- Overengineering: criar AURG/Graph novo ignorando stores existentes.
- Claim falso: dizer que Graph RAG global esta ativo quando o proprio router
  marca `graph_retrieval` como `future_governed`.
- Contexto caro: colar tudo no prompt em vez de refs ranqueadas e hashes.
- Privacidade: enviar memoria/codigo bruto para embedding/provider externo.
- Python runtime escapar do boundary: qualquer chamada de rede/provider/shell
  precisa continuar bloqueada por contrato.
- World Model stale: usar edges antigas sem Time-Aware World Model/freshness.

Mitigacoes:

- Reuse-first.
- Evidence refs e hashes.
- Sufficiency/missing source gate.
- Local-first para embedding/retrieval.
- Human review para external vector/Graph promotion.
- Decision receipt antes de runtime execution.

## Exemplos

Exemplo de handoff para nova IA:

```text
Antes de implementar RAG/Graph no Atlas, leia
docs/engineering-knowledge-base/atlas-rag-graph-python-context-handoff.md.
Depois leia os docs primarios citados em Dependencias. Nao trate Graph RAG global
como pronto; use ProgrammingGraphRagRuntime apenas no escopo bounded/local e
preserve APCR/ACIE/Mandatory RAG Gate.
```

Exemplo de decisao correta:

- Melhorar Atlas Dev retrieval: mexer em `ProgrammingRetrievalPlanner`,
  `ProgrammingRetrievalExecutor`, `ProgrammingProfessionalReranker` e testes.
- Melhorar grafo de codigo: mexer em `ProgrammingSemanticCodeGraphService` ou
  `WorldModelGraphRanker`, mantendo receipts.
- Melhorar Python/data: ampliar analyzer em `runtimes/python/programming_intelligence`
  e manter gate/contract.

## Proximas Acoes

1. Rodar os comandos de certificacao listados em Evidencias.
2. Se o objetivo for AURG, fazer primeiro um design reuse-first usando este
   handoff como mapa de dependencias.
3. Se o objetivo for RAG profissional, priorizar integração do
   `WorldModelGraphRanker` no reranking/pack real antes de criar novo grafo.
4. Se o objetivo for Python/data, ampliar o runtime local com tests Python e
   receipt projection, sem provider/network.
5. Atualizar este handoff sempre que algum status `future_governed`, `bounded`
   ou `active` mudar.
