---
id: atlas-ai-research-self-improvement-failure-modes
type: engineering_knowledge
title: Atlas AI Research Self-Improvement Failure Modes
status: active
category: safety
priority: 98
summary: Failure taxonomy for research, documentation promotion and self-improvement automation.
tags:
  - atlas-ai
  - research
  - failure-modes
  - safety
capabilities:
  - research_failure_detection
  - self_improvement_safety
decisions:
  - Research and self-improvement failures must fail closed.
  - Hallucinated source is a stop-the-line condition.
maintenance:
  - Update when source verifier, crawler, evaluator or Curator promotion changes.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-research-self-improvement-runtime.md
  - docs/engineering-knowledge-base/research-self-improvement/source-quality-and-trust-ladder.md
---

# Atlas AI Research Self-Improvement Failure Modes

## Failure Table

| Failure | Risk | Required response |
|---|---|---|
| Hallucinated source | False truth enters Atlas | Stop, mark packet invalid, require source verification. |
| Hype-driven release | Provider marketing becomes roadmap | Route through Provider Evolution and benchmark. |
| Secondary source treated primary | Weak evidence | Downgrade tier, require primary source. |
| Research without docs | Context lost | Promote to owner doc or archive. |
| Docs without AP/plan | Unbounded implementation | Create AP/plan before code. |
| Proposal auto-applied | Unsafe autonomy | Block, require review gate. |
| Memory polluted by weak claim | Long-term degradation | Revoke memory, trace source, add guardrail. |
| Benchmark missing | Improvement unproven | Hold promotion. |
| Contradiction ignored | Bad decisions | Add conflict record and research more. |
| Automation has no rollback | Enterprise risk | Refuse promotion. |

## Stop-The-Line Conditions

- invented citation;
- source unavailable and claim is critical;
- runtime/policy change requested by research output only;
- memory write from Tier 4 or Tier 5 source;
- implementation touches Kernel/Policy/Receipt/Ledger without AP;
- validation cannot be run and risk is medium/high.

## Recovery

1. Preserve raw evidence.
2. Mark invalid packet or proposal.
3. Identify contaminated docs/memory/code.
4. Roll back or supersede.
5. Add guardrail/test.
6. Record Self-Improvement finding.

