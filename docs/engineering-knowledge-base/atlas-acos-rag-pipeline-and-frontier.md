# RAG no ACOS — Pipeline Unificado + Auge Absoluto (doc canônico)

> Consolida TUDO sobre RAG (retrieval-augmented generation) no ACOS: as 6 áreas que o compõem vistas como UM pipeline, o estado real medido, e o caminho até o teto honesto deste stack local-first. Derivado de 8 leituras de fronteira (Max A/B/C/D/E/G) + consolidação + análise SOTA 2024-2026 (12/07/2026). Read-only: nada no repo foi alterado por este doc.

## (0) O que é "RAG no ACOS"

RAG no ACOS **não é uma área** — é a espinha que atravessa 6 das 18 áreas: **3 Embeddings & Índices · 4 Busca & Ranking · 5 Busca Agentic · 6 Grafo de Conhecimento · 7 Composição de Contexto · 13 Avaliação & Eficiência**. É o mecanismo pelo qual qualquer motor (Opus 4.8 ou outro) recebe o contexto certo antes de responder — o coração do M na equação N×M.

## (1) O pipeline de ponta a ponta

```
gatilho (hook/query)
  → ingestão [AKIF, área 2]
  → chunking [ASEF, área 3]
  → embedding [ASEF, área 3]
  → indexação pgvector [ASEF, área 3]
  → recuperação híbrida (léxico+vetorial+registry) [AHRI, área 4]
  → travessia de grafo [AGRN/AURG, área 6]
  → ranking (relevância/autoridade/frescor) [ACRS, área 4]
  → relevance floor [área 4]
  → composição/budget de tokens [ACCR/ATER, área 7]
  → entrega do pack [AOBG, área 14]
  → feedback (útil/ruído/faltou) [ARFL, área 4/13]
  → avaliação (golden, ROI, latência) [AREBA/ACOP, área 13]
```

Cada estágio tem classe/arquivo real e slice(s) Max que o atacam — o mapa completo está no fragmento de consolidação (resumo abaixo).

## (2) Estado vivo medido (12/07) — os 3 pontos onde o pipeline SANGRA

1. **Latência do embed é 99,9% overhead, não computação.** O embedding real custa ~30ms; mas subprocesso Python por chamada + mesma query embedada 2-3×/recall + zero cache → recall 5,9-8,5s, pack 13-18s, hooks duplicados → ~65s/turno. Alvo Max: pack p95 ≤ 2s. (MAXA-01/02, MAXB-01, MAXE-02/03, ASI-04/16.)
2. **Cobertura de 247 vetores num universo de ~292 mil.** 290.211 símbolos de código + ~950 itens de KB + ~1.011 docs SEM coluna `embedding`. A busca semântica de código não existe — nenhum ranking supera corpus ausente. (MAXA-03/06 re-embed incremental por source_hash.)
3. **O pipeline não se enxerga (a sangria que esconde as outras).** Refs `memory:`/`graph:` não renderizados no markdown → ARFL measured=0/20 → sinal 99,9% positivo/0 negativo → golden mede recall@5=0 (alvos ausentes) → ARLCG governa 810ms de ficção vs 22,9s reais. **Nenhum ganho é auditável.** (MAXE-01 refs citáveis, MAXG-01 latência real, MAXG-04/MAXB-02 golden v2 real.)

## (3) O que o Max A-G já resolve vs a lacuna que sobra

- **Max entrega o M do RAG**: cobertura de embeddings, daemon+cache (latência), RRF parametric-free, reranker local, grafo com PageRank/comunidades, decomposição de query, golden set v2 congelado por juiz externo, budget adaptativo por valor, latência instrumentada.
- **Fica de fora, mesmo com Max completo**: (a) o corpus vivo/curado no tempo (áreas 1/2/16 — coberto por MAXH/MAXI); (b) a **verificação de fidelidade da resposta** — o Max mede a qualidade da *recuperação*, ninguém mede se a resposta *usou* o contexto recuperado; (c) o motor local generativo governado (ainda não reside). O auge absoluto (seção 4) fecha (b).

## (4) O AUGE ABSOLUTO — o ALÉM do Max (slices RAGX-01..11)

Avaliação SOTA 2024-2026 para ESTE stack (local-first, pgvector, fastembed/ONNX, PHP; motor frontier como gerador/advisory sob author≠judge). **DESCARTADOS com motivo** (não viram slice): ColBERT/late-interaction 1ª-etapa e MUVERA (pgvector não é multi-vetor nativo; ganho ~0 com corpus pequeno), proposition-indexing (custo > ganho nesta escala), RAG-Fusion (já é MAXC-07+MAXB-03 composto), query-routing (já é MAXC-01/03), fine-tune/distillation do embedder (faminto de labels que não existem).

### Os 3 saltos de maior impacto

1. **RAGX-08 + RAGX-09 — verificação de fidelidade (o loop que o Max deixa ABERTO).** Citation-grounding determinístico local (cada afirmação da resposta rastreada a um ref entregue) + faithfulness LLM amostral advisory sob author≠judge. MAXG mede recuperação; isto mede se a resposta *usou* o contexto. É o **único eixo cujo valor cresce com o provider** (o M), não satura como recall@k.
2. **RAGX-01 — late chunking com jina-v3 (provider-free).** Chunk contextual pelo truque de encoder puro (Jina 2024) — sem chamar motor, casa com o modelo-alvo do MAXA-04, escala para o corpus de código. Melhor gain/custo dos itens de qualidade.
3. **RAGX-03 — corretivo CRAG-lite determinístico.** Grade de relevância por-item + knowledge-strip refinement reusando os scores reais + o hop-2 já budgetado do MAXC. Mata o "pack irrelevante com cara de suficiente" sem chamar motor.

### Tabela de vereditos (resumo — detalhe no fragmento)

| Técnica SOTA | Veredito | Compõe com |
|---|---|---|
| Contextual/late chunking (jina-v3, provider-free) | **APLICAR** RAGX-01 | MAXA-04 |
| CRAG-lite corretivo determinístico | **APLICAR** RAGX-03 | MAXC hop-2 |
| Citation-grounding determinístico | **APLICAR** RAGX-08 | MAXE-01, MAXG |
| Faithfulness LLM advisory (author≠judge) | **ADAPTAR** RAGX-09 | golden v2 |
| Semantic caching (similaridade, não hash) | **APLICAR** RAGX-02 | cache exato Max |
| Adaptive retrieval depth | **APLICAR** RAGX-06 | MAXC |
| GraphRAG global search (comunidades) | **APLICAR** RAGX-07 | MAXD comunidades |
| Reranking em cascata + LLM-rerank advisory | **ADAPTAR** RAGX-05 | MAXB reranker |
| HyDE condicional (author≠judge) | **ADAPTAR** RAGX-04 | MAXC |
| RAPTOR/sumário hierárquico | **ADAPTAR** RAGX-10 | MAXD/MAXF |
| Learned sparse (SPLADE/BM42) local | **ADAPTAR** RAGX-11 | MAXB |
| ColBERT/MUVERA · proposition · RAG-Fusion · routing · fine-tune | **DESCARTAR** | (motivo na seção 4) |

Todo slice que mede qualidade versiona série/medidor **v2 próprio** e usa o golden v2 (MAXG-04/MAXB-02) congelado por juiz externo como régua, com peek `record_usage=false`, denominador mínimo em cada aceite, e critério de morte declarado nos condicionais (RAGX-04 HyDE, RAGX-05 tier-LLM, RAGX-11 SPLADE). Motor frontier sempre gerador/advisory sob author≠judge, `default-OFF`, local preferido, nunca em sensitive/secret nem gravando na espinha.

## (5) A definição de "RAG no auge absoluto" para ESTE stack

**O auge NÃO é recuperar mais.** Com 77-300 entries o recall@k satura e todo ranker rende marginália ranking-bound — perseguir número de recuperação é Goodhart. O auge é um **loop soberano e medido** onde cada item entregue é *provadamente usado com fidelidade* ou *provadamente ausente com falta nomeada*. Critério objetivo, por comando:

- recall@5 ≥ **0,90** no golden v2 VIVO (não sintético; congelado por juiz externo)
- citation-grounding ≥ **0,90** (a resposta rastreia ao contexto entregue)
- pack p95 ≤ **2s** (latência real instrumentada, não fórmula)
- **zero** motor julgando a si mesmo (author≠judge em toda etapa generativa)
- ARFL measured_share ≥ **0,90** (o pipeline se enxerga)

**O teto honesto (anti-hype):** o ganho composto real do RAG mora na **verificação da resposta** (o M que cresce com o provider), não no número de recuperação (o N que satura). Um RAG no auge deste stack é aquele em que você confia na saída sem reler — porque cada afirmação é fundamentada, medida, e reversível — não aquele que recupera mais documentos.

## Referências
- Slices e detalhe: fragmentos `rag-consolidado.md` + `rag-auge-absoluto.md` (scratchpad da sessão de 12/07)
- Plano-mãe: `atlas-acos-max-frontier-plan-v1.md` (MAXA-G = cadeia de contexto; §ix = 18 áreas)
- Mapa: `atlas-acos-areas-map.md` (áreas 3,4,5,6,7,13 = o RAG)
