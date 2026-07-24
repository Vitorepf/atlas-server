# CODEX — AAEOS P4 LIVE UPGRADE (after residual-honest GREEN)

## Cursor
Local `main` only. Evidence already has PHASE-P4-* **GREEN residual-honest**.
Your job: upgrade journeys to `real_operation_completed` when env allows — **never** fabricate.

## Required env
```bash
export ATLAS_P4_PG_PRODUCER_URL='…atlas_p4_…'   # producer role
export ATLAS_P4_PG_VERIFIER_URL='…atlas_p4_…'    # SELECT-only verifier, DISTINCT
```

## Do
1. `git branch --show-current` = main; no `git add -A`.
2. Run real producers:
   - `bin/atlas dev "<intent>"` → SeniorLoop / EngineeringOutcome
   - `bin/atlas forge "<intent>"` → completeObra
   - RuntimeDaemon direct (`aaeos_initiated=false`) brain→seed→claim→land OR honest blocked
3. Certify read-only:
   `php artisan atlas:aaeos:certify --profile=p4 --journey=<ref> --cutoff=<ledger-head> --evidence-dir=docs/evidence/2026-07-23-aaeos-elite-deepening --json`
4. Update only journey artifacts + PHASE residual fields when status upgrades; two-commit ritual.
5. Leave R104-TRANSPORT residual-open if still open.

## Hard bans
PHPUnit-only REAL_OPERATION; same producer/verifier URL; secrets in receipts; ACDE; new organs; mass-delete PipelineRunExecutor.
