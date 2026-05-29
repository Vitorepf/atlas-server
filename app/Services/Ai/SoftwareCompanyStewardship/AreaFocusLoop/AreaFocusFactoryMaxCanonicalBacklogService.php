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
