# STATUS — 54 blocos (AP-815) · placar honesto

> ✅ = código + teste verde + zero-regressão provada · 🔨 = em andamento · ⬜ = pendente
> Spec: `ATLAS-CROSS-PROJECT-CONTEXT-DEEP-ROADMAP.md` · Contrato: `docs/ap/AP-815-*.md`
> Regra anti-over-claim: só marca ✅ com nome do teste na coluna Evidência.

## Progresso: 24/54 ✅ + W-3 🔨 (independentemente re-verificados: 226 testes / 1120 asserts verdes)
## Keystones: W-1 ✅ · E-1 ✅ · G-5 ✅ · P-7 ✅ · Q-2 ([py], precisa runtime python) — 4/5 done

## W — Fundação cross-project
| Bloco | Lang | Status | Evidência |
|---|---|---|---|
| W-1 keying | php | ✅ | CodeGraphWorkspaceKeyingTest (3/14), stash-proven zero-regression |
| W-2 scope+--workspace | php | ✅ | CodeGraphSymbolBuildWorkspaceTest (3/14), build/command per-workspace |
| W-3 readers workspace-aware | php | 🔨 | resolver primitive green (CodeGraphWorkspaceModelResolverTest 4/10); wiring readers pending |
| W-4 pipeline AWIS por-workspace | php | ⬜ | |
| W-5 isolar memória/outcome | php | ⬜ | |
| W-7 identidade estável | php | ✅ | CodeGraphWorkspaceKeyingTest (4/19): git-remote + basename+hash + monorepo sub-scopes |
| W-8 retenção/GC + forget | php | ✅ | CodeGraphRetentionPolicyTest: stale decision + primary-protected (purge executor = G-9) |
| W-9 schema-versioning + reindex | php | ✅ | CodeGraphSchemaVersionTest (7/43): per-workspace version + needsReindex |
| W-10 lock de index | php | ✅ | CodeGraphIndexLockTest (4/16), cross-process atomic lock |
| W-11 first-index orçado | php+py | ✅ | CodeGraphFirstIndexPlannerTest: staged/sampled budget plan (py parse = follow-up) |

## P — Precisão
| Bloco | Lang | Status | Evidência |
|---|---|---|---|
| P-1 cauda dinâmica type-flow | native | ⬜ | |
| P-3 data-flow/taint | py | ⬜ | |
| P-4 semântico governado | py | ⬜ | |
| P-5a tree-sitter breadth | py | ⬜ | |
| P-5b LSP/SCIP | php+py | ⬜ | |
| P-7 framework-aware 🔑 | native | ✅ | CodeGraphFrameworkAwareResolverTest: route/DI/eloquent edges via Laravel reflection |
| P-8 co-change (git) | py | ⬜ | |
| P-9 coverage edges | php | ✅ | CodeGraphCoverageEdgeParserTest, clover+lcov → covered_by edges |
| P-10 contract cross-language | php+py | ⬜ | |
| P-11 line-precise anchoring | py | ⬜ | |
| P-12 multimodal ingest | py | ⬜ | |
| P-13 Leiden communities | py | ⬜ | |

## E — Eficiência
| Bloco | Lang | Status | Evidência |
|---|---|---|---|
| E-1 compressão-no-retrieval 🔑 | php | ✅ | CodeGraphRetrievalCompressorTest (4/22): wires AP-813, fail-open, never-grows |
| E-2 incremental/live | php+py | ⬜ | |
| E-3 context pack mínimo | php | ✅ | CodeGraphContextPackAssemblerTest (15/65): greedy token-budget pack |
| E-6 ranker híbrido | py | ⬜ | |
| E-7 tiered/skeleton-first | php+py | ⬜ | |
| E-8 anti-contexto/poda | php | ✅ | CodeGraphAntiContextPrunerTest, BFS distance prune |
| E-9 cache de query | php | ✅ | CodeGraphQueryCacheTest, per-workspace memoize + invalidate |
| E-10 telemetria de economia | php | ✅ | CodeGraphEconomyTelemetryTest, per-workspace ROI |

## X — Cross-workspace
| Bloco | Lang | Status | Evidência |
|---|---|---|---|
| X-1 traversal cross-workspace | php+py | ⬜ | |
| X-2 blast-radius cross-repo | py | ⬜ | |
| X-3 padrões AWEF | py | ⬜ | |
| X-4 cross-workspace ∪ cross-domain | php+py | ⬜ | |
| X-5 supply-chain/CVE | py | ⬜ | |
| X-6 centralidade portfólio | py | ⬜ | |
| X-7 resolução conceito cross-repo | py | ⬜ | |

## G — Governança
| Bloco | Lang | Status | Evidência |
|---|---|---|---|
| G-1 privacy class por workspace | php | ✅ | CodeGraphWorkspacePrivacyTest, class resolution + heuristic |
| G-5 secret/PII scan 🔑 | php+py | ✅ | CodeGraphSecretScannerTest (10/88): regex secret+PII core + redact; ML NER = py follow-up |
| G-6 licença/proveniência | php | ✅ | CodeGraphLicenseDetectorTest (22/87), SPDX detect + path |
| G-7 access policy | php | ✅ | CodeGraphWorkspaceAccessPolicyTest (14/34), default-deny sensitive/secret |
| G-8 integrity hash | php | ✅ | CodeGraphIntegrityHasherTest (16/34), order-independent sha256 |
| G-9 forget/purge | php | ⬜ | |

## Q — Qualidade do grafo
| Bloco | Lang | Status | Evidência |
|---|---|---|---|
| Q-1 self-audit/health | php+py | ⬜ | |
| Q-2 eval harness 🔑 | py | ⬜ | |
| Q-3 regressão do grafo | py | ✅ | CodeGraphRegressionDetectorTest: snapshot diff + drop flags (php core; heavy stats = py follow-up) |
| Q-4 guarda INFERRED | php | ✅ | CodeGraphInferredGuardTest (8/59), ratio cap + extracted never dropped |

## I — Consumo/Interface
| Bloco | Lang | Status | Evidência |
|---|---|---|---|
| I-1 Atlas-as-language-server | php | ⬜ | |
| I-2 CLI atlas ctx | php | ⬜ | |
| I-3 diff/PR→review-context | php | ⬜ | |
| I-4 auto-pull no loop | php | ⬜ | |

## D — Escala/Perf
| Bloco | Lang | Status | Evidência |
|---|---|---|---|
| D-1 storage/partição | php | ⬜ | |
| D-2 SLO latência | php | ✅ | CodeGraphLatencyBudgetTest, injectable clock + breach record |
| D-3 traversal pesado no python | py | ⬜ | |
