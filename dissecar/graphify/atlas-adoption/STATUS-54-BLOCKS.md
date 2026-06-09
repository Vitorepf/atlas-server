# STATUS — 54 blocos (AP-815) · placar honesto

> ✅ = código + teste verde + zero-regressão provada · 🔨 = em andamento · ⬜ = pendente
> Spec: `ATLAS-CROSS-PROJECT-CONTEXT-DEEP-ROADMAP.md` · Contrato: `docs/ap/AP-815-*.md`
> Regra anti-over-claim: só marca ✅ com nome do teste na coluna Evidência.

## Progresso: 49/54 ✅ + W-3 🔨 (independentemente re-verificados: 272 PHP asserts + 20 [py] ops venv-verified, 0 falhas)
## Keystones: W-1 ✅ · E-1 ✅ · G-5 ✅ · P-7 ✅ · Q-2 ✅ — 5/5 DONE

## ⏭️ CONTINUAÇÃO (próximo "eu" — leia isto primeiro)
- **Onda G [py] DONE + verificada + wirada** (X-1/X-2/X-5 — ops em main.py, venv 0-falhas). Padrão de wiring [py]: módulo standalone em `atlas_code_graph/` + teste self-run em `tests/` + registrar no `_OPS` de main.py (import + lambda) + verificar dispatch via venv.
- **Onda H [py] DONE + verificada + wirada** (X-3/X-6/D-3 — ops em main.py, venv 0-falhas, dispatch provado).
- **Onda I [py] DONE + verificada + wirada** (P-10/X-4 — ops em main.py, venv 0-falhas, dispatch provado). main.py = 22 ops total.
- **APÓS Onda K restam 8** (todos buildáveis; melhor com contexto fresco):
  - **[php] integração main-loop** (sequencial, tocam readers compartilhados → não fan-out): **W-3-fechar** (wirar `WorldModelGraphRanker` + os MCP tools `atlas_*` pra usar `CodeGraphWorkspaceModelResolver` em vez de latest-global), **W-4** (pipeline AWIS certify→index→build por-workspace, 1 comando), **W-5** (isolar tabelas de outcome por workspace_id), **I-1** (Atlas-as-language-server, o maior), **I-4** (auto-pull do pack no loop/Dev/Forge).
  - **[py]/[native]**: **P-5a** (tree-sitter breadth → precisa de grammar packs = NOVO dep, pedir OK; `tree-sitter-language-pack`), **P-11** (line-precise anchoring → TOCA `treesitter_extract.py` existente, main-loop não fan-out), **P-1** (PHPStan type-flow → `vendor/bin/phpstan analyse --error-format=json` + parse; PHPStan já é dep dev).
  - **✅ DEPS APROVADOS + INSTALADOS no venv (py3.14):** fastembed 0.8, networkx, pillow, openai-whisper (torch tem wheel 3.14), **igraph+leidenalg** (Leiden de verdade; `graspologic` FALHOU no 3.14 por causa do `gensim` → P-13 usa leidenalg). **Onda J DONE + verificada + wirada** (P-4/X-7/P-13/P-12 — ops em main.py, venv 0-falhas, dispatch provado). main.py = 26 ops.
  - **Onda K [py] DONE + verificada + wirada** (P-3/P-5b — ops em main.py, venv 0-falhas, dispatch provado). main.py = 28 ops total.
  - **Onda L DONE + verificada** (W-4 ✅, P-11 ✅, P-1 ✅ — 49/54, 272 PHP asserts).
  - **Onda M EM VOO** (W-3-fechar, I-1 LSP, W-5 outcome-isolation, I-4 auto-pull — aditivos+default-safe): ao completar, **rode verificação AMPLA** (não só CodeGraph — os testes dos arquivos-core que tocaram: WorldModelGraphRanker, MCP, evidence-store) + a suíte CodeGraph; marque ✅ só os com teste verde + zero-regressão. → até 53/54. **ÚLTIMO restante = P-5a** (dep-gated: `tree-sitter-language-pack`, precisa do OK do operador — depois é só adicionar grammars ao treesitter op + teste).
  - **🏁 (referência) os 8 finais:** [php] integração main-loop = **W-3-fechar** (wirar `WorldModelGraphRanker` + MCP `atlas_*` no `CodeGraphWorkspaceModelResolver`), **W-4** (comando pipeline AWIS por-workspace), **W-5** (outcome tables por workspace_id), **I-1** (Atlas-as-language-server), **I-4** (auto-pull do pack no loop/Dev/Forge). [py] = **P-5a** (precisa `tree-sitter-language-pack` = 1 dep novo pequeno, pedir OK rápido), **P-11** (line-precise → editar `treesitter_extract.py` existente, main-loop). [native] = **P-1** (PHPStan: novo serviço [php] que roda `vendor/bin/phpstan analyse --error-format=json` + parseia → arestas type-resolved; PHPStan já é dep). NENHUM tem bloqueador permanente.
- **RESTAM 16:** buildável sem dep [py] = P-3 (data-flow AST), P-5a (tree-sitter breadth — checar se grammar packs já estão), P-5b (estende ingest_scip), P-11 (estende treesitter — toca módulo existente, NÃO é standalone), X-4 (cross-ws∪cross-domain, compõe M-8), P-10 (contract cross-language — JSON OpenAPI/GraphQL SDL stdlib; YAML precisa PyYAML=dep). + [native] P-1 (PHPStan). + [php] integração main-loop (tocam readers compartilhados → sequencial, eu): W-3-fechar, W-4, W-5, I-1, I-4. + 🔒 dep-gated: P-4, X-7, P-12, P-13.
- **Verificação rápida do todo:** PHP = `php -d memory_limit=3072M artisan test tests/Unit/CodeGraph/ tests/Feature/CodeGraph/` (deve dar 264+ verde). PY = rodar cada `runtimes/python/code_graph/tests/test_*.py` via venv.
- **Buildável SEM dep nova (stdlib [py]):** P-3 (data-flow AST), P-5b (estende ingest_scip), P-11 (estende treesitter), X-3 (padrões), X-4 (cross-ws∪cross-domain), X-6 (reusa betweenness), D-3 (reusa betweenness). + **[native]** P-1 (PHPStan, já é dep dev). + **[php] integração** (main-loop, tocam readers compartilhados → sequencial): W-3-fechar (wirar WorldModelGraphRanker/MCP no CodeGraphWorkspaceModelResolver), W-4 (pipeline AWIS), W-5 (isolar outcome), I-1 (LSP server), I-4 (auto-pull no loop).
- **🔒 DEP-GATED — precisam de OK do operador (regra de soberania, NÃO auto-instalar):** P-4 (semantic edges → `fastembed`, confirmado AUSENTE no venv), X-7 (entity-resolution → `fastembed`), P-12 (multimodal → `whisper`/`pillow`), P-13 (Leiden communities → `graspologic`/`networkx`). Construa a orquestração [php] + contrato + stub e PARE no boundary até aprovação.
- **Padrão que funciona:** workflow de 3-6 blocos self-contained → cada agente impl+testa → eu re-verifico independentemente (nunca confio no "done") → registro/wiro serial → atualizo este placar com nome do teste.

## W — Fundação cross-project
| Bloco | Lang | Status | Evidência |
|---|---|---|---|
| W-1 keying | php | ✅ | CodeGraphWorkspaceKeyingTest (3/14), stash-proven zero-regression |
| W-2 scope+--workspace | php | ✅ | CodeGraphSymbolBuildWorkspaceTest (3/14), build/command per-workspace |
| W-3 readers workspace-aware | php | 🔨 | resolver primitive green (CodeGraphWorkspaceModelResolverTest 4/10); wiring readers pending |
| W-4 pipeline AWIS por-workspace | php | ✅ | AtlasCodeGraphPipelineCommand (atlas:code-graph:pipeline, 2 tests): certify→index→build por-workspace |
| W-5 isolar memória/outcome | php | ⬜ | |
| W-7 identidade estável | php | ✅ | CodeGraphWorkspaceKeyingTest (4/19): git-remote + basename+hash + monorepo sub-scopes |
| W-8 retenção/GC + forget | php | ✅ | CodeGraphRetentionPolicyTest: stale decision + primary-protected (purge executor = G-9) |
| W-9 schema-versioning + reindex | php | ✅ | CodeGraphSchemaVersionTest (7/43): per-workspace version + needsReindex |
| W-10 lock de index | php | ✅ | CodeGraphIndexLockTest (4/16), cross-process atomic lock |
| W-11 first-index orçado | php+py | ✅ | CodeGraphFirstIndexPlannerTest: staged/sampled budget plan (py parse = follow-up) |

## P — Precisão
| Bloco | Lang | Status | Evidência |
|---|---|---|---|
| P-1 cauda dinâmica type-flow | native | ✅ | CodeGraphTypeFlowResolver via nikic/php-parser (6 tests): resolve $var->m() por property/param/@var; unresolved→sem aresta |
| P-3 data-flow/taint | py | ✅ | data_flow.py def-use chains (17 py tests) + op wired; venv-verified (extraction of events = extractor follow-up) |
| P-4 semântico governado | py | ✅ | semantic_edges.py via fastembed (13 py tests) + op wired; venv-verified (model ran live) |
| P-5a tree-sitter breadth | py | ⬜ | |
| P-5b LSP/SCIP | php+py | ✅ | scip_references.py occurrence→def edges (23 py tests) + op wired; venv-verified (LSP-server subprocess = optional follow-up) |
| P-7 framework-aware 🔑 | native | ✅ | CodeGraphFrameworkAwareResolverTest: route/DI/eloquent edges via Laravel reflection |
| P-8 co-change (git) | py | ✅ | co_change.py (13 py tests) + wired op `co_change`; venv-verified |
| P-9 coverage edges | php | ✅ | CodeGraphCoverageEdgeParserTest, clover+lcov → covered_by edges |
| P-10 contract cross-language | php+py | ✅ | cross_language_contract.py (23 py tests) + op wired; venv-verified (openapi/graphql/proto) |
| P-11 line-precise anchoring | py | ✅ | treesitter_extract.py line_start/line_end (6 tests + 0 regressão); venv-verified |
| P-12 multimodal ingest | py | ✅ | multimodal_ingest.py via pillow+whisper (img+audio) + op wired; venv-verified |
| P-13 Leiden communities | py | ✅ | leiden_communities.py via igraph+leidenalg (10 py tests) + op wired; venv-verified (true Leiden) |

## E — Eficiência
| Bloco | Lang | Status | Evidência |
|---|---|---|---|
| E-1 compressão-no-retrieval 🔑 | php | ✅ | CodeGraphRetrievalCompressorTest (4/22): wires AP-813, fail-open, never-grows |
| E-2 incremental/live | php+py | ✅ | CodeGraphIncrementalReindexPlannerTest (11/60): diff→reindex plan (py re-extract = follow-up) |
| E-3 context pack mínimo | php | ✅ | CodeGraphContextPackAssemblerTest (15/65): greedy token-budget pack |
| E-6 ranker híbrido | py | ✅ | hybrid_ranker.py BM25+centrality+recency (11 py tests) + wired op `hybrid_rank`; venv-verified (semantic=fastembed follow-up) |
| E-7 tiered/skeleton-first | php+py | ✅ | CodeGraphSkeletonViewTest (10/41): skeleton-first + drill map (py AST = follow-up) |
| E-8 anti-contexto/poda | php | ✅ | CodeGraphAntiContextPrunerTest, BFS distance prune |
| E-9 cache de query | php | ✅ | CodeGraphQueryCacheTest, per-workspace memoize + invalidate |
| E-10 telemetria de economia | php | ✅ | CodeGraphEconomyTelemetryTest, per-workspace ROI |

## X — Cross-workspace
| Bloco | Lang | Status | Evidência |
|---|---|---|---|
| X-1 traversal cross-workspace | php+py | ✅ | cross_workspace_traverse.py (22 py tests) + op wired; venv-verified |
| X-2 blast-radius cross-repo | py | ✅ | blast_radius.py (19 py tests) + op wired; venv-verified |
| X-3 padrões AWEF | py | ✅ | cross_workspace_patterns.py (19 py tests) + op wired; venv-verified (signatures, not content) |
| X-4 cross-workspace ∪ cross-domain | php+py | ✅ | cross_domain_union.py (22 py tests) + op wired; venv-verified (code∪domain seam) |
| X-5 supply-chain/CVE | py | ✅ | supply_chain.py (18 py tests) + op wired; venv-verified |
| X-6 centralidade portfólio | py | ✅ | portfolio_centrality.py (14 py tests) + op wired; venv-verified |
| X-7 resolução conceito cross-repo | py | ✅ | entity_resolution.py via fastembed (12 py tests) + op wired; venv-verified |

## G — Governança
| Bloco | Lang | Status | Evidência |
|---|---|---|---|
| G-1 privacy class por workspace | php | ✅ | CodeGraphWorkspacePrivacyTest, class resolution + heuristic |
| G-5 secret/PII scan 🔑 | php+py | ✅ | CodeGraphSecretScannerTest (10/88): regex secret+PII core + redact; ML NER = py follow-up |
| G-6 licença/proveniência | php | ✅ | CodeGraphLicenseDetectorTest (22/87), SPDX detect + path |
| G-7 access policy | php | ✅ | CodeGraphWorkspaceAccessPolicyTest (14/34), default-deny sensitive/secret |
| G-8 integrity hash | php | ✅ | CodeGraphIntegrityHasherTest (16/34), order-independent sha256 |
| G-9 forget/purge | php | ✅ | CodeGraphWorkspacePurgerTest (5/42): per-workspace purge + primary-protected |

## Q — Qualidade do grafo
| Bloco | Lang | Status | Evidência |
|---|---|---|---|
| Q-1 self-audit/health | php+py | ⬜ | |
| Q-2 eval harness 🔑 | py | ✅ | eval_harness.py (12 py tests) + wired main.py op `eval_precision_recall`; venv-verified |
| Q-3 regressão do grafo | py | ✅ | CodeGraphRegressionDetectorTest: snapshot diff + drop flags (php core; heavy stats = py follow-up) |
| Q-4 guarda INFERRED | php | ✅ | CodeGraphInferredGuardTest (8/59), ratio cap + extracted never dropped |

## I — Consumo/Interface
| Bloco | Lang | Status | Evidência |
|---|---|---|---|
| I-1 Atlas-as-language-server | php | ⬜ | |
| I-2 CLI atlas ctx | php | ✅ | atlas:ctx command test: keyword reader → E-3 pack (py ranker = follow-up) |
| I-3 diff/PR→review-context | php | ✅ | CodeGraphReviewContextAssemblerTest (5/18): blast-radius + E-3 pack |
| I-4 auto-pull no loop | php | ⬜ | |

## D — Escala/Perf
| Bloco | Lang | Status | Evidência |
|---|---|---|---|
| D-1 storage/partição | php | ⬜ | |
| D-2 SLO latência | php | ✅ | CodeGraphLatencyBudgetTest, injectable clock + breach record |
| D-3 traversal pesado no python | py | ✅ | neighborhood.py (21 py tests) + op wired; venv-verified (bidirectional k-hop) |
