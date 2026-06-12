# T002 — Judge/Fable adversarial validation

## Decision

`approved_for_scout_then_worker`, not approved for immediate Worker yet.

The target and order are valid:

- Ledger current first `em andamento`/`pendente`: **O-1 — Certification Sweep da espinha de engenharia + Marco Zero**.
- O-2 remains pending and must not start until O-1 closes with DoD/capture/audit.
- T001's decomposition is directionally correct and covers the four load-bearing floors required by O-1.

## Refutation results

### Order skip

No order skip detected. O-1 is correctly selected.

### DoD weakness

DoD is acceptable only if treated as a checklist with concrete evidence per floor. A generic "audit done" receipt is insufficient.

Minimum evidence per floor:

1. path/symbol map;
2. current tests/harnesses;
3. adversarial findings;
4. correction with frozen regression or explicit `[OPERADOR]` triage;
5. independent re-proof output.

### Fable misuse risk

High. O-1 can devolve into broad spelunking. Fable should own PM/Judge decisions and hard architecture/risk calls; Scout/Worker should do mechanical mapping and edits.

### Creation measuring creation

High. O-1 includes gates/measurement/capture. Any correction to measuring/gate/imune code needs reinforced adversarial verification before acceptance.

### `[OPERADOR]` risk

Known pending O-1 operator decision: destination of risks triaged as "accepted". The run must not autonomously accept those; it should record exact risks/evidence and ask/queue decision only when needed.

### Proof weakness

Marco Zero has a ledger line but still needs integrity proof against the actual artifact and/or Evidence Ledger source. This should happen before relying on it for later O-2/O-3 comparisons.

## Required next task

Activate **T003 Scout** before any Worker implementation.

T003 must perform O1-S0/O1-S1 read-only mapping:

1. Run bootstrap:

```bash
/opt/homebrew/bin/php artisan atlas:ai:session-bootstrap --task="O-1 Certification Sweep da espinha de engenharia + Marco Zero" --json
```

2. Run AOBG/context:

```bash
bin/atlas open-brain context "O-1 Certification Sweep da espinha de engenharia + Marco Zero" --json
```

3. Verify Marco Zero artifact exists and extract only provider-safe summary:

```bash
test -f storage/app/atlas/evidence/marco-zero-fable-2026-06-11.json
```

4. Map candidate files/tests for:
   - Dev pipe real: `AiProviderManager`, conductor, `WorkspaceMutatingProviders`.
   - Forge gates.
   - Loop stack/drivers.
   - Compounding flywheel + capture quality gate.

## Worker not approved yet

T004 remains queued with empty `allowed_files` because file scopes must be derived from T003 evidence. A Worker may be activated only after Scout evidence lets PM/Judge set:

- exact objective;
- allowed_files;
- verify commands;
- stop_if conditions.

## Initial stop conditions for the next stage

Stop before Worker if:

- bootstrap/AOBG fails without a trustworthy fallback;
- Marco Zero artifact is missing or internally inconsistent;
- the next slice touches gate/measurement/imune code without reinforced Judge plan;
- any required step would alargar autonomia;
- accepted-risk disposition requires `[OPERADOR]`.
