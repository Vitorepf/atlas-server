# Atlas · Roadmap PROFUNDO de Contexto Cross-Project (deepening profissional)

> **Doc TEMPORÁRIA de staging — NÃO é AP.** Versão aprofundada da
> `ATLAS-CROSS-PROJECT-ULTRA-PRECISE-CONTEXT-ROADMAP.md` (a 1ª passada). Consolida TODOS os
> blocos + crítica profissional por família + os blocos NOVOS que o pensamento profundo achou.
> Objetivo: contexto **bizarro de preciso E eficiente**, em QUALQUER projeto, governado pelo AWIS.
> Legenda: **[JÁ]** construído · **[GAP]** planejado na 1ª passada · **[NOVO]** achado neste deepening · **[KEYSTONE]**.
>
> **Achado-mãe deste deepening:** a 1ª passada listou *features*. Faltavam 3 famílias inteiras —
> **Q (medir a acurácia do próprio grafo)**, **I (como o contexto é consumido)** e **D (escala/perf)** —
> e dois blocos CRÍTICOS: **Q-2 (eval harness: hoje não MEDIMOS quão preciso o grafo é)** e
> **G-5 (scan de secret/PII na ingestão: indexar repos externos VAI ingerir segredo um dia)**.

---

## Família W — Fundação cross-project (AWIS multi-workspace)

**Blocos:** W-1[KEYSTONE][GAP] workspace_id nas tabelas+world-model · W-2[GAP] scope=workspace + `--workspace` no build · W-3[GAP] readers workspace-aware · W-4[GAP] pipeline AWIS por-workspace · W-5[GAP] isolar memória/outcome · W-6[JÁ] registry+gate.

**Crítica profissional + NOVOS:**
- **Identidade de workspace é frágil se for só path.** O mesmo projeto muda de path por máquina; monorepo = N workspaces lógicos num repo. → **W-7[NOVO] identidade estável** = git-remote URL + UUID de workspace + sub-scopes de monorepo (path é só um alias).
- **Sem ciclo de vida.** Projeto arquivado/removido deixa lixo no grafo. → **W-8[NOVO] retenção/GC + right-to-forget por workspace** (sobrepõe G-9).
- **Schema do grafo evolui** → grafos por-workspace precisam de `graph_schema_version` + re-index automático no bump. → **W-9[NOVO] versionamento de schema + auto-reindex**.
- **Concorrência:** loop autônomo + index manual no mesmo workspace = corrida. → **W-10[NOVO] lock de index por-workspace**.
- **Primeiro index de repo gigante (1M LOC) é caro.** → **W-11[NOVO] first-index estagiado/orçado** (top-level → drill, com amostragem e budget).

---

## Família P — Precisão (bizarra)

**Blocos:** P-1[GAP] cauda dinâmica (type-flow) · P-2[JÁ] runtime-proof (M-2) · P-3[GAP] data-flow/taint · P-4[GAP] semântico governado · P-5[GAP] LSP/SCIP+langs · P-6[JÁ] confiança/proveniência.

**Crítica profissional + NOVOS:**
- **O maior ganho de precisão real em código de verdade NÃO é AST genérico — é framework-aware.** AST não enxerga a "mágica" de framework: Laravel (container DI, route→controller, event→listener, Eloquent relations, jobs), React (árvore de componentes, hooks), Spring, etc. → **P-7[NOVO][KEYSTONE-de-precisão] resolvers framework-aware** (DI/routing/ORM/eventos). É onde Atlas passa graphify de longe em precisão prática.
- **LSP/SCIP merece ser elevado** (não um item perdido). Para TS/Go/Rust/Java, um language server dá resolução **compiler-grade** que vence heurística. → **P-5 split: P-5a tree-sitter breadth · P-5b[NOVO] resolução LSP/SCIP compiler-grade**.
- **Edges de co-change (git history):** "estes arquivos mudam juntos" — acoplamento oculto que o AST não vê; sinal forte e barato. → **P-8[NOVO] co-change edges do git log**.
- **Edges de cobertura test→código:** qual teste exercita qual código (de coverage real) — preciso e runtime-grade. → **P-9[NOVO] coverage-derived edges**.
- **Edges cross-linguagem:** front (TS) chama rota back (PHP); contrato GraphQL/OpenAPI/proto liga os dois. A fronteira poliglota que ninguém resolve. → **P-10[NOVO] contract edges cross-language**.
- **Ancoragem por LINHA, não só arquivo** — contexto cirúrgico (range exato), não o arquivo todo. → **P-11[NOVO] line-precise anchoring**.
- **Ingest multimodal** — hoje só **markdown + PDF prontos**; imagem/vídeo/áudio-whisper são deps pesadas gated. → **P-12[GAP] multimodal ingest** (imagem/vídeo/áudio, dep-approval + Atlas Decide, local-first p/ sensível).
- **Communities REAIS (Leiden):** hoje roda **Louvain stdlib + betweenness**; o Leiden do graphify exige networkx/graspologic. → **P-13[GAP] Leiden communities** (dep-approval; o analytics já entrega Louvain/centralidade).

---

## Família E — Eficiência extrema (preciso E barato)

**Blocos:** E-1[GAP][KEYSTONE] compressão-no-retrieval (AP-813) · E-2[GAP] incremental/live · E-3[GAP] context pack query-shaped · E-4[JÁ] CacheAligner · E-5[JÁ] economia medida.

**Crítica profissional + NOVOS:**
- **O coração da precisão-eficiência é o RANKER de relevância — E-3 escondeu isso.** Montar o contexto mínimo exige um ranker híbrido forte: BM25 + centralidade-no-grafo + recência + runtime-proof + semântico. → **E-6[NOVO] ranker híbrido de montagem de contexto** (o cérebro do "pack mínimo").
- **Disclosure progressivo (skeleton-first):** dar ao modelo PRIMEIRO os esqueletos (assinaturas/imports), e deixar ele aprofundar via o tool de retrieve (CCR) só no que precisa — a ideia do CodeCompressor do headroom aplicada ao grafo. → **E-7[NOVO] contexto tiered/progressivo**.
- **Anti-contexto (poda explícita):** o grafo sabe o que NÃO é relacionado — excluir o irrelevante economiza tanto quanto incluir o certo. → **E-8[NOVO] poda anti-contexto**.
- **Cache/memoização por query:** queries iguais/parecidas reusam o pack montado. → **E-9[NOVO] cache de resultado de query**.
- **Telemetria de economia por-workspace:** quanto token foi poupado por projeto (o painel de ROI). → **E-10[NOVO] economia por-workspace medida**.

---

## Família X — O salto cross-workspace

**Blocos:** X-1[GAP] traversal cross-workspace · X-2[GAP] blast-radius cross-repo · X-3[GAP] padrões (AWEF) · X-4[GAP] cross-workspace ∪ cross-domain (M-8).

**Crítica profissional + NOVOS:**
- **Grafo de dependências/supply-chain cross-workspace:** que libs externas eu compartilho entre projetos; CVE numa lib → quais repos afeta. → **X-5[NOVO] dependency/supply-chain graph cross-repo**.
- **Centralidade nível-portfólio:** entre TODOS os projetos, quais conceitos/módulos compartilhados são centrais. → **X-6[NOVO] god-nodes/communities de portfólio**.
- **Resolução de identidade cross-repo:** o mesmo conceito nomeado diferente em repos diferentes (entity resolution) — pré-requisito de X-1/X-3 não retornarem lixo. → **X-7[NOVO] resolução de conceito cross-workspace**.

---

## Família G — Governança/soberania (o fosso)

**Blocos:** G-1[GAP] privacy class por workspace · G-2[JÁ] veto ARPTL · G-3[JÁ] drift-gate · G-4[JÁ] evidence.

**Crítica profissional + NOVOS:**
- **CRÍTICO e ausente: scan de secret/PII na INGESTÃO.** Indexar projetos arbitrários **vai** ingerir um `.env`, chave, token ou PII um dia. Tem que detectar+redigir ANTES de entrar no grafo/contexto. → **G-5[NOVO][KEYSTONE-de-segurança] secret/PII scan-and-redact na ingestão**.
- **Licença/proveniência:** rastrear licença de código externo (não vazar GPL pra contexto proprietário indevidamente). → **G-6[NOVO] tag de licença/proveniência**.
- **Controle de acesso por-workspace:** quais agentes/providers podem consultar o grafo de qual workspace. → **G-7[NOVO] access policy por-workspace**.
- **Integridade/repro:** hash de conteúdo do grafo inteiro por workspace (snapshot verificável). → **G-8[NOVO] graph integrity hash + snapshot reproduzível**.
- **Right-to-forget:** purgar grafo+memória de um workspace sob demanda (soberania). → **G-9[NOVO] forget/purge por workspace** (par de W-8).

---

## Família Q — Qualidade do PRÓPRIO grafo *(NOVA — a 1ª passada assumiu o grafo correto)*

> Sem isto, "bizarro de preciso" é claim, não fato. Esta família é o anti-over-claim do grafo.

- **Q-1[NOVO] self-audit/health:** orphan nodes, dangling edges, anomalia de god-node, drift de confiança, cobertura ("60% dos símbolos sem aresta → extractor fraco").
- **Q-2[NOVO][KEYSTONE] eval harness precisão/recall:** benchmark de relações conhecidas por linguagem → **MEDIR** quão acurado o grafo é (ex.: "call graph PHP = 94% precision / 88% recall"). Hoje não medimos isso. É o número que prova "preciso".
- **Q-3[NOVO] detecção de regressão do grafo:** mudança entre index runs sinalizada (o extractor piorou?).
- **Q-4[NOVO] guarda de INFERRED:** teto de arestas inferidas; INFERRED só vira fato com evidência (liga a P-6/M-1).

---

## Família I — Consumo/Interface *(NOVA — como o contexto chega ao agente/humano)*

- **I-1[NOVO] Atlas-as-language-server:** surface do grafo no editor (jump-to, preview de blast-radius) via LSP — o grafo no fluxo do dev.
- **I-2[NOVO] CLI de contexto em linguagem natural:** `atlas ctx "como funciona o auth"` → pack montado.
- **I-3[NOVO][alto valor] diff/PR → review-context assembler:** dado um PR, monta o contexto de review PRECISO (nós mudados + blast-radius + testes + docs) automaticamente. Casa com a surface PR-risk já construída.
- **I-4[NOVO] auto-pull no loop/Dev/Forge:** o runtime autônomo puxa o pack do grafo sozinho (parte já via gate).

---

## Família D — Escala/Performance *(NOVA — a realidade de engenharia de grafos grandes)*

- **D-1[NOVO] escala de storage:** 100k+ símbolos × N workspaces → estratégia de índice/partição, adjacency materializada.
- **D-2[NOVO] SLO de latência de query:** traversal < Xms p/ uso interativo (orçamento de latência).
- **D-3[NOVO] traversal pesado no python_ai_data:** grafos grandes não carregam em PHP; empurrar centralidade/communities/paths pesados pro runtime python (a fronteira já existe).

---

## A tese (atualizada)

```
GRAFO (o que ler) × PRECISÃO (type-flow + runtime-proof + FRAMEWORK-AWARE + LSP + data-flow + co-change + semântico)
 × EFICIÊNCIA (compressão-no-retrieval + RANKER híbrido + tiered/skeleton-first + anti-contexto)
 × ESCOPO (multi + cross-workspace + supply-chain + portfólio)
 × GOVERNANÇA (privacy + SECRET/PII-scan + license + access + forget por workspace)
 × QUALIDADE-MEDIDA (eval harness precision/recall — o que prova "preciso")
 = contexto que nenhum tool externo iguala, em QUALQUER projeto, PROVADO (não alegado)
```

## Sequenciamento (revisado)
- **P0 cross-project:** W-1+W-2+W-3+W-4+**W-7** (identidade estável) + **G-5** (secret/PII scan — antes de indexar repo externo!). Abre o blackink com segurança.
- **P1 precisão+eficiência que importam:** **P-7 framework-aware** + **E-1 compressão-no-retrieval** + **E-6 ranker** + **E-7 tiered** + P-1 cauda dinâmica + **Q-2 eval harness** (medir).
- **P2 salto:** X-1 + X-2 + **X-5 supply-chain** + X-4 + **I-3 PR→review-context**.
- **P3 profundidade:** P-5b LSP + P-8 co-change + P-9 coverage + P-3 data-flow + P-4 semântico + D-1/D-3 escala.

## Os 5 blocos de MAIOR leverage (se fosse escolher)
1. **W-1** (keying) — destrava cross-project inteiro.
2. **E-1** (compressão-no-retrieval) — economia composta sobre o grafo.
3. **P-7** (framework-aware) — o maior salto de precisão real em código de verdade.
4. **Q-2** (eval harness) — transforma "preciso" de claim em número provado.
5. **G-5** (secret/PII scan) — sem ele, cross-project é um risco de vazamento, não uma feature.

## O que NÃO fazer
- NÃO criar 2º grafo/runtime (estender code-graph + AWIS + compression).
- NÃO indexar repo externo sem G-5 (secret/PII) + G-1 (privacy class).
- NÃO afirmar "preciso" sem Q-2 (medir).
- NÃO misturar workspaces sem W-1 (workspace_id).
- NÃO promover runtime python / semântico-como-fato sem review.

## Honesto: DONE vs GAP
- **[JÁ] (não precisa implementar — já construído):** motor agnóstico; grafo símbolo/call/**type-resolved**; **M-2 runtime-proof**; **M-9 unified-reality** (código∪docs∪memória∪evidências∪decisões∪missões); **M-8 cross-domain**; **reconciliação doc↔código** (anti-over-claim); **verdade temporal TEOS** (valid_from/until/superseded_by); governança/privacidade/soberania; compression (AP-813); AWIS registry+gate; **loop de compounding**; **self-construction (proposer)**; PR-risk; drift-gate load-bearing; evidence append-only; economia medida 24,3×/95,9% (1 workspace); multimodal **markdown+PDF**; communities **Louvain**+betweenness.
- **[GAP/NOVO] a construir:** todas as famílias W (cross-project), Q (medição) e I (consumo) e D (escala) são majoritariamente novas; P/E/X/G têm o core feito mas os blocos de PROFUNDIDADE (framework-aware, LSP, ranker, tiered, secret-scan, eval) são o que falta pra "bizarro de preciso".
