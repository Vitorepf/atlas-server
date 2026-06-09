# STATUS — 54 blocos (AP-815) · placar honesto

> ✅ = código + teste verde + zero-regressão provada · 🔨 = em andamento · ⬜ = pendente
> Spec: `ATLAS-CROSS-PROJECT-CONTEXT-DEEP-ROADMAP.md` · Contrato: `docs/ap/AP-815-*.md`
> Regra anti-over-claim: só marca ✅ com nome do teste na coluna Evidência.

## Progresso: 1/54 ✅

## W — Fundação cross-project
| Bloco | Lang | Status | Evidência |
|---|---|---|---|
| W-1 keying | php | ✅ | CodeGraphWorkspaceKeyingTest (3/14), stash-proven zero-regression |
| W-2 scope+--workspace | php | ⬜ | |
| W-3 readers workspace-aware | php | ⬜ | |
| W-4 pipeline AWIS por-workspace | php | ⬜ | |
| W-5 isolar memória/outcome | php | ⬜ | |
| W-7 identidade estável | php | 🔨 | resolver entregue no W-1 (CodeGraphWorkspaceIdentity); falta monorepo sub-scopes |
| W-8 retenção/GC + forget | php | ⬜ | |
| W-9 schema-versioning + reindex | php | ⬜ | |
| W-10 lock de index | php | ⬜ | |
| W-11 first-index orçado | php+py | ⬜ | |

## P — Precisão
| Bloco | Lang | Status | Evidência |
|---|---|---|---|
| P-1 cauda dinâmica type-flow | native | ⬜ | |
| P-3 data-flow/taint | py | ⬜ | |
| P-4 semântico governado | py | ⬜ | |
| P-5a tree-sitter breadth | py | ⬜ | |
| P-5b LSP/SCIP | php+py | ⬜ | |
| P-7 framework-aware 🔑 | native | ⬜ | |
| P-8 co-change (git) | py | ⬜ | |
| P-9 coverage edges | php | ⬜ | |
| P-10 contract cross-language | php+py | ⬜ | |
| P-11 line-precise anchoring | py | ⬜ | |
| P-12 multimodal ingest | py | ⬜ | |
| P-13 Leiden communities | py | ⬜ | |

## E — Eficiência
| Bloco | Lang | Status | Evidência |
|---|---|---|---|
| E-1 compressão-no-retrieval 🔑 | php | ⬜ | |
| E-2 incremental/live | php+py | ⬜ | |
| E-3 context pack mínimo | php | ⬜ | |
| E-6 ranker híbrido | py | ⬜ | |
| E-7 tiered/skeleton-first | php+py | ⬜ | |
| E-8 anti-contexto/poda | php | ⬜ | |
| E-9 cache de query | php | ⬜ | |
| E-10 telemetria de economia | php | ⬜ | |

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
| G-1 privacy class por workspace | php | ⬜ | |
| G-5 secret/PII scan 🔑 | php+py | ⬜ | |
| G-6 licença/proveniência | php | ⬜ | |
| G-7 access policy | php | ⬜ | |
| G-8 integrity hash | php | ⬜ | |
| G-9 forget/purge | php | ⬜ | |

## Q — Qualidade do grafo
| Bloco | Lang | Status | Evidência |
|---|---|---|---|
| Q-1 self-audit/health | php+py | ⬜ | |
| Q-2 eval harness 🔑 | py | ⬜ | |
| Q-3 regressão do grafo | py | ⬜ | |
| Q-4 guarda INFERRED | php | ⬜ | |

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
| D-2 SLO latência | php | ⬜ | |
| D-3 traversal pesado no python | py | ⬜ | |
