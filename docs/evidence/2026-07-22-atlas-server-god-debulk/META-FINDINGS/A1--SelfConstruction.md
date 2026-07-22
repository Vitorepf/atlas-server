# A1--SelfConstruction — META FINDINGS

> Bucket: `app/Services/Ai/SelfConstruction`
> Wave: A1
> Layout: um registro YAML por arquivo. Não misturar outros buckets neste arquivo.
> Se este md > ~1500 linhas → partir `A1--SelfConstruction--<sub>.md`

```yaml
meta_complete: false
files_scanned: 12
files_total: 1210
lines_scanned: 98942
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

```yaml
path: app/Services/Ai/SelfConstruction/Readiness/ReadinessProjectionAgentReviewMergeSection.php
loc: 14169
kind: generated_review_merge_future_contract_chain_section
intent_axes: [2, 3, 5, 6, 7, 10, 14, 15, 16, 17, 18, 19, 20, 21, 22, 24, 25, 27, 28, 29, 33, 34, 37, 40, 41, 42, 49, 50, 54, 55, 62, 64, 65, 66, 67, 68, 69]
findings:
  - id: A1-SC-0009
    type: godfile
    severity: s0
    detail: "The extracted final collaborator is itself a 14,169 LOC, 1,130,171-byte godfile with 109 public agentReviewMerge methods. Its longest public method name is 170 characters and the class spans the full merge, authorization, writer release, re-enable, disable, repair, later-cycle, and manual-decision lifecycle, so the extraction still cannot be loaded or owned as a bounded hot module."
    evidence:
      - "wc -l => 14,169; wc -c => 1,130,171"
      - "public agentReviewMerge method count => 109, plus constructor"
      - "first domain method starts at line 33; last starts at line 13,996"
      - "longest method name length => 170 characters"
      - "sha256=9d9d086ffe3e163a08fa6aac13714f9e3168693c27a10eca0984122f4af6ed9c"
  - id: A1-SC-0010
    type: false_abstraction
    severity: s0
    detail: "The section extraction added indirection without transferring the public boundary or removing the old owner. AtlasSelfConstructionReadinessService keeps 109 one-line delegators, this section keeps an injected parent back-reference for cross-class work, and AtlasAiSelfConstructionMotherCommand separately maps all 109 names. The result is three synchronized surfaces around one unbounded lifecycle."
    evidence:
      - "AtlasSelfConstructionReadinessService reviewMergeSection delegator count => 109"
      - "AtlasAiSelfConstructionMotherCommand agent-review-merge route count => 109"
      - "constructor stores AtlasSelfConstructionReadinessService as parent: lines 23-25"
      - "class comment promises byte-identical dynamic dispatch and zero call-site changes: lines 7-20"
  - id: A1-SC-0011
    type: bug
    severity: s1
    detail: "Operational readiness is not computed from the evidence and blockers carried by most later payloads. The outer status usually becomes *_ready solely because the immediately preceding template was structurally emitted; for example writer-release preflight returns *_ready while its own payload is waiting for external evidence, has 13 blocking conditions, and says writer release is still unauthorized. Consumers can therefore mistake schema-chain reachability for executable readiness."
    evidence:
      - "writer-release preflight declares 13 concrete blockers: lines 4,903-4,921"
      - "inner status waits for external signed evidence: line 4,926"
      - "outer status ignores blocking_count and reports *_ready from templateReady alone: lines 4,980-4,994"
      - "107 outer status ternaries emit a *_ready variant; blocking_conditions appears in 30 methods"
  - id: A1-SC-0012
    type: dishonest_name
    severity: s1
    detail: "Late public surfaces are named HumanEscalation and ManualDecisionRequest and can report *_ready, but explicitly do not notify a human, create a task, or request a real decision. They are inert descriptions of hypothetical future behavior, not escalation or decision-request capabilities."
    evidence:
      - "human escalation policy says does_not_notify_human and does_not_create_task: lines 13,890-13,891"
      - "manual decision policy says does_not_request_real_decision, does_not_notify_human, and does_not_create_task: lines 14,062-14,064"
      - "manual decision flags remain false: lines 14,114-14,116 and 14,137-14,139"
      - "manual-decision outer status can still become *_ready: lines 14,119-14,141"
  - id: A1-SC-0013
    type: dupe
    severity: s2
    detail: "The file is an industrial template farm: 108 methods call another agentReviewMerge predecessor, 119 stable hashes are recomputed, and 110 near-identical non_execution_guarantees blocks repeat ever-longer negative capability lists. There are 232 lines containing future_ and 57 ready_as_future status strings instead of one declarative transition catalog with invariant-owned projectors."
    evidence:
      - "rg '$this->agentReviewMerge' => 108 predecessor calls"
      - "rg 'ReadinessHash::stable' => 119 occurrences"
      - "rg non_execution_guarantees => 110 occurrences"
      - "rg ready_as_future => 57 lines; rg future_ => 232 lines"
  - id: A1-SC-0014
    type: perf
    severity: s2
    detail: "A late projection recursively materializes and hashes its entire predecessor chain on every call, with no memoized projection context or visible latency/memory budget. A cold test-environment invocation of the final method took 240.242 ms and the booted process peaked at 93,323,264 PHP bytes; the result was still blocked and manually inert. This is a characterization signal, not an isolated production benchmark."
    evidence:
      - "108 predecessor calls form one deep reconstruction chain"
      - "cold diagnostic result: elapsed_ms=240.242, peak_bytes=93,323,264"
      - "diagnostic result status was blocked, execution_allowed=false, manual_decision_requested=false"
      - "119 array hashes are present across the section"
  - id: A1-SC-0015
    type: test_gap
    severity: s1
    detail: "The dedicated 47-line feature test proves only reflection count and schema_version reachability for agentReviewMergeActionTemplate and agentReviewMergeExecutionChecklist. It never invokes the writer-release/fresh-authorization/disable/later-cycle tail and asserts no status truth table, blocker propagation, stable hashes, evidence binding, or negative side effects."
    evidence:
      - "AtlasAiSelfConstructionReadinessProjectionAgentReviewMergeSectionTest has 3 tests and 5 assertions"
      - "only domain methods invoked are the methods beginning at source lines 33 and 668"
      - "no test occurrence of agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterRelease"
      - "focused test command passes, but exercises no late-chain method"
  - id: A1-SC-0016
    type: os_overlap
    severity: s0
    detail: "A complete review/merge and release-governance state machine is embedded under SelfConstruction/Readiness. It owns merge checks, authorization receipts, signature choreography, writer release, monitoring, disable, evidence repair, later-cycle authorization, and human escalation vocabulary while the parent readiness service and mother command remain parallel entrypoints. Review/merge governance needs a provider-neutral owner outside the Self-Construction readiness projection."
    evidence:
      - "109 CLI-visible agent-review-merge routes exist in AtlasAiSelfConstructionMotherCommand"
      - "method chain runs from merge action at line 33 to manual decision request at line 13,996"
      - "future writer release implementation and policy artifacts occupy most of the section"
      - "the only runtime collaborator consumer is the parent readiness facade; CLI calls through that facade, while the focused test references the section directly only for reflection"
actions:
  - op: TEST
    detail: "Characterize all 109 public aliases before changing structure. For every method freeze schema_version, status semantics, stable hash inputs, required evidence, blocker propagation, negative side effects, and dynamic-dispatch compatibility; include direct cases from the late writer-release and later-cycle tail."
    target_paths:
      - tests/Feature/Ai/AtlasAiSelfConstructionReadinessProjectionAgentReviewMergeSectionTest.php
      - tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php
    acceptance:
      - "/opt/homebrew/bin/php artisan test tests/Feature/Ai/AtlasAiSelfConstructionReadinessProjectionAgentReviewMergeSectionTest.php"
      - "every one of the 109 mapped methods is covered by a contract case or an explicitly generated data-provider row"
      - "tests fail when outer *_ready conflicts with non-empty blockers, missing evidence, or false authorization flags"
  - op: BUGFIX_PLAN
    detail: "Separate structural surface readiness from operational readiness and authorization. A generated template may be schema_ready, but operational_ready must fail closed on any blocker, missing external evidence, false signature/authorization flag, or inert dispatch flag; never expose bare *_ready for an unauthorized action."
    target_paths:
      - app/Services/Ai/SelfConstruction/Readiness/ReadinessProjectionAgentReviewMergeSection.php
      - app/Services/Ai/SelfConstruction/Readiness/AtlasSelfConstructionReadinessService.php
    acceptance:
      - "rg -n \"'status'.*ready\" app/Services/Ai/SelfConstruction/Readiness/ReadinessProjectionAgentReviewMergeSection.php"
      - "contract tests distinguish schema_ready, evidence_pending, authorized, executable, and completed"
      - "all missing evidence and non-empty blocker sets remain fail-closed"
  - op: SPLIT
    detail: "SPLIT before any fusion. Retire this mega-section behind a temporary compatibility adapter and create bounded review, authorization, signature, release, disable, repair, and escalation owners; no replacement PHP may exceed 2,000 LOC and hot public facades stay at or below 800 LOC."
    target_paths:
      - app/Services/Ai/SelfConstruction/Readiness/ReadinessProjectionAgentReviewMergeSection.php
      - app/Services/Ai/SelfConstruction/Readiness/AtlasSelfConstructionReadinessService.php
    acceptance:
      - "find app/Services/Ai/SelfConstruction -name '*.php' -print0 | xargs -0 wc -l | awk '$1 > 2000 {print}'"
      - "no new *Section class replaces this file with another mega-file"
      - "one-way dependencies replace the injected parent back-reference"
  - op: OWNER
    detail: "Assign review/merge governance to one provider-neutral capability owner and keep SelfConstruction readiness as a consumer. Document where policy, pure projection, external signature authority, persistence, dispatch, and human interaction belong before creating replacement paths."
    target_paths:
      - docs/evidence/2026-07-22-atlas-server-god-debulk/OWNERSHIP.md
      - docs/engineering-knowledge-base/CODEMAP.md
    acceptance:
      - "one owner is named for review/merge lifecycle and one for each I/O authority"
      - "no readiness projector claims notification, task creation, signature authority, persistence, or dispatch ownership"
  - op: EXTRACT
    detail: "Replace repeated future templates and negative guarantee arrays with a typed declarative transition catalog plus small pure projectors. Each state must declare actual input evidence, invariant checks, resulting state, and allowed authority; preserve hashes and payload aliases only where characterization proves a live consumer."
    target_paths:
      - app/Services/Ai/SelfConstruction/Readiness/ReadinessProjectionAgentReviewMergeSection.php
    acceptance:
      - "predecessor-chain call count falls from 108 to zero in the retired section"
      - "non_execution_guarantees are derived from one named policy, not repeated 110 times"
      - "no abstraction is accepted without a governing invariant or second consumer"
  - op: CODEMAP
    detail: "Map all 109 CLI routes and facade aliases to consumers, stability requirements, and future owners. Remove unmapped routes after one compatibility cycle; keep aliases thin and forbid 170-character capability names in the canonical API."
    target_paths:
      - app/Console/Commands/AtlasAiSelfConstructionMotherCommand.php
      - app/Services/Ai/SelfConstruction/Readiness/AtlasSelfConstructionReadinessService.php
      - docs/engineering-knowledge-base/CODEMAP.md
    acceptance:
      - "all 109 routes have owner, caller, contract test, and migration disposition"
      - "/opt/homebrew/bin/php artisan atlas:engineering:knowledge codemap-verify --json"
      - "temporary aliases are removed or dated for removal within one migration cycle"
  - op: PERF
    detail: "Benchmark first, middle, and final projections with cold and warm application state, query counts, allocations, and hash work. Introduce a request-scoped immutable projection context only after contract tests prove identical outputs; set explicit latency and memory budgets for CLI and status paths."
    target_paths:
      - app/Services/Ai/SelfConstruction/Readiness/ReadinessProjectionAgentReviewMergeSection.php
      - tests/Feature/Ai/AtlasAiSelfConstructionReadinessProjectionAgentReviewMergeSectionTest.php
    acceptance:
      - "benchmark reports cold/warm p50 and p95 for representative first, middle, and final methods"
      - "late projections do not rebuild unchanged predecessor arrays or rehash identical payloads"
      - "performance changes retain byte-compatible characterized hashes where required"
caps: [A, B, C, D, E, F, G]
evidence:
  read_mode: full_file_sequential_no_skipped_lines
  line_range_read: 1-14169
  syntax: "No syntax errors detected by /opt/homebrew/bin/php -l"
  source_modified_during_meta: false
  source_sha256: 9d9d086ffe3e163a08fa6aac13714f9e3168693c27a10eca0984122f4af6ed9c
  public_domain_method_count: 109
  parent_facade_delegation_count: 109
  cli_route_count: 109
  predecessor_call_count: 108
  stable_hash_occurrence_count: 119
  non_execution_guarantee_block_count: 110
  blocking_conditions_key_count: 30
  outer_ready_status_ternary_count: 107
  focused_test_count: 3
  focused_test_assertion_count: 5
  next_file_by_loc: app/Services/Ai/SelfConstruction/Readiness/ReadinessProjectionAgentCodexSection.php
```

```yaml
path: app/Services/Ai/SelfConstruction/Readiness/ReadinessProjectionAgentCodexSection.php
loc: 13784
kind: generated_provider_execution_future_contract_chain_section
intent_axes: [2, 3, 5, 6, 7, 10, 14, 15, 16, 17, 18, 19, 20, 21, 22, 24, 25, 27, 28, 29, 33, 34, 36, 37, 38, 40, 41, 42, 49, 50, 54, 55, 62, 64, 65, 66, 67, 68, 69]
findings:
  - id: A1-SC-0017
    type: godfile
    severity: s0
    detail: "The extracted Codex collaborator is itself a 13,784 LOC, 1,095,709-byte godfile with 171 public methods and 63 imported collaborators. It owns the complete provider execution, process start, spawn, runtime, authorization, receipt, evidence, dispatch, release, enablement, supervised-start, and operator-handoff lifecycle, so the extraction still cannot be understood or changed as a bounded module."
    evidence:
      - "wc -l => 13,784; wc -c => 1,095,709"
      - "public agentCodex method count => 171"
      - "imported collaborator count => 63"
      - "longest method name length => 84 characters"
      - "sha256=5039dd71e3acae11707fe74127d1f5d77408b9e0f1cfe89578c81fc161f94df7"
  - id: A1-SC-0018
    type: false_abstraction
    severity: s0
    detail: "The section extraction preserved three synchronized public surfaces instead of transferring ownership: this file implements 171 methods, AtlasSelfConstructionReadinessService retains 171 one-line delegators, and AtlasAiSelfConstructionMotherCommand maps 171 agent-codex routes. The class comment explicitly promises byte-identical delegation and zero semantic boundary, making the new class an additional routing layer around the same unbounded API."
    evidence:
      - "class comment declares ownership of every agentCodex method and thin byte-identical delegation: lines 7-14"
      - "AtlasSelfConstructionReadinessService agentCodexSection delegator count => 171"
      - "AtlasAiSelfConstructionMotherCommand agent-codex route count => 171"
      - "only three files directly reference ReadinessProjectionAgentCodexSection: the section, parent facade, and focused reflection test"
  - id: A1-SC-0019
    type: bug
    severity: s0
    detail: "Every executable preflight path is broken after extraction because the class calls Schema::hasTable 121 times without importing Illuminate\\Support\\Facades\\Schema. PHP resolves the name to the nonexistent App\\Services\\Ai\\SelfConstruction\\Readiness\\Schema, so an actual late-method diagnostic aborts before producing any readiness result even though syntax and the focused test are green."
    evidence:
      - "imports occupy lines 16-78 and contain no Schema import"
      - "first unresolved Schema::hasTable calls occur at lines 212-213"
      - "Schema::hasTable occurrence count => 121"
      - "runtime resolution: namespaced_schema_exists=false; laravel_schema_exists=true"
      - "late implementation-packet invocation fatal: Class App\\Services\\Ai\\SelfConstruction\\Readiness\\Schema not found"
  - id: A1-SC-0020
    type: bug
    severity: s1
    detail: "The post-start real-invoker release contract hashes a nonexistent upstream key named codex_real_invoker_release_preflight_preflight_hash. The upstream preflight actually emits codex_real_invoker_release_preflight_hash, so the contract id silently binds null instead of the release-preflight evidence and cannot change when that evidence changes."
    evidence:
      - "upstream emitted hash key is codex_real_invoker_release_preflight_hash: line 2,706"
      - "normal signed-release consumer reads the emitted key: lines 2,842 and 2,847"
      - "post-start contract reads nonexistent doubled-preflight key: line 11,730"
      - "no producer for codex_real_invoker_release_preflight_preflight_hash exists in the file"
  - id: A1-SC-0021
    type: bug
    severity: s1
    detail: "The post-start evidence contracts define a circular prerequisite: receipt builder and evidence writer require post_start_evidence_acceptance_bridge_id, while the acceptance bridge itself requires the outputs of both preflights. The receipt builder also lists the bridge id twice in one input contract. A strict implementation cannot create the prerequisite artifacts without already possessing the id produced by their downstream bridge."
    evidence:
      - "receipt input repeats post_start_evidence_acceptance_bridge_id at lines 7,403 and 7,406"
      - "receipt preflight requires the bridge from operator handoff: line 7,533"
      - "evidence-writer input requires the bridge id: line 7,717"
      - "acceptance bridge invokes receipt and evidence-writer preflights as prerequisites: lines 8,002-8,003"
      - "acceptance bridge is the contract whose result produces post_start_evidence_acceptance_bridge_id: lines 8,020-8,050"
  - id: A1-SC-0022
    type: dishonest_name
    severity: s1
    detail: "The API repeatedly names stages execution, release, enablement, authorization, final, actual, and post-start while every one of the 171 public envelopes hard-codes execution_allowed=false and dispatch_allowed=false. After external-start evidence appears, 102 PostStart methods replay most of the pre-start chain and keep adding requires_separate_* successor contracts; this is a future-gate treadmill, not an executable provider boundary."
    evidence:
      - "171 execution_allowed=false envelopes and 171 dispatch_allowed=false envelopes"
      - "102 public PostStart methods span lines 7,380-13,756"
      - "requires_separate_ occurs 133 times"
      - "future readiness/file markers occur 347 times"
      - "final tail still says post-start receipt contract is a separate later contract: lines 13,762-13,783"
  - id: A1-SC-0023
    type: perf
    severity: s2
    detail: "Each later method recursively rebuilds predecessor preflights, embeds their arrays, probes the same schema tables, and hashes the compound payload again. The file contains 194 internal agentCodex calls, 229 stable hashes, and 121 schema probes with no request-scoped projection context or budgets; a representative late call cannot yet be benchmarked because the missing Schema import aborts first."
    evidence:
      - "internal $this->agentCodex call count => 194"
      - "ReadinessHash::stable occurrence count => 229"
      - "Schema::hasTable occurrence count => 121"
      - "late chains carry source_*_preflight arrays into the next template and output"
      - "performance characterization is blocked honestly by the fatal unresolved Schema class"
  - id: A1-SC-0024
    type: test_gap
    severity: s1
    detail: "The dedicated 88-line feature test passes 5 tests and 10 assertions but executes none of the 171 domain methods. It checks construction, a standalone ReadinessHash call, reflection counts, method_exists for only the first and last names, and lazy resolver wiring, so it missed the universal Schema fatal, the null hash input, the circular bridge contract, status semantics, side effects, and predecessor-chain cost."
    evidence:
      - "focused command passes: 5 tests, 10 assertions"
      - "test domain method names appear only inside method_exists lists: lines 53-62 and 69-77"
      - "no ContractTemplate, Preflight, or ImplementationPacket is invoked"
      - "the only hash exercised is ReadinessHash::stable on a local fixture: lines 29-35"
  - id: A1-SC-0025
    type: os_overlap
    severity: s0
    detail: "A complete Codex-specific execution OS is embedded inside SelfConstruction/Readiness: 57 ContractTemplate/Preflight/ImplementationPacket triads model provider execution through external process lifecycle, evidence acceptance, dispatch, release, process activation, and human operator handoff. Provider-neutral orchestration, runtime I/O, policy, evidence, and projection need separate owners; readiness should consume their state instead of defining a parallel operating system."
    evidence:
      - "57 ContractTemplate + 57 Preflight + 57 ImplementationPacket public methods"
      - "provider-execution chain begins at line 83"
      - "post-start evidence/dispatch chain begins at line 7,380"
      - "replayed process lifecycle continues through the final method at line 13,756"
      - "171 CLI routes expose the whole provider-specific state machine"
actions:
  - op: BUGFIX_PLAN
    detail: "First restore truthful reachability under characterization: import the Laravel Schema facade, correct the doubled preflight hash key, remove the duplicate bridge input, and redesign the receipt/evidence/acceptance dependency direction so an id is produced before downstream artifacts require it."
    target_paths:
      - app/Services/Ai/SelfConstruction/Readiness/ReadinessProjectionAgentCodexSection.php
      - tests/Feature/Ai/AtlasAiSelfConstructionReadinessProjectionAgentCodexSectionTest.php
    acceptance:
      - "/opt/homebrew/bin/php artisan test tests/Feature/Ai/AtlasAiSelfConstructionReadinessProjectionAgentCodexSectionTest.php"
      - "a first, middle, and final preflight execute without unresolved-class errors"
      - "release-preflight contract id changes when the real upstream preflight hash changes"
      - "receipt, evidence receipt, and acceptance bridge form an acyclic producer-consumer graph"
  - op: TEST
    detail: "Characterize all 171 public aliases before structural changes. Freeze schema_version, status, stable hash inputs, required evidence, blocker propagation, negative side effects, and facade/CLI compatibility; include explicit regression cases for the Schema fatal, bad hash key, and bridge cycle."
    target_paths:
      - tests/Feature/Ai/AtlasAiSelfConstructionReadinessProjectionAgentCodexSectionTest.php
      - tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php
    acceptance:
      - "every one of the 171 methods has a contract case or an explicit generated data-provider row"
      - "tests execute each method rather than relying on reflection or method_exists"
      - "tests fail on missing imports, unknown hash keys, cyclic prerequisites, or execution/readiness contradictions"
  - op: SPLIT
    detail: "SPLIT before fusion. Retire the mega-section behind a temporary compatibility adapter and create bounded owners for provider contract projection, pre-start authorization, runtime start, post-start evidence, dispatch, release/activation, and operator handoff; no replacement PHP may exceed 2,000 LOC and hot facades stay at or below 800 LOC."
    target_paths:
      - app/Services/Ai/SelfConstruction/Readiness/ReadinessProjectionAgentCodexSection.php
      - app/Services/Ai/SelfConstruction/Readiness/AtlasSelfConstructionReadinessService.php
    acceptance:
      - "find app/Services/Ai/SelfConstruction -name '*.php' -print0 | xargs -0 wc -l | awk '$1 > 2000 {print}'"
      - "no new *Section class replaces this file with another mega-file"
      - "the 171 parent aliases are thin, characterized, and dated for removal within one migration cycle"
  - op: OWNER
    detail: "Assign provider-neutral ownership for execution state, runtime I/O, evidence acceptance, dispatch authorization, and operator interaction. Codex becomes an adapter; SelfConstruction readiness becomes a read-only projection consumer and must not own process start or dispatch policy."
    target_paths:
      - docs/evidence/2026-07-22-atlas-server-god-debulk/OWNERSHIP.md
      - docs/engineering-knowledge-base/CODEMAP.md
    acceptance:
      - "one owner is named for each policy, projection, persistence, process I/O, evidence, dispatch, and human-interaction authority"
      - "dependency arrows are one-way from readiness projection to owner interfaces"
      - "no provider-specific readiness class defines a parallel end-to-end execution OS"
  - op: EXTRACT
    detail: "Replace the 57 handwritten triads and successor treadmill with a typed declarative state-transition catalog plus small invariant-owned projectors. Model structural availability, evidence readiness, authorization, executability, and completion as distinct states; preserve public hashes only where characterization proves a live compatibility consumer."
    target_paths:
      - app/Services/Ai/SelfConstruction/Readiness/ReadinessProjectionAgentCodexSection.php
    acceptance:
      - "execution transitions form an acyclic graph with explicit evidence producers"
      - "negative guarantees and required schema probes are derived once per projection context"
      - "no abstraction is accepted without a governing invariant or second consumer"
  - op: CODEMAP
    detail: "Map all 171 facade aliases and 171 CLI routes to callers, stability requirements, and future owner methods. Collapse the documented command surface into bounded capability families and remove dead routes after the compatibility cycle."
    target_paths:
      - app/Console/Commands/AtlasAiSelfConstructionMotherCommand.php
      - app/Services/Ai/SelfConstruction/Readiness/AtlasSelfConstructionReadinessService.php
      - docs/engineering-knowledge-base/CODEMAP.md
    acceptance:
      - "all 171 methods and routes have owner, caller, contract test, and migration disposition"
      - "/opt/homebrew/bin/php artisan atlas:engineering:knowledge codemap-verify --json"
      - "canonical command families remain at or below 12 documented entrypoints"
  - op: PERF
    detail: "After correctness is restored, benchmark representative first, middle, evidence-bridge, and final projections with cold/warm state, query counts, allocations, payload bytes, and hash work. Introduce one immutable request-scoped projection context only after byte-compatibility tests prove unchanged required outputs."
    target_paths:
      - app/Services/Ai/SelfConstruction/Readiness/ReadinessProjectionAgentCodexSection.php
      - tests/Feature/Ai/AtlasAiSelfConstructionReadinessProjectionAgentCodexSectionTest.php
    acceptance:
      - "benchmark reports cold/warm p50 and p95 plus query, peak-memory, and payload-size budgets"
      - "each schema table is probed at most once per projection context"
      - "late projections do not rebuild or rehash unchanged predecessor payloads"
caps: [A, B, C, D, E, F, G]
evidence:
  read_mode: full_file_sequential_no_skipped_lines
  line_range_read: 1-13784
  syntax: "No syntax errors detected by /opt/homebrew/bin/php -l"
  source_modified_during_meta: false
  source_sha256: 5039dd71e3acae11707fe74127d1f5d77408b9e0f1cfe89578c81fc161f94df7
  public_domain_method_count: 171
  contract_template_method_count: 57
  preflight_method_count: 57
  implementation_packet_method_count: 57
  parent_facade_delegation_count: 171
  cli_route_count: 171
  imported_collaborator_count: 63
  internal_agent_codex_call_count: 194
  stable_hash_occurrence_count: 229
  schema_probe_occurrence_count: 121
  non_execution_guarantee_block_count: 171
  post_start_public_method_count: 102
  focused_test_count: 5
  focused_test_assertion_count: 10
  runtime_characterization: "blocked by fatal unresolved App\\Services\\Ai\\SelfConstruction\\Readiness\\Schema"
  next_file_by_loc: app/Services/Ai/SelfConstruction/Readiness/ReadinessProjectionCodexReviewMergeSection.php
```

```yaml
path: app/Services/Ai/SelfConstruction/Readiness/ReadinessProjectionCodexReviewMergeSection.php
loc: 12751
kind: generated_codex_review_merge_future_contract_chain_section
intent_axes: [2, 3, 5, 6, 7, 10, 14, 15, 16, 17, 18, 19, 20, 21, 22, 24, 25, 27, 28, 29, 33, 34, 36, 37, 38, 40, 41, 42, 49, 50, 54, 55, 62, 64, 65, 66, 67, 68, 69]
findings:
  - id: A1-SC-0026
    type: godfile
    severity: s0
    detail: "The extracted final collaborator is itself a 12,751 LOC, 1,646,900-byte godfile with 149 public codexReviewMerge methods. It spans merge authorization, signatures, receipt persistence, writer release, disable/re-enable, observability, evidence repair, later-cycle authorization, human decision, activation, and session bootstrap previews; its longest public name is 204 characters."
    evidence:
      - "wc -l => 12,751; wc -c => 1,646,900"
      - "public codexReviewMerge method count => 149"
      - "66 methods are handwritten chains and 83 are generic later-cycle aliases"
      - "longest public method name length => 204 characters"
      - "sha256=9e1fa9beb3811b238710e847c09a8468397c8edca9d9484a0e901ad62e534f72"
  - id: A1-SC-0027
    type: false_abstraction
    severity: s0
    detail: "The split preserves a bidirectional god boundary instead of transferring ownership. AtlasSelfConstructionReadinessService retains 149 one-line delegators; this section injects that parent and forwards six unresolved dependencies through unrestricted __call; the late 83-method surface is then another routing layer over a private positional-tag interpreter. Missing boundaries remain runtime conventions rather than typed dependencies."
    evidence:
      - "AtlasSelfConstructionReadinessService codexReviewMergeSection delegator count => 149"
      - "nullable parent injection and app fallback: lines 20-35"
      - "unrestricted __call forwards to the parent facade: lines 37-47"
      - "six statically unresolved $this calls cross back to the parent: codexExecutionStatus, codexFinalReviewPacket, codexIntegrationReport, codexMergeReadiness, codexReviewPostSignatureRunbook, codexReviewSignatureRequest"
      - "83 public aliases route through laterCycleChainProjection: lines 6,862-7,275"
  - id: A1-SC-0028
    type: bug
    severity: s1
    detail: "Envelope readiness means only that the predecessor template was emitted, not that the enclosed contract is usable. In the generic tail all 83 envelope success branches are *_ready, while the corresponding payload success branch is ready_as_future_* in 82 specs and explicitly blocked_waiting_for_external_* in one. The handwritten writer-release execution contract has the same contradiction: its inner contract is blocked with blockers while the outer envelope reports *_ready."
    evidence:
      - "generic descriptor characterization: specs=83, outer_ready=83, inner_ready_as_future=82, inner_blocked=1"
      - "generic new-cycle execution contract is internally blocked_waiting_for_external_fresh_authorization_new_cycle_execution_authority: lines 7,703-7,726"
      - "the same descriptor emits an outer *_execution_contract_template_ready status: lines 7,737-7,750"
      - "handwritten writer-release contract carries blocking_conditions and blocked_waiting_for_external_writer_release_execution_authority: lines 4,845-4,870"
      - "its envelope reports *_execution_contract_template_ready solely from preflightReady: lines 4,933-4,947"
  - id: A1-SC-0029
    type: dishonest_name
    severity: s1
    detail: "The late API names operational capabilities that it explicitly refuses to perform. HumanEscalation does not notify a human or create a task; ManualDecisionRequest does not request a real decision; session packet/scope/start/prompt/readiness previews do not create packets, assign work, allow edits, start a session, or mark it ready. These surfaces can still advertise structural readiness, so names overstate authority."
    evidence:
      - "human escalation negative policy is encoded at lines 9,539-9,579"
      - "manual decision request explicitly does not request a real decision, notify, or create a task: lines 9,599-9,641"
      - "packet scope and start previews forbid packet/task creation, assignment, and file edits: lines 12,388-12,550"
      - "operator prompt does not start a session: lines 12,570-12,646"
      - "ready-check preview does not mark the session ready: lines 12,666-12,746"
  - id: A1-SC-0030
    type: dupe
    severity: s2
    detail: "This is an industrial provider-labelled template farm. It contains 149 repeated non_execution_guarantees envelopes, 3,958 literal false assignments, 97 ready_as_future occurrences, and 79 stable-hash calls. After normalizing only the agent/codex prefix, all 109 methods from ReadinessProjectionAgentReviewMergeSection also exist here; 73.2% of this class's public capability names overlap that sibling."
    evidence:
      - "non_execution_guarantees key count => 149"
      - "literal => false assignment count => 3,958"
      - "ready_as_future occurrence count => 97; ReadinessHash::stable call count => 79"
      - "normalized cross-provider method overlap => 109 of 149 Codex methods; agent-only=0, Codex-only=40"
      - "the two provider review/merge sections total 26,920 LOC"
  - id: A1-SC-0031
    type: perf
    severity: s2
    detail: "Each later projection recursively reconstructs its predecessor chain, re-embeds arrays, resolves the descriptor tree, and hashes a new payload without a request-scoped projection context. The focused full-corpus test needs 10.00 seconds for the golden corpus; a single-process diagnostic of first/middle/final methods peaked at 126,877,696 PHP bytes. These are characterization signals, not production benchmarks."
    evidence:
      - "laterCycleChainProjection invokes the predecessor and rebuilds extracted variables/payload/envelope on every call: lines 7,282-7,301"
      - "83 aliases recurse through 83 predecessor descriptors"
      - "focused blocked-corpus test duration => 10.00s; full focused test duration => 10.71s"
      - "diagnostic first/middle/final elapsed_ms => 188.806 / 78.426 / 69.614; process peak_bytes=126,877,696"
      - "diagnostic payload bytes => 3,539 / 7,303 / 15,631"
  - id: A1-SC-0032
    type: test_gap
    severity: s1
    detail: "The dedicated test executes all 149 methods and pins one blocked-state corpus hash, but it asserts no semantic relationship between outer status, inner status, blocking_count, authority flags, and namesake behavior. The golden hash therefore detects byte drift while blessing the current ready-versus-blocked contradiction and inert escalation/session surfaces."
    evidence:
      - "focused test passes 6 tests and 14 assertions"
      - "the corpus loop invokes every reflected codexReviewMerge method: test lines 101-115"
      - "the only corpus contract assertions are count=149 and one SHA-256: test lines 37-47"
      - "remaining checks cover resolution, ReadinessHash determinism, method existence, and facade wiring"
      - "golden corpus hash=ced5b7d5b0ef5dd110a5687c9f0a61fc3d3cf057afe19b735a1dc5f78ac67f17"
  - id: A1-SC-0033
    type: os_overlap
    severity: s0
    detail: "A second end-to-end review/merge operating system is embedded in SelfConstruction/Readiness. It models authorization, signature ceremony, append-only persistence, writer lifecycle, disable/re-enable, monitoring, repair, human escalation, durable decision records, activation, scope/work intake, packet creation, and session readiness. Readiness should consume provider-neutral owners for those capabilities rather than define a Codex-specific parallel OS."
    evidence:
      - "initial merge-action chain begins at line 49"
      - "writer execution/disable/observability chain is present around lines 4,845-5,500"
      - "83-spec later-cycle catalog spans lines 7,339-12,750"
      - "human decision chain appears around lines 9,539-10,500"
      - "activation/session bootstrap previews occupy the final catalog through line 12,750"
actions:
  - op: BUGFIX_PLAN
    detail: "Separate structural projection availability from evidence readiness, external authorization, executability, and completion. An envelope must not emit *_ready when its enclosed payload is blocked, future-only, has blocking_count > 0, or keeps the required authority false; preserve legacy strings only behind characterized compatibility aliases."
    target_paths:
      - app/Services/Ai/SelfConstruction/Readiness/ReadinessProjectionCodexReviewMergeSection.php
      - tests/Feature/Ai/AtlasAiSelfConstructionReadinessProjectionCodexReviewMergeSectionTest.php
    acceptance:
      - "all outer ready statuses imply zero blockers and the required authority state"
      - "blocked or ready_as_future inner payloads cannot be wrapped as operationally ready"
      - "first, handwritten execution-contract, generic execution-contract, human-decision, and final-session regressions are explicit"
  - op: TEST
    detail: "Retain the 149-method corpus hash as compatibility evidence, then add semantic matrix assertions per transition family: envelope/payload status agreement, blocker propagation, negative side effects, authority flags, required evidence, stable hash inputs, and truthful namesake behavior."
    target_paths:
      - tests/Feature/Ai/AtlasAiSelfConstructionReadinessProjectionCodexReviewMergeSectionTest.php
      - tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php
    acceptance:
      - "all 149 methods have a semantic family row in addition to snapshot coverage"
      - "tests fail when an outer status is ready while the inner payload is blocked or future-only"
      - "escalation, decision, packet, and session names are either real effects or explicitly named previews"
  - op: SPLIT
    detail: "SPLIT before fusion. Retire the mega-section behind a temporary compatibility adapter and create bounded owners for merge review, authorization/signatures, receipt persistence, writer lifecycle, monitoring/repair, human decisions, activation, and session intake; no replacement PHP may exceed 2,000 LOC and hot facades stay at or below 800 LOC."
    target_paths:
      - app/Services/Ai/SelfConstruction/Readiness/ReadinessProjectionCodexReviewMergeSection.php
      - app/Services/Ai/SelfConstruction/Readiness/AtlasSelfConstructionReadinessService.php
    acceptance:
      - "find app/Services/Ai/SelfConstruction -name '*.php' -print0 | xargs -0 wc -l | awk '$1 > 2000 {print}'"
      - "no new *Section mega-file replaces this class"
      - "parent-to-section and section-to-parent dependencies are one-way or removed"
  - op: OWNER
    detail: "Make review/merge lifecycle ownership provider-neutral. Assign one authority each for signature validation, authorization, persistence, writer release, disable/re-enable, observability, human interaction, decision records, activation, packet creation, and session readiness; Codex remains an adapter and readiness only projects owner state."
    target_paths:
      - docs/evidence/2026-07-22-atlas-server-god-debulk/OWNERSHIP.md
      - docs/engineering-knowledge-base/CODEMAP.md
    acceptance:
      - "one owner is named for every policy, state transition, durable write, provider adapter, and human side effect"
      - "readiness projections cannot grant authority or simulate namesake execution"
      - "the normalized Agent/Codex review-merge duplication has one canonical owner"
  - op: EXTRACT
    detail: "Replace the 83-entry positional tag DSL and 66 handwritten predecessor chains with a typed, validated transition catalog plus small invariant-owned projectors. Validate descriptor shape at construction, reject unknown tags/keys/methods, compute shared predecessor state once, and preserve hashes only where a live consumer requires compatibility."
    target_paths:
      - app/Services/Ai/SelfConstruction/Readiness/ReadinessProjectionCodexReviewMergeSection.php
    acceptance:
      - "catalog validation proves every transition, predecessor, payload schema, and status invariant"
      - "unknown methods or malformed tags fail with typed domain errors rather than array-key/type errors"
      - "no abstraction is accepted without a governing invariant or second consumer"
  - op: CODEMAP
    detail: "Map all 149 facade aliases and command cases to callers, status consumers, hash stability requirements, side effects, and future owner methods. Remove unmapped routes after one compatibility cycle and replace 204-character canonical names with bounded capability vocabulary."
    target_paths:
      - app/Console/Commands/AtlasAiSelfConstructionMotherCommand.php
      - app/Services/Ai/SelfConstruction/Readiness/AtlasSelfConstructionReadinessService.php
      - docs/engineering-knowledge-base/CODEMAP.md
    acceptance:
      - "all 149 methods have owner, caller, semantic contract, and migration disposition"
      - "/opt/homebrew/bin/php artisan atlas:engineering:knowledge codemap-verify --json"
      - "temporary aliases are dated for removal within one migration cycle"
  - op: PERF
    detail: "Benchmark representative first, middle, and final projections with cold/warm state, query counts, allocations, payload bytes, and hash work. Introduce one immutable request-scoped projection context only after semantic and byte-compatibility characterization defines what must remain stable."
    target_paths:
      - app/Services/Ai/SelfConstruction/Readiness/ReadinessProjectionCodexReviewMergeSection.php
      - tests/Feature/Ai/AtlasAiSelfConstructionReadinessProjectionCodexReviewMergeSectionTest.php
    acceptance:
      - "benchmark reports cold/warm p50 and p95 plus peak-memory and payload-size budgets"
      - "late projections do not rebuild or rehash unchanged predecessor payloads"
      - "the full 149-method corpus stays within an explicit test and CLI latency budget"
caps: [A, B, C, D, E, F, G]
evidence:
  read_mode: full_file_sequential_no_skipped_lines
  line_range_read: 1-12751
  syntax: "No syntax errors detected by /opt/homebrew/bin/php -l"
  source_modified_during_meta: false
  source_sha256: 9e1fa9beb3811b238710e847c09a8468397c8edca9d9484a0e901ad62e534f72
  source_bytes: 1646900
  public_domain_method_count: 149
  handwritten_chain_method_count: 66
  later_cycle_alias_count: 83
  later_cycle_descriptor_count: 83
  parent_facade_delegation_count: 149
  unresolved_parent_dependency_count: 6
  stable_hash_occurrence_count: 79
  non_execution_guarantee_block_count: 149
  literal_false_assignment_count: 3958
  ready_as_future_occurrence_count: 97
  normalized_agent_review_merge_overlap_count: 109
  focused_test_count: 6
  focused_test_assertion_count: 14
  focused_test_duration_seconds: 10.71
  focused_golden_corpus_hash: ced5b7d5b0ef5dd110a5687c9f0a61fc3d3cf057afe19b735a1dc5f78ac67f17
  next_file_by_loc: app/Services/Ai/SelfConstruction/Readiness/ReadinessProjectionAgentAutomaticDispatchBatch1Section.php
```

```yaml
path: app/Services/Ai/SelfConstruction/Readiness/ReadinessProjectionAgentAutomaticDispatchBatch1Section.php
loc: 6198
kind: arbitrary_automatic_dispatch_scheduler_contract_and_status_batch_section
intent_axes: [2, 3, 5, 6, 7, 10, 14, 15, 16, 17, 18, 19, 20, 21, 22, 23, 24, 25, 27, 28, 29, 33, 34, 35, 36, 37, 38, 40, 41, 42, 49, 50, 54, 55, 62, 64, 65, 66, 67, 68, 69]
findings:
  - id: A1-SC-0034
    type: godfile
    severity: s0
    detail: "The extracted Batch1 collaborator is itself a 6,198 LOC, 497,732-byte godfile with 50 domain-public methods and 228 imports. It combines scheduler policy, database-backed status queries, implementation packets, guarded-writer contracts, release receipts, operator handoff, executor enablement, provider start, and a long Codex post-start gate chain; its longest public name is 110 characters."
    evidence:
      - "wc -l -c => 6,198 LOC and 497,732 bytes"
      - "52 public methods total: setMother, __call, and 50 domain methods"
      - "228 imports; 90 referenced in the class body and 138 unused after extraction"
      - "longest public method name length => 110 characters"
      - "sha256=6704e09160fee75d9d7dcbd53c7e0b3f89418ee4ea098017b6c03e031ad97416"
  - id: A1-SC-0035
    type: false_abstraction
    severity: s0
    detail: "Batch1 is a mechanical slice, not an ownership boundary. The parent facade retains 50 delegators, injects itself through setMother, and this section resolves 56 absent method names across 107 calls via unrestricted ReflectionMethod forwarding. The section can invoke non-public parent methods and its behavior still depends bidirectionally on the god facade and other extracted sections."
    evidence:
      - "AtlasSelfConstructionReadinessService retains 50 agentAutomaticDispatchBatch1Section forwards"
      - "nullable mother injection and unrestricted __call: lines 241-258"
      - "static call inventory => 56 unresolved method names and 107 unresolved call occurrences"
      - "ReflectionMethod is constructed from arbitrary $name and invoked on the parent"
      - "138 of 228 imports are unused, consistent with a mechanical source split"
  - id: A1-SC-0036
    type: bug
    severity: s0
    detail: "The extracted graph is not executable through most of this section's public contract routes. Runtime characterization of all 20 methods that hard-code a *_ready contract status found that 19 abort before returning: calls proxied through the parent enter ReadinessProjectionAgentCodexSection and fail on either an undefined agentProviderAdapterRegistryPreflight method or an unimported namespaced Schema class. Focused command tests fail with zero assertions, so the advertised Batch1 surface is broadly unavailable."
    evidence:
      - "runtime characterization => 1 of 20 hard-coded-ready contract methods returned; 19 of 20 threw"
      - "policy command test exits 2 with undefined method ReadinessProjectionAgentCodexSection::agentProviderAdapterRegistryPreflight at line 85"
      - "executor-enablement contract command test exits 2 with the same undefined method and zero assertions"
      - "provider-start contract command test exits 2 with missing App\\Services\\Ai\\SelfConstruction\\Readiness\\Schema at AgentCodexSection line 8,363"
      - "the parent facade has agentProviderAdapterRegistryPreflight, but the sibling section has no forwarding boundary"
  - id: A1-SC-0037
    type: bug
    severity: s1
    detail: "The scheduler policy tests three parent-owned capabilities with method_exists($this, ...). Magic __call does not make method_exists true, so dispatch_preflight_contract, dispatch_receipt_writer, and dispatch_executor_preflight_contract are permanently false on this section. Even after the earlier cross-section fatal is repaired, the policy will retain three artificial *_not_ready blockers and cannot become ready."
    evidence:
      - "component readiness checks method_exists($this, ...) for all three capabilities: lines 1,795-1,797"
      - "isolated section method_exists results => false / false / false"
      - "isolated parent facade method_exists results => true / true / true"
      - "blocking reasons are generated for every false component at lines 1,799-1,802"
  - id: A1-SC-0038
    type: doc_lie
    severity: s1
    detail: "The guarded-runtime implementation packet declares itself ready and sets implementation_allowed_by_packet=true while two of its six allowed_files paths do not exist because both classes moved into subdirectories. The focused test passes while explicitly requiring one stale path, so the executable work order and its proof agree with each other but not with the repository."
    evidence:
      - "packet hard-codes ready_for_scoped_* at line 2,786 and implementation_allowed_by_packet=true at line 2,839"
      - "missing declared path: app/Services/Ai/SelfConstruction/AgentAutomaticDispatchSchedulerOneShotTickGuardedRuntimeInvoker.php"
      - "actual invoker path: app/Services/Ai/SelfConstruction/ControlPlane/AgentAutomaticDispatchSchedulerOneShotTickGuardedRuntimeInvoker.php"
      - "missing declared path: app/Services/Ai/SelfConstruction/AtlasSelfConstructionReadinessService.php"
      - "actual readiness facade path: app/Services/Ai/SelfConstruction/Readiness/AtlasSelfConstructionReadinessService.php"
      - "focused packet test passes 1 test / 20 assertions and asserts the stale invoker path"
  - id: A1-SC-0039
    type: dishonest_name
    severity: s1
    detail: "Twenty Contract methods hard-code a *_ready outer status without deriving it from the predecessor statuses copied into the payload. A caller therefore cannot treat 'contract ready' as an invariant that upstream evidence, release, writer, executor, provider, or process-start prerequisites are ready; the status proves only that an array template was emitted."
    evidence:
      - "exact literal 'status' => '*_ready' count => 20"
      - "executor-enablement contract hard-codes ready at line 657 while copying source statuses"
      - "provider-start-driver contract hard-codes ready at line 2,410 while copying source statuses"
      - "outer envelopes keep execution/provider/token authority false, so readiness has no single operational meaning"
  - id: A1-SC-0040
    type: dupe
    severity: s2
    detail: "The section is a hand-expanded template farm: 21 Contract, 16 Preflight, and 10 Status methods repeatedly copy predecessor payloads, enumerate false authorities, build non-execution guarantees, compute a stable hash, and return another envelope. It contains 834 literal false assignments, 50 non_execution_guarantees blocks, and 50 stableHash calls."
    evidence:
      - "public method suffix inventory => Contract 21, Preflight 16, Status 10, ImplementationPacket 1"
      - "literal => false count => 834"
      - "non_execution_guarantees count => 50"
      - "$this->stableHash occurrence count => 50"
      - "the 50 method names all repeat one scheduler/provider lifecycle vocabulary"
  - id: A1-SC-0041
    type: perf
    severity: s2
    detail: "Status projection repeatedly probes schema and issues independent model queries with no visible request-scoped snapshot or query budget. The file contains 60 Schema::hasTable checks, 22 query builders, 22 count calls, and eight first calls; nested status/contract chains can repeat those checks while rebuilding and hashing large predecessor arrays."
    evidence:
      - "Schema::hasTable occurrence count => 60"
      - "::query() occurrence count => 22; ->count() => 22; ->first() => 8"
      - "contract and preflight methods recursively call earlier projection methods before hashing new envelopes"
      - "no cache, immutable projection context, or query-budget boundary exists in this section"
  - id: A1-SC-0042
    type: test_gap
    severity: s1
    detail: "All 50 domain methods are exposed as flags in one giant command test, but no test directly characterizes the section boundary, reflection forwarding, method-existence semantics, or readiness invariants. Current focused tests can abort before assertions, while the passing implementation-packet test freezes a nonexistent path. Route presence and snapshot strings are therefore standing in for behavior proof."
    evidence:
      - "all 50 domain methods map to parent facade command routes and all 50 flags occur in AtlasAiSelfConstructionCommandTest.php"
      - "no test directly references ReadinessProjectionAgentAutomaticDispatchBatch1Section or its method names"
      - "scheduler-policy and executor-enablement focused tests currently fail with zero assertions"
      - "implementation-packet test passes while asserting the stale root-level invoker path"
  - id: A1-SC-0043
    type: os_overlap
    severity: s0
    detail: "A complete automatic-dispatch operating system is embedded inside Readiness: scheduler selection and runtime gates, wakeup/control-plane tables, writer and receipt stages, guarded invocation, release, operator handoff, executor plans and enablement, provider start, adapter execution, liveness/output/cost bindings, and process-start authorization. These transitions and authorities need provider-neutral runtime owners; Readiness should project their evidence rather than define the lifecycle."
    evidence:
      - "scheduler policy and runtime execution gate begin around lines 1,783-2,029"
      - "guarded invocation preflight/packet/status/contract span lines 2,637-3,712"
      - "manual receipt and operator handoff contracts span lines 3,251-3,819"
      - "executor, release, provider, adapter, and process-start gates continue through line 6,198"
      - "the same section mixes Eloquent/Schema read models with future mutation and authority contracts"
actions:
  - op: BUGFIX_PLAN
    detail: "Restore executable dependency boundaries before preserving any Batch1 output: replace sibling-to-sibling magic paths with typed collaborators, bind or move agentProviderAdapterRegistryPreflight and Schema dependencies, test capabilities on their real owner, and validate every implementation-packet path against the current tree before reporting ready."
    target_paths:
      - app/Services/Ai/SelfConstruction/Readiness/ReadinessProjectionAgentAutomaticDispatchBatch1Section.php
      - app/Services/Ai/SelfConstruction/Readiness/ReadinessProjectionAgentCodexSection.php
      - app/Services/Ai/SelfConstruction/Readiness/AtlasSelfConstructionReadinessService.php
    acceptance:
      - "all 50 command routes return a typed payload or an intentional typed blocker, never an undefined-method/class fatal"
      - "scheduler policy capability checks resolve on the owning collaborator and can become true"
      - "every allowed_files path exists at packet creation time; stale paths fail closed"
  - op: TEST
    detail: "Add a dedicated semantic characterization matrix for all 50 domain methods before splitting. Cover direct section construction, facade wiring, dependency reachability, outer/inner status agreement, blocker propagation, packet path existence, no-side-effect guarantees, hashes, and representative query budgets; keep command tests only as routing compatibility evidence."
    target_paths:
      - tests/Feature/Ai/AtlasAiSelfConstructionReadinessProjectionAgentAutomaticDispatchBatch1SectionTest.php
      - tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php
    acceptance:
      - "all 50 methods have direct semantic coverage and all 50 command flags have routing coverage"
      - "a *_ready contract requires its declared upstream statuses and authorities"
      - "tests reproduce then reject the 19 current fatal routes, the three false method_exists checks, and both stale packet paths"
  - op: SPLIT
    detail: "SPLIT by lifecycle authority, never into Batch3. Retire Batch1 behind a temporary compatibility adapter and create bounded owners for scheduler policy, runtime/evidence status, implementation packets, guarded writer/release, operator handoff, executor enablement, provider start, and process supervision; no replacement PHP may exceed 2,000 LOC and hot facades stay at or below 800 LOC."
    target_paths:
      - app/Services/Ai/SelfConstruction/Readiness/ReadinessProjectionAgentAutomaticDispatchBatch1Section.php
      - app/Services/Ai/SelfConstruction/Readiness/AtlasSelfConstructionReadinessService.php
    acceptance:
      - "find app/Services/Ai/SelfConstruction -name '*.php' -print0 | xargs -0 wc -l | awk '$1 > 2000 {print}'"
      - "no numbered Batch section replaces this class"
      - "dependencies flow one way from readiness projectors to typed owner queries"
  - op: OWNER
    detail: "Assign one provider-neutral authority for scheduler policy, wakeup and dispatch state, writer/receipt mutations, guarded invocation, operator handoff, executor lifecycle, provider/adapter start, supervision evidence, implementation-packet validity, and readiness projection. Neither a readiness class nor a provider-labelled contract may grant runtime authority."
    target_paths:
      - docs/evidence/2026-07-22-atlas-server-god-debulk/OWNERSHIP.md
      - docs/engineering-knowledge-base/CODEMAP.md
    acceptance:
      - "every state transition, durable write, external process action, policy decision, and evidence read has exactly one owner"
      - "readiness consumes owner state and cannot manufacture ready authority"
      - "implementation packets resolve current owner paths rather than embedding historical locations"
  - op: EXTRACT
    detail: "Extract typed contract/preflight/status descriptors with explicit prerequisite predicates, blocker propagation, authority semantics, path validation, and stable-hash inputs. Reuse a request-scoped immutable projection context for database state, but only after characterization proves compatibility needs; delete unrestricted reflection forwarding."
    target_paths:
      - app/Services/Ai/SelfConstruction/Readiness/ReadinessProjectionAgentAutomaticDispatchBatch1Section.php
    acceptance:
      - "unknown dependencies fail at construction with typed errors"
      - "ready is computed from validated prerequisites rather than a literal string"
      - "each abstraction owns an invariant and has at least two consumers"
  - op: CODEMAP
    detail: "Map the 50 facade aliases and command flags to current callers, side effects, source statuses, real owner, query cost, hash compatibility, and migration disposition. Replace 110-character canonical names with bounded capability vocabulary and date temporary aliases for removal after one compatibility cycle."
    target_paths:
      - app/Console/Commands/AtlasAiSelfConstructionCommand.php
      - app/Services/Ai/SelfConstruction/Readiness/AtlasSelfConstructionReadinessService.php
      - docs/engineering-knowledge-base/CODEMAP.md
    acceptance:
      - "all 50 methods have owner, caller, semantic contract, side-effect class, and migration disposition"
      - "/opt/homebrew/bin/php artisan atlas:engineering:knowledge codemap-verify --json"
      - "unmapped flags and aliases are removed after one compatibility cycle"
  - op: PERF
    detail: "Measure cold and warm representative status/contract chains with query count, schema-probe count, allocations, hash work, payload bytes, p50, and p95. Give database-backed owners batched snapshots and explicit budgets so nested readiness calls do not repeat identical table probes and counts."
    target_paths:
      - app/Services/Ai/SelfConstruction/Readiness/ReadinessProjectionAgentAutomaticDispatchBatch1Section.php
      - tests/Feature/Ai/AtlasAiSelfConstructionReadinessProjectionAgentAutomaticDispatchBatch1SectionTest.php
    acceptance:
      - "benchmarks publish cold/warm p50 and p95 plus query, schema-probe, memory, and payload budgets"
      - "one request does not repeat unchanged Schema::hasTable or aggregate queries"
      - "hashing does not recursively rebuild unchanged predecessor payloads"
caps: [A, B, C, D, E, F, G]
evidence:
  read_mode: full_file_sequential_no_skipped_lines
  line_range_read: 1-6198
  syntax: "No syntax errors detected by /opt/homebrew/bin/php -l"
  source_modified_during_meta: false
  source_sha256: 6704e09160fee75d9d7dcbd53c7e0b3f89418ee4ea098017b6c03e031ad97416
  source_bytes: 497732
  public_method_count: 52
  public_domain_method_count: 50
  parent_facade_delegation_count: 50
  import_count: 228
  used_import_count: 90
  unused_import_count: 138
  unresolved_parent_method_name_count: 56
  unresolved_parent_call_occurrence_count: 107
  hard_coded_ready_contract_count: 20
  hard_coded_ready_contract_runtime_success_count: 1
  hard_coded_ready_contract_runtime_failure_count: 19
  literal_false_assignment_count: 834
  stable_hash_call_count: 50
  non_execution_guarantee_block_count: 50
  schema_has_table_count: 60
  query_builder_count: 22
  count_query_count: 22
  first_query_count: 8
  missing_implementation_packet_path_count: 2
  next_file_by_loc: app/Services/Ai/SelfConstruction/Readiness/ReadinessProjectionAgentAutomaticDispatchBatch2Section.php
```

```yaml
path: app/Services/Ai/SelfConstruction/Readiness/ReadinessProjectionAgentAutomaticDispatchBatch2Section.php
loc: 5360
kind: arbitrary_automatic_dispatch_process_adapter_and_evidence_batch_section
intent_axes: [2, 3, 5, 6, 7, 10, 14, 15, 16, 17, 18, 19, 20, 21, 22, 23, 24, 25, 27, 28, 29, 33, 34, 35, 36, 37, 38, 40, 41, 42, 49, 50, 54, 55, 62, 64, 65, 66, 67, 68, 69]
findings:
  - id: A1-SC-0044
    type: godfile
    severity: s0
    detail: "The second numbered extraction is another 5,360 LOC, 430,239-byte godfile with 50 domain-public methods and 228 imports. It spans receipt use, provider start, adapter boundaries, provider execution, external-process authorization/dry-run/runtime, real-invoker release, executor planning, process spawn, post-start evidence/liveness, dispatch handoff, and eight implementation packets; its longest public name is 121 characters."
    evidence:
      - "wc -l -c => 5,360 LOC and 430,239 bytes"
      - "52 public methods total: setMother, __call, and 50 domain methods"
      - "228 imports; 88 referenced in the class body and 140 unused after extraction"
      - "longest public method name length => 121 characters"
      - "sha256=d36356fae6dda8e763705c6bf1a9adaf75cb5f317986affddc42fa6e4aff914d"
  - id: A1-SC-0045
    type: false_abstraction
    severity: s0
    detail: "Batch2 is another arbitrary routing slice rather than a bounded owner. The parent facade retains 50 one-line delegators, while this section injects that parent and resolves 48 absent method names across 97 calls through unrestricted ReflectionMethod forwarding. Runtime behavior remains bidirectionally coupled to the parent and sibling sections, and 140 unused imports expose the mechanical extraction residue."
    evidence:
      - "AtlasSelfConstructionReadinessService retains 50 agentAutomaticDispatchBatch2Section forwards"
      - "nullable mother injection and unrestricted __call: lines 241-258"
      - "static call inventory => 48 unresolved method names and 97 unresolved call occurrences"
      - "ReflectionMethod is constructed from arbitrary $name and invoked on the parent"
      - "140 of 228 imports are unused in the class body"
  - id: A1-SC-0046
    type: bug
    severity: s0
    detail: "Most operationally named routes in this section cannot currently return. Of the 16 methods that hard-code a *_ready contract status, 13 abort through sibling-section dependencies; all eight implementation packets also abort. The two active failure families are an undefined agentProviderAdapterRegistryPreflight call and an unimported namespaced Schema class inside ReadinessProjectionAgentCodexSection, proving that the numbered split broke cross-section reachability across the public command surface."
    evidence:
      - "runtime characterization of hard-coded-ready methods => 3 returned and 13 threw"
      - "runtime characterization of ImplementationPacket methods => 0 returned and 8 threw"
      - "executor-plan packet command test exits 2 with undefined agentProviderAdapterRegistryPreflight at AgentCodexSection line 85 and zero assertions"
      - "post-start start-execution packet command test exits 2 with missing App\\Services\\Ai\\SelfConstruction\\Readiness\\Schema at AgentCodexSection line 8,363 and zero assertions"
      - "executor-plan status remains reachable and passes 1 test / 21 assertions, showing route-dependent rather than bootstrap-wide failure"
  - id: A1-SC-0047
    type: doc_lie
    severity: s1
    detail: "Every one of the eight implementation packets declares ready_for_scoped_* and implementation_allowed_by_packet=true while two of its six allowed_files entries are nonexistent. Each packet points to its invoker at the old SelfConstruction root instead of ControlPlane and repeats the moved readiness facade path; this yields 16 invalid path entries across eight work orders, and tests explicitly pin at least the stale executor-plan path."
    evidence:
      - "implementation packet count => 8; each has six allowed_files and exactly two missing paths"
      - "missing invoker entries => eight distinct root-level paths; matching classes exist under app/Services/Ai/SelfConstruction/ControlPlane"
      - "repeated missing facade entry => app/Services/Ai/SelfConstruction/AtlasSelfConstructionReadinessService.php"
      - "actual facade => app/Services/Ai/SelfConstruction/Readiness/AtlasSelfConstructionReadinessService.php"
      - "executor-plan command test line 17,966 asserts the stale root-level invoker path"
  - id: A1-SC-0048
    type: dishonest_name
    severity: s1
    detail: "Sixteen contract/release methods literalize a *_ready status instead of deriving it from copied predecessor statuses. Live output demonstrates the contradiction: provider-start-driver release reports ready while its source provider-start preflight is blocked, and adapter-invocation-boundary release reports ready while its source boundary preflight is blocked. Ready therefore means template emitted, not prerequisite or release readiness."
    evidence:
      - "exact hard-coded '*_ready' status count => 16"
      - "live provider-start release output: outer=one_shot_tick_provider_start_driver_release_contract_ready; source_provider_start_driver_preflight_status=blocked"
      - "live adapter-boundary release output: outer=one_shot_tick_adapter_invocation_boundary_release_contract_ready; source_adapter_invocation_boundary_preflight_status=blocked"
      - "liveness contract was the only sampled successful contract whose two source statuses were also ready"
      - "all outer envelopes continue to state execution/runtime/provider/adapter authority false"
  - id: A1-SC-0049
    type: dupe
    severity: s2
    detail: "The 50-method section is another hand-expanded transition template farm: 15 Contract, 13 Preflight, 13 Status, eight ImplementationPacket, and one ContractRelease method repeatedly copy predecessor payloads, enumerate false authorities, produce non-execution guarantees, and hash a new envelope. It contains 786 literal false assignments, 50 non_execution_guarantees blocks, and 50 stableHash calls."
    evidence:
      - "method-family inventory => Contract 15, Preflight 13, Status 13, ImplementationPacket 8, Release 1"
      - "literal => false count => 786"
      - "non_execution_guarantees count => 50"
      - "$this->stableHash occurrence count => 50"
      - "each packet repeats four tasks, the same gates, the same six allowed-file categories, and nearly identical negative policies"
  - id: A1-SC-0050
    type: perf
    severity: s2
    detail: "Database-backed status methods repeatedly probe the same tables and issue independent counts/latest queries without a request-scoped snapshot or budget. The file contains 53 Schema::hasTable checks, 20 query builders, 20 count calls, and eight first calls; contract and preflight chains then traverse sibling projections and rehash predecessor arrays."
    evidence:
      - "Schema::hasTable occurrence count => 53"
      - "::query() occurrence count => 20; ->count() => 20; ->first() => 8"
      - "agent_runs and atlas_ledger_events are probed independently across consecutive status methods"
      - "no cache, immutable projection context, query budget, or shared snapshot exists in this section"
  - id: A1-SC-0051
    type: test_gap
    severity: s1
    detail: "All 50 domain methods have flags in the giant command test, but no test directly references this section or characterizes reflection forwarding, prerequisite invariants, or allowed-file existence. Current focused packet tests abort with zero assertions, while the reachable status test passes and packet assertions pin stale paths; route snapshots neither isolate the extraction boundary nor prove truthful readiness."
    evidence:
      - "all 50 domain methods map to flags present in AtlasAiSelfConstructionCommandTest.php"
      - "no test directly references ReadinessProjectionAgentAutomaticDispatchBatch2Section"
      - "executor-plan and post-start start-execution packet focused tests fail before assertions"
      - "executor-plan status focused test passes 1 test / 21 assertions"
      - "executor-plan packet test source asserts a nonexistent allowed_files entry"
  - id: A1-SC-0052
    type: os_overlap
    severity: s0
    detail: "Batch2 completes the duplicate dispatch/process operating system begun in Batch1. It models provider pre-start, adapter invocation and execution guards, external-process authorization and dry-run, release and executor enablement, process spawn and supervision, post-start evidence acceptance and liveness, dispatch authorization/handoff/receipt use, plus executable implementation work orders. Readiness should project provider-neutral owners rather than define this lifecycle."
    evidence:
      - "provider/adapter release contracts appear around lines 3,417 and 4,308-4,596"
      - "process/release/executor/status chains span the full file from line 265 through line 5,360"
      - "post-start evidence receipt and liveness contracts appear at lines 594 and 2,909"
      - "eight implementation packets prescribe source, tests, command, docs, gates, and next slices"
      - "the section mixes Eloquent read models, future mutations, provider policy, process authority, evidence, and planning"
actions:
  - op: BUGFIX_PLAN
    detail: "Repair the split graph before preserving Batch2 output: replace magic sibling calls with typed collaborators, bind/import AgentCodex dependencies, fail closed on inaccessible prerequisites, derive release readiness from predecessor state, and validate every packet path against canonical owners before emitting ready."
    target_paths:
      - app/Services/Ai/SelfConstruction/Readiness/ReadinessProjectionAgentAutomaticDispatchBatch2Section.php
      - app/Services/Ai/SelfConstruction/Readiness/ReadinessProjectionAgentCodexSection.php
      - app/Services/Ai/SelfConstruction/Readiness/AtlasSelfConstructionReadinessService.php
    acceptance:
      - "all 50 command routes return a typed payload or intentional blocker, never undefined-method/class fatals"
      - "outer ready requires every declared predecessor/preflight to be ready"
      - "all allowed_files resolve to current canonical paths or packet creation fails closed"
  - op: TEST
    detail: "Create direct semantic characterization for all 50 methods before splitting. Cover section construction/facade wiring, all dependency edges, outer/inner status agreement, blocker propagation, packet path existence, authority flags, no-side-effect guarantees, stable hashes, and query budgets; retain command tests only for alias routing compatibility."
    target_paths:
      - tests/Feature/Ai/AtlasAiSelfConstructionReadinessProjectionAgentAutomaticDispatchBatch2SectionTest.php
      - tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php
    acceptance:
      - "all 50 methods have direct semantic coverage and all 50 flags retain routing coverage"
      - "tests reproduce then reject the 13 contract and eight packet fatal routes"
      - "tests fail for blocked-source/ready-outer contradictions and all 16 stale packet entries"
  - op: SPLIT
    detail: "SPLIT by lifecycle authority, not another numbered batch. Retire Batch2 behind a temporary compatibility adapter and create bounded owners for receipt/provider start, adapter boundary/guard, provider execution, external-process lifecycle, real-invoker release/executor/start, post-start evidence/liveness, dispatch handoff, and packet validation; no replacement PHP may exceed 2,000 LOC and hot facades stay at or below 800 LOC."
    target_paths:
      - app/Services/Ai/SelfConstruction/Readiness/ReadinessProjectionAgentAutomaticDispatchBatch2Section.php
      - app/Services/Ai/SelfConstruction/Readiness/AtlasSelfConstructionReadinessService.php
    acceptance:
      - "find app/Services/Ai/SelfConstruction -name '*.php' -print0 | xargs -0 wc -l | awk '$1 > 2000 {print}'"
      - "no numbered Batch section replaces this class"
      - "readiness dependencies are typed and one-way"
  - op: OWNER
    detail: "Assign provider-neutral authorities for receipt use, provider pre-start, adapter invocation/execution guards, process authorization/dry-run/runtime, real-invoker release and executor lifecycle, process supervision, post-start evidence/liveness, dispatch handoff, and packet path validation. Readiness owns projection only; Codex owns adapter-specific translation only."
    target_paths:
      - docs/evidence/2026-07-22-atlas-server-god-debulk/OWNERSHIP.md
      - docs/engineering-knowledge-base/CODEMAP.md
    acceptance:
      - "every policy, state transition, durable write, external-process action, evidence observation, and work packet has one owner"
      - "provider-labelled readiness methods cannot grant lifecycle authority"
      - "packet paths are resolved from owner metadata rather than copied strings"
  - op: EXTRACT
    detail: "Replace repeated contract/preflight/status/packet arrays with typed transition descriptors, prerequisite predicates, blocker propagation, authority semantics, canonical-path resolution, and explicit stable-hash inputs. Share one immutable database snapshot per request after byte and semantic characterization; remove unrestricted ReflectionMethod forwarding."
    target_paths:
      - app/Services/Ai/SelfConstruction/Readiness/ReadinessProjectionAgentAutomaticDispatchBatch2Section.php
    acceptance:
      - "unknown dependencies and paths fail at construction with typed errors"
      - "ready is computed from validated prerequisites rather than literal strings"
      - "each abstraction owns an invariant and has at least two consumers"
  - op: CODEMAP
    detail: "Map the 50 facade aliases and command flags to callers, predecessors, side effects, true owner, query cost, packet paths, hash compatibility, and migration disposition. Replace 121-character canonical names with bounded lifecycle vocabulary and date temporary aliases for removal after one compatibility cycle."
    target_paths:
      - app/Console/Commands/AtlasAiSelfConstructionCommand.php
      - app/Services/Ai/SelfConstruction/Readiness/AtlasSelfConstructionReadinessService.php
      - docs/engineering-knowledge-base/CODEMAP.md
    acceptance:
      - "all 50 methods have owner, caller, prerequisites, side-effect class, and migration disposition"
      - "/opt/homebrew/bin/php artisan atlas:engineering:knowledge codemap-verify --json"
      - "unmapped or stale packet/command aliases are removed after one compatibility cycle"
  - op: PERF
    detail: "Benchmark cold/warm representative status, contract, and packet chains with query count, schema probes, allocations, hash work, payload bytes, p50, and p95. Batch repeated agent-run/ledger reads into owner snapshots with explicit budgets and reuse unchanged predecessor projections."
    target_paths:
      - app/Services/Ai/SelfConstruction/Readiness/ReadinessProjectionAgentAutomaticDispatchBatch2Section.php
      - tests/Feature/Ai/AtlasAiSelfConstructionReadinessProjectionAgentAutomaticDispatchBatch2SectionTest.php
    acceptance:
      - "benchmarks publish cold/warm p50 and p95 plus query, schema-probe, memory, and payload budgets"
      - "one request does not repeat unchanged Schema::hasTable or aggregate queries"
      - "packet/contract chains do not recursively rebuild and hash unchanged payloads"
caps: [A, B, C, D, E, F, G]
evidence:
  read_mode: full_file_sequential_no_skipped_lines
  line_range_read: 1-5360
  syntax: "No syntax errors detected by /opt/homebrew/bin/php -l"
  source_modified_during_meta: false
  source_sha256: d36356fae6dda8e763705c6bf1a9adaf75cb5f317986affddc42fa6e4aff914d
  source_bytes: 430239
  public_method_count: 52
  public_domain_method_count: 50
  parent_facade_delegation_count: 50
  import_count: 228
  used_import_count: 88
  unused_import_count: 140
  unresolved_parent_method_name_count: 48
  unresolved_parent_call_occurrence_count: 97
  hard_coded_ready_method_count: 16
  hard_coded_ready_runtime_success_count: 3
  hard_coded_ready_runtime_failure_count: 13
  implementation_packet_count: 8
  implementation_packet_runtime_success_count: 0
  implementation_packet_runtime_failure_count: 8
  implementation_packet_missing_path_entry_count: 16
  implementation_packet_unique_missing_path_count: 9
  literal_false_assignment_count: 786
  stable_hash_call_count: 50
  non_execution_guarantee_block_count: 50
  schema_has_table_count: 53
  query_builder_count: 20
  count_query_count: 20
  first_query_count: 8
  focused_packet_failure_assertion_count: 0
  focused_status_pass_assertion_count: 21
  next_file_by_loc: app/Services/Ai/SelfConstruction/Readiness/ReadinessProjectionAgentDispatchProviderSection.php
```

```yaml
path: app/Services/Ai/SelfConstruction/Readiness/ReadinessProjectionAgentDispatchProviderSection.php
loc: 4302
kind: dispatch_provider_receipt_release_and_authorization_section
intent_axes: [2, 3, 5, 6, 7, 10, 14, 15, 16, 17, 18, 19, 20, 21, 22, 23, 24, 25, 27, 28, 29, 33, 34, 35, 36, 37, 38, 40, 41, 42, 49, 50, 54, 55, 62, 64, 65, 66, 67, 68, 69]
findings:
  - id: A1-SC-0053
    type: godfile
    severity: s0
    detail: "This 4,302 LOC, 251,143-byte section exposes 39 domain-public methods spanning dispatch policy, receipt validation and mutation, executor release, receipt consumption, sandbox binding, provider startup, adapter registry/guard/boundary, human authorization, signature collection, persistence, and seven executable implementation packets. It is a second operating facade rather than one readiness projection."
    evidence:
      - "wc -l -c => 4,302 LOC and 251,143 bytes"
      - "42 public methods total: constructor, setMother, __call, and 39 domain methods"
      - "method-family inventory includes 13 Preflight, 12 Template, eight ContractTemplate, seven ImplementationPacket, one Draft, one Request, one Runbook, one Status, and one mutating Write route"
      - "longest public method name => agentDispatchExecutorReleaseAuthorizationPersistenceWriterImplementationPreflight (81 characters)"
      - "sha256=b150526798706404a819381576f406990a26feb611187bc67f27bf735de1ee44"
  - id: A1-SC-0054
    type: false_abstraction
    severity: s0
    detail: "The section is mechanically coupled back to the 29,744 LOC mother facade: that facade retains 39 one-line delegators, this class accepts the mother after construction, and unrestricted __call forwards nine statically absent method names across 12 calls by ReflectionMethod. Dependency direction and method availability remain runtime conventions instead of typed boundaries."
    evidence:
      - "AtlasSelfConstructionReadinessService retains 39 agentDispatchProviderSection delegators"
      - "nullable mother injection plus setMother and unrestricted __call: lines 29-53"
      - "static inventory => nine unresolved parent method names and 12 unresolved call occurrences"
      - "ReflectionMethod is constructed from arbitrary $name and invoked on the mother"
      - "seven of 15 imports are unused while required ControlPlane imports are absent"
  - id: A1-SC-0055
    type: bug
    severity: s0
    detail: "Seven runtime capability checks reference unimported short class names. PHP therefore resolves them inside the Readiness namespace, where none exists, although all seven implementations exist under ControlPlane. Registry readiness reports zero providers and six blockers, and writer/driver/guard/boundary checks remain permanently missing; the focused registry command test fails because it receives blocked instead of provider_adapter_registry_ready."
    evidence:
      - "affected names: AgentDispatchExecutorAdapterInvocationBoundary, AgentDispatchExecutorProviderStartDriver, AgentDispatchExecutorReceiptUseWriter, AgentDispatchExecutorReleaseAuthorizationPersistenceWriter, AgentDispatchExecutorSandboxBindingWriter, AgentProviderAdapterExecutionGuard, and AgentProviderAdapterRegistry"
      - "runtime class_exists(Readiness\\Name)=false and class_exists(ControlPlane\\Name)=true for all seven names"
      - "live registry preflight => provider_count=0, all five providers missing, blocking_count=6"
      - "focused test test_command_returns_agent_provider_adapter_registry_preflight_as_json => expected provider_adapter_registry_ready, received blocked; 1 failed / 4 assertions"
      - "unqualified capability checks occur from the receipt-use writer preflight at line 1,342 through persistence-writer preflight at line 4,183"
  - id: A1-SC-0056
    type: bug
    severity: s0
    detail: "agentDispatchReceiptWrite can manufacture an authorization-bearing signed_pending_dispatch row from caller-supplied text. It checks only a ready dispatch preflight, an allowed decision, non-empty signed_by/expires_at, and two arbitrary 64-hex strings; it never calls the adjacent receipt-validation preflight, verifies a signature, binds the receipt hash to a canonical payload, compares the envelope hash with the computed draft, validates provider/scope/budget/evidence, or proves expiry is in the future before updateOrCreate. Downstream executor flows consume this status as release authority."
    evidence:
      - "mutating public writer spans lines 652-792 inside a class labelled Readiness"
      - "accepted inputs are decision, signed_by, receipt_hash, dispatch_envelope_hash, and non-empty expires_at"
      - "successful approve_dispatch_once writes status=signed_pending_dispatch at line 737 and signed_at=now"
      - "no call to agentDispatchReceiptValidationPreflight exists in the writer"
      - "aggregate command test accepts either blocked or agent_dispatch_receipt_write_ready and asserts no signature/hash-binding invariant"
  - id: A1-SC-0057
    type: doc_lie
    severity: s1
    detail: "All seven implementation packets literalize ready_for_scoped_* and implementation_allowed_by_packet=true even when their source preflight is blocked. Their allowed_files and gates still point to pre-move root-level service locations: nine entries are missing, representing eight unique nonexistent paths, while the actual services live under ControlPlane. Packet tests can pass while the described work order is false."
    evidence:
      - "implementation packet count => seven; all seven returned a ready packet during runtime characterization"
      - "implementation_allowed_by_packet=true appears in every packet"
      - "allowed_files scan => nine missing entries and eight unique missing paths; the adapter-boundary path is repeated in two packets"
      - "matching service implementations exist under app/Services/Ai/SelfConstruction/ControlPlane"
      - "focused registry implementation-packet test passes 1 test / 14 assertions while its source registry preflight is blocked"
  - id: A1-SC-0058
    type: dishonest_name
    severity: s1
    detail: "The executor-release chain emits authorization_template_ready, ready_for_signature_collection, authorization runbook readiness, and a signed-receipt template even while its source release preflight is unconditionally blocked and provider, packet, and receipt identities are null. The release preflight itself hard-codes blocked irrespective of its computed blocker list, while later ceremony emits ready labels without acquiring authority."
    evidence:
      - "agentDispatchExecutorReleasePreflight at lines 1,081-1,211 hard-codes inner and outer blocked status"
      - "live authorization-template output => template ready, inner ready_for_signature_collection, preflight_status=blocked"
      - "live authorization-template provider, packet, and receipt identifiers => null"
      - "signed-receipt preflight at lines 3,595-3,755 hard-codes six blockers"
      - "all envelopes continue to deny execution/runtime/provider authority despite ready-labelled signature artifacts"
  - id: A1-SC-0059
    type: dupe
    severity: s2
    detail: "The 39-method surface is a hand-expanded template farm. It repeatedly copies predecessor payloads, constructs blocker arrays, enumerates negative authority, prescribes nearly identical implementation work, and hashes the resulting envelope. The file contains 356 literal false assignments, 43 non_execution_guarantees blocks, and 54 stableHash calls."
    evidence:
      - "literal => false count => 356"
      - "non_execution_guarantees count => 43"
      - "$this->stableHash call count => 54"
      - "seven implementation packets repeat tasks, current/future scopes, gates, acceptance criteria, and negative policies"
      - "authorization template, signature request, runbook, signed receipt, persistence template, and persistence preflight restate the same release identities and denial state"
  - id: A1-SC-0060
    type: perf
    severity: s2
    detail: "Readiness routes repeatedly probe schema and query receipt/ledger state without a request-scoped snapshot or stated budget. The file contains 21 Schema::hasTable probes, five query builders, three first queries, one count, and one updateOrCreate; nested template/preflight chains also rebuild and hash predecessor payloads."
    evidence:
      - "Schema::hasTable occurrence count => 21"
      - "::query() occurrence count => five; ->first() => three; ->count() => one"
      - "the public writer performs updateOrCreate from the same projection surface"
      - "no cache, immutable projection context, query budget, or shared validated release snapshot exists"
  - id: A1-SC-0061
    type: test_gap
    severity: s1
    detail: "Tests expose the missing-import regression but do not protect the more dangerous invariants. Packet tests accept ready output without requiring a ready source preflight or existing paths, and the generic receipt-write dataset accepts blocked or ready while checking neither signature identity, expiry, hash binding, scope/provider correlation, nor absence of unauthorized writes."
    evidence:
      - "registry preflight focused test fails on blocked, proving at least one route detects the namespace regression"
      - "registry packet focused test passes despite the blocked source preflight"
      - "command dataset at lines 26,049-26,061 accepts status in [blocked, agent_dispatch_receipt_write_ready]"
      - "no test reference to agentDispatchReceiptWrite was found outside the aggregate command dataset"
      - "downstream tests seed signed_pending_dispatch directly instead of proving its authorization provenance"
  - id: A1-SC-0062
    type: os_overlap
    severity: s0
    detail: "This Readiness section owns a complete duplicate dispatch operating system: provider registry and guards, receipt authorization and mutation, sandbox binding, provider start, adapter invocation, executor release, human signatures, persistence, work packets, and policy. It therefore both projects and manufactures the authority that runtime consumers later trust."
    evidence:
      - "agentDispatchReceiptWrite performs a durable updateOrCreate"
      - "seven provider/runtime services are detected and prescribed from this projection"
      - "executor release and signature/persistence chains span lines 1,081-4,302"
      - "seven implementation packets prescribe source, tests, command, docs, gates, and next slices"
      - "the class combines read models, write authority, signature ceremony, provider policy, runtime release, and planning"
actions:
  - op: BUGFIX_PLAN
    detail: "First restore truthful reachability and authorization: import or inject the seven ControlPlane capabilities, eliminate namespace-dependent class_exists checks, make every packet and authorization label derive from validated prerequisites and canonical existing paths, and replace receiptWrite with a provider-neutral command that cryptographically verifies and atomically binds the signed canonical payload, envelope, scope, budget, evidence, provider, decision, and future expiry."
    target_paths:
      - app/Services/Ai/SelfConstruction/Readiness/ReadinessProjectionAgentDispatchProviderSection.php
      - app/Services/Ai/SelfConstruction/ControlPlane
      - app/Services/Ai/SelfConstruction/Readiness/AtlasSelfConstructionReadinessService.php
    acceptance:
      - "all seven capability checks resolve their current typed ControlPlane owner"
      - "no blocked preflight or missing path can produce a ready packet or signature stage"
      - "fabricated, replayed, expired, mismatched, or unsigned receipts cannot create or update authorization state"
  - op: TEST
    detail: "Add direct semantic characterization for all 39 domain methods before splitting. Cover typed construction, every prerequisite edge, blocked/ready propagation, all current path existence, writer transactional behavior, signature verification, canonical-hash binding, replay/idempotency, expiry, scope/budget/provider/evidence correlation, stable hashes, no-side-effect projections, and query budgets; keep command tests only for routing compatibility."
    target_paths:
      - tests/Feature/Ai/AtlasAiSelfConstructionReadinessProjectionAgentDispatchProviderSectionTest.php
      - tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php
    acceptance:
      - "all 39 domain methods have direct semantic coverage and all facade/command aliases retain routing coverage"
      - "tests reproduce then reject the seven missing-import false negatives and nine stale packet paths"
      - "authorization tests reject arbitrary hex, identity mismatch, envelope mismatch, replay, past expiry, and blocked preflight without a durable write"
  - op: SPLIT
    detail: "Retire this section behind a temporary compatibility adapter and split by authority: provider catalog/health projection, receipt authorization command, executor release policy, sandbox/provider-start/adapter queries, signature workflow, authorization persistence, and packet validation. Readiness classes remain read-only; no replacement PHP may exceed 2,000 LOC and hot facades stay at or below 800 LOC."
    target_paths:
      - app/Services/Ai/SelfConstruction/Readiness/ReadinessProjectionAgentDispatchProviderSection.php
      - app/Services/Ai/SelfConstruction/Readiness/AtlasSelfConstructionReadinessService.php
      - app/Services/Ai/SelfConstruction/ControlPlane
    acceptance:
      - "find app/Services/Ai/SelfConstruction -name '*.php' -print0 | xargs -0 wc -l | awk '$1 > 2000 {print}'"
      - "readiness projectors expose no update/insert/delete/upsert operation"
      - "dependencies are typed and flow one way from projection to provider-neutral owners"
  - op: OWNER
    detail: "Assign one provider-neutral owner for signed receipt authorization and replay protection, one for executor-release policy, one for provider/adapter capability state, one for human signature workflow, one for authorization persistence, and one for canonical packet path resolution. Readiness owns projection only and cannot grant or persist runtime authority."
    target_paths:
      - docs/evidence/2026-07-22-atlas-server-god-debulk/OWNERSHIP.md
      - docs/engineering-knowledge-base/CODEMAP.md
    acceptance:
      - "every policy decision, signature verification, durable transition, provider action, and evidence read has exactly one owner"
      - "receipt authority is derived from a verified canonical signed payload with replay protection"
      - "implementation packets resolve current owner paths instead of embedding historical locations"
  - op: EXTRACT
    detail: "Extract typed release/receipt/provider descriptors with explicit prerequisite predicates, blocker propagation, capability interfaces, signature-verification results, canonical hashes, state-transition invariants, and path validation. Replace __call and class-name probing with constructor-enforced collaborators and reuse one immutable projection snapshot per request."
    target_paths:
      - app/Services/Ai/SelfConstruction/Readiness/ReadinessProjectionAgentDispatchProviderSection.php
      - app/Services/Ai/SelfConstruction/ControlPlane
    acceptance:
      - "unknown dependencies fail at construction with typed errors"
      - "ready and authorized states are computed from validated invariants rather than literal strings"
      - "every write uses an explicit command/result boundary and every projection is side-effect free"
  - op: CODEMAP
    detail: "Map all 39 facade aliases and command flags to callers, prerequisites, side effects, true owner, query cost, stable-hash compatibility, current packet paths, and migration disposition. Date temporary aliases and replace authorization ceremony names with bounded lifecycle vocabulary."
    target_paths:
      - app/Console/Commands/AtlasAiSelfConstructionCommand.php
      - app/Services/Ai/SelfConstruction/Readiness/AtlasSelfConstructionReadinessService.php
      - docs/engineering-knowledge-base/CODEMAP.md
    acceptance:
      - "all 39 methods have owner, caller, prerequisites, side-effect class, authorization semantics, and migration disposition"
      - "/opt/homebrew/bin/php artisan atlas:engineering:knowledge codemap-verify --json"
      - "unmapped reflection routes and stale packet/command aliases are removed after one compatibility cycle"
  - op: PERF
    detail: "Benchmark cold/warm provider, receipt, release, authorization, and packet chains with query count, schema probes, allocations, hash/signature work, payload bytes, p50, and p95. Share one validated owner snapshot per request and give cryptographic verification and persistence explicit budgets."
    target_paths:
      - app/Services/Ai/SelfConstruction/Readiness/ReadinessProjectionAgentDispatchProviderSection.php
      - tests/Feature/Ai/AtlasAiSelfConstructionReadinessProjectionAgentDispatchProviderSectionTest.php
    acceptance:
      - "benchmarks publish cold/warm p50 and p95 plus query, schema-probe, memory, hash/signature, and payload budgets"
      - "one request does not repeat unchanged schema or receipt/ledger queries"
      - "template/packet chains do not recursively rebuild and hash unchanged payloads"
caps: [A, B, C, D, E, F, G]
evidence:
  read_mode: full_file_sequential_no_skipped_lines
  line_range_read: 1-4302
  syntax: "No syntax errors detected by /opt/homebrew/bin/php -l"
  source_modified_during_meta: false
  source_sha256: b150526798706404a819381576f406990a26feb611187bc67f27bf735de1ee44
  source_bytes: 251143
  public_method_count: 42
  public_domain_method_count: 39
  parent_facade_delegation_count: 39
  import_count: 15
  used_import_count: 8
  unused_import_count: 7
  unresolved_parent_method_name_count: 9
  unresolved_parent_call_occurrence_count: 12
  missing_control_plane_import_count: 7
  implementation_packet_count: 7
  implementation_packet_missing_path_entry_count: 9
  implementation_packet_unique_missing_path_count: 8
  literal_false_assignment_count: 356
  stable_hash_call_count: 54
  non_execution_guarantee_block_count: 43
  schema_has_table_count: 21
  query_builder_count: 5
  count_query_count: 1
  first_query_count: 3
  mutating_update_or_create_count: 1
  next_file_by_loc: app/Services/Ai/SelfConstruction/Readiness/ReadinessProjectionAgentControlPlaneSection.php
```

```yaml
path: app/Services/Ai/SelfConstruction/Readiness/ReadinessProjectionAgentControlPlaneSection.php
loc: 4284
kind: monolithic_self_construction_control_plane_projection_and_capability_catalog
intent_axes: [2, 3, 5, 6, 7, 10, 14, 15, 16, 17, 18, 19, 20, 21, 22, 23, 24, 25, 27, 28, 29, 33, 34, 35, 36, 37, 38, 40, 41, 42, 49, 50, 54, 55, 62, 64, 65, 66, 67, 68, 69]
findings:
  - id: A1-SC-0063
    type: godfile
    severity: s0
    detail: "The file is a 4,284 LOC, 358,503-byte one-method god object. agentControlPlane alone occupies 4,017 lines and combines reservation/session projection, six persistent-model counts, runtime schema interpretation, 317 method/class availability probes, a 336-branch next-slice state machine, a 467-entry static capability catalog, 315 conditional capability appends, workspace/liveness/readiness policy, continuation instructions, and final hashing."
    evidence:
      - "agentControlPlane spans lines 267-4,283"
      - "228 imports, of which only 70 are referenced after the class declaration and 158 are unused"
      - "253 unique method_exists probes plus 64 unique class_exists probes"
      - "467 static current-capability literals plus 315 conditional append sites"
      - "sha256=31b5c19910dbfc83ee42419d84a594e1f2057b06382c09d6a5df6c446e53dfeb"
  - id: A1-SC-0064
    type: false_abstraction
    severity: s0
    detail: "The section is not autonomous: the parent retains the public facade method, while this class is created unbound, later receives the mother, and forwards nine absent helper names across ten calls through unrestricted ReflectionMethod. Its lone domain method therefore depends on private implementation knowledge spread across the 29,744 LOC parent and sibling sections, and 158 unused imports expose mechanical extraction residue."
    evidence:
      - "declared methods => setMother, __call, and agentControlPlane only"
      - "unresolved parent calls => nine names / ten occurrences"
      - "forwarded helpers include packetQueue, parallelSessionPlan, multiSessionReadinessGate, forgeWorkspaceStatus, reservationStatus, providerRoleForActor, runtime-table helpers, and stableHash"
      - "AtlasSelfConstructionReadinessService retains the public agentControlPlane delegator"
      - "158 of 228 imports are unused in the class body"
  - id: A1-SC-0065
    type: bug
    severity: s0
    detail: "All 253 surface-availability checks call method_exists on the section itself. PHP does not treat __call as a declared method, so every check is false even though all 253 methods exist on the mother facade and are callable through this section. With schema and services present, the live pointer is consequently pinned to the first checked method, mutating-writer release preflight, forever."
    evidence:
      - "static inventory => 253 method_exists($this, ...) checks, all names unique"
      - "all 253 checked names are public methods on AtlasSelfConstructionReadinessService"
      - "runtime proof for the first name => method_exists(section)=false, method_exists(mother)=true, is_callable(section)=true, is_callable(mother)=true"
      - "live control plane => schema_ready and next_required_slice=activate_signed_one_shot_scheduler_tick_mutating_writer_release_preflight"
      - "agentControlPlane still returns status=agent_control_plane_ready"
  - id: A1-SC-0066
    type: bug
    severity: s0
    detail: "The next-slice algorithm cannot become truthful by changing the receiver. All 253 facade methods and all 64 probed classes exist, so a receiver-only repair would skip every stage without invoking any preflight/status and fall through to re-activate the already-present post-start receipt contract. Today the pinned mutating-writer preflight route itself aborts through a broken sibling call, proving that existence is not operational readiness."
    evidence:
      - "parent method hit inventory => 253 / 253; class_exists inventory => 64 / 64"
      - "direct invocation of the live pointed-to facade method aborts: undefined ReadinessProjectionAgentCodexSection::agentProviderAdapterRegistryPreflight"
      - "final else at lines 2,342-2,347 selects activate_*_post_start_receipt_contract rather than a complete/no-work state"
      - "the same post-start receipt slice is assigned at lines 1,263, 1,943, and 2,343"
      - "no branch invokes a checked contract, preflight, packet, service, or status to validate its semantics"
  - id: A1-SC-0067
    type: doc_lie
    severity: s1
    detail: "current_capability is primarily an unconditional prose catalog rather than observed capability. The method preloads 467 unique entries, including contract/preflight/implementation-packet/service/status claims, without resolving or executing them; live output advertises 557 unique current capabilities while the next required route is both reported missing and fatals when called. It also emits a one-worktree-or-branch-per-packet strategy that conflicts with this repository's canonical local-main-only contract."
    evidence:
      - "static currentCapabilities array => 467 unique strings"
      - "live current_capability => 557 entries / 557 unique"
      - "158 imported classes named by the conceptual catalog are never referenced by executable code in this section"
      - "live next slice is absent according to this projection but present on the facade and callable"
      - "execution_workspaces.0.branch_strategy=one_worktree_or_branch_per_claimed_packet at line 4,142; AGENTS.md requires local main only"
  - id: A1-SC-0068
    type: dishonest_name
    severity: s1
    detail: "The outer status, maturity, and human summary always declare the Agent Control Plane ready even when schema is missing, the next-slice classifier is wrong, or the named next route fatals. In the live schema-ready case the projection narrows not_yet_runtime_capable to four labels and says it can coordinate claimed packets, while its first alleged missing capability exists but cannot return. Ready here means an array was assembled, not that the control plane is coherent."
    evidence:
      - "outer status is literal agent_control_plane_ready at line 4,267"
      - "maturity is literal durable_packet_claims_with_read_only_control_projection"
      - "human summary unconditionally says projection is ready"
      - "live not_yet_runtime_capable contains only four labels despite the broken next route"
      - "dispatch/execution remain false, but no degraded or blocked projection status represents the incoherent catalog"
  - id: A1-SC-0069
    type: dupe
    severity: s2
    detail: "The control plane hand-expands the same lifecycle catalog three times: availability variables, a positional elseif selector, and conditional capability appends. The selector contains 336 assignments for only 255 unique slice names; 80 names are duplicated and there are 81 excess duplicate assignments, including a repeated post-start block. This duplication makes ordering, completeness, and terminal-state behavior drift independently."
    evidence:
      - "next-slice assignments => 336 total / 255 unique"
      - "duplicated slice names => 80; excess duplicate assignments => 81"
      - "conditional currentCapabilities append sites => 315 / 315 unique"
      - "method availability variables => 253 method checks plus 64 class checks"
      - "the post-start receipt/evidence/liveness/dispatch/provider/process chain is repeated inside the same elseif ladder"
  - id: A1-SC-0070
    type: perf
    severity: s2
    detail: "Every control-plane read fans into five other projections, performs six independent full-table counts, runs 317 availability probes, allocates hundreds of long capability strings and branch variables, then hashes the entire large payload. Active reservation liveness also calls wall-clock time and embeds seconds remaining in the hash input, so identical persistent state can produce a different control-plane hash on each second."
    evidence:
      - "initial fan-out => packetQueue, parallelSessionPlan, multiSessionReadinessGate, forgeWorkspaceStatus, and reservationStatus"
      - "six model query()->count() calls at lines 327-344"
      - "253 method_exists plus 64 class_exists probes per request"
      - "live output allocates 557 unique capability strings before hashing the control plane"
      - "time() contributes seconds_until_lease_expiry to providerSessions, which is included in control_plane_hash"
  - id: A1-SC-0071
    type: test_gap
    severity: s1
    detail: "The focused command test passes 286 assertions largely by pinning literal capability names, yet it does not assert the method_exists receiver, invoke the selected next route, require a terminal complete state, derive capabilities from real status, or enforce local-main policy. Chain-integrity tests also pass while the live pointer targets a fatal route, so current green evidence certifies catalog self-consistency rather than executable control-plane integrity."
    evidence:
      - "focused test_command_returns_agent_control_plane_as_json passes 1 test / 286 assertions"
      - "focused chain-integrity pointer/capability tests pass 2 tests / 7 assertions"
      - "the command test contains long assertContains sequences for static capability literals"
      - "no focused assertion compares method_exists(section) with facade callability"
      - "no focused assertion invokes control_plane.persistent_runtime.next_required_slice and requires a non-fatal semantic result"
  - id: A1-SC-0072
    type: os_overlap
    severity: s0
    detail: "A read-only readiness section claims the whole Self-Construction operating system: queue/reservations, sessions and liveness, workspaces, persistence, provider adapters, scheduler and process lifecycle, receipts and signatures, replay/certification, governance, cost/work products, task leasing, runtime registry, final evidence, completion, human gates, operator runbooks, and implementation packets. This universal registry has no bounded owner and makes a single projection the naming authority for hundreds of independent lifecycles."
    evidence:
      - "467 unconditional capability labels span ControlPlane, NativeImplementation, Support, scheduler, provider, completion, evidence, and operator domains"
      - "228 imports cross models plus ControlPlane, NativeImplementation, Support, and root SelfConstruction namespaces"
      - "current-capability and next-build outputs are consumed as system-wide planning authority"
      - "the projection emits workspace branch strategy, human handoff commands, readiness decisions, runtime maturity, and invariants"
      - "one method owns both the capability taxonomy and the ordered roadmap through 255 purported slices"
actions:
  - op: BUGFIX_PLAN
    detail: "Replace receiver-relative method/class existence probing and the positional ladder with an explicit provider-neutral capability registry whose entries resolve typed owners and evaluate real contract/preflight/status outcomes. Fail closed on invocation errors, distinguish unavailable/degraded/blocked/complete, remove the dead fallback, derive current capabilities from proof, and align workspace policy with local-main governance."
    target_paths:
      - app/Services/Ai/SelfConstruction/Readiness/ReadinessProjectionAgentControlPlaneSection.php
      - app/Services/Ai/SelfConstruction/Readiness/AtlasSelfConstructionReadinessService.php
      - app/Services/Ai/SelfConstruction/ControlPlane
    acceptance:
      - "the registry invokes typed status evaluators and never infers readiness from method/class existence alone"
      - "the next slice is executable, semantically blocked with explicit evidence, or an explicit complete state"
      - "reported capabilities, missing capabilities, next slice, and workspace policy cannot contradict each other"
  - op: TEST
    detail: "Characterize the one public domain method before splitting, then replace literal-list snapshots with registry invariants. Cover wrong-receiver regression, every registry entry, invocation failure propagation, complete/degraded/blocked states, duplicate/unknown slices, current-capability proof, schema permutations, query budgets, hash determinism, and local-main workspace policy."
    target_paths:
      - tests/Feature/Ai/AtlasAiSelfConstructionReadinessProjectionAgentControlPlaneSectionTest.php
      - tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php
      - tests/Feature/Ai/AtlasAiSelfConstructionAgentControlPlaneChainIntegrityAuditTest.php
    acceptance:
      - "tests fail if a selected next slice is absent, fatal, already complete, or unsupported by its status evaluator"
      - "every advertised current capability has a passing typed proof and no duplicate registry key"
      - "tests enforce main-only policy and bounded query/payload/hash budgets"
  - op: SPLIT
    detail: "Retire the 4,017-line method behind a compatibility projector. Split reservation/session snapshot, persistent runtime snapshot, capability registry, next-work selector, provider/process lifecycle projection, completion/evidence projection, workspace policy, continuation handoff, and final envelope; no replacement PHP may exceed 2,000 LOC and hot facades stay at or below 800 LOC."
    target_paths:
      - app/Services/Ai/SelfConstruction/Readiness/ReadinessProjectionAgentControlPlaneSection.php
      - app/Services/Ai/SelfConstruction/Readiness/AtlasSelfConstructionReadinessService.php
    acceptance:
      - "find app/Services/Ai/SelfConstruction -name '*.php' -print0 | xargs -0 wc -l | awk '$1 > 2000 {print}'"
      - "no universal capability array or positional 200-plus-branch selector remains"
      - "readiness dependencies are typed, acyclic, and one-way"
  - op: OWNER
    detail: "Assign bounded provider-neutral owners for runtime schema/snapshot, reservations and liveness, capability certification, next-work selection, provider/process lifecycle, completion/evidence, and workspace/handoff policy. Readiness aggregates typed owner results but cannot invent capability, implementation order, branch strategy, or completion state."
    target_paths:
      - docs/evidence/2026-07-22-atlas-server-god-debulk/OWNERSHIP.md
      - docs/engineering-knowledge-base/CODEMAP.md
    acceptance:
      - "every capability and transition has one owner, one proof evaluator, and one failure vocabulary"
      - "control-plane projection owns no runtime transition or universal roadmap"
      - "workspace policy is sourced from canonical governance rather than a literal payload string"
  - op: EXTRACT
    detail: "Extract a typed capability descriptor and registry with stable ids, owner/status callable, prerequisites, side-effect class, query cost, deprecation metadata, and next-state edges. Generate projection and ordered work from the same validated graph; use an immutable request snapshot for reservations/tables/counts and delete unrestricted __call forwarding."
    target_paths:
      - app/Services/Ai/SelfConstruction/Readiness/ReadinessProjectionAgentControlPlaneSection.php
      - app/Services/Ai/SelfConstruction/ControlPlane
    acceptance:
      - "unknown, duplicate, cyclic, fatal, or ownerless registry entries fail validation"
      - "current capabilities and next work are projections of one graph rather than three hand-maintained catalogs"
      - "unknown dependencies fail at construction with typed errors"
  - op: CODEMAP
    detail: "Map the agent-control-plane command to its five upstream projections, nine forwarded helpers, 253 facade surface checks, 64 services, 255 slice ids, 467 static labels, runtime queries, hash inputs, true owners, and migration disposition. Delete catalog entries and imports that do not resolve to a current governed owner."
    target_paths:
      - app/Console/Commands/AtlasAiSelfConstructionCommand.php
      - app/Services/Ai/SelfConstruction/Readiness/AtlasSelfConstructionReadinessService.php
      - docs/engineering-knowledge-base/CODEMAP.md
    acceptance:
      - "every retained capability/slice has owner, evaluator, callers, prerequisites, side-effect class, and migration status"
      - "/opt/homebrew/bin/php artisan atlas:engineering:knowledge codemap-verify --json"
      - "158 unused imports, duplicate slices, and reflection-only helper routes are removed"
  - op: PERF
    detail: "Benchmark cold/warm control-plane projection with upstream fan-out, query count, schema probes, capability-evaluator count, allocations, payload bytes, hashing, p50, and p95. Batch six counts into one owner snapshot, cache immutable capability metadata, and keep wall-clock liveness outside stable structural hashes."
    target_paths:
      - app/Services/Ai/SelfConstruction/Readiness/ReadinessProjectionAgentControlPlaneSection.php
      - tests/Feature/Ai/AtlasAiSelfConstructionReadinessProjectionAgentControlPlaneSectionTest.php
    acceptance:
      - "benchmarks publish cold/warm p50 and p95 plus query, allocation, payload, evaluator, and hash budgets"
      - "one request shares one reservation/runtime snapshot and does not issue six independent count queries"
      - "unchanged persistent/control state produces a deterministic structural hash"
caps: [A, B, C, D, E, F, G]
evidence:
  read_mode: full_file_sequential_no_skipped_lines
  line_range_read: 1-4284
  syntax: "No syntax errors detected by /opt/homebrew/bin/php -l"
  source_modified_during_meta: false
  source_sha256: 31b5c19910dbfc83ee42419d84a594e1f2057b06382c09d6a5df6c446e53dfeb
  source_bytes: 358503
  public_method_count: 3
  public_domain_method_count: 1
  agent_control_plane_method_loc: 4017
  parent_facade_delegation_count: 1
  import_count: 228
  used_import_count: 70
  unused_import_count: 158
  unresolved_parent_method_name_count: 9
  unresolved_parent_call_occurrence_count: 10
  method_exists_check_count: 253
  method_exists_unique_name_count: 253
  method_exists_parent_facade_hit_count: 253
  class_exists_check_count: 64
  class_exists_runtime_ready_count: 64
  next_slice_assignment_count: 336
  next_slice_unique_count: 255
  next_slice_duplicated_name_count: 80
  next_slice_excess_duplicate_assignment_count: 81
  static_current_capability_count: 467
  dynamic_current_capability_append_count: 315
  live_current_capability_count: 557
  query_builder_count: 6
  count_query_count: 6
  wall_clock_time_call_count: 1
  focused_command_test_assertion_count: 286
  focused_chain_test_assertion_count: 7
  next_file_by_loc: app/Services/Ai/SelfConstruction/Readiness/ReadinessProjectionDispatchGateSection.php
```

```yaml
path: app/Services/Ai/SelfConstruction/Readiness/ReadinessProjectionDispatchGateSection.php
loc: 2355
kind: codex_post_start_process_release_gate_contract_section
intent_axes: [2, 3, 5, 6, 7, 10, 14, 15, 16, 17, 18, 19, 20, 21, 22, 23, 24, 25, 27, 28, 29, 33, 34, 35, 36, 37, 38, 40, 41, 42, 49, 50, 54, 55, 62, 64, 65, 66, 67, 68, 69]
findings:
  - id: A1-SC-0073
    type: godfile
    severity: s0
    detail: "This 2,355 LOC, 166,873-byte section packs 12 provider-specific post-start contracts covering real-invoker release preflight, signed release, implementation boundary, executor plan/fresh release/enablement, supervised activation, guarded start, final authorization, rehearsal, start envelope, and execution gate. Each 158-194 LOC method embeds service routing, lifecycle policy, 50-92 future inputs, future mutations, prohibitions, status projection, hashing, and human copy."
    evidence:
      - "14 public methods total: setMother, __call, and 12 domain contracts"
      - "12 domain methods span lines 265-2,353"
      - "228 imports, of which only 36 are referenced after the class declaration and 192 are unused"
      - "required input lists total 796 entries across the 12 methods"
      - "sha256=2ad7b6846284321c1deaddd41d721f389f44e67fa1bd6398ad72531b6bfa0081"
  - id: A1-SC-0074
    type: false_abstraction
    severity: s0
    detail: "The section remains mechanically fused to the mother facade and sibling sections. The facade keeps 12 one-line delegators; this class is bound after construction and forwards 31 absent method names across 42 calls through unrestricted ReflectionMethod. Its contracts cannot be instantiated or evaluated from typed collaborators, and 192 unused imports demonstrate that the extraction copied a universal import header instead of creating a boundary."
    evidence:
      - "nullable mother, setMother, and unrestricted __call: lines 241-258"
      - "static call inventory => 31 unresolved parent names / 42 occurrences"
      - "AtlasSelfConstructionReadinessService retains 12 dispatchGateSection forwards"
      - "192 of 228 imports are unused"
      - "each method reaches predecessor status and template logic through the mother rather than constructor-enforced interfaces"
  - id: A1-SC-0075
    type: bug
    severity: s0
    detail: "Every one of the 12 public contracts currently aborts before returning because its predecessor-status path reaches ReadinessProjectionAgentCodexSection::Schema, but that section does not import Illuminate's Schema facade. The dispatch-gate file imports Schema itself yet never uses it, so the duplicated header masks rather than fixes the actual dependency location."
    evidence:
      - "runtime characterization => 0 returned / 12 threw Class App\\Services\\Ai\\SelfConstruction\\Readiness\\Schema not found"
      - "failing location => ReadinessProjectionAgentCodexSection.php line 8,363"
      - "AgentCodexSection contains repeated Schema::hasTable calls but no Schema import"
      - "two focused contract command tests fail with zero assertions at the same line"
      - "this file's own Illuminate\\Support\\Facades\\Schema import is one of its 192 unused imports"
  - id: A1-SC-0076
    type: bug
    severity: s0
    detail: "The missing Schema import is only the first failure. When a process-local class alias is used solely to continue characterization, all 12 routes still abort because ReadinessProjectionAgentCodexSection calls agentProviderAdapterRegistryPreflight on itself even though that method lives on the mother/dispatch-provider section. Thus the complete contract family has at least two independent broken cross-section edges."
    evidence:
      - "process-local Schema alias characterization => 0 returned / 12 threw"
      - "second error for all routes => undefined ReadinessProjectionAgentCodexSection::agentProviderAdapterRegistryPreflight"
      - "agentProviderAdapterRegistryPreflight is exposed by AtlasSelfConstructionReadinessService and implemented in ReadinessProjectionAgentDispatchProviderSection"
      - "unrestricted magic forwarding is not consistently present/bound across the numbered extraction graph"
      - "repairing only the import cannot restore this command surface"
  - id: A1-SC-0077
    type: doc_lie
    severity: s1
    detail: "All 12 methods literalize a *_contract_ready status after merely copying predecessor/template statuses. None checks that a source status is ready, validates any of the 796 required input entries, or converts predecessor blockers into its own state. Even once reachability is repaired, a blocked source will still yield a ready-labelled contract and next-preflight instruction."
    evidence:
      - "literal *_contract_ready status count => 12"
      - "each method copies two or three source_*_status values without a readiness predicate"
      - "required_input_fields_for_future_invoker blocks => 12, with 796 total entries / 124 unique"
      - "no blocker array, degraded status, source-status guard, or validation result exists in this section"
      - "all 12 human summaries unconditionally say the contract is ready"
  - id: A1-SC-0078
    type: dishonest_name
    severity: s1
    detail: "Contract-internal authority labels contradict their outer envelopes. Examples include process_start_armed_by_contract=true, executor_enabled_by_contract=true, start_execution_authorized_by_contract=true, and final_process_start_authorized_by_contract=true while the corresponding outer gate_allowed, executor_enabled, process_start_armed, execution_allowed, and actual_process_start_allowed fields remain false. The text explains these as metadata-only stages, but the field vocabulary represents two incompatible meanings of enabled/authorized/armed."
    evidence:
      - "guarded-process contract sets process_start_armed_by_contract=true while outer process_start_armed=false"
      - "executor-enablement contract sets executor_enabled_by_contract=true while outer executor_enabled=false and gate_allowed=false"
      - "start-execution contract sets start_execution_authorized_by_contract=true while outer gate_allowed=false and execution_allowed=false"
      - "final-authorization contract sets final_process_start_authorized_by_contract=true while outer gate_allowed=false and actual_process_start_allowed=false"
      - "the file contains 108 true literals and 241 false literals across nested authority vocabularies"
  - id: A1-SC-0079
    type: dupe
    severity: s2
    detail: "The 12 contracts are a hand-expanded transition-template farm. They repeat provider/adapter metadata, source status/hash copying, class/method descriptors, long cumulative input lists, three future mutations, 11-13 prohibitions, outer authority denials, six or seven non-execution guarantees, hashing, and human summaries. The 796 required-input occurrences collapse to only 124 unique field names."
    evidence:
      - "required input entries => 796 total / 124 unique; per-method range 50-92"
      - "stableHash calls => 12; non_execution_guarantees blocks => 12"
      - "allowed_future_mutations blocks => 12; forbidden_even_after_contract blocks => 12"
      - "literal false assignments => 241; literal true assignments => 108"
      - "each method differs mainly in stage prefix, predecessor, class/method labels, and a few cumulative fields"
  - id: A1-SC-0080
    type: perf
    severity: s2
    detail: "A single contract request traverses two or three predecessor/template projections through the mother, copies their status/hash data, allocates up to 92 repeated input strings plus policy lists, and hashes the full contract. Because predecessor statuses themselves traverse the long post-start chain and database-backed readiness, the section provides no request snapshot, memoization, payload budget, or bound on recursive projection/hash work."
    evidence:
      - "42 mother calls across 12 methods, representing 31 distinct forwarded names"
      - "each contract performs one additional stableHash over its full array"
      - "required input lists alone allocate 796 string entries"
      - "predecessor status calls currently reach database Schema probes in AgentCodexSection"
      - "no cache, immutable projection context, query budget, payload budget, or depth guard exists"
  - id: A1-SC-0081
    type: test_gap
    severity: s1
    detail: "Command tests exist for these long routes but no test directly references this section or isolates its mother wiring, source-state semantics, or all 12 contracts as a family. Two representative focused tests fail before any assertion, while source assertions pin internal true flags and literal ready names rather than requiring reachable dependencies and blocked-source propagation."
    evidence:
      - "no test directly references ReadinessProjectionDispatchGateSection"
      - "executor-enablement focused command test => 1 failed / 0 assertions"
      - "guarded-process focused command test => 1 failed / 0 assertions"
      - "runtime loop proves all 12 public contracts throw the same first error"
      - "existing assertions explicitly require executor_enabled_by_contract and process_start_armed_by_contract true without reconciling outer false fields"
  - id: A1-SC-0082
    type: os_overlap
    severity: s0
    detail: "A readiness projection duplicates a Codex-specific post-start execution operating system. It defines ordered release, signature, implementation, planning, enablement, supervised/guarded start, final authorization, rehearsal, envelope, and start-execution semantics; names canonical and scheduler runtime services; prescribes future mutations and operator receipt hashes; and establishes the next implementation slice. These lifecycle and authority rules belong to provider-neutral runtime owners, with Codex as an adapter."
    evidence:
      - "the 12 methods form an end-to-end real-invoker process-start chain"
      - "36 used imports are primarily paired Support runtime services and ControlPlane invokers"
      - "every contract prescribes allowed future mutations on provider-start and observed runs"
      - "required inputs include signatures, receipts, command/environment hashes, rollback, liveness, supervisor, and token/dispatch policy"
      - "the section owns provider-specific lifecycle ordering through next_required_slice"
actions:
  - op: BUGFIX_PLAN
    detail: "Repair both broken cross-section edges first: give AgentCodexSection its typed Schema dependency and replace the absent sibling call with a constructor-injected provider-registry query. Then fail closed on dependency exceptions, derive every contract state from predecessor/template results, and replace contradictory metadata authority names with explicit proposed/recorded/not-runtime-authorized semantics."
    target_paths:
      - app/Services/Ai/SelfConstruction/Readiness/ReadinessProjectionDispatchGateSection.php
      - app/Services/Ai/SelfConstruction/Readiness/ReadinessProjectionAgentCodexSection.php
      - app/Services/Ai/SelfConstruction/Readiness/ReadinessProjectionAgentDispatchProviderSection.php
      - app/Services/Ai/SelfConstruction/Readiness/AtlasSelfConstructionReadinessService.php
    acceptance:
      - "all 12 contract routes return a typed ready/blocked/degraded result, never a missing-class/method fatal"
      - "ready requires every declared predecessor/template invariant to pass"
      - "enabled/authorized/armed fields have one unambiguous runtime meaning across nested and outer envelopes"
  - op: TEST
    detail: "Add direct semantic characterization for all 12 methods before splitting. Cover section construction, every dependency edge, both current fatal regressions, blocked-source propagation, required-input schema validity, inner/outer authority agreement, stable hashes, side-effect freedom, next-slice validity, query depth, and payload budgets; retain command tests only for alias routing."
    target_paths:
      - tests/Feature/Ai/AtlasAiSelfConstructionReadinessProjectionDispatchGateSectionTest.php
      - tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php
    acceptance:
      - "all 12 methods have direct semantic coverage and all 12 command flags retain routing coverage"
      - "tests reproduce then reject both Schema and sibling-method failures"
      - "tests fail on blocked-source/ready-outer or inner-authorized/outer-disabled contradictions"
  - op: SPLIT
    detail: "Retire this section behind a temporary compatibility adapter. Move the ordered post-start lifecycle into one provider-neutral transition graph and bounded stage owners for release/signature, executor preparation, supervised/guarded activation, and final-start rehearsal/envelope/execution; Codex supplies typed adapter bindings only. No replacement PHP may exceed 2,000 LOC and hot facades stay at or below 800 LOC."
    target_paths:
      - app/Services/Ai/SelfConstruction/Readiness/ReadinessProjectionDispatchGateSection.php
      - app/Services/Ai/SelfConstruction/Readiness/AtlasSelfConstructionReadinessService.php
      - app/Services/Ai/SelfConstruction/ControlPlane
    acceptance:
      - "find app/Services/Ai/SelfConstruction -name '*.php' -print0 | xargs -0 wc -l | awk '$1 > 2000 {print}'"
      - "no provider-specific class owns the canonical process-start lifecycle"
      - "readiness dependencies are typed, acyclic, and one-way"
  - op: OWNER
    detail: "Assign provider-neutral owners for release authorization, executor planning/enablement, supervised and guarded start, final-start authorization, rehearsal, envelope, and execution gating. Assign a separate contract-schema owner for receipt/hash inputs and a projection owner for read-only status. Codex owns only adapter translation and invocation."
    target_paths:
      - docs/evidence/2026-07-22-atlas-server-god-debulk/OWNERSHIP.md
      - docs/engineering-knowledge-base/CODEMAP.md
    acceptance:
      - "every transition, policy predicate, receipt/signature validation, and future mutation has one owner"
      - "readiness cannot grant lifecycle authority or prescribe provider-specific runtime mutations"
      - "one canonical lifecycle graph serves all provider adapters"
  - op: EXTRACT
    detail: "Extract typed transition descriptors with stage id, predecessor ids, owner query, input schema, validated evidence hashes, authority effect, forbidden effects, next edge, and stable-hash material. Generate the 12 projections from that graph, collapse cumulative inputs into composed typed schemas, inject collaborators, and delete unrestricted reflection forwarding."
    target_paths:
      - app/Services/Ai/SelfConstruction/Readiness/ReadinessProjectionDispatchGateSection.php
      - app/Services/Ai/SelfConstruction/ControlPlane
    acceptance:
      - "duplicate, cyclic, ownerless, unreachable, or contradictory transitions fail registry validation"
      - "796 hand-copied input occurrences are replaced by composed schemas with explicit provenance"
      - "unknown dependencies fail at construction with typed errors"
  - op: CODEMAP
    detail: "Map all 12 facade aliases/command flags and 31 forwarded dependencies to canonical lifecycle stages, runtime owners, provider adapters, predecessor/status semantics, side effects, input-schema owners, hash compatibility, query cost, and migration disposition. Remove 192 unused imports and date compatibility aliases for deletion."
    target_paths:
      - app/Console/Commands/AtlasAiSelfConstructionCommand.php
      - app/Services/Ai/SelfConstruction/Readiness/AtlasSelfConstructionReadinessService.php
      - docs/engineering-knowledge-base/CODEMAP.md
    acceptance:
      - "all 12 routes have owner, caller, predecessors, input schema, side-effect class, and migration disposition"
      - "/opt/homebrew/bin/php artisan atlas:engineering:knowledge codemap-verify --json"
      - "unmapped reflection routes, duplicate vocabulary, and unused imports are removed"
  - op: PERF
    detail: "Benchmark cold/warm representative early, middle, and final contracts with projection depth, query/schema probes, allocations, hash work, payload bytes, p50, and p95. Evaluate the lifecycle graph against one immutable request snapshot and reuse predecessor results instead of recursively rebuilding cumulative payloads."
    target_paths:
      - app/Services/Ai/SelfConstruction/Readiness/ReadinessProjectionDispatchGateSection.php
      - tests/Feature/Ai/AtlasAiSelfConstructionReadinessProjectionDispatchGateSectionTest.php
    acceptance:
      - "benchmarks publish cold/warm p50 and p95 plus query, depth, allocation, payload, and hash budgets"
      - "one request evaluates each predecessor/status at most once"
      - "contract payload size does not grow quadratically with lifecycle depth"
caps: [A, B, C, D, E, F, G]
evidence:
  read_mode: full_file_sequential_no_skipped_lines
  line_range_read: 1-2355
  syntax: "No syntax errors detected by /opt/homebrew/bin/php -l"
  source_modified_during_meta: false
  source_sha256: 2ad7b6846284321c1deaddd41d721f389f44e67fa1bd6398ad72531b6bfa0081
  source_bytes: 166873
  public_method_count: 14
  public_domain_method_count: 12
  parent_facade_delegation_count: 12
  import_count: 228
  used_import_count: 36
  unused_import_count: 192
  unresolved_parent_method_name_count: 31
  unresolved_parent_call_occurrence_count: 42
  literal_ready_contract_count: 12
  literal_false_assignment_count: 241
  literal_true_assignment_count: 108
  stable_hash_call_count: 12
  non_execution_guarantee_block_count: 12
  required_input_block_count: 12
  required_input_field_occurrence_count: 796
  required_input_unique_field_count: 124
  required_input_min_per_contract: 50
  required_input_max_per_contract: 92
  runtime_contract_success_count: 0
  runtime_contract_schema_failure_count: 12
  runtime_after_schema_alias_success_count: 0
  runtime_after_schema_alias_sibling_failure_count: 12
  focused_contract_test_failure_count: 2
  focused_contract_test_assertion_count: 0
  next_file_by_loc: app/Services/Ai/SelfConstruction/NativeImplementation/AtlasSelfConstructionFinalOperatorEvidenceClosureCorridorService.php
```

```yaml
path: app/Services/Ai/SelfConstruction/NativeImplementation/AtlasSelfConstructionFinalOperatorEvidenceClosureCorridorService.php
loc: 2102
kind: final_operator_evidence_closure_workflow_projection_and_command_runbook
intent_axes: [1, 2, 3, 6, 7, 10, 14, 16, 17, 18, 19, 20, 21, 22, 23, 24, 25, 27, 28, 29, 33, 34, 36, 37, 38, 40, 41, 42, 45, 49, 50, 54, 55, 62, 64, 65, 66, 67, 68, 69]
findings:
  - id: A1-SC-0083
    type: godfile
    severity: s0
    detail: "This 2,102 LOC, 136,401-byte service uses one 443-line build method plus 44 private methods to assemble completion audit, evidence status, submission preflight, workspace inspection/publishing, a 16-step operator state machine, artifact schemas, command plan, handoff, runbook, shell packet, recovery matrix, readiness gate, progress meter, completion-claim policy, command-surface audit, and 15 nested hashes. The file is simultaneously projection, workflow owner, CLI documentation, safety policy, and I/O orchestrator."
    evidence:
      - "46 methods total: constructor, one public build entrypoint, and 44 private methods"
      - "build spans lines 38-480; orderedOperatorPath spans lines 1,451-1,774"
      - "16 pathStep calls, 219 data_get calls, 39 literal self-construction command occurrences, and 15 internal stableHash calls"
      - "eight concrete collaborators are constructed internally; only the readiness facade is injected"
      - "sha256=f806b76a9c5e6a8aae5c30f3e0bed6c2c6523029f3b2a4045b690758a15a3dcf"
  - id: A1-SC-0084
    type: false_abstraction
    severity: s1
    detail: "Four previous split commits left the god owner and added parallel helper layers instead of one boundary. Nine private forwarding methods have no internal caller, the same envelope/command/hash APIs coexist in this class, FinalOperatorClosureCorridor, and Support adapters, and fresh concrete helpers are still selected from private accessors. The extraction reduces individual method bodies but does not reduce ownership, construction fan-out, or representations of the workflow."
    evidence:
      - "nine private methods have zero $this caller: five command-surface wrappers, two envelope wrappers, and two hash wrappers"
      - "OperatorSubmissionEnvelopeBuilder exists both under FinalOperatorClosureCorridor and Support, while this class retains three private delegates"
      - "canonical hash and command-surface APIs likewise exist in old static helpers, new Support adapters, and private delegates"
      - "git history contains split-final-operator-closure-corridor-1 through -4, yet the source remains above the 2,000 LOC hard limit"
      - "submissionEnvelopeBuilder and hashSupport return new instances rather than constructor-owned collaborators"
  - id: A1-SC-0085
    type: bug
    severity: s0
    detail: "The post-extraction hash path is unreachable: hashSupport instantiates FinalOperatorClosureCorridorHashSupport without importing its Support namespace. PHP resolves the symbol inside NativeImplementation, where no such class exists. Any route that reaches the first of the 15 stableHash calls terminates with a class-not-found fatal instead of returning the advertised closure payload."
    evidence:
      - "this file has no use statement for FinalOperatorClosureCorridorHashSupport"
      - "the only class is App/Services/Ai/SelfConstruction/Support/FinalOperatorClosureCorridorHashSupport.php"
      - "direct private stable-hash characterization throws Class App\\Services\\Ai\\SelfConstruction\\NativeImplementation\\FinalOperatorClosureCorridorHashSupport not found at line 2,079"
      - "stableHash is reached by shell-packet verification, policy, progress, runbook, handoff, action, and final payload construction"
      - "PHP lint remains green because the missing class is a runtime resolution failure"
  - id: A1-SC-0086
    type: bug
    severity: s0
    detail: "The read-only entrypoint forwards publish_operator_draft_workspace and the caller's workspace path into a real publisher. With a valid finalized bundle, that collaborator copies three JSON artifacts via Storage::put. The corridor then still emits runtime_write_allowed=false, can_write_from_corridor=false, and non-execution guarantees, so the response can attest that no write occurred after causing writes."
    evidence:
      - "build passes draftWorkspacePublisherOptions into AtlasSelfConstructionOperatorEvidenceDraftWorkspacePublisherService::publish at line 62"
      - "the publisher reads publish_operator_draft_workspace and executes three Storage::put calls when the bundle is ready"
      - "publisher characterization proves 3 published artifacts with the explicit flag"
      - "the corridor's direct 21-test suite never passes publish_operator_draft_workspace"
      - "the class header says it is read-only and never closes blockers by itself"
  - id: A1-SC-0087
    type: perf
    severity: s0
    detail: "A no-option build did not return under the default 128 MiB PHP limit. In about 4.1 seconds it exhausted memory while reading task-packet storage; the stack entered completion audit, release dossier, readiness, AgentControlPlaneTaskQueueOrchestrator::prepareAndEnqueue, queue listing, and file_get_contents. The supposedly final read model therefore loads a mutating cross-OS graph and performs unbounded task-file work before it can construct its own payload."
    evidence:
      - "runtime result => Allowed memory size of 134217728 bytes exhausted; no payload returned"
      - "failing allocation originated in LocalFilesystemAdapter::read via AgentControlPlaneTaskPacketQueueRepository::list"
      - "stack includes AtlasSelfConstructionReadinessService::agentControlPlaneTaskQueueOrchestratorStatus -> prepareAndEnqueue"
      - "the direct corridor suite stayed near one full CPU core and about 207,200 KiB RSS, did not complete in 4m17s, and was terminated"
      - "no request snapshot, task-file cap, projection-depth budget, query/IO budget, or memoized dependency graph exists at this boundary"
  - id: A1-SC-0088
    type: bug
    severity: s1
    detail: "closureCorridorStatusCommand concatenates operator_draft_workspace_path and terminal-loop proof input directly into a copyable shell string with no tokenization or escaping. Semicolons, &&, whitespace, quotes, or substitutions survive verbatim. The shell-packet safety check only detects angle-bracket/path placeholders and does not reject shell control operators, so operator-reviewed output can become a command-injection carrier."
    evidence:
      - "lines 2,043-2,057 append both option values with raw string concatenation"
      - "reflection characterization generated: --operator-draft-workspace-path=storage/app/reviewed; id --agent-control-plane-terminal-loop-operational-proof-json=@proof.json && whoami --json"
      - "placeholderFields recognizes only <...> and @/path/to/... patterns"
      - "safe_to_copy_after_operator_review is derived from placeholder absence, not shell-argument safety"
      - "the direct test asserts raw context preservation but has no spaces/metacharacters/quote round-trip case"
  - id: A1-SC-0089
    type: doc_lie
    severity: s1
    detail: "Comments and emitted modes overstate both purity and the partial extraction. The file says it never writes and emits read_only/non-execution claims despite calling a flag-driven writer and a readiness route that reaches prepareAndEnqueue. Its ITEM8 accessor comments also promise lazy first-use allocation, but submissionEnvelopeBuilder and hashSupport return a new object on every call and hold no cached property."
    evidence:
      - "class docblock: read-only macro projection; does not close blockers by itself"
      - "payload mode is read_only_final_operator_evidence_closure_corridor and runtime_write_allowed is literal false"
      - "publisher call and release-dossier status graph contradict those declarations"
      - "hashSupport comment says no construction cost beyond first use while line 2,079 returns new every time"
      - "submissionEnvelopeBuilder makes the same first-use claim while line 1,444 returns new every time"
  - id: A1-SC-0090
    type: dupe
    severity: s2
    detail: "One 16-step closure workflow is hand-rendered repeatedly as orderedOperatorPath, operatorCommandPlan, artifact matrix, submission envelopes, next action, closure handoff, execution runbook, shell packet, verification bundle, failure recovery, readiness gate, progress meter, and completion policy. The file contains 39 command occurrences representing only 26 unique command fragments, and copies step/status/hash fields across nested arrays before hashing them again."
    evidence:
      - "orderedOperatorPath contains 16 hand-authored pathStep calls"
      - "operatorExecutionRunbook rebuilds every path step into step_cards"
      - "operatorClosureHandoff rebuilds every path step into full_ordered_command_sequence"
      - "operatorNextActionShellPacket and postActionVerificationBundle repeat commands and hashes again"
      - "39 literal command occurrences / 26 unique command fragments; 219 data_get calls"
  - id: A1-SC-0091
    type: test_gap
    severity: s1
    detail: "There are 21 direct corridor test methods, but they lock the large happy/blocked JSON shape while missing the three failures found by characterization: hash-helper resolution, mutation through the publish flag/default status graph, and shell-token safety. The separate publisher suite proves writes but still asserts runtime_write_allowed=false; it also currently finishes 6 passed / 1 failed because a declared quartet option no longer exists."
    evidence:
      - "direct corridor test reference count => 21 methods"
      - "zero publish_operator_draft_workspace occurrences in the direct corridor test"
      - "no test invokes stableHash in the corridor namespace after extraction"
      - "no command test uses whitespace, semicolon, ampersand, quote, or substitution input"
      - "publisher suite => 6 passed, 1 failed, 76 assertions; failure is missing --atlas-self-construction-operator-evidence-draft-workspace-publisher-contract"
  - id: A1-SC-0092
    type: os_overlap
    severity: s0
    detail: "A NativeImplementation read model owns a second final-operator operating system over completion authority. It decides artifact order, runtime promotion, real-provider smoke, human signature, workspace finalization/publication, persistence sequencing, terminal-loop proof, recovery, resume, final audit, and next-stage promotion while reaching the generic readiness and Agent Control Plane runtime graph. These rules duplicate the lifecycle owners they project and make a read-side class an authority over write-side behavior."
    evidence:
      - "16 ordered steps span refresh, draft/hash/persist, real provider activity, human receipt, workspace publish, audit, and promotion"
      - "the service embeds verifier class names, exact mutating commands, stop conditions, forbidden shortcuts, and produced artifacts"
      - "default build reaches Agent Control Plane queue orchestration through release-dossier readiness"
      - "the publisher path can stage canonical submission JSON from the same entrypoint"
      - "completion_allowed and completion_claim_allowed are projected alongside command/runbook ownership"
actions:
  - op: BUGFIX_PLAN
    detail: "Restore reachability first by importing/injecting the canonical hash owner. Then enforce a read-only option allowlist at this boundary, remove all mutating status calls, move workspace publication behind an explicit writer command, render commands from validated argv tokens, and return typed dependency failures instead of continuing with contradictory defaults."
    target_paths:
      - app/Services/Ai/SelfConstruction/NativeImplementation/AtlasSelfConstructionFinalOperatorEvidenceClosureCorridorService.php
      - app/Services/Ai/SelfConstruction/Support/FinalOperatorClosureCorridorHashSupport.php
      - app/Services/Ai/SelfConstruction/NativeImplementation/AtlasSelfConstructionOperatorEvidenceDraftWorkspacePublisherService.php
      - app/Services/Ai/SelfConstruction/Readiness/AtlasSelfConstructionReadinessService.php
    acceptance:
      - "a no-option build returns a typed payload under the configured memory/IO budget"
      - "read-only corridor execution performs zero Storage/DB/queue mutations for every option key"
      - "missing collaborators fail at construction or as typed blocked/degraded results, never class-not-found fatals"
      - "shell arguments with spaces and control characters round-trip as one inert argument"
  - op: TEST
    detail: "Add focused boundary tests before splitting: direct hash reachability, mutation spies across all accepted options, publish-flag rejection, default-build queue/write isolation, dependency-error propagation, bounded task-packet fixtures, shell metacharacter/property cases, command-option parity, and cold/warm memory/latency budgets. Keep schema characterization separate from semantic safety tests."
    target_paths:
      - tests/Feature/Ai/SelfConstruction/AtlasSelfConstructionFinalOperatorEvidenceClosureCorridorTest.php
      - tests/Feature/Ai/SelfConstruction/AtlasSelfConstructionOperatorEvidenceDraftWorkspacePublisherTest.php
      - tests/Feature/Ai/SelfConstruction/FinalOperatorClosureCorridorHashSupportTest.php
    acceptance:
      - "tests fail if the corridor writes storage, enqueues work, calls a mutator, or forwards a mutation flag"
      - "all emitted command argv values survive spaces/metacharacters without becoming operators"
      - "the focused suite completes inside explicit time/RSS/IO/query budgets"
      - "all exposed CLI flags exist and route to one canonical owner"
  - op: SPLIT
    detail: "Retire the god builder behind a short compatibility facade. Split into a pure completion snapshot reader, provider-neutral closure transition graph, artifact contract catalog, operator presentation projector, safe argv renderer, and separately authorized publication/persistence commands. No replacement PHP may exceed 2,000 LOC; hot facade/command/projector owners stay at or below 800 LOC."
    target_paths:
      - app/Services/Ai/SelfConstruction/NativeImplementation/AtlasSelfConstructionFinalOperatorEvidenceClosureCorridorService.php
      - app/Services/Ai/SelfConstruction/FinalOperatorClosureCorridor
      - app/Services/Ai/SelfConstruction/Support
      - app/Services/Ai/SelfConstruction/ControlPlane
    acceptance:
      - "find app/Services/Ai/SelfConstruction -name '*.php' -print0 | xargs -0 wc -l | awk '$1 > 2000 {print}'"
      - "projection dependencies are typed, one-way, and read-only"
      - "writers cannot be reached from snapshot/projector entrypoints"
  - op: OWNER
    detail: "Give one provider-neutral owner to completion evidence state, closure transitions, command rendering, and mutation authorization. Give workspace publication and evidence persistence distinct writer owners. Keep Codex/operator presentation as an adapter; readiness and the corridor may only consume immutable snapshots."
    target_paths:
      - docs/evidence/2026-07-22-atlas-server-god-debulk/OWNERSHIP.md
      - docs/engineering-knowledge-base/CODEMAP.md
    acceptance:
      - "each of the 16 transitions, three artifact families, hashes, commands, and mutations maps to exactly one owner"
      - "read models cannot import or instantiate writer/orchestrator implementations"
      - "completion authority remains solely in the canonical audited predicate"
  - op: EXTRACT
    detail: "Define one typed closure graph whose stage descriptors hold prerequisites, artifact schema, verifier, safe argv template, authorization effect, stop condition, evidence outputs, and next edge. Project path, runbook, handoff, shell packet, recovery, progress, and hashes from that graph and one immutable request snapshot instead of copying nested arrays."
    target_paths:
      - app/Services/Ai/SelfConstruction/FinalOperatorClosureCorridor
      - app/Services/Ai/SelfConstruction/NativeImplementation/AtlasSelfConstructionFinalOperatorEvidenceClosureCorridorService.php
    acceptance:
      - "duplicate, ownerless, cyclic, unreachable, or mutation-bearing read transitions fail graph validation"
      - "each dependency and stage is evaluated once per request snapshot"
      - "all public payload aliases have explicit compatibility mappings and removal dates"
  - op: DELETE
    detail: "After characterization and caller migration, delete the nine dead private wrappers, unused Artisan import, parallel static/Support helper copies, and stale quartet aliases. Preserve only one canonical implementation per hash, command-surface, envelope, and draft-workspace rule."
    target_paths:
      - app/Services/Ai/SelfConstruction/NativeImplementation/AtlasSelfConstructionFinalOperatorEvidenceClosureCorridorService.php
      - app/Services/Ai/SelfConstruction/FinalOperatorClosureCorridor
      - app/Services/Ai/SelfConstruction/Support
    acceptance:
      - "rg finds one implementation for every extracted helper method family"
      - "zero private methods without an internal caller and zero unused imports remain"
      - "compatibility payload and hash fixtures remain green"
  - op: CODEMAP
    detail: "Map the public build/status/CLI entrypoints, 16 stages, 26 unique command fragments, all nested status/hash aliases, eight constructed collaborators, mutation edges, completion authority, and compatibility helpers to Class::method owners. Explicitly mark read, command, writer, provider, and external-operator boundaries."
    target_paths:
      - docs/engineering-knowledge-base/CODEMAP.md
      - app/Console/Commands/Support/AtlasSelfConstructionMotherCommandSurface.php
    acceptance:
      - "every command and payload family has caller, owner, side-effect class, authorization predicate, and migration disposition"
      - "/opt/homebrew/bin/php artisan atlas:engineering:knowledge codemap-verify --json"
      - "no CLI quartet advertises a missing option"
  - op: PERF
    detail: "Benchmark no-option and representative artifact states with cold/warm latency, peak RSS, task files/bytes read, queries, projection depth, object construction, payload bytes, and hash work. Replace recursive status reconstruction with a bounded immutable snapshot and refuse oversized task/evidence inputs before loading whole files."
    target_paths:
      - app/Services/Ai/SelfConstruction/NativeImplementation/AtlasSelfConstructionFinalOperatorEvidenceClosureCorridorService.php
      - app/Services/Ai/SelfConstruction/ControlPlane/AgentControlPlaneTaskPacketQueueRepository.php
      - tests/Feature/Ai/SelfConstruction/AtlasSelfConstructionFinalOperatorEvidenceClosureCorridorTest.php
    acceptance:
      - "no characterization case exceeds the approved RSS, latency, query, file-count, byte-read, depth, or payload budget"
      - "one request evaluates each upstream snapshot and each stable hash at most once"
      - "oversized/corrupt task packets produce typed bounded failures, never process OOM"
caps: [A, B, C, D, E, F, G]
evidence:
  read_mode: full_file_sequential_no_skipped_lines
  line_range_read: 1-2102
  syntax: "No syntax errors detected by /opt/homebrew/bin/php -l"
  source_modified_during_meta: false
  source_sha256: f806b76a9c5e6a8aae5c30f3e0bed6c2c6523029f3b2a4045b690758a15a3dcf
  source_bytes: 136401
  total_method_count: 46
  public_build_method_count: 1
  private_method_count: 44
  dead_private_wrapper_count: 9
  build_method_loc: 443
  ordered_operator_path_loc: 324
  ordered_operator_path_step_count: 16
  internally_constructed_collaborator_count: 8
  data_get_call_count: 219
  internal_stable_hash_call_count: 15
  literal_self_construction_command_occurrence_count: 39
  unique_self_construction_command_fragment_count: 26
  literal_false_assignment_count: 49
  literal_true_assignment_count: 16
  direct_corridor_test_method_count: 21
  runtime_hash_helper_success_count: 0
  runtime_hash_helper_class_not_found_count: 1
  runtime_default_build_success_count: 0
  runtime_default_build_oom_count: 1
  runtime_default_build_memory_limit_bytes: 134217728
  runtime_default_build_elapsed_ms_approx: 4093
  focused_corridor_suite_completed: false
  focused_corridor_suite_terminated_after_seconds: 257
  focused_corridor_suite_child_rss_kib_at_termination: 207200
  publisher_suite_passed_test_count: 6
  publisher_suite_failed_test_count: 1
  publisher_suite_assertion_count: 76
  next_file_by_loc: app/Services/Ai/SelfConstruction/Readiness/ReadinessProjectionDurableReservationSection.php
```

```yaml
path: app/Services/Ai/SelfConstruction/Readiness/ReadinessProjectionDurableReservationSection.php
loc: 1956
kind: durable_reservation_planning_approval_blueprint_and_runtime_packet_projection_section
intent_axes: [1, 2, 3, 4, 6, 7, 10, 14, 15, 16, 17, 18, 19, 20, 21, 22, 24, 25, 27, 28, 29, 30, 33, 34, 36, 37, 38, 40, 41, 42, 45, 49, 50, 54, 55, 62, 64, 65, 66, 67, 68, 69]
findings:
  - id: A1-SC-0093
    type: godfile
    severity: s1
    detail: "At 1,956 LOC this section sits just below the generic 2,000 LOC cutoff while exposing 18 CLI-backed domain methods. It owns a ledger plan, AP candidate, approval request/template, two preflights, implementation packet, storage/repository/guard/lifecycle/projection contracts, five blueprints, and a runtime build packet. This is a planning document and approval operating system encoded as one runtime projection class."
    evidence:
      - "21 public methods total: constructor, setMother, __call, and 18 durableReservation domain methods"
      - "18 domain methods span lines 61-1,954"
      - "parent readiness facade retains 18 one-line public delegators"
      - "AtlasAiSelfConstructionMotherCommand exposes all 18 methods as CLI flags and human-output surfaces"
      - "sha256=af645d54e1d159907054de89a5fbf0c2e4f64c5bc9982e019d9d79ffa19e015e"
  - id: A1-SC-0094
    type: false_abstraction
    severity: s0
    detail: "The extracted section is still bidirectionally fused to the mother. It is created by the 29,744 LOC facade, rebound through setMother, and forwards five absent helper names across 26 static call sites with unrestricted ReflectionMethod. Fifteen copied imports remain, but only Closure is used, showing that the split moved a contiguous text family without establishing typed reservation, scope, completion-gate, or hot-scope owners."
    evidence:
      - "nullable mother, setMother, and unrestricted __call occupy lines 29-54"
      - "unresolved calls => hotForbiddenFiles 19, multiSessionReadinessGate 4, packetCompletionGate 1, reservationLedgerPreview 1, scopeValidator 1"
      - "15 imports / 1 used / 14 unused"
      - "18 parent facade delegators plus one private lazy section resolver remain"
      - "the section cannot evaluate any public domain method without the mother binding"
  - id: A1-SC-0095
    type: perf
    severity: s0
    detail: "durableReservationRuntimeBuildPacket recomputes five overlapping blueprint branches instead of evaluating one dependency graph. Static expansion yields 1,066 domain invocations, 1,066 stable hashes, and about 2,896 magic mother calls for one final packet; the base plan alone is rebuilt 445 times. The resulting CLI takes more than ten seconds even though its output is smaller than the base plan output."
    evidence:
      - "expanded call frequencies: plan 445, AP candidate 245, approval request 100, decision 50, post-approval preflight 50"
      - "total expanded domain invocations / stable hashes => 1,066"
      - "expanded mother calls => approximately 2,896, including repeated scope/gate/hot-file projections"
      - "runtime-build CLI => 10.61s, 134,807,552-byte max RSS, 167,714,503,711 retired instructions, 5,592 output bytes"
      - "ledger-plan CLI => 0.50s, 4,441,476,380 retired instructions, 6,179 output bytes"
  - id: A1-SC-0096
    type: bug
    severity: s0
    detail: "The hand-copied durable-reservation contract contradicts itself before implementation. The ledger plan requires three storage objects including atlas_self_construction_packet_snapshots, but storage schema and migration blueprint emit only two tables and silently drop snapshots. State vocabularies also drift: the plan uses available/renewed while storage schema substitutes preview and omits renewed. An executor cannot satisfy all emitted contracts simultaneously."
    evidence:
      - "ledger plan storage_object_count=3 at lines 69-119"
      - "storage schema table_count=2 at lines 739-798 and migration blueprint table_count=2 at lines 1,346-1,412"
      - "atlas_self_construction_packet_snapshots appears only in the initial plan"
      - "plan states => available, claimed, renewed, released, expired, completed, blocked"
      - "storage schema states => preview, claimed, released, expired, completed, blocked"
  - id: A1-SC-0097
    type: dishonest_name
    severity: s1
    detail: "Every public surface is labelled *_ready while the modeled workflow has no route to approval. ApprovalDecisionTemplate always creates null/pending signer slots; PostApprovalPreflight always inserts two blocked checks and literal preflight_passed=false; every downstream implementation/blueprint/build packet is still emitted as ready. Here ready means only that a speculative document was rendered, not that its prerequisites, approval, schema, repository, runtime, or implementation exist."
    evidence:
      - "18 domain returns contain ready-labelled top-level statuses; 177 literal false assignments dominate the surfaces"
      - "decision_status is template_not_signed and every signer slot is pending/null"
      - "post-approval preflight hard-codes approval_decision_signed and approved_for_scoped_implementation to blocked"
      - "no option accepts or verifies a signed approval decision"
      - "runtime build packet status is ready while build_status remains blocked_until_signed_approval_preflight_and_runtime_scope_approval"
  - id: A1-SC-0098
    type: doc_lie
    severity: s1
    detail: "The implementation packet directs future work to a source path that does not exist: app/Services/Ai/SelfConstruction/AtlasSelfConstructionReadinessService.php. The real facade lives under Readiness/. The packet presents that stale string as an allowed future scope and pairs it with one 19k-line command test, so an executor following the generated plan is sent to the wrong production location and a test-monster bottleneck."
    evidence:
      - "stale allowed_future_scope path appears at line 661"
      - "real source path is app/Services/Ai/SelfConstruction/Readiness/AtlasSelfConstructionReadinessService.php"
      - "AtlasAiSelfConstructionCommandTest.php is referenced as the primary future test and already exceeds 19,000 LOC"
      - "no path-existence or CODEMAP validation runs when the implementation packet is built"
  - id: A1-SC-0099
    type: dupe
    severity: s2
    detail: "The same reservation vocabulary is manually restated through 18 cumulative projections: tables, fields, states, transitions, blockers, methods, commands, future files, tests, forbidden scopes, and literal safety booleans. The file repeats hotForbiddenFiles 19 times, stableHash 18 times, data_get 54 times, and false 177 times; downstream methods copy only predecessor hashes and then restate another near-identical contract."
    evidence:
      - "19 hotForbiddenFiles calls and 18 stableHash calls in source"
      - "177 literal false assignments versus 13 true assignments"
      - "repository methods, collision decisions, lease states, and readiness queue states are repeated in contract and blueprint variants"
      - "schema, migration, repository, guard, lifecycle, projection, and runtime packets each repeat the same non-execution envelope"
      - "manual duplication already produced the packet-snapshot and state-vocabulary drift"
  - id: A1-SC-0100
    type: test_gap
    severity: s1
    detail: "The dedicated section suite is structural only: four tests verify resolvability, method count, facade delegation, and lazy resolution without invoking one domain contract. The giant command suite does execute the final packet and passes, but asserts output literals rather than dependency evaluation counts, schema consistency, real approval reachability, path validity, or budgets; the passing final test takes 10.97 seconds by itself."
    evidence:
      - "dedicated section suite => 4 passed / 8 assertions / zero domain method invocations"
      - "focused runtime-build command test => 1 passed / 24 assertions / 10.97s"
      - "no test asserts packet_snapshots survives schema/migration projection"
      - "no test rejects ready when approval is unverified or checks a signed-decision input path"
      - "no test caps expanded calls, stable hashes, mother calls, latency, RSS, or instructions"
  - id: A1-SC-0101
    type: os_overlap
    severity: s0
    detail: "This SelfConstruction section duplicates an entire durable-reservation OS already represented by 16 AAEOS Quarantine services and their generated contract tests. Both families encode ledger plan, AP/approval, schema, repository, collision, lease, readiness, preflight, and blueprint semantics, but they differ in closed storage/state vocabularies and command names. Keeping both makes ownership, quarantine status, and future implementation authority undecidable."
    evidence:
      - "16 app/Services/Ai/Aaeos/Quarantine/AtlasDurableReservation*.php files total 8,025 LOC"
      - "eight generated AAEOS durable-reservation tests total 1,972 LOC"
      - "AAEOS plan declares a closed three-table storage set including packet snapshots"
      - "SelfConstruction exposes 18 parallel CLI surfaces through the readiness facade"
      - "no live SelfConstruction Reservations implementation or matching migrations exist; only planning/projection families are present"
actions:
  - op: BUGFIX_PLAN
    detail: "Define one canonical closed reservation schema/state vocabulary, restore or explicitly remove packet snapshots with a migration decision, validate every generated path/flag, and make post-approval preflight consume a typed verified approval receipt. Top-level status must derive from prerequisite truth rather than artifact-render success."
    target_paths:
      - app/Services/Ai/SelfConstruction/Readiness/ReadinessProjectionDurableReservationSection.php
      - app/Services/Ai/Aaeos/Quarantine
      - app/Services/Ai/SelfConstruction/Readiness/AtlasSelfConstructionReadinessService.php
    acceptance:
      - "plan, schema, migration, repository, lifecycle, and readiness contracts share one validated table/field/state registry"
      - "approved status is unreachable without a cryptographically/currently bound approval receipt"
      - "every emitted path and CLI flag resolves to a mapped live or explicitly future-owned target"
  - op: TEST
    detail: "Add direct semantic tests for all 18 entrypoints against one fake typed dependency snapshot. Cover schema/state consistency, packet-snapshot disposition, signed/expired/drifted approval receipts, predecessor blocker propagation, path/flag validation, one-evaluation-per-node, deterministic hashes, and cold/warm latency/RSS/instruction budgets. Move this family out of the 19k command-test host."
    target_paths:
      - tests/Feature/Ai/AtlasAiSelfConstructionReadinessProjectionDurableReservationSectionTest.php
      - tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php
      - tests/Feature/Ai/SelfConstruction
    acceptance:
      - "all 18 methods have direct semantic characterization and only thin CLI routing remains in the command test"
      - "tests fail on schema/state/path/approval drift"
      - "runtime-build packet evaluates each dependency node once and stays inside published performance budgets"
  - op: SPLIT
    detail: "Before any fusion, remove this family from the mother back-reference graph. Keep a temporary facade alias only; introduce typed immutable inputs for reservation policy, completion gate, scope, approval, and hot-scope registry. Hot CLI/projector owners stay at or below 800 LOC and no replacement PHP may exceed 2,000 LOC."
    target_paths:
      - app/Services/Ai/SelfConstruction/Readiness/ReadinessProjectionDurableReservationSection.php
      - app/Services/Ai/SelfConstruction/Readiness/AtlasSelfConstructionReadinessService.php
    acceptance:
      - "zero setMother/__call/ReflectionMethod dependency edges remain"
      - "facade compatibility aliases have a one-cycle removal date"
      - "typed construction fails when any required snapshot/registry is absent"
  - op: OWNER
    detail: "Choose one owner for durable reservation law before implementation: reservation domain policy owns schema/state/claim/lease/collision; approval governance owns signed decisions; read-side projection owns status; command adapters own presentation. Explicitly dispose of or archive the AAEOS Quarantine twin instead of promoting both."
    target_paths:
      - docs/evidence/2026-07-22-atlas-server-god-debulk/OWNERSHIP.md
      - docs/evidence/2026-07-22-atlas-server-god-debulk/ARCH-BLUEPRINTS
      - app/Services/Ai/Aaeos/Quarantine
      - app/Services/Ai/SelfConstruction
    acceptance:
      - "one canonical owner and one implementation of every durable-reservation invariant"
      - "quarantine services cannot be live owners or command targets"
      - "approval, policy, projection, and storage I/O remain one-way boundaries"
  - op: EXTRACT
    detail: "Replace the cumulative method chain with a validated DAG/catalog evaluated once per request snapshot. Each node declares id, prerequisites, schema/state registry version, approval predicate, owner, output/hash projection, and blocker propagation; render plan, packet, schema, blueprint, and CLI views from that single graph."
    target_paths:
      - app/Services/Ai/SelfConstruction/Reservations
      - app/Services/Ai/SelfConstruction/Readiness/ReadinessProjectionDurableReservationSection.php
    acceptance:
      - "runtime build performs 18 or fewer node evaluations/hashes rather than 1,066"
      - "duplicate, cyclic, ownerless, unreachable, or inconsistent nodes fail validation"
      - "views cannot redefine tables, fields, states, transitions, or approval semantics"
  - op: FUSE
    detail: "After parent split and ownership decision, fuse same-owner plan/AP/approval and contract/blueprint view peels into a small catalog plus view renderer; do not retain 18 public behavior methods solely to expose phases of one never-executed planning ladder. Quarantine twins are deleted or archived, not fused into a new godfile."
    target_paths:
      - app/Services/Ai/SelfConstruction/Readiness/ReadinessProjectionDurableReservationSection.php
      - app/Services/Ai/Aaeos/Quarantine
      - app/Console/Commands/AtlasAiSelfConstructionMotherCommand.php
    acceptance:
      - "CLI exposes a bounded documented reservation family rather than 18 sibling flags"
      - "navigation from command to canonical reservation owner is at most three hops"
      - "LOC, method count, command count, and call depth all decrease without new oversized hosts"
  - op: DELETE
    detail: "Delete 14 unused imports, stale path literals, dead compatibility flags, duplicate quarantine services/tests, and copied ready/non-execution envelopes only after ownership and characterization prove which family is canonical."
    target_paths:
      - app/Services/Ai/SelfConstruction/Readiness/ReadinessProjectionDurableReservationSection.php
      - app/Services/Ai/Aaeos/Quarantine
      - tests/Unit/Ai/Aaeos/Generated
    acceptance:
      - "one durable-reservation contract/test family remains"
      - "zero unused imports and zero emitted nonexistent paths"
      - "no deleted command has an unmigrated caller"
  - op: CODEMAP
    detail: "Map all 18 flags/facade aliases and the AAEOS twins to canonical Class::method owners, schema/state registry, approval authority, consumers, status semantics, performance cost, quarantine disposition, and migration alias."
    target_paths:
      - docs/engineering-knowledge-base/CODEMAP.md
      - app/Console/Commands/AtlasAiSelfConstructionMotherCommand.php
    acceptance:
      - "every surface has owner, caller, truth predicate, side-effect class, performance budget, and disposition"
      - "/opt/homebrew/bin/php artisan atlas:engineering:knowledge codemap-verify --json"
      - "no ownership row points to both SelfConstruction and AAEOS Quarantine"
  - op: PERF
    detail: "Benchmark every graph view with node evaluations, hash calls, mother calls, instructions, wall/user/sys time, peak RSS, and output bytes. Cache only immutable request-scoped node results; never cache across schema/approval/scope version changes."
    target_paths:
      - app/Services/Ai/SelfConstruction/Readiness/ReadinessProjectionDurableReservationSection.php
      - tests/Feature/Ai/SelfConstruction
    acceptance:
      - "runtime-build is near-linear in graph nodes and each node evaluates once"
      - "published cold/warm budgets cover latency, RSS, instructions, calls, hashes, and bytes"
      - "runtime-build overhead is proportional to its 5-source output, not hundreds of repeated base plans"
caps: [A, B, C, D, E, F, G]
evidence:
  read_mode: full_file_sequential_no_skipped_lines
  line_range_read: 1-1956
  syntax: "No syntax errors detected by /opt/homebrew/bin/php -l"
  source_modified_during_meta: false
  source_sha256: af645d54e1d159907054de89a5fbf0c2e4f64c5bc9982e019d9d79ffa19e015e
  source_bytes: 90993
  total_public_method_count: 21
  public_domain_method_count: 18
  parent_facade_delegation_count: 18
  import_count: 15
  used_import_count: 1
  unused_import_count: 14
  unresolved_mother_method_name_count: 5
  unresolved_mother_call_occurrence_count: 26
  data_get_call_count: 54
  direct_stable_hash_call_count: 18
  literal_false_assignment_count: 177
  literal_true_assignment_count: 13
  ready_label_occurrence_count: 19
  hot_forbidden_files_call_count: 19
  expanded_runtime_build_domain_invocation_count: 1066
  expanded_runtime_build_stable_hash_count: 1066
  expanded_runtime_build_mother_call_count_approx: 2896
  runtime_build_cli_wall_seconds: 10.61
  runtime_build_cli_max_rss_bytes: 134807552
  runtime_build_cli_instructions_retired: 167714503711
  runtime_build_cli_output_bytes: 5592
  ledger_plan_cli_wall_seconds: 0.50
  ledger_plan_cli_instructions_retired: 4441476380
  ledger_plan_cli_output_bytes: 6179
  dedicated_section_test_passed_count: 4
  dedicated_section_test_assertion_count: 8
  focused_runtime_build_test_passed_count: 1
  focused_runtime_build_test_assertion_count: 24
  focused_runtime_build_test_duration_seconds: 10.97
  aaeos_quarantine_duplicate_service_count: 16
  aaeos_quarantine_duplicate_service_loc: 8025
  aaeos_generated_duplicate_test_count: 8
  aaeos_generated_duplicate_test_loc: 1972
  next_file_by_loc: app/Services/Ai/SelfConstruction/AgentControlPlaneTaskQueueOrchestrator.php
```

```yaml
path: app/Services/Ai/SelfConstruction/AgentControlPlaneTaskQueueOrchestrator.php
loc: 1937
kind: persistent_task_queue_admission_serving_repair_lease_and_completion_orchestrator
intent_axes: [2, 3, 5, 6, 7, 8, 13, 14, 15, 16, 17, 18, 19, 20, 21, 22, 23, 24, 25, 27, 28, 29, 33, 35, 36, 37, 38, 40, 42, 45, 49, 54, 55, 62, 64, 66, 67, 68, 69]
findings:
  - id: A1-SC-0102
    type: godfile
    severity: s1
    detail: "At 1,937 LOC this final class sits 63 lines below the generic godfile threshold and exposes 42 methods across admission, anti-farm policy, dependency classification, queue serving, repair sweeps, leases, give-back, behavior learning, scope expansion, quarantine, terminal resolution, and completion evidence. The near-threshold count understates the dependency surface: 43 other app files and 51 test files name it."
    evidence:
      - "19 public methods and 23 private methods"
      - "45 queue-repository calls, 12 lease-repository calls, and 33 envelope calls"
      - "44 app references including the definition; 51 test-file references; 95 total reference files"
      - "sha256=31b8123830c20ce49bf88a36e4d82afba5d41e1331d84c81239c23749b86070a"
  - id: A1-SC-0103
    type: doc_lie
    severity: s1
    detail: "Every response hard-codes ledger_write_allowed=false and task_queue_orchestrator_does_not_write_ledger, while the class durably appends queue receipts, a resolved-receipt JSONL, learning-transfer facts, and worker-behavior ledger events. The opening contract narrows the claim to the evidence ledger, but the machine-readable guarantee is generic and is asserted by tests even on mutating entrypoints."
    evidence:
      - "envelope lines 1907-1,937 always emit ledger_write_allowed=false and task_queue_orchestrator_does_not_write_ledger"
      - "16 queue appendReceipt calls plus JsonlReceiptStore::appendWith in markResolved"
      - "bridgeOutcomeToLearning admits learning facts and recordWorkerBehavior writes AtlasMaestroWorkerBehaviorLedger"
      - "unit and feature suites explicitly assert the false ledger guarantee"
  - id: A1-SC-0104
    type: bug
    severity: s0
    detail: "markResolved makes a public terminal transition on an arbitrary non-empty string presented as commitSha. It deliberately bypasses structured completion evidence and never validates hash syntax, resolves the object in Git, binds changed files to allowed_files, or verifies the claimed tests. Its safety argument exists only in a caller comment; the terminal owner itself accepts abc123 and persists completed_dry_run."
    evidence:
      - "markResolved lines 1,600-1,697 validates queue/lease/agent binding but not commit identity or scope"
      - "comment lines 1,593-1,596 says the commit itself is proof and assumes AtlasTaskScopedCommitter already enforced scope"
      - "tests call markResolved directly with abc123/abc123def/deadbeef and expect task_resolved"
      - "focused test run passed the abc123 terminal transition and replay contract"
  - id: A1-SC-0105
    type: bug
    severity: s0
    detail: "Terminal and repair workflows cross two independently locked repositories plus receipt stores without a transaction, journal, or checked compensation. markResolved releases authority before checking the queue transition and still appends a resolved receipt and learning fact; completeDryRun releases, appends completion proof, then updates status; scope expansion releases, blocks, replaces, and appends. A failure between steps can strand queue/lease truth or publish success evidence for a transition that did not occur."
    evidence:
      - "markResolved lines 1,649-1,697 ignores release status and does not gate receipts/learning on transition status"
      - "completeDryRun lines 1,809-1,847 appends dry_run_completion_recorded before updateStatus"
      - "requestScopeExpansion lines 1,422-1,453 ignores release and blocked-transition results before replacement"
      - "releaseLease, reportGiveBack, and quarantineClaimed repeat release -> update -> receipt sequences"
      - "repository methods each own separate file locks; there is no encompassing unit of work"
  - id: A1-SC-0106
    type: bug
    severity: s0
    detail: "Dependency safety is explicitly fail-open: a missing dependency record and a detected dependency cycle are both treated as satisfied, while cancelled is also a satisfied state. The queue can therefore serve a task whose prerequisite vanished or whose graph is structurally invalid, converting dependency corruption into execution order instead of quarantine/operator repair."
    evidence:
      - "AgentControlPlaneTaskDependencyClassifier classifies absent nodes and cycles as met"
      - "DEPENDENCY_SATISFIED_STATES contains completed_dry_run and cancelled"
      - "orchestrator claimNext delegates this verdict before leasing the candidate"
      - "focused tests prove absent_dependency_ids/cycle_broken_dependency_ids are visible while verdict remains met"
  - id: A1-SC-0107
    type: bug
    severity: s1
    detail: "Safety and recovery failures are swallowed at admission and serving boundaries. An exception in either anti-farm gate records only its message and still enqueues; an exception while reaping expired leases is silently ignored and claim selection continues; learning and behavior writes are also swallowed. The most important duplicate, recovery, and feedback controls therefore degrade without a typed health state or operator-visible durable failure."
    evidence:
      - "prepareAndEnqueue lines 83-103 catches Throwable and proceeds to queue->enqueue"
      - "reapExpiredBeforeListing lines 382-396 catches Throwable without emitting a result field"
      - "eight catch(Throwable) sites exist in the file"
      - "anti-farm fail-open is described as intentional in the source but has no failure-mode gate"
  - id: A1-SC-0108
    type: perf
    severity: s0
    detail: "Hot admission and serving paths repeatedly materialize the filesystem-backed queue. Anti-farm admission loads every claimable packet and performs two per-candidate similarity passes; claim, servability, malformed repair, forbidden-target repair, scope repair, and cooldown each invoke another full list. A default closure build observed this exact anti-farm list/read stack exhausting the 128 MiB PHP limit after about 4.1 seconds."
    evidence:
      - "seven queue->list calls in the orchestrator"
      - "checkAntiFarmGates lines 174-255 is O(N) assessments after queue list materialization"
      - "queue repository list reads task files from local storage; no bounded page/index contract is accepted here"
      - "observed fatal stack reached checkAntiFarmGates via queue list/readTaskFile/file_get_contents at 134,217,728-byte limit"
      - "claimNext plus servability/repair/cooldown paths perform additional queue-wide scans"
  - id: A1-SC-0109
    type: false_abstraction
    severity: s1
    detail: "Three clusters were extracted into static helper classes, but this class retains forwarding wrappers, repository closures, policy sequencing, and every mutable lifecycle. Dependency classification, scope repair, and evidence validation moved code without creating typed owner interfaces or shrinking the orchestrator below the threshold; concrete learning/behavior/storage collaborators are still constructed inline."
    evidence:
      - "wrappers delegate to AgentControlPlaneTaskDependencyClassifier, AgentControlPlaneScopeRepairInputRebuilder, and AgentControlPlaneCompletionEvidenceValidator"
      - "orchestrator still owns 42 methods and 1,937 LOC after three split commits"
      - "new AtlasTaskPacketQualityInspector, JsonlReceiptStore, learning orchestrator/ledger, and worker behavior ledger bypass injection"
      - "git history contains split-acp-task-queue-1/2/3 but the lifecycle hub remains mandatory"
  - id: A1-SC-0110
    type: test_gap
    severity: s1
    detail: "The broad duplicated unit/feature suites characterize happy paths and replay but normalize unsafe contracts instead of challenging them. They explicitly accept six-to-nine-character fake commit ids and cyclic fail-open, assert the false no-ledger guarantee, and provide no injected failure between lease release, queue transition, receipt append, JSONL append, or learning admission."
    evidence:
      - "focused dependency/resolve run => 4 passed / 13 assertions / 0.49s"
      - "no Mockery/createMock/failure-injection seam appears in the two orchestrator suites or scope-expansion suite"
      - "tests assert abc123 resolves and a second different string is blocked only after the first terminal write"
      - "no test proves rollback/reconciliation after partial multi-store failure"
      - "no test imposes queue-size, memory, IO, or latency budgets"
  - id: A1-SC-0111
    type: os_overlap
    severity: s0
    detail: "One SelfConstruction orchestrator is simultaneously the task registry, admission court, dependency scheduler, repair daemon, lease coordinator, scope-change workflow, completion authority, outcome-learning bridge, and Maestro behavior-feedback writer. These are separate consistency and policy authorities with different failure semantics, yet 43 application consumers converge on this class and its queue/lease file stores."
    evidence:
      - "class imports ControlPlane, TaskQueue, LearningTransfer, Maestro Adaptive/Health, EngineeringKernel storage, and AutonomousEvolution guard concerns"
      - "public API spans prepare, claim, lease, give-back, repair, scope expansion, quarantine, task scope, resolve, and dry-run completion"
      - "behavior demotion changes future scheduling based on an inline-written external ledger"
      - "the class is directly named by 44 app files including the definition"
actions:
  - op: BUGFIX_PLAN
    detail: "Make terminal truth fail-closed: resolve a canonical Git object, bind it to the queue packet and allowed-file diff, require the authoritative scoped-commit/evidence receipt, and replace release/update/receipt chains with a durable state machine plus idempotent reconciliation. Missing/cyclic dependencies and admission/recovery exceptions become typed blocked/quarantined states."
    target_paths:
      - app/Services/Ai/SelfConstruction/AgentControlPlaneTaskQueueOrchestrator.php
      - app/Services/Ai/SelfConstruction/ControlPlane/AgentControlPlaneTaskPacketQueueRepository.php
      - app/Services/Ai/SelfConstruction/ControlPlane/AgentControlPlaneClaimLeaseRepository.php
      - app/Services/Ai/SelfConstruction/TaskQueue/AgentControlPlaneTaskDependencyClassifier.php
    acceptance:
      - "arbitrary, missing, unreachable, wrong-tree, wrong-scope, or unverified commit ids cannot terminally resolve a packet"
      - "no terminal/scope/give-back API reports success or appends success proof after a failed prerequisite mutation"
      - "missing/cyclic dependencies and anti-farm/recovery exceptions remain non-servable until explicit repair"
  - op: TEST
    detail: "Add direct failure-injection characterization for every multi-store step, commit/scope/evidence binding, dependency corruption, admission/reaper exceptions, stale lease races, replay, and reconciliation. Consolidate the duplicated unit/feature hosts after preserving distinct boundary coverage."
    target_paths:
      - tests/Unit/Ai/SelfConstruction/AgentControlPlaneTaskQueueOrchestratorTest.php
      - tests/Feature/Ai/AtlasAiSelfConstructionAgentControlPlaneTaskQueueOrchestratorTest.php
      - tests/Unit/Ai/SelfConstruction/AgentControlPlaneTaskDependencyClassifierTest.php
      - tests/Unit/Ai/SelfConstruction/AtlasTaskScopeExpansionTest.php
    acceptance:
      - "every injected failure leaves one recoverable, non-terminal canonical state and no false-success receipt"
      - "fake commit ids and dependency corruption fail before lease authority is released"
      - "tests separately prove queue, lease, receipt, learning, and Git failure semantics"
  - op: SPLIT
    detail: "Split by lifecycle authority before any fusion: admission, dependency scheduling, serving/lease coordination, packet repair, scope-change workflow, and completion reconciliation. Keep a temporary compatibility facade below 800 LOC; every extracted owner stays below 800 hot-path LOC and no replacement exceeds 2,000 LOC."
    target_paths:
      - app/Services/Ai/SelfConstruction/AgentControlPlaneTaskQueueOrchestrator.php
      - app/Services/Ai/SelfConstruction/TaskQueue
      - app/Services/Ai/SelfConstruction/ControlPlane
    acceptance:
      - "the compatibility facade sequences typed results but owns no policy, scans, storage construction, or catch-all recovery"
      - "each lifecycle invariant has one owner and one failure vocabulary"
      - "all 43 application consumers migrate or retain a dated thin alias"
  - op: OWNER
    detail: "Assign queue-state law, lease authority, dependency policy, admission policy, completion proof, repair/reconciliation, learning transfer, and behavior scoring to distinct canonical owners. The completion owner, not its caller, must verify every terminal invariant."
    target_paths:
      - docs/evidence/2026-07-22-atlas-server-god-debulk/OWNERSHIP.md
      - app/Services/Ai/SelfConstruction/TaskQueue
      - app/Services/Ai/SelfConstruction/ControlPlane
      - app/Services/Ai/SelfConstruction/LearningTransfer
      - app/Services/Ai/SelfConstruction/Maestro
    acceptance:
      - "one owner is authoritative for each state transition and receipt vocabulary"
      - "learning/behavior projection cannot mutate queue or decide terminal truth"
      - "provider/CLI callers cannot bypass commit/evidence authorization"
  - op: EXTRACT
    detail: "Introduce one typed transition intent/result and append-only operation journal with expected queue version, lease version, actor, packet hash, evidence/commit binding, planned effects, applied effects, and reconciliation status. Use indexed snapshots for candidate selection and anti-farm lookup rather than full packet-file scans."
    target_paths:
      - app/Services/Ai/SelfConstruction/TaskQueue
      - app/Services/Ai/SelfConstruction/ControlPlane
    acceptance:
      - "each lifecycle request has one idempotency key and one reconstructible transition journal"
      - "partial application is detected and reconciled before the packet is servable"
      - "admission and claim use bounded/indexed reads with published complexity"
  - op: FUSE
    detail: "After the owner split, fuse only same-owner forwarding wrappers into their typed policy/value objects. Do not fuse queue, lease, completion, learning, and repair into another orchestration godfile."
    target_paths:
      - app/Services/Ai/SelfConstruction/TaskQueue/AgentControlPlaneTaskDependencyClassifier.php
      - app/Services/Ai/SelfConstruction/TaskQueue/AgentControlPlaneScopeRepairInputRebuilder.php
      - app/Services/Ai/SelfConstruction/TaskQueue/AgentControlPlaneCompletionEvidenceValidator.php
    acceptance:
      - "zero private pass-through wrappers remain without an invariant or second consumer"
      - "fusion lowers hops and LOC without crossing owner boundaries"
  - op: DELETE
    detail: "Delete generic false no-ledger guarantees, duplicated test copies, stale compatibility wrappers, and direct concrete collaborator construction only after typed replacements and characterization prove payload compatibility."
    target_paths:
      - app/Services/Ai/SelfConstruction/AgentControlPlaneTaskQueueOrchestrator.php
      - tests/Unit/Ai/SelfConstruction/AgentControlPlaneTaskQueueOrchestratorTest.php
      - tests/Feature/Ai/AtlasAiSelfConstructionAgentControlPlaneTaskQueueOrchestratorTest.php
    acceptance:
      - "machine-readable envelopes truthfully enumerate performed writes and their receipts"
      - "one canonical test owner remains per boundary case"
      - "no deleted alias has an unmigrated caller"
  - op: CODEMAP
    detail: "Map all 19 public methods and 43 application consumers to canonical transition owners, side effects, locks, receipts, failure semantics, idempotency keys, and migration aliases. Include the AtlasTaskServingService commit-to-resolution path and every readiness/command consumer."
    target_paths:
      - docs/engineering-knowledge-base/CODEMAP.md
      - app/Services/Ai/SelfConstruction/AtlasTaskServingService.php
      - app/Services/Ai/SelfConstruction/AgentControlPlaneTaskQueueOrchestrator.php
    acceptance:
      - "every public method has owner, caller, inputs, writes, locks, proof predicate, and failure state"
      - "navigation from surface to transition owner is at most three hops"
      - "/opt/homebrew/bin/php artisan atlas:engineering:knowledge codemap-verify --json"
  - op: PERF
    detail: "Benchmark admission, claim, servability, repair sweeps, and completion at controlled queue sizes. Publish latency, peak RSS, task-file reads, bytes, lock hold time, similarity assessments, and recovery work; enforce bounded pages/indexes and no request-path full queue materialization."
    target_paths:
      - app/Services/Ai/SelfConstruction/AgentControlPlaneTaskQueueOrchestrator.php
      - app/Services/Ai/SelfConstruction/ControlPlane/AgentControlPlaneTaskPacketQueueRepository.php
      - tests/Feature/Ai/SelfConstruction
    acceptance:
      - "admission and claim stay within explicit cold/warm budgets at 10, 100, 1,000, and 10,000 packets"
      - "no ordinary request reads every task packet or exhausts the configured PHP memory limit"
      - "performance tests expose scans, file reads, bytes, wall time, RSS, and lock contention"
caps: [A, B, C, D, E, F, G]
evidence:
  read_mode: full_file_sequential_no_skipped_lines
  line_range_read: 1-1937
  syntax: "No syntax errors detected by /opt/homebrew/bin/php -l"
  source_modified_during_meta: false
  source_sha256: 31b8123830c20ce49bf88a36e4d82afba5d41e1331d84c81239c23749b86070a
  source_bytes: 95378
  public_method_count: 19
  private_method_count: 23
  catch_throwable_count: 8
  queue_repository_call_count: 45
  lease_repository_call_count: 12
  envelope_call_count: 33
  data_get_call_count: 77
  queue_list_call_count: 7
  app_reference_file_count_including_definition: 44
  test_reference_file_count: 51
  total_reference_file_count: 95
  focused_test_passed_count: 4
  focused_test_assertion_count: 13
  focused_test_duration_seconds: 0.49
  observed_oom_memory_limit_bytes: 134217728
  observed_oom_elapsed_ms_approx: 4093
  next_file_by_loc: app/Services/Ai/SelfConstruction/NativeImplementation/AtlasSelfConstructionOperatorEvidenceSubmissionReadinessService.php
```

## Bucket rollup

```markdown
- files_scanned: 12 / 1210
- lines_scanned: 98942
- s0..s3: 50 / 42 / 19 / 0
- intent_axes_covered: [1, 2, 3, 4, 5, 6, 7, 8, 10, 13, 14, 15, 16, 17, 18, 19, 20, 21, 22, 23, 24, 25, 27, 28, 29, 30, 33, 34, 35, 36, 37, 38, 40, 41, 42, 45, 49, 50, 54, 55, 62, 64, 65, 66, 67, 68, 69]
- intent_axes_missing_in_this_bucket: [9, 11, 12, 26, 31, 32, 39, 43, 44, 46, 47, 48, 51, 52, 53, 56, 57, 58, 59, 60, 61, 63]
- ownership_proposal: "thin compatibility facades -> read-only bounded readiness owners + provider-neutral post-start transition graph + provider-neutral runtime command/writer owner + typed capability registry/certifier + next-work graph selector + reservation/liveness snapshot owner + workspace-governance owner + certification workbench owner + completion evidence owner + provider-neutral review/merge lifecycle owner + scheduler/dispatch state owners + cryptographically verified receipt-authorization owner + executor-release policy owner + provider/adapter capability owners + human-signature workflow owner + authorization-persistence owner + canonical packet-path validator + Codex adapter"
- ordered_worklist: ["BUGFIX_PLAN transactional queue/lease/receipt completion, verified commit binding, fail-closed dependency/admission/recovery, durable-reservation schema/state/approval/path truth, closure-corridor hash reachability, read-only mutation isolation, bounded task-packet reads, safe argv rendering, 12 DispatchGate fatal routes, AgentCodex Schema/sibling reachability, ControlPlane receiver/existence classifier, duplicated/dead next-slice state machine, seven DispatchProvider imports, receipt authorization, packet paths, evidence graph, local-main policy, and ready-versus-blocked semantics", "TEST queue transition failure injection and scale budgets, 18 durable-reservation semantic/budget contracts outside the command-test monster, closure corridor mutation/hash/shell/memory boundaries, 12 post-start gate contracts, 253 ControlPlane surface entries, all 171 Codex execution, 109 agent review/merge, 149 Codex review/merge, 100 numbered automatic-dispatch, and 39 dispatch/provider contracts", "SPLIT task queue lifecycle and parent/monster sections by authority", "OWNER queue state, lease authority, dependency policy, completion reconciliation, learning, one durable-reservation law outside AAEOS quarantine, provider-neutral completion closure, post-start lifecycle, capability certification, next-work selection, workspace governance, readiness, review/merge, scheduler, dispatch, receipt authorization, executor release, provider/adapter capabilities, external process, executor/process supervision, human signature, publication, persistence, session, evidence, and I/O authorities", "EXTRACT journaled typed transitions, bounded candidate indexes, validated capability/transition DAGs, immutable request snapshots, safe argv templates, composed input schemas, prerequisite predicates, cryptographic receipt invariants, canonical packet paths, and projectors", "FUSE only same-owner peels after lifecycle/parent split", "DELETE false ledger guarantees, stale closure helpers, AAEOS quarantine twins, dead wrappers, imports, paths, and quartet aliases", "CODEMAP queue consumers/transitions plus callers, routes, reservation surfaces, closure stages, commands, 255 slices, 467 labels, statuses, aliases, authorization semantics, lifecycle inputs, packet paths, and costs", "PERF queue-size/lock/task-file plus node/hash/mother/instruction/memory/query/schema/IO/signature/depth/payload budgets"]
- meta_complete: false
```
