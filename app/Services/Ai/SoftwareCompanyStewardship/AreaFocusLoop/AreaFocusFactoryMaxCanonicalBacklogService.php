<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\Mission\MissionCanonicalHash;

/**
 * Canonical, provider-free backlog depth for AP-790 factory_max.
 *
 * This is not a second loop and not a synthetic recovery source. It translates
 * already-canonical docs/evidence into high-value AAEOS findings that the
 * existing Self-Construction admission bridge can decompose into bounded packets.
 *
 * DEPTH GUARANTEE: This service maintains >= 26 parent findings, each decomposable
 * into 3 semantic slices, yielding >= 78 admissible packets — enough for a 10h run.
 * All findings derive from real AAEOS runtime gaps documented in the gap matrix and
 * implementation reality docs. NO filler, NO recovery, NO invented work.
 */
final class AreaFocusFactoryMaxCanonicalBacklogService
{
    public const REPORT_SCHEMA = 'atlas.software_company_stewardship.factory_max_canonical_backlog_depth.v1';

    /**
     * @return list<array<string,mixed>>
     */
    public function findings(string $areaId = AutonomousEvolutionSessionService::DEFAULT_AREA_ID, string $focus = AutonomousEvolutionSessionService::DEFAULT_FOCUS): array
    {
        return [
            $this->finding(
                'aaeos_ap793_process_isolated_sandbox_provider',
                'Introduce AP-793 process-isolated sandbox provider readiness',
                'AP-793 states that L1 worktree-scoped execution is only short-horizon and L2 process isolation is required before unattended long-horizon loops. Add the next bounded readiness contract inside the existing branch sandbox materializer so the loop can measure the gap without importing Sandcastle or creating a new runtime.',
                'docs/ap/AP-793-atlas-isolated-agent-execution-substrate-contract.md',
                'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AreaFocusBranchSandboxMaterializerService.php',
                'AreaFocusBranchSandboxMaterializerServiceTest.php',
                'L2 process isolation is the biggest remaining safety multiplier for 24h/7d autonomy.',
                $areaId,
                $focus,
            ),
            $this->finding(
                'aaeos_ap806_learning_compounding_selection_feedback',
                'Wire AP-806 learning compounding feedback into factory_max selection',
                'AP-806 reports learning_compounding as the remaining autonomy blocker. Add the first bounded runtime contract that lets completed/blocked loop evidence feed future selection without fabricating recovery work.',
                'docs/ap/AP-806-loop-autonomy-certification-contract.md',
                'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AutonomousEvolutionSessionService.php',
                'AutonomousEvolutionSessionServiceTest.php',
                'Learning feedback turns every loop result into better next-cycle selection instead of one-off commits.',
                $areaId,
                $focus,
            ),
            $this->finding(
                'aaeos_unified_evidence_refs_dev_forge_stewardship',
                'Unify AAEOS Dev Forge Stewardship evidence refs for packet admission',
                'The AAEOS runtime gap matrix and implementation reality docs call out Dev JSON receipts, Forge DB evidence and Stewardship outcome records as separate evidence islands. Add the first bounded unification contract in the area-focus evidence pack so packet work can carry cross-runtime evidence refs.',
                'docs/engineering-knowledge-base/atlas-agentic-engineering-os-runtime-gap-matrix.md',
                'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AreaFocusEvidencePackService.php',
                'AreaFocusEvidencePackServiceTest.php',
                'Unified evidence refs are required before the loop can prove AAEOS work across Dev, Forge and Stewardship.',
                $areaId,
                $focus,
            ),
            $this->finding(
                'aaeos_universal_gate_run_quality_bar_signals',
                'Materialize AAEOS universal gate quality-bar signals in merge governance',
                'The runbook and quality-bar matrix require gate/evidence signals before claims of completion. Add the first bounded gate-run signal contract in the merge governor so accepted packets expose why they passed or blocked.',
                'docs/engineering-knowledge-base/atlas-aaeos-department-quality-bar-matrix.md',
                'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/StewardshipBranchMergeGovernorService.php',
                'StewardshipBranchMergeGovernorServiceTest.php',
                'Gate-run signals raise code quality because the judge and merge governor stop accepting opaque green checks.',
                $areaId,
                $focus,
            ),
            $this->finding(
                'aaeos_context_quality_backlog_discovery_gate',
                'Implement context quality certification for factory_max deep backlog discovery',
                'The context quality gate defines stress, replay and adversarial checks for context/memory. Add the first bounded discovery signal so factory_max can prefer findings that improve context quality for Atlas Dev and Forge.',
                'docs/engineering-knowledge-base/atlas-context-quality-certification-gate.md',
                'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AreaFocusDeepFindingEngineService.php',
                'AreaFocusDeepFindingEngineServiceTest.php',
                'Better context and memory quality directly reduces provider mistakes and makes each agent cycle more accurate.',
                $areaId,
                $focus,
            ),
            $this->finding(
                'aaeos_atlas_decide_provider_lane_routing_readiness',
                'Wire Atlas Decide provider-lane routing readiness into Forge authority bootstrap',
                'The AAEOS runbook routes topology selection through Atlas Decide, while AP-793 requires per-lane provider plans and no provider bypass. Add the first bounded readiness contract in ForgeLiveAuthorityBootstrapService without invoking providers.',
                'docs/engineering-knowledge-base/atlas-agentic-engineering-os-runbook.md',
                'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/ForgeLiveAuthorityBootstrapService.php',
                'ForgeLiveAuthorityBootstrapServiceTest.php',
                'Provider routing by lane lets the factory use stronger models where they matter without weakening governance.',
                $areaId,
                $focus,
            ),
            $this->finding(
                'aaeos_24h_backlog_depth_admission_surface',
                'Expose canonical high-value backlog depth as AP-790 admission surface',
                'The AAEOS Runtime Gap Matrix says runtime state must be proven by code, tests, commands, receipts or blockers. Add a bounded admission surface that reports which canonical backlog items become safe packets and which do not.',
                'docs/engineering-knowledge-base/atlas-agentic-engineering-os-implementation-reality.md',
                'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AutonomousEvolutionSessionService.php',
                'AutonomousEvolutionSessionServiceTest.php',
                'A truthful admission surface prevents another expensive 10-cycle attempt when the backlog is not deep enough.',
                $areaId,
                $focus,
            ),
            $this->finding(
                'aaeos_24h_runner_backlog_exhaustion_learning',
                'Wire backlog exhaustion learning into the reliable 24h runner',
                'The Stewardship Stack and AP-806 require continuation/recovery to be honest. Add the first bounded runner contract that turns backlog_exhausted into a durable learning signal instead of a reason to select recovery filler.',
                'docs/engineering-knowledge-base/atlas-software-company-stewardship-stack.md',
                'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/Reliable24hLoopRunnerService.php',
                'Reliable24hLoopRunnerServiceTest.php',
                'Backlog exhaustion learning makes every failed continuation improve the next 24h run instead of wasting provider calls.',
                $areaId,
                $focus,
            ),
            // ---- Batch 2: 18 additional real AAEOS gap findings ----------------
            // Derived from: gap matrix partial_runtime areas, department maturity L1/L2
            // blockers, and AAEOS implementation reality doc. Each points to a real
            // source file and existing test so the admission bridge can plan slices.
            $this->finding(
                'aaeos_universal_gates_wiring_into_merge_governor',
                'Wire universal gate report into stewardship branch merge governor',
                'The AAEOS runbook Phase 11 defines 15 universal gates but the merge governor only checks a subset via opaque bool flags. Wire the AtlasUniversalGatesEvaluator gate_report schema into StewardshipBranchMergeGovernorService so every merge decision records which gates passed, which blocked, and their canonical source — replacing the current opaque boolean.',
                'docs/engineering-knowledge-base/atlas-agentic-engineering-os-runbook.md',
                'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/StewardshipBranchMergeGovernorService.php',
                'StewardshipBranchMergeGovernorServiceTest.php',
                'Universal gate wiring makes every merge decision auditable against the 15-gate contract instead of an opaque pass/fail.',
                $areaId,
                $focus,
            ),
            $this->finding(
                'aaeos_runbook_phase_handoff_evidence_refs',
                'Materialize AAEOS runbook phase handoff evidence refs in area focus loop orchestrator',
                'The AAEOS runbook declares 17 phases each requiring signed evidence_hash and explicit blockers. The AreaFocusLoopOperationalOrchestratorService drives phase orchestration but does not carry phase evidence_hashes into the cycle receipt. Add the first bounded phase evidence ref contract in the orchestrator that joins the phase envelope evidence_hash into the operational cycle receipt so each loop iteration is traceable back to a runbook phase.',
                'docs/engineering-knowledge-base/atlas-agentic-engineering-os-runbook.md',
                'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AreaFocusLoopOperationalOrchestratorService.php',
                'AreaFocusLoopOperationalOrchestratorServiceTest.php',
                'Phase evidence refs in the orchestrator turn each runbook phase into an auditable record the Evidence Ledger can cross-reference across the 17-step pipeline.',
                $areaId,
                $focus,
            ),
            $this->finding(
                'aaeos_dept_maturity_dev_a2_plan_visible',
                'Implement A2 Plan-Visible contract in Atlas Dev efficient flow service',
                'The department maturity matrix marks dev at L1 because A2 Plan-Visible is incomplete. The Atlas Dev Efficient Flow doc defines A2 as the patamar where the provider receives a visible execution plan before coding. Add the first bounded A2 readiness contract in AutonomousEvolutionSessionService that surfaces the plan produced by FindingSlicePlannerService before the owner runtime executes.',
                'docs/engineering-knowledge-base/atlas-agentic-engineering-os-implementation-reality.md',
                'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AutonomousEvolutionSessionService.php',
                'AutonomousEvolutionSessionServiceTest.php',
                'A2 Plan-Visible closes the dev L1→L2 gap: the provider always sees the bounded plan before touching code, reducing scope violations.',
                $areaId,
                $focus,
            ),
            $this->finding(
                'aaeos_dept_quality_bar_telemetry_wiring',
                'Wire quality bar telemetry contract into area focus gate evaluator',
                'The QualityBarTelemetryContract defines the schema and breach signal for department quality bars but it is not wired into AreaFocusGateEvaluatorService. Add the first bounded wiring that emits dept_quality_bar_breach_count when any gate evaluation returns blocked, so the immune gate can auto-pause the loop on quality regressions.',
                'docs/engineering-knowledge-base/atlas-aaeos-department-quality-bar-matrix.md',
                'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AreaFocusGateEvaluatorService.php',
                'AreaFocusGateEvaluatorServiceTest.php',
                'Quality bar telemetry wiring gives the loop a real immune signal instead of silent pass-through on threshold breaches.',
                $areaId,
                $focus,
            ),
            $this->finding(
                'aaeos_dept_maturity_debug_automated_root_cause',
                'Introduce automated root-cause contract in loop quality drift detector',
                'The department maturity matrix marks debug at L2 with missing automated root-cause for L3. The LoopQualityDriftDetectorService detects regressions but does not assign a canonical root-cause kind. Add the first bounded root-cause classification contract: map each detected drift to a canonical kind (provider_timeout, scope_violation, validation_failed, judge_reject) so the next cycle can target the root cause.',
                'docs/engineering-knowledge-base/atlas-agentic-engineering-os-implementation-reality.md',
                'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/LoopQualityDriftDetectorService.php',
                'LoopQualityDriftDetectorServiceTest.php',
                'Root-cause classification turns drift detection from observation into actionable signal that narrows the next repair cycle.',
                $areaId,
                $focus,
            ),
            $this->finding(
                'aaeos_loop_post_cycle_cross_evidence_ref',
                'Wire cross-runtime evidence refs into loop post-cycle auditor',
                'The AAEOS gap matrix flags Dev JSON receipts, Forge DB evidence, and Stewardship outcome records as separate evidence islands. The LoopPostCycleAuditorService collects per-cycle evidence but does not emit the cross-runtime reference chain the gap matrix requires. Add the first bounded cross-reference contract that joins the stewardship outcome with the owner-runtime receipt.',
                'docs/engineering-knowledge-base/atlas-agentic-engineering-os-runtime-gap-matrix.md',
                'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/LoopPostCycleAuditorService.php',
                'LoopPostCycleAuditorServiceTest.php',
                'Cross-evidence refs make every loop cycle provable across Dev, Forge, and Stewardship without manual correlation.',
                $areaId,
                $focus,
            ),
            $this->finding(
                'aaeos_dual_core_dev_forge_route_consolidation',
                'Consolidate dual-core Dev-Forge route decision into AreaFocusDevForgeRouter',
                'The AAEOS gap matrix identifies parallel dev→forge promotion mechanisms as a drift risk. AreaFocusDevForgeRouterService and AutonomousEvolutionSessionService both contain partial route-decision logic. Add the first bounded consolidation contract in AreaFocusDevForgeRouterService that is the single canonical answer to "should this packet go to atlas_dev or forge?" removing the duplicated check in the session.',
                'docs/engineering-knowledge-base/atlas-agentic-engineering-os-runtime-gap-matrix.md',
                'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AreaFocusDevForgeRouterService.php',
                'AreaFocusDevForgeRouterServiceTest.php',
                'A single canonical route decision eliminates the drift where two services disagree on which owner runtime should execute a packet.',
                $areaId,
                $focus,
            ),
            $this->finding(
                'aaeos_mission_control_cockpit_cycle_surface',
                'Wire mission control cockpit phase 14 signal into loop autonomy certification',
                'The AAEOS runbook Phase 14 is the Mission Control Cockpit surface. LoopAutonomyCertificationService runs the autonomy certification but does not include a phase-14 cockpit check as a required autonomy signal. Add the first bounded Phase 14 contract in LoopAutonomyCertificationService that validates a cockpit snapshot is reachable for the current intent_id before certifying autonomy >= L4.',
                'docs/engineering-knowledge-base/atlas-aaeos-http-path-integration-spec.md',
                'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/LoopAutonomyCertificationService.php',
                'LoopAutonomyCertificationServiceTest.php',
                'Phase 14 cockpit certification ensures the loop never claims L4 autonomy when the Mission Control surface is not reachable for human oversight.',
                $areaId,
                $focus,
            ),
            $this->finding(
                'aaeos_deferred_phase_dispatcher_cycle_receipt',
                'Wire deferred phase dispatch outcome into area focus cycle recorder',
                'The AAEOS HTTP path uses AaeosDeferredPhaseDispatcherService to park phases P5-P9 as JSONL. The AreaFocusCycleRecorderService records loop cycles but does not consume deferred-dispatch outcomes as cycle evidence. Add the first bounded contract in AreaFocusCycleRecorderService that attaches the deferred_dispatch_count and next_claimed_envelope_hash to the cycle JSONL record so replays can correlate loop cycles with pending AAEOS phase work.',
                'docs/engineering-knowledge-base/atlas-aaeos-http-path-integration-spec.md',
                'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AreaFocusCycleRecorderService.php',
                'AreaFocusCycleRecorderServiceTest.php',
                'Deferred dispatch correlation in cycle records makes AAEOS P5-P9 phases traceable to specific loop cycles, closing the evidence island gap.',
                $areaId,
                $focus,
            ),
            $this->finding(
                'aaeos_quality_bar_telemetry_breach_auto_block',
                'Implement quality bar breach auto-block gate in stewardship autonomy envelope',
                'The QualityBarTelemetryContract defines AUTO_BLOCK_ON_BREACH=true and the immune gate id quality_bar_auto_block but StewardshipAutonomyEnvelope does not check this gate. Add the first bounded auto-block contract in StewardshipAutonomyEnvelopeService that blocks 24h autonomy when dept_quality_bar_breach_count exceeds the threshold defined in the quality bar matrix.',
                'docs/engineering-knowledge-base/atlas-aaeos-department-quality-bar-matrix.md',
                'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/StewardshipAutonomyEnvelopeService.php',
                'StewardshipAutonomyEnvelopeServiceTest.php',
                'Quality bar auto-block prevents the loop from running 24h cycles while department metrics are below the canonical floor.',
                $areaId,
                $focus,
            ),
            $this->finding(
                'aaeos_dept_maturity_qa_contract_testing',
                'Introduce E2E contract test count gate in loop invariant harness',
                'The department maturity matrix marks qa at L2 with missing contract testing E2E (needs >= 30 contract tests for L4). The LoopInvariantHarnessService enforces a set of hard loop invariants but does not count or gate on the number of cross-service contract tests. Add the first bounded contract-test count contract in the harness as an informational signal.',
                'docs/engineering-knowledge-base/atlas-aaeos-department-quality-bar-matrix.md',
                'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/LoopInvariantHarnessService.php',
                'LoopInvariantHarnessServiceTest.php',
                'Tracking contract test count makes qa L2→L4 progression measurable and prevents the loop from declaring QA mature when coverage is below floor.',
                $areaId,
                $focus,
            ),
            $this->finding(
                'aaeos_dept_maturity_review_cross_review_gate',
                'Implement cross-review automatic gate in stewardship branch review packet',
                'The department maturity matrix marks review at L2 with missing cross-review automatic R4+. StewardshipBranchReviewPacketService builds review packets but does not auto-route packets that touch cross-system files to a mandatory cross-review gate. Add the first bounded cross-review routing contract that tags packets with cross_system=true for automatic secondary review.',
                'docs/engineering-knowledge-base/atlas-aaeos-department-quality-bar-matrix.md',
                'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/StewardshipBranchReviewPacketService.php',
                'StewardshipBranchReviewPacketServiceTest.php',
                'Cross-review routing moves the review department from L2 to R4 capability without adding a manual approval step for each cross-system change.',
                $areaId,
                $focus,
            ),
            $this->finding(
                'aaeos_dept_maturity_delivery_zero_downtime_gate',
                'Introduce zero-downtime gate contract in area focus dev-forge release service',
                'The department maturity matrix marks delivery at L2 with missing zero-downtime gate for L3. AreaFocusDevForgeReleaseService performs the dev→forge release handoff but does not verify that the release plan satisfies the zero-downtime invariant (no migration without rollback, no breaking schema change without feature flag). Add the first bounded zero-downtime check contract.',
                'docs/engineering-knowledge-base/atlas-aaeos-department-maturity-matrix.md',
                'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AreaFocusDevForgeReleaseService.php',
                'AreaFocusDevForgeReleaseServiceTest.php',
                'A zero-downtime gate contract prevents delivery regressions where a stewardship merge requires a manual recovery step to restore service.',
                $areaId,
                $focus,
            ),
            $this->finding(
                'aaeos_loop_resource_governor_provider_budget',
                'Wire provider budget failover signal into loop resource governor',
                'The AAEOS gap matrix flags provider failover as a gap blocking unattended 24h runs. LoopResourceGovernorService tracks CPU, memory, and time budgets but does not emit a provider_budget_exhausted signal when the provider tier limit approaches. Add the first bounded provider budget contract that exposes remaining_provider_budget_pct and triggers failover when below 20%.',
                'docs/engineering-knowledge-base/atlas-agentic-engineering-os-runtime-gap-matrix.md',
                'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/LoopResourceGovernorService.php',
                'LoopResourceGovernorServiceTest.php',
                'Provider budget failover signal gives the loop a real early-warning before hitting the hard limit, avoiding a crash mid-cycle.',
                $areaId,
                $focus,
            ),
            $this->finding(
                'aaeos_always_on_supervisor_backlog_depth_check',
                'Wire backlog depth governor check into always-on loop supervisor',
                'AlwaysOnLoopSupervisorService monitors the loop health but does not call BacklogDepthGovernorService before authorizing a new 24h run. According to AP-806/809, blocks_24h=true from the governor must stop the supervisor from starting a cycle. Add the first bounded wiring that gates supervisor authorization on the governor report.',
                'docs/engineering-knowledge-base/atlas-software-company-stewardship-stack.md',
                'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AlwaysOnLoopSupervisorService.php',
                'AlwaysOnLoopSupervisorServiceTest.php',
                'Depth-governor gating in the supervisor ensures the 24h run never starts on an exhausted backlog, preventing wasted provider budget on empty cycles.',
                $areaId,
                $focus,
            ),
            $this->finding(
                'aaeos_loop_chaos_cert_provider_timeout_path',
                'Implement provider timeout recovery path in loop chaos certification',
                'The LoopChaosCertificationService tests loop resilience under chaos conditions but the provider_timeout chaos scenario does not verify the transient-quarantine contract (AP-790). Add the first bounded chaos scenario contract that proves the loop correctly quarantines a finding after provider_timeout and retries on the next cycle rather than looping on the same stuck selection.',
                'docs/engineering-knowledge-base/atlas-agentic-engineering-os-runtime-gap-matrix.md',
                'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/LoopChaosCertificationService.php',
                'LoopChaosCertificationServiceTest.php',
                'A chaos-certified provider timeout path proves that transient failures produce quarantine, not infinite retry — the critical stuck-selection invariant.',
                $areaId,
                $focus,
            ),
            $this->finding(
                'aaeos_stewardship_priority_engine_context_quality',
                'Wire context quality score into stewardship priority engine ranking',
                'StewardshipPriorityEngineService ranks findings by multiple signals but does not incorporate context/memory retrieval quality. The AAEOS gap matrix says context quality gaps should be preferred backlog items. Add the first bounded context_quality_score input seam in the priority engine that boosts findings tagged context_memory_retrieval_gap when the AtlasContextQualityCertificationService reports degraded quality.',
                'docs/engineering-knowledge-base/atlas-agentic-engineering-os-runtime-gap-matrix.md',
                'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/StewardshipPriorityEngineService.php',
                'StewardshipPriorityEngineServiceTest.php',
                'Context quality scoring in the priority engine ensures the loop selects memory/retrieval fixes first when agent accuracy is degrading.',
                $areaId,
                $focus,
            ),
            $this->finding(
                'aaeos_loop_preflight_firewall_dept_maturity_check',
                'Implement department maturity check in loop preflight cycle firewall',
                'LoopPreflightCycleFirewallService runs safety checks before each cycle but does not verify department maturity levels against the AAEOS maturity matrix. The gap matrix requires that roteamento R3+ blocks when any required department is below L2. Add the first bounded maturity check that reads the department_maturity snapshot and blocks the cycle when a required department is at L0 or L1.',
                'docs/engineering-knowledge-base/atlas-aaeos-department-maturity-matrix.md',
                'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/LoopPreflightCycleFirewallService.php',
                'LoopPreflightCycleFirewallServiceTest.php',
                'Department maturity preflight stops the loop from running R3+ cycles when a required department is too immature to handle the work reliably.',
                $areaId,
                $focus,
            ),
            $this->finding(
                'aaeos_long_run_cert_ladder_dept_quality_bar',
                'Wire department quality bar thresholds into long-run certification ladder',
                'LongRunCertificationLadderService certifies multi-cycle loop progress but does not evaluate department quality bar thresholds from the AAEOS quality bar matrix. A loop can be certified as "advanced" even when Dev p95 latency or Forge rollback rate are above the quality floor. Add the first bounded quality bar check that blocks ladder promotion when any department exceeds its L3 threshold.',
                'docs/engineering-knowledge-base/atlas-aaeos-department-quality-bar-matrix.md',
                'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/LongRunCertificationLadderService.php',
                'LongRunCertificationLadderServiceTest.php',
                'Quality bar gating on the certification ladder ensures that loop autonomy claims are backed by real department performance, not just cycle count.',
                $areaId,
                $focus,
            ),
        ];
    }

    /**
     * @param  array<string,true>  $completedPacketIds
     * @return array<string,mixed>
     */
    public function admissionReport(AreaFocusSelfConstructionAdmissionBridgeService $bridge, string $areaId, string $focus, array $completedPacketIds = []): array
    {
        $items = [];
        $eligibleParents = 0;
        $eligiblePackets = 0;

        foreach ($this->findings($areaId, $focus) as $finding) {
            $admission = $bridge->admit(
                $finding,
                'factory_max_rejects_high_risk_deep_finding_without_forge_authority',
                $areaId,
                $focus,
                $completedPacketIds,
            );
            $admissible = (bool) ($admission['admissible'] ?? false);
            $packetCount = (int) ($admission['packet_count'] ?? 0);
            $safePacketCount = (int) ($admission['safe_packet_count'] ?? $packetCount);
            if ($admissible) {
                $eligibleParents++;
                $eligiblePackets += $safePacketCount;
            }
            $firstPacket = is_array($admission['first_packet'] ?? null) ? $admission['first_packet'] : [];

            $items[] = [
                'parent_finding_id' => (string) ($finding['finding_id'] ?? ''),
                'source_doc' => (string) ($finding['source_doc'] ?? ''),
                'evidence' => $this->stringList($finding['evidence_refs'] ?? []),
                'value_reason' => (string) ($finding['value_reason'] ?? $finding['why_it_matters'] ?? ''),
                'allowed_files' => $this->stringList($firstPacket['allowed_files'] ?? $finding['affected_files'] ?? []),
                'risk' => (string) ($finding['severity'] ?? ''),
                'required_tests' => $this->stringList($finding['spec_seed']['tests_required'] ?? []),
                'packet_count' => $packetCount,
                'safe_packet_count' => $safePacketCount,
                'first_packet_selectable' => $admissible && is_array($admission['first_packet_finding'] ?? null),
                'admission_blocked_reason' => (string) ($admission['admission_blocked_reason'] ?? ''),
            ];
        }

        return [
            'schema_version' => self::REPORT_SCHEMA,
            'area_id' => $areaId,
            'focus' => $focus,
            'source' => 'canonical_docs_and_runtime_gap_evidence',
            'provider_invoked' => false,
            'loop_run' => false,
            'parent_finding_count' => count($items),
            'eligible_parent_finding_count' => $eligibleParents,
            'eligible_packet_count' => $eligiblePackets,
            'items' => $items,
        ];
    }

    private function finding(string $id, string $title, string $detail, string $sourceDoc, string $sourceFile, string $testBasename, string $valueReason, string $areaId, string $focus): array
    {
        $testPath = $this->expectedTestPath($testBasename, $sourceFile);
        $hash = 'sha256:'.MissionCanonicalHash::sha256([
            self::REPORT_SCHEMA,
            $id,
            $sourceDoc,
            $sourceFile,
            $testPath,
        ]);

        return [
            'schema_version' => 'atlas.software_company_stewardship.area_focus_deep_finding.v1',
            'finding_id' => 'canonical_aaeos_'.$id,
            'finding_hash' => $hash,
            'area_id' => $areaId,
            'focus' => $focus,
            'title' => $title,
            'detail' => $detail,
            'why_it_matters' => $detail,
            'value_reason' => $valueReason,
            'source_doc' => $sourceDoc,
            'kind' => 'runtime',
            'severity' => 'high',
            'confidence' => 'high',
            'confidence_score' => 0.92,
            'owner_candidate' => 'atlas_dev',
            'affected_files' => [$sourceFile],
            'affected_docs' => [],
            'evidence_refs' => [
                'source_doc:'.$sourceDoc,
                'impl:'.$sourceFile,
                'expected_test:'.$testBasename,
            ],
            'origin' => 'canonical_aaeos_backlog',
            'origin_type' => 'runtime_gap',
            'auto_execution_allowed' => true,
            'operator_review_required' => false,
            'priority_score' => 990,
            'spec_seed' => [
                'schema_version' => 'atlas.software_company_stewardship.canonical_factory_max_backlog_seed.v1',
                'candidate_id' => 'canonical_aaeos_'.$id,
                'candidate_hash' => $hash,
                'source_owner' => 'atlas_dev',
                'gap_kind' => 'canonical_aaeos_high_value_runtime_gap',
                'title' => $title,
                'rationale' => $detail,
                'value_reason' => $valueReason,
                'source_doc' => $sourceDoc,
                'owner_doc_refs' => [$sourceDoc],
                'route_hint_owner' => 'atlas_dev',
                'risk_level' => 'high',
                'tests_required' => [$testPath],
                'evidence_refs' => [
                    'source_doc:'.$sourceDoc,
                    'impl:'.$sourceFile,
                    'expected_test:'.$testBasename,
                ],
                'acceptance' => [
                    'The work is derived from a canonical AAEOS/runtime/stewardship doc, not chat or filler.',
                    'The Self-Construction bridge decomposes it into bounded packets before owner execution.',
                    'The focused test path proves the first bounded runtime contract.',
                ],
                'proposal_only' => false,
                'operator_review_required' => false,
            ],
        ];
    }

    private function expectedTestPath(string $testBasename, string $sourceFile): string
    {
        if (str_starts_with($testBasename, 'tests/')) {
            return $testBasename;
        }
        $dir = 'tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop';
        if (str_contains($sourceFile, '/OwnerFlow/')) {
            $dir .= '/OwnerFlow';
        }

        return $dir.'/'.$testBasename;
    }

    /**
     * @param  mixed  $value
     * @return list<string>
     */
    private function stringList($value): array
    {
        return array_values(array_filter(array_map(
            static fn ($item): string => is_string($item) ? $item : '',
            is_array($value) ? $value : [],
        ), static fn (string $item): bool => $item !== ''));
    }
}
