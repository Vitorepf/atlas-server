---
title: AP-791 Autonomous Loop Inbox / Merge / Receipt Integrity Contract
status: active
implementation_state: implemented
requires_evidence: true
owner: software_company_stewardship
companion_of: AP-786-autonomous-evolution-session-contract
glossary: docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
---

> ⚠️ **DEFINIÇÃO CANÔNICA DO LOOP — leia primeiro: `docs/loop-canonical-definition.md` + memórias `loop-*`.** Este doc descreve IMPLEMENTAÇÃO / ESTADO / HISTÓRICO; parte do framing aqui (refactor / ciclomática / landing-rate / best-of-N / proxy) é o **ALVO ERRADO**. O Loop = evolução autônoma **exponencial** de features REAIS do Atlas (entender escopo → projeção frontier + crítica cross-model → multi-agente → teste → wiring), **nunca faxina / proxy / one-shot**. Objetivo final: ser o ÚNICO que evolui o Atlas 24/7 sozinho.


# AP-791 Autonomous Loop Inbox / Merge / Receipt Integrity Contract

## Authority

For a trustworthy 24h loop the operator must be able to review every cycle
*after the fact*. AP-791 guarantees that each AP-786 cycle is auditable and that
nothing merges without operator-visible evidence. It does not replace AP-786,
AP-769/AP-774 merge governance, or AP-787/AP-788 Forge authority — it sits on the
existing cycle and normalizes/guards it.

The prior failure mode was fake/incomplete loops and many small repeated commits.
AP-791 makes that visible: a cycle that did not prove its evidence cannot merge,
a planned Forge dispatch is never reported as completed, and repeated
finding/commit titles are flagged. See the canonical glossary for Atlas Dev /
Forge naming (`docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md`).

- Service: `app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AutonomousLoopReceiptIntegrityService.php`
- Integrated in: `AutonomousEvolutionSessionService` (pre-merge gate + per-cycle receipt).
- Schema: `atlas.software_company_stewardship.autonomous_loop_cycle_receipt.v1`

## Required cycle order (AP-786, unchanged)

1. create isolated branch/worktree (AP-756);
2. execute Atlas Dev/Forge owner-runtime (AP-747 → AP-750);
3. **emit the pre-merge inbox** (what was found, done, why, why it matters) before any merge;
4. validate;
5. ff-only merge if AP-769/AP-774 allow;
6. emit the post-cycle inbox / result receipt;
7. pull main and continue.

## The loop receipt

Every cycle — for **every** lifecycle state — carries a normalized receipt:

| Field | Meaning |
|---|---|
| `cycle_id` / `session_id` | Identity, for replay. |
| `lifecycle_state` | `merged` \| `planned` \| `blocked` \| `failed` \| `skipped`. `planned` (incl. Forge runtime-dispatch) is never `completed`. |
| `selected_finding` | finding_id + title. |
| `owner` | atlas_dev \| forge. |
| `sandbox_id` / `branch_ref` / `worktree_path` | Isolation proof. |
| `changed_files` | Files the cycle changed. |
| `validation` | status (passed/failed/not_run) + command_count. |
| `inbox_pre_merge` | Operator-visible pre-merge inbox / AP-750 evidence. |
| `merge` + `merge_hash` | AP-769/AP-774 decision and the merge commit hash. |
| `inbox_post_merge` | Post-merge result receipt / post-cycle receipt. |
| `evidence_refs` | result_bridge / owner execution / run / consumption / release ids. |
| `replay_command` | Deterministic command to replay/re-certify this cycle. |
| `failure_reasons` / `blockers` | Why it failed/blocked. |
| `next_action` | Exactly what the operator should do next. |
| `warnings` | e.g. `duplicate_commit_or_cycle_title`. |
| `integrity` | `ok` \| `incomplete` with `missing[]`. |

## Hard rules

- **Pre-merge inbox gate.** A cycle may not merge without an operator-visible
  pre-merge inbox (or AP-750 result-bridge evidence). If none was emitted, the
  cycle is blocked with `pre_merge_inbox_required` before any merge is attempted.
- **Planned is not completed.** A `cycle_completed_waiting_review_or_merge`
  result (including a Forge `runtime-dispatch` plan) maps to `lifecycle_state=planned`,
  `completed=false`. It never looks done.
- **Every state gets a receipt.** completed/merged, planned, blocked, failed and
  skipped all carry a receipt with `replay_command` and `next_action`.
- **Failed vs blocked.** A failure blocker (validation_failed, commit_failed,
  ap759_owner_command_failed, …) marks `failed`; governance blockers mark `blocked`.
- **Duplicate titles warn.** A repeated finding/commit title across cycles raises
  the `duplicate_commit_or_cycle_title` warning so small repeated commits surface.
- **Read-only judge.** The service never runs git, providers, merge, scheduler or
  inbox; it normalizes and gates only.

## Acceptance

- A completed owner-flow cycle with no pre-merge inbox blocks the merge
  (`pre_merge_inbox_required`); the merge governor is never reached.
- A blocked cycle produces a receipt with the blocker and a `next_action`.
- A merged cycle produces a post-merge receipt with a `merge_hash`.
- A planned Forge cycle is `lifecycle_state=planned`, never completed.
- Every receipt carries a `replay_command`.
- A duplicated commit/cycle title is reported as a warning (never a silent merge).
