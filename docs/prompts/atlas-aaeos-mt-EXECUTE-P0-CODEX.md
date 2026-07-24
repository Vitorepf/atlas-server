# Codex handoff — EXECUTE P0 (AAEOS Elite Deepening)

> Paste the block below into a **fresh Codex session** on workspace `atlas-server`.  
> Operator must include the literal gate phrase **`EXECUTE P0`**.  
> Plan edition: **vFINAL-EXEC**.

---

## BLOCO PARA COLAR

```
EXECUTE P0

You are implementing AAEOS Elite Deepening on local main only.

══════════════════════════════════════════════════════════════════
CANONICAL LAW (read fully before any edit)
══════════════════════════════════════════════════════════════════
docs/superpowers/plans/2026-07-23-aaeos-elite-deepening-MASTER.md
  → implement ONLY section "# P0 — truth before capability"
  → closed production path list + closed test path list are AUTHORIZATION, not examples
  → if RED needs an unlisted path: STOP and amend MASTER first (do not freestyle)

Satellites (update at end of slice):
  docs/evidence/2026-07-23-aaeos-elite-deepening/LEDGER.md
  docs/evidence/2026-07-23-aaeos-elite-deepening/SCOREBOARD.md
  docs/evidence/2026-07-23-aaeos-elite-deepening/PHASE-P0.json   (create; schema in MASTER handoff)

Archive (detail only — NOT law):
  docs/superpowers/plans/archive/aaeos-elite-deepening-2026-07-23/MASTER-v16-pre-final.md
  → residual R52–R103 full text if a MASTER row is truncated
  → IGNORE any AaeosRunApplication architecture in old §3

Do NOT implement from:
  …-MASTER-FINAL.md (alias)
  …-MASTER-CLAUDE.md (archived)
  cycle-workflow.mjs / improve-agenda prompts

══════════════════════════════════════════════════════════════════
BRANCH / GIT (pétreo)
══════════════════════════════════════════════════════════════════
- git branch --show-current MUST be main
- scoped commits only: git add -- <exact paths>
- NEVER git add -A, never work branch, never merge/pull that creates merge, never stash to hide WIP
- Prefer one commit (or two if measured-reader must split):
  feat(core): AAEOS-MT P0 honesty port admission and measured projection

══════════════════════════════════════════════════════════════════
P0 OBJECTIVE (honesty — not Brain live, not effect authority)
══════════════════════════════════════════════════════════════════
Remove false success; unify daily port on AaeosCycleRuntime; honest projection.

MUST fix:
1. AaeosAdmissionVerdict + Policy: invalid/unknown mode → repair_required (NOT halt_sovereign)
2. AaeosCycleRuntime: derive runtime_write_performed; dry always false; stop authoritative human_in_engineering_loop in receipt assembly
3. Run + Cycle commands: both call AaeosCycleRuntime only; shared flag normalize; dry must not write OutcomeRecorder; dispatch_failed non-zero both ports
4. ScorecardProjector + CertifyCommand: remove static 9.2/9.2/9.0 injects; no human_in_loop===false certify gate; zero-sample → unknown/null
5. IntentCompiler: regex = suspicion only (no authority)
6. Delete AaeosOperateScorecardProjector after rg proves zero production consumers
7. Cockpit/Router/daily-map: cannot teach GOD_SOTA without measured evidence
8. Elite executors doc pointer already targets vFINAL-EXEC — keep consistent

MUST NOT do in P0:
- create AaeosRunApplication (FORBIDDEN — shared use-case is AaeosCycleRuntime)
- fix R33 brainNextArgs / R34 / R35 (those are P1a)
- provider calls, mutation, sandbox, Decision v3, Ledger chain harden (later phases)
- claim GOD_SOTA or REAL_OPERATION

══════════════════════════════════════════════════════════════════
RITUAL
══════════════════════════════════════════════════════════════════
1. Preflight:
   git branch --show-current
   BASE=$(git rev-parse HEAD)
   /opt/homebrew/bin/php artisan atlas:aaeos:certify --json | tee /tmp/aaeos-cert-before.json
   /opt/homebrew/bin/php artisan atlas:aaeos:scorecard --json | tee /tmp/aaeos-score-before.json

2. Write RED tests first (paths in MASTER §P0 test list). Confirm they fail on HEAD.

3. Production fixes (only closed production paths).

4. GREEN tests. Do not "fix" by weakening asserts that encode honesty.

5. Write PHASE-P0.json using the closed schema in MASTER §Handoff.
   Update LEDGER (P0 status) + SCOREBOARD gates that P0 closes.

6. Scoped commit(s) on main.

7. STOP. Reply with:
   - head commit
   - PHASE-P0 status
   - residual still open (R33+)
   - do not start P1a until operator says EXECUTE P1a

══════════════════════════════════════════════════════════════════
DISK TRUTH REVALIDATE (must still fail before your fix; re-check after)
══════════════════════════════════════════════════════════════════
rg -n 'human_in_engineering_loop|runtime_write_performed' app/Services/Ai/Aaeos app/Console/Commands/AtlasAaeosCertifyCommand.php
rg -n 'HALT_SOVEREIGN|invalid_mode' app/Services/Ai/Aaeos/Control/AaeosAdmissionPolicy.php
# rwp hardcoded true @ CycleRuntime ~:93 is the bug P0 fixes
```

---

## Operador

1. Abra Codex no `atlas-server` com branch `main`.  
2. Cole o bloco (inclui **`EXECUTE P0`**).  
3. Depois do STOP do Codex: revise `PHASE-P0.json` + diff; só então diga **`EXECUTE P1a`**.  
