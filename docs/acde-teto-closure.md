# ACDE — Teto-closure runbook (the 3 residual ceilings)

> The single-target + obra-scale moat (Leaps 1–5) makes the weak engine deliver verified-against-a-frozen-bar.
> Three residual ceilings survived **by design**. This runbook is how to push each to its honest machine-anchored
> maximum — and the precise, irreducible residual that **no deterministic gate can close** (closing it would
> require the same-engine self-grading judge the moat forbids — the Goodhart). Built 2026-06-16; all flags
> default-OFF + byte-identical-OFF; the design was vetted by a 28-agent adversarial workflow (`wf_440d78c5`).

## Ceiling 1 — design-judgement (wrong abstraction below a named seam)

The boundary-oracle (Leap 2) proves the right FILES are separate nodes; it says nothing about the abstraction
inside them. **Leap 6** anchors the abstraction with a decorrelated AST census against a HUMAN-frozen contract.

- **As-built cert** (`atlas.loop.interface_contract_enabled`): replays the obra net diff and checks each
  contracted file's REAL AST surface — required public methods, implements/extends, **forbidden imports**
  (exact or `Namespace\` prefix), and **forbidden deps reached via container strings** (`app(X::class)`,
  `resolve()`, `App::make`). Anchored on `nikic/php-parser` (decorrelated from the provider engine).
- **Plan-time steering** (`atlas.loop.node_interface_plan_gate_enabled`): REPLANS a DAG with a missing/inverted
  seam-to-seam `depends_on` edge — the planner's first machine design-steering gap.

**Freeze a contract:** `frozen/obra-interfaces/<goal-hash>.json` where `goal-hash` =
`substr(sha256(strtolower(trim($goal))),0,16)`:
```json
{ "files": {
  "app/Support/HubHelper.php": {
    "fqn": "App\\Support\\HubHelper",
    "required_public_methods": ["compute"],
    "implements": ["App\\Contracts\\Helper"],
    "forbidden_imports": ["App\\Services\\"],        // namespace-prefix denylist (anti-inversion)
    "forbidden_depend_on": ["app/Services/Hub.php"], // plan-time seam-edge rule
    "must_depend_on": []
  }
}}
```

## Ceiling 2 — spec/index completeness (un-tested seam = fail-open)

A changed PUBLIC symbol with no frozen command + no wired consumer emits ZERO criteria and certifies fail-open.
**Leap 7** (`atlas.loop.changed_symbol_census_enabled`) forces coverage: every public method declared on the
diff's **added** lines must be NAMED (whole-word) in the corpus of the test files the frozen acceptance commands
run — else `changed_symbol_uncovered`. Reflection / container-string sites are recorded as an audit signal.
**No fixture needed** — it uses the obra's own acceptance commands. **Honest limit:** name-reference is
necessary, NOT sufficient (a thin test that merely names the symbol passes — true sufficiency needs a
mutation-kill / coverage-line anchor, a separate axis; `phpunit.xml` configures no coverage driver).

## Ceiling 3 — greenfield (no per-goal oracle)

For a novel goal nobody froze, the oracle degrades to structural-only. **Leap 8**
(`atlas.loop.decomposition_archetype_enabled`) imports the moat into greenfield via a REUSABLE library: a human
freezes archetypes (a deterministic token classifier + structural invariants), and any goal that classifies is
held to them — no per-goal fixture.

**Freeze an archetype:** `frozen/obra-archetypes/<name>.json`:
```json
{ "id": "extract_class",
  "match_all": ["extract"], "match_any": ["class","helper","service"],
  "min_nodes": 2, "required_create_suffixes": ["Helper.php","Service.php"], "min_distinct_targets": 2 }
```

## Arming (proof of non-false-reject)

| Flag | Fires without a fixture? | Safe to arm now |
|---|---|---|
| `interface_contract_enabled` | no (no contract ⇒ no-op) | ✅ inert until you freeze a contract |
| `node_interface_plan_gate_enabled` | no | ✅ inert until you freeze edge rules |
| `decomposition_archetype_enabled` | no (no archetype ⇒ no-op) | ✅ inert until you freeze an archetype |
| `decomposition_oracle_enabled` (Leap 2) | no | ✅ inert until you freeze a boundary |
| `decomposition_corpus_enabled` (Leap 5) | records only | ✅ pure telemetry |
| `shape_prior_gate_enabled` (Leap 5) | only at n≥min_samples | ✅ inert until corpus fills |
| `changed_symbol_census_enabled` | **YES** (uses acceptance cmds) | ⚠️ behaviour-changing — stricter; weigh throughput |
| `obra_full_cert_enabled` (Leap 3) | **YES** (uses obra acceptance) | ⚠️ behaviour-changing — stricter; weigh throughput |

The ✅ rows are proven non-false-reject (no frozen artifact ⇒ the band returns `[]` / passes ⇒ byte-identical).
The ⚠️ rows fire on real obras and tighten the bar — arm them when you want the stricter moat and accept fewer
(but higher-quality) certifications.

## The irreducible residual (REALITY, not a missing feature)

1. **Greenfield origination.** For a truly novel obra matching no frozen contract/archetype and referencing
   nothing the static index carries, every machine anchor degrades to structural-only. Which seams should exist
   is authored by the model (steered) and ratified by a human (frozen). There is no out-of-model, out-of-human
   deterministic ground truth for "is this the right decomposition of work nobody has done before."
2. **Self-consistent-but-wrong.** The gates prove a plan is COHERENT with what it declared — never that what it
   declared is RIGHT. A plan that declares the wrong split and faithfully builds it passes.
3. **Behaviour below a honored signature.** Right name, right imports, right interface, WRONG body has no
   syntactic footprint — it stays with tests/mutation, not these contracts.
4. **Name-reference ≠ assertion strength** (see Ceiling 2).
5. **The human-frozen bar is only as complete/correct as the human.** These mechanisms enforce human judgement
   deterministically and ungameably — they do not author it.

**Net:** the loop now converts "wrong abstraction below a NAMED seam" and "un-named new public symbol" from
undetectable to machine-detectable, and imports the single-target moat into greenfield WHENEVER a human freezes
a contract or characterizes an archetype. The originative-design + semantic-correctness residual is probabilistic
in the model and ratified by a human — and correctly OFF-LIMITS to any same-engine self-grading gate.

## Measurement flywheel — the ">=2x vs ultracode" head-to-head runbook

The verdict is MACHINE-RESOLVED on both arms (refusal counts as a defect; the ">=2x" claim is the relative-risk
lower bound on defect rate, so it needs real evidence — never a self-graded number). The CODE is complete and
runnable end-to-end; reaching a CONFIDENT verdict is a runtime batch, not a code gap.

```
# 1. ACE arm — the loop's OWN merged-to-main outcomes (report-only; reads the loop tables).
php artisan atlas:loop:dqs-extract-ace --multi-file-only --out=ace.json

# 2. OPUS arm — real one-shot ultracode refactors on the SAME task set, machine-resolved.
#    Manifest entry per task: {task_id, committed, workspace, test_command, [mutation_kill_ratio, completeness, cyclomatic_drop]}.
#    The command RUNS each test to resolve canary (green/red) — a refused task is committed:false (a defect).
php artisan atlas:loop:dqs-capture-opus-arm --manifest=opus-manifest.json --out=opus.json

# 3. Verdict — relative-risk lower bound on defect rate; tie-breaks decide only near parity.
php artisan atlas:loop:dqs-head-to-head --ace=ace.json --opus=opus.json --factor=2.0
```

**The irreducible runtime cost (honest).** A CONFIDENT `a_at_least_factor_better` needs the SAME task set on both
arms at **N≈30+** (the relative-risk CI is wide at small N — by design; a 1–5 sample correctly reports
`parity_tie_breaks_decide`/`a_better_not_factor`, not a fluke 2x). That means: (a) engine-hours of the loop
(Hermes/MiniMax) to accrue ~30 merged obras for the ACE arm, and (b) a real ultracode pass over those same ~30
tasks for the Opus manifest. Producing the Opus arm by hand inside this provider would be ~30 Claude refactors —
exactly the token burn the operator rules forbid — so it is deliberately left as an operator-triggered batch.
The instrument refuses to manufacture confidence it has not measured.
