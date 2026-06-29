# Concepts

Atlas Server is built on a set of cross-cutting doctrines that appear across all eight subsystems. These are not isolated rules in one module. They are structural invariants enforced in the constitution, the frozen judge, the master switches, the provider-safety guards, and the database schema. Understanding them is a prerequisite for working in any part of the codebase.

The doctrines exist because Atlas evolves itself 24/7 through an autonomous loop with no human reviewing every change. Without these guardrails, a self-improving system would optimize measurable surrogates, judge its own work, leak private data to external models, and grant itself authority it never earned. Each doctrine closes one of those failure modes.

## Concept pages

- [Anti-Goodhart and no-proxy](anti-goodhart.md) — never optimize a measurable surrogate instead of real capability; refactor that preserves behavior is zero improvement
- [Separation of powers](separation-of-powers.md) — no single agent originates, implements, judges, merges, and promotes its own work
- [Provider safety](provider-safety.md) — every byte that crosses to an external AI is redacted by construction
- [Evidence and receipts](evidence-and-receipts.md) — append-only, tamper-evident, signed receipt chains prove what actually ran
- [Knowledge governance](knowledge-governance.md) — the authority hierarchy that resolves conflicts between docs, code, ledgers, read models, and projections
- [Earned autonomy](earned-autonomy.md) — no scope gets 24/7 authority by ambition; it is earned through receipts, gates, and real value

## Where the doctrines live

The doctrines are not documented only here. They are enforced in code and config:

- The [Autonomous Evolution Loop](../systems/evolution-loop/index.md) encodes anti-Goodhart in its frozen judge, anti-farm floor, and diff-earned gate.
- The [Self-Construction Government](../systems/self-construction-government/index.md) enforces separation of powers across 16 organs and the constitution.
- The [Open Brain](../systems/open-brain/index.md) enforces provider-safety in `AtlasMemoryPrivacyService`, `AtlasOpenBrainGuardService`, and the AURG provider floor.
- The [CLI and operator surface](../systems/cli-operator/index.md) enforces earned autonomy through fail-closed master switches the loop can never re-enable.

See the [glossary](../overview/glossary.md) for definitions of terms used across these pages (frozen judge, pétreo, babá, FREIO, ambition faculty).
