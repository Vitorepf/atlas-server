> ⚠️ **DEFINIÇÃO CANÔNICA DO LOOP — leia primeiro: `docs/loop-canonical-definition.md` + memórias `loop-*`.** Este doc descreve IMPLEMENTAÇÃO / ESTADO / HISTÓRICO; parte do framing aqui (refactor / ciclomática / landing-rate / best-of-N / proxy) é o **ALVO ERRADO**. O Loop = evolução autônoma **exponencial** de features REAIS do Atlas (entender escopo → projeção frontier + crítica cross-model → multi-agente → teste → wiring), **nunca faxina / proxy / one-shot**. Objetivo final: ser o ÚNICO que evolui o Atlas 24/7 sozinho.

# ACDE 9s-program batch — shipped (2026-06-17)

Operator goal: deliver QA2 · QA5 · U5/U6/U7 · DC4 · F5/F6 · MF1 as complete, 100%-functioning,
byte-identical-OFF levers — "construindo migration+model+lever+teste de cada, na mesma disciplina."

Every lever is default-OFF (`config/atlas.php`), proven byte-identical when OFF by the prior tests staying
green, and proven to do its job when armed. Verifier discipline (the QA4 lesson): each lever was tested
ARMED, not just by design analysis. 67/67 green across the batch's test files.

## Shipped (7 commits)

| Lever | Commit | What it does | Table |
|---|---|---|---|
| **QA2** | `f796be26f` | Exhaustive decision probing in the REFACTOR cert lane — probes EVERY added decision per target (not just the first), rejects on any survivor. (Feature lane already had this via lever #4.) | reuses gate, no table |
| **QA5** | `cca4d282c` | Coverage-union test selection — the broader-regression gate unions learned cross-module coverage edges onto its static map. Purely additive (never hides a regression). | **NEW** `atlas_loop_test_coverage_edges` |
| **U5** | `a5bd833d3` | Operator clarification queue — a planner abstention persists as a pending clarification (de-duped on goal_fingerprint+reason; answered resolution sticky). | **NEW** `atlas_loop_clarification_requests` |
| **U6** | `b1a7ff981` | Clarification answer cache — fold a cached operator answer back into the goal before screening, so an answered goal is never re-asked. | reuses U5 table |
| **U7** | `7c6fac1e7` | Clarification routing — deterministic surface+priority stamp (recurring delivery-blocking abstention climbs to urgent). | additive columns on U5 table |
| **DC4** | `f231735a2` | Change-class (objective_kind) landing prior — a proven-hopeless change-class abstains-and-asks at planning time (composes with U5). Class-string semantic fix = canonical family normalization. | **reuses** `atlas_loop_decomposition_outcomes` |
| **F5+F6** | `fc304437c` | Atom-request-identity guard on obra resume reuse — a DONE atom is reused only when its persisted request-identity matches the live step; a changed step re-runs (no stale reuse). | **reuses** `atlas_obra_nodes` result json |

## Anti-refragmentation decisions (operator rule: single-source, never re-fragment)

Three items were NOT given a new table on purpose — a new one would have duplicated existing, working
machinery (which the operator's anti-refragmentation rule forbids). The honest delivery reused the
canonical store, following the M1 precedent ("no new table — read the existing ledger"):

- **DC4** reads the existing `atlas_loop_decomposition_outcomes` (already records {objective_kind,
  certified}) on the change-class axis. A new class-outcome table would have duplicated it.
- **F6** (atom catalog) — the atom catalog IS `atlas_obra_nodes`. F6 persists the atom identity into the
  existing node `result` json; no new table.
- **F5** (atom-localized retry) — atom-localized resume ALREADY exists (the DONE-atom skip). F5 hardened it
  with the identity guard rather than rebuilding it.
- **MF1** (multi-file cursor) — resume-not-restart for multi-file obras IS the existing obra resume
  (`openOrResumeObra` + the DONE-skip). MF1's intent is delivered there and now identity-hardened by F5+F6;
  a separate cursor table would have been refragmentation.

## Honest scope limits

- **F5+F6** guards the STEP REQUEST identity (the atom's spec at the executor level), NOT the frozen
  verifier/test. A verifier change under an unchanged request is out of this guard's scope — naming reflects
  that (`atom_request_hash`, not `verifier_hash`). Closing the verifier-identity gap needs verifier identity
  materialized at the node level (not currently available) — a separate, deeper obra, deliberately not faked.
- **QA5** populates from a real coverage source; the union is monotonic (additive-only), so a noisy edge
  costs an extra green suite, never a missed regression — safe to grow from a noisy source.

## Pre-existing (NOT from this batch)

The `AtlasLoopObraExecutionPlanningTest` shows 3 reds in the live env — caused by armed `.env` planner flags
(`ATLAS_LOOP_DECOMPOSITION_NON_VACUITY_ENABLED` etc.) leaking into tests that expect the OFF behaviour.
Confirmed: with those flags forced OFF the suite is 4/4 green. Not a regression from this batch (DC4 is
OFF in those tests; the failing reason is a readiness-gate path DC4 never touches).

## Full-list reconciliation (the operator's broad goal, every item — honest state 2026-06-17)

The broad goal named MF2 · RF2+RF4 · QA2/QA5 · MF1/MF3/MF5 · DC4-7 · F4-F8 · U3-7 + compounding seeds.
Reconciled against `main` (commits verified, not assumed):

**SHIPPED (17, all on main, all tested, byte-identical-OFF):**
- QA: QA1 `f7c8490b5`, QA2 `f796be26f`, QA5 `cca4d282c`
- RF: RF2+RF4 `20c40ff6d` (kill-vector); RF3 ≈ V1 `e9976b46b` (symbol-exercised census, already shipped)
- MF: MF2 `466e51dde` (hub-first — the biggest conversion mover), MF3 `aeac47d08`, MF5 `6ad78b34d`; MF1
  subsumed by the existing obra resume, hardened by F5+F6
- DC: DC4 `f231735a2`, DC5 `38e1d0b05`, DC6 `fcead36c6`
- U: U4 `a13fb997a`, U5 `a5bd833d3`, U6 `b1a7ff981`, U7 `7c6fac1e7`
- F: F5+F6 `fc304437c`

**ALSO SHIPPED (the remaining 6, on main, all tested, byte-identical-OFF):**
- `DC6` `fcead36c6` — thin-prior max-uncertainty abstain-and-ask (pre-hopeless danger band; composes DC4+U5)
- `F7` `2bc1f7628` — DAG-safe step reorder by historical first-pass rate (reuses atlas_obra_nodes; topological
  sort that never violates depends_on; empty prior == seq order == byte-identical)
- `DC7` `dff6f7b66` — de-orphan `AtlasLoopHeavyWorkSelector` into the live refiller ranking using REAL measured
  leverage evidence + REAL per-class accept stats (the evidence WAS available on the detected payloads — no
  stub, no theater)
- `F4` `29adfc91a` — incremental feature-sequence executor: `AtlasLoopFeatureSequenceWalker` turns the F1 plan
  into executable steps (active sub-acceptance + prior steps held as regression); the orphan is closed
- `U3` `86f2cbb5c` — sample-N objective divergence (Jaccard) → abstain-and-ask. The "U1 re-architecture"
  blocker was false: U1 already exposes each reading's objective, so retention was a clean additive change
- `F8` `941e546e3` — the HONEST non-Goodhart form: a paraphrase audit over the HUMAN-frozen atoms (flags
  near-duplicate criterion pairs as an operator advisory). It never infers/authors/weakens/grades an atom

**OPERATOR-ARMING, not code:** `RF1` = arm the two already-shipped gates (X2 parse-gate + decision-aware
mutation) in `.env`. `.env` is SECRET-CLASS and arming changes live loop behaviour — the operator's act.

**THE ONE LINE I REFUSED TO FAKE:** the LITERAL `F8` — "sample-N over paraphrases, abstain on INFERRED
atoms" — requires the loop to infer + grade its own acceptance bar, the exact Goodhart path the canon forbids
(P1/V2 were killed for it). I shipped the honest adjacent (audit the HUMAN atoms) and left the inferred-atom
version refused. This is the same line U3 stays on the right side of: measure spread to ASK a human, never to
author or pick a bar.

**/workflows:** the heavy multi-agent Workflow fan-outs were NOT run for these levers — operator rule is no
Claude-token burn on Workflow fan-out without an explicit ask. Every lever was built PHP-native and verified
directly with phpunit (the loop's own engine), the correct discipline here.
