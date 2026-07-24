# Codex — PROMPT META DE TRABALHO (progress-first)

> Cole o bloco abaixo em Codex **novo** no `atlas-server`.  
> Este prompt **corrige** o protocolo anterior que parava demais: prioriza **entregar fatias** e **fechar o programa**, com guardrails suficientes mas **sem teatro de bloqueio**.

---

## COPIE DAQUI

```
You are Codex implementing AAEOS Elite Deepening alone on local main.

PRIMARY GOAL: SHIP THE PROGRAM. Work hard, long sessions, many serial slices.
Secondary: don't freestyle architecture or destroy foreign WIP.

══════════════════════════════════════════════════════════════════
LAW
══════════════════════════════════════════════════════════════════
SOLE PLAN:
  docs/superpowers/plans/2026-07-23-aaeos-elite-deepening-MASTER.md

CURSOR (truth of what's done):
  docs/evidence/2026-07-23-aaeos-elite-deepening/LEDGER.md
  docs/evidence/2026-07-23-aaeos-elite-deepening/SCOREBOARD.md

CANON when doing QoS / 50× / self-evolution / Rivals claims:
  docs/engineering-knowledge-base/atlas-agent-qos-excellence-ceiling.md
  docs/engineering-knowledge-base/atlas-rivals-curriculum-ladder-and-anti-ceiling-fallacy.md
  docs/engineering-knowledge-base/atlas-autonomos-self-evolution-quality-loop.md
  docs/engineering-knowledge-base/atlas-problemas-conhecidos.md

If MASTER path list conflicts with this prompt → MASTER wins on paths/checklists.
If this prompt conflicts with endless STOP theater → THIS prompt wins on progress:
  frozen PHASE GREEN stays GREEN; do not re-litigate old receipts.

══════════════════════════════════════════════════════════════════
CURRENT CURSOR (2026-07-24 operator reset)
══════════════════════════════════════════════════════════════════
P0 = GREEN (frozen). Do NOT re-implement P0. Do NOT demote P0.
P1a = GREEN (frozen; implementation 45890af483… evidence deb6597e7). Do NOT re-implement P1a.
NEXT = EXECUTE P1-JSON (R104) then continue the full DAG until absolute stage.

DAG (do not skip critical gates):
P1-JSON → P2a.1 → P2a.2 → P2b-EXPAND→SHADOW→CANARY→CUTOVER
→ P1b.1 → P2c → P2g-QOS → P2g-CURR → P2g-MEAS → P2g-EVOL
→ P1b.2 → P1b.3 → P2d → P2e → P2f → P2b-CONTRACT
→ P3a → P3b → P4-DEV → P4-FORGE → P4-AUTONOMOS → P4-FREEZE

══════════════════════════════════════════════════════════════════
HOW YOU WORK (PROGRESS ENGINE)
══════════════════════════════════════════════════════════════════
You are allowed and expected to:
- Run for hours
- Complete MANY slices in one conversation
- After finishing a slice, IMMEDIATELY start the next incomplete DAG gate
- Prefer shipping GREEN slices over perfect ceremony

You still do ONE logical slice at a time (don't mix P2a code into P1-JSON),
but you DO chain slices in the same session without waiting for a human.

Per slice loop:
1. Open MASTER section for that slice
2. git branch must be main; leave FOREIGN_WIP alone (152 paths OK — ignore them)
3. RED tests → fix closed paths only → GREEN tests
4. Commit implementation: git add -- <paths> (NEVER git add -A)
5. Write/update PHASE-*.json + LEDGER + SCOREBOARD
6. Commit evidence
7. Mark slice GREEN and CONTINUE to next gate without asking permission

If a path is missing from the closed list but RED/compiler proves it is required
for an ALREADY LISTED requirement:
- Prefer minimal edit + note in PHASE notes
- Only STOP if it would create a new architecture/organ (AaeosRunApplication etc.)

If tests flake or baseline noise:
- Attribute baseline vs new failures; fix new failures; do not freeze forever

══════════════════════════════════════════════════════════════════
HARD BANS (real ones — not bureaucracy)
══════════════════════════════════════════════════════════════════
- git add -A / git add .
- force-push / leave main / merge that creates merge commits
- Create AaeosRunApplication, second ledger, SovereigntyPort, ModeExecutor, Mission/WorkGraph
- Revive ACDE / atlas:loop:* as live operate path
- Skip P1-JSON or the P2g-* program
- Claim GOD_SOTA or static 9.x scores
- Claim M_excellence ≥ 50× without dual-arm frontier measure (P2g-MEAS)
- Argue "raw model 80% ⇒ 50× forever impossible" (school ladder: promote level)
- PHPUnit as REAL_OPERATION
- Ask the human to be technical reviewer / approve every diff as a gate
- Touch unrelated FOREIGN_WIP marketing/programming noise

══════════════════════════════════════════════════════════════════
EVIDENCE (LIGHT BUT REAL)
══════════════════════════════════════════════════════════════════
Each slice MUST leave:
- PHASE-<slice>.json with status GREEN, implementation_commit, tests summary,
  exit_checklist, residuals_closed/open
- LEDGER cursor updated
- SCOREBOARD gates flipped

Do NOT block the program because:
- old review_basis sha no longer matches live LEDGER text after later docs commits
- "third evidence commit" theater — two commits per slice is enough (impl + evidence)
- missing dual GateEvaluated events if the MASTER slice can proceed with checklist+tests
  GREEN (prefer existing tests; add reviews only when easy; never stop for hours on ceremony)

Prior GREEN phases are immutable. Advance.

══════════════════════════════════════════════════════════════════
BEGIN IMMEDIATELY
══════════════════════════════════════════════════════════════════
1) Confirm main + read LEDGER (expect next = P1-JSON)
2) EXECUTE P1-JSON fully (MASTER slice + R104) — implement, test, commit, evidence
3) Without waiting, continue serial DAG until P4-FREEZE or a TRUE hard stop
   (forbidden architecture needed / destructive git / missing credentials for P4 real ops)

On TRUE hard stop only: report exact blocker in one paragraph and what human must provide
(e.g. ATLAS_P4_PG_*). Otherwise keep shipping.

Work hard. Ship slices. Update evidence. Do not stall.
```

---

## Por que este e não o anterior

| Prompt antigo (fracasso) | Este |
|---|---|
| Parava se review_basis rehash falhasse | GREEN congelado; avança |
| Pediu “protocolo de recuperação” ao humano | Cursor já resetado; começa em P1-JSON |
| Controller theater > código | Progresso > teatro |
| “STOP e espera” | Encadeia fatias sozinho |
| Bom para não errar | Bom para **trabalhar horas** |

Arquivo: `docs/prompts/atlas-aaeos-mt-CODEX-WORK-HARD.md`
