# Cortex Council v3

The Cortex Council is a fact-only multi-lens observer of code subjects. Five lenses (CallGraph, DataFlow, GitHistory, TestCoverage, DocIntent) each emit FACTS for the same subject; a triangulator composes them into a `CouncilReport` carrying:

- **raw facts by lens** — every lens output preserved verbatim
- **agreements** — facts whose canonical signature is asserted by ≥2 lens ids
- **disagreements** — lens-emitted `disagreement_signals` + cross-lens fact conflicts (kind+target with differing discriminators), all as first-class FACTS

Operator memory: *"Disagreement is itself a FACT"*. The council never picks a winner, never emits a score, never weights votes. Downstream code reads the report and decides.

## CLI

`atlas:loop:cortex:council --subject=<id> [--file-path=<path>] [--source-code=<php>] [--json]`

Flag-gated by `config('atlas.cortex.council.enabled', false)`. Default OFF ⇒ the CLI prints `{"status":"disabled"}` and runs ZERO lenses.

## Pétreo invariants

- `CouncilReport` carries NO `score`, NO `verdict`, NO `rank`, NO `winner` field
- Triangulator is pure (no IO, no provider call)
- Lens registry holds the 5 built-in lenses as singletons; re-registering the same id with a different class throws (silent perspective-swap is a Goodhart hazard)
