---
id: atlas-ai-self-construction-autonomous-implementation-loop
type: engineering_knowledge
title: Atlas Self-Construction Autonomous Implementation Loop
status: active
category: architecture
priority: 100
summary: Governed loop for Atlas researching, documenting, specifying, implementing, validating and improving itself.
tags:
  - atlas-ai
  - self-construction
  - autonomous-loop
capabilities:
  - autonomous_implementation_loop
  - self_construction_os
decisions:
  - Autonomous construction is a loop with gates, not an open-ended coding session.
  - Every loop stage must emit an artifact or evidence.
maintenance:
  - Update before implementing the runtime loop or adding autonomous scheduler behavior.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-self-construction-os.md
  - docs/engineering-knowledge-base/self-construction/self-programming-safety-contract.md
owner: atlas-ai
layer: 0.8-self-construction
line_limit: 220
---

# Atlas Self-Construction Autonomous Implementation Loop

The loop is the operational heart of governed self-programming.

## Loop Stages

```text
1. Observe
2. Diagnose
3. Research
4. Document
5. Specify
6. Plan
7. Decide
8. Execute
9. Validate
10. Evidence
11. Drift Check
12. Learn
13. Report
```

## Stage Contracts

| Stage | Output |
|---|---|
| Observe | gap, metric, failure, user request or roadmap item |
| Diagnose | layer, capability, maturity and risk |
| Research | source-backed findings or "research not needed" reason |
| Document | canonical doc/AP update when durable |
| Specify | Meta-SDD spec and acceptance criteria |
| Plan | technical plan and task list |
| Decide | Decision Receipt with scope, gates and rollback |
| Execute | patch or no-op with reason |
| Validate | tests, docs-health, architecture validation, scans |
| Evidence | diff, command output, traceability, residual risk |
| Drift Check | spec/code/doc mismatch report |
| Learn | proposal, not silent mutation |
| Report | concise human-readable closeout |

## Stop Conditions

Stop and ask/review when:

- target context is missing;
- risk is high and no human gate exists;
- gates are unavailable;
- rollback is impossible;
- spec conflicts with canonical docs;
- implementation touches forbidden files;
- validation fails outside receipt scope;
- research sources conflict.

## Small Slice Rule

The loop must prefer the smallest block that improves maturity.

Bad:

```text
Implement all self-programming runtime.
```

Good:

```text
Implement read-only self-construction gap report with tests and docs.
```

## Loop Receipt Requirements

Each autonomous loop execution needs:

```yaml
loop_receipt:
  operation_id:
  target_capability:
  maturity_delta:
  allowed_files:
  allowed_commands:
  required_gates:
  rollback:
  evidence:
  max_scope:
```

## Long-Running Checkpoint Receipt

Before Atlas treats a resumed construction session as part of a long-running loop, the session must carry a read-only checkpoint receipt from the readiness digest:

```bash
php artisan atlas:ai:self-construction --readiness-digest --json
php artisan atlas:ai:self-construction --continuation-token --json
```

The checkpoint receipt is unsigned and non-authorizing. It binds current phase, next action, blockers, checklist count, required next commands and lineage hashes into `checkpoint_receipt_hash` so a future session can resume from auditable state without relying on chat history.

The checkpoint receipt does not write ledger events, sign execution, enable provider dispatch, mark completion or change autonomy policy. Any future durable checkpoint writer needs a separate AP, signed receipt and tests.

## Learning Rule

The loop may generate learning proposals such as:

- update SDD template;
- add a new gate;
- improve source scoring;
- update priority weights;
- add common failure pattern.

It may not apply critical learning automatically.
