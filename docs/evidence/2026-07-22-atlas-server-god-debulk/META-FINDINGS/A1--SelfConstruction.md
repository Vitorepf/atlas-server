# A1--SelfConstruction — META FINDINGS

> Bucket: `app/Services/Ai/SelfConstruction`
> Wave: A1
> Layout: um registro YAML por arquivo. Não misturar outros buckets neste arquivo.
> Se este md > ~1500 linhas → partir `A1--SelfConstruction--<sub>.md`

```yaml
meta_complete: false
files_scanned: 4
files_total: 1210
lines_scanned: 70448
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

## Bucket rollup

```markdown
- files_scanned: 4 / 1210
- lines_scanned: 70448
- s0..s3: 13 / 13 / 7 / 0
- intent_axes_covered: [2, 3, 5, 6, 7, 10, 14, 15, 16, 17, 18, 19, 20, 21, 22, 23, 24, 25, 27, 28, 29, 33, 34, 35, 36, 37, 38, 40, 41, 42, 49, 50, 54, 55, 62, 64, 65, 66, 67, 68, 69]
- intent_axes_missing_in_this_bucket: [1, 4, 8, 9, 11, 12, 13, 26, 30, 31, 32, 39, 43, 44, 45, 46, 47, 48, 51, 52, 53, 56, 57, 58, 59, 60, 61, 63]
- ownership_proposal: "thin compatibility facades -> readiness query owner + runtime command/writer owner + certification workbench owner + completion evidence owner + provider-neutral review/merge lifecycle owner + authorization/signature owner + persistence/writer lifecycle owner + human decision/session owner + provider-neutral execution/evidence/dispatch owners + Codex adapter"
- ordered_worklist: ["BUGFIX_PLAN Codex reachability, hash binding, evidence graph, and ready-versus-blocked semantics", "TEST all 171 Codex execution, 109 agent review/merge, and 149 Codex review/merge contracts", "SPLIT parent and monster sections", "OWNER readiness, review/merge, authorization, persistence, writer lifecycle, human decision, session, execution, evidence, dispatch, and I/O authorities", "EXTRACT typed validated catalogs and transition projectors", "CODEMAP callers, routes, statuses, and aliases", "PERF query/IO/hash/payload budgets"]
- meta_complete: false
```
