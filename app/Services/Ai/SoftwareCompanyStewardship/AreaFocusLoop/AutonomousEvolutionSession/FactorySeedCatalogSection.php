<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AutonomousEvolutionSession;

use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AutonomousEvolutionSessionService;

/**
 * Factory-max seed catalog, extracted VERBATIM from
 * AutonomousEvolutionSessionService by the GOD-DEBULK split. The deterministic
 * high-impact fallback backlog for the operator's core thesis (improve the
 * software factory itself before downstream domains). Pure data: every seed is
 * built through the parent's shared {@see AutonomousEvolutionSessionService::factorySeed()}
 * finding factory.
 */
final class FactorySeedCatalogSection
{
    public function __construct(
        private readonly AutonomousEvolutionSessionService $parent,
    ) {}

    /**
     * High-impact fallback work for the operator's core thesis: improve the
     * software factory itself before spending cycles on downstream domains or
     * low-leverage documentation/evidence cleanup.
     *
     * @return list<array<string,mixed>>
     */
    public function factoryMaxSeedCandidates(): array
    {
        return [
            $this->factorySeed(
                'ap789_forge_topology_dispatch_readiness',
                'Repair AP-789 Forge live topology dispatch readiness',
                'The 24h loop cannot execute high-impact Forge work while AP-789 reports forge_live_topology_unavailable. Improve the real readiness diagnostics or wiring around ForgeLiveAuthorityBootstrapService so the loop gets an actionable, bounded next step instead of starving candidate selection.',
                'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/ForgeLiveAuthorityBootstrapService.php',
                'ForgeLiveAuthorityBootstrapServiceTest.php',
                'atlas_dev',
                'bug',
            ),
            $this->factorySeed(
                'ap789_awis_workspace_handoff_readiness',
                'Repair AP-789 AWIS workspace handoff readiness',
                'The 24h loop cannot graduate into real Forge owner runtime while AP-789 reports workspace_handoff_pack_blocked or awis_handoff blockers. Improve the AWIS handoff readiness surface and tests so AP-790 can progress without fabricating authority.',
                'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/ForgeLiveAuthorityBootstrapService.php',
                'ForgeLiveAuthorityBootstrapServiceTest.php',
                'atlas_dev',
                'bug',
            ),
            $this->factorySeed(
                'ap790_runtime_gap_matrix_ingestion',
                'Make AP-790 consume structural AAEOS runtime gap backlog before maintenance',
                'Wire the autonomous loop selection policy to prefer high-impact partial_runtime/spec_runtime_gap items from the AAEOS runtime gap matrix before spending more cycles on routine missing-test maintenance.',
                'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AutonomousEvolutionSessionService.php',
                'AutonomousEvolutionSessionServiceTest.php',
                'atlas_dev',
                'bug',
            ),
            $this->factorySeed(
                'ap789_forge_authority_readiness',
                'Improve AP-789 live authority readiness diagnostics',
                'Make AP-789 live authority blockers more actionable so the 24h loop can graduate from Atlas Dev maintenance into real owner-runtime dispatch without fabricating authority.',
                'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/ForgeLiveAuthorityBootstrapService.php',
                'ForgeLiveAuthorityBootstrapServiceTest.php',
                'atlas_dev',
                'bug',
            ),
            $this->factorySeed(
                'ap792_loop_certification_runtime_realness',
                'Harden 24h certification harness against partial-runtime false confidence',
                'Strengthen the loop certification harness so it distinguishes small successful maintenance cycles from large Dev/Forge runtime cycles before any months-ready claim.',
                'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/Loop24hCertificationHarnessService.php',
                'Loop24hCertificationHarnessServiceTest.php',
                'atlas_dev',
                'bug',
            ),
            $this->factorySeed(
                'ap786_loop_hardening',
                'Harden AP-786 autonomous evolution loop against wasted cycles',
                'Make the autonomous loop better at choosing, executing, validating, merging and continuing without wasting provider calls.',
                'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AutonomousEvolutionSessionService.php',
                'AutonomousEvolutionSessionServiceTest.php',
                'atlas_dev',
                'bug',
            ),
            $this->factorySeed(
                'ap785_priority_power',
                'Improve factory-max priority scoring for highest-return engineering work',
                'Tune the priority engine so work that improves Atlas Dev, Forge, provider routing, sandboxing, validation and merge throughput dominates cosmetic or documentary work.',
                'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/StewardshipPriorityEngineService.php',
                'StewardshipPriorityEngineServiceTest.php',
                'atlas_dev',
                'bug',
            ),
            $this->factorySeed(
                'ap748_deep_scan_power',
                'Expand deep finding engine to discover runtime bottlenecks in Atlas Dev and Forge',
                'Increase the scanner ability to find real runtime gaps, missing tests, provider-routing risks and execution bottlenecks instead of low-leverage doc findings.',
                'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AreaFocusDeepFindingEngineService.php',
                'AreaFocusDeepFindingEngineServiceTest.php',
                'atlas_dev',
                'bug',
            ),
            $this->factorySeed(
                'ap717_missing_test_precision',
                'Suppress AP-717 interface-only missing-test false positives',
                'The 24h loop must not waste provider cycles on impossible or low-value missing-test findings for interfaces when the concrete implementation/service test already covers the runtime contract.',
                'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AreaFocusDeepFindingEngineService.php',
                'AreaFocusDeepFindingEngineServiceTest.php',
                'atlas_dev',
                'bug',
            ),
            $this->factorySeed(
                'ap756_sandbox_throughput',
                'Harden branch sandbox materializer for faster safe autonomous cycles',
                'Improve the isolated branch/worktree layer because every autonomous implementation cycle depends on reliable sandbox creation, cleanup and receipts.',
                'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AreaFocusBranchSandboxMaterializerService.php',
                'AreaFocusBranchSandboxMaterializerServiceTest.php',
                'atlas_dev',
                'bug',
            ),
            $this->factorySeed(
                'ap769_merge_throughput',
                'Improve merge governor throughput without lowering safety',
                'Reduce false blocks and strengthen evidence in the merge governor so safe changes land faster while risky changes remain isolated.',
                'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/StewardshipBranchMergeGovernorService.php',
                'StewardshipBranchMergeGovernorServiceTest.php',
                'atlas_dev',
                'bug',
            ),
            $this->factorySeed(
                'cursor_driver_reliability',
                'Harden Cursor CLI driver for long autonomous factory runs',
                'Provider invocation reliability directly controls factory throughput; improve prompt passing, scope checks, timeout evidence and account-driver safety.',
                'app/Services/Ai/Programming/AtlasForgeCursorCliInvocationDriver.php',
                'AtlasForgeCursorCliDriverTest.php',
                'atlas_dev',
                'bug',
            ),
            $this->factorySeed(
                'ap786_owner_failure_specificity',
                'Expose owner-runtime failure states as actionable AP-786 blockers',
                'When the owner runtime returns no_patch_needed, senior_loop_execution_not_passed or routing_not_executable, AP-786 should surface the precise machine blocker instead of collapsing everything into owner_runtime_result_not_completed.',
                'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AutonomousEvolutionSessionService.php',
                'AutonomousEvolutionSessionServiceTest.php',
                'atlas_dev',
                'bug',
            ),
            $this->factorySeed(
                'ap790_blocked_cycle_summary_test',
                'Add focused unit coverage for AP-790 blocked-cycle summaries',
                'Prove that the reliable 24h runner reports blocked cycles with exact blockers, cycle indexes and no merge claim so the operator can trust loop progress telemetry.',
                'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/Reliable24hLoopRunnerService.php',
                'Reliable24hLoopRunnerServiceTest.php',
                'atlas_dev',
                'test',
            ),
            $this->factorySeed(
                'ap785_priority_state_test',
                'Add focused unit coverage for AP-785 priority state awareness',
                'Prove that factory priority ranking prefers high-return Atlas Dev and Forge execution work while preserving deterministic state-aware ordering.',
                'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/StewardshipPriorityEngineService.php',
                'StewardshipPriorityEngineServiceTest.php',
                'atlas_dev',
                'test',
            ),
            $this->factorySeed(
                'ap748_deep_scan_path_test',
                'Add focused unit coverage for AP-748 deep-scan path precision',
                'Prove that the deep finding engine emits actionable source and test paths for factory runtime work instead of routing low-leverage documentation-only findings.',
                'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AreaFocusDeepFindingEngineService.php',
                'AreaFocusDeepFindingEngineServiceTest.php',
                'atlas_dev',
                'test',
            ),
            $this->factorySeed(
                'ap786_read_model_test',
                'Add focused unit coverage for AP-786 session read model',
                'Prove that the autonomous session read model projects recorded cycle receipts without executing providers, branches or merge operations.',
                'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AutonomousEvolutionSessionReadModelService.php',
                'AutonomousEvolutionSessionReadModelServiceTest.php',
                'atlas_dev',
                'test',
            ),
            $this->factorySeed(
                'ap791_receipt_integrity_test',
                'Add focused unit coverage for AP-791 loop receipt integrity',
                'Prove that loop receipt integrity keeps pre-merge inbox evidence mandatory and emits reviewable lifecycle receipts for completed, blocked and planned cycles.',
                'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AutonomousLoopReceiptIntegrityService.php',
                'AutonomousLoopReceiptIntegrityServiceTest.php',
                'atlas_dev',
                'test',
            ),
            $this->factorySeed(
                'ap716_area_focus_read_model_test',
                'Add focused unit coverage for AP-716 area focus read model',
                'Prove that the area focus read model exposes actionable agentic engineering status without mutating repositories or bypassing owner routing.',
                'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AtlasAreaFocusLoopReadModelService.php',
                'AtlasAreaFocusLoopReadModelServiceTest.php',
                'atlas_dev',
                'test',
            ),
            $this->factorySeed(
                'ap790_blocked_cycle_mergeable_test',
                'Add mergeable AP-790 blocked-cycle regression coverage',
                'Add a focused regression test proving AP-790 records blocked-cycle blockers and remains safe to auto-merge when the diff is test-only.',
                'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/Reliable24hLoopRunnerService.php',
                'Reliable24hLoopRunnerServiceTest.php',
                'atlas_dev',
                'test',
            ),
            $this->factorySeed(
                'ap748_interface_false_positive_test',
                'Add AP-748 interface false-positive regression coverage',
                'Add a focused regression test proving AP-748 does not promote interface-only missing-test findings when the concrete runtime already has coverage.',
                'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AreaFocusDeepFindingEngineService.php',
                'AreaFocusDeepFindingEngineServiceTest.php',
                'atlas_dev',
                'test',
            ),
            $this->factorySeed(
                'ap790_seen_finding_resume_test',
                'Add AP-790 seen-finding resume regression coverage',
                'Add a focused regression test proving AP-790 crash recovery forwards seen findings so the loop keeps moving instead of repeating completed work.',
                'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/Reliable24hLoopRunnerService.php',
                'Reliable24hLoopRunnerServiceTest.php',
                'atlas_dev',
                'test',
            ),
        ];
    }

    /** @return array<string,mixed> */
    public function factorySeed(string $id, string $title, string $detail, string $sourceFile, string $testBasename, string $owner, string $kind, string $severity = 'medium'): array
    {
        $hash = 'sha256:'.MissionCanonicalHash::sha256(['AP-786', AutonomousEvolutionSessionService::SCOPE_FACTORY_MAX, $id, $sourceFile, $testBasename]);

        return [
            'schema_version' => 'atlas.software_company_stewardship.area_focus_deep_finding.v1',
            'finding_id' => 'factory_max_'.$id,
            'finding_hash' => $hash,
            'area_id' => AutonomousEvolutionSessionService::DEFAULT_AREA_ID,
            'focus' => AutonomousEvolutionSessionService::DEFAULT_FOCUS,
            'title' => $title,
            'detail' => $detail,
            'kind' => $kind,
            'severity' => $severity,
            'confidence' => 'high',
            'confidence_score' => 0.9,
            'owner_candidate' => $owner,
            'evidence_refs' => [
                'factory_max_seed:'.$id,
                'impl:'.$sourceFile,
                'expected_test:'.$testBasename,
            ],
            'affected_files' => [$sourceFile],
            'affected_docs' => [],
            'why_it_matters' => $detail,
            'proposed_spec_title' => 'Factory Max: '.$title,
            'proposed_next_action' => sprintf(
                'Implement "%s" by changing the targeted runtime and/or focused test. Target runtime: %s. Required focused test: %s. This cycle is invalid if it only changes docs or returns no_patch_needed without concrete proof.',
                $title,
                $sourceFile,
                $this->parent->expectedTestPath($testBasename, [$sourceFile]),
            ),
            'in_focus' => true,
            'priority_score' => 950,
            'origin' => 'factory_max_seed',
            'origin_type' => $id,
            'auto_execution_allowed' => true,
            'operator_review_required' => false,
            'spec_seed' => [
                'schema_version' => 'atlas.software_company_stewardship.factory_max_spec_seed.v1',
                'candidate_id' => 'factory_max_'.$id,
                'candidate_hash' => $hash,
                'source_owner' => $owner,
                'gap_kind' => 'software_factory_runtime_improvement',
                'title' => 'Factory Max: '.$title,
                'rationale' => $detail,
                'capability' => AutonomousEvolutionSessionService::DEFAULT_FOCUS,
                'risk_level' => $severity,
                'evidence_refs' => ['factory_max_seed:'.$id, 'impl:'.$sourceFile, 'expected_test:'.$testBasename],
                'owner_doc_refs' => [],
                'route_hint_owner' => $owner,
                'acceptance' => [
                    'The implementation changes the targeted runtime or its focused tests, not only documentation.',
                    'The focused test path proves the behavior or guard that makes autonomous cycles more robust.',
                    'The AP-786 robust flow contract remains ready before owner execution.',
                ],
                'tests_required' => [$this->parent->expectedTestPath($testBasename, [$sourceFile])],
                'proposal_only' => false,
                'operator_review_required' => false,
            ],
        ];
    }
}
