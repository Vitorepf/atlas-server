# Motor de Entrega ACOS (#18 C/D + #19) — status honesto da sessão

**Data:** 2026-07-07 · **Autonomia:** Carta · **Landing:** todo slice via `atlas:land` (commit escopado + Diário no mesmo ato) · **Sem push.**

## Entregue (14/18 endereçados) — 12 código testado + 2 verificados

| Slice | O quê | Commit | Prova |
|---|---|---|---|
| **L1** | `atlas:land` — commit escopado fail-closed + Diário | 64ec572 | 2/2 |
| **P1** | `atlas:pregate` ≤3s + fix crash phpstan (`--memory-limit=3G`) | c2e4c8f | 2/2 |
| **C2** | 5ª fonte `relevant_memory` na esteira (fail-open) | 5e02195 | 1/1 + frozen 4/4 |
| **P6** | vendor clonado (`cp -Rc`) — **vetor wiper morto** | 03062cd | 2/2 adversarial |
| **P3** | `atlas:test:cached` receipt-cache anti-fake-green | 6ce8d3d | 2/2 |
| **S2** | ops-room no session-bootstrap (obra T1 + locks + WOs) | 778075f | 1/1 |
| **S4** | dieta do `atlas-ctx.sh` (dedupe + skip operacional) | cf60d5b | 3/3 shell |
| **P2** | `atlas:test:impacted` (world-model edges + fallback) | 4e7f4a3 | 2/2, benchmark 1.0 |
| **D5** | recalibrar quality — 4 crus 1ª classe; **score 93→56** | bfe27e4 | 13/13 + medição viva |
| **L2** | lease blackboard-aware (claim de outro engine⇒adiado) | 73d16e5 | 2/2 + frozen 5/5 |
| **D4** | `atlas:memory:feedback-implicit` (citada∧diff=útil) | 6556525 | 2/2 |
| **P5** | `atlas:golden` freeze/check (hash PROFUNDO por-caso) | 15218c5 | 2/2 (inclui gotcha) |
| **L3** | guard reset do soak — já enforçado + testado (verificação) | 6eb47b1 | 1/1 existente |
| **S1** | disciplina fan-out (infra Explore/Plan/Workflow existe) | c8081d9 | verificação |

Critérios PRONTO: ✅ **P6 vetor wiper morto** · ✅ **atlas:land serializou 14 commits** · ⚠️ **D5=56** (não ≤50 — ver abaixo).

## D5 = 56, não ≤50 (decisão consciente anti-Goodhart)
93→56 na medição VIVA, dirigido pelos números crus (feedback=0, rationale=5, relation_density=0, structural_honesty=50). O invariante que importa — "sobe SÓ quando D1-D4 movem os crus" — está satisfeito. Os 6 pts de gap vêm de dims legitimamente saudáveis (provider_safety/governance/freshness=100: o wiper genuinamente É seguro/sem-conflito/fresco). **NÃO tunei peso pra forçar ≤50** — seria exatamente o Goodhart que o cânone do loop proíbe. 56 é o número honesto; cairá quando D1-D4 rodarem de verdade.

## Deferido (4/18) — porquê honesto
- **D3** (memória↔code + supersede): gate ≥70% relações precisa de backfill em dados vivos (registry em wiper-stubs). Mecanismo de supersede (`superseded_by` + relations) é extensão testável do `AtlasMemoryRelationsCommand` (0 uso) — próxima sessão.
- **C3** (fechamento simétrico G0): precisa do canal de candidato G0 + filtro delta-surpresa cross-superfície; heavier.
- **P4** (paratest): PÉTREA — quarentenar colisores + TEST_TOKEN nos 3 paths ANTES, sqlite intocado; muda a suite inteira e o gate "~50min sob 8 workers" exige uma rodada real de ~50min (não fabricável in-session).
- **S3** (estado pós-compactação): detecção de compactação no hook é o não-trivial (sem marcador claro do harness).

## Retomada
Próximos tratáveis sem dados vivos: **D3 mecanismo** (supersede/link no command) e **S3** (se houver sinal de compactação do harness). **P4** precisa de janela dedicada (quarentena + rodada de suite). **C3** precisa do canal G0.
