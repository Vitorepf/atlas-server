<?php

namespace Tests\Feature\Ai\ProgrammingRuntime;

use App\Services\Ai\ProgrammingRuntime\AtlasProgrammingFinalCertificationService;
use App\Services\Ai\ProgrammingRuntime\RepoProbe;
use Tests\TestCase;

class AtlasProgrammingFinalCertificationServiceTest extends TestCase
{
    public function test_green_path_when_all_canonical_artifacts_present(): void
    {
        $probe = $this->probeWithGreenFixtures();
        $service = new AtlasProgrammingFinalCertificationService($probe);

        $payload = $service->certify();

        $this->assertSame(AtlasProgrammingFinalCertificationService::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame(
            AtlasProgrammingFinalCertificationService::BENCHMARK_STATUS_NOT_RUN,
            $payload['benchmark_status'],
            'Final certification must never declare benchmark execution.',
        );
        // local_memory_ingestion is a documented warn at severity P2 — it does
        // not force a partial/blocked overall, so green is achievable in the
        // green fixture provided P0/P1 checks all pass.
        $this->assertSame(
            AtlasProgrammingFinalCertificationService::STATUS_GREEN,
            $payload['overall_status'],
        );
        $this->assertSame([], $payload['blockers']);
        $this->assertSame(64, strlen((string) $payload['certification_hash']));

        // Every canonical check id must appear.
        $checkIds = array_column($payload['checks'], 'check_id');
        foreach (AtlasProgrammingFinalCertificationService::ALL_CHECK_IDS as $expected) {
            $this->assertContains($expected, $checkIds, "missing check_id={$expected}");
        }

        // benchmark_readiness_harness_not_run is a pass with informative reason.
        $benchmark = $this->checkById($payload, AtlasProgrammingFinalCertificationService::CHECK_BENCHMARK_READINESS_HARNESS_NOT_RUN);
        $this->assertSame(AtlasProgrammingFinalCertificationService::CHECK_STATUS_GREEN, $benchmark['status']);
        $this->assertStringContainsString('NOT executed', (string) $benchmark['reason']);
    }

    public function test_p0_blocker_forces_overall_blocked_even_when_other_checks_pass(): void
    {
        $probe = $this->probeWithGreenFixtures();
        // Remove a P0 artifact: ForgeIntakeService missing breaks the
        // forge_intake_and_obra_building_blocks check.
        $probe->removeFile('app/Services/Ai/Programming/Forge/ForgeIntakeService.php');

        $payload = (new AtlasProgrammingFinalCertificationService($probe))->certify();

        $this->assertSame(
            AtlasProgrammingFinalCertificationService::STATUS_BLOCKED,
            $payload['overall_status'],
            'A single P0 blocker must force overall_status=blocked.',
        );
        $blockerIds = array_column($payload['blockers'], 'check_id');
        $this->assertContains(
            AtlasProgrammingFinalCertificationService::CHECK_FORGE_INTAKE_AND_OBRA_BUILDING_BLOCKS,
            $blockerIds,
        );
        $this->assertGreaterThanOrEqual(1, $payload['summary']['p0_blockers']);
        $remediationIds = array_column($payload['remediation'], 'check_id');
        $this->assertContains(
            AtlasProgrammingFinalCertificationService::CHECK_FORGE_INTAKE_AND_OBRA_BUILDING_BLOCKS,
            $remediationIds,
            'Blocked checks must surface a remediation entry.',
        );
    }

    public function test_p1_warn_downgrades_overall_to_partial_without_blocking(): void
    {
        $probe = $this->probeWithGreenFixtures();
        // Forge live execution is a P1 check that warns when missing; this
        // should bring overall to `partial`, never `blocked`.
        $probe->removeFile('app/Services/Ai/Programming/AtlasForgeLiveExecutionService.php');

        $payload = (new AtlasProgrammingFinalCertificationService($probe))->certify();

        $this->assertSame(AtlasProgrammingFinalCertificationService::STATUS_PARTIAL, $payload['overall_status']);
        $this->assertSame([], $payload['blockers']);
        $liveExec = $this->checkById($payload, AtlasProgrammingFinalCertificationService::CHECK_FORGE_LIVE_EXECUTION_PRESENT);
        $this->assertSame(AtlasProgrammingFinalCertificationService::CHECK_STATUS_WARN, $liveExec['status']);
        $this->assertSame(AtlasProgrammingFinalCertificationService::SEVERITY_P1, $liveExec['severity']);
    }

    public function test_certification_hash_is_stable_for_identical_input(): void
    {
        $probe = $this->probeWithGreenFixtures();
        $service = new AtlasProgrammingFinalCertificationService($probe);

        $a = $service->certify();
        $b = $service->certify();

        $this->assertSame($a['certification_hash'], $b['certification_hash']);

        // JSON serialization is stable and re-parsable.
        $json = json_encode($a, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->assertNotFalse($json, 'certification payload must serialize cleanly');
        $decoded = json_decode((string) $json, true);
        $this->assertSame(
            AtlasProgrammingFinalCertificationService::SCHEMA_VERSION,
            $decoded['schema_version'],
        );
        $this->assertCount(
            count(AtlasProgrammingFinalCertificationService::ALL_CHECK_IDS),
            $decoded['checks'],
        );
    }

    public function test_benchmark_status_remains_not_run_even_when_blocker_present(): void
    {
        // Even in the worst case (everything blocked), the certification must
        // never flip benchmark_status to "executed". This is a hard invariant.
        $probe = new FinalCertFakeProbe;
        $payload = (new AtlasProgrammingFinalCertificationService($probe))->certify();

        $this->assertSame(
            AtlasProgrammingFinalCertificationService::STATUS_BLOCKED,
            $payload['overall_status'],
        );
        $this->assertSame(
            AtlasProgrammingFinalCertificationService::BENCHMARK_STATUS_NOT_RUN,
            $payload['benchmark_status'],
        );
        $this->assertStringContainsString('OUT of scope', (string) $payload['benchmark_note']);
    }

    /**
     * Builds a fake probe with every canonical artifact present so the green
     * path is reachable in a deterministic way. Individual tests then remove
     * specific files to simulate blockers/warns.
     */
    private function probeWithGreenFixtures(): FinalCertFakeProbe
    {
        $probe = new FinalCertFakeProbe;
        $stub = '<?php // canonical stub for final-cert green fixture';

        foreach ([
            'app/Services/Ai/Programming/AtlasDev/Repair/DevRepairLoopService.php',
            'app/Services/Ai/RouterRuntime/FlowRouterService.php',
            'app/Services/Ai/Programming/AtlasDev/Schemas/PatchIntelligenceReceipt.php',
            'app/Services/Ai/Programming/ProgrammingTestImpactAnalyzer.php',
            'app/Services/Ai/Programming/AtlasDev/Schemas/DebugReceipt.php',
            'app/Services/Ai/Programming/AtlasDev/Schemas/ReviewReceipt.php',
            'app/Services/Ai/Programming/ProgrammingRepairExecutor.php',
            'app/Services/Ai/Programming/AtlasDev/Repair/FailureModeClassifier.php',
            'app/Services/Ai/Programming/Forge/ForgeIntakeService.php',
            'app/Services/Ai/Programming/Forge/ForgeIntakeCanon.php',
            'app/Services/Ai/Programming/Forge/ForgeMilestonePlanner.php',
            'app/Services/Ai/Programming/Forge/ForgeWorkPacketComposer.php',
            'app/Models/AiForgeIntake.php',
            'app/Models/AiForgeMilestone.php',
            'app/Models/AiForgeWorkPacket.php',
            'app/Services/Ai/Programming/Forge/Qa/ForgeSddSpecGate.php',
            'app/Services/Ai/Programming/Forge/Qa/ForgeQaGateRunner.php',
            'app/Services/Ai/Programming/Forge/Qa/ForgeObraCertificationService.php',
            'app/Services/Ai/Programming/AtlasForgeLiveExecutionService.php',
            'app/Services/Ai/Programming/AtlasDev/Schemas/EscalationPacket.php',
            'app/Services/Ai/Programming/AtlasDev/Escalation/DevToForgeEscalationPacketFactory.php',
            'app/Services/Ai/Programming/AtlasDev/Gate/MandatoryRagGate.php',
            'app/Services/Ai/Programming/ProgrammingSemanticCodeGraphService.php',
            'app/Models/AiCodebaseWorldModel.php',
            'app/Services/Ai/Compounding/AtlasCompoundingRuntimeService.php',
            'app/Services/Ai/Compounding/AtlasRagFeedbackService.php',
            'docs/engineering-knowledge-base/atlas-local-agent-memory-ingestion.md',
            'app/Services/Ai/Programming/AtlasDev/Repair/RepairTelemetryRecorder.php',
            'app/Services/Ai/Evidence/AuditEventService.php',
            'app/Services/Ai/Evidence/EvidenceControlPlaneService.php',
            'tests/Feature/Ai/E2E/AtlasDevForgeE2EScenarioBatteryTest.php',
        ] as $path) {
            $probe->setFile($path, $stub);
        }
        // Programming retrieval planner must mention failed_closed for the
        // RAG fail-closed check to pass (otherwise it returns warn).
        $probe->setFile(
            'app/Services/Ai/Programming/ProgrammingRetrievalPlanner.php',
            "<?php\nclass ProgrammingRetrievalPlanner { public const STATUS = 'failed_closed'; }",
        );

        return $probe;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function checkById(array $payload, string $checkId): array
    {
        foreach ($payload['checks'] as $check) {
            if ($check['check_id'] === $checkId) {
                return $check;
            }
        }
        $this->fail("check {$checkId} not found in certification payload");
    }
}

class FinalCertFakeProbe implements RepoProbe
{
    /** @var array<string,string> */
    private array $files = [];

    public function setFile(string $relativePath, string $contents): void
    {
        $this->files[$relativePath] = $contents;
    }

    public function removeFile(string $relativePath): void
    {
        unset($this->files[$relativePath]);
    }

    public function fileExists(string $relativePath): bool
    {
        return array_key_exists($relativePath, $this->files);
    }

    public function readFile(string $relativePath): ?string
    {
        return $this->files[$relativePath] ?? null;
    }

    public function countMatchesInDirectory(
        string $relativeDirectory,
        string $needle,
        string $glob = '*.php',
        array $excludeRelativePaths = [],
    ): int {
        return 0;
    }

    public function findFilesContaining(
        string $relativeDirectory,
        string $needle,
        string $glob = '*.php',
        array $excludeRelativePaths = [],
    ): array {
        return [];
    }
}
