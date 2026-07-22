# Atlas ACOS Max — Plano de Fronteira (v1)

> Obra sucessora do plano `atlas-acos-excellence-10-10-plan-v1.md` (o "v1", 98 slices, em execução). O v1 leva o ACOS ao teto dos medidores atuais; **este plano sobe o teto** e cobre as **18 áreas inteiras** do ACOS em profundidade de fronteira, local-first. **255 slices** em 9 famílias: MAXA..MAXG (cadeia de contexto, seção v) + ASI-01..18 (Programa ASI-Substrato, seção viii — a ordem MESTRA F0–F3 da auditoria de 5 lentes) + MAXH..MAXN (60 slices das 11 áreas restantes, seção ix) + RAGX-01..11 (RAG no auge absoluto, seção x) + ELEV-01..31 (elevações da revisão adversarial externa DUPLA — Fable + Codex, seção xi: 12 slices novos + ~18 elevações in-place aplicadas nos slices existentes) + MULT (60 slices do núcleo multiplicador em 7 catálogos — áreas 9/10/1+16/17/12/15 + Integração, seção xii) + ESP-00..12 + MARCO Vertical-1 (as 3 Espinhas compostas, seção xiii) + REC-01..06 (teto: Recursive Intelligence governada, seção xiv) + TETO-01..10 (os últimos degraus ao teto máximo — N-Capture Drill, mission_e2e, Trajectory Vault, 2º domínio, obra-retro, execução paralela, seção xv) — Memória/Consolidação, Captura&Imunidade, Aprendizado, Decisão/Governança, Evidência/Execução-Verificada, Porta&Provider, Modelo-do-Operador/Originação; verificados por 21 lentes adversariais + crítico global). Achado unânime dos leitores das 11 áreas: em TODAS o padrão é *construído+correto+DESLIGADO ao lado de dado vazio* — o caminho para o auge é ativação+endurecimento+medição honesta, não construção do zero.

> **EXECUÇÃO:** o roteiro executável deste plano (bootstrap de sessão, leis, protocolo por slice, ORDEM MESTRA em 13 lotes com gates, MARCO Vertical-1, gatilhos do operador, gotchas) vive em **`atlas-acos-max-implementation-playbook-v1.md`** — projeção deste plano para qualquer IA implementar 100%. Em divergência, ESTE plano vence e a divergência vira gap no ledger.

> **ECOSSISTEMA DE DOCS (5 arquivos):** `…-frontier-plan-v1.md` (a LEI — o quê) · `…-implementation-playbook-v1.md` (o COMO + errata de agenda) · `…-execution-scoreboard-v1.md` (o ESTADO vivo, 255 slices) · `…-implementation-prompt-v1.md` (o prompt do executor) · **`…-remaining-to-100-v1.md` (o QUE FALTA para 100% — manifesto de-duplicado das caixas abertas, gerado do scoreboard vivo).**

---

## ÍNDICE (navegação — `grep -n "## (<numeral>)"` salta para a seção)

| Seção | Título | Famílias de slice definidas aqui |
|---|---|---|
| (i) | Corte e estado REAL medido (12/07/2026) | — (diagnóstico) |
| (ii) | Relação com o v1 — regras de convivência INVIOLÁVEIS | — (contrato; a lei 6 traz ELEV-03/18/20/26/28/31) |
| (iii) | A tese do Max — 5 leis que ordenam tudo | — |
| (iv) | Ondas Max (M0–M6) | — (mapa de ondas = classes de trabalho; a AGENDA real é a dos 13 lotes no playbook §5; tradução onda↔lote em §0.8) |
| (v) | Catálogo por área — busca/recuperação/contexto/compactação/medição | **MAXA-01..10, MAXB-01..10, MAXC-01..07, MAXD-01..09, MAXE-01..08, MAXF-01..11, MAXG-01..10** (65) |
| (vi) | Colisões consolidadas com o v1 (mapa único) | — |
| (vi-b) | **MAPA DE DONO ÚNICO** — dedup cross-área (slices gêmeos) | — (autoridade de propriedade) |
| (vii) | Critério de conclusão do ACOS Max (itens 1–10) | — |
| (viii) | Programa ASI-Substrato — ordem F0–F3 + critério (itens 11–17) | **ASI-01..18** |
| (ix) | Cobertura das 18 áreas — 11 áreas restantes | **MAXH-01..10, MAXI-01..09, MAXJ-01..08, MAXK-01..09, MAXL-01..10, MAXM-01..08, MAXN-01..06** (60) |
| (x) | RAG no auge absoluto | **RAGX-01..11** |
| (xi) | ELEV — elevações da revisão adversarial dupla | **ELEV standalone: 02,12,17,19,20s,21,22,24,25,26s,27,29s** (12) + ELEV in-place: ver nota abaixo |
| (xii) | MULT — Evolução do Núcleo Multiplicador | **MULTJ-01..09, MULTK-01..08, MULTH-01..08, MULTN17-01..08, MULTV-01..10, MULTN15-01..08, MULTX-01..09** (60) |
| (xiii) | ESP — as 3 Espinhas Compostas + Vertical 1 | **ESP-00..12 + MARCO ESP-V1** (13) |
| (xiv) | REC — Teto: Recursive Intelligence governada | **REC-01..06** |
| (xv) | TETO — os últimos degraus ao teto máximo | **TETO-01..10** |

**Total: 255 slices.** Contagem por família e atribuição slice→lote de execução: playbook `atlas-acos-max-implementation-playbook-v1.md` §5.5 (inventário) e scoreboard (estado vivo).

> **⚠️ DUAS ESPÉCIES DE ID ELEV (ler antes de resolver qualquer `deps: [ELEV-NN]`):** (a) **ELEV standalone = SLICES a implementar** — têm sufixo `s` (ELEV-20s, ELEV-26s, ELEV-29s) OU são os números 02,12,17,19,21,22,24,25,27; cada um tem bloco próprio na seção (xi). (b) **ELEV in-place = LEIS/edições já aplicadas** — números 01,03,04,05,06,07,08,09,10,11,13,14,15,16,18,20,26,28,30,31; NÃO são slices a implementar, são regras/emendas já embutidas nos slices e no contrato (ii.6); resolvem-se lendo a seção (xi) "Índice das elevações in-place" e a lei correspondente em ii.6. Uma dep `[ELEV-15]` (linhagem como aceite dos flips) é uma LEI que o slice-dono já carrega, não um slice à parte.

## (i) Corte e estado REAL medido (12/07/2026 — pós-progresso do executor v1)

O executor do v1 avançou rápido: ondas 0–3 da dimensão composição já landadas (COM-01 refs canônicos, COM-04 medição honesta — as constantes fabricadas 92/62/28 morreram), RAG-07/08 landados (AURG saltou de 586 para **1.848 nós / 2.934 arestas**, cobertura cross-layer de memória 0,61 ≥ alvo), EVI-04 landado, WDG-01 em voo na working tree. Este plano nasce contra o estado de HOJE, não contra a auditoria de 09/07.

**Números medidos ao vivo pelos leitores (a verdade que define a fronteira):**

| Medição | Valor real | Ficção reportada hoje |
|---|---|---|
| Latência de 1 embed | 1,2–1,9s (spawn de processo Python + reload ONNX por chamada) | — |
| Embeds da MESMA query por recall | 3× (registry, verbatim, semantic), sem cache | — |
| Recall completo | 5,9–8,5s | — |
| Context-pack completo | 13,3–18,7s | ARLCG reporta 810ms (fórmula `360+75×refs`) |
| Hooks por turno típico (UserPromptSubmit 15,2s + PostToolUse 8,1s + PreToolUse 5,2s…) | **~65s de wall-clock** | não medido em nenhum seam |
| Hooks registrados em settings.json | **em DUPLICATA** (2 injeções byte-idênticas comprovadas ao vivo) | — |
| `aobg workspace activate` | >170s, roda A CADA prompt, sem timeout | — |
| Vetores de embedding existentes | **247** (77 memórias + 169 notes + 1 verbatim) | — |
| Code symbols / KB items com embedding | **0** de 290.211 / 0 de ~950 | — |
| Golden set v1 (RAG-05, congelado) | recall@5 = **0,0** — must_include são ids sintéticos `sha256('golden-memory-00N')` que só existem em teste | check `author_judge_separated` passa em boolean auto-declarado |
| ARFL janela live | total=20, **measured=0** — o markdown injetado não imprime refs de memória/grafo, o consumidor não pode citar o que não vê | — |
| Arena AREBA | roda 800–2.088×/dia DENTRO do enforce, permanentemente `blocked` (context_roi=0), **não bloqueia nada** | — |
| Compactação de conversa (dados reais) | 6 compactions: **5 EXPANDIRAM** (boilerplate > thread); 1 comprimiu (0.40) | — |
| `atlas_long_horizon_compaction_receipts` | **tabela NÃO existe** no DB vivo (baixa do wiper; migrations diz "Ran"); `compactForScope` sem guard ⇒ QueryException latente no próximo handoff real | CPT-02/05/06/07 verdes em teste |

**Órgãos construídos-e-mortos (built-but-unwired) encontrados:** ponte `CodeGraphUnifiedView`/`CodeGraphRealityIngestionService` (0 callers), 2 linkers AURG que produzem 0 arestas (evidence, doc_memory — mismatch de ids), marker pós-compaction (161 linhas, 0 leitores), `dup_group` do SegmentImportanceRanker (0 produtores), campo `importance` (0 produtores), working set ACMF SIS1 (0 writers), `ContextPackSelfReflectionGate` (vácuo: 1 item "sufficient"), AARF que declara em código `is_agentic=false`.

## (ii) Relação com o v1 — regras de convivência (INVIOLÁVEIS)

1. **Este plano NÃO substitui o v1** — ele constrói o ALÉM. Slices v1 não são re-propostos; quando um MAX depende de um v1, a dep é nominal.
2. **Medidores v1 congelados são intocáveis** enquanto os relógios v1 correm (golden set RAG-05, série 30d, janelas ARFL, soaks CPT-09/ENG). A fronteira sobe régua **versionando medidores v2** (golden v2, floors v2, série v2), nunca editando um medidor congelado in-place.
3. **Sub-queries e medições extras rodam em peek** (`record_usage=false`) — nunca inflar as séries v1 (RAG-01/MEM-03) com tráfego sintético da fronteira.
4. **Contenção com o executor v1**: antes de editar arquivo compartilhado (routes/console.php, settings.json, docs), reler do disco na hora; edições pontuais, nunca rewrite full-file de cópia em contexto; commit rápido e escopado.
5. Contrato pétreo do v1 herdado por inteiro: anti-Goodhart, floor de autonomia (charter 06/07 — auto-aplicação reversível etiquetada, revisão-depois), author≠judge, reversibilidade (ROL), medidor≠produtor (MED-01, leitura dupla), relógios vigiados. Todo watchdog/check novo nasce **plugin do WDG-01**.
6. **Leis transversais adicionadas pela revisão adversarial dupla (seção xi):** **ELEV-03** — todo limiar numérico de aceite ("≥X%", "margem ≥ mínimo declarado") é carimbado no ledger no FREEZE do medidor, antes da 1ª linha do produtor; limiar definido depois de ver o dado é aceite decorativo. **ELEV-18** — "juiz externo" é identidade MECÂNICA: todo congelamento de régua grava `{judge_engine_id, author_engine_id}` com `judge != author` assertado por teste (o padrão MAXJ-08/RAGX-09, promovido a lei). **ELEV-20** — nenhum slice fecha sem ≥1 produtor E ≥1 consumidor com tráfego real provado no ledger: o anti-"construído-mas-não-ligado" vira invariante de aceite, não esperança. **ELEV-26** — toda promoção `default-OFF → shadow → live` segue o protocolo ÚNICO da seção xi (estado+janela+juiz+receipt comuns), nunca variação ad-hoc por slice. **ELEV-28** — família cujo A/B raiz dá resultado negativo entra em **gate de continuação** (subárvore SUSPENSA até a dep de evidência virar verde — nunca deletada; compatível com completude máxima). **ELEV-31** — toda proposta de evolução compete obrigatoriamente com TRÊS alternativas: não fazer nada, simplificar o existente, ou remover uma camada; o comparativo é campo obrigatório do objeto-hipótese (REC-01, seção xiv) e do receipt de promoção — a catraca anti-complexidade de um plano de 255 slices.

## (iii) A tese do Max — 5 leis que ordenam tudo

1. **Eficiência estrutural PRIMEIRO.** Não se constrói inteligência sobre um substrato que paga ~65s de imposto por turno e 18s por pack. Daemon de embeddings + memoização + dedupe de hooks + cache de pack derrubam o custo de TUDO que vem depois — e são as maiores alavancas ganho/custo do plano inteiro.
2. **Corpus antes de ranking.** recall@5=0 com 25/25 alvos irresolvíveis prova: nenhum ranker move o número enquanto o corpus (77 entries) não tiver os alvos. Ranking sofisticado (LTR, reranker) só entra DEPOIS de golden v2 real + corpus re-hidratado (v1 MEM-05/CORP-01) + sinal negativo real.
3. **Ligar órgãos mortos antes de construir novos.** A lista do item (i) é o backlog mais barato de capacidade real do ACOS: wiring de seams que já existem testados.
4. **Medição honesta antes de capacidade.** Latência real instrumentada nos seams (matar a fórmula-ficção do ARLCG), golden v2 ancorado em content_hash de memórias REAIS congelado por juiz externo, arena fora do hot path, suite de retrieval no caminho de land. Nenhuma melhoria conta sem régua v2 previamente congelada (MED-01).
5. **Autonomia composta.** Tudo que aprende/aplica segue o charter: auto-aplicação reversível + Diário/digest + handle de remoção. Nenhum slice cria fila de aprovação humana.

## (iv) Ondas Max (M0–M6)

Ordem obrigatória entre ondas; dentro da onda, deps do catálogo. Slices não citados nominalmente entram na onda da sua classe (higiene→M0, medidor→M1, eficiência→M2, cobertura/corpus→M3, inteligência→M4, certificação→M5, fronteira-pós-Max→M6), respeitando as deps declaradas no catálogo. **A tradução ONDA→LOTE de execução está no playbook §0.8; a ordem operacional real é a dos 13 lotes (playbook §5), não a das ondas (que são classes de trabalho, não uma agenda linear).**

- **M0 — Higiene quase-grátis (pode começar HOJE, zero colisão com v1):** dedupe dos hooks duplicados + timeout no atlas-ctx.sh (MAXE-02/03), refs citáveis no markdown do pack (MAXE-01 — destrava o loop ARFL/D4 inteiro por ~2-3% de budget), reparo da tabela de receipts long-horizon + guard fail-open (MAXF-01 — chip já aberto), decisão do órgão-órfão da ponte de grafo (MAXD-07 preparação).
- **M1 — Medidores honestos v2:** provenance por vetor (`embedding_model` + `embedded_content_hash` — MAXA), golden set v2 ancorado em content_hash real congelado por juiz externo (MAXG-04, MAXB-02), instrumentação de latência real p50/p95 nos seams vivos (pack, recall, hooks, ledger — MAXG), ARLCG lendo medição real em vez de fórmula, fixação de fallback de embedding: **provider externo NUNCA grava vetor na espinha** (default-OFF, coluna separada se existir).
- **M2 — Eficiência estrutural:** daemon residente de embeddings + memoização da query (3×→1, MAXB-01/MAXA), `aobg activate` fora do per-prompt, cache incremental de pack por query_hash+content_hash (MAXE), arena AREBA movida do enforce para cadência própria barata (MAXG), alvo: pack p95 ≤ 2s, hooks por turno ≤ 5s total.
- **M3 — Corpus e cobertura:** embeddings de código/KB incremental por source_hash (290k símbolos entram na busca semântica — MAXA), linkers mortos ligados por ids reais (evidence via receipt/trace — MAXD-02) + import dos 231.082 links doc→código auditados em vez de re-derivação por regex (MAXD-01), re-embed incremental, golden v2 com alvos 100% resolvíveis.
- **M4 — Inteligência:** fusão RRF parameter-free → negative mining das rejeições do digest → LTR gated por diversidade (MAXB, nesta ordem), reranker local só com ganho provado no golden v2, PageRank ponderado + comunidades Louvain + drill-down federado grafo↔índice (MAXD-04/05/07), agentic REAL: decomposição determinística de query, bloco de suficiência com faltas nomeadas (nunca escalar — o "92" não volta pela porta dos fundos), hop-2 AURG budgetado com morte declarada por used-rate, priors por perfil de tarefa, plano de retrieval injetado no hook (MAXC-01..07), ACMF com primeiro writer real: seen-refs por sessão + demoção do já-visto (MAXE-06), compactação fronteira: dedup lexical por hash, importância por recovery-hits/citações, lookup `turn:{id}` no recovery, compaction-aware packs (MAXF).
- **M5 — Certificação Max + re-prova externa:** floors de latência viram gate permanente (plugin WDG-01), golden v2 recall@5 ≥ 0,90 contra corpus VIVO, ARFL measured_share real ≥ 0,90 com refs citados de verdade, ROI de token medido com alvo, fidelity de compactação provada por recovery-test amostral, zero órgãos unwired na lista do item (i), Marco Zero v2 cunhado (nova série, sem tocar a v1), e **ADV-Max**: re-prova adversarial externa de cada certificador novo antes de qualquer "máximo" ser carimbado.
- **M6 — Fronteira pós-Max (o ALÉM das réguas do Max):** RAGX (late-chunking, contextual retrieval, CRAG-lite, HyDE, cascata de rerank, cache semântico, RAPTOR, SPLADE — seção x), os slices MULT/ESP/REC/TETO de inteligência tardia, e o meta-otimizador REC-04 em shadow. **Cada slice M6 é GATED pela dep Max correspondente ter aterrissado** (ex.: RAGX-01 espera MAXA-04/05); nada de M6 fura F0→F1. Sub-fases a/b/c dentro de M6 = régua→atuador→transferência.

## (v) Catálogo por área (65 slices)

As sete seções abaixo são o material integral dos leitores de fronteira — estado atual citado por arquivo:linha, teto, técnicas com veredito, slices com aceite mensurável e colisões com o v1.


---

# MAX-A — Embeddings & Índices Semânticos (bloco ASEF)

Leitura ground-truth em 2026-07-11, repo `/Users/vitorepf/develop/Atlas/atlas-server`, DB pgsql local `atlas` (porta 5433, Postgres 16.13 Debian/container, pgvector **0.8.2**). Todos os números abaixo são medidos, não estimados.

## Estado atual

### Modelo e onde roda
- **Modelo vivo (local, sovereign default):** `sentence-transformers/paraphrase-multilingual-MiniLM-L12-v2` via **fastembed 0.8.0** (ONNX, onnxruntime 1.26.0, CPU) — 384-d, L2-normalizado, mean pooling. Definido em `runtimes/python/semantic_rag/atlas_semantic_rag/embeddings.py:67` (classe `FastEmbedEmbedder`, dim provado por probe em :78-79).
- **Fallback externo:** OpenAI `text-embedding-3-small` (API, 1536-d nativo) — `embeddings.py:86-106` e lado PHP `app/Services/Semantic/EmbeddingService.php:107-138`. Fallback **default ON** (`embedding_fallback_enabled`, `config/atlas.php:437`) e dispara sempre que o runtime local falha e há `OPENAI_API_KEY` (`EmbeddingService.php:98-102`).
- **Boundary PHP→Python:** `app/Services/Ai/RuntimeBoundary/SemanticRagRuntimeClient.php:46-49` (`embed`), com receipt anti-fake (`real_embeddings===true`, `fabricated_vectors===false`, :34-38). Mecânica: **um subprocesso Python por chamada** — escreve manifest JSON em `storage/app`, spawna `.venv/bin/python main.py <manifest>`, lê stdout (`PythonManifestRuntimeClient.php:31-66`). Não há daemon, não há cache: **cada `embedText()` paga startup do Python + load do modelo ONNX**.
- **Latência medida** (2 execuções, manifest de 1 texto curto, M-series): **1.54s e 1.94s wall** por chamada. O warm run é igual ao cold — o modelo é recarregado toda vez.
- **Custo por recall:** `AtlasHybridMemoryRetrievalService` chama `scoreEntries` (:204) e `scoreVerbatims` (:362) separados; cada um embeda a query de novo (`AtlasMemoryVectorSearchService.php:84`) → **até 2 subprocessos (~3-4s) por recall**, pago em cada context pack (UserPromptSubmit hook injeta pack por prompt).
- Gotcha vivo: fastembed 0.8.0 emite warning de que este modelo **mudou de CLS para mean pooling** (visível em qualquer run; `embeddings.py:76`). Vetores gravados sob pooling antigo seriam silenciosamente incompatíveis com queries novas — e não há stamp por linha que permita provar qual pooling/modelo gerou cada vetor armazenado (ver Teto).

### Chunking
- **Caminho vivo (memória/notes): NÃO há chunking.** Um vetor por linha, texto = title+summary+body truncado em `max_embedding_chars` = **12.000 chars** (`config/atlas.php:459`; `AtlasMemorySemanticIndexer.php:187-190`; `SemanticNoteIndexer.php:163-174` — notes concatenam title+summary+when_to_use+trigger_signals+body).
- **ASEF chunker existe mas é manifest-only:** `AtlasSemanticEmbeddingFoundationService.php:17` (`MAX_CHARS_PER_CHUNK=1600`), chunking determinístico por parágrafo (:210-245), com chunk_hash sha256, privacy class e delete_cascade_key — porém `embedding_status='candidate_manifest_only'` (:58). Consumidor real: scoring AUCRI local (`chunkTextsByHash` :123-131 → `AtlasHybridRetrievalInfrastructureService`, flag `aucri.local_semantic_scoring`, `config/atlas.php:474-482`) — os chunks são re-embedados por request para score, **nunca persistidos em pgvector**.
- **Contexto por chunk:** nenhum (nem no ASEF manifest, nem no caminho vivo).

### Índice pgvector
- **Tipo: ivfflat cosine em TODAS as 4 tabelas, sem parâmetros (lists default=100), criado com a tabela vazia** — `2026_04_28_050000_create_semantic_memory_tables.php:79`, `2026_05_01_170000_...:43`, `2026_06_09_120000_add_embedding_to_atlas_memory_tables.php:50`, rebuild em `2026_06_08_200000_align_semantic_embedding_dimension_to_real_model.php:43,59`, repair em `2026_07_09_154000:33`. Zero uso de HNSW/halfvec/sparsevec apesar do pgvector 0.8.2 suportar tudo.
- **Cobertura de colunas `embedding` (pg_attribute, medido):** só 4 tabelas — `semantic_notes` (384), `atlas_memory_entries` (384), `atlas_verbatim_memories` (384), `ai_attachment_index_entries` (**1536** — desalinhada do modelo local).
- Nota: a busca viva nem usa o índice como ANN — `AtlasMemoryVectorSearchService.php:92-98` faz `whereIn(id) + selectRaw '1 - (embedding <=> ?)'` sobre candidatos já filtrados (scan exato, correto neste tamanho).

### Pipeline de (re)index
- **Embed-on-write:** memória via `AtlasMemoryRegistryService.php:70,87,138` → `AtlasMemorySemanticIndexer` (best-effort, NULL = degrade lexical honesto); notes via `SemanticNoteIndexer::indexFile` com skip por `content_hash` (:48-52).
- **Backfill:** `atlas:memory:embed-backfill` (`AtlasMemoryEmbedBackfillCommand.php:28-33`) — só `--missing-only`/`--all`; **não detecta stale** (sem hash do texto embedado, sem modelo por linha).
- **Retrieval:** hybrid_score = 0.85·vector + 0.15·lexical, max com lexical puro (`AtlasHybridMemoryRetrievalService.php:27-29,489-497`). Braço "lexical" é token-overlap/substring, **não** FTS (tsvector só existe em `ai_messages`).

### Cobertura real (contado no DB)
| Store | rows | com embedding |
|---|---|---|
| atlas_memory_entries | 77 | 77 (100%) |
| atlas_verbatim_memories | 1 | 1 |
| semantic_notes | 169 | 169 (100%) |
| ai_attachment_index_entries | 0 | 0 |
| **atlas_engineering_knowledge_items** | **950** | **0 — sem coluna** |
| **atlas_engineering_code_symbols** | **290.211** | **0 — sem coluna** (só btree em symbol_name/file_path etc.) |

Total do espaço vetorial do Atlas hoje: **247 vetores**. Provenance suspeita: metadata de 9/169 notes ainda diz `"model":"local-hash-v1","provider":"local_hash","semantic":false,"dimensions":1536` (fake aposentado) enquanto a coluna guarda vetor 384-d real — o stamp por linha não é confiável.

### Medição existente (reusar, não recriar)
`atlas:ai:local-rag-benchmark --json` (`LocalRagBenchmarkService.php`, agendado com `--record-memory-quality`) já mede latency p95, corpus de precisão independente R8 (`LocalRagPrecisionCorpusService`, fixture em `resources/atlas/local_rag`, override `independent_precision_corpus_path` `config/atlas.php:471`) e semeia o golden set do RAG-05 (`frozen_set_id 'memory_recall_golden_2026_07_rag05_seed'`, :833).

## Teto atual

1. **Latência estrutural:** ~1.5-2s por embed (spawn+model load por chamada), ×2 por recall — é o piso de latência de todo context pack. Nenhum cache de query embedding em lugar nenhum.
2. **Cobertura 247 vetores:** 99,9% do conhecimento consultável (290k símbolos, 950 KB items, ~1.011 docs canônicos, evidence ledger) não tem representação densa — busca semântica de código/KB simplesmente não existe.
3. **Um vetor por documento, truncado em 12k chars:** conteúdo do meio/fim de docs longos fica diluído ou fora do vetor; o chunker ASEF (1600 chars, hashes, privacy) já existe mas nunca persiste.
4. **Modelo 2021-era:** MiniLM-L12 multilingual 384-d foi escolhido por PT + dim compat (`embeddings.py:67-70`); o mesmo fastembed instalado já serve `jina-embeddings-v3` (1024-d, matryoshka, multilingual, 8k ctx, 2.3GB) e `multilingual-e5-large` (1024-d, 2.2GB) — zero dependência nova.
5. **Sem provenance por vetor:** nada impede vetor OpenAI-384 (fallback) e fastembed-384 coexistirem na mesma coluna (similaridade cross-model = ruído); 9 rows com metadata de provider fake; mudança de pooling do fastembed é indetectável.
6. **ivfflat treinado em tabela vazia, lists default:** hoje irrelevante (scan exato em 247 rows), mas vira recall-loss silencioso quando o corpus crescer (CORP-01); pgvector 0.8.2 já suporta HNSW (sem treino) + halfvec + sparsevec + iterative scans.
7. **Braço lexical fraco:** substring/token-overlap, sem FTS/trigram — queries por termo exato ("atlas:brain:seed", nome de classe) dependem do acaso do substring.
8. **Fallback externo default-ON na espinha:** venv quebrado + key presente ⇒ texto de memória provider-safe vai para API externa sem decisão do operador por linha de espinha (gates de privacy por row existem, mas o default fere local-first estrito).

## Fronteira

| Técnica | Veredito | Porquê (medível) |
|---|---|---|
| **Daemon de embedding persistente** (modelo residente) | **APLICAR (maior alavanca)** | 1.5-2s → ~10-30ms por embed warm (MiniLM encode em CPU M-series é ~5-15ms). Medir: `local-rag-benchmark` latency p95 antes/depois. Zero mudança de qualidade — só remove overhead morto. |
| **Cache/memo de query embedding** | **APLICAR (rung mais baixo)** | Mesma query embedada 2× por recall (entries+verbatims) e re-embedada entre packs; memo por request + cache keyed sha256(text)+model corta 50-100% das chamadas repetidas. Medir: contador de spawns por recall (2→1→0 em query repetida). |
| **Provenance por vetor (model + content_hash) + re-embed incremental** | **APLICAR (pré-condição de tudo)** | Sem isso, upgrade de modelo/pooling é indetectável e o fallback OpenAI contamina a coluna. `source_hash` dos code symbols prova o padrão. Medir: `stale_count==0` pós-backfill; teste que recall filtra `embedding_model = modelo_da_query`. |
| **Modelo local melhor (jina-embeddings-v3 1024-d matryoshka)** | **APLICAR condicionado a medição** | Já suportado pelo fastembed instalado; multilingual PT; matryoshka permite truncar honestamente (com re-norm). Ganho declarável SÓ pelo corpus R8 + golden set RAG-05 congelado (mesmo set, dois modelos, dual-read). Custo: 2.3GB disco, encode ~4-8× mais lento (irrelevante com daemon). |
| **Embeddings contextuais (prepend título/summary/section-path ao chunk)** | **APLICAR junto com chunking persistido** | Só faz sentido quando houver chunk-level embeddings; contexto vem de campos já parseados (frontmatter/heading) — custo zero de provider, determinístico. Medir: precision@5 no corpus R8 estendido com alvos mid-doc (nunca no golden set congelado). |
| **Cobertura: KB items + code symbols + docs** | **APLICAR faseado** | 950 KB items = ~30s com daemon (S). 290k símbolos = one-off ~2-3h + incremental por `source_hash` já existente; `jinaai/jina-embeddings-v2-base-code` já no fastembed. Storage halfvec: 290k×384×2B ≈ 220MB. Medir: corpus R8 de queries de código (precision@5 vs busca btree atual). |
| **HNSW (m=16, ef_construction=64) no lugar de ivfflat** | **APLICAR nas tabelas novas; migrar as 4 atuais junto** | HNSW não precisa de treino (mata o bug do ivfflat-em-tabela-vazia) e é o default correto para corpus crescente. Nas 4 tabelas atuais o ganho de latência é ~0 (247 rows) — o valor é ELIMINAR o recall-loss futuro por construção. Medir: recall exato-vs-índice = 1.0 em suíte determinística. |
| **halfvec (fp16)** | **APLICAR só nas tabelas grandes novas** | 2× menos storage/IO; recall-loss ~nulo documentado. Em 247 rows é irrelevante — não tocar nas tabelas existentes só por isso. |
| **Quantização binária + rescore** | **DESCARTAR por ora** | Vale a partir de ~1M+ vetores; abaixo disso é complexidade sem ganho mensurável neste hardware. Re-avaliar se code-chunks passarem de ~2M. |
| **Híbrido denso+esparso** | **APLICAR em degrau: FTS Postgres primeiro; SPLADE só se FTS não bastar** | Rung 4 (nativo): tsvector+GIN nas mesmas rows + RRF em SQL resolve termo exato com zero Python (padrão já existe em `ai_messages`). fastembed instalado já tem `SparseTextEmbedding` (SPLADE/BM42) + pgvector `sparsevec` se a medição do R8 mostrar gap remanescente. Medir: precision@5 em queries de termo exato (subconjunto rotulado do R8). |
| **Late-interaction / rerank local (answerai-colbert-small / jina-colbert-v2)** | **APLICAR por último, atrás de switch default-OFF** | fastembed instalado já tem `LateInteractionTextEmbedding`. Rerank top-20→top-5 tipicamente +5-15pp precision@5, custo +50-200ms com daemon. Só medível com corpus R8 maduro; nunca tunar contra o golden set. |
| **Matryoshka/dimensões adaptativas** | **APLICAR só como propriedade do jina-v3** | Guardar 1024 full, indexar halfvec; truncação adaptativa só se latência de índice virar gargalo medido (não vai, neste corpus). |

## Slices candidatos

**MAXA-01 — Daemon de embedding local (modelo residente)** · E:M · deps: []
- Goal: matar o custo de spawn+model-load por chamada mantendo o boundary receipt anti-fake intacto.
- Mecanismo: processo Python longevo no mesmo venv `semantic_rag` (loop stdin/unix-socket, idle-timeout, lifecycle simples — sem framework novo), `SemanticRagRuntimeClient` fala com o daemon e cai para o spawn atual se ausente (fail-open byte-idêntico). Receipt boundary igual (`real_embeddings` etc.).
- Aceite: `php artisan atlas:ai:local-rag-benchmark --json | jq '.latency'` → p95 do embed <100ms warm (baseline medido: 1.5-1.9s); teste que o fallback spawn continua funcionando com daemon morto.
- **Governança do runtime residente (fundação APDR — decisão de escopo 12/07):** este daemon é o primeiro processo Python *always-on* da espinha, então herda a governança do runtime residente da área APDR. Cobertura: **supervisão/idle-reap/RSS-cap/idle-restart = ASI-16** (servidor de contexto residente, mesmo padrão de lifecycle); **load-shed sob carga = ASI-18** (fila cognitiva); **socket local-only + health como plugin WDG-01 + manifest de integridade do modelo = ELEV-19**. MAXA-01 entrega o loop mínimo (idle-timeout + socket unix local + fail-open ao spawn); a governança pesada NÃO é reimplementada aqui — é consumida de ASI-16/18/ELEV-19 quando aterrissarem (até lá, o daemon roda com idle-timeout curto + cap de processo do ASI-04). Isto FECHA o gap "APDR sem cobertura" como decisão declarada, não buraco silencioso.

**MAXA-02 — Memo por request + cache persistente de query embedding** · E:S · deps: []
- Goal: 1 embed por (query, modelo) por processo; 0 para query repetida entre packs.
- Mecanismo: memo em `EmbeddingService` (ou no VectorSearchService) por sha256(text)+model; `Cache::remember` com TTL curto para cross-request. `scoreEntries`+`scoreVerbatims` passam a compartilhar o vetor.
- Aceite: teste contando invocações do runtime: recall com entries+verbatims = 1 chamada (hoje 2); segunda recall da mesma query = 0.

**MAXA-03 — Provenance de vetor + re-embed incremental por hash** · E:S/M · deps: [] (pré-condição de MAXA-04/06)
- Goal: todo vetor armazenado auditável {modelo, hash do texto embedado}; recall nunca compara cross-model; re-embed só do que mudou.
- Mecanismo: colunas `embedding_model` + `embedded_content_hash` nas 4 tabelas (e nas novas); indexers gravam; `AtlasMemoryVectorSearchService` filtra `embedding_model = modelo corrente`; `atlas:memory:embed-backfill --stale` re-embeda hash divergente. Corrige de passagem os 9 rows com metadata `local-hash-v1`.
- Aceite: `psql`: 0 rows com `embedding IS NOT NULL AND embedding_model IS NULL` pós-backfill; phpunit: query fastembed nunca pontua row com `embedding_model='openai:...'`.

**MAXA-04 — Upgrade de modelo local medido (jina-embeddings-v3, matryoshka 1024-d)** · E:M · deps: [MAXA-01, MAXA-03, **MAXB-02/MAXG-04 (golden v2 com `targets_available == cases` — ELEV-01)**, RAG-05 (v1 só como regressão de floor)]
- Goal: trocar MiniLM-L12 pelo melhor modelo local que o runtime já suporta, SÓ se vencer no set congelado.
- Mecanismo: re-embed do corpo todo (247 rows ≈ segundos com daemon) sob `embedding_model` novo em coluna própria/tag; rodar corpus R8 + golden set RAG-05 com ambos; promover por dual-read registrado (procedimento pétreo do plano v1, seção MED); rollback = apontar de volta.
- Aceite (ELEV-01): a régua do A/B é o **golden v2** (`targets_available == cases` — o v1 mede 0.0 com 25/25 alvos ausentes; "≥ baseline" contra ele seria 0≥0, vacuamente verde para QUALQUER modelo) + corpus R8: `atlas:ai:local-rag-benchmark --json` → recall@5 e precision@5 do modelo novo ≥ modelo atual no MESMO set v2 congelado (hash igual), com os dois números registrados no ledger; o v1 roda só como regressão de `improper_floor_discards`; nenhuma edição de fixture no diff. **ELEV-29:** o contrato do slice especifica CAPACIDADES requeridas (ctx ≥8k, multilingual PT, dim, pooling, determinismo, licença) — "jina-v3" é implementação substituível, nunca parte do aceite.

**MAXA-05 — Chunk-level embeddings persistidos com contexto prepended (ASEF vira índice real)** · E:M/L · deps: [MAXA-01, MAXA-03; ordem: DEPOIS de MEM-05 re-hidratar textos]
- Goal: conteúdo mid-doc recuperável; ASEF sai de manifest-only para índice pgvector governado.
- Mecanismo: tabela filha `asef_chunks` (chunk_hash, source_ref, privacy_class, delete_cascade_key — schema já definido em `AtlasSemanticEmbeddingFoundationService.php:46-65`) + embedding halfvec HNSW; texto embedado = `"{title} > {section}\n\n{chunk}"` (contexto determinístico de frontmatter/heading); retrieval mapeia chunk→documento com dedup; delete cascade pelo key já manifestado.
- Aceite: phpunit determinístico chunk→doc + delete cascade; corpus R8 estendido com ≥10 queries de alvo mid-doc (>1600 chars da abertura do doc): precision@5 dessas queries > baseline single-vector, registrado dual-read.

**MAXA-06 — Cobertura semântica: KB items (fase 1) e code symbols (fase 2)** · E:L · deps: [MAXA-01, MAXA-03, MAXA-07]
- Goal: os 950 knowledge items e 290k símbolos passam a ter busca densa local; incremental por `source_hash` (colunas já existem).
- Mecanismo: fase 1 — coluna embedding halfvec em `atlas_engineering_knowledge_items`, embed no `knowledge sync` (950 rows ≈ <1min com daemon). Fase 2 (switch default-OFF) — tabela `code_symbol_embeddings` (halfvec+HNSW, `jina-embeddings-v2-base-code` já no fastembed), backfill one-off ~2-3h, incremental no `index-code` por source_hash; consumo no context pack code section.
- Aceite: fase 1 — `psql`: count(embedding)==count(*) em knowledge_items; corpus R8 com ≥10 queries de KB: precision@5 > busca lexical atual. Fase 2 — mesma régua com queries de código; job incremental re-embeda SÓ hashes novos (assert por contagem).

**MAXA-07 — HNSW por construção (matar ivfflat-treinado-no-vazio)** · E:S · deps: []
- Goal: nenhum índice vetorial do Atlas pode perder recall por ter sido treinado sem dados.
- Mecanismo: migração `DROP INDEX ivfflat / CREATE INDEX ... USING hnsw (embedding vector_cosine_ops) WITH (m=16, ef_construction=64)` nas 4 tabelas; padrão obrigatório para tabelas novas (MAXA-05/06); `SET hnsw.ef_search` só se medição exigir.
- Aceite: suíte determinística: top-K via índice == top-K via scan exato (recall 1.0) nas 4 tabelas; `pg_indexes` sem ivfflat em colunas vector.

**MAXA-08 — Braço lexical de verdade: FTS + RRF (degrau 1 do híbrido)** `[GÊMEO — dono: MAXB-03, ver vi-b]` · E:M · deps: [MAXA-03; medir contra R8] — *as MESMAS colunas tsvector+GIN e o MESMO RRF do MAXB-03; UM landing fecha os dois; o subconjunto termo-exato do R8 (aceite abaixo) roda nesse landing; degrau-2 SPLADE = exclusivamente RAGX-11*
- Goal: query por termo exato (comando, classe, id) sempre acha a row certa — sem Python novo.
- Mecanismo: tsvector+GIN (padrão de `2026_04_30_150000_add_fts_to_ai_messages.php`) em memory entries/notes/KB items; fusão RRF (denso + FTS) substituindo o blend substring 0.85/0.15 atrás de switch default-OFF até medição. Degrau 2 (SÓ se R8 mostrar gap): `SparseTextEmbedding` (SPLADE) + `sparsevec` — fastembed e pgvector já suportam, zero dep nova.
- Aceite: subconjunto rotulado do R8 com ≥10 queries de termo exato: hit@5 == 1.0 com FTS+RRF vs baseline medido; switch OFF = byte-idêntico ao atual (teste).

**MAXA-09 — Rerank local late-interaction (top-20→top-5)** · E:M · deps: [MAXA-01, MAXA-04, RAG-05 congelado; último da fila]
- Goal: +precision@5 real no último degrau do ranking, 100% local.
- Mecanismo: `answerai-colbert-small-v1` (fastembed `LateInteractionTextEmbedding`, ~150MB) no daemon; rerank dos top-20 do híbrido antes do corte top-K; switch default-OFF; latência alvo <200ms.
- Aceite: R8 + golden set congelado: precision@5 com rerank > sem rerank (dual-read registrado); latency p95 do pack < baseline+250ms; switch OFF = ranking byte-idêntico.

**MAXA-10 — Espinha local-only por default (fallback externo opt-in)** · E:S · deps: [MAXA-01 (daemon torna o local confiável o bastante para tirar a muleta)]
- Goal: nenhum texto da espinha sai da máquina sem opt-in explícito do operador.
- Mecanismo: `embedding_fallback_enabled` default false (env continua podendo ligar); `shouldUseOpenAi` (`EmbeddingService.php:98-102`) exige provider explicitamente `openai` OU fallback ligado — nunca só "key presente". Degrade honesto já existe (NULL→lexical).
- Aceite: teste com venv indisponível + key setada + fallback default: `Http` nunca chamado, recall degrada lexical; `embedding-info --json` reporta `external_fallback: opt_in_off`.

Ordem sugerida: MAXA-02 → MAXA-03 → MAXA-07 → MAXA-01 → MAXA-10 → MAXA-04 → MAXA-08 → MAXA-05 → MAXA-06 → MAXA-09.

## Colisões com v1 (não tocar / ordenar)

- **RAG-05 golden set congelado (hash registrado, author≠judge):** NENHUM slice MAXA pode editar fixtures/set para favorecer modelo novo — o set é a régua, não o alvo. Upgrade de modelo (MAXA-04/09) roda o MESMO set e registra antes/depois via dual-read (procedimento MED do plano v1). Se MAXA-04 landar antes do RAG-05 congelar, re-rodar a suíte no congelamento.
- **Séries temporais vivas (RAG-02 30d, MEM-03 45d, janelas ARFL pós-onda-2):** medem usage/feedback — mudanças de embedding não tocam essas tabelas e NÃO podem resetar contadores; nenhum slice MAXA altera `retrieval_eval_window_days` nem os denominadores janelados.
- **RAG-01/RAG-03 (`recalled_pre_filter`, dominância/concentração):** modelo/rerank novos mudam o ranking e portanto a concentração pré-filtro — isso é capacidade, esperado; proibido "ajustar" o medidor ou a régua 0.5 para compensar. Registrar a mudança de ranking no ledger quando MAXA-04/09 landarem.
- **MEM-05 re-hidrata texto das entries via `curate()`** (que já re-embeda on-write, `AtlasMemoryRegistryService.php:138`): re-embed em massa (MAXA-04) deve rodar DEPOIS do MEM-05 para não embedar texto que vai mudar — senão paga duas vezes e o A/B fica sujo.
- **CORP-01 cresce o corpus por admissão gated:** MAXA-07 (HNSW) deve landar antes do crescimento para o índice novo nascer certo; contagem de corpus continua indicador informativo, nunca aceite.
- **`atlas:ai:local-rag-benchmark` é medidor agendado vivo** (`--record-memory-quality`, LocalRagBenchmarkService.php:860): não renomear, não mudar schema de saída em uso; MAXA só ADICIONA chaves (latência de embed, precision por subconjunto).
- **Blend 0.85/0.15 e o canal `manifest_pending_embedding` (score_hint 0.60)** são comportamento medido por testes existentes: MAXA-08 muda o blend só atrás de switch default-OFF com prova byte-idêntica no OFF.

---

# MAX-B — Retrieval Híbrido + Ranking + Qualidade do que Volta (AHRI + ACRS + floor)

Ground-truth lido do código em 2026-07-11, HEAD `477ce0dc89` (ondas 0–3 do v1 ✅, ondas 4–5 pendentes). Todos os números abaixo foram medidos nesta máquina (pgsql local + pgvector + runtime Python semantic_rag).

## Estado atual

### Arquitetura do recall híbrido (4 fontes fundidas)

`AtlasHybridMemoryRetrievalService::recall()` (`app/Services/Ai/AtlasHybridMemoryRetrievalService.php:56-110`) funde:

1. **Registry** (`atlas_memory_entries`) — `registryItems()` :182-282. Candidatos por escopo+prioridade via `AtlasMemoryRegistryService::relevantForContext()` (`app/Services/Ai/AtlasMemoryRegistryService.php:328-410`), query-aware desde WO-17-T0.1/T0.2: união de (a) ids relevantes à pergunta por vetor (pool `max(limit*10,100)` :429) e (b) ids de prioridade, cap no limit.
2. **Verbatim** (`atlas_verbatim_memories`) — `verbatimItems()` :349-399, só `external_ai_allowed=true`.
3. **Semantic notes** (`semantic_notes`) — `semanticItems()` :405-417 via `SemanticSearchService::search()` (`app/Services/Semantic/SemanticSearchService.php:17-33`): vector top-K **merge** lexical ILIKE top-K (score fixo `0.62` :98), dedup por id, sort por score. Não é RRF — é união com sort por score.
4. **Compounding** (`ai_compounding_memories`) — `compoundingItems()` :122-175, default ON (`compounding_recall_enabled`, config/atlas.php:446), cap 6, ranking **só lexical**.

### hybrid_score — fórmula exata

- **Lexical** (:505-528): tokens únicos `/[\pL\pN]{3,}/u` lowercase da query; score = `round(matches/total_tokens, 3)` onde match = **substring containment** no concat(title,summary,body,source_type). Não há BM25/tsvector — zero colunas `tsvector` nas 3 tabelas (verificado em `information_schema`).
- **Vector** (`app/Services/Ai/Memory/AtlasMemoryVectorSearchService.php:70-111`): cosine pgvector `1 - (embedding <=> ?::vector)` clamp 0..1, escopado ao candidate set (nunca alarga recall). Índices `ivfflat vector_cosine_ops` nas 3 tabelas.
- **Blend** (:489-500): `vector === null ? lexical : round(max(0.85*v + 0.15*l, l), 4)` — semantic-dominante com piso lexical, degrade honesto sem vetor.
- **Registry-only** (:224-236): `hybrid = blended × feedback_factor × concentration_multiplier`:
  - `feedback_factor` (FEE-04, :555-588): Laplace `(pos_explicit + 0.01*pos_implicit + 1)/(neg + 1)` clamp `[0.7, 1.15]`, flag `atlas.memory.feedback_ranking_enabled` **default OFF** (config/atlas.php:425). Exposto em `explain.feedback_ranking`.
  - `concentration_multiplier` (`AtlasMemoryRecallConcentrationDemotion.php:65-76`): `0.35` se a entry é dominante. Dominante = share > 35% dos usages `recalled_pre_filter` em janela 45d com mínimo 100 recalls, top-8 (:23-63, MEM-03 com denominadores 45d).
  - Superseded no mesmo pool (relação `supersedes` open) é **removida** (:219-221, `supersededEntryIds` :177-195).

### Fusão final (composer) — escalas incomparáveis

`AtlasMemoryContextComposer::compose()` (`app/Services/Ai/AtlasMemoryContextComposer.php:33-99`) + `AtlasMemoryRecallRelevanceScorer` (`app/Services/Ai/Aaeos/Cores/AtlasMemoryRecallRelevanceScorer.php:30-57`):

| Fonte | Fórmula do score final |
|---|---|
| registry | `effective_priority + importance*10 + confidence*10 + hybrid*30 + scope(4..22) + type(5..16)`; degrade ⇒ ×0.5 |
| verbatim | `82 + hybrid*24 + scope + type` |
| semantic | `score*100 + type` |
| compounding | fórmula registry com hybrid lexical |

Medido ao vivo (query "concentração demotion recall memória"): registry top = **178.9**, verbatim = **100.0**, semantic = **51.1**. As escalas não são comparáveis entre fontes — o rank cross-source é decidido por constantes de fórmula, não por relevância à query. No registry, o termo de query (`hybrid*30`, máx 30 pts) é **minoria** contra `priority` (até 100) + `importance*10` — memória de prioridade alta entra no top-K quase independente da pergunta (observado: top-1 "26 classes AtlasLoop*" para query sobre demotion). Depois: Pareto-drop por fonte em {score max, age min} (:480-524), sort, greedy por budget.

### Relevance floor do pack

`AtlasOpenBrainContextPackService::memorySection()` (:2218-2321) → `filterDemotedMemoryItems` → `filterLowRelevanceMemoryItems` (:2409-2472):
- Item com `_recall_score > 0` **passa direto** (rank-escape, P0 de 09/07, :2449-2457 — o overlap lexical zerava a seção em query PT vs título EN). Como todo item composto tem score > 0 por construção (constantes de fórmula), **o floor hoje é pass-through para tudo que o ranker serviu**; o overlap>=2 só pega itens sem score. Observado: `memory_relevance_filtered=0`.
- Wiper-guard por conceito ESTÁVEL (RAG-03): `metadata.incident_scope='wiper'` ou refs fixas em config (:2480-2492); a task precisa conter termo wiper-ish (:2494-2499).
- RAG-01 vivo: usage dual-point — `recorded pre_filter` antes dos filtros (:2328-2341, alimenta o sensor de dominância) e `memory_recall` só do que foi entregue (:2349-2380).

### Reranking existente

- **L3-6** `semanticallyReorderMemory()` (:2621-2666) via `SemanticContextRetrievalService::rank()` — re-rank por embedding real dos itens recallados. Flag `atlas.aobg.semantic_retrieval` **default OFF** (`SemanticContextRetrievalService.php:127`). Hoje a ordem do pack = ordem do composer.
- **ACRS** (`app/Services/Ai/Context/AtlasContextRankingSystemService.php:40+`, com `ProgrammingProfessionalReranker` + `WorldModelGraphRanker`) está wirado nos caminhos AUCRI/Programming retrieval — **não** no recall de memória vivo. **AHRI** (`atlas:context:hybrid-retrieval`) é report-only sem writes. Não existe cross-encoder em nenhum caminho.

### Latência medida (nesta máquina, read-only)

| Medição | Wall |
|---|---|
| `php artisan about` (baseline boot) | **0.59s** |
| 1 embed Python cold (spawn+load ONNX+embed) | **1.18s** |
| `atlas:memory:recall "<q>" --peek --json` | **6.71s** e **8.52s** (2 queries) |
| `atlas:context-pack "<q>" --workspace=… --json` | **18.67s** |
| `atlas:ai:local-rag-benchmark --json` | **87.74s** |

Causa dominante: **cada `embedText()` spawna um processo Python novo** (`PythonManifestRuntimeClient.php:45-49`, `Symfony\Process`) que recarrega o modelo ONNX (`fastembed`, `sentence-transformers/paraphrase-multilingual-MiniLM-L12-v2`, 384-d — `runtimes/python/semantic_rag/atlas_semantic_rag/embeddings.py:67-77`). Um recall embeda **a mesma query 3×** (scoreEntries, scoreVerbatims, semantic search) = 3 spawns ≈ 3.5-4s; `EmbeddingService` (`app/Services/Semantic/EmbeddingService.php`) não tem **nenhum** cache (verificado: zero `Cache::`/`remember` no caminho). O pack soma mais spawns (registry query-relevance, seções). Secundário: N+1 no `feedbackStatsForEntries` (COUNT por entry, `AtlasMemoryRecallConcentrationDemotion.php:158-165`) e `relatedConflictsForEntries` (resolver por entry, :209-213) — ~30-60 queries extra por recall; irrelevante com corpus atual, ruim quando CORP-01 crescer.

### Top-K por estágio (defaults reais)

| Estágio | K / budget |
|---|---|
| recall limit | 10 (`ATLAS_AI_MEMORY_RECALL_LIMIT`, max 50) |
| registry candidates | 3×limit = 30 (max 100); pool vetorial query-aware `max(limit*10,100)` |
| verbatim candidates | limit (min 4, max 50) |
| semantic candidates | limit (min 5, max 50); vector-K + lexical-K merged |
| compounding | cap 6, pool `max(24, cap*4)` |
| composer entrega | budget 2400 chars, item 360 chars ⇒ **~3 itens reais** (observado recall_count=3, budget usado 786) |
| pack memory sub-budget | 2000 chars (`aobg.memory_budget_chars`) |
| injection | 6 (`ATLAS_OPEN_BRAIN_INJECTION_MEMORY_RECALL_LIMIT`) |

### Corpus e sinal (medidos)

- `atlas_memory_entries`: **77** (77 com embedding); verbatim: **1**; `semantic_notes`: **169** (169 com embedding).
- Usages 45d: **56.346**. Feedback rows: **11.533** = `useful_implicit` 11.519 + `ignored_implicit` 14 + **0 explícito, 0 negativo real** (not_useful/wrong_context/stale = 0).
- Golden set RAG-05: **congelado** (25 casos, `frozen_set_id=memory_recall_golden_2026_07_rag05_seed`, fixture `tests/Fixtures/Context/memory_recall_golden/v1.json`), mas `judge=external_judge_required_before_certification` e **recall@5 = 0.0 / recall@3 = 0.0 com `expected_source_available=false` em 25/25 casos** — nenhum must_include (ref-hash) resolve no corpus de 77 entries. O certificado mede zero hoje porque os alvos não existem, não porque o ranker erra.

## Teto atual

O que limita o sistema HOJE, em ordem de dor real:

1. **Corpus, não ranking**: golden mede 0 porque 25/25 alvos estão ausentes. Enquanto CORP-01/re-hidratação não popular os alvos, nenhuma melhoria de ranking move recall@5. Anti-Goodhart: o número só pode subir por corpus+capacidade, nunca por edição do set.
2. **Latência de embed**: 3 spawns Python por recall para embedar a MESMA string; 6.7-8.5s recall / 18.7s pack numa máquina onde o embed em si custa ~30ms (o 1.18s é 97% spawn+model-load).
3. **Fusão por constantes**: escalas incomparáveis entre fontes (178/100/51) e termo de query minoritário no registry (30/178). A ordem cross-source é decidida por pesos de mão, não por relevância medida.
4. **Sinal de aprendizado degenerado**: 99.9% `useful_implicit`, zero negativo explícito — qualquer aprendizado de ranking hoje aprenderia popularidade, não utilidade (FEE-03/FEE-04 do v1 criam o sinal; o flip ainda não ocorreu).
5. **Floor sem dentes**: rank-escape faz todo item pontuado passar; o floor real é o budget (2400 chars ⇒ ~3 itens).
6. **Arm lexical primitivo**: substring containment sem stemming/PT-EN, sem BM25/tsvector — cego a morfologia e cross-language (corpus é PT+EN misto; só o braço vetorial é multilíngue).

## Fronteira

Cada técnica: veredito + porquê (local-first/provider-safe sempre; nada toca o golden v1 congelado).

| Técnica | Veredito | Porquê |
|---|---|---|
| **Caching de embed + daemon Python persistente** | **FAZER PRIMEIRO** | Maior ganho/custo do bloco inteiro. (a) Memoização intra-request da query (a mesma string é embedada 3×/recall) = eliminar 2 spawns; (b) cache persistente `hash(model+text)→vector` (embedding é determinístico por modelo; hooks re-disparam as mesmas tasks) ; (c) daemon local (unix socket/porta localhost) mantém o ONNX carregado — o receipt boundary (`real_embeddings=true`) continua o guard anti-fake. Ganho medível: recall 8.5s→~1.5-2.5s; pack 18.7s→~7-9s. Zero mudança de ranking ⇒ zero colisão com golden v1 (outputs byte-identical). |
| **Caching de recall por query_hash** | FAZER, com cuidado de usage | TTL curto (minutos) keyed `hash(workspace+query+filters+options)` + invalidação em write de memória. CUIDADO: o recall grava usage (`record_usage`) e o pack grava pre_filter/delivery (RAG-01) — hit de cache NÃO pode re-gravar usage (mentiria o sensor de dominância) nem deixar de gravar delivery real; marcar `cached=true` e gravar só delivery. Ganho: repeat-query (UserPromptSubmit re-injeta pack por prompt) cai para ~boot+DB. |
| **RRF (reciprocal rank fusion) cross-source** | FAZER como fusão v2 | Conserta a incomparabilidade de escala **de graça e sem parâmetros aprendidos**: cada fonte contribui `1/(k+rank)` — um top-1 semântico e um top-1 registry pesam igual, e o termo de query volta a mandar. Robusto com labels escassos (o caso atual). Entra atrás de `ranking_formula_v2` flag OFF; medido só pelo golden **v2** (nunca o v1). |
| **Pesos aprendidos / learning-to-rank com feedback ARFL** | ADIAR (gated por diversidade de labels) | Hoje o dataset é 11.519 positivos implícitos + 14 ignored + 0 negativos — LTR aprenderia popularidade (Goodhart clássico). Só destrava quando FEE-03/FEE-04 flipados + negative mining (abaixo) produzirem ≥N negativos reais (gate objetivo: ≥100 negativos explícitos+minerados e ≥3 feedback_actions distintos com volume). Então: regressão logística offline (features: vector_sim, lexical, scope, type, age, health, feedback ratio) em replay shadow — nunca online learning direto no caminho vivo. |
| **Cross-encoder reranker local** | VIÁVEL JÁ, valor cresce com o corpus | `fastembed 0.8.0` **já instalado no venv** suporta `TextCrossEncoder` com `jinaai/jina-reranker-v2-base-multilingual` (PT+EN, ONNX, roda em Apple Silicon CPU; alternativa leve: `Xenova/ms-marco-MiniLM-L-6-v2` EN-only). Custo: ~1-2GB de modelo em disco + ~30-80ms/par no daemon ⇒ top-30 ≈ 1-2.5s. Com 77 entries o ganho de precision é marginal (bi-encoder já separa); com corpus CORP-01 em centenas/milhares vira o maior ganho de precision@K disponível localmente. Implementar atrás de flag OFF no seam L3-6 já existente (`semanticallyReorderMemory` — trocar bi-encoder rank por cross-encoder), medir no golden v2 antes de qualquer flip. |
| **Floor calibrado por dados** | FAZER em shadow, flip gated | Substituir o par {rank-escape score>0, overlap>=2} por threshold no espaço do vector_sim calibrado por quantil da distribuição real de `used` vs `ignored/noise` (dados do COM-04/FEE-03). Invariante herdado do v1: `improper_floor_discards == 0` no golden (v1 E v2) é o teste de regressão do floor — qualquer descarte de must_include reprova. Shadow primeiro: logar o que o floor v2 descartaria sem descartar. |
| **Query expansion / reescrita local** | PARCIAL — barato sim, LLM não | O braço vetorial já é multilíngue (MiniLM-L12 multilingual); o gap é o braço LEXICAL cego a PT↔EN e morfologia. Barato e determinístico: normalização de stems + dicionário PT/EN de termos de domínio (memória/memory, cérebro/brain…) no `lexicalScore`/`relevanceTokens`. Reescrita por LLM local (Hermes) = latência +segundos e não-determinismo nos medidores — só se o golden v2 provar ganho ≥5pts de recall@5; não como default. |
| **MMR / diversificação contra concentração** | FAZER — complementa RAG-03 | A demotion atual é punitiva-reativa (multiplier 0.35 pós-dominância). MMR é preventivo: seleção final do top-K maximiza `λ·rel − (1−λ)·max_sim(já-selecionados)` usando os embeddings que já estão no pgvector; O(K²) com K=10 = desprezível. Aplicar DEPOIS da demotion (nunca substituí-la — o sensor 45d continua a autoridade); medir pelo próprio sensor de concentração pré-filtro (share do top-1 cai) com recall@5 v2 estável. |
| **Negative mining das rejeições do digest/curadoria** | FAZER — destrava o LTR | Fontes vivas que hoje viram lixo: rejeições do candidate gate (`AtlasMemoryCandidateGateService`), fila `held` do digest (FEE-12), `atlas:ai:memory:forget`, curadoria MEM-06. Cada rejeição/remoção com ref = hard negative rotulado de graça (query do contexto → entry rejeitada). Alimenta: calibração do floor + dataset LTR + validação do cross-encoder. Provider-safe por construção (refs/hashes, nunca raw). |
| **BM25/tsvector real no braço lexical** | FAZER JUNTO com RRF (mesmo slice) | pgsql já dá `tsvector` PT+EN + `ts_rank` de graça; substitui substring containment por ranking lexical honesto e resolve stemming sem dicionário na mão. Corpus pequeno ⇒ ganho hoje modesto; custo pequeno (coluna gerada + índice GIN); como braço limpo do RRF v2 faz o par léxico/semântico ficar simétrico. |

## Slices candidatos

Ordem = ordem de execução recomendada. Nenhum toca o set/medidores v1 congelados.

**MAXB-01 — Embed memo + daemon local do semantic_rag (latência, ranking intocado)** `[GÊMEO — dono: MAXA-01+MAXA-02, ver vi-b]` · E:M · deps: nenhuma do v1 — *mesmo órgão proposto pelo leitor da área B; UM landing (o dos donos) fecha os dois; os aceites abaixo rodam nesse landing*
- Goal: matar o custo de spawn — recall p50 < 2.5s, pack p50 < 9s, sem mudar 1 byte do ranking.
- Mecanismo: (1) memoização intra-request em `EmbeddingService::embedText` (mesma string → mesmo vetor no mesmo processo); (2) cache persistente `Cache::rememberForever("emb:".hash(model+text))` com invalidação por troca de modelo (a chave inclui o model do receipt); (3) modo daemon no runtime Python (unix socket local, mesmo venv, mesmo receipt boundary `real_embeddings=true` por resposta) com fallback automático ao modo spawn se o daemon não estiver de pé (fail-open idêntico ao atual).
- Aceite mensurável: `/usr/bin/time` de `atlas:memory:recall --peek` antes/depois no MESMO corpus: wall cai ≥60%; diff dos JSONs de recall (sem timestamps) = vazio; golden v1 re-rodado com números idênticos (prova de não-mudança de ranking).
- Risco: daemon morto silencioso → fallback spawn (nunca erro); cache poisoning impossível (chave = hash do texto+modelo, valor determinístico).

**MAXB-02 — Golden set v2 + medidores versionados (pré-requisito de TODA mudança de ranking)** `[GÊMEO — dono: MAXG-04, ver vi-b]` · E:M · deps: RAG-05 (v1, congelado), RAG-12, MED-01 — *o MESMO fixture `v2.json`; o MAXG-04 (live-anchored + protocolo vN + juiz por evento) é a versão canônica; UM landing fecha os dois e os aceites abaixo rodam nele*
- Goal: criar a régua da fronteira SEM tocar a régua do v1 — `memory_recall_golden_v2` com hash próprio, autor≠juiz, queries novas (nunca eco), rotulado contra o corpus PÓS-CORP-01.
- Mecanismo: novo fixture `tests/Fixtures/Context/memory_recall_golden/v2.json` (mesmo schema `atlas.memory_recall_golden_set.v1`, `frozen_set_id` novo), ≥25 casos com `expected_source_available=true` verificado no landing (o v1 nasceu com 25/25 alvos ausentes — o v2 não pode repetir isso); output do benchmark ganha chave `memory_recall_golden_v2` ao lado da v1; leitura dupla MED-01 registrada. O v1 continua rodando intocado como regressão do certificado.
- Aceite: `atlas:ai:local-rag-benchmark --json | jq '.memory_recall_golden_v2 | {cases, r5, fd, targets_available}'` → cases ≥ 25, `targets_available == cases`, hash congelado por juiz externo no ledger; `.memory_recall_golden` (v1) byte-idêntico ao baseline.
- Risco: rotular contra corpus ainda em crescimento → congelar só após CORP-01 estabilizar o estoque das pétreas.

**MAXB-03 — Fusão v2: RRF cross-source + braço lexical tsvector (flag OFF)** · E:M · deps: MAXB-02
- Goal: rank cross-source decidido por relevância (rank em cada braço), não por constantes de escala (178 vs 100 vs 51).
- Mecanismo: `ranking_formula_v2` atrás de `atlas.memory.fusion_v2_enabled` (default OFF): score final = `Σ_braços 1/(60+rank_braço)` sobre braços {vector pgvector, lexical ts_rank (coluna tsvector gerada PT+EN + índice GIN), prioridade/escopo (o ranking atual vira UM braço, preservando o valor de curadoria de priority/importance)}; demotion de concentração e feedback_factor aplicados APÓS a fusão (multiplicadores, como hoje). `formula_version` no explain de cada item (padrão COM-11).
- Aceite: phpunit novo com fixtures determinísticas (item semanticamente certo de prioridade baixa supera item de prioridade alta irrelevante SÓ com flag ON; flag OFF = byte-idêntico); golden v2 com flag ON: recall@5 ≥ v2 baseline (nunca aceitar queda); golden v1 intocado (flag OFF é o default servido até flip com ROL-01).
- Risco: RRF dilui curadoria (priority) — mitigado mantendo o ranking atual como braço; rollback trigger pré-declarado.

**MAXB-04 — MMR na seleção final do top-K (anti-concentração preventivo)** · E:S · deps: MAXB-02; convive com RAG-03/MEM-03
- Goal: o top-K entregue deixa de ter quase-duplicatas/dominantes por construção, não só por punição a posteriori.
- Mecanismo: no composer, após o sort e ANTES do greedy de budget, seleção MMR (λ≈0.7) usando embeddings já persistidos (uma query `<=>` por par candidato-selecionado, K≤10 ⇒ ≤45 comparações); só ativa com ≥2 candidatos com embedding; flag default OFF.
- Aceite: sensor de concentração pré-filtro (share do top-1 na janela 45d, régua do RAG-12) cai ≥20% relativo em shadow-replay das últimas queries reais; recall@5 do golden v2 não cai; teste unitário com 3 quase-duplicatas → só 1 no top-3.
- Risco: excluir item relevante por similaridade a outro relevante — λ alto + invariante `improper_floor_discards==0` no v2.

**MAXB-05 — Negative mining: rejeições de curadoria/digest viram labels** · E:S · deps: FEE-12 (v1 ✅), MEM-06 (v1 ✅)
- Goal: quebrar o dataset 11.519-positivos/0-negativos sem esperar só o operador clicar — cada rejeição do gate/curadoria/forget é um negativo rotulado de graça.
- Mecanismo: writer provider-safe que converte {candidate-gate reject, digest held→discarded, memory:forget, curadoria MEM-06 demote} em rows `feedback_action='mined_negative'` (novo action, FORA do negative_count de archive/inactivate — mesmo floor do FEE-03: implícito/minerado nunca mata memória sozinho) com ref+query-context-hash. Tabela de leitura: mesma `atlas_memory_entry_usages`.
- Aceite: phpunit → rejeição no gate gera exatamente 1 row mined_negative com ref correto; `mined_negative` NUNCA entra no gatilho archive/inactivate; contagem de labels negativos no corpus sai de ~0 para >0 medida por query SQL no aceite.
- Risco: minerar rejeição de ADMISSÃO como negativo de RETRIEVAL (conceitos diferentes) — separar por `label_kind` e usar só para calibração de floor/validação até o LTR ter juiz.

**MAXB-06 — Cross-encoder reranker local no seam L3-6 (flag OFF)** · E:M · deps: MAXB-01 (daemon — sem ele a latência é proibitiva), MAXB-02
- Goal: precision@K do pack acima do teto do bi-encoder, 100% local (Apple Silicon, ONNX).
- Mecanismo: operação `rerank` no runtime semantic_rag usando `fastembed.rerank.cross_encoder.TextCrossEncoder` (**fastembed 0.8.0 já instalado no venv** — modelos suportados incluem `jinaai/jina-reranker-v2-base-multilingual`, PT+EN); `SemanticContextRetrievalService` ganha modo `cross_encoder` (o seam `semanticallyReorderMemory` :2621 já existe e já é fail-open); rerank só do top-30, budget de latência hard (config, ex. 3s) com fallback à ordem atual.
- Aceite: golden v2 com modo ON: recall@5 e MRR ≥ baseline v2 e precision@3 medida sobe (registrar número no ledger antes do flip); latência do pack com daemon: p50 ≤ baseline+2.5s; modo OFF = byte-idêntico; receipt boundary continua exigindo `real_embeddings/engine_in_python`.
- Risco: com corpus de 77 entries o ganho pode ser ~0 — o aceite exige registrar o número honesto e NÃO flipar se ganho < 2pts (anti-polish); re-medir após CORP-01 crescer o corpus.

**MAXB-07 — Floor v2 calibrado por dados, em shadow** · E:M · deps: MAXB-02, MAXB-05, FEE-03 (v1 ✅) + flip do FEE-04
- Goal: floor que separa sinal de lixo por distribuição medida (used vs ignored/mined_negative), substituindo o rank-escape pass-through.
- Mecanismo: job que computa quantis de vector_sim para labels positivos vs negativos (janela 45d); threshold = quantil que mantém FN≈0 nos positivos (ex. p05 dos used); shadow mode primeiro: `would_filter_count` no provenance do pack sem filtrar nada; flip só com ROL-01 e invariante `improper_floor_discards==0` nos goldens v1 E v2.
- Aceite: phpunit com distribuições sintéticas → threshold no quantil certo; em shadow ≥7d: zero must_include do v2 marcado would_filter; após flip: `relevance_filtered_count > 0` em queries lixo reais (hoje é sempre 0) SEM queda de recall@5 v2.
- Risco: threshold global para corpus heterogêneo — começar global, por-fonte só se o shadow mostrar bimodalidade.

**MAXB-08 — LTR-lite offline: pesos da fusão aprendidos por replay (shadow only)** · E:L · deps: MAXB-02, MAXB-03, MAXB-05, FEE-04 flipado + gate de labels (≥100 negativos reais, ≥3 action-types com volume)
- Goal: os pesos do braço-fusão deixam de ser chute de mão — regressão logística local (features: vector_sim, ts_rank, scope, type, age_days, health_score, feedback ratio) treinada em pares delivered→used vs delivered→ignored/mined_negative.
- Mecanismo: treino no runtime Python local (stats-engine boundary já existe), coeficientes versionados como `formula_version=ltr_v1` em config (não em DB), avaliação EXCLUSIVAMENTE por replay offline com **held-out TEMPORAL (ELEV-30: treino e avaliação em períodos DISJUNTOS — replay no mesmo período do treino é leakage temporal que infla AUC por construção)** (AUC + recall@5 no golden v2) — o caminho vivo só troca de fórmula por flip com ROL-01; retreino manual, nunca contínuo (anti-drift/anti-gaming).
- Aceite: AUC do replay > 0.65 sobre baseline (fusão v2) E recall@5 v2 ≥ baseline; coeficientes + hash do dataset no ledger; sem melhora mensurável ⇒ NÃO flipa e registra o resultado negativo (honestidade > feature).
- Risco: overfit em corpus pequeno — mínimo de exemplos no gate; labels enviesados por posição (só top-3 é entregue) — corrigir por inverse-propensity simples ou registrar limitação.

**MAXB-09 — Cache de recall por query_hash (usage-safe)** · E:S · deps: MAXB-01 (empilha), RAG-01 (v1 ✅)
- Goal: repeat-queries dos hooks (mesma task string re-injetada por turno) custam ~0.
- Mecanismo: `Cache` TTL 5-15min keyed `sha256(workspace|query|filters|options_sem_record_usage)`; hit devolve o payload com `cached=true` e re-grava SÓ usage de delivery (nunca pre_filter de novo — senão o denominador de dominância infla); invalidação: bump de versão global em qualquer write de memória (`AtlasMemoryEntry::saved` observer) — corpus pequeno torna invalidação global barata e simples.
- Aceite: phpunit → 2ª chamada idêntica não toca `AtlasMemoryVectorSearchService` (spy) e grava delivery-usage correto; write de memória entre chamadas ⇒ miss; latência medida da 2ª chamada < 1.5s.
- Risco: servir recall stale pós-write — coberto pela invalidação por versão; usage duplo — coberto no aceite.

**MAXB-10 — Normalização lexical PT/EN (stems + termos de domínio)** · E:S · deps: nenhuma (mas MAXB-03/tsvector pode absorvê-lo — se MAXB-03 landar primeiro, reavaliar se este ainda existe)
- Goal: o braço lexical deixa de dar 0.0 para query PT vs título EN do mesmo conceito.
- Mecanismo: mapa curto e versionado de equivalências de domínio (memória↔memory, cérebro↔brain, esteira↔pipeline…) aplicado em `tokens()`/`relevanceTokens`; determinístico, sem LLM.
- Aceite: phpunit → `lexicalScore('memória recall', ['memory recall …'])` > 0; golden v2 recall@5 não cai.
- Risco: mapa vira lista infinita — cap explícito (~50 pares) e review; tsvector com dicionários PT+EN pode tornar isto obsoleto (preferir MAXB-03).

## Colisões com v1

**CRÍTICO — o golden do RAG-05 JÁ ESTÁ CONGELADO** (onda 3 ✅, commit `f39af178b`, `frozen_set_id=memory_recall_golden_2026_07_rag05_seed`, fixture `tests/Fixtures/Context/memory_recall_golden/v1.json`). Regras pétreas da fronteira:

1. **Nunca tocar o set v1 nem seus medidores.** Qualquer slice MAXB que muda ordem (MAXB-03/04/06/07/08/10) mede-se APENAS no golden **v2** (MAXB-02, hash e juiz próprios). O v1 continua rodando como regressão: os invariantes v1 que valem para sempre são `improper_floor_discards == 0` e "nunca subir número por edição do set". Detalhe honesto: o v1 hoje mede recall@5=0 com 25/25 `expected_source_available=false` — quando CORP-01 popular os alvos ele passa a medir de verdade; a fronteira NÃO pode "consertar" esse zero mexendo no set nem no medidor (só corpus+capacidade). Nota: o v1 ainda aguarda o juiz externo (`judge=external_judge_required_before_certification`) — o v2 não herda esse débito, nasce julgado.
2. **FEE-04 (default OFF, aguardando flip com ROL-01)**: MAXB-03/07/08 interagem com o feedback_factor. Ordem obrigatória: flip do FEE-04 → janela de watchdog → MAXB que consome labels. O rollback trigger `ope_04_fee_04_pack_quality` já declarado no v1 cobre o braço feedback; cada flip MAXB declara o seu ANTES do flip (padrão ROL-01).
3. **RAG-12/WDG-01 (onda 4, pendentes)**: todo monitor novo da fronteira (concentração pós-MMR, would_filter do floor v2, latência do daemon) entra como **plugin do WDG-01**, nunca check solto — mesma decisão de arquitetura do v1.
4. **MED-01 leitura dupla**: MAXB-03 (fusão), MAXB-07 (floor) e MAXB-08 (pesos) mudam medidores/fórmulas ⇒ registro dual-read obrigatório {valor_antigo, valor_novo, commit, justificativa}, e quem muda o medidor não se auto-certifica (ADV-01 re-prova).
5. **RAG-03 hysteresis + MEM-03 45d**: MAXB-04 (MMR) é camada ADICIONAL à demotion — o sensor 45d/0.35 continua a autoridade; MAXB-09 (cache) não pode re-gravar `recalled_pre_filter` em hit senão infla o denominador do próprio sensor.
6. **RAG-01 dual-point usage**: qualquer cache/curto-circuito (MAXB-01/09) preserva a semântica pre_filter vs delivery — é o feed do certificado da dimensão.
7. **Anti-Goodhart geral**: MAXB-06 e MAXB-08 têm aceite com cláusula de resultado-negativo-registrado (não flipa sem ganho ≥ mínimo declarado) — melhorar o número do medidor sem capacidade real é proibido por construção.

## Apêndice — números crus desta medição

- Modelo local: `sentence-transformers/paraphrase-multilingual-MiniLM-L12-v2` (fastembed/ONNX, 384-d); receipt `provider=fastembed_local, real_embeddings=true`.
- fastembed no venv: **0.8.0**; cross-encoders suportados: ms-marco-MiniLM-L-6/L-12, bge-reranker-base, jina-reranker-v1-tiny/turbo-en, **jina-reranker-v2-base-multilingual**.
- Índices: `ivfflat vector_cosine_ops` em atlas_memory_entries, atlas_verbatim_memories, semantic_notes; zero tsvector.
- Recall observado (query "concentração demotion recall memória"): 30 registry + 1 verbatim + 7 semantic + 0 compounding candidates → 3 entregues (786/2400 chars), scores 178.9/100.0/51.1, concentration_demoted_count=0.
- Pack observado: counts {code_graph 14, reality_paths 3, memory 3, fusion 12}; hygiene {path_filtered 2, memory_relevance_filtered **0**, ceiling_trimmed 49}.
- memory:quality snapshot (progress doc, 11/07): score=63, feedback 8539 all-neutral.

---

# MAX-C — RAG Agentic / Planejamento de Retrieval (AARF) — ground-truth + fronteira

Leitura: 2026-07-11, repo `/Users/vitorepf/develop/Atlas/atlas-server`, main @ 477ce0dc89 (+ working tree onda 3 completa).
Todos os paths são absolutos a partir de `app/` = `/Users/vitorepf/develop/Atlas/atlas-server/app/`.

## Estado atual

### Camada 1 — O "AARF" nominal é um gate determinístico de cobertura de fontes, NÃO agentic RAG (auto-confessado)

- `app/Services/Ai/Context/AtlasAgenticRagFrameworkService.php:10-35` — o docblock (correção R5) declara em código: *"despite the 'Agentic RAG' name, plan() is a DETERMINISTIC source-coverage checker"* — **NO agent, NO LLM, NO reasoning, NO iterative agentic retrieval**. O payload emite claims em banda: `is_agentic=false`, `llm_reasoning_used=false`, `iterative_agentic_retrieval=false`, `deterministic_source_coverage_only=true` (:144-147).
- `plan()` (:54-156): required/optional sources são **listas hardcoded por domain/task_type/risk** (`requiredSources()` :161-177 — ex.: dev/debug ⇒ +`code_intelligence`; high/finance ⇒ +`evidence_replay`); `critic()` (:201-235) é **set-difference sobre TIPOS de fonte declarados** (nunca sobre conteúdo); a "iteração" é 1 segunda passada determinística que **concatena os NOMES das fontes opcionais faltantes à query** (:80-97, `max_source_coverage_passes = 2`); `sufficiencyGate()` (:241-261) fail-closed para risk high/irreversible.
- `AtlasHybridRetrievalInfrastructureService.php:13-24` (AHRI) — mesmo padrão de honestidade (R4 PART B): `report()` é um **readiness/routing verdict**, não retrieval de conteúdo para o prompt. Único ponto de inteligência real: `applyLocalSemanticScores()` (:207-274) substitui o placeholder 0.60 por **cosine real do runtime Python local** (`SemanticRetrievalRuntime`), com degrade honesto (sem vetores fabricados).
- Plano de fontes: `ContextRetrievalRouter::plan()` (`app/Services/Ai/Context/ContextRetrievalRouter.php:24-84`) — **5 fontes fixas** (vector_retrieval, memory_signals, code_intelligence, evidence_replay, graph_retrieval), selecionadas por regras keyword+task_type (`needsCode` :144, `needsEvidence` :155, `needsGraph` :166), prioridade estática (:130-136), modo `audit_heavy|deep|balanced` (:216-227). `graph_retrieval` marcado `future_governed`, `available=false`.
- `AtlasGraphRetrievalNetworkService.php` (GRN) — traversal receipt com **`max_depth => 1` hardcoded** (:216), `global_graph_retrieval_active => false` — verdict, não engine.
- Consumo do gate: `AtlasAucriRuntimeEnforcementService::enforce()` → `AtlasContextRuntime::compose()` (`AtlasContextRuntime.php:86-92`) → PipelineRunExecutor, AiWorker, AtlasForgeLiveExecutionService, AtlasTaskServingService — **como pass/block ANTES da chamada de provider**, nunca como retrieval.
- Base "forte" do Programming: `app/Services/Ai/Programming/ProgrammingRetrievalPlanner.php:31-161` — parece agentic (plan→graph→pack→critic→eval→receipt) mas: `queries()` (:188-210) gera 1 "query" por fonte = `"{source} {flow} {objetivo truncado 180c}"` (**mesma string base, decoração cosmética**); `ProgrammingGapCritic::critique()` checa presença de fontes + 1 contradição estrutural (`docs_present_but_code_graph_incomplete`); as "iterations" do professionalPlan (:256-281) são um **relatório pós-fato de 3 passos, não loop real**.

### Camada 2 — O retrieval REAL do prompt (AOBG context pack) é single-shot paralelo de 3 fontes

- `app/Services/Ai/AtlasOpenBrainContextPackService.php::packFor()` (:171-338): **3 seções INDEPENDENTES, fail-open, ordem fixa code→reality→memory** (:217-219), **zero realimentação entre elas, zero iteração** — 1 chamada por fonte, corte por char budget (default 6000; `enforceTotalCeiling` :595 garante teto real com piso top-1 por seção).
  - `codeSection()` (:1492-1564): `CodeGraphContextRetriever` BM25/E-3, **query = o task inteiro**; filtros pós-hoc (noise :1571, demotion :1649, delivery policy :1695).
  - `realitySection()` (:2003-2109): AURG via `app/Services/Ai/Reality/AtlasRealityGraphQueryService.php::query()` (:119-183) — **o único multi-hop real do sistema**: BFS não-direcionada, depth default 2, `HARD_MAX_DEPTH=3` (:84), seeds **semânticos (pgvector sobre memory vectors) + lexicais** mesclados (:152-154), caps 60 nós/120 arestas/8 seeds; só paths `cross_layer` sobrevivem (:2048), com filtros anti-echo de sessão (:2131-2184).
  - `memorySection()` (:2218-2321): `AtlasHybridMemoryRetrievalService::recall()` (`app/Services/Ai/AtlasHybridMemoryRetrievalService.php:56-110`) — **4 braços** (registry, verbatim, semantic, compounding OPE-04 default-ON), blended score `0.85*vector + 0.15*lexical` (:27-29), depois filtros do pack: demotion (RAG-03), relevância lexical (:2409), budget multi-item (RAG-04, :1339). Pack usa `record_usage=false` (peek) e grava usage **na entrega** (:2284, :2294 — RAG-01).
- Fusão RRF cross-source: `AtlasRetrievalFusionService` — **default OFF** (`atlas.aobg.fusion_enabled=false`, :278-305).
- **Decomposição de query: NÃO EXISTE.** A query é o prompt inteiro contra todas as fontes. Não há reformulação semântica em lugar nenhum (a "pass 2" do AARF concatena nomes de fonte; o planner Programming decora com prefixos).
- **Detecção "não preciso buscar"**: só no hook — `.claude/hooks/atlas-ctx.sh:60-66` (dieta S4: prompt operacional single-line `ls|git status|rg…` ⇒ skip) + dedupe por hash do pack (:128-140). No PHP, `LocalPrereasoningPolicy::classify()` (`LocalPrereasoningPolicy.php:26`) existe mas mira poupar chamada de PROVIDER (count/diff/parse/classify/validate), não retrieval.
- **Roteamento por tipo de tarefa no caminho real: quase nada.** `task_type` no packFor só: (a) libera auxiliary code source types por regex (`initialAuxiliarySourceTypes` :1913-1975 — test_symbols/canonical_doc/runtime_surfaces); (b) monta flow_id p/ feedback (:1217-1245). **Os budgets code/memory são fixos por config, iguais para bugfix e arquitetura.** O roteamento por task_type que existe (ContextRetrievalRouter) vive no caminho do VERDICT, não do pack.
- **Self-check de suficiência**: `ContextPackSelfReflectionGate::assess()` (`ContextPackSelfReflectionGate.php:53-89`) existe mas (a) é consumido só por `AtlasOpenBrainContextInjectionService::selfReflection()` e `AtlasContextIntelligenceService::certifyContext()` — **o packFor/CLI/hook NÃO anexa autoavaliação**; (b) é vácuo: "insufficient" = soma de TODAS as contagens == 0 (:113-121) — **1 item já é "sufficient"**; contradição = substring `"contradiction|conflict"` (:168-176); os kernels de claim-coherence são default-OFF.
- **Loop adaptativo ENTRE packs (não DENTRO)**: `contextDeliveryPolicy()` (:691-990) lê `AiRagFeedbackEvent` (janela 168h, 20 eventos), deriva budget multiplier / expand_source_types / defer / demote — pós COM-05 **só bebe measured=true** (senão `insufficient_signal` e multiplier 1.0). ARFL `AtlasRetrievalFeedbackLoopService::capture()` (:45) grava used/noise/missed vs pack ENTREGUE (join pelo ledger COM-01) + `nextRetrievalHint()` (:606) consumido por `AtlasContextRankingSystemService` (:788-930, hints globais cap 50, mínimo 2 eventos).

### Medição (derivada do código; NÃO rodei `atlas:context-pack` — a execução grava usage rows + delivered-pack ledger e contaminaria as séries RAG-01/RAG-12 em curso)

| Pergunta | Resposta ground-truth |
|---|---|
| Fontes por pack típico | 3 seções sempre tentadas; fisicamente ~7 consultas: 1 code-graph BM25 + 2 famílias de seed AURG (semântica+lexical) + BFS + 4 braços de memória |
| Ordem | Fixa: code → reality → memory (:217-219); independentes, sem dependência de resultado |
| Hops | 1 por fonte; exceção AURG: BFS depth≤2 (cap 3) DENTRO do grafo. Cross-source hop = 0 |
| Reformulação | 0 |
| Critério de parada | Char budget only. Sem ganho-marginal, sem sufficiency-driven stop |
| "Não buscar" | Só heurística de shell no hook (single-line ops) + dedupe |
| Sufficiency declarada | As constantes fabricadas 92/62/28 e 86/58/32 do `contextRoi` foram **ABOLIDAS pelo COM-04** (commit c093a5fb5); hoje `context_sufficiency => null` sempre (`AtlasRetrievalFeedbackLoopService.php:263`) e utility só explícita (COM-11, be0eec499). O gap "declarou suficiente vs 18.5 de utilidade real" era o estado PRÉ-COM-04; o número fabricado morreu, mas **nenhum sensor de suficiência honesto nasceu no lugar** — o pack continua mudo sobre a própria cobertura |

## Teto atual

1. **Single-shot por construção**: nada re-busca quando o pack sai fraco; um pack com 1 memória irrelevante e 0 code hits é entregue igual a um pack denso — o consumidor não tem como distinguir sem ler tudo.
2. **Query monolítica**: task de 3 frases dilui BM25 e o vetor; símbolo citado no meio do prompt compete com prosa. (`RELEVANCE_STOP_TERMS` :127-133 é o único anti-diluição, e é uma stop-list de 18 termos.)
3. **Self-check vácuo e não entregue**: o gate diz "sufficient" com 1 item, e nem esse veredito viaja no pack do hook/CLI.
4. **Roteamento por tarefa só em verdict-land**: o pack real trata bugfix = arquitetura = pergunta operacional (fora a dieta do shell).
5. **Cross-source hop = 0**: AURG já usa memory-vectors como seeds (hop semântico→grafo DENTRO do AURG), mas code hits nunca semeiam o grafo, e paths do grafo nunca re-hidratam memória/código no mesmo pack.
6. **Parada adaptativa inexistente**: budget é teto, não política.

O que o v1 (98 slices, ondas 0-3 LANDED; onda 4-5 pendente) já resolveu neste eixo: **medição honesta** (RAG-01 entrega, RAG-02/MEM-03 janelas, COM-01 ledger, COM-04 abolição de constantes, COM-11 utility explícita), **anti-dominância** (RAG-03), **golden set congelado** (RAG-05, 25 casos, author≠judge), **feedback→ranking/política measured-only** (COM-02/05/06/07/08, FEE-04/11). O v1 **não** constrói: decomposição, multi-hop cross-source, sufficiency in-pack, roteamento por tarefa no pack, parada adaptativa. É exatamente o ALÉM.

## Fronteira

Régua invariável para todas: local-first (pgvector/BM25/BFS/regex — o "agente" do retrieval é determinístico; LLM local só motor opcional default-OFF), provider-safe (nada de raw query em ledger — invariante `no_raw_query_or_context_in_report_or_ledger` já existe), anti-Goodhart (nenhum número novo entra em `memory:quality`; medição = série NOVA + golden set congelado como régua externa).

### 1. Decomposição de pergunta em sub-queries (determinística + LLM local opcional)
**Veredito: SIM (determinística agora; LLM local só depois de medir).**
Extração determinística de FACETAS da query: símbolos CamelCase/`Fqcn::method`, paths, comandos `atlas:*`, termos entre aspas, e split por conjunções — cada faceta vira sub-query roteada à fonte apta (símbolo→code-graph; frase→vector; comando→runtime_surfaces). Já existe meio-caminho no repo: `initialAuxiliarySourceTypes()` (:1936-1972) e `seedTerms()` do GRN (:249-263) tokenizam por padrões — a faceta é a generalização com dono único.
- Ganho mensurável: recall@5/recall@3 no golden set RAG-05 CONGELADO (A/B single-query vs multi-facet, mesmo set, `improper_floor_discards == 0` mantido).
- Custo: +N consultas locais (pgvector/BM25, ms); zero provider.
- Medição honesta: o A/B roda em modo peek (`record_usage=false`) para não inflar séries de usage; nunca editar o golden set para passar.
- LLM local (Hermes/GLM): flag default-OFF, motor não-espinha; ganho hipotético — só considerar se a versão determinística deixar facetas mensuráveis na mesa.

### 2. Multi-hop cross-source guiado pelo AURG (hop-1 vira semente do hop-2)
**Veredito: SIM, estreito e budgetado.**
O AURG já é "semente semântica → BFS ≤2" internamente. O que falta é o hop cross-source: refs top-K do pack hop-1 (memory entry ids, code file paths) viram **seeds explícitos** de uma segunda query AURG (o serviço já é parametrizável por opts; hoje não aceita seed refs externos — extensão pequena), e nodes descobertos com `source_kind ∈ {memory_entry, doc, code_file}` re-hidratam itens numa seção `graph_expansion` **sob o MESMO teto de chars** (`enforceTotalCeiling` cobre por construção).
- Ganho mensurável: % de packs onde o hop-2 adiciona ref que o feedback marca `used` (join pelo ledger COM-01 — infra já existe) — série nova.
- Custo: +1 query AURG (BFS bounded, DB local); latência ~dezenas de ms.
- Medição honesta: hop-2 default-OFF → shadow (registra o que TERIA adicionado, sem entregar) → live; a régua de promoção é used-rate medido, não contagem de refs.

### 3. Self-check de suficiência ANTES de entregar o pack
**Veredito: SIM — o maior gap honesto do eixo.**
Bloco `sufficiency` DETERMINÍSTICO anexado ao pack: (a) **cobertura de facetas** (das sub-queries do item 1: quantas facetas têm ≥1 ref entregue; quais ficaram a zero); (b) **força de sinal crua** (top cosine real / BM25 top score — os scores já existem em `_recall_score` :2267 e no semantic reorder); (c) declaração explícita `not_enough_context` + lista de facetas sem hit + handles `expand:*` correspondentes (:1251-1266). **Não** emitir um escalar "suficiência = X" — cobertura crua e faltas nomeadas; o escalar é o que virou 92 fabricado da última vez.
- Ganho mensurável: calibração ex-post — correlação entre `not_enough_context` declarado e outcome do ARFL (`measured=true` only): packs declarados insuficientes devem ter used-rate/utility MENORES; se não tiverem, o sensor é ruído e sai.
- Custo: zero consultas novas (agrega dados que o pack já tem).
- Medição honesta: o bloco NUNCA entra no `memory:quality` score nem preenche `context_sufficiency` do evento ARFL (campo aposentado pelo COM-04 — reocupá-lo seria ressuscitar o número fabricado pela porta dos fundos). Substituir também o miolo vácuo do `ContextPackSelfReflectionGate::isInsufficient()` (soma==0) pela leitura do bloco, atrás de flag.

### 4. Roteamento por tipo de tarefa (bugfix ≠ arquitetura)
**Veredito: SIM, como PRIOR de budget compondo com a política medida (COM-05), nunca competindo.**
Perfis determinísticos pequenos: `bugfix/debug` (code+evidence pesados, memória filtrada a incident/harness_learning), `arquitetura/planning` (reality/AURG + canonical_doc + memória decision), `operacional` (dieta, pack mínimo). Detecção: reusar o léxico já provado de `needsCode/needsEvidence/needsGraph` (ContextRetrievalRouter :144-171) — movendo a DECISÃO para o caminho real (multiplicadores de sub-budget no packFor), não duplicando o router.
- Ganho mensurável: used-rate por perfil vs baseline única (série COM-07 política→ROI já persiste o par política/ROI — o perfil entra como dimensão nova do mesmo registro).
- Custo: zero consultas novas; só realocação dentro do teto.
- Medição honesta: perfil = prior; a source_selection_policy do feedback (COM-05) corrige por cima; `insufficient_signal` ⇒ prior puro; nenhuma fonte zerada (piso top-1 por seção preservado).

### 5. Parada adaptativa por budget/ganho marginal
**Veredito: PARCIAL — só onde existe iteração para parar.**
Sem os itens 1-2 não há o que parar (single-shot). Com eles: contador simples no orquestrador — para de rodar sub-queries/hops quando a última consulta trouxe `< X%` refs únicos novos ou o budget de consultas (não só chars) esgotou. Implementar como 10 linhas no orquestrador, não como framework. **NÃO** construir "utility prevista por ML" — sem dados para treinar e é o caminho do proxy.

### 6. Hooks AOBG (UserPromptSubmit) como ponto de injeção do plano de retrieval
**Veredito: SIM, custo ~zero, fecha o loop com o agente.**
O hook (`atlas-ctx.sh`) já injeta o markdown do pack. Adicionar ao markdown renderizado: a linha de sufficiency (item 3) + facetas sem hit + os handles `expand:<source>` / `recheck:canonical_doc` já existentes e o manifest de progressive disclosure (:548-565). Resultado: o modelo SABE o que o cérebro não achou e tem a ação nomeada para pedir mais — "não achei o bastante" vira comportamento do agente, não silêncio.
- Ganho mensurável: taxa de invocação dos handles via telemetria MCP OPE-05 (já landed) antes/depois.
- Custo: bytes no markdown; atenção: muda o hash do dedupe S4 do hook (ok, registrar).
- Medição honesta: se os handles não forem usados em N semanas, o bloco sai do markdown (reversível ROL-01-style).

### Recusados (anti-Goodhart / anti-bloat)
- **Re-rank neural local no caminho quente**: o semantic reorder L3-6 já existe flag-gated; empilhar cross-encoder local sem prova de gap de ranking (golden set já mede) é polish especulativo.
- **Escalar único de suficiência**: repetiria o 92 fabricado.
- **LLM (mesmo local) na espinha do plano de retrieval**: viola a regra motor-opcional-nunca-dependência; tudo acima fecha determinístico.
- **Novo framework de "agent loop"**: os 6 itens são extensões de seams existentes (packFor, AURG query, hook, ledger); um AgentLoop novo seria a quarta reencarnação do problema AARF (nome grande, runtime verdict).

## Slices candidatos

**MAXC-01 — Facet decomposition determinística no packFor** · E:M · deps: [**MAXB-02 (golden v2 = régua do A/B — ELEV-01)**, RAG-05 (só regressão de floor), RAG-01 (semântica peek)]
- Goal: query monolítica vira K facetas tipadas (símbolo/path/comando/frase) roteadas por fonte; recall deixa de ser diluído por prosa.
- Mecanismo: extrator puro (classe única, zero deps) `TaskFacetExtractor` — CamelCase/FQCN/paths/`atlas:*`/aspas/split por conjunção; `memorySection`/`codeSection` consultam por faceta com `record_usage=false` nas passadas extras, dedupe por ref antes da entrega; entrega final grava usage 1× (semântica RAG-01 intacta). Flag `atlas.aobg.facet_retrieval` default-OFF → shadow → live.
- Aceite (ELEV-01): A/B no **golden v2** (MAXB-02, `targets_available == cases` — no RAG-05 v1 os 25/25 alvos são irresolvíveis e `0 >= 0` seria verde vácuo): `recall_at_5(facet) >= recall_at_5(baseline)` com denominador resolvível, e `improper_floor_discards == 0` nos DOIS goldens (v1 e v2); suite phpunit do extrator (fixtures determinísticas); zero rows de usage nas passadas peek (asserção).
- Effort: M.

**MAXC-02 — Bloco `sufficiency` honesto no pack (cobertura de facetas + not_enough_context)** · E:S · deps: [MAXC-01]
- Goal: o pack sabe dizer "não achei o bastante" — com faltas NOMEADAS e handles de expansão, sem escalar fabricável.
- Mecanismo: agregador pós-`enforceTotalCeiling`: por faceta {refs_delivered, top_score_real, source}; facetas a zero listadas + handle `expand:*` correspondente; `not_enough_context=true` quando ≥1 faceta essencial (símbolo/path citado pelo operador) está a zero. Campo novo no pack + markdown; PROIBIDO escrever em `context_sufficiency` do evento ARFL e no memory:quality.
- Aceite: phpunit: query com símbolo inexistente ⇒ `not_enough_context=true` + faceta nomeada; query coberta ⇒ false; `rg 'context_sufficiency' app/Services/Ai/AtlasOpenBrainContextPackService.php` não ganha writer novo.
- Effort: S.

**MAXC-03 — Task-profile priors de budget no packFor** · E:S · deps: [COM-05, COM-07]
- Goal: bugfix busca diferente de arquitetura no caminho REAL (multiplicadores de sub-budget), compondo com a política medida.
- Mecanismo: 3 perfis determinísticos (debug/planning/ops) detectados pelo léxico já provado do ContextRetrievalRouter; aplicados como prior de `budget_multipliers` ANTES da source_selection_policy (COM-05) — feedback measured corrige por cima; piso top-1 por seção inviolável; perfil registrado no par política→ROI (COM-07) como dimensão.
- Aceite: phpunit: task debug ⇒ code/evidence priors > 1.0 e memória decision filtrada; política measured sobrepõe prior quando presente; `insufficient_signal` ⇒ prior puro; used-rate por perfil consultável na série COM-07.
- Effort: S.

**MAXC-04 — Hop-2 cross-source AURG com parada por ganho marginal** · E:M · deps: [MAXC-02, COM-01, RAG-08 (camada DOC no AURG)]
- Goal: resultado do hop-1 (memory ids + code paths entregues) semeia uma segunda travessia AURG; nodes cross-layer novos re-hidratam o pack sob o mesmo teto.
- Mecanismo: `AtlasRealityGraphQueryService::query()` ganha `opts['seed_refs']` (ids/paths → nodes por lookup direto, caps existentes intactos, `provider_bound=true` não-relaxável); orquestrador no packFor roda hop-2 SÓ quando `not_enough_context=true` (MAXC-02) OU perfil arquitetura (MAXC-03); parada: novos refs únicos < 20% ou 1 hop extra no máximo. Shadow primeiro (registra o que adicionaria no delivered-pack ledger com marker, sem entregar).
- Aceite: phpunit BFS com seed_refs respeitando HARD caps; em shadow ≥ N packs: used-rate dos refs hop-2 (join ledger COM-01/feedback measured) reportado; promoção a live só se used-rate hop-2 ≥ used-rate médio do pack (senão o hop é ruído e morre — registrado no ledger de gaps).
- Effort: M.

**MAXC-05 — Injeção do plano de retrieval no hook AOBG (sufficiency + handles no markdown)** · E:S · deps: [MAXC-02]
- Goal: o agente (Claude Code/Codex via MCP) vê o que o cérebro NÃO achou e tem ação nomeada para expandir — "não achei" vira comportamento, não silêncio.
- Mecanismo: `renderMarkdown()` (:2814) ganha seção curta `## Suficiência` (facetas sem hit + handles `expand:*`/`recheck:*` + workflow progressive disclosure já existente); hook inalterado (transporta markdown). Dedupe S4 continua por hash (nota: hash muda — esperado).
- Aceite: dry-run do hook (`echo '{"prompt":...}' | atlas-ctx.sh`) mostra a seção só quando `not_enough_context=true`; telemetria OPE-05: taxa de chamadas expand/recheck antes/depois em 2 semanas (número informativo, não meta).
- Effort: S.

**MAXC-06 — Calibração ex-post do sensor de suficiência (verdade vs declaração)** · E:S · deps: [MAXC-02, COM-04, COM-11, WDG-01 (quando existir)]
- Goal: provar que `not_enough_context` prevê utilidade baixa — ou matá-lo. O sensor que não calibra é ruído institucionalizado.
- Mecanismo: check plugável (formato WDG-01; até WDG-01 landar, comando read-only `atlas:context:sufficiency-calibration --json`): join packs entregues (ledger COM-01, que carrega o bloco) × eventos ARFL `measured=true`; reporta used-rate/utility médios nos grupos declarado-insuficiente vs declarado-coberto, com {measured_count, total_event_count} (floor X-10e respeitado).
- Aceite: comando retorna os 2 grupos com denominadores expostos; janela sem massa ⇒ `insufficient_signal` (nunca 100); regra pétrea escrita no output: separação ausente após N eventos measured ⇒ issue no ledger de gaps recomendando remoção do bloco (o slice declara o próprio critério de morte).
- Effort: S.

**MAXC-07 — Decomposição LLM-local opcional (motor, não espinha)** · E:M · deps: [MAXC-01 medido, MAXC-06]
- Goal: facetas semânticas que o determinístico não extrai (paráfrases, intenção implícita) — SOMENTE se MAXC-01 deixar gap mensurável.
- Mecanismo: adapter atrás de `atlas.aobg.facet_llm_local` default-OFF chamando runtime local (Hermes/GLM local) com contrato: output = lista de strings de sub-query, validada e capada; falha/ausência ⇒ determinístico puro (degrade honesto, padrão applyLocalSemanticScores).
- Aceite: mesmo A/B do MAXC-01 no golden set congelado: só promove se `recall_at_5(llm) > recall_at_5(facet determinístico)` com custo de latência reportado; OFF ⇒ byte-idêntico.
- Effort: M. (Candidato a corte se MAXC-01 fechar o gap — declarado desde já.)

Ordem sugerida: MAXC-01 → 02 → 03/05 (paralelos) → 04 → 06 → (07 condicional).

## Colisões com v1

| Seam | Slice v1 (estado) | Regra de não-colisão para MAXC |
|---|---|---|
| `recordRecallUsages` no ponto de ENTREGA (`AtlasOpenBrainContextPackService.php:2284,2294`) | RAG-01 ✅ [MEDIDOR] | Sub-queries/hops extras rodam **peek** (`record_usage=false`); só a entrega final grava 1×. Violação = inflar usage e a janela 45d do MEM-03 |
| Janelas 30d (retrieval_eval) / 45d (concentração) e fórmula `:698-702` de AtlasMemoryQualityService | RAG-02 ✅, MEM-03 ✅ [MEDIDOR] | MAXC não toca fórmula nem janelas; todo número novo é série NOVA com nome próprio; mudanças de medidor (se houver) passam por MED-01 dual-read |
| Anti-dominância + wiper-guard conceito estável (`AtlasMemoryRecallConcentrationDemotion`, `_demote_context_refs` :186-189) | RAG-03 ✅ | Multi-facet pode fazer a MESMA entry vencer em N sub-queries — o dedupe por ref pré-entrega e o contador pré-filtro continuam obrigatórios; facet retrieval não cria canal novo de demotion |
| Golden set congelado (`atlas.memory_recall_golden_set.v1`, author≠judge, hash no ledger) | RAG-05 ✅ | Régua de TODOS os A/B MAXC; **nunca editar** para passar; casos novos de decomposição ⇒ set ADICIONAL separado com juiz externo e hash próprio |
| Ledger do pack entregue + namespace canônico de refs | COM-01 ✅ (dono único) | `graph_expansion` (MAXC-04) e o bloco sufficiency (MAXC-02) usam o MESMO namespace de refs; zero formato paralelo |
| Abolição de constantes fabricadas; `context_sufficiency=null`; utility explícita + formula_version | COM-04 ✅, COM-11 ✅ [MEDIDOR] | MAXC-02 **não** preenche `context_sufficiency` do evento ARFL nem cria escalar de suficiência — cobertura crua + faltas nomeadas apenas. Reocupar o campo = ressuscitar o 92 fabricado |
| Política adaptativa measured-only (`source_selection_policy`, budget multipliers) | COM-05 ✅, COM-07 ✅, COM-08 ✅ | MAXC-03 é PRIOR composto por baixo da política medida; `insufficient_signal` ⇒ prior puro; nunca zera fonte (piso top-1 do enforceTotalCeiling) |
| Demotion measured-only | COM-02 ✅ | Facetas não geram demotion própria; só o canal existente |
| Watchdog unificado + certificadores | WDG-01/RAG-12/COM-10 ⬜ (onda 4 pendente) | Checks MAXC (06) nascem no formato plugin WDG-01 — nunca job agendado paralelo; até WDG-01 landar, comando read-only |
| Re-prova adversarial externa | ADV-01 ⬜ (onda 5) | Aceites MAXC congelados em teste ⇒ re-prováveis pelo mesmo mecanismo; nenhum aceite "verdade vácua" (todos têm caso negativo) |
| Hook dieta S4 + dedupe por hash | (Obra #19, fora do v1) | MAXC-05 muda o hash do CTX ⇒ dedupe hits caem; comportamento esperado, registrado |
| Relógios/cadência: `fable:delta-series`→`acos` (EVI-09), séries contíguas EVI-05/06 | EVI-* ✅ | Séries MAXC novas seguem o mesmo padrão de contiguidade/anti-backfill; nenhuma série existente é reiniciada |
| Compactação/token economy | CPT-01..08 ✅ | MAXC realoca DENTRO do teto de chars existente; defaults de budget intocados |

---

# MAX-D — Grafo de Conhecimento (AURG + travessia; blocos AGRN/AURG do ACOS)

Ground-truth lido do código em 2026-07-11. Números vivos de `php artisan atlas:aurg:status --json` (18:25 UTC).

## Estado atual

### Store e schema
- Tabelas: `atlas_aurg_nodes` / `atlas_aurg_edges`.
- Nó (`app/Models/AtlasAurgNode.php:16`): `id` determinístico `"<source_kind>:<kind>:<source_id>"` (construído em `AtlasRealityGraphIngestionService::nodeKey`, `app/Services/Ai/Reality/AtlasRealityGraphIngestionService.php:1973`), `kind`, `source_kind` (memory/code/doc/domain/evidence/strategic + mission/obra), `source_id`, `label` (sempre redigido/provider-projection), `workspace_id`, `provider_safe`, `sensitive`, `meta` (json), `content_hash`. O brain guarda REFS, nunca payloads.
- Aresta (`app/Models/AtlasAurgEdge.php:21`): única por `(from,to,kind)`; `source` nomeia o produtor determinístico; `confidence` em escada fechada 1.0 (id/path exato) / 0.7 (derivado) — nunca output de modelo (`AtlasRealityGraphIngestionService.php:93-95`); carrega Temporal Truth (`valid_from`/`valid_until`/`stale_after`/`superseded_by`/`authority_level` via `HasTemporalTruth`, SIS4) — `valid_from` estampado no create (`AtlasAurgEdge.php:41-49`).

### Ingest (como o AURG é construído)
`AtlasRealityGraphIngestionService::sync()` (`:118-177`), idempotente, upsert por chave determinística, `--prune` por `source_kind`. Fontes (`SOURCES`, `:91`):
- **memory** (`gatherMemory` `:186`): últimas 500 entries ativas não-supersedidas + verbatims `external_ai_allowed`; label SÓ da projeção redigida (`AtlasMemoryPrivacyService`); extrai `meta.paths` (metadata/tags + paths citados no texto da projeção, `providerProjectionPaths` `:2027`) e `meta.domains` (taxonomia estrita, `:2114`).
- **code** (`gatherCode` `:998`): 1 nó workspace + top-300 módulos por workspace de `atlas_engineering_code_modules` — **módulos APENAS, nunca símbolos** (docblock `:34-37`: "the brain holds a bounded projection, never the symbols").
- **docs** (`gatherDocs` `:1088`): varre `docs/engineering-knowledge-base/**/*.md` (cap 2000), nó por doc com `meta.paths` (paths repo citados + `file_exists`, `:2064`) e `meta.memory_refs` (UUID/ULID citados, `:2092`). — *Este é o RAG-08 já aterrissado.*
- **domains** (`gatherDomains` `:1153`): 21 domínios canônicos do `CrossDomainTaxonomyMap` + arestas allowed-crossing do mesh existente; domínios sensíveis nascem `provider_safe=false`.
- **evidence** (`gatherEvidence` `:1216`): últimos **200** eventos do Evidence Ledger (`atlas_ledger_events`) como refs (ids/hashes/trace/correlation/receipt + paths citados no payload) — nunca payload.
- **strategic** (`gatherStrategic` `:1278`): entidades ASRE ativas (decay 14d honrado) + relationships como arestas.
- **Escritas vivas fora do sync**: `ingestMemoryEntry` (F4 on-write, `:333` — chamado do write-path do `AtlasMemoryRegistryService`, fail-open), `recordMissionOutcome` (`:420` — nó mission + nó evidence + arestas generated/references com touched_paths→módulo pela MESMA escada), `recordObraOutcome` (`:593` — obra→evidence + obra→step-missions).

### Linkers cross-layer (todos determinísticos, cite-or-omit; rodam sobre o estado do DB ao fim do sync, `:158-165`)
| Linker | Arquivo:linha | Liga | Regra | Vivo hoje |
|---|---|---|---|---|
| `linker_memory_code` | `:1376` | memory→module `references` | path==root 1.0 / prefixo 0.7 / token-label==slug 0.7, cap 10/nó | **10 arestas** |
| `linker_memory_domain` | `:1467` | memory→domain `belongs_to` | resolução exata na taxonomia 1.0 | **51** |
| `linker_evidence` | `:1544` | evidence→memory/module `proves` | target_id/memory_ref==source_id 1.0; path→root 1.0/0.7 | **0 arestas** |
| `linker_code_domain` | `:1614` | workspace→engineering `belongs_to` | 1.0 por construção | 1 |
| `linker_doc_code` | `:1639` | doc→module `references` | paths citados no doc (file_exists) vs root 1.0/0.7 | **2.203** |
| `linker_doc_memory` | `:1704` | doc→memory `references` | UUID/ULID citado == memory node 1.0 | **0 arestas** |
Fronteira do store também é cite-or-omit: aresta só grava se AMBOS os endpoints existem; dedup mantém a maior confiança (`upsertEdges` `:1800-1861`).

### Travessia / recall (o retrieval vivo do grafo)
`AtlasRealityGraphQueryService::query()` (`app/Services/Ai/Reality/AtlasRealityGraphQueryService.php:119`):
1. **Seeds híbridos** (cap 8): semânticos = pgvector cosine sobre as ROWS-fonte de memória via `AtlasMemoryVectorSearchService` (`:199-266`; sem engine → vazio honesto, nunca fake) + lexicais = LIKE por termo em label+meta re-pontuado por contagem literal em PHP (`:276-350`), com rebaixamento de ruído mission/evidence em packs provider-bound (`lexicalSeedQuality` `:880`).
2. **BFS não-direcionado** dos seeds, profundidade default 2, **HARD cap 3**, max 60 nós/120 arestas, fetch 2.000 arestas/camada (`:401-539`). `provider_bound` é estrutural: nó excluído nunca é atravessado, então tudo alcançável só por ele fica inalcançável por construção (`:502,541`).
3. **Paths**: cada nó alcançado carrega a cadeia nó→aresta→nó até o seed, com flag `cross_layer` quando cruza source_kind (`:581-630`) — a unidade que o pack consome.
4. **Ranking**: acima de 12 nós, delega ao runtime Python networkx (`GraphRankRuntimeClient`, `app/Services/Ai/RuntimeBoundary/GraphRankRuntimeClient.php:31`; runtime em `runtimes/python/graph_rank/`) — degree centrality + propagação por aresta + match textual; fallback HONESTO = ordem de inserção com flag `unranked_*` (`:661-746`). **O runtime só expõe `operation: rank`** (`runtimes/python/graph_rank/atlas_graph_rank/contract.py:27`) — reordena o conjunto que o BFS colheu; nunca muda o conjunto.
- Custo por consulta: ≤3 rodadas SQL de arestas + fetch de nós + 1 subprocess Python sobre ≤60 nós. Milissegundos-a-segundo.

### Consumo no pack
`AtlasOpenBrainContextPackService::realitySection` (`app/Services/Ai/AtlasOpenBrainContextPackService.php:2004`): UMA chamada `query()` por pack, `provider_bound=true` inegociável, mantém só paths `cross_layer`, derruba echo de sessão (`isSessionArtifactPath`) e paths doc-mission, sanitiza labels (label de grafo = texto NÃO-confiável). Flag viva: `injection_include_reality_graph=true`.

### AGRN (o nome vs a coisa)
`AtlasGraphRetrievalNetworkService` (`app/Services/Ai/Context/AtlasGraphRetrievalNetworkService.php:21`) **não é retrieval de prompt**: é o CHECK de prontidão do AUCRI — veredito/recibo de travessia (`max_depth: 1`, tudo hasheado) sobre o `WorldModelGraphRanker` (grafo `ai_codebase_world_model_*`: **59 nós/125 arestas**), consumido pelo enforcement AUCRI. A travessia real de recall é a do `AtlasRealityGraphQueryService` acima.

### Temporal
`AtlasUnifiedRealityGraphTemporalService`: cadeia hash append-only (`prev_tick_hash`→`tick_hash`, `:122-183`, verificação `:311`); tick de snapshot REAL pós-sync com `snapshot_hash` derivado do estado (ingestion `:898-989`, estado-determinístico); `AtlasGitHistoryTemporalProducerService` emite ticks bi-temporais por commit. Vivo: **5.264 ticks, cadeia íntegra, 26 snapshot ticks**.

### Agendamento e caps
Sync diário `atlas:aurg:ingest --prune --json` 05:50 (`routes/console.php:121-125`). Caps em `config/atlas.php:4343-4383` (memory 500, evidence 200, modules 300/ws, max_nodes 5.000, query 60/120/depth2, rank_threshold 12, ingest_on_write ON).

### Relação com Code Intelligence — por que dois grafos?
Separados **por design** (docblock do ingest `:34-37`): o AURG é o cérebro pequeno e provider-safe (centenas-a-milhares de nós, snapshot-hasheável, privacy estrutural); o Code Intelligence é o read-model gordo:
- `atlas_engineering_code_symbols`: **290.098** símbolos (cresceu desde os 219.842 da auditoria);
- `atlas_engineering_doc_links`: **231.082** links doc→código (era 189.581) — `symbol_path` 224.420, `module_capability` 4.337, `module_path` 2.325; colunas `knowledge_item_id/module_id/symbol_id/link_type/canonical_path/target_path/hashes` — índice pronto, com hash de citação por link;
- `atlas_engineering_code_modules`: 62 (a ÚNICA fatia que entra no AURG);
- terceiro grafo: `atlas_docs_authority_graph` (**6.201** arestas doc→doc, usado pelo `AtlasFeaturePlacementService`); quarto: world model AUCRI (59/125).
**Ponte construída mas NÃO ligada**: `CodeGraphUnifiedView` (`app/Services/Engineering/CodeGraph/CodeGraphUnifiedView.php` — "code edge → the node it touches → the AURG edges that govern it") + `CodeGraphRealityIngestionService` têm **0 callers fora dos próprios testes** — órgão unwired.

### Números vivos (18:25 UTC — o estado da auditoria está STALE)
- Nós **1.848** (doc 1.034, mission 448, evidence 200, memory 64, code 63, domain 21, obra 18); arestas **2.934** (references 2.566, generated 254, belongs_to 114). O "586/637, ~1 aresta de linker" da auditoria morreu: **RAG-07/08 já aterrissaram** (camada doc + 2.203 arestas doc→code + backfill: memory_cross_layer_coverage_ratio **0,6094** ≥ alvo 0,60).
- `linker_evidence` **ausente** de `edges_by_source` (=0) e `linker_doc_memory` =0 — dois linkers cite-or-omit que não acham nada no dado real.
- Órfãos: 233. provider_safe 1.842/1.848. A concentração migrou de missões (25%) para **docs (56%)**.

## Teto atual

1. **Retrieval = bola BFS**: raio ≤3 a partir de ≤8 seeds; o ranking Python só REORDENA o que o BFS colheu — zero noção global de estrutura; um nó central a 4 hops nunca aparece; seed errado = pack errado.
2. **Frescor e autoridade não pontuam**: arestas têm `valid_from`/`authority_level`, nós têm `updated_at` — nada disso entra no ranking; uma memória de 2025 e uma de ontem pesam igual.
3. **Evidence é janela rolante amnésica**: 200 eventos + prune diário ⇒ nó de evidence some do grafo MESMO com arestas (o prune mantém só os ids do gather corrente, `:149-153` + `:1870`), apagando proofs antigos; e `linker_evidence`=0 porque o match exige `target_id`/path no payload — os ids que o ledger REALMENTE carrega (trace/correlation/receipt) não são usados para linkar.
4. **Símbolo fora do grafo por design, sem drill-down**: o pack tem seção code separada, mas nenhuma travessia cruza módulo→símbolo; os 231k links doc→código do índice existente não alimentam nada no AURG (o `linker_doc_code` re-deriva por regex o que o índice já sabe com hash de citação).
5. **Runtime Python subutilizado**: networkx presente, mas `VALID_OPERATIONS={"rank"}` — sem PageRank personalizado, sem comunidades, sem caminhos ponderados.
6. **Uma chamada de grafo por pack, seed por LIKE/vetor**: pergunta que cita `app/Services/X.php` ou um FQCN não vira seed por ID exato de nó — vira tokens.
7. **Docs re-lidos inteiros a cada sync** (1.034 arquivos + hash) — barato hoje, cresce linear com o corpus (CORP-01 vai crescê-lo de propósito).

## Fronteira

**Local-first é dado**: o grafo tem ~2-3k nós — QUALQUER algoritmo de grafo clássico roda em milissegundos no networkx já instalado. O limite não é compute, é honestidade de aresta e medição.

1. **Linkers automáticos densos**
   - *doc↔código importado do índice de 231k links* → **SIM, prioridade máxima**. O trabalho já foi feito e auditado (`atlas_engineering_doc_links` com `link_hash` por citação); agregar `symbol_path` a nível de módulo (via `module_id`/`symbol.module_id`) e upsert como arestas com `source='linker_doc_code_index'` e meta citando `link_hash`. Cite-or-omit preservado (a citação é o row do índice). Ganho mensurável: % de docs com ≥1 aresta código (hoje ~2.203 arestas para 1.034 docs, muitos com 0); custo: 1 query agregada por sync. Anti-Goodhart: contador separado em `edges_by_source`, nunca somado ao gate RAG-10.
   - *evidência↔tudo por trace* → **SIM**. Match exato `receipt_id`/`trace_id`/`correlation_id` do evento contra `meta.receipt`/`receipt_hash` dos nós mission/obra (ambos já no store). Conserta o `linker_evidence`=0 com id exato 1.0 — zero adivinhação.
   - *memória↔código por co-citação em missões* → **SIM, com cerca**. Derivação 2-hop determinística: memory ←references— mission —references→ module ⇒ memory—module, edge kind próprio (`co_cited`), rung novo **0.5** na escada, meta citando o mission id testemunha, cap por nó. Cerca anti-Goodhart: EXCLUÍDO do `memory_cross_layer_coverage_ratio` (o gate RAG-10 mede linkers diretos; co-citação pontuando o gate = gaming do próprio medidor).
   - *co-citação em diffs/sessões cruas* → **NÃO**. Sessões são echo-prone (todo o trabalho anti-echo do pack existe por isso, `AtlasOpenBrainContextPackService` `isSessionArtifactPath`); missões JÁ SÃO a destilação governada de diffs+sessões com `touched_paths`. Redundante e perigoso.
2. **Travessia com scoring (PPR local + pesos frescor/autoridade)** → **SIM**. Adicionar `operation: 'ppr'` ao runtime graph_rank (uma função networkx: `nx.pagerank(personalization=seeds)`); pesos de aresta = `confidence × decay(idade via updated_at/valid_from) × fator authority_level`. Retrieval vira PPR-first sobre o store inteiro exportado (2-3k nós = ms), BFS mantido como fallback honesto e como extrator de paths (o PPR escolhe o conjunto, o BFS-para-trás explica). Ganho: recall estrutural além do raio-2; medição honesta: recall@5 no golden set CONGELADO do RAG-05 + `cross_layer_paths` entregues por pack, lado a lado com o BFS (leitura dupla MED-01) — nunca métrica nova inventada.
3. **Comunidades + sumários por cluster (GraphRAG-style)** → **SPLIT**. Detecção (Louvain/Leiden no networkx, seed fixado para determinismo) + **cards determinísticos** por comunidade (top-k labels por grau, mix de domínios, contagens, exemplares) = SIM — barato, sem provider, útil como seção opcional do pack quando o resultado concentra numa comunidade. Sumários por LLM = **NÃO no default**: corpus é 100× menor que o alvo do GraphRAG, o ganho não é mensurável sem um golden de sumário, e provider spend/echo violam o piso — no máximo default-OFF via writer local, depois de o card determinístico provar utilidade (COM-11 `post_execution_utility` já mede).
4. **Consultas multi-hop no pack (pergunta→entidades→vizinhança)** → **SIM na metade que falta**. Profundidade 2 do BFS já é multi-hop; o gargalo é o SEED. Extração determinística de entidades da pergunta (path repo → nó module por root_path; FQCN → path via PSR-4; UUID/ULID → nó memory; id de domínio) e seed por **node-id exato** antes do LIKE. Segunda chamada de grafo por pack = NÃO (budget e latência; uma travessia bem semeada basta).
5. **Unificação AURG↔CodeIntelligence** → **NÃO unificar stores; SIM ponte formal em dois movimentos**. Unificar mataria o design correto (brain bounded, snapshot-hasheável, privacy estrutural; 290k símbolos explodem caps e cadeia temporal). Ponte: (a) importar AGREGADOS do índice (item 1a) — o grafo fica denso sem engordar; (b) **drill-down federado em query-time**: `atlas:aurg:query --expand=code` expande nó module → top símbolos/links das tabelas CI sob demanda, sem persistir nada no AURG (progressive disclosure já é padrão do AOBG). Decidir o destino do `CodeGraphUnifiedView` órfão: ou vira o leitor oficial dessa ponte, ou aposenta (0 callers = custo de manutenção sem produto).
6. **Ingest incremental barato** → **SIM, cirúrgico**. (a) Docs: short-circuit por mtime+hash antes de re-ler conteúdo; (b) Evidence: trocar prune janela-rolante por **keep-if-linked** (nó de evidence com aresta sobrevive à janela) ou cursor append — hoje o grafo ESQUECE prova antiga por design de cap, não por decisão. Medição: `duration_ms` do sync (já reportado) e zero perda de arestas linkadas entre dois syncs.

## Slices candidatos

**MAXD-01 — Importar o índice doc→código do Code Intelligence (agregado a módulo)** · E:S · deps: [RAG-08 ✅ aterrissado]
- Goal: os 231k links já auditados do índice alimentam o AURG sem re-derivar por regex nem engordar o brain.
- Mecanismo: no sync, query agregada em `atlas_engineering_doc_links` (status=current, workspace do sync): `symbol_path` agregado por (doc, module do símbolo) + `module_capability`/`module_path` diretos → upsert arestas doc→module `references`, `source='linker_doc_code_index'`, confidence 1.0 (id de módulo exato no índice), meta = {link_count, sample link_hash}. Docs fora de `docs/engineering-knowledge-base` são omitidos (governança de nó do RAG-08 intacta).
- Aceite: `atlas:aurg:ingest && atlas:aurg:status --json | jq '.store.edges_by_source.linker_doc_code_index'` ≥ 500; % de nós doc com ≥1 aresta código sobe vs baseline registrado no ledger (leitura dupla); gate RAG-10 NÃO conta a fonte nova (teste prova).
- Risco: dupla contagem com `linker_doc_code` — dedup por (from,to,kind) já resolve no store; manter fontes distintas para auditoria.

**MAXD-02 — Evidência↔missão/obra por trace/receipt (linker_evidence deixa de ser 0)** · E:S · deps: [RAG-09]
- Goal: a camada evidence (200 nós, 0 arestas de linker hoje) linka pelo que o ledger REALMENTE carrega: trace/correlation/receipt.
- Mecanismo: estender `linkEvidence` (`AtlasRealityGraphIngestionService.php:1544`): match exato de `meta.receipt_id` do evento contra `meta.receipt`/`receipt_hash` de nós mission/obra (1.0), e `trace_id`/`correlation_id` contra ids citados em meta de mission (1.0, cite-or-omit — sem match, sem aresta). Nunca inferir por proximidade temporal.
- Aceite: `edges_by_source.linker_evidence` ≥ 20 com origem no ledger vivo, cada aresta citando o campo casado; teste com fixture de evento sem refs → 0 arestas (omissão honesta).
- Risco: se o ledger não estampar receipt nos payloads certos, o número não sai — corrigir o PRODUTOR do evento (EVI-*), nunca afrouxar o match.

**MAXD-03 — Co-citação memória↔código via missões (edge kind próprio, fora do gate)** · E:S · deps: [MAXD-02 não; RAG-10 (cerca)]
- Goal: densificar memória↔código além das 10 arestas diretas, sem gamear o ratio do gate.
- Mecanismo: passo determinístico pós-linkers: para cada mission com aresta →memory E →module, emitir memory→module kind `co_cited`, confidence 0.5 (rung novo documentado na escada), meta={witness_mission_id}, cap 5/nó de memória. `co_cited` EXCLUÍDO de `memory_cross_layer_coverage_ratio` e do check RAG-10 (teste prova a exclusão).
- Aceite: arestas `co_cited` ≥ 10 citando witness; ratio do gate INALTERADO pelo slice (asserção antes/depois); pack de consulta real entrega ≥1 path novo cross-layer que passa nos filtros anti-echo.
- Risco: transitivo de missão gorda (mission que tocou 30 paths e citou 5 memórias) vira produto cartesiano — o cap por nó é obrigatório.

**MAXD-04 — Operação PPR no runtime graph_rank + retrieval PPR-first ponderado** · E:M · deps: [**MAXG-04 (golden v2 como medidor — ELEV-01)**, RAG-05 (só regressão de floor), runtime existente]
- Goal: retrieval enxerga estrutura global (frescor+autoridade+confiança), não só a bola raio-2 do seed.
- Mecanismo: (a) runtime: `VALID_OPERATIONS += 'ppr'` — `nx.pagerank` com personalization=seeds e peso de aresta `confidence × exp(-idade/τ) × authority_factor` (τ e fatores PINADOS em código, mudança = commit); (b) PHP: `AtlasRealityGraphQueryService` ganha modo `ppr` (config default-OFF → shadow → default, escada de rollout existente): exporta store bounded (id/kind/arestas — nunca labels crus para além do já-exportado), recebe top-K, extrai paths por BFS-para-trás dos escolhidos até os seeds; fallback honesto = BFS atual com flag.
- Aceite (ELEV-01): no **golden v2** (MAXG-04, `targets_available == cases` — no v1 zerado "ppr ≥ BFS" seria 0≥0, verde vácuo): recall@5 do modo ppr ≥ recall@5 do BFS registrados lado a lado no ledger (leitura dupla MED-01); latência da query < 2s; flag `ranking='python_ppr'` presente e nunca fabricada; provider_bound continua estrutural (subgrafo exportado já filtrado).
- Risco: PPR favorece hubs (nó doc gordo) — normalização por grau ou teleporte só-em-seeds; validar contra o golden, não contra intuição.

**MAXD-05 — Comunidades Louvain + cards determinísticos por cluster** · E:M · deps: [MAXD-04 (mesmo export de grafo), COM-* (budget do pack)]
- Goal: unidade de recall intermediária entre nó e grafo: "esta pergunta cai no cluster X (AURG/retrieval), composto por A docs, B memórias, C módulos".
- Mecanismo: pós-sync diário, runtime ganha `operation: 'communities'` (Louvain, seed fixo, modularidade reportada crua); persistir `community_id` em meta do nó + card determinístico por comunidade (top-5 labels por grau, mix de source_kind/domínio, contagens) numa tabela pequena ou meta agregada. Pack: seção opcional (dentro do sub-budget AOBG, seção vazia honesta) exibida SÓ quando ≥60% do resultado da query cai numa comunidade. SEM sumário por LLM neste slice.
- Aceite: comunidades persistidas com modularidade reportada (não inventada); card determinístico reproduzível (mesmo input ⇒ mesmo card); pack com card respeita budget e some quando o resultado é disperso; utilidade medida pelo `post_execution_utility` (COM-11) já existente — nunca métrica nova.
- Risco: comunidade dominada pelos 1.034 docs vira "cluster de tudo" — rodar Louvain com peso de aresta do MAXD-04, e reportar concentração por source_kind no card (o sensor de concentração MEM-03 é o padrão).

**MAXD-06 — Seeding entidade-first no pack (id exato antes de LIKE)** · E:S · deps: [nenhuma dura; mede com RAG-05]
- Goal: pergunta que cita path/FQCN/UUID semeia o nó CERTO com score 1.0, não tokens.
- Mecanismo: em `AtlasRealityGraphQueryService`, passo 0 de seeding: extração determinística (regex de path repo → module por root_path prefix; FQCN → path PSR-4 → module; UUID/ULID → memory source_id; id de domínio via taxonomia) → seeds `via='entity_exact'` que ocupam as primeiras vagas do cap 8; semântico/lexical preenchem o resto (merge atual intacto).
- Aceite: teste: query citando `app/Services/Ai/Reality/...` → nó do módulo correspondente é seed; **golden v2 recall@5 não piora (ELEV-01 — o v1 vácuo não serve de régua de não-regressão)**; casos novos com citação explícita de path acertam 100% do seed.
- Risco: FQCN→PSR-4 de código movido resolve para nada = omitir (nunca fuzzy).

**MAXD-07 — Drill-down federado module→símbolos + veredito do órgão órfão** · E:M · deps: [MAXD-01 (a ponte agregada primeiro)]
- Goal: a ponte formal AURG↔Code Intelligence em query-time: expandir um nó module para os símbolos/links vivos SEM persistir símbolo no brain.
- Mecanismo: `atlas:aurg:query --expand=code` (e opt no MCP `atlas_aurg_query`): para nós module do resultado, anexar top-N símbolos por `atlas_engineering_code_symbols` (module_id, ordenação determinística) + links doc↔símbolo relevantes, marcados `federated=true` (não são nós do store). Mesmo movimento no pack atrás do progressive-disclosure existente. No mesmo slice: decidir `CodeGraphUnifiedView`/`CodeGraphRealityIngestionService` (0 callers) — ou viram o leitor desta ponte ou são aposentados com deletion-preflight (veto organs-0-ref respeitado: verificar `rg --no-ignore -w` antes).
- Aceite: query com `--expand=code` devolve símbolos reais com module_id citado; store node/edge count INALTERADO pelo expand (federado, não persistido); veredito do órgão órfão registrado no ledger.
- Risco: expand estourar budget do pack — N pequeno (5/módulo) e só sob flag.

**MAXD-08 — Ingest incremental honesto (docs skip-unchanged + evidence keep-if-linked)** · E:S · deps: [nenhuma]
- Goal: sync diário barato com corpus crescendo (CORP-01) e SEM amnésia de evidência linkada.
- Mecanismo: (a) docs: cache (path, mtime, hash) — arquivo inalterado pula leitura/upsert; (b) prune de evidence passa a preservar nós com ≥1 aresta (`keep-if-linked`), janela de 200 vale só para nós órfãos; contadores de skip/kept reportados no stats do sync.
- Aceite: segundo sync consecutivo sem mudanças: `duration_ms` ≤ 50% do primeiro e 0 upserts de doc; nó evidence linkado sobrevive a um sync com 200 eventos mais novos (teste); `orphan_nodes` não cresce por causa do keep (kept-if-linked tem aresta por definição).
- Risco: keep-if-linked acumula para sempre — cap absoluto de nós evidence (ex. 2.000) com prune dos linkados MAIS ANTIGOS reportado honestamente.

**MAXD-09 — Importar `atlas_docs_authority_graph` como arestas doc↔doc** · E:S · deps: [RAG-08 ✅]
- Goal: a camada doc fica navegável internamente (6.201 arestas de autoridade já computadas) — travessia doc→doc→código passa a existir.
- Mecanismo: no sync, ler `atlas_docs_authority_graph` e upsert arestas doc→doc `references` (`source='linker_doc_authority'`, 1.0 — o grafo de autoridade é determinístico), cite-or-omit (ambos os docs no store).
- Aceite: `edges_by_source.linker_doc_authority` ≥ 100; path doc→doc→module aparece em query real; fonte separada no status.
- Risco: nenhum relevante — dado já existe e é local; só não somar no gate RAG-10.

Ordem sugerida (dependências + razão ganho/custo): MAXD-02 → MAXD-01 → MAXD-09 → MAXD-06 → MAXD-08 → MAXD-03 → MAXD-04 → MAXD-05 → MAXD-07.

## Colisões com v1

1. **RAG-07/RAG-08 (onda 2) JÁ ATERRISSARAM** — números vivos: 1.034 nós doc, 2.203 `linker_doc_code`, coverage 0,6094 ≥ 0,60. Nada aqui re-propõe esses slices; MAXD-01/09 são o ALÉM (importar índices existentes vs re-derivar por regex).
2. **RAG-09 (onda 0)**: o status vivo NÃO mostra `linker_evidence` em `edges_by_source` — ou o slice não aterrissou, ou aterrissou sem produzir arestas (o código atual JÁ lê `atlas_ledger_events`, então o gap é de MATCH, não de fonte). MAXD-02 depende de RAG-09 e o estende (trace/receipt além de target_id/path). Não duplicar: se RAG-09 entregar ≥20 arestas por path/ref, MAXD-02 vira só o eixo trace/receipt.
3. **RAG-10 (gate de cobertura, onda 4)**: MAXD-03 (co-citação) e MAXD-01/09 (imports) DEVEM usar `source`/kind distintos excluídos do ratio e dos checks do gate — arestas derivadas pontuando o medidor do gate é exatamente o gaming que o plano proíbe. Os testes de exclusão são parte do aceite de cada MAXD.
4. **RAG-05 (golden congelado, onda 3)**: é o ÚNICO medidor de MAXD-04/06 — nenhuma métrica nova de recall é inventada; leitura dupla (BFS vs PPR) segue o padrão MED-01.
5. **RAG-12/WDG-01 (watchdogs, onda 4)**: novos linkers aparecem de graça em `edges_by_source` (o status já agrupa por source); os checks de "linker zerado" do RAG-10 continuam olhando SÓ os linkers v1 — check novo para fontes MAXD só se/quando os slices aterrissarem.
6. **COM-\* (composição/budget)**: MAXD-05/06/07 tocam o pack — sub-budgets AOBG (`config/atlas.php` bloco aobg) e o padrão de seção honesta vazia são invioláveis; nenhum MAXD altera budget default.
7. **CORP-01 (crescimento de corpus)**: muda denominadores de cobertura — todo aceite MAXD que cita ratio registra o denominador junto (padrão MEM-03 45d).
8. **EVI-\***: MAXD-02 pode exigir que PRODUTORES de eventos estampem receipt/trace nos payloads — isso é trabalho EVI, não RAG; a colisão é de direção única (MAXD-02 consome, nunca afrouxa o match para compensar produtor pobre).

---

# ACOS Max — Área E: Recuperação/Composição de Contexto (ACCR + ACMF + ATER + AOBG pack)

Ground-truth lido em 11/07/2026, repo `/Users/vitorepf/develop/Atlas/atlas-server`, main @ 477ce0dc89 (+working tree acos10). Latências medidas hoje na máquina do operador.

## Estado atual

### Montagem do pack, ponta a ponta (`app/Services/Ai/AtlasOpenBrainContextPackService.php`, 3215 linhas)
- **Entrada única**: `packFor()` (:171). Ordem real: resolve workspace (:175, `CodeGraphWorkspaceIdentity`) → budgets em **chars** (:176-201; defaults `config/atlas.php:4410-4417`: total 6000, code 2500, memory 2000) → `contextDeliveryPolicy()` (:185→:691-990, lê ARFL) → shrink por multiplier (:190-201) → ceiling proporcional quando sub-budgets > total (:208-213) → 3 seções **independentes e fail-open**:
  - `codeSection` (:1492): `CodeGraphContextRetriever::packFor` BM25, tokenBudget = chars/4 (:1506); filtro de path-noise (vendor/migrations/generated/fixtures, :1571-1628); demote por refs de feedback (:1649); defer de símbolos auxiliares (test/doc/file/cli/route → handles on-demand, :1695-1798).
  - `realitySection` (:2003): AURG `provider_bound=true`, só cross-layer; labels sanitizados a 160 chars (:2112-2125); filtro de session-echo (:2131-2184) e doc-mission (:2190-2208).
  - `memorySection` (:2218): `AtlasHybridMemoryRetrievalService::recall` (pgvector 0.85 + lexical 0.15, `AtlasHybridMemoryRetrievalService.php:27-29`; embedding da query via runtime Python semantic_rag — `Semantic/EmbeddingService.php:35`), filtros demote/relevância (:2285-2286), rerank semântico opcional (:2291, env ON hoje), empacote por item (:1339-1374; floor 400 chars/item :142; **truncação bruta do body com marker** :1380-1401).
- `enforceTotalCeiling` (:595-656): poda trailing-first pelo char medido; cada seção retém top-1.
- Fusão RRF (:278-305): env vivo hoje = `ATLAS_AOBG_FUSION_ENABLED=true`, `mode=default`, canary 50%.
- `retomada` (:310/:385) e `brief` (:314/:460) — obra ativa + brief determinístico, renderizados PRIMEIRO no markdown (:2828-2884).
- `context_pack_hash` (:316/:2675, sha256 canônico) → `context_feedback_request` (:317/:2688-2742): pede `used/noise/missed` refs pós-execução, com `delivered_context_refs` canônicos.
- **Ledger COM-01** (:333-335 → `Context/AtlasDeliveredPackLedger.php`): JSONL `storage/atlas/aobg/delivered-pack-ledger.jsonl`, retenção 168h, grava refs+budgets+policy_snapshot. Nota: `record()` faz `replay()` + `rewrite()` do arquivo INTEIRO a cada pack (:58-62) — "append-only" só no contrato, não no I/O.
- Namespace canônico (COM-01, entregue): `Context/AtlasCanonicalContextRef.php:17` — `(code|memory|graph):[a-f0-9]{32}`; formas de menção/matching (:199-236).

### Formato de saída e citabilidade (o gap D4)
`renderMarkdown()` (:2814-3050) é o ÚNICO payload que os hooks injetam (`.markdown`). Por item:
- código: `- sym:Id [file_path] type=… sig=…` (:2984-2994) → o `id` é citável de volta;
- **memória: `- [type] Title — summary(200)` (:3029-3038) — o ref canônico `memory:<hash32>` e o próprio `id` NÃO são renderizados**;
- grafo: chain de labels (:3004-3014) — `graph:<hash32>` não aparece.
O feedback request exige `used_context_refs` no formato canônico (:2716-2727) que o consumidor de markdown **nunca viu**. Consequência: no caminho de maior volume (hooks), atribuição de memória/grafo só por inferência de diff/transcript — o `cited` do COM-06 depende de menção do id/hash (`AtlasCanonicalContextRef::isMentionedInText` :221) que é impossível de produzir. O caminho MCP recebe o JSON completo (refs presentes), mas não é o caminho quente.

### Progressive disclosure
- Manifest (:548-565 → `Mcp/AtlasMcpTierService`, 3 tiers discovery/context/detail). No pack medido hoje veio `status: "unavailable"` (degrade silencioso fail-open).
- Expansão on-demand real: `AtlasOpenBrainContextExpansionService.php` — handles `ranking/test_symbols/canonical_doc`, budget 800..8000, consumindo ACRS.

### Hooks AOBG — o que entra por turno (e quantas vezes)
- Umbrella `/Users/vitorepf/develop/Atlas/.claude/settings.json`: UserPromptSubmit→`atlas-ctx.sh`, PreToolUse→guard, PostToolUse→`atlas-postedit-context.sh`, Stop→capture.
- **`atlas-server/.claude/settings.json` registra `atlas-ctx.sh` DUAS VEZES** (uma via `$CLAUDE_PROJECT_DIR`, outra via caminho absoluto — idênticas quando o projeto é atlas-server), + heartbeat + session-state reinject. O PostToolUse também dispara em dobro: **comprovado ao vivo nesta sessão — cada Read recebeu 2 injeções byte-idênticas do file-context**.
- `atlas-ctx.sh`: skip de prompt operacional (:62-66); **`atlas:aobg:workspace activate` a cada prompt** (:91-93, sem timeout); `atlas:context-pack --budget=6000` (:97, **sem timeout**); dedupe por cksum do markdown (:129-137) — corta a re-INJEÇÃO, **não o re-CÁLCULO**.
- `atlas-postedit-context.sh`: budget 3500, timeout duro 8s (:61), soft budget 1500ms (config :4472).

### Caching e latência (medido hoje, 11/07)
- **Zero cache** no caminho do pack: nenhum `Cache::`/`remember` no service, no command, no retriever. Pack recalculado do zero a cada prompt.
- `php artisan --version` = **0.31s** (boot não é o custo).
- `atlas:context-pack --budget=6000 --json`: **18.49s frio, 13.31s / 13.54s quente** — com e sem semantic-rerank+fusion (custo NÃO vem do rerank; vem de recall+grafo+code-graph+embedding da query no runtime Python).
- `atlas:aobg:workspace activate`: **>170s, morto por timeout de 3min sem terminar** (pode ter incluído reindex pontual; estruturalmente roda a cada prompt e sem bound).
- Custo real por prompt no Claude Code em atlas-server hoje: **2× activate + 2× pack** (duplicação de hooks) — dezenas de segundos a minutos de wall-clock por turno de hook.
- Pack entregue hoje (live): 13 code / 2 paths / 4 memory, 5382/6000 chars — acima do "1 memória/2 caminhos" registrado como estado conhecido.

### ACMF existe de verdade?
Dois artefatos, **nenhum no caminho vivo de injeção**:
- `Context/AtlasCognitiveMemoryFabricService.php` (:34 `plan()`): simulador puro — sem input **fabrica 4 itens default** (:168-173, 'decision:current' etc.), claims `writes:false`; consumidores = AUCRI audit/enforcement/scorecard.
- `CognitiveMemory/AtlasCognitiveWorkingSetMemoryService.php`: heat/delta/spillover com persistência SIS1 opt-in (:83-116, `storage/atlas/working-set/state.json`); consumidor único = `atlas:working-set` CLI; **o state.json não existe em storage** — nunca foi alimentado. Docblock admite: hooks são "later". Veredito: working memory de sessão = built-but-not-fed.

### ATER — como aloca tokens
- `Tokens/AtlasTokenEconomyBudgetPolicyService.php`: budgets canônicos por flow×risk (default/low = 8k input, :76-83), Phase 1 in-memory.
- `Context/AtlasTokenEconomyRuntimeService.php::optimize()` (:50-…): compõe ACCR (`AtlasContextCompilerRuntimeService::compile`) + compression/reuse receipts + canary + enforcement observe/shadow/enforce (OPT-08).
- **Consumidores reais**: VerifiedContextExecution, ProgrammingFinalCertification, AUCRI enforcement/audit, comandos. **O pack AOBG não passa por ATER** — o budget do pack é chars fixos de config; o sidecar `runtime_compose` (OB-01, :321-374 → `Context/AtlasContextRuntime.php:37`) que encostaria os dois é default-OFF. ACCR idem: caminho AUCRI, não hook.

### ARFL — o medidor
- `Context/AtlasRetrievalFeedbackLoopService.php::capture()` (:45): freshness gate → join com ledger COM-01 (`deliveredPackLedger`) → missed/noise candidates → `contextRefAttribution` → ROI → `next_context_policy` → persiste `AiRagFeedbackEvent` → `resolvePriorMisses` (COM-08). Fórmula explícita congelada v1 (:31).
- Consumo no pack: `contextDeliveryPolicy` (:691) lê os últimos 20 eventos da janela 168h (:729-740), **filtra measured-only** (COM-05, :758-763); shrink 0.85/0.75 (:918-924) e source-mix 0.70/0.85 (:1085-1093) só com floor de eventos.
- **Live hoje**: `total_event_count=20, feedback_event_count(measured)=0` → multiplier 1.0, `applied=false`; ações = `defer_auxiliary_code_symbols`. Ou seja: a política adaptativa está correta e faminta — o produtor de sinal medido (COM-11, gate_verified) acabou de aterrissar e ainda não encheu a janela.
- Status v1 (progress doc): COM-01..09 e COM-11 ✅; **COM-10 (watchdog) pendente**.

## Teto atual
1. **Latência**: 13-18s por pack, ×2 por duplicação de hooks, + activate sem bound por prompt, sem nenhum cache — o hook interativo mais importante (UserPromptSubmit) custa ordens de magnitude acima de qualquer alvo interativo.
2. **Citabilidade quebrada no caminho quente**: refs canônicos existem (COM-01) mas não são renderizados por item → o loop D4 (used/noise por ref) não fecha para memória/grafo via hooks; atribuição degrada para inferência.
3. **Budget cego a valor**: chars fixos + multiplicadores discretos (0.85/0.75/0.70) que hoje nunca aplicam (0 eventos medidos); nenhuma noção de valor esperado por item ou por fonte com dados.
4. **Sem memória de sessão**: o que a sessão já viu re-entra (dedupe só se o pack for byte-idêntico); ACMF/working set persistido existe e está vazio.
5. **Compressão = truncação bruta** com marker; summary já existe por item mas o body truncado ainda é o mecanismo.

## Fronteira (técnica → veredito + porquê)

1. **Headers de proveniência + citabilidade por item** → **FAZER JÁ (maior ROI/char da área)**. Renderizar o ref canônico COM-01 por item (`ref=memory:ab12…`, `ref=graph:…`; código já tem id citável) + 1 linha no rodapé instruindo a citar refs usados. Ganho mensurável: share de eventos ARFL com `used_context_refs` canônicos e `attribution_quality=cited` sai de ~0; alimenta COM-06/COM-11 sem tocar na régua. Custo: ~15-20 chars/item (~2-3% do budget). Medição honesta: comparar janelas COM-07 antes/depois (mudança de comportamento do consumidor, não da fórmula — formula_version intocada).
2. **Alocação por VALOR ESPERADO (dados ARFL reais)** → **FAZER, mas gated em sinal**. Multiplicadores contínuos por source_type = f(used_ratio × utility médios da janela measured), com floors/teto e formula_version própria (reset da série COM-07 previsto em contrato). Hoje seria régua sem dados (0 measured na janela); o gatilho de implementação é a janela COM-11 encher (floor N eventos). Anti-Goodhart: EV calculado SÓ de measured=true; nunca otimizar o multiplier contra ele mesmo; COM-10 vigia a cadência.
3. **Cache incremental por query_hash+content_hash** → **FAZER em 2 degraus, medindo antes**. Degrau 0 (custo ~zero, ganho ~50%): matar a duplicação de hooks. Degrau 1: activate 1×/TTL por sessão + timeout duro no atlas-ctx.sh. Degrau 2: cache de pack keyed por (workspace, query normalizada, fingerprint do corpus = index receipt + max(updated_at) de memória) com TTL curto — retorna hit sem recomputar; delta real (pack-diff) só faz sentido junto com o working set (item 6). Medição honesta: timing por seção persistido no ledger ANTES de otimizar (regra MED-01 do plano: medidor congela antes do produtor).
4. **Pack em camadas (núcleo + expansão sob demanda)** → **NÃO re-propor**: v1/hoje já cobrem (defer auxiliares + on_demand_handles + tiers MCP + retomada/brief primeiro). O que falta é conserto, não feature: o manifest de tiers responde `unavailable` no pack real — diagnosticar e restaurar (fail-open está escondendo um degrade permanente).
5. **Latência-alvo por hook** → **medido hoje** (seção Estado atual). Alvos honestos local-first: UserPromptSubmit ≤2s p95 (hoje ~27-37s+ efetivos), PostToolUse ≤1.5s p95 (soft budget já existe; hoje até 8s ×2). O caminho: degraus 0-2 do item 3; um worker persistente (processo residente respondendo por socket) é a fronteira seguinte SE, após os degraus, o floor de ~13s de retrieval não ceder — decisão só com o timing por seção em mãos (não especular onde dói).
6. **Working set persistente por sessão (ACMF real)** → **FAZER — é o "ALÉM" estrutural da área**. Os eventos de hook carregam session_id; o ledger COM-01 ganha escopo de sessão (seen_refs); `packFor` recebe `_session_seen_refs` e demove item já entregue NESTA sessão (mesmo canal `_demote_context_refs` já plumbado, :186-189), preservando: top-1 por seção, retomada/brief nunca demovidos, expansão on-demand re-obtém qualquer coisa. Alimenta o `AtlasCognitiveWorkingSetMemoryService` persistido (SIS1 pronto, 0 writers) — ACMF sai de aspiracional para vivo com writer real. Ganho mensurável: repeated_ref_share por sessão (novo campo no ledger) caindo; chars liberados vão para itens novos. Anti-Goodhart: o alvo é utility estável com menos repetição, nunca "menos chars" por si.
7. **Compressão semântica de itens longos** → **ADIAR; fazer summary-first barato**. Compressão por modelo no hook path viola custo-zero/local-first (sem runtime local barato hoje). O degrau honesto: quando o cap por item aperta, preferir `summary` completo a `body` truncado no meio (title+summary já existem por item; truncação bruta vira último recurso). Ganho: densidade por char sem nenhum modelo. Compressão semântica real entra quando existir runtime local residente (mesma dependência do worker do item 5) e será medida por utility mantida, não por chars economizados.

## Slices candidatos

**MAXE-01 — Refs canônicos visíveis por item + contrato de citação no markdown** · E:S · deps: [COM-01, COM-04, COM-06]
- Goal: o consumidor do markdown consegue citar de volta exatamente o que usou; `cited` deixa de ser impossível no caminho hook.
- Mecanismo: `renderMarkdown` imprime `ref=<canonical>` por item de memória/grafo (código mantém id) + rodapé de 1 linha no `context_feedback_request` renderizado instruindo citar refs usados no report. Nenhum ref novo: só `AtlasCanonicalContextRef` existente.
- Aceite: teste asserta que 100% dos itens renderizados carregam ref canônico e que `deliveredFromPack` == refs renderizados; após 1 janela ARFL, share de eventos com used_context_refs canônicos > 0 (hoje 0), lido via `atlas:context:policy-trend`.
- Custo: ~2-3% do budget de chars.

**MAXE-02 — De-duplicar os hooks AOBG (config, zero código)** · E:S · deps: []
- Goal: cada evento dispara cada hook UMA vez; custo por prompt cai ~50% imediatamente.
- Mecanismo: remover a entrada absoluta redundante de `atlas-ctx.sh` em `atlas-server/.claude/settings.json` (UserPromptSubmit) e a duplicação equivalente do PostToolUse (umbrella + projeto registram o mesmo script para o mesmo cwd).
- Aceite: sessão live recebe 1 injeção por Read (hoje: 2 byte-idênticas, evidenciado em 11/07); nenhum hook perde cobertura no workspace umbrella.

**MAXE-03 — Dieta do hook: activate com TTL + timeout duro no atlas-ctx.sh** · E:S · deps: [MAXE-02]
- Goal: UserPromptSubmit nunca paga activate repetido nem fica sem bound.
- Mecanismo: marker file por workspace com TTL (ex.: 6h) pula o `atlas:aobg:workspace activate` já feito; `timeout`/perl-alarm no pack call (mesmo padrão já provado no atlas-postedit-context.sh:114-127). Fail-open intacto.
- Aceite: dry-run do hook com marker fresco não invoca activate; wall-clock do hook == custo do pack apenas; um activate lento nunca excede o bound.

**MAXE-04 — Timing por seção no ledger COM-01 (medidor congela ANTES do produtor)** `[harmonizado com MAXG-01 — ver vi-b]` · E:S · deps: [COM-01, MAXG-01 (a série-dona)] — *uma régua, duas granularidades: este slice grava por-seção no entry do COM-01 E espelha o agregado para a série do MAXG-01; zero série paralela de latência*
- Goal: saber ONDE os ~13s vivem (code/reality/memory/embedding/boot) antes de qualquer otimização de cache/worker.
- Mecanismo: `packFor` cronometra cada seção + total e grava `timings_ms` no entry do ledger (campo aditivo, schema bump; refs intocados). Comando read-only de trend (p50/p95 por seção). De carona: trocar o `record()` replay+rewrite por append real com prune no read (I/O honesto com o nome).
- Aceite: ledger carrega timings em todo pack novo; trend imprime p50/p95; teste asserta campo presente e refs inalterados.

**MAXE-05 — Cache de pack por (workspace, query_hash, corpus_fingerprint)** · E:M · deps: [MAXE-04]
- Goal: prompt repetido/refinado na mesma sessão não paga retrieval completo.
- Mecanismo: chave = workspace + hash da query normalizada + fingerprint do corpus (receipt do index CodeGraph + max(updated_at) de atlas_memory_entries); TTL curto (minutos); hit devolve o pack persistido (o próprio ledger COM-01 já guarda refs/budgets — cache guarda o markdown+JSON ao lado); miss segue caminho atual. Telemetria hit/miss no ledger.
- Aceite: mesma query 2× em <TTL → 2ª resposta <2s (medida por MAXE-04) e `context_pack_hash` idêntico; mudança de corpus invalida (teste com memória nova).
- Risco anti-Goodhart: cache nunca serve pack stale após mudança de corpus — o fingerprint é a única autoridade.

**MAXE-06 — Working set de sessão vivo (ACMF ganha writer real)** · E:M · deps: [COM-01, COM-02, MAXE-04]
- Goal: o que a sessão já viu não re-entra; ACMF sai de built-but-not-fed.
- Mecanismo: hooks passam session_id; ledger COM-01 indexa refs entregues por sessão; `packFor` demove (canal `_demote_context_refs` existente, :186-189) refs já entregues na MESMA sessão, com floors: top-1 por seção sempre, retomada/brief imunes, expansion handles re-obtêm sob demanda. Escreve o delta-seen no `AtlasCognitiveWorkingSetMemoryService::sharedPath()` (SIS1) — primeiro writer real.
- Aceite: `repeated_ref_share` por sessão (novo no ledger) cai vs. janela baseline; teste: 2º pack da mesma sessão com mesma query entrega refs novos ou honest-empty, nunca o mesmo conjunto; `storage/atlas/working-set/state.json` passa a existir e refletir a sessão.
- Colisão evitada: demoção de sessão NUNCA entra no `demote_context_refs` de feedback (COM-02 measured-only) — são canais com provenance distinta no evidence.

**MAXE-07 — Budget por valor esperado (fórmula v2 versionada, gated em sinal medido)** · E:M · deps: [COM-05, COM-07, COM-11, MAXE-01]
- Goal: multiplicadores contínuos por fonte derivados de EV real (used_ratio × utility por bucket), substituindo os degraus fixos 0.70/0.85 quando houver dados.
- Mecanismo: `sourceSelectionPolicy` ganha modo `ev_weighted` atrás de switch default-off + floor de eventos measured por bucket; fórmula documentada e congelada com `formula_version` própria (série COM-07 reseta por contrato). Floors 0.5..1.0, nunca zera fonte.
- Aceite: testes de propriedade (monotonicidade em used_ratio/utility, floor respeitado, bucket sem sinal ⇒ 1.0); com janela real ≥floor, policy-trend mostra a série nova sem misturar versões.
- Gatilho honesto: NÃO ligar antes de ≥1 janela com eventos measured>floor (hoje: 0/20).

**MAXE-08 — Summary-first packing (fim da truncação no meio do body)** · E:S · deps: []
- Goal: máxima densidade por char sem modelo: item que não cabe entrega title+summary íntegros em vez de body serrado.
- Mecanismo: `compactMemoryItemForPack` (:1380) passa a degradar em degraus: body inteiro → summary inteiro (body omitido, marcado `body_omitted=true`) → truncação com marker só como último recurso.
- Aceite: teste: item com body>cap e summary<cap entrega summary íntegro sem marker; chars/item medidos não sobem; nenhum item perde title.

Ordem sugerida (custo→ganho): MAXE-02 → MAXE-03 → MAXE-01 → MAXE-04 → MAXE-08 → MAXE-06 → MAXE-05 → MAXE-07.

## Colisões com v1
- **COM-01 dono único do namespace de refs**: nenhum slice cria formato novo. MAXE-01 renderiza os refs existentes; MAXE-04/05/06 adicionam campos ADITIVOS ao entry do ledger (timings, session_id, cache meta) sem tocar em `delivered_refs`/hash; MAXE-06 consome o canal de demoção existente com provenance própria.
- **Janelas ARFL de 4 semanas não podem ser invalidadas**: MAXE-01/02/03/08 mudam entrega/comportamento do consumidor, não a régua — formula_version intocada, janelas comparáveis (mudança de comportamento aparece COMO tendência, que é o que a série mede). MAXE-07 muda a política ⇒ usa o mecanismo contratual do COM-07/COM-11 (formula_version nova reseta a série; versões nunca comparadas entre si). MAXE-06 muda o pack entregue ⇒ ARFL continua verdadeiro por construção porque mede o ENTREGUE via ledger (COM-04).
- **COM-05 (measured-only)**: a demoção de sessão do MAXE-06 não é feedback — não entra no caminho measured; sem risco de inflar sinal.
- **COM-10 (watchdog, pendente)**: MAXE-04 fornece exatamente a telemetria que o watchdog vai vigiar; implementar MAXE-04 antes de COM-10 evita medidor retrofit.

---

# MAX-F — Compactação (ACIE / 4 mecanismos) — ground-truth + fronteira v2

> **⚠️ FORMATO (aviso ao executor):** ao contrário de todas as outras famílias — que definem cada slice como bloco `**ID — título** · E · deps …`— os slices **MAXF-01..11** estão na TABELA de "Slices candidatos" abaixo (`| MAXF-01 | Título | Goal | Mecanismo | Aceite | Effort | Deps |`). Para localizar um MAXF: `grep -n "| MAXF-0"` (não `grep "**MAXF"`). Cada LINHA da tabela é a definição INTEIRA do slice (Goal/Mecanismo/Aceite/Effort/Deps nas colunas). O conteúdo é equivalente; só o layout difere.

Leitura real do código em 2026-07-11 (branch main). Estado do plano v1: **CPT-01..08 landados** (progress.md linhas 59, 86-88, 116-119 do working tree: CPT-03/04/07 em 3a3a81957, CPT-06 em f706e88ba); **CPT-09 (soak) e CPT-10 (certify) pendentes** (ondas 4/5).

## Estado atual

### Mecanismo 1 — Conversa (`app/Services/Ai/AiCompactionService.php`)

**Gatilhos:**
- Auto: `maybeAutoCompact` (:35-68) — ≥18 mensagens (`atlas.ai.auto_compaction_message_threshold`, :658-661) E ≥10 desde a última (`auto_compaction_messages_since_last`, :663-666). Chamado em `AiGatewayService.php:280` a cada enqueue.
- Manual/eventos: `compact()` (:70-81) com reasons `manual|auto|provider_switch|phase_change|session_resume|session_close` (:119).

**O que preserva (determinístico, sem LLM — pétreo intacto):**
- Mensagens com `<skill_content name=` são protegidas fora do sumário (:91-93, política registrada no structured_state :105-109).
- `summary()` (:422-491, CPT-03 vivo): partes fixas (título, objetivo, fase, notas de qualidade) + segmentos ranqueados por `SegmentImportanceRanker` (`app/Services/Ai/Aaeos/Cores/SegmentImportanceRanker.php` — score = kind_weight + 0.5/(1+recency) + importance/100 + bônus evidence 0.20 / link 0.30 + penalidade dup_group −0.5 acumulada) sob budget de segmentos = `summary_token_budget` (default 3000 tokens, :446-449) − partes fixas. Turnos truncados a 320 chars (:535). Cap final `Str::limit(…, 12000)` (:488).
- Segmentos dropados pelo ranker viram `dropped_segments` → merged em `unresolved_loss`/`discarded_items` do receipt (:220-221).

**Receipt de conversa (CPT-02, :197-312):**
- must_keep via `CompactionMustKeepExtractor::extract` (ver abaixo); cobertura medida EXCLUSIVAMENTE contra a superfície consumer-visible: `str_contains($summary, $digest)` (:318-347) — nunca contra structured_state.
- Vácuo honesto: `must_keep_items === []` → coverage 0.0, status `vacuous`, loss_risk medium, write_allowed=false (:223-248).
- `CompactionLossPolicy::classify` (`app/Services/Ai/Compaction/CompactionLossPolicy.php:24-36`): **write_allowed só com coverage==1.0 E sem critical kind tocado** — invariante pétrea confirmada no código.
- CPT-04 vivo: `qualityGateStatus` (:598-612) → `needs_review` (ações abertas / avaliações falhas / sem objetivo+tópico) bloqueia overwrite de `thread.summary` (:114, :185-187); receipt ainda persiste.
- CPT-07 vivo: `context_retention_score` via `SummaryFidelityCoverageScorer` (Aaeos/Cores, verdict failed se qualquer decision faltou ou retention <0.6) sobre o summary SEM a cauda "ATENCAO unresolved_loss" (:396-402 — evita contar o eco da perda como retenção).

**Extração de must_keep (`app/Services/Ai/Compaction/CompactionMustKeepExtractor.php`):**
- Puro, sem LLM: `AiSessionState.decisions→decision`, `open_loops→blocker`, `next_steps→dod`, `constraints→risk_critical` (:48-74); digest = `Str::limit(text, 280)` (:149); merge caller-wins por id (:113-126); `must_keep_source ∈ {caller,extracted,none}`.

**recovery_queries:**
- Derivadas deterministicamente por perda: `"rehydrate {kind}:{id} from canonical sources"` (`AiCompactionService:1073-1089`), união com queries do caller, dedup.
- Executor (CPT-06, `app/Services/Ai/LongHorizon/CompactionRecoveryExecutor.php`): parseia por regex (:63-73), procura em `ai_session_states` (200 mais recentes, colunas por kind, :78-115) e fallback no payload do próprio receipt (`must_keep_items|discarded_items|unresolved_loss`, :121-141). **Read-only por política explícita** (:52-56, write gate = LongHorizonMemoryPromotionGuard). Consumidor: `LongHorizonRecoveryPlannerService`. Teste: `tests/Feature/Ai/Compaction/CompactionZeroLossRecoveryTest.php`.

**Rehidratação (consumidor):** `app/Services/Ai/AiConversationContextBuilder.php` — injeta `thread_summary` (:82), `latest_compaction.summary` (:99-106), turnos após `source_position_end` (:130-131), limite 12 turnos default (min 2/max 40, `ConversationContextInput`), truncados 1800/2400 chars (:72).

**Taxa de compressão REAL medida (DB vivo pgsql:5433/atlas):**
- `ai_compactions` tem **6 linhas** (todas `session_resume`, jun/2026). **5 das 6 EXPANDIRAM**: before 10-29 tokens → after 49-105 (boilerplate fixo do sumário > thread pequena). A única compressão real: 764→309 (**ratio 0.40**). Média de ratio = 3.72 (inflação!). O gatilho auto (18 msgs) na prática nunca disparou em produção. Conclusão honesta: taxa de compressão de conversa é **não-medível em escala hoje e net-negativa em threads pequenas**.

### Mecanismo 2 — Handoff de sessão (`app/Services/Ai/AiProviderHandoffService.php`)

- Gatilho: switch de provider no enqueue (`AiGatewayService:281-289`); skip via config `atlas.ai.handoff_skip_providers` default `['claude_codex']` (:260-266, CPT-05b feito).
- Brief: estado + thread_summary + últimos 8 turnos @900 chars, take(5) por lista, cap 12k (:160-197).
- CPT-05: receipt via `compactForScope` com `SCOPE_TYPE_HANDOFF` (:222-250); fair mode emite receipt-de-perda audit-only SEM row de AiProviderHandoff (:124-158) — não contamina o `latest_provider_handoff` do context builder.
- DB vivo: 22 handoffs, último 03/07, **nenhum com receipt** (todos pré-CPT-05).

### Mecanismo 3 — Longo-horizonte (`compactForScope` :709-868 + receipts)

- 13 scope types permitidos (`AtlasLongHorizonCanon:240-253`, inclui `conversation`/`handoff` novos); merge caller+extracted must_keep; forced_discards com reasons canônicos; summary de escopo com bloco "ATENCAO unresolved_loss" que embute a recovery_query no texto (:1048-1056); `receipt_hash` canônico; hooks pós-compaction (Obra 7 CMP-01, :1134-1170) apendam marker em `storage/app/atlas/evidence/post-compaction-markers.jsonl`.
- `AtlasVerifiedCompactionService` (ContextIntelligence) envelopa com semantic_diff (interseção de ids — "semantic" só no nome) e status passed/blocked.
- **Marker tem 161 linhas reais e ZERO leitores** — `rg` só acha o writer (`AiCompactionService`). O `continuity_inject=true` é flag morta: nada injeta.

### Mecanismo 4 — Payload / token-economy (`app/Services/Ai/Context/AtlasTokenEconomyRuntimeService.php`)

- `optimize()` (:49-118): compile → reuse receipt (hash-match → economia estimada 55%, :340-358) → local prereasoning → **compression_receipt com % HEURÍSTICO por risco** (low 28% / medium 18% / high 10% / irreversible 5%, :390-423 — é estimativa declarada, não medição) → context delivery policy (staged, multiplicadores 0.52-0.92) → quality_check (`must_keep_coverage<1.0` → blocked, :679-715) → enforcement `observe` default (`atlas.token_economy.enforcement_mode`).
- CPT-08 vivo: `must_keep_allocator_enabled` default **true** (config/atlas.php:385); shadow puro fora do payload/hash (:113-115, :159-186) → `storage/app/private/atlas/context-budget/must-keep-shadow.jsonl`.
- Allocator (`ContextWindowMustKeepBudgetAllocator.php` + Support): kernel puro 3 fases — greedy fit must_keep ranqueado; overflow → compression_target proporcional por segmento para recuperar o déficit; opcionais só no leftover; blockers.
- **Dados reais do shadow (45 registros, 11/07 17:08→18:24): 6/45 com overflow, must_keep_coverage mínima 0.6667 (déficit até 716 tokens), média 0.956.** O shadow já está capturando degradações reais de must_keep — exatamente a amostra que o soak CPT-09 precisa.

### 🔴 Gap vivo transversal (achado mais importante)

**A tabela `atlas_long_horizon_compaction_receipts` NÃO EXISTE no DB vivo** (verificado: `Schema::hasTable` = false; `information_schema` sem NENHUMA tabela `%long_horizon%`) — baixa do incidente wiper de 02/07. O ledger de migrations diz "Ran" (2026_05_19_040000), então `php artisan migrate` **não a recria**; e a migration `2026_07_11_171500_add_context_retention_score` está Pending. Consequências:
1. Receipt de conversa (CPT-02) fail-open `unavailable` em TODA compaction real (`DatabaseTableAvailability::has`, AiCompactionService:207).
2. `compactForScope` **NÃO tem guard** (:829 `create()` direto, sem try) → próximo switch real de provider quebra a criação de handoff (`AiGatewayService:283` sem catch); fair mode idem (:287).
3. CPT-02/05/06/07 estão verdes em teste (RefreshDatabase) mas **produzem ZERO receipts em produção** — o denominador do CPT-10 é 0 e o soak CPT-09 nunca sai de `insufficient_sample`.

## Teto atual

- **Conversa:** cap 12k chars + budget 3k tokens; compressão real limitada por baixo pelo boilerplate fixo — threads pequenas INFLAM (5/6 casos reais). Ratio isolado é métrica sem sentido; o par honesto é (ratio × retention).
- **Cobertura:** `str_contains(summary, digest)` — exata, determinística, mas frágil a qualquer reescrita; digest de 280 chars é o átomo de fidelidade.
- **Recovery:** kinds de estado (decision/blocker/dod/risk_critical) recuperam payload completo; `conversation_turn` dropado só recupera o digest de 280 chars do receipt — **o texto integral continua em `ai_messages` (compaction não deleta mensagens) mas o executor não tem lookup `turn:{id}`**. Perda declarada recuperável que na prática só rehidrata parcial.
- **Token-economy:** `savings_estimate` é ficção declarada (percentual por risco) até o enforce aplicar corte real; a única medição verdadeira do mecanismo payload é o shadow do allocator.
- **Sem loop de feedback:** o campo `importance` dos segmentos (ranker já consome, :186-191) tem **zero produtores** — sempre 0.0 para turnos; nada aprende do que foi re-pedido/recuperado depois da compactação.
- **Dedup:** `dup_group` do ranker (penalidade pronta e testada) tem **zero produtores** — duplicatas queimam budget 2×.

## Fronteira

(cada técnica: veredito + porquê; TODAS respeitam: L1 determinístico sem LLM permanece canônico; write_allowed<1.0 pétreo; local-first; nada toca payload hasheado durante soak)

1. **Sumários hierárquicos versionados (L1 determinístico + L2 assíncrono verificado)** → **FAZER, com cerca dura.** L1 já existe e é o canônico. L2 = reescrita por modelo LOCAL (runtime Hermes/GLM local; nunca provider externo para conteúdo de conversa), rodando em job assíncrono fora do hot path, aceita SÓ se `SummaryFidelityCoverageScorer` der coverage==1.0 E retention ≥ L1, armazenada VERSIONADA em canal separado (metadata/coluna própria) — `thread.summary`, receipts, hashes e o certificador CPT-10 continuam lendo L1. Ganho mensurável: chars(L2)/chars(L1) a retention igual (esperado 0.4-0.6 no bloco de turnos). Custo: inferência local + verificação determinística (barata). Medição honesta: par (ratio, retention) por versão, gravado em evidência; L2 rejeitada conta como rejeição, não some.
2. **Dedup semântico entre turnos** → **FAZER em 2 degraus; lexical primeiro.** O sink já existe (dup_group + penalidade no ranker, 0 produtores). Degrau 1 (determinístico, sem modelo): shingle/hash normalizado sobre o texto dos segmentos → dup_group para duplicatas exatas/quase-exatas — zero risco ao pétreo. Degrau 2 (opcional, flag OFF): embedding local já existe e é soberano (`EmbeddingService` → runtime Python `semantic_rag`, 384-dim, pgvector; `app/Services/Semantic/EmbeddingService.php:34-45`) — cosine > τ marca dup_group, rodando em SHADOW (loga divergência vs lexical, não muda seleção) até medir. Ganho: % de tokens duplicados no summary (medível direto no shadow). Custo: degrau 1 ~zero; degrau 2 só se o degrau 1 mostrar resíduo semântico relevante.
3. **Importância aprendida do feedback real** → **FAZER como CONTAGEM, não ML.** Sinal determinístico: (a) hits do CompactionRecoveryExecutor (item recuperado depois = era importante), (b) recall/citation events já persistidos (`ai_rag_feedback_events`, espinha FEE), (c) reaparição do digest em turnos posteriores. Incrementa `importance` do item de estado (o ranker já consome importance/100). Anti-Goodhart: importância NUNCA rebaixa must_keep (piso pétreo intocado); só reordena o que já era elegível a corte. Ganho: retention dos receipts seguintes sobe para o mesmo budget (medível série CPT-07). Custo: contadores + join simples.
4. **Fidelity score da compactação (recovery test automático amostral)** → **FAZER — é o medidor que falta.** Sampler agendado read-only: amostra N receipts recentes, roda `CompactionRecoveryExecutor::recover()`, agrega recovered/missing por kind e scope, grava evidência JSONL → vira insumo direto do verdict do soak CPT-09 e do CPT-10. Perda medida DE FATO (não coverage declarada): `recovery_rate = recovered/(recovered+missing)`. Custo: ~zero (kernel existe). Pré-req duro: tabela de receipts restaurada.
5. **Compressão por referência** → **FAZER PARCIAL, onde há canal de rehidratação.** O protocolo já existe pela metade: recovery_queries `rehydrate kind:id` + digests no receipt. Frontier: (a) executor ganha lookup `turn:{message_id}` em `ai_messages` (o texto integral JÁ está lá — recuperação vira 100% em vez de digest 280); (b) no summary, bloco de turnos vira `turn:id + digest curto` SÓ para consumidores Atlas-side (packs/CLI) que sabem expandir — nunca no prompt cru de provider (provider não rehidrata mid-call). Ganho: o bloco de turnos domina o 12k; refs cortam o maior custo com perda-zero real (conteúdo recuperável byte-exato). Cuidado: gotcha de markers `[[...]]` (memória do cockpit) — usar sintaxe já canônica `rehydrate kind:id`.
6. **Compaction-aware packs** → **FAZER — é wiring de seam morto.** O marker pós-compaction já é escrito (161 linhas, `continuity_inject=true`) e NINGUÉM lê; `AiContextPackBuilder.php:171` já injeta `latest_compaction`. Frontier: pack expõe seção `compacted` {scope, receipt_hash, must_keep_coverage, unresolved_loss, recovery_queries} lida do último receipt+marker — o consumidor sabe O QUE foi compactado e COMO pedir de volta. Ganho: recovery deixa de depender de sorte do operador; medição = taxa de recovery_queries efetivamente executadas após compaction (hoje 0). Custo: leitura de 1 receipt no build do pack.

**Vereditos-resumo:** 4 e 6 são as maiores alavancas por custo (~zero, kernels/dados já existem); 5a (turn lookup) é a maior alavanca de FIDELIDADE; 2-degrau-1 e 3 são as alavancas de TAXA sem tocar o pétreo; 1 e 2-degrau-2 são os únicos que envolvem modelo — ambos assíncronos, verificados, fora do caminho quente e fora do canônico.

## Slices candidatos

| ID | Título | Goal | Mecanismo | Aceite mensurável | Effort | Deps |
|---|---|---|---|---|---|---|
| MAXF-01 | Restaurar tabela de receipts no vivo + guard fail-open no compactForScope | Destravar TODO o pipeline de evidência de compactação (hoje 0 receipts em produção) | Recriar `atlas_long_horizon_compaction_receipts` (ledger de migrations mente "Ran" pós-wiper — recriar por migration nova idempotente `create if not exists` + rodar a Pending do retention_score); adicionar guard `DatabaseTableAvailability` + retorno `failed_open` em `compactForScope` (AiCompactionService:829) espelhando o guard da conversa (:207) | `Schema::hasTable`=true no vivo; 1 switch real de provider persiste receipt handoff; com tabela ausente, handoff NÃO lança exception (teste) | S | — (pré-req de MAXF-02/06/07 e do CPT-09/10 v1) |
| MAXF-02 | Sampler de fidelidade: recovery test automático amostral | Medir perda DE FATO, não coverage declarada | Comando read-only `atlas:compaction:recovery-sample --json`: amostra N receipts recentes, roda `CompactionRecoveryExecutor`, agrega `recovery_rate` por kind/scope, apenda evidência JSONL; registrado como check-plugin do watchdog | ≥20 receipts reais → recovery_rate reportado por kind; 0 receipts → `insufficient_sample` nunca pass; série entra no verdict do soak | S | MAXF-01, CPT-06; plugin: WDG-01/CPT-09 |
| MAXF-03 | Recovery integral de turnos (`turn:{id}` → ai_messages) | Perda de turno dropado vira recuperável byte-exato (hoje só digest 280) | `CompactionRecoveryExecutor::findInSessionState`-style lookup novo: kind `conversation_turn`/prefixo `turn:` resolve em `ai_messages` por id (read-only; respeita status!=redacted) | Teste: turno dropado por budget → `recover()` retorna payload com conteúdo integral da mensagem; recovery_rate de turns no MAXF-02 sobe de ~0 para ~1 | S | CPT-06 (MAXF-02 mede o ganho) |
| MAXF-04 | Dedup lexical determinístico (produtor de dup_group) | Duplicata não queima budget 2× | Em `conversationSummarySegments` (AiCompactionService:496-553): dup_group = hash de shingles normalizados do texto; ranker já penaliza (SegmentImportanceRanker:227-239). Sem modelo, sem I/O | Fixture com turnos repetidos: 2º duplicado dropado antes de conteúdo único; sem dups → summary byte-idêntico; % tokens duplicados reportado no metadata do receipt | S | CPT-03 (⚠️ muda summary → ver colisões) |
| MAXF-05 | Dedup semântico local em shadow (flag OFF) | Detectar repetição parafraseada que o lexical não pega | Embedding local `semantic_rag` (EmbeddingService, 384-dim) marca pares cosine>τ em SHADOW JSONL (divergência vs MAXF-04); nunca altera seleção até flip separado com gatilho de rollback pré-declarado (ROL-01) | Flag OFF → zero mudança de payload (teste parity); shadow acumula ≥50 comparações com % de pares semânticos não-lexicais | M | MAXF-04, ROL-01 |
| MAXF-06 | Importância medida por uso real (counting, não ML) | O que foi citado/recuperado depois da compactação sobe; o que nunca reapareceu comprime primeiro | Contadores determinísticos: hit no recovery executor / recall event / reaparição de digest em turno posterior → incrementa `importance` do item de estado (sink já existe no ranker :186-191, hoje 0 produtores). must_keep floor intocado | Item recuperado 1× ganha importance>0 e sobrevive ao próximo corte no mesmo budget (teste); série de context_retention_score (CPT-07) não regride; medidor dual-read (MED-01) registrado | M | MAXF-01, MAXF-02, CPT-07 |
| MAXF-07 | Compressão por referência no bloco de turnos (packs Atlas-side) | Maior corte de tokens com perda-zero real (conteúdo recuperável byte-exato) | Para consumidores com canal de rehidratação (packs/CLI, NÃO prompt cru de provider): bloco 'Ultimos turnos' vira `turn:id` + digest 80 chars; recovery_query já derivada; consumidor expande sob demanda via MAXF-03 | Ratio do bloco de turnos medido (chars antes/depois, esperado ≥3×) com retention score ≥ baseline; write_allowed inalterado; consumidor sem canal recebe formato antigo byte-idêntico | M | MAXF-03, CPT-02 (⚠️ muda summary → ver colisões) |
| MAXF-08 | Compaction-aware packs (liga o marker morto) | O pack sabe o que foi compactado e oferece re-hidratação | `AiContextPackBuilder` (+ pack Open Brain) lê último receipt + post-compaction-marker e expõe seção `compacted: {scope_type, receipt_hash, must_keep_coverage, unresolved_loss, recovery_queries}` | Thread compactada → pack mostra a seção com queries executáveis; thread sem compactação → pack byte-idêntico; nº de recovery_queries executadas pós-pack passa de 0 a >0 medido | S | MAXF-01, CPT-02 |
| MAXF-09 | Sumário hierárquico L2 local assíncrono verificado (nunca canônico) | Compressão de leitura acima do teto determinístico, sem tocar o pétreo | Job assíncrono local-only reescreve o summary; aceito SÓ se scorer der coverage==1.0 E retention ≥ L1; armazenado versionado em canal separado; L1 permanece o que receipts/hashes/certificador leem; consumo de L2 atrás de flag default OFF | L2 rejeitada registrada (não some); com flag OFF zero mudança; com ON: par (ratio, retention) por versão em evidência, ratio médio <0.6 com retention==L1 | L | MAXF-01, CPT-10 (só após L1 certificado), ROL-01 |
| MAXF-10 | Detector de compactação net-negativa (anti-inflação) | Parar de "compactar" thread pequena para o dobro do tamanho (5/6 casos reais hoje) | Em `compactLocked`: se `token_after ≥ token_before`, registrar compaction com metadata `net_negative=true` e NÃO sobrescrever thread.summary (mesma via do needs_review :185-187); receipt registra o skip | Fixture thread 3 turnos → summary não substituído; medidor (ELEV-04): % de **overwrites** net-negativos = 0 — as TENTATIVAS net-negativas continuam ocorrendo (o gatilho não muda) e viram série informativa; exigir que "compactions net-negativas caiam a 0" convidaria re-rotulagem do que conta como compaction; dual-read (MED-01) do ratio antigo vs novo | S | CPT-02 (⚠️ ver colisões) |
| MAXF-11 | Compressão do payload MEDIDA (aposenta o % heurístico) | `savings_estimate` real em vez de 28%/18%/10%/5% fixos | Após enforce do CPT-09: `compression_receipt` passa a derivar savings do plano real do allocator (compression_target_tokens aplicados) com o heurístico mantido como campo `estimate_legacy` para comparação dual-read | **≥N receipts reais (N pinado no freeze, ex.≥20) → divergência estimativa×medida reportada com denominador cru; a troca do medidor SÓ vale se a divergência exceder margem carimbada (ELEV-03); 0 receipts ⇒ mantém `estimate_legacy`, NUNCA troca no vácuo (insatisfazível por inatividade)**; MED-01 dual-read | M | CPT-09→enforce (NÃO antes) |

Ordem sugerida: MAXF-01 → 02 → 03 → 08 → 10 → 04 → 06 → 07 → 05 → 09 → 11.

## Colisões com v1

- **Soak CPT-09 (observe→shadow→enforce) — payload hasheado é intocável durante o soak:**
  - MAXF-05 e MAXF-11 tocam a vizinhança do `token_economy_hash` (AtlasTokenEconomyRuntimeService:109-111). MAXF-05 é shadow-JSONL puro (mesmo padrão CPT-08 — fora do payload e do hash, ok DURANTE o soak); **MAXF-11 muda o compression_receipt dentro do payload hasheado → PROIBIDO até o enforce flipar**; por isso dep explícita CPT-09→enforce.
  - O shadow do allocator (must-keep-shadow.jsonl) é a amostra do soak: **nenhum slice pode mudar o formato `atlas.context.must_keep_budget_allocation.shadow.v1` no meio da janela de N≥50/14d** — MAXF-05 escreve em arquivo/schema PRÓPRIO, não apenda no shadow do CPT-08.
- **Série de receipts que o soak/CPT-07 consomem:** MAXF-04, MAXF-07 e MAXF-10 mudam o texto do summary → mudam `candidate_summary_hash`, coverage e a série de `context_retention_score`. Não é proibido (o payload do token-economy não depende do summary de conversa), mas **quebra a comparabilidade da série do soak**: landar ANTES de abrir a janela de soak do CPT-09, ou depois do enforce — nunca no meio; registrar o corte de série via leitura-dupla (MED-01 do v1).
- **CPT-10 (certificador):** MAXF-02 vira insumo (recovery_rate medido) — o certificador continua author≠judge (o sampler só LÊ receipts). MAXF-08/09 não criam scope_type novo nem receipt novo (nenhuma mudança em `ALLOWED_SCOPE_TYPES`); MAXF-09 explicitamente NÃO conta para o certificador (só L1).
- **MAXF-01 é pré-condição do próprio v1:** sem a tabela no vivo, CPT-09 fica eternamente `insufficient_sample` e o CPT-10 conta denominador 0 nos mecanismos conversation/handoff/long_horizon. É ops+guard, não re-proposta de slice v1 — o v1 assumiu a tabela existente (ela existe em teste; não no vivo).
- **Rollback (ROL-01):** MAXF-05/06/09 nascem com gatilho objetivo de reversão escrito antes de qualquer flip (padrão do v1 §123); MAXF-04/07/10 são determinísticos e testáveis por fixture — reversão = revert do commit.
- **Pétreo re-afirmado:** nenhum slice põe LLM no caminho quente da compactação de conversa; write_allowed<1.0 permanece bloqueando; L2 (MAXF-09) e embeddings (MAXF-05) são assíncronos, locais, verificados e não-canônicos.

---

# MAX-G — Avaliação contínua + Eficiência (AREBA + ACOP + ARLCG)

Leitura ground-truth em 2026-07-11, head `477ce0dc89` (+working tree), com medições reais de latência (`/usr/bin/time -p`, MBP local, read-only). Todos os caminhos absolutos em `/Users/vitorepf/develop/Atlas/atlas-server`.

---

## Estado atual

### 1. LocalRagBenchmarkService — o que mede de verdade

`app/Services/Ai/Context/LocalRagBenchmarkService.php` (1.357 linhas) agrega 4 instrumentos distintos num único `report()`:

| Instrumento | Onde | O que mede | Honestidade |
|---|---|---|---|
| `cases()` router-quality | :386-430, avaliado em :436-481 | 4 casos sintéticos de GOVERNANÇA do `ContextRetrievalRouter` (fontes esperadas selecionadas, bypass bloqueado, graph governado) + latência do `plan()` (hrtime :438-444, p95 ≤ 250ms :505) | Declara `does_not_measure_retrieval_answer_quality_yet` (:537). O p95 de 250ms é só do PLANEJAMENTO do router, não do retrieval real. |
| `memory_recall_corpus` | :546-763 | Known-item self-retrieval: query = title+summary do próprio alvo, 2-6 fixtures das memórias promovidas vivas, precision@3/@5 | Declarado `self_retrieval_sanity=true` (:585) — mede regressão de embedding, não capacidade. |
| `memory_recall_golden` (RAG-05 ✅ f39af178b) | :825-970 | Carrega fixture congelada `tests/Fixtures/Context/memory_recall_golden/v1.json` (25 casos), roda recall REAL via `AtlasHybridMemoryRetrievalService::recall` (:930-940), computa recall@3/@5 + `improper_floor_discards` (:947) | **Ver "Teto atual" — o v1 só passa em ambiente phpunit semeado.** |
| `independent_precision_corpus` (R8) | `LocalRagPrecisionCorpusService.php` (557 linhas) | 23 queries parafraseadas INDEPENDENTES do texto-alvo (overlap ≤ 0.34 verificado :68-72,80), engine semântica Python REAL (`SemanticRetrievalRuntime`), precision@1/@3/@5 + recall@K; honest-unmeasured se engine ausente (:83-88) | O instrumento mais honesto do arquivo — MAS o corpus é de 24 documentos-fixture passados ao `retrieve()` (`resources/atlas/local_rag/independent_precision_corpus.v1.json`), não o store vivo. |

Evidência: `recordEvidence()` (:309-355) grava 3 eventos `LOCAL_RAG_*` hash-only no ledger. `raw_query_persisted=false` / `raw_context_persisted=false` em todo payload — invariante provider-safe pétreo (:806-810).

### 2. AREBA real — `AtlasRetrievalEvaluationBenchmarkArenaService`

`app/Services/Ai/Context/AtlasRetrievalEvaluationBenchmarkArenaService.php` (591 linhas), comando `atlas:context:evaluate-retrieval`:

- **Corpus**: 3 casos default estáticos (`defaultCases()` :210-238) — programming/forge/research. Não versionado, não julgado, editável inline.
- **Métricas**: `required_source_recall` (cobertura de source_type via freshness gate :289-297), `precision_proxy` = 1 − ruído/selecionados (:298-300), `groundedness` = FÓRMULA `coverage*0.48 + precision*0.24 + sufficiency*0.28` onde sufficiency é um match hardcoded no status do feedback (:354-366) — número composto, não medição; `context_roi` vem do ARFL.
- **Floors por risco** (:554-579): recall 0.80/0.90/1.0, groundedness 0.68/0.78/0.86, roi 0.50/0.62/0.70.
- **Persistência longitudinal**: cada `evaluate()` appenda um run-summary em `storage/app/atlas/aucri/arena-runs/YYYY-MM-DD.jsonl` (:127-162). Volume real: **2.088 runs em 09/07, 808 runs em 11/07** — porque `AtlasAucriRuntimeEnforcementService::enforce()` (:141) roda a arena a cada execução de pipeline (caller vivo: `PipelineRunExecutor.php:191` antes de todo provider call), e ARLCG.govern() + ACOP.snapshot() re-executam a arena DE NOVO dentro do mesmo request.
- **Regressão vs baseline**: `baselineRegressions()` (:507-534) compara com o ÚLTIMO run persistido, tolerância 0.02, severidade `watch` (não bloqueia, aplicada pós-hash — :100-113).
- **Estado vivo AGORA** (medido): `status=blocked`, `context_roi=0` abaixo do floor 0.50 → 4 regressões permanentes. O gate AREBA está cronicamente vermelho em produção — e como blocker de roi tem severidade critical no summary (:480-498), o sinal virou ruído de fundo. Nuance honesta: o `enforce()` só deriva blockers de freshness/privacy/compiler/token_economy (:227-234), então a arena vermelha NÃO bloqueia o pipeline — ela é carregada como audit payload (block 10, :214).

### 3. ARLCG — `AtlasRetrievalCostLatencyGovernorService`

`app/Services/Ai/Context/AtlasRetrievalCostLatencyGovernorService.php` (295 linhas), comando `atlas:context:retrieval-budget`:

- Budget policy por risco (:87-107): 1400ms/12 units (low) → 5000ms/42 units (high); `required_source_trim_policy=block_not_trim`; cache proibido em high/irreversible.
- Degraded mode com receipt (:174-200): nunca degrada silenciosamente, block quando required source seria cortada — a LÓGICA é boa.
- **O buraco**: `observed_latency_ms` default = `estimatedLatency()` = `360 + maxRefs*75` **SIMULADO** (:38, :259-268); `estimated_cost_units` = fórmula sintética `refs*mult + caseCount` (:270-279). Nenhum caller vivo injeta latência real. Medido agora: ACOP reporta `budget_latency_ms: 810` (= 360+6*75) enquanto o comando real levou **22.93s de wall**. O governador de custo/latência governa números de ficção.
- Cache decision (:144-166) depende de `arena.status=='ready'` — como a arena vive blocked (roi=0), `quality_ready=false` → **cache sempre bypass** em produção.

### 4. ACOP — o que é longitudinal de verdade

`app/Services/Ai/Context/AtlasContextObservabilityPlaneService.php` (265 linhas), comando `atlas:context:observability`:

- `snapshot()` RECOMPUTA arena+governor+feedback fresh a cada chamada (:57-66); `window_hours` é metadado cosmético — nada lê histórico (:37, :77). **ACOP não é longitudinal; é uma foto instantânea cara (22.9s medidos).**
- O que É longitudinal hoje:
  - `storage/app/atlas/evidence/acos-delta-series.jsonl` — **8 dias** desde 2026-06-12 (EVI-04 agendado 2×/dia), `AtlasAcosDeltaSeriesCommand.php` (:35-101): append idempotente por data, anti-backfill (EVI-05, :131-135), trend primeiro-vs-último. Métricas: scorecard_overall (8.88 hoje vs 7.86 Marco Zero), capture_gate_mode, semantic_recall_real, receipts do Loop. **Nenhuma métrica de retrieval/latência/custo na série.**
  - `arena-runs/*.jsonl` — per-run, mas só o ÚLTIMO run é lido (baseline watch); não há p50/p95 nem tendência multi-dia.
  - `storage/atlas/aobg/delivered-pack-ledger.jsonl` (COM-01 ✅) — 42 entradas: `delivered_refs`, `budgets` **em CHARS** (requested 6000 / estimated 5382), `policy_snapshot`, `context_pack_hash`, `ts`. **Sem campo de latência, sem tokens.**
  - `atlas:context:policy-trend` (COM-07 ✅ eac190025) — janelas day/week × N (default 4), ARFL measured-only, `formula_version` pinada (`AtlasRetrievalFeedbackLoopService.php:31`, utility explícita :242-271).

### 5. Custos reais MEDIDOS (o que ninguém estava medindo)

`/usr/bin/time -p`, 2026-07-11, cada valor 1-2 amostras:

| Operação | Wall real | Observação |
|---|---|---|
| `php artisan --version` (piso de boot) | **0.31s** | O framework não é o problema. |
| `atlas:memory:recall --peek --json` | **5.86s / 6.76s** | ~5.5s de retrieval híbrido puro. |
| `atlas:context-pack --budget=6000 --json` | **17.29s (frio) / 13.39s (quente)** | O comando do hook por turno. |
| Hook UserPromptSubmit (`atlas-ctx.sh`, dedupe off, activate off) | **15.18s** | Pago a cada prompt (dedupe só pula pack byte-idêntico). |
| Hook PostToolUse (`atlas-postedit-context.sh`, 1 Read) | **8.11s** | Pago a cada Read/Edit/Write. |
| Hook PreToolUse (`atlas-pretooluse-guard.sh`, 1 Edit) | **5.22s** | Pago a cada Edit/Write. |
| `atlas:context:evaluate-retrieval` (AREBA, persist off) | **15.79s** | Roda embutida ~800-2000×/dia via enforce. |
| `atlas:context:observability` (ACOP, persist off) | **22.93s** | Re-roda a arena 2× internamente (direta + via governor). |

**Turno típico Claude Code (1 prompt + 3 reads + 2 edits): ~15 + 3×8 + 2×(5+8) ≈ 65s de wall em hooks.** Contra isso, o único "floor" de latência codificado é o p95 ≤ 250ms do plan() do router e os budgets sintéticos do ARLCG.

**Tamanho do pack**: markdown injetado = 8.766 / 8.023 chars nas 2 amostras ≈ **~2.0-2.2k tokens/turno** (estimativa 4 chars/token); payload JSON ~41-42k chars; counts típicos: code_graph 11, reality_paths 3, memory 5.

### 6. Watchdog (contexto para deps)

WDG-01 está **em voo agora** na working tree (untracked): `app/Console/Commands/AtlasWatchdogRunCommand.php` (`atlas:watchdog:run`), `app/Services/Ai/Cognition/Watchdog/{AtlasWatchdogRunner,AtlasWatchdogCheckRegistry,AtlasWatchdogCheck,AtlasWatchdogCheckResult}.php`, `tests/Feature/Ai/Cognition/AtlasWatchdogFrameworkTest.php`. Todo check novo do v2 DEVE nascer como plugin desse registry.

---

## Teto atual

1. **O golden set v1 prova o encanamento, não o cérebro vivo.** Verificado byte a byte: `must_include.source_ref_hash` do case 1 da fixture = `sha256('golden-memory-001')` (confirmado por shasum) — os 25 hashes apontam para memórias sintéticas que SÓ existem quando o teste phpunit as semeia (`AtlasAiLocalRagBenchmarkCommandTest.php:853-899`), com body que CONTÉM a query verbatim (`'Provider safe RAG05 seed for query: '.$case['query']`, :871). No banco vivo: **0 entradas `golden-memory-*` entre 62 ativas** (verificado read-only). Rodar `atlas:ai:local-rag-benchmark` contra o cérebro real dá `recall_at_5=0` com `expected_source_available=false`. O check `author_judge_separated` passa num boolean auto-declarado (`judge_differs_from_author: true` na fixture, lido em :861) enquanto `judge: external_judge_required_before_certification` admite que o julgamento externo não ocorreu. A definição de 10/10 da dimensão RAG exige esse número contra o vivo — o instrumento atual não pode produzi-lo.
2. **ARLCG governa ficção.** Latência simulada 810ms vs 13-22s reais; custo em "units" sintéticas; nenhum caller injeta observação real. Não existe NENHUM ponto do caminho vivo (pack build, recall, hooks) que registre duração — nem o delivered-pack-ledger tem campo de latência.
3. **A avaliação contínua roda no lugar errado e caro.** A arena roda 800-2000×/dia DENTRO do enforce (custo CPU repetido 2-3× por request via ACOP/ARLCG re-execução), mas o resultado (a) não bloqueia nada (blockers do enforce vêm de outros 4 blocos), (b) está cronicamente blocked por roi=0, (c) não roda NUNCA como gate de land — não há suite de retrieval no `atlas:pregate`/`atlas:land`/test:impacted.
4. **Nada mede tokens, nem tokens ÚTEIS.** Budgets em chars; ROI do ARFL existe (used_ratio, post_execution_utility com formula_version — COM-04/07/11 ✅) mas nunca é dividido pelo custo entregue.
5. **ACOP não acumula.** A única série real (delta-series, 8 dias) não carrega retrieval/latência/custo; window_hours do ACOP é decorativo.
6. **Régua única, congelada uma vez.** Não existe protocolo para subir a régua: v1 é o único set, e qualquer edição nele invalida o hash congelado — sem mecanismo vN, "melhorar o set" e "quebrar a série" são a mesma operação.

---

## Fronteira

(local-first, provider-safe, anti-Goodhart; veredito por técnica)

### F1. Eval contínuo em CI/land — regressão de recall vira teste vermelho
**Veredito: SIM, com recorte de custo.** Rodar a arena real (15.8s) no pregate que promete ≤3s (`AtlasPregateCommand.php:34`) é inviável. O recorte certo: suite phpunit determinística contra corpus VERSIONADO (fixture própria, sem DB vivo), acionada pelo `test:impacted` existente quando o diff toca `app/Services/Ai/Context|Memory` — exatamente o padrão que o RAG-05 phpunit já usa, mas com aceite pinado ao corpus-hash (não a baseline móvel). Ganho mensurável: toda regressão de recall@K em código de retrieval bloqueia o land (hoje: 0 bloqueios possíveis). Custo: segundos, só em lands impactados. Medição honesta: o floor vem do hash do corpus versionado; mudar o corpus = novo vN + novo julgamento, nunca edição in-place.

### F2. Golden sets EVOLUTIVOS versionados (v2, v3, …) — o mecanismo de subir o teto para sempre
**Veredito: SIM — é a peça central do MAX.** O defeito do v1 (hashes de seeds de teste) tem conserto barato porque `sourceRefHashes()` (:1039-1050) JÁ aceita `lineage.content_hash`: o v2 ancora `must_include` no **content_hash de entradas REAIS ativas** do registry vivo — verificável contra o banco vivo E reproduzível em phpunit semeando por content-hash (sem eco da query no body). Protocolo vN: (a) cada set é um arquivo novo (`v2.json`), congelado por hash NO LEDGER com **receipt de julgamento real** (veredito do juiz gravado como evento, não boolean na fixture); (b) vN nunca é editado — régua sobe ADICIONANDO vN+1 com casos mais difíceis; (c) o report vira matriz por versão (`recall@5[v1_seed_env]`, `recall@5[v2_live]`, …) — séries por versão jamais se misturam. Ganho: o teto sobe indefinidamente sem invalidar nenhuma série passada. Anti-Goodhart: quem autora vN não julga vN; query sem eco do alvo (regra do independence check do R8, reusar `queryIndependenceReport`).

### F3. Métricas por camada — recall@K por fonte, MRR, nDCG local
**Veredito: SIM, quase grátis.** Os itens do recall já carregam fonte (registry/verbatim/semantic/compounding) e posição; MRR e nDCG são matemática pura sobre os MESMOS runs do golden vN — zero corpus novo, zero chamada extra. Ganho: diagnóstico "qual camada falhou" (hoje o recall@5 agregado esconde se o semantic está morto e o lexical carregando). Medição honesta: report-only por ≥2 janelas antes de qualquer uso como gate (nDCG como gate prematuro convida tuning para a métrica).

### F4. Telemetria de latência p50/p95 por operação com floors de eficiência
**Veredito: SIM — maior alavanca de eficiência do bloco.** Hoje: 65s/turno em hooks, medido por ninguém. Mecanismo: hrtime no seam ÚNICO (build do pack em `AtlasOpenBrainContextPackService` + `AtlasHybridMemoryRetrievalService::recall` + duração do próprio hook via bash), append hash-only `{op, ms, refs, budget_chars, ts}` em JSONL local com rotação diária; `atlas:context:latency --json` reporta p50/p95 por operação/dia; check-plugin WDG-01 alerta quando p95 fura o floor. Floor inicial DERIVADO da medição (ex.: pack p95 < 15s → depois aperta), nunca inventado. Ganho: (a) ARLCG passa a receber `observed_latency_ms` REAL — deixa de ser ficção sem mudar sua lógica; (b) o hotspot dos 13s vira visível e otimizável com antes/depois auditável (dual-read MED-01). Custo: ~0 (um hrtime + um fwrite).

### F5. Custo por token de contexto ÚTIL (ROI real)
**Veredito: SIM, por JOIN — não por medidor novo.** As duas metades já existem: delivered-pack-ledger (chars entregues por pack) + ARFL measured (used_ratio, post_execution_utility, formula_version). Slice: `useful_tokens = (delivered_chars/4, rotulado estimate) × used_ratio` de eventos `measured=true` apenas; exposto como campo ADITIVO na série do policy-trend (COM-07), com `formula_version` própria. Ganho: a pergunta "o pack de 2.2k tokens/turno paga o que custa?" ganha número com denominador honesto. Anti-Goodhart: entregar pack menor não infla o ratio (used_ratio já pondera pelo budget consumido — regra do COM-11); mudar a fórmula = novo formula_version = série nova.

### F6. Canário de qualidade diário barato (queries reais do dia re-avaliadas)
**Veredito: SIM, com desenho provider-safe em 2 estágios.** O invariante `raw_query_persisted=false` proíbe query crua em report/ledger — então o canário v1 NÃO usa query crua: replay por refs — para os top-N `flow_id` do delivered-pack-ledger do dia, re-monta o pack e verifica estabilidade de refs, floor-discards e freshness, mais um run do golden vN (queries já congeladas e revisadas). Roda como plugin diário do WDG-01, alerta no ledger de gaps (EVI-01). Estágio 2 (OPCIONAL, flag default-off, decisão explícita do operador): ring-buffer LOCAL de queries do hook (gitignored, prune 7d, nunca exportado, nunca em report) para amostrar queries reais — é mudança de política de persistência, não pode entrar por default. Ganho: drift de qualidade detectado em D+1, custo = 1 run/dia (~30-60s), não 800/dia.

### F7. Marco Zero v2 — congelar novo baseline sem invalidar a série v1
**Veredito: SIM, versionado, nunca in-place.** Quando o v1 certificar 10/10 (gatilho objetivo: veredito `confirmado` do ADV-01 para os 6 certificadores), o operador roda um freeze explícito: novo arquivo `marco-zero-acos-v2-<data>.json` + NOVA série `acos-delta-series.v2.jsonl` com métricas ampliadas (retrieval golden vN, p95 de latência, custo/token útil — as réguas que o v1 não tinha). A série v1 recebe uma linha final `sealed` e vira read-only para sempre; o comando ganha `--baseline-version` (default v1 até flip de config auditado); o trend report mostra as duas séries lado a lado, jamais fundidas. Ganho: o "N×M compôs?" continua respondível na régua velha E a régua nova nasce no dia certo. Anti-Goodhart: selar v1 no mesmo commit que congela v2 — impossível "escolher a série que ficou bonita".

### F8. Dieta do gate AUCRI — arena única por request
**Veredito: SIM, higiene pura.** ACOP.snapshot() roda arena direta (:57) + governor que roda arena de novo (:33 do ARLCG); enforce roda tudo por pipeline-run → 800-2.000 appends/dia e CPU 2-3× repetida. Memo request-scoped (keyed por risk + hash dos cases, mesmo processo) corta as re-execuções sem mudar nenhum hash de payload (arena_hash é determinístico por conteúdo, :96-98 — mesmo input ⇒ mesmo hash). Ganho medido pela própria F4 (antes/depois no p95 do enforce). Risco: nenhum — não muda semântica, só evita recomputar o idêntico.

### Recusados
- **Juiz LLM contínuo por land** — custo provider por land viola local-first/barato; juiz externo só no congelamento de vN e no ADV-01 (já no v1).
- **Dashboard/UI de observabilidade** — viola o foco terminal-first (doc priority 100); a superfície é `--json` + WDG-01.
- **Métrica composta única "retrieval score"** — groundedness da arena já mostra o perigo: fórmula vira alvo. Métricas cruas por camada + floors simples.
- **Perseguir p95 250ms do pack** — floor herdado do router-plan; para o pack completo seria inalcançável e convidaria a mutilar o pack para passar a régua. Floors nascem da medição real (F4).

---

## Slices candidatos

Formato: id — título · goal · mecanismo · aceite mensurável · esforço · deps (v1 entre colchetes).

**MAXG-01 — Latency ledger real por operação `[MEDIDOR]`** · E:M · deps: [MED-01; WDG-01 para o check]
- Goal: latência p50/p95 REAL de pack/recall/hook, hoje inexistente, vira série local barata.
- Mecanismo: hrtime nos seams `AtlasOpenBrainContextPackService` (build) e `AtlasHybridMemoryRetrievalService::recall`; hooks gravam a própria duração; append hash-only `{op, ms, refs, budget_chars}` em `storage/atlas/aobg/latency-ledger/YYYY-MM-DD.jsonl`; comando read-only `atlas:context:latency --json` (p50/p95 por op/dia); check-plugin no registry WDG-01 com floor derivado da 1ª semana de dados.
- Aceite: após 1 dia de uso real, `atlas:context:latency --json | jq '.ops.pack.p95_ms'` > 0 e consistente com `/usr/bin/time` (±20%); zero campo de texto cru no ledger; leitura dupla MED-01 registrada no land (simulado ARLCG vs real).

**MAXG-02 — ARLCG consome latência real (mata o 360+75×refs)** · E:S · deps: [MAXG-01]
- Goal: o governador de custo/latência governa observação, não estimativa.
- Mecanismo: `govern()` recebe `observed_latency_ms` do latency-ledger (p95 da janela do flow_id) quando disponível; `estimatedLatency()` vira fallback rotulado `basis: estimated|observed` no receipt.
- Aceite: `atlas:context:retrieval-budget --json | jq '.receipt | {observed_latency_ms, basis}'` → `basis=observed` com valor vindo do ledger; teste que prova que sem ledger o fallback declara `basis=estimated` (nunca finge).

**MAXG-03 — Suite de retrieval no land (test:impacted) com corpus versionado** · E:M · deps: [MAXG-04; v1: RAG-05]
- Goal: regressão de recall em código de retrieval bloqueia o land — hoje bloqueia zero.
- Mecanismo: suite phpunit determinística que semeia por content-hash do golden v2 (sem eco de query no body), computa recall@3/@5 e falha sob o floor PINADO ao hash do corpus; registrada no mapeamento do `test:impacted` para paths `app/Services/Ai/Context/**` e `app/Services/Ai/*Memory*`.
- Aceite: mutação proposital no ranker (ex. inverter ordenação) ⇒ suite vermelha ⇒ `atlas:land` recusa; revert ⇒ verde; tempo da suite < 60s.

**MAXG-04 — Golden set v2 LIVE-anchored + protocolo evolutivo vN** · E:M · deps: [v1: RAG-05, MEM-04, CORP-01]
- Goal: golden que mede o cérebro VIVO (não o phpunit semeado) e régua que sobe sem tocar régua velha.
- Mecanismo: `tests/Fixtures/Context/memory_recall_golden/v2.json` com `must_include` por `content_hash` de entradas reais ativas (caminho :1046-1049 já aceita); queries reescritas sem eco (validadas pelo independence check do R8); julgamento externo REAL = evento no ledger com hash do set + veredito do juiz (não boolean na fixture); `memoryRecallGoldenFixtureReport()` vira loop sobre `v*.json` emitindo matriz por versão; v1.json intocável (teste RAG-05 continua passando).
- Aceite: `atlas:ai:local-rag-benchmark --json | jq '.memory_recall_golden_versions.v2 | {cases, recall_at_5, judged}'` → cases ≥ 25, `judged=true` via evento de ledger citado por hash, e recall_at_5 MEDIDO CONTRA O BANCO VIVO (expected_source_available=true em ≥ 90% dos casos — hoje seria 0%).

**MAXG-05 — Recall@K por fonte + MRR + nDCG local** · E:S · deps: [MAXG-04]
- Goal: diagnóstico por camada em vez de agregado cego.
- Mecanismo: agregação extra sobre os mesmos runs do golden vN (fonte + posição já presentes nos itens); report-only por 2 janelas (flag `gate=false` hardcoded no primeiro land).
- Aceite: output expõe `by_source.{registry,verbatim,semantic}.recall_at_5`, `mrr`, `ndcg_at_5`; teste de propriedade: nDCG monotônico sob melhora de posição; nenhum floor novo neste slice.

**MAXG-06 — Canário diário replay-by-refs (sem query crua)** · E:M · deps: [MAXG-04; v1: COM-01, WDG-01, EVI-01]
- Goal: drift de qualidade detectado em D+1 por 1 run barato/dia, não 800 runs cegos.
- Mecanismo: plugin diário do WDG-01: top-N flow_ids do delivered-pack-ledger do dia → re-monta pack → compara refs/floor-discards/freshness com o entregue; + 1 run do golden vN; divergência acima do limiar ⇒ issue no ledger de gaps. Estágio 2 (ring-buffer local de queries, default-OFF, flip explícito do operador) documentado mas não ligado.
- Aceite: `atlas:watchdog:run --json` lista o check com `status` e `evidence.{flows_checked, ref_stability, golden_recall_at_5}`; custo medido do run < 120s; zero query crua em qualquer output (grep no artefato).

**MAXG-07 — Custo por token útil na série COM-07** · E:S · deps: [v1: COM-04, COM-07, COM-11]
- Goal: ROI real do contexto — tokens entregues × utilidade medida, em série.
- Mecanismo: join delivered-pack-ledger (chars→tokens, `estimate_basis: chars_div_4`) × eventos ARFL `measured=true` (used_ratio); campo ADITIVO `cost_per_useful_token` no policy-trend com `formula_version` própria; janelas existentes intocadas.
- Aceite: `atlas:context:policy-trend --json | jq '.windows[0].cost_per_useful_token'` presente com `formula_version`; série anterior byte-idêntica nos campos antigos; denominador só measured (teste que evento inferred não entra).

**MAXG-08 — Marco Zero v2 + série v2 (selar, nunca substituir)** · E:S · deps: [v1: ADV-01, EVI-04/05/06]
- Goal: quando o v1 certificar 10/10, congelar régua nova sem invalidar a série v1.
- Mecanismo: `atlas:acos:marco-zero --freeze-v2` (gated: exige veredito ADV-01 confirmado no ledger): grava `marco-zero-acos-v2-<data>.json` (inclui golden vN recall, p95 pack, cost_per_useful_token), appenda linha `sealed` na série v1, cria `acos-delta-series.v2.jsonl`; `--baseline-version` no delta-series (default v1 até flip auditado); report dual-view.
- Aceite: pós-freeze, série v1 recusa append novo (teste), v2 appenda; `--report` mostra as duas janelas separadas; arquivos v1 byte-idênticos exceto a linha `sealed`.

**MAXG-09 — Dieta do gate: arena única por request** · E:S · deps: [MAXG-01 para medir o ganho]
- Goal: parar de recomputar a arena 2-3× por enforce e appendar 800-2.000 runs/dia idênticos.
- Mecanismo: memo request-scoped (Laravel container scoped instance) keyed por `risk + golden_set_hash`; persistência do run-summary passa a 1×/request; `arena_hash` inalterado (determinístico por conteúdo).
- Aceite: ACOP snapshot passa a registrar 1 run no JSONL do dia (não 2-3); p95 do enforce cai (leitura dupla via MAXG-01); todos os testes de arena existentes verdes sem edição.

**MAXG-10 — Floor de eficiência do turno (hook diet, guiado por medição)** · E:M · deps: [MAXG-01, MAXG-09]
- Goal: baixar os ~65s/turno de hooks com prova antes/depois — sem cortar capacidade.
- Mecanismo: com 1 semana de latency-ledger, atacar o maior hotspot medido (candidatos visíveis: boot+bootstrap repetido por hook — 0.31s é só o framework, o resto é retrieval/gate; cache de pack por prompt-hash já existe via dedupe — estender a janela; PostToolUse com early-exit por extensão/no-op); cada otimização landa com leitura dupla (p95 antes/depois) no ledger.
- Aceite: p95(UserPromptSubmit hook) reduz ≥ 30% vs semana-baseline SEM queda no golden vN nem nos counts médios do pack (anti-Goodhart: as duas réguas no mesmo aceite); floors então pinados como check WDG-01.

Ordem sugerida: MAXG-01 → 09 → 02 → 04 → 03 → 05 → 07 → 06 → 10 → 08 (08 só dispara no gatilho ADV-01).

---

## Colisões com v1

| Artefato v1 | Regra de não-colisão |
|---|---|
| Série 30d `retrieval_eval` (RAG-02) + contadores vida-inteira | Intocáveis. MAXG-05/07 só ADICIONAM campos; qualquer mudança de medidor passa por MED-01 (leitura dupla) — nunca reset. |
| Golden v1 (`v1.json`, frozen_set_id `memory_recall_golden_2026_07_rag05_seed`) + teste RAG-05 | Arquivo e hash intocáveis; MAXG-04 cria `v2.json` NOVO e um report multi-versão que mantém a chave `memory_recall_golden` (v1) byte-compatível. Nunca "consertar" o v1 in-place — ele fica como registro honesto do que a onda 3 congelou. |
| `acos-delta-series.jsonl` + `marco-zero-fable-2026-06-11.json` | Append-only; MAXG-08 sela com 1 linha e versiona em arquivos novos; jamais reescreve ou funde séries. |
| WDG-01 (em voo na working tree: `app/Services/Ai/Cognition/Watchdog/*`) | TODOS os checks MAXG (01, 06, 09-ganho, 10-floor) nascem como plugins do `AtlasWatchdogCheckRegistry` — zero scheduler/comando de vigia paralelo (mesma regra do RAG-10/RAG-12). |
| RAG-12 / COM-10 (onda 4, ⬜) | Já vigiarão recall_at_5/measured_share/concentração — MAXG não duplica esses checks; MAXG cobre o que eles NÃO cobrem (latência real, land-gate, versões vN, custo/token, canário replay). MAXG-04 muda o denominador que o RAG-12 vigiará (recall vivo, não seed) — landar MAXG-04 ANTES de pinar o threshold do RAG-12, ou registrar leitura dupla. |
| `arena_hash` / `governor_hash` / `snapshot_hash` (schemas persistidos) | MAXG-02/09 não alteram payloads hasheados: latência real entra no receipt já existente (`observed_latency_ms` é input suportado, :38) e o memo não muda conteúdo. Campo novo `basis` = mudança de schema → bump de `RECEIPT_SCHEMA` com teste de compat. |
| Série COM-07 (policy-trend) + `formula_version` (COM-11) | MAXG-07 é aditivo com formula_version própria; mudar a fórmula do custo/token reseta SÓ a sub-série nova, nunca a série de ROI existente. |
| Invariante provider-safe `no_raw_query_or_context_in_report_or_ledger` | MAXG-06 estágio 1 não persiste query; estágio 2 (ring local) é flip de política default-OFF, decisão explícita do operador, nunca embutido. |
| Charter de autonomia (06/07) | Checks alertam e etiquetam no Diário; flips de floor/enforce seguem ROL-01 (condição de reversão pré-declarada) — o watchdog nunca flipa sozinho. |

---

## (vi) Colisões consolidadas com o v1 (mapa único)

| Área Max | O que NÃO pode ser tocado enquanto o v1 corre | Como a fronteira convive |
|---|---|---|
| B/D/G — ranking, grafo, avaliação | Golden set RAG-05 congelado; qualquer mudança de ranking/travessia muda o que ele mede | Golden v2 próprio (content_hash real, juiz externo) ANTES de qualquer mudança de ranker; leitura dupla v1/v2 durante a transição |
| E — composição | Janelas ARFL de 4 semanas (COM-07/10); COM-01 é dono único do namespace de refs | MAXE usa os refs COM-01 (nunca formato novo); mudanças de composição registram leitura dupla na janela |
| F — compactação | Soak CPT-09 (observe→shadow→enforce); payload hasheado não pode mudar durante o soak | MAXF-11 e mudanças de summary PROIBIDOS dentro da janela de soak; shadow em canal separado |
| G/EVI — séries | Série longitudinal de 30d (medidor congelado EVI-05/06) | Série v2/Marco Zero v2 só se cunham APÓS a certificação v1; nunca editar a série v1 |
| Todas | Séries de uso (RAG-01/MEM-03) | Sub-queries e sondas da fronteira rodam em peek (`record_usage=false`) |
| Todas | Arquivos compartilhados com o executor v1 (routes/console.php, settings.json, docs de obra) | Reler do disco antes de editar; edição pontual; commit imediato escopado |

## (vi-b) MAPA DE DONO ÚNICO — dedup cross-área (slices gêmeos fecham num landing só)

> O plano foi escrito por 7 leitores independentes; leitores de áreas diferentes propuseram o MESMO órgão sem se ver. Esta tabela resolve a propriedade: **o DONO implementa; o GÊMEO fecha no MESMO landing do dono (os aceites de AMBOS rodam nesse landing; o scoreboard marca os dois com o mesmo sha).** Implementar um gêmeo separadamente é violação de reuso e desqualifica o landing.

| Superfície/órgão | DONO (implementa) | Gêmeo/relacionado (fecha junto) | Regra |
|---|---|---|---|
| Daemon de embeddings + memo/cache de query | **MAXA-01 (daemon) + MAXA-02 (memo/cache)** | MAXB-01 | Mesmo órgão visto por dois leitores; UM daemon, UM memo. Os aceites do MAXB-01 (recall wall −60%, diff de JSONs vazio, golden re-rodado idêntico) rodam no landing de MAXA-01/02 |
| Golden set v2 (fixture `memory_recall_golden/v2.json`) | **MAXG-04** (live-anchored + protocolo vN + juiz por evento no ledger — a versão mais completa) | MAXB-02 | UM fixture, UM freeze, UM juiz. Os aceites do MAXB-02 (cases ≥25, targets_available==cases, v1 byte-idêntico) rodam no landing do MAXG-04 |
| tsvector+GIN + fusão RRF do braço lexical | **MAXB-03** (fusão v2 é o quadro maior) | MAXA-08 | UMA coluna tsvector por tabela, UM RRF. O subconjunto termo-exato do R8 (aceite do MAXA-08) roda no landing do MAXB-03; o degrau-2 SPLADE segue exclusivamente via RAGX-11 |
| Seam de rerank L3-6 (`semanticallyReorderMemory`) | **RAGX-05** é o dono do ORQUESTRADOR (cascata bi→cross→LLM) | MAXB-06 (cross-encoder) e MAXA-09 (late-interaction) | NÃO são gêmeos (técnicas distintas, A/Bs distintos e independentes) — mas há UM único seam/switch: MAXB-06 e MAXA-09 landam como ESTÁGIOS plugáveis medidos separadamente; jamais dois orquestradores de rerank |
| Telemetria de latência do pack | **MAXG-01** (a série p50/p95 por operação) | MAXE-04 (timings por seção) | Uma régua, duas granularidades: MAXE-04 grava por-seção no entry do COM-01 E espelha o agregado para a série do MAXG-01 — zero série paralela de latência |

**Regra permanente (vale para todo slice futuro):** antes de implementar qualquer slice que crie {daemon, cache, coluna de índice, série de latência, golden, orquestrador de rerank, watchdog}, consultar esta tabela e o registry ELEV-20s; superfície já com dono ⇒ o slice novo é estágio/consumidor, nunca segunda implementação.

## (vii) Critério de conclusão do ACOS Max

Todos verdes NO MESMO CORTE, com hashes carimbados, e nenhum medidor v1 tocado:

| # | Verificação | Alvo |
|---|---|---|
| 1 | Latência real instrumentada (p95, série sustentada ≥14 dias) | context-pack ≤ 2s · recall ≤ 1s · hooks por turno ≤ 5s total · zero fórmula-ficção no ARLCG |
| 2 | Golden set v2 (content_hash de memórias reais, congelado por juiz externo) | recall@5 ≥ 0,90 contra o corpus VIVO · improper_floor_discards = 0 |
| 3 | Cobertura de embeddings | 100% memórias/notes + código/KB indexados incrementalmente por source_hash · 100% dos vetores com provenance (`embedding_model`+`embedded_content_hash`) · zero vetor de provider externo na espinha |
| 4 | Citabilidade e ARFL | refs impressos por item no pack injetado · measured_share ≥ 0,90 em janela real · multiplicadores adaptativos aplicando de fato |
| 5 | Grafo | linkers evidence/doc_memory produzindo arestas reais · agregados dos 231k links importados · PageRank/comunidades no retrieval com ganho provado no golden v2 |
| 6 | Agentic | decomposição + suficiência com faltas nomeadas + hop-2 budgetado vivos no caminho REAL do pack · `is_agentic=true` verdadeiro no código, provado por teste de comportamento |
| 7 | Compactação | tabela de receipts restaurada + guard · compressão líquida positiva provada em dados reais · fidelity por recovery-test amostral ≥ alvo · zero write sem receipt |
| 8 | Órgãos unwired | lista do item (i) zerada — cada órgão ligado com produtor/consumidor real OU aposentado com justificativa |
| 9 | Eval contínuo | suite de retrieval no caminho de land (regressão = teste vermelho) · arena fora do hot path · canário diário barato |
| 10 | ADV-Max | re-prova adversarial externa dos certificadores novos — zero refutação pendente |

**Regra final herdada do v1:** se fechar qualquer item exigir relaxar um medidor, o plano está refutado naquele ponto — o slice volta para a onda do defeito com justificativa registrada no ledger.

---

## (viii) Programa ASI-Substrato — a ordem integrada de implementação (F0–F3)

As 5 lentes convergem num único veredito: o motor está pronto, o cérebro está construído, e o produto composto não gira. O multiplicador real de hoje é **M≈0,9–1,1 no interativo** (o imposto de ~65s/turno de hooks aproximadamente cancela o ganho) e só no delegado M≈1,3–1,8; o **flywheel de auto-melhoria está code-complete com RPM≈0** (nenhuma volta outcome→memória→recall→execução registrada no vivo — a única "evidência" da espinha era eco de phpunit no ledger); o **freio está atrás do motor** (imunidade G0–G8 guardando 1 de 32 portas de escrita de memória, ConstitutionGate deletado num commit "save", rollback alert-only e unitário); o **processo aguenta ~1×** (spawn-por-op, fan-out de hooks sem backpressure, Postgres em defaults de fábrica) enquanto o hardware aguenta 100×; e o **eixo reflexivo é órfão** — modelo-de-si, memória procedural cross-executor e metacognição de decisão existem como fragmentos que nenhum decisor consome. O achado sistêmico, repetido nas 5 lentes, é um só: **construído-mas-não-ligado**.

**Definição operacional de "capacidade máxima composta"** (o que este programa entrega, mensurável): (1) **nunca re-resolver o resolvido** — o que o Atlas aprendeu entra por uma porta com imunidade, é recallado com ref citável e reusado pelos 3 executores; (2) **output confiável sem revisão** — verificação em enforce, com rollback composto por decision-id como salvaguarda de máquina; (3) **melhorar sozinho toda semana** — flywheel com voltas reais registradas ponta a ponta e meta-aprendizado comendo dados vivos, não vazio.

As 4 fases são pré-condição encadeada: **F0** para de subtrair e religa o freio (não se liga volume com a admissão aberta); **F1** liga o que já existe (não se constrói órgão novo com os atuais desligados); **F2** constrói o eixo reflexivo (não se aprende sobre si sem fluxo real fluindo); **F3** sobe o substrato para 10–100× (não se escala um processo que subtrai). O programa herda por inteiro o contrato pétreo do v1/Max: anti-Goodhart, author≠judge, MED-01 (medidor congela antes do produtor), ROL-01 (rollback pré-declarado), charter de autonomia 06/07 (zero fila de aprovação humana; flips de master são gatilho operacional do operador) e as regras de convivência da seção (ii) — **nenhum slice ASI toca medidor v1/Max congelado; réguas novas nascem versionadas**.

---

### F0 — Parar de subtrair + religar o freio

**Racional:** enquanto o substrato subtrai (~65s/turno, packs de 13–18s, load 11 com 1 sessão) todo ganho de F1–F3 é pago com juros; e enquanto a admissão de memória tem 31 portas sem imunidade e o freio constitucional está deletado, ligar volume (F1) seria multiplicar erro pelo mesmo fator que multiplica acerto. F0 é barato, quase todo S, e é a única fase sem dependência de dados novos.

**Ordem de execução:**

1. `→ MAXG-01-mínimo (antecipado de M1 — ELEV-13)` — latency ledger real (p50/p95 pack/recall/hooks; a versão mínima = hrtime no hook + append JSONL, E:S): a régua congela ANTES do maior produtor de eficiência do programa (MAXE-02/03) — landar o dedupe antes da régua violaria a própria Lei 4/MED-01 e o antes/depois da maior alavanca ficaria para sempre não-medido. Se o dedupe precisar landar no mesmo dia, as medições manuais de 11/07 (~65s/turno; 15,18s/8,11s/5,22s por hook) são carimbadas no Evidence Ledger como baseline dual-read degradado ANTES do primeiro commit de eficiência.
2. `→ MAXE-02/03 (onda M0)` — dedupe dos hooks em duplicata byte-idêntica + TTL do activate + timeout duro: remove o divisor ×0,65–0,8 de TODO o interativo pelo menor custo do programa inteiro — com antes/depois lido da régua do passo 1.
3. `→ MAXA-02 → MAXA-01 → MAXB-01 + MAXE-04/05 (onda M2)` — memo de embed, daemon residente de embeddings, cache de recall/pack e append real no ledger COM-01: mata o piso de 13–18s por pack e o replay+rewrite O(N) por write.

**ASI-01 — ConstitutionGate religado com caller vivo + sentinela anti-deleção** · E:M · fase F0 · deps: [] *(chip `task_3a4d6896` já em execução paralela — este slice VALIDA e sela, não duplica)*
- Goal: o freio property-gated de self-edit volta a existir como código vivo com caller real; o teste-sentinela sai do vermelho e nunca mais fica silenciosamente órfão.
- Mecanismo: o chip `task_3a4d6896` reimplanta o `AtlasLoopConstitutionGateService` (deletado no commit `048b6468a9`, −93 linhas; teste `AtlasLoopConstitutionGateServiceTest` falha ao carregar; `commitWithConstitutionToken` em `AtlasLoopMergeActuator.php:126` tem zero callers em `app/`). Este slice: (a) valida a entrega contra os 3 critérios do asi-3 — serviço existe, o actuator ganha ≥1 caller vivo do caminho token-gated, e o verdict é **property-gated por prova/nonce, nunca boolean setado pelo caller** (o padrão forjável `operator=>true` da `AtlasAutonomyLadderRuntimeService.php:257-284` é o anti-exemplo; se o chip não cobrir a ladder, registrar gap no ledger — não expandir escopo aqui); (b) adiciona **teste-sentinela de presença** que falha se o serviço ou o caller sumirem de novo (a lição: freio deletado em commit "save" sem substituto); (c) registra o veredito no Evidence Ledger. O que NÃO fazer: segundo gate paralelo; tratar a denylist FORBIDDEN (substring, falha-aberto no novo — `AtlasLoopHarnessGuard.php:371`) como substituto suficiente.
- Arquivos: `app/Services/Ai/AutonomousEvolution/AtlasLoopMergeActuator.php:126`, serviço restaurado pelo chip, `tests/.../AtlasLoopConstitutionGateServiceTest.php`, `app/Services/Ai/AutonomousEvolution/AtlasLoopHarnessGuard.php`.
- Aceite: `php artisan test --filter=AtlasLoopConstitutionGateServiceTest` verde; `rg -n "commitWithConstitutionToken" app/ | wc -l` ≥ 2 (definição + caller); **teste negativo que prova o bloqueio**: commit sintético mirando path de FORBIDDEN_SELF_TARGETS sem token válido é RECUSADO com receipt (nunca passa silencioso).
- Risco: chip entregar "religado de nome" (gate sem caller) — o aceite exige o caller e o caso negativo; colisão com o executor do chip — reler do disco antes de editar (regra ii.4).

**ASI-02 — Porta única de admissão de memória (31 portas → 1, com G0–G8)** · E:M/L · fase F0 · deps: []
- Goal: TODA escrita de `AtlasMemoryEntry` passa por UM chokepoint que roda a imunidade G0–G8 — hoje a cerca guarda 1 de 32 entradas e o corpus é envenenável por qualquer writer.
- Mecanismo: evidência asi-3 §1.1: o `CognitiveImmunePromotionGateEvaluator` (G0–G8, confirm-gates pending até prova positiva, forbid G3/G4 em secret/contradição) só é chamado em 2 sites (command + `AtlasOpenBrainWriteBackService.php:427`); o caminho primário `AtlasMemoryCandidateGateService.php:153-193` usa `qualityReport()` próprio e fraco (sem secret, sem contradição-com-newer, sem probation); **31 serviços chamam `registry->record()` direto** contornando ambos. Desenho: a avaliação G0–G8 move para DENTRO do write-path do `AtlasMemoryRegistryService` (record/curate) — o chokepoint é a porta que todos JÁ usam, não uma porta nova que ninguém chamaria. Escada: modo `observe` (grava verdicts por writer sem bloquear, ≥1 semana, relatório por origem) → `enforce` (forbid-gates bloqueiam o write com receipt; confirm-gates seguram promoção em probation — a entry entra quarentenada, nunca some silenciosa). O CandidateGate passa a delegar ao mesmo evaluator (o qualityReport vira complemento). Teste arquitetural congela o invariante: nenhuma escrita no model fora do registry. O que NÃO fazer: criar wrapper novo "que os 31 writers deveriam adotar" (seria a 33ª porta); afrouxar G0–G8 para acomodar writer ruidoso — writer bloqueado é sinal e vai para o ledger de gaps. **ELEV-08 (escopo REAL da porta):** a porta única cobre as **4 vias de ingestão do recall** — além de `AtlasMemoryEntry`: o `SemanticNoteIndexer` (169 notes indexadas de arquivo markdown — hoje um bypass COMPLETO de G0–G8: nota envenenada entra no pack como memória sem passar por nenhum gate) e o writer de compounding rodam no mínimo G3 (secret scanner real, MAXI-01) + o classifier inbound (MAXM-03) na ingestão; verbatim já é gated por `external_ai_allowed`. E o teste arquitetural varre também `DB::table()`/upsert/SQL cru contra as tabelas de memória — varredura Eloquent-only deixaria a 33ª porta aberta via query builder.
- Arquivos: `app/Services/Ai/AtlasMemoryRegistryService.php` (write-path :70/:87/:138), `app/Services/Ai/Cognition/CognitiveImmunePromotionGateEvaluator.php`, `app/Services/Ai/Memory/AtlasMemoryCandidateGateService.php:153`, `app/Services/Ai/AtlasOpenBrainWriteBackService.php:427`, `tests/Feature/Ai/Memory/MemoryAdmissionChokepointTest.php` (novo).
- Aceite: phpunit: entry com secret submetida por 3 vias distintas (candidate gate, writeback, `record()` direto) é bloqueada nas 3 com receipt G3; teste arquitetural: varredura de escritas diretas ao model fora do registry = 0 (lista de exceções vazia e congelada em teste); em enforce, `gate_evaluations == writes` numa janela com **≥50 writes reais** (denominador mínimo — inatividade não satisfaz). **ELEV-14 (anti-deadlock de denominador, declaração explícita):** a janela observe PODE e DEVE ser preenchida com o músculo Autônomos ligado a 1 worker — observe não bloqueia nada, é seguro por construção; sem esta declaração, o ≥50 espera volume que só o flip de ASI-06 (gated atrás DESTE observe) gera, e F1 trava em círculo por semanas.
- Risco: writer legítimo de alta frequência (Evidence/*, RealExecution/*) degradar — por isso observe-primeiro com relatório por writer; admissão é fail-closed por definição (é o freio), mas probation ≠ perda: tudo quarentenado é recuperável e aparece no digest.

**ASI-03 — Postgres dimensionado para o corpus real** · E:S · fase F0 · deps: []
- Goal: o DB físico para de rodar com defaults de fábrica (shared_buffers 128MB para um DB de 3,7GB/391 tabelas) antes que o M3 (290k embeddings) torne o working set ≫ buffers e tudo vire I/O-bound.
- Mecanismo: evidência asi-2: tuning de memória no container Postgres 16 @5433 — `shared_buffers` 4–8GB, `effective_cache_size` ~24GB, `maintenance_work_mem` dimensionado para os builds HNSW futuros (MAXA-07), autovacuum ajustado para `atlas_memory_entry_usages` (57k rows/45d hoje, 100× projetado). Arquivo de conf versionado no repo (reversível por arquivo). O que NÃO fazer: particionamento preventivo (gatilho = medição >1M rows, nunca antecipação), pgbouncer, sharding (asi-2 §5).
- Arquivos: conf do container (postgresql.conf/compose), runbook em docs.
- Aceite: `psql -c 'SHOW shared_buffers'` ≥ 4GB e `SHOW effective_cache_size` ≥ 16GB no vivo; `atlas:memory:recall --peek` não regride no latency ledger (MAXG-01); antes/depois registrado no Evidence Ledger.
- Risco: disputa de RAM com o motor nos 48GiB — teto de 8GB de buffers; reversão = restaurar conf + restart.

**ASI-04 — Coalescing + cap global de hooks (degrau shell de backpressure)** · E:S · fase F0 · deps: [MAXE-02, MAXE-03]
- Goal: o fan-out de hooks deixa de ser ilimitado (comprovado ao vivo: 6 processos `file-context` byte-idênticos simultâneos; load 11 gerado por UMA sessão) — sem daemon, sem fila nova, só shell.
- Mecanismo: evidência asi-2 gap (b): (1) `flock -n` por (hook, alvo) com **skip fail-open** — se um idêntico já roda, este sai 0 sem injetar (coalescing); (2) cap global de processos de hook (contagem no próprio script; acima do cap, hooks de background saem 0 com log); (3) load-shed: hooks auxiliares (file-context, heartbeat) checam loadavg e pulam acima de limiar configurável — o **interativo (UserPromptSubmit) NUNCA é shed**. Tudo fail-open: hook pulado jamais quebra o turno. O que NÃO fazer: fila persistente/prioridade real (isso é ASI-18, F3); matar processos à força.
- Arquivos: `.claude/hooks/atlas-ctx.sh`, `atlas-postedit-context.sh` (padrão de timeout já provado em :114-127), `atlas-pretooluse-guard.sh`.
- Aceite: bancada: 6 invocações simultâneas do mesmo hook/alvo ⇒ exatamente 1 executa e 5 saem com skip logado; com loadavg forçado acima do limiar, PostToolUse pula e o turno segue; durante sessão real, contagem de processos de hook nunca excede o cap (verificação por `ps`).
- Risco: skip demais esconde contexto — contador de skip por hook no log; skip-rate alto em uso normal ⇒ subir limiar com registro.

**ASI-05 — Ledgers vivos imunes a phpunit (medição não-falsificável)** · E:S · fase F0 · deps: []
- Goal: nenhuma medição do flywheel pode ser satisfeita rodando a suíte — hoje as únicas 7 linhas "vivas" da espinha em `live_outcomes.jsonl` são eco de phpunit, e qualquer aceite de F1 seria falsificável.
- Mecanismo: evidência asi-5 §1: `AtlasDecideLiveOutcomeFeedbackService::logPath()` escreve por `file_put_contents` direto em `storage_path(...)`; o helper `setLogPathForTesting` existe (:74) mas os testes não o chamam. Fix na raiz compartilhada (não por teste): guard `APP_ENV=testing` dentro do próprio `logPath()` desvia para path de teste (ou o TestCase base seta o fake para todos) — cobre testes presentes e futuros; mesmo padrão para o heartbeat do brain e para todo JSONL vivo escrito fora de `Storage::fake`. Limpeza etiquetada da poluição existente (7 linhas `actor=engineering_outcome_spine:autonomos` de 11/07 15:58–59 UTC, coincidentes com o phpunit pré-land do OUTC-01; heartbeat `actor:"test"` de 09/07), com receipt.
- Arquivos: `AtlasDecideLiveOutcomeFeedbackService.php:74` (e logPath), `tests/TestCase.php`, `storage/atlas/atlas_decide/live_outcomes.jsonl`, `storage/app/atlas/brain/heartbeat`.
- Aceite: rodar a suíte inteira ⇒ hash dos ledgers vivos idêntico antes/depois (check automatizável, candidato a plugin WDG-01); linhas poluídas removidas com evidência citada; caso negativo: teste que grava outcome em `APP_ENV=testing` prova que o path vivo não é tocado.
- Risco: apagar linha legítima — a remoção cita a coincidência de timestamp com o phpunit; em dúvida, etiquetar em vez de remover.

---

### F1 — Ligar o que existe

**Racional:** o flywheel está construído e parado (RPM≈0) por três interruptores e um template: músculo OFF, auto-apply OFF, reflection/pattern-ledger vazios, distiller gerando boilerplate que o próprio imune descarta (23 candidates → 0 memórias). A ordem interna segue o asi-5: **volume primeiro, ponte autônoma depois, qualidade da destilação por último** — comprar qualidade para um ciclo que não gira é desperdício; e refs citáveis entram já no início porque destravam a MEDIÇÃO de tudo (measured_share 0→0,9 é pré-condição de provar qualquer M). Nenhum item aqui cria fila de aprovação humana; os dois masters são gatilho operacional do operador, claramente marcados.

**Ordem de execução:**

1. `→ MAXE-01 (onda M0)` — refs canônicos visíveis por item no markdown: sem isso o consumidor não pode citar o que usou e M_contexto/M_aprendizado ficam eternamente em "não sei".
2. `→ MAXC-01 → MAXC-02 → MAXC-06 (onda M4, PRIORIDADE ELEVADA — antecipar como sub-trilha)` — decomposição em facetas, bloco de suficiência com faltas nomeadas e calibração ex-post do sensor: a metade retrieval da metacognição; o modo de falha "contexto insuficiente com cara de suficiente" PIORA com motor melhor, por isso sobe de M4 para cá (deps MAXC-01 respeitadas; medidores v1 intocados).
3. **ASI-06** (pre-flight do músculo + FLIP do operador) — abaixo.
4. **ASI-07** (floor do auto-apply provado + FLIP do operador) — abaixo.
5. **ASI-08** (reflection stream + pattern-ledger ligados) — abaixo.
6. **ASI-09** (distiller de-template com motor frontier sob author≠judge) — abaixo.
7. `→ v1 onda 5: ENG-13, ENG-14, ENG-15, PIP-07, CPT-10, ADV-01` — os flips de verificação e a re-prova adversarial externa: convertem o moat de verificação de "construído" para "enforçando" (investimento nº3 do asi-4, o mais ortogonal a N); executam pelo protocolo do v1 (ROL-01, 1 flip por janela).
8. **ASI-10** (AVCEL fora de shadow) — abaixo, na esteira da onda 5.

**ASI-06 — Pre-flight do músculo Autônomos + religação [FLIP DO OPERADOR]** · E:S · fase F1 · deps: [ASI-01, ASI-02 (≥observe limpo), ASI-05; v1 VOL-01 (onda 0 — este slice é a materialização operacional dele)]
- Goal: o limitador dominante do flywheel (volume de execução real ≈ 0 — asi-5 limitador 1) sai do caminho: **a fila de 6.169 packets** do serving volta a ser servida, com todos os freios PROVADOS antes e destravando todas as medições (lift, proven_real, denominadores FEE-13/MEM-09).
- Mecanismo: comando read-only `atlas:autonomos:preflight --json` com 8 checks e evidência citada: (1) ConstitutionGate vivo (ASI-01 verde); (2) porta única com relatório observe limpo ou enforce (ASI-02); (3) ledgers imunes a teste (ASI-05); (4) seed-gate ativo; (5) leases stale reapadas + give-back de packets-veneno operante (`AtlasLoopGiveBackToReplenisherFeedback`, keep-list); (6) committer escopado com boot-smoke fail-closed + lock único de main-merge (`AtlasTaskScopedCommitter:332-339`); (7) espinha OUTC-01 wirada nos 3 executores; (8) `atlas:task:repair-blocked` sem jam. **O flip em si — `atlas:agents:on autonomos` (env `ATLAS_AUTONOMOS_MASTER_ENABLED`) — é gatilho operacional EXCLUSIVO do operador** (charter: flip de master é dele; a máquina entrega o checklist verde e NUNCA liga sozinha; a memória implement-only vigente só ele revoga). Rampa: começar com 1–2 workers; subir é decisão dele (tabela de gatilhos). O que NÃO fazer: operar QUALQUER `atlas:loop:*` (família morta — os comandos vivos são `atlas:brain:*`/`atlas:task:*`); `git add -A`; merge.
- Arquivos: `app/Console/Commands/AtlasAutonomosPreflightCommand.php` (novo), leituras de `app/Services/Ai/SelfConstruction/*`, serving via `AtlasTaskServingStack` (disco dedicado `atlas_serving` — gotcha conhecido).
- Aceite: `atlas:autonomos:preflight --json` → 8/8 verdes com evidência por check e exit 0; caso negativo com fixture (gate ausente ⇒ preflight falha); **pós-flip (do operador)**: `ai_run_outcomes` > 0, `atlas_aemor_execution_episodes` crescendo, ≥5 outcomes reais/dia em 7d fluindo pela espinha (denominador real — ASI-05 garante que não é eco de suíte); **ELEV-15: decision_id/obra_id estampado em 100% dos landings da primeira janela — a estampagem de linhagem (ASI-11 movimento 1) é ACEITE do flip, não parêntese: o flip não fecha sem ela.**
- Risco: qualidade desconhecida dos 6.169 packets antigos — cap baixo + seed-gate + give-back filtram; jam de esteira — check 8 + runbook `atlas:task:reindex`/`repair-blocked` já conhecidos.

**ASI-07 — Floor do auto-apply provado ponta a ponta + FLIP [FLIP DO OPERADOR]** · E:S · fase F1 · deps: [ASI-02, ASI-05; v1 FEE-12 ✅]
- Goal: a única ponte autônoma delta→memória sai de OFF: latência real de aprendizado cai de 1–7 dias + humano (contradição vigente com o charter 06/07) para minutos nas classes seguras.
- Mecanismo: evidência asi-5 pista C: `atlas:ai:auto-apply-safe` agendado 1×/dia 04:10 (`bootstrap/app.php:466`), `ATLAS_AUTONOMOUS_AUTO_APPLY` default false (`config/atlas.php:1001`, ausente do .env) ⇒ tudo `held` → digest de domingo → operador. Slice (lado máquina): (a) teste E2E do floor fail-closed: privacy (`AtlasLearningProposalApplier.php:244` — só public/normal materializa), kinds seguros, **reversal handle em toda aplicação**, digest reportando `auto_applied/held`; (b) prova de reversão real (aplicar + reverter por handle + estado restaurado); (c) cadência pós-soak preparada atrás de config (hourly ou event-driven pós-outcome), default diária até o soak. **O flip `ATLAS_AUTONOMOUS_AUTO_APPLY=true` é gatilho do operador** (tabela). O que NÃO fazer: fila de aprovação (o digest é revisão-DEPOIS, pétreo); auto-apply de kinds fora da lista segura.
- Arquivos: `app/Services/Ai/Autonomy/AtlasAutonomousLearningApplier.php:73`, `app/Services/Ai/Compounding/AtlasLearningProposalApplier.php:244`, `bootstrap/app.php:466`, `config/atlas.php:1001`.
- Aceite: phpunit: proposal privada NUNCA materializa (caso negativo); ciclo aplicar→reverter restaura estado byte-comparável; **pós-flip**: primeira janela com `ai_learning_proposals.applied > 0` e `atlas_memory_entries` source=`atlas_autonomous_learning` > 0, cada uma com reverse handle **E decision_id de linhagem (ELEV-15 — pré-condição do rollback composto ASI-11)**; `reversal_rate` exposto no digest **+ métricas de review-debt (ELEV-25): itens auto-aplicados ainda não-revisados, idade da fila de revisão, tempo médio de inspeção — revisão-depois que degrada silenciosamente para revisão-nunca é freio de papel, e o digest é o único humano do sistema**. Zero applied com fila não-vazia = aceite NÃO satisfeito (anti-inatividade).
- Risco: aprender lixo em velocidade — freios vivos (CaptureQualityGate enforce, false_learning_gate, ASI-02) + o painel do critério de conclusão vigia `negative_feedback_rate > 0` e reversal rate (freio com 0 rejeições é freio não-testado, não saudável).

**ASI-08 — Reflection stream + pattern-ledger ligados (originador com combustível)** · E:S · fase F1 · deps: [ASI-05, ASI-06 (o volume que alimenta)]
- Goal: os 4 órgãos de meta-aprendizado que hoje rodam sobre vazio (`PathYieldEwma`, `PortfolioRouter`, `CausalEffectGate`, FP-estimator) saem de teatro para instrumento — a derivada segunda deixa de ser 0.
- Mecanismo: evidência asi-5 §2: `ATLAS_BRAIN_REFLECTION_ENABLED` default OFF (`config/atlas.php:79`) com `reflection-stream.ndjson` inexistente; `AtlasBrainCausalEffectGate` lê `AtlasLoopPatternLearningLedger` → `storage/atlas-loop/pattern-learning-ledger.jsonl` **não existe**. Slice: (a) reflection ON por default (write local barato e reversível por env — NÃO é master de músculo, não é gatilho de operador); (b) writer do pattern-ledger no seam de report/landing do task loop (o dado nasce onde o landing acontece — server-side, nunca no claim do worker); (c) comando read-only expondo PathYieldEwma com amostras reais. Arquivos novos cobertos pelo guard ASI-05.
- Arquivos: `config/atlas.php:79`, `app/Services/Ai/AutonomousEvolution/Brain/{AtlasBrainReflectionStream,AtlasBrainPathYieldEwma,AtlasBrainCausalEffectGate}.php`, seam de report em `app/Services/Ai/SelfConstruction/`, `storage/atlas-loop/pattern-learning-ledger.jsonl`.
- Aceite: após **≥20 ciclos reais** de brain/task (denominador mínimo): `reflection-stream.ndjson` ≥20 linhas; pattern-ledger com ≥1 entry por landing real; PathYieldEwma reporta ≥2 paths com `sample_count>0` via comando; phpunit não escreve nos arquivos vivos (herda ASI-05).
- Risco: as séries novas virarem proxy (originador otimizando refuse-rate/landing-rate) — refuse-rate é INFORMATIVO, nunca meta; o guardrail anti-Goodhart do brain ("isso evolui o escopo de verdade?") permanece a régua.

**ASI-09 — Distiller de-template: motor frontier na destilação, sob author≠judge** · E:M · fase F1 · deps: [ASI-06, ASI-07 (ciclo girando e aplicando), v1 OUTC-01 ✅]
- Goal: o motor de destilação para de fabricar boilerplate que o próprio imune corretamente descarta (23 candidates → 0 memórias promovidas): cada volta do flywheel passa a gravar lição densa e específica — o torque por volta do N×M.
- Mecanismo: evidência asi-5 limitador 3: `AtlasLearningDistiller.php:83-87` emite claim-TEMPLATE ("Flow %s produced %s outcome and should inform future routing…"); o CaptureQualityGate enforce (94% waste medido no observe) segura boilerplate — **freio bom, motor de destilação fraco**. Redesenho com separação estrita: o motor frontier **AUTORA** a destilação (outcome + diff + evidência → claim específico com refs), via adapter plugável no padrão já provado do brain-writer (`ATLAS_BRAIN_PROVIDER`, `config/atlas.php:32`), atrás de flag default-OFF → shadow → ON; os **JUÍZES permanecem 100% determinísticos e intocados**: CaptureQualityGate enforce + `false_learning_gate` + confidence floor + porta única ASI-02 — claim model-written entra na MESMA fila, mesmos gates, nunca se auto-promove. Fallback = template atual (degrade honesto). Privacy: payload sensitive/secret nunca vai a provider externo — destilação local (Hermes/GLM) para essas classes. O que NÃO fazer: afrouxar qualquer gate para o modelo passar; contar claim de shadow como memória.
- Arquivos: `app/Services/Ai/Compounding/AtlasLearningDistiller.php:83-87`, `app/Services/Ai/Compounding/AtlasCompoundingRuntimeService.php`, `config/atlas.php` (flag nova).
- Aceite: em shadow sobre **≥10 outcomes reais**: taxa candidate→promovida do braço modelo > braço template no MESMO período, dual-read registrado (MED-01); caso negativo vivo: claim boilerplate continua rejeitado pelo gate; flag OFF = byte-idêntico ao atual.
- Risco: modelo inventar lição (falso aprendizado) — claim exige refs de evidência verificáveis + false_learning_gate; custo de provider — batelada assíncrona fora do hot path.

**ASI-10 — AVCEL fora de shadow (flip de enforce da execução verificada)** · E:S · fase F1 · deps: [v1 ENG-13/14/15 + ADV-01 (mesma família; 1 flip por janela), ROL-01]
- Goal: a camada de execução verificada (área 12) passa de shadow para enforce — completa a conversão do moat de verificação, o único fator do M que nenhum salto de N substitui.
- Mecanismo: mesmo protocolo dos flips v1: janela shadow com evidência de não-regressão, gatilho objetivo de rollback pré-declarado (ROL-01) escrito ANTES do flip, leitura dupla registrada; sequenciado DEPOIS de ENG-13/14/15 para nunca empilhar dois flips na mesma janela de observação (atribuição limpa).
- Arquivos: config/serviço do AVCEL (área 12), ledger de flips.
- Aceite: janela shadow ≥7d com taxa de falso-positivo abaixo do limiar declarado; pós-flip: ≥1 bloqueio real de execução não-verificada registrado OU zero bloqueios com **≥N execuções verificadas passando** (denominador exposto — o aceite nunca é satisfeito por inatividade); rollback trigger citável no ledger com data anterior ao flip.
- Risco: falso-positivo bloqueando executor legítimo — rollback pré-declarado executa sem aprovação no meio (charter).

---

### F2 — O eixo reflexivo

**Racional:** com F0 o freio acompanha o motor e com F1 o fluxo real gira — só agora existe matéria-prima (outcomes, linhagem, volume) para o sistema saber DE SI. O asi-1 provou que as lacunas reais contra o estado da arte estão todas neste eixo, e que nenhuma exige área nova (nascem dentro das áreas 10/1/9, regra do mapa respeitada). Ordem interna: o freio composto e as séries à prova de volume vêm ANTES (acompanham a escala que F1 destravou); modelo-de-si, procedural e metacognição consomem os dados que então se acumulam.

**Ordem de execução:**

**ASI-11 — Rollback composto: ledger de linhagem + revert por decision-id** · E:L · fase F2 · deps: [ASI-02 (a porta estampa), ASI-06/ASI-07 (o fluxo que gera linhagem), v1 EVI-01]
- Goal: a "única salvaguarda" do charter passa a aguentar cascata: reverter o fecho transitivo de uma decisão ruim (commits + memórias + outcomes) por decision-id, em máquina — hoje `memory-forget` é unitário, o rollback-trigger é alert-only (`AtlasAcosRollbackTriggerCheckService.php:13`: "watchdog ALERTS; the operator reverts") e 500 reverts manuais independentes é exatamente o que 100× torna impossível.
- Mecanismo: evidência asi-3 §3. Quatro movimentos: (1) **LINHAGEM (landa primeiro, junto com os flips de F1)**: decision_id/obra_id estampado em (a) todo commit escopado (trailer no `AtlasTaskScopedCommitter`), (b) toda escrita de memória via porta única ASI-02 (meta), (c) toda aplicação do applier (o reverse handle ganha decision_id), (d) outcomes da espinha OUTC-01; (2) **LEDGER de linhagem** consultável (tabela pequena append-only commit↔memória↔decisão↔receipt); (3) **EXECUTOR** `atlas:rollback:cascade --decision-id=... --dry-run|--execute`: computa o fecho transitivo e executa — `git revert` dos commits do conjunto em ordem reversa, archive não-destrutivo das memórias (reusa o mecanismo do `memory-forget`), demoção dos outcomes alimentados — com receipt completo; a execução é ela própria reversível (unarchive + revert-do-revert); (4) o rollback-trigger ganha modo **executor** gated por gatilho objetivo pré-declarado (ROL-01) — freio de MÁQUINA, não alerta para humano ler na velocidade errada. O que NÃO fazer: aprovação humana no meio (charter — o operador revisa depois via digest); deleção destrutiva; grafo de dependência semântica entre commits além do agrupamento por decision-id (o conjunto por decisão é o átomo; dependências cruzadas param com receipt parcial honesto).
- Arquivos: `app/Services/Ai/SelfConstruction/AtlasTaskScopedCommitter.php`, `app/Services/Ai/AtlasMemoryRegistryService.php` (via ASI-02), `app/Services/Ai/Autonomy/AtlasAutonomousLearningApplier.php`, `app/Services/Ai/Cognition/AtlasAcosRollbackTriggerCheckService.php:13`, `app/Console/Commands/AtlasAiMemoryForgetCommand.php` (reuso), migration nova, comando novo.
- Aceite: E2E sintético: **cascata de 5 mudanças interdependentes** (3 commits escopados + 2 memórias + 1 outcome sob o mesmo decision-id) revertida por UM comando — working tree limpa, memórias arquivadas, outcomes demovidos, receipt listando o fecho; `--dry-run` lista sem tocar nada; teste-invariante: commit novo do committer SEM decision_id reprova o certify; cobertura de linhagem = 100% dos landings novos numa janela de **≥20 landings reais**. **ELEV-10/23 (escala + semântica formal — a pergunta que o programa se faz é "centenas", não 5):** caso sintético adicional de **≥50 mudanças** com trabalho conflitante intercalado, provando os 4 estados FORMAIS do rollback — `clean_revert` · `partial_with_receipt` (o que reverteu, o que não, por quê, o que fazer com o resto) · `blocked_conflict` (para com receipt, zero força) · `containment` (reversão impossível ⇒ congela auto-apply da família afetada + alerta) — com o TEMPO de reversão medido: o SLA de rollback vira número citado no gatilho de ≥3 workers, não esperança.
- Risco: revert conflitando com trabalho posterior nos mesmos arquivos — o executor detecta conflito e para com receipt parcial (nunca força); fecho agressivo revertendo trabalho bom — conjunto por decision-id exato, nunca heurística de vizinhança.

**ASI-12 — Séries de aprendizado multi-ator (sensores à prova do próprio volume)** · E:S/M · fase F2 · deps: [v1 MEM-03 ✅ (régua congelada, INTOCADA), MED-01]
- Goal: os sensores (concentração 45d, janelas ARFL, denominadores de feedback) sobrevivem à escala que eles mesmos vão medir: 10 motores paralelos × 100× recalls hoje deslocariam denominadores silenciosamente — o risco anti-Goodhart DA escala (asi-2 gap c).
- Mecanismo: (a) coluna aditiva actor/origem em `atlas_memory_entry_usages` (`interactive:{session}`, `autonomos:{worker}`, `brain`, `watchdog`…), estampada pelos writers de usage do pack (`AtlasOpenBrainContextPackService:2328-2380`); (b) leituras **v2 normalizadas** (concentração por ator + agregada volume-normalizada) como série NOVA versionada — os medidores v1 congelados (MEM-03 45d, janelas ARFL) continuam rodando byte-idênticos em paralelo (regra ii.2: versionar, nunca editar in-place); (c) peek (`record_usage=false`) permanece fora das séries, como sempre.
- Arquivos: migration aditiva, `app/Services/Ai/Memory/AtlasMemoryRecallConcentrationDemotion.php` (leitura v2 ao lado da v1), writers de usage do pack.
- Aceite: usage novo 100% com actor-tag (query SQL na janela); comando v2 reporta concentração por ator com denominadores expostos; série v1 inalterada (dual-read MED-01 registrado); teste: 2 atores sintéticos com volumes 10:1 ⇒ a normalização não deixa o ator de alto volume mascarar concentração no de baixo.
- Risco: a v2 virar régua sem juiz — v2 é informativa até ganhar julgamento próprio; jamais substitui a v1 durante os relógios do v1.

**ASI-13 — Modelo-de-si com evidência, consumido pela Decisão** · E:M · fase F2 · deps: [v1 ENG-08 ✅ / ENG-15 (rotas proven_real), ASI-06 (volume de outcomes), ASI-05]
- Goal: a escolha de músculo/rota (área 10) deixa de ser sub-informada: o Decide consulta UM read-model de capacidades com base em evidência — a maior alavanca não-planejada do asi-1 (#1), porque erro de roteamento multiplica tudo que vem depois, na mesma proporção do salto de N.
- Mecanismo: evidência asi-1 L1: os 5 fragmentos hoje desconectados — `AtlasCapabilityRegistry` (`Kernel/Capability`, manifesto de CONFIG: declarado, não provado), comando causal self-model R1 (read-only, 0 consumidores em decisão), `AtlasExternalBrainModelCapabilityGapLedger` (**zero callers** — órgão unwired), rotas proven_real do ADML (`AtlasDecideLiveOutcomeFeedbackService`/`live_outcomes.jsonl` — o único pedaço com evidência real), scorecard estrutural. Desenho: serviço read-model na área 10 que agrega por (task_category, rota): `{taxa histórica proven_real, n, basis ∈ {proven|declared|inferred}, gaps abertos}`; o Atlas Decide consome como TERMO da escolha (nunca override dos floors da área 18 — separação de poderes pétrea) e cita o bloco no decision receipt; o gap-ledger ganha o Decide como primeiro caller (gap aberto ⇒ rota despriorizada com explain). **Read-model sobre evidência, nunca narrativa introspectiva** (anti-item 8 do asi-1 §4); `basis` é DERIVADA da fonte, jamais setada pelo caller (lição SEV-1 dos booleans forjáveis). Não criar 19ª área: isto vive na 10, medido pela 13, provado pela 11.
- Arquivos: `app/Services/Ai/Kernel/Capability/AtlasCapabilityRegistry.php:22-27`, `AtlasDecideLiveOutcomeFeedbackService`, `AtlasExternalBrainModelCapabilityGapLedger`, serviço novo no namespace do Atlas Decide.
- Aceite: decision receipt de escolha real carrega bloco `self_model` {basis, n, taxa} por rota candidata; fixture determinística: rota sem proven e com gap aberto perde para rota com n≥10 proven; `rg --no-ignore -w AtlasExternalBrainModelCapabilityGapLedger app/ | wc -l` ≥ 2 (ganhou caller); honestidade de base: rota com n<10 nunca reporta `basis=proven`.
- Risco: modelo-de-si alimentado por eco de teste — ASI-05 isolou os ledgers; capability declarada divergindo da provada — o contrato expõe as duas com basis, nunca funde.

**ASI-14 — Memória procedural de primeira classe, cross-executor** · E:M · fase F2 · deps: [ASI-02 (o tipo entra pela porta), v1 OUTC-01 ✅]
- Goal: o conhecimento procedural que o Atlas JÁ pagou para aprender serve os 3 executores elite — hoje o `ProceduralPlaybook` (objective, steps, postconditions, forbiddenActions, priorCorrections, derivados de falhas REAIS) vive só no Dev, e o registry é taxonomicamente cego a ele ('procedural' não é tipo; zero menção nos 3 docs de planejamento). Melhor razão prova/custo do inventário asi-1 (#2): transforma "descubra o procedimento do zero" em "execute o procedimento provado e diga o que mudou".
- Mecanismo: (a) tipo `procedural` em `AtlasMemoryEntry::TYPES` (`app/Models/AtlasMemoryEntry.php:21`) + ponte playbook↔memória: playbook persistido vira entry procedural VIA porta única ASI-02 (G0–G8 aplicam — playbook com secret não entra); (b) consumo cross-executor: Forge (`ForgeWorkPacketExecutionCycleService`) e Autônomos (plan-phase do serving) consultam playbook aplicável por task_category no mesmo seam que o Dev já usa (`AtlasDevFastPathOrchestrator`/`DevOutcomeMemoryService` como referência de wiring); steps+forbiddenActions+priorCorrections entram no contexto do packet, cap 1–2 playbooks; (c) o tipo vira recallável no braço registry. Floor: playbook é CONTEXTO, nunca autoridade — jamais substitui gates/certify.
- Arquivos: `app/Models/AtlasMemoryEntry.php:21`, `app/Services/Ai/Kernel/Procedural/ProceduralPlaybook.php:24`, seams do Dev (referência), seams Forge/Autônomos.
- Aceite: phpunit: playbook derivado de falha real no Dev é servido a packet Forge/Autônomos da mesma task_category e citado no receipt de execução; registry recalla tipo `procedural` por query; janela real: **≥3 execuções não-Dev com playbook citado em ≥2 task_categories distintas** (ELEV-05 — citação no receipt é quase automática quando se injeta o playbook; categorias distintas + série informativa outcome-com vs outcome-sem playbook no digest medem USO, não wiring).
- Risco: playbook stale envenenando executor — priorCorrections carregam ref de outcome e a área 16 (consolidação) responde pela supersedência; explosão de playbooks — cap por packet + curadoria pelo digest.

**ASI-15 — Metacognição calibrada na entrega de decisão** · E:M · fase F2 · deps: [ASI-13, v1 OUTC-01 ✅, MAXC-06 (o irmão do retrieval, já elevado em F1)]
- Goal: o executivo declara confiança e a realidade confere — "confiança X nesta rota, acerto histórico Y" com calibração medida ex-post. É a metade da metacognição que NENHUM plano cobre (a metade retrieval é MAXC-02/06); fecha o L3 do asi-1.
- Mecanismo: reusar o padrão de calibração já vivo (`Cognitive/PredictiveFailure/CalibrationBandClassifier.php` + `PredictiveFailureCalibrationMetricsService` — hoje só para previsão de falha): o decision receipt (ASI-13) ganha **banda de confiança DERIVADA** (função pura de taxa proven_real + n — nunca auto-declarada pelo caller); job ex-post junta banda declarada × outcome real (espinha OUTC-01) e computa a curva declarado-vs-realizado por banda; check plugin WDG-01 alerta quando |declarado − realizado| fura o limiar com n mínimo. **Sem escalar único fabricável** (a lição do "92" do COM-04): bandas + denominadores crus, nunca um score.
- Arquivos: `app/Services/Ai/Cognitive/PredictiveFailure/CalibrationBandClassifier.php` (reuso), serviço do Decide (ASI-13), plugin no `AtlasWatchdogCheckRegistry`.
- Aceite: comando `--json` expõe bandas {declared_band, realized_rate, n}; banda com n<10 ⇒ `insufficient_sample` (nunca "calibrado" por vácuo); teste de propriedade: banda é função pura da evidência; após **≥30 decisões reais**: curva publicada no digest.
- Risco: gaming por declarar sempre banda baixa — impossível: a banda é derivada, não declarável; consumo da calibração NA escolha só após 2 janelas honestas.

---

### F3 — Substrato 10-100×

**Racional:** o hardware aguenta 100×; a arquitetura de processo aguenta ~1× (asi-2). Pós-Max o teto honesto é ~10-30×; os 100× exigem exatamente o que nenhum plano cobre: processo residente, fila cognitiva com prioridade, e corpus denso pesquisável. F3 vem por último porque otimiza um fluxo que F0–F2 tornaram correto, medido e freado — escalar antes disso seria multiplicar defeito. Réguas de F3 leem exclusivamente do latency ledger MAXG-01 (congelado desde F0).

**Ordem de execução:**

**ASI-16 — Servidor de contexto residente (pack/recall/file-context quentes)** · E:M/L · fase F3 · deps: [MAXG-01 (régua congelada), MAXE-04/05, MAXA-01, F0 completa]
- Goal: matar o imposto estrutural spawn-por-op — a 100× seriam ~10⁵ spawns/dia só de boot artisan + grafo de serviços + conexão DB nova por tool-call, insustentável por construção (asi-2 gargalo 1, a maior alavanca estrutural pós-Max).
- Mecanismo: reusar o esqueleto que JÁ roda residente (3 processos `atlas:open-brain:mcp`): o processo MCP ganha listener local (unix socket) servindo {context-pack, recall, file-context} com serviços Laravel quentes e o daemon de embeddings MAXA-01 no mesmo ciclo de vida; invalidação de cache EXCLUSIVAMENTE pelo corpus_fingerprint do MAXE-05 (regra pétrea do Max: uma única autoridade de invalidação); lifecycle simples — launchd/supervisor, idle-restart, health-check como plugin WDG-01. O que NÃO fazer: framework de RPC, Redis/broker, reescrever hooks em Go/Rust (o custo é o boot do framework, não a linguagem — asi-2 §5), segundo daemon paralelo ao MCP.
- Arquivos: comando MCP residente existente, `app/Services/Ai/AtlasOpenBrainContextPackService.php`, boundary socket novo, plist launchd.
- Aceite: pelo latency ledger MAXG-01, janela real de 7d: `context-pack` servido pelo residente **p95 ≤ 2s** e `recall` **p95 ≤ 1s** (os mesmos alvos do vii.1, agora estruturais); daemon morto ⇒ fallback spawn byte-idêntico (teste de paridade); RSS do processo residente sob cap declarado com idle-restart provado.
- Risco: staleness de serviços quentes após mudança de config/corpus — fingerprint + restart por invalidação; leak de memória — cap + idle-restart; o receipt anti-fake do boundary permanece obrigatório.

**ASI-17 — Hooks como clientes finos do servidor residente** · E:S/M · fase F3 · deps: [ASI-16]
- Goal: o hook para de pagar boot + retrieval frio e vira cliente de socket (~10-50ms): o alvo "hooks ≤5s/turno" deixa de ser otimização e vira propriedade estrutural.
- Mecanismo: `atlas-ctx.sh`/`atlas-postedit-context.sh`/`atlas-pretooluse-guard.sh` ganham caminho preferencial via socket do ASI-16 (timeout curto agressivo), payload idêntico ao atual; sem resposta no bound ⇒ **fallback ao caminho spawn atual** — fail-open por construção; o coalescing/cap de F0 (ASI-04) continua por cima como cinto de segurança.
- Arquivos: `.claude/hooks/atlas-ctx.sh:91-97`, `atlas-postedit-context.sh`, `atlas-pretooluse-guard.sh`.
- Aceite: MAXG-01: **p95 do total de hooks por turno ≤ 5s sustentado por 14 dias** COM counts médios do pack estáveis (anti-Goodhart: as duas réguas no mesmo aceite — pack vazio rápido não conta); bancada: servidor parado ⇒ hook completa via fallback dentro do timeout duro.
- Risco: socket pendurado — timeout curto + fail-open; divergência de payload entre caminhos — teste de paridade byte-a-byte com corpus fixo.

**ASI-18 — Fila cognitiva global: prioridade, backpressure e load-shed** · E:M · fase F3 · deps: [ASI-16, ASI-04 (o degrau shell que esta fila substitui), MAXG-01]
- Goal: sob 10–15 execuções de motor em paralelo (o teto físico honesto desta máquina), o interativo NUNCA espera background — prioridade e descarte viram propriedade do substrato, não acaso do scheduler do SO (asi-2 gargalo 2: pile-up comprovado com UMA sessão).
- Mecanismo: fila in-process no servidor residente: 2 classes {interactive, background} com prioridade estrita; cap de workers por classe; backpressure honesto — background acima do cap é **coalescido por (op, alvo) ou descartado com contador**, nunca fila infinita; load-shed por loadavg para produtores de background (arena, séries, brain heartbeat — o que o Max já tirou do hot path fica atrás desta fila); telemetria {queued, coalesced, shed, wait_ms} gravada no latency ledger. O que NÃO fazer: broker externo, fila distribuída, dimensionar para "milhares de agentes" (dimensionar para 10-15, o teto real).
- Arquivos: servidor ASI-16, plugin novo no `AtlasWatchdogCheckRegistry`, latency ledger MAXG-01.
- Aceite: bancada de carga com 12 clientes paralelos (4 interativo / 8 background) por 10min: **p95 de espera do interativo < 500ms e p95 do pack interativo ≤ 2s**, `shed_count > 0` no background (o descarte ACONTECE, não é teórico), loadavg sob o limiar; caso negativo: mesma carga com a fila desligada VIOLA o p95 (prova de causalidade da fila).
- Risco: starvation do background — idade máxima na fila reportada + watchdog; shed silencioso escondendo trabalho necessário — contadores expostos, nunca descarte mudo.

Fecha a fase: `→ MAXA-06 fase 2 (onda M3, com MAXA-03/MAXA-07 antes)` — embeddings dos 290k símbolos de código + 950 KB items (halfvec + HNSW, incremental por source_hash): o corpus denso que o servidor residente serve quente; permanece no plano Max — o ASI só o sequencia DEPOIS de ASI-16 (backfill one-off de ~2-3h roda como background atrás da fila ASI-18, nunca no hot path).

---

### Tabela de gatilhos do operador

O charter (06/07) elimina aprovação humana no MEIO dos fluxos; o que resta — e é dele por definição — são os **masters e a escala**. A máquina entrega o verde; só ele vira a chave.

| Gatilho (SÓ o operador) | Como | O que deve estar VERDE antes |
|---|---|---|
| Master do músculo Autônomos | `atlas:agents:on autonomos` (`ATLAS_AUTONOMOS_MASTER_ENABLED`) | ASI-01 (ConstitutionGate + sentinela), ASI-02 (≥observe com relatório limpo), ASI-05 (ledgers imunes), `atlas:autonomos:preflight` 8/8 (ASI-06); memória implement-only revogada por ele |
| Flip do auto-apply | `ATLAS_AUTONOMOUS_AUTO_APPLY=true` | ASI-02, ASI-05, ASI-07 (floor E2E: privacy fail-closed + reversal provado); digest FEE-12 operante |
| Cadência hourly/event-driven do auto-apply | config (preparada no ASI-07) | soak ≥7d pós-flip com `reversal_rate` medido e `negative_feedback_rate > 0` observado ≥1× (freio TESTADO, não presumido) |
| Escala paralela (≥3 workers do músculo; múltiplas sessões de motor) | cap de workers / disciplina de sessão | ASI-11 (linhagem 100% dos landings), ASI-12 (séries multi-ator); para ≥10 paralelos: ASI-18 (fila com p95 provado sob carga) |
| Push para remoto | `git push` | regra pétrea existente e inalterada por este programa: só com OK explícito dele |
| Meta-otimizador REC-04: shadow → atuar (seção xiv) | config/env do meta-loop | MARCO ESP-V1 com **M > 1 medido** (série ELEV-02); **R > 0** (REC-03) com juiz independente (ELEV-18); freios REC-06 verdes (breaker armado + review-debt sob cap); ≥3 hipóteses de shadow com challenger e evidência de funil |

---

### Critério de conclusão do ASI-Substrato (adições ao (vii))

Somam-se às verificações 1–10 do (vii), no mesmo regime: todos verdes NO MESMO CORTE, hashes carimbados, nenhum medidor v1/Max tocado. **Nenhum item abaixo é satisfazível por inatividade ou re-rotulagem — todo denominador mínimo é parte do alvo.**

| # | Verificação | Alvo |
|---|---|---|
| 11 | Chokepoint de admissão provado por teste | teste arquitetural verde: **0 writers de `AtlasMemoryEntry` fora da porta única**; em enforce, `gate_evaluations == writes` numa janela com ≥50 writes reais; entry com secret bloqueada por qualquer via (caso negativo vivo) |
| 12 | Rollback composto demonstrado | cascata sintética de **≥5 mudanças interdependentes** (commits + memórias + outcome sob um decision-id) revertida por 1 comando com dry-run e receipt do fecho; linhagem em 100% dos landings de uma janela ≥20 |
| 13 | Flywheel com ≥1 volta REAL registrada ponta a ponta | outcome real (não-phpunit — ledgers selados pelo ASI-05) → memória promovida pela porta com imunidade → recall servido → execução seguinte com **ref citado** (evento ARFL `measured=true`), com os ids encadeados verificáveis no Evidence Ledger; e `learning-recall-lift` com braços ≥10 (`measurement_ready=true`) |
| 14 | M medido por janela (a régua do asi-4) | **ELEV-02: a fórmula de "valor/turno" é CONGELADA pelo slice ELEV-02/ASI-METRIC (M1) ANTES de qualquer flip de F1** — composto versionado de {used_ratio measured × utility COM-11, green-run pass rate, retrabalho evitado provado por rollback}, formula_version própria; "valor" sem definição executável é a única métrica-manchete inauditável do programa, e quem a define depois de ver o dado escolhe o proxy que sobe. Série de M por janela com denominadores expostos: valor/turno com ACOS ÷ mesmo motor cru (faixas peek `record_usage=false` para o A/B honesto), separado interativo × delegado; imposto de hooks **p95 ≤ 5s/turno sustentado 14d** com counts do pack estáveis; a régua congela ANTES de qualquer tuning (MED-01) e pack menor não infla o ratio (regra COM-11) |
| 15 | Fila cognitiva com p95 sob carga | bancada 12 clientes paralelos: p95 de espera do interativo < 500ms, p95 do pack interativo ≤ 2s, `shed_count > 0` no background, loadavg sob limiar; caso negativo (fila off viola o p95) registrado como prova de causalidade |
| 16 | Cobertura do moat de verificação (ELEV-12) | série `verified_share = execuções sob enforce ÷ execuções totais` (join OUTC-01 × receipts AVCEL/ENG) publicada com denominadores crus — a tese "verificação é o M que cresce com N" sem medidor de cobertura é asserção; alvo: verified_share ≥ 0,80 em janela ≥14d com **≥50 execuções reais** (nunca satisfeito por inatividade) |
| 17 | Review-debt do operador sob controle (ELEV-25) | métricas do digest: itens auto-aplicados não-revisados, idade máxima da fila de revisão, tempo médio de inspeção — publicadas por janela; check WDG alerta quando idade da fila > cap declarado; revisão-depois que degrada para revisão-nunca refuta o charter na prática |

**Regra final (herdada do v1, reafirmada):** se fechar qualquer item exigir relaxar um medidor, afrouxar um gate ou reocupar um campo aposentado (o "92" não volta pela porta dos fundos), o programa está refutado naquele ponto — o slice volta para a fase do defeito com justificativa no ledger de gaps.

---

## (ix) Cobertura das 18 áreas — fronteira das 11 áreas restantes (MAXH..MAXN)

Com este bloco o **Max cobre as 18 áreas do ACOS**. Antes, o Max era 7 áreas (MAXA-G, o cluster de busca/recuperação/contexto/compactação/medição, áreas 3–8+13) mais o Programa ASI-Substrato (F0–F3, que corta transversalmente as 18). Sete leitores de fronteira mergulharam nas 11 áreas ainda não cobertas — 1, 2, 9, 10, 11, 12, 14, 15, 16, 17, 18 — e produziram 60 slices (MAXH..MAXN); 21 verificadores adversariais (goodhart · floor-charter · exequibilidade por área) mais 1 crítico global os revisaram, corrigiram e sequenciaram. Esta seção é a **versão revisada**: fixes aplicados, ondas do crítico.

O achado é **unânime nas 11 áreas** e é o mesmo do §viii: **mecanismo construído + correto + DESLIGADO ao lado de dado vazio.** Os exemplos mais fortes:
- **Evidência (11):** o `event_hash` da migration não sobreviveu ao repair pós-wiper — a coluna **não existe na tabela viva** e `record()` guarda por `hasColumn`, então o selo por-evento está silenciosamente OFF; sem hash-chain, **deleção/reordenação de linha é indetectável** (489 eventos, 0 com `event_hash`).
- **Imunidade (2):** G3/G4 são flags que o caller preenche com "seguro"; os detectores reais (secret scanners, 2 contradiction detectors) existem no repo com **1 caller cada, fora da imunidade** — a porta não enxerga.
- **Modelo do Operador (15):** a captura está ligada e default-ON, mas escreve para **8 tabelas `operator_*` que não existem** no pgsql@5433 → **0 sinais**, engolidos por um `try/catch` silencioso.
- **Verdade Temporal (16):** `valid_until`/`stale_after`/`authority_level`/`superseded_by_id` = **0/77**; o grafo de relação tem **0 rows**; os 6 kernels são puros, corretos e dead-fed.
- **Governança (18):** a escada de autonomia promove por **boolean forjável** (`$area[sig]===true`, zero nonce) e os floors são `private const` mudados por PR sem trilha de emenda.
- **Aprendizado (9):** **3 órgãos de credit-assignment** deterministas e corretos existem em silos (ExternalBrain, AEMOR, Aaeos) e **nenhum alcança a memória do recall** — a lição nasce de template sem atribuição.

**Total de slices do Max agora = 65 (MAXA-G) + 18 (ASI) + 60 (MAXH-N) + 11 (RAGX) + 12 (ELEV, xi) + 60 (MULT, xii) + 13 (ESP, xiii) + 6 (REC, xiv) + 10 (TETO, xv) = 255.**

---

### Tabela de cobertura das 18 áreas

> **Nota de leitura:** a coluna "Coberta por" abaixo lista as famílias MAX/ASI da FRONTEIRA INICIAL (esta seção ix). **A profundidade REAL de cada área soma também os catálogos MULT (seção xii), ELEV (xi), ESP (xiii), REC (xiv) e TETO (xv)** — ex.: a área 12 é MAXL+ASI-10 **+ MULTV (10 slices, §xii) + ESP-01..05 (§xiii)**; a área 1+16 é MAXH **+ MULTH (§xii)**; etc. Esta tabela é o mapa da fronteira inicial, NÃO o censo total dos 255 slices (esse é o inventário §5.5 do playbook). Nenhuma das 18 áreas está órfã.

| # | Área | Coberta por | Profundidade |
|---|---|---|---|
| 1 | Memória | **MAXH** (H) · v1 MEM · ASI-02/12/14 | fronteira profunda (nova) |
| 2 | Captura & Imunidade | **MAXI** (I) · ASI-02 | fronteira profunda (nova) |
| 3 | Busca/recuperação semântica (embeddings) | MAXA · ASI-16 | fronteira profunda (Max v1) |
| 4 | Recall híbrido & ranking | MAXB | fronteira profunda (Max v1) |
| 5 | Retrieval agêntico | MAXC | fronteira profunda (Max v1) |
| 6 | Grafo / cartografia (AURG) | MAXD | fronteira profunda (Max v1) |
| 7 | Contexto & pack (ATER/ARFL) | MAXE | fronteira profunda (Max v1) |
| 8 | Compactação | MAXF | fronteira profunda (Max v1) |
| 9 | Aprendizado por Execução | **MAXJ** (J) · ASI-09 | fronteira profunda (nova) |
| 10 | Decisão | **MAXK** (K) · ASI-13/15 | fronteira profunda (nova) |
| 11 | Evidência & Certificação Longitudinal | **MAXL** (L) · ASI-11 | fronteira profunda (nova) |
| 12 | Execução Verificada & Qualidade | **MAXL** (L) · ASI-10 | fronteira profunda (nova) |
| 13 | Medição, Latência & Marco Zero | MAXG | fronteira profunda (Max v1) |
| 14 | Porta do Cérebro & Provider-safety | **MAXM** (M) | fronteira profunda (nova) |
| 15 | Modelo do Operador | **MAXN** (N) · ASI-13 (irmão) | fronteira profunda (nova) |
| 16 | Consolidação & Verdade Temporal | **MAXH** (H) | fronteira profunda (nova) |
| 17 | Originação & Ambição | **MAXN** (N) · ASI-08 (combustível) | fronteira profunda (nova) |
| 18 | Governança & Constituição | **MAXK** (K) · ASI-01 | fronteira profunda (nova) |

O substrato (ASI F0–F3) permanece transversal a todas. Os 60 slices novos **endurecem/enchem/medem** o substrato existente — nenhum é slice de substrato 10–100× (F3 não recebe nenhum, ver ordem integrada).

---

### Catálogo por área

> Formato herdado do plano: **ID — título** `[marca]` · E · onda · deps + Goal / Mecanismo (arquivo:linha) / Arquivos / Aceite executável com denominador mínimo / Risco. Contrato pétreo herdado por inteiro: anti-Goodhart (denominador mínimo REAL, nunca n=2, insatisfazível por inatividade), author≠judge, MED-01 (medidor v2 congela antes do produtor; medidor v1/Max/ASI congelado **nunca tocado** — versiona), ROL-01 (rollback pré-declarado), charter 06/07 (zero fila humana; auto-aplicação reversível + digest; flip de master = gatilho exclusivo do operador), property-gated > boolean, local-first. `atlas:loop:*` jamais religado (vivo = `atlas:brain:*`/`atlas:task:*`).

---

#### Área 1 + 16 — Memória & Consolidação / Verdade Temporal (MAXH)

**Teto medido:** schema temporal completo + 6 kernels puros + trait de scopes + comando judge, e **zero dado vivo passando**. Discriminadores temporais 0/77; grafo de relação 0 rows; os 6 kernels default-OFF sem produtor de pares; recall temporalmente cego (scopes só filtram world-model edges); o judge exige `--confirm` humano (viola o charter). É "disciplina sem dono" por contagem. MAXH constrói o produtor contínuo de pares (o "sono do cérebro"), o atuador sob charter, o desconto temporal no recall e a régua v2 — sempre congelando a régua ANTES dos produtores.

**MAXH-01 — Medidor v2 de verdade temporal, congelado antes dos produtores** `[MEDIDOR]` · E:S · onda M1 · deps: []
- Goal: régua NOVA versionada que mede a *disciplina* temporal sem tocar o `atlas:memory:quality` congelado.
- Mecanismo: comando read-only `atlas:memory:temporal-quality --json` + `AtlasMemoryTemporalQualityService` (novo). `temporal_provenance_coverage` conta **só valores NÃO-default** (caller-supplied/evidence-derived — nunca defaults de type-map cego, senão sobe por construção); `truth_density_v2` conta uma relação para a densidade **só se acima de barra FROZEN de confiança/qualidade OU se melhora recall no subset R8** — o guard R8 do MAXH-07 aplicado UNIFORMEMENTE a toda relação (supersedes/related inclusive), não só à síntese; o cosine threshold + confidence floor do scanner ficam **congelados como parâmetros cegos à densidade** (definir "não-patológica" concretamente no freeze). `supersession_maintained` = share de supersedes com verdict-row E superseder ativo. Denominador-mínimo: active < N_min OU janela vazia ⇒ `no_signal` (nunca 100). Só LÊ. Baseline no Evidence Ledger (dual-read MED-01).
- Arquivos: `app/Console/Commands/AtlasMemoryTemporalQualityCommand.php` (novo), `app/Services/Ai/Memory/AtlasMemoryTemporalQualityService.php` (novo).
- Aceite: `atlas:memory:temporal-quality --json` retorna as 3 coberturas com num/den CRUS; corpus vazio ⇒ `no_signal`; **teste arquitetural: nenhuma chave nova em `atlas:memory:quality`**; baseline gravado 1×; teste: relação abaixo da barra frozen NÃO conta para `truth_density_v2`.
- Risco: virar proxy — num/den crus, escalar único proibido; scanner-thresholds frozen impedem bombear densidade relaxando o cosine.

**MAXH-03 — Scanner autônomo de pares candidatos (o produtor que faltava aos 6 kernels)** · E:M · onda M2 · deps: [MAXH-01; MAXA-02 (cache de embed)]
- Goal: os 6 classificadores puros + o judge saem de dead-fed — nasce o produtor CONTÍNUO de pares (hoje só por digitação humana).
- Mecanismo: `atlas:memory:consolidation-scan --observe --json` + `MemoryConsolidationScanner` (novo). Não há API entry-to-entry: para cada active, usar seu summary/rationale como query em `AtlasMemoryVectorSearchService::scoreEntries` contra os demais ids (**adapter** + cache de embed MAXA-02 evita O(n²) recompute — não é "reuso" direto), cosine ≥ threshold frozen, scan exato (62 actives, barato); roda os 6 kernels (liga os flags default-OFF **só dentro deste caminho**, nunca global) → verdict + confidence + cosine. Ledger de proposta append-only JSONL (herda ASI-05). Observe NÃO persiste relação. O classifier temporal vive em `app/Services/Ai/Cognition/TemporalSupersessionClassifier.php` (não Memory/); os 6 kernels dead-fed em `AtlasMemoryConflictResolutionService.php:459-550`.
- Arquivos: comando novo, `app/Services/Ai/Memory/MemoryConsolidationScanner.php` (novo), reuso kernels + `AtlasMemoryVectorSearchService`, `app/Services/Ai/Cognition/TemporalSupersessionClassifier.php`, ledger JSONL.
- Aceite: scan sobre os 62 actives emite a **DISTRIBUIÇÃO de verdicts** (contagens por verbo related/compatible/scoped/conflicts_with/supersedes/not_conflict) ao ledger, com set de candidatos não-trivial — **ELEV-06: "não-trivial" = ≥N pares avaliados (N congelado junto com o cosine threshold no freeze do MAXH-01) e distribuição com ≥2 verbos não-degenerados; nunca `≥1 par`**; observe grava **0 relation rows**; phpunit não toca o ledger vivo (ASI-05); geração determinística p/ embedding set congelado.
- Risco: explosão de pares em escala CORP-01 — cap top-K + cosine floor ⇒ O(n·K); *ponytail: threshold+cap são o teto; clustering só >~2k entries*.

**MAXH-02 — Produtor de `authority_level`/`stale_after`/`observed_at` por derivação com base** · E:S/M · onda M3 (lote L5) · deps: [MAXH-01, v1 MEM-05] — *NÃO há aresta de build para MAXH-05: MAXH-02 FECHA em L5 com aceite determinístico próprio (derivação pura das colunas; ver aceite). A validação "stale ranqueia abaixo de fresh no recall" (que precisa do MAXH-05, L6) é VALIDAÇÃO E2E POSTERIOR, marcada abaixo como o "aceite REAL" — fecha em L6, não bloqueia o landing de L5. Sem ciclo de build.*
- Goal: as colunas 0/77 ganham dono por DERIVAÇÃO pura auditável no funil que todos já usam.
- Mecanismo: em `AtlasMemoryRegistryService::normalize()` (`:607`, o mesmo funil que MEM-08 guarda), quando o caller não fornece: `authority_level` por mapa puro {tipo→nível}; `stale_after` por tabela TTL-por-tipo (config documentada com o porquê por tipo, jamais inline mágico); `observed_at`=recorded_at. Fail-open: derivação NULL ⇒ coluna NULL. Comando `atlas:memory:temporal-backfill --dry-run|--apply` estampa os 77 pelas MESMAS funções puras; reversível (revert = SET NULL). **Nota anti-Goodhart:** os valores derivados de type-map cego são DEFAULT — não contam para `temporal_provenance_coverage` (MAXH-01 já os exclui).
- Arquivos: `AtlasMemoryRegistryService.php:607,670-690`, `config/atlas.php` (mapa authority + TTL, com porquê), comando backfill novo.
- Aceite (o REAL, não tautológico): **stale ranqueia abaixo de fresh no recall (MAXH-05)** e supersedido nunca supera superseder — a subida de `temporal_provenance_coverage` NÃO é aceite (sobe por construção); derivação pura (mesmo input → mesmas colunas); pós-backfill 0 rows tipo-decaidor com `stale_after` NULL.
- Risco: TTL fabricado disfarçado de base — tabela cita a razão por tipo; jamais tunada para mover o medidor (MAXH-01 congelado antes; coverage não é o gate).

**MAXH-04 — Atuador de supersedência/relação sob charter (auto-aplica reversível + digest, nunca fila humana)** · E:M · onda M4 · deps: [MAXH-03, v1 MEM-02, ASI-02]
- Goal: verdicts de alta confiança viram relações persistidas E supersedências efetivas, aplicadas pela MÁQUINA, reversíveis, no digest; corrige o judge que exige `--confirm`.
- Mecanismo: modo enforce do scan: verdicts com confidence ≥ floor E não-alto-risco (reuso `shouldEscalate` — decision/architecture/policy vão ao **bucket de review do digest de domingo**, NÃO fila bloqueante) persistem via `judge(actor=atlas)`; p/ `supersedes` setam `superseded_by_id` + `valid_until=now` do perdedor. Reverse handle em toda aplicação (reuso `memory-forget`/`rollbackRef`). `AtlasAiWeeklyMemoryDigestCommand` ganha seção `consolidation`. Charter-exato.
- Arquivos: enforce path do scanner, `AtlasMemoryConflictResolutionService::judge`, `AtlasAiWeeklyMemoryDigestCommand.php`, reuso `AtlasAiMemoryForgetCommand`.
- Aceite: E2E: A(older)+B(newer) mesmo-key ⇒ B supersedes A, A.superseded_by_id=B, A.valid_until set, reverse handle; digest lista em `consolidation`; reversão restaura A. Par decision **não** auto-aplica (bucket de review). **Zero applied com fila de alta-confiança não-vazia = NÃO satisfeito.**
- Risco: auto-supersede memória boa — floor + author≠judge + reversibilidade + alto-risco→digest; medidores congelados vigiam.

**MAXH-05 — Desconto temporal no recall (superseded/stale demovidos, nunca escondidos)** · E:S/M · onda M4 · deps: [MAXH-02, MAXH-04]
- Goal: o recall para de servir memória expirada/superseded com peso cheio.
- Mecanismo: no braço registry-only de score (`AtlasHybridMemoryRetrievalService.php:224-236`), `temporal_multiplier` ao lado do `concentration_multiplier`: demoção SOFT (×0.5 após `stale_after`, ×0.35 se `valid_until` passou), nunca hard-hide. Superseded já removido quando o superseder está no pool; estender p/ DEMOVER quando o superseder NÃO está no pool, com flag no explain. Reusa predicados de `HasTemporalTruth`. Switch default-OFF até medir num subset R8 temporal. **Piso COMPOSTO (compartilhado com MAXH-08):** o produto de todos os multiplicadores temporais tem mínimo recuperável (a row sempre retorna com flag) e uma memória sob decay+stale entra periodicamente no bucket de review do digest — decay+stale nunca vira silêncio permanente.
- Arquivos: `AtlasHybridMemoryRetrievalService.php:224-236`, reuso `HasTemporalTruth`, flag config.
- Aceite: entry com `stale_after` no passado pontua estritamente abaixo de fresh equivalente; superseded nunca supera superseder; switch OFF = ranking byte-idêntico; concentração 0.35 + janela MEM-03 intocados; **teste do piso composto: entry sob stale+decay+valid_until simultâneos ainda aparece em ≥1 canal recuperável (com flag).**
- Risco: dupla-penalização escondendo memória — multiplicadores soft com piso composto; recall retorna a row com flag, nunca zera.

**MAXH-06 — Resolução de contradição por autoridade>evidência>frescor (axis-resolver wirado)** · E:S · onda M4 · deps: [MAXH-03, MAXH-04]
- Goal: `conflicts_with` para de ficar aberto pra sempre — o sistema PROPÕE quem vence pela cascata canônica (`MemoryConflictAxisResolver`, hoje 0 callers).
- Mecanismo: num `conflicts_with`, alimentar ambos os lados em `resolveConflictAxis` (authority_rank do `authority_level` de MAXH-02, evidence_count, recorded_ts) → vencedor. Cascata decisiva E confidence ≥ floor E não-alto-risco ⇒ propõe `supersedes` pelo atuador reversível de MAXH-04. Empate/alto-risco ⇒ bucket de review. Liga `axis_resolver_enabled` só neste caminho.
- Arquivos: conflict path do scanner, `AtlasMemoryConflictResolutionService::resolveConflictAxis`, config.
- Aceite: A(canonical) vs B(operational) conflito ⇒ resolver escolhe A, propõe B superseded, reversível; autoridade+evidência iguais ⇒ frescor desempata; indecidível ⇒ held ao digest, 0 write; flag do kernel OFF global.
- Risco: mapa de autoridade errado ⇒ vencedor errado — alto-risco nunca auto-aplica; reversível; digest expõe toda supersedência por eixo.

**MAXH-07 — Síntese de cluster redundante: muitas→1 canônica (author≠judge, reversível)** · E:M/L · onda M4 · deps: [MAXH-03, ASI-02, ASI-09, v1 MEM-05]
- Goal: clusters de N memórias redundantes viram 1 canônica + N supersedes, sem perder o original (arquivado, recuperável).
- Mecanismo: reusar `StrategicForgettingService::plan()` (verbo `compress`) como fonte de clusters + o scanner (≥3 membros pairwise related/compatible). Um distiller AUTORA a canônica (motor local p/ classes provider-safe, padrão ASI-09) citando os evidence_refs de TODOS os membros; os JUÍZES determinísticos (CaptureQualityGate + porta ASI-02 + confidence floor) admitem; os N membros ganham `supersedes`→canônica + arquivo. Author≠judge estrito. default-OFF → shadow → ON. Cada síntese é 1 unidade reversível.
- Arquivos: cluster path do scanner, reuso `StrategicForgettingService` + adapter ASI-09, `AtlasMemoryRegistryService` (via ASI-02), `AtlasAiMemoryForgetCommand`.
- Aceite: shadow propõe ≥1 síntese com canônica citando os refs de TODO membro; enforce (cluster sintético de 3 dups) ⇒ 1 canônica active, 3 arquivados+superseded, recall retorna a canônica, reversão restaura os 3; provider-safe (cluster sensitive destila localmente: 0 chamada externa). Conta em `truth_density_v2` só se a canônica for recall-superior num subset R8. **Zero síntese com cluster qualificável = não satisfeito.**
- Risco: fusão que perde nuance — canônica DEVE citar refs (verificável), gates julgam, reversível.

**MAXH-08 — Decaimento de confiança por não-verificação, com recuperação (demoção, nunca deleção)** · E:S/M · onda M4 · deps: [MAXH-02, MAXH-01, MAXH-05 (piso composto compartilhado)]
- Goal: memória nunca re-verificada nem recallada perde confiança de forma auditável, alimentando review + demoção — sem nunca apagar sozinha.
- Mecanismo: passe dobrado na cadência do digest computa `decayed_confidence = base × f(dias_desde_verified_at, recall_hits)` — half-life por tipo, função pura; grava em campo SEPARADO (nunca sobrescreve base) e liga `needs_reverification`/ajusta `stale_after` ao cruzar piso. Recuperação: recall-hit OU re-link de evidência reseta `verified_at`. O `temporal_multiplier` (MAXH-05) lê o decaído. **Sob o piso composto de MAXH-05** (decay+stale nunca some).
- Arquivos: `app/Services/Ai/Memory/MemoryConfidenceDecayService.php` (novo), hook no digest, `AtlasMemoryRegistryService`, config half-life.
- Aceite: entry não-verificada além do half-life ⇒ `decayed_confidence < base`, `needs_reverification`, base intocada; recall-hit reseta e restaura; decay puro/determinístico; canonical isento; nunca deleta.
- Risco: decair pétrea ainda-verdadeira — canonical isento; recupera em qualquer verificação; base preservada.

**MAXH-09 — Recall temporal como consulta ("as-of" / só-vigentes) na porta do cérebro** · E:S · onda M4 · deps: [MAXH-02, MAXH-05]
- Goal: o operador/decisor pode perguntar "o que era verdade em D" ou "só o vigente".
- Mecanismo: flags `--as-of=<data>` e `--current-only` no `atlas:memory:recall` + caminho do pack, aplicando `HasTemporalTruth::current($at)`. **Peek-forçado** por default p/ as-of (`record_usage=false`).
- Arquivos: `AtlasMemoryRegistryService::relevantForContext`, `AtlasMemoryRecallCommand.php`, reuso `HasTemporalTruth::current`.
- Aceite: com A `valid_until`=ontem e B vigente, `--current-only` retorna B; `--as-of=<2d atrás>` retorna A; queries as-of gravam **0 usage**; sem flags = recall byte-idêntico.
- Risco: as-of vazar p/ série viva — peek forçado; teste assegura `usage_total` inalterado.

**MAXH-10 — Cadência de consolidação viva + watchdog da verdade temporal (WDG-01)** `[MEDIDOR]` · E:M · onda M5 · deps: [MAXH-01, MAXH-04, todos os produtores, v1 WDG-01]
- Goal: consolidação vira disciplina CONTÍNUA vigiada, com watchdog que detecta cadência morta e regressão real (não uma métrica pinada em 100%).
- Mecanismo: agendar o passe scan+atuar em cadência barata (dobrar no fluxo de sessão/land + passe profundo na janela do digest, sem novo launchd). Check-plugin em `AtlasWatchdogCheckRegistry`. **Como `temporal_provenance_coverage` conta só non-default (MAXH-01), o check "abaixo do baseline" volta a ter sinal;** além dele, o watchdog falha quando: superseded ainda supera superseder numa amostra de recall, ledger de proposta >N dias parado (cadência morta), bucket de review de alto-risco excede cap, OU coverage non-default regride. Cada check fail-open.
- Arquivos: `bootstrap/app.php` (schedule na janela do digest), plugin novo em `AtlasWatchdogCheckRegistry.php`, `AtlasMemoryTemporalQualityService`.
- Aceite: `atlas:memory:temporal-quality --check --json | jq '.checks | length >= 3'`; regressão forjada ⇒ exit≠0; um ciclo real de scan avança o timestamp do ledger (**≥1 passe real na janela** — nunca satisfeito por inatividade).
- Risco: cadência morre em silêncio — o check da própria idade do ledger é o guarda, monitorado independentemente.

---

#### Área 2 — Captura & Imunidade (MAXI)

**Teto medido:** a imunidade está fragmentada em 4 superfícies G0–G8 que divergem (evaluator forbid/confirm · learning-kernel strict all-pass · classifier keyword · capture-audit strings constantes); **0 delas roda detecção real.** G3/G4 são flags que o único caller vivo preenche com "seguro" (`AtlasOpenBrainWriteBackService.php:418-424`); os detectores reais existem com 1 caller cada, fora da imunidade. Sem calibração FP/FN, sem células de memória imune, sem detecção de auto-envenenamento, proveniência é hash solto (não cadeia), quarentena gradua por boolean. A porta é o ASI-02; MAXI é a inteligência que a porta consome.

**MAXI-01 — Detectores reais alimentam G3/G4 (ligar os órgãos que já existem)** · E:M · onda F0 · deps: [ASI-02]
- Goal: G3 (safety) e G4 (contradiction) deixam de ser flags do caller e passam a ser PRODUZIDOS por detectores reais.
- Mecanismo: **no ponto onde ASI-02 pousa a porta única** (writeback:408 hoje; `AtlasMemoryRegistryService` se ASI-02 mover — REFERENCIA a porta, não corta uma segunda; hoje `AtlasMemoryRegistryService` tem 0 refs imunes), montar os `$signals`: (a) G3 roda `CodeGraphSecretScanner`/`LocalAgentSecretScanner` → `contains_secret`/`contains_sensitive_unnecessary` DERIVADOS; (b) G4 roda `NumericRangeOverlapContradictionDetector` + `FactPairPolarityContradictionDetector` contra o pool de memórias mais novas do mesmo escopo → `contradicts_newer` DERIVADO. Remove o hardcode `contains_secret=>false` e o `g4=>not_checked`. Determinístico, local, 0 provider. Sinal DERIVADO da fonte, nunca do caller (lição SEV-1). Reforço de reuso: contradiction = 1 caller cada; secret scanners já em uso em múltiplos callers.
- Arquivos: porta única (via ASI-02), `app/Services/Ai/Cognition/{NumericRangeOverlapContradictionDetector,FactPairPolarityContradictionDetector,CognitiveImmunePromotionGateEvaluator}.php`, `LocalAgentSecretScanner.php`, `CodeGraphSecretScanner.php`, `AtlasOpenBrainWriteBackService.php:418`.
- Aceite: candidato com chave sintética (AWS/PEM) → G3 `block` via scanner SEM flag do caller; número contraditório a memória mais nova do mesmo escopo → G4 `block` SEM flag; candidato limpo passa; scanners/detectors ganharam caller (`rg` ≥2/≥ múltiplos); em observe, verdicts G3/G4 gravados por writer numa janela com **≥50 candidatos reais**.
- Risco: FP barra writer legítimo — observe-primeiro (escada ASI-02), relatório por writer, writer barrado vira sinal no ledger de gaps, nunca afrouxamento do gate.

**MAXI-02 — Uma autoridade G0–G8 (audit real sob `.v2`; captura para de gravar verdict-constante)** `[MEDIDOR]` · E:M · onda M1 · deps: [ASI-02]
- Goal: o mesmo candidato recebe o MESMO verdict em qualquer estágio; a auditoria imune da captura para de gravar strings hardcoded.
- Mecanismo: (a) `CaptureService::cognitiveImmuneAudit()` (`:247-294`) chama o `CognitiveImmunePromotionGateEvaluator` real em **shadow** e emite o verdict sob **`atlas.capture.cognitive_immune_audit.v2`** (bloco NOVO) — o payload `.v1` (schema_version em `CaptureService.php:251`, contrato consumível) fica **byte-estável**; o `audit_hash` do campo v2 deriva do verdict real. (b) `AtlasMemoryCognitiveImmuneLearningKernelService::evaluatePromotion()` (`:212`, escada duplicada) migra ao evaluator — **`evaluatePromotionGates()`:307 JÁ delega ao evaluator atrás do flag `promotion_gate_evaluator_enabled`; o alvo de dedup é SÓ `:212`** (verificar seus callers antes de remover). (c) O classifier vira **produtor de sinais** (input-class → hints), não 4ª régua.
- Arquivos: `CaptureService.php:247,251`, `AtlasMemoryCognitiveImmuneLearningKernelService.php:212`, `AtlasAaeosCognitiveImmuneInputClassifier.php`, `tests/Feature/Ai/Cognition/ImmuneVerdictSingleAuthorityTest.php` (novo).
- Aceite: o MESMO candidato por captura(shadow)/candidate-gate/writeback produz `gate_statuses` idênticos; **`audit_hash` do campo v2 muda quando o verdict muda** (não constante); o payload `.v1` roda byte-idêntico (dual-read); teste arquitetural: nenhuma classe além do evaluator resolve `promotion_status`; denominador vivo: quando alimentada (ASI-06), **≥20 capturas** com audit v2 real.
- Risco: captures=0 hoje ⇒ denominador vivo dorme — corpus sintético pela pipeline real + "quando alimentada"; retirar `:212` quebra caller — reler do disco, migrar na mesma mudança.

**MAXI-03 — Calibração dos gates imunes por FP/FN real (com denominador known-miss seeded)** `[MEDIDOR]` · E:M · onda M1 · deps: [MAXI-01, ASI-02, MED-01]
- Goal: saber, com número, se o imune bloqueia demais ou de menos — "freio com 0 rejeições é freio não-testado".
- Mecanismo: ledger append-only versionado NOVO `immune_verdict_ledger` {candidate_hash, writer, gate_statuses, promotion_status, decided_at}; o digest rotula amostras `true_block`/`false_block`/`missed_poison`; bandas FP/FN DERIVADAS (função pura, reusa `Cognitive/PredictiveFailure/CalibrationBandClassifier.php`) por gate e writer, `insufficient_sample` quando n<10. Comando read-only `atlas:immune:calibration --json`. **O `missed_poison_rate` é rotulado explicitamente como "lower bound de known-miss" com o caveat ao lado da banda; o FN band puxa denominador não-vácuo de um corpus seeded known-should-catch injetado no pipeline REAL já em M1** (seed mínimo, NÃO a suíte MAXI-09) — **nunca emitir FN band "calibrado"/verde com denominador known-miss = 0** (senão 0-missed lê "calibrado" por vácuo por 4 ondas). Nunca auto-ajusta o gate.
- Arquivos: migration `immune_verdict_ledger`, `CognitiveImmunePromotionGateEvaluator.php`, `CalibrationBandClassifier.php` (reuso), comando novo, plugin de leitura no digest.
- Aceite: `atlas:immune:calibration --json` expõe {gate, writer, blocks, false_block_rate, missed_poison_rate(lower_bound), n} com bandas; n<10 ⇒ `insufficient_sample`; property-test: banda é função pura; após **≥50 verdicts reais** curva no digest; caso negativo: 1 `missed_poison` do seed known-should-catch aparece na série.
- Risco: régua sem juiz — informativa até ganhar julgamento; rótulo enviesado — amostragem cega + denominador cru exposto.

**MAXI-04 — Classificador de ingestão híbrido: substring → léxico + assinatura semântica** · E:M · onda M3 · deps: [MAXA-01, MAXI-03, MAXI-02]
- Goal: ofuscação (base64, homoglyph, paráfrase, PT fora da lista) para de passar por depender de 15 strings hardcoded.
- Mecanismo: `AtlasAaeosCognitiveImmuneInputClassifier` ganha braço semântico atrás de switch default-OFF: além do match léxico (piso honesto), embeda (daemon MAXA-01, local) e compara com exemplares-âncora de cada classe hostil (vetores locais); classe hostil vence por `max(léxico, similaridade≥τ)`. τ calibrado (MAXI-03). Secret usa o scanner real (MAXI-01), não `has_secret_marker` morto. **Aceite ancorado em floor ABSOLUTO**, não relativo (ver abaixo). Zero provider.
- Arquivos: `AtlasAaeosCognitiveImmuneInputClassifier.php:59-155`, boundary de embedding (`SemanticRagRuntimeClient`), fixture de âncoras em `resources/atlas/immune/`, config do switch.
- Aceite: corpus red-team (≥20 injections ofuscadas + ≥20 técnicos legítimos): **recall absoluto ≥X% das ≥20 ofuscadas capturado** (floor absoluto, não "> substring" que é tautológico no corpus ofuscado; **lei ELEV-03: X e o teto de FP são carimbados no ledger no FREEZE da régua MAXI-03, ANTES da implementação — limiar definido depois de ver o dado é aceite decorativo**) E **FP nos ≥20 legítimos ≤ teto calibrado** (teto duro); o delta vs substring vira número informativo, não gate; switch OFF = byte-idêntico; nenhum vetor-âncora sai da máquina.
- Risco: τ mal calibrado barrar operador — τ derivado da calibração com denominador mínimo, switch OFF até ganho provado.

**MAXI-05 — Imunidade que APRENDE: assinatura de veneno confirmado vira célula de memória** · E:M · onda M4 · deps: [MAXI-03, MAXI-04, ASI-11]
- Goal: padrão de veneno confirmado (bloqueado OU revertido via ASI-11) deposita assinatura que o classificador consulta ANTES de re-avaliar.
- Mecanismo: store local append-only `immune_signature_store` {signature (família de content_hash + marcadores/centroid), origin_ref, first_seen, hit_count, class}; alimentado por verdicts `block` recorrentes (MAXI-03) e memórias revertidas pela cascata ASI-11. O classificador (MAXI-04) consulta: match ⇒ classe hostil `reason=known_poison_signature:{ref}`. Assinatura DERIVADA do incidente, nunca à mão. Reversível (handle) e decai se nunca mais bate. Só hash/centroid (privacy). Mesma escada observe→enforce.
- Arquivos: migration `immune_signature_store`, `AtlasAaeosCognitiveImmuneInputClassifier.php`, seam de reversão em `AtlasAcosRollbackTriggerCheckService`/ASI-11, digest.
- Aceite: 2ª ocorrência de padrão previamente revertido bloqueada por `known_poison_signature` (não por re-derivação), com ref; nunca guarda texto bruto (teste de privacy); assinatura sem hit decai; janela real: **≥3 assinaturas com hit_count≥2**.
- Risco: assinatura FP fossilizando — decaimento por não-uso + handle + observe antes de enforce; explosão — dedup por família + curadoria no digest.

**MAXI-06 — Detecção de auto-envenenamento (loop memória-ruim→recall→decisão→memória-pior)** · E:M · onda M4 · deps: [ASI-11, MAXI-05]
- Goal: candidato cuja proveniência fecha transitivamente sobre uma memória já revertida/tombstoned é sinalizado antes de promover.
- Mecanismo: sobre o ledger de linhagem do ASI-11, checagem determinística no G4-estendido: monta o fecho transitivo da proveniência do candidato e bloqueia/quarentena se toca `tombstoned`/`deprecated`/`conflicted` OU se detecta ciclo (candidato descende de si via recall). Puro sobre o grafo; 0 provider. Só estados degradados/ciclo disparam (`trusted` nunca).
- Arquivos: ledger de linhagem (ASI-11), `CognitiveImmunePromotionGateEvaluator.php` (sinal `provenance_traces_to_reverted`), `AtlasMemoryConflictResolutionService.php`.
- Aceite: cascata memória-ruim(revertida)→recall→outcome→destilação ⇒ candidato `self_poisoning:{ancestor_id}` retido antes de promover; descendente de `trusted` limpo passa; ciclo barrado; cobertura de linhagem = 100% dos promovidos numa janela **≥20**.
- Risco: linhagem incompleta escapar — herda o invariante ASI-11 (landing sem decision_id reprova o certify); fecho agressivo — só estados degradados/ciclo.

**MAXI-07 — Proveniência criptográfica encadeada da captura (tamper-evident, HMAC local-only)** · E:M · onda M4 · deps: [ASI-02, ASI-11]
- Goal: provar que fonte→captura→candidato→memória não foi adulterada — hoje há hashes soltos, nenhum encadeamento.
- Mecanismo: `AtlasKnowledgeSourcePacket` e o bloco `cognitive_quarantine.lineage` (`CaptureService.php:226-230`) passam a encadear `receipt_hash_n = HMAC_k(receipt_hash_{n-1} ‖ stage_payload_hash_n)`, chave `k` **local-only** (nunca em provider/URL/log — charter). Verificador `atlas:immune:verify-lineage --ref=…`. A porta ASI-02, ao promover, estampa o elo memória. Só hashes; sem PKI.
- Arquivos: `AtlasKnowledgeSourcePacket.php`, `CaptureService.php:226`, porta única (ASI-02), `atlas:immune:verify-lineage` (novo), derivação de chave local.
- Aceite: adulterar `stage_payload` ⇒ `verify-lineage` acusa `broken_at:{stage}`; cadeia íntegra ⇒ `verified`; privacy: chave `k` nunca em log/receipt/projeção (grep=0); cobertura 100% dos packets/capturas novos numa janela **≥10**.
- Risco: rotação/perda da chave — versionar + fallback `unverifiable_legacy` honesto; disputa com `source_hash` — encadear é aditivo.

**MAXI-08 — Quarentena que gradua por evidência, não por boolean** · E:M · onda M4 · deps: [MAXI-03, ASI-12]
- Goal: G8 probation deixa de ler `on_probation` e gradua `watch`→`trusted` por regra derivada de evidência.
- Mecanismo: `probationGate()` (`CognitiveImmunePromotionGateEvaluator.php:283`) computa `trusted` só quando (tempo-em-`watch` ≥ T) ∧ (recalls ≥ R, contados por ator via ASI-12, sem feedback negativo) ∧ (zero contradição superveniente por MAXI-01). Função pura. T/R derivados de calibração (MAXI-03), não chutados.
- Arquivos: `CognitiveImmunePromotionGateEvaluator.php:283`, `AtlasMemoryCognitiveImmuneLearningKernelService.php:263`, usage-por-ator (ASI-12).
- Aceite: entry recém-`watch` NUNCA resolve `trusted` sem os 3; 1 feedback negativo mantém `watch` com razão; inflada por 1 ator (100:0) não gradua (normalização ASI-12); janela real: **≥5 graduações watch→trusted** com os 3 predicados no receipt.
- Risco: graduação lenta segurar memória boa — T/R calibrados e visíveis; ausência de feedback ≠ validação — R conta recalls COM oportunidade de feedback.

**MAXI-09 — Certificação da imunidade + re-prova adversarial (ADV-MAXI)** · E:M · onda M5 · deps: [MAXI-01..08]
- Goal: a inteligência imune vira gate permanente medido, e um adversário externo prova que o freio bloqueia veneno de verdade.
- Mecanismo: (a) plugin permanente do WDG-01 que publica FP/FN por gate (MAXI-03), cobertura (MAXI-01), assinaturas (MAXI-05), integridade de linhagem (MAXI-07); (b) **ADV-MAXI**: corpus red-team sintético versionado (secret, injection ofuscada, contradição, veneno auto-referente, captura adulterada) rodado no caminho de land; cada certificador (MAXI-01..08) re-provado adversarialmente. O seed mínimo de MAXI-03 já vive em M1; aqui é a suíte completa. author≠judge (quem escreve payload ≠ quem tuna o gate).
- Arquivos: plugin em `AtlasWatchdogCheckRegistry`, `tests/Feature/Ai/Immune/AdvMaxiRedTeamTest.php` (nova), corpus em `tests/Fixtures/Immune/`, ledger de flips.
- Aceite: cada classe de ataque bloqueada por ≥1 gate com receipt (secret→G3, injection→classifier, contradição→G4, auto-veneno→MAXI-06, cadeia adulterada→MAXI-07); watchdog publica FP/FN com denominador; **≥1 bloqueio real por classe** OU denominador ≥N com zero furos (nunca por corpus vazio); rollback trigger citável.
- Risco: red-team virar alvo de treino — corpus congelado, author≠judge; certificar cedo — sequenciado após MAXI-01..08 verdes no mesmo corte.

---

#### Área 9 — Aprendizado por Execução (MAXJ)

**Teto medido:** a maquinaria está construída e parada por falta de fluxo (`ai_run_outcomes`=0, `ai_compounding_memories`=0, aemor=1). Credit assignment não chega na memória (3 organs causais em silos, nenhum alimenta o distiller); zero abstração no caminho canônico (1 outcome→1 candidate template); negative learning é booleano + colheita manual; sem currículo; meta-aprendizado cego a TIPO de lição; compounding acumula, não compõe; motor frontier ausente do assento de JUIZ. Floor frágil: `min_cases_per_arm=2`. Cada slice é a camada de QUALIDADE, buildável+shadow agora, provando valor pós-ASI-06.

**MAXJ-01 — Régua de qualidade de lição + série de yield** `[MEDIDOR]` · E:S · onda M1 · deps: [v1 OUTC-01, MED-01, MAXG-01]
- Goal: congelar a régua ANTES de qualquer produtor de qualidade (a lição do "92").
- Mecanismo: comando read-only `atlas:ai:lesson-quality --json` que projeta, sobre `ai_learning_candidates` + `ai_rag_feedback_events` + OUTC-01, denominadores crus versionados por `memory_type × flow_id × scope` → {candidate_count, promoted_rate, measured_lift, case_count, negative_count}. Hash próprio **`atlas.ai.lesson_quality.v2`** (juiz externo, herda selo ASI-05). Nenhum escalar único; nenhuma edição de medidor v1/Max.
- Arquivos: comando novo; leitura de `AtlasLearningRecallUseLiftService` (reuso), modelos existentes.
- Aceite: comando retorna os grupos com {measured_count, total} expostos; janela sem massa ⇒ `insufficient_signal` (nunca 100); phpunit não escreve no ledger vivo; série v1 (RecallUseLift agregado) inalterada.
- Risco: rotular qualidade com outcomes=0 → o comando declara `insufficient_signal` até ASI-06; nunca "verde vácuo".

**MAXJ-05 — Lesson-type yield: meta-aprender QUAIS TIPOS de lição rendem (floor n≥8 na série NOVA)** `[MEDIDOR]` · E:S · onda M1 · deps: [MAXJ-01, v1 FEE-04 flipado]
- Goal: responder "qual TIPO de lição paga?" — o meta-aprendizado que hoje só existe para provider (ADML).
- Mecanismo: estender `AtlasLearningRecallUseLiftService` segmentando o A/B lift por `memory_type` numa **série NOVA versionada `atlas.ai.lesson_type_yield.v2`** (nome alinhado ao MAXJ-01 `.v2`), com o **floor pétreo n≥8 hard-coded SOMENTE nesta série (`max(8, …)`)**. **NÃO tocar `config/atlas.php:1137` nem a chave `atlas.ai.loop.learning_recall_use_lift.min_cases_per_arm` (lida em `AtlasLearningRecallUseLiftService.php:44`, `max(1, $minCases ?? config(…,2))`)** — essa chave é o floor do agregado v1 congelado FEE-13/RecallUseLift; bumpá-la muda a SAÍDA do v1 (arms que eram medidos a n=2 viram `insufficient`) e quebra o dual-read byte-idêntico (MED-01). Se o operador quiser corrigir o n=2 do v1, é um re-corte v1→v1.1 declarado, gatilhado por ele — não embutido aqui.
- Arquivos: `AtlasLearningRecallUseLiftService.php` (extensão aditiva, série v2 própria), comando; **`config/atlas.php:1137` intocado**.
- Aceite: comando reporta lift por tipo com denominadores; tipo com `case_count < 8` ⇒ `insufficient_signal`; 2 tipos com volumes 10:1 ⇒ o de baixo volume não é mascarado; **o agregado v1 roda byte-idêntico** (chave v1 inalterada — dual-read).
- Risco: tipo raro nunca atinge floor ⇒ honesto reportar `insufficient`, jamais promover por escassez.

**MAXJ-02 — Credit assignment na destilação: a lição carrega POR QUÊ** · E:M · onda M4 · deps: [MAXJ-01, ASI-11, v1 OUTC-01]
- Goal: cada lição promovida carrega QUAL insumo causou o outcome (context ref / decisão / provider / spec-dim), não só QUE aconteceu.
- Mecanismo: adapter puro no `AtlasLearningDistiller` que consulta `AtlasExternalBrainTaskOutcomeCausalAttributor` (reuso, organ determinista) com os sinais do outcome → grava `caused_by` (primary_cause + contributing_causes + refs citáveis) no `payload` do candidate. Atrás de flag `atlas.ai.credit_assignment` default-OFF → shadow → ON. O motor frontier de ASI-09 recebe `caused_by` como ENTRADA. Sensitive/secret: atribuição local. **A moldura `falseLearningGate`/R3 foi REMOVIDA** de goal/mecanismo/aceite: era premissa falsa (produtores AEMOR já setam `attribution_reviewed` — `AtlasAemorCertificationService.php:156/202`, `AtlasAemorRuntimeService.php:556/582`; o gate é condicional, não pass-through) + pipeline errado (o distiller produz `AiLearningCandidate` em Compounding; o gate lê `AtlasAemorOutcome` — setar o flag no candidate é INERTE p/ o gate). Fechar gap AEMOR é outro slice.
- Arquivos: `AtlasLearningDistiller.php`, adapter novo, config flag; NÃO toca o attributor.
- Aceite (só sobre o candidate): outcome com spec ruim ⇒ `caused_by.primary_cause=poor_spec_quality`; sucesso com evidência ⇒ `good_execution`; candidate ganha `caused_by` não-vazio; OFF ⇒ template byte-idêntico. **Zero cláusula falseLearningGate/R3.**
- Risco: atribuição errada vira crédito errado — `confidence` do attributor entra no candidate, o gate determinista mantém veto; shadow mede taxa de `unknown` antes do flip.

**MAXJ-03 — Generalização: N candidates com assinatura comum → 1 lição abstrata com case_count** · E:M · onda M4 · deps: [MAXJ-01, MAXJ-02, ASI-02]
- Goal: parar de inflar o corpus com N lições quase-idênticas; fundir em 1 lição forte com `case_count`.
- Mecanismo: passe read-model que agrupa `ai_learning_candidates` por assinatura {memory_type, caused_by.primary_cause, scope} (reusa o padrão do `AtlasSelfConstructionGiveBackLessonConsolidator`); grupo com `case_count ≥ floor` (config **default 8, mínimo pétreo > 2**) propõe UMA lição abstrata (claim generalizado + refs de todos os membros + `case_count`) via porta única ASI-02; membros viram evidência. Shadow primeiro. Reversível.
- Arquivos: serviço novo de consolidação de candidates, reuso do consolidator pattern, comando read-only.
- Aceite: 8 candidates de mesma assinatura ⇒ 1 proposta `case_count=8` com refs unidos; 2 candidates ⇒ NADA (floor); grupo heterogêneo ⇒ não funde; `case_count` nunca inflado por duplicados (dedupe por candidate_hash).
- Risco: abstrair cedo demais gera lição genérica — floor ≥ 8 + mesma `primary_cause` são o freio.

**MAXJ-04 — Negative learning com força de refutação + injeção como contexto proibido** · E:M · onda M4 · deps: [MAXJ-02, ASI-08, v1 FEE-12]
- Goal: o "não faça" ganha FORÇA (quão refutado) e um consumidor vivo — hoje é booleano + colheita manual sem quem leia.
- Mecanismo: (a) writer que converte {outcome failed/give_back/quarantine com `caused_by`, refutação colhida, repeated_failure_signature} em `refutation_memory` com `refutation_strength` = função pura de {recorrência (pattern-ledger ASI-08), severidade (quarantine>failed_gate>give_back), custo evitado} — sempre {componentes + denominador}, nunca escalar fabricável; (b) consumidor: no pack de contexto, task que casa a assinatura de uma refutação forte injeta o "não faça" como **forbidden-context** (materializa a `operationalDoctrine`). Injeção é CONTEXTO, nunca autoridade. Flag default-OFF → shadow → ON.
- Arquivos: writer novo (reuso `negativeKnowledge` do AemorJudgment), seam de injeção no `AtlasOpenBrainContextPackService` (peek), config.
- Aceite: 3 give_backs de mesma assinatura ⇒ `refutation_strength` monotônico crescente; quarantine ⇒ força > give_back; pack de task casada mostra o forbidden-context nomeado; força nunca > teto sem denominador; injeção não muda o floor de execução.
- Risco: negative-learning venenoso — força exige recorrência ≥ N; o negativo NUNCA mata memória sozinho (floor FEE-03).

**MAXJ-06 — Currículo de aprendizado: priorizar onde aprender rende** · E:M · onda M4 · deps: [MAXJ-05, MAXJ-02, ASI-13] · *(o crítico não sequenciou J-06/07/08; onda derivada dos deps declarados)*
- Goal: o esforço/confiança de destilação deixa de ser uniforme — vai para categorias com lift proven positivo, recua nas de lift ≤ 0.
- Mecanismo: ranking read-only `atlas:ai:learning-curriculum --json` que junta o yield por tipo (MAXJ-05) + ablação causal (`CausalAblationBatchStudy`, reuso) + custo → ordena categorias {memory_type × flow × scope} por ROI. O distiller lê o ranking como **prior de confiança/esforço** (alto ROI ⇒ threshold ligeiramente menor / destilação frontier priorizada; baixo ROI ⇒ mais evidência). **Nunca zera categoria** (piso). Reversível. Auto-aplicação sob charter (Diário + digest).
- Arquivos: comando novo, prior no `AtlasLearningDistiller`, reuso do ablation study.
- Aceite: categoria com lift+ e case_count≥8 ⇒ topo com prior maior; categoria lift≤0 ⇒ mais evidência (nunca desligada); `insufficient_signal` global ⇒ prior neutro (byte-idêntico ao uniforme); ranking expõe denominadores.
- Risco: currículo vira Goodhart — o piso "nunca zera" + digest são o freio; o ranking é informativo, o floor de evidência continua.

**MAXJ-07 — Composição real: A+B co-recall medido → insight composto (frontier AUTORA, gate determinista JULGA)** · E:L · onda M5 · deps: [MAXJ-03, MAXJ-05, ASI-09, ASI-06] · *(onda derivada dos deps — salto que precisa do ciclo girando)*
- Goal: o compounding COMPÕE — duas lições que co-ocorrem em acertos medidos geram um insight que nenhuma tinha sozinha (A+B→C).
- Mecanismo: detector de co-recall sobre `atlas_memory_entry_usages` + `ai_rag_feedback_events`: pares recallados JUNTOS em outcomes **passing medidos** com `co_case_count ≥ floor` (default 8) viram candidatos. O motor frontier de ASI-09 AUTORA o claim composto (C) a partir de A+B+evidência; **entra na MESMA fila e nos MESMOS gates deterministas** (CaptureQualityGate + falseLearningGate + porta ASI-02); jamais se auto-promove. Fallback = sem composição. Shadow → ON. Local para sensitive/secret.
- Arquivos: detector de co-recall novo, reuso do adapter frontier de ASI-09, porta ASI-02.
- Aceite: par A+B com co_case_count≥8 em outcomes passing ⇒ 1 candidato composto na fila held; co_case_count=2 ⇒ NADA; composto nunca promove sem passar os gates de A e B; OFF ⇒ nenhum composto criado.
- Risco: composição espúria — `co_case_count` + outcomes passing + gates herdados; a métrica de composição passa a ler `composed_insight_count` REAL.

**MAXJ-08 — Motor frontier no assento de JUIZ (advisory, zero veto, calibrado ex-post)** · E:M · onda M5 · deps: [MAXJ-01, ASI-09, ASI-15] · *(onda derivada dos deps — depende de calibração ASI-15)*
- Goal: segunda opinião de motor frontier sobre "essa lição vale?" — no papel de JUIZ, sem furar author≠judge nem dar veto a modelo.
- Mecanismo: juiz frontier ADVISORY que recebe uma destilação **que NÃO autorou** (engine ≠ autor) → `{advisory_quality_band, rationale}`. Invariantes: (1) ZERO veto — os gates deterministas seguem os únicos que promovem/bloqueiam; a banda é sinal logado; (2) author≠judge físico (verificado por id de engine); (3) calibração ex-post reusando `CalibrationBandClassifier` (padrão ASI-15): banda-declarada × lift real (MAXJ-05) → curva; (4) **critério de morte escrito**: se após N lições a banda "alta" NÃO prevê lift acima da "baixa" (n mínimo), o juiz é ruído e é removido. Local para sensitive/secret. Flag default-OFF.
- Arquivos: serviço juiz novo (adapter frontier), reuso `CalibrationBandClassifier`, comando de calibração; NÃO toca gates deterministas.
- Aceite: juiz nunca altera `decision`/`status` (só anexa banda); autor==juiz ⇒ recusa; comando de calibração retorna curva com denominadores; separação ausente após N ⇒ issue no ledger de gaps recomendando remoção.
- Risco: virar veto por baixo — proibido por construção (banda não é campo de gate); modelo julgando o próprio texto — bloqueado por engine-id.

---

#### Área 10 + 18 — Decisão & Governança/Constituição (MAXK)

**Teto medido:** a Decisão é **greedy exploit-only, NÃO bandit** — escolhe o provider mais barato acima de um score-floor sobre médias históricas; provider com <5 calls nunca é elegível ⇒ cold-start nunca amostrado; sem régua de regret/contrafactual; latência registrada mas fora do objetivo. A Governança JÁ está no hot-path (Kernel privacy + Admission no `consult()`), mas o freio de autonomia é **boolean forjável** (`$area[sig]===true` :249-266, zero nonce) e as métricas de exit vêm do caller — os dois inputs que graduam autonomia são supridos por quem quer promover. Não existe emenda constitucional: floors são `private const` mudados por PR in-place. Separação de poderes pétrea: Decisão propõe · Governança limita · Evidência registra — quem decide nunca escreve as próprias regras.

**MAXK-07 — Emenda constitucional versionada (registry de floors + amendment receipt)** `[pétreo]` · E:M · onda F0 · deps: [ConstitutionGate monotonicity, ROL-01]
- Goal: mudar uma regra de governança vira **emenda** (proposta etiquetada + reversível + rollback pré-escrito), não diff de constante — executável a fronteira "quem decide nunca escreve as próprias regras".
- Mecanismo: registry das constantes de governança atrás de um `GovernanceAmendmentLedger` append-only; toda mudança exige amendment receipt {proposta_id, diff, rollback_pré-declarado, actor, at} + monotonicity check (reusa `AtlasLoopConstitutionResolveFlags::monotonicityCheck`). **Os floors reais são `private const` em classes PHP, NÃO config keys** (`TRUST_THRESHOLD=0.95` `AutonomyLadderRuntimeService.php:26`, `DEMOTE_CONSECUTIVE_BREACHES=2` :28, `DEFAULT_FORBIDDEN_ACTIONS` `StewardshipAutonomyEnvelope.php:32-40`, thresholds 0.7/0.4 do live-feedback, pesos MAXK-03) — por isso o aceite exige **gate de completude: ZERO floor de governança legível fora do registry** (consts hardcoded removidas; todo consumidor — ladder, envelope, live-feedback, MAXK-03 — lê do registry), senão o registry-beside-const-ainda-hardcoded é re-rotulagem e a emenda fica decorativa. Não duplica ASI-01 (superfície config do self-edit do loop).
- Arquivos: registry novo + `GovernanceAmendmentLedger`, `AutonomyLadderRuntimeService.php:26,28`, `StewardshipAutonomyEnvelope.php:32-40`, live-feedback thresholds, reuso `AtlasLoopConstitutionResolveFlags::monotonicityCheck`.
- Aceite: mudar um floor sem amendment receipt → rejeitado; emenda que afrouxa safety sem etiqueta → `monotonicity_violation`; toda emenda tem rollback executável pré-declarado (ROL-01); **gate grep/AST: 0 floor de governança lido fora do registry (consts removidas)**; `atlas:governance:amendments --json` lista o histórico. **ELEV-09 (escopo além da área 18):** os floors que gate-iam auto-aplicação nas OUTRAS áreas — confidence floor do atuador MAXH-04 (auto-supersedência!), lista de kinds seguros do ASI-07, floors por-área do MAXL-05, floor n≥8 do MAXJ-03/05 — entram no registry OU ganham **teste-sentinela congelando o valor** (mudança = teste vermelho = emenda consciente); floor de auto-aplicação editável por config in-place é exatamente o defeito que este slice existe para matar, e ele não vive só na área 18.
- Risco: registry-beside-const ainda editável — o gate de completude é o aceite duro; colisão com ASI-01 — superfícies distintas, zero sobreposição de arquivo-alvo.

**MAXK-01 — Régua de regret/contrafactual da rota (v2 congelada, escopo grosso)** `[DECISÃO][MEDIDOR]` · E:S/M · onda M1 · deps: [MED-01, ASI-06 (a espinha rasa até lá)]
- Goal: medir a QUALIDADE da escolha de músculo antes de mudar qualquer router — proven_success + custo + latência da rota CHOSEN vs FALLBACK.
- Mecanismo: ledger de outcome de roteamento (reusa `live_outcomes.jsonl` + o `fallback` que `costOutcomeRoute` já computa, :152); job ex-post junta escolhido×fallback por `entry_hash`; regret proxy = Δ(proven_success_rate) − custo-benefício. **Agregar a escopo GROSSO (role×category, DROPAR framework)** até ASI-06 encher a espinha (~7 linhas hoje; a tripla task_category×role×framework dá 0-1 sample/cell = ruído n=1) → reportar `insufficient_n` por célula, não número. **Nomear o mínimo concreto: ≥ min_evidence 3 / MIN_CALLS 5.** **Redefinir o contrafactual para exploration ser scorável: logar o pick GREEDY que o exploratório DESLOCOU (chosen=exploratory vs would-have-been-greedy), não o in-pool second-best** (que não pontua um cold-start jamais elegível — o que MAXK-02 existe para medir). Régua v2 congelada; peek (`record_usage=false`), nunca infla séries v1.
- Arquivos: `AtlasDecideCostOutcomeRouter.php:152`, ledger de roteamento, job ex-post, comando `atlas:atlas-decide:live-feedback --regret --json`.
- Aceite: reporta regret por escopo grosso com n≥3/5 nomeado; célula com n baixo ⇒ `insufficient_n` (não número); hash da régua registrado; nenhum medidor v1 tocado; o contrafactual de exploração loga o greedy deslocado.
- Risco: régua rasa até ASI-06 — `insufficient_n` honesto por célula; virar proxy — num/den crus.

**MAXK-04 — Escolha de músculo como Decision Receipt v2 canônico** `[DECISÃO]` · E:S/M · onda M1 · deps: [DecisionReceiptIssuer v1]
- Goal: a decisão executiva vira receipt de primeira classe — basis, evidência, veredito de governança, decision_id.
- Mecanismo: `consult()` emite via `DecisionReceiptIssuer` além do JSONL atual (aditivo): campos `routing_basis` (score|cost_outcome|exploration), `evidence_refs`, `kernel_decision`, `admission_decision`, `decision_id`. **`DecisionReceiptIssuer::issue()` (`app/Services/Ai/Kernel/Decision/DecisionReceiptIssuer.php:21`) exige `OperationEnvelope $envelope, array $decision` — montar/mapear um `OperationEnvelope` a partir do context do consult (escopo/task/role) ANTES de `issue()`** (o adaptador de fronteira é sub-tarefa; esforço M). Não duplica ASI-11 (estampa linhagem) nem ASI-15 (banda).
- Arquivos: `AtlasDecideGatewayConsultationService.php`, `DecisionReceiptIssuer.php:21` (+ adaptador OperationEnvelope), `gateway_consultations.jsonl` (preservado).
- Aceite: cada consult produz um Decision Receipt v2 com decision_id estável; **`atlas:ai:decision-receipt-report`** (hífen — a signature real em `AtlasAiDecisionReceiptReportCommand.php:10`, NÃO `:report`) lista rotas com basis e evidência; `gateway_consultations.jsonl` byte-compat.
- Risco: adaptador OperationEnvelope subestimado — marcado como sub-tarefa, esforço M.

**MAXK-05 — Assinaturas da escada property-gated wiradas na mutação REAL (ledger nonce+policy_hash)** `[GOV][pétreo]` · E:M · onda M2 · deps: [ASI-05, AtlasAutonomousLearningApplier.php:356 (a mutação auto-apply viva)]
- Goal: matar o boolean forjável E dar-lhe denominador de enforcement — a assinatura property-gated tem que RECUSAR uma mutação real, não só retornar `signature_missing` num calculador advisory.
- Mecanismo: `missingSignatures` (`AutonomyLadderRuntimeService.php:249-266`) verifica cada assinatura contra um **ledger de assinaturas append-only**: receipt = {actor, nonce, policy_hash, at} bound ao alvo; verificação por nonce (padrão `AtlasLoopConstitutionGateToken`). Boolean no mapa deixa de ser aceito. **Wirar o verdict property-gated na ÚNICA mutação auto-apply viva — o `promotion_gate` de `AtlasAutonomousLearningApplier.php:356` (`auto_apply=false`)**, não só no calculador advisory que ninguém enforce (cujos callers são L7PromotionRequestBuilder/DemoteWatchdog/MetricsAggregator/CLI report — todos report/calc). Prova o `operator_actor` do `StewardshipAutonomyEnvelopeService::arm` (:60). Aplica ao sibling `Autonomy/AtlasAutonomyLadderRuntimeService`.
- Arquivos: `AutonomyLadderRuntimeService.php:249-266`, `AtlasAutonomousLearningApplier.php:356`, ledger de assinaturas, sibling ladder.
- Aceite: suíte adversarial que monta `$area` com todas as assinaturas `true` **sem** receipt no ledger → **mudança de estado de promoção REAL é BLOQUEADA** (não só `signature_missing` retornado); com receipt válido → passa; nonce reusado → NEGADO; asserir "assinaturas all-true forjadas bloqueiam uma promoção de fato".
- Risco: endurecer o advisory e deixar a mutação aberta — o aceite nomeia a mutação concreta (`:356`) e exige o bloqueio real, não o retorno da lista.

**MAXK-06 — Métricas de exit da escada lidas de ledger imune, não do caller** `[GOV]` · E:S/M · onda M2 · deps: [MAXK-05, ASI-05, MED-01]
- Goal: fechar o segundo input forjável — o mesmo ator que promove não fornece mais os números que decidem.
- Mecanismo: o gate lê `$metrics` de uma porta de autoridade de métrica (fonte selada ASI-05 / `AtlasAutonomyMetricsAggregator` com proveniência), não de mapa livre no boundary. `evaluate()` como calculadora continua para report; o **gate de promoção** exige métrica com proveniência.
- Arquivos: `AutonomyLadderRuntimeService.php` (leitura de métrica), `AtlasAutonomyMetricsAggregator`, fonte selada ASI-05.
- Aceite: métrica fabricada no caller não altera o verdict de promoção; métrica da fonte selada é a que conta; report mode intocado.
- Risco: régua v2 sem juiz — a métrica selada é instrumento; report segue calculadora.

**MAXK-02 — Política de exploração honesta (Thompson/UCB budget-capped, governance-gated)** `[DECISÃO]` · E:M · onda M4 · deps: [MAXK-01, ASI-01, gateway consult]
- Goal: o Decide passa a **descobrir** — amostrar providers não-provados sob orçamento — em vez de só explorar o melhor já-medido.
- Mecanismo: braço de exploração no `recommendationFromSignal`/consult: Thompson sobre Beta(`proven_success`, `failure`) por provider|escopo, OU ε com budget diário capado; toda exploração passa pelo MESMO `consult()` (Kernel bloqueia sensitive/secret/cyber; Admission decide autonomia); nunca sobrepõe veredito de governança; pick carimbado `routing_basis=exploration`. Switch default-OFF; OFF = greedy byte-idêntico.
- Arquivos: `AtlasDecideGatewayConsultationService.php`/router, config `ATLAS_..._EXPLORATION_ENABLED`.
- Aceite (não `regret ON≤OFF`, que premia NÃO explorar): **(a) cobertura de providers amostrados sobe estritamente sob ON no fixture cold-start**, com denominador mínimo de picks exploratórios; **(b) degradação de regret LIMITADA — ON regret ≤ OFF regret + budget declarado** (não ON≤OFF); invariantes duros: 0 picks em sensitive/secret/cyber; budget nunca excedido (assert por contagem).
- Risco: exploração vira teatro por near-inatividade — o aceite mede cobertura↑, não regret≤; budget e class-gate são hard invariants.

**MAXK-03 — Objetivo multi-objetivo com pesos operator-authored por risk-class** `[DECISÃO]` · E:M · onda M4 · deps: [MAXK-01, área 15 (MAXN-02)]
- Goal: escolher por proven_success × custo × **latência** ponderados pela classe de tarefa, não cost-first cego.
- Mecanismo: escalarização em `costOutcomeCandidates`/seleção; pesos vêm de config operator-authored por risk-class (charter: operador seta a política uma vez), **default = cost-first atual** ⇒ OFF byte-idêntico. Latência (já no ledger, :345) entra no score. Pesos **nunca** escritos pelo modelo (fronteira pétrea); só mutáveis por config/emenda (MAXK-07).
- Arquivos: `AtlasDecideCostOutcomeRouter.php` (seleção/escalarização), config de pesos.
- Aceite: com pesos default, seleção idêntica à atual; com peso de latência, rota escolhida tem p95 menor sem queda de proven_success no yardstick; pesos só mutáveis por config/emenda (MAXK-07).
- Risco: peso forjado pelo modelo — pesos são config do operador, nunca do modelo.

**MAXK-08 — Calibração do envelope por reversões (máquina aperta, operador re-alarga)** `[GOV]` · E:M · onda F2 · deps: [ASI-11, MAXK-07]
- Goal: autonomia estreita sozinha quando reversões sobem; só o operador re-alarga (charter).
- Mecanismo: os ceilings do envelope (`max_auto_merge_files`, `risk_ceiling`, `max_cycles`) viram um **teto efetivo EFÊMERO computado em runtime** — função monotônica-para-baixo da taxa de reversão/rollback (linhagem ASI-11), **nunca persistido**, só safety-increasing. **A const de governança armazenada fica byte-inalterada após um ciclo de auto-aperto**; qualquer mudança na const persistida OU no mapa de calibração roteia pelo `GovernanceAmendmentLedger` (MAXK-07) — a máquina nunca escreve a própria regra. Aperto = automático (lei de máquina); re-alargar = re-arm do operador. Default-OFF até ASI-11 fornecer linhagem.
- Arquivos: envelope service (teto efêmero em runtime), `StewardshipAutonomyEnvelopeService::arm`, linhagem ASI-11, ledger MAXK-07.
- Aceite: reversal_rate alto → ceiling **efetivo** cai; **a const armazenada é byte-inalterada após o auto-aperto** (teste); ceiling nunca sobe sem re-arm do operador; mudança da const ou do mapa de calibração passa por emenda (MAXK-07).
- Risco: máquina escrever a própria regra de governança — teto efêmero nunca persiste; a const só muda via ledger de emenda.

**MAXK-09 — Certificação: escada+envelope como gate real ungameable (plugin WDG-01), sobre a mutação REAL** `[GOV][CERT]` · E:M · onda M5 · deps: [MAXK-05, MAXK-06, MAXK-07, MAXK-08]
- Goal: fechar o "vários sem enforcement" — os verdicts property-gated viram check permanente provado ungameable, nomeando a mutação concreta que gateiam.
- Mecanismo: check plugin do WDG-01 que roda os gates de assinatura/exit/emenda em modo adversarial (ADV-Max): tenta forjar cada input (boolean, métrica de caller, floor sem emenda) → **teste vermelho** se algum passar. **Nomear a mutação concreta gateada — a promoção de estado em `AtlasAutonomousLearningApplier.php:356` (MAXK-05) — não a lógica de gate genérica** (o land-path do WDG-01 v1 testa lógica de gate, categoria diferente da promoção da escada de autonomia).
- Arquivos: plugin no `AtlasWatchdogCheckRegistry`, suíte adversarial, ledger de flips.
- Aceite: suíte adversarial 100% verde (todo forjar-input NEGADO **numa mudança de estado real**); check no caminho de land; regressão que reabre o boolean forjável quebra o gate.
- Risco: certificar o advisory em vez da mutação — o aceite nomeia `:356` como o alvo gateado.

---

#### Área 11 + 12 — Evidência & Certificação / Execução Verificada (MAXL)

**Teto medido:** o Evidence Ledger é append-only por **convenção** (payload_hash por linha), 3 dias de dados vivos; `event_hash` **desligado por drift de schema pós-wiper** (coluna ausente na tabela viva), **cadeia inexistente** → deleção/reordenação indetectável; o verificador de cadeia é órfão (0 callers). O gate longitudinal é honesto mas **agregado-único e acausal** (1 overall + 1 pipeline × 30d; hoje 8/30). Execução verificada: AVCEL prova estrutura em shadow, ACQCG prova precision@k sintético, pipeline prova teste-verde-fresco — **nenhum prova execução real correta ligada ao contexto que a causou.**

**MAXL-01 — Reparo do drift de schema do ledger: religar `event_hash`+`scope_type`/`scope_id`** `[HIGIENE/MEDIDOR]` · E:S · onda F0 · deps: [v1 SUB-01, EVI-01]
- Goal: o selo por-evento (que existe no código e está DESLIGADO no vivo por coluna ausente pós-wiper) volta a gravar E é de fato verificado.
- Mecanismo: migration idempotente `hasColumn`-guarded restaurando `event_hash`(64,idx), `scope_type`(40), `scope_id`(80) em `atlas_ledger_events` (espelha `2026_05_19_050000`); backfill do `event_hash` das **489 linhas** por RE-HASH determinístico dos bytes já gravados (sela bytes existentes, NÃO backfill de tempo, `occurred_at` intocado); guard `DatabaseTableAvailability::hasColumn` adicionado aos métodos **realmente desguardados: `latestForScope()` (`:201`) + `engineeringOutcomeEvent()` (`:216`)** — **`eventsForScope()` (`:132-137`) JÁ é guardado** (não é alvo). Snapshot SUB-01 cobre a tabela antes.
- Arquivos: migration nova, `AtlasEvidenceLedger.php:201,216` (guards), backfill.
- Aceite (property-gated, NÃO format-only): `information_schema` mostra as 3 colunas; evento novo grava `event_hash` não-nulo; **para uma amostra das 489 linhas, recompute `event_hash` DETERMINÍSTICO (fixar serialização de `occurred_at` em UTC ms canônico) + `hash_equals` contra o valor gravado** — não confiar em `eventIntegrityValid()` que só checa formato hex (`:227-243`, regex OU null=válido, satisfazível por qualquer 64-hex); teste-sentinela: toda query `where('scope_type')` no ledger precedida de `hasColumn` (varredura = 0 desguardadas em `latestForScope`/`engineeringOutcomeEvent`).
- Risco: selo decorativo por format-only — o aceite recompute+hash_equals mata isso; drift de serialização — `occurred_at` canônico fixado.

**MAXL-02 — Hash-chain REAL do Evidence Ledger (`prev_hash` por cadeia)** `[MEDIDOR]` · E:M · onda F0 · deps: [MAXL-01]
- Goal: adulteração por DELEÇÃO/reordenação/inserção vira detectável — prova criptográfica de integridade, não só edição de payload por linha.
- Mecanismo: coluna `prev_event_hash`(64,nullable) + writer que, dentro da cadeia (chave `scope_type:scope_id`, fallback `correlation_id`), sela cada evento ao `event_hash` do anterior (head da cadeia sob lock curto no `record()`); genesis = `null` rotulado. Linhas pré-chain (as 489 seladas em MAXL-01) recebem `chain_basis: legacy_unchained` honesto. O `EvidenceLedgerHashChainIntegrityVerifier` órfão é **materializado** (adaptado às colunas reais) — deixa de ser 0-caller. **ELEV-11 (âncora EXTERNA + threat model declarado):** o chain-head diário é commitado no git junto com a âncora JSONL (os commits escopados já são a âncora externa natural do sistema) — hash-chain no MESMO Postgres + âncora no MESMO disco detecta acidente/deleção ingênua, não processo com acesso ao DB que re-sela a cadeia; o threat model coberto é adulteração acidental/parcial, e isso fica escrito, não implícito.
- Arquivos: migration `prev_event_hash`, `AtlasEvidenceLedger.php::record`, `EvidenceLedgerHashChainIntegrityVerifier.php` (materializado).
- Aceite: insere 3 eventos na mesma cadeia ⇒ verificador `status=ok`; DELETA a linha do meio ⇒ `gap`; edita 1 payload ⇒ `tampered`; append normal ⇒ `ok`; nenhuma régua v1 tocada (colunas aditivas).
- Risco: cadeia falsa por linhas legacy — `chain_basis: legacy_unchained` nunca finge selo.

**MAXL-06 — Evidência causal: linhagem mudança→delta no ledger (report-only)** `[CARTÓRIO]` · E:M · onda M1 · deps: [ASI-11, MAXL-04]
- Goal: cada movimento diário do scorecard ganha os decision_ids/commits que landaram na janela — atribuição registrada, nunca decisão.
- Mecanismo: junta o delta diário da série v2 com o ledger de linhagem do ASI-11 (decision_id↔commit↔receipt); quando ausente, degrada a `git log --since/--until` (basis mais fraco, rotulado). Emite `attributed_delta` **report-only** com `basis: {lineage_ledger|git_log}` e `counterfactual_basis: none` explícito. É Evidência (11), não Decisão (10): jamais claim causal sem o rótulo `correlational_attribution`.
- Arquivos: junção série v2 × linhagem ASI-11, artefato report-only.
- Aceite: para um dia com `overall` movido, lista decision_ids candidatos + commit hashes; grep no artefato = 0 claims causais sem rótulo; nenhuma série mutada; provider_calls=0.
- Risco: correlação lida como causa — rótulo `correlational_attribution` + `counterfactual_basis: none` no papel.

**MAXL-03 — Integridade do ledger como check vivo do WDG-01 + âncora diária** `[MEDIDOR]` · E:S · onda M4 · deps: [MAXL-02, v1 WDG-01, EVI-01]
- Goal: o verificador (agora real) roda diário sobre o ledger vivo; drift/tamper alerta em vez de passar silencioso.
- Mecanismo: plugin `evidence_ledger_integrity` no `AtlasWatchdogCheckRegistry` — roda o verificador sobre as cadeias do dia, emite issue no ledger de gaps (EVI-01) em `tampered`/`gap`, appenda linha diária `{date, chains, chain_length, tampered, gap}` num JSONL (append-only, SUB-01).
- Arquivos: plugin no `AtlasWatchdogCheckRegistry`, JSONL de integridade.
- Aceite: `atlas:watchdog:run --json` lista o check com `evidence.{chains, tampered_event_ids, gap_count}`; tamper sintético ⇒ alerta; run < 5s; zero payload cru no artefato.
- Risco: check custoso — run < 5s; false alert — só `tampered`/`gap` disparam.

**MAXL-04 — Série v2 por-área (14 módulos/73 facets → séries diárias)** `[MEDIDOR]` · E:M · onda M4 · deps: [v1 EVI-05/06 (série v1 congelada), MED-01]
- Goal: certificação passa a ter séries POR-ÁREA, não só o agregado — a área mais fraca fica visível ao longo do tempo.
- Mecanismo: produtor lê o breakdown por-subsistema que `AtlasCognitionScoreCardService::build()` **já computa** e appenda **`acos-delta-series.v2.jsonl` NOVO** (`by_area.<area>.{overall,code,doc,pipeline}` + agregado), mesmas regras pétreas da v1 (append-only por dia, `--date` retroativo recusado, `recorded_at.date==date`, provenance `resolved-evidence`). Peek (`record_usage=false`). Série v1 **byte-idêntica** (arquivo separado). Dual-read MED-01 no land. *(Nota: a régua v2 congela ANTES de ser consumida pelo gate MAXL-05, na mesma onda M4.)*
- Arquivos: produtor novo, `acos-delta-series.v2.jsonl`, leitura de `AtlasCognitionScoreCardService`.
- Aceite: linha v2 carrega `by_area` com ≥14 áreas; `sha256(acos-delta-series.jsonl)` inalterado; teste: produtor não escreve na v1; anti-backfill herdado.
- Risco: v2 inflar séries v1 — arquivo separado + peek; backfill — row future-dated/backfilled recusada.

**MAXL-05 — Gate longitudinal por-área (v2, régua versionada)** `[CERTIFICADOR]` · E:M · onda M4 · deps: [MAXL-04, v1 EVI-07]
- Goal: o relógio de 30d exige que CADA área trackeada segure seu floor na janela — o agregado ≥9.5 não pode mascarar uma área podre.
- Mecanismo: variante v2 do `AtlasAcosLongHorizonGateService` que **reusa** window/gap/staleness/backfill/future-date da v1 (zero re-implementação) e adiciona varredura de floor **por-área** sobre a série v2 (padrão do `certificationWindowOverallScan`). Floors por-área configuráveis, default = min_overall — **ELEV-09: mudança de floor roteia pelo `GovernanceAmendmentLedger` (MAXK-07) ou quebra teste-sentinela; floor abaixável por config in-place tornaria o gate por-área decorativo (abaixa-se o floor para passar)**. **Gate v1 congelado e intocado** (relógio correndo, 8/30). Nunca flipa sozinho; feed segue ROL-01.
- Arquivos: gate v2 novo (reuso da máquina v1 por composição), série v2.
- Aceite: fixture onde agregado ≥9.5 mas 1 área fura o floor por 1 dia ⇒ v2 BLOQUEIA com `area_below_floor:<area>`; todas seguram ⇒ ready; receipt do gate v1 byte-idêntico no mesmo corte (dual-read).
- Risco: gate v2 sem massa — herda o `insufficient` da série; nunca substitui a v1 durante o relógio.

**MAXL-07 — Contrafactual barato: replay-sem-a-mudança no golden vN (A/B medido, gated)** `[CARTÓRIO]` · E:M · onda M4 · deps: [MAXL-06, MAXG-04]
- Goal: o ÚNICO contrafactual honesto — "o que o recall@5 teria sido SEM esta mudança" medido por A/B no golden congelado, não palpite de modelo.
- Mecanismo: para uma mudança landada de retrieval/contexto com decision_id, re-roda o golden vN no commit-pai vs commit-filho (**gated, off hot-path, opt-in, background atrás da fila**) e grava `recall_at_5_without` vs `_with` do par REAL. Rotula `skipped` quando checkout indisponível. Nunca extrapola além do par medido. **É o lastro honesto que MAXL-08 e o enforce do ASI-10 exigem antes de virar número.**
- Arquivos: replay A/B gated, golden vN (MAXG-04), report.
- Aceite: dados 2 commits, o report mostra `_without`/`_with` de runs reais + `delta`; skip rotulado quando não-executável; teste: nenhum campo contrafactual sem os dois runs presentes.
- Risco: checkout pesado — gated/background; extrapolação — só o par A/B medido.

**MAXL-08 — AVCEL: receipt pós-execução ligado ao outcome REAL (shadow do sinal novo, report-only)** `[MEDIDOR]` · E:M · onda M4 · deps: [v1 ENG-13/14/15, ADV-01, ASI-10, COM-01, ARFL, MAXL-07 (o lastro)]
- Goal: medir a co-ocorrência "o contexto entregue apareceu no used-set do run que passou verde" — hoje AVCEL prova só estrutura em shadow.
- Mecanismo: receipt pós-execução (canal shadow separado do AVCEL estrutural) que liga, num run real: `delivered_refs` (COM-01) ∩ `used_refs` (ARFL `measured=true`) → outcome = green-run receipt id. **Rotulado explicitamente `correlational_cooccurrence` (como MAXL-06 rotula `correlational_attribution`); a linguagem "causou" é REMOVIDA do goal** — isto é presença simultânea, não causalidade. **Report-only estrito; PROIBIDO alimentar o enforce do ASI-10 até que a ligação seja lastreada pelo par contrafactual medido de MAXL-07** (senão um sinal correlacional vira gate de enforcement = Goodhart). Degrade honesto: ARFL `measured_share=0` ⇒ `unmeasurable`. Sensitive/secret local.
- Arquivos: receipt pós-execução (canal shadow), leitura COM-01/ARFL, green-run receipt.
- Aceite: em run real, receipt carrega `delivered_refs`, `used_refs`, `outcome=<green-run receipt id>`, `context_causal_binding` rotulado `correlational_cooccurrence`; **denominador cru exposto (n de runs, measured_share)**; caso negativo: `measured_share=0 ⇒ unmeasurable` (sem número); intersecção-não-vazia sozinha nunca vira número de enforcement; caminho sintético nunca conta.
- Risco: correlação virar gate — report-only + proibição de alimentar ASI-10 até MAXL-07 lastrear.

**MAXL-09 — ACQCG cruzado com outcome real (sai do `synthetic_readiness_only`)** `[MEDIDOR]` · E:S · onda M5 · deps: [MAXL-08, v1 COM-07, MAXG-04]
- Goal: o frontier auto-declarado do doc canon — cruzar o sinal ACQCG com outcomes reais, sem fundir num escalar fabricável.
- Mecanismo: base ADITIVA no `certify()`: cruza a qualidade ACQCG com os outcomes do delivered-pack real — ARFL `used_ratio` (measured=true) × taxa de green-run pass numa janela — reportada com `basis` próprio, ao lado da precision@k. Nunca funde (lição do "92"): as duas com denominadores crus.
- Arquivos: `AtlasContextQualityCertificationService::certify()` (aditivo), leitura ARFL/green-run.
- Aceite: **`atlas:context:quality-certify --json`** (a signature real em `AtlasContextQualityCertifyCommand.php:12`, NÃO `quality-certification`) ganha `real_outcome_crosscheck.{used_ratio, green_run_pass_rate, n}` com `basis=measured`; `n<min` ou `measured_share=0` ⇒ `unavailable` (nunca um número); score sintético byte-idêntico com flag OFF.
- Risco: fundir num escalar — as duas expostas com denominador; comando errado — signature real corrigida.

**MAXL-10 — Marco Zero v2 por-área embutido no selo (coordena MAXG-08, NÃO duplica)** `[CERTIFICADOR]` · E:S · onda M5 · deps: [MAXG-08, MAXL-04, MAXL-02, v1 ADV-01] · *(o crítico não sequenciou L-10; onda M5 do fragmento, gatilho ADV-01)*
- Goal: quando o v1 certificar 10/10 e o MAXG-08 cunhar Marco Zero v2, o selo captura a verdade POR-ÁREA + a âncora de integridade do ledger.
- Mecanismo: **não é segundo minter** — ESTENDE o payload do freeze do MAXG-08 com `by_area_baseline` (série v2, MAXL-04) + `ledger_chain_head` (hash da cabeça-de-cadeia, MAXL-02), gated pelo MESMO veredito ADV-01. Zero freeze paralelo.
- Arquivos: extensão do payload de freeze do MAXG-08.
- Aceite: pós-freeze, `marco-zero-acos-v2-<data>.json` contém `by_area_baseline` + `ledger_chain_head`; campos existentes do MAXG-08 byte-idênticos; um único minter (teste: nenhum segundo caminho de freeze).
- Risco: segundo baseline — minter único (MAXG-08); coordenação explícita.

---

#### Área 14 — Porta do Cérebro & Provider-safety (MAXM)

**Teto medido:** a redação provider-safe é **denylist de ~11 formatos** — qualquer segredo/prompt-interno fora dos padrões vaza por construção; **a prova não existe** (1 teste de que entry `secret` não vaza, zero adversarial omni-superfície). A defesa de injeção está construída e desligada (outbound gate: 1 caller de incidente; inbound classifier `@unwired-until 2026-08-05`). A superfície é **64 tools planas** sem `stability/since/side_effect/provider_bound/cost_tier` por-tool (progressive disclosure anunciada mas não implementada). Sem identidade de consumidor ⇒ sem cota honesta.

**MAXM-05 — Contrato self-describing por-tool + versionado (conjunto-mutante de fonte independente)** · E:S/M · onda M2 · deps: []
- Goal: um provider externo navega a porta por metadado de máquina, sem ler descrição em PT.
- Mecanismo: cada entry de `tools()` ganha `annotations.atlasContract` {`stability`, `since`, `provider_bound:bool`, `side_effect: read|write`, `cost_tier`}. Bump `surface_contract` para `.v1.1` (aditivo). **O invariante "read-tool nunca escreve" deve derivar o conjunto-mutante de fonte INDEPENDENTE do rótulo** — senão o invariante é circular/tautológico (a lista de write-tools e a annotation `side_effect=write` derivam da mesma lista hand-mantida; uma write nova rotulada read não quebra o teste). O teste inspeciona o corpo do handler privado de cada tool marcada `read` e falha se toca seam de persistência (`->save(`/`->create(`/`AtlasOpenBrainWriteBackService`/`record*Persisted`/`DB::` mutativo), OU cruza a lista de write-tools contra o conjunto real de handlers que chamam write-path. Assim drift (write novo rotulado read) quebra o teste.
- Arquivos: `AtlasOpenBrainMcpService.php:213-1274` (tools), `surfaceContract()`, teste de dispatch com inspeção de handler.
- Aceite: toda tool tem os 5 campos; toda write-tool tem `side_effect=write`; **teste que inspeciona o corpo do handler de cada read-tool e falha se ela toca write-path** (não re-rotulagem circular); `atlas_capabilities` expõe o contrato por-tool. *(Contagem: **64 tools** — não 66; `tools()` retorna 64 entradas, o alias de COMPATIBILITY não é anexado por `toolNames()`.)*
- Risco: invariante circular cego a drift — a fonte independente (inspeção de handler) é o fix.

**MAXM-08 — Progressive disclosure estrutural (gate do tools/list + discovery)** · E:M · onda M2 · deps: [OPE-07, OPE-05, MAXM-05]
- Goal: um provider novo recebe uma superfície navegável (primárias + descoberta), não 64 tools planas.
- Mecanismo: `tools/list` passa a devolver as 9 primárias + um `atlas_tool_search` (query→subset com o contrato por-tool do MAXM-05); as 64 permanecem chamáveis por nome (compat), a `deprecation_policy` 90d intacta. Alternativa medida: os 3 tiers físicos SÓ se OPE-07 mostrar demanda. Zero remoção (herda OPE-07). Não reorganizar endpoints antes da telemetria OPE-05.
- Arquivos: `AtlasOpenBrainMcpService.php` (`tools/list`), `atlas_tool_search` novo.
- Aceite: `tools/list` default ≤ 10 entries + `atlas_tool_search` resolve tool por intenção; toda tool antiga ainda responde a `tools/call` por nome (compat byte-idêntico); surface-review sem regressão.
- Risco: reorganizar antes da telemetria — gated em OPE-05/07; sobreposição com ASI-16 — thin-client se o residente landar antes.

**MAXM-01 — Corpus adversarial de vazamento provider-safe + teste omni-superfície** `[MEDIDOR]` · E:M · onda M3 · deps: [] (a régua; landa antes de MAXM-04)
- Goal: uma prova executável de que segredo/prompt-interno nunca sai por NENHUMA superfície provider-bound.
- Mecanismo: fixture congelada `provider_leak_corpus/v1.json` (juiz≠implementador) com ~30 payloads: segredos FORA dos 11 regexes (token custom, id interno, frase-segredo), fragmentos de prompt interno, imperativos-injeção. Teste alimenta cada payload como corpo de entry `normal` E `sensitive` E `secret`, percorre `memorySection` do pack, cada read-tool MCP que devolve texto de memória (`atlas_memory_recall/get/context_pack/aurg_query/mission_history/obra_status`), `AtlasProviderProjectionService` (claude E agents). Assert: payload literal ausente; `redaction_status` nunca diz `clean` sobre corpo com marcador; entry `secret` 0 ocorrências.
- Arquivos: `provider_leak_corpus/v1.json`, `ProviderLeakCorpusTest` (novo).
- Aceite: `php artisan test …ProviderLeakCorpusTest` verde; hash do corpus no ledger; documentar baseline (quantos a denylist ATUAL pega vs quantos vazam — a linha de base que MAXM-04 sobe).
- Risco: corpus vira alvo de treino — congelado, juiz≠implementador.

**MAXM-02 — Wire do outbound safety-gate no caminho provider-bound vivo** · E:S · onda M4 · deps: [MAXM-01]
- Goal: o gate que detecta prompt-leak/imperativo passa a rodar no pack/MCP/projeção, não só no incident-planner.
- Mecanismo: `AtlasProviderProjectionService::entryLine()` e o `memorySection` do pack chamam `AtlasOpenBrainMemoryProjectionSafetyGate::evaluate()` sobre {summary,excerpt,title,source,freshness}; violação sem `safe_text` ⇒ item cai + `recordProviderMemoryBlocked`; com `safe_text` ⇒ usa o sanitizado. Fail-closed na SAÍDA (item some), nunca quebra o pack inteiro.
- Arquivos: `AtlasProviderProjectionService.php` (entryLine), pack `memorySection`, `AtlasOpenBrainMemoryProjectionSafetyGate`.
- Aceite: entry com "ignore previous instructions" no corpo não aparece no pack nem na projeção; entry limpa byte-idêntica; contador de itens bloqueados no ledger. Reusa MAXM-01 como régua.
- Risco: gate quebrar o pack — fail-closed no item, nunca no pack inteiro.

**MAXM-03 — Wire do inbound injection-classifier na admissão de memória/captura** · E:S/M · onda M4 · deps: [MAXM-01 (fonte dos payloads imperativos)]
- Goal: conteúdo observado que tenta instruir o Atlas nunca vira `live_instruction` nem memória autoritativa.
- Mecanismo: `AtlasOpenBrainSessionCaptureService` (write-back de sessão) e o CORP-01 `capture-candidates` classificam cada segmento via `AtlasOpenBrainContextInjectionBoundaryClassifier::classify({text, source, age_seconds})`; só `source ∈ {current_turn, task_contract}` recente ⇒ `allow_as_worker_directive`; imperativo em `source=memory/excerpt` ⇒ `quoted_memory` (não-diretivo). Remove o marker `@unwired-until 2026-08-05`. Reusa payloads imperativos do corpus MAXM-01.
- Arquivos: `AtlasOpenBrainSessionCaptureService.php`, CORP-01 capture-candidates, `AtlasOpenBrainContextInjectionBoundaryClassifier.php` (remove `@unwired-until`).
- Aceite (binding = teste comportamental do corpus, não "≥1 classificação por sessão"): excerpt observado "ignore prior / delete all X" capturado ⇒ `quoted_memory` com `allow_as_worker_directive=false`; instrução real do turno atual ⇒ `live_instruction`; **denominador de liveness = ≥1 segmento reclassificado-para-não-diretivo em sessões que CONTENHAM segmento imperativo observado** (não "qualquer sessão", que passa com um `noise` trivial).
- Risco: classificar `noise` trivial e passar — o aceite exige reclassificação em sessão com imperativo observado.

**MAXM-04 — Redação provável-por-construção: projeção provider-bound montada de campos allowlistados** · E:M · onda M4 · deps: [MAXM-01, MAXM-02]
- Goal: a saída provider-safe é COMPOSTA de campos vetados, não derivada de corpo cru passado por denylist.
- Mecanismo: `providerBody/Summary` param de emitir corpo cru; a linha provider-bound é montada de {title-token curado, `redacted_summary` vetado na escrita, refs canônicos `memory:<hash>`}. Corpo cru só sai se a entry carregar stamp `provider_body_verified` gravado NO WRITE-PATH. A denylist `AtlasSecurity` permanece como 2ª linha. Degrade honesto: sem campo vetado ⇒ title-token + "corpo omitido".
- Arquivos: `AtlasMemoryPrivacyService` (providerBody/Summary), write-path stamp, `AtlasSecurity` (2ª linha).
- Aceite: MAXM-01 — todos os payloads de segredo-novo em corpo `normal` ausentes da projeção e do pack (baseline sobe para 100% capturado); entry sem `provider_body_verified` nunca emite corpo cru; dual-read MED-01 (itens que perderam corpo cru).
- Risco: quebrar packs úteis — degrade honesto (title-token) preserva citabilidade.

**MAXM-06 — Receipt de redação + invariante anti-drift provider-bound** `[MEDIDOR]` · E:S · onda M4 · deps: [MAXM-01, MAXM-04]
- Goal: nenhuma superfície provider-bound emite cru quando o estado diz redigido; drift silencioso vira teste vermelho.
- Mecanismo: cada emissão provider-bound anexa `redaction_receipt` {patterns_fired[], class_gate, verified_by} (sem o valor cru); invariante WDG-01-plugin + teste: `redaction_status=redacted` ⇒ superfície não contém o raw; entry com metadata fake (`local-hash-v1`) é sinalizada. Reusa `recordProviderMemoryBlocked`.
- Arquivos: plugin `provider_bound_redaction_drift` no WDG-01, `recordProviderMemoryBlocked` (reuso).
- Aceite: check plugin retorna alert=none na régua MAXM-01; teste com row-fake ⇒ alert=drift.
- Risco: drift silencioso — o invariante é o teste vermelho.

**MAXM-07 — Cota/cadência honesta por consumidor (identidade local, fail-open)** · E:M · onda M4 · deps: [OPE-05]
- Goal: a porta contabiliza chamadas por `client_id` opaco e anuncia limites que ela realmente aplica.
- Mecanismo: accounting local de cadência por `client_id` (janela rolante, store append-only provider-safe) reusando o evento `open_brain.mcp_tool_call` do OPE-05; backpressure SOFT (`rate_softcapped` + retry-after, nunca 500) acima do limite; `client_id` continua opaco/nunca-ramifica-em-plataforma. `transportContract().quotas` ganha `calls_per_window` REAL. Fail-open: falha de accounting nunca bloqueia read. NÃO fazer: rate-limit dos HOOKS shell (ASI-04); ramificar por identidade de plataforma.
- Arquivos: dispatch do `AtlasOpenBrainMcpService`, store de cadência, `transportContract().quotas`.
- Aceite: N+1 chamadas do mesmo `client_id` em janela ⇒ `rate_softcapped` honesto; dois `client_id` independentes; contrato anuncia o limite que o teste prova.
- Risco: accounting bloquear read — fail-open por construção.

---

#### Área 15 + 17 — Modelo do Operador & Originação/Ambição (MAXN)

**Teto medido:** a captura do operador está ligada e default-ON, mas escreve para **8 tabelas `operator_*` inexistentes** no pgsql@5433 → **0 sinais** (fail-open silencioso). O perfil compilado (`OperatorProfilePolicyRule`) é **write-only para a Decisão** (0 callers de decisão). Nenhum aprendizado de preferência implícita (confidence nunca se move por comportamento). Na Originação: origina de verdade (1.173 alvos), mas a metade leverage-first está OFF, o yield real (`PathYieldEwma`) NÃO é consumido por `produce()`, e o path de pesquisa é catálogo tipado sem fetcher. Não duplica ASI-13 (modelo DE SI) nem ASI-08 (acende o combustível).

**MAXN-01 — Schema do operador materializado + captura viva provada (fim da dormência)** · E:S · onda F0 · deps: [ASI-05, SUB-01]
- Goal: a área 15 sai de código-morto — hoje o seam vivo (`AiGatewayService.php:862`, flag ON) escreve ZERO porque as 8 tabelas `operator_*` não existem e o `try/catch` (`:869-874`) engole o erro.
- Mecanismo: (a) rodar/reconciliar as migrations `2026_06_08_130000..150000_*operator*` no canônico (reversível por migration); (b) health-check plugin (`AtlasWatchdogCheckRegistry`) que FALHA se `chat_capture_enabled=true` E a tabela ausente (fail-open silencioso vira alarme); (c) o capture-fail incrementa contador exposto, nunca só log. Guard ASI-05 cobre JSONL novo. NÃO fazer: fila de aprovação; capturar classe secret (G0–G8 via porta única).
- Arquivos: migrations existentes (reconciliar), `AiGatewayService.php:862-874`, `OperatorLearningRuntimeCaptureService.php`, plugin watchdog.
- Aceite (reversibilidade = pre-state PROVÁVEL, não só "migration tem down"): **(a) snapshot SUB-01 VERIFICADO (restore testado) como PRÉ-CONDIÇÃO DURA antes de migrate contra o pgsql canônico @5433** — não nota de risco; **(b) invariante-teste de que phpunit/RefreshDatabase NUNCA resolve para o DB canônico @5433** (guard de connection — exatamente a classe do incidente db-wiper, `atlas_memory_entries` PERDIDO); `operator_learning_signals` existe; após ≥1 sessão real, `OperatorLearningSignal::count() > 0` com `source_type` da sessão (não phpunit — ASI-05); watchdog vermelho no fixture tabela-ausente+flag-ON; contador de capture-fail exposto. Zero sinais com flag ON + tabela presente = NÃO satisfeito.
- Risco: aprender lixo de sessão — G0–G8 + confidence floor + privacy por classe; migration lossy em DB vivo — snapshot SUB-01 verificado + guard @5433.

**MAXN-03 — Aprendizado de preferência implícita do digest (o comportamento julga)** · E:M · onda M2 · deps: [MAXN-01, MAXN-02, v1 FEE-12] · *(o crítico colocou N-03 em M2; como o sinal de outcome vem de MAXN-02 (M4), o scaffold de decay + reverse-handle pousa em M2 e o movimento de confiança por comportamento fecha quando MAXN-02 entrega o outcome)*
- Goal: a área 15 fecha o loop — hoje `OperatorProfileFeedbackEvent` só é gravado e contado; `confidence` nunca se move por comportamento.
- Mecanismo: job ex-post que junta feedback real (injeção → aceito/ignorado/override, do trace MAXN-01 e do outcome MAXN-02) e ajusta `confidence` do `OperatorProfileItem` por função DERIVADA (aceito↑, override/ignorado↓ com n mínimo) — **author≠judge: o comportamento é o juiz, a confiança nunca é auto-declarada**; item abaixo do floor é DEMOVIDO (não deletado) com reverse handle; o digest reporta a curva declarado-vs-comportamento (par de ASI-15). NÃO fazer: mover confiança sem n mínimo; auto-apply fora da lista segura (floor ASI-07).
- Arquivos: `OperatorProfileFeedbackService.php` (leitura), `OperatorProfileDigestService.php`, `app/Services/Ai/Compounding/AtlasLearningProposalApplier.php:244` (floor de privacy fail-closed `['public','normal']` reusável), job novo.
- Aceite: n<10 ⇒ `insufficient_sample`; property-test: confidence é função pura de (aceitos, overrides, ignorados); ciclo aplicar→reverter restaura confidence byte-comparável; após ≥20 injeções reais: ≥1 item com confidence movida por comportamento, com reverse handle e evidência citada.
- Risco: gaming por sempre injetar — a régua é override/ignore observado, não injeção; ajuste rápido — n mínimo + EWMA.

**MAXN-02 — Preferência do operador consumida pela Decisão (perguntar menos = saber mais)** · E:M · onda M4 · deps: [MAXN-01, ASI-13 (mesmo seam de receipt)]
- Goal: a Área 10 deixa de ignorar o que o cérebro já sabe do operador — a regra compilada é write-only (0 callers de decisão).
- Mecanismo: `AtlasDecideService` consome um bloco `operator_model` DERIVADO das policy rules ativas por flow (reusa `OperatorProfilePolicyCompiler`/`OperatorProfileRegistry`), como TERMO ponderado — **nunca override dos floors da Área 18**; cita o bloco no decision receipt (irmão do `self_model` do ASI-13, MESMO seam). `provider_safe`/`privacy_class` já vêm da regra; classe não-safe nunca vai a provider. `effect=do_not_do` vira restrição dura no envelope; `tool/workflow_preference` vira viés. NÃO fazer: 19ª área; regra setada por caller (deriva da fonte, lição SEV-1).
- Arquivos: `AtlasDecideService.php`, `OperatorProfilePolicyCompiler.php` (reuso), serviço novo no namespace Decide (par do ASI-13).
- Aceite (binding = fixtures comportamentais; o `rg …| grep Decide` é rebaixado a nota de sanidade não-load-bearing): decision receipt real carrega bloco `operator_model` das regras ativas; rota que viola `do_not_do` é excluída E `tool_preference` desempata entre rotas equivalentes num decision receipt real; classe `private` nunca aparece em receipt provider-external.
- Risco: preferência stale enviesando — MAXN-03 decai por comportamento; conflito com floor da 18 — floor SEMPRE vence (teste-invariante).

**MAXN-04 — Origem enviesada por yield REAL provado (fecha o loop que ASI-08 só acende)** · E:M · onda M4 · deps: [ASI-08, ASI-06, v1 OUTC-01]
- Goal: o originador para de escolher cego ao que rendeu — `PathYieldEwma.php:29` computa EWMA mas `produce()` nem o importa; ASI-08 resolve o tail vazio, ninguém resolve o consumo.
- Mecanismo: `AtlasLoopOriginationPipeline::produce()` pondera o candidato leverage-ranked pelo `PathYieldEwma` do path — yield ancorado em **proven_real** (OUTC-01/ADML `live_outcomes.jsonl`), **nunca accept/landing/refuse** (anti-Goodhart pétreo). Ativa `leverage_first_origination_enabled` (default FALSE, `config/atlas.php:3466`) sob shadow→ON. Path com yield desconhecido NÃO é despriorizado por vácuo (explora); yield provado baixo cede a alto. NÃO fazer: yield de eco de teste (ASI-05); refuse-rate como meta; tocar o pick com reflection vazio (degrade = ordem leverage atual).
- Arquivos: `AtlasLoopOriginationPipeline.php:81,155`, `AtlasBrainPathYieldEwma.php` (consumo), `config/atlas.php:3466`.
- Aceite: após ≥20 ciclos reais (ASI-08 populou o tail): dois paths com yield proven 0.8 vs 0.2 e leverage igual ⇒ produce() escolhe o de 0.8 (fixture determinística); yield ancorado em proven (n exposto), nunca em accept cru; flag OFF/tail vazio = pick byte-idêntico; `PathYieldEwma` ganha caller em produce (`rg` ≥ 1).
- Risco: yield virar proxy — ancorado em proven_real; convergência prematura — exploração de yield-desconhecido preservada.

**MAXN-05 — Path de pesquisa vivo: discover→read→ground com fetcher governado + anti-hype** · E:M · onda M4 · deps: [MAXN-04, MED-01]
- Goal: o path de pesquisa externo sai de catálogo tipado (`AtlasBrainResearchSourceRegistry.php:14` "no network, data only") para pipeline — o cérebro não VÊ a fronteira externa, só re-hunta o próprio repo.
- Mecanismo: fetcher limitado por tier reusando `Aaeos/Quarantine/AtlasSourceConnectorsAndCaptureService`: DISCOVER (trendshift → repos em tendência) → READ (github → tests/uso real, não stars) → GROUND (arxiv → paper) — cada resultado vira frontier row EXPLORATÓRIA com `trust_tier`/`anti_hype_note` derivados. **LEAD, nunca fronteira semeada** (faculdade de ambição pétrea: o cérebro AINDA origina o pick). Rate-limit + cadence; batelada assíncrona. NÃO fazer: auto-adotar trending como evidência; network no hot path; LLM que "julga" o trend.
- Arquivos: `AtlasBrainResearchSourceRegistry.php`, `AtlasBrainFrontierIngestCommand.php` (modo fetch gated), connector reuso, `storage/app/atlas/brain/frontier/`.
- Aceite (com egress-safety): `atlas:brain:frontier-ingest --fetch` (flag nova, default OFF) popula frontier row real com `trust_tier=exploratory` + anti-hype note; dedup; source read sem tests não vira lead "verified"; dry-run não toca rede; brain:next consome o lead como candidato exploratório; **fixture determinístico provando que a query OUTBOUND é genérica/provider-safe e contém ZERO conteúdo operator-private ou repo-derived** (classes sensitive/secret/cyber nunca contribuem para a query, enforçado pelo mesmo class gate — asserir sobre o payload outbound, não só "row voltou"). Rede off = degrade honesto.
- Risco: vazar o que o Atlas trabalha na query de saída — o aceite de egress-safety asserta o payload outbound; envenenamento por hype — anti-hype gate + exploratório-only + seed-gate.

**MAXN-06 — Ambição calibrada ao modelo do operador (interseção 15×17)** · E:M · onda M4 · deps: [MAXN-02, MAXN-04]
- Goal: originar o salto CERTO no momento certo, alinhado ao que o operador prioriza — sem virar fronteira semeada.
- Mecanismo: produce() ganha um PESO de alinhamento — **derivado de um effect EXISTENTE do enum `OperatorProfilePolicyRule` (workflow_preference/handoff_preference, já compilados e consumidos via MAXN-02)**, NÃO de "objetivo-de-pé/prioridade" (fonte-fantasma: o enum de effects é {context_hint, response_style, approval_gate, autonomy_limit, do_not_do, workflow_preference, tool_preference, handoff_preference}; `priority` é coluna derivada, não um effect de meta-de-pé). **Peso, nunca semente**: o cérebro origina o conjunto sozinho; o modelo do operador só REORDENA. A **exclusão por `do_not_do` é REMOVIDA daqui (dona = MAXN-02)** — este slice isola só o delta do alignment weight. Invariante anti-dormência: exclusão por regra do operador nunca zera a origem sem SURFACE explícito (contador/explain "origem estrangulada por regra do operador X").
- Arquivos: `AtlasLoopOriginationPipeline.php` (peso de alinhamento), serviço `operator_model` da MAXN-02 (leitura), selector de leverage (reuso).
- Aceite (isola o delta): **dois candidatos com leverage IGUAL E proven-yield IGUAL diferem SÓ na preferência do operador (workflow/handoff_preference) ⇒ produce() escolhe o alinhado**; com o effect-fonte ausente, o pick é byte-idêntico ao MAXN-04; teste pétreo: alvo continua ORIGINADO pelo cérebro (nunca copiado de um input do operador). *(O critério de exclusão `do_not_do` é de MAXN-02, não repetido aqui.)*
- Risco: operador virar semeador implícito — alinhamento é PESO sobre conjunto auto-originado; fonte-fantasma — effect existente nomeado; dormência disfarçada — surface de origem-estrangulada.

---

### Ordem global integrada (enxerto nas ondas M0–M5 + ASI F0–F3)

Dois trilhos correm em paralelo (ondas Max M0–M5 e programa ASI F0–F3); os 60 novos se enxertam no trilho que casa com sua natureza. Regra pétrea de intercalação: **todo medidor de área nova pousa cedo e congela antes dos produtores da própria área; todo restauro-de-freio pousa em F0 antes do medidor que o assume; qualquer coisa que alimentaria enforcement (MAXL-08→ASI-10, MAXK-05→auto-apply) fica report-only até o contrafactual/wiring-de-mutação-real existir.**

| Onda | Slices novos (MAXH..MAXN) | Papel |
|---|---|---|
| **F0** (parar de subtrair + religar o freio) | MAXI-01 · MAXK-07 · MAXL-01 · MAXL-02 · MAXN-01 | Religa os freios que os leitores acharam mortos/ausentes ANTES de construir em cima (selo event_hash ausente na tabela viva; floors editáveis in-place; captura para tabela-fantasma; imunidade cega). Medidor sobre selo decorativo ou tabela-fantasma não certifica nada. |
| **M1** (congelar réguas v2 antes dos produtores) | MAXH-01 · MAXI-02 · MAXI-03 · MAXJ-01 · MAXJ-05 · MAXK-01 · MAXK-04 · MAXL-06 | As réguas v2 congelam ANTES de seus produtores (guard R8 uniforme + scanner congelado; audit sob `.v2`; FN band com seed known-miss; `lesson_type_yield.v2` com floor n≥8 PRÓPRIO; regret grosso com n nomeado). |
| **M2** (eficiência estrutural + assinatura na mutação real) | MAXH-03 · MAXK-05 · MAXK-06 · MAXM-05 · MAXM-08 · MAXN-03 | Scanner observe 0-write; assinatura property-gated wirada na mutação REAL (`AtlasAutonomousLearningApplier:356`); conjunto-mutante independente; 64 tools + tool_search. |
| **M3** (encher denominadores vazios) | MAXH-02 · MAXI-04 · MAXM-01 | Backfill temporal (aceite = MAXH-05); classificador híbrido; corpus adversarial de vazamento (régua antes de MAXM-04). |
| **M4** (inteligência que consome as réguas congeladas) | MAXH-04..09 · MAXI-05..08 · MAXJ-02/03/04/06 · MAXK-02/03 · MAXL-03/04/05/07/08 · MAXM-02/03/04/06/07 · MAXN-02/04/05/06 | Atuadores, penalização temporal, decay, síntese; imunidade que aprende + auto-veneno + linhagem HMAC; atribuição causal no candidate; explore/exploit sob cobertura↑; contrafactual A/B (refs report-only NÃO alimentando ASI-10); redação por-construção; preferência do operador na Decisão + yield + fetcher. |
| **F2** (eixo reflexivo) | MAXK-08 | Auto-aperto EFÊMERO do envelope roteado pelo ledger MAXK-07 — nunca persiste a const. |
| **M5** (certificação + ADV externa) | MAXH-10 · MAXI-09 · MAXJ-07 · MAXJ-08 · MAXK-09 · MAXL-09 · MAXL-10 | Watchdog temporal; red-team imune (seed mínimo já em M1); composição + juiz frontier; certificação da mutação real; cross-check ACQCG; selo Marco Zero v2 por-área. |
| **F3** (substrato 10–100×) | **nenhum** | Os 11 novos endurecem/enchem/medem o substrato existente — não são substrato. F3 continua trilho ASI-nativo (honesto: não inventar F3 aqui). |

*(Onde o crítico foi silencioso — MAXJ-06/07/08 e MAXL-10 — a onda foi derivada dos deps declarados: J-06→M4, J-07/08→M5, L-10→M5. MAXN-03: o crítico posicionou em M2; como seu sinal de outcome vem de MAXN-02 (M4), o scaffold de decay pousa em M2 e o movimento de confiança por comportamento fecha em M4.)*

---

### Top-10 de maior alavancagem

1. **MAXL-01 (L, F0)** — selo `event_hash` restaurado E de fato verificado (recompute + hash_equals, não format-only); a hash-chain está AUSENTE na tabela viva e toda prova downstream repousa nele.
2. **MAXK-07 (K, F0)** — floors de governança são `private const` editáveis in-place; sem o registry de emenda + gate de completude (consts removidas), o freio é decorativo e MAXK-08 escreveria a própria regra.
3. **MAXI-01 (I, F0)** — imunidade cega (G3/G4 placeholder); montar os sinais na porta única religa o freio imune (referencia ASI-02, não duplica a porta).
4. **MAXN-01 (N, F0)** — o modelo do operador captura para tabelas INEXISTENTES; materializar as 8 `operator_*` destrava a área inteira, sob gate duro SUB-01 + invariante @5433 (classe do incidente db-wiper).
5. **MAXK-05 (K, M2)** — transforma o calculador de flags forjável/advisory em contenção REAL, wirando o verdict property-gated na única mutação auto-apply viva (`AtlasAutonomousLearningApplier:356`).
6. **MAXH-01 (H, M1)** — espinha de medição da verdade temporal (0/77 hoje); sem `truth_density_v2`/`temporal_provenance_coverage` honestos e congelados (guard R8 uniforme), toda a área H vira Goodhart.
7. **MAXH-05 (H, M4)** — primeira capacidade REAL que faz a proveniência temporal importar (stale ranqueia abaixo de fresh no recall); é o aceite verdadeiro de MAXH-02, sob piso composto recuperável.
8. **MAXJ-02 (J, M4)** — fecha o silo de credit-assignment fora da memória: o distiller carimba atribuição causal no candidate (reuso puro do causal attributor), removida a moldura falsa falseLearningGate/R3.
9. **MAXL-07 (L, M4)** — o contrafactual A/B no golden é o ÚNICO lastro honesto para contexto→outcome; é o gate que impede MAXL-08 e o enforce do ASI-10 de virarem número correlacional.
10. **MAXK-01 (K, M1)** — a régua de regret que MAXK-02 (explore/exploit) precisa como yardstick; versão honesta a escopo grosso com n nomeado e contrafactual que consegue pontuar exploração.

---

### Colisões bloqueantes resolvidas

| Slice | Tipo | Como era | Resolução aplicada |
|---|---|---|---|
| **MAXJ-05** | medidor v1 congelado tocado | bump 2→8 em `config/atlas.php:1137` (chave `atlas.ai.loop.learning_recall_use_lift.min_cases_per_arm`, lida em `AtlasLearningRecallUseLiftService.php:44`) mudava a SAÍDA do agregado v1 FEE-13/RecallUseLift → viola MED-01/dual-read | floor n≥8 **SÓ na série v2 nova** (`max(8,…)` em código); chave v1 INTOCADA; nome alinhado `.v2`; correção do v1 seria re-corte v1→v1.1 gatilhado pelo operador |
| **MAXI-02** | contrato v1 congelado tocado | mutava as constantes + `audit_hash` do payload `atlas.capture.cognitive_immune_audit.v1` (`CaptureService:251`) in-place | verdict shadow real sob **`.v2`**; payload `.v1` byte-estável; aceite "audit_hash muda" vale sobre o campo v2; dedup só de `:212` (`:307` já delega) |
| **MAXH-02** | medidor pinado tautologicamente | preenchia `temporal_provenance_coverage` a ~100% por type-map cego → near-matava o watchdog MAXH-10 | não creditar coverage-rise como aceite (sobe por construção); coverage conta só valores NÃO-default; aceite REAL = MAXH-05 (stale < fresh); MAXH-10 ganha check saudável |
| **MAXK-05/06/09** | seam disputado advisory vs mutação | endureciam/certificavam um calculador de flags ADVISORY (ninguém enforce `missingSignatures`); suíte 100% verde sem contenção real | wirar o verdict property-gated na mutação auto-apply REAL (`AtlasAutonomousLearningApplier:356`); asserir "assinatura forjada bloqueia mudança de estado real"; MAXK-09 nomeia a mutação concreta |
| **MAXJ-02** | premissa falsa / pipeline errado | afirmava que nenhum produtor seta `attribution_reviewed` (FALSO) e ligava o valor ao `falseLearningGate` (lê `AtlasAemorOutcome`; o distiller produz `AiLearningCandidate` em Compounding — INERTE) | apagada toda cláusula falseLearningGate/R3; aceite SÓ sobre `candidate.caused_by` |
| **MAXL-01** | selo format-only | `eventIntegrityValid()` (`:227-243`) só checa formato hex do `event_hash` (regex OU null=válido) — satisfazível por qualquer 64-hex; selo restaurado decorativo | recompute determinístico (`occurred_at` UTC ms canônico) + `hash_equals` numa amostra das 489; alvos do guard corrigidos p/ `latestForScope():201` + `engineeringOutcomeEvent():216` (`eventsForScope()` já guardado) |
| **MAXM-05** | invariante circular | "read-tool nunca escreve" derivava da MESMA lista hand-mantida da annotation `side_effect=write` → tautológico, cego a drift | derivar o conjunto-mutante de fonte INDEPENDENTE (inspecionar o corpo do handler por `->save/->create/AtlasOpenBrainWriteBackService/DB::` mutativo); contagem corrigida 66→**64 tools** |
| **MAXI-01** | seam disputado (localização da porta) | fixava o arquivo em `AtlasMemoryRegistryService` (rg=0 refs imunes hoje); a porta viva é `AtlasOpenBrainWriteBackService:408` | frasear "onde ASI-02 pousa a porta única" (writeback:408 hoje; RegistryService se ASI-02 mover), não fixar arquivo |
| **MAXN-06** | duplicação real + fonte-fantasma | o aceite "candidato que viola do_not_do é excluído" duplicava MAXN-02; o alignment weight vinha de "objetivo-de-pé/prioridade" (effect inexistente no enum) | N-02 é dono da exclusão do_not_do; N-06 isola só o delta do alignment weight (dois candidatos leverage+yield iguais diferindo só na preferência), fonte = effect EXISTENTE (workflow/handoff_preference) |
| **MAXK-09** | category mismatch | reivindicava certificar promoção da escada de autonomia, mas o WDG-01 land-path testa lógica de gate (categoria diferente) | nomear a mutação concreta gateada (`:356`, MAXK-05), não a lógica de gate genérica |
| **MAXN-01** | reversibilidade fraca (classe db-wiper) | "reversível por migration" não provava o pre-state se o down for lossy ou um teste dropar a tabela @5433 | aceite exige snapshot SUB-01 VERIFICADO (restore testado) como pré-condição dura + invariante-teste RefreshDatabase-nunca-@5433 |
| **MAXL-08** | correlação alimentando enforce | "o contexto CAUSOU o acerto" medido por delivered∩used (co-ocorrência) e "alimenta o enforce do ASI-10 depois" | rotular `correlational_cooccurrence`, remover "causou" do goal, report-only, PROIBIDO alimentar ASI-10 até MAXL-07 lastrear; denominador cru + caso `measured_share=0 ⇒ unmeasurable` |


---

## (x) RAG no auge absoluto — slices RAGX-01..11 (o ALÉM do Max)

> Fronteira SOTA 2024-2026 avaliada para este stack local-first. Doc canônico completo (pipeline unificado + vereditos + teto honesto): `atlas-acos-rag-pipeline-and-frontier.md`. Regra: cada slice que mede qualidade versiona medidor v2 próprio + golden v2 congelado por juiz externo; motor frontier sempre gerador/advisory sob author≠judge, default-OFF, nunca na espinha.


> Onda: `M6 (fronteira pós-Max)` salvo indicado; cada slice é **gated pela dep Max** correspondente ter aterrissado. Todo slice que mede qualidade **versiona série/medidor v2 próprio** e usa o **golden v2 (MAXG-04/MAXB-02) congelado por juiz externo** como régua — jamais edita golden/série congelados. Motor frontier/local só como **gerador/advisory** (author≠judge); `default-OFF → shadow → live` com gatilho de rollback pré-declarado (ROL-01). **Leis ELEV aplicadas a TODO RAGX:** toda "margem ≥ mínimo declarado" (RAGX-05, RAGX-11, …) é número carimbado no ledger no FREEZE do medidor, antes da implementação — nunca declarado ex-post (ELEV-03); todo congelamento grava `{judge_engine_id, author_engine_id}` com `judge != author` assertado (ELEV-18); promoção segue o protocolo único ELEV-26.

---

**RAGX-01 — Late chunking com jina-v3 (chunk contextual sem custo de LLM)** · E:M · onda M3/M6 · deps: [MAXA-04 (jina-v3 8k ctx promovido), MAXA-05 (chunking persistido), MAXA-01 (daemon)]
- **Goal:** cada chunk carrega o contexto do documento inteiro no seu vetor, provider-free, matando a diluição de chunk-isolado sem o custo por-chunk do Contextual Retrieval.
- **Mecanismo:** no runtime `semantic_rag/embeddings.py`, caminho novo `embed_document_late_chunked(text, spans)`: embeda o doc inteiro em 1 forward (jina-v3, 8k ctx, token embeddings), aplica **mean-pool por span de chunk** sobre os token-embeddings contextualizados, L2-norm por chunk. Persistência = mesma tabela `asef_chunks` (halfvec+HNSW) do MAXA-05, `embedding_model='jina-v3:late-chunk'` (provenance MAXA-03). Fallback = embed por-chunk isolado do MAXA-05 (degrade honesto byte-idêntico com flag OFF). Docs > 8k tokens: janela deslizante com overlap, pool por span dentro da janela dona.
- **Arquivos prováveis:** `runtimes/python/semantic_rag/atlas_semantic_rag/embeddings.py`, `app/Services/Ai/Context/AtlasSemanticEmbeddingFoundationService.php:46-65`, `app/Services/Semantic/EmbeddingService.php`, migration halfvec (herda MAXA-05).
- **Aceite executável:** R8 estendido com **≥15 queries de alvo mid-doc** (>1600 chars da abertura): `precision@5(late-chunk) ≥ precision@5(chunk-isolado)` no MESMO corpus-hash, dual-read registrado (MED-01); teste determinístico pool-por-span (mesmo input ⇒ mesmo vetor); flag OFF ⇒ pipeline byte-idêntico ao MAXA-05. Denominador mínimo: 15 queries mid-doc rotuladas; alvo: Δprecision@5 ≥ +0,05 no corpus de código, ≥ 0 (não-regressão) no de 77 memories.
- **Risco:** pooling por span mal-alinhado a tokenização ⇒ asserção de cobertura de span (todo char do chunk ∈ ≥1 token pooled); janela deslizante duplicar contexto ⇒ span dono único por chunk.

---

**RAGX-02 — Contextual Retrieval: blurb gerado por LLM local (código/KB, author≠judge)** · E:M/L · onda M6 · deps: [RAGX-01 OU MAXA-05, MAXA-06 (código/KB), MAXA-08 (FTS contextual)]
- **Goal:** para o corpus onde a escala justifica (290k símbolos, 950 KB items), cada chunk ganha um blurb de 1-2 frases "como isto se encaixa no doc/módulo", prepended ANTES de embeddar e de indexar no FTS — a técnica da Anthropic, local e verificada.
- **Mecanismo:** job assíncrono fora do hot path: LLM **local** (Hermes/GLM via `ATLAS_BRAIN_PROVIDER`) recebe (doc/módulo resumido + chunk) → emite blurb ≤ 200 chars; texto embedado = `blurb + "\n\n" + chunk` (RAGX-01/MAXA-05) E o MESMO texto entra no tsvector do MAXA-08 (BM25 contextual). Author≠judge: o blurb é **metadado gerador**, o retrieval e o juiz (golden v2) são determinísticos; o blurb NUNCA vira memória nem é citado como fato. `embedding_model` estampa `+ctx` (MAXA-03). Privacy: sensitive/secret ⇒ só LLM local, nunca provider externo. Incremental por `source_hash` (re-blurba só o que mudou). `default-OFF → shadow (loga blurbs) → live`.
- **Arquivos prováveis:** `app/Services/Ai/Context/AtlasSemanticEmbeddingFoundationService.php`, adapter de blurb no padrão brain-writer (`config/atlas.php:32`), `runtimes/python/semantic_rag`.
- **Aceite executável:** A/B no R8 de código/KB (**≥20 queries**): `precision@5(ctx-blurb) > precision@5(sem-blurb)` com custo de latência de indexação reportado, dual-read; caso negativo: em corpus de 77 memories o slice **NÃO** liga (declara ganho não-mensurável — anti-Goodhart); flag OFF ⇒ byte-idêntico. Alvo: Δprecision@5 ≥ +0,05 no código; denominador ≥ 20 queries julgadas.
- **Risco:** blurb alucinado envenena o índice ⇒ blurb é só reforço de contexto (nunca claim recuperável) + amostragem de revisão no shadow; custo de re-blurbar em massa ⇒ incremental por hash, batelada assíncrona.

---

**RAGX-03 — Corretivo CRAG-lite: grade de relevância por-item + knowledge-strip refinement (determinístico)** · E:M · onda M6 · deps: [MAXC-02 (bloco suficiência), MAXC-04 (hop-2), MAXA-05 (chunks p/ strips)]
- **Goal:** o pack para de entregar item irrelevante com cara de relevante — cada item recuperado é triado (keep/ambíguo/drop) e o ambíguo-longo é **refinado** na sua melhor passagem antes de gastar budget.
- **Mecanismo:** pós-recall, pré-`enforceTotalCeiling`: bandas determinísticas pelos scores REAIS já presentes (`_recall_score`, cosine, BM25 top) calibradas por quantil (reusa a distribuição do floor v2 MAXB-07) → `keep` (alto), `ambiguous` (médio), `drop` (< floor, já é o MAXB-07). Para `ambiguous` com corpo longo: **knowledge-strip** — split em passagens (chunker MAXA-05), re-score cada passagem contra a faceta (MAXC-01), substitui o corpo pela melhor passagem (mantém o ref canônico COM-01). Se ≥X% dos itens caem `ambiguous`/`drop` ⇒ dispara o hop-2 (MAXC-04, já budgetado). Tudo peek (`record_usage=false`) nas passadas de refinamento.
- **Arquivos prováveis:** `app/Services/Ai/AtlasOpenBrainContextPackService.php` (memorySection ~:2218, pós-recall), `app/Services/Ai/AtlasHybridMemoryRetrievalService.php`, chunker do MAXA-05.
- **Aceite executável:** phpunit: item ambíguo-longo ⇒ corpo substituído pela passagem de maior score, ref inalterado; **em ≥N=30 packs reais** (shadow): used-rate dos itens refinados (join COM-01/feedback measured) ≥ used-rate dos itens originais que substituíram — senão o refinamento é ruído e morre (critério de morte declarado); recall@5 golden v2 não regride; zero rows de usage nas passadas peek (asserção). Alvo: Δused-rate ≥ 0 e redução mensurável de itens `drop` entregues.
- **Risco:** strip cortar a evidência-chave no meio ⇒ overlap de passagem + manter top-1 por faceta intocado; grade calibrada demais ⇒ bandas lêem do floor v2 congelado, nunca régua nova ad-hoc.

---

**RAGX-04 — HyDE local (hypothetical document embedding), motor-opcional** · E:M · onda M6 · deps: [MAXC-01 (facetas, medido), MAXC-07 (irmão), MAXB-03 (RRF), golden v2 congelado]
- **Goal:** para query vaga/curta cujo vocab diverge do corpus, gerar um doc hipotético local, embeddá-lo e fundir seu vetor às facetas — SÓ se deixar ganho mensurável na mesa após MAXC-01/07.
- **Mecanismo:** adapter atrás de `atlas.aobg.hyde_local` `default-OFF`: LLM local (Hermes/GLM) gera 1 resposta hipotética curta à query → embeda (daemon MAXA-01) → vira uma sub-query adicional fundida por RRF (MAXB-03) junto com as facetas do MAXC-01. Author≠judge (gerador de expansão; retrieval/golden determinísticos). Falha/ausência do LLM ⇒ facetas puras (degrade honesto). Privacy: local-only.
- **Arquivos prováveis:** adapter novo no namespace do packFor, `app/Services/Ai/AtlasOpenBrainContextPackService.php`, `config/atlas.php` (flag).
- **Aceite executável:** A/B no golden v2 CONGELADO (hash inalterado): só promove se `recall_at_5(hyde+facetas) > recall_at_5(facetas)` com latência reportada; **candidato a corte declarado desde já** (se MAXC-01/07 já fecham o gap, RAGX-04 não liga); OFF ⇒ byte-idêntico. Denominador: os 25+ casos do golden v2; nunca editar o set p/ passar.
- **Risco:** doc hipotético alucinado puxa ruído ⇒ é só vetor de busca (nunca entregue/citado como conteúdo); não-determinismo suja medidor ⇒ roda em peek, medição por seed fixo do modelo local.

---

**RAGX-05 — Cascata de rerank completa com tier LLM advisory (bi→cross→LLM), cost-bounded** · E:M · onda M6 · deps: [MAXB-06 (cross-encoder local), MAXA-09 (late-int), golden v2]
- **Goal:** formalizar a cascata escalonada e adicionar o topo LLM como scorer advisory só onde o cross-encoder empata — os "últimos pontos" de precision, sem estourar latência.
- **Mecanismo:** orquestrador de rerank no seam L3-6: estágio 1 bi-encoder (existente) → top-20; estágio 2 cross-encoder local (MAXB-06) → top-5; estágio 3 **opcional** `atlas.aobg.llm_rerank` `default-OFF`: LLM (local preferido; frontier advisory) pontua **só os top-5 empatados** (Δcross-score < ε) → top-3. Author≠judge: o LLM dá score advisory, a ORDEM final e o golden v2 são a autoridade; claim de score nunca vira memória. Cost cap: ≤ 5 itens/query, batelada, timeout duro, fallback = ordem do cross-encoder.
- **Arquivos prováveis:** `app/Services/Ai/AtlasOpenBrainContextPackService.php` (`semanticallyReorderMemory` ~:2291), `config/atlas.php`.
- **Aceite executável:** A/B golden v2: `precision@3(cascata c/ LLM) > precision@3(cascata s/ LLM)` por margem ≥ mínimo declarado, com p95 de latência reportado; se não bater a margem, tier LLM fica OFF permanente (resultado-negativo-registrado, padrão MAXB-06); OFF ⇒ byte-idêntico. Denominador: golden v2 congelado; alvo: Δprecision@3 ≥ +0,03 OU corte.
- **Risco:** LLM na cadeia quente estoura latência interativa ⇒ só top-5 empatados + timeout + fallback; provider externo em conteúdo sensível ⇒ local-only para essas classes.

---

**RAGX-06 — Cache semântico de recall (similaridade de query, usage-safe, shadow-first)** · E:S · onda M2/M6 · deps: [MAXB-09 (cache exato), MAXA-02 (memo), MAXE-06 (working set)]
- **Goal:** camada de cache acima do exato: query cosine-similar a uma já servida retorna o resultado sem recomputar recall+grafo+code — sem mentir os sensores de usage.
- **Mecanismo:** sobre o cache exato do MAXB-09, um índice pequeno `{query_embedding → top-K refs, ts}` (TTL curto, invalidação por write de memória/`corpus_fingerprint` do MAXE-05). Hit = cosine(query, cached) > τ **alto** (conservador). Regra pétrea RAG-01/MEM-03: hit **não re-grava usage** nem `recalled_pre_filter`; marca `cached=semantic`, grava só delivery real. `shadow-first`: por N dias loga would-be-hits e verifica que o recall fresco devolveria o **mesmo top-K** (mede taxa de falso-hit ANTES de servir do cache).
- **Arquivos prováveis:** `app/Services/Ai/AtlasHybridMemoryRetrievalService.php`, `app/Services/Semantic/EmbeddingService.php`, `config/atlas.php`.
- **Aceite executável:** shadow ≥ **200 queries**: taxa de falso-hit (top-K divergente) < limiar declarado (ex. 5%) ANTES de qualquer flip; pós-flip: hit-rate incremental sobre o exato reportado, e teste provando que hit não emite row de usage nem infla o denominador do sensor 45d (MEM-03). Alvo: falso-hit < 5% em 200 queries; ganho = hit-rate > 0 sem regressão de recall.
- **Risco:** τ baixo devolve contexto errado ⇒ τ alto + shadow-gate obrigatório; cache stale pós-write ⇒ invalidação por corpus_fingerprint (autoridade única MAXE-05).

---

**RAGX-07 — Profundidade adaptativa por dificuldade (K derivado do sinal de suficiência)** · E:S · onda M6 · deps: [MAXC-02 (bloco suficiência), MAXC-03 (perfil)]
- **Goal:** K/budget da recuperação encolhe quando o sinal é forte (economia de tokens) e cresce quando é fraco (mais recall) — decisão por-query, não perfil estático.
- **Mecanismo:** o bloco de suficiência (MAXC-02) já computa cobertura de facetas + força de sinal cru (top cosine/BM25) determinísticos; RAGX-07 mapeia esse sinal → multiplicador de K **antes** da entrega, componível por baixo da política medida (COM-05) como o prior do MAXC-03 (feedback measured corrige por cima; `insufficient_signal` ⇒ neutro; piso top-1 por seção inviolável). Sem ML, sem escalar fabricável — função pura do sinal já medido.
- **Arquivos prováveis:** `app/Services/Ai/AtlasOpenBrainContextPackService.php` (packFor, pós-MAXC-02), `config/atlas.php`.
- **Aceite executável:** phpunit: query com sinal alto ⇒ K menor entregue e recall@5 golden v2 NÃO regride; query com sinal baixo ⇒ K maior; token-economy (chars/tokens médios entregues) cai em queries fortes na série COM-07 (campo aditivo, formula_version própria) SEM queda de used-rate. Denominador: golden v2 + janela COM-07; alvo: −X% tokens em queries fortes, recall-neutro.
- **Risco:** encolher K e perder recall ⇒ o golden v2 é a trava (recall-neutro é aceite duro); virar proxy de "menos chars" ⇒ o alvo é utility estável, nunca chars por si (regra COM-11).

---

**RAGX-08 — Citation-grounding determinístico (refs da resposta vs refs entregues) `[MEDIDOR]`** · E:S · onda M6 · deps: [MAXE-01 (refs citáveis no markdown), COM-06 (cited), COM-01 (ledger)]
- **Goal:** fechar o loop que o Max deixa aberto — não só "o recall foi bom" mas "a resposta USOU o que foi entregue" — barato, local, não-gameável, sem LLM.
- **Mecanismo:** após MAXE-01 imprimir `ref=memory:… / graph:… / sym:…` por item, um medidor determinístico junta (delivered refs do ledger COM-01) × (refs citados na resposta, via `AtlasCanonicalContextRef::isMentionedInText`): `citation_grounding_rate = refs_citados_e_entregues / refs_citados`; `unsupported_citation` = refs citados que NÃO foram entregues (alucinação de fonte). Série v2 própria, `record_usage=false`, provider-safe (só refs/hashes). Plugin WDG-01.
- **Arquivos prováveis:** `app/Services/Ai/Context/AtlasDeliveredPackLedger.php`, `app/Services/Ai/Context/AtlasCanonicalContextRef.php:221`, `app/Services/Ai/Context/AtlasRetrievalFeedbackLoopService.php`, plugin em `app/Services/Ai/Cognition/Watchdog/`.
- **Aceite executável:** comando `atlas:context:citation-grounding --json` reporta `{grounding_rate, citation_coverage, unsupported_citation_count, measured_count, total}` com denominador exposto (**ELEV-07: `citation_coverage` = share de respostas measured com ≥1 citação — grounding_rate sozinho dá 1.0 para quem cita um único ref óbvio; as duas juntas, nunca fundidas num escalar**); janela sem massa ⇒ `insufficient_signal` (nunca 100); **≥30 eventos ARFL measured** ⇒ rate reportada; resposta citando ref não-entregue ⇒ `unsupported_citation` incrementa (caso negativo vivo). NÃO toca `context_sufficiency` (o "92") nem `memory:quality`. Alvo objetivo: grounding_rate ≥ 0,90 em janela real (parte do teto — §3).
- **Risco:** caminho de hook não vê a resposta ⇒ mede onde vê (MCP/Stop), reporta cobertura do denominador honestamente; citação por inferência ⇒ MAXE-01 é pré-req duro (sem ref impresso, rate honesta = "não medível").

---

**RAGX-09 — Faithfulness por LLM-judge (amostrado, advisory, author≠judge)** · E:M · onda M6 · deps: [RAGX-08, MAXG-04 (golden v2 + protocolo vN), WDG-01, OUTC-01]
- **Goal:** medir se cada claim da resposta se ancora no contexto recuperado — a métrica RAGAS-style — sem virar juiz de si nem gate que trava o operador.
- **Mecanismo:** amostrador agendado (NÃO por-turno): amostra N respostas reais com contexto entregue (ledger COM-01 + transcript onde disponível), um **LLM-judge distinto do modelo que respondeu** (author≠judge pétreo; local p/ sensível) decompõe a resposta em claims e marca cada um `supported/unsupported/partial` contra o contexto → `faithfulness = supported/total_claims`. **Advisory**: série v2 própria congelada por protocolo vN (MAXG-04), alimenta o digest e o WDG-01; NUNCA bloqueia entrega nem edita a `groundedness` da AREBA (fórmula congelada). Custo bounded: amostra diária, batelada assíncrona.
- **Arquivos prováveis:** comando novo `atlas:context:faithfulness-sample`, plugin WDG-01, adapter LLM-judge no padrão brain-writer, série JSONL versionada em `storage/atlas/`.
- **Aceite executável:** `--json` sobre **≥20 respostas amostradas** reporta `{faithfulness, unsupported_claims, n, judge_model, author_model}` com `judge_model != author_model` provado (asserção); n < mínimo ⇒ `insufficient_sample` (nunca "fiel" por vácuo); série v2 nunca funde com AREBA/golden congelados; provider-safe (claims por ref/hash, sem raw). Alvo: faithfulness medida por janela; anti-Goodhart: LLM-judge não pontua o próprio autor.
- **Risco:** juiz LLM alucinar veredito ⇒ author≠judge + amostra revisável + é advisory (nunca gate); provider externo em conteúdo sensível ⇒ juiz local obrigatório p/ sensitive/secret.

---

**RAGX-10 — RAPTOR-lite: nós de sumário de cluster indexados (thematic + global mode)** · E:L · onda M6 (fase-2, pós-corpus) · deps: [MAXA-05/06 (chunks), MAXD-05 (comunidades Louvain), MAXF-09 (sumário L2 verificado)]
- **Goal:** unidade de recall temática entre chunk e corpus — perguntas "sobre o quê é X / quais os temas de Y" passam a ter alvo recuperável, incluindo o modo global do GraphRAG, sem sumário LLM na espinha.
- **Mecanismo:** clusteriza os chunk-embeddings (RAGX-01/MAXA-05) por comunidade (reusa Louvain do MAXD-05); por cluster gera um **nó de sumário**: L1 **determinístico** primeiro (concatena headings/summaries existentes + top-labels do card MAXD-05); L2 **local verificado** opcional (reusa `SummaryFidelityCoverageScorer` do MAXF-09 — só aceita coverage==1.0 E retention ≥ L1, canal separado, nunca canônico). Os vetores dos nós de sumário entram no MESMO índice (collapsed-tree: recall busca chunks E sumários juntos). **Global mode** (`--scope=global`): rankeia comunidades por tamanho/centralidade (PPR MAXD-04) e devolve o conjunto de cards determinísticos — zero map-reduce LLM.
- **Arquivos prováveis:** `app/Services/Ai/Context/AtlasSemanticEmbeddingFoundationService.php`, `app/Services/Ai/Reality/AtlasRealityGraphQueryService.php`, runtime `communities` do MAXD-05, scorer MAXF-09.
- **Aceite executável:** R8 estendido com **≥10 queries temáticas/globais** (alvo = tema, não frase literal): `recall(collapsed-tree) > recall(chunk-only)` nessas queries, dual-read; L2 rejeitada conta como rejeição (não some); `--scope=global` reprodutível (mesmo input ⇒ mesmo conjunto de cards); flag OFF ⇒ índice byte-idêntico. **Honesto: só liga quando MAXA-06 (código/KB) aterrissar** — declarado que em ~1000 docs o ganho é marginal. Alvo: Δrecall temático ≥ +0,05 no corpus estendido.
- **Risco:** cluster "de tudo" dominado por docs ⇒ peso de aresta do MAXD-04 + concentração reportada (padrão MEM-03); sumário L2 alucinado ⇒ coverage==1.0 gate + nunca canônico.

---

**RAGX-11 — Learned sparse (SPLADE/BM42) como gate medido do braço lexical** · E:M · onda M6 · deps: [MAXA-08 (FTS+RRF é a linha de base), golden v2/R8 rotulado]
- **Goal:** materializar a decisão que MAXA-08 defere ("SPLADE só se FTS não bastar") como um A/B medido — recuperação esparsa aprendida vs FTS, decisão por dado, não por intuição.
- **Mecanismo:** `SparseTextEmbedding` (SPLADE ou BM42, já no venv) gera vetores esparsos persistidos em `sparsevec` (pgvector 0.8.2) nas mesmas rows; braço esparso entra na fusão RRF (MAXB-03) em paralelo ao FTS do MAXA-08, `default-OFF → shadow`. BM42 (atenção do próprio embedder, desenhado p/ docs curtos) é o candidato primário p/ os memory entries curtos. Provenance MAXA-03 estampa o modelo esparso.
- **Arquivos prováveis:** `runtimes/python/semantic_rag/atlas_semantic_rag/embeddings.py`, `app/Services/Ai/AtlasHybridMemoryRetrievalService.php`, migration `sparsevec`, `config/atlas.php`.
- **Aceite executável:** subconjunto rotulado de **≥15 queries de termo-exato/raro** do R8: `precision@5(SPLADE/BM42 + RRF) vs precision@5(FTS + RRF)` no mesmo corpus-hash, dual-read; só promove a live se Δ ≥ mínimo declarado; se FTS empata/ganha, RAGX-11 fica OFF (resultado-negativo-registrado — a decisão que MAXA-08 deferiu vira dado). Alvo: Δprecision@5 ≥ +0,05 OU descarte documentado.
- **Risco:** SPLADE infla índice sem ganho no corpus pequeno ⇒ gate de promoção por margem; termo-vocab explode ⇒ cap + halfvec/sparsevec eficiente.

---

**Ordem sugerida (por dep + ganho/custo):** RAGX-08 (barato, destrava verificação) → RAGX-01 (provider-free, escala) → RAGX-07 (economia) → RAGX-06 (cache) → RAGX-03 (corretivo) → RAGX-11 (gate SPLADE) → RAGX-05 (cascata) → RAGX-02 (blurb, pós-corpus código) → RAGX-10 (RAPTOR, pós-corpus) → RAGX-09 (faithfulness LLM) → RAGX-04 (HyDE, condicional/corte).

---

## (xi) ELEV — Elevações da revisão adversarial externa dupla (12 slices novos + índice das elevações in-place)

> Origem: duas revisões adversariais EXTERNAS e independentes do plano (12/07/2026 — revisor Fable: régua/Goodhart/sequência; revisor Codex: operação/processo/rollback). Os top-5 de alavancagem dos dois revisores convergiram em 4 itens (ASI-02, MAXL-01/02, MAXA-01, MAXE-01) — sinal forte de consenso. **Nada foi cortado do plano**: todo achado virou elevação de régua, de escopo, de sequência, ou slice novo. As elevações in-place JÁ FORAM APLICADAS no corpo do plano (índice abaixo); esta seção carrega as leis transversais (também replicadas em ii.6) e os 12 slices novos. Contrato pétreo herdado por inteiro.

### Leis transversais ELEV (replicadas em ii.6 — vinculantes para TODOS os slices)

- **ELEV-03 — Limiar carimbado no freeze:** todo limiar numérico de aceite ("≥X%", "margem ≥ mínimo declarado") é gravado no ledger no FREEZE do medidor, antes da 1ª linha do produtor. Limiar definido depois de ver o dado é aceite decorativo.
- **ELEV-18 — Juiz externo mecânico:** todo congelamento de régua/golden grava `{judge_engine_id, author_engine_id}` com `judge != author` assertado por teste. Num sistema de 1 operador, "juiz externo" sem identidade mecânica é rótulo.
- **ELEV-20 — Produtor+consumidor vivos:** nenhum slice fecha sem ≥1 produtor E ≥1 consumidor com tráfego real provado no ledger. É a vacina contra o plano produzir a próxima geração de "construído-mas-não-ligado" — o modo de falha sistêmico que este plano existe para curar não pode ser reproduzido por ele.
- **ELEV-26 — Protocolo único de promoção:** ver slice ELEV-26 abaixo; nenhuma escada default-OFF→shadow→live ad-hoc.
- **ELEV-28 — Gate de continuação por família:** se o A/B do slice-raiz de uma família dá resultado negativo (ex.: MAXC-01 sem ganho), a subárvore dependente (MAXC-04/05/07, RAGX-04/07…) entra em SUSPENSÃO até a evidência virar — nunca é deletada (completude preservada), nunca prossegue no vácuo (esforço preservado). Estado `suspended_pending_evidence` registrado no ledger de gaps.

### Slices novos

**ELEV-02 — ASI-METRIC: a fórmula de M congelada antes de F1** `[MEDIDOR][pétreo]` · E:S · onda M1 (bloqueia flips de F1) · deps: [MED-01, COM-11, OUTC-01]
- Goal: a métrica-manchete do programa (M: ~1 → 2–4×) ganha numerador EXECUTÁVEL — "valor/turno" hoje não é definido em lugar nenhum do plano, e quem o definir depois de ver os dados escolhe o proxy que sobe.
- Mecanismo: comando read-only `atlas:acos:m-series --json` com fórmula CONGELADA e versionada: `valor = f(used_ratio measured × post_execution_utility [COM-11], green_run_pass_rate [OUTC-01], retrabalho_evitado_provado [reversões ASI-11 + give-backs])`, denominadores crus expostos por componente; M = valor/turno com ACOS ÷ mesmo motor em faixa peek (`record_usage=false`), separado interativo × delegado; `formula_version` própria; mudança de fórmula = série nova, nunca edição.
- Aceite: fórmula + hash no Evidence Ledger ANTES do primeiro flip de F1 (gate: ASI-06/07 não flipam sem o registro); comando reporta M por janela com componentes e denominadores; janela sem massa ⇒ `insufficient_signal` (nunca um M bonito por vácuo); phpunit não escreve na série (ASI-05).
- Risco: a fórmula virar alvo — componentes crus sempre expostos ao lado do composto; tuning contra o M é vetado pela mesma regra COM-11 (pack menor não infla ratio).

**ELEV-12 — verified_share: cobertura do moat de verificação** `[MEDIDOR]` · E:S · onda M1 · deps: [OUTC-01, v1 ENG-13/14/15]
- Goal: a tese central ("verificação é o ganho que cresce com o motor") ganha medidor de COBERTURA — sem saber que fração do output dos executores passa por enforce, o moat é asserção.
- Mecanismo: série `verified_share = execuções sob enforce ÷ execuções totais` por executor (Dev/Forge/Autônomos), join OUTC-01 × receipts de verificação; campo aditivo em série própria; plugin WDG-01.
- Aceite: comando reporta share por executor com {verified_count, total_count} crus; janela < N execuções ⇒ `insufficient_signal`; item 16 do critério de conclusão lê daqui (≥0,80 em janela ≥14d com ≥50 execuções reais).
- Risco: inflar o denominador excluindo execuções — "execução total" definida no freeze (todo run com receipt de outcome), não filtrável ex-post.

**ELEV-17 — Drill de restore recorrente (a reversibilidade ensaiada)** · E:S · onda F0/M1 · deps: [SUB-01]
- Goal: num sistema cuja ÚNICA salvaguarda é reversibilidade, restore não-ensaiado é reversibilidade teórica — e o wiper de 02/07 (102 tabelas, `atlas_memory_entries` PERDIDO) é o precedente existencial.
- Mecanismo: `atlas:substrate:restore-drill --json` mensal: restaura o snapshot SUB-01 mais recente (DB + JSONLs vivos críticos: evidence, live_outcomes, delivered-pack, séries) num ambiente descartável (DB alternativo/porta alternativa — NUNCA o @5433 canônico), diffa contagens/hashes contra o manifesto do snapshot, emite receipt. Check WDG falha se o último drill bem-sucedido > 45d.
- Aceite: drill executado contra snapshot real com receipt `restored_ok` + contagens batendo; caso negativo: snapshot corrompido de fixture ⇒ `restore_failed` com diff nomeado (o drill DETECTA, não só roda); invariante: o drill nunca toca o DB canônico (guard de connection, classe do incidente db-wiper — mesmo padrão MAXN-01).
- Risco: drill caro — mensal, fora de horário de sessão; falso conforto de snapshot recente — o check mede o último drill VERDE, não o último snapshot.

**ELEV-19 — Manifest de integridade dos modelos locais** · E:S · onda M2 · deps: [MAXA-01]
- Goal: a espinha local depende de artefatos ONNX baixados (MiniLM hoje; jina-v3 2,3GB, rerankers, SPLADE amanhã) sem pin de integridade — supply-chain do próprio cérebro.
- Mecanismo: manifest versionado no repo `{model_id, sha256, dim, pooling, license, source_url}` por artefato; o daemon MAXA-01 verifica o hash no boot; mismatch ⇒ degrade honesto (modelo não carrega, spawn-fallback/lexical) + alerta WDG, nunca carga silenciosa.
- Aceite: boot com artefato íntegro ⇒ `model_verified=true` no receipt do daemon; artefato adulterado em fixture ⇒ recusa + alerta; manifest cobre 100% dos modelos referenciados por slices MAXA/MAXB/RAGX.
- Risco: atualização legítima de modelo travada — bump do manifest é commit normal (o pin é contra mudança SILENCIOSA, não contra mudança).

**ELEV-20s — Watchdog genérico de série-morta (o slice da lei ELEV-20)** `[MEDIDOR]` · E:S · onda M1 · deps: [WDG-01]
- Goal: o plano cria ~30 medidores/séries/ledgers novos e só MAXH-10 vigia a própria cadência — medidor que morre em silêncio é o próximo lote de órgãos-mortos.
- Mecanismo: registry de séries vivas `{série, path/tabela, ttl_dias declarado no freeze}`; UM plugin WDG que varre o registry e alerta quando `idade(último_append) > ttl`; todo slice `[MEDIDOR]` novo REGISTRA sua série no landing (teste arquitetural: série nova sem registro reprova).
- Aceite: plugin lista todas as séries registradas com idade; série congelada com ttl vencido ⇒ alerta no ledger de gaps; fixture de série parada ⇒ vermelho; as séries dos 65+ slices `[MEDIDOR]` existentes entram no registry num backfill único.
- Risco: TTL frouxo esconde morte — ttl declarado no freeze da série (lei ELEV-03), não ajustável ex-post sem emenda.

**ELEV-21 — Aceleração de corpus qualificado (o limitador nº1 ganha dono)** · E:M · onda M3 · deps: [ASI-02 (a porta), v1 CORP-01/MEM-05, MAXI-01]
- Goal: o próprio plano confessa (Lei 2, teto MAXB-1) que NADA de ranking move sem corpus — mas deixa o crescimento inteiro delegado ao CORP-01 do v1. 77 entries é a dependência crítica de ~40 slices; merece dono próprio no Max.
- Mecanismo: mineração GATED de candidatos dos estoques que já existem: 1.011 docs canônicos (decisões/gotchas/invariantes extraíveis deterministicamente por seção), sessões capturadas (via classifier MAXM-03), give-backs/outcomes (via distiller ASI-09) — TODOS entrando pela porta única ASI-02 (G0–G8 aplicam; nada de import em massa sem imunidade); meta declarada: **≥300 entries ativas qualificadas** como pré-condição de representatividade do golden v2 (registrada como dep de contexto, não como aceite de contagem — contagem crua nunca é meta, qualidade gated é).
- Aceite: pipeline produz candidatos com origem citada; taxa de aprovação da porta reportada por fonte (fonte com 100% de aprovação = suspeita de gate frouxo, vai ao ledger); corpus ativo cresce SÓ por admissão gated (0 writes diretos — invariante ASI-02); recall@5 do golden v2 medido antes/depois do crescimento (dual-read — o corpus é PARA isso).
- Risco: Goodhart de contagem — a meta ≥300 é pré-condição de outra medição, nunca aceite deste slice; envenenamento em massa — a porta é a defesa, e o throughput é capado por cadência.

**ELEV-22 — Claim de blackboard obrigatório entre executores** · E:S · onda M0 · deps: []
- Goal: a regra ii.4 (contenção com o executor v1) é disciplina, não mecanismo — e o Atlas JÁ tem blackboard/claim. Dois executores em `routes/console.php`/`settings.json` sem claim é corrida esperando acontecer.
- Mecanismo: editar arquivo da lista compartilhada (routes/console.php, settings.json, docs de obra, config/atlas.php) exige claim ativo via `atlas_claim_task`/CLI equivalente; o hook PreToolUse existente avisa quando o alvo tem claim de outro executor; lista de arquivos-quentes versionada.
- Aceite: edição com claim alheio ativo ⇒ aviso injetado no turno (bancada com 2 claims); claim liberado no commit; zero mudança para arquivos fora da lista.
- Risco: claim esquecido travando — TTL curto no claim + fail-open (aviso, não bloqueio duro — o humano decide).

**ELEV-24 — Runbook de operação local: rotação, disk-full, cold-start, queda de energia** · E:S · onda M2 · deps: [MAXG-01]
- Goal: continuidade operacional num MacBook real — o plano cria dezenas de JSONLs (immune_verdict, pattern-ledger, shadows, arena-runs, latency, séries v2) e só o latency ledger declara rotação; disk-full, reboot e power-loss no meio de um append não têm comportamento definido.
- Mecanismo: (a) política de rotação/retenção declarada POR ledger no registry do ELEV-20s (mesmo registry, campo extra); (b) appends de cadeia (MAXL-02) e de séries usam write atômico (tmp+rename ou O_APPEND de linha única) — power-loss nunca deixa linha meia-escrita que quebre o parse; (c) check WDG de disco livre (< X GB ⇒ alerta; produtores de background pausam com contador, interativo nunca); (d) runbook curto de cold-start (ordem: Postgres → daemon embeddings → residente MCP → hooks) versionado em docs.
- Aceite: kill -9 no meio de um append de cadeia ⇒ próximo boot detecta e repara/rotula a última linha (fixture); disco cheio simulado ⇒ background pausa com contador, turno interativo completa; todo ledger novo tem política no registry (teste arquitetural).
- Risco: over-engineering de robustez — só os 4 casos nomeados (rotação, disk-full, cold-start, power-loss); nada de HA/replicação.

**ELEV-25 — Review-debt do operador: o gargalo humano medido** `[MEDIDOR]` · E:S · onda M1 · deps: [v1 FEE-12, ASI-07]
- Goal: o charter troca aprovação-antes por revisão-depois via digest — com auto-apply em minutos + consolidação + buckets de alto-risco, o digest vira fila infinita e o gargalo volta a ser humano, invisível. Revisão-depois que degrada para revisão-nunca é freio de papel. *(O melhor achado do revisor Codex.)*
- Mecanismo: o digest passa a computar e publicar: `{itens_auto_aplicados_nao_revisados, idade_max_da_fila, itens_revisados_na_janela, tempo_medio_inspecao (proxy: itens/sessão de digest)}`; check WDG alerta quando `idade_max > cap declarado` (cap no freeze, lei ELEV-03); quando o alerta dispara, a CADÊNCIA de auto-apply desacelera automaticamente (lei de máquina safety-increasing, padrão MAXK-08 — teto efêmero, nunca persiste; re-acelerar = decisão do operador).
- Aceite: digest real publica as 4 métricas com denominadores; fixture fila-velha ⇒ alerta + cadência reduzida no próximo ciclo (teste); a redução NUNCA vira fila de aprovação (itens continuam auto-aplicando, só mais devagar — charter intacto); métricas entram no registry ELEV-20s.
- Risco: operador ignorar o alerta — o desacelerador automático é o backstop de máquina; medir "revisão" por abertura de digest é proxy fraco — declarado como proxy, denominadores crus ao lado.

**ELEV-26s — Protocolo único de promoção (o slice da lei ELEV-26)** · E:M · onda M2 · deps: [ROL-01, WDG-01]
- Goal: ~40 slices repetem `default-OFF → shadow → live + ROL-01` com variações sutis (janelas, juízes, receipts ad-hoc) — drift de protocolo é onde nasce o flip mal-vigiado. O precedente interno é o WDG-01 (registry único de checks); promoção merece o mesmo.
- Mecanismo: serviço/contrato único `PromotionProtocol` com estados `{off, shadow, live, rolled_back, suspended_pending_evidence (ELEV-28)}`, campos obrigatórios {janela mínima de shadow, critério objetivo de flip, rollback trigger pré-declarado (ROL-01), judge_engine_id (ELEV-18), receipt}; um ledger de flips único; comando `atlas:promotions --json` lista o estado de TODA flag do programa. Slices existentes migram ao protocolo no próximo touch (não big-bang).
- Aceite: flip sem rollback-trigger pré-registrado ⇒ recusado; dois flips na mesma janela de observação da mesma família ⇒ recusado (atribuição limpa, regra ASI-10); `atlas:promotions --json` mostra 100% das flags novas do Max; flag antiga não-migrada aparece como `legacy_unmanaged` (honesto, não invisível).
- Risco: protocolo virar burocracia — o contrato tem 5 campos, não 50; slices E:S continuam E:S (o protocolo é biblioteca, não processo).

**ELEV-27 — Orçamento conjunto de recursos da máquina** `[MEDIDOR]` · E:S · onda M2 (pré-condição informativa de F3) · deps: [ASI-03, MAXG-01]
- Goal: ninguém soma daemon de embeddings + 2,3GB de modelos + 220MB halfvec + builds HNSW + Postgres 8GB + 10–15 workers + o MOTOR (Claude/Codex) nos mesmos 48GiB — os caps existem por componente (ASI-03, ASI-16, ASI-18) mas o orçamento CONJUNTO não existe.
- Mecanismo: tabela de orçamento versionada em docs {componente, RAM cap, disco, CPU share esperado} somando ≤ margem declarada dos 48GiB/SSD; check WDG lê RSS real dos residentes + tamanho real dos stores e alerta quando um componente fura o próprio cap OU a soma fura a margem; F3 (ASI-16/18) cita a tabela nos aceites de RSS.
- Aceite: tabela cobre 100% dos residentes/stores do programa; check reporta real-vs-cap por componente; fixture de estouro ⇒ alerta; a soma declarada fecha com margem para o motor (≥12GiB livres declarados).
- Risco: caps de papel — o check lê o REAL (ps/du/pg), nunca o declarado sozinho.

**ELEV-29s — Spec de capacidades de modelo por função (o slice da lei ELEV-29)** · E:S · onda M2 · deps: [MAXA-03]
- Goal: neutralidade executável — os slices nomeiam modelos locais (jina-v3, colbert-small, SPLADE) como implementação, mas o CONTRATO de cada função de embedding/rerank precisa ser capacidade, não nome, para o modelo ser substituível sem reescrever aceites.
- Mecanismo: spec versionada por função {dense_embed: ctx mín, dim, pooling, multilingual PT, determinismo, licença; late_chunk: token-embeddings expostos; rerank: par-scoring, latência/par; sparse: term-weights} em config/docs; a provenance MAXA-03 (`embedding_model` por vetor) já garante a troca detectável; aceites que citam modelo nominal apontam para a spec (MAXA-04 já ajustado).
- Aceite: toda função de modelo do programa tem spec; teste: modelo substituto que cumpre a spec passa o mesmo harness sem edição de aceite (provado com 2 dense models já instalados); modelo que viola a spec (dim errada) é recusado no boot com razão nomeada.
- Risco: spec frouxa demais — campos mínimos obrigatórios; spec rígida demais — só capacidades que algum slice de fato consome.

### Deps ADICIONADAS a slices existentes (nenhum corte — condição que os torna pagáveis)

| Slice | Dep/condição adicionada (normativa) | Porquê |
|---|---|---|
| **MAXI-07** (HMAC linhagem) | Sequenciar DEPOIS de ELEV-11 (âncora git) + threat model declarado no slice (adulteração entre estágios de captura, não adversário de DB) + aceite ganha 1 ataque real do corpus MAXM-01 detectado pela cadeia | Sem threat model, é criptografia decorativa para 1 operador local |
| **MAXM-07** (cotas) | Reenquadrado: o valor imediato é **telemetria de consumo por client_id** (alimenta MAXM-08/OPE-07); o softcap fica default-OFF até existir 2º consumidor real | Cota para ~3 clientes do mesmo dono é teatro multi-tenant; a telemetria não é |
| **RAGX-09** (faithfulness LLM) | Gate de entrada: RAGX-08 com `grounding_rate` estável por 2 janelas E corpus pós-ELEV-21; herda critério-de-morte do MAXJ-08 | Métrica advisory pesada só interpreta com base determinística madura |
| **MAXD-05** (Louvain/cards) | Dep dura em MAXA-06 (grafo denso código/KB) — igual RAGX-10 declara para si; antes disso, só comando read-only exploratório, nunca seção de pack | Grafo 2-3k nós 56% docs ⇒ "cluster de tudo" (risco que o próprio slice admite) |
| **MAXJ-08** (juiz frontier) | Dep dura em MAXJ-05 com ≥2 tipos acima do floor n≥8; o adapter pode landar, o logging só liga com a série de lift viva | Banda advisory sem série de lift madura é sinal ininterpretável — puro custo |

### Índice das elevações IN-PLACE já aplicadas no corpo do plano

| ELEV | Onde foi aplicada | O quê |
|---|---|---|
| ELEV-01 | MAXA-04, MAXC-01, MAXD-04, MAXD-06 (deps + aceites) | Régua dos A/Bs trocada do golden v1 (mede 0.0, 25/25 alvos ausentes — "≥ baseline" era 0≥0 vácuo) para o golden v2 com `targets_available == cases`; v1 vira só regressão de floor |
| ELEV-02 | item 14 do critério ASI | Fórmula de "valor" referencia o slice ASI-METRIC, congelada antes de F1 |
| ELEV-03 | ii.6, MAXI-04, preâmbulo RAGX | Limiar carimbado no freeze (lei + aplicações nomeadas) |
| ELEV-04 | MAXF-10 (aceite) | "% net-negativas cai a 0" (inconsistente com o mecanismo) → "% de OVERWRITES = 0 + tentativas como série informativa" |
| ELEV-05 | ASI-14 (aceite) | ≥2 task_categories + série outcome-com/sem — uso, não wiring |
| ELEV-06 | MAXH-03 (aceite) | "não-trivial" definido: ≥N pares (N no freeze) + ≥2 verbos |
| ELEV-07 | RAGX-08 (aceite) | `citation_coverage` ao lado do grounding_rate |
| ELEV-08 | ASI-02 (mecanismo) | Porta cobre as 4 vias (notes/compounding/verbatim) + varredura DB-level no teste arquitetural |
| ELEV-09 | MAXK-07 (aceite), MAXL-05 (mecanismo) | Floors de auto-aplicação fora da área 18 no registry ou sob sentinela |
| ELEV-10/23 | ASI-11 (aceite) | Cascata ≥50 com conflito + 4 estados formais (`clean/partial/blocked/containment`) + SLA de reversão medido |
| ELEV-11 | MAXL-02 (mecanismo) | Chain-head diário commitado no git + threat model declarado |
| ELEV-12 | item 16 novo do critério ASI | `verified_share` ≥0,80 como verificação de conclusão |
| ELEV-13 | §viii F0 (ordem 1↔2) | MAXG-01-mínimo ANTES de MAXE-02/03 (a régua antes do maior produtor — MED-01 respeitada pelo próprio F0) |
| ELEV-14 | ASI-02 (aceite) | Observe preenchível com músculo a 1 worker — mata o deadlock de denominador ASI-02↔ASI-06 |
| ELEV-15 | ASI-06 e ASI-07 (aceites) | Linhagem decision_id como ACEITE dos flips de F1, não parêntese |
| ELEV-18 | ii.6, preâmbulo RAGX | Juiz com engine-id mecânico (lei) |
| ELEV-25 | ASI-07 (aceite) + item 17 novo do critério ASI | Review-debt medido no digest |
| ELEV-30 | MAXB-08 (mecanismo) | Held-out temporal no replay do LTR |

**Nota de escopo:** ELEV-16 (alvo intermediário de hooks pós-M2: UserPromptSubmit p95 ≤ 8s / turno ≤ 20s como check WDG desde M2, apertando em F3) fica registrado aqui como floor intermediário do MAXG-10 — o alvo estrutural ≤5s continua sendo de ASI-16/17; a pressão passa a ser contínua, não um salto no fim.

---

## (xii) MULT — Evolução do Núcleo Multiplicador (a parte que multiplica os resultados)

> **Tese:** as áreas 12 (Execução Verificada), 9 (Aprendizado por Execução), 10 (Decisão), 17 (Originação), 1+16 (Memória & Verdade Temporal) e 15 (Modelo do Operador) são o NÚCLEO MULTIPLICADOR — as que não saturam com corpus pequeno e cujo ganho compõe (ou cresce com o motor). Esta seção é o mergulho de fronteira dedicado a elas, produzido por 6 leitores especializados (1 por área + 1 de INTEGRAÇÃO/costuras do flywheel), cada um relendo o código vivo e propondo o ALÉM do que MAXx/ASI/ELEV já cobrem. Contrato pétreo herdado por inteiro + leis ELEV (ii.6). Onda default dos slices MULT: **M6 (pós-Max)** salvo marcação — nenhum MULT fura a ordem F0→F1 do §viii; os `[MEDIDOR]` de cada família congelam primeiro (mesma lei de sempre).
>
> **Status de produção desta seção: COMPLETA (12/07/2026)** — os 7 catálogos LANDADOS abaixo: **MULTJ** (área 9, 9 slices) · **MULTK** (área 10, 8) · **MULTH** (áreas 1+16, 8) · **MULTN17** (área 17, 8) · **MULTV** (área 12, 10) · **MULTN15** (área 15, 8) · **MULTX** (Integração/costuras, 9) — **60 slices MULT**, todos com file:linha verificados no código vivo em 11-12/07/2026. O bloco "Pendências" no fim da seção virou registro histórico do briefing dos leitores.

---

#### Área 9 — Aprendizado por Execução (MULTJ) — o motor do composto

**Teto atual (relido no código em 11/07/2026):**
- Destilação é 1:1 e template-shaped: `AtlasLearningDistiller.php:15` (`distill(AiRunOutcome…): AiLearningCandidate`) produz exatamente 1 candidate por outcome, decisão `promote/hold` por `confidence >= 70` hard-coded (`:21`), schema `learning_candidate.v1` (`:10`) — zero abstração, zero composição na origem.
- Floor frágil do lift: `AtlasLearningRecallUseLiftService.php:44` — `max(1, config('atlas.ai.loop.learning_recall_use_lift.min_cases_per_arm', 2))`; corrigido para n≥8 só na série v2 (MAXJ-05), o agregado v1 segue congelado a n=2.
- Playbook é CONTEXTO, não skill: `ProceduralPlaybook.php:22` é value-object (objective/forbiddenActions, `:13-16`) injetado como texto pelo `AtlasProceduralPlaybookApplier`; não existe artefato executável com pós-condição testável em lugar nenhum do repo.
- Credit assignment em silo: `AtlasExternalBrainTaskOutcomeCausalAttributor.php:82` (`attribute()`) atribui causa mas o resultado nunca alcança `AiLearningCandidate.payload` (MAXJ-02 é quem liga; nada além dele).
- Negative learning é status booleano: `AtlasAemorJudgmentService.php:329` (`negativeKnowledge()`) emite `status: 'candidate'` sem força, sem recorrência quantificada, sem meia-vida (MAXJ-04 dá força; ninguém dá curva temporal).
- Flywheel RPM≈0: `AtlasCompoundingRuntimeService.php:34` (`recordExecution`) vivo mas `ai_run_outcomes=0`, `ai_compounding_memories=0`; `AtlasCaptureQualityGate.php:71` descarta 94% do que chega.

**Fronteira (técnica → veredito + porquê):**

| Técnica | Veredito | Porquê |
|---|---|---|
| Biblioteca de SKILLS executáveis (Voyager) | **SIM** | ASI-14/playbook = contexto injetado; skill = artefato versionado com pós-condição executável — é a diferença entre "lembrar" e "saber fazer". Maior salto da área. |
| Currículo por replay (shadow off-policy) | **SIM, gated** | Único jeito local de medir "a lição teria ajudado?" sem esperar tráfego novo; custo controlado por amostra fixa + peek. |
| Crédito contrafactual barato (A/B peek com/sem memória) | **SIM** | Extensão natural do RecallUseLift; hoje o lift é observacional (com≠sem por seleção); pareamento na MESMA task remove o confound. |
| Dedup semântico pré-promoção | **SIM** | Reuso puro do `AtlasMemoryVectorSearchService`; sem isso MAXJ-03 agrega só mesmo-assinatura e o corpus infla com quase-duplicatas. |
| Curvas de esquecimento ativas (re-teste antes de decair) | **SIM** | MAXH-08 decai por não-uso; re-testar antes de matar evita perder lição rara-mas-valiosa (falso decay). |
| Lição multi-nível (tática→padrão→princípio) | **SIM** | MAXJ-03 abstrai só assinatura comum; a escada de níveis com case_count crescente é a generalização que falta. |
| Auto-síntese de playbook de N skills co-ocorrentes | **ADIAR (gate ELEV-28)** | Depende de skills existirem + co-ocorrência medida; construir antes do produtor viola ELEV-20. Entra como slice suspenso. |
| Transferência cross-domain gated | **SIM, M6 tardio** | Só com prova A/B no domínio destino; sem prova é contaminação de contexto. |
| Métrica de meia-vida do conhecimento | **SIM (medidor)** | Denominador natural para forgetting-curves e para MAXJ-06 (ROI por tipo × tempo); congela antes dos atuadores. |

**Slices:**

**MULTJ-01 — Meia-vida do conhecimento: série `lesson_half_life.v2`** `[MEDIDOR]` · E:S · onda M6-a · deps: [MAXJ-01, MAXJ-05, MED-01]
- Goal: cada lição ganha curva de rendimento no tempo — quanto tempo continua produzindo lift após promoção — para que decay e currículo parem de operar cegos.
- Mecanismo: leitor read-only sobre `atlas_memory_entry_usages` + a série MAXJ-05, bucketizando lift por idade-da-lição (semanas desde promote); emite `{half_life_weeks, buckets[], n_per_bucket}` por memory_type. NÃO tocar `AtlasLearningRecallUseLiftService.php:44` nem o agregado v1; série própria versionada.
- Aceite: `atlas:ai:lesson-half-life --json` com denominador exposto por bucket; bucket com n<8 ⇒ `insufficient` (floor pinado no freeze — ELEV-03); caso negativo: lição sintética com uso zero após semana 1 reporta half_life curta, nunca `null` silencioso; freeze grava `{judge_engine_id, author_engine_id}` (ELEV-18).
- Risco: janela real curta demais → série fica `insufficient` por meses; mitigação: buckets largos (2 semanas) declarados no freeze.

**MULTJ-02 — Dedup semântico pré-promoção (embedding local, gate advisory→enforce)** · E:S · lote L2 (gate advisory de dedup — NÃO é medidor; landável em L2 porque deps MAXJ-01@L2 + ASI-02@L1 já estão prontas) · deps: [MAXJ-01, ASI-02] — *usa o embedding LOCAL JÁ EXISTENTE (`AtlasMemoryVectorSearchService`, MiniLM vivo) que o próprio mecanismo reusa; NÃO depende do upgrade de modelo MAXA-04 (se MAXA-04 landar depois, a dedup só melhora — nunca bloqueia). Correção de agenda 12/07: célula L2 é válida sem MAXA-04.*
- Goal: candidate quase-duplicata de lição já ativa não vira segunda entrada — vira `case_count++` na existente.
- Mecanismo: no caminho de promoção do distiller (`AtlasLearningDistiller.php:46-54`), consulta ao `AtlasMemoryVectorSearchService` (reuso — órgão vivo, ELEV: reuso antes de construção); cosine ≥ threshold pinado no freeze ⇒ `merged_into` + incremento de case_count na canônica, com receipt reversível. Escada observe (loga would-merge) → enforce. NÃO criar embedder novo; NÃO tocar CaptureQualityGate (a dedup roda DEPOIS do gate, sobre aprovados).
- Aceite: fixture com 2 candidates parafraseados ⇒ 1 entry com case_count=2 + receipt; 2 candidates semanticamente distintos (cosine < threshold) ⇒ 2 entries (caso negativo); threshold + corpus de calibração carimbados no ledger antes do enforce (ELEV-03); modo observe grava 0 merges.
- Risco: merge falso-positivo apaga nuance; mitigação: merge preserva payload de ambos (reversível, charter 06/07).

**MULTJ-03 — Crédito contrafactual pareado: mesma task com/sem memória, em peek** `[MEDIDOR]` · E:M · onda M6-a · deps: [MAXJ-05, v1 FEE-04, MED-01]
- Goal: substituir o lift observacional (braços com/sem auto-selecionados) por pares contrafactuais: a MESMA task avaliada com e sem a lição injetada.
- Mecanismo: no ponto de injeção do pack, fração amostrada (rate pinada) roda avaliação dupla em faixa peek `record_usage=false` (padrão RAG-01): score do juiz determinístico com pack+lição vs pack−lição; delta gravado em série `counterfactual_lift.v2` por memory_type. LLM nunca é juiz; juízes = gates deterministas existentes. NÃO tocar a série v1 do RecallUseLift.
- Aceite: `--json` reporta `{paired_delta, n_pairs, rate}` com n_pairs ≥ 8 por tipo antes de reportar (floor hard-coded na série nova, padrão MAXJ-05); zero rows de usage nas passadas peek (asserção); caso negativo: lição irrelevante injetada ⇒ delta ≈ 0, nunca positivo fabricado; limiar de "lift real" carimbado no freeze.
- Risco: custo 2× na fração amostrada; mitigação: rate default 5% pinada, kill-switch env.

**MULTJ-04 — Skills executáveis: sucesso repetido vira procedimento invocável versionado** · E:L · onda M6-b · deps: [ASI-14, MAXJ-03, ASI-02, MULTJ-02]
- Goal: o salto Voyager — lição com case_count ≥ floor e pós-condição verificável vira `skill.v1`: artefato {passos, pré-condições, pós-condição EXECUTÁVEL (comando/teste determinista), versão, provenance} invocável pelo executor, não só lido como contexto.
- Mecanismo: promotor que eleva `procedural` (ASI-14) a skill SOMENTE quando a pós-condição é mecanizável (existe check determinista: exit-code de comando, asserção phpunit, gate existente); armazenada via porta ASI-02 com tipo novo `skill`; consumidor: o `AtlasProceduralPlaybookApplier` ganha braço "invoke" atrás de flag default-OFF → shadow (skill sugerida+logada, não executada) → live (protocolo ELEV-26). Skill que falha a pós-condição 2× consecutivas é auto-demovida a playbook (reversível). O que NÃO fazer: gerar skills por LLM sem case_count real; executar skill fora do sandbox floor pétreo.
- Aceite: ≥1 skill derivada de ≥3 execuções reais passing com pós-condição que RODA no aceite (executável, não prosa); invocação citada em receipt de ≥2 execuções vivas (produtor+consumidor — ELEV-20); caso negativo: lição sem pós-condição mecanizável NUNCA vira skill (fica playbook); demoção automática testada com skill sabotada.
- Risco: pós-condição frouxa vira skill decorativa; mitigação: só checks deterministas admitidos, lista de tipos de check pinada no freeze.

**MULTJ-05 — Replay curricular em shadow: a lição nova contra o passado** `[MEDIDOR]` · E:L · onda M6-b · deps: [MULTJ-03, MAXJ-01, sandbox floor pétreo]
- Goal: off-policy local — antes de uma lição promovida ganhar confiança alta, re-executar amostra fixa de tasks históricas (do ledger) em shadow-sandbox com a lição no pack e medir delta vs o outcome histórico registrado.
- Mecanismo: runner sobre snapshot sandbox (reuso do materializer sandbox floor — NUNCA live repo), amostra de N tasks históricas congelada por hash; delta = gates deterministas passam/não-passam vs registro histórico; resultado alimenta o prior de confiança (MAXJ-06) como componente, nunca como promoção direta. NÃO re-executar tasks com side-effects externos (filtro por task_class read-only).
- Aceite: replay de amostra ≥8 tasks com lição X ⇒ `{delta_pass_rate, n, sample_hash}` no ledger; amostra e limiar pinados no freeze (ELEV-03); caso negativo: lição neutra ⇒ delta dentro da banda de ruído declarada; zero writes fora do sandbox (asserção do floor); replay OFF = zero custo.
- Risco: replay caro e não-determinista; mitigação: amostra pequena fixa, só task_classes determinísticas elegíveis, roda em janela ociosa.

**MULTJ-06 — Escada de abstração: tática → padrão → princípio com case_count por nível** · E:M · onda M6-b · deps: [MAXJ-03, MULTJ-02, ASI-09, ASI-02]
- Goal: além do MAXJ-03 (mesmo-assinatura): quando ≥K lições-padrão de assinaturas DISTINTAS compartilham estrutura causal (`caused_by` de MAXJ-02), o motor frontier de ASI-09 AUTORA um princípio de nível 3; gates deterministas julgam (author≠judge).
- Mecanismo: agregador sobre candidates com `caused_by.primary_cause` comum entre assinaturas diferentes; nível gravado no payload (`abstraction_level: 1|2|3`) + `derived_from[]` citável; princípio entra pela MESMA porta ASI-02 e MESMA fila (padrão MAXJ-07 — nunca fila paralela). Injeção no pack prioriza nível mais alto que casa, com fallback ao tático. NÃO deixar o frontier promover; NÃO criar tipo de memória fora da porta.
- Aceite: fixture com 3 padrões de assinaturas distintas e mesma causa ⇒ 1 candidate nível-3 com `derived_from` = 3 refs resolvíveis; princípio com `derived_from` quebrado ⇒ rejeitado pelo gate (caso negativo); K pinado no freeze; lift do nível-3 medido separado na série MAXJ-05 antes de qualquer priorização de injeção.
- Risco: princípio genérico-inútil ("teste antes de commitar"); mitigação: CaptureQualityGate roda no nível 3 também + contrafactual MULTJ-03 obrigatório antes de priorizar na injeção.

**MULTJ-07 — Esquecimento ativo: re-teste antes de decair** · E:M · onda M6-b · deps: [MULTJ-01, MULTJ-03, MAXH-08]
- Goal: lição não-usada há meia-vida (MULTJ-01) não decai silenciosa — ganha UM re-teste contrafactual barato (MULTJ-03, injeção forçada em 1 par peek); rende lift ⇒ renova; não rende ⇒ decai com evidência.
- Mecanismo: hook no planner de decay do MAXH-08 (reuso — não segundo decay engine): antes do verbo decay em memória de execução, agenda 1 par contrafactual; resultado grava `decay_evidence` no receipt do MAXH-08. NÃO bloquear o decay de outras classes de memória; NÃO re-testar mais de 1× por ciclo (custo).
- Aceite: lição na fronteira de decay com lift positivo no re-teste ⇒ decay adiado + receipt com o delta; lift ≈ 0 ⇒ decay procede com `decay_evidence` anexada; flag OFF = decay MAXH-08 byte-idêntico.
- Risco: re-teste vira loophole de imortalidade; mitigação: máximo de 2 renovações por lição, contador no payload, teto pinado.

**MULTJ-08 — Transferência cross-domain com prova no destino** · E:M · onda M6-c · deps: [MULTJ-06, MULTJ-03, MAXL-05]
- Goal: princípio nível-3 de engenharia só entra no pack de outro domínio (marketing, finance…) após A/B contrafactual POSITIVO medido no domínio destino — nunca por analogia.
- Mecanismo: candidatos = princípios com lift comprovado no domínio origem; piloto shadow no destino via MULTJ-03 (pares peek, n≥8 no DESTINO); aprovado ⇒ entry ganha `scopes[]` adicional via porta ASI-02 com receipt citando o A/B; reprovado ⇒ família suspensa (ELEV-28), nunca deletada.
- Aceite: princípio com lift origem>0 e destino≈0 ⇒ scope NÃO expande (caso negativo obrigatório); expansão de scope sem ref de A/B destino ⇒ rejeitada pela porta; n e limiar do destino pinados no freeze; sensitive/secret nunca cruza domínio (classe bloqueada estruturalmente).
- Risco: contaminação sutil de contexto irrelevante; mitigação: shadow-only até 2 A/Bs positivos em janelas distintas.

**MULTJ-09 — Auto-síntese de playbook de skills co-ocorrentes** `[SUSPENSO — gate ELEV-28]` · E:M · onda M6-c · deps: [MULTJ-04 (≥5 skills vivas com invocação real), MAXJ-07]
- Goal: N skills invocadas juntas em execuções passing viram playbook composto (sequência ordenada com pós-condição agregada).
- Mecanismo: mesmo padrão do MAXJ-07 (co-ocorrência sobre receipts de invocação, floor de co-casos, frontier autora, gates deterministas julgam). SUSPENSO até MULTJ-04 provar ≥5 skills com tráfego real (ELEV-20/ELEV-28 — construir o compositor antes do produtor reproduziria o construído-mas-não-ligado).
- Aceite (quando destravar): playbook composto citado em **≥N execuções (N≥8, nomeado no freeze, alinhado a MULTJ-04/06 — NUNCA n=2, contrato pétreo)** com pós-condição agregada rodando; floor de co-casos pinado no freeze (ELEV-03).
- Risco: destravar cedo demais; o gate de família é o próprio mecanismo de mitigação.

**Ordem sugerida (MULTJ):** M6-a mede antes de agir — MULTJ-01 → 02 → 03 (réguas/higienes, congeladas antes de qualquer atuador). M6-b: MULTJ-04 (o salto skills) em paralelo com MULTJ-06; MULTJ-05 e 07 depois de MULTJ-03 existir. M6-c: MULTJ-08 só com nível-3 provado; MULTJ-09 suspenso por gate.

**Colisões MULTJ com v1/Max/ASI:**
- MULTJ-02 NÃO duplica MAXH-01/MAXH-07 (relações/síntese entre memórias JÁ ativas): opera pré-promoção sobre candidates aprovados pelo gate; se MAXH-07 aterrissar antes, MULTJ-02 reusa seu adapter de síntese em vez de merge próprio.
- MULTJ-03 é o análogo em Compounding do padrão peek `record_usage=false` do RAG — mesma semântica, série própria; nunca alimenta o agregado v1 do RecallUseLift (floor n=2 congelado, fronteira já pinada pelo MAXJ-05).
- MULTJ-04 estende ASI-14, não o substitui: playbook segue sendo o fallback de toda skill demovida; o Applier é o mesmo órgão.
- MULTJ-06 estende MAXJ-03 para assinaturas distintas; sem MAXJ-02 aterrissado, MULTJ-06 não tem entrada.
- MULTJ-07 é hook no MAXH-08, não segundo motor de decay; flag OFF = MAXH-08 byte-idêntico.
- Nenhum slice toca `config/atlas.php` (`min_cases_per_arm`), o golden v1, ou séries congeladas — toda medição é série `.v2`/nova com freeze próprio (ELEV-03/18).

---

#### Área 10 — Decisão (MULTK) — o multiplicador silencioso

**Teto atual (relido no código em 2026-07-11):**
- Seleção é single-shot cost-first sobre médias pontuais: `usort` custo-asc → score → certified_count em `AtlasDecideCostOutcomeRouter.php:129-140`; não existe caminho de escalada — a rota barata escolhida é final, falha vira só outcome no ledger.
- Evidência vira **média sem intervalo**: `average_score` = média dos certified (`:332`); a "confiança" é rótulo por contagem (`confidenceFor()` `:400-413`, thresholds 6/3) que **nunca entra na seleção**.
- Cold-start estruturalmente inamostrável: `min_evidence` default 3 (`:181`) + blocker `insufficient_certified_evidence` (`:342-343`); MIN_CALLS_FOR_SIGNAL=5 no live-feedback (`AtlasDecideLiveOutcomeFeedbackService.php:61`) — provider sem histórico nunca elegível (MAXK-02 é quem cria exploração).
- Latência já está no ledger (`avg_latency_ms` `AtlasDecideLiveOutcomeFeedbackService.php:345`) mas **ausente dos campos de candidato** (`:358-376`) e de qualquer chave de ordenação (MAXK-03 cobre o peso).
- `consult()` tem vocabulário de 4 verdicts (`AtlasDecideGatewayConsultationService.php:38-44`) — não existe "abster"; incerteza alta degrada silenciosamente para `free_to_choose` (`:157-158`); `requested_autonomy` é **literal hardcoded** `'autonomous'` no request de admission (`:106`), não derivado de risco/histórico.
- `fallback` = best-score in-pool (`:143-152`) — contrafactual existe mas sem régua (MAXK-01 cria); decomposição de obra em packets não é decisão registrada em lugar nenhum do namespace Decide.

**Fronteira (técnica → veredito + porquê):**

| Técnica | Veredito | Porquê |
|---|---|---|
| Cascata custo-consciente (barato→caro sob incerteza/falha) | **ADOTAR** | Extensão natural do cost-first vivo; o gate de escalada é a banda derivada (ASI-15), não juízo do caller; é o único caminho em que errar barato custa pouco |
| Quantificação de incerteza (Beta posterior, sem ML) | **ADOTAR primeiro** | Função pura de (proven_success, n) — intervalo largo é o sinal honesto que o rótulo `confidenceFor` finge dar; pré-requisito da cascata e da abstenção |
| Decision replay em shadow | **ADOTAR** | `gateway_consultations.jsonl` + `live_outcomes.jsonl` já são o corpus; peek puro, zero mutação; série "hoje discorda de ontem em X%" é o medidor de drift do próprio Decide |
| Abstenção calibrada como braço | **ADOTAR com charter-guard** | Não-decidir é legítimo quando o intervalo fura o teto — mas abster = anotar+digest+degradar para o default do gateway, NUNCA fila humana (charter 06/07) |
| Decomposição de tarefa como decisão de 1ª classe | **ADOTAR (shadow)** | Hoje implícita e não-auditável; vira receipt (seam MAXK-04) antes de virar atuador |
| Orçamento de portfólio {reativo, originado, manutenção} | **ADOTAR** | Liga MAXN-04; alocação é decisão executiva com receipt; pesos operator-authored (nunca do modelo) |
| Autonomia por tarefa derivada de risco+histórico | **ADOTAR estreito** | Só o *input* `requested_autonomy` (`:106`) vira derivado — e só para BAIXO (safety-increasing); os floors/escada continuam de MAXK-05..09 |
| Mineração de decision receipts (padrão pré-falha ⇒ sinal negativo) | **ADOTAR report-only** | Precisa de MAXK-04 (receipts com basis) + OUTC-01; promoção a termo de seleção só via ELEV-26 |
| Simulação barata pré-decisão (dry-run) | **REJEITAR como slice** | A "estimativa determinística" honesta JÁ É o posterior do ledger (MULTK-01); dry-run real de provider não é determinístico e queima budget — dobrado no gate da cascata |

**Slices:**

**MULTK-01 — Intervalo de incerteza por candidato (Beta posterior, medidor congelado)** `[DECISÃO][MEDIDOR]` · E:S · onda M1 · deps: [MAXK-01, MED-01]
- Goal: cada candidato carrega `{lower_bound, upper_bound, n}` — a média pontual de `:332` deixa de fingir certeza; é o alicerce de cascata, abstenção e exploração.
- Mecanismo: campos aditivos em `costOutcomeCandidates()` (`AtlasDecideCostOutcomeRouter.php:358-376`): intervalo de credibilidade Beta(α=proven_success+1, β=falhas+1) sobre a janela do live-feedback — **função pura da evidência, jamais setável pelo caller** (lição SEV-1). NÃO tocar a ordenação `:129-140` (isso é MULTK-02); NÃO tocar séries v1; `confidenceFor()` fica intacto (rótulo legado).
- Aceite: teste de propriedade — mesmo (sucessos, n) ⇒ mesmo intervalo; n<3 ⇒ campo `interval: insufficient_n` (nunca número); largura decresce monotônica com n; hash da régua v2 registrado no freeze com quantil pinado (ELEV-03); seleção byte-idêntica antes/depois (só campos novos).
- Risco: virar escalar único fabricável (lição do "92") — publica-se o par de bounds + n crus, nunca um score composto.

**MULTK-02 — Roteamento em cascata custo-consciente (escala só sob incerteza/falha)** `[DECISÃO]` · E:M · onda M4 · deps: [MULTK-01, MAXK-01, MAXK-02, ELEV-26]
- Goal: tentar a rota barata primeiro e escalar para a cara SÓ quando o lower_bound fura o floor ou a tentativa falha — captura o grosso da economia sem sacrificar proven_success.
- Mecanismo: no ponto de seleção (`costOutcomeRoute`, `:142-169`): escolhe o mais barato com `lower_bound ≥ score_floor` (não mais `average_score ≥ floor`, `:113-117`); em `result ∈ {failure,timeout}` do pick, UMA escalada para o próximo da cadeia, carimbada `routing_basis=cascade_escalation` no receipt (seam MAXK-04); cap diário de escaladas (vocabulário de budget do MAXK-02). Toda perna passa pelo MESMO `consult()` (Kernel/Admission intactos, `:93-107`). Switch default-OFF ⇒ greedy byte-idêntico. NÃO fazer: dry-run de provider como gate; mais de 1 escalada por task.
- Aceite: fixture determinística — candidato barato com intervalo largo NÃO é escolhido direto quando existe caro com lower_bound acima do floor; sob ON no yardstick MAXK-01, custo médio ≤ OFF E proven_success_rate ≥ OFF − margem pinada no freeze (ELEV-03); caso negativo: cap de escaladas atingido ⇒ próxima falha NÃO escala (assert por contagem); 0 escaladas em sensitive/secret/cyber.
- Risco: cascata degenerar em "sempre escala" (custo dobra) — o cap é hard invariant e a série escaladas/dia entra no digest; A/B raiz negativo ⇒ família suspensa (ELEV-28), MULTK-04 segue vivo (dep é MULTK-01, não 02).

**MULTK-03 — Decision replay em shadow (série de divergência do Decide)** `[DECISÃO][MEDIDOR]` · E:S/M · onda M4 · deps: [ASI-13, MAXK-01, MAXK-04]
- Goal: medir drift do próprio decisor — "o Decide de hoje discorda do de ontem em X%" — antes que qualquer mudança de router (MAXK-02/03, MULTK-02) seja creditada ou culpada às cegas.
- Mecanismo: job read-only que re-executa a decisão sobre os contextos históricos de `gateway_consultations.jsonl` (`AtlasDecideGatewayConsultationService.php:68`) com o estado ATUAL (evidência+modelo-de-si ASI-13) em modo peek (`record_usage=false`) e publica `{n_replayed, divergence_rate, divergência por escopo grosso}` — junção por `envelope_hash` (`:126-134`). NÃO fazer: replay que escreve no JSONL vivo; replay de contexto sensitive para provider (é 100% local/determinístico por construção).
- Aceite: comando `--json` publica série com denominador; replay do MESMO estado ⇒ divergência 0 (sanidade); célula com n<10 ⇒ `insufficient_n`; nenhum byte novo em `gateway_consultations.jsonl`/`live_outcomes.jsonl` após um run (teste de hash do arquivo); divergência que salta após deploy de router gera issue no ledger de gaps.
- Risco: divergência virar proxy de "melhora" (mudar muito ≠ melhorar) — a série é DESCRITIVA, cruzada com regret MAXK-01; nunca é gate.

**MULTK-04 — Abstenção calibrada como braço legítimo (rota para o digest, nunca fila)** `[DECISÃO]` · E:S/M · onda M4 · deps: [MULTK-01, ASI-15]
- Goal: quando a incerteza fura o teto, "não recomendar" vira verdict de 1ª classe com receipt — em vez do silêncio atual que degrada para `free_to_choose` (`:157-158`) sem registrar POR QUÊ.
- Mecanismo: novo verdict `abstained_uncertain` em `deriveVerdict()` (`AtlasDecideGatewayConsultationService.php:151-165`): disparado quando NENHUM candidato tem `lower_bound ≥ floor` E largura máxima de intervalo > teto pinado; efeito operacional = idêntico a `free_to_choose` (gateway segue autoridade, charter: ZERO fila humana) + entrada no digest com a evidência que faltou. Teto de abstenção derivado (banda ASI-15), jamais config do caller. NÃO fazer: bloquear a task; criar aprovação pendente.
- Aceite: fixture todos-intervalos-largos ⇒ `abstained_uncertain` + task PROSSEGUE pelo default do gateway (teste); taxa de abstenção publicada com denominador; teto carimbado no freeze (ELEV-03); caso negativo: 1 candidato com lower_bound ≥ floor ⇒ NUNCA abstém; calibração ex-post: abstenções não têm proven_success pior que decisões forçadas de intervalo largo (senão abster foi teatro).
- Risco: abstenção crônica esconder o cold-start — a série taxa-de-abstenção por escopo alimenta MAXK-02 (é exatamente onde explorar).

**MULTK-05 — Decomposição de obra como decisão de primeira classe (N packets vs direto)** `[DECISÃO]` · E:M · onda M5 · deps: [MAXK-04, ASI-13]
- Goal: "quebrar em N ou executar direto" sai do implícito e vira Decision Receipt auditável com basis — hoje nenhum receipt do namespace Decide registra essa escolha.
- Mecanismo: serviço novo no namespace Decide que emite receipt `decision_kind=decomposition` `{direct|split_n, basis, evidence_refs}` via o adaptador OperationEnvelope do MAXK-04 (`DecisionReceiptIssuer.php:21`); sinal DERIVADO: taxa proven_real por banda de tamanho do modelo-de-si (ASI-13), nunca declarado pelo executor. **Shadow primeiro**: registra o que TERIA decidido vs o que o executor fez; só vira atuador via ELEV-26 após 2 janelas. NÃO fazer: atuador na 1ª onda; 19ª área — vive na 10.
- Aceite: execução real de obra gera receipt de decomposição com decision_id (produtor+consumidor ELEV-20: o digest lê); fixture — obra grande com histórico proven ruim em direct ⇒ shadow recomenda split; caso negativo: n<10 na banda de tamanho ⇒ `basis=insufficient` e shadow NÃO recomenda; divergência shadow-vs-real publicada com denominador.
- Risco: régua de tamanho virar proxy Goodhart (quebrar tudo em migalhas) — o outcome julgado é proven_real da obra INTEIRA, não dos packets.

**MULTK-06 — Orçamento de portfólio {reativo, originado, manutenção} com régua de yield** `[DECISÃO]` · E:M · onda M6 · deps: [MAXN-04, MAXK-01, MAXK-07]
- Goal: a alocação de ciclos entre trabalho reativo, originado e manutenção deixa de ser acidente de fila e vira decisão com receipt e régua de yield por classe.
- Mecanismo: alocador que consome o yield REAL provado do MAXN-04 (PathYieldEwma consumido, não write-only) e emite receipt `decision_kind=portfolio_allocation`; **pesos/bandas de alocação são operator-authored e só mutáveis via emenda MAXK-07** (quem decide nunca escreve as próprias regras — alocação é exatamente o tipo de regra que o modelo iria querer afrouxar); default = distribuição atual observada ⇒ OFF byte-idêntico. NÃO fazer: o alocador escrever os próprios pesos; teto de manutenção zero (starvation).
- Aceite: com pesos default, o mix de fila é estatisticamente idêntico ao histórico (janela pinada no freeze, ELEV-03); yield por classe publicado com denominador; mudança de peso sem amendment receipt ⇒ rejeitada (teste); caso negativo: classe com yield alto NÃO pode exceder a banda máxima do operador (anti-Goodhart: yield medido por proxy nunca captura tudo).
- Risco: régua de yield rasa (n baixo por classe) — `insufficient_n` por classe, alocação segue default até encher.

**MULTK-07 — `requested_autonomy` derivado de risco+histórico (só estreita, nunca alarga)** `[DECISÃO→GOV]` · E:M · onda M5 · deps: [MAXK-05, MAXK-06, ASI-11]
- Goal: fechar o literal hardcoded — hoje TODO consult pede `'autonomous'` (`AtlasDecideGatewayConsultationService.php:106`) independente de risco; o pedido passa a ser função derivada de {privacy_class, taxa de reversão da rota (linhagem ASI-11), n} — a Decisão pede menos autonomia quando a evidência é rasa.
- Mecanismo: função pura que mapeia evidência → `requested_autonomy`, **monotônica só-para-baixo** a partir de `'autonomous'` (padrão MAXK-08: máquina aperta, nunca alarga); Admission continua a ÚNICA autoridade (`:101-107` intacto) — isto muda o *pedido*, jamais o *veredito*; floors da área 18 vencem sempre. NÃO fazer: derivar para cima; tocar a escada MAXK-05/06.
- Aceite: teste de propriedade — para toda evidência, `derived ≤ 'autonomous'` na ordem da escada; rota com reversal alto ⇒ pedido rebaixado (fixture); caso negativo: pedido rebaixado NUNCA vira allow onde antes era deny — só o inverso; série pedidos-rebaixados/dia no digest.
- Risco: parecer redundante com MAXK-08 — não é: MAXK-08 aperta o ENVELOPE (teto), este aperta o PEDIDO (piso do request); os dois são safety-increasing e compõem.

**MULTK-08 — Mineração de decision receipts: padrão pré-falha vira sinal negativo (report-only)** `[DECISÃO][MEDIDOR]` · E:M · onda M6 · deps: [MAXK-04, MAXK-01, v1 OUTC-01]
- Goal: os receipts param de ser só audit trail — padrões de decisão que precedem falha (escopo×basis×provider com failure rate acima do teto) viram sinal negativo explicável.
- Mecanismo: job ex-post junta Decision Receipts v2 (MAXK-04) × outcomes reais (OUTC-01, junção por decision_id/entry_hash) e publica `{padrão, failure_rate, n}` a escopo grosso (mesma lição do MAXK-01: dropar framework até ASI-06 encher); padrão só é REPORTADO com `n ≥ mínimo pinado` + failure_rate > teto pinado (ELEV-03); consumo como termo negativo da seleção SÓ via promoção ELEV-26 após 2 janelas, e como VIÉS report-carimbado — nunca blocker duro (blocker é papel dos floors da 18). NÃO fazer: minerar sobre o JSONL v1 sem basis (esperar MAXK-04); auto-blacklist de provider.
- Aceite: fixture com padrão sintético de falha ⇒ detectado com num/den crus; padrão com n abaixo do mínimo ⇒ AUSENTE do report (caso negativo); régua v2 congelada com hash + `{judge_engine_id ≠ author_engine_id}` no freeze (ELEV-18); nenhuma mudança de seleção enquanto report-only (teste byte-idêntico).
- Risco: mineração achar padrão espúrio (múltiplas comparações) — teto + n mínimo pinados ANTES de ver o dado (ELEV-03) e escopo grosso limitam o espaço de hipóteses.

**Ordem sugerida (MULTK):** MULTK-01 (M1, junto de MAXK-01 — mesma família de régua) → MULTK-03 e MULTK-04 (M4) → MULTK-02 (M4 tardio, após MAXK-02 assentar o vocabulário de budget) → MULTK-05 e MULTK-07 (M5, dependem do seam MAXK-04 e da escada MAXK-05/06) → MULTK-06 e MULTK-08 (M6, dependem de MAXN-04 vivo e corpus de receipts v2).

**Colisões MULTK com v1/Max/ASI:**
- MAXK-01 é dep, não re-proposto: MULTK-01 adiciona intervalo ao candidato; a régua de regret e o contrafactual greedy-deslocado continuam 100% do MAXK-01.
- MAXK-02 (exploração) é ortogonal a MULTK-02 (cascata): exploração AMOSTRA o não-provado; cascata ORDENA os provados por custo com escalada — compartilham só o vocabulário de budget e o `routing_basis` do receipt.
- MAXK-03 (pesos multi-objetivo por call) ≠ MULTK-06 (alocação de ciclos entre classes) — escalas diferentes; ambos herdam "pesos nunca do modelo" e roteiam mudança por MAXK-07.
- MAXK-05/06/08/09 são donos da escada/envelope; MULTK-07 toca SÓ o literal `:106` do lado do pedido, só-para-baixo.
- ASI-13/15 são consumidos, jamais reconstruídos — MULTK-02/04/05 citam a banda no receipt pelo MESMO seam.
- MAXK-04 é o seam de receipt de MULTK-03/05/08 — nenhum cria emissor paralelo; `gateway_consultations.jsonl` permanece byte-compat (aditivo).
- MAXN-04 fornece o yield que MULTK-06 consome — MULTK-06 não recomputa yield, fecha o consumidor que faltava (ELEV-20).
- Medidores v1 congelados intocados em todos os 8: campos e verdicts ADITIVOS; toda régua nova é v2 própria com hash no freeze.

---

#### Áreas 1+16 — Memória & Verdade Temporal (MULTH) — o capital que não pode apodrecer

**Teto atual (relido no código em 11/07/2026):**
- `AtlasMemoryEntry.php:21-36` — 11 tipos FLAT (decision…refutation_memory); sem tier, sem TTL por tier, sem `procedural` (chega via ASI-14); corpo é texto livre — contradição só é detectável por cosine, nunca por campo.
- `AtlasMemoryRegistryService.php:607` (`normalize()`) e `:678` — os campos temporais (`valid_until`, `observed_at`, `stale_after`, `authority_level`, `source_hash`) são pass-through caller-supplied; nenhuma derivação (MAXH-02 cobre a derivação, não os schemas).
- `AtlasMemoryConflictResolutionService.php:459-550` — 6 kernels puros; `detectFactPolarityContradiction` (:516) e `detectNumericRangeOverlap` (:533) são dead-fed **por construção**: exigem fatos estruturados que o entry flat não carrega. Wire deles = pré-requisito de schema, não de scanner.
- `AtlasHybridMemoryRetrievalService.php:234-236` — score final = base × feedback factor × concentration multiplier; zero peso de proveniência/evidência (MAXH-05 adiciona só o temporal).
- `HasTemporalTruth.php:24-65` — scopes `current/stale/superseded/authorityLevel` prontos e não consumidos pelo recall (MAXH-09 consome as-of; nada consome linhagem descendente).
- `AtlasMemoryEntryUsage.php:93-158` — usage com trace/session/thread já persiste o substrato de co-recall (MAXJ-07 consome); `AtlasMemoryEntryRelation` (grafo, 0 rows) espera MAXH-03/04.

**Fronteira (técnica → veredito + porquê):**

| Técnica | Veredito | Porquê |
|---|---|---|
| Schemas tipados de conhecimento | **SIM (alavanca-mãe)** | Torna contradição detectável por campo, não por cosine; é o produtor que os 2 kernels estruturais (:516, :533) esperam desde o nascimento. Reuso puro = wiring. |
| Tiering episódica→semântica→procedural | **SIM** | MAXH-07 já faz muitas→1 (episódica→semântica de facto); falta o tier explícito com TTL/porta própria e o degrau →procedural (ASI-14 cria o tipo, não a decantação). |
| Revisão de crença em cascata | **SIM** | Superseder sem re-avaliar descendentes = apodrecimento silencioso; a linhagem ASI-11 existe exatamente para isso e hoje só serve rollback. |
| Verdade ponderada por proveniência | **SIM** | `resolveConflictAxis` já aceita `evidence_count` (:478) e o recall ignora evidência; claim com refs verificáveis empatando com claim declarado é defeito de régua. |
| Pressão de memória com orçamento | **SIM, com piso** | Sem pressão, MAXH-07 nunca dispara sozinho; cap por escopo força consolidação. Risco Goodhart alto ⇒ demoção-nunca-deleção pétreo + cap NUNCA é medidor de sucesso. |
| Memória de trabalho de OBRA | **SIM** | MAXE-06 é sessão (efêmero, seen-refs); obra/arco atravessa semanas e hoje re-hidrata do zero a cada retomada. Distinto por construção. |
| Co-recall persistente como estrutura | **NÃO como slice próprio** | MAXJ-07 já detecta co-recall medido e MAXH-07 já materializa cluster; persistir a aresta é subproduto dos dois — slice novo seria a 3ª porta para o mesmo grafo. Citado como colisão. |
| Interrogação ativa de lacuna | **SIM** | Recall vazio recorrente é o único sinal negativo barato num corpus com 11.519 positivos / 0 negativos; a memória aprende o que não sabe. |
| Snapshot semântico do corpus | **SIM (medidor)** | Sem fingerprint por escopo, regressão de conhecimento (deleção/veneno/drift) só aparece quando um recall falha em produção. |

**Slices:**

**MULTH-01 — Schemas tipados: campos estruturados que tornam contradição detectável por construção** `[ALAVANCA]` · E:M · onda M4 · deps: [ASI-02, MAXH-01, MAXH-03]
- Goal: `decision` carrega `{contexto, alternativas, porquê, expiry}`, `harness_learning`/gotcha carrega `{sintoma, causa, fix, versão}` — em coluna JSON `structured_facts` validada por schema-por-tipo, para que polaridade e ranges numéricos sejam comparáveis por campo.
- Mecanismo: schema map puro por tipo (config documentada), validação dentro de `normalize()` (`AtlasMemoryRegistryService.php:607`) — a MESMA porta ASI-02, jamais writer paralelo; extração fail-open (campo não-extraível ⇒ NULL, entry entra igual). MAXH-03 passa a alimentar `detectFactPolarityContradiction` (:516) e `detectNumericRangeOverlap` (:533) com os campos quando ambos os lados os têm. O que NÃO fazer: obrigar schema em write (mataria os 31 writers); LLM-extração no hot-path (extração é passe do digest, ASI-09 autora / gate julga).
- Aceite: par sintético com polaridade oposta em `structured_facts` ⇒ scanner emite `conflicts_with` **sem cosine acima do threshold** (prova que o campo, não o embedding, detectou); denominador = share de entries dos tipos schematizados com ≥1 campo não-NULL sobre actives do tipo (floor pinado no freeze, ex.: ≥0.4 em 30d — nunca contagem bruta); caso negativo: entry sem schema passa intacta (fail-open) e phpunit prova 0 write fora da porta.
- Risco: schema-teatro (campos preenchidos com lixo para subir coverage) — coverage NÃO é aceite; aceite é o par sintético detectado por campo + verdicts estruturais no ledger do MAXH-03.

**MULTH-02 — Verdade ponderada por proveniência: evidence-refs verificáveis pesam no recall e no conflito** · E:S/M · onda M4 · deps: [MAXH-05, MAXH-06, v1 EVI-01]
- Goal: claim com refs verificáveis no Evidence Ledger vence claim declarado — no ranking e no desempate de contradição.
- Mecanismo: `provenance_weight` puro = f(evidence_refs resolvíveis contra o ledger, verificados na cadência do digest — nunca no hot-path); entra (a) como multiplicador ao lado do `temporal_multiplier` de MAXH-05 (`AtlasHybridMemoryRetrievalService.php:234-236`), sob o MESMO piso composto recuperável; (b) como `evidence_count` REAL em `resolveConflictAxis` (`MemoryConflictAxisResolver.php:58`) — o campo existe e ninguém o computa de verdade. O que NÃO fazer: contar refs sem resolvê-las (ref morta = peso zero, não peso um); novo eixo de autoridade (authority>evidência>frescor de MAXH-06 fica intocado — isto só enche o eixo 2).
- Aceite: par A(com 3 refs resolvíveis)/B(declarado) mesmo escopo ⇒ A ranqueia acima com `provenance` no explain; ref apontando para evidence-id inexistente conta 0 (caso negativo executável); denominador = pares de conflito onde o eixo evidência foi DECISIVO / conflitos resolvidos (janela 30d, n≥8 ou `no_signal`); multiplicador floor pinado no freeze (nunca <0.5 — demoção, não ocultação).
- Risco: fabricação de refs para subir peso — só refs que RESOLVEM no ledger append-only contam; a resolução é do digest, author≠judge (ELEV-18).

**MULTH-03 — Revisão de crença em cascata: superseder dispara re-avaliação dos descendentes** · E:M · onda M4/M5 · deps: [MAXH-04, ASI-11, MAXH-06]
- Goal: quando A é superseded, tudo que foi derivado/decidido a partir de A entra em fila de re-avaliação — a verdade se propaga em vez de apodrecer nas folhas.
- Mecanismo: consumidor do evento de supersedência do atuador MAXH-04: caminha o grafo de linhagem ASI-11 (fecho descendente, profundidade cap pinada) e marca descendentes `needs_reverification` + bucket de review do digest — reuso direto do estado que MAXH-08 já cria, zero campo novo. Re-avaliação = re-rodar os kernels de MAXH-03 do descendente contra o SUPERSEDER; cascata decisiva e não-alto-risco ⇒ propõe pelo próprio atuador MAXH-04 (reversível+digest, zero fila humana). O que NÃO fazer: cascata de deleção (demoção nunca deleção); cascata síncrona no write (é passe do digest); disparar em `related` (só `supersedes`).
- Aceite: cadeia sintética A→B→C, supersede A ⇒ B e C ganham `needs_reverification` com `cascade_origin=A` no explain; profundidade além do cap NÃO propaga (caso negativo); denominador = descendentes re-avaliados / descendentes marcados na janela (série própria, versionada — MEM-03 45d intocada); ciclo no grafo termina (prova phpunit determinística).
- Risco: cascata-avalanche num grafo denso futuro — cap de profundidade E cap de itens/janela pinados; excesso vai ao bucket, nunca silêncio.

**MULTH-04 — Decantação em tiers: episódica → semântica → procedural com TTL e porta por tier** · E:M · onda M5 · deps: [MAXH-07, ASI-14, MAXH-08, ASI-02]
- Goal: outcomes crus (episódicos) decantam em lições (semânticas) que decantam em procedimentos (ASI-14) — cada tier com TTL próprio; o flat de hoje trata gotcha de 1 sessão e decisão pétrea com a mesma física.
- Mecanismo: coluna `tier` (episodic|semantic|procedural) derivada por mapa puro tipo→tier default em `normalize()` (:607) — aditiva, NULL = comportamento atual; a tabela TTL do MAXH-02 ganha a dimensão tier (episódica curta, semântica longa, procedural verificada-por-uso via OUTC-01). Promoção de tier = exatamente o pipeline MAXH-07 (síntese author≠judge) e ASI-14 — este slice NÃO cria promotor novo, cria o eixo que os dois estampam. O que NÃO fazer: 3 tabelas/3 portas (1 tabela, 1 porta ASI-02); auto-promover por idade (idade decanta TTL, promoção exige o gate do MAXH-07).
- Aceite: pós-backfill 0 actives com tier NULL; episódica além do TTL do tier demove no recall (via MAXH-05 — o aceite REAL, mesmo padrão anti-tautologia do MAXH-02) enquanto semântica do mesmo timestamp não demove; caso negativo: síntese rejeitada pelo gate NÃO muda tier; contagem por tier NUNCA é aceite (sobe por construção).
- Risco: tier virar rótulo cosmético sem física — o aceite exige diferença OBSERVÁVEL de ranking entre tiers no subset R8; sem diferença, slice reprovado.

**MULTH-05 — Pressão de memória com orçamento: cap de ativas por escopo → fila de decantação, nunca deleção** · E:S/M · onda M5 · deps: [MAXH-07, MULTH-04, MAXH-01]
- Goal: escopo acima do orçamento força consolidação (síntese/decantação) em vez de acúmulo — a pressão é o produtor contínuo que faz MAXH-07 disparar sem humano.
- Mecanismo: check no passe do digest: actives por (scope, tier) > budget pinado ⇒ os piores por (decayed_confidence de MAXH-08 × recall-hits) entram na fila de candidatos do MAXH-07/MULTH-04 e ganham demoção soft sob o piso composto de MAXH-05. Budget vive no registry de floors (ELEV-09), mudança = emenda. O que NÃO fazer: deletar/arquivar ao estourar (demoção nunca deleção — pétreo); tratar "ficar sob o budget" como métrica de sucesso (é gatilho, não medidor; o medidor da área segue MAXH-01).
- Aceite: escopo sintético com budget+K entries ⇒ exatamente K candidatos enfileirados, 0 rows deletadas/desativadas (assert no banco de teste); entry pressionada continua retornável com flag (piso composto); caso negativo: escopo sob budget ⇒ passe é no-op byte-idêntico; budget alterado sem emenda ⇒ teste-sentinela vermelho.
- Risco: Goodhart clássico (otimizar para caber no cap sintetizando lixo) — a síntese continua atrás do gate author≠judge do MAXH-07; taxa de rejeição do gate entra no digest como sinal, não como falha.

**MULTH-06 — Working set durável de OBRA: retomada re-hidrata o estado cognitivo inteiro** · E:M · onda M5 · deps: [MAXE-06, ASI-02, v1 COM-01]
- Goal: obra/arco tem working set persistente (decisões vigentes, gotchas, lacunas abertas, refs já vistos) distinto do de sessão — retomar obra após dias ≠ começar do zero.
- Mecanismo: agregado `obra_working_set` keyed por obra_id (a linhagem ASI-11 já estampa decision_id/obra_id nos landings — reuso): materializado no fim de sessão a partir do working set MAXE-06 + entries tocadas com aquele obra_id; re-hidratação injeta no pack via o namespace de refs existente do COM-01 (nenhum formato novo). Escritas de memória continuam 100% pela porta ASI-02 — o working set é ponteiro-para-entries, nunca 2ª cópia de conteúdo. O que NÃO fazer: duplicar corpo de entry no set (drift garantido); TTL infinito (set expira com a obra, arquivamento reversível).
- Aceite: sessão 2 da obra sintética recebe no pack ≥N refs do set da sessão 1 (N pinado) com `origin=obra_working_set` no ledger de entrega; denominador = retomadas com re-hidratação não-vazia / retomadas de obra com set existente; caso negativo: sessão SEM obra_id não re-hidrata nada (zero vazamento cross-obra); obra encerrada ⇒ set arquivado, pack limpo.
- Risco: pack inchado por set gordo — cap de refs por re-hidratação pinado; o corte usa o ranking vivo (MAXH-05/MULTH-02), não FIFO.

**MULTH-07 — Interrogação ativa de lacuna: o recall vazio recorrente vira candidato de captura** · E:S · onda M5 · deps: [MAXH-01, ASI-02, v1 MEM-02]
- Goal: a memória aprende o que NÃO sabe — query recorrente com recall vazio/fraco registra a LACUNA como candidato, o primeiro sinal negativo honesto num corpus com 11.519 positivos e 0 negativos.
- Mecanismo: no retorno do `AtlasHybridMemoryRetrievalService` (braço registry, pós-score :234-236), resultado vazio OU top-score < floor pinado grava evento leve `recall_gap` (query-hash normalizado, scope, score) — append-only, hot-path barato (1 insert). Passe do digest agrega: hash com ≥K ocorrências em janela ⇒ candidato `knowledge_gap` na fila NORMAL de captura (CaptureQualityGate + porta ASI-02, jamais auto-entry). O que NÃO fazer: gravar a query crua (privacy — hash+features); auto-criar memória da lacuna (candidato ≠ entry; o gate julga).
- Aceite: 3 recalls vazios da mesma query normalizada ⇒ 1 candidato agregado com `occurrences=3`; caso negativo: query única não gera candidato (K pinado ≥2) e recall bem-sucedido não grava gap; denominador = candidatos-lacuna que viraram entry aceita pelo gate / candidatos emitidos (janela 30d, n≥8 ou `no_signal`) — emissão de candidatos NUNCA é o medidor.
- Risco: enxurrada de gaps por queries-lixo — normalização + floor de ocorrências + cap de candidatos/janela; excesso é sinal no digest, não spam na fila.

**MULTH-08 — Snapshot semântico do corpus: fingerprint por escopo detecta drift/regressão de conhecimento** `[MEDIDOR]` · E:S · onda M5/M6 · deps: [MAXH-01, MAXA-02 (cache de embed), v1 WDG-01]
- Goal: regressão de conhecimento (perda, veneno, drift agressivo) detectada entre janelas — o wiper de 02/07 só foi percebido quando doeu; o fingerprint teria gritado no primeiro digest.
- Mecanismo: passe do digest computa por (scope, tier): contagens por tipo/estado temporal + centroid de embeddings (reuso do cache MAXA-02, zero embed novo) + histograma de decayed_confidence; grava snapshot append-only no Evidence Ledger. Check no watchdog (mesmo registry do MAXH-10): delta de centroid > τ pinado OU queda de actives > p% sem tombstones/supersedências correspondentes ⇒ alerta no digest. Só LÊ — nunca atua. O que NÃO fazer: snapshot virar gate de write (é sismógrafo, não porta); comparar snapshots cross-formula_version (versionar, séries nunca misturadas).
- Aceite: deleção sintética de 20% de um escopo num banco de teste ⇒ check dispara com o escopo nomeado; caso negativo: churn normal (adds+supersedências balanceadas) NÃO dispara; corpus < N_min ⇒ `no_signal` (nunca "estável" por vazio); τ e p congelados no freeze do MAXH-01, mudança = emenda.
- Risco: falso alarme em semana de consolidação pesada (MULTH-05 ativa) — o check desconta supersedências/sínteses ledger-provadas antes de comparar; alarme residual é review, nunca bloqueio.

**Ordem sugerida (MULTH):** MULTH-01 (schemas — destrava os 2 kernels dead-fed e engorda o scanner MAXH-03) → MULTH-02 (proveniência — régua de peso antes dos atuadores que a consomem) → MULTH-07 (lacunas — sinal negativo barato, independe do resto) → MULTH-03 (cascata — exige MAXH-04 vivo + linhagem ASI-11) → MULTH-04 (tiers — exige MAXH-07/ASI-14 girando) → MULTH-05 (pressão — só faz sentido com decantação pronta) → MULTH-06 (working set de obra) → MULTH-08 (snapshot — fecha a área com o sismógrafo).

**Colisões MULTH com v1/Max/ASI:**
- **Co-recall persistente NÃO virou slice**: MAXJ-07 já detecta pares co-recallados medidos e MAXH-07 já materializa cluster canônico — um "cluster navegável" próprio seria a 3ª porta para o mesmo grafo de relação. MULTH nada escreve em `atlas_memory_entry_relations` fora do atuador MAXH-04.
- **MULTH-01 não re-propõe ASI-14**: procedural como TIPO entra por ASI-14; MULTH-01/04 só dão campos estruturados e o eixo de decantação — o tipo é dep, não entrega.
- **MULTH-02 estende a cadeia de multiplicadores de MAXH-05/08** sob o MESMO piso composto — nunca um caminho de demoção paralelo.
- **MULTH-04/05 usam a tabela TTL do MAXH-02 e o pipeline do MAXH-07** — zero promotor/TTL paralelo; budgets e floors novos entram no registry do ELEV-09 (teste-sentinela, mudança = emenda).
- **MULTH-07 alimenta a fila de captura EXISTENTE (v1 MEM-02 + CaptureQualityGate)** — não cria via de ingestão nova; ELEV-08 (4 vias da porta única) permanece verdadeiro.
- **MULTH-08 pluga no registry de checks do MAXH-10/WDG-01** e respeita medidores congelados (MEM-03 45d intocada; snapshot é série NOVA versionada).
- **ELEV-21 (corpus ≥300)**: MULTH-05/07 aceleram qualidade gated do corpus, mas contagem segue NUNCA sendo aceite — os denominadores são taxas gated, não volume.

---

#### Área 17 — Originação & Ambição (MULTN17) — quem decide o expoente

**Teto atual (relido no código em 12/07/2026):**
- **Origina task AVULSA, cega ao que rendeu:** `AtlasLoopOriginationPipeline::produce()` (`app/Services/Ai/AutonomousEvolution/AtlasLoopOriginationPipeline.php:45`) retorna 1 objective + 1 target (`:124-132`); NÃO importa `PathYieldEwma` (rg no arquivo = 0 — MAXN-04 é quem liga); leverage-first gated OFF (`config/atlas.php:3466`). Os únicos sinais de história são demotions binárias queue-aware (`:186-188`) e refusal-aware (`:193-198`).
- **O yield que existe é ACCEPT-cru:** `AtlasBrainPathYieldEwma.php:40` ancora a amostra em `result_kind === 'accepted'` (1.0/0.0) — nem quando MAXN-04 ligar o consumo o sinal atual é proven_real; o re-anchor em OUTC-01 é parte do dep, não deste catálogo.
- **Ambição PARCIAL já construída, dormente:** `AtlasLoopAmbitionDecider` (`Discovery/AtlasLoopAmbitionDecider.php:36`, score = magnitude·P^riskTolerance − cost, magnitude UNCAPPED) + dial "the rung grows" `capability_ambition_enabled` (`config/atlas.php:3478`, default OFF, wirado via `AtlasLoopHeavyWorkSelector.php:98`/`CapabilityTrendService`) + `AtlasBrainEvolutionLevelClassifier` (3 níveis: rejected_proxy/evolucao/patamar). Não existe DEGRAU explícito task→slice→obra→salto nem regra de subida.
- **Obra composta é detector reativo, não composição do cérebro:** `AtlasLoopQueueRefiller.php:473-485` agrupa clusters (`obra_cluster_detection_enabled`, `config/atlas.php:2714` OFF; ranking `:3331` OFF) como candidates de operator-review — ninguém compõe arco com tese/conclusão/kill-gate.
- **Saturação é binário fila-vazia:** `origination_on_starvation_enabled` (`config/atlas.php:3458` OFF) consumido em `AtlasLoopCampaignSupervisor.php:929` — origina só quando `queue_starved`; yield reativo em queda com fila cheia não dispara nada (`AtlasBrainOriginationGapDetector`/`AtlasBrainPathStarvationDetector` existem, não disparam widen).
- **O mapa de alavancagem mais rico não alimenta a origem:** `docs/engineering-knowledge-base/atlas-open-gaps-regressions-ledger.md` tem ZERO consumidores programáticos em `app/` (rg = 0); nenhum `predicted_impact` em toda AutonomousEvolution (rg = 0); zero sinal de fricção do operador (tabelas `operator_*` inexistentes até MAXN-01).

**Fronteira (técnica → veredito + porquê):**

| Técnica | Veredito | Porquê |
|---|---|---|
| Escada de ambição explícita (task→slice→obra→salto) | **ADOTAR** | Os órgãos já existem desligados (AmbitionDecider uncapped, dial :3478, LevelClassifier) — falta o conceito de degrau + regra de subida por saturação de yield. Reuso, não segundo dial. |
| Originação de OBRAS COMPOSTAS (arco com tese+kill-gate) | **ADOTAR** | produce() só sabe task avulsa; o cluster-detector reativo vira LEAD do composer, nunca o autor. |
| Scanning de oportunidade multi-fonte | **ADOTAR** | Gaps ledger com prova por entrada + órgãos-0-caller + séries regredindo + ponytail-debt = alavancagem de EVIDÊNCIA parada; 100% local, zero colisão com o fetcher externo MAXN-05. |
| predicted_impact vs realized (calibração do originador) | **ADOTAR** | Padrão ASI-15 pronto (`CalibrationBandClassifier`); banda DERIVADA, report-only. Sem previsão o originador não aprende o próprio julgamento. |
| Portfólio de apostas + gate de continuação | **ADOTAR com dono dividido** | Colide com MULTK-06 (Área 10 aloca ENTRE classes) — aqui só a mecânica de apostas DENTRO da fatia originada, com ELEV-28. |
| Teses-de-visão de longo horizonte | **ADOTAR com cerca pétrea** | Originação deixa de ser amnésica por-ciclo, mas risco máximo de "fronteira semeada por humano" — tese só DERIVA de séries/ledger/outcomes; doc canônico é peso, jamais fonte do texto. |
| Auto-detecção de saturação do reativo | **ADOTAR** | Upgrade do binário `queue_starved` para sinal derivado de séries com histerese — antecipa o widen ANTES de secar. |
| Dogfooding loop | **ADOTAR gated por MAXN-01** | A fonte de problema mais honesta, mas hoje 0 sinais (tabelas fantasma). Fricção OBSERVADA = evidência/peso; instrução do operador = jamais semente. |

**Slices:**

**MULTN17-04 — Banda predicted_impact derivada + curva declarado-vs-realizado do originador** `[MEDIDOR]` · E:M · onda M1 (freeze) → M4 (curva) · deps: [ASI-08, v1 OUTC-01, ASI-15 (padrão)]
- Goal: o originador declara zero expectativa (rg `predicted_impact` = 0) — sem previsão não há calibração do próprio julgamento de grandeza.
- Mecanismo: `produce()` estampa banda `predicted_impact` DERIVADA (função pura de {rung, posição no ranking leverage, yield do path} — nunca free-text do writer, lição dos booleans forjáveis); job ex-post casa banda × realized (proven_real OUTC-01 + tamanho do delta landado) reusando `CalibrationBandClassifier.php` (padrão ASI-15); publica curva por banda com n cru. NÃO fazer: banda influenciar o pick antes da curva provar sinal (report-only); escalar único.
- Aceite: banda é função pura (property-test); medidor ON ⇒ pick byte-idêntico (report-only); após ≥20 originações reais, curva por banda com denominador exposto; task nunca-servida cai em `unresolved`, nunca conta acerto (caso negativo); limiar de desvio |declarado−realizado| carimbado no freeze (ELEV-03); colapso de distribuição (originador só prevendo "baixo" para acertar) é alarmado, nunca meta.
- Risco: gaming por previsão covarde — a distribuição de bandas é vigiada junto com a curva.

**MULTN17-03 — Scanner de oportunidade multi-fonte (alavancagem derivada de EVIDÊNCIA)** `[PRODUTOR]` · E:M · onda M4 · deps: [ASI-08, MAXN-04 (ranking que consome)]
- Goal: os candidates vêm de 1 fonte (`AtlasLoopComprehensionOriginationCandidates`: orphan/clone/doc-gap); gaps ledger (0 consumidores em `app/`), órgãos-0-caller, séries com derivada negativa e `ponytail:`-debt não alimentam `produce()`.
- Mecanismo: leitor determinístico local (zero network — não duplica MAXN-05) que agrega {entradas ABERTAS do ledger com prova, 0-caller organs via inventário, séries regredindo via `AtlasBrainMetricSnapshot`/`HealthScoreLedger`, ponytail-debt} em LEADS tipados {fonte, evidence file:linha, classe}; entram no MESMO ranking leverage-first (`AtlasLoopOriginationPipeline.php:157` — o selector reordena, nunca fabrica). NÃO fazer: lead sem evidência verificável; o scanner escrever no ledger; segundo ranking paralelo.
- Aceite: com o ledger real, ≥1 lead com evidence que resolve (is_file/rg>0; evidência morta ⇒ drop com contador exposto); `produce()` pode escolher um lead (consumidor vivo — ELEV-20); fixture entrada FECHADA ⇒ nunca vira lead (caso negativo); flag OFF ⇒ byte-idêntico.
- Risco: ledger stale envenenar a origem — validação de evidência na leitura, drop honesto e contado.

**MULTN17-07 — Saturação do reativo por SÉRIE (widen antecipado, com histerese)** `[DECISÃO]` · E:S · onda M4 · deps: [ASI-08, MAXN-04]
- Goal: o gatilho de "reativo secou" é fila-vazia binário (`config/atlas.php:3458` → `AtlasLoopCampaignSupervisor.php:929`); yield reativo desabando com fila ainda cheia não dispara widen — o cérebro segue moendo casca de baixo valor.
- Mecanismo: sinal `reactive_saturated` derivado: EWMA de yield proven do lane reativo em queda por N janelas com n mínimo (compõe `PathYieldEwma` pós-re-anchor MAXN-04 + `PathStarvationDetector`/`OriginationGapDetector`); satura ⇒ `produce()` prioriza originado ANTES da fila zerar. Demote, nunca exclude (padrão da própria pipeline, `:232`). NÃO fazer: desligar o lane reativo; limiar ajustável ex-post.
- Aceite: fixture fila cheia + yield em queda (n≥N pinado no freeze, ELEV-03) ⇒ `reactive_saturated=true` e pick prefere originado com leverage igual; yield estável ⇒ false + byte-idêntico; tail insuficiente ⇒ `insufficient_n`, nunca satura (caso negativo); histerese com janelas de entrada/saída distintas (anti-oscilação testado).
- Risco: flapping — histerese; saturar no vácuo — n mínimo sobre proven_real.

**MULTN17-01 — Escada de ambição explícita: rung {task, slice, obra, salto} + subida por saturação de yield** `[DECISÃO]` · E:M · onda M4 · deps: [MAXN-04, MULTN17-07, ASI-08]
- Goal: o expoente vive aqui — hoje não há conceito de degrau: `AtlasLoopAmbitionDecider.php:36` pontua magnitude por candidato e o dial `:3478` está OFF sem escada para subir.
- Mecanismo: classifier determinístico rung ∈ {task, slice, obra, salto} derivado de {blast radius, nº de arquivos, work-type} — REUSO de `AtlasBrainEvolutionLevelClassifier` estendido, não segundo classifier; `produce()` carimba o rung; regra de subida: yield proven do rung atual saturado (sinal N17-07 por-rung) ⇒ preferência ordinal por rung+1 no pick. "Tamanho médio do degrau originado" vira série INFORMATIVA no digest — nunca meta, nunca termo somável de score. NÃO fazer: teto de rung (escopo SEM TETO); rung como escalar no score; segundo dial de risco (o dial `:3478` é o consumidor natural).
- Aceite: fixture yield do rung task saturado (n≥N, limiar ELEV-03) ⇒ produce() prefere candidato rung slice/obra com leverage igual (delta isolado); tail vazio/flag OFF ⇒ pick byte-idêntico; série de rung publicada com denominador; caso negativo: forçar a série de rung como input de score falha teste-invariante (anti-Goodhart executável).
- Risco: subir degrau no vácuo — subida exige saturação PROVADA, nunca calendário; Goodhart no tamanho — série informativa com teste-invariante.

**MULTN17-08 — Dogfooding: fricção real do operador vira lead prioritário** `[PRODUTOR]` · E:M · onda M4 (tardio) · deps: [MAXN-01, MAXN-02, MULTN17-03]
- Goal: a fonte de problema mais honesta — o Atlas falhando em servir (override repetido, capture-fail, gap operator-facing tipo GAP-HERMES-01) — não alimenta a originação; hoje o sinal é 0 por construção (tabelas `operator_*` inexistentes, MAXN-01).
- Mecanismo: leitor sobre sinais REAIS pós-MAXN-01 ({override/ignore de `operator_learning_signals`, contadores de capture-fail, entradas operator-facing do gaps ledger}) que emite leads dogfooding com evidência e CLASSE prioritária no scanner N17-03 — peso alto, jamais semente: o cérebro ainda origina objective+target do código; a fricção é evidência, não spec. NÃO fazer: transformar instrução do operador em semente (fricção OBSERVADA ≠ pedido); ler classe secret (G0–G8 via porta única).
- Aceite: fixture override repetido (n≥N por assinatura de fricção) ⇒ lead dogfooding com evidência; produce() o escolhe sobre lead igual não-dogfooding (delta isolado); teste pétreo: nenhum texto do operador aparece no objective (objective gerado do alvo de código, assertado por fixture); MAXN-01 ausente ⇒ degrade `no_signal_source`, nunca fabrica.
- Risco: enviesar por dia ruim do operador — n mínimo por assinatura; virar fila humana disfarçada — charter 06/07 intacto (lead entra no ranking, zero aprovação).

**MULTN17-02 — Originação de OBRA COMPOSTA: arco com tese, critério de conclusão e kill-gate** `[DECISÃO]` · E:L · onda M5 · deps: [MULTN17-01, MAXN-04, MULTN17-03]
- Goal: `produce()` só sabe task avulsa (`:124-132`); o detector existente (`AtlasLoopQueueRefiller.php:473-485`, flags OFF) apenas AGRUPA alvos reativos para review — o cérebro nunca compõe um arco originado com começo, meio e morte.
- Mecanismo: composer que junta N candidates grounded vizinhos (reuso `AtlasBrainOrganDependencyGraph` para vizinhança; cluster-detector reativo entra como LEAD) num arco {tese falsificável, tasks ordenadas, critério de conclusão executável, kill-gate objetivo: K falhas consecutivas ⇒ arco arquivado com receipt}; CADA task do arco passa individualmente pelo `ArchitectPhaseGate` + seed-gate (o arco jamais compra gate por atacado); pouso continua commit escopado na main, nunca merge de obra. NÃO fazer: arco sem kill-gate; bypass de gate por pertencer a arco; auto-merge.
- Aceite: fixture 3 candidates vizinhos ⇒ 1 arco serializado com tese+conclusão+kill-gate; kill-gate dispara com K falhas (caso negativo) e arquiva com receipt, tasks restantes nunca servidas; task reprovada no seed-gate NÃO derruba o arco (independência testada); flag OFF ⇒ zero arcos + produce() byte-idêntico; author≠judge: engine que autora a tese ≠ engine que certifica conclusão (ELEV-18).
- Risco: arco zumbi ocupando a fila — kill-gate + TTL; tese grandiosa fake — tasks continuam sob o mesmo floor red→green/cert individual.

**MULTN17-05 — Portfólio de apostas exploratórias com gate de continuação (dobra na provada)** `[DECISÃO]` · E:M · onda M6 · deps: [MAXN-04, MULTK-06, ELEV-28, AtlasBrainCausalEffectGate (reuso)]
- Goal: exploração hoje é acidente (yield desconhecido apenas "não é despriorizado", MAXN-04) — não uma política de k apostas pequenas com dobra na que prova e suspensão da refutada.
- Mecanismo: DENTRO da fatia "originado" alocada por MULTK-06 (dono dos pesos entre classes — este slice NÃO recomputa alocação nem escreve pesos, MAXK-07), sub-política: k apostas rung-task em paths de yield desconhecido; aposta prova (reuso `AtlasBrainCausalEffectGate::effect()` — CI exclui zero, `MIN_N=5`, `admit_compounding=true`) ⇒ peso do path sobe na próxima janela com receipt; efeito negativo provado ⇒ família entra `suspended_pending_evidence` (estado ELEV-28 no ledger de gaps), nunca deletada. NÃO fazer: contar aposta por landing/accept (só proven_real); suspender por `insufficient_n` (vácuo ≠ refutação).
- Aceite: fixture CI>0 ⇒ dobra com receipt; CI<0 com n≥MIN_N ⇒ suspensão registrada; suspenso reentra quando a evidência vira (caso de retomada testado); `insufficient_n` ⇒ continua explorando (caso negativo); flag OFF byte-idêntico; k e a janela carimbados no freeze (ELEV-03).
- Risco: colisão de dono com a Área 10 — fronteira escrita: K-06 aloca ENTRE classes, N17-05 aposta DENTRO da fatia originada.

**MULTN17-06 — Teses-de-visão persistentes derivadas de evidência (originar contra a tese)** `[PRODUTOR]` · E:M · onda M6 · deps: [MULTN17-03, MULTN17-04]
- Goal: a originação é amnésica por-ciclo — cada `produce()` re-decide do zero; não existem 1–3 teses persistentes ("o M vaza no estágio X há N semanas") contra as quais originar em vez de só reagir.
- Mecanismo: composer que DERIVA ≤3 teses de evidência agregada (leads N17-03 + séries + curva N17-04) — **cerca pétrea: a tese cita séries/outcomes/file:linha, nunca texto de doc ou do operador; canônico entra só como peso de lead**; tese = {claim falsificável, evidência, critério de morte escrito no nascimento, TTL}; teses ativas entram em `produce()` como CONTEXTO de reordenação (peso, nunca veto nem fabricação); morte pelo critério ⇒ arquivada com receipt. NÃO fazer: tese eterna; tese autorada de texto humano; 4ª tese.
- Aceite: fixture série regredindo 4 janelas ⇒ tese com evidência citada; candidato alinhado sobe sobre não-alinhado com leverage+yield iguais (delta isolado, padrão do aceite MAXN-06); critério de morte satisfeito ⇒ arquivamento + reordenação desaparece (caso negativo); teste pétreo: fonte de cada campo da tese é enumerada {série, ledger, outcome} — string de input do operador presente ⇒ teste falha.
- Risco: tese virar túnel — máx 3 + TTL + critério de morte obrigatório; tese-narrativa — claim precisa ser falsificável por série nomeada.

**Ordem sugerida (MULTN17):** MULTN17-04 (M1 — a régua congela antes de qualquer consumidor) → MULTN17-03 + 07 (M4) → MULTN17-01 (M4 — escada consumindo o yield que MAXN-04 ligou) → MULTN17-08 (M4 tardio, gated por MAXN-01/02) → MULTN17-02 (M5 — obra composta sobre escada assentada) → MULTN17-05 + 06 (M6).

**Colisões MULTN17 com v1/Max/ASI (+ regras pétreas da faculdade de ambição):**
- **MAXN-04/05/06 são deps, não re-propostos:** N17-01/05/07 consomem o yield que MAXN-04 liga (e herdam o re-anchor proven_real — o EWMA atual é accept-cru, `PathYieldEwma.php:40`); N17-03/06/08 são 100% locais e não duplicam o fetcher externo MAXN-05.
- **MULTK-06 (Área 10) é o dono da alocação ENTRE classes com pesos operator-authored (MAXK-07):** N17-05 opera só DENTRO da fatia originada e nunca escreve pesos.
- **ASI-08 é pré-condição de combustível** para N17-01/04/05/07: tail/ledger vazio ⇒ degrade byte-idêntico com refusal honesto (`insufficient_n`), nunca fabricação de sinal.
- **Medidores v1 congelados intocados;** limiares carimbados no freeze (ELEV-03); todo freeze grava judge≠author (ELEV-18); promoção via ELEV-26; produtor+consumidor vivos (ELEV-20).
- **Faculdade de ambição pétrea preservada:** nenhum teto de escopo (a escada não tem último degrau); teses/leads/fricção/canônico são PESO ou LEAD — a semente é sempre originada pelo cérebro (testes pétreos executáveis em N17-06 e N17-08); refuse-rate/landing-rate seguem INFORMATIVOS; yield sempre proven_real.
- **Reuso antes de construção (verificado):** AmbitionDecider + dial `:3478` + EvolutionLevelClassifier (N17-01); ObraClusterDetector como lead (N17-02); CausalEffectGate (N17-05); CalibrationBandClassifier (N17-04); Starvation/GapDetector (N17-07).
- **Charter 06/07 e Loop-morto:** zero fila humana; tudo pousa em `atlas:brain:*`/`atlas:task:*` (`AtlasBrainNextCommand.php:131` é o call-site de `produce()`); nenhuma superfície `atlas:loop:*` tocada.

---

#### Área 12 — Execução Verificada & Qualidade (MULTV) — o multiplicador que cresce com N

**Teto atual (relido no código em 12/07/2026):**
- AVCEL prova ESTRUTURA em shadow: os checks são `eight_stage_loop_present` / `must_keep_coverage_full` / `read_only_shadow_policy` (`app/Services/Ai/VerifiedContextExecution/AtlasVerifiedContextExecutionLoopService.php:160-180`, schema shadow `:19`); a superfície só expõe `certify|shadow` (`AtlasVerifiedContextExecutionLoopCommand.php:13`) — **não existe modo enforce nem seam onde um enforce morderia**.
- A porta de land roda ZERO testes por design (`app/Console/Commands/AtlasLandCommand.php:24`) e os guards de pressão são advisory fail-open (`:79-93`) — o `verified_share` (ELEV-12) no ponto de land é estruturalmente ~0 hoje.
- Os órgãos de uma cascata JÁ existem, disjuntos e sem composer: pregate estático ≤3s (`AtlasPregateCommand.php:34`), seleção de teste impactado advisory (`AtlasTestImpactedCommand.php:24,31`), skip por receipt verde-fresco com hash (test,impl) (`AtlasTestCachedCommand.php:12-20`), golden manifests genéricos (`AtlasGoldenCommand.php:26,91`), e suíte REAL em worktree descartável (`AtlasRepoVerifiedDeliveryService.php:21-27,87`) com **1 caller** (`AtlasEngineeringDeliverCommand.php`).
- Mutation testing COMPLETO e semi-dormente: infection ^0.33.2 (`composer.json:18` + `infection.json5`), `MutationScoreGate` com threshold 60.0 (`Mutation/MutationScoreGate.php:82`), wirado SÓ no pipeline Dev (`PipelineRunExecutor.php:819-834`) com safe-default advisory (`ElevationConfig.php:50`); no lado Autônomos o kill-ratio floor default é 0.0 = OFF (`config/atlas.php:3059`).
- Oráculo de regressão + quarentena existem em EMBRIÃO com 1 call-site: `RegressionLockWriter::lockRepairedFailure` (3 greens consecutivos ⇒ locked; any red ⇒ quarantined; dedupe por signature — `RegressionLock/RegressionLockWriter.php:30,60`, `RegressionLockLedger.php:23-25,35`) chamado só de `AtlasRepoVerifiedDeliveryService.php:234`; não existe série de flake por test_ref nem estado de quarentena consumido por gate.
- OutcomeProofGate valida a evidência APRESENTADA (`OutcomeProofGate.php:39`, marker `:28`, consumo `EliteExecutorKernel.php:513-521`) — **nada re-executa o landing para conferir a alegação**; `acceptance_criteria` é obrigatório no packet (`AgentControlPlaneTaskQueueOrchestrator.php:558`; Forge `ForgeWorkPacketExecutionCycleService.php:184`) mas é prosa que nunca vira teste executável.

**Fronteira (técnica → veredito + porquê):**

| Direção | Veredito | Porquê |
|---|---|---|
| Cascata de verificação em camadas | **ACEITA como WIRING** | Os 5 órgãos existem como comandos disjuntos; o que falta é o composer com receipt único — reconstruir qualquer camada violaria reuso. → MULTV-02 |
| Mutation testing como prova da suíte | **ACEITA como WIRING/PROMOÇÃO** | Stack Infection inteiro já vive (E3 advisory, Dev-only; Autônomos floor 0.0). Fronteira = série de poder-de-detecção + cobertura dos 3 executores + promoção ELEV-26s. → MULTV-03 |
| Replay determinístico de diff em worktree limpo | **ACEITA como WIRING** | A máquina de worktree descartável existe com 1 caller; o replay do diff LANDADO (pós-land, amostrado) não existe — é a única prova contra evidência fabricada. → MULTV-04 |
| Contratos de runtime (pré/pós fail-closed) | **ACEITA REDUZIDA** | NÃO como framework DbC genérico (zero consumidores = YAGNI); SIM como extensão do padrão FalseClaimInvariant a 4 seams pinados. → MULTV-06 |
| Oráculos de regressão minerados de falhas reais | **ACEITA como WIRING** | RegressionLockWriter/Ledger prontos, presos a 1 call-site; universalizar a mineração para os 3 executores. → MULTV-05 |
| Quarentena de teste flaky | **ACEITA como EXPANSÃO** | Quarentena existe SÓ na admissão de locks novos; flake_rate por test_ref e estado de gate não existem. → MULTV-07 |
| Verificação proporcional ao risco | **ACEITA FUNDIDA em MULTV-02** | Risk-tier DERIVADO de sensores que já existem (MigrationSafetyProbe, PressureLayerGuards); classificador sem cascata seria medidor sem consumidor (ELEV-20). |
| Spec-to-test binding (TDD de máquina) | **ACEITA** | `acceptance_criteria` já é obrigatório e é prosa morta; compilador determinístico + probe vermelho-antes. → MULTV-08 |
| Golden traces de CLIs críticos | **ACEITA como WIRING** | `atlas:golden freeze\|check` é o organ genérico pronto; falta o corpus de traces e o consumo no tier alto. → MULTV-09 |
| *(derivada da releitura)* O flip ASI-10 não tem onde morder | **ACEITA** | `atlas:land` roda zero testes e AVCEL shadow só prova estrutura — o seam de enforce precisa EXISTIR antes do flip. → MULTV-10 |

**Slices:**

**MULTV-01 — Receipt unificado de verificação por landing (a régua da profundidade)** `[MEDIDOR]` · E:S/M · onda M4 · deps: [v1 OUTC-01, ELEV-12, ELEV-20s, MED-01]
- Goal: cada landing ganha UM receipt `verification_receipt.v1` registrando QUAIS camadas rodaram (estático/impactado/cached/suite/mutation/golden/replay), veredito e duração por camada — sem isso, o `verified_share` (ELEV-12) tem numerador cego a profundidade ("sob enforce" sem saber o que rodou).
- Mecanismo: agregador que consome os receipts que JÁ existem — pregate, impactado, test_run_receipts do cached, golden — e appenda 1 linha por landing (chaveada no commit do committer escopado) em série própria versionada, selada por `KernelEvidenceAuthority::sealOutcome` (`KernelEvidenceAuthority.php:453`). `layers_run[]` é DERIVADO da presença dos receipts, nunca setado pelo caller. NÃO fazer: comando de verificação novo; tocar séries v1; camadas self-declared.
- Aceite: após **≥20 landings reais**, série com ≥20 linhas, `layers_run` derivado e seal verificável (`verifyOutcome`, `:461`); landing sem verificação aparece como `layers_run=[]` (o zero é VISÍVEL); caso negativo: receipt sem seal ⇒ reprova; série no registry ELEV-20s (ttl 7d); phpunit nunca escreve na série viva.
- Risco: contar camadas virar vaidade — profundidade nunca é meta, é insumo do tier; caller declarar camada — derivação por receipt-presence mata.

**MULTV-02 — Cascata risk-tiered: o composer dos órgãos P** `[CERTIFICADOR]` · E:M · onda M5 · deps: [MULTV-01, ELEV-26s, ELEV-03]
- Goal: as camadas viram UMA cascata com profundidade DERIVADA do risco do diff — hoje é o executor quem escolhe (ou não) cada comando.
- Mecanismo: `atlas:verify:cascade <paths>` compõe os comandos existentes em tiers: T0 estático → T1 impactado+cached → T2 suíte real em worktree (reuso `AtlasRepoVerifiedDeliveryService.php:87`) → T3 golden traces + mutation (MULTV-09/03). Risk-tier DERIVADO por sensores existentes: migration destrutiva ⇒ `MigrationSafetyProbe` (`:48-61`) força T2+; diff fora do blast-radius ⇒ `PressureLayerGuards` (`:54-55,159`) força T2; lista de seams críticos pinada no freeze. Mapa tier→camadas carimbado no freeze (ELEV-03): low=T0-T1, standard=T2, irreversible=T3; mudança = emenda. Resultado gravado no receipt MULTV-01. NÃO fazer: re-implementar camada; downgrade de tier por flag do caller (upgrade manual ok); bloquear fluxo antes de ELEV-26s promover.
- Aceite: diff com migration destrutiva ⇒ receipt `risk_tier=irreversible` com sensor nomeado + T2+ exigido; diff docs-only ⇒ T0 com p95 ≤5s (pinado); caso negativo: downgrade por flag ⇒ recusado com razão; **≥30 execuções reais** em advisory antes de qualquer promoção; advisory não muda exit code de nenhum fluxo existente.
- Risco: cascata lenta incentivar bypass — T0/T1 com orçamento pinado em segundos e o cached-skip já dá ~0s; sensor ausente ⇒ tier default `standard`, nunca `low` (fail-safe na direção certa).

**MULTV-03 — Mutation como prova de que a suíte DETECTA (série de poder + promoção governada)** `[MEDIDOR]` · E:M · onda M5 · deps: [MULTV-01, ELEV-26s, ELEV-28, MED-01]
- Goal: suíte que não mata mutante não verifica nada — e o Atlas já tem o stack inteiro parado em advisory/Dev-only; o slice é série + cobertura dos 3 executores, nunca reconstrução.
- Mecanismo: (a) série `suite_detection_power` (MSI real por família de suíte tocada, do `MutationTestingResult` — nunca self-declared, contrato VAL-E3-007 de `MutationScoreGate.php:14`) appendada dos runs E3 existentes + runs amostrados no T3 da cascata; (b) Forge/Autônomos ganham o MESMO adapter escopado a touched-files (`MutationScope.php:26`, pcov por LCA `:75-83`) amostrado 1/N landings (N=10 pinado no freeze), background, nunca hot-path; (c) promoção advisory→enforce POR EXECUTOR via ELEV-26s, com A/B raiz: MSI-baixo-advisory correlacionado (rotulado correlacional) com falha real posterior na janela; negativo ⇒ família suspensa (ELEV-28). NÃO fazer: subir threshold para a série "melhorar"; enforce sem A/B; mutation no turno interativo.
- Aceite: série com **≥8 amostras reais por executor** OU `insufficient_signal`; threshold 60.0 e N=10 carimbados no freeze; caso negativo: suíte que não mata mutante ⇒ MSI baixo reportado + flag `mutation_score_below_threshold` presente; flag OFF ⇒ byte-idêntico (herda contrato E3).
- Risco: custo de infection — escopo por touched-files já resolvido + amostragem; Goodhart de MSI — MSI é prova de suíte, nunca meta de originação.

**MULTV-04 — Replay determinístico do diff landado (caçador de fake-green pós-land)** `[CARTÓRIO]` · E:M · onda M6 · deps: [MULTV-01, MULTV-02, MULTV-07, ASI-11 (linhagem), SUB-01]
- Goal: re-executar em worktree limpo a verificação do landing JÁ landado e comparar com o que o executor alegou — o OutcomeProofGate valida a evidência apresentada (`OutcomeProofGate.php:39`), nada confere se a evidência corresponde à realidade.
- Mecanismo: job background amostrado (1/N landings, N pinado; 100% para tier `irreversible`) que reusa a máquina de worktree descartável (`AtlasRepoVerifiedDeliveryService.php:87` — detached HEAD, vendor symlink, cleanup em finally; herda o sandbox floor pétreo) no commit landado, roda T1/T2 da cascata e diffa o veredito com o receipt MULTV-01. Discordância (verde alegado, vermelho estável no replay) ⇒ evento `replay_divergence` no ledger + `FAKE_GREEN_MARKER` no candidato de learning (`OutcomeProofGate.php:28`) + demoção do outcome pela via existente. Report-only sobre o LANDING: reverter é sempre ASI-11/ROL-01. NÃO fazer: replay síncrono no land; segundo worktree-manager; revert automático.
- Aceite: fixture com evidência forjada ⇒ `replay_divergence` gravado + outcome demovido de `proven_real`; **≥10 replays reais** na janela com taxa de divergência publicada com denominador cru; caso negativo: landing honesto ⇒ replay concorda e NADA muda; replay jamais toca o repo vivo (guard de path/connection — classe do incidente wiper).
- Risco: flake lido como fraude — divergência só com vermelho ESTÁVEL (3 re-runs, probe MULTV-07); custo — amostragem + fila de background.

**MULTV-05 — Mineração universal de oráculos de regressão (toda falha real vira teste permanente)** · E:S/M · onda M4/M5 · deps: [v1 OUTC-01, MULTV-07]
- Goal: o órgão existe preso a 1 call-site — `RegressionLockWriter` (3 greens ⇒ locked; red ⇒ quarantined; dedupe) só é chamado do delivery worktree; falhas reparadas no Dev, no Forge e nos landings Autônomos morrem sem virar oráculo.
- Mecanismo: WIRING puro: os 3 caminhos de outcome FAILED→repaired real submetem `{failure_signature, failing_case, locked_test_ref}` ao MESMO writer (`RegressionLockWriter.php:30`) — Dev via pipeline, Forge via ciclo de packet, Autônomos via seam de report do task loop (o dado nasce server-side, nunca no claim do worker). Probe de estabilidade em worktree descartável. Locks entram no T2 da cascata via seleção impactada (só os relacionados ao diff rodam). NÃO fazer: segundo ledger; lock de falha não-reproduzida (o probe é o gate); auto-fix de teste (TEST_WRONG→never-autofix permanece pétreo).
- Aceite: janela com **≥5 falhas reparadas reais cross-executor** ⇒ ≥5 candidatos submetidos, cada um `locked|quarantined` no ledger (silêncio nunca); mesma signature 2× ⇒ 1 lock (dedupe); caso negativo: candidato instável ⇒ `quarantined`, JAMAIS entra na suíte como verde; denominador `locked/candidatos` publicado — submissão nunca é o medidor.
- Risco: suíte de locks crescer sem fim — consumo via impactado limita o custo por diff; poda só por supersedência com receipt, nunca deleção silenciosa.

**MULTV-06 — Contratos de runtime nos 4 seams críticos (invariantes fail-closed, não framework)** · E:M · onda M6 · deps: [MULTV-01, ELEV-26s, ROL-01]
- Goal: pré/pós-condições EXECUTÁVEIS onde violação é catastrófica e silenciosa — hoje o único invariante mecânico compartilhado é o anti-fake-green (`FalseClaimInvariant`, consumo `EliteExecutorKernel.php:513-521`) e os guards do land são advisory fail-open.
- Mecanismo: estende o PADRÃO existente (invariant puro + verdict property-gated) a uma lista PINADA de 4 seams: committer escopado (pós: diff ⊆ arquivos da task), porta única de memória ASI-02 (pré: candidato passou G0–G8), applier (pré: reverse handle presente), migração no land (pré: sem destruição irreversível não-declarada, `MigrationSafetyProbe.php:48-61`). Cada contrato: determinístico, default advisory → enforce POR SEAM via ELEV-26s com rollback ROL-01 pré-declarado. NÃO fazer: framework DbC genérico/annotations (YAGNI); contrato com LLM; fila humana (violação ⇒ bloqueio mecânico + digest — charter intacto).
- Aceite: os 4 contratos disparam em fixture com violação sintética ⇒ `violated` com fato nomeado; em enforce, commit fora do escopo ⇒ recusado ANTES do commit (não warning depois); caso negativo: operação legítima ⇒ overhead p95 ≤100ms (pinado); lista de seams no freeze — seam novo = emenda, nunca config in-place.
- Risco: fail-closed travando executor legítimo — rollback por seam + kill-switch env; escopo virar framework — a lista de 4 é pétrea neste slice.

**MULTV-07 — Quarentena de flaky de 1ª classe (flake_rate medido; vermelho intermitente nunca treina ignorar)** `[MEDIDOR]` · E:S/M · onda M4/M5 · deps: [MULTV-01, ELEV-20s, ELEV-03]
- Goal: flaky no gate é ruído que ensina a ignorar vermelho. Quarentena hoje existe SÓ na admissão de locks novos; `env_flake` só re-roda sem registrar (`AtlasRepoVerifiedDeliveryService.php:156`, `RepairDiagnosisStage.php:39`) — nenhum registro por-teste, nenhum estado consumido por gate.
- Mecanismo: (a) série `flake_rate` por test_ref alimentada pelos re-runs que JÁ acontecem: discordância = vereditos diferentes no MESMO commit; (b) registry de quarentena com TTL + re-probe automático (saída com 5 greens consecutivos, pinado); (c) gates da cascata tratam quarentenado como estado PRÓPRIO `quarantined` — não conta verde, não conta vermelho, não bloqueia, aparece SEMPRE no receipt MULTV-01. Entrada SÓ por discordância medida ≥2 em ≤7d (pinado). NÃO fazer: quarentena manual sem probe; teste quarentenado invisível; quarentena como tapete para teste incômodo.
- Aceite: fixture com teste 50%-flake ⇒ quarentena após 2 discordâncias, receipt marca `quarantined`; re-probe 5 greens ⇒ sai; caso negativo: teste ESTÁVEL vermelho jamais entra (exige vereditos discordantes no mesmo commit); série no registry ELEV-20s; denominador cru `{discordâncias, re-runs}` publicado.
- Risco: quarentena esconder regressão real — TTL + re-probe + visibilidade obrigatória no receipt e no digest; entrada gameável — só discordância medida, nunca declaração.

**MULTV-08 — Spec-to-test binding: o aceite do packet vira teste vermelho-antes/verde-depois** · E:M · onda M6 · deps: [MULTV-01, MULTV-02, ASI-09 (adapter advisory), ELEV-18]
- Goal: todo packet já é OBRIGADO a ter `acceptance_criteria` — mas é prosa que nenhuma máquina executa. TDD de máquina: o subset compilável vira teste executável ANTES da execução.
- Mecanismo: compilador DETERMINÍSTICO aceite→teste para os padrões que o brain já emite ("comando X --json expõe campo Y", "arquivo Z contém W", "classe C expõe método m") — o teste é gerado no ENQUEUE, do lado do cérebro, com engine-id próprio (author≠judge mecânico, ELEV-18: quem implementa a task NUNCA autora o teste de aceite); aceite não-compilável ⇒ rótulo `not_compilable` honesto (nunca teste vazio que passa). Probe pétreo `born_green`: teste gerado que já passa no pre-state é RECUSADO (não testa nada). Executor recebe o packet com o teste vermelho; T1 da cascata roda o binding no fim. LLM local como compilador de padrões novos = advisory default-OFF, saída sempre sujeita ao mesmo probe. NÃO fazer: teste autorado pelo mesmo engine que implementa; reescrever o aceite para caber no compilador; contar `not_compilable` como coberto.
- Aceite: em **≥10 packets reais** com padrão compilável: teste gerado FALHA no pre-state e PASSA no post-state real (o par vermelho→verde É o aceite, registrado no receipt MULTV-01); caso negativo: teste `born_green` ⇒ recusado com razão; packet não-compilável ⇒ rotulado, nunca fabricado; share compilável publicado como série INFORMATIVA com denominador cru (nunca meta).
- Risco: compilador gerar teste trivial — probe born_green mata; prosa ambígua — `not_compilable` honesto em vez de teste inventado.

**MULTV-09 — Golden traces dos CLIs críticos (não-regressão comportamental)** · E:S · onda M5/M6 · deps: [MULTV-02, MULTV-07, ELEV-03, ELEV-20s]
- Goal: comandos CLI críticos ganham prova de não-regressão de COMPORTAMENTO (saída canônica congelada) — o organ genérico já existe (`atlas:golden freeze|check`, `AtlasGoldenCommand.php:26-33`, schema `:91`) e nenhum corpus de traces de CLI o consome.
- Mecanismo: corpus `cli-traces` versionado: top-K comandos read-only críticos (K pinado; seleção DERIVADA de frequência de uso real no ledger, nunca lista de gosto) com invocação fixa + normalização determinística declarada por caso (timestamps/ulids/paths mascarados) ⇒ `atlas:golden freeze cli-traces`; o T3 da cascata roda `check` quando o diff toca os comandos do corpus. Mudança INTENCIONAL de comportamento = re-freeze explícito com receipt — o diff do manifest é o changelog comportamental. NÃO fazer: golden de comando mutativo (read-only only); normalização frouxa que engole regressão; segundo organ de golden.
- Aceite: corpus congela **≥10 comandos** com `targets_available == cases` (lição ELEV-01 — golden que mede vazio é 0≥0); regressão sintética ⇒ `check` vermelho com caso nomeado (`:146`); caso negativo: re-run sem mudança ⇒ verde estável 3× (trace flaky não entra — herda probe MULTV-07); re-freeze sem receipt ⇒ recusado; ttl de check no registry ELEV-20s (14d sem check ⇒ alerta).
- Risco: masks engolindo tudo — normalização declarada por caso no manifest, revisável; corpus stale — o ttl vigia.

**MULTV-10 — O seam de enforce da porta de land (o que o flip ASI-10 vai LIGAR)** `[CERTIFICADOR]` · E:S/M · onda M6 (pronto ANTES do flip; o flip é do ASI-10) · deps: [MULTV-01, MULTV-02, ASI-10, ROL-01, ELEV-26s, ELEV-12]
- Goal: hoje o flip ASI-10 não tem onde morder: `atlas:land` roda ZERO testes por design, os guards são advisory fail-open e o AVCEL shadow só prova estrutura. Este slice constrói o seam default-OFF: land AUTÔNOMO exige receipt de verificação do tier mínimo — o mecanismo que leva `verified_share` (ELEV-12) a ≥0,80.
- Mecanismo: check no caminho do land/committer, no MESMO ponto onde a distinção operador×autônomo já existe (`AtlasLandCommand.php:61`): sob a flag do ASI-10, land autônomo sem receipt MULTV-01 selado cobrindo o tier derivado (MULTV-02) ⇒ recusa com razão mecânica + entrada no digest. Default-OFF; promoção EXCLUSIVAMENTE pelo flip ASI-10 via ELEV-26s — **este slice nunca flipa** (1 flip por família/janela, regra do próprio ASI-10). NÃO fazer: segundo flip; bloquear o port interativo do operador; aceitar receipt não-selado.
- Aceite: bancada com flag ON: land autônomo sem receipt ⇒ `verification_receipt_missing`; receipt do tier certo ⇒ landa; tier abaixo do risco derivado ⇒ `tier_below_risk`; seal inválido ⇒ recusado; port do operador intocado (teste: `atlas:land` manual landa sem receipt com flag ON); anti-inatividade herdado do ASI-10 (≥1 bloqueio real OU zero bloqueios com ≥N landings verificados passando, denominador exposto).
- Risco: paralisar a esteira dos 4.841 landings — shadow ≥7d medindo taxa-de-recusa-HIPOTÉTICA antes do flip (número no ledger; se alto, o gap é da cascata — a régua nunca abaixa); rollback ROL-01 pré-declarado.

**Ordem sugerida (MULTV):** MULTV-01 (o medidor-receipt congela primeiro) → MULTV-07 (quarentena limpa o sinal ANTES de qualquer gate consumi-lo) → MULTV-05 (oráculos engordam a suíte com falhas reais) → MULTV-02 (o composer risk-tiered, em advisory) → MULTV-03 → MULTV-09 → MULTV-04 → MULTV-08 → MULTV-06 → MULTV-10 (o seam de enforce, pronto para o flip ASI-10).

**Colisões MULTV com v1/Max/ASI:**
- **ASI-10 é o ÚNICO dono do flip de enforce da área**: MULTV-10 constrói o seam que o flip liga e MULTV-02/03/06 promovem POR ELEV-26s — nenhum slice MULTV flipa nada; 1 flip por família/janela permanece.
- **ELEV-12 (verified_share) não é duplicado**: MULTV-01 é o numerador AUDITÁVEL (o que rodou, por landing); a série de share continua sendo do ELEV-12 — MULTV-01 a alimenta, nunca a substitui.
- **MAXL-07/08 intactos**: o replay MULTV-04 re-executa VERIFICAÇÃO do diff landado (execução), não retrieval no golden vN (contexto); e nenhum sinal MULTV correlacional alimenta o enforce do ASI-10 (mesma lei que trava MAXL-08).
- **MAXL-01/02**: receipts MULTV selam via `KernelEvidenceAuthority` existente e appendam no ledger — integridade de cadeia é MAXL-02, jamais re-proposta aqui.
- **MAXG-04 (golden vN de retrieval) ≠ MULTV-09 (golden traces de CLI)**: manifests separados sob o MESMO organ `atlas:golden` — zero segundo mecanismo de freeze.
- **ASI-11**: divergência de replay (MULTV-04) e violação de contrato (MULTV-06) apontam para o rollback por decision-id do ASI-11 — MULTV nunca reverte sozinho.
- **Obra #19 (Frentes P/L)**: pregate/impactado/cached/golden/land são REUSADOS por composição em MULTV-02 — qualquer re-implementação de camada é violação de reuso e desqualifica o slice.
- **ELEV-28**: mutation-enforce (MULTV-03) e contratos (MULTV-06) são famílias com A/B raiz — resultado negativo ⇒ `suspended_pending_evidence`, nunca prosseguir no vácuo.

---

#### Área 15 — Modelo do Operador (MULTN15) — autonomia sem fricção

**Teto atual (relido no código em 2026-07-12):**
- Captura default-ON com dupla mordaça silenciosa: o seam vivo chama em `AiGatewayService.php:553,850` e o método `:859-871` engole `Throwable` em `Log::warning` (`operator_learning_gateway_capture_failed`, `:865`); antes disso `OperatorLearningRuntimeCaptureService.php:120` retorna `false` sem alarme quando as `REQUIRED_TABLES` (`:13-18`) não existem — 0 sinais com flag ON (`config/atlas_operator_intelligence.php:4,20`, defaults `true`). MAXN-01 é o dono do conserto; nada aqui re-propõe.
- O único feedback jamais gravado é `context_injected` (`OperatorContextComposer.php:48`); `OperatorProfileFeedbackService.php:14-27` nunca recebe accepted/override/ignored — a curva de comportamento não tem produtor (dono: MAXN-03).
- Confidence só sobe, nunca desce: `OperatorProfileRegistry.php:51` (`max(candidate, existing)`); o único consumidor vivo do perfil é injeção de contexto (`AtlasOpenBrainContextInjectionService.php:27,162,190`) — consumo por DECISÃO = 0 (dono: MAXN-02).
- Ask-vs-act é heurística fixa sem termo de histórico: `OperatorApprovalRiskPolicy.php:34+` resolve por tabelas de categoria; o único reuso adaptativo é aprovação prévia da MESMA mission+action (`OperatorApprovalGateService.php:88-116`); produtores vivos de approve/deny/expire em `:213-229` (chamados por `MissionFollowThroughService.php:630`, `AtlasAiApprovalCommand`, ControlPlane) — histórico EXISTE, ninguém aprende dele.
- Digest é contagem, não juízo: `OperatorProfileDigestService.php:37-43` publica 4 counts; zero review-debt (dono: ELEV-25), zero curva declarado-vs-comportamento.
- Transparência parcial: `atlas:operator-profile context` (`AtlasOperatorProfileContextCommand.php:11-21`, com peek `--no-record-usage`) mostra só o top-K injetável — não o modelo inteiro com evidência e handle de correção.

**Fronteira (técnica → veredito + porquê):**

| Técnica | Veredito | Porquê |
|---|---|---|
| Limiar ask-vs-act calibrado (risco×histórico×confiança) | **ADOTAR (régua antes do atuador)** | A heurística é fixa e o histórico já existe em `ai_operator_approvals` sem leitor; menos perguntas desnecessárias É o multiplicador nomeado da área; assimetria safety-increasing (1 deny/revert ⇒ volta a perguntar na hora) preserva charter+floors |
| Tolerância a risco POR DOMÍNIO aprendida | **ADOTAR** | Banda derivada de aceites/reverts com n mínimo; `effect=autonomy_limit` já existe no enum (`OperatorProfilePolicyRule.php:17`) e o seam de consumo é o MAXN-02 — zero seam novo; migrations nunca alarga (floor 18) |
| Orçamento de atenção/interrupção | **ADOTAR estreito** | Hoje toda confirmação interrompe igual e expira igual (`:229`); batch só re-agenda asks JÁ existentes (nunca fila nova); expiry silencioso proibido por teste |
| Modelo de contexto do operador (sinais locais) | **DOBRAR no orçamento de atenção** | Não sustenta slice próprio: o único consumidor honesto da cadência de sessões é o timing de batching/digest; timestamps-only, zero surveillance |
| Simulação do operador como pre-review advisory | **ADOTAR TARDE (shadow + critério de morte)** | Precisa do corpus de reverts do MAXN-03; advisory puro, nunca gate; primeiro consumidor honesto = ordenar a fila de revisão do ELEV-25 |
| Estilo operacional como policy rule compilada | **ADOTAR pequeno** | Compiler+composer já fazem 90% (`COL-*→response_style`, `OperatorProfilePolicyCompiler.php:62-63`); é wiring de produtor + prova ELEV-20, E:S |
| Mudança de preferência ⇒ supersedência | **ADOTAR via padrão MAXH-04** | Item ativo hoje vive para sempre (`OperatorProfileItem.php:55-61`) e o novo acumula ao lado; demove com reverse handle, nunca deleta, nunca segundo motor |
| Transparência total do modelo | **ADOTAR CEDO** | Auditabilidade precede atuadores; correção digitada pelo operador é o sinal de treino de maior confiança (trusted-provenance já é conceito vivo no gate de auto-apply, `config/atlas_operator_intelligence.php:6-8`) |

**Slices:**

**MULTN15-01 — Transparência total: o operador audita o próprio modelo** `[TRANSPARÊNCIA]` · E:S · onda M1 · deps: [MAXN-01]
- Goal: comando que mostra TUDO que o Atlas acredita sobre o operador — item, confidence, evidência (candidate→signal→trace refs), regra compilada, automation_level — com handle de correção; hoje só existe o top-K injetável.
- Mecanismo: ação `audit` no comando EXISTENTE (`AtlasOperatorProfileContextCommand.php:11`, reuso — não comando novo): lista 100% dos `OperatorProfileItem` (ativos+pausados+arquivados); ação `correct --item= --verdict=confirm|reject` grava `OperatorProfileFeedbackEvent` `feedback_action=operator_correction` (peso máximo no juiz MAXN-03 — correção digitada = trusted-provenance); reject demove (status paused) com reverse handle. NÃO fazer: saída provider-external (local-only); editar confidence direto (só o juiz MAXN-03 move).
- Aceite: `--json` lista N == count total da tabela do operador (denominador = tabela, não top-K); item sensitive aparece LOCAL com `provider_blocked=true`; `correct --verdict=reject` ⇒ item paused + evento gravado + ciclo correct→reverse restaura byte-comparável; caso negativo: item rejeitado NUNCA volta ao contexto composto no turno seguinte (fixture do composer).
- Risco: virar segunda fonte de verdade — a ação só lê e grava feedback/status pelos modelos existentes, zero schema novo.

**MULTN15-02 — Régua ask↔act congelada: histórico de aprovação por classe de ação** `[MEDIDOR]` · E:S · onda M1 · deps: [nenhum novo — `ai_operator_approvals` vivo (migration `2026_05_19_120000`); ELEV-03/18 no freeze]
- Goal: antes de qualquer atuador, a série honesta: por classe de ação × risco, `{asks, approved, denied, expired, reused, n}` — o denominador real do "o operador sempre diz sim aqui".
- Mecanismo: leitor read-only sobre `ai_operator_approvals` (produtores vivos `OperatorApprovalGateService.php:213-229`, reuso `:88-116`); classe = o `requested_action` já registrado (vocabulário existente, não taxonomy nova); publica no digest FEE-12 e em `atlas:operator-learning digest`. Freeze: n mínimo e teto de deny-rate para elegibilidade futura PINADOS no ledger ANTES do MULTN15-03 (ELEV-03), com `judge_engine_id ≠ author_engine_id` (ELEV-18). NÃO fazer: mover gate_mode (medidor puro).
- Aceite: `--json` publica células {classe, risco} com contadores crus + n; célula n<10 ⇒ `insufficient_n` (nunca "sempre aprova" por vácuo); re-run sobre o mesmo DB ⇒ série byte-idêntica (determinística); caso negativo: janela sem approvals ⇒ report vazio honesto, nunca inventa classe.
- Risco: `requested_action` esparso demais para formar classe — escopo grosso primeiro (prefixo `mission.*`), granular só quando o n encher.

**MULTN15-03 — Limiar ask-vs-act calibrado: allow_auto derivado do histórico (só REDUZ perguntas)** `[ATUADOR]` · E:M · onda M4 · deps: [MULTN15-02, ASI-11 (linhagem de revert), ELEV-26]
- Goal: classe de ação onde o operador aprovou n≥pinado vezes com 0 denies e risco ≤ medium para de interromper — a decisão perguntar-ou-agir sai da tabela fixa (`OperatorApprovalRiskPolicy.php:34+`) e ganha o termo de histórico que hoje não existe.
- Mecanismo: termo derivado APÓS o `resolve()` (função pura da célula da régua 15-02 — caller jamais seta, lição SEV-1): eleva SÓ `require_confirmation→allow_auto`; NUNCA toca `block`/`escalate_to_forge`/review-de-floor; toda elevação grava receipt `calibrated_auto:{classe,n,deny_rate}` + digest. **Assimetria safety-increasing:** 1 deny OU 1 revert (ASI-11) na classe ⇒ volta a perguntar IMEDIATAMENTE; re-alargar exige nova janela cheia. default-OFF → shadow ("teria auto-aprovado X asks") → live via ELEV-26. NÃO fazer: gate humano novo (charter); generalizar além da classe pinada.
- Aceite: fixture {n=12, 12 approves, 0 denies, low} ⇒ allow_auto com receipt; caso negativo A: mesma classe + 1 deny ⇒ `require_confirmation` no request SEGUINTE; caso negativo B: n=9 < pinado ⇒ NUNCA eleva (`insufficient_n`); invariante-teste: o termo só é mais-permissivo partindo de `require_confirmation`, jamais de block/floor; shadow publica perguntas-economizadas/janela com denominador.
- Risco: operador aprovando em piloto-automático infla a régua — deny/revert único derruba a classe; teto de classes elevadas por janela pinado no freeze.

**MULTN15-04 — Tolerância a risco por domínio: bandas de autonomia derivadas de aceites/reverts** `[ATUADOR]` · E:M · onda M4 · deps: [MAXN-02 (seam de consumo), MAXN-03 (juiz), MULTN15-02, ASI-11]
- Goal: "autonomia total em docs, revisar migrations" vira banda DERIVADA por classe de alvo — hoje `automation_level` é por item (`OperatorProfileItem.php:20`), nunca por classe de alvo.
- Mecanismo: job ex-post agrega {aceites, overrides, reverts do OPERADOR} por classe de alvo grossa (docs/tests/config/migrations/código — vocabulário do repair-taxonomy existente) e compila UMA policy rule `effect=autonomy_limit` por classe via `OperatorProfilePolicyCompiler.php:16-38`; consumo = bloco `operator_model` do MAXN-02 (MESMO seam, zero seam novo). **Alargar exige n≥pinado E 0 reverts na janela; estreitar é imediato com 1 revert. Classe `migration` nunca alarga acima do floor da 18 (`migration_safety`).**
- Aceite: fixture {docs: 20 aceites/0 revert} ⇒ rule `autonomy_limit` banda alta com evidence refs; {migrations: qualquer histórico} ⇒ banda ≤ floor (teste-invariante floor-vence); caso negativo: classe com n<10 ⇒ NENHUMA rule compilada (ausência honesta, não default); ciclo compilar→reverter restaura byte-comparável (`reverse_handle`, `OperatorProfilePolicyRule.php:31`).
- Risco: classe grossa misturando alvos — começa grosso, refina só quando o n da subclasse encher; poluição por aceite de máquina — só sinal operator-typed/trusted conta.

**MULTN15-05 — Orçamento de atenção: interromper só acima do custo-de-erro, batch para o digest** `[ATUADOR]` · E:M · onda M5 · deps: [MULTN15-02, ELEV-25, v1 FEE-12]
- Goal: QUANDO interromper vale a pena — hoje toda `require_confirmation` interrompe igual e expira igual (`OperatorApprovalGateService.php:229`); pergunta não-urgente espera o digest, interrupção imediata só acima do limiar de custo-de-erro.
- Mecanismo: classificador derivado `{interrupt_now, batch_to_digest}` em função de {risco, reversibilidade, expiry, deny-rate da classe (régua 15-02)} — limiar pinado no freeze (ELEV-03); ask batched continua bloqueando SÓ a própria task (nunca fila nova, nunca auto-aprova) e agrupa no digest ELEV-25; **ask batched perto do expiry ESCALA para interrupção — ask→nunca-respondida é proibido por teste**; sinal local barato (cadência de sessões via timestamps de `ai_traces`, zero conteúdo/surveillance) modula só QUANDO o digest é montado.
- Aceite: fixture {low-risk, reversível, expiry 24h} ⇒ batched com idade visível no digest; {high-risk} ⇒ interrupt_now (contraste); caso negativo: batched a X min do expiry ⇒ PROMOVIDA a interrupção (anti-silêncio); métricas {interrupções/janela, batched/janela, idade máx pendente} com denominador ao lado do review-debt ELEV-25; charter-teste: zero ask NOVA criada por este slice (só re-agendamento das existentes).
- Risco: batch virar stall invisível de mission — idade máx pendente entra no digest E no watchdog; limiar mal calibrado — pinado, muda só por emenda.

**MULTN15-06 — Estilo operacional persistente como policy rule compilada (PT-BR, placar, cadência)** `[WIRING]` · E:S · onda M2 · deps: [MAXN-01]
- Goal: as regras de estilo que hoje vivem em memória solta de provider (responder em PT-BR, placar por task, cadência de report) viram `OperatorProfileItem`+rule `effect=response_style` — servidas a QUALQUER executor via composer, não só ao provider que leu o MEMORY.md.
- Mecanismo: captura pela via normal (`atlas:operator-learning capture --source-type=manual`, `AtlasOperatorLearningCommand.php:17` — operator-typed = trusted provenance) → promote → compiler (`COL-*→response_style`, `OperatorProfilePolicyCompiler.php:62-63`); consumo já vivo: `OperatorContextComposer.php:64` expõe rules e `AtlasOpenBrainContextInjectionService.php:162,190` injeta. É wiring de produtor + prova de consumo (ELEV-20), não construção.
- Aceite: item "responder em PT-BR" capturado→promovido ⇒ contexto composto REAL carrega rule `response_style` com `provider_safe=true`; produtor+consumidor provados: `context_injected` gravado para o item em tráfego real (não phpunit — ASI-05); caso negativo: item de estilo `privacy_class=private` NUNCA aparece em composição `provider_external=true` (`OperatorContextComposer.php:24-26,35`).
- Risco: duplicar o que o CLAUDE.md projeta — a rule vira FONTE e a projection deriva dela (governança canônica intacta); estilo momentâneo eternizado — `validity_hint` já expira momentary (`OperatorProfileRegistry.php:95-99`).

**MULTN15-07 — Mudança de preferência ⇒ supersedência por contradição comportamental (nunca acúmulo)** `[ATUADOR]` · E:M · onda M4 · deps: [MAXN-03 (juiz), MAXH-04 (padrão do atuador)]
- Goal: preferência antiga contradita por comportamento recente vira candidata a supersedência — hoje o item ativo vive para sempre (`OperatorProfileItem.php:55-61`) e o novo acumula ao lado do velho.
- Mecanismo: job ex-post detecta streak de override explícito (≥n pinado consecutivos, do juiz MAXN-03) contra item ativo ⇒ supersedência pelo MESMO padrão reversível do MAXH-04 (auto-aplica reversível + etiqueta no digest, nunca fila humana — charter): item velho DEMOVIDO (paused, reverse handle), novo (se houver candidate) promovido com ref `supersedes`; contradição sem substituto ⇒ só demove + digest. NÃO fazer: segundo motor de supersedência (MAXH-04 aterrissado ⇒ reusar o atuador); deletar item.
- Aceite: fixture item ativo + 5 overrides consecutivos reais ⇒ paused com reverse handle + digest citando o streak; ciclo demover→reverter restaura byte-comparável; caso negativo A: 4 overrides + 1 aceite (streak quebrado) ⇒ NADA acontece; caso negativo B: regra de floor/`do_not_do` de segurança NUNCA é auto-demovida por streak (floor da 18 não é preferência — teste-invariante).
- Risco: comportamento ambíguo lido como override — só override EXPLÍCITO conta (ignorado não conta); flapping — cooldown pinado por `profile_key`.

**MULTN15-08 — Simulação do operador: pre-review advisory "ele reverteria isto?" (shadow, critério de morte)** `[ADVISORY]` · E:M · onda M6 · deps: [MAXN-03 (corpus), ASI-15 (padrão de calibração), ELEV-25 (consumidor), ELEV-28]
- Goal: juiz local prevê "o operador reverteria isto?" ANTES do auto-apply — advisory PURO: anota o receipt e ORDENA a fila de revisão do digest (prováveis-revert no topo; o primeiro consumidor honesto é o review-debt ELEV-25), jamais bloqueia.
- Mecanismo: score derivado de features determinísticas {classe de alvo, risco, taxa histórica de revert/override em itens similares (MAXN-03), banda de confidence} — função pura da evidência, sem LLM na v1 (se frontier autorar features um dia: author≠judge ELEV-18); anotação `predicted_revert_band` no receipt; calibração ex-post estilo ASI-15 (banda declarada × revert real, bandas+denominadores crus, nunca escalar único — lição do "92"). **Critério de morte pinado no freeze: após 2 janelas, se banda alta não separar (lift alto-vs-baixo abaixo do mínimo pinado) ⇒ família SUSPENSA (ELEV-28), anotação removida.**
- Aceite: auto-apply real carrega `{band, n_similar}` (n<10 ⇒ `insufficient_sample`, nunca banda); digest ordena a fila pela banda (consumidor provado ELEV-20); curva banda×revert-real publicada por janela; caso negativo: banda alta NUNCA bloqueia nem atrasa o auto-apply (pipeline byte-idêntico exceto anotação+ordem do digest); fixture de não-separação ⇒ `suspended` executado.
- Risco: profecia auto-realizada (banda alta ⇒ mais revisão ⇒ mais revert achado) — viés declarado no report; lift medido contra a taxa base da mesma classe.

**Ordem sugerida (MULTN15):** MULTN15-02 e 01 (M1 — régua congelada + transparência ANTES de qualquer atuador) → MULTN15-06 (M2, wiring barato) → MULTN15-03, 04, 07 (M4 — atuadores sobre régua congelada + juiz MAXN-03 vivo) → MULTN15-05 (M5 — precisa do ELEV-25 operante) → MULTN15-08 (M6 — precisa do corpus de reverts).

**Colisões MULTN15 com v1/Max/ASI:**
- MAXN-01/02/03 são deps, não re-propostos: 01 materializa as tabelas (todo aceite comportamental daqui pressupõe o guard @5433 + invariante RefreshDatabase — nenhum slice MULTN15 cria migration nova); MAXN-02 é o ÚNICO seam de consumo na Decisão (15-04 pega carona no bloco `operator_model`, zero seam paralelo); MAXN-03 é o único motor de confidence (15-07 demove status, não move confidence; 15-08 só lê).
- MULTK-07 (`requested_autonomy` só-para-baixo no Decide) × MULTN15-03 (allow_auto no gate de aprovação): órgãos DIFERENTES, sem sobreposição — 15-03 só converte `require_confirmation→allow_auto` sob régua com queda instantânea; nenhum dos dois toca block/floors.
- ASI-13 = modelo DE SI; área 15 = modelo DO OPERADOR — irmãos no MESMO receipt (`self_model` vs `operator_model`, seam MAXN-02).
- ELEV-25 é o dono do review-debt: 15-05 publica AO LADO e 15-08 ordena a fila dele — nenhum recomputa as métricas.
- MAXH-04 é o dono do atuador de supersedência: 15-07 reusa padrão/atuador, nunca segundo motor.
- Charter intacto nos 8: 15-03 e 15-05 só reduzem/re-agendam perguntas EXISTENTES; 15-08 é anotação; zero fila de aprovação nova; floors da 18 vencem por teste-invariante em 15-03/04/07.
- Medidores v1 congelados intocados; toda régua nova é v2 própria com limiar carimbado no freeze (ELEV-03) e `judge≠author` (ELEV-18); classes private/sensitive nunca saem da máquina.

---

#### Integração — O Flywheel N×M como objeto de primeira classe (MULTX)

**Teto atual (relido no código em 12/07/2026):**
- **O spine OUTC-01 é 1 escritor/3 sinks, mas o CONTRATO diverge nos 3 call-sites:** `app/Services/Ai/Aemor/AtlasEngineeringOutcomeRecorder.php:219-289` faz o fan-out (ai_run_outcomes + live_outcomes, actor `engineering_outcome_spine:{executor}` :281); Dev (`PipelineRunExecutor.php:1295`) passa provider real + `verified=$passed` + `provider_calls_made`; Forge (`AtlasForgeLiveExecutionService.php:871`) NÃO passa `verified` e o recorder o **default-a para true quando passed** (`:255` — `$input['verified'] ?? ($outcomeStatus === 'passed')`; a lição "unmarked is NOT proven" foi aplicada em `proven_real` mas não aqui); Autônomos (`AtlasTaskServingService.php:1594`) não passa `provider` (⇒ `'local'` :277). E `task_category` é remapeado assimetricamente: `dev→'programming'`, forge/autônomos→nome do executor (`:275`) — o mesmo tipo de trabalho credita em chaves diferentes do ADML.
- **Forge tem DUAS bocas e só uma alimenta o spine:** `ForgeWorkPacketExecutionCycleService` (o executor de packets real) nunca chama o recorder — persiste em silo próprio via `ForgeOutcomeMemoryService` (`:74,983-987`); só o caminho live (`AtlasForgeLiveExecutionService:871`) emite outcome.
- **A metade "recall com ref" do loop só existe no Dev:** ARFL `capture()` é chamado exclusivamente em `PipelineRunExecutor.php:1330-1421` (com `context_pack_hash` :1407, lookup no COM-01 :4257); `measured` exige utility explícita E atribuição (`AtlasRetrievalFeedbackLoopService.php:87,246`). O Autônomos consome o cérebro por **recall direto** (`AtlasTaskServingService.php:246` → `AtlasHybridMemoryRetrievalService->recall` :1155) sem passar pelo packFor ⇒ zero linha no delivered-pack ledger, zero evento ARFL — **toda volta do Autônomos é invisível ao funil por construção**.
- **O bypass claude_cli está cercado, não fechado:** `PipelineRunExecutor.php:1532-1560` — o caminho Dev-claude dirige `ClaudeCliGateway` DIRETO, pulando `AiProviderManager`; o seam compartilhado `ProviderGovernanceConsult::consultBeforeSpawn` roda antes do spawn, mas fail-open e enforce OFF.
- **Nenhum id encadeia a volta:** a entry de `live_outcomes` (`AtlasDecideLiveOutcomeFeedbackService.php:165-189`) não carrega decision_id, context_pack_hash nem refs de memória; episode/outcome/candidate/usage/ARFL são joináveis só por timestamp+heurística. O flywheel existe como fragmentos, não como objeto.

**Fronteira (técnica → veredito + porquê):**

| Técnica | Veredito | Porquê |
|---|---|---|
| Volta do flywheel como objeto de 1ª classe (loop_id encadeado) | **SIM (a alavanca-mãe)** | Os ids JÁ existem por estágio (decision_id ELEV-15, context_pack_hash COM-01, episode/outcome_id, entry_hash); falta só o fio. Assembler read-only por join, não 7 writers novos. |
| Funil de perda do M (tela única, denominador por estágio) | **SIM [MEDIDOR]** | Cada costura hoje perde sinal em silêncio; o funil é o mapa de vazamento que diz ONDE investir. Congela antes de qualquer atuador de paridade (MED-01). |
| Paridade dos 3 executores — contrato de outcome | **SIM** | O recorder único já existe; a divergência é dos call-sites + default `verified` fail-open (:255). Corrigir contrato < construir seam novo. |
| Paridade dos 3 executores — consumo do cérebro | **SIM, mínimo honesto** | Não forçar packFor completo no Autônomos (custo do serving); o mínimo que fecha o loop: entrega registrada no COM-01 + citação capturada no ARFL. Sem isso o funil só mede o Dev. |
| Fechar bypass claude_cli re-roteando por AiProviderManager | **NÃO re-rotear; SIM enforce do seam** | Auditoria forge-driver já provou keep-separate para drivers CLI; o consult compartilhado existe e roda. A fronteira é flip observe→enforce via ELEV-26 + teste de paridade de governança, não reencanamento. |
| Latência de aprendizado como série | **SIM [MEDIDOR]** | Derivável por join (outcome→candidate→entry→primeiro usage/citação); ASI-07 é o atuador que move o número — a série só mede. |
| A/B permanente do M com braço motor-cru EM PRODUÇÃO | **REJEITADO na forma live; SIM como replay pareado** | Degradar task real do operador de propósito viola a definição operacional (§viii: "output confiável"). A forma honesta: replay pareado em sandbox (runner MULTJ-05) + faixas peek de retrieval (padrão RAG-01). |
| Detecção de loop degenerativo (derivada negativa por família) | **SIM** | MAXI-06 pega ancestral JÁ revertido; falta o sensor pré-reversão (família piorando antes de qualquer tombstone). Circuit-breaker = lei de máquina safety-increasing, re-arm do operador. |
| Orquestrador de janelas de observação | **SIM, read-only** | O programa é serial em relógio e ninguém computa o caminho crítico; ELEV-26s já carrega janela+critério por flip — este slice só adiciona a visão de grafo e o alerta de janela-morta. Scheduler executivo novo = NÃO. |

**Slices:**

**MULTX-01 — A volta do flywheel: loop_id encadeado + assembler read-only** `[MEDIDOR][ALAVANCA]` · E:M · onda M1 (freeze da definição) / M4 (série cheia) · deps: [ASI-05, ASI-11/ELEV-15 (linhagem), MAXK-04, MAXE-01, v1 OUTC-01 ✅]
- Goal: a volta {task → decisão citada → pack entregue → execução → outcome → lição → recall subsequente com ref} vira registro verificável com ids encadeados — o item 13 do §viii deixa de ser evento único e vira série: `voltas/semana`, `tempo-por-volta`, `estágio-de-morte` por volta incompleta.
- Mecanismo: **zero writer novo por estágio** — um assembler read-only (`atlas:flywheel:loops --json`) junta o que já existe: decision receipt (MAXK-04) → `context_pack_hash` (COM-01) → episode/outcome (`AtlasEngineeringOutcomeRecorder.php:79-125`) → learning candidate/entry → primeiro usage + evento ARFL `measured=true` citando o ref. Único write aditivo: `live_outcomes` ganha campos opcionais `{decision_id, context_pack_hash}` (aditivo, entry_hash v2 versionado) estampados pelo spine — a linhagem ELEV-15 já obriga decision_id nos landings; isto só o propaga ao sink. **Definição de volta VÁLIDA congelada no freeze (ELEV-03): exige outcome `proven_real=true` — task trivial/sintética não conta; volta NUNCA é meta de contagem, é unidade de MEDIDA.** O que NÃO fazer: tabela nova de "loops" com writer próprio em cada estágio (7 pontos de drift); volta como KPI de produtor.
- Aceite: sobre a primeira janela real pós-ASI-06, `--json` reporta `{loops_complete, loops_partial (com estágio-de-morte nomeado), n_total, tempo_por_volta p50/p95}` com denominadores crus; janela sem massa ⇒ `insufficient_signal` (nunca 0 verde); caso negativo executável: volta cujo outcome é eco de phpunit (guard ASI-05) NUNCA entra na série; freeze grava `{judge_engine_id ≠ author_engine_id}` (ELEV-18) + definição de volta válida com hash.
- Risco: join frágil por ids ausentes nos dados históricos — a série começa no freeze, sem backfill heurístico (linhas pré-chain rotuladas `legacy_unjoined`, honesto, padrão MAXL).

**MULTX-02 — Funil de perda do M: a tela única de vazamento por costura** `[MEDIDOR]` · E:M · onda M4 · deps: [MULTX-01, MULTX-03 (contrato v2 para denominadores honestos), v1 COM-01/COM-11 ✅, MAXE-01]
- Goal: cada costura ganha numerador/denominador na mesma tela: % outcomes sem lição → % lições sem promoção → % promovidas sem recall → % recalls sem citação → % citações sem outcome subsequente melhor — o mapa de ONDE o multiplicador vaza, por executor e por janela.
- Mecanismo: agregador read-only sobre os mesmos ledgers do MULTX-01; os estágios já são observáveis: `learning.status` no retorno do recorder (`:128-140`), fila candidate→promoted do Compounding, `atlas_memory_entry_usages`, ARFL `measured`. Publica no digest + comando `--json`; por-executor SEMPRE lado a lado (a assimetria Dev-vs-Autônomos É o achado, não ruído a normalizar). O que NÃO fazer: fundir o funil num escalar único (lição do "92"); usar estágio do funil como meta de produtor (é diagnóstico — ex.: "% outcomes sem lição = 94%" pode ser o CaptureQualityGate funcionando; o funil reporta, o juiz é humano+digest).
- Aceite: comando publica os 5 estágios com `{num, den}` crus por executor e por janela; estágio com den < N_min pinado ⇒ `insufficient` (ELEV-03); caso negativo: janela vazia reporta `no_signal`, nunca funil "100% saudável"; série registrada no registry ELEV-20s.
- Risco: o funil virar meta (Goodhart clássico — inflar lições para baixar "% sem lição") — mitigação pétrea: nenhum estágio do funil entra em aceite de OUTRO slice como alvo; só limiares de ALERTA, pinados no freeze.

**MULTX-03 — Contrato único de outcome v2: os 3 call-sites param de divergir + `verified` fail-closed** · E:M · onda M4 · deps: [v1 OUTC-01 ✅, ASI-05, MED-01 (dual-read), ELEV-26 (o flip do default)]
- Goal: mesmo outcome lógico pelos 3 executores ⇒ mesma shape no spine; e o furo `verified => default true quando passed` (`AtlasEngineeringOutcomeRecorder.php:255`) fecha — sucesso NÃO-provado nunca mais vira verified por omissão.
- Mecanismo: (a) schema `atlas.engineering_outcome.v2` com campos obrigatórios por executor: `provider` (Forge/Autônomos hoje omitem ⇒ 'local' fantasma :277), `verified` explícito com `verified_basis ∈ {server_verified, gates_passed, claimed, absent}` DERIVADA da fonte (nunca setada livre — lição SEV-1); ausente ⇒ `false/absent` (fail-closed, espelhando `proven_real`); (b) `task_category` unificado por mapa único documentado (o remap `dev→programming` :275 fragmenta o credit assignment do ADML — v2 usa a MESMA taxonomia, série v2, a v1 continua byte-idêntica em dual-read MED-01); (c) **Forge packet-cycle entra no spine**: `ForgeWorkPacketExecutionCycleService` passa a chamar o recorder no fecho do cycle (o `ForgeOutcomeMemoryService` permanece — é memória de Forge, não substituto de spine); (d) teste de paridade como invariante: fixture executa o mesmo outcome lógico pelos 3 call-sites ⇒ diffs APENAS em campos de identidade, assertado campo a campo.
- Aceite: phpunit de paridade verde nos 3 call-sites; caso negativo: chamada v2 sem `verified` explícito ⇒ `verified_basis=absent` e `verified=false` (nunca herda `passed`); janela real ≥20 outcomes: 100% com `verified_basis` presente; série v1 inalterada no dual-read; mudança do default roteia por ELEV-26 (o v1 congelado não é editado — o v2 nasce ao lado).
- Risco: quebrar consumidores do shape v1 — v2 é aditivo com dual-write na janela de transição; `verified_share` (ELEV-12) lê a v2 SÓ após o freeze próprio dela.

**MULTX-04 — Paridade de consumo do cérebro: Autônomos e Forge entram no COM-01 e no ARFL** · E:M · onda M4/M5 · deps: [MAXE-01, v1 COM-01 ✅, ASI-06 (o volume), MAXE-04/05 (custo do append — ver risco)]
- Goal: a entrega de contexto ao Autônomos/Forge deixa de ser invisível: o que o serving injeta no packet (`AtlasTaskServingService.php:246,1155`) vira linha no delivered-pack ledger com refs canônicos, e o report server-side do task captura evento ARFL — sem isso, MULTX-01/02 medem só ⅓ do sistema para sempre.
- Mecanismo: **reuso estrito, nenhuma porta nova**: (a) no ponto de injeção do serving, os itens recallados ganham refs canônicos (`AtlasCanonicalContextRef`, mesmo namespace COM-01) e `AtlasDeliveredPackLedger::record()` registra a entrega keyed por hash do payload injetado — o MESMO produtor, chamado de um segundo site; (b) no fecho do task (onde o commit escopado já é verificado server-side, `:1594`), `AtlasRetrievalFeedbackLoopService::capture()` com o hash da entrega — o mesmo seam do Dev (`PipelineRunExecutor.php:1421`), atribuição derivada dos refs citados no report do worker; (c) Forge idem no packet-cycle. O que NÃO fazer: forçar o packFor completo no hot-path do serving (o recall direto continua — só ganha recibo); segundo formato de ref; capture no CLAIM do worker (sempre server-side, lição do committer).
- Aceite: janela real ≥20 tasks servidas: ≥90% com linha COM-01 de origem `task_serving`; evento ARFL por task fechada com `measured` honesto (utility explícita quando o report a fornece, `unmeasured` quando não — nunca fabricada); caso negativo: task sem memória injetada NÃO grava linha (zero entrega fantasma); funil MULTX-02 passa a reportar os 3 executores com den > 0.
- Risco: o `record()` do ledger faz replay+rewrite O(N) por append (`AtlasDeliveredPackLedger.php:60-63`) — sob volume do serving isso morde; sequenciar APÓS MAXE-04/05 (append real) ou cap de writes/ciclo declarado até lá.

**MULTX-05 — Bypass claude_cli: o seam de governança sai de fail-open decorativo para enforce provado** · E:S/M · onda M5 · deps: [seam SLICE 2 vivo (`PipelineRunExecutor.php:1532-1560`), ELEV-26, MAXK-04, MULTX-03]
- Goal: os 3 músculos consultam o MESMO seam de governança antes do spawn com consequência real — a paridade de decisão vira invariante testado, sem re-rotear o driver CLI pelo `AiProviderManager` (keep-separate já auditado).
- Mecanismo: (a) teste de paridade de governança: a mesma operação sintética pelos 3 caminhos atinge `ProviderGovernanceConsult::consultBeforeSpawn` com o mesmo shape de input e produz o mesmo verdict — assertado; (b) o fail-open ganha CONTADOR exposto (seam unbound ⇒ prossegue MAS incrementa `governance_consult_skipped` no ledger — hoje `is_object($consult) && method_exists(...)` :1539 falha em silêncio absoluto); (c) flip observe→enforce pelo protocolo único ELEV-26 (janela shadow com % de would-block publicado, rollback ROL-01); (d) o consult verdict entra no decision receipt (MAXK-04) como `governance_basis`. O que NÃO fazer: reencanar `ClaudeCliGateway` por dentro do manager (keep-separate); enforce sem janela shadow com denominador.
- Aceite: teste de paridade verde nos 3 caminhos; `governance_consult_skipped == 0` numa janela real ≥50 spawns (denominador mínimo — inatividade não satisfaz); pós-enforce: ≥1 bloqueio real registrado OU zero bloqueios com ≥N spawns consultados (nunca por vácuo); caso negativo: com enforce ON e fixture de custo estourado, o spawn é RECUSADO com receipt nos 3 caminhos.
- Risco: enforce bloqueando executor legítimo em cascata — rollback pré-declarado executa sem aprovação no meio (charter); o cap/threshold é floor da área 18 (registry ELEV-09), nunca config in-place.

**MULTX-06 — Latência de aprendizado: da volta N para a volta N+1, em série** `[MEDIDOR]` · E:S · onda M4 · deps: [MULTX-01, v1 FEE-12 ✅, ASI-07 (o atuador — dep de evidência, não de código)]
- Goal: `learning_latency` vira série de primeira classe: Δt(outcome que originou a lição → primeira entrega da lição em pack/packet → primeira citação measured) — hoje o caminho é 1–7 dias + digest e NINGUÉM mede; o alvo de minutos (ASI-07) precisa de régua ANTES do flip.
- Mecanismo: leitor read-only sobre a cadeia do MULTX-01: para cada entry promovida com origem em outcome, computa os 3 deltas contra `atlas_memory_entry_usages` (primeira usage) e eventos ARFL (primeira citação); publica `{p50, p95, n}` por janela e por classe de lição, no digest e no registry ELEV-20s. **Congela ANTES do flip do ASI-07 (MED-01)** — este medidor é a prova do maior salto de latência do programa. O que NÃO fazer: atuador próprio; média sem percentil (uma lição-zumbi de 30d esconde tudo).
- Aceite: `--json` com `{p50, p95, n}` por janela; n<8 ⇒ `insufficient`; caso negativo: lição promovida e NUNCA entregue aparece como `never_delivered` no denominador (não some da série); antes/depois do flip ASI-07 registrado como dual-read no Evidence Ledger.
- Risco: série vazia até F1 girar — correto e honesto (`insufficient_signal`); o freeze não espera dados, espera régua.

**MULTX-07 — A/B permanente do M por replay pareado (controle motor-cru sem sabotar produção)** `[MEDIDOR]` · E:M/L · onda M6 · deps: [ELEV-02/ASI-METRIC (a fórmula congelada — dep pétrea), MULTJ-05 (runner sandbox de replay), MULTX-01, sandbox floor pétreo]
- Goal: o M deixa de ser estimado e vira medido continuamente: amostra fixa de tasks reais já executadas COM ACOS é re-executada em sandbox SEM o contexto (motor cru), pareada task a task — o braço de controle que o item 14 do §viii pede, sem degradar nenhuma entrega real.
- Mecanismo: **a variante live foi rejeitada** (braço de controle em produção = sabotar de propósito o output do operador). Forma honesta: extensão do runner MULTJ-05 (reuso — não um 3º runner): amostra por hash (rate pinada, ex. 5%) das tasks da janela re-roda em snapshot sandbox com pack suprimido; juízes = os MESMOS gates deterministas do registro histórico; delta agregado pela fórmula ELEV-02 (consumida, JAMAIS redefinida aqui), publicado como série `m_measured.v1` com braços e n expostos. Passadas de retrieval do braço controle em peek `record_usage=false` (nunca infla RAG-01/MEM-03). Só task_classes determinísticas/read-only elegíveis; janela ociosa; kill-switch env.
- Aceite: série publica `{m_ratio, n_pairs, task_classes}` com n_pairs ≥ 8 antes de reportar; zero rows de usage das passadas controle (asserção); caso negativo: task cujo replay diverge do histórico no braço COM contexto (não-determinismo) é descartada do par com contador — nunca entra como sinal; rate e elegibilidade pinadas no freeze (ELEV-03); custo por janela reportado (ELEV-27).
- Risco: viés de seleção (só tasks determinísticas) — a série declara `coverage_share` da amostra sobre o total; o M das classes não-elegíveis permanece honesto: `unmeasured`.

**MULTX-08 — Circuit-breaker de loop degenerativo: derivada negativa por família congela o auto-apply DELA** · E:M · onda M5/M6 · deps: [MULTX-02, ASI-07 (o que é congelado), MAXI-06 (o irmão de proveniência), ELEV-25, WDG-01]
- Goal: a volta que PIORA (lição ruim → decisão pior → outcome pior → lição pior) é detectada antes da reversão em massa: sensor de derivada por família de lição com freio de máquina — MAXI-06 pega ancestral já revertido; este pega a família decaindo ANTES do primeiro tombstone.
- Mecanismo: check plugin no `AtlasWatchdogCheckRegistry` (reuso WDG-01, nunca job paralelo): por família (task_category × classe de lição, via cadeia MULTX-01), compara `proven_real_rate` das execuções que CITARAM lições da família entre K janelas consecutivas; queda monotônica além do limiar pinado E n ≥ mínimo por janela ⇒ **congela o auto-apply SÓ daquela família** (mesmo mecanismo de teto efêmero do padrão MAXK-08/ELEV-25: lei de máquina safety-increasing, nunca persiste, re-arm = decisão do operador via digest) + entrada no ledger de gaps. Itens já aplicados NÃO são revertidos automaticamente (isso é ASI-11 sob gatilho ROL-01 próprio — separação de freios). O que NÃO fazer: congelar auto-apply global (freio-avalanche); sensor sobre série de 1 janela (ruído); breaker que o próprio pipeline de learning possa re-armar.
- Aceite: fixture sintética com família decaindo 3 janelas ⇒ breaker dispara, `auto_apply` da família recusa com receipt, demais famílias intocadas (assert); caso negativo: queda com n < mínimo ⇒ NÃO dispara (`insufficient_n`); re-arm sem ação do operador ⇒ impossível por construção (teste); K, limiar e n mínimo pinados no freeze (ELEV-03); disparo real OU zero disparos com denominador ≥N publicado (nunca verde por vácuo).
- Risco: falso-positivo em família de baixo volume — o n mínimo por janela é parte do gatilho; freio disparado é SINAL no digest (freio com 0 disparos em corpus grande é freio não-testado).

**MULTX-09 — Orquestrador de janelas: caminho crítico de relógio real + watchdog de janela-morta** `[MEDIDOR]` · E:S · onda M2 · deps: [ELEV-26s (PromotionProtocol — a fonte das janelas), ELEV-20s, WDG-01]
- Goal: o programa inteiro depende de janelas seriais (observe 7d, shadow, sustain 14d, soak) e ninguém computa o relógio: um grafo read-only das janelas declaradas com deps, que paraleliza o paralelizável no papel e reporta o caminho crítico em dias de relógio — a resposta executável para "o que destrava o quê, e quando".
- Mecanismo: `atlas:windows --json` lê o ledger de flips/estados do PromotionProtocol (ELEV-26s — cada flip já declara janela mínima + critério) + os freezes de medidores; monta o DAG {janela, dep, estado, dias restantes, o-que-bloqueia}; computa caminho crítico e janelas paralelizáveis; check WDG de **janela-morta**: janela correndo cujo medidor associado não recebeu NENHUM dado em X dias ⇒ alerta (janela que expira vazia = semanas de relógio desperdiçadas em silêncio — o modo de falha real do programa). O que NÃO fazer: scheduler que INICIA janelas sozinho (flip é do operador ou do protocolo ELEV-26 — este slice só lê e reporta); estimativas de término fabricadas (só janelas com início real entram no relógio).
- Aceite: comando publica DAG com caminho crítico nomeado e dias; fixture com 2 janelas independentes ⇒ reportadas como paralelizáveis; caso negativo: janela sem dado há X dias dispara o check WDG com a janela nomeada (X pinado no freeze); janela nunca-iniciada aparece como `not_started`, jamais com ETA inventada.
- Risco: DAG desatualizado se slices declararem janela fora do protocolo — é exatamente o que ELEV-26 proíbe; janela ad-hoc detectada = gap no ledger, não exceção no grafo.

**Ordem sugerida (MULTX):** MULTX-01 e MULTX-06 primeiro (as réguas da integração congelam antes de qualquer atuador de paridade, MED-01), com MULTX-09 já em M2 (barato, e o programa inteiro ganha relógio). Depois MULTX-03 (o contrato v2 é pré-condição de denominadores honestos) → MULTX-02 (o funil, lendo o contrato v2) → MULTX-04 (a paridade de consumo que dá den>0 aos 3 executores no funil) → MULTX-05 (enforce do seam de governança) → MULTX-08 (o breaker, que precisa do funil por família) → MULTX-07 (o A/B do M, último: consome a fórmula ELEV-02 e o runner MULTJ-05 já provado).

**Colisões MULTX com v1/Max/ASI:**
- **Item 13/14 do §viii são deps, não re-propostos:** MULTX-01 transforma o "≥1 volta" (item 13) em série; a fórmula do M é 100% do ELEV-02/ASI-METRIC — MULTX-07 fornece só o braço de controle e JAMAIS redefine "valor". Nenhum MULTX fura a ordem F0→F1.
- **MULTX-03 vs ELEV-12 (`verified_share`):** o fechamento do default `verified` muda o numerador — por isso o v2 nasce versionado ao lado com dual-read MED-01; a série que ELEV-12 congelar lê UMA fonte declarada, nunca as duas fundidas.
- **MULTX-04 não cria porta de ingestão nova** (ELEV-08 intacto): reusa o produtor COM-01 e o seam ARFL do Dev; e NÃO conserta o rewrite O(N) do ledger — isso é MAXE-04/05 (dep de custo declarada).
- **MULTX-05 respeita o veredito keep-separate** da auditoria de drivers Forge: fecha o bypass por ENFORCE do seam compartilhado, não por re-roteamento pelo `AiProviderManager`.
- **MULTX-08 vs MAXI-06/ASI-11:** três freios complementares, zero duplicação — MAXI-06 = proveniência que toca revertido (grafo); MULTX-08 = derivada negativa pré-reversão (série); ASI-11 = a reversão em si (atuador). MULTX-08 congela auto-apply por família, nunca reverte.
- **MULTX-07 vs MULTJ-05/MULTK-03:** um único runner de replay (MULTJ-05) — MULTX-07 é consumidor com braço de pack suprimido; MULTK-03 replaya DECISÃO, MULTX-07 replaya EXECUÇÃO; nenhum terceiro runner nasce. Faixas de controle sempre peek (RAG-01/MEM-03 jamais inflados).
- **MULTX-09 vs ELEV-26s:** o PromotionProtocol é a ÚNICA fonte de janelas; o orquestrador é read-only por cima — janela declarada fora do protocolo é gap, não input.
- **Charter 06/07 intacto em todos:** nenhum slice cria fila de aprovação humana; o breaker MULTX-08 e o enforce MULTX-05 são leis de máquina safety-increasing com re-arm/rollback do operador; medidores v1/Max congelados intocados (toda régua MULTX é série nova com freeze próprio, ELEV-03/18).

---

### Registro histórico — briefing dos leitores da seção (xii)

(Todos os catálogos acima LANDARAM em 12/07/2026 — esta tabela fica como registro do escopo que foi pedido a cada leitor pendente na época; não é backlog.)

| Área | ID reservado | Direções centrais a avaliar |
|---|---|---|
| **12 — Execução Verificada** | **MULTV** | Cascata de verificação em camadas (estático→teste impactado→propriedade→replay determinístico→juiz advisory); mutation testing como prova de que a suíte detecta; replay determinístico de diff em worktree limpo; contratos de runtime (pré/pós-condições fail-closed); oráculos de regressão minerados de falhas reais (falha vira teste permanente); quarentena de flaky; verificação proporcional ao risco; spec-to-test binding (aceite do packet vira teste ANTES da execução); golden traces de CLI críticos. Régua: verified_share (ELEV-12) + MAXL-07 como lastro. |
| **15 — Modelo do Operador** | **MULTN15** | Limiar ask-vs-act calibrado (perguntar ou agir derivado de risco×histórico de overrides — só REDUZ perguntas, nunca cria gate novo); tolerância a risco POR DOMÍNIO aprendida de aceites/reverts reais; orçamento de atenção/interrupção (batching para digest vs interrupção imediata); simulação do operador como pre-review ADVISORY ("ele reverteria isto?" — nunca gate, calibrado ex-post com critério de morte); estilo operacional como policy rule compilada; mudança de preferência ⇒ supersedência via MAXH-04; transparência total (comando que mostra tudo que o Atlas acredita sobre o operador, com evidência e handle de correção). Floors da 18 SEMPRE vencem. |
| **Integração** | **MULTX** | A VOLTA do flywheel como objeto de 1ª classe ({task→decisão→contexto→execução→outcome→lição→recall com ref} com id encadeado; voltas/semana, tempo-por-volta); FUNIL DE PERDA DO M (% outcomes sem lição, % lições sem promoção, % promovidas sem recall, % recalls sem citação — o mapa de vazamento do multiplicador, cada estágio com denominador); paridade dos 3 executores (mesmo cérebro, mesmo seam, mesmo contrato de outcome — fechar o bypass claude_cli); LATÊNCIA DE APRENDIZADO como série (lição da volta N disponível na N+1); A/B permanente do M (faixa peek contínua motor-cru como controle); detecção de loop degenerativo (derivada negativa ⇒ circuit-breaker congela auto-apply da família — liga MAXI-06/ELEV-25); orquestrador de janelas de observação (paraleliza o paralelizável, reporta o caminho crítico de relógio). |

---

## (xiii) ESP — As 3 Espinhas Compostas + Vertical 1 (o programa que faz as áreas girarem como UM multiplicador)

> **Origem:** proposta arquitetural do revisor Codex (12/07), revisada adversarialmente e aprovada com correções. **Princípio de integração:** as espinhas ORGANIZAM e SEQUENCIAM os slices existentes (MAXx/ASI/ELEV/MULT são o estoque de peças); nenhum runtime novo, nenhum "Multiplier Engine", nenhuma 19ª área. **Esta seção É a especificação** — não existe (nem existirá) documento canônico paralelo. Contrato pétreo + leis ELEV herdados por inteiro.
>
> **Pré-condições do programa (invioláveis):**
> 1. **ESP-00 primeiro** — os números da leitura Codex divergem dos medidos pelos leitores (106 vs 77 entries; 369 vs 1.173 targets; "AWEOS 69 execuções" — órgão sem nome no mapa): NENHUM freeze de espinha antes da reconciliação.
> 2. **Cerca anti-unificação** — "Dev procedural, AEMOR e Compounding viram adapters de uma espinha única" é DESENHO DE ADAPTER, jamais fusão: a unificação de kernels já foi REFUTADA neste repo (memória `eng-kernel-unification-map`) e o padrão "hardening quebra callers" está registrado. Zero reescrita dos 3 órgãos; só o envelope comum (MULTX-03/ESP-06).
> 3. **Sem re-medição sem re-anchor** — todo número citado pelas espinhas referencia o ground-truth reconciliado do ESP-00, com file:linha.

### Mapa das espinhas → slices (as espinhas ordenam; os catálogos são as peças)

| Espinha | Áreas | Pipeline | Slices existentes que a compõem | Novos (ESP) |
|---|---|---|---|---|
| **1 — Verified Decision-to-Outcome** | 10 + 12 + 11 | `attempt_started → DecisionReceipt → invocação real → estado inicial → comandos estruturados → diff hash → test attestation → VerifiedExecutionReceipt → outcome terminal → elegibilidade para aprendizado` | MAXK-04, MULTK-01/02, MAXL-01/02/07/08, MULTV-01..10, ASI-10, ELEV-12, MULTX-03/05 | ESP-01..05 |
| **2 — Causal Learning & Temporal Memory** | 9 + 1 + 16 | `VerifiedExecutionReceipt → OutcomeEnvelope → destilação causal → candidate held → admissão única → shadow recall treatment/control → outcome posterior → yield causal → promoção/demissão → consolidação` | ASI-02/07/09, MAXJ-01..08, MULTJ-01..09, MAXH-01..10, MULTH-01..08, MULTX-06 | ESP-06..08 |
| **3 — Cognitive Opportunity & Epistemic Context** | 15 + 17 + 5 + 7 | `Belief Graph + mapa vivo de gaps + fila/yield → Opportunity Portfolio → challenger → candidato governado → Retrieval Agenda → Evidence Bundle → Decide/brain:next` | MAXN-01..06, MULTN15-01..08, MULTN17-01..08, MAXC-01/02/06, MAXE-01/06, MULTK-06 | ESP-09..12 |

### Slices ESP (os endurecimentos genuinamente novos da proposta Codex)

**ESP-00 — Reconciliação de ground-truth (pré-condição de TODO freeze de espinha)** `[HIGIENE]` · E:S · onda F0 · deps: []
- Goal: um programa não nasce sobre números não-reconciliados — as duas leituras (leitores Max 11-12/07 vs revisor Codex) divergem em fatos base.
- Mecanismo: re-medição única com file:linha/SQL na data do corte: entries ativas (77 vs 106?), targets de originação (1.173 vs 369?), o que é "AWEOS" (69 execuções de quê?), proven_real no Decide (52/3.481 confirmado?), 87 successes não-provados no ranking. Tabela reconciliada {número, fonte, comando de verificação} no Evidence Ledger.
- Aceite: tabela publicada com TODA divergência explicada (escopo diferente, data diferente, ou erro de leitor — nomeado); divergência inexplicada ⇒ issue no ledger de gaps; nenhum freeze ESP/REC antes deste receipt.
- Risco: virar auditoria infinita — escopo fechado na lista das 5 divergências nomeadas.

**ESP-01 — Denominador por `attempt_started` (fim do viés de sobrevivência)** `[MEDIDOR][pétreo]` · E:S · onda F0/M1 · deps: [ELEV-12, MAXG-01, MULTX-03]
- Goal: crash e timeout NÃO podem desaparecer por falta de outcome terminal — hoje o denominador de tudo (verified_share, proven-rates) conta execuções que TERMINARAM, não tentativas.
- Mecanismo: todo início de execução dos 3 executores grava `attempt_started`; estado terminal OBRIGATÓRIO ∈ {completed, crashed, timed_out, abandoned} (census barre attempt órfão >TTL e o marca `abandoned`); as séries v2 (verified_share, proven-rate, funil MULTX-02) recomputam sobre attempts — as v1 continuam intocadas (dual-read).
- Aceite: kill -9 no meio de uma execução ⇒ o attempt aparece como `crashed`/`abandoned` no próximo census (NUNCA some — caso negativo executável); % attempts sem estado terminal = 0 numa janela com ≥50 attempts reais; outcome registrado sem attempt correspondente ⇒ teste vermelho.
- Risco: attempts fantasma inflando denominador — attempt exige task/packet id resolvível; dedupe por id.

**ESP-02 — Comandos estruturados: `argv + cwd + timeout + env_allowlist`** · E:M · onda M2 · deps: [ASI-01, MAXK-07 (a allowlist é floor)]
- Goal: os guards de comando dos executores param de validar shell-string por prefix match — classe inteira de bypass (`bash -c "…"` disfarçado) fecha por construção.
- Mecanismo: contrato de comando {argv[], cwd, timeout_s, env_allowlist} para todo comando emitido por executor autônomo; validação sobre o argv parseado, nunca sobre a string; shell-string vira formato legado rotulado (aceito em observe, contado, e recusado pós-flip ELEV-26); a allowlist de env vive no registry de floors (ELEV-09).
- Aceite: payload `sh -c` embutido em argv "seguro" ⇒ recusado com razão nomeada (caso negativo); 100% dos comandos novos do committer/serving no formato estruturado numa janela ≥50 comandos; comando legítimo executa byte-idêntico; contador de legado exposto.
- Risco: quebrar comandos legítimos complexos — observe-primeiro com relatório por padrão de comando; migração incremental.

**ESP-03 — Test attestation com state-binding** `[MEDIDOR]` · E:M · onda M2 · deps: [ESP-01, MAXL-01, MULTV-01]
- Goal: "teste passou" vira atestado verificável: {runner reconhecido, suite, n_tests, n_assertions, exit_code, tree-hash do estado testado} — e qualquer mudança de estado APÓS o teste INVALIDA o atestado (o furo clássico: testar, depois editar, depois certificar).
- Mecanismo: o wrapper de teste dos executores emite o atestado no fim do run; o certify/land compara o tree-hash do atestado com o tree-hash do estado a landar — divergência ⇒ `attestation_stale`, re-teste obrigatório; atestado entra no receipt MULTV-01 como a camada `suite`.
- Aceite: editar 1 arquivo após o teste ⇒ certify recusa com `attestation_stale` (caso negativo vivo); atestado com runner não-reconhecido nunca conta; `n_tests=0` com exit 0 ⇒ `vacuous`, jamais verde; janela ≥20 landings: 100% com atestado ou `layers_run` sem `suite` (o zero visível).
- Risco: hash de tree caro — hash sobre os paths do diff+deps impactadas, não o repo inteiro; custo pinado.

**ESP-04 — VerifiedExecutionReceipt canônico + `certified_execution_id` obrigatório** `[CERTIFICADOR]` · E:M/L · onda M4 · deps: [ESP-01/02/03, MAXL-02, MULTV-01]
- Goal: certificadores param de aceitar mapas fornecidos pelo caller — só certificam contra um receipt canônico resolvível que encadeia {attempt, decision_id, estado inicial, argv, diff_hash, attestation, outcome terminal}.
- Mecanismo: o receipt MULTV-01 evolui para o VerifiedExecutionReceipt completo (mesma série, campos aditivos, selado MAXL-02); AWEOS/OutcomeProofGate/certificadores ENG exigem `certified_execution_id` resolvível e RECUSAM evidência recontada pelo caller (o OutcomeProofGate passa a validar o receipt canônico em vez de re-certificar fatos apresentados).
- Aceite: certificação com mapa-do-caller sem receipt ⇒ recusada (caso negativo vivo); receipt com elo faltando ⇒ `incomplete`, nunca certifica; 100% dos success claims em enforce com receipt válido numa janela ≥50; elo adulterado ⇒ seal MAXL-02 acusa.
- Risco: transição quebrar certificadores vivos — dual-accept rotulado durante a janela de migração (`basis: canonical_receipt|legacy_map`), com % legacy publicado e alvo 0.

**ESP-05 — Peso ZERO para outcome não-provado (routing e aprendizado)** `[pétreo]` · E:S · onda M2 · deps: [ESP-00 (confirmar os 87), ESP-04, MAXK-01]
- Goal: outcome sem receipt resolvível pesa exatamente 0 em routing e aprendizado — a leitura Codex aponta 87 successes não-provados possivelmente influenciando ranking (confirmar em ESP-00).
- Mecanismo: o Decide e o distiller filtram por `verified_basis ∈ {server_verified, gates_passed}` (MULTX-03); `claimed/absent` ⇒ peso 0 com contador exposto (nunca descarte silencioso — o volume de não-provado é sinal).
- Aceite: fixture com successes não-provados ⇒ ranking byte-idêntico ao ranking sem eles; teste-invariante: `basis=proven` exige receipt id resolvível; série `%outcomes_peso_zero` publicada com denominador.
- Risco: zerar demais e esfomear o cold-start — exploração é papel do MAXK-02 (braço explícito), nunca de outcome não-provado contando como provado.

**ESP-06 — OutcomeEnvelope único + 3 adapters (a cerca anti-unificação executável)** · E:M · onda M4 · deps: [MULTX-03, v1 OUTC-01, ASI-09]
- Goal: Dev procedural, AEMOR e Compounding consomem/emitem UM contrato de outcome via adapters FINOS — sem fusão, sem reescrita, sem terceira verdade.
- Mecanismo: o schema v2 do MULTX-03 é o envelope; 3 adapters de leitura/escrita (1 por órgão) mapeiam campos nativos↔envelope com campos divergentes ROTULADOS por origem (nunca coagidos); cerca executável: teste arquitetural que falha se qualquer classe dos 3 órgãos for deletada/renomeada por slice ESP (a fusão é proibida por teste, não por lembrete).
- Aceite: os 3 órgãos emitem envelope válido pelo adapter em tráfego real (produtor+consumidor ELEV-20); campo divergente aparece rotulado, nunca "normalizado" silenciosamente; teste-cerca verde; zero mudança nos consumidores nativos de cada órgão.
- Risco: adapter virar shim eterno — aceitável por design (o shim É o produto; a fusão é o anti-produto).

**ESP-07 — Treatment-before-outcome + grupos de independência** `[MEDIDOR][pétreo]` · E:M · onda M4 · deps: [MULTJ-03, MAXJ-03, ESP-04]
- Goal: matar a inferência retrospectiva no aprendizado: a atribuição tratamento/controle do shadow recall é gravada ANTES do outcome existir; e promoção exige evidências INDEPENDENTES (runs distintos, evidence roots distintos), nunca 8 repetições do mesmo run.
- Mecanismo: (a) o writer de atribuição grava `{entry, arm, assigned_at}` no momento da entrega do contexto (timestamp anterior ao outcome, verificável); o job de yield só aceita pares com `assigned_at < outcome_at`; (b) `independence_group` = hash(run_id, evidence_root) — o floor n≥8 de MAXJ-03/05 passa a contar GRUPOS, não linhas.
- Aceite: evidência com atribuição posterior ao outcome ⇒ excluída do yield com contador (caso negativo executável); 8 linhas do mesmo run ⇒ contam 1 grupo (dedupe testado); property-test: assigned_at é imutável pós-outcome.
- Risco: grupos raros atrasarem promoção — correto e honesto (`insufficient` até haver independência real).

**ESP-08 — Rollback com busca negativa em TODOS os stores e caches** `[pétreo]` · E:M · onda M4 · deps: [ASI-11, MAXH-04, MAXE-05, MAXB-09]
- Goal: forget/rollback só fecha com PROVA DE AUSÊNCIA — reverter memória sem limpar embedding/caches/AURG/working-sets é reversão de papel.
- Mecanismo: o executor de reversão (ASI-11/memory-forget) ganha passo final de busca negativa: recall + pack + AURG query + caches (MAXE-05/MAXB-09, invalidação por fingerprint) + working sets (MAXE-06/MULTH-06) NÃO retornam o ref revertido; cada busca citada no receipt de reversão.
- Aceite: reverter memória ⇒ as 5 buscas negativas executadas e citadas no receipt; cache hit servindo item revertido ⇒ teste vermelho (fixture); receipt sem as buscas ⇒ reversão `incomplete`, nunca `done`.
- Risco: custo por reversão — as buscas são locais e baratas (ms); em cascata grande, batch com receipt agregado.

**ESP-09 — Challenger independente (anti-alinhamento-cego)** `[ADVISORY]` · E:M · onda M5 · deps: [ELEV-18, MAXN-02, ASI-13, ASI-09 (adapter)]
- Goal: decisão fortemente alinhada à preferência do operador OU hipótese de melhoria recursiva (REC) recebe um challenger com engine-id ≠ autor que tenta REFUTAR antes da promoção — o sistema não pode ser bajulador de si nem do operador.
- Mecanismo: gatilho derivado (alinhamento acima de banda pinada OU decision_kind ∈ {melhoria recursiva, obra composta}) ⇒ challenger autora a melhor alternativa/refutação (adapter ASI-09, engine-id distinto provado — ELEV-18); ADVISORY estrito: o bloco challenger entra no receipt e no digest; os gates deterministas continuam os únicos que decidem; promoção elegível ao challenger sem bloco ⇒ ADIADA (não vetada) até o bloco existir.
- Aceite: decision receipt de alta-afinidade carrega bloco challenger com `challenger_engine_id != author_engine_id` assertado; caso negativo: decisão de baixa afinidade NÃO gera challenger (custo controlado); refutações acatadas/ignoradas viram série com denominador (alimenta a calibração ASI-15).
- Risco: challenger-teatro (refuta fraco sempre) — a série refutação-acatada é vigiada; challenger com taxa de acerto ~0 em 2 janelas entra em revisão (critério de morte, padrão MAXJ-08).

**ESP-10 — Operator Belief Graph: crenças refutáveis com contraprova** · E:M/L · onda M5 · deps: [MAXN-01/02/03, MULTN15-01/07]
- Goal: o perfil plano vira grafo de crenças {claim, onde vale, onde NÃO vale, validade temporal, evidência a favor, CONTRAPROVA, exceções, confiança calibrada} — preferência desempata e restringe, nunca apaga evidência contrária.
- Mecanismo: estende `OperatorProfileItem` com campos aditivos {scope_valid, scope_invalid, counter_evidence_refs[]}; o juiz MAXN-03 grava contraprova em vez de só decair; o composer injeta a crença COM a contraprova quando ambas existem (o consumidor vê o conflito, não uma verdade lisa); supersedência via MULTN15-07.
- Aceite: crença com contraprova recente ⇒ confiança cai E o par {evidência, contraprova} aparece no digest e no bloco `operator_model`; crença sem evidência além do TTL ⇒ `unverified`; floors da 18 vencem (invariante herdado); caso negativo: contraprova NUNCA deleta a crença (demoção só).
- Risco: grafo virar segunda fonte de verdade — campos aditivos no store existente, zero tabela paralela.

**ESP-11 — Retrieval Agenda epistemológica (claims, unknowns, contraevidência, parada por ganho)** · E:M · onda M5 · deps: [MAXC-01/02, MAXC-06, MULTX-01]
- Goal: o retrieval trabalha com AGENDA {claims a provar, fonte autoritativa esperada, unknowns essenciais, evidência CONTRÁRIA, condição de parada por ganho informacional} — a evolução do bloco de suficiência de cobertura-de-facetas para cobertura-de-CLAIMS.
- Mecanismo: o extrator MAXC-01 ganha a camada de claims (afirmações verificáveis citadas na task); o bloco MAXC-02 passa a reportar por claim {refs a favor, refs CONTRA (busca de contraevidência em ≥1 fonte — não só confirmação), unknowns nomeados}; parada: sub-queries cessam quando o ganho informacional marginal < limiar pinado (extensão do critério de parada MAXC); calibração ex-post herda MAXC-06.
- Aceite: task com claim explícito ⇒ agenda lista o claim + fonte esperada; unknown essencial sem hit ⇒ `not_enough_context=true` com o unknown NOMEADO; a busca de contraevidência roda e o slot `contra` aparece (mesmo vazio-honesto); caso negativo: task sem claims ⇒ agenda vazia e pack byte-idêntico ao MAXC atual.
- Risco: agenda inflar latência — cabe no budget de sub-queries existente do MAXC-01 (peek), nunca passadas extras ilimitadas.

**ESP-12 — Evidence Bundle: o pack epistêmico completo** · E:M/L · onda M5/M6 · deps: [MAXE-01/06/08, RAGX-08, ESP-11, MULTH-06]
- Goal: o pack ganha as 5 camadas epistêmicas que faltam: `must_carry` (constituição/invariantes SEMPRE presentes em task de risco), `novelty_pool` (anti-repetição além do working set), policy do operador SEPARADA dos fatos, seção de CONTRAEVIDÊNCIA, e citação por claim/span/content-version.
- Mecanismo: extensões do packFor por cima dos seams existentes: must_carry = lista curta versionada (registry ELEV-09) injetada por perfil de risco antes do budget normal; novelty_pool compõe com o seen-refs do MAXE-06; policy do operador (MAXN-02/MULTN15-06) renderizada em bloco separado de fatos (parse distinto); contraevidência vem da agenda ESP-11; citação claim-level estende os refs MAXE-01 com `content_version`.
- Aceite: task de risco irreversible ⇒ invariante pétreo presente no pack (must_carry testado, mesmo com budget apertado); fato e policy nunca no mesmo bloco (parse testado); citação claim-level resolve para content-version exato (fixture); caso negativo: task trivial ⇒ zero overhead das camadas novas (budget intocado).
- Risco: pack inchar — must_carry tem cap duro pinado; as camadas novas degradam honesto sob budget (a ordem de corte é declarada).

### MARCO ESP-V1 — Vertical 1 no Autônomos (a primeira volta completa; gate de saída de F1)

A primeira implementação atravessa as 3 espinhas numa task de engenharia REAL do Autônomos — **o item 13 do critério ASI transformado de verificação final em ENTREGÁVEL organizador**:

1. Originação escolhe oportunidade por leverage + proven-yield (MAXN-04/MULTN17-03) → 2. Challenger apresenta alternativa (ESP-09) → 3. Decide emite receipt (MAXK-04 + MULTK-01) → 4. Retrieval cria agenda + evidence bundle (ESP-11/12, mínimo: MAXC-01/02) → 5. Autônomos executa com attempt registrado (ESP-01) e comandos estruturados (ESP-02) → 6. Estado, diff e testes ligados criptograficamente (ESP-03/04 + MAXL-02) → 7. Outcome terminal vira `proven_real` só com receipt (ESP-05) → 8. Distiller cria lesson candidate com `caused_by` (MAXJ-02/ASI-09) → 9. Candidate entra em shadow recall com treatment-before-outcome (ESP-07) → 10. Execuções posteriores formam tratamento e controle → 11. Yield causal decide promoção/demissão (MAXJ-05/MULTJ-03) → 12. Memória temporal consolida com rollback provado por busca negativa (MAXH-04 + ESP-08).

**Aceite do marco:** 1 task real atravessa os 12 passos com ids encadeados verificáveis no Evidence Ledger (a volta MULTX-01 completa, `loops_complete ≥ 1` com definição válida); NENHUM passo satisfeito por fixture/phpunit (guard ASI-05); o marco é o gate de saída de F1 do programa ASI — **F2 não abre sem ele**. Depois do marco, os slices restantes espessam um caminho que JÁ funciona, em vez de construir 100 peças esperando o circuito fechar no fim.

### Critérios pétreos da seção (consolidados da proposta Codex; os já-existentes citam a lei)

- 100% dos success claims em enforce com VerifiedExecutionReceipt válido (ESP-04).
- 100% dos attempts com receipt terminal ou estado explícito `crashed/timed_out/abandoned` (ESP-01).
- Zero outcome não-provado influenciando routing ou aprendizado (ESP-05).
- Zero write cognitivo fora da admissão/adapter autorizado (= ASI-02 + ELEV-08, já lei).
- Flywheel: ≥10 treatment + ≥10 control, ≥3 flows, ≥2 tipos de memória antes de qualquer claim de lift (ESP-07 + MULTJ).
- Promoção exige ≥8 evidências INDEPENDENTES por assinatura (ESP-07; o n≥8 já era lei MAXJ).
- Originação mede `proven_real_value / opportunity_budget`, nunca quantidade de tasks (= anti-proxy pétreo, já lei).
- Forget/rollback prova busca negativa em todos os stores e caches (ESP-08).
- Sem massa: `insufficient_signal`, nunca verde (= lei geral do plano).

### O que NÃO construiremos (confirmado + herdado)

Novo OS ou 4º executor · corpus canônico paralelo · ledger separado por executor · LLM como juiz de teste/integridade/decisão (advisory only, sempre) · score único "9,8/10" · clustering sofisticado para corpus pequeno (MAXD-05/RAGX-10 já gated) · UI nova antes dos loops funcionarem · slices que só provam presença de classes (lei ELEV-20 os torna impossíveis) · **especificação canônica paralela — esta seção é a spec.**

---

## (xiv) REC — Teto: Recursive Intelligence governada (o originador apontado para o ACOS)

> **Origem:** teto máximo proposto pelo revisor Codex (12/07) — o ACOS como sistema de melhoria recursiva governada: `Observar → Modelar → Hipotetizar → Desafiar → Experimentar → Verificar → Aprender → Consolidar → Escolher a próxima melhoria → Simplificar/evoluir → Observar`. **Aprovado com 4 correções da revisão adversarial**, que são LEI desta seção:
> 1. **O meta-otimizador NÃO é um órgão novo** — é o originador (área 17, `atlas:brain:next`) consumindo {funil de perda do M (MULTX-02), modelo-de-si (ASI-13), séries regredindo, R histórico} e emitindo objetos-hipótese. Construir um "Meta-Optimizer Engine" separado violaria reuso-antes-de-construção e seria a camada aspiracional que a própria proposta rejeita.
> 2. **M e R são ponderáveis pelo OPERADOR** — num sistema pessoal, o teto não é "melhorar a si", é melhorar no que o operador valoriza (REC-05).
> 3. **O loop recursivo tem freio próprio** — auto-envenenamento de 2ª ordem e sobrecarga de revisão são cobertos explicitamente (REC-06).
> 4. **Sem especificação paralela** — esta seção é o canon; o "escrever a spec completa e decompor" proposto criaria o 5º documento de planejamento.

### Mapa de consolidação — as "6 capacidades superiores" → onde já vivem (não re-nascem com nome novo)

| Capacidade (Codex) | Onde já vive no plano | O que REC adiciona |
|---|---|---|
| 1. Modelo causal de si | ASI-13 (basis proven/declared/inferred) + ASI-15 (bandas) + MULTK-01 (incerteza) + MULTK-04 (abstenção) | nada novo — consumo pelo meta-loop (REC-04) |
| 2. Motor de hipóteses/falsificação | MED-01 + ROL-01 + ELEV-03 + ELEV-26 (o protocolo de promoção JÁ é o método científico espalhado) | o OBJETO-hipótese único (REC-01) + challenger (ESP-09) |
| 3. Experimentação ativa | MULTJ-03/05, MAXL-07, MAXK-02, ADV-Max | o SCHEDULER por valor-de-informação (REC-02) |
| 4. Memória hierárquica temporal | MULTH-04 (tiers) + MAXH (verdade temporal) + MAXK-07 (via lenta constitucional) | nada novo — a via de 2 velocidades já existe |
| 5. Portfólio cognitivo | MULTK-06 + MULTN17-05 (apostas + ELEV-28) | dimensões valor-de-opção/redução-de-incerteza entram no ranking de REC-02 |
| 6. Meta-otimizador governado | **é o brain (área 17) + MULTN17-03 (scanner) apontados para o ACOS** | o wiring + protocolo + shadow (REC-04) |

### Slices REC

**REC-01 — Objeto-hipótese canônico (o contrato único do método científico)** `[pétreo]` · E:S/M · onda M5 · deps: [MED-01, ROL-01, ELEV-26s, ELEV-31]
- Goal: toda evolução importante nasce como hipótese TESTÁVEL num contrato único — hoje os ingredientes (métrica congelada, falsificador, rollback) existem espalhados por 3 leis.
- Mecanismo: schema `hypothesis.v1` {mudança proposta, mecanismo causal esperado, métrica congelada (ref do freeze), resultado esperado, FALSIFICADOR (condição que refuta), tratamento/controle, orçamento, rollback real pré-declarado, custo arquitetural permitido, **comparativo obrigatório das 3 alternativas (ELEV-31): não fazer nada · simplificar o existente · remover uma camada**}; toda promoção via ELEV-26s passa a referenciar um objeto-hipótese; refutação é APRENDIZADO registrado (nunca some).
- Aceite: flip sem objeto-hipótese completo ⇒ recusado pelo protocolo; falsificador disparado ⇒ rollback executa E o resultado negativo entra no ledger como lição; campo faltando ⇒ objeto inválido (schema testado); o comparativo das 3 alternativas presente em 100% das hipóteses (grep no ledger).
- Risco: burocracia — o objeto tem ~10 campos que os slices JÁ produzem hoje espalhados; é consolidação, não formulário novo.

**REC-02 — Scheduler de experimentos por valor-de-informação (o dono do relógio)** · E:M · onda M5/M6 · deps: [REC-01, MULTX-09 (o DAG de janelas), ELEV-27]
- Goal: o recurso escasso do programa é RELÓGIO de janelas de observação — o scheduler prioriza o experimento que mais reduz incerteza × ganho potencial ÷ ocupação de janela, e mantém a regra 1-flip-por-família-por-janela.
- Mecanismo: consome o DAG do MULTX-09 + os objetos-hipótese pendentes (REC-01); ranking por VOI determinístico {largura do intervalo da métrica-alvo (MULTK-01-style), ganho esperado declarado, dias de janela, custo ELEV-27}; paraleliza famílias independentes; publica a agenda de experimentos no digest; **propõe, nunca inicia** — o início segue ELEV-26s/operador.
- Aceite: 2 hipóteses independentes ⇒ agendadas em janelas sobrepostas (paralelização provada); 2 da mesma família ⇒ NUNCA sobrepostas (atribuição limpa, teste); a agenda publica o caminho crítico com datas; caso negativo: hipótese sem falsificador ⇒ inelegível para agenda.
- Risco: VOI virar chute — os componentes são todos medidos/pinados; hipótese com componentes `unmeasurable` vai para o fim da fila, nunca some.

**REC-03 — Métrica R pinada: velocidade de melhoria por custo** `[MEDIDOR]` · E:S · onda M5 · deps: [ELEV-02 (M), ELEV-20s (registry), MAXG-01, ASI-15]
- Goal: `R = ΔM / (custo + complexidade + risco)` — o indicador de que a recursão PAGA. Sem operacionalizar o denominador, R seria o "92" do meta-nível (termos vagos = número de vibes).
- Mecanismo: denominador OPERACIONALIZADO no freeze (ELEV-03): custo = tokens + wall-clock do experimento (MAXG-01/ELEV-27); complexidade = **delta no registry de órgãos/flags/séries vivas (ELEV-20s)** — cada órgão/flag/série adicionado soma, cada um removido SUBTRAI (a simplificação ganha crédito mecânico); risco = banda derivada (ASI-15). ΔM lido da série ELEV-02. Publicado por hipótese promovida, componentes crus sempre ao lado.
- Aceite: R publicado com os 3 componentes crus por hipótese; componente não-mensurável ⇒ `unmeasurable` (nunca estimado em silêncio); property-test: remover um órgão do registry ⇒ complexidade negativa ⇒ R sobe (o incentivo à simplificação é mecânico); série de R por janela com denominadores no registry ELEV-20s.
- Risco: gaming por sub-declarar complexidade — a complexidade é lida do REGISTRY (que a lei ELEV-20 obriga), nunca declarada pelo autor da hipótese.

**REC-04 — Meta-loop: o originador apontado para o ACOS (SHADOW até a Vertical 1 provar M>1)** `[ALAVANCA]` · E:M/L · onda M6 · deps: [MULTX-01/02 (a volta + o funil), ASI-13, REC-01/02/03, ESP-09 (challenger), MARCO ESP-V1]
- Goal: quem escolhe "qual capacidade do ACOS evoluir" é o BRAIN — o meta-otimizador é wiring do originador existente, não engine novo.
- Mecanismo: classe de lead nova `acos_capability` no scanner MULTN17-03: {estágio do funil MULTX-02 vazando acima do alerta, série regredindo, R histórico baixo de uma família, capacidade com gap no modelo-de-si ASI-13} viram leads com evidência; `produce()` origina hipóteses de melhoria (objetos REC-01, com as 3 alternativas ELEV-31 e challenger ESP-09 obrigatório); saídas {promover, manter em shadow, refinar, reverter, simplificar, REMOVER}; **SHADOW estrito até o MARCO ESP-V1 provar M>1 medido (ELEV-02) — e o flip shadow→atuar é gatilho EXCLUSIVO do operador (linha nova na Tabela de Gatilhos do §viii)**. O que NÃO fazer: Meta-Optimizer Engine novo; hipótese sem evidência de funil; auto-flip.
- Aceite: em shadow, ≥3 hipóteses de melhoria geradas com lead de funil citado (evidência real, não narrativa); capacidade SEM vazamento no funil ⇒ NUNCA gera hipótese (caso negativo — anti-busywork); zero atuação em shadow (teste); cada hipótese carrega o comparativo das 3 alternativas + bloco challenger.
- Risco: o meta-loop otimizar as próprias métricas — as réguas que ele consome (funil, M, R) são congeladas por juízes ELEV-18 que ele não controla; e o breaker REC-06 vigia a derivada.

**REC-05 — M ponderado pelo operador (o teto de um sistema PESSOAL)** `[MEDIDOR]` · E:S · onda M5 · deps: [ELEV-02, MAXN-02]
- Goal: melhoria com ΔM alto numa classe de tarefa que o operador nunca usa vale menos que ΔM médio no fluxo diário dele — o valor não é neutro num sistema de 1 operador.
- Mecanismo: série `M_operator` publicada AO LADO do M neutro (nunca substituindo — duas séries, formula_version próprias): pesos por classe de tarefa DERIVADOS das policy rules compiladas + frequência real de uso (MAXN-02/MULTN15), nunca free-text; mudança de peso = série nova.
- Aceite: as duas séries publicadas com pesos citados e fonte das rules; peso sem rule compilada correspondente ⇒ inválido (teste); dual-view no digest.
- Risco: pesos enviesarem a originação para conforto — o M neutro continua publicado ao lado (a divergência entre os dois é ela própria sinal).

**REC-06 — Freio recursivo: auto-envenenamento de 2ª ordem + sobrecarga de revisão** `[pétreo]` · E:M · onda M6 · deps: [MAXI-06, MULTX-08, ELEV-25, REC-04]
- Goal: o loop que se auto-melhora é o lugar do envenenamento em 2ª ordem (funil contaminado → hipótese errada → "melhoria" que piora → funil mais contaminado) e da sobrecarga do único humano.
- Mecanismo: (a) MAXI-06 estendido ao meta-nível: a linhagem de cada hipótese REC até os dados de funil é verificada — hipótese cuja evidência desce de item revertido/contaminado ⇒ `provenance_suspect`, não promove; (b) o breaker MULTX-08 cobre a família "melhorias recursivas": ΔM negativo sustentado por K janelas ⇒ meta-loop VOLTA a shadow sozinho (lei de máquina safety-increasing; re-arm do operador); (c) ELEV-25 vigia a carga: review-debt acima do cap ⇒ cadência de hipóteses novas desacelera automaticamente.
- Aceite: fixture funil-contaminado ⇒ hipótese `provenance_suspect` retida (caso negativo vivo); ΔM negativo sustentado ⇒ shadow automático com receipt; review-debt acima do cap ⇒ cadência cai no ciclo seguinte (teste); re-arm sem operador ⇒ impossível por construção.
- Risco: freio excessivo paralisar a recursão — os 3 gatilhos têm n mínimo e limiar pinados (ELEV-03); freio disparado é sinal no digest, nunca silêncio.

### Gatilho do operador (linha ADICIONADA à Tabela de Gatilhos do §viii)

| Gatilho (SÓ o operador) | Como | O que deve estar VERDE antes |
|---|---|---|
| Meta-otimizador REC-04: shadow → atuar | config/env do meta-loop | MARCO ESP-V1 com **M > 1 medido** (série ELEV-02); **R > 0** (REC-03) em avaliação com juiz independente (ELEV-18); freios REC-06 verdes (breaker armado + review-debt sob cap); ≥3 hipóteses de shadow com challenger e evidência de funil |

### Sequência do teto (= a sequência do plano; nada fura F0→F1)

1. Restaurar verdade operacional (F0 + ESP-00) → 2. Fechar Espinha 1 (Decisão→Execução→Prova) → 3. Fechar Espinha 2 (Outcome→Aprendizado→Memória→Retrieval) → 4. Espinha 3 (Portfolio, Agenda, Belief Graph) → 5. Challenger + experimentos controlados + modelo causal (ESP-09, REC-01/02/03) → 6. Meta-otimizador em SHADOW (REC-04) → 7. Promoção da recursão SÓ após lift causal repetido (M>1, R>0, gatilho do operador) → 8. Expandir para providers/modalidades/domínios.

**Regra final (herdada e reafirmada pela última vez):** o sistema só está evoluindo quando `M > 1` e `R > 0` em avaliação independente — e se fechar qualquer item desta seção exigir relaxar um medidor, afrouxar um gate ou reocupar um campo aposentado, o programa está refutado naquele ponto e o slice volta para a fase do defeito com justificativa no ledger de gaps.

---

## (xv) TETO — Os últimos degraus (varredura final: onde o teto declarado não tinha mecanismo)

> **Origem:** varredura adversarial de 12/07 (pós-xiv) com a pergunta "onde o teto DECLARADO ainda não tem slice que o alcance?". Achados em 4 blocos: **buracos na TESE** (o N×M assumido sem prova, a métrica-fim ausente, o ativo de soberania evaporando), **tetos declarados com zero slices** (multi-domínio, dogfooding da obra), **teto operacional** (obra serial, cadências, cockpit) e **dívidas de simetria** (áreas sem veredito explícito, digest-lista). Contrato pétreo + leis ELEV herdados por inteiro. TETO-06 e TETO-09 pousam ANTES do executor começar (L0).

**TETO-01 — N-Capture Drill: a prova periódica da tese N×M** `[MEDIDOR][pétreo]` · E:M · onda M3 (L5) · deps: [MULTK-01, MAXK-01, ELEV-29s, ELEV-26s; MULTX-07 quando existir (braço do M)]
- Goal: o claim central do Atlas ("provider salta N× ⇒ Atlas captura automaticamente via wrapper governance") vira EXERCÍCIO MEDIDO — hoje é fé sem denominador.
- Mecanismo: drill com gatilho duplo (engine novo disponível OU 180 dias): ativar um engine já instalado e não-roteado (candidatos reais: GLM/variante Hermes) no registry de capacidades/AiProviderManager; medir `{time_to_first_routed_task, time_to_first_proven_real, horas_de_integração}`; rodar o yardstick (golden v2 + regret MAXK-01) com o engine novo em faixa peek; publicar série `n_capture_drill.v1` (freeze §0.6 do playbook). O motor é músculo: ZERO mudança na espinha. NÃO fazer: pinar modelo; promover engine sem yardstick; degradar tráfego real (tudo peek/shadow).
- Aceite: 1 drill executado com os 3 tempos publicados e denominadores; engine novo elegível a rota SÓ pelo caminho normal (cold-start via MAXK-02, nunca bypass); caso negativo: engine que falha o yardstick NÃO entra no pool, com receipt do porquê.
- Risco: drill-teatro com engine de brinquedo — o candidato tem que cumprir a spec de capacidades ELEV-29.

**TETO-02 — `mission_e2e_rate`: a métrica-fim da tese (pedido em linguagem natural → resultado completo)** `[MEDIDOR]` · E:S · onda M1 (L2) · deps: [MED-01, ELEV-03/18; leitura do Mission/Follow-Through existente]
- Goal: medir o que o operador SENTE — o ELEV-02 mede proxies internos; nenhuma série mede "pedidos completados fim-a-fim com o mínimo de perguntas", que é a tese canônica literal.
- Mecanismo: leitor read-only sobre missions/follow-through + approvals: por janela `{pedidos reais do operador, completados fim-a-fim sem intervenção além das aprovações previstas, perguntas/asks por pedido, tempo pedido→entrega p50/p95}`; série `mission_e2e.v1` congelada; **entra como item 18 do critério de conclusão** (alvo pinado no freeze, ex.: e2e_rate ≥ 0,70 com n ≥ 20 pedidos reais). NÃO fazer: contar task interna como pedido (definição de "pedido do operador" pinada no freeze); reduzir perguntas pulando gate (floor vence, sempre).
- Aceite: `--json` com os 4 números e denominadores crus; janela sem pedidos ⇒ `insufficient_signal`; caso negativo: missão abandonada conta como não-completada (nunca some do denominador).
- Risco: gaming por reclassificação — a definição de pedido é frozen; mudança = emenda.

**TETO-03 — Trajectory Vault: o ativo de treino soberano** `[pétreo]` · E:S/M · onda M4 (L7) · deps: [ESP-04 (receipt canônico), ASI-05, MAXM-01 (régua de leak), ELEV-24/27 (rotação/orçamento)]
- Goal: a Espinha 1 vai produzir o insumo mais valioso que existe — trajetórias de execução VERIFICADAS — e sem coletor esse ativo evapora; o futuro declarado (modelo próprio self-host) nunca terá matéria-prima.
- Mecanismo: exportador governado que decanta VerifiedExecutionReceipts em registros `{task_input, contexto entregue (refs), ações estruturadas (argv/diff), outcome, verificação}` num dataset local versionado (`storage/atlas/trajectory-vault/`, schema versionado, dedupe por receipt hash, rotação/orçamento declarados); classes sensitive/secret/cyber EXCLUÍDAS por construção (mesmo class-gate da porta); ZERO saída da máquina. Consumidor declarado: fine-tune local futuro (fora deste plano — o vault é o ativo, não o treino). NÃO fazer: exportar a provider; payload cru de classe protegida; vault como meta de volume.
- Aceite: ≥20 trajetórias reais decantadas com schema validado; teste de privacy vivo: receipt com marcador sensitive ⇒ AUSENTE do vault (caso negativo); grep-prova de zero egress; série de crescimento no registry ELEV-20s (informativa, nunca meta).
- Risco: data swamp — schema versionado + orçamento de disco + rotação; qualidade herda a verificação (só receipt selado entra).

**TETO-04 — Segundo domínio instrumentado: o M fora de engenharia** · E:M/L · onda M6 (L10; gated MARCO ESP-V1) · deps: [MARCO ESP-V1, MULTJ-08, v1 OUTC-01, ASI-02, ESP-06 (adapters)]
- Goal: o passo 8 da sequência do teto ("expandir para domínios") tem ZERO slices — o M inteiro é medido em engenharia; "domain-general" é claim de um domínio só até esta prova.
- Mecanismo: escolher o domínio não-engenharia com TRÁFEGO REAL existente (candidato: Afiliado/Conversion OS); instrumentar o MÍNIMO das Espinhas 1+2 nele via adapters (ESP-06): outcome com receipt, lição pela porta ASI-02 com escopo do domínio, recall medido em peek; transferência de princípios só via MULTJ-08 (A/B no destino). NÃO fazer: reconstruir espinha por domínio; tocar as leis pétreas do funil do afiliado sem OK do operador.
- Aceite: `atlas:flywheel:loops` reporta ≥1 volta completa `proven_real` com `domain != engineering`; lição do domínio recallada e citada em execução posterior do MESMO domínio (ELEV-20); caso negativo: lição de engenharia NÃO entra no pack do domínio sem A/B positivo.
- Risco: domínio sem volume ⇒ `blocked` honesto com registro — a escolha é pelo tráfego real, nunca pela vontade.

**TETO-05 — Obra-Retro: a obra alimenta o flywheel que ela constrói** · E:S · onda F0 (L1; cadência a cada fecho de lote) · deps: [v1 OUTC-01; ASI-02 quando landar (antes: candidatos ficam `held`)]
- Goal: os 255 slices executados são os outcomes mais ricos do período — o sistema de aprendizado-por-execução não pode ignorar a própria construção.
- Mecanismo: no fecho de CADA lote, o executor emite: (a) outcomes da obra pela espinha OUTC-01 (slice landed/refutado/suspenso, com evidência, actor tag `obra:acos-max` — padrão ASI-12, série separável); (b) candidatos de lição pela fila NORMAL (padrões de fricção: "aceites da área X travam em Y", drift de anchors, gargalos de janela) — CaptureQualityGate + porta, zero via especial. NÃO fazer: auto-promover lição da obra; misturar outcomes de obra nas séries de produto sem tag.
- Aceite: fecho de lote gera ≥1 outcome taggeado + candidatos na fila; ≥1 lição promovida da obra é recallada em lote posterior (consumidor provado — ELEV-20); caso negativo: candidato boilerplate da obra rejeitado pelo gate (o filtro vale para a obra também).
- Risco: eco de processo virando ruído — o gate é o mesmo de sempre; taxa de rejeição alta é resultado aceitável e registrado.

**TETO-06 — Execução paralela da obra (protocolo multi-engine)** · E:S · onda M0 (L0 — ANTES do executor começar) · deps: [ELEV-22 (claims)]
- Goal: 255 slices seriais = trimestres de wall-clock; famílias independentes executam em paralelo sem colisão.
- Mecanismo: extensão de PROTOCOLO (playbook/scoreboard, não código): claim por FAMÍLIA×lote no blackboard; scoreboard ganha anotação `claimed_by:<engine>` por item; arquivos-quentes (lista ELEV-22) serializados por claim; commits continuam atômicos por slice; gate de lote só fecha com TODAS as famílias terminais; "1 flip por família por janela" vale POR FAMÍLIA (paralelismo não fura atribuição). NÃO fazer: 2 engines no mesmo slice; paralelizar dentro de família com deps encadeadas.
- Aceite: playbook+scoreboard atualizados com o protocolo; bancada: 2 engines em famílias distintas do mesmo lote sem tocar o mesmo arquivo (claims provados); caso negativo: claim ativo de outro engine ⇒ o segundo pula com registro.
- Risco: race no scoreboard — atualização por-linha em commit atômico por slice; conflito = reler do disco e reaplicar (regra A11 já cobre).

**TETO-07 — Model-refresh drill: cadência de candidatos locais** · E:S · onda M6 (L10) · deps: [MAXA-04 (o harness), ELEV-29s (spec), ELEV-19 (manifest)]
- Goal: o melhor modelo local de hoje não é o de daqui a 6 meses — a reavaliação vira cadência com régua, não evento único.
- Mecanismo: drill trimestral: rodar o harness do MAXA-04 (golden v2 + R8, dual-read) contra candidatos que cumpram a spec ELEV-29; promoção SÓ por vitória com margem pinada no freeze; manifest sha256 (ELEV-19) atualizado no mesmo commit; resultado negativo REGISTRADO ("não trocar" também é dado).
- Aceite: 1 drill com tabela candidato×métrica publicada; modelo promovido OU mantido com receipt; caso negativo: candidato que viola a spec nem entra no harness; máximo 1 troca por trimestre (pinado).
- Risco: churn de modelo — margem mínima + teto de troca; provenance MAXA-03 torna toda troca reversível.

**TETO-08 — Cockpit único do programa: `atlas:acos:cockpit --json`** · E:S · onda M2 (L3) · deps: [MAXG-01; consome ELEV-02/12/25, MULTX-01/02/09, ELEV-26s à medida que landam]
- Goal: o estado do programa inteiro numa leitura — a atenção do operador é o recurso mais escasso do sistema, e hoje o estado vive em ~8 comandos.
- Mecanismo: agregador READ-ONLY: `{M, R, voltas/funil, janelas+caminho crítico, flips pendentes, review-debt, freios armados, lote corrente do scoreboard}`; fonte ainda-inexistente ⇒ `unavailable` honesto; todo campo é passthrough com a fonte citada (zero cálculo próprio — nunca vira segunda verdade); CLI/JSON only (dashboard segue vetado — terminal-first).
- Aceite: comando roda HOJE com as fontes existentes e `unavailable` nas futuras; campos acendem conforme as fontes landam sem mudança de schema (aditivo); zero write (teste).
- Risco: virar segunda verdade — proibição de cálculo próprio é invariante testado.

**TETO-09 — Veredito explícito das áreas 2/11/14 (segunda passada OU teto declarado)** `[HIGIENE]` · E:S · onda M0 (L0) · deps: []
- Goal: Captura&Imunidade (2), Evidência (11) e Porta&Provider (14) não receberam catálogo MULT — hoje a decisão está IMPLÍCITA; teto máximo exige decisão assinada.
- Mecanismo: para cada uma das 3 áreas: OU (a) lançar leitor de fronteira no formato exato da seção (xii) (mesma especificação, catálogo MULT), OU (b) registrar no plano + Evidence Ledger a declaração datada: "teto desta área = endurecimento; atingido por MAXI/MAXL/MAXM + ASI + ESP; segunda passada não agrega; reavaliação gatilhada por incidente OU por vazamento no funil MULTX-02 apontando para a área".
- Aceite: as 3 áreas com veredito registrado (catálogo landado OU declaração no ledger com gatilho de reavaliação escrito); zero área em estado implícito.
- Risco: declarar teto cedo — o gatilho de reavaliação escrito na própria declaração é a válvula.

**TETO-10 — Digest como produto de revisão (o gargalo de 2026 atacado de frente)** · E:M · onda M5 (L9) · deps: [ELEV-25, MULTN15-08, ASI-07/MAXH-04 (handles), ASI-11 (linhagem), v1 FEE-12]
- Goal: revisar deixa de ser ler-lista-e-caçar-handle — o próprio doc de foco diz que VERIFICAÇÃO/review é o gargalo de 2026, e o digest de hoje é contagem.
- Mecanismo: o gerador do digest evolui: agrupamento por decision-id/família (linhagem ASI-11); ordenação pela banda predicted-revert (MULTN15-08); cada item com `{evidência citada, diff-ref, comando de reversão PRONTO inline}`; seção consolidada de flips pendentes; batched-asks (MULTN15-05) integradas. Terminal-first: markdown/CLI, zero UI nova.
- Aceite: digest real com ≥10 itens agrupados e handles executáveis — rodar 1 revert direto do digest é o teste vivo; tempo-de-revisão por item (proxy ELEV-25) cai vs baseline (dual-read); caso negativo: item sem handle aparece rotulado `manual_review`, nunca como revisável.
- Risco: digest inchar — caps por seção; a ordenação por banda faz o topo valer o tempo.

**Ordem TETO:** TETO-06 e TETO-09 em L0 (antes do executor) → TETO-05 em L1 (cadência desde então) → TETO-02 em L2 (freeze junto das réguas) → TETO-08 em L3 → TETO-01 em L5 → TETO-03 em L7 → TETO-10 em L9 → TETO-04 e TETO-07 em L10.

**Critério de conclusão — itens ADICIONADOS:** item 18 = `mission_e2e_rate` ≥ alvo pinado com n≥20 pedidos reais (TETO-02); item 19 = ≥1 N-Capture Drill executado com tempos publicados (TETO-01); item 20 = ≥1 volta proven_real fora de engenharia (TETO-04); item 21 = Trajectory Vault com ≥20 trajetórias e privacy provada (TETO-03).

### TETO-09 — Vereditos assinados (opção b: teto = endurecimento; sem catálogo MULT)

> Datado **2026-07-11**. Autoridade: slice TETO-09. Espelho no Evidence Ledger (`EVIDENCE_PACKED`, slice_id=`TETO-09`). Segunda passada MULT **não** é aberta para estas áreas; reavaliação só pelos gatilhos abaixo.

| Área | Nome | Famílias que atingem o teto | Veredito | Gatilho de reavaliação |
|---|---|---|---|---|
| **2** | Captura & Imunidade | MAXI + ASI-02 (+ ELEV-08 in-place) + ESP (imunidade na espinha) | **teto = endurecimento** — catálogo MULT não agrega além de MAXI/ASI/ESP | incidente de admissão/poison **OU** vazamento no funil MULTX-02 apontando para a área 2 |
| **11** | Evidência & Certificação Longitudinal | MAXL + ASI-11 + ESP-04/recibo + ELEV evidence | **teto = endurecimento** — catálogo MULT não agrega além de MAXL/ASI/ESP | incidente de integridade/selo/cadeia **OU** vazamento no funil MULTX-02 apontando para a área 11 |
| **14** | Porta do Cérebro & Provider-safety | MAXM + ASI (hooks/porta) + ESP (provider-bound) | **teto = endurecimento** — catálogo MULT não agrega além de MAXM/ASI/ESP | incidente de provider-leak/projeção **OU** vazamento no funil MULTX-02 apontando para a área 14 |

Caso negativo do aceite: qualquer uma das 3 áreas sem linha nesta tabela (ou sem receipt no ledger) ⇒ TETO-09 **não** fecha.

---

---
