# Motor de Entrega ACOS (#18 C/D + #19) — status honesto da sessão

**Data:** 2026-07-07 · **Autonomia:** Carta (age+commita+etiqueta, sem aprovação) · **Landing:** todo slice via `atlas:land` (commit escopado + Diário, no mesmo ato) · **Sem push.**

## Entregue nesta sessão (8/18 endereçados) — o NÚCLEO do motor está vivo

| Slice | O quê | Commit | Prova |
|---|---|---|---|
| **L1** | `atlas:land` — porta única de commit escopado (fail-closed sob lock, `git add -A` impossível) + Diário no mesmo ato | 64ec572 | AtlasLandCommandTest 2/2 |
| **P1** | `atlas:pregate` ≤3s (php -l + pint + phpstan) + **fix do crash default do phpstan** (`--memory-limit=3G`) | c2e4c8f | AtlasPregateCommandTest 2/2; fast-path 0.18s |
| **C2** | 5ª fonte advisory `relevant_memory` na esteira (`next()` fail-open, escopada por `allowed_files`, recall query-aware, zero provider spend) | 5e02195 | AtlasTaskServingRelevantMemoryTest 1/1; frozen 4/4 |
| **P6** | vendor **clonado** (`cp -Rc` clonefile) nos 2 provisioners — **vetor wiper morto** | 03062cd | AtlasCloneDirWiperVectorTest 2/2 (isola + contraste symlink envenena) |
| **P3** | `atlas:test:cached` — receipt-cache (hash teste,impl) verde-fresco⇒skip; senão runAndRecord anti-fake-green | 6ce8d3d | AtlasTestCachedCommandTest 2/2 |
| **S2** | sala de operações no `session-bootstrap` (obra ativa via T1 + master switch + probe de locks + WOs) | 778075f | AtlasSessionBootstrapOpsRoomTest 1/1 |
| **L3** | guard do reset do soak — **já enforçado + testado** (verificação, ponytail rung-1, sem código morto) | 6eb47b1 | test_dirty_working_tree_is_refused 1/1 |
| **S4** | dieta do `atlas-ctx.sh` — dedupe por hash + skip de prompt operacional | cf60d5b | atlas-ctx-diet.test.sh 3/3 |

Critérios PRONTO já batidos: **P6 prova o vetor wiper morto** ✅ · **atlas:land serializou os commits** ✅ (8/8 pela porta lockada).

## Deferido (10/18) — com o PORQUÊ honesto (não fabricar green)

- **D3 / D4 / C3 / D5** (ladder de memória #18): gates dependem de **dados vivos** (`atlas_memory_entries` está em wiper-stubs) + backfill operacional. D3 ≥70% relações, D4 ≥20% feedback, D5 score ≤50 "de hoje" só sobem quando os números crus se movem — não dá pra provar honesto num teste sqlite. Mecanismo de feedback (`AtlasMemoryUsageService::recordFeedback`) já EXISTE (0 callers); D4 = wire no Stop hook + análise de diff (cross-surface).
- **P2** (`atlas:test:impacted`): PÉTREA — recall ≥0,85 provado por `atlas:programming:test-impact-benchmark` ANTES de trocar suite. Substancial; ligar `ProgrammingTestImpactAnalyzer` às edges `test_targets` via `CodeGraphEdgeResolver` + rodar o benchmark.
- **P4** (paratest): PÉTREA — sufixar `TEST_TOKEN` nos 3 paths compartilhados + quarentenar colisores ANTES; NÃO tocar sqlite `:memory:`. Muda a suite inteira — risco de desestabilizar; precisa de rodada de quarentena dedicada.
- **P5** (`atlas:golden:freeze/check`): harness golden (ordenação PROFUNDA via `MissionCanonicalHash`); design meaty (determinismo de `setTestNow` cross-process).
- **S1** (fan-out sem stall): a INFRA já existe (tipos `Explore`/`Plan` excluem a tool `Agent` por construção; `general-purpose` a tem; `Workflow` p/ ≥2 fases). Falta só a disciplina/template documentada — não é código enforçável.
- **S3** (estado pós-compactação): re-injeção só após compactação detectada — detecção de compactação no hook é o ponto não-trivial.

## Retomada
Próximo slice tratável e testável sem dados vivos: **P2** (com o benchmark ≥0,85 como gate pétreo) ou **P5** (golden harness). Ladder de memória (D3→D4→C3→D5) precisa primeiro de registry re-hidratado (D1/D2 da #18, fora do escopo desta sessão).
