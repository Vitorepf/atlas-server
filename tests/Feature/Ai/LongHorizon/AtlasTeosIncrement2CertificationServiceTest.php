<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\LongHorizon;

use App\Services\Ai\LongHorizon\AtlasTeosIncrement2CertificationService;
use App\Services\Ai\ProgrammingRuntime\RepoProbe;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class AtlasTeosIncrement2CertificationServiceTest extends TestCase
{
    public function test_ready_when_replay_causal_and_continuity_surfaces_are_present(): void
    {
        $payload = (new AtlasTeosIncrement2CertificationService($this->greenProbe()))->certify();

        $this->assertSame(AtlasTeosIncrement2CertificationService::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame(AtlasTeosIncrement2CertificationService::STATUS_READY, $payload['status']);
        $this->assertSame([], $payload['blockers']);
        $this->assertTrue($payload['claim_policy']['benchmark_not_run']);
        $this->assertFalse($payload['provider_calls_made']);
        $this->assertFalse($payload['writes']);
        $this->assertSame(64, strlen((string) $payload['certification_hash']));

        $checkIds = array_column($payload['checks'], 'check_id');
        foreach (AtlasTeosIncrement2CertificationService::ALL_CHECK_IDS as $expected) {
            $this->assertContains($expected, $checkIds);
        }
    }

    public function test_missing_replay_surface_blocks_increment_2(): void
    {
        $probe = $this->greenProbe();
        $probe->removeFile('app/Services/Ai/LongHorizon/Replay/ReplayManifestReader.php');

        $payload = (new AtlasTeosIncrement2CertificationService($probe))->certify();

        $this->assertSame(AtlasTeosIncrement2CertificationService::STATUS_BLOCKED, $payload['status']);
        $this->assertContains(
            AtlasTeosIncrement2CertificationService::CHECK_REPLAY_SURFACE,
            array_column($payload['blockers'], 'check_id'),
        );
    }

    public function test_missing_causal_graph_surface_blocks_increment_2(): void
    {
        $probe = $this->greenProbe();
        $probe->removeFile('app/Services/Ai/LongHorizon/Causal/LongHorizonCausalDecisionGraphService.php');

        $payload = (new AtlasTeosIncrement2CertificationService($probe))->certify();

        $this->assertSame(AtlasTeosIncrement2CertificationService::STATUS_BLOCKED, $payload['status']);
        $this->assertContains(
            AtlasTeosIncrement2CertificationService::CHECK_CAUSAL_GRAPH_SURFACE,
            array_column($payload['blockers'], 'check_id'),
        );
    }

    public function test_missing_focused_test_warns_without_p0_blocker(): void
    {
        $probe = $this->greenProbe();
        $probe->removeFile('tests/Feature/Ai/LongHorizon/Causal/AtlasLongHorizonCausalGraphCommandTest.php');

        $payload = (new AtlasTeosIncrement2CertificationService($probe))->certify();

        $this->assertSame(AtlasTeosIncrement2CertificationService::STATUS_PARTIAL, $payload['status']);
        $this->assertSame([], $payload['blockers']);
        $this->assertContains(
            AtlasTeosIncrement2CertificationService::CHECK_I2_TESTS_PRESENT,
            array_column($payload['warnings'], 'check_id'),
        );
    }

    public function test_provider_or_rivals_token_blocks_certification(): void
    {
        $probe = $this->greenProbe();
        $probe->setFile(
            'app/Services/Ai/LongHorizon/Replay/ReplayManifestReader.php',
            "<?php\nuse App\\Services\\Ai\\Programming\\AtlasForgeClaudeCliInvocationDriver;\nfinal class ReplayManifestReader {}",
        );

        $payload = (new AtlasTeosIncrement2CertificationService($probe))->certify();

        $this->assertSame(AtlasTeosIncrement2CertificationService::STATUS_BLOCKED, $payload['status']);
        $this->assertContains(
            AtlasTeosIncrement2CertificationService::CHECK_NO_PROVIDER_OR_RIVALS,
            array_column($payload['blockers'], 'check_id'),
        );
    }

    public function test_command_emits_json_payload(): void
    {
        $exit = Artisan::call('atlas:teos:i2-certify', ['--json' => true]);

        $this->assertSame(0, $exit);
        $payload = json_decode(Artisan::output(), true);
        $this->assertIsArray($payload);
        $this->assertSame(AtlasTeosIncrement2CertificationService::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertArrayHasKey('status', $payload);
        $this->assertTrue($payload['claim_policy']['benchmark_not_run']);
    }

    private function greenProbe(): TeosIncrement2FakeProbe
    {
        $probe = new TeosIncrement2FakeProbe;
        $stub = '<?php // TEOS-I2 green fixture';

        foreach ([
            'app/Models/AtlasLongHorizonReplayManifest.php',
            'database/migrations/2026_05_19_150000_create_atlas_long_horizon_replay_manifests_table.php',
            'app/Services/Ai/LongHorizon/Replay/LongHorizonReplayManifestBuilder.php',
            'app/Services/Ai/LongHorizon/Replay/ReplayManifestReader.php',
            'app/Console/Commands/AtlasLongHorizonReplayManifestCommand.php',
            'app/Services/Ai/LongHorizon/Causal/CausalGraphBuilder.php',
            'app/Services/Ai/LongHorizon/Causal/LongHorizonCausalDecisionGraphService.php',
            'app/Console/Commands/AtlasLongHorizonCausalGraphCommand.php',
            'app/Services/Ai/LongHorizon/LongHorizonContinuityCertificationService.php',
            'app/Console/Commands/AtlasLongHorizonContinuityCertifyCommand.php',
            'tests/Feature/Ai/LongHorizon/Replay/LongHorizonReplayManifestBuilderTest.php',
            'tests/Feature/Ai/LongHorizon/Replay/ReplayManifestReaderTest.php',
            'tests/Feature/Ai/LongHorizon/Replay/AtlasLongHorizonReplayManifestCommandTest.php',
            'tests/Feature/Ai/LongHorizon/Causal/LongHorizonCausalDecisionGraphServiceTest.php',
            'tests/Feature/Ai/LongHorizon/Causal/AtlasLongHorizonCausalGraphCommandTest.php',
            'tests/Feature/Ai/LongHorizon/LongHorizonContinuityCertificationServiceTest.php',
            'docs/engineering-knowledge-base/atlas-temporal-engineering-operating-system.md',
            'docs/engineering-knowledge-base/atlas-temporal-engineering-operating-system-primitives.md',
            'docs/engineering-knowledge-base/atlas-temporal-engineering-operating-system-operations.md',
            'docs/engineering-knowledge-base/atlas-teos-increment-1-plan.md',
            'docs/engineering-knowledge-base/atlas-long-horizon-replay-manifest.md',
        ] as $file) {
            $probe->setFile($file, $stub);
        }

        $probe->setFile(
            'app/Services/Ai/LongHorizon/AtlasLongHorizonCanon.php',
            "<?php\nfinal class AtlasLongHorizonCanon {\n".
            " public const REPLAY_MANIFEST_SCHEMA_VERSION = 'atlas.long_horizon.replay_manifest.v1';\n".
            " public const REPLAY_READER_SCHEMA_VERSION = 'atlas.long_horizon.replay_reader_bundle.v1';\n".
            " public const CAUSAL_GRAPH_LITE_SCHEMA_VERSION = 'atlas.long_horizon.causal_graph_lite.v1';\n".
            " public const CAUSAL_GRAPH_LITE_ALLOWED_SCOPES = [];\n".
            "}\n",
        );

        return $probe;
    }
}

class TeosIncrement2FakeProbe implements RepoProbe
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
        return count($this->findFilesContaining($relativeDirectory, $needle, $glob, $excludeRelativePaths));
    }

    public function findFilesContaining(
        string $relativeDirectory,
        string $needle,
        string $glob = '*.php',
        array $excludeRelativePaths = [],
    ): array {
        $matches = [];
        foreach ($this->files as $path => $contents) {
            if (! str_starts_with($path, rtrim($relativeDirectory, '/').'/')) {
                continue;
            }
            if (in_array($path, $excludeRelativePaths, true)) {
                continue;
            }
            if (str_contains($contents, $needle)) {
                $matches[] = $path;
            }
        }

        return $matches;
    }
}
