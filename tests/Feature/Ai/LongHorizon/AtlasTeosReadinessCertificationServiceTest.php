<?php

namespace Tests\Feature\Ai\LongHorizon;

use App\Services\Ai\LongHorizon\AtlasTeosReadinessCertificationService;
use App\Services\Ai\ProgrammingRuntime\RepoProbe;
use Tests\TestCase;

class AtlasTeosReadinessCertificationServiceTest extends TestCase
{
    public function test_ready_status_when_all_canonical_artifacts_present(): void
    {
        $probe = $this->probeWithGreenFixtures();
        $service = new AtlasTeosReadinessCertificationService($probe);

        $payload = $service->certify();

        $this->assertSame(AtlasTeosReadinessCertificationService::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame(AtlasTeosReadinessCertificationService::STATUS_READY, $payload['status']);
        $this->assertSame([], $payload['blockers']);
        $this->assertSame(64, strlen((string) $payload['certification_hash']));

        // Every canonical check must appear in the payload.
        $checkIds = array_column($payload['checks'], 'check_id');
        foreach (AtlasTeosReadinessCertificationService::ALL_CHECK_IDS as $expected) {
            $this->assertContains($expected, $checkIds, "missing check_id={$expected}");
        }
    }

    public function test_status_is_blocked_when_p0_canon_doc_missing(): void
    {
        $probe = $this->probeWithGreenFixtures();
        // Remove TEOS-I1 plan doc — a P0 artifact.
        $probe->removeFile('docs/engineering-knowledge-base/atlas-teos-increment-1-plan.md');

        $payload = (new AtlasTeosReadinessCertificationService($probe))->certify();

        $this->assertSame(AtlasTeosReadinessCertificationService::STATUS_BLOCKED, $payload['status']);
        $blockerIds = array_column($payload['blockers'], 'check_id');
        $this->assertContains(
            AtlasTeosReadinessCertificationService::CHECK_CANONICAL_DOCS_PRESENT,
            $blockerIds,
        );
        $this->assertGreaterThanOrEqual(1, $payload['summary']['p0_blockers']);
        $remediationIds = array_column($payload['remediation'], 'check_id');
        $this->assertContains(
            AtlasTeosReadinessCertificationService::CHECK_CANONICAL_DOCS_PRESENT,
            $remediationIds,
        );
    }

    public function test_status_is_partial_when_p1_warn_present(): void
    {
        $probe = $this->probeWithGreenFixtures();
        // Drop one optional test — promotes the focused-tests check to WARN.
        $probe->removeFile('tests/Feature/Ai/LongHorizon/LongHorizonContextFreshnessGateTest.php');

        $payload = (new AtlasTeosReadinessCertificationService($probe))->certify();

        $this->assertSame(AtlasTeosReadinessCertificationService::STATUS_PARTIAL, $payload['status']);
        $this->assertSame([], $payload['blockers']);
        $focusedCheck = $this->checkById($payload, AtlasTeosReadinessCertificationService::CHECK_FOCUSED_TESTS_PRESENT);
        $this->assertSame(AtlasTeosReadinessCertificationService::CHECK_STATUS_WARN, $focusedCheck['status']);
        $this->assertSame(AtlasTeosReadinessCertificationService::SEVERITY_P1, $focusedCheck['severity']);
    }

    public function test_external_claim_status_is_always_not_claimed(): void
    {
        // Even with a totally empty probe (every check fails), the invariant
        // must hold — the certification never promotes external completion.
        $probe = new TeosFakeProbe;
        $payload = (new AtlasTeosReadinessCertificationService($probe))->certify();

        $this->assertSame(
            AtlasTeosReadinessCertificationService::EXTERNAL_CLAIM_NOT_CLAIMED,
            $payload['external_claim_status'],
        );
        $this->assertFalse($payload['provider_calls_made'], 'TEOS readiness must NEVER call a provider.');
        $this->assertFalse(
            $payload['atlas_decide_topology_modified'],
            'TEOS readiness must NEVER mutate Atlas Decide topology.',
        );
        $this->assertSame(AtlasTeosReadinessCertificationService::STATUS_BLOCKED, $payload['status']);
        $this->assertNotEmpty($payload['blockers']);
    }

    public function test_certification_hash_is_stable_for_identical_input(): void
    {
        $probe = $this->probeWithGreenFixtures();
        $service = new AtlasTeosReadinessCertificationService($probe);

        $a = $service->certify();
        $b = $service->certify();

        $this->assertSame($a['certification_hash'], $b['certification_hash']);

        $json = json_encode($a, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->assertNotFalse($json, 'TEOS readiness payload must serialize cleanly to JSON.');
        $decoded = json_decode((string) $json, true);
        $this->assertSame(
            AtlasTeosReadinessCertificationService::SCHEMA_VERSION,
            $decoded['schema_version'],
        );
        $this->assertCount(
            count(AtlasTeosReadinessCertificationService::ALL_CHECK_IDS),
            $decoded['checks'],
        );
    }

    public function test_boundary_violation_against_atlas_decide_topology_is_blocked(): void
    {
        $probe = $this->probeWithGreenFixtures();
        // Inject a forbidden token into one of TEOS source files to simulate
        // a regression where TEOS started referencing Provider Topology.
        $probe->setFile(
            'app/Services/Ai/LongHorizon/LongHorizonRecoveryPlannerService.php',
            "<?php\nclass LongHorizonRecoveryPlannerService { use AtlasForgeProviderTopologyService; }",
        );

        $payload = (new AtlasTeosReadinessCertificationService($probe))->certify();

        $check = $this->checkById($payload, AtlasTeosReadinessCertificationService::CHECK_DECIDE_TOPOLOGY_UNTOUCHED);
        $this->assertSame(AtlasTeosReadinessCertificationService::CHECK_STATUS_FAIL, $check['status']);
        $this->assertSame(AtlasTeosReadinessCertificationService::STATUS_BLOCKED, $payload['status']);
    }

    public function test_provider_invocation_use_statement_in_teos_source_blocks_readiness(): void
    {
        $probe = $this->probeWithGreenFixtures();
        // Inject REAL usage (use statement + instantiation) of a forbidden
        // provider invocation driver into one of the audited TEOS services —
        // simulating a regression where TEOS started importing a CLI driver.
        // The detector ignores textual mentions inside comments/strings, so
        // the fixture must reflect actual code usage to trip the check.
        $probe->setFile(
            'app/Services/Ai/LongHorizon/LongHorizonRecoveryPlannerService.php',
            "<?php\n".
            "use App\\Services\\Ai\\Programming\\AtlasForgeClaudeCliInvocationDriver;\n".
            "class LongHorizonRecoveryPlannerService { public function plan() { return new AtlasForgeClaudeCliInvocationDriver(); } }",
        );

        $payload = (new AtlasTeosReadinessCertificationService($probe))->certify();

        $check = $this->checkById($payload, AtlasTeosReadinessCertificationService::CHECK_NO_REAL_PROVIDER_CALLED);
        $this->assertSame(AtlasTeosReadinessCertificationService::CHECK_STATUS_FAIL, $check['status']);
        $this->assertSame(AtlasTeosReadinessCertificationService::STATUS_BLOCKED, $payload['status']);
    }

    public function test_textual_mention_in_strings_does_not_trip_provider_check(): void
    {
        // The TEOS readiness service itself enumerates the forbidden tokens as
        // canonical strings. That must NOT be flagged as use — otherwise the
        // service would always block itself. This test pins the
        // false-positive-avoidance behavior.
        $probe = $this->probeWithGreenFixtures();
        $probe->setFile(
            'app/Services/Ai/LongHorizon/LongHorizonRecoveryPlannerService.php',
            "<?php\nclass LongHorizonRecoveryPlannerService {\n  // mentions only — no use statement, no new, no static call\n  private const NOTE = 'AtlasForgeClaudeCliInvocationDriver is banned here';\n}",
        );

        $payload = (new AtlasTeosReadinessCertificationService($probe))->certify();

        $check = $this->checkById($payload, AtlasTeosReadinessCertificationService::CHECK_NO_REAL_PROVIDER_CALLED);
        $this->assertSame(AtlasTeosReadinessCertificationService::CHECK_STATUS_PASS, $check['status']);
    }

    /**
     * Builds a fake probe with every canonical artifact present so the green
     * path is reachable in a deterministic way. Individual tests then remove
     * specific files to simulate blockers/warns.
     */
    private function probeWithGreenFixtures(): TeosFakeProbe
    {
        $probe = new TeosFakeProbe;
        $stub = '<?php // canonical stub for TEOS readiness green fixture';

        // Docs
        foreach ([
            'docs/engineering-knowledge-base/atlas-teos-increment-1-plan.md',
            'docs/engineering-knowledge-base/atlas-temporal-engineering-operating-system.md',
            'docs/engineering-knowledge-base/atlas-temporal-engineering-operating-system-primitives.md',
            'docs/engineering-knowledge-base/atlas-temporal-engineering-operating-system-operations.md',
            'docs/engineering-knowledge-base/atlas-long-horizon-intelligence-layer.md',
            'docs/engineering-knowledge-base/atlas-teos-existing-code-map.md',
            'docs/engineering-knowledge-base/atlas-hyperflow-operation.md',
        ] as $doc) {
            $probe->setFile($doc, '# canon doc');
        }

        // Services + models + tests
        foreach ([
            'app/Models/AtlasLongHorizonContinuationPack.php',
            'app/Models/AtlasLongHorizonCompactionReceipt.php',
            'database/migrations/2026_05_19_040000_create_atlas_long_horizon_continuation_pack_and_compaction_receipt_tables.php',
            'tests/Concerns/CreatesLongHorizonPersistenceTables.php',
            'app/Services/Ai/LongHorizon/Gate/LongHorizonContextFreshnessGate.php',
            'app/Services/Ai/LongHorizon/Gate/LongHorizonContextFreshnessGateResult.php',
            'app/Services/Ai/LongHorizon/LongHorizonRecoveryPlannerService.php',
            'app/Services/Ai/LongHorizon/LongHorizonMemoryPromotionGuard.php',
            'app/Services/Ai/LongHorizon/LongHorizonMemoryPromotionRefusedException.php',
            'tests/Feature/Ai/LongHorizon/AtlasLongHorizonPersistenceTest.php',
            'tests/Feature/Ai/LongHorizon/LongHorizonRecoveryPlannerServiceTest.php',
            'tests/Feature/Ai/LongHorizon/LongHorizonContextFreshnessGateTest.php',
            'tests/Feature/Ai/LongHorizon/LongHorizonMemoryScopesAndPromotionGuardTest.php',
            'app/Services/Ai/LongHorizon/AtlasTeosReadinessCertificationService.php',
        ] as $path) {
            $probe->setFile($path, $stub);
        }

        // Canon service needs the canonical tokens for its sub-check.
        $probe->setFile(
            'app/Services/Ai/LongHorizon/AtlasLongHorizonCanon.php',
            "<?php\nclass AtlasLongHorizonCanon {\n  const CONTINUATION_PACK_SCHEMA_VERSION='atlas.long_horizon.continuation_pack.v2';\n  const COMPACTION_RECEIPT_SCHEMA_VERSION='atlas.long_horizon.compaction_receipt.v1';\n  const RECOVERY_PLAN_SCHEMA_VERSION='atlas.long_horizon.recovery_plan.v1';\n  const ALLOWED_SAFE_RESUME_MODES=[];\n  const ALLOWED_SCOPE_TYPES=[];\n}",
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
        $this->fail("check {$checkId} not found in TEOS readiness payload");
    }
}

class TeosFakeProbe implements RepoProbe
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
