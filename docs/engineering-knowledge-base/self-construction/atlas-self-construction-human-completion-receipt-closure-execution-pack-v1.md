# Atlas Self-Construction · Human Completion Receipt Closure Execution Pack v1

> **Status:** services + tests **delivered green**; readiness/CLI/contract
> wiring kept as **integration patch pending** because the three shared files
> (`AtlasSelfConstructionReadinessService.php`,
> `AtlasAiSelfConstructionCommand.php`,
> `agent-control-plane-contract.md`) were modified concurrently by another
> agent during this slice's execution. Per the operator's explicit
> instruction this slice did **not** rewrite them.

---

## What this slice closes

The corridor for the canonical blocker
`human_signed_os_complete_receipt_present`. It NEVER:

- signs the receipt on behalf of the operator;
- persists the receipt;
- promotes completion;
- declares Atlas Self-Construction OS complete;
- calls a provider, dispatches work, spends tokens or enables runtime.

It composes a **single read-only execution pack** that explains, step by
step, what the operator must do; runs a pre-submission diagnostic verifier;
and exposes a read-only completion finalization gate.

In the current real state the slice's invariant is: `completion_claim_allowed
= false` everywhere. The finalization gate flips to `true` **only** when the
canonical completion audit is `status=complete` with zero failed criteria.

---

## New services (already delivered, green)

### 1. `AtlasSelfConstructionHumanCompletionReceiptClosureExecutionPackService`
- Schema: `atlas.self_construction.human_completion_receipt_closure_execution_pack.v1`
- Method: `build(array $options = []): array`
- Composes: completion audit · completion evidence status · human completion
  receipt runbook · human completion receipt draft · human completion
  receipt dossier · blocker explainer · final evidence bundle · submission
  preflight.
- Status enum:
  - `blocked_runtime_and_smoke_required`
  - `blocked_human_signature_required`
  - `ready_to_verify_human_completion_receipt`
  - `ready_to_persist_human_completion_receipt`
  - `human_completion_receipt_verified`
- Payload includes `current_prerequisites`, `receipt_template`,
  `receipt_draft`, `verification_result`, `persistence_preflight`,
  `ordered_operator_steps`, `exact_commands`, `anti_cheat_policy`,
  `non_execution_guarantees`, `closure_pack_hash`.

### 2. `AtlasSelfConstructionHumanCompletionReceiptPreSubmissionVerifierService`
- Schema: `atlas.self_construction.human_completion_receipt_pre_submission_verifier.v1`
- Method: `verify(array $receipt, array $context = []): array`
- Diagnostic codes covered: `missing_receipt_id`, `missing_signed_by`,
  `placeholder_or_fake_signer`, `missing_reason`, `reason_too_short`,
  `missing_completion_audit_hash`, `missing_runtime_promotion_receipt_hash`,
  `missing_real_provider_smoke_hash`, `missing_release_dossier_hash`,
  `missing_replay_diff_hash`, `missing_certification_status_batch_hash`,
  `receipt_hash_invalid`, `receipt_hash_mismatch`,
  `stale_completion_audit_hash`, `stale_release_dossier_hash`,
  `stale_replay_diff_hash`, `stale_runtime_gap_matrix_hash`,
  `stale_runtime_promotion_receipt_hash`, `stale_real_provider_smoke_hash`,
  `stale_certification_status_batch_hash`, `os_complete_approved_false`,
  `operator_reviewed_completion_audit_false`,
  `no_autopromotion_acknowledged_false`, `forbidden_flag_true`,
  `prerequisites_not_green`, `placeholder_reason_pattern`.
- Returns `status` ∈ {`passed`, `blocked`}, `can_persist`,
  `persistence_blocker`, `failed_prerequisites`.

### 3. `AtlasSelfConstructionCompletionFinalizationGateService`
- Schema: `atlas.self_construction.completion_finalization_gate.v1`
- Method: `evaluate(array $options = []): array`
- Read-only final gate. Returns `completion_claim_allowed=false` in the
  current real state, **true** only when the completion audit is complete
  with every check green.
- Exposes: `completion_audit_complete`, `runtime_all_y`, `smoke_green`,
  `human_receipt_green`, `replay_green`, `dossier_green`, `batch_green`,
  `mutation_guard_green`, `next_stage_allowed`, `next_stage_blockers`.

All three services are in `app/Services/Ai/SelfConstruction/`. `php -l`
clean. They never persist state and emit deterministic stable hashes.

---

## New tests (already delivered, green)

| Test file | Tests | Assertions |
|---|---|---|
| `tests/Feature/Ai/SelfConstruction/AtlasSelfConstructionHumanCompletionReceiptClosureExecutionPackTest.php` | 8 | 35 |
| `tests/Feature/Ai/SelfConstruction/AtlasSelfConstructionHumanCompletionReceiptPreSubmissionVerifierTest.php` | 11 | 31 |
| `tests/Feature/Ai/SelfConstruction/AtlasSelfConstructionCompletionFinalizationGateTest.php` | 6 | 24 |

**Total: 25 tests green, 0 failures.** Each test covers exactly the
behaviours required by the slice brief (closure pack blocks without
runtime/smoke; draft not ready without prereqs; verifier detects placeholder
signer / missing hashes / stale hashes / forbidden flags; verifier passes
with canonical test payload when prereqs green; persistence preflight
blocks before prereqs; finalization gate false in current state; gate true
only with audit complete; non-execution guarantees preserved).

---

## Integration patch pending — what the next agent must add

> **Why pending:** while this slice was implementing, another agent
> modified `AtlasSelfConstructionReadinessService.php`,
> `AtlasAiSelfConstructionCommand.php` and
> `docs/engineering-knowledge-base/self-construction/agent-control-plane-contract.md`.
> Per operator instruction this slice did not overwrite them.

### A. `AtlasSelfConstructionReadinessService.php`

**A.1** Add 15 capability strings to the capability catalog (next to the
existing `atlas_self_construction_human_completion_receipt_dossier_*` and
`atlas_self_construction_human_completion_receipt_draft_*` blocks):

```
atlas_self_construction_human_completion_receipt_closure_execution_pack_contract
atlas_self_construction_human_completion_receipt_closure_execution_pack_preflight
atlas_self_construction_human_completion_receipt_closure_execution_pack_implementation_packet
atlas_self_construction_human_completion_receipt_closure_execution_pack_service
atlas_self_construction_human_completion_receipt_closure_execution_pack_status_projection
atlas_self_construction_human_completion_receipt_pre_submission_verifier_contract
atlas_self_construction_human_completion_receipt_pre_submission_verifier_preflight
atlas_self_construction_human_completion_receipt_pre_submission_verifier_implementation_packet
atlas_self_construction_human_completion_receipt_pre_submission_verifier_service
atlas_self_construction_human_completion_receipt_pre_submission_verifier_status_projection
atlas_self_construction_completion_finalization_gate_contract
atlas_self_construction_completion_finalization_gate_preflight
atlas_self_construction_completion_finalization_gate_implementation_packet
atlas_self_construction_completion_finalization_gate_service
atlas_self_construction_completion_finalization_gate_status_projection
```

**A.2** Add three readiness quartets (mirroring the existing
`atlasSelfConstructionHumanCompletionReceiptRunbook*` / `Dossier*` / `Draft*`
quartet shape):

```php
public function atlasSelfConstructionHumanCompletionReceiptClosureExecutionPackContract(array $options = []): array
{
    return $this->buildCertificationWorkbenchQuartet(
        'atlas_self_construction_human_completion_receipt_closure_execution_pack',
        'Atlas Self-Construction Human Completion Receipt Closure Execution Pack',
        AtlasSelfConstructionHumanCompletionReceiptClosureExecutionPackService::SCHEMA_VERSION,
        AtlasSelfConstructionHumanCompletionReceiptClosureExecutionPackService::class,
        'contract',
    );
}
public function atlasSelfConstructionHumanCompletionReceiptClosureExecutionPackPreflight(array $options = []): array
{
    return $this->buildCertificationWorkbenchQuartet(
        'atlas_self_construction_human_completion_receipt_closure_execution_pack',
        'Atlas Self-Construction Human Completion Receipt Closure Execution Pack',
        AtlasSelfConstructionHumanCompletionReceiptClosureExecutionPackService::SCHEMA_VERSION,
        AtlasSelfConstructionHumanCompletionReceiptClosureExecutionPackService::class,
        'preflight',
    );
}
public function atlasSelfConstructionHumanCompletionReceiptClosureExecutionPackImplementationPacket(array $options = []): array
{
    return $this->buildCertificationWorkbenchQuartet(
        'atlas_self_construction_human_completion_receipt_closure_execution_pack',
        'Atlas Self-Construction Human Completion Receipt Closure Execution Pack',
        AtlasSelfConstructionHumanCompletionReceiptClosureExecutionPackService::SCHEMA_VERSION,
        AtlasSelfConstructionHumanCompletionReceiptClosureExecutionPackService::class,
        'implementation_packet',
    );
}
public function atlasSelfConstructionHumanCompletionReceiptClosureExecutionPackStatus(array $options = []): array
{
    $result = (new AtlasSelfConstructionHumanCompletionReceiptClosureExecutionPackService($this))->build($options);

    return $this->wrapCertificationWorkbenchStatus(
        keyPrefix: 'atlas_self_construction_human_completion_receipt_closure_execution_pack',
        label: 'Atlas Self-Construction Human Completion Receipt Closure Execution Pack',
        payload: $result,
        statusKey: 'status',
        extraStatusFields: [
            'closure_pack_hash' => (string) data_get($result, 'closure_pack_hash'),
            'completion_audit_status' => (string) data_get($result, 'completion_audit.status'),
            'completion_audit_failed_count' => (int) data_get($result, 'completion_audit.failed_count', 0),
            'persistence_preflight_can_persist' => (bool) data_get($result, 'persistence_preflight.can_persist', false),
            'verifier_status' => (string) data_get($result, 'verification_result.status'),
        ],
    );
}
```

Repeat the same shape for `atlasSelfConstructionHumanCompletionReceiptPreSubmissionVerifier*` (status method:
`$result = (new AtlasSelfConstructionHumanCompletionReceiptPreSubmissionVerifierService)->verify((array) ($options['completion_receipt'] ?? $this->decodeJsonOption($options['completion_receipt_json'] ?? null)), (array) ($options['verifier_context'] ?? []));`) and `atlasSelfConstructionCompletionFinalizationGate*` (status method:
`$result = (new AtlasSelfConstructionCompletionFinalizationGateService($this))->evaluate($options);`).

### B. `AtlasAiSelfConstructionCommand.php`

Add CLI flags next to the existing
`--atlas-self-construction-human-completion-receipt-dossier-*` block:

```
{--atlas-self-construction-human-completion-receipt-closure-execution-pack-contract : ...}
{--atlas-self-construction-human-completion-receipt-closure-execution-pack-preflight : ...}
{--atlas-self-construction-human-completion-receipt-closure-execution-pack-implementation-packet : ...}
{--atlas-self-construction-human-completion-receipt-closure-execution-pack-status : Run the read-only Atlas Self-Construction Human Completion Receipt Closure Execution Pack}
{--atlas-self-construction-human-completion-receipt-pre-submission-verifier-contract : ...}
{--atlas-self-construction-human-completion-receipt-pre-submission-verifier-preflight : ...}
{--atlas-self-construction-human-completion-receipt-pre-submission-verifier-implementation-packet : ...}
{--atlas-self-construction-human-completion-receipt-pre-submission-verifier-status : Run the read-only Atlas Self-Construction Human Completion Receipt Pre-Submission Verifier}
{--atlas-self-construction-completion-finalization-gate-contract : ...}
{--atlas-self-construction-completion-finalization-gate-preflight : ...}
{--atlas-self-construction-completion-finalization-gate-implementation-packet : ...}
{--atlas-self-construction-completion-finalization-gate-status : Run the read-only Atlas Self-Construction Completion Finalization Gate}
```

Add corresponding `match (true) {}` arms invoking
`$readiness->atlasSelfConstructionHumanCompletionReceiptClosureExecutionPackStatus($options)` etc.

### C. `agent-control-plane-contract.md`

Append a single canonical bullet under the existing receipt corridor
section:

> - Atlas Self-Construction Human Completion Receipt Closure Execution Pack
>   v1 (schema
>   `atlas.self_construction.human_completion_receipt_closure_execution_pack.v1`),
>   plus the Pre-Submission Verifier
>   (`atlas.self_construction.human_completion_receipt_pre_submission_verifier.v1`)
>   and the Completion Finalization Gate
>   (`atlas.self_construction.completion_finalization_gate.v1`), close the
>   `human_signed_os_complete_receipt_present` corridor. They compose
>   audit/runbook/draft/dossier/blocker-explainer/final-bundle/submission-preflight
>   into a single read-only execution pack; never sign or persist receipts;
>   never promote completion; and only flip
>   `completion_claim_allowed=true` after the canonical completion audit
>   reaches `status=complete` with every criterion green.

---

## How the operator closes the corridor

The closure pack itself emits the ordered steps, but the canonical sequence
is:

1. `php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-audit-status --json` — refresh completion audit and capture the current `completion_audit_hash`.
2. Persist runtime promotion receipt until `runtime_gap_matrix.all_runtime_y=true`.
3. Run end-to-end real-provider claim→completion smoke and persist the evidence.
4. Re-run the audit; confirm release dossier, replay diff, mutation guard, certification status batch all green.
5. Draft the human completion receipt with a real operator name and a ≥32-char reason; recompute `receipt_hash` via the canonical hash service.
6. Run the pre-submission verifier; iterate until `status=passed`.
7. Persist the receipt through the canonical verifier (`--persist-completion-evidence`).
8. Re-run the audit; `human_signed_os_complete_receipt_present` must flip green.
9. Run the completion finalization gate; `completion_claim_allowed` flips to `true` only when every check is green.

The blocker only closes after **real** runtime+smoke evidence, a **real**
human signature, and a **real** completion audit pass. There is no
auto-promotion path. There is no fake-signature path. There is no
short-circuit.
