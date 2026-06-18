> ⚠️ **DEFINIÇÃO CANÔNICA DO LOOP — leia primeiro: `docs/loop-canonical-definition.md` + memórias `loop-*`.** Este doc descreve IMPLEMENTAÇÃO / ESTADO / HISTÓRICO; parte do framing aqui (refactor / ciclomática / landing-rate / best-of-N / proxy) é o **ALVO ERRADO**. O Loop = evolução autônoma **exponencial** de features REAIS do Atlas (entender escopo → projeção frontier + crítica cross-model → multi-agente → teste → wiring), **nunca faxina / proxy / one-shot**. Objetivo final: ser o ÚNICO que evolui o Atlas 24/7 sozinho.

# ACDE — the complete buildout to the HONEST MiniMax-M3 ceiling

Produced by an adversarial multi-agent pass (8 scenario designers → per-scenario refuters that held the
Section-D line → synthesis + completeness critic). Every ceiling here is **post-refutation** — the refuters
lowered four inflated designer claims and re-verified against live `file:line` seams. No lever manufactures
model-bounded capability; every grade above ~7 on the novel lanes is an **effective** (human-in-the-loop, one
operator question) number, not full autonomy.

## Honest ceilings (MiniMax-M3 only)

| Scenario | Today | Autonomous ceiling | Effective ceiling (one operator question) |
|---|:---:|:---:|:---:|
| Refactor single-file, behavior-preserving | 8.5 | **9.0** | 9.0 |
| QA / anti-regression guarantee | 9.0 | **9.3** | 9.3 |
| Refactor multi-file, known shape | 6.5 | **8.25** † | 8.25 |
| Feature small/bounded | 6.0 | **~7** | **8.0** |
| Decide WHAT to do (autonomous) | 6.5 | **7.5** | 7.5 |
| Understand a vague request | 4.5 | **~6** | **7.0** |
| Feature ENORMOUS greenfield novel | 3.5 | **4.5** | **5.5** |
| 24/7 autonomy + self-heal | 9.5 | **9.5** (saturated) | 9.5 |

† multi-file 8.25 holds **only** under the `covered≥2` known-shape guard; an unanchored novel cluster falls to the human-frozen-bar path (it would be a novel-decomposition act = Section D #1).

## The levers, by scenario

**Refactor single-file → 9.0** (deterministic, autonomous)
- `RF1` arm the two shipped gates (parse-gate + decision-aware mutation) — S
- `RF3` symbol-exercised census (call+assert, not name-presence) — S
- `RF2+RF4` per-operator kill VECTOR — **co-scoped** (a 0.7 floor on a denominator==1 receipt is theater; rewrite the receipt to accumulate one record per applied operator first) — M
- *Residual (why not >9): data-flow `skipped_no_refactor_mutant` certified-skip + finite mutation vocabulary — NOT design cohesion (a behavior-preserving rewrite's cohesion is irrelevant to the certified claim).*

**QA → 9.3** (pure structure, autonomous)
- `QA1` three new mutation operators (exception-noop / null-coalesce / early-return-delete) — S — *independently arm-able*
- `QA4` four new cert-pipeline source-scan invariants — S — *independently arm-able*
- `QA2` per-family decision kill-ratio floor — S — **hard-gated behind the RF2+RF4 per-operator rewrite** (same denominator==1 theater otherwise)
- `QA5` compounding blast-radius test map — S (compounding)

**Refactor multi-file → 8.25**
- `MF2` decompose the cluster cert into hub-first single-file framework-refactor tasks (route each through the proven single-file lane) — M — **the real win for the ~17% conversion drop**; `none` residual only under `covered≥2`
- `MF5` cluster-framing compounding degrade (≥4 attempts, cert-rate<0.3 → hub-only) — S (compounding)
- `MF3` multi-file rejection-dimension narrowing directive (via the live `dimensionDirective` seam) — S
- `MF1` durable per-sequence cursor (kills merge-lag churn only) — S

**Decide → 7.5** (corrected down from 8 — the "4 orphan deciders" premise was false; only the Selector entry-point + EVDecider are orphan)
- `DC7` wire the orphan `HeavyWorkSelector` entry-point into live refiller ranking — M
- `DC4` materialized per-class outcome ledger (single source for EV + M1) — M
- `DC5` compound bandit key (target_type|tier × work-class Wilson-LB) — S
- `DC6` abstain-and-ask on thin prior / max-uncertainty — S (human_question)

**Feature bounded → 7 autonomous / 8 effective**
- `F4` incremental feature-sequence executor (close the verified orphan) — M
- `F5` atom-localized retry (split the monolithic frozen test into N files) — S
- `F8` sample-N-over-paraphrases abstain on inferred atoms — M (human_question)
- `F6` provider-safe atom catalog, operator-confirmed reuse — S (human_question)
- `F7` step reorder by historical first-pass rate — S (compounding)

**Understand vague → ~6 autonomous / 7 effective**
- `U5` abstain-and-ask clarification queue (one question, then resume) — M (human_question)
- `U6` vagueness-pattern → operator-answer compounding cache — S (compounding)
- `U7` route the clarified intent through the readiness-gated spec compiler — S (human_question)
- `U4` cost-free deterministic vagueness pre-screener — S
- `U3` sample-N objective divergence (Jaccard) — M — only after the U1 re-architecture that retains the K objectives

**Greenfield ENORMOUS novel → 4.5 autonomous / 5.5 effective** (hard-capped)
- `GF2` parallel sample-N decomposition + structural-disagreement vote → abstain — M (human_question)
- `GF4` human-in-the-loop frozen-bar authoring interface — M (human_frozen_bar)
- `GF5` archetype-family bar lookup + human confirm — S (human_frozen_bar)
- `GF1` operator review-cadence checkpoint wrapper — S (human_question)
- `GF6` node-count + no-frozen-oracle escalation gate — S (human_question)

**24/7 autonomy → 9.5** — saturated; no net-new lever (only maintenance hardening, already shipped).

## Wave sequence

- **Wave 0 — free arming + compounding seeds** (start the priors filling now, zero new logic): RF1, RF3, QA5, MF5, DC4, DC5, U6, F7.
- **Wave 1 — harden the QA/refactor 9s**: the RF2+RF4 per-operator rewrite ONCE (shared prerequisite), then QA1, QA4, QA2, MF3, MF1.
- **Wave 2 — decompose the hard lanes to the proven single-file/single-step bar** (largest honest uplift): MF2, F4, F5, DC7.
- **Wave 3 — abstain-and-ask + readiness routing** (effective-grade uplift, honestly human-gated): DC6, U4, U5, U7, F8, F6, GF2, GF4, GF5.
- **Wave 4 — hard-cap escalation guards LAST** (cap the blast radius, do NOT lift the ceiling): GF1, GF6, U3.

## The three hard caps no MiniMax-only lever lifts to 9

1. **Greenfield-novel origination correctness** (Section D #1) — caps enormous-greenfield autonomous at ~4.5; a majority-of-weak-model vote is a *consistency* oracle, not a *correctness* oracle (correlated weak-model error converges on wrong skeletons).
2. **Novel-feature frozen bar** (Section D #3) — the human-frozen verification atoms are the irreducible input; GF4/GF5/F6 only *look up* or let the operator *confirm* a bar, never manufacture one.
3. **Semantic correctness + design cohesion of NEW behavior** (Section D #2) — the pétreo cert proves behavior-preservation vs the frozen bar, not that the new behavior is what was wanted; an LLM design judge is forbidden.

## Brutal bottom line

After the full buildout on MiniMax-M3 only, the loop is an **honest 8–9 autonomous delivery machine on
bounded / known-shape / QA / refactor work** that compounds upward on its own as priors fill — and a **~4.5–7
*effective* machine on novel-comprehension / novel-greenfield only because abstain-and-ask honestly hands the
model-bounded core to one operator question**. Structure multiplied the weak model everywhere it could; nowhere
did a lever manufacture model-bounded capability. Every number above 7 on the novel lanes is a human-in-the-loop
effective grade, not full autonomy.
