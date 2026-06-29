# Verification court and merge governor

For 24/7 autonomy, worker output is an allegation until Atlas verifies it. The Verification Court re-runs the required gates server-side, validates the evidence, detects Goodhart and false green, and rejects. It never authors the candidate. The Merge Governor controls integration into `main`, rollback, canary, and blast-radius. It never trusts worker self-report. Every verdict and every release decision is written to an append-only, tamper-evident ledger before autonomous release authority is allowed to grow.

## Purpose

To make a self-improving system safe to run unattended. A worker that reports green on a diff that does nothing, or that steps outside its allowed files, or that claims a proxy metric as value, must be caught by an organ that did not write the change and cannot be gamed by the worker. The court and the governor are that organ. They are the runtime enforcement of the [separation of powers](separation-of-powers.md): the author is never the judge.

## Key abstractions

| Abstraction | Role |
|---|---|
| Gate replay plan | Pure planner that derives the ordered server-side gates to re-run from task evidence and risk facts |
| False green detector | Pure detector that rejects worker green when replay or scope facts contradict it |
| Verdict ledger | Append-only, tamper-evident ledger of every pass, fail, and blocked verdict |
| Risk classifier | Pure classifier that scores blast radius as low, medium, high, or blocked |
| Rollback plan gate | Pure gate that requires an executable rollback plan before integration |
| Admission policy | Pure policy that decides admitted, rejected, repair_required, or blocked |
| Release decision ledger | Append-only ledger of every release decision |
| FORBIDDEN_ORGANS | The organs whose mutation is forbidden to any auto-promoted candidate |

## How it works

### Verification Court

The court runs three pure stages, then records the verdict.

`AtlasVerificationCourtGateReplayPlan` derives the ordered list of server-side gates to re-run. It never executes anything: no shell, no git, no provider call, no queue write. It emits commands in five categories, always included when applicable:

| Category | When included |
|---|---|
| `docs-health` | Changed files include `docs/` or `.md` |
| `phpunit-scoped` | Changed files include impl PHP under `app/` |
| `declared-gates` | Every entry from `packet_facts.declared_gates` |
| `diff-check` | Any changed file is present |
| `lane-freshness` | A project lane with a `project_id` is attached |

The plan is blocked when the evidence contract is not accepted or no replayable gate can be derived. Command ids are deterministic (sha256 of category and payload, truncated).

`AtlasVerificationCourtFalseGreenDetector` is the anti-false-green gate. It rejects worker completion when evidence says green but replay or scope facts contradict it. It emits one of three verdicts and a sorted reason list:

| Verdict | When |
|---|---|
| `passed` | No blocker and no failed reason |
| `failed` | A replay command is red, output is missing, a file is outside allowed scope, or evidence is proxy-only |
| `blocked` | The evidence contract is not accepted, the replay plan is blocked, or a planned command has no recorded outcome |

The reason families are closed and deterministic:

- `evidence_contract_not_accepted` (blocked)
- `replay_plan_blocked` and `replay_plan_blocker:<b>` (blocked)
- `replay_missing_for:<command_id>` (blocked)
- `replay_red:<command_id>` (failed)
- `replay_output_missing:<command_id>` (failed, a claimed test with no command output)
- `changed_file_outside_allowed:<path>` (failed, the scope-clean check)
- `proxy_only_evidence` (failed, the anti-Goodhart check)

The detector emits no scalar score, performs no mutation, runs no shell, no git, and no provider call.

`AtlasVerificationCourtVerdictLedger` records every verdict in an append-only file. It opens with `fopen('a')` and `flock(LOCK_EX)`, validates `task_packet_id`, `evidence_hash`, `replay_plan_hash`, `verdict`, `reasons`, `replay_outcome_hash`, and `decided_at`, and is idempotent: a duplicate `verdict_hash` returns `already_recorded` without appending. The `verdict_hash` is sha256 over the canonical fields.

### Merge Governor

The governor runs three pure gates, then decides, then records.

`AtlasMergeGovernorRiskClassifier` classifies the candidate by blast radius before any admission decision. The risk level is one of `low`, `medium`, `high`, or `blocked`. The classifier blocks on five hard conditions:

- scope deviations are present
- verification did not pass
- the rollback plan is missing or lacks a `mode`
- a FORBIDDEN organ is touched
- a changed file escapes the project lane's `allowed_scope_roots`

The forbidden organs, whose mutation is forbidden to any auto-promoted candidate, are `Constitution`, `MasterSwitch`, and `WorkspaceMaterializer`. Core-adjacent organs (`Merge Governor`, `Verification Court`, `Task Fabric`) escalate risk to high when touched. Docs-only changes (every file under `docs/` or with a `.md`, `.txt`, or `.rst` extension) are low. Other service or test changes are medium. No scalar score is computed.

`AtlasMergeGovernorRollbackPlanGate` requires every candidate to carry an executable rollback plan. A plan is conformant only when `restore_strategy` is non-empty, `affected_files` is non-empty, `verification_after_rollback` is non-empty, `owner_scope` matches the project lane's `project_id`, and every affected file lives inside the lane's `allowed_scope_roots`.

`AtlasMergeGovernorAdmissionPolicy` composes the risk classification, the rollback gate, the verification court verdict, the project lane, and the release window into one decision. The decisions are:

| Decision | When |
|---|---|
| `blocked` | Risk is blocked, the court and lane disagree on project id, high risk lacks conformant rollback, or risk is outside the release window |
| `rejected` | Verification is not server-side green |
| `repair_required` | Verification is green but the evidence hash is missing, a gate rerun is missing, or rollback is not conformant |
| `admitted` | Server-side green is true, the evidence hash is present, rollback is conformant, and no blocker triggered |

The policy is pure: no queue writes, no git, no shell, no provider call, no merge side effect. It never trusts worker self-report alone. Admission requires `server_side_green = true` and a present `evidence_hash`. This is the structural cut that makes author-not-judge enforceable at the merge boundary.

`AtlasMergeGovernorReleaseDecisionLedger` records every decision in an append-only file with the same guarantees as the verdict ledger: `fopen('a')` plus `flock(LOCK_EX)`, full field validation, idempotency on duplicate `decision_hash`, and a deterministic hash over the canonical fields. The ledger exists before autonomous release authority is allowed to grow, so the audit trail is in place first.

### The older runtime families

The Self-Construction root also holds the older, larger runtime families that the per-organ subdirectories refine and supersede: the `AgentValidationGate*` family (`Catalog`, `CertificationService`, `PlanBuilder`, `FailureClassifier`, `RepairRecommendationBuilder`, `ResultRepository`) and the `AgentMergeReview*` family (`PacketBuilder`, `RiskScorer`, `ScopeVerifier`, `RollbackVerifier`, `HumanApprovalPlanner`, `PromotionDryRun`, `CertificationService`). The pure per-organ services under `VerificationCourt/` and `MergeGovernor/` are the canonical destination; the root families are the bootstrap runtime that wires those policies into the live dispatch chain.

## Integration points

- Inputs are candidate changes from the [Maestro and worker swarm](maestro-and-worker-swarm.md).
- The court's `server_side_green` and `evidence_hash` are the inputs the governor requires for admission; nothing else authorizes a merge.
- The rollback plan gate enforces that rollback must be executable before higher autonomy is granted, tying back to [Constitution and earned autonomy](constitution-and-earned-autonomy.md).
- The verdict and decision ledgers feed [Receipts and evidence](learning-transfer-and-knowledge-sync.md) and the broader evidence ledger.
- The forbidden-organ list overlaps the FORBIDDEN/pétreo core; see the [constitution](constitution-and-earned-autonomy.md).

## Key source files

| File | Role |
|---|---|
| `app/Services/Ai/SelfConstruction/VerificationCourt/AtlasVerificationCourtGateReplayPlan.php` | Pure gate replay planner |
| `app/Services/Ai/SelfConstruction/VerificationCourt/AtlasVerificationCourtFalseGreenDetector.php` | False-green and proxy-only detector |
| `app/Services/Ai/SelfConstruction/VerificationCourt/AtlasVerificationCourtVerdictLedger.php` | Append-only verdict ledger |
| `app/Services/Ai/SelfConstruction/VerificationCourt/AtlasVerificationCourtEvidenceContract.php` | Evidence contract |
| `app/Services/Ai/SelfConstruction/MergeGovernor/AtlasMergeGovernorRiskClassifier.php` | Blast-radius risk classifier |
| `app/Services/Ai/SelfConstruction/MergeGovernor/AtlasMergeGovernorRollbackPlanGate.php` | Executable rollback plan gate |
| `app/Services/Ai/SelfConstruction/MergeGovernor/AtlasMergeGovernorAdmissionPolicy.php` | Admission policy (never trusts self-report) |
| `app/Services/Ai/SelfConstruction/MergeGovernor/AtlasMergeGovernorReleaseDecisionLedger.php` | Append-only release decision ledger |
| `app/Services/Ai/SelfConstruction/AgentValidationGateCertificationService.php` | Older validation gate certification runtime |
| `app/Services/Ai/SelfConstruction/AgentMergeReviewCertificationService.php` | Older merge review certification runtime |

## Related pages

- [Self-Construction OS and government](index.md) — the overview and 16-organ map
- [Separation of powers](separation-of-powers.md) — why the author is never the judge
- [Maestro and worker swarm](maestro-and-worker-swarm.md) — where candidates come from
- [Constitution and earned autonomy](constitution-and-earned-autonomy.md) — the forbidden organs and rollback requirements
- [Learning transfer and knowledge sync](learning-transfer-and-knowledge-sync.md) — where verdicts and decisions are recorded as evidence
- [Evidence and receipts (concept)](../../concepts/evidence-and-receipts.md) — the append-only, tamper-evident ledger pattern
- [Anti-Goodhart and no-proxy](../../concepts/anti-goodhart.md) — the proxy_only_evidence check
- [Glossary](../../overview/glossary.md) — verification court, merge governor, false green, blast radius
