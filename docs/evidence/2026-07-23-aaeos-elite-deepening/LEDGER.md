# AAEOS Elite Deepening — LEDGER

**Master (CANONICAL sole law):** `docs/superpowers/plans/2026-07-23-aaeos-elite-deepening-MASTER.md`  
**Edition:** **vFINAL-COOKBOOK · document-preflight contract**
**State:** PLAN_ONLY · P0 stays inactive until the committed contract has two independently verifiable approvals and controller gate verification; no implementation proof is recorded
**P0–P4:** NOT_STARTED  
**Archive v16/C6:** non-normative historical only (not a second master)

## How any IA uses this

1. The operator standing-authorizes the catalogued EXECUTE phrases; the controller activates exactly one serial phrase only after its predecessor is GREEN.
2. Open MASTER → that SLICE only.  
3. Preflight WIP → RED → fix → GREEN → COMMIT 1 (impl) → canonical review basis (PHASE excludes its four post-review fields; LEDGER/SCOREBOARD hash exact UTF-8 LF-normalized bytes with zero exclusions) + two existing-ledger GateEvaluated review events → COMMIT 2 (evidence) → STOP.
4. Unlisted path → STOP before edit. Controller preserves a redacted mechanical-evidence hash, binds the exact MASTER amendment diff/path/requirement to a versioned amendment-basis SHA, and requires two fresh-verified existing-ledger `GateEvaluated` approvals under the §0.3.1 contract before any new-path edit. SoD: controller != implementer != each reviewer; opaque refs never qualify.
5. STOP closes the slice, produces/reviews its receipt, and returns control to the Goal controller. A draft cannot activate the next slice. Only after COMMIT 2 does the controller read the exact PHASE from that committed evidence tree, validate its non-circular basis and existing-ledger review events, and re-derive the serial activation; operator diff review is optional audit only.

## Phase cursor

| Slice | Status | Gate phrase | implementation_commit |
|---|---|---|---|
| P0 | NOT_STARTED | `EXECUTE P0` | — |
| P1a | NOT_STARTED | `EXECUTE P1a` | — |
| P2a.1+ | NOT_STARTED | see MASTER DAG | — |
| P1b.* | NOT_STARTED | after P2b-CUTOVER | — |
| P3* / P4* | NOT_STARTED | see MASTER | — |

Evidence-commit SHA is reported by the controller after COMMIT 2, outside the committed evidence artifacts; this ledger must not try to contain the SHA of its own commit.

## Open on disk (P0)

- `runtime_write_performed` hardcoded true (`AaeosCycleRuntime.php:93`)
- certify injects 9.2 / 9.2 / 9.0
- authoritative `human_in_engineering_loop`
- invalid_mode → `halt_sovereign` (→ `repair_required`)
- run/cycle dry still call OutcomeRecorder
- R33–R35 remain P1a (do not fix in P0)

## Document-preflight contract

The contract specifies non-circular/provider-safe PHASE fields, existing-ledger GateEvaluated review events before COMMIT 2, and committed-tree-only activation. It asserts no P0 outcome, activation, or review approval.
