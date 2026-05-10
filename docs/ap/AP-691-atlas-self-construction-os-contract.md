# AP-691 Atlas Self-Construction OS Contract

Status: proposed
Owner: atlas-ai
Area: self-construction
Risk: critical

## Problem

Atlas now has Documentation OS, Knowledge Governance, Cognitive Runtime,
Research Self-Improvement Runtime and Spec Operating System. The next layer is
the law for Atlas building Atlas: how it researches, documents, specifies,
implements, validates, repairs and improves itself without losing the thread,
creating parallel architectures or bypassing governance.

## Goal

Document Atlas Self-Construction OS as the critical layer that governs
self-programming:

- every Atlas core change is framed as governed construction work;
- self-programming is SDD-driven, evidence-driven and rollback-aware;
- implementation order follows the build graph and priority engine;
- capability maturity is measured, not guessed;
- autonomous loops are allowed only inside receipts, gates and safety contracts;
- failure modes are explicit and auditable;
- any AI can continue construction from docs without relying on conversation
  memory.

## Non Goals

- No autonomous runtime implementation in this AP.
- No auto-merge or self-mutation without human/governance gate.
- No new provider, daemon, external runtime or parallel memory.
- No bypass of APs, SDD, Decision Receipts, Evidence Ledger or docs-health.
- No claim that Atlas is already self-programming at full autonomy.

## Required Docs

- `docs/engineering-knowledge-base/atlas-ai-self-construction-os.md`
- `docs/engineering-knowledge-base/self-construction/constitution.md`
- `docs/engineering-knowledge-base/self-construction/meta-sdd-contract.md`
- `docs/engineering-knowledge-base/self-construction/capability-maturity-ladder.md`
- `docs/engineering-knowledge-base/self-construction/build-graph.md`
- `docs/engineering-knowledge-base/self-construction/implementation-priority-engine.md`
- `docs/engineering-knowledge-base/self-construction/autonomous-implementation-loop.md`
- `docs/engineering-knowledge-base/self-construction/self-programming-safety-contract.md`
- `docs/engineering-knowledge-base/self-construction/quality-bar-and-metrics.md`
- `docs/engineering-knowledge-base/self-construction/failure-modes.md`
- `docs/engineering-knowledge-base/self-construction/builder-persona-and-handoff.md`
- `docs/engineering-knowledge-base/self-construction/runtime-implementation-roadmap.md`

## Acceptance Criteria

- Self-Construction OS is linked from START_HERE, README and canonical index.
- Docs define what Atlas may and may not change by itself.
- Docs define Meta-SDD for changes to Atlas itself.
- Docs define maturity levels from documented to strategic self-programming.
- Docs define build graph dependencies between Memory, SDD, Research, Runtime,
  Voice, Mobile, Evidence and Self-Improvement.
- Docs define priority rules that prevent distraction by low-leverage features.
- Docs define autonomous implementation loop from gap detection to evidence.
- Docs define self-programming safety contract with rollback and gates.
- Docs define "absurd level" quality bar as measurable behavior.
- Docs define failure modes, builder persona and handoff packet.

## Validation

```bash
php artisan atlas:engineering:knowledge docs-health --json
php artisan atlas:engineering:knowledge sync --prune --json
php artisan atlas:ai:architecture-validate --json
php artisan atlas:engineering:knowledge index-code --prune --json
git diff --check
```
