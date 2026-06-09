# STATUS — 54 blocos (AP-815) · placar honesto

> ✅ = código + teste verde + zero-regressão provada · 🟡 = construído+testado mas NÃO ligado em produção · 🔨 = em andamento · ⬜ = pendente
> Spec: `ATLAS-CROSS-PROJECT-CONTEXT-DEEP-ROADMAP.md` · Contrato: `docs/ap/AP-815-*.md`
> Regra anti-over-claim: só marca ✅ com nome do teste na coluna Evidência.

## Progresso: 54/54 blocos + HARDENING P0-P3 + PATAMAR DE CONSUMO CONCLUÍDOS (2026-06-09) — re-verificado por mim: **349 PHP code-graph + seams de consumo (402 no batch de verificação), 0 falhas** + 29 python test files. Flag `auto_context` **ON**; atlas-server re-indexado (113.891 símbolos / 24k arestas).

> ✅ **Hardening P0-P3 concluído (2026-06-09)** — a auditoria por agentes achou que o motor Python (28 ops) estava construído mas **NÃO ligado**; AGORA está ligado + provado:
> - **P0** — **A1** tree-sitter no build (React 499→614 símbolos reais, incl. arrow-components; multi-linguagem py/go/rs/etc.) · **A2** Decision Receipt obrigatório no boundary Python (nenhum op roda sem sha256) · **A3** ranker E-6/BM25 no `atlas:ctx` (símbolos relevantes, não keyword-substring; CamelCase-split).
> - **P1** — **B1** índices compostos + `edgesTouching` OR→UNION · **B2** otimizadores read-path (D-1 `CodeGraphAdjacencyIndex`) ligados+memoizados · **B3** `nodeIndex` projetado (só os nós visitados) · **C1** N+1 do cache morno eliminado · **C2** mtime short-circuit (pula read/hash de arquivo intacto) · **C3/C4/C6** summary 8→1 query / refreshDoc só-quando-muda / syncDocLinks O(n²)→hash-index · **C5** resolve em Python opt-in flag-gated (PHP segue default — proven 99,5% + IPC).
> - **P2** (prova) — **D1** gold P/R real (1.0/1.0, 5 arestas human-verified) · **D2** baseline golden-graph (pega colapso de arestas) · **D3** guardas de orçamento de performance · **D4** isolamento destrutivo cross-workspace (trava o incidente 208k, mutation-tested) · **D5/D6** corpus de recall do G-5 (18/18, gaps reais documentados) + floor de economia E-1 · **D7** e2e de relevância de retrieval · **D8** runner Python agregado (29/29).
> - **P3** — **E1** over-claim corrigido (521→343 real) + Q-1/D-1 reconciliados · **E2** dead-code cortado (`loadFileSnapshot`); dual-community (Louvain dep-free + Leiden) e 60 serviços avaliados = justificados-por-design, não cortados.

> ✅ **Patamar de CONSUMO — LIGADO + RODANDO ponta-a-ponta (2026-06-09)** — o motor deixou de ser "construído" e virou CONSUMIDO. Flag `auto_context` ON no .env; atlas-server re-indexado.
> - **CodeGraphContextRetriever** = fonte ÚNICA compartilhada (extraída do atlas:ctx: termos → BM25 A3 → pack E-3); o command delega a ela.
> - **Seam compartilhado** `AtlasOpenBrainContextInjectionService::buildInjection` → Dev/chat/CLI/voz/mobile recebem o bloco `## Code Graph Context`. **PROVADO AO VIVO**: `AiPromptBuilder` montou um prompt de 35k chars COM o bloco (flag ON, dados reais). Flag OFF = byte-identical (hash estável).
> - **Forge** (`AtlasForgeProviderInvocationPromptBuilder` → `evidence_contract.code_graph_pack`) + **Loop** (`WorkspaceProviderLoopExecutionDriver::buildPrompt`) wirados, flag-gated, prova por dry-run/captura.
> - **Externo**: MCP `atlas-open-brain` (tools code-graph respondem — **regressão B1 json no pgsql CORRIGIDA** (era SQLSTATE 42883, union→unionAll); workspace default → atlas-server; find_relevant multi-termo) + hook `.claude/hooks/atlas-ctx.sh` UserPromptSubmit (injeta o pack do atlas:ctx; provado 50k chars).
> - Gaps honestos flagados como task: **W-3** read-path scoping de `symbols()` (MCP find_relevant vê todos os workspaces) + **G-5** recall (3 formatos de segredo). Guia: `dissecar/graphify/atlas-adoption/code-graph-consumption.md`.
## Keystones: W-1 ✅ · E-1 ✅ · G-5 ✅ · P-7 ✅ · Q-2 ✅ — 5/5 DONE

## 🔬 PROVA EM DADOS REAIS (atlas-server live, 2026-06-09) — não é claim, é medição
- **Grafo construído live** (`atlas:code-graph:build --symbols`): 8.200 nós, 24.132 arestas; **91,3% EXTRACTED (type-certas) / 8,7% INFERRED**; **0 ambíguas**; **24.617 relações puladas como unresolved** (recusa-se a chutar = anti-over-claim por construção). 113.592 símbolos indexados.
- **Saúde (Q-1 CodeGraphHealthAuditor):** coverage **100%**, **0 órfãos**, **0 dangling**, god-node grau 2601, grau médio 5,9.
- **PRECISÃO VERIFICADA — oráculo INDEPENDENTE** (nikic/php-parser + NameResolver re-parseia o fonte, parser diferente do que construiu as arestas; amostra 800): **EXTRACTED 398/400 = 99,5%** · **INFERRED 400/400 = 100%**.
- **Caveats honestos:** (1) mede corroboração-de-referência (precision-proxy forte, não gold semântico perfeito; shortname-match leniente). (2) recall-blind: o gap de recall = as 24,6k relações omitidas por incerteza (omite em vez de fabricar). (3) Q-2 formal precision/recall contra gold rotulado completo = follow-up.

## 🌐 PROVA CROSS-PROJECT (blackink-app indexado AO VIVO, 2026-06-09)
- **2º workspace real**: `/Users/vitorepf/develop/blackink/blackink-app` (React, git NexacodeTech/blackink-app) → workspace_id **`nexacodetech-blackink-app`** (W-7 git-remote slug). 499 símbolos, 1 módulo.
- **Governança ANTES da ingestão (G-5+G-1+W-7):** secret-scan achou **1 `google-key.json` com private_key Google REAL** no repo + 31 findings/22 arquivos; `.json`/`.env` ficam fora por extensão (não ingeridos). privacy_class=internal.
- **ISOLAMENTO PROVADO:** símbolos atlas-server **113.592** + blackink **499** (mesma tabela, keyados); módulos atlas-server **23** + blackink **1**; world models por scope separados; **"App" coexiste nos 2** (unique composto OK); **atlas-server intacto** (prune workspace-scoped não tocou nele).
- **2 BUGS REAIS pegos só pelo teste em repo real** (testes sintéticos não pegaram) + corrigidos + regressão: (1) `discoverFiles` hardcoded p/ Laravel → +`src/`+fallback genérico (indexa repo arbitrário); (2) **leak de módulo** (`workspace_id` fora do `$fillable` do Eloquent → caía no default `atlas-server`) → adicionado ao fillable + `test_module_model_mass_assigns_workspace_id`. **Zero-regressão** (CodeGraph 290/0; index test 7→8 passed).
- **⚠️ SECURITY (repo do operador):** rotacionar/remover o `google-key.json` committado no blackink-app + `.gitignore`.

## 🤖 MULTI-REPO AUTOMÁTICO (`atlas:code-graph:index-all`, 2026-06-09)
- **Novo comando** `atlas:code-graph:index-all <root>`: auto-descobre repos git sob uma pasta + indexa CADA um como workspace isolado, com G-5 antes. (CodeGraphIndexAllCommandTest 2 testes; CodeGraph suite 293/0.)
- **Rodado em /develop/blackink → 5 workspaces reais isolados:** atlas-server (113.592 sym / 23 mod / 208.031 doc-links), **nexacodetech-nivor-back-end (PHP: 11.666 sym / grafo RICO 1020 nós / 3072 arestas)**, nexacodetech-nivor-front-end (1.052 sym, React, 0 arestas), nexacodetech-blackink-app (499), nexacodetech-blackink-website (119). Cada um com seu world-model scope; nenhum colide.
- **3 BUGS REAIS que só o teste multi-repo pegou** (testes sintéticos não pegaram) — todos corrigidos + regressão:
  1. `discoverFiles` hardcoded p/ Laravel → +`src/`+fallback genérico.
  2. Leak de módulo (`workspace_id` fora do `$fillable` Eloquent) → fillable + teste.
  3. **`syncDocLinks` + `archiveStaleDocLinks` não-escopados** → cada repo recebia cópia dos ~168k doc-links da KB do atlas-server, E o `--prune` de um repo arquivava os doc-links do atlas-server. **INCIDENTE**: o prune do blackink zerou os doc-links ativos do atlas-server (208k → 0). **Detectado, root-caused, CORRIGIDO** (guard primary-only no syncDocLinks + workspace-scope no archiveStaleDocLinks) e **atlas-server RESTAURADO 100%** (208.031 des-arquivados; nada perdido). Re-index do blackink agora não polui nem toca o atlas-server (provado).
- **Lição:** os 54 estavam verdes em teste, mas só indexar repos REAIS expôs 3 bugs de isolamento de produção. Provar > assumir.

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
  - **Onda M DONE + verificada AMPLAMENTE** (W-3-fechar ✅, I-1 ✅, W-5 ✅, I-4 ✅ — 53/54; 302 code-graph testes/1537 asserts 0 regressão; WorldModelGraphRankerTest 7/40; AtlasEngineeringKnowledgeBaseTest mantém só os 2 reds pré-existentes-ambientais = W-5 zero-regressão; os 5 reds do AtlasOpenBrainMcpServiceTest são pré-existentes/ambientais — architecture/readiness/provider-release/clock, não os code-graph tools).
  - **🏁🏁 54/54 FECHADO** (P-5a ✅ com `tree-sitter-language-pack` aprovado+instalado). Nada pendente neste backlog.
  - **GOVERNANÇA (não esquecer):** tudo construído atrás de flag/default-safe + NÃO auto-promovido. Os [py] ops são dispatcháveis via `CodeGraphRuntimeInvoker` (flag-gated por design; promoção a produção = review humano, `runtime_promotion_policy.v1`). Caveats honestos por bloco no histórico: P-3 = compute def-use (extração de eventos = extractor à parte); P-12 = imagem+áudio (vídeo deferido); P-13 = leidenalg (graspologic falhou no py3.14); P-5b = SCIP-refs (LSP-subprocess opcional); P-7 eloquent = best-effort. Reds ambientais conhecidos (NÃO desta obra): 2 em AtlasEngineeringKnowledgeBaseTest (cache.quality_guard drift) + 5 em AtlasOpenBrainMcpServiceTest (architecture/readiness/provider-release/clock).
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
| W-3 readers workspace-aware | php | ✅ | resolver + WorldModelGraphRanker + 3 MCP tools workspace-aware aditivamente (CodeGraphWorkspaceAwareReadersTest 8/22 + ranker 7/40 + 302 broad, 0 regressão) |
| W-4 pipeline AWIS por-workspace | php | ✅ | AtlasCodeGraphPipelineCommand (atlas:code-graph:pipeline, 2 tests): certify→index→build por-workspace |
| W-5 isolar memória/outcome | php | ✅ | recordToolRuntimeEvidence carrega workspace_id (CodeGraphEvidenceWorkspaceIdTest); 0 regressão no index service |
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
| P-5a tree-sitter breadth | py | ✅ | treesitter_extract.py + tree-sitter-language-pack (go/rust/java/ruby/kotlin/scala/swift/php/c/cpp/lua/bash); test_treesitter_breadth + 2 regressões venv 0-falha |
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
| Q-1 self-audit/health | php+py | ✅ | CodeGraphHealthAuditorTest + rodou live (coverage 100% / 0 órfãos / 0 dangling) |
| Q-2 eval harness 🔑 | py | ✅ | eval_harness.py (12 py tests) + wired main.py op `eval_precision_recall`; venv-verified |
| Q-3 regressão do grafo | py | ✅ | CodeGraphRegressionDetectorTest: snapshot diff + drop flags (php core; heavy stats = py follow-up) |
| Q-4 guarda INFERRED | php | ✅ | CodeGraphInferredGuardTest (8/59), ratio cap + extracted never dropped |

## I — Consumo/Interface
| Bloco | Lang | Status | Evidência |
|---|---|---|---|
| I-1 Atlas-as-language-server | php | ✅ | CodeGraphLanguageServer (initialize/definition/references LSP) + atlas:code-graph:lsp (9 tests) |
| I-2 CLI atlas ctx | php | ✅ | atlas:ctx command test: keyword reader → E-3 pack (py ranker = follow-up) |
| I-3 diff/PR→review-context | php | ✅ | CodeGraphReviewContextAssemblerTest (5/18): blast-radius + E-3 pack |
| I-4 auto-pull no loop | php | ✅ | CodeGraphAutoContextProvider flag-gated default-OFF (compõe E-3/I-3); teste gated on/off |

## D — Escala/Perf
| Bloco | Lang | Status | Evidência |
|---|---|---|---|
| D-1 storage/partição + adjacency | php | ✅ | CodeGraphAdjacencyIndex (D-1) + test; **B2 ligou na travessia MCP** (memoizado por modelo) + índices compostos (B1). CodeGraphPerformanceBudgetTest guarda o custo |
| D-2 SLO latência | php | ✅ | CodeGraphLatencyBudgetTest, injectable clock + breach record |
| D-3 traversal pesado no python | py | ✅ | neighborhood.py (21 py tests) + op wired; venv-verified (bidirectional k-hop) |
