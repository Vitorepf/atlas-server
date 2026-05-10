# AP-689 Research Intelligence And Self-Improvement Runtime Contract

Status: proposed
Owner: atlas-ai
Area: research-self-improvement
Risk: high

## Problem

Atlas needs a governed way to evolve faster than normal product teams without
absorbing bad information, hallucinated sources, hype, weak plans or unsafe
automation.

Research, documentation, planning, implementation, validation and correction
must become one governed loop. The loop must spend more effort on source
quality, synthesis, documentation law and planning than on blind coding.

## Goal

Create the canonical contract for:

- maximum-quality research;
- source trust and anti-hallucination gates;
- research-to-docs promotion;
- implementation planning after documentation;
- continuous self-improvement;
- automated execution only through governed proposal and validation gates.

## Non Goals

- No autonomous runtime mutation.
- No provider-direct evolution channel.
- No crawler or daemon without a dedicated AP.
- No memory write without governance.
- No automatic code apply from research findings.
- No replacement for human review on high-risk changes.

## Canonical Flow

```text
Question / objective
-> Research scout
-> Source quality gate
-> Evidence synthesis
-> Documentation law update
-> AP / plan
-> Implementation
-> Focused tests
-> Architecture / docs validation
-> Evidence packet
-> Self-Improvement proposal
-> Promotion or rollback
```

## Acceptance Criteria

- Documentation defines the research trust ladder.
- Documentation defines the pipeline from research to implementation.
- Documentation defines source, citation and no-hallucination rules.
- Documentation defines self-improvement automation boundaries.
- Documentation defines metrics for research quality and evolution velocity.
- Documentation is linked from START_HERE, README, architecture index and the
  Self-Improvement domain.
- Docs health, knowledge sync, architecture validation and diff check pass.

## Required Docs

- `docs/engineering-knowledge-base/atlas-ai-research-self-improvement-runtime.md`
- `docs/engineering-knowledge-base/research-self-improvement/research-operating-system.md`
- `docs/engineering-knowledge-base/research-self-improvement/evidence-lake-and-citation-health.md`
- `docs/engineering-knowledge-base/research-self-improvement/source-connectors-and-capture.md`
- `docs/engineering-knowledge-base/research-self-improvement/multi-agent-research-roles.md`
- `docs/engineering-knowledge-base/research-self-improvement/scheduled-research-and-triggers.md`
- `docs/engineering-knowledge-base/research-self-improvement/reporting-and-publication-contract.md`
- `docs/engineering-knowledge-base/research-self-improvement/private-connectors-security-and-stack.md`
- `docs/engineering-knowledge-base/research-self-improvement/source-quality-and-trust-ladder.md`
- `docs/engineering-knowledge-base/research-self-improvement/research-pipeline.md`
- `docs/engineering-knowledge-base/research-self-improvement/research-to-docs-promotion.md`
- `docs/engineering-knowledge-base/research-self-improvement/implementation-planning-and-rollout.md`
- `docs/engineering-knowledge-base/research-self-improvement/continuous-self-improvement-loop.md`
- `docs/engineering-knowledge-base/research-self-improvement/schemas-and-packets.md`
- `docs/engineering-knowledge-base/research-self-improvement/metrics-and-evals.md`
- `docs/engineering-knowledge-base/research-self-improvement/automation-runbook.md`
- `docs/engineering-knowledge-base/research-self-improvement/failure-modes.md`
- `docs/engineering-knowledge-base/research-self-improvement/enterprise-excellence-checklist.md`

## Validation

```bash
php artisan atlas:engineering:knowledge docs-health --json
php artisan atlas:engineering:knowledge sync --prune --json
php artisan atlas:ai:architecture-validate --json
git diff --check
```
