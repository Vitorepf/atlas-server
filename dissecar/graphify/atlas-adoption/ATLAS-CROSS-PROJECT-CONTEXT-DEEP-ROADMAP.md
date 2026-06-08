# Atlas · Backlog de Contexto Cross-Project (blocos A IMPLEMENTAR)

> **Doc TEMPORÁRIA de staging — NÃO é AP.** Lista de trabalho: **SÓ os blocos PENDENTES**.
> O que já foi feito vive nos **APs 811–814 + memória** (não fica aqui).
> Objetivo: contexto **bizarro de preciso E eficiente**, em QUALQUER projeto, governado pelo AWIS.
> Princípio: **estender** code-graph + compression + AWIS (não criar 2º grafo/runtime).
> **Total: ~54 blocos pendentes em 8 famílias.**

## ⚙️ Fronteira de linguagem (REGRA — `runtime-language-boundaries.md`)
**Atlas usa a melhor linguagem pro objetivo. Não implementar em PHP o que é Python, nem forçar em Python o que é nativo de PHP/framework.**
- **`[py]` = python_ai_data** — DADOS/GRAFO/ML/STATS/EMBEDDINGS/PARSING-pesado. Roda atrás do invoker assinado (manifest-in / json-out, `CodeGraphRuntimeInvoker`). Promover op python nova = **review humano + dep-approval** (networkx/graspologic/tree-sitter/etc.). Python é **músculo**: NUNCA decide provider/modelo/domínio nem é fonte de Evidence.
- **`[php]` = Laravel/Kernel** — schema/migrations, governança, orquestração, persistência, policy, gates, MCP/CLI/API. PHP é o **cérebro**: decide, governa, persiste, expõe.
- **`[php+py]`** = PHP orquestra/governa + Python computa o pesado (a divisão padrão de grafo).
- **`[native]`** = ferramenta NATIVA da linguagem-alvo (PHPStan/Psalm p/ tipos PHP, tsserver/LSP p/ TS, reflection do framework p/ DI/rotas/ORM). **Forçar isso em Python seria pior** — é o outro lado da regra.

---

## Família W — Fundação cross-project (AWIS) · **majoritariamente [php]** (é a camada Kernel/schema/governança)
- **W-1 🔑 [php]** `workspace_id` nas tabelas+world-model + índice único (migrations/models).
- **W-2 [php]** `scope=<workspace>` + `--workspace` no build (command/config; a EXTRAÇÃO que ele dispara é [py]).
- **W-3 [php]** readers (MCP/ranker/invoker) workspace-aware (lógica de resolução).
- **W-4 [php]** pipeline AWIS por-workspace (orquestração + gate).
- **W-5 [php]** isolar memória/outcome (schema).
- **W-7 [php]** identidade estável (registry + git-remote + UUID + monorepo).
- **W-8 [php]** retenção/GC + forget (lifecycle DB).
- **W-9 [php]** schema-versioning + auto-reindex (PHP versiona; dispara reindex [py]).
- **W-10 [php]** lock de index por-workspace.
- **W-11 [php+py]** first-index orçado (PHP planeja/orça/governa; **Python** parseia em escala).

## Família P — Precisão · **majoritariamente [py]** (extração/parsing/ML/grafo) — com 2 exceções nativas
- **P-1 [native]** cauda dinâmica type-flow — **PHPStan/Psalm p/ PHP**, tsserver p/ TS. Type-flow maduro é da linguagem, NÃO reimplementar em Python.
- **P-3 [py]** data-flow/taint (análise AST/grafo pesada).
- **P-4 [py]** arestas semânticas (embeddings — explicitamente Python; governança Atlas Decide fica [php]).
- **P-5a [py]** tree-sitter breadth (mais grammars no runtime python).
- **P-5b [php+py]** LSP/SCIP — orquestra o subprocess LSP/indexer ([php]); normaliza o SCIP/protobuf ([py]).
- **P-7 [native] 🔑** framework-aware — **Laravel = PHP** (reflection + `route:list` + container + Eloquent); React = node. **NUNCA em Python** (re-parsear PHP p/ adivinhar DI seria pior).
- **P-8 [py]** co-change edges (matriz/estatística de coupling do git log).
- **P-9 [php]** coverage edges (parse clover/lcov + mapear — leve, sem dado pesado).
- **P-10 [php+py]** contract edges cross-language (PHP orquestra; **Python** parseia OpenAPI/GraphQL/proto + faz o match).
- **P-11 [py]** line-precise anchoring (no extractor tree-sitter).
- **P-12 [py]** multimodal (OCR/áudio/vídeo — explicitamente Python; deps gated).
- **P-13 [py]** Leiden communities (networkx/graspologic — Python; dep-approval).

## Família E — Eficiência · **mista**
- **E-1 🔑 [php]** compressão-no-retrieval (fiar AP-813 na saída — engine leve já é PHP; **stats pesadas de compressão futuras = [py]**).
- **E-2 [php+py]** incremental/live (PHP: fila/diff/git-hook; **Python**: re-extrai os arquivos mudados).
- **E-3 [php]** context pack mínimo (assembly + token-budget = Kernel; o ranking é o E-6 [py]).
- **E-6 [py]** ranker híbrido (rerank + BM25 + centralidade + embedding — explicitamente reranking/embeddings).
- **E-7 [php+py]** tiered/skeleton-first (**Python**: esqueletos AST; PHP: disclosure/orquestração).
- **E-8 [php]** anti-contexto/poda (lógica de query no grafo).
- **E-9 [php]** cache/memoização de query.
- **E-10 [php]** telemetria de economia.

## Família X — Cross-workspace · **[py]-heavy** (algoritmos de grafo em escala) + [php] governança
- **X-1 [php+py]** traversal cross-workspace (PHP: bounded + veto ARPTL; **Python**: traversal pesado em escala).
- **X-2 [py]** blast-radius cross-repo (reverse-reachability em escala).
- **X-3 [py]** padrões AWEF (mineração/clustering cross-graph).
- **X-4 [php+py]** cross-workspace ∪ cross-domain (PHP: governança/veto M-8; **Python**: merge/traversal pesado).
- **X-5 [py]** supply-chain/CVE (resolução de deps + matching CVE — dados).
- **X-6 [py]** centralidade de portfólio (centralidade em escala).
- **X-7 [py]** resolução de conceito cross-repo (entity resolution / embeddings).

## Família G — Governança · **majoritariamente [php]** (policy/gate) — G-5 híbrido
- **G-1 [php]** privacy class por workspace (policy).
- **G-5 🔑 [php+py]** secret/PII scan na ingestão — PHP: gate/policy + regex de secret; **Python**: PII NER de verdade (ML).
- **G-6 [php]** licença/proveniência.
- **G-7 [php]** access policy por-workspace.
- **G-8 [php]** integrity hash + snapshot.
- **G-9 [php]** forget/purge.

## Família Q — Qualidade do grafo · **[py]-heavy** (métricas/eval/stats) + [php] policy
- **Q-1 [php+py]** self-audit/health (**Python**: stats do grafo; PHP: expõe/gate).
- **Q-2 🔑 [py]** eval harness precision/recall (métricas/eval — dados; PHP orquestra o benchmark).
- **Q-3 [py]** regressão do grafo (diff/stats entre runs).
- **Q-4 [php]** guarda de INFERRED (policy/cap).

## Família I — Consumo/Interface · **majoritariamente [php]**
- **I-1 [php]** Atlas-as-language-server (servidor LSP servindo o grafo existente).
- **I-2 [php]** CLI `atlas ctx "…"` (comando; NL→query via provider governado por PHP).
- **I-3 [php]** diff/PR → review-context (assembly Kernel; usa o ranker [py]).
- **I-4 [php]** auto-pull no loop/Dev/Forge.

## Família D — Escala/Perf · [php] infra + D-3 [py]
- **D-1 [php]** storage/partição + adjacency materializada (schema/cache).
- **D-2 [php]** SLO de latência (medição/governança).
- **D-3 [py]** traversal/centralidade/communities pesados no python_ai_data (grafo grande não carrega em PHP).

---

## Resumo por linguagem (pra não errar na hora de implementar)
- **[py] (python_ai_data):** P-3, P-4, P-5a, P-8, P-11, P-12, P-13, E-6, X-2, X-3, X-5, X-6, X-7, Q-2, Q-3, D-3.
- **[php] (Kernel):** W-1..W-5, W-7..W-10, P-9, E-1, E-3, E-8, E-9, E-10, G-1, G-6, G-7, G-8, G-9, Q-4, I-1, I-2, I-3, I-4, D-1, D-2.
- **[php+py] (orquestra + computa):** W-11, P-5b, P-10, E-2, E-7, X-1, X-4, G-5, Q-1.
- **[native] (ferramenta da linguagem-alvo, NÃO Python):** P-1 (PHPStan/Psalm/tsserver), P-7 (reflection do framework).

## Sequenciamento
- **P0 cross-project seguro:** W-1 + W-2 + W-3 + W-4 + W-7 [php] + **G-5** [php+py] (secret/PII antes de indexar repo externo).
- **P1 precisão+eficiência:** **P-7** [native] + **E-1** [php] + **E-6** [py] + E-7 [php+py] + P-1 [native] + **Q-2** [py].
- **P2 salto:** X-1 [php+py] + X-2 [py] + **X-5** [py] + X-4 [php+py] + **I-3** [php].
- **P3 profundidade:** P-5b [php+py] + P-8 [py] + P-9 [php] + P-3 [py] + P-4 [py] + D-1 [php]/D-3 [py].

## Os 5 keystones (maior leverage)
1. **W-1 [php]** keying — destrava cross-project. 2. **E-1 [php]** compressão-no-retrieval. 3. **P-7 [native]** framework-aware. 4. **Q-2 [py]** eval harness. 5. **G-5 [php+py]** secret/PII.

## O que NÃO fazer
- **NÃO implementar [py] em PHP** (grafo/ML/stats/embeddings) nem **[native] em Python** (type-flow/framework-aware).
- NÃO criar 2º grafo/runtime; NÃO indexar repo externo sem G-5+G-1; NÃO afirmar "preciso" sem Q-2.
- NÃO deixar runtime Python decidir provider/modelo/domínio nem virar fonte de Evidence.
- NÃO promover op python nova sem review humano + dep-approval; NÃO ligar `--persist` do M-8 (AP-814 §11).

> **Feito (fora desta doc):** ver APs 811/812/813/814 + memória — code-graph símbolo/call/type-resolved, M-2/M-8/M-9, doc↔código, TEOS, governança/privacy, compression (AP-813), AWIS registry+gate, compounding, self-construction (proposer), PR-risk, drift-gate, evidence, economia 24,3×, markdown+PDF, Louvain+betweenness. (Split de linguagem já respeitado: PHP=Kernel, Python=runtimes/python/code_graph.)
