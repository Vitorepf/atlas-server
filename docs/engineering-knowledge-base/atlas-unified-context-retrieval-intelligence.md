---
id: atlas-unified-context-retrieval-intelligence
type: engineering_knowledge
title: Atlas Unified Context Retrieval Intelligence
status: active
implementation_state: runtime_surfaces_18_blocks_plus_programming_enforcement_ready
blocker: none_for_programming_flows; ampliar enforcement para flows nao-programming em fatias futuras.
category: intelligence-runtime
priority: 100
summary: Documentacao mae da area de contexto, memoria, embeddings, RAG, Graph RAG, ranking, freshness, feedback, Python/data retrieval, cognitive memory, context compiler e token economy.
tags: [atlas-ai, aucri, context, retrieval, rag, embeddings, graph-rag, memory, world-model, token-economy]
capabilities: [unified_context_retrieval, semantic_embedding_retrieval, agentic_rag, graph_rag, context_reranking, context_freshness, cognitive_memory_fabric, context_compiler_runtime, token_economy_runtime]
decisions:
  - AUCRI e a area-mae de contexto e recuperacao do Atlas; ela organiza ACIE, APCR, RAG, embeddings, memoria, grafo e Python/data runtime sem substituir esses sistemas.
  - AUCRI deve virar a camada padrao de contexto de Atlas AI, Atlas Dev, Atlas Forge, Research, Finance, Marketing, Strategy e futuros flows.
  - Embeddings sao candidate retrieval, nao autoridade; decisoes finais exigem source authority, graph evidence, freshness, privacy e sufficiency gate.
  - Graph RAG global e external vector RAG continuam bloqueados ate AP, review humano, privacy/retention policy, golden-set benchmark e rollback.
  - O objetivo final e nota 10/10 em contexto global: contexto certo, pouco ruido, evidencias, relacoes, freshness e feedback de resultado.
maintenance:
  - Atualizar esta doc antes de criar novo runtime de contexto, embedding, graph, reranker ou memory retrieval.
  - Manter nomes canonicos internos sincronizados com atlas-canonical-glossary-and-naming.md.
  - Separar status real de estado-alvo; nao declarar completo sem certificacao e gates.
related_paths:
  - docs/engineering-knowledge-base/atlas-rag-graph-python-context-handoff.md
  - docs/engineering-knowledge-base/atlas-semantic-embedding-foundation.md
  - docs/engineering-knowledge-base/atlas-hybrid-retrieval-infrastructure.md
  - docs/engineering-knowledge-base/atlas-agentic-rag-framework.md
  - docs/engineering-knowledge-base/atlas-context-ranking-system.md
  - docs/engineering-knowledge-base/atlas-context-freshness-quality-gate.md
  - docs/engineering-knowledge-base/atlas-retrieval-feedback-loop.md
  - docs/engineering-knowledge-base/atlas-graph-retrieval-network.md
  - docs/engineering-knowledge-base/atlas-unified-reality-graph.md
  - docs/engineering-knowledge-base/atlas-python-data-retrieval-runtime.md
  - docs/engineering-knowledge-base/atlas-retrieval-evaluation-benchmark-arena.md
  - docs/engineering-knowledge-base/atlas-retrieval-cost-latency-governor.md
  - docs/engineering-knowledge-base/atlas-context-observability-plane.md
  - docs/engineering-knowledge-base/atlas-retrieval-privacy-trust-layer.md
  - docs/engineering-knowledge-base/atlas-knowledge-ingestion-fabric.md
  - docs/engineering-knowledge-base/atlas-cognitive-memory-fabric.md
  - docs/engineering-knowledge-base/atlas-context-compiler-runtime.md
  - docs/engineering-knowledge-base/atlas-token-economy-runtime.md
  - docs/engineering-knowledge-base/atlas-context-pareto-frontier-runtime.md
  - docs/engineering-knowledge-base/atlas-context-quality-certification-gate.md
  - docs/engineering-knowledge-base/atlas-aucri-continuous-optimization-protocol.md
  - docs/engineering-knowledge-base/atlas-context-intelligence-engine.md
  - docs/engineering-knowledge-base/atlas-persistent-context-runtime.md
  - docs/engineering-knowledge-base/domains/programming-agentic-rag-professional-spec.md
  - docs/engineering-knowledge-base/domains/programming-professional-rag-operating-standard.md
  - docs/engineering-knowledge-base/memory/retrieval-and-context.md
  - docs/engineering-knowledge-base/atlas-world-model.md
  - docs/engineering-knowledge-base/atlas-semantic-graph.md
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
  - app/Services/Ai/Context/LocalRagReadinessService.php
  - app/Services/Ai/Programming/ProgrammingRetrievalPlanner.php
doc_schema: atlas_canonical_module_doc.v1
macro_layer: true
product_name: Atlas Unified Context & Retrieval Intelligence
runtime_acronym: AUCRI
internal_product_name: Atlas Memory Graph
technical_runtime: AtlasUnifiedContextRetrievalIntelligenceService
graph_id: atlas-unified-context-retrieval-intelligence
graph_title: Atlas Unified Context Retrieval Intelligence
graph_world: atlas
graph_layer: system
graph_kind: index
graph_parent: atlas-autonomous-intelligence-operating-system
graph_status: planned
graph_source: repo
owner: atlas-ai
repo_paths:
  - docs/engineering-knowledge-base/atlas-unified-context-retrieval-intelligence.md
allowed_changes:
  - Adicionar sub-runtimes, fases, gates e contracts quando a implementacao evoluir.
  - Promover status planned para building/active somente com services, comandos, testes e certificacao.
forbidden_changes:
  - Criar store paralelo de memoria, embeddings, graph ou evidence sem ADR.
  - Declarar Graph RAG global ou external vector RAG como pronto sem gates formais.
  - Tratar embeddings como fonte de verdade.
  - Bypassar APCR, ACIE, privacy gate ou evidence ledger.
depends_on:
  - atlas-context-intelligence-engine
  - atlas-persistent-context-runtime
  - atlas-rag-graph-python-context-handoff
  - atlas-canonical-glossary-and-naming
flows_to:
  - atlas-ai-router-runtime
  - atlas-dev
  - atlas-forge
  - atlas-strategic-reality-engine
  - atlas-autonomous-reality-sandbox
unlocks:
  - atlas-unified-reality-graph
  - cross_domain_context_superiority
  - provider_independent_memory_graph
governs:
  - embeddings
  - retrieval
  - rag
  - graph_rag
  - memory_context
  - context_pack_ranking
evidence:
  - docs/engineering-knowledge-base/atlas-unified-context-retrieval-intelligence.md
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
  - "php artisan atlas:ai:local-rag-readiness --json"
requires_evidence: true
risk_level: high
line_limit: 520
next_actions:
  - Criar AUCRI-I1 plan com slices implementaveis e reuse-first.
  - Criar certification service AUCRI antes de qualquer claim de nota 10.
  - Integrar WorldModelGraphRanker ao retrieval/reranking real quando seguro.
---

# Atlas Unified Context Retrieval Intelligence

## Resumo

AUCRI e uma arquitetura planejada, ainda nao o runtime atual. Ela e a
documentacao mae da area que deve virar um dos maiores
multiplicadores do Atlas: contexto, memoria, embeddings, RAG, Agentic RAG,
Graph RAG, World/Reality Graph, reranking, freshness, feedback e Python/data
runtime.

O problema que ela resolve:

```text
Atlas sabe muita coisa, mas precisa recuperar a coisa certa, no momento certo,
com evidencia, relacao, frescor, privacidade e baixo ruido.
```

Estado atual estimado:

- fundacao: 7/10;
- produto global final: 4.5/10;
- alvo desta area: 10/10 auditavel.

## Papel no Atlas

AUCRI nao e provider, nao e LLM e nao e uma tela isolada. Ela e a camada de
recuperacao contextual que alimenta todos os flows. Se AUCRI melhora, todos os
runtimes melhoram: Atlas AI, Dev, Forge, Research, Finance, Marketing,
Strategy, ASRE, AARS, AAEL, AEMOR, AREG e futuros sistemas.

## Onde Se Encaixa

```text
Atlas AI / Surface
-> APCR: contexto persistente obrigatorio
-> ACIE: qualidade, compaction, handoff, sufficiency
-> AUCRI: retrieval unificado, embeddings, graph, ranking, feedback
-> Router / Domain / Flow
-> Dev / Forge / Research / Finance / Marketing / Strategy
```

AUCRI organiza os sistemas existentes em uma area unica, sem duplicar stores.

## Contratos

Contratos mae:

- `atlas.aucri.request.v1`
- `atlas.aucri.retrieval_plan.v1`
- `atlas.aucri.context_pack.v1`
- `atlas.aucri.embedding_candidate_set.v1`
- `atlas.aucri.graph_evidence_set.v1`
- `atlas.aucri.rerank_result.v1`
- `atlas.aucri.freshness_report.v1`
- `atlas.aucri.feedback_event.v1`
- `atlas.aucri.certification.v1`

Campos obrigatorios:

- `schema_version`;
- `scope_type`;
- `scope_id`;
- `domain`;
- `flow_id`;
- `query_hash`;
- `source_refs`;
- `evidence_refs`;
- `privacy_class`;
- `authority_level`;
- `freshness`;
- `context_pack_hash`;
- `claim_policy`.

## Fluxo

Fluxo final:

```text
intent/domain/flow/risk
-> context objective decomposition
-> source plan
-> lexical retrieval
-> embedding candidate retrieval
-> memory retrieval
-> graph traversal
-> world/reality model retrieval
-> authority/privacy/freshness gates
-> reranking
-> context pack
-> sufficiency gate
-> flow execution
-> outcome feedback
-> memory/graph learning proposal
```

## Regras para IA

- Nao criar novo store sem provar que nenhum store existente serve.
- Nao chamar embedding de verdade; embedding e candidato.
- Nao declarar Graph RAG global pronto enquanto `graph_retrieval` estiver
  `future_governed`.
- Nao enviar dado sensivel para embedding/provider externo sem privacy review.
- Nao promover memoria automaticamente sem AEMOR/Memory Promotion.
- Nao entregar contexto bruto quando refs, hashes e summaries bastam.
- Nao passar prompt grande para compensar retrieval fraco.
- Sempre separar fato, inferencia, estimativa e memoria.

## Escopo de Implementacao

AUCRI deve ser implementada em 18 grandes blocos: retrieval, graph, quality,
enterprise maturity, cognitive RAM, compiler, token economy e Pareto frontier.

### Indice de Implementacao Obrigatorio

Toda IA deve navegar a area por este indice, nesta ordem. A doc mae decide
ordem e fronteiras; cada bloco grande pode ganhar doc filha quando entrar em
implementacao.

| Ordem | Bloco | Leia antes | Crie/estenda | Evidencia minima |
| --- | --- | --- | --- | --- |
| 0 | AUCRI core | este doc + handoff RAG | audit + runtime enforcement antes de Dev/Forge | `atlas:aucri:optimize-audit` + `AtlasAucriRuntimeEnforcementService` |
| 1 | ASEF | memory/retrieval + local rag readiness | manifest de chunks, hashes, privacy/delete keys; Python futuro para embeddings | command `atlas:context:semantic-foundation` + no privacy leak |
| 2 | AHRI | ACIE/APCR + ContextRetrievalRouter | hybrid retrieval service, source adapters, context refs | command `atlas:context:hybrid-retrieval` + retrieval report |
| 3 | AARF | programming agentic RAG spec + AHRI | planner cross-domain, gap critic, source plans | command `atlas:context:agentic-rag` + blocked/degraded/pass |
| 4 | ACRS | reranker atual + WorldModelGraphRanker | ranking unico com authority/freshness/outcome | command `atlas:context:rank` + ranking auditavel |
| 5 | ACFQ | TEOS freshness + APCR sufficiency | freshness/quality gate cross-domain | command `atlas:context:freshness-quality` + block stale/contradiction |
| 6 | ARFL | AEMOR + Compounding RAG feedback | feedback de uso, missed refs, noise refs | command `atlas:context:retrieval-feedback` + candidate review-only |
| 7 | AGRN | semantic graph + world model | bounded Codebase World Model retrieval | command `atlas:context:graph-retrieval` + traversal receipt |
| 8 | AURG | world model + ASRE/AARS | reality graph snapshot over ASRE entities | command `atlas:context:reality-graph` + source/freshness/confidence |
| 9 | APDR | python runtime boundary | governed Python data runtime wrapper | command `atlas:context:python-data` + receipt/fragment |
| 10 | AREBA | ARFL + ACRS | internal evaluation arena, golden sets, regressions | command `atlas:context:evaluate-retrieval` + recall/groundedness/ROI |
| 11 | ARCLG | AREBA + AREG | cost/latency governor, cache, degradation | command `atlas:context:retrieval-budget` + budget receipt |
| 12 | ACOP | Control Plane + AUCRI | observability read model por source/flow/query | command `atlas:context:observability` sem texto cru |
| 13 | ARPTL | privacy/security docs | privacy, trust, redaction, retention | command `atlas:context:privacy-trust` + provider-safe gate |
| 14 | AKIF | rich input + ingestion jobs | ingestion fabric para docs/repos/videos/dados | command `atlas:context:knowledge-ingestion` + source packet |
| 15 | ACMF | AUCRI + AREG | cognitive RAM fabric, hot context, delta, spillover | command `atlas:context:cognitive-memory` + 3GB reserve |
| 16 | ACCR | AUCRI + ACMF | context compiler provider-aware | command `atlas:context:compile` + loss check |
| 17 | ATER | ACCR + ARCLG | token economy, local pre-reasoning, reuse | command `atlas:context:token-economy` + quality gate |
| 18 | ACPFR | ATER + AREBA | Pareto frontier quality/token/cost/latency | melhor tradeoff sem regressao |

Regra de navegacao:

1. Comece pelo bloco 0.
2. Nao pule para AURG/AGRN antes de ASEF/AHRI/AARF/ACRS/ACFQ.
3. Se um bloco nao possui service, command, tests e certification, ele fica
   `planned` ou `building`, nunca `active`.
4. Cada bloco deve declarar nome canonico/produto, acronimo tecnico, nome
   interno/superficie e runtime tecnico.
5. Cada implementacao deve atualizar esta matriz quando mudar status real.

### Artefatos Minimos Por Bloco

Cada bloco filho precisa entregar:

- doc filha canonica quando passar de design para implementacao;
- service principal;
- schemas versionados;
- migrations/models quando houver persistencia;
- command `readiness` ou `certify`;
- tests unit/feature cobrindo o contrato;
- Control Plane read model quando houver estado operacional;
- claim policy separando local readiness, benchmark e superioridade externa.

### 1. ASEF: Atlas Semantic Embedding Foundation

- Nome canonico/produto: Atlas Semantic Embedding Foundation
- Acronimo tecnico: ASEF
- Nome interno/superficie: Atlas Vector Seed
- Runtime tecnico: `AtlasSemanticEmbeddingFoundationService`
- Papel: manifest de chunks, versionamento, delete keys, privacy, local-first
  e provider-safe policy; embeddings reais ficam no runtime Python governado.
- Estado atual: service/comando/testes de manifest e readiness existem; sem
  vector indexing final.

### 2. AHRI: Atlas Hybrid Retrieval Infrastructure

- Nome canonico/produto: Atlas Hybrid Retrieval Infrastructure
- Acronimo tecnico: AHRI
- Nome interno/superficie: Atlas Retrieval Core
- Runtime tecnico: `AtlasHybridRetrievalInfrastructureService`
- Papel: combinar lexical, vector, memory, docs, evidence, code refs e ASEF
  candidates.
- Estado atual: service/comando/testes emitem retrieval report read-only
  cross-domain; falta adapter profundo e ACRS.

### 3. AARF: Atlas Agentic RAG Framework

- Nome canonico/produto: Atlas Agentic RAG Framework
- Acronimo tecnico: AARF
- Nome interno/superficie: Atlas Retrieval Planner
- Runtime tecnico: `AtlasAgenticRagFrameworkService`
- Papel: decompor objetivo, iterar busca, criticar lacunas e bloquear contexto
  insuficiente.
- Estado atual: service/comando/testes read-only sobre AHRI existem; passa,
  degrada ou bloqueia por required sources e risk fail-closed. Ainda falta
  virar mandatory gate nos flows depois de ACRS/ACFQ.

### 4. AGRN: Atlas Graph Retrieval Network

- Nome canonico/produto: Atlas Graph Retrieval Network
- Acronimo tecnico: AGRN
- Nome interno/superficie: Atlas Graph RAG
- Runtime tecnico: `AtlasGraphRetrievalNetworkService`
- Papel: Graph RAG global governado, usando semantic graph, world model,
  codebase graph e reality graph.
- Estado atual: service/comando/testes read-only sobre Codebase World Model
  existem; emite graph query/evidence/traversal receipt e bloqueia risco alto
  sem grafo. Graph global/external continua future-governed.

### 5. AURG: Atlas Unified Reality Graph

- Nome canonico/produto: Atlas Unified Reality Graph
- Acronimo tecnico: AURG
- Nome interno/superficie: Atlas Reality Map
- Runtime tecnico: `AtlasUnifiedRealityGraphService`
- Papel: grafo vivo de empresas, projetos, decisoes, pessoas, docs, codigo,
  metas, riscos, resultados e oportunidades.
- Estado atual: service/comando/testes read-only projetam entidades/edges ASRE
  existentes em snapshot AUCRI com sources, freshness, confidence e fail-closed
  por risco. Grafo unificado completo ainda evolui por ingestion/trust.

### 6. ACRS: Atlas Context Ranking System

- Nome canonico/produto: Atlas Context Ranking System
- Acronimo tecnico: ACRS
- Nome interno/superficie: Atlas Context Ranker
- Runtime tecnico: `AtlasContextRankingSystemService`
- Papel: reranking por relevancia, autoridade, freshness, graph distance,
  outcome history, risk, domain e user intent.
- Estado atual: service/comando/testes read-only existem; consome AARF/AHRI,
  reaproveita `ProgrammingProfessionalReranker`, consulta `WorldModelGraphRanker`
  quando disponivel e emite score components/reasons/excluded refs.

### 7. ACFQ: Atlas Context Freshness & Quality Gate

- Nome canonico/produto: Atlas Context Freshness & Quality Gate
- Acronimo tecnico: ACFQ
- Nome interno/superficie: Atlas Freshness Gate
- Runtime tecnico: `AtlasContextFreshnessQualityGateService`
- Papel: bloquear contexto velho, contraditorio, sem autoridade ou com fonte
  obrigatoria ausente.
- Estado atual: service/comando/testes read-only existem; consome ACRS, avalia
  freshness, autoridade, provider safety, cobertura obrigatoria e contradicoes,
  e falha fechado em risco alto.

### 8. ARFL: Atlas Retrieval Feedback Loop

- Nome canonico/produto: Atlas Retrieval Feedback Loop
- Acronimo tecnico: ARFL
- Nome interno/superficie: Atlas Context Learning
- Runtime tecnico: `AtlasRetrievalFeedbackLoopService`
- Papel: aprender quais refs ajudaram, quais foram ruido, quais faltaram e qual
  contexto reduziu erro.
- Estado atual: service/comando/testes AUCRI existem sobre ACFQ e
  `AtlasRagFeedbackService`; calcula context ROI, misses/noise e gera candidato
  review-only sem auto-promocao.

### 9. APDR: Atlas Python Data Retrieval Runtime

- Nome canonico/produto: Atlas Python Data Retrieval Runtime
- Acronimo tecnico: APDR
- Nome interno/superficie: Atlas Data Intelligence Runtime
- Runtime tecnico: `AtlasPythonDataRetrievalRuntimeService`
- Papel: runtime Python governado para embeddings locais, graph analytics,
  clustering, reranking experimental, evals e dados.
- Estado atual: wrapper AUCRI sobre `ProgrammingPythonRuntime*` existe; modo
  padrao manifest-only e execucao exige approval, decision receipt e boundary.

### 10-18. Enterprise Maturity, Cognitive Memory, Compiler, Token e Pareto

- AREBA: arena interna de golden sets e regression gate sem benchmark externo.
- ARCLG: budget, latencia, cache decision e degraded mode com receipt.
- ACOP: read model de contexto com traces, source health, blockers e redacao.
- ARPTL/AKIF: trust e source packets com lineage/privacy receipts.
- ACMF: RAM como working memory cognitiva com budget, delta e spillover.
- ACCR: compila context pack por provider com budget e loss check.
- ATER: reduz tokens com quality gate, reuse e provider selection advisory.
- ACPFR: escolhe a fronteira otima entre qualidade, token, custo e latencia.

## Dependencias

Dependencias obrigatorias:

- APCR para contexto persistente;
- ACIE para qualidade, compaction, handoff e sufficiency;
- Memory/Open Brain para memoria provider-safe;
- Evidence Ledger para hashes e replay;
- AEMOR para outcome/learning;
- AREG para budget cognitivo;
- AURG para realidade relacional quando existir;
- Python runtime boundary para execucao local segura.

## Evidencias

Evidencias atuais de fundacao:

- `LocalRagReadinessService` retorna readiness local.
- `ContextRetrievalRouter` seleciona fontes e marca Graph como future-governed.
- `ProgrammingRetrievalPlanner` e `ProgrammingRetrievalExecutor` implementam
  Agentic RAG de programacao.
- `ProgrammingGraphRagRuntime` implementa Graph RAG bounded/local.
- `ProgrammingSemanticCodeGraphService` implementa grafo de codigo com fallback.
- `WorldModelGraphRanker` ranqueia Codebase World Model por texto + grafo.
- `TimeAwareWorldModelService` projeta edges com validade temporal.
- `ProgrammingPythonRuntimeContract/Executor/GraphProjector` protegem Python.

Comandos de base:

```bash
php artisan atlas:engineering:knowledge docs-health --json
php artisan atlas:ai:local-rag-readiness --json
php artisan atlas:programming:retrieval-benchmark --json
php artisan atlas:context-intelligence:certify --json --strict
php artisan atlas:persistent-context:certify --json --strict
```

## Riscos

Riscos principais:

- AUCRI virar mais uma camada paralela em vez de organizar as existentes.
- Graph RAG global ser liberado antes de privacy/retention/delete cascade.
- Embeddings externos vazarem dados sensiveis.
- Reranking otimizar parecido, nao certo.
- Context pack ficar grande demais e reduzir qualidade.
- Feedback loop aprender falso positivo.
- Python runtime virar execucao arbitraria.

Mitigacoes:

- reuse-first;
- evidence refs;
- provider-safe policy;
- golden-set benchmark;
- human review para external vector/Graph promotion;
- decision receipt para runtime Python;
- AEMOR/AREG para aprendizado e budget.

## Exemplos

Pedido: "analise oportunidade de mercado para produto X".

AUCRI final deve recuperar:

- memorias e decisoes anteriores;
- docs internos relevantes;
- fontes externas permitidas;
- entidades do Reality Graph;
- oportunidades e riscos;
- freshness das fontes;
- evidencias e incertezas;
- contexto compacto para ASRE/AARS/Research.

Pedido: "corrija bug no Forge".

AUCRI final deve recuperar:

- arquivos e simbolos tocados;
- testes relacionados;
- docs canonicos;
- receipts e falhas anteriores;
- graph edges de impacto;
- context pack provider-safe;
- sufficiency gate.

## Proximas Acoes

Roadmap de longo prazo:

1. AUCRI-I1: certificacao e inventory real dos 18 blocos.
2. AUCRI-I2: ASEF + AHRI com candidate sets, chunking e retrieval provider-safe.
3. AUCRI-I3: AARF cross-domain e mandatory retrieval gate por risco.
4. AUCRI-I4: ACRS + ARFL com feedback real de uso/outcome.
5. AUCRI-I5: AGRN bounded global com privacy, AP e rollback.
6. AUCRI-I6: AURG conectado a ASRE/AARS/AEMOR.
7. AUCRI-I7: APDR para graph analytics/evals/reranking local seguro.
8. AUCRI-I8: AREBA + ARCLG para eval, benchmark interno, custo e latencia.
9. AUCRI-I9: ACOP + ARPTL + AKIF para observabilidade, trust e ingestion.
10. AUCRI-I10: ACMF com working memory RAM, delta, heat score e spillover.
11. AUCRI-I11: ACCR para context compiler provider-aware com loss checks.
12. AUCRI-I12: ATER para token budget, reuse, output compression e quality check.
13. AUCRI-I13: ACPFR para Pareto frontier e safe exploration em shadow.
14. AUCRI-I14: certification final 10/10 com golden sets, replay e Control Plane.

Definition of Done 10/10:

- Atlas Dev e Atlas Forge usam AUCRI por padrao antes de provider/patch/test;
- os 18 blocos possuem doc filha canonica ou justificativa de bloqueio;
- cada context pack tem hash, evidence refs, authority, freshness e privacy;
- ACMF reduz tokens sem baixar RAM disponivel abaixo de 3GB;
- ACCR compila prompt final menor, auditavel e provider-aware;
- ATER reduz token sem remover must-keep, evidence ou sufficiency;
- ACPFR otimiza tradeoff sem regressao de qualidade high-risk;
- embeddings sao fortes e governados;
- Graph RAG global e ativo somente apos AP/gates;
- Reality Graph alimenta estrategia, pesquisa, Forge e Dev;
- feedback de outcome melhora ranking;
- contexto insuficiente bloqueia execucao;
- certificacao AUCRI passa sem claim falso.
