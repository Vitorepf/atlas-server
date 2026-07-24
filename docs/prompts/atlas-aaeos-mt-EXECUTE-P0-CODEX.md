# Codex / any-IA handoff — AAEOS Elite Deepening (vFINAL-COOKBOOK + GO-fix)

> **Lei única:** `docs/superpowers/plans/2026-07-23-aaeos-elite-deepening-MASTER.md`  
> **Modo:** a IA **só segue o plano**. Zero auto-escopo. Zero auto-amend do MASTER.  
> **Gate:** frase EXECUTE literal do operador.  
> **Promoção de fatia:** PHASE `status=GREEN` + checklist + testes — **não** “humano revisou diff”.

---

## BLOCO GENÉRICO

```
You are implementing AAEOS Elite Deepening on local main only.

RULE #0 — follow MASTER only:
  docs/superpowers/plans/2026-07-23-aaeos-elite-deepening-MASTER.md
Jump to the SLICE named by the operator EXECUTE phrase.
Edit ONLY that slice's closed paths.

Preflight (mandatory):
  git branch --show-current  # must be main
  git status --short
  Classify every dirty path as FOREIGN_WIP / IN_SLICE / OUT_OF_SCOPE.
  Never stash, reset, or touch FOREIGN_WIP.
  If FOREIGN_WIP overlaps a path you must change: STOP and report.
  Optional multi-engine: blackboard claim targets before edit.

Unlisted path needed:
  STOP. Report exact path + reason.
  Do NOT edit it. Do NOT amend MASTER. Do NOT self-authorize.
  Wait for human MASTER amendment + new EXECUTE phrase.

Two-commit ritual (binding — kills receipt self-reference):
  COMMIT 1 = production + tests only → IMPLEMENTATION_COMMIT
  Write PHASE-*.json with:
    base_commit, implementation_commit=IMPLEMENTATION_COMMIT, evidence_commit=null
    allowed_paths, touched_paths, deleted_paths
    tests_red_then_green (red_exit, green_exit, failure_reason)
    baseline_failures, new_failures
  Update LEDGER + SCOREBOARD
  COMMIT 2 = PHASE + LEDGER + SCOREBOARD only
  Do NOT put implementation_commit equal to the evidence commit.
  No receipt.md. No extra evidence files.

Never: AaeosRunApplication, second ledger, Mission/WorkGraph/SovereigntyPort, git add -A.

OPERATOR GATE (one slice only):
EXECUTE P0
```

---

## BLOCO P0 (copie inteiro)

```
EXECUTE P0

Open docs/superpowers/plans/2026-07-23-aaeos-elite-deepening-MASTER.md
Implement ONLY "# SLICE P0 — truth before capability".

Follow in order:
  P0.0 preflight (WIP classify + baseline)
  P0.1 RED tests first — every NEW test must fail for the right reason on HEAD
  P0.2 production recipes (literal before→after in MASTER)
  P0.3 GREEN suite (GREEN_EXIT=0; record all exit codes)
  P0.4 COMMIT 1 implementation only
       feat(core): AAEOS-MT P0 honesty port admission and measured projection
  P0.5 write PHASE-P0.json (schema §0.3) + LEDGER + SCOREBOARD → COMMIT 2
       docs(evidence): AAEOS-MT P0 phase receipt
  STOP — do not start P1a

P0 hard proofs required:
  - both dry CLIs never call OutcomeRecorder::record
    (AaeosDryOutcomeRecorderAbsenceTest)
  - runtime_write_performed false on dry
  - invalid_mode → repair_required
  - no static 9.2 / vanity GOD_SOTA
  - AtlasEvidenceLedger.php only if read-only measurement touch; else leave untouched
  - no AaeosRunApplication

Forbidden in P0:
  R33 brain args, R34/R35, provider/effect, Decision v3, ledger chain harden,
  self-amend MASTER, receipt.md, single commit that mixes impl+self-ref head

Promotion: PHASE-P0 GREEN + checklist. Human diff review is optional audit only.
```

---

## Operador

1. Branch `main`. Aceite FOREIGN_WIP alheio (não mande a IA limpar).  
2. Cole **um** bloco EXECUTE.  
3. Opcional: olhe o diff (auditoria) — **não** é gate.  
4. Próxima fatia só com nova frase EXECUTE após PHASE GREEN.
