# AP-690 Atlas Spec Operating System Contract

Status: proposed
Owner: atlas-ai
Area: spec-operating-system
Risk: high

## Problem

Atlas already has documentation-as-law, APs, Decision Receipts, Evidence
Ledger, architecture validation and Self-Improvement. It still needs a canonical
SDD flow that turns simple user intent into context-grounded spec, plan, tasks,
execution, gates, evidence, drift detection and learning.

## Goal

Document Atlas Spec Operating System as the enterprise SDD layer:

- simple intent becomes operational spec;
- spec is grounded in repo, business, design system and architecture context;
- assumptions and ambiguities are explicit;
- execution is authorized by Decision Receipt;
- implementation is scoped by allowed files/actions;
- evidence proves every requirement;
- drift detector keeps specs alive;
- learning remains proposal-first.

## Non Goals

- No parallel `.atlas` source of truth.
- No runtime implementation in this AP.
- No auto-merge policy.
- No bypass of Kernel, Decision Receipt, Evidence Ledger or docs-health.
- No replacement of APs, canonical docs or Knowledge DB.

## Required Docs

- `docs/engineering-knowledge-base/atlas-ai-spec-operating-system.md`
- `docs/engineering-knowledge-base/spec-operating-system/context-discovery-and-business-context.md`
- `docs/engineering-knowledge-base/spec-operating-system/spec-compiler-and-critic.md`
- `docs/engineering-knowledge-base/spec-operating-system/plan-task-and-receipt-contract.md`
- `docs/engineering-knowledge-base/spec-operating-system/spec-graph-and-traceability.md`
- `docs/engineering-knowledge-base/spec-operating-system/autonomy-and-clarification-policy.md`
- `docs/engineering-knowledge-base/spec-operating-system/drift-detector-and-learning.md`
- `docs/engineering-knowledge-base/spec-operating-system/templates-and-schemas.md`
- `docs/engineering-knowledge-base/spec-operating-system/data-model-and-services.md`
- `docs/engineering-knowledge-base/spec-operating-system/agents-and-mcp-contract.md`
- `docs/engineering-knowledge-base/spec-operating-system/context-packages-and-projections.md`
- `docs/engineering-knowledge-base/spec-operating-system/implementation-roadmap.md`

## Acceptance Criteria

- SDD is linked from START_HERE, README and canonical architecture index.
- Docs explicitly say `.atlas` can be a projection, not source of truth.
- Docs define one-shot rule: act when context is sufficient, ask when
  ambiguity is risky, block when policy/safety is violated.
- Docs define how a "green save button" request becomes governed execution.
- Docs define Spec Graph and traceability from requirement to evidence.
- Docs define drift detection and proposal-only learning.
- Docs define SDD internal data model and Laravel service boundaries.
- Docs define SDD agent roles and MCP resource/prompt/tool boundaries.
- Docs define versioned context packages and `.atlas` projection law.

## Validation

```bash
php artisan atlas:engineering:knowledge docs-health --json
php artisan atlas:engineering:knowledge sync --prune --json
php artisan atlas:ai:architecture-validate --json
php artisan atlas:engineering:knowledge index-code --prune --json
git diff --check
```
