<?php

namespace Tests\Feature\Ai\ProgrammingRuntime;

use App\Services\Ai\ProgrammingRuntime\ConfigReader;
use App\Services\Ai\ProgrammingRuntime\ProgrammingRuntimeReadinessCanon;
use App\Services\Ai\ProgrammingRuntime\ProgrammingRuntimeReadinessService;
use App\Services\Ai\ProgrammingRuntime\RepoProbe;
use Tests\TestCase;

class ProgrammingRuntimeReadinessServiceTest extends TestCase
{
    public function test_all_green_when_every_sentinel_passes(): void
    {
        $probe = $this->probeWithGreenFixtures();
        $config = new FakeConfigReader(['atlas_ai.tool_runtime.strict_mode' => true]);

        $report = (new ProgrammingRuntimeReadinessService($probe, $config))->report();

        $this->assertSame(ProgrammingRuntimeReadinessCanon::STATUS_GREEN, $report['status']);
        $this->assertSame(0, $report['summary']['blocked']);
        $this->assertSame(0, $report['summary']['warn']);
        $this->assertSame(count(ProgrammingRuntimeReadinessCanon::ALL_CHECK_IDS), $report['summary']['green']);
        $this->assertSame([], $report['blockers']);
        $this->assertSame([], $report['next_actions']);
    }

    public function test_blocked_when_neither_gateway_nor_worker_imports_kernel(): void
    {
        $probe = $this->probeWithGreenFixtures();
        // override: BOTH gateway and worker present but with NO Kernel sentinels —
        // legacy path on both layers. This is the canonical "no Kernel anywhere" state.
        $probe->setFile('app/Services/Ai/AiGatewayService.php', '<?php class AiGatewayService {}');
        $probe->setFile('app/Services/Ai/AiWorker.php', '<?php class AiWorker { public function __construct(public AiProviderManager $providers) {} }');

        $config = new FakeConfigReader(['atlas_ai.tool_runtime.strict_mode' => true]);
        $report = (new ProgrammingRuntimeReadinessService($probe, $config))->report();

        $this->assertSame(ProgrammingRuntimeReadinessCanon::STATUS_BLOCKED, $report['status']);
        $this->assertGreaterThanOrEqual(1, $report['summary']['p0_blocked']);
        $aiworkerCheck = $this->checkById($report, ProgrammingRuntimeReadinessCanon::CHECK_AIWORKER_KERNEL_INTEGRATION);
        $this->assertSame(ProgrammingRuntimeReadinessCanon::CHECK_STATUS_BLOCKED, $aiworkerCheck['status']);
        $this->assertSame(ProgrammingRuntimeReadinessCanon::SEVERITY_P0, $aiworkerCheck['severity']);
        $this->assertNotNull($aiworkerCheck['blocker_reason']);
        $this->assertStringContainsString('atlas-aiworker-kernel-integration-adr.md', $aiworkerCheck['remediation']);
    }

    public function test_warn_when_phase_1_gateway_bridge_shipped_but_worker_pending(): void
    {
        // Post-fix 2026-05-18: green fixture already mirrors Phase 1 (gateway
        // bridge) wired. Remove worker Kernel injection to simulate the
        // current production state (Phase 1 shipped, Phases 4-6 ADR pending).
        $probe = $this->probeWithGreenFixtures();
        $probe->setFile('app/Services/Ai/AiWorker.php', '<?php class AiWorker { public function __construct(public AiProviderManager $providers) {} }');

        $config = new FakeConfigReader(['atlas_ai.tool_runtime.strict_mode' => true]);
        $report = (new ProgrammingRuntimeReadinessService($probe, $config))->report();

        $aiworkerCheck = $this->checkById($report, ProgrammingRuntimeReadinessCanon::CHECK_AIWORKER_KERNEL_INTEGRATION);
        $this->assertSame(
            ProgrammingRuntimeReadinessCanon::CHECK_STATUS_WARN,
            $aiworkerCheck['status'],
            'Phase 1 shipped + Phases 4-6 pending must be warn (partial), never falsely blocked.',
        );
        $this->assertStringContainsString('Phase 1', (string) $aiworkerCheck['detail']);
        $this->assertStringContainsString('Phases 4-6', (string) $aiworkerCheck['remediation']);
    }

    public function test_warn_when_worker_injects_kernel_but_gateway_bridge_absent(): void
    {
        // Inverted partial state: worker upgraded ahead of gateway bridge.
        $probe = $this->probeWithGreenFixtures();
        $probe->setFile('app/Services/Ai/AiGatewayService.php', '<?php class AiGatewayService {}');

        $config = new FakeConfigReader(['atlas_ai.tool_runtime.strict_mode' => true]);
        $report = (new ProgrammingRuntimeReadinessService($probe, $config))->report();

        $aiworkerCheck = $this->checkById($report, ProgrammingRuntimeReadinessCanon::CHECK_AIWORKER_KERNEL_INTEGRATION);
        $this->assertSame(ProgrammingRuntimeReadinessCanon::CHECK_STATUS_WARN, $aiworkerCheck['status']);
        $this->assertStringContainsString('AiGatewayMissionBridge', (string) $aiworkerCheck['detail']);
    }

    public function test_blocked_when_route_decision_has_no_production_callers(): void
    {
        $probe = $this->probeWithGreenFixtures();
        // override: no caller of DualCoreRouteDecisionService outside its own dir
        $probe->setFindFilesResults('app::DualCoreRouteDecisionService', []);

        $config = new FakeConfigReader(['atlas_ai.tool_runtime.strict_mode' => true]);
        $report = (new ProgrammingRuntimeReadinessService($probe, $config))->report();

        $this->assertSame(ProgrammingRuntimeReadinessCanon::STATUS_BLOCKED, $report['status']);
        $check = $this->checkById($report, ProgrammingRuntimeReadinessCanon::CHECK_ROUTE_DECISION_V1_HAS_PRODUCTION_CALLERS);
        $this->assertSame(ProgrammingRuntimeReadinessCanon::CHECK_STATUS_BLOCKED, $check['status']);
        $this->assertSame(ProgrammingRuntimeReadinessCanon::SEVERITY_P0, $check['severity']);
    }

    public function test_blocked_when_rag_gate_class_missing(): void
    {
        $probe = $this->probeWithGreenFixtures();
        // override: remove the canonical gate class file.
        $probe->setFile('app/Services/Ai/Programming/AtlasDev/Gate/MandatoryRagGate.php', '');
        // Re-empty by re-creating an empty fixture (FakeRepoProbe::setFile with
        // empty content still counts as fileExists=true; to truly remove we
        // use a new probe state).
        $bare = new FakeRepoProbe;
        foreach ([
            'app/Services/Ai/AiGatewayService.php' => '<?php use App\Services\Ai\Mission\AiGatewayMissionBridge; class AiGatewayService {}',
            'app/Services/Ai/AiWorker.php' => '<?php use App\Services\Ai\Mission\MissionLifecycleService; use App\Services\Ai\Mission\MissionFactoryService; use App\Services\Ai\Mission\MissionCertificationService; use App\Services\Ai\Policy\PermissionGateService; use App\Services\Ai\Evidence\CertificationRuntimeService; use App\Services\Ai\RouterRuntime\FlowRouterService; use App\Services\Ai\RouterRuntime\DomainRouterService; class AiWorker {}',
            'app/Services/Ai/DualCore/DualCoreRouteDecisionService.php' => '<?php class DualCoreRouteDecisionService {}',
            'app/Models/AiDualCoreRouteDecision.php' => '<?php class AiDualCoreRouteDecision {}',
            'app/Services/Ai/Mission/MissionCertificationService.php' => "<?php const SEVERITY_CRITICAL = 'critical'; \$criticalFailures = []; return ['severity' => SEVERITY_CRITICAL, 'remediation' => 'fix it'];",
            'app/Services/Ai/Programming/Kernel/AtlasForgeHandoffAdapter.php' => '<?php class AtlasForgeHandoffAdapter {}',
        ] as $path => $content) {
            $bare->setFile($path, $content);
        }
        // canonical RAG class files deliberately absent
        $bare->setFindFilesResults('app::DualCoreRouteDecisionService', ['app/Services/Ai/RouterRuntime/FlowRouterService.php']);
        $bare->setFindFilesResults('app::atlas.dev_to_forge.escalation_packet.v1', ['app/Services/Ai/Programming/Kernel/AtlasForgeHandoffAdapter.php']);
        $bare->setCountInDirectoryResult('app::atlas.dev_to_forge.escalation_packet.v1', 1);
        $bare->setFindFilesResults('app/Services/Ai/Programming::atlas.dev_to_forge.escalation_packet.v1', ['app/Services/Ai/Programming/Kernel/AtlasForgeHandoffAdapter.php']);
        $bare->setFindFilesResults('tests/Feature/Ai::POST /ai/interactions', ['tests/Feature/Ai/Kernel/AiWorkerKernelIntegrationE2ETest.php']);
        $bare->setFindFilesResults('tests/Feature/Ai::KernelIntegrationE2E', ['tests/Feature/Ai/Kernel/AiWorkerKernelIntegrationE2ETest.php']);
        $bare->setFindFilesResults('app::DevToForgePromotionService', []);

        $config = new FakeConfigReader(['atlas_ai.tool_runtime.strict_mode' => true]);
        $report = (new ProgrammingRuntimeReadinessService($bare, $config))->report();

        $this->assertSame(ProgrammingRuntimeReadinessCanon::STATUS_BLOCKED, $report['status']);
        $check = $this->checkById($report, ProgrammingRuntimeReadinessCanon::CHECK_MANDATORY_RAG_GATE_ENFORCED);
        $this->assertSame(ProgrammingRuntimeReadinessCanon::CHECK_STATUS_BLOCKED, $check['status']);
        $this->assertSame(ProgrammingRuntimeReadinessCanon::SEVERITY_P1, $check['severity']);
        $this->assertStringContainsString('canonical gate class missing', (string) $check['blocker_reason']);
    }

    public function test_warn_when_rag_gate_canonical_classes_exist_but_no_caller(): void
    {
        // Post-fix 2026-05-18: canonical signal heuristic returns warn (not
        // blocked) when MandatoryRagGate + STATUS_BLOCKED exist but no
        // production caller is wired into the strict-flow runtime.
        $probe = $this->probeWithGreenFixtures();
        $probe->setFindFilesResults('app/Services/Ai/Programming::MandatoryRagGate', []);

        $config = new FakeConfigReader(['atlas_ai.tool_runtime.strict_mode' => true]);
        $report = (new ProgrammingRuntimeReadinessService($probe, $config))->report();

        $check = $this->checkById($report, ProgrammingRuntimeReadinessCanon::CHECK_MANDATORY_RAG_GATE_ENFORCED);
        $this->assertSame(ProgrammingRuntimeReadinessCanon::CHECK_STATUS_WARN, $check['status']);
        $this->assertStringContainsString('no production caller', (string) $check['detail']);
    }

    public function test_blocked_when_tool_strict_mode_disabled(): void
    {
        $probe = $this->probeWithGreenFixtures();
        $config = new FakeConfigReader(['atlas_ai.tool_runtime.strict_mode' => false]);

        $report = (new ProgrammingRuntimeReadinessService($probe, $config))->report();

        $this->assertSame(ProgrammingRuntimeReadinessCanon::STATUS_BLOCKED, $report['status']);
        $check = $this->checkById($report, ProgrammingRuntimeReadinessCanon::CHECK_TOOL_POLICY_EVIDENCE_STRICT_MODE);
        $this->assertSame(ProgrammingRuntimeReadinessCanon::CHECK_STATUS_BLOCKED, $check['status']);
        $this->assertSame(ProgrammingRuntimeReadinessCanon::SEVERITY_P1, $check['severity']);
    }

    public function test_blocked_when_escalation_packet_schema_missing(): void
    {
        $probe = $this->probeWithGreenFixtures();
        $probe->setFindFilesResults('app::atlas.dev_to_forge.escalation_packet.v1', []);
        $probe->setCountInDirectoryResult('app::atlas.dev_to_forge.escalation_packet.v1', 0);

        $config = new FakeConfigReader(['atlas_ai.tool_runtime.strict_mode' => true]);
        $report = (new ProgrammingRuntimeReadinessService($probe, $config))->report();

        $this->assertSame(ProgrammingRuntimeReadinessCanon::STATUS_BLOCKED, $report['status']);
        $implementedCheck = $this->checkById($report, ProgrammingRuntimeReadinessCanon::CHECK_ESCALATION_PACKET_V1_IMPLEMENTED);
        $this->assertSame(ProgrammingRuntimeReadinessCanon::CHECK_STATUS_BLOCKED, $implementedCheck['status']);

        $devForgeCheck = $this->checkById($report, ProgrammingRuntimeReadinessCanon::CHECK_ESCALATION_PACKET_V1_USED_IN_DEV_FORGE_PATH);
        // child check inherits blocker when parent is missing
        $this->assertSame(ProgrammingRuntimeReadinessCanon::CHECK_STATUS_BLOCKED, $devForgeCheck['status']);
        $this->assertStringContainsString('cannot evaluate', $devForgeCheck['blocker_reason']);
        $this->assertStringContainsString('Depends on escalation_packet_v1_implemented', $devForgeCheck['detail']);
    }

    public function test_partial_when_only_non_blocker_warn_present(): void
    {
        $probe = $this->probeWithGreenFixtures();
        // Simulate a retained surface adapter that has not yet been wrapped by
        // the canonical escalation_packet_v1. File presence alone is no longer
        // a warning; missing canonical wrapping is.
        $probe->setFile('app/Services/Ai/Programming/AtlasDev/Escalation/ForgePromotionPreviewBuilder.php', '<?php final class ForgePromotionPreviewBuilder {}');

        $config = new FakeConfigReader(['atlas_ai.tool_runtime.strict_mode' => true]);
        $report = (new ProgrammingRuntimeReadinessService($probe, $config))->report();

        $this->assertSame(ProgrammingRuntimeReadinessCanon::STATUS_PARTIAL, $report['status']);
        $check = $this->checkById($report, ProgrammingRuntimeReadinessCanon::CHECK_DEV_FORGE_NO_PARALLEL_ESCALATION_SCHEMAS);
        $this->assertSame(ProgrammingRuntimeReadinessCanon::CHECK_STATUS_WARN, $check['status']);
        $this->assertStringContainsString('lack canonical escalation_packet_v1 wrapping', $check['detail']);
    }

    public function test_json_shape_is_stable(): void
    {
        $probe = $this->probeWithGreenFixtures();
        $config = new FakeConfigReader(['atlas_ai.tool_runtime.strict_mode' => true]);

        $report = (new ProgrammingRuntimeReadinessService($probe, $config))->report();

        foreach (['schema', 'status', 'generated_at', 'summary', 'checks', 'blockers', 'next_actions'] as $field) {
            $this->assertArrayHasKey($field, $report, "missing top-level field [{$field}]");
        }
        $this->assertSame(ProgrammingRuntimeReadinessCanon::SCHEMA_VERSION, $report['schema']);

        foreach (['total_checks', 'green', 'warn', 'blocked', 'p0_blocked', 'p1_blocked'] as $field) {
            $this->assertArrayHasKey($field, $report['summary'], "missing summary field [{$field}]");
        }

        $this->assertCount(count(ProgrammingRuntimeReadinessCanon::ALL_CHECK_IDS), $report['checks']);
        foreach ($report['checks'] as $check) {
            foreach (['id', 'label', 'status', 'severity', 'detail', 'evidence_refs', 'remediation', 'blocker_reason'] as $field) {
                $this->assertArrayHasKey($field, $check, "missing check field [{$field}] on {$check['id']}");
            }
        }

        $json = json_encode($report, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->assertNotFalse($json, 'report must be JSON encodable');
        $decoded = json_decode((string) $json, true);
        $this->assertSame($report['schema'], $decoded['schema']);
        $this->assertSame($report['status'], $decoded['status']);
    }

    public function test_command_returns_failure_exit_when_blocked(): void
    {
        $probe = $this->probeWithGreenFixtures();
        // Wipe BOTH gateway and worker Kernel sentinels to force a hard P0 block.
        $probe->setFile('app/Services/Ai/AiGatewayService.php', '<?php class AiGatewayService {}');
        $probe->setFile('app/Services/Ai/AiWorker.php', '<?php class AiWorker {}');
        $config = new FakeConfigReader(['atlas_ai.tool_runtime.strict_mode' => true]);

        $this->app->instance(ProgrammingRuntimeReadinessService::class, new ProgrammingRuntimeReadinessService($probe, $config));

        $exit = $this->artisan('atlas:ai:programming-runtime', ['--action' => 'readiness', '--json' => true])->run();

        $this->assertSame(1, $exit, 'blocked report must produce a non-zero exit code for CI');
    }

    public function test_command_returns_success_exit_when_green(): void
    {
        $probe = $this->probeWithGreenFixtures();
        $config = new FakeConfigReader(['atlas_ai.tool_runtime.strict_mode' => true]);

        $this->app->instance(ProgrammingRuntimeReadinessService::class, new ProgrammingRuntimeReadinessService($probe, $config));

        $exit = $this->artisan('atlas:ai:programming-runtime', ['--action' => 'readiness', '--json' => true])->run();

        $this->assertSame(0, $exit);
    }

    public function test_real_repo_report_is_honest_about_known_gaps(): void
    {
        // Smoke against the real repository state. This test is intentionally
        // narrow: it asserts previously delivered contracts stay green instead
        // of preserving stale "known gap" expectations after the gap is closed.
        $service = app(ProgrammingRuntimeReadinessService::class);
        $report = $service->report();

        $aiworker = $this->checkById($report, ProgrammingRuntimeReadinessCanon::CHECK_AIWORKER_KERNEL_INTEGRATION);
        $this->assertSame(
            ProgrammingRuntimeReadinessCanon::CHECK_STATUS_GREEN,
            $aiworker['status'],
            'AiWorker must consult Kernel PermissionGate in warn-only mode after the gateway bridge persists payload.kernel.',
        );

        // route_decision implemented in prior session
        $implemented = $this->checkById($report, ProgrammingRuntimeReadinessCanon::CHECK_ROUTE_DECISION_V1_IMPLEMENTED);
        $this->assertSame(
            ProgrammingRuntimeReadinessCanon::CHECK_STATUS_GREEN,
            $implemented['status'],
            'DualCoreRouteDecisionService was delivered 2026-05-18; implementation check must be green.',
        );

        $devForge = $this->checkById($report, ProgrammingRuntimeReadinessCanon::CHECK_DEV_FORGE_NO_PARALLEL_ESCALATION_SCHEMAS);
        $this->assertSame(
            ProgrammingRuntimeReadinessCanon::CHECK_STATUS_GREEN,
            $devForge['status'],
            'Retained Dev→Forge surface adapters must dual-emit escalation_packet_v1 instead of being flagged by file presence.',
        );
    }

    /**
     * @param  array<string,mixed>  $report
     * @return array<string,mixed>
     */
    private function checkById(array $report, string $id): array
    {
        foreach ((array) $report['checks'] as $check) {
            if ($check['id'] === $id) {
                return $check;
            }
        }
        $this->fail("check [{$id}] not found in report");
    }

    private function probeWithGreenFixtures(): FakeRepoProbe
    {
        $probe = new FakeRepoProbe;

        // Phase 1 gateway bridge — AiGatewayService injects AiGatewayMissionBridge.
        $probe->setFile(
            'app/Services/Ai/AiGatewayService.php',
            '<?php use App\Services\Ai\Mission\AiGatewayMissionBridge; class AiGatewayService {}',
        );

        // Phase 4-6 worker injection — AiWorker contains all Kernel sentinels.
        $probe->setFile(
            'app/Services/Ai/AiWorker.php',
            '<?php use App\Services\Ai\Mission\MissionLifecycleService; use App\Services\Ai\Mission\MissionFactoryService; use App\Services\Ai\Mission\MissionCertificationService; use App\Services\Ai\Policy\PermissionGateService; use App\Services\Ai\Evidence\CertificationRuntimeService; use App\Services\Ai\RouterRuntime\FlowRouterService; use App\Services\Ai\RouterRuntime\DomainRouterService; class AiWorker {}',
        );

        // route_decision implementation
        $probe->setFile('app/Services/Ai/DualCore/DualCoreRouteDecisionService.php', '<?php class DualCoreRouteDecisionService {}');
        $probe->setFile('app/Models/AiDualCoreRouteDecision.php', '<?php class AiDualCoreRouteDecision {}');

        // route_decision callers (production)
        $probe->setFindFilesResults('app::DualCoreRouteDecisionService', ['app/Services/Ai/RouterRuntime/FlowRouterService.php']);

        // escalation packet schema present in code + Programming caller
        $probe->setFindFilesResults('app::atlas.dev_to_forge.escalation_packet.v1', ['app/Services/Ai/Programming/Kernel/AtlasForgeHandoffAdapter.php']);
        $probe->setCountInDirectoryResult('app::atlas.dev_to_forge.escalation_packet.v1', 1);
        $probe->setFindFilesResults(
            'app/Services/Ai/Programming::atlas.dev_to_forge.escalation_packet.v1',
            ['app/Services/Ai/Programming/Kernel/AtlasForgeHandoffAdapter.php'],
        );

        // Mandatory RAG gate canonical signals (post-fix 2026-05-18):
        // gate class + result class with STATUS_BLOCKED + production caller.
        $probe->setFile(
            'app/Services/Ai/Programming/AtlasDev/Gate/MandatoryRagGate.php',
            '<?php final class MandatoryRagGate { public function gate(): MandatoryRagGateResult {} }',
        );
        $probe->setFile(
            'app/Services/Ai/Programming/AtlasDev/Gate/MandatoryRagGateResult.php',
            '<?php final class MandatoryRagGateResult { public const STATUS_BLOCKED = "blocked"; }',
        );
        $probe->setFindFilesResults(
            'app/Services/Ai/Programming::MandatoryRagGate',
            ['app/Services/Ai/Programming/AtlasDev/AtlasDevFastPathOrchestrator.php'],
        );

        // MissionCertificationService quality-aware (severity tier + critical gate + remediation)
        $probe->setFile(
            'app/Services/Ai/Mission/MissionCertificationService.php',
            "<?php const SEVERITY_CRITICAL = 'critical'; \$criticalFailures = []; return ['severity' => SEVERITY_CRITICAL, 'remediation' => 'fix it'];",
        );

        // E2E canonical test exists
        $probe->setFindFilesResults('tests/Feature/Ai::POST /ai/interactions', ['tests/Feature/Ai/Kernel/AiWorkerKernelIntegrationE2ETest.php']);
        $probe->setFindFilesResults('tests/Feature/Ai::KernelIntegrationE2E', ['tests/Feature/Ai/Kernel/AiWorkerKernelIntegrationE2ETest.php']);

        // Canonical adapter exists; retained surface adapters are wrapped by
        // escalation_packet_v1, so presence does not imply drift.
        $probe->setFile(
            'app/Services/Ai/Programming/Kernel/AtlasForgeHandoffAdapter.php',
            '<?php class AtlasForgeHandoffAdapter { public function promoteWithPacket() { return ["escalation_packet_v1" => []]; } }',
        );
        $probe->setFile(
            'app/Services/Ai/Programming/AtlasDev/Escalation/ForgePromotionPreviewBuilder.php',
            '<?php class ForgePromotionPreviewBuilder { public function build(DevToForgeEscalationPacketFactory $f) { return ["escalation_packet_v1" => []]; } }',
        );
        $probe->setFile(
            'app/Services/AtlasCode/DevToForgePromotionService.php',
            '<?php class DevToForgePromotionService { public function attachCanonicalEscalationPacket() { return ["escalation_packet_v1" => []]; } public function recordCanonicalRouteDecision() {} }',
        );
        $probe->setFile(
            'app/Services/Ai/Programming/AtlasForgeRuntimeDispatchService.php',
            '<?php class AtlasForgeRuntimeDispatchService { public const SCHEMA_VERSION = "atlas.forge.runtime_dispatch_plan.v1"; /* NEVER calls an external provider */ }',
        );
        $probe->setFindFilesResults('app::DevToForgePromotionService', []);

        return $probe;
    }
}

class FakeRepoProbe implements RepoProbe
{
    /** @var array<string,string> */
    private array $files = [];

    /** @var array<string,array<int,string>> */
    private array $findResults = [];

    /** @var array<string,int> */
    private array $countResults = [];

    public function setFile(string $relativePath, string $contents): void
    {
        $this->files[$relativePath] = $contents;
    }

    /**
     * @param  array<int,string>  $matches
     */
    public function setFindFilesResults(string $key, array $matches): void
    {
        $this->findResults[$key] = $matches;
    }

    public function setCountInDirectoryResult(string $key, int $count): void
    {
        $this->countResults[$key] = $count;
    }

    public function fileExists(string $relativePath): bool
    {
        return isset($this->files[$relativePath]);
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
        $key = $this->key($relativeDirectory, $needle);
        if (isset($this->countResults[$key])) {
            return $this->countResults[$key];
        }
        if (isset($this->findResults[$key])) {
            return count($this->findResults[$key]);
        }

        return 0;
    }

    public function findFilesContaining(
        string $relativeDirectory,
        string $needle,
        string $glob = '*.php',
        array $excludeRelativePaths = [],
    ): array {
        return $this->findResults[$this->key($relativeDirectory, $needle)] ?? [];
    }

    private function key(string $relativeDirectory, string $needle): string
    {
        return $relativeDirectory.'::'.$needle;
    }
}

class FakeConfigReader implements ConfigReader
{
    /**
     * @param  array<string,mixed>  $values
     */
    public function __construct(private readonly array $values = []) {}

    public function get(string $key, mixed $default = null): mixed
    {
        return array_key_exists($key, $this->values) ? $this->values[$key] : $default;
    }
}
