# AAEOS Elite Deepening — LEDGER

**Master:** `docs/superpowers/plans/2026-07-23-aaeos-elite-deepening-MASTER.md`  
**State:** P0…P3b GREEN · P2a.1 GREEN · **P4 durable PG provisioned + proven** · mutative REAL_OPERATION eng journey still open  
**Branch:** main only

## Cursor

| Slice | Status | Next |
|---|---|---|
| P0…P3b | GREEN | prior |
| P2a.1 | GREEN | R-P2A1-PG-LIVE closed |
| P4 durable PG | **PROVEN** | docker `atlas-p4-pg:55449` + least-privilege roles |
| P4 mutative REAL_OPERATION | OPEN | full write journey + COVERED model provider eng run |
| Absolute DONE | **NOT** | until mutative real_operation_completed |

## Notes
- Local provisioner: `scripts/aaeos-p4-pg-up.sh` → exports ATLAS_P4_PG_PRODUCER_URL / VERIFIER_URL
- Live contract: `AaeosP4PostgresDurableRolesTest` GREEN
- Real entry: `php artisan atlas:cli:dev --plan-only` completed (plan_only ≠ REAL_OPERATION)
- Do not invent mutative REAL_OPERATION from --version or plan-only alone

## Rule for implementers
Source `source <(./scripts/aaeos-p4-pg-up.sh --export)` then run live P4 producers. Foreign WIP untouched.
