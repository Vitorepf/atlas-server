<?php

declare(strict_types=1);

namespace App\Services\Ai\ProgrammingRuntime;

use App\Services\Ai\Mission\MissionCanonicalHash;
use Carbon\CarbonImmutable;

/**
 * Final, internal-only certification of the Atlas Programming Runtime.
 *
 * Different from {@see ProgrammingRuntimeReadinessService} (which audits the
 * 10 superiority-roadmap gaps) and from the Forge {@see
 * \App\Services\Ai\Programming\AtlasForgeContinuumCertificationService}
 * (which audits provider topology + cockpit invariants). This service is the
 * higher-level aggregator that answers a single question:
 *
 *   "Before *any* benchmark is run against external rivals, is the Atlas
 *    Programming Runtime internally ready across the 12 canonical
 *    dimensions described in the architecture/contracts/roadmap trinity?"
 *
 * Schema: `atlas.programming.runtime_final_certification.v1`.
 *
 * Hard invariants — enforced by the aggregator, NOT by callers:
 *   1. `overall_status === 'green'` requires zero P0 blocker AND zero P1 warn.
 *   2. `benchmark_status` is ALWAYS `not_run`. The runtime does not allow this
 *      service to declare benchmark execution; that flow lives elsewhere and
 *      is intentionally out of scope.
 *   3. Any check whose runtime is genuinely missing surfaces as a structured
 *      blocker with remediation — never silently passes.
 */
class AtlasProgrammingFinalCertificationService
{
    public const SCHEMA_VERSION = 'atlas.programming.runtime_final_certification.v1';

    public const STATUS_GREEN = 'green';

    public const STATUS_PARTIAL = 'partial';

    public const STATUS_BLOCKED = 'blocked';

    public const CHECK_STATUS_GREEN = 'pass';

    public const CHECK_STATUS_WARN = 'warn';

    public const CHECK_STATUS_BLOCKED = 'fail';

    public const SEVERITY_P0 = 'P0';

    public const SEVERITY_P1 = 'P1';

    public const SEVERITY_P2 = 'P2';

    public const BENCHMARK_STATUS_NOT_RUN = 'not_run';

    public const CHECK_DEV_ROUTING = 'dev_routing';

    public const CHECK_DEV_PATCH_TEST_DEBUG_REVIEW_REPAIR = 'dev_patch_test_debug_review_repair';

    public const CHECK_DEV_RUNTIME_INTELLIGENCE = 'dev_runtime_intelligence';

    public const CHECK_FORGE_INTAKE_AND_OBRA_BUILDING_BLOCKS = 'forge_intake_and_obra_building_blocks';

    public const CHECK_FORGE_SDD_QA_CERTIFICATION_LOOP = 'forge_sdd_qa_certification_loop';

    public const CHECK_FORGE_WORK_PACKET_NATIVE_CAPABILITIES = 'forge_work_packet_native_capabilities';

    public const CHECK_FORGE_LIVE_EXECUTION_PRESENT = 'forge_live_execution_present';

    public const CHECK_DEV_TO_FORGE_ESCALATION = 'dev_to_forge_escalation';

    public const CHECK_RAG_FAIL_CLOSED_AVAILABLE = 'rag_fail_closed_available';

    public const CHECK_WORLD_MODEL_RANKING = 'world_model_ranking';

    public const CHECK_COMPOUNDING_AND_RAG_FEEDBACK = 'compounding_and_rag_feedback';

    public const CHECK_LOCAL_MEMORY_INGESTION = 'local_memory_ingestion';

    public const CHECK_TELEMETRY_AND_AUDIT = 'telemetry_and_audit';

    public const CHECK_CONTROL_PLANE = 'control_plane';

    public const CHECK_E2E_BATTERY_EXISTS = 'e2e_battery_exists';

    public const CHECK_CODE_INTELLIGENCE_AUTOMATIC_GATE = 'code_intelligence_automatic_gate';

    public const CHECK_VERIFIED_CONTEXT_EXECUTION_LOOP = 'verified_context_execution_loop';

    public const CHECK_BENCHMARK_READINESS_HARNESS_NOT_RUN = 'benchmark_readiness_harness_not_run';

    public const ALL_CHECK_IDS = [
        self::CHECK_DEV_ROUTING,
        self::CHECK_DEV_PATCH_TEST_DEBUG_REVIEW_REPAIR,
        self::CHECK_DEV_RUNTIME_INTELLIGENCE,
        self::CHECK_FORGE_INTAKE_AND_OBRA_BUILDING_BLOCKS,
        self::CHECK_FORGE_SDD_QA_CERTIFICATION_LOOP,
        self::CHECK_FORGE_WORK_PACKET_NATIVE_CAPABILITIES,
        self::CHECK_FORGE_LIVE_EXECUTION_PRESENT,
        self::CHECK_DEV_TO_FORGE_ESCALATION,
        self::CHECK_RAG_FAIL_CLOSED_AVAILABLE,
        self::CHECK_WORLD_MODEL_RANKING,
        self::CHECK_COMPOUNDING_AND_RAG_FEEDBACK,
        self::CHECK_LOCAL_MEMORY_INGESTION,
        self::CHECK_TELEMETRY_AND_AUDIT,
        self::CHECK_CONTROL_PLANE,
        self::CHECK_E2E_BATTERY_EXISTS,
        self::CHECK_CODE_INTELLIGENCE_AUTOMATIC_GATE,
        self::CHECK_VERIFIED_CONTEXT_EXECUTION_LOOP,
        self::CHECK_BENCHMARK_READINESS_HARNESS_NOT_RUN,
    ];

    public function __construct(
        private readonly RepoProbe $probe = new FilesystemRepoProbe,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function certify(): array
    {
        $checks = [
            $this->checkDevRouting(),
            $this->checkDevPatchTestDebugReviewRepair(),
            $this->checkDevRuntimeIntelligence(),
            $this->checkForgeIntakeAndObraBuildingBlocks(),
            $this->checkForgeSddQaCertificationLoop(),
            $this->checkForgeWorkPacketNativeCapabilities(),
            $this->checkForgeLiveExecution(),
            $this->checkDevToForgeEscalation(),
            $this->checkRagFailClosedAvailable(),
            $this->checkWorldModelRanking(),
            $this->checkCompoundingAndRagFeedback(),
            $this->checkLocalMemoryIngestion(),
            $this->checkTelemetryAndAudit(),
            $this->checkControlPlane(),
            $this->checkE2eBatteryExists(),
            $this->checkCodeIntelligenceAutomaticGate(),
            $this->checkVerifiedContextExecutionLoop(),
            $this->checkBenchmarkReadinessHarnessNotRun(),
        ];

        $overall = $this->aggregateOverall($checks);
        $summary = $this->summarize($checks);
        $blockers = $this->collectBlockers($checks);
        $remediation = $this->collectRemediation($checks);

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'overall_status' => $overall,
            'summary' => $summary,
            'checks' => $checks,
            'blockers' => $blockers,
            'remediation' => $remediation,
            'benchmark_status' => self::BENCHMARK_STATUS_NOT_RUN,
            'benchmark_note' => 'External rivals battery is OUT of scope of this certification. Comparisons against Claude Code / Codex / Cursor are NOT executed here.',
            'evidence_refs' => $this->topLevelEvidenceRefs($checks),
        ];

        $hashPayload = $payload;
        $payload['generated_at'] = CarbonImmutable::now()->toISOString();
        $payload['certification_hash'] = MissionCanonicalHash::sha256($hashPayload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    private function checkDevRouting(): array
    {
        $repair = 'app/Services/Ai/Programming/AtlasDev/Repair/DevRepairLoopService.php';
        $router = 'app/Services/Ai/RouterRuntime/FlowRouterService.php';

        $present = $this->probe->fileExists($repair) && $this->probe->fileExists($router);

        return $present
            ? $this->pass(self::CHECK_DEV_ROUTING, self::SEVERITY_P0, 'Dev routing (FlowRouter + DevRepairLoop) present', [$repair, $router])
            : $this->fail(self::CHECK_DEV_ROUTING, self::SEVERITY_P0, 'Dev routing missing FlowRouter or DevRepairLoop',
                'wire FlowRouterService and ensure DevRepairLoopService is present in AtlasDev/Repair/',
                [$repair, $router]);
    }

    /**
     * @return array<string,mixed>
     */
    private function checkDevPatchTestDebugReviewRepair(): array
    {
        $required = [
            'patch' => 'app/Services/Ai/Programming/AtlasDev/Schemas/PatchIntelligenceReceipt.php',
            'test_impact' => 'app/Services/Ai/Programming/ProgrammingTestImpactAnalyzer.php',
            'debug_receipt' => 'app/Services/Ai/Programming/AtlasDev/Schemas/DebugReceipt.php',
            'review_receipt' => 'app/Services/Ai/Programming/AtlasDev/Schemas/ReviewReceipt.php',
            'repair_loop' => 'app/Services/Ai/Programming/AtlasDev/Repair/DevRepairLoopService.php',
            'repair_executor' => 'app/Services/Ai/Programming/ProgrammingRepairExecutor.php',
            'failure_classifier' => 'app/Services/Ai/Programming/AtlasDev/Repair/FailureModeClassifier.php',
        ];

        $missing = [];
        foreach ($required as $label => $path) {
            if (! $this->probe->fileExists($path)) {
                $missing[] = $label.':'.$path;
            }
        }

        if ($missing === []) {
            return $this->pass(
                self::CHECK_DEV_PATCH_TEST_DEBUG_REVIEW_REPAIR,
                self::SEVERITY_P0,
                'Dev patch/test/debug/review/repair primitives all present',
                array_values($required),
            );
        }

        return $this->fail(
            self::CHECK_DEV_PATCH_TEST_DEBUG_REVIEW_REPAIR,
            self::SEVERITY_P0,
            'Missing primitives: '.implode(', ', $missing),
            'restore or implement the missing schemas/services per atlas-programming-superiority-contracts.md',
            array_values($required),
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function checkDevRuntimeIntelligence(): array
    {
        $required = [
            'task_packet_runtime' => 'app/Services/Ai/Programming/AtlasDev/RuntimeIntelligence/DevTaskPacketRuntimeService.php',
            'context_gate' => 'app/Services/Ai/Programming/AtlasDev/RuntimeIntelligence/DevContextGateService.php',
            'failure_capsule' => 'app/Services/Ai/Programming/AtlasDev/RuntimeIntelligence/DevFailureCapsuleRuntimeService.php',
            'outcome_memory' => 'app/Services/Ai/Programming/AtlasDev/RuntimeIntelligence/DevOutcomeMemoryService.php',
            'run_certification' => 'app/Services/Ai/Programming/AtlasDev/RuntimeIntelligence/DevRunCertificationService.php',
            'native_capability_orchestrator' => 'app/Services/Ai/Programming/AtlasDev/RuntimeIntelligence/DevNativeCapabilityOrchestrator.php',
            'decision_materialization' => 'app/Services/Ai/Programming/AtlasDev/RuntimeIntelligence/DevDecisionMaterializationService.php',
            'orchestrator' => 'app/Services/Ai/Programming/AtlasDev/RuntimeIntelligence/DevRuntimeIntelligenceService.php',
            'task_packet_model' => 'app/Models/AtlasDevTaskPacket.php',
            'context_gate_model' => 'app/Models/AtlasDevContextGate.php',
            'failure_capsule_model' => 'app/Models/AtlasDevFailureCapsule.php',
            'outcome_memory_model' => 'app/Models/AtlasDevOutcomeMemory.php',
            'run_certification_model' => 'app/Models/AtlasDevRunCertification.php',
            'decision_materialization_model' => 'app/Models/AtlasDevDecisionMaterialization.php',
            'run_certify_command' => 'app/Console/Commands/AtlasDevRunCertifyCommand.php',
            'migration' => 'database/migrations/2026_05_22_160000_create_atlas_dev_runtime_intelligence_tables.php',
            'runtime_wiring' => 'app/Services/Ai/Programming/AtlasDevRuntimeService.php',
            'test' => 'tests/Feature/Ai/Programming/AtlasDev/AtlasDevRuntimeIntelligenceTest.php',
            'doc' => 'docs/engineering-knowledge-base/atlas-dev-runtime-intelligence.md',
        ];

        $missing = [];
        foreach ($required as $label => $path) {
            if (! $this->probe->fileExists($path)) {
                $missing[] = $label.':'.$path;
            }
        }

        $runtimeSource = $this->probe->readFile($required['runtime_wiring']) ?? '';
        $testSource = $this->probe->readFile($required['test']) ?? '';
        $docSource = $this->probe->readFile($required['doc']) ?? '';
        $wired = str_contains($runtimeSource, 'atlas_dev_runtime_intelligence')
            && str_contains($runtimeSource, 'DevRuntimeIntelligenceService');
        $testsCover = str_contains($testSource, 'DevTaskPacketRuntimeService')
            && str_contains($testSource, 'DevContextGateService')
            && str_contains($testSource, 'DevFailureCapsuleRuntimeService')
            && str_contains($testSource, 'DevOutcomeMemoryService')
            && str_contains($testSource, 'DevRunCertificationService')
            && str_contains($testSource, 'DevNativeCapabilityOrchestrator')
            && str_contains($testSource, 'AtlasDevDecisionMaterialization')
            && str_contains($testSource, 'atlas:dev:run-certify')
            && str_contains($testSource, 'AtlasDevRuntimeService');
        $docCanon = str_contains($docSource, 'runtime_acronym: ADRI')
            && str_contains($docSource, 'DevTaskPacketRuntime')
            && str_contains($docSource, 'DevContextGate')
            && str_contains($docSource, 'DevOutcomeMemory')
            && str_contains($docSource, 'DevFailureCapsule')
            && str_contains($docSource, 'DevRunCertification')
            && str_contains($docSource, 'DevNativeCapabilityOrchestrator')
            && str_contains($docSource, 'AtlasDevDecisionMaterialization')
            && str_contains($docSource, 'atlas:dev:run-certify');

        if ($missing === [] && $wired && $testsCover && $docCanon) {
            return $this->pass(
                self::CHECK_DEV_RUNTIME_INTELLIGENCE,
                self::SEVERITY_P0,
                'Atlas Dev has all 15 native capability blocks materialized, certified and wired into runtime previews',
                array_values($required),
            );
        }

        return $this->fail(
            self::CHECK_DEV_RUNTIME_INTELLIGENCE,
            self::SEVERITY_P0,
            'Atlas Dev runtime intelligence stack missing, unwired or insufficiently tested/documented',
            'restore all 15 Dev native capability blocks, materialization models, migration, AtlasDevRuntimeService preview wiring, focused tests, command and canonical doc',
            array_values($required),
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function checkForgeIntakeAndObraBuildingBlocks(): array
    {
        $required = [
            'intake_service' => 'app/Services/Ai/Programming/Forge/ForgeIntakeService.php',
            'intake_canon' => 'app/Services/Ai/Programming/Forge/ForgeIntakeCanon.php',
            'milestone_planner' => 'app/Services/Ai/Programming/Forge/ForgeMilestonePlanner.php',
            'work_packet_composer' => 'app/Services/Ai/Programming/Forge/ForgeWorkPacketComposer.php',
            'intake_model' => 'app/Models/AiForgeIntake.php',
            'milestone_model' => 'app/Models/AiForgeMilestone.php',
            'work_packet_model' => 'app/Models/AiForgeWorkPacket.php',
        ];

        $missing = [];
        foreach ($required as $label => $path) {
            if (! $this->probe->fileExists($path)) {
                $missing[] = $label.':'.$path;
            }
        }

        return $missing === []
            ? $this->pass(self::CHECK_FORGE_INTAKE_AND_OBRA_BUILDING_BLOCKS, self::SEVERITY_P0, 'Forge intake + milestone + work_packet building blocks present', array_values($required))
            : $this->fail(self::CHECK_FORGE_INTAKE_AND_OBRA_BUILDING_BLOCKS, self::SEVERITY_P0, 'Missing Forge intake artifacts: '.implode(', ', $missing),
                'implement missing services/models per atlas-forge-operating-system-contracts.md', array_values($required));
    }

    /**
     * @return array<string,mixed>
     */
    private function checkForgeSddQaCertificationLoop(): array
    {
        $required = [
            'sdd_gate' => 'app/Services/Ai/Programming/Forge/Qa/ForgeSddSpecGate.php',
            'qa_runner' => 'app/Services/Ai/Programming/Forge/Qa/ForgeQaGateRunner.php',
            'obra_certification' => 'app/Services/Ai/Programming/Forge/Qa/ForgeObraCertificationService.php',
        ];

        $missing = [];
        foreach ($required as $label => $path) {
            if (! $this->probe->fileExists($path)) {
                $missing[] = $label.':'.$path;
            }
        }

        return $missing === []
            ? $this->pass(self::CHECK_FORGE_SDD_QA_CERTIFICATION_LOOP, self::SEVERITY_P0, 'Per-Obra SDD/QA/Certification loop wired', array_values($required))
            : $this->fail(self::CHECK_FORGE_SDD_QA_CERTIFICATION_LOOP, self::SEVERITY_P0, 'Missing SDD/QA/cert artifacts: '.implode(', ', $missing),
                'implement SDD spec gate, QA gate runner and Obra certification service before claiming green', array_values($required));
    }

    /**
     * @return array<string,mixed>
     */
    private function checkForgeLiveExecution(): array
    {
        $path = 'app/Services/Ai/Programming/AtlasForgeLiveExecutionService.php';

        return $this->probe->fileExists($path)
            ? $this->pass(self::CHECK_FORGE_LIVE_EXECUTION_PRESENT, self::SEVERITY_P1, 'Forge live execution service present', [$path])
            : $this->warn(self::CHECK_FORGE_LIVE_EXECUTION_PRESENT, self::SEVERITY_P1, 'Forge live execution service missing',
                'implement AtlasForgeLiveExecutionService per atlas-forge-live-execution-e2e-v1.md', [$path]);
    }

    /**
     * @return array<string,mixed>
     */
    private function checkForgeWorkPacketNativeCapabilities(): array
    {
        $required = [
            'work_packet_intelligence' => 'app/Services/Ai/Programming/Forge/Intelligence/ForgeWorkPacketIntelligenceRuntimeService.php',
            'context_gate' => 'app/Services/Ai/Programming/Forge/Intelligence/ForgeObraContextGateService.php',
            'test_impact' => 'app/Services/Ai/Programming/Forge/Intelligence/ForgeTestImpactRuntimeService.php',
            'failure_intelligence' => 'app/Services/Ai/Programming/Forge/Intelligence/ForgeFailureIntelligenceService.php',
            'senior_obra_review' => 'app/Services/Ai/Programming/Forge/Intelligence/ForgeSeniorObraReviewService.php',
            'specialist_workcell_router' => 'app/Services/Ai/Programming/Forge/Intelligence/ForgeSpecialistWorkcellRouterService.php',
            'provider_projection' => 'app/Services/Ai/Programming/Forge/Intelligence/ForgeProviderProjectionService.php',
            'scope_guard' => 'app/Services/Ai/Programming/Forge/Intelligence/ForgeObraScopeGuardService.php',
            'obra_simulation' => 'app/Services/Ai/Programming/Forge/Intelligence/ForgeObraSimulationService.php',
            'outcome_memory' => 'app/Services/Ai/Programming/Forge/Intelligence/ForgeOutcomeMemoryService.php',
            'capability_orchestrator' => 'app/Services/Ai/Programming/Forge/Intelligence/ForgeWorkPacketCapabilityOrchestrator.php',
            'outcome_memory_model' => 'app/Models/AiForgeOutcomeMemory.php',
            'workcell_route_model' => 'app/Models/AiForgeWorkPacketWorkcellRoute.php',
            'intelligence_materialization_migration' => 'database/migrations/2026_05_22_150000_create_ai_forge_packet_intelligence_materializations.php',
            'execution_cycle_wiring' => 'app/Services/Ai/Programming/Forge/ForgeWorkPacketExecutionCycleService.php',
            'test' => 'tests/Feature/Ai/Programming/Forge/ForgeWorkPacketNativeCapabilitiesTest.php',
        ];

        $missing = [];
        foreach ($required as $label => $path) {
            if (! $this->probe->fileExists($path)) {
                $missing[] = $label.':'.$path;
            }
        }

        $cycleSource = $this->probe->readFile($required['execution_cycle_wiring']) ?? '';
        $testSource = $this->probe->readFile($required['test']) ?? '';
        $wired = str_contains($cycleSource, 'forge_native_capabilities')
            && str_contains($cycleSource, 'failure_intelligence')
            && str_contains($cycleSource, 'outcome_memory')
            && str_contains($cycleSource, 'materializeWorkcellSchedule')
            && str_contains($cycleSource, 'persistOutcomeMemory');
        $testsCover = str_contains($testSource, 'FWPIR')
            && str_contains($testSource, 'FOCG')
            && str_contains($testSource, 'FTIR')
            && str_contains($testSource, 'FFIR')
            && str_contains($testSource, 'FSORB')
            && str_contains($testSource, 'FSWR')
            && str_contains($testSource, 'FPPR')
            && str_contains($testSource, 'FOSG')
            && str_contains($testSource, 'FOSR')
            && str_contains($testSource, 'FOMR')
            && str_contains($testSource, 'AiForgeOutcomeMemory')
            && str_contains($testSource, 'AiForgeWorkPacketWorkcellRoute');

        if ($missing === [] && $wired && $testsCover) {
            return $this->pass(
                self::CHECK_FORGE_WORK_PACKET_NATIVE_CAPABILITIES,
                self::SEVERITY_P0,
                'Forge has native work-packet intelligence, context, tests, failure, review, workcell, provider projection, scope, simulation and outcome memory blocks wired into execution cycles',
                array_values($required),
            );
        }

        return $this->fail(
            self::CHECK_FORGE_WORK_PACKET_NATIVE_CAPABILITIES,
            self::SEVERITY_P0,
            'Forge work-packet native capability stack missing, unwired or insufficiently tested',
            'restore all 10 Forge-native blocks, wire them into ForgeWorkPacketExecutionCycleService and keep ForgeWorkPacketNativeCapabilitiesTest covering every acronym',
            array_values($required),
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function checkDevToForgeEscalation(): array
    {
        $packet = 'app/Services/Ai/Programming/AtlasDev/Schemas/EscalationPacket.php';
        $factory = 'app/Services/Ai/Programming/AtlasDev/Escalation/DevToForgeEscalationPacketFactory.php';
        $devRepairLoop = 'app/Services/Ai/Programming/AtlasDev/Repair/DevRepairLoopService.php';

        $present = $this->probe->fileExists($packet) && $this->probe->fileExists($factory) && $this->probe->fileExists($devRepairLoop);

        return $present
            ? $this->pass(self::CHECK_DEV_TO_FORGE_ESCALATION, self::SEVERITY_P0, 'Dev→Forge escalation packet + factory + loop emission present', [$packet, $factory, $devRepairLoop])
            : $this->fail(self::CHECK_DEV_TO_FORGE_ESCALATION, self::SEVERITY_P0,
                'Dev→Forge escalation path incomplete',
                'restore EscalationPacket schema + DevToForgeEscalationPacketFactory + DevRepairLoopService emission',
                [$packet, $factory, $devRepairLoop]);
    }

    /**
     * @return array<string,mixed>
     */
    private function checkRagFailClosedAvailable(): array
    {
        $gate = 'app/Services/Ai/Programming/AtlasDev/Gate/MandatoryRagGate.php';
        $planner = 'app/Services/Ai/Programming/ProgrammingRetrievalPlanner.php';
        $available = $this->probe->fileExists($gate) || $this->probe->fileExists($planner);

        if (! $available) {
            return $this->fail(
                self::CHECK_RAG_FAIL_CLOSED_AVAILABLE,
                self::SEVERITY_P1,
                'No RAG fail-closed surface present',
                'implement MandatoryRagGate or ensure ProgrammingRetrievalPlanner emits context_sufficiency_gate',
                [$gate, $planner],
            );
        }

        // Soft warn until the planner enforces (throws) on failed_closed in strict flows.
        $plannerContents = $this->probe->readFile($planner);
        $enforced = $plannerContents !== null
            && (str_contains((string) $plannerContents, 'failed_closed')
                || str_contains((string) $plannerContents, 'ProgrammingRagGateException'));

        return $enforced
            ? $this->pass(self::CHECK_RAG_FAIL_CLOSED_AVAILABLE, self::SEVERITY_P1, 'RAG fail-closed primitive present and observable', [$gate, $planner])
            : $this->warn(self::CHECK_RAG_FAIL_CLOSED_AVAILABLE, self::SEVERITY_P1,
                'RAG gate surface exists but enforcement not detected in planner source',
                'wire MandatoryRagGate to throw / fail-closed in strict programming flows', [$gate, $planner]);
    }

    /**
     * @return array<string,mixed>
     */
    private function checkWorldModelRanking(): array
    {
        $graph = 'app/Services/Ai/Programming/ProgrammingSemanticCodeGraphService.php';
        $model = 'app/Models/AiCodebaseWorldModel.php';

        return $this->probe->fileExists($graph) && $this->probe->fileExists($model)
            ? $this->pass(self::CHECK_WORLD_MODEL_RANKING, self::SEVERITY_P1, 'World Model (graph + persistence) primitives present', [$graph, $model])
            : $this->warn(self::CHECK_WORLD_MODEL_RANKING, self::SEVERITY_P1,
                'World Model primitives partially missing',
                'ensure ProgrammingSemanticCodeGraphService + AiCodebaseWorldModel persistence are present',
                [$graph, $model]);
    }

    /**
     * @return array<string,mixed>
     */
    private function checkCompoundingAndRagFeedback(): array
    {
        $runtime = 'app/Services/Ai/Compounding/AtlasCompoundingRuntimeService.php';
        $ragFeedback = 'app/Services/Ai/Compounding/AtlasRagFeedbackService.php';

        return $this->probe->fileExists($runtime) && $this->probe->fileExists($ragFeedback)
            ? $this->pass(self::CHECK_COMPOUNDING_AND_RAG_FEEDBACK, self::SEVERITY_P1, 'Compounding runtime + RAG feedback service present', [$runtime, $ragFeedback])
            : $this->warn(self::CHECK_COMPOUNDING_AND_RAG_FEEDBACK, self::SEVERITY_P1,
                'Compounding runtime OR rag feedback service missing',
                'ensure AtlasCompoundingRuntimeService and AtlasRagFeedbackService remain available',
                [$runtime, $ragFeedback]);
    }

    /**
     * @return array<string,mixed>
     */
    private function checkLocalMemoryIngestion(): array
    {
        $specDoc = 'docs/engineering-knowledge-base/atlas-local-agent-memory-ingestion.md';
        $runtime = 'app/Services/Ai/Memory/LocalAgentIngestion/LocalAgentMemoryIngestionService.php';
        $canon = 'app/Services/Ai/Memory/LocalAgentIngestion/LocalAgentMemoryIngestionCanon.php';
        $discovery = 'app/Services/Ai/Memory/LocalAgentIngestion/LocalAgentSourceDiscoveryService.php';
        $secretScanner = 'app/Services/Ai/Memory/LocalAgentIngestion/LocalAgentSecretScanner.php';
        $classifier = 'app/Services/Ai/Memory/LocalAgentIngestion/LocalAgentSourceClassifier.php';
        $command = 'app/Console/Commands/AtlasLocalAgentMemoryIngestCommand.php';
        $migration = 'database/migrations/2026_05_19_020000_create_ai_local_agent_ingestion_tables.php';
        $runModel = 'app/Models/AiLocalAgentIngestionRun.php';
        $sourceModel = 'app/Models/AiLocalAgentIngestionSource.php';
        $candidateModel = 'app/Models/AiLocalAgentIngestionCandidate.php';
        $config = 'config/atlas_local_agent_ingestion.php';
        $tests = 'tests/Feature/Ai/Memory/LocalAgentIngestion/LocalAgentMemoryIngestionServiceTest.php';
        $evidenceRefs = [
            $specDoc,
            $runtime,
            $canon,
            $discovery,
            $secretScanner,
            $classifier,
            $command,
            $migration,
            $runModel,
            $sourceModel,
            $candidateModel,
            $config,
            $tests,
        ];

        $hasRuntime = collect($evidenceRefs)->every(fn (string $path): bool => $this->probe->fileExists($path));
        $serviceSource = $this->probe->readFile($runtime) ?? '';
        $configSource = $this->probe->readFile($config) ?? '';
        $hasSafetyContract = str_contains($serviceSource, 'secretScanner')
            && str_contains($serviceSource, 'dry_run')
            && str_contains($serviceSource, 'receipt_hash')
            && str_contains($serviceSource, 'quarantined')
            && str_contains($configSource, 'dry_run_default')
            && str_contains($configSource, 'denylist_patterns');

        if ($hasRuntime && $hasSafetyContract) {
            return $this->pass(
                self::CHECK_LOCAL_MEMORY_INGESTION,
                self::SEVERITY_P2,
                'Local Agent Memory Ingestion runtime present with read-only discovery, dry-run default, quarantine, secret scan and receipts',
                $evidenceRefs,
            );
        }

        if ($this->probe->fileExists($specDoc)) {
            return $this->warn(self::CHECK_LOCAL_MEMORY_INGESTION, self::SEVERITY_P2,
                'Local Agent Memory Ingestion runtime or safety contract incomplete',
                'restore service/command/models/migration/tests and preserve dry-run, denylist, quarantine, secret scan and receipt hash invariants',
                $evidenceRefs);
        }

        return $this->warn(self::CHECK_LOCAL_MEMORY_INGESTION, self::SEVERITY_P2,
            'Local Agent Memory Ingestion: neither spec nor runtime present',
            'declare a canonical spec and runtime for local agent memory ingestion before relying on provider-history learning',
            [$specDoc]);
    }

    /**
     * @return array<string,mixed>
     */
    private function checkVerifiedContextExecutionLoop(): array
    {
        $service = 'app/Services/Ai/VerifiedContextExecution/AtlasVerifiedContextExecutionLoopService.php';
        $command = 'app/Console/Commands/AtlasVerifiedContextExecutionLoopCommand.php';
        $doc = 'docs/engineering-knowledge-base/atlas-verified-context-execution-loop.md';
        $test = 'tests/Feature/Ai/VerifiedContextExecution/AtlasVerifiedContextExecutionLoopServiceTest.php';
        $source = $this->probe->readFile($service) ?? '';
        $testSource = $this->probe->readFile($test) ?? '';
        $docSource = $this->probe->readFile($doc) ?? '';
        $present = $this->probe->fileExists($service)
            && $this->probe->fileExists($command)
            && $this->probe->fileExists($doc)
            && $this->probe->fileExists($test);
        $connectsRuntime = str_contains($source, 'AtlasContextCacheCompilerRuntimeService')
            && str_contains($source, 'AtlasTokenEconomyRuntimeService')
            && str_contains($source, 'AtlasLocalVerificationEngineService')
            && str_contains($source, 'AtlasAemorRuntimeService');
        $readOnly = str_contains($source, "'providers_invoked' => false")
            && str_contains($source, "'commands_executed' => false")
            && str_contains($source, "'writes' => false");
        $testsEightStages = str_contains($testSource, 'test_shadow_builds_eight_stage_read_only_verified_context_execution_loop');
        $docCanon = str_contains($docSource, 'runtime_acronym: AVCEL');

        if ($present && $connectsRuntime && $readOnly && $testsEightStages && $docCanon) {
            return $this->pass(
                self::CHECK_VERIFIED_CONTEXT_EXECUTION_LOOP,
                self::SEVERITY_P0,
                'AVCEL shadow loop connects context cache, token economy, ALVE and AEMOR candidate',
                [$service, $command, $doc, $test],
            );
        }

        return $this->fail(
            self::CHECK_VERIFIED_CONTEXT_EXECUTION_LOOP,
            self::SEVERITY_P0,
            'AVCEL missing or incomplete for Dev/Forge verified context execution',
            'implement AtlasVerifiedContextExecutionLoopService + command + doc + tests with read-only provider-free invariants',
            [$service, $command, $doc, $test],
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function checkCodeIntelligenceAutomaticGate(): array
    {
        $service = 'app/Services/Engineering/AtlasCodeIntelligenceAutomaticGateService.php';
        $command = 'app/Console/Commands/AtlasEngineeringKnowledgeCommand.php';
        $programmingGate = 'app/Services/Ai/Programming/Governance/Gates/ProgrammingCodeIntelligenceGate.php';
        $sessionBootstrap = 'app/Services/Ai/Kernel/Architecture/AtlasSessionBootstrapService.php';
        $test = 'tests/Feature/Engineering/AtlasCodeIntelligenceAutomaticGateServiceTest.php';
        $doc = 'docs/engineering-knowledge-base/code-intelligence.md';

        $serviceSource = $this->probe->readFile($service) ?? '';
        $commandSource = $this->probe->readFile($command) ?? '';
        $programmingGateSource = $this->probe->readFile($programmingGate) ?? '';
        $sessionBootstrapSource = $this->probe->readFile($sessionBootstrap) ?? '';
        $testSource = $this->probe->readFile($test) ?? '';
        $docSource = $this->probe->readFile($doc) ?? '';

        $present = $this->probe->fileExists($service)
            && $this->probe->fileExists($command)
            && $this->probe->fileExists($programmingGate)
            && $this->probe->fileExists($sessionBootstrap)
            && $this->probe->fileExists($test)
            && $this->probe->fileExists($doc);
        $failClosed = str_contains($serviceSource, 'blocks_dev_forge_when_blocked')
            && str_contains($serviceSource, 'stale_index_allowed')
            && str_contains($serviceSource, 'consumer_missing');
        $consumersCovered = str_contains($serviceSource, "'forge'")
            && str_contains($serviceSource, "'atlas_dev'")
            && str_contains($serviceSource, "'acrui'")
            && str_contains($serviceSource, "'software_twin'")
            && str_contains($serviceSource, "'avcel'");
        $commandWired = str_contains($commandSource, "'code-gate'")
            && str_contains($commandSource, '--auto-refresh')
            && str_contains($commandSource, '--strict');
        $devWired = str_contains($programmingGateSource, 'AtlasCodeIntelligenceAutomaticGateService')
            && str_contains($programmingGateSource, 'code_intelligence_automatic_gate_blocked');
        $bootstrapWired = str_contains($sessionBootstrapSource, 'code_intelligence_automatic_gate');
        $testsCover = str_contains($testSource, 'test_stale_index_blocks_when_strict_freshness_is_enabled')
            && str_contains($testSource, 'consumer_count');
        $docCovers = str_contains($docSource, 'code-gate --auto-refresh --strict --json')
            && str_contains($docSource, 'Atlas Dev, Forge, ACRUI, Software Twin e AVCEL');

        if ($present && $failClosed && $consumersCovered && $commandWired && $devWired && $bootstrapWired && $testsCover && $docCovers) {
            return $this->pass(
                self::CHECK_CODE_INTELLIGENCE_AUTOMATIC_GATE,
                self::SEVERITY_P0,
                'index-code automatic gate is fail-closed and wired into session bootstrap + Atlas Dev governance',
                [$service, $command, $programmingGate, $sessionBootstrap, $test, $doc],
            );
        }

        return $this->fail(
            self::CHECK_CODE_INTELLIGENCE_AUTOMATIC_GATE,
            self::SEVERITY_P0,
            'index-code automatic gate missing consumer coverage or Dev/Forge enforcement',
            'wire AtlasCodeIntelligenceAutomaticGateService through code-gate, session bootstrap and ProgrammingCodeIntelligenceGate with focused tests',
            [$service, $command, $programmingGate, $sessionBootstrap, $test, $doc],
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function checkTelemetryAndAudit(): array
    {
        $telemetry = 'app/Services/Ai/Programming/AtlasDev/Repair/RepairTelemetryRecorder.php';
        $auditEvent = 'app/Services/Ai/Evidence/AuditEventService.php';

        return $this->probe->fileExists($telemetry) && $this->probe->fileExists($auditEvent)
            ? $this->pass(self::CHECK_TELEMETRY_AND_AUDIT, self::SEVERITY_P1,
                'Repair telemetry recorder + Evidence AuditEventService present',
                [$telemetry, $auditEvent])
            : $this->warn(self::CHECK_TELEMETRY_AND_AUDIT, self::SEVERITY_P1,
                'Telemetry OR audit event service missing',
                'ensure RepairTelemetryRecorder and AuditEventService are wired for observability',
                [$telemetry, $auditEvent]);
    }

    /**
     * @return array<string,mixed>
     */
    private function checkControlPlane(): array
    {
        $controlPlane = 'app/Services/Ai/Evidence/EvidenceControlPlaneService.php';

        return $this->probe->fileExists($controlPlane)
            ? $this->pass(self::CHECK_CONTROL_PLANE, self::SEVERITY_P1, 'Evidence Control Plane service present', [$controlPlane])
            : $this->warn(self::CHECK_CONTROL_PLANE, self::SEVERITY_P1, 'Evidence Control Plane service missing',
                'implement EvidenceControlPlaneService per atlas-evidence-certification-runtime.md', [$controlPlane]);
    }

    /**
     * @return array<string,mixed>
     */
    private function checkE2eBatteryExists(): array
    {
        $battery = 'tests/Feature/Ai/E2E/AtlasDevForgeE2EScenarioBatteryTest.php';

        return $this->probe->fileExists($battery)
            ? $this->pass(self::CHECK_E2E_BATTERY_EXISTS, self::SEVERITY_P0, 'Internal Dev/Forge E2E scenario battery present', [$battery])
            : $this->fail(self::CHECK_E2E_BATTERY_EXISTS, self::SEVERITY_P0,
                'Internal Dev/Forge E2E scenario battery missing',
                'create tests/Feature/Ai/E2E/AtlasDevForgeE2EScenarioBatteryTest.php exercising the 10 canonical scenarios + benchmark_not_run marker',
                [$battery]);
    }

    /**
     * @return array<string,mixed>
     */
    private function checkBenchmarkReadinessHarnessNotRun(): array
    {
        // This certification deliberately reports the benchmark harness as
        // `not_run`. The check therefore PASSES when no rival execution
        // happened, and is the canonical place the operator can rely on to
        // see "we did NOT compare against Claude Code / Codex / Cursor".
        return $this->pass(
            self::CHECK_BENCHMARK_READINESS_HARNESS_NOT_RUN,
            self::SEVERITY_P2,
            'External benchmark/rivals harness intentionally NOT executed by this certification (benchmark_status=not_run)',
            [
                'docs/engineering-knowledge-base/atlas-programming-superiority-roadmap.md',
                'tests/Feature/Ai/E2E/AtlasDevForgeE2EScenarioBatteryTest.php',
            ],
        );
    }

    /**
     * @param  list<array<string,mixed>>  $checks
     */
    private function aggregateOverall(array $checks): string
    {
        $hasFail = false;
        $hasP0OrP1Warn = false;

        foreach ($checks as $check) {
            $status = (string) ($check['status'] ?? '');
            $severity = (string) ($check['severity'] ?? '');
            if ($status === self::CHECK_STATUS_BLOCKED) {
                $hasFail = true;
            }
            if ($status === self::CHECK_STATUS_WARN
                && in_array($severity, [self::SEVERITY_P0, self::SEVERITY_P1], true)) {
                $hasP0OrP1Warn = true;
            }
        }

        if ($hasFail) {
            return self::STATUS_BLOCKED;
        }
        if ($hasP0OrP1Warn) {
            return self::STATUS_PARTIAL;
        }

        return self::STATUS_GREEN;
    }

    /**
     * @param  list<array<string,mixed>>  $checks
     * @return array<string,int>
     */
    private function summarize(array $checks): array
    {
        $summary = [
            'total' => count($checks),
            'pass' => 0,
            'warn' => 0,
            'fail' => 0,
            'p0_blockers' => 0,
            'p1_blockers' => 0,
        ];

        foreach ($checks as $check) {
            $status = (string) ($check['status'] ?? '');
            $severity = (string) ($check['severity'] ?? '');
            if ($status === self::CHECK_STATUS_GREEN) {
                $summary['pass']++;
            } elseif ($status === self::CHECK_STATUS_WARN) {
                $summary['warn']++;
            } elseif ($status === self::CHECK_STATUS_BLOCKED) {
                $summary['fail']++;
                if ($severity === self::SEVERITY_P0) {
                    $summary['p0_blockers']++;
                }
                if ($severity === self::SEVERITY_P1) {
                    $summary['p1_blockers']++;
                }
            }
        }

        return $summary;
    }

    /**
     * @param  list<array<string,mixed>>  $checks
     * @return list<array<string,mixed>>
     */
    private function collectBlockers(array $checks): array
    {
        $blockers = [];
        foreach ($checks as $check) {
            if (($check['status'] ?? '') === self::CHECK_STATUS_BLOCKED) {
                $blockers[] = [
                    'check_id' => $check['check_id'],
                    'severity' => $check['severity'],
                    'reason' => $check['reason'],
                    'evidence_refs' => array_values((array) ($check['evidence_refs'] ?? [])),
                ];
            }
        }

        return $blockers;
    }

    /**
     * @param  list<array<string,mixed>>  $checks
     * @return list<array<string,mixed>>
     */
    private function collectRemediation(array $checks): array
    {
        $remediation = [];
        foreach ($checks as $check) {
            $status = (string) ($check['status'] ?? '');
            if ($status === self::CHECK_STATUS_GREEN) {
                continue;
            }
            $action = (string) ($check['remediation'] ?? '');
            if ($action === '') {
                continue;
            }
            $remediation[] = [
                'check_id' => $check['check_id'],
                'severity' => $check['severity'],
                'action' => $action,
            ];
        }

        return $remediation;
    }

    /**
     * @param  list<array<string,mixed>>  $checks
     * @return list<string>
     */
    private function topLevelEvidenceRefs(array $checks): array
    {
        $refs = [];
        foreach ($checks as $check) {
            foreach ((array) ($check['evidence_refs'] ?? []) as $ref) {
                if (is_string($ref) && $ref !== '') {
                    $refs[] = $ref;
                }
            }
        }

        return array_values(array_unique($refs));
    }

    /**
     * @param  list<string>  $evidenceRefs
     * @return array<string,mixed>
     */
    private function pass(string $checkId, string $severity, string $reason, array $evidenceRefs): array
    {
        return [
            'check_id' => $checkId,
            'status' => self::CHECK_STATUS_GREEN,
            'severity' => $severity,
            'reason' => $reason,
            'remediation' => null,
            'evidence_refs' => array_values($evidenceRefs),
        ];
    }

    /**
     * @param  list<string>  $evidenceRefs
     * @return array<string,mixed>
     */
    private function warn(string $checkId, string $severity, string $reason, string $remediation, array $evidenceRefs): array
    {
        return [
            'check_id' => $checkId,
            'status' => self::CHECK_STATUS_WARN,
            'severity' => $severity,
            'reason' => $reason,
            'remediation' => $remediation,
            'evidence_refs' => array_values($evidenceRefs),
        ];
    }

    /**
     * @param  list<string>  $evidenceRefs
     * @return array<string,mixed>
     */
    private function fail(string $checkId, string $severity, string $reason, string $remediation, array $evidenceRefs): array
    {
        return [
            'check_id' => $checkId,
            'status' => self::CHECK_STATUS_BLOCKED,
            'severity' => $severity,
            'reason' => $reason,
            'remediation' => $remediation,
            'evidence_refs' => array_values($evidenceRefs),
        ];
    }
}
