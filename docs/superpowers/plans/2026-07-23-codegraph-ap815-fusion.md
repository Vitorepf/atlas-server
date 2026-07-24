# FUSÃO AP-815 — religar o code-graph avançado no pipeline vivo (patamar mais poderoso)

**Princípio (correção do operador):** capacidade construída+testada mas não-ligada NÃO se deleta — **funde-se, religa-se, vira patamar mais poderoso.** Sempre há como reaproveitar. Pensar muito antes.

## Diagnóstico
O pipeline vivo (`atlas:code-graph:pipeline` → `CodeGraphSymbolBuilder.build`) roda só 2 passos: symbols + `resolveEdges()` (symbol→symbol básico, com fallback PHP↔Python governado). **40 classes do programa AP-815 (86 testes verdes) estão construídas e órfãs** — o code-graph avançado inteiro, desligado. Como o code-graph alimenta o **AOBG context-pack (usado em toda interação)**, religá-lo eleva a inteligência de código diária do operador.

## Arquitetura da fusão (aditiva, flag-gated, fallback-safe — o padrão que o pipeline já usa)

`SymbolBuilder.build()` vira um **pipeline de passes de edge**, cada um aditivo e gated, mergeando no mesmo world-model:

### Tier 1 — RESOLUÇÃO PROFUNDA (edges mais ricos) — maior valor imediato
| Órfão | Adiciona | Como plugar |
|---|---|---|
| `CodeGraphCallResolver` | edges method→method CALL (`call_edges.v1`) | pass após base; input = calls extraídos dos symbols/relations + methodIndex |
| `CodeGraphTypedCallResolver` | edges CALL type-aware (precisão maior) | refina os do CallResolver quando há info de tipo |
| `CodeGraphTypeFlowResolver` | resolve calls dinâmicos por type-flow | pass sobre calls não-resolvidos |
| `CodeGraphFrameworkAwareResolver` | edges via convenção de framework (Laravel etc.) | pass; a "precision keystone" P-7 |
| `CodeGraphCoverageEdgeParser` | edges test→code (runtime-grade) | pass lendo coverage; liga cobertura ao graph |

### Tier 2 — QUALIDADE/INTEGRIDADE (gate do build, não deixa regredir)
`CodeGraphHealthAuditor` (Q-1) · `CodeGraphRegressionDetector` (Q-3, entre 2 índices) · `CodeGraphIntegrityHasher` (G-8, snapshot verificável) · `CodeGraphInferredGuard` (Q-4, anti-over-claim no edge). → rodam pós-build como gate; falha = não promove o world-model.

### Tier 3 — RETRIEVAL PODEROSO (o que o AOBG consome)
`CodeGraphRetrievalCompressor` (E-1 keystone) · `CodeGraphSkeletonView` (E-7 progressive disclosure) · `CodeGraphQueryCache`+`Store` (E-9 memoize) · `CodeGraphAntiContextPruner` (E-8). → plugam no caminho de leitura do context-pack (`atlas:ctx`/`atlas:context-pack`), tornando o pack mais denso e barato.

### Tier 4 — SOBERANIA/SEGURANÇA (pétreo local-first)
`CodeGraphPrivacyFilter` · `CodeGraphWorkspaceAccessPolicy` (G-7) · `CodeGraphIngestGuard` (SSRF) · `CodeGraphLicenseDetector` (G-6) · `CodeGraphWorkspacePurger` (G-9 right-to-forget) · `CodeGraphRetentionPolicy` (W-8). → wrap no caminho de ingest/leitura cross-project.

### Tier 5 — O PATAMAR: fusão com a realidade
`CodeGraphUnifiedView` funde o graph estático com o **AURG (Atlas Universal Reality Graph)** + `CodeGraphRealityIngestionService` + `CodeGraphRuntimeEvidenceOverlay` (overlay de evidência de execução real nos edges). → **este é o "algo mais grandioso"**: o code-graph deixa de ser estático e passa a refletir o comportamento runtime real.

### Planejadores/economia (ligam o índice incremental e o ROI)
`CodeGraphFirstIndexPlanner` (W-11) + `CodeGraphIncrementalReindexPlanner` (E-2) → índice incremental barato (hoje re-indexa tudo). `CodeGraphEconomyTelemetry`+`Benchmark`+`LatencyBudget` → ROI/SLO por workspace.

## Sequência de execução SEGURA (cada slice: flag default-OFF → wire → teste do órfão já cobre → boot → commit escopado)
1. **Beachhead Tier-1**: `CodeGraphCallResolver` como pass aditivo gated por `atlas.code_graph.call_edges` (default OFF). Merge de edges no world-model. Prova o padrão de fusão.
2. Tier-1 restante (Typed/TypeFlow/Framework/Coverage) — cada um um slice.
3. Tier-2 gate no build. 4. Tier-3 no retrieval. 5. Tier-4 wrap soberania. 6. **Tier-5 UnifiedView+AURG** (o patamar).
7. Planejadores incrementais + economia.

**Invariantes:** cada pass é aditivo (nunca remove/altera edges existentes) e flag-gated default-OFF (byte-identical até ligar) — o mesmo contrato que `resolveEdges()` já respeita com o Python. Fallback sempre pro caminho provado. Zero risco ao AOBG até o operador ligar cada flag.

**NÃO deletar nada do AP-815.** Cada órfão é um tier a religar.

---

## ACHADO DE EXECUÇÃO (24/07) — por que os Tier-1 são órfãos: falta o PIPELINE DE DADOS, não o wiring

O build (`CodeGraphSymbolBuilder`) carrega symbols + relations (`dependencies`/`symbol_references`/`test_targets` de `atlas_engineering_code_file_snapshots.relations_json`). Os resolvers profundos precisam de dados que ESSA fonte não tem:
- `CodeGraphCallResolver` → quer `calls[{caller,callee,path}]` (pares de chamada method→method) — NÃO estão nas relations; exigem extração AST de call-sites.
- `CodeGraphCoverageEdgeParser` → quer report Clover/LCOV cru — exige RODAR a suíte com coverage (proibido: wipe do DB).
- Typed/TypeFlow/FrameworkAware → refinam call edges — dependem do CallResolver base.

**Conclusão (revisada 24/07):** o extractor de call-sites JÁ EXISTE e é testado — `runtimes/python/code_graph/atlas_code_graph/callgraph.py::extract_calls(files)` → `{schema_version:"atlas.code_graph.callgraph.v1", calls:[{caller,callee,path,language}]}` (tree-sitter, language-agnostic, degrada gracioso sem venv). O contrato bate BYTE-A-BYTE com o docblock de `CodeGraphCallResolver::resolveCalls`. Não é "construir a fonte"; é FUNDIR duas peças testadas que nunca se falaram.

## O QUE FOI RELIGADO (24/07) — `callgraph` op exposto no runtime
`extract_calls` estava construído+testado mas AUSENTE do `_OPS` de `runtimes/python/code_graph/main.py` → **inalcançável pelo kernel Laravel** (`CodeGraphRuntimeInvoker` só chama ops registrados). Registrei `"callgraph": lambda m: extract_calls(m.get("files", []))` ao lado do `treesitter` op (+ import + docstring). Aditivo puro: import stdlib-only (tree-sitter lazy dentro), demais ops byte-identical. Provado: `python3 main.py <manifest>` → `{"ok":true,"result":{"calls":[],"schema_version":"atlas.code_graph.callgraph.v1"}}` (dispatch ok, degrada sem venv); `main._OPS` agora tem 30 ops incl. `callgraph`; a correção de extração real está coberta pelos testes já-verdes do módulo (`test_typed_callgraph.py`). O invoker usa o venv (tem tree-sitter) p/ este op. Commit `46b1c482c`. **Um órfão testado virou primitivo callable pelo kernel.**

### O QUE FALTA p/ ATIVAÇÃO COMPLETA (operador-supervisionado — decisão de arquitetura/perf)
`CodeGraphSymbolBuilder.build()` trabalha de **snapshots do DB (symbols+relations), não de conteúdo cru de arquivo** — mas `extract_calls` precisa de `files:[{path,language,content}]`. Ativar o Tier-1 no build vivo exige: (a) uma **fonte de conteúdo de arquivo** no builder (carregar source do workspace) + decisão de **perf/incremental** (rodar tree-sitter no workspace todo a cada build é caro — precisa cache/reindex incremental, que os planejadores `CodeGraphIncrementalReindexPlanner`/`FirstIndexPlanner` já cobririam); (b) construir o `methodIndex` (short-name → [FQN]) dos symbols já carregados; (c) pass flag-gated default-OFF: invoca op `callgraph` → `resolveCalls($calls, $methodIndex)` → merge aditivo de edges (mesmo padrão do `postBuildAudit`). Isso toca o caminho que alimenta o AOBG → **não é slice overnight; é obra focada com o operador.**

**FEASÍVEL e FEITO (report-tier, opera sobre nodes/edges já construídos):** HealthAuditor + IntegrityHasher + InferredGuard no `postBuildAudit` (flag `atlas.code_graph.post_build_audit`). Tier-5 (UnifiedView+AURG) precisa do AURG como fonte — verificar disponibilidade.

## CORREÇÃO (24/07): o venv EXISTE e o callgraph op está PROVADO com edges reais
Uma medição anterior minha disse "venv ausente" — **ERRADA, dois erros**: (1) olhei `runtimes/python/.venv` (caminho errado — o invoker usa `RUNTIME_ROOT/.venv` = `runtimes/python/code_graph/.venv`); (2) rodei os checks de importabilidade e o `run_tests.py` com o **python de SISTEMA**, que não enxerga os pacotes isolados do venv. O venv em `runtimes/python/code_graph/.venv` **já existia** (dir nascido 2026-06-08) e está **provisionado**: `tree_sitter_language_pack`, `fastembed`, `igraph`, `leidenalg`, `whisper`, `pypdf`, `fpdf2`, `networkx`, `numpy` (60 pkgs).

**PROVA da fusão viva (rodado com o venv python):**
- `callgraph` op → pares reais: manifesto `outer()→inner()→helper()` devolve `[{caller:inner,callee:helper},{caller:outer,callee:inner}]` no schema `atlas.code_graph.callgraph.v1` — exatamente o que `CodeGraphCallResolver::resolveCalls` consome.
- `run_tests.py` via venv: **29 pass / 0 fail / 0 skip** (os tree-sitter/embedding/pdf tests rodam de verdade, não dep-skip).

O invoker `pythonBinary()` já prefere esse venv, então os ops pesados rodam com precisão real quando o flag `code_graph_real_edges` está ligado. Os guards de self-skip que adicionei nos 8 test files continuam válidos: quando o suite roda com python de SISTEMA (ou CI sem o venv) ele degrada honesto (dep-skip) em vez de errar. **Falta p/ ativação LIVE completa** (inalterado): `SymbolBuilder` precisa de fonte de conteúdo de arquivo + decisão perf/incremental + o flag `code_graph_real_edges` (operador) — o extractor e o op já estão prontos e provados.
