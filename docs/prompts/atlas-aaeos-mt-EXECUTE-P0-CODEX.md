# Codex / any-IA handoff — AAEOS Elite Deepening (vFINAL-COOKBOOK)

> **Lei:** `docs/superpowers/plans/2026-07-23-aaeos-elite-deepening-MASTER.md`  
> **Modo:** a IA **só segue o plano**. Não inventa arquitetura. Não reordena o DAG.  
> **Gate:** frase EXECUTE literal do operador.

---

## BLOCO GENÉRICO (qualquer slice)

```
You are implementing AAEOS Elite Deepening on local main only.

RULE #0: Open and FOLLOW exactly:
  docs/superpowers/plans/2026-07-23-aaeos-elite-deepening-MASTER.md
Jump to the SLICE named by the operator EXECUTE phrase below.
Edit ONLY that slice's closed production + test paths.
If a RED needs an unlisted path: STOP and report — do not freestyle.
No AaeosRunApplication. No second ledger. No Mission/WorkGraph/SovereigntyPort.
Scoped git add -- paths only. Branch must be main.
After GREEN: write the exact PHASE-*.json, update LEDGER + SCOREBOARD, commit, STOP.

OPERATOR GATE (only one slice):
EXECUTE P0
```

Substitua a última linha por outra frase do MASTER quando for a fatia seguinte, ex.:
`EXECUTE P1a` · `EXECUTE P2a.1` · `EXECUTE P2b-CUTOVER` · `EXECUTE P4-DEV` …

---

## BLOCO P0 (primeiro — copie inteiro)

```
EXECUTE P0

Open docs/superpowers/plans/2026-07-23-aaeos-elite-deepening-MASTER.md
Implement ONLY "# SLICE P0 — truth before capability".

Follow P0 ordered steps exactly:
  P0.0 preflight
  P0.1 RED tests first (must fail on HEAD)
  P0.2 production recipes (literal before→after in the MASTER)
  P0.3 GREEN suite
  exit checklist all true
  write PHASE-P0.json (schema in MASTER §0.2)
  update LEDGER + SCOREBOARD
  scoped commit:
    feat(core): AAEOS-MT P0 honesty port admission and measured projection
  STOP — do not start P1a

Disk anchors the plan names (must still be broken before your fix):
  AaeosCycleRuntime.php:93 runtime_write_performed true
  AaeosAdmissionPolicy.php invalid_mode → HALT_SOVEREIGN
  AtlasAaeosCertifyCommand.php 9.2 injects + human_in_loop gate
  Run/Cycle OutcomeRecorder on dry / exit taxonomy

Forbidden in P0:
  AaeosRunApplication, R33 brain args, R34/R35, provider/effect, Decision v3
```

---

## Operador

1. `main` limpa o suficiente para commit escopado (não stash de obra alheia).  
2. Cole o bloco com **uma** frase EXECUTE.  
3. Revise PHASE + diff.  
4. Só então a próxima frase EXECUTE.
