# AAEOS Elite Deepening — LEDGER

**Master (CANONICAL):** `docs/superpowers/plans/2026-07-23-aaeos-elite-deepening-MASTER.md`  
**Edition:** **vFINAL-COOKBOOK** — full implementer script (every slice: paths, steps, recipes, tests, commits, STOP)  
**State:** PLAN_ONLY  
**P0–P4:** NOT_STARTED  
**Next:** operator literal **`EXECUTE P0`** → implementer follows MASTER §SLICE P0 only → STOP

## How to use (any IA)

1. Open MASTER.  
2. Jump to the SLICE named by the EXECUTE phrase.  
3. Edit only that slice’s closed paths.  
4. RED → fix → GREEN → PHASE-*.json → update this LEDGER + SCOREBOARD → scoped commit.  
5. STOP. Do not start the next slice without a new EXECUTE phrase.

## Phase cursor

| Slice | Status | Gate phrase |
|---|---|---|
| P0 | NOT_STARTED | `EXECUTE P0` |
| P1a | NOT_STARTED | `EXECUTE P1a` |
| P2a.1 | NOT_STARTED | `EXECUTE P2a.1` |
| P2a.2 | NOT_STARTED | `EXECUTE P2a.2` |
| P2b-EXPAND…CONTRACT | NOT_STARTED | `EXECUTE P2b-*` |
| P1b.1–.3 | NOT_STARTED | after P2b-CUTOVER; `EXECUTE P1b.*` |
| P2c–P2f | NOT_STARTED | DAG order in MASTER |
| P3a/P3b | NOT_STARTED | `EXECUTE P3a` then `EXECUTE P3b` |
| P4-DEV/FORGE/AUTONOMOS/FREEZE | NOT_STARTED | `EXECUTE P4-*` |

## Open on disk (P0 targets)

- `runtime_write_performed` hardcoded true (`AaeosCycleRuntime.php:93`)
- certify injects 9.2 / 9.2 / 9.0
- authoritative `human_in_engineering_loop`
- invalid_mode → `halt_sovereign` (must become `repair_required`)
- run/cycle exit/outcome parity
- R33 brain `--scope` (P1a — do not fix in P0)
- R34/R35 seed/exit honesty (P1a)

## Archive

`docs/superpowers/plans/archive/aaeos-elite-deepening-2026-07-23/` — SUPERSEDED bodies only.
