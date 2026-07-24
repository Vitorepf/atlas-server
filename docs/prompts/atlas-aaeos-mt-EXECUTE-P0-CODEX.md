# Codex / any-IA handoff — AAEOS Elite Deepening (vFINAL-COOKBOOK)

> **Lei única:** `docs/superpowers/plans/2026-07-23-aaeos-elite-deepening-MASTER.md`  
> **Modo:** a IA **só segue o plano**. Zero auto-escopo. Zero auto-amend do MASTER.  
> **Gate:** a frase EXECUTE é standing-authorized pelo operador e só é ativada pelo controlador quando o predecessor serial está GREEN.
> **Promoção de fatia:** predecessor serial GREEN + PHASE `status=GREEN` + checklist + testes + dois commits + reviews independentes do draft — **não** “humano revisou diff”.
> **Contrato:** P0 permanece inativo até o contrato document-preflight commitado ter duas aprovações independentemente verificáveis e o controlador verificar todos os gates; este arquivo não afirma essas aprovações nem ativação.

---

## BLOCO GENÉRICO

```
You are implementing AAEOS Elite Deepening on local main only.

RULE #0 — follow MASTER only:
  docs/superpowers/plans/2026-07-23-aaeos-elite-deepening-MASTER.md
Controller activates exactly one standing-authorized serial slice; jump only to that activated slice.
Edit ONLY that slice's closed paths.

Preflight (mandatory):
  git branch --show-current  # must be main
  git status --short
  Classify every dirty path as FOREIGN_WIP / IN_SLICE / OUT_OF_SCOPE.
  Never stash, reset, or touch FOREIGN_WIP.
  If FOREIGN_WIP overlaps a path you must change: STOP and report.
  Multi-engine: claim every target before edit. Exactly one active writer is mandatory;
  claim conflict = STOP. Reviewers are read-only and never writer claimants.

Unlisted path needed:
  STOP. Do not edit it first. Report exact path + requirement + mechanical necessity evidence.
  Controller-only: create atlas.aaeos.mt.master_amendment_review_basis.v1 SHA-256 over exact
  UTF-8 LF-normalized MASTER amendment unified diff + repo-relative path + existing requirement
  ID + mechanical-evidence hash. Two independent read-only AtlasEvidenceLedger::record
  GateEvaluated events must bind that basis with authenticated-runtime principal hash, role,
  verdict, unresolved Critical/Important count, timestamp, and remediation/re-review parent ref.
  Fresh-read each event with eventById/eventIntegrityValid; verify hashes, principal, SoD,
  exact basis, APPROVED, and zero unresolved. Opaque approval refs never qualify; a fix changes
  the basis and requires linked re-review. Implementer self-amend is forbidden.
  This standing authorization covers only mechanical paths required by existing requirements;
  it never authorizes a new architecture, phase, organ, or convenience residual.

Two-commit ritual (binding — kills receipt self-reference):
  COMMIT 1 = production + tests only → IMPLEMENTATION_COMMIT
  Write PHASE-*.json with:
    base_commit, implementation_commit=IMPLEMENTATION_COMMIT, branch, dirty_before, staged_before
    allowed_paths, touched_paths, deleted_paths, baseline_failures, new_failures
    tests {path, command, red_exit, green_exit, sanitized red_failure_reason, hash-only output_fingerprint_or_ref}
    artifact_hashes_or_refs, exit_checklist, residuals_closed, residuals_still_open,
    forbidden_touched, precise failure_reason_code/failure_reason|null, canonical-redaction-only notes
    review_basis v1 = SHA-256 canonical ordered payload excluding review_basis,
      review_attestation_refs, next_phase_authorized, next_phase_authorization_basis + SHA-256
      of exact draft LEDGER/SCOREBOARD UTF-8 LF-normalized bytes (zero exclusions)
    review_attestation_refs[] {ledger_event_id, event_hash, payload_hash, role, review_basis_sha256}
    next_phase_authorized {precommit predicates, evidence_commit_required=true,
      effective_only_when_read_from_committed_evidence_tree=true}
  All receipt paths are repo-relative allowlisted paths; redact with AtlasSecurity::redactString.
  Never persist raw env/provider output, prompts, credentials, secrets, or command output.
  Update LEDGER + SCOREBOARD
  Each review is existing AtlasEvidenceLedger::record GateEvaluated: authenticated-runtime
  principal hash, role, review_basis SHA, verdict, unresolved Critical/Important count,
  timestamp, and remediation/re-review parent event ref. Controller fresh-reads each event via
  eventById and eventIntegrityValid; verifies event/payload hashes, distinct principal/role/SoD,
  exact basis, APPROVED, and zero unresolved. Fixes change basis and require linked re-review.
  Missing ledger/event/identity/verification => BLOCKED/PARTIAL, never fabricate.
  Draft next_phase_authorized never activates anything. After COMMIT 2 only, controller reads
  that exact PHASE from the committed evidence tree and re-derives activation; no third commit.
  COMMIT 2 = PHASE + LEDGER + SCOREBOARD only
  PHASE contains no evidence_commit; controller reports its SHA only after COMMIT 2.
  No receipt.md. No extra evidence files.

Never: AaeosRunApplication, second ledger, Mission/WorkGraph/SovereigntyPort, git add -A.

CONTROLLER ACTIVATION (one serial slice only):
Activate `EXECUTE P0` only after the committed document-preflight contract has two independently
verifiable approvals, standing authorization, and all technical predicates; this prompt asserts none of them.
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
  - AaeosLedgerMeasurementReaderTest has recorded RED and GREEN exits
  - AtlasEvidenceLedger.php only if read-only measurement touch; else leave untouched
  - no AaeosRunApplication

Forbidden in P0:
  R33 brain args, R34/R35, provider/effect, Decision v3, ledger chain harden,
  self-amend MASTER, receipt.md, single commit that mixes impl+self-ref head

STOP: close P0, produce receipt, review it, and return control to the Goal controller.
P1a is already standing-authorized, but controller activates it only after reading P0 from the
evidence COMMIT 2 tree and re-deriving P0 GREEN, checklist/tests, commits, and valid independent
spec + governance/quality attestations. Human diff review is optional audit only, never a technical gate.
```

---

## Operador

1. Branch `main`. Aceite FOREIGN_WIP alheio (não mande a IA limpar).  
2. O controlador ativa exatamente uma frase serial já pré-autorizada.
3. Opcional: olhe o diff (auditoria) — **não** é gate.  
4. O controlador ativa a próxima frase já pré-autorizada somente após o predecessor GREEN, dois commits, reviews independentes e todos os gates técnicos.
