# A1--SelfConstruction — META FINDINGS

> Bucket: `app/Services/Ai/SelfConstruction`
> Wave: A1
> Layout: um registro YAML por arquivo. Não misturar outros buckets neste arquivo.
> Se este md > ~1500 linhas → partir `A1--SelfConstruction--<sub>.md`

```yaml
meta_complete: false
files_scanned: 1
files_total: 1210
lines_scanned: 29744
```

## Files

<!-- Sol: append one YAML block per file below, LOC desc order -->

```yaml
path: app/Services/Ai/SelfConstruction/Readiness/AtlasSelfConstructionReadinessService.php
loc: 29744
kind: php_god_facade_with_queries_commands_writers_and_policy_projections
intent_axes: [2, 3, 5, 6, 7, 10, 14, 15, 16, 18, 19, 20, 21, 22, 23, 24, 25, 28, 29, 33, 34, 35, 36, 37, 38, 40, 41, 42, 49, 50, 55, 62, 64, 65, 66, 67, 68, 69]
findings:
  - id: A1-SC-0001
    type: godfile
    severity: s0
    detail: "A final class has 29,744 LOC and 1,242 public methods. It owns readiness queries, packet/contract projection, queue and lease commands, database synchronization, heartbeat and wakeup writes, completion evidence, certification workbenches, provider-specific gates, and policy fixtures. This blocks context loading, bounded ownership, and safe characterization."
    evidence:
      - "wc -l => 29,744"
      - "rg '^\\s*public function ' => 1,242"
      - "167 app/tests files reference AtlasSelfConstructionReadinessService"
      - "sha256=dd8d410ab2e7eeb0cf6b00c4e6305b00559a6c6ef2c0aa0648bcbf2abdc6b3b0"
  - id: A1-SC-0002
    type: false_abstraction
    severity: s0
    detail: "The partial extraction preserved the god API instead of producing bounded owners: 735 methods are one-line forwards to *Section objects, while ReadinessProjection*.php totals 75,908 LOC and contains new monsters of 14,169, 13,784, and 12,751 LOC. Mother back-references and scattered lazy section factories keep bidirectional coupling and make the parent a mandatory routing hub."
    evidence:
      - "rg 'return $this->...Section()->' => 735 delegations"
      - "ReadinessProjectionAgentReviewMergeSection.php=14,169 LOC"
      - "ReadinessProjectionAgentCodexSection.php=13,784 LOC"
      - "ReadinessProjectionCodexReviewMergeSection.php=12,751 LOC"
      - "section factories and setMother wiring: lines 29,532-29,689"
  - id: A1-SC-0003
    type: bug
    severity: s1
    detail: "The shared status wrapper claims mode=read_only, runtime_write_allowed=false, and non-execution guarantees for every wrapped payload, but several *Status methods mutate durable state by recovering leases, enqueueing and claiming tasks, replenishing the queue, bootstrapping a worker, publishing artifacts, or capturing snapshots. The output can therefore attest the opposite of what the invoked path actually did."
    evidence:
      - "task lease recovery mutates through recoverExpiredLeases/recoverOrphanedClaims/recoverReleasedTasks: lines 25,086-25,178"
      - "task queue orchestrator status calls prepareAndEnqueue: lines 25,291-25,313"
      - "claim-next status calls claimNext and may prepareAndEnqueue: lines 25,328-25,389"
      - "auto-replenishment status calls replenish: lines 25,424-25,486"
      - "terminal-worker-bootstrap status defaults preview=false and calls bootstrap: lines 25,675-25,773"
      - "wrapper hard-codes read_only and runtime_write_allowed=false: lines 26,686-26,716"
      - "publisher tests prove three files can be copied while runtime_write_allowed remains false"
  - id: A1-SC-0004
    type: bug
    severity: s1
    detail: "Merge-review status is fail-open when expected result fields are absent: promotion_allowed and completion_claim_allowed default to true, unlike the fail-closed defaults used across safety-critical completion and dispatch projections. A malformed or drifted certification payload can therefore surface permissive aliases."
    evidence:
      - "lines 24,387-24,397 default promotion_allowed=true and completion_claim_allowed=true"
      - "the same wrapper derives status from an arbitrary payload key and otherwise uses unknown: lines 26,686-26,691"
  - id: A1-SC-0005
    type: dupe
    severity: s2
    detail: "Contract/preflight/implementation-packet/status quartets and status graph construction are repeated at industrial scale. There are 220 buildCertificationWorkbenchQuartet occurrences and 67 wrapCertificationWorkbenchStatus occurrences; many status methods reconstruct the same audit/replay/store/diff/gate graph and repeat completion blocker classification and command selection."
    evidence:
      - "certification quartet sequences dominate lines 20,600-26,650"
      - "audit/replay/store/diff/gate object graphs recur across certification status methods"
      - "completion blocker classification and next-command matches recur across lines 21,390-23,550"
  - id: A1-SC-0006
    type: perf
    severity: s2
    detail: "A single service entrypoint repeatedly probes schema and constructs large service graphs, performs registry scans, full run collection, and nested readiness projections. The file contains 151 Schema::hasTable occurrences plus status paths that call other compound statuses; query and filesystem budgets are not visible at the facade boundary."
    evidence:
      - "rg 'Schema::hasTable(' => 151 occurrences"
      - "agentRunLiveness loads all runs then maps them in memory: lines 26,923-27,018"
      - "completion/finalization statuses recursively rebuild evidence and gap-matrix projections: lines 21,390-23,900"
      - "two direct Storage::disk occurrences and at least 16 explicit mutator-call occurrences exist in this file"
  - id: A1-SC-0007
    type: os_overlap
    severity: s0
    detail: "The generic readiness owner embeds Atlas Self-Construction completion, Agent Control Plane runtime, operator handoff, Self-Programming transition, and a long Codex-specific process-start/post-start chain. Provider/bootstrap compatibility is not isolated from the provider-neutral control plane, so ownership and sovereignty cannot be stated truthfully from the class name or namespace."
    evidence:
      - "Codex-specific public forwarding chain spans roughly lines 27,680-28,550"
      - "Self-Construction completion and Self-Programming transition projections coexist around lines 21,390-23,950"
      - "queue/runtime persistence begins around lines 25,000-27,500"
  - id: A1-SC-0008
    type: doc_lie
    severity: s1
    detail: "The in-code cold-lane certification declares self_construction_surfaces_are_read_only and cites a stale non-Readiness source path, even though the same class exposes and invokes persistent queue, lease, artifact, heartbeat, wakeup, and runtime writers. The governance scorecard then awards readiness points from those self-declared projections instead of independent runtime evidence."
    evidence:
      - "cold-lane certified_scope uses app/Services/Ai/SelfConstruction/AtlasSelfConstructionReadinessService.php: lines 28,751-28,766"
      - "governance scorecard derives points from local digest/surface/certification projections: lines 29,006-29,078"
      - "actual source is app/Services/Ai/SelfConstruction/Readiness/AtlasSelfConstructionReadinessService.php"
actions:
  - op: TEST
    detail: "Before any split, freeze every externally consumed public schema, stable hash, command flag, default option, error path, and side effect. Add explicit mutation spies for each *Status method so the suite distinguishes read-only projection, preview, command, writer, and provider dispatch semantics."
    target_paths:
      - tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php
      - tests/Feature/Ai/AtlasAiSelfConstructionAgentControlPlaneTaskLeaseRecoveryTest.php
      - tests/Feature/Ai/AtlasAiSelfConstructionAgentControlPlaneTaskAutoReplenishmentTest.php
      - tests/Feature/Ai/AtlasAiSelfConstructionAgentControlPlaneTerminalWorkerBootstrapTest.php
      - tests/Feature/Ai/SelfConstruction/AtlasSelfConstructionOperatorEvidenceDraftWorkspacePublisherTest.php
    acceptance:
      - "/opt/homebrew/bin/php artisan test tests/Feature/Ai/AtlasAiSelfConstructionAgentControlPlaneTaskLeaseRecoveryTest.php tests/Feature/Ai/AtlasAiSelfConstructionAgentControlPlaneTaskAutoReplenishmentTest.php tests/Feature/Ai/AtlasAiSelfConstructionAgentControlPlaneTerminalWorkerBootstrapTest.php"
      - "/opt/homebrew/bin/php artisan test tests/Feature/Ai/SelfConstruction/AtlasSelfConstructionOperatorEvidenceDraftWorkspacePublisherTest.php"
      - "rg -n 'runtime_write_allowed|non_execution_guarantees' tests/Feature/Ai | rg 'Readiness|TaskLease|Replenishment|Bootstrap|Publisher'"
  - op: BUGFIX_PLAN
    detail: "Make envelope truthfulness non-negotiable. Read-only *Status methods must not write; mutating operations must have explicit command/writer names and report runtime_write_performed, persisted artifact ids, and idempotency keys. Replace permissive missing-field defaults with fail-closed false plus typed violation evidence."
    target_paths:
      - app/Services/Ai/SelfConstruction/Readiness/AtlasSelfConstructionReadinessService.php
      - app/Services/Ai/SelfConstruction/Readiness/ReadinessStatusProjection.php
      - app/Services/Ai/SelfConstruction/Readiness/ReadinessCertificationWorkbenchQuartetBuilder.php
    acceptance:
      - "rg -n 'function .*Status' app/Services/Ai/SelfConstruction/Readiness | wc -l"
      - "rg -n \"promotion_allowed.*true|completion_claim_allowed.*true\" app/Services/Ai/SelfConstruction/Readiness"
      - "/opt/homebrew/bin/php artisan test --parallel tests/Feature/Ai/SelfConstruction tests/Feature/Ai/AtlasAiSelfConstructionAgentControlPlaneTaskLeaseRecoveryTest.php"
  - op: SPLIT
    detail: "SPLIT first. Keep AtlasSelfConstructionReadinessService only as a temporary compatibility facade and move behavior into bounded, one-way owners for readiness queries, runtime commands/writers, certification workbench, completion evidence, and provider adapters. Do not create another *Section mega-file; each resulting PHP must be below 2,000 LOC and hot facade/command owners at or below 800 LOC."
    target_paths:
      - app/Services/Ai/SelfConstruction/Readiness/AtlasSelfConstructionReadinessService.php
      - app/Services/Ai/SelfConstruction/Readiness
      - app/Services/Ai/SelfConstruction/ControlPlane
      - app/Services/Ai/SelfConstruction/NativeImplementation
    acceptance:
      - "find app/Services/Ai/SelfConstruction -name '*.php' -print0 | xargs -0 wc -l | awk '$1 > 2000 {print}'"
      - "wc -l app/Services/Ai/SelfConstruction/Readiness/AtlasSelfConstructionReadinessService.php"
      - "rg -n 'setMother\\(\\$this\\)' app/Services/Ai/SelfConstruction/Readiness"
  - op: OWNER
    detail: "Define one owner and one public facade per capability family. Treat Codex/manual operator flows as provider/bootstrap adapters behind provider-neutral contracts; do not delete compatibility until callers and payload schemas are characterized."
    target_paths:
      - docs/evidence/2026-07-22-atlas-server-god-debulk/OWNERSHIP.md
      - app/Services/Ai/SelfConstruction/Readiness
      - app/Services/Ai/SelfConstruction/ControlPlane
    acceptance:
      - "rg -n 'SelfConstruction|Agent Control Plane|Codex|completion evidence' docs/evidence/2026-07-22-atlas-server-god-debulk/OWNERSHIP.md"
      - "rg -n 'AtlasSelfConstructionReadinessService' app tests | cut -d: -f1 | sort -u"
  - op: EXTRACT
    detail: "Replace handwritten quartet and repeated status-graph construction with a typed capability catalog plus small capability-owned builders. Keep payload keys and hashes stable; reject any abstraction that lacks an invariant or second consumer."
    target_paths:
      - app/Services/Ai/SelfConstruction/Readiness/ReadinessCertificationWorkbenchQuartetBuilder.php
      - app/Services/Ai/SelfConstruction/Readiness/AtlasSelfConstructionReadinessService.php
    acceptance:
      - "rg -c 'buildCertificationWorkbenchQuartet\\(' app/Services/Ai/SelfConstruction/Readiness/AtlasSelfConstructionReadinessService.php"
      - "rg -c 'new AgentControlPlaneChainIntegrityAuditService' app/Services/Ai/SelfConstruction/Readiness/AtlasSelfConstructionReadinessService.php"
      - "/opt/homebrew/bin/php artisan test tests/Feature/Ai/AtlasAiSelfConstructionAgentControlPlaneCertificationStatusBatchTest.php tests/Feature/Ai/AtlasAiSelfConstructionAgentControlPlaneChainIntegrityAuditTest.php"
  - op: CODEMAP
    detail: "Map all 1,242 public methods to their command/caller and future owner, collapse the CLI to documented capability families, and retain thin aliases for no more than one migration cycle. No public method may remain ownership-unknown."
    target_paths:
      - docs/engineering-knowledge-base/CODEMAP.md
      - app/Console/Commands/AtlasAiSelfConstructionMotherCommand.php
      - app/Console/Commands/AtlasAiSelfConstructionStatusCommand.php
    acceptance:
      - "rg -n '^\\s*public function ' app/Services/Ai/SelfConstruction/Readiness/AtlasSelfConstructionReadinessService.php"
      - "/opt/homebrew/bin/php artisan atlas:ai:architecture-validate --json"
      - "/opt/homebrew/bin/php artisan atlas:engineering:knowledge codemap-verify --json"
  - op: PERF
    detail: "Set query, filesystem, memory, and startup budgets per readiness family; remove repeated schema probes and compound status recomputation only after characterization proves identical results."
    target_paths:
      - app/Services/Ai/SelfConstruction/Readiness/ReadinessAgentControlPlaneSchemaProbe.php
      - app/Services/Ai/SelfConstruction/Readiness/AtlasSelfConstructionReadinessService.php
    acceptance:
      - "rg -c 'Schema::hasTable\\(' app/Services/Ai/SelfConstruction/Readiness/AtlasSelfConstructionReadinessService.php"
      - "/opt/homebrew/bin/php artisan test tests/Feature/Ai/SelfConstruction/AtlasAiSelfConstructionStatusCommandTest.php"
caps: [A, B, C, D, E, F, G]
evidence:
  read_mode: full_file_sequential_no_skipped_lines
  line_range_read: 1-29744
  syntax: "No syntax errors detected by /opt/homebrew/bin/php -l"
  source_modified_during_meta: false
  callers_and_tests_reference_file_count: 167
  public_method_count: 1242
  section_delegation_count: 735
  quartet_builder_occurrence_count: 220
  status_wrapper_occurrence_count: 67
  schema_probe_occurrence_count: 151
  explicit_mutator_call_occurrence_count: 16
  projection_section_loc_total: 75908
  next_file_by_loc: app/Services/Ai/SelfConstruction/Readiness/ReadinessProjectionAgentReviewMergeSection.php
```

## Bucket rollup

```markdown
- files_scanned: 1 / 1210
- lines_scanned: 29744
- s0..s3: 3 / 3 / 2 / 0
- intent_axes_covered: [2, 3, 5, 6, 7, 10, 14, 15, 16, 18, 19, 20, 21, 22, 23, 24, 25, 28, 29, 33, 34, 35, 36, 37, 38, 40, 41, 42, 49, 50, 55, 62, 64, 65, 66, 67, 68, 69]
- intent_axes_missing_in_this_bucket: []
- ownership_proposal: "thin compatibility facade -> readiness query owner + runtime command/writer owner + certification workbench owner + completion evidence owner + provider adapters"
- ordered_worklist: ["TEST status truthfulness and payload contracts", "SPLIT parent and monster sections", "OWNER capability families", "EXTRACT typed quartet catalog", "CODEMAP callers and aliases", "PERF query/IO budgets"]
- meta_complete: false
```
