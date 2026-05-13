---
id: atlas-ai-qualitative-levels-implementation
type: engineering_knowledge
title: Atlas AI Qualitative Levels Implementation Queue
status: active
category: roadmap
priority: 86
summary: Focused implementation queue for Atlas qualitative levels QL-0 through QL-7.
tags:
  - atlas-ai
  - qualitative-levels
  - roadmap
capabilities:
  - qualitative_levels_roadmap
decisions:
  - Qualitative level implementation remains proposal/gated until Evidence Ledger and Rivals prove benefit.
maintenance:
  - Update when a QL item changes implementation status.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-qualitative-levels-roadmap.md
---

# Atlas AI Qualitative Levels Implementation Queue

| Item | Status | Notes |
|---|---|---|
| QL-0 Documentation promotion | Done | Source roadmap preserved, compact KB spec active. |
| QL-1 Maturity read model | Implemented | `atlas ai qualitative-levels`, API read model. |
| QL-2 Rivals Strategy | Implemented | `atlas:ai:rivals-strategy report --json` exposes P4 promotion readiness, next review command, no-synthetic-score rule and blocked actions until a real scored revisit preserves agency. |
| QL-3 Strategic Decision scaffold | Implemented scaffold | No automatic external execution. |
| QL-4 Co-Strategist plan-only | Started | Counterargument, values alignment, cool-down, agency gate. |
| QL-5 Curator mutation classes | Future | Class-1/2/3 mutation governance. |
| QL-6 Presence and eclipse | Started | Mobile Inbox/Push has explicit proactive opt-out, manual eclipse for non-critical push, quiet-hours support and hash-only delivery receipts; broader environment presence remains future. |
| QL-7 Voice Realtime Surface | Scaffold active | Mobile-first voice, LiveKit Agents SDK, Swift native edge later. |

## Rule

No P4+ claim without Evidence Ledger, Rivals evidence and human agency gates.
`p4_promotion_readiness.status=ready` is required before any P4 claim; pending
scheduled reviews are evidence of discipline, not proof of outcome.
