---
id: atlas-ai-local-performance-memory-strategy
type: engineering_knowledge
title: Atlas AI Local Performance Memory Strategy
status: active
category: performance-architecture
priority: 96
summary: Contrato canonico para usar 48GB de RAM e hardware local como substrato de IA: hot context, RAG local, reranking, modelos locais, cache, destilacao e precomputacao sem criar cerebro paralelo.
tags:
  - atlas-ai
  - local-ai
  - performance
  - ram
  - rag
  - context
capabilities:
  - local_ai_performance_strategy
  - hot_context_pack_daemon
  - vector_graph_rag
  - local_reranking
  - local_model_runtime
  - evidence_distillation
decisions:
  - Os 48GB de RAM nao viram contexto infinito do provider externo.
  - A RAM local deve ser usada para selecionar, comprimir, ranquear, validar e precomputar contexto antes da chamada de IA.
  - Providers externos recebem Context Packs compactos, citados e auditaveis, nao dumps crus de memoria.
  - Modelos locais, embeddings e caches sao runtimes governados pelo Kernel, nao novo cerebro.
  - Qualidade vence volume: contexto menor, mais correto e melhor ranqueado vale mais que prompt gigante.
maintenance:
  - Manter abaixo de 260 linhas.
  - Atualizar antes de implementar Graph RAG, Vector RAG, local models, reranker, cache persistente, daemon de contexto ou precomputacao noturna.
  - Rodar docs-health, sync, index-code e architecture-validate depois de alterar.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-runtime-language-boundaries.md
  - docs/engineering-knowledge-base/atlas-ai-memory-context-core-open-brain.md
  - docs/engineering-knowledge-base/open-brain-context-injection.md
  - docs/engineering-knowledge-base/code-intelligence.md
  - docs/engineering-knowledge-base/atlas-ai-telemetry-evidence-performance.md
  - docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md
  - docs/engineering-knowledge-base/atlas-native-mac-agent.md
  - docs/ap/AP-683-local-rag-graph-promotion-review.md
---

# Atlas AI Local Performance Memory Strategy

Este documento define como o Atlas deve aproveitar um Mac local com 48GB de RAM
para entregar mais qualidade, velocidade e confiabilidade em IA.

## Regra Mae

RAM local nao e memoria magica do provider. RAM local e vantagem quando o Atlas
mantem conhecimento quente, busca melhor contexto, reranqueia evidencias,
precalcula sinais e chama o modelo com menos ruído.

```text
Docs / Codigo / Ledger / Vault / Memory
        -> indexadores locais
        -> hot retrieval + rerank + graph expansion
        -> Context Pack compacto
        -> Provider ou modelo local
        -> Evidence + Learning
```

O ganho vem de **contexto certo**, nao de **contexto enorme**.

## O Que Usar Em RAM

| Tecnica | Uso correto | Dono |
|---|---|---|
| Hot Context Pack Cache | manter contextos recentes por workspace/domain/flow | Laravel + Python |
| Vector RAG local | recuperar semantica de docs, ledger, codigo e vault | Python |
| Graph RAG local | navegar relacoes entre docs, symbols, decisions e runs | Python |
| Local reranker | reordenar candidatos por relevancia real antes do prompt | Python |
| Embedding service quente | evitar cold start em buscas frequentes | Python |
| Code Intelligence cache | manter simbolos, rotas e testes prontos para dev/forge | Laravel + Python |
| Evidence distillation | resumir runs antigos em aprendizados pequenos e citados | Laravel + Python |
| Prompt/provider cache | reutilizar prefixos e contextos quando provider suportar | Laravel |
| Local model triage | classificar intent, risco, privacy, query e relevancia sem custo externo | Python/Swift |
| KV cache local | acelerar sessoes longas com modelos locais quantizados | Python/MLX/llama.cpp |
| Precompute noturno | reconstruir indices, summaries, scores e gaps de madrugada | Laravel scheduler + Python |

## O Que Nao Fazer

1. Nao despejar vault, docs ou ledger bruto no prompt.
2. Nao tratar RAM local como aumento de context window do Claude/Codex/Gemini.
3. Nao rodar modelo local fora de Policy/Profile, Receipt, Evidence e Gates.
4. Nao criar vector store externo com dados privados por padrao.
5. Nao duplicar Memory Core, Code Intelligence ou Evidence Ledger em Python.
6. Nao manter cache sem versionamento, freshness e invalidacao.
7. Nao otimizar latencia sacrificando citacao, rastreabilidade ou qualidade.

## Piramide De Memoria

| Nivel | Conteudo | Retencao | Objetivo |
|---|---|---|---|
| L0 Prompt | Context Pack final enviado ao modelo | por chamada | maxima precisao |
| L1 Hot RAM | docs/symbols/runs recentes e indices em memoria | horas/dia | baixa latencia |
| L2 Local Index | embeddings, graph, summaries e caches em disco | persistente | recall confiavel |
| L3 Canonical Store | Postgres, docs, ledger, vault gerenciado | permanente | fonte de verdade |
| L4 Archive | source material legado e snapshots | historico | auditoria/pesquisa |

O Atlas deve promover L3 -> L2 -> L1 -> L0 com filtros. Nunca pular direto de
L3 bruto para L0.

## Pipeline De Contexto De Alta Qualidade

1. Detectar domain, flow, workspace, risco e objetivo.
2. Buscar candidatos em docs, Code Intelligence, Memory Core, Ledger e Vault.
3. Expandir por grafo: decisoes relacionadas, symbols, testes, runs e docs donos.
4. Reranquear localmente por relevancia, frescor, autoridade e diversidade.
5. Remover duplicatas, conflitos, conteudo legado e material nao autoritativo.
6. Comprimir em Context Pack com fontes, hashes e budget de tokens.
7. Rodar gate de privacidade/provider-safety.
8. Chamar provider/modelo local.
9. Gravar evidence de quais fontes entraram e quais foram descartadas.

## Tecnicas Prioritarias

### P0 - Maior Ganho Agora

1. Hot cache de Context Packs por `workspace + domain + flow`.
2. Embeddings locais para docs canonicos, symbols e ledger summaries.
3. Reranker local para reduzir hallucination por contexto errado.
4. Evidence distillation: runs antigos viram aprendizados pequenos.
5. Freshness guard: cache invalida quando docs/codigo/migrations mudam.

### P1 - Ganho Estrutural

1. Graph RAG conectando doc -> code symbol -> route -> test -> ledger event.
2. Local model triage para intent/risk/context relevance.
3. Precompute noturno de gaps, summaries, provider scores e AP candidates.
4. Prompt cache/provider cache para prefixos estaveis.
5. Memory quality score por Context Pack antes de chamar provider caro.

### P2 - Avancado

1. KV cache para modelos locais em sessoes longas.
2. Modelos multimodais locais para triagem privada de imagem/audio.
3. Speculative routing: modelo barato prepara, modelo forte valida.
4. Benchmark local continuo para decidir quando modelo local supera cloud.

Modelos locais podem reescrever query, sumarizar, reranquear, detectar anomalia
e classificar privacidade. Nao podem decidir provider final, policy, autonomy,
gate result ou apply.

## Como Usar Os 48GB

Reserva recomendada inicial:

| Uso | RAM alvo | Observacao |
|---|---:|---|
| Sistema/macOS/apps | 8-12GB | nao competir com o operador |
| Laravel/Postgres/queues | 3-6GB | Kernel e storage local |
| Python RAG/embeddings/rerank | 8-16GB | principal ganho de qualidade |
| Modelo local quantizado opcional | 8-20GB | apenas quando policy permitir |
| Cache/indices/hot packs | 4-8GB | ajustar por workspace |

O Atlas deve medir memoria real, nao assumir. Se houver pressao de memoria,
degrade assim: desligar modelo local -> reduzir hot cache -> reduzir rerank
batch -> usar provider externo com Context Pack compacto.

## Gates De Qualidade

Todo Context Pack gerado por RAM/local AI deve passar:

1. relevance score minimo;
2. diversity de fontes;
3. freshness e invalidacao;
4. authority check contra docs canonicos;
5. privacy/provider-safety;
6. token budget;
7. contradiction scan;
8. replay metadata: fontes, hashes, query e rerank version.

Sem esses gates, a RAM vira acelerador de erro.

## Fronteira De Linguagens

| Componente | Linguagem |
|---|---|
| Policy, Receipt, Evidence, scheduler e gates | Laravel |
| Embeddings, RAG, graph, rerank, ML, local model | Python |
| Watchers de rede/eventos de alto volume | Go |
| Contexto nativo macOS, Core ML leve e sensores opt-in | Swift |

Python pode pensar pesado, mas Laravel decide. Swift pode sentir o Mac, mas
nao escolhe modelo. Go pode ingerir volume, mas nao aprende sozinho.

## Retrieval Readiness Atual

`ContextRetrievalRouter` ja declara `vector_retrieval`, `memory_signals`,
`code_intelligence`, `evidence_replay` e `graph_retrieval` no plano de contexto.
Graph RAG aparece como `future_governed`, `available=false`,
`runtime=python_ai_data_candidate` e `provider_bypass_allowed=false`. Isso
mantem perguntas arquiteturais conscientes da lacuna sem autorizar uma IA a
criar um segundo cerebro RAG fora do Kernel.

Use `php artisan atlas:ai:local-rag-readiness --json` para auditar substrato e
`php artisan atlas:ai:local-rag-benchmark --json` para rodar o corpus
controlado `local_rag_controlled_router_quality_v1`. Esse corpus cobre
programacao, arquitetura, desenvolvimento pessoal e financas; mede score
minimo, latencia p95 local e boundary de privacidade sintetico; e prova que o
router seleciona fontes, bloqueia bypass e mantem Graph RAG como candidato
futuro.

Quando o corpus passa, estes pre-requisitos ficam satisfeitos:
`retrieval_quality_corpus`, `latency_p95_measurement` e
`privacy_redaction_verification`. O contrato de Evidence Ledger usa a familia
`LOCAL_RAG_*` (`PLAN_CREATED`, `QUALITY_CORPUS_EVALUATED`,
`GRAPH_PROMOTION_BLOCKED`) via `AtlasEvidenceLedger::recordLocalRagEvent` e
nunca persiste query/contexto/documentos brutos. O comando tambem publica
`evidence_ledger.status=persisted|unavailable`; se a tabela do Ledger nao
gravar os 3 eventos esperados, `promotion_evidence_satisfied=false` e a
execucao nao pode ser usada como evidencia de promocao. O benchmark tambem emite
`review_packet` com decisao humana requerida, evidencias minimas, rollback e
proibicoes ate review (`enable_python_graph_rag_runtime`,
`auto_apply_policy_patch`, `surface_direct_graph_rag_call`). A promocao de Graph
RAG/Python continua bloqueada ate existir `human_review_or_curator_proposal`; o
flow `self_improvement.docs_drift_review` pode abrir essa proposta em modo
`proposal_only`, mas nunca autoaplica policy patch. AP-683 e o review gate
canonico para decidir se Graph RAG/Python merece AP futuro.

## Implementation Roadmap

| Fase | Status | Entrega |
|---|---|---|
| LP-0 | active | spec canonica e fronteira de linguagem |
| LP-1 | active | Readiness + corpus controlado de qualidade do router |
| LP-2 | future | Embeddings locais + reranker governado |
| LP-3 | future | Graph RAG docs/codigo/ledger |
| LP-4 | future | Evidence distillation e memory quality scoring |
| LP-5 | future | Local model triage + KV cache opcional |
| LP-6 | future | Precompute noturno e degradation por memoria |

## Definition Of Done

Antes de implementar qualquer tecnica de RAM/local AI:

1. criar AP curto quando houver codigo novo;
2. declarar capability, owner e linguagem dona;
3. passar por DecisionReceipt;
4. registrar fontes, hashes, versao do indice e reranker;
5. ter invalidacao por freshness;
6. ter privacy gate;
7. ter metricas de relevancia, latencia, memoria e custo;
8. provar ganho contra baseline sem RAG/cache;
9. ter fallback quando servico local estiver indisponivel;
10. atualizar docs e Code Intelligence.
